<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Shared\Context;
use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;

/** Available execution environments and software versions for an authorized workflow. */
final class WorkflowEnvironment
{
    public function __construct(private readonly Context $context, private readonly ExperimentDefinition $definition)
    {
    }

    public function maxJobs(): int
    {
        return max(1, min(100000, (int) $this->context->setting(0, 'other', 'maxJobsPerEvaluation', '1000')));
    }

    public function options(array $experiment): array
    {
        $db = $this->context->db;
        $systemId = (int) $experiment['systemId'];
        $system = $db->row('SELECT cem FROM `System` WHERE systemId=?', [$systemId]);
        $environments = [];
        foreach ($db->rows("SELECT item FROM `Setting` WHERE systemId=? AND section='environments' ORDER BY item", [$systemId]) as $row) {
            $environments[] = ['key' => 'system-' . $row['item'], 'name' => $row['item'], 'type' => 'system'];
        }
        if ($system && (bool) $system['cem']) {
            $cem = json_decode($this->context->setting(0, 'cem', 'environments', '[]'), true);
            $timeout = max(1, (int) $this->context->setting(0, 'cem', 'nodeMissingTimeout', '300'));
            foreach (is_array($cem) ? $cem : [] as $name) {
                if (!is_string($name)) continue;
                $column = preg_match('/[\x{10000}-\x{10FFFF}]/u', $name) ? 'CONVERT(environment USING utf8mb4)' : 'environment';
                $count = (int) $db->row('SELECT COUNT(*) n FROM `Node` WHERE ' . $column . '=? AND lastUpdate>?', [$name, date('Y-m-d H:i:s', time() - $timeout)])['n'];
                $environments[] = ['key' => 'cem-' . $name, 'name' => $name, 'type' => 'cem', 'nodeCount' => $count];
            }
        }
        $values = $this->definition->values($experiment);
        $versions = [];
        $extensions = new Extensions($this->context->root, $systemId);
        foreach ($this->definition->elements($experiment, $extensions) as $element) {
            try { $type = $extensions->parameterBase((string) ($element['type'] ?? '')); }
            catch (HttpError) { continue; }
            if ($type !== 'software-versions-list') continue;
            $parameter = $element['parameter'];
            $count = (int) ($values[$parameter . '-numberOfVersions'] ?? 0);
            $entries = [];
            for ($index = 0; $index < $count && $index < 1000; $index++) $entries[] = (string) ($values[$parameter . '-version-' . $index] ?? '');
            $versions[] = ['parameter' => $parameter, 'label' => $parameter, 'values' => $entries];
        }
        return ['environments' => $environments, 'versions' => $versions,
            'defaultEnvironment' => $experiment['defaultEnvironment'] ?? ($environments[0]['key'] ?? null)];
    }

    public function assertAvailable(array $experiment, string $environment): void
    {
        $options = $this->options($experiment + ['postData' => '{}', 'defaultEnvironment' => null]);
        if (!in_array($environment, array_column($options['environments'], 'key'), true)) {
            Input::invalid('environment', 'Choose an available environment for this system.');
        }
    }
}
