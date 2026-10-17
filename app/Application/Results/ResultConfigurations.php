<?php
declare(strict_types=1);

namespace Chronos\Application\Results;

use Chronos\Application\Shared\Context;
use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Systems\{ConfigurationStore, DefinitionValues};
use Chronos\Infrastructure\VersionControl\RepositoryInspector;

final class ResultConfigurations
{
    private readonly ConfigurationStore $files;
    private readonly RepositoryInspector $inspector;

    public function __construct(private readonly Context $context, private readonly string $root)
    {
        $this->files = new ConfigurationStore($context, $root);
        $this->inspector = new RepositoryInspector($root);
    }

    public static function validate(mixed $value, ?Extensions $extensions = null): array
    {
        $document = DefinitionValues::arrays($value);
        if (!is_array($document) || ($document['version'] ?? null) !== '1.0' || !is_array($document['elements'] ?? null) || count($document['elements']) > 1000) Input::invalid('elements', 'Submit a version 1.0 result configuration document.');
        foreach ($document['elements'] as $id => $configuration) {
            DefinitionValues::identifier($id, 'elements.id');
            if (strlen($id) > 50) Input::invalid('elements.id', 'Configuration IDs must fit the 50-character experiment field.');
            if (!is_array($configuration)) Input::invalid('elements.' . $id, 'Submit a configuration object.');
            Input::text($configuration['name'] ?? null, 'elements.' . $id . '.name', 256);
            foreach (['all', 'job'] as $scope) {
                $plots = $configuration[$scope] ?? null;
                if (!is_array($plots) || !array_is_list($plots) || count($plots) > 1000) Input::invalid('elements.' . $id . '.' . $scope, 'Submit a plot array.');
                $seen = [];
                foreach ($plots as $plot) {
                    if (!is_array($plot)) Input::invalid('elements.' . $id, 'Submit plot objects.');
                    $pid = DefinitionValues::identifier($plot['id'] ?? null, 'plot.id');
                    if (isset($seen[$pid])) Input::invalid('plot.id', 'Each plot needs a unique ID within its scope.');
                    $seen[$pid] = true;
                    $type = $plot['type'] ?? null;
                    if (is_string($type) && $extensions !== null) $type = $extensions->plotBase($type);
                    if (!is_string($type) || !isset(Extensions::PLOT_TYPES[$type])) Input::invalid('plot.type', 'This custom plot requires a registered v3 editor and renderer before it can be saved.');
                    Input::text($plot['name'] ?? null, 'plot.name', 256);
                    Input::text($plot['parameter'] ?? null, 'plot.parameter', 1024);
                    if (in_array($type, ['line-plot-multi', 'box-plot'], true)) Input::text($plot['plotting'] ?? null, 'plot.plotting', 256);
                    if ($type === 'box-plot' && !in_array($plot['orientation'] ?? 'vertical', ['vertical', 'horizontal'], true)) Input::invalid('plot.orientation', 'Choose vertical or horizontal.');
                }
            }
        }
        return $document;
    }

    public static function visible(array $document, int $experimentId, string $selectedId = ''): array
    {
        $document['elements'] = array_filter($document['elements'], static fn(string $id): bool => str_starts_with($id, 'system-') || str_starts_with($id, 'experiment-' . $experimentId . '-'), ARRAY_FILTER_USE_KEY);
        return $document;
    }

    public function system(int $id): array
    {
        $this->context->system($id);
        $document = ConfigurationStore::resultDocument($this->root, $id);
        $document['elements'] = (object) $document['elements'];
        return $document;
    }

    public function saveSystem(int $id, mixed $value, string $message = 'Updated result configuration'): array
    {
        $this->context->system($id);

        $document = self::validate($value, new Extensions($this->root, $id));
        $old = ConfigurationStore::resultDocument($this->root, $id);
        foreach (array_diff(array_keys($old['elements']), array_keys($document['elements'])) as $removed) {
            if ($this->context->db->row('SELECT experimentId FROM `Experiment` WHERE systemId=? AND resultId=? LIMIT 1', [$id, $removed])) throw new HttpError(409, 'configuration_in_use', 'A result configuration used by an experiment cannot be deleted.');
        }
        $this->files->writeJson($id, 'resultConfigurations.json', $document, $message);

        return $this->system($id);
    }

    public function migrate(int $id): array
    {
        $this->context->system($id);
        if (!file_exists($this->inspector->directory($id) . '/resultConfigurations.json')) {
            $document = self::validate(ConfigurationStore::resultDocument($this->root, $id), new Extensions($this->root, $id));
            $this->files->writeJson($id, 'resultConfigurations.json', $document, 'Migrated legacy result configuration');
        }
        return ['migrated' => true, 'legacyFilesRetained' => true];
    }

    public function experiment(int $id): array
    {
        $experiment = $this->context->experiment($id);
        $document = self::visible(ConfigurationStore::resultDocument($this->root, (int) $experiment['systemId']), $id);
        $document['elements'] = (object) $document['elements'];
        return $document;
    }

    public function saveExperiment(int $experimentId, mixed $value): array
    {
        $experiment = $this->context->experiment($experimentId);
        $systemId = (int) $experiment['systemId'];

        $submitted = DefinitionValues::arrays($value);
        if (!is_array($submitted['elements'] ?? null)) Input::invalid('elements', 'Submit a configuration document.');
        $prefix = 'experiment-' . $experimentId . '-';
        // Merge inside the file lock so parallel experiments cannot overwrite one another.
        $this->files->writeJson($systemId, 'resultConfigurations.json', function () use ($submitted, $prefix, $experimentId, $systemId): array {
            $this->context->experiment($experimentId);
            $current = ConfigurationStore::resultDocument($this->root, $systemId);
            $owned = ['version' => '1.0', 'elements' => []];
            foreach ($submitted['elements'] as $id => $configuration) {
                if (str_starts_with((string) $id, $prefix)) $owned['elements'][$id] = $configuration;
                elseif (!str_starts_with((string) $id, 'system-') || !isset($current['elements'][$id]) || $configuration !== $current['elements'][$id]) throw new HttpError(403, 'configuration_scope', 'Only this experiment’s own result configurations can be modified.');
            }
            $owned = self::validate($owned, new Extensions($this->root, $systemId));
            foreach (array_keys($current['elements']) as $id) if (str_starts_with($id, $prefix)) {
                if (!isset($owned['elements'][$id]) && $this->context->db->row('SELECT experimentId FROM `Experiment` WHERE systemId=? AND resultId=? LIMIT 1', [$systemId, $id])) throw new HttpError(409, 'configuration_in_use', 'Select a different result configuration before deleting one that is in use.');
                unset($current['elements'][$id]);
            }
            $current['elements'] += $owned['elements'];
            return $current;
        }, 'Updated experiment result configurations');

        return $this->experiment($experimentId);
    }
}
