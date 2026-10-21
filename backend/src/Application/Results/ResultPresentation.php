<?php
declare(strict_types=1);

namespace Chronos\Application\Results;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Systems\ConfigurationStore;
use Chronos\Infrastructure\VersionControl\RepositoryInspector;
use Chronos\Domain\Results\BuiltinPlots;

final class ResultPresentation
{
    private readonly RepositoryInspector $inspector;

    public function __construct(private readonly Context $context, private readonly string $root) { $this->inspector = new RepositoryInspector($root); }

    public function evaluation(int $id, array $query): array
    {
        return $this->present($this->context->evaluation($id), null, false, $query);
    }

    public function job(int $id, array $query): array
    {
        $resource = $this->context->job($id);
        return $this->present($resource, [$resource], true, $query);
    }

    private function present(array $resource, ?array $rows, bool $single, array $query): array
    {
        Input::fields($query, ['configurationId', 'view', 'page', 'pageSize', 'search', 'sort']);
        $view = $query['view'] ?? 'combined';
        if (!is_string($view) || !in_array($view, ['combined', 'charts', 'raw'], true)) Input::invalid('view', 'Choose charts or raw results.');
        $configurationQuery = $query['configurationId'] ?? null;
        if ($configurationQuery !== null && !is_string($configurationQuery)) Input::invalid('configurationId', 'Choose a result configuration.');
        unset($query['configurationId'], $query['view']);
        $options = $view === 'charts' ? Input::listOptions($query, ResultRows::SORTS) : $this->context->options($query, ResultRows::SORTS);
        $sort = (string) ($query['sort'] ?? 'id');
        $evaluationId = (int) ($resource['evaluationId'] ?? 0);
        if (!$single && $view === 'combined') $rows = $this->context->db->rows('SELECT * FROM `Job` WHERE evaluationId=? ORDER BY internalId,jobId', [$evaluationId]);
        $raw = new ResultRows($this->context);
        $summary = $raw->summary($evaluationId, $rows);
        $response = ['configurationId'=>'', 'experimentId'=>(int) $resource['experimentId'], 'configurations'=>[], ...$summary, 'charts'=>[], 'jobs'=>[], 'warnings'=>[]];
        // Keep the legacy combined response stable. Explicit views avoid both
        // transferring raw JSON with charts and recalculating charts on a raw
        // search/page change. No result configuration is needed to inspect raw data.
        if ($view === 'raw') {
            $page = $raw->page($evaluationId, $options, $sort, $summary['totalJobs'], $rows);
            return array_replace($response, $page);
        }
        $systemId = (int) $resource['systemId'];
        // Keep the extension boundary's ancestor isolation even when built-in
        // charts need no manifest discovery. Raw DB-only browsing stays usable.
        if (is_link($this->root . '/webroot') || is_link($this->root . '/webroot/systems')) {
            throw new HttpError(409, 'system_files_unavailable', 'The isolated system directory is unavailable.');
        }
        $document = ConfigurationStore::resultDocument($this->root, $systemId);
        if ($single) $resource['resultId'] = $this->context->experiment((int) $resource['experimentId'])['resultId'];
        $document = ResultConfigurations::visible($document, (int) $resource['experimentId']);
        $configurationId = $configurationQuery ?? $resource['resultId'] ?? '';
        if (!is_string($configurationId)) Input::invalid('configurationId', 'Choose a result configuration.');
        if ($configurationId === '' && count($document['elements']) === 1) $configurationId = (string) array_key_first($document['elements']);
        $configuration = $document['elements'][$configurationId] ?? null;
        if ($configuration === null && $configurationId !== '') throw new HttpError(404, 'configuration_not_found', 'The selected result configuration does not exist.');
        $response['configurationId'] = $configurationId;
        $response['configurations'] = array_map(static fn($id, $entry): array => ['id'=>(string) $id, 'name'=>Dto::text((string) $entry['name'])], array_keys($document['elements']), array_values($document['elements']));
        if (!file_exists($this->inspector->directory($systemId) . '/resultConfigurations.json') && $document['elements'] !== []) $response['warnings'][] = 'Legacy result configuration is displayed without modifying system files. A system owner can migrate it explicitly.';
        if ($configuration !== null && ((!$single && ($configuration['all'] ?? []) !== []) || ($configuration['job'] ?? []) !== [])) {
            $rows ??= $summary['finishedJobs'] > 0 ? $this->context->db->rows('SELECT * FROM `Job` WHERE evaluationId=? AND status=3 ORDER BY internalId,jobId', [$evaluationId]) : [];
            $groups = [];
            foreach ($rows as $row) {
                if ((int) $row['status'] !== 3) continue;
                $config = ResultRows::jsonObject($row['configuration']);
                $parameters = $config['parameters'] ?? []; unset($parameters['run']); ksort($parameters);
                $groups[json_encode($parameters, JSON_THROW_ON_ERROR)][] = ResultJobs::fromRow($row, $config);
            }
            $groups = array_values($groups);
            $extensions = null; $decoded = [];
            if (!$single) foreach ($configuration['all'] ?? [] as $definition) $response['charts'][] = $this->chart($systemId, $definition, $groups, 'all', $extensions, $decoded);
            foreach ($groups as $group) foreach ($configuration['job'] ?? [] as $definition) $response['charts'][] = $this->chart($systemId, $definition, [$group], 'job', $extensions, $decoded, (string) ($group[0]->getConfiguration()['title'] ?? 'Job configuration'));
        }
        if ($view === 'combined') $response = array_replace($response, $raw->page($evaluationId, $options, $sort, $summary['totalJobs'], $rows));
        return $response;
    }

    private function chart(int $systemId, array $definition, array $groups, string $scope, ?Extensions &$extensions, array &$decoded, string $label = ''): array
    {
        $ids = []; foreach ($groups as $group) foreach ($group as $job) $ids[] = (int) $job->getId();
        $chart = ['id' => (string) ($definition['id'] ?? 'missing') . ($scope === 'job' ? '-' . ($ids[0] ?? 'empty') : ''), 'title' => Dto::text((string) ($definition['name'] ?? 'Untitled plot')), 'definition' => $definition, 'scope' => $scope, 'groupLabel' => $label, 'jobIds' => $ids, 'data' => ['labels' => [], 'datasets' => []]];
        try {
            if (!is_string($definition['type'] ?? null)) throw new HttpError(422, 'extension_unavailable', 'The plot type is missing.');
            $builtin = BuiltinPlots::supports($definition['type']);
            if (!$builtin) $extensions ??= new Extensions($this->root, $systemId);
            $baseType = $builtin ? $definition['type'] : $extensions->plotBase($definition['type']);
            $chart['renderer'] = Extensions::PLOT_TYPES[$baseType];
            $chart['definition']['baseType'] = $baseType;
            if ($groups === []) return $chart;
            if (!$builtin) {
                // A trusted processor can normalize its in-memory models. A
                // following built-in plot must observe those changes as before.
                try { $chart['data'] = $extensions->plotData($definition['type'], $groups, $definition); }
                finally { $decoded = []; }
            } else {
                $chart['data'] = BuiltinPlots::render($baseType, ResultJobs::forBuiltinPlots($groups, $decoded), $definition);
            }
        } catch (HttpError $error) { $chart['error'] = $error->errorCode === 'extension_unavailable' ? $error->getMessage() : 'The plot adapter returned invalid data. Its definition has been preserved.'; }
        catch (\Throwable) { $chart['error'] = 'The plot data could not be calculated. Check the configured metric names and available results.'; }
        return $chart;
    }
}
