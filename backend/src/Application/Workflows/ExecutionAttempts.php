<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Benchmarks\{AdapterBinding, BenchmarkContract};
use Chronos\Application\Artifacts\CemArtifactSetup;
use Chronos\Application\Shared\Input;
use Chronos\Domain\Workflows\{JobPhases, JobStatus};
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\{DatabaseJson, Store};

/** Attempt ownership and result selection. Mutations serialize on the repetition row. */
final class ExecutionAttempts
{
    public const PROTOCOL = 'attempt-v1';

    private readonly JobSpecifications $specifications;

    public function __construct(private readonly Store $db)
    {
        $this->specifications = new JobSpecifications($db);
    }

    /** Start the transaction only after a concurrent poll with this credential has committed. */
    public function claimTransaction(string $token, callable $operation): mixed
    {
        $hash = self::hashToken($token);
        $name = $this->db->row("SELECT SHA2(CONCAT(DATABASE(), ':chronos-attempt:', ?), 256) AS name", [$hash])['name'];
        if ((int) $this->db->row('SELECT GET_LOCK(?, 10) AS acquired', [$name])['acquired'] !== 1) {
            throw new HttpError(409, 'claim_busy', 'The previous claim is still in progress. Retry with the same credential.');
        }
        try {
            return $this->db->transaction($operation);
        } finally {
            $this->db->row('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    /** Claim inside the caller's transaction, including any CEM node assignment. */
    public function claim(string $environment, string $adapter, ?string $supports, string $kind, string $worker, string $token, bool &$rejectedCemPlan = false): ?array
    {
        $hash = self::hashToken($token);
        $worker = self::worker($worker);
        if ($adapter !== AdapterBinding::COMMAND) Input::invalid('adapter', 'Advertise a supported adapter version.');
        $previous = $this->db->row('SELECT jobId FROM JobAttempt WHERE tokenHash=?', [$hash]);
        if ($previous !== null) {
            $job = $this->lockJob((int) $previous['jobId']);
            $attempt = $this->authorize($job, $token, $kind, $worker);
            $spec = $this->specifications->forJob((int) $job['jobId'], true);
            if ($job['environment'] !== $environment || $spec['adapter'] !== $adapter) self::stale();
            return $this->claimResponse($job, (int) $attempt['attemptId'], $spec);
        }
        if ($kind === 'cem' && $this->db->row("SELECT attemptId FROM JobAttempt WHERE workerKind='cem' AND workerId=? AND status IN (1,2) LIMIT 1 FOR UPDATE", [$worker])) {
            throw new HttpError(409, 'node_busy', 'The CEM node already has an active attempt.');
        }
        $args = [$environment, $adapter];
        $scope = '';
        if ($supports !== null && $supports !== 'demoall') {
            $systems = array_unique(explode(',', $supports));
            if (count($systems) > 100) Input::invalid('supports', 'Advertise at most 100 system identifiers.');
            $scope = ' AND s.systemIdentifier IN (' . implode(',', array_fill(0, count($systems), '?')) . ')';
            array_push($args, ...$systems);
        }
        do {
            $job = $this->db->row('SELECT j.* FROM Job j WHERE j.environment=? AND j.status=0 AND EXISTS
                (SELECT 1 FROM JobSpecification s WHERE s.jobId=j.jobId AND s.adapter=?' . $scope . ') ORDER BY j.jobId LIMIT 1 FOR UPDATE', $args);
            if ($job === null) return null;
            $jobId = (int) $job['jobId'];
            $spec = $this->specifications->forJob($jobId, true);
            if ($kind !== 'cem') break;
            try {
                CemArtifactSetup::bindingManifest($spec['artifacts']);
                break;
            } catch (\InvalidArgumentException $error) {
                // Older releases could queue a binding whose artifacts cannot be delivered by CEM.
                $this->db->update('Job', 'jobId', $jobId, ['status' => JobStatus::FAILED, 'finished' => self::now()]);
                $this->event($jobId, 'Invalid CEM artifact manifest', $error->getMessage());
                $rejectedCemPlan = true;
            }
        } while (true);
        $attemptId = $this->db->insert('JobAttempt', [
            'jobId' => $jobId, 'tokenHash' => $hash, 'workerKind' => $kind, 'workerId' => $worker,
            'status' => JobStatus::SETUP, 'progress' => 0, 'claimed' => self::now(),
        ]);
        $this->db->update('Job', 'jobId', $jobId, ['status' => JobStatus::SETUP]);
        $job['status'] = JobStatus::SETUP;
        $this->event($jobId, 'Attempt claimed', 'Attempt ' . $attemptId . ' was assigned to ' . $worker . '.');
        return $this->claimResponse($job, $attemptId, $spec);
    }

    private function claimResponse(array $job, int $attemptId, array $spec): array
    {
        return ['job' => $job, 'attemptId' => $attemptId, 'specification' => $spec];
    }

    public function lockJob(int $jobId): array
    {
        return $this->db->row('SELECT * FROM Job WHERE jobId=? FOR UPDATE', [$jobId])
            ?? throw new HttpError(404, 'not_found', 'Job does not exist!');
    }

    public function authorize(array $job, string $token, ?string $kind = null, ?string $worker = null, bool $terminal = false): array
    {
        $hash = self::hashToken($token);
        $attempt = $this->specifications->latestAttempt((int) $job['jobId'], true);
        if ($attempt === null || !hash_equals($attempt['tokenHash'], $hash)
            || ($kind !== null && $attempt['workerKind'] !== $kind) || ($worker !== null && $attempt['workerId'] !== $worker)
            || (int) $job['status'] !== (int) $attempt['status']
            || (!$terminal && !in_array((int) $job['status'], [JobStatus::SETUP, JobStatus::RUNNING], true))) {
            self::stale();
        }
        return $attempt;
    }

    public function patch(array $job, array $attempt, array $body): void
    {
        Input::fields($body, ['status', 'progress', 'currentPhase', 'result', 'environment', 'artifacts', 'reason']);
        if ($body === []) Input::invalid('body', 'Submit an attempt update.');
        $next = isset($body['status']) ? WorkflowInput::jobStatus($body['status']) : (int) $job['status'];
        $status = (int) $job['status'];
        if (!in_array($next, [JobStatus::SETUP, JobStatus::RUNNING, JobStatus::FAILED, JobStatus::FINISHED], true)
            || ($status === JobStatus::RUNNING && ($next === JobStatus::SETUP || ($next === JobStatus::RUNNING && isset($body['status']))))
            || ($next === JobStatus::FINISHED && $status !== JobStatus::RUNNING)) {
            throw new HttpError(409, 'attempt_transition', 'The requested attempt status transition is invalid.');
        }
        $jobId = (int) $job['jobId'];
        $spec = $this->specifications->forJob($jobId, true);
        $changes = ['status' => $next];
        if ($next === JobStatus::RUNNING && $attempt['started'] === null) $changes['started'] = self::now();
        if (isset($body['currentPhase'])) {
            $phase = Input::integer($body['currentPhase'], 'currentPhase', 1, count(JobPhases::NAMES));
            if ($phase < (int) $attempt['currentPhase'] || ((int) $spec['phases'] & (1 << ($phase - 1)))) {
                throw new HttpError(409, 'attempt_phase', 'This phase is excluded or has already passed.');
            }
            $changes['currentPhase'] = $phase;
            if ($phase > (int) $attempt['currentPhase']) $changes['progress'] = 0;
        }
        if (isset($body['progress'])) {
            $progress = Input::integer($body['progress'], 'progress', 0, 100);
            if ($progress < ($changes['progress'] ?? (int) $attempt['progress'])) {
                throw new HttpError(409, 'attempt_progress', 'The progress update precedes progress already recorded.');
            }
            $changes['progress'] = $progress;
        }

        $evidence = [];
        if (isset($body['environment'])) {
            $evidence['observedEnvironment'] = DatabaseJson::encode(self::objectJson($body['environment'], 'environment', 16384), 'environment');
        }
        if (isset($body['artifacts'])) {
            $reported = self::objectJson($body['artifacts'], 'artifacts', 16384);
            $expected = array_column($spec['artifacts']['artifacts'], null, 'role');
            foreach ($reported as $role => $entry) {
                if (!isset($expected[$role]) || !$entry instanceof \stdClass || count((array) $entry) !== 2 || ($entry->sha256 ?? null) !== $expected[$role]['sha256']
                    || ($entry->sizeBytes ?? null) !== $expected[$role]['sizeBytes']) {
                    Input::invalid('artifacts', 'Report the verified digest and size for each observed artifact.');
                }
            }
            $prior = $attempt['observedArtifacts'] === null ? [] : json_decode($attempt['observedArtifacts'], true, 16, JSON_THROW_ON_ERROR);
            $evidence['observedArtifacts'] = DatabaseJson::encode((object) array_replace($prior, (array) $reported), 'artifacts');
        }
        if (isset($body['result'])) {
            self::objectJson($body['result'], 'result', 1048576);
            $evidence['result'] = $body['result'];
        }
        if (isset($body['reason'])) $evidence['reason'] = Input::text($body['reason'], 'reason', 4000);
        if (in_array($next, [JobStatus::FAILED, JobStatus::FINISHED], true)) $changes['finished'] = self::now();
        if ($next === JobStatus::FINISHED) {
            $contract = $spec['benchmark']['contract'];
            $resultJson = $evidence['result'] ?? $attempt['result'];
            if ($resultJson === null) Input::invalid('result', 'Submit the measured results before completing the attempt.');
            $result = json_decode($resultJson, false, 32, JSON_THROW_ON_ERROR);
            BenchmarkContract::result($contract['results'], (array) $result);
            $verified = json_decode($evidence['observedArtifacts'] ?? $attempt['observedArtifacts'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            if (count($verified) !== count($spec['artifacts']['artifacts'])) {
                Input::invalid('artifacts', 'Verify the full artifact manifest before completing the attempt.');
            }
            $changes['progress'] = 100;
            $evidence['selected'] = 1;
            // The existing Job result column is the committed selection projection.
            $jobResult = ['result' => DatabaseJson::encode($result, 'result', 2097152)];
        }
        $this->db->update('JobAttempt', 'attemptId', (int) $attempt['attemptId'], $changes + $evidence);
        $this->db->update('Job', 'jobId', $jobId, $changes + ($jobResult ?? []));
        if ($next !== $status) $this->event($jobId, 'Attempt ' . JobStatus::label($next), 'Attempt ' . $attempt['attemptId'] . ' changed to ' . JobStatus::label($next) . '.');
    }

    /** Browser actions cannot manufacture or modify a completed attempt. */
    public function operatorTransition(array $job, int $next): void
    {
        $status = (int) $job['status'];
        $allowed = match ($next) {
            JobStatus::ABORTED => in_array($status, [JobStatus::SCHEDULED, JobStatus::SETUP, JobStatus::RUNNING, JobStatus::FAILED], true),
            JobStatus::SCHEDULED => in_array($status, [JobStatus::FAILED, JobStatus::ABORTED, JobStatus::SCHEDULED], true),
            default => false,
        };
        if (!$allowed) throw new HttpError(409, 'execution_managed', 'Only abort and reschedule actions are allowed for managed repetitions.');
        if ($next === JobStatus::ABORTED && in_array($status, [JobStatus::SETUP, JobStatus::RUNNING], true)) {
            $attempt = $this->specifications->latestAttempt((int) $job['jobId'], true);
            $this->db->update('JobAttempt', 'attemptId', (int) $attempt['attemptId'], [
                'status' => JobStatus::ABORTED, 'finished' => self::now(), 'reason' => 'Aborted by an operator.',
            ]);
        }
    }

    private static function objectJson(mixed $value, string $field, int $bytes): \stdClass
    {
        if (!is_string($value) || strlen($value) > $bytes) Input::invalid($field, 'Provide a bounded JSON object.');
        try { $decoded = json_decode($value, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { Input::invalid($field, 'Provide a valid JSON object.'); }
        if (!$decoded instanceof \stdClass) Input::invalid($field, 'Provide a JSON object.');
        return $decoded;
    }

    private static function hashToken(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HttpError(409, 'attempt_token', 'Provide the attempt credential in X-Chronos-Attempt-Token.');
        }
        return hash('sha256', $token);
    }

    private static function worker(string $worker): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,99}$/D', $worker)) Input::invalid('worker', 'Provide a stable worker identifier.');
        return $worker;
    }

    private static function stale(): never
    {
        throw new HttpError(409, 'stale_attempt', 'This attempt no longer owns the repetition.');
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function event(int $jobId, string $title, string $message): void
    {
        $this->db->insert('Event', ['title' => $title, 'time' => self::now(), 'eventText' => $message, 'eventType' => 'job',
            'relatedId' => $jobId, 'userId' => null, 'nodeId' => null]);
    }
}
