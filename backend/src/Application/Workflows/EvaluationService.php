<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Application\Shared\TextSearch;
use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Infrastructure\Persistence\DatabaseJson;

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
            $jobs = $this->context->db->rows('SELECT jobId,status FROM `Job` WHERE evaluationId=? AND status IN (' . implode(',', $statuses) . ') FOR UPDATE', [$id]);
            foreach ($jobs as $job) {
                $values = ['status' => $next];
                if ($next === 0) $values += ['currentPhase' => null, 'progress' => 0, 'started' => null, 'finished' => null];
                $this->context->db->update('Job', 'jobId', (int) $job['jobId'], $values);
                $this->context->event('job', (int) $job['jobId'], $action === 'abort' ? 'Job aborted' : 'Job rescheduled', 'The job status changed from ' . $job['status'] . ' to ' . $next . '.');
            }
            return count($jobs);
        });
        return ['changedJobs' => $changed, 'evaluation' => $this->projection->evaluation($this->context->evaluation($id))];
    }

    public function run(int $id, array $body): array
    {
        $experiment = $this->context->experiment($id);
        if ((bool) $experiment['isArchived']) throw new HttpError(409, 'experiment_archived', 'Restore this experiment before starting another evaluation.');
        $environment = Input::text($body['environment'] ?? null, 'environment', 100);
        $this->environment->assertAvailable($experiment, $environment);
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
        $configurations = $this->definition->compile($experiment, $this->environment->maxJobs(), new Extensions($this->context->root, (int) $experiment['systemId']), $versions);
        $metadata = WorkflowInput::metadata(['name' => $body['name'] ?? date('d.m.Y - H:i'), 'description' => $body['description'] ?? Dto::text($experiment['description'])]);
        $evaluationId = $this->context->db->transaction(function () use ($id, $experiment, $environment, $configurations, $metadata): int {
            $current = $this->context->experiment($id, true);
            if ((bool) $current['isArchived']) throw new HttpError(409, 'experiment_archived', 'Restore this experiment before starting another evaluation.');
            $next = (int) $this->context->db->row('SELECT COALESCE(MAX(internalId),0)+1 n FROM `Evaluation` WHERE experimentId=?', [$id])['n'];
            $evaluationId = $this->context->db->insert('Evaluation', $metadata + ['systemId' => (int) $experiment['systemId'], 'experimentId' => $id, 'internalId' => $next, 'isArchived' => 0, 'isStarred' => 0]);
            foreach ($configurations as $index => $configuration) $this->schedule($evaluationId, $index + 1, $experiment, $environment, $configuration);
            $this->context->db->update('Experiment', 'experimentId', $id, ['defaultEnvironment' => $environment]);
            $this->context->event('evaluation', $evaluationId, 'Evaluation started', 'A new evaluation was started.');
            return $evaluationId;
        });
        return $this->projection->evaluation($this->context->evaluation($evaluationId));
    }

    private function schedule(int $evaluationId, int $internalId, array $experiment, string $environment, array $configuration): void
    {
        $parameters = $configuration['parameters'];
        $identity = $parameters;
        unset($identity['run']);
        $labels = [];
        foreach ($parameters as $key => $value) $labels[] = $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value));
        $configuration['title'] = 'Job[' . implode(', ', $labels) . ']';
        $jobId = $this->context->db->insert('Job', [
            'userId' => $this->context->actor['id'], 'description' => Dto::storedText($configuration['title'], 'parameters', 65535),
            'systemId' => (int) $experiment['systemId'], 'environment' => $environment, 'phases' => (int) $experiment['phases'],
            'currentPhase' => null, 'configuration' => DatabaseJson::encode($configuration, 'parameters'),
            'status' => 0, 'progress' => 0, 'result' => '', 'created' => date('Y-m-d H:i:s'), 'started' => null, 'finished' => null,
            'evaluationId' => $evaluationId, 'internalId' => $internalId, 'configurationIdentifier' => sha1(json_encode($identity, JSON_THROW_ON_ERROR)),
        ]);
        $this->context->event('job', $jobId, 'Job created', 'A new job was scheduled for the evaluation.');
    }
}
