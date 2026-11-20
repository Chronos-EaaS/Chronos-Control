<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Shared\{Context, Dto};
use Chronos\Domain\Workflows\JobStatus;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Agents\AttemptFiles;

final class AttemptEvidence
{
    private readonly JobSpecifications $specifications;
    private readonly string $dataDirectory;

    public function __construct(private readonly Context $context)
    {
        $this->specifications = new JobSpecifications($context->db);
        $this->dataDirectory = defined('UPLOADED_DATA_PATH') ? UPLOADED_DATA_PATH : $context->root . '/webroot/data';
    }

    public function read(int $jobId): array
    {
        $this->context->job($jobId);
        $specification = $this->specifications->document($jobId);
        $managed = ($specification->protocol ?? null) === ExecutionAttempts::PROTOCOL;
        $rows = $managed ? $this->context->db->rows('SELECT * FROM JobAttempt WHERE jobId=? ORDER BY attemptId', [$jobId]) : [];
        $attempts = array_map(fn(array $row): array => $this->attempt($row), $rows);
        return [
            'repetitionId' => $jobId, 'specification' => $specification, 'attempts' => $attempts,
            'resultSelection' => $managed ? 'successful-attempt' : 'legacy-job-result',
            'selectedAttemptId' => array_column(array_filter($attempts, static fn(array $attempt): bool => $attempt['selected']), 'id')[0] ?? null,
        ];
    }

    public function file(int $jobId, int $attemptId, string $kind): string
    {
        $this->context->job($jobId);
        $row = $this->context->db->row('SELECT attemptId,archive FROM JobAttempt WHERE jobId=? AND attemptId=?', [$jobId, $attemptId])
            ?? throw new HttpError(404, 'attempt_unavailable', 'The execution attempt is unavailable.');
        if ($kind === 'archive' && $row['archive'] !== null) {
            return AttemptFiles::readableArchive($this->dataDirectory, $attemptId, self::decode($row['archive']));
        }
        if ($kind === 'log' && $this->logAvailable($attemptId)) return AttemptFiles::log($this->dataDirectory, $attemptId);
        throw new HttpError(404, 'evidence_missing', 'This attempt has no stored ' . $kind . '.');
    }

    private function attempt(array $row): array
    {
        $id = (int) $row['attemptId'];
        $url = '/api/ui/jobs/' . $row['jobId'] . '/attempts/' . $id;
        $archive = self::decode($row['archive']);
        $archivePath = $archive === null ? null : AttemptFiles::archive($this->dataDirectory, $id, $archive['sha256']);
        return [
            'id' => $id, 'status' => JobStatus::label((int) $row['status']), 'selected' => (bool) $row['selected'],
            'worker' => ['id' => $row['workerId'], 'kind' => $row['workerKind']],
            'claimedAt' => Dto::instant($row['claimed']), 'startedAt' => Dto::instant($row['started']), 'finishedAt' => Dto::instant($row['finished']),
            'progress' => (int) $row['progress'], 'currentPhase' => isset($row['currentPhase']) ? (int) $row['currentPhase'] : null,
            'observedEnvironment' => self::decode($row['observedEnvironment']),
            'observedArtifacts' => self::decode($row['observedArtifacts']),
            'result' => $row['result'] === null ? null : json_decode($row['result'], false, 32, JSON_THROW_ON_ERROR),
            'reason' => $row['reason'],
            'logUrl' => $this->logAvailable($id) ? $url . '/log' : null,
            'archive' => $archive,
            'archiveUrl' => $archivePath !== null && is_file($archivePath) && !is_link($archivePath) ? $url . '/archive' : null,
        ];
    }

    private function logAvailable(int $attemptId): bool
    {
        $file = AttemptFiles::log($this->dataDirectory, $attemptId);
        return is_file($file) && !is_link($file);
    }

    private static function decode(?string $json): ?array
    {
        return $json === null ? null : json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }
}
