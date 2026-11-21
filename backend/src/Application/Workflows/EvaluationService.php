<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Benchmarks\{BenchmarkCatalog, BenchmarkContract};
use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Application\Shared\TextSearch;
use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;

/** Evaluation generation and atomic job actions. */
final class EvaluationService
{
    public function __construct(
        private readonly Context $context,
        private readonly WorkflowProjection $projection,
        private readonly ExperimentDefinition $definition,
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    public function list(?string $parent, ?int $id, array $options, string $state): array
    {
        [$where, $args] = $parent === null ? $this->context->scope('p', $options['all'])
            : [$parent === 'project' ? 'p.projectId=?' : 'x.experimentId=?', [$id]];
        $filters = $options;
        $filters['search'] = '';
        WorkflowFilters::append($filters, 'e', $where, $args);
        if ($options['search'] !== '') {
            [$clause, $patterns] = TextSearch::condition($options['search'], ['e.name', 'e.description', 'x.name', 'p.name', 's.name']);
            $where .= ' AND ' . $clause; array_push($args, ...$patterns);
        }
        $running = 'EXISTS (SELECT 1 FROM `Job` running WHERE running.evaluationId=e.evaluationId AND running.status IN (-2,0,1,2))';
        $where .= match ($state) {
            'running' => ' AND ' . $running,
            'starred' => ' AND e.isStarred=1',
            'running-or-starred' => ' AND (' . $running . ' OR e.isStarred=1)',
            'completed' => ' AND NOT ' . $running,
            default => '',
        };
        $page = $this->context->page(
            'FROM `Evaluation` e JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=e.systemId',
            $where, $args, $options, 'e.*,x.projectId,x.internalId experimentInternalId,x.name experimentName,x.resultId,p.name projectName,s.name systemName',
            static fn(array $row): array => $row, 'e.evaluationId',
        );
        $page['items'] = $this->projection->evaluations($page['items']);
        return $page;
    }

    public function update(int $id, array $body): array
    {
        $this->context->db->transaction(function () use ($id, $body): void {
            $this->context->evaluation($id, true);
            $changes = WorkflowInput::metadata($body);
            if (array_key_exists('starred', $body)) $changes['isStarred'] = (int) Input::boolean($body['starred'], 'starred');
            if (array_key_exists('archived', $body)) {
                $archive = Input::boolean($body['archived'], 'archived');
                $counts = $this->projection->counts($id);
                if ($archive && $counts['active'] + $counts['failed'] > 0) throw new HttpError(409, 'evaluation_running', 'Finish or abort the active jobs before archiving this evaluation.');
                $changes['isArchived'] = (int) $archive;
            }
            $this->context->db->update('Evaluation', 'evaluationId', $id, $changes);
            $this->context->event('evaluation', $id, 'Evaluation updated', 'The evaluation settings were updated.');
        });
        return $this->projection->evaluation($this->context->evaluation($id));
    }

    public function action(int $id, string $action): array
    {
        $changed = $this->context->db->transaction(function () use ($id, $action): int {
            $this->context->evaluation($id, true);
            $statuses = $action === 'abort' ? [-2, 0, 1, 2] : [-2, -1];
            $next = $action === 'abort' ? -1 : 0;
            $changed = 0;
            $lastId = 0;
            $attempts = new ExecutionAttempts($this->context->db);
            do {
                $jobs = $this->context->db->rows(
                    'SELECT j.jobId,j.status,EXISTS (SELECT 1 FROM JobSpecification s WHERE s.jobId=j.jobId AND s.adapter IS NOT NULL) AS managed
                     FROM Job j WHERE j.evaluationId=? AND j.jobId>? AND j.status IN (' . implode(',', $statuses) . ')
                     ORDER BY j.jobId LIMIT 128 FOR UPDATE', [$id, $lastId]);
                foreach ($jobs as $job) {
                    $lastId = (int) $job['jobId'];
                    if ((bool) $job['managed']) $attempts->operatorTransition($job, $next);
                    $values = ['status' => $next];
                    if ($next === 0) $values += ['currentPhase' => null, 'progress' => 0, 'started' => null, 'finished' => null];
                    $this->context->db->update('Job', 'jobId', $lastId, $values);
                    $this->context->event('job', $lastId, $action === 'abort' ? 'Job aborted' : 'Job rescheduled', 'The job status changed from ' . $job['status'] . ' to ' . $next . '.');
                }
                $changed += count($jobs);
            } while (count($jobs) === 128);
            return $changed;
        });
        return ['changedJobs' => $changed, 'evaluation' => $this->projection->evaluation($this->context->evaluation($id))];
    }

    public function run(int $id, array $body): array
    {
        $experiment = $this->context->experiment($id);
        if ((bool) $experiment['isArchived']) throw new HttpError(409, 'experiment_archived', 'Restore this experiment before starting another evaluation.');
        $environment = Input::text($body['environment'] ?? null, 'environment', 100);
        $this->environment->assertAvailable($experiment, $environment);
        $definition = $this->definition->decode($experiment);
        $binding = isset($definition['bindingId']) ? (new BenchmarkCatalog($this->context))->resolve($definition['bindingId'], false) : null;
        if ($binding !== null && $binding['systemId'] !== (int) $experiment['systemId']) {
            throw new HttpError(409, 'binding_mismatch', 'The binding does not support this experiment system.');
        }
        $explicit = array_key_exists('jobs', $body);
        if ($explicit && ($binding === null || array_key_exists('versions', $body))) {
            Input::invalid('jobs', 'Use a benchmark binding and supply versions through the explicit job parameters.');
        }
        if (!$explicit && ($definition['planning'] ?? 'generated') === 'explicit') {
            Input::invalid('jobs', 'Supply the explicit job list for this experiment.');
        }
        $plan = new ResolvedJobPlan($this->context, (int) $experiment['systemId'], $environment, (int) $experiment['phases'], $binding);
        $configurations = $explicit ? $this->explicit($body['jobs']) : $this->generated($experiment, $body);
        // Validate the whole evaluation before writing, spilling large plans to
        // temporary storage instead of retaining all expanded specifications.
        $repetitions = new \SplTempFileObject();
        foreach ($configurations as $configuration) {
            $line = json_encode($plan->resolve($configuration, !$explicit), JSON_THROW_ON_ERROR) . "\n";
            if ($repetitions->fwrite($line) !== strlen($line)) throw new \RuntimeException('Unable to stage the evaluation plan.');
        }
        $count = count($configurations);
        unset($configurations);
        $repetitions->rewind();
        $metadata = WorkflowInput::metadata(['name' => $body['name'] ?? date('d.m.Y - H:i'), 'description' => $body['description'] ?? Dto::text($experiment['description'])]);
        $evaluationId = $this->context->db->transaction(function () use ($id, $experiment, $environment, $repetitions, $count, $metadata): int {
            $current = $this->context->experiment($id, true);
            if ((bool) $current['isArchived']) throw new HttpError(409, 'experiment_archived', 'Restore this experiment before starting another evaluation.');
            $next = (int) $this->context->db->row('SELECT COALESCE(MAX(internalId),0)+1 n FROM `Evaluation` WHERE experimentId=?', [$id])['n'];
            $evaluationId = $this->context->db->insert('Evaluation', $metadata + ['systemId' => (int) $experiment['systemId'], 'experimentId' => $id, 'internalId' => $next, 'isArchived' => 0, 'isStarred' => 0]);
            for ($ordinal = 1; $ordinal <= $count; $ordinal++) {
                $resolved = json_decode($repetitions->fgets(), true, 16, JSON_THROW_ON_ERROR);
                $this->schedule($evaluationId, $ordinal, $experiment, $environment, $resolved);
            }
            $this->context->db->update('Experiment', 'experimentId', $id, ['defaultEnvironment' => $environment]);
            $this->context->event('evaluation', $evaluationId, 'Evaluation started', 'A new evaluation was started.');
            return $evaluationId;
        });
        return $this->projection->evaluation($this->context->evaluation($evaluationId));
    }

    private function explicit(mixed $jobs): array
    {
        if (!is_array($jobs) || !array_is_list($jobs) || $jobs === [] || count($jobs) > $this->environment->maxJobs()) {
            Input::invalid('jobs', 'Provide an ordered job list within the configured evaluation limit.');
        }
        foreach ($jobs as $index => &$job) {
            $field = 'jobs.' . $index;
            $job = BenchmarkContract::map($job, $field);
            Input::fields($job, ['parameters']);
            $job['parameters'] = BenchmarkContract::map($job['parameters'] ?? null, $field . '.parameters');
        }
        return $jobs;
    }

    private function generated(array $experiment, array $body): array
    {
        $versions = [];
        if (isset($body['versions'])) {
            if (!is_array($body['versions'])) Input::invalid('versions', 'Provide a map of software parameter version lists.');
            $allowed = array_column($this->environment->options($experiment)['versions'], 'parameter');
            Input::fields($body['versions'], $allowed);
            foreach ($body['versions'] as $parameter => $entries) {
                if (!is_array($entries) || !array_is_list($entries) || $entries === [] || count($entries) > 1000) Input::invalid('versions', 'Choose one or more software versions.');
                $versions[$parameter] = array_map(static fn(mixed $version): string => ParameterCompiler::parameterText($version, 'versions', 256, true), $entries);
            }
        }
        return $this->definition->compile($experiment, $this->environment->maxJobs(), new Extensions($this->context->root, (int) $experiment['systemId']), $versions);
    }

    private function schedule(int $evaluationId, int $internalId, array $experiment, string $environment, array $plan): void
    {
        $jobId = $this->context->db->insert('Job', [
            'userId' => $this->context->actor['id'], 'description' => $plan['description'],
            'systemId' => (int) $experiment['systemId'], 'environment' => $environment, 'phases' => (int) $experiment['phases'],
            'currentPhase' => null, 'configuration' => $plan['configuration'],
            'status' => 0, 'progress' => 0, 'result' => '', 'created' => date('Y-m-d H:i:s'), 'started' => null, 'finished' => null,
            'evaluationId' => $evaluationId, 'internalId' => $internalId, 'configurationIdentifier' => $plan['configurationIdentifier'],
        ]);
        $this->context->db->insert('JobSpecification', [
            'jobId' => $jobId, 'adapter' => $plan['adapter'], 'systemIdentifier' => $plan['systemIdentifier'], 'specification' => $plan['specification'],
        ]);
        $this->context->event('job', $jobId, 'Job created', 'A new job was scheduled for the evaluation.');
    }
}
