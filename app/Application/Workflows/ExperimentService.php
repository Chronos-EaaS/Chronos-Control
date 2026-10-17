<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Systems\ConfigurationStore;

/** Experiment drafts, copy options and persisted definitions. */
final class ExperimentService
{
    public function __construct(
        private readonly Context $context,
        private readonly WorkflowProjection $projection,
        private readonly ExperimentDefinition $definition,
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    public function list(array $project, array $options): array
    {
        $where = 'x.projectId=?';
        $args = [$project['projectId']];
        WorkflowFilters::append($options, 'x', $where, $args);
        return $this->context->page(
            'FROM `Experiment` x JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=x.systemId',
            $where, $args, $options, 'x.*,p.name projectName,s.name systemName',
            fn(array $row): array => $this->projection->experiment($row), 'x.experimentId',
        );
    }

    public function update(int $id, array $body): array
    {
        $this->context->db->transaction(function () use ($id, $body): void {
            $experiment = $this->context->experiment($id, true);
            $changes = WorkflowInput::metadata($body, 50);
            if (array_key_exists('archived', $body)) {
                $archive = Input::boolean($body['archived'], 'archived');
                if ($archive && $this->context->db->row('SELECT j.jobId FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId WHERE e.experimentId=? AND j.status IN (-2,0,1,2) LIMIT 1', [$id])) {
                    throw new HttpError(409, 'evaluation_running', 'Finish or abort the active evaluations before archiving this experiment.');
                }
                $changes['isArchived'] = (int) $archive;
            }
            if (array_key_exists('resultId', $body)) {
                $result = Input::text($body['resultId'], 'resultId', 50, false);
                $config = ConfigurationStore::resultDocument($this->context->root, (int) $experiment['systemId']);
                if ($result !== '' && (!isset($config['elements'][$result])
                    || (!str_starts_with($result, 'system-') && !str_starts_with($result, 'experiment-' . $id . '-')))) {
                    Input::invalid('resultId', 'Choose an available result configuration.');
                }
                $changes['resultId'] = $result;
            }
            if (array_key_exists('defaultEnvironment', $body)) {
                $selected = Input::text($body['defaultEnvironment'], 'defaultEnvironment', 100);
                $this->environment->assertAvailable($experiment, $selected);
                $changes['defaultEnvironment'] = $selected;
            }
            $this->context->db->update('Experiment', 'experimentId', $id, $changes);
            $this->context->event('experiment', $id, 'Experiment updated', 'The experiment settings were updated.');
        });
        return $this->projection->experiment($this->context->experiment($id));
    }

    public function options(array $project, ?int $copyId): array
    {
        $systemId = (int) $project['systemId'];
        $groups = ConfigurationStore::parameterGroups($this->context->root, $systemId);
        $values = [];
        $warmUp = $this->context->setting($systemId, 'defaultValues', 'phase_warmUp', '1');
        $environment = $this->context->setting($systemId, 'defaultValues', 'environment', '');
        $available = $this->environment->options(['systemId' => $systemId, 'postData' => '{}', 'defaultEnvironment' => null]);
        $keys = array_column($available['environments'], 'key');
        // Historical settings contain a local name; execution requests use a typed key.
        if (!in_array($environment, $keys, true) && in_array('system-' . $environment, $keys, true)) $environment = 'system-' . $environment;
        if (!in_array($environment, $keys, true)) $environment = $available['environments'][0]['key'] ?? null;
        $defaults = ['runs' => 1, 'distribution' => 'alter', 'warmUp' => !in_array(strtolower($warmUp), ['0', 'false', 'off', 'unchecked', ''], true), 'environment' => $environment];
        if ($copyId !== null) {
            $source = $this->context->experiment($copyId);
            if ((int) $source['systemId'] !== $systemId) Input::invalid('copy', 'Choose an experiment for the same system.');
            $groups = $this->definition->groups($source);
            $values = $this->definition->values($source);
            $defaults = $this->definition->execution($source) + ['environment' => $source['defaultEnvironment']];
        }
        return ['project' => Dto::project($project, $this->context->actor), 'groups' => $groups, 'values' => $values,
            'defaults' => $defaults, 'maxJobs' => $this->environment->maxJobs()];
    }

    public function create(array $project, array $body): array
    {
        $source = null;
        if (isset($body['sourceExperimentId'])) {
            $source = $this->context->experiment(Input::id($body['sourceExperimentId'], 'sourceExperimentId'));
            if ((int) $source['systemId'] !== (int) $project['systemId']) Input::invalid('sourceExperimentId', 'Choose an experiment for this system.');
        }
        $metadata = WorkflowInput::metadata(['name' => $body['name'] ?? null, 'description' => $body['description'] ?? ''], 50);
        $groups = $source !== null ? $this->definition->groups($source) : ConfigurationStore::parameterGroups($this->context->root, (int) $project['systemId']);
        $runs = Input::integer($body['runs'] ?? 1, 'runs', 1, $this->environment->maxJobs());
        $distribution = $body['distribution'] ?? 'alter';
        if (!in_array($distribution, ['order', 'alter', 'rand'], true)) Input::invalid('distribution', 'Choose a supported run distribution.');
        $warmUp = isset($body['warmUp']) ? Input::boolean($body['warmUp'], 'warmUp') : true;
        $environment = isset($body['environment']) ? Input::text($body['environment'], 'environment', 100) : null;
        if ($environment !== null) $this->environment->assertAvailable(['systemId' => $project['systemId']], $environment);
        $extensions = new Extensions($this->context->root, (int) $project['systemId']);
        $values = ParameterCompiler::values($groups, $body['parameters'] ?? [], $extensions);
        $document = ExperimentDefinition::document($groups, $values, $runs, $distribution, $warmUp);
        ParameterCompiler::compile($this->context->root, $groups, $values, $document['execution'], $this->environment->maxJobs(), $extensions);
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 65535) Input::invalid('parameters', 'The saved experiment exceeds the storage limit.');
        $id = $this->context->db->transaction(function () use ($project, $metadata, $json, $warmUp, $environment): int {
            $this->context->project((int) $project['projectId'], true);
            $next = (int) $this->context->db->row('SELECT COALESCE(MAX(internalId),0)+1 n FROM `Experiment` WHERE projectId=?', [$project['projectId']])['n'];
            $id = $this->context->db->insert('Experiment', $metadata + [
                'userId' => $this->context->actor['id'], 'systemId' => (int) $project['systemId'], 'phases' => $warmUp ? 0 : 2,
                'status' => 0, 'created' => date('Y-m-d H:i:s'), 'projectId' => (int) $project['projectId'], 'postData' => $json,
                'internalId' => $next, 'isArchived' => 0, 'resultId' => '', 'defaultEnvironment' => $environment,
            ]);
            $this->context->event('experiment', $id, 'Experiment created', 'A new experiment was created.');
            return $id;
        });
        return $this->projection->experiment($this->context->experiment($id));
    }
}
