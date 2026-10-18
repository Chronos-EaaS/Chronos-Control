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
        $resource = $this->context->evaluation($id);
        $rows = $this->context->db->rows('SELECT * FROM `Job` WHERE evaluationId=? ORDER BY internalId,jobId', [$id]);
        return $this->present($resource, $rows, false, $query);
    }

    public function job(int $id, array $query): array
    {
        $resource = $this->context->job($id);
        return $this->present($resource, [$resource], true, $query);
    }

    private static function jsonObject(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        try { $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : []; }
        catch (\JsonException) { return []; }
    }

    private function present(array $resource, array $rows, bool $single, array $query): array
    {
        Input::fields($query, ['configurationId', 'page', 'pageSize', 'search', 'sort']);
        $configurationQuery = $query['configurationId'] ?? null; unset($query['configurationId']);
        $options = $this->context->options($query, ['id' => 'id', 'internalId' => 'internalId', 'name' => 'label', 'parameters' => 'parameters', 'result' => 'result', 'status' => 'status', 'finishedAt' => 'finishedAt']);
        $systemId = (int) $resource['systemId'];
        $document = ConfigurationStore::resultDocument($this->root, $systemId);
        if ($single) $resource['resultId'] = $this->context->experiment((int) $resource['experimentId'])['resultId'];
        $document = ResultConfigurations::visible($document, (int) $resource['experimentId']);
        $configurationId = $configurationQuery ?? $resource['resultId'] ?? '';
        if (!is_string($configurationId)) Input::invalid('configurationId', 'Choose a result configuration.');
        if ($configurationId === '' && count($document['elements']) === 1) $configurationId = (string) array_key_first($document['elements']);
        $configuration = $document['elements'][$configurationId] ?? null;
        if ($configuration === null && $configurationId !== '') throw new HttpError(404, 'configuration_not_found', 'The selected result configuration does not exist.');
        $groups = $raw = []; $finished = 0;
        foreach ($rows as $row) {
            $config = self::jsonObject($row['configuration']);
            $result = self::jsonObject($row['result']);
            $raw[] = ['id' => (int) $row['jobId'], 'internalId' => (int) $row['internalId'], 'label' => Dto::text($row['description']), 'parameters' => (object) ($config['parameters'] ?? []), 'result' => (object) $result, 'status' => (int) $row['status'], 'finishedAt' => Dto::instant($row['finished'] ?? null), 'downloadUrl' => '/api/ui/jobs/' . (int) $row['jobId'] . '/download'];
            if ((int) $row['status'] !== 3) continue;
            ++$finished;
            $job = ResultJobs::fromRow($row, $config);
            $parameters = $config['parameters'] ?? []; unset($parameters['run']); ksort($parameters);
            $key = json_encode($parameters, JSON_THROW_ON_ERROR);
            $groups[$key][] = $job;
        }
        $groups = array_values($groups); $charts = []; $warnings = [];
        if (!file_exists($this->inspector->directory($systemId) . '/resultConfigurations.json') && $document['elements'] !== []) $warnings[] = 'Legacy result configuration is displayed without modifying system files. A system owner can migrate it explicitly.';
        if ($configuration !== null) {
            if (!$single) foreach ($configuration['all'] ?? [] as $definition) $charts[] = $this->chart($systemId, $definition, $groups, 'all');
            foreach ($groups as $group) foreach ($configuration['job'] ?? [] as $definition) $charts[] = $this->chart($systemId, $definition, [$group], 'job', (string) ($group[0]->getConfiguration()['title'] ?? 'Job configuration'));
        }
        if ($options['search'] !== '') { $needle = mb_strtolower($options['search'], 'UTF-8'); $raw = array_values(array_filter($raw, static fn(array $job): bool => str_contains(mb_strtolower(json_encode([$job['id'], $job['internalId'], $job['label'], $job['parameters'], $job['result'], $job['status'], $job['finishedAt'], $job['finishedAt'] === null ? '' : (new \DateTimeImmutable($job['finishedAt']))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i:s')], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'UTF-8'), $needle))); }
        $sort = (string) ($query['sort'] ?? 'id'); $key = ['id' => 'id', 'internalId' => 'internalId', 'name' => 'label', 'parameters' => 'parameters', 'result' => 'result', 'status' => 'status', 'finishedAt' => 'finishedAt'][ltrim($sort, '-')]; $direction = str_starts_with($sort, '-') ? -1 : 1;
        usort($raw, static function (array $left, array $right) use ($key, $direction): int {
            $a = $left[$key]; $b = $right[$key];
            if (in_array($key, ['parameters', 'result'], true)) { $a = json_encode($a, JSON_UNESCAPED_UNICODE); $b = json_encode($b, JSON_UNESCAPED_UNICODE); }
            $comparison = $key === 'finishedAt' ? ($a === null ? PHP_INT_MIN : strtotime($a)) <=> ($b === null ? PHP_INT_MIN : strtotime($b)) : (is_int($a) && is_int($b) ? $a <=> $b : strnatcasecmp((string) $a, (string) $b));
            return $direction * $comparison ?: $left['id'] <=> $right['id'];
        });
        $total = count($raw); $page = min($options['page'], max(1, (int) ceil($total / $options['pageSize']))); $raw = array_slice($raw, ($page - 1) * $options['pageSize'], $options['pageSize']);
        return ['configurationId' => $configurationId, 'experimentId' => (int) $resource['experimentId'], 'configurations' => array_map(static fn($id, $entry): array => ['id' => (string) $id, 'name' => Dto::text((string) $entry['name'])], array_keys($document['elements']), array_values($document['elements'])), 'finishedJobs' => $finished, 'totalJobs' => count($rows), 'activeJobs' => count(array_filter($rows, static fn(array $row): bool => (int) $row['status'] >= 0 && (int) $row['status'] < 3)), 'charts' => $charts, 'jobs' => $raw, 'jobPage' => ['total' => $total, 'page' => $page, 'pageSize' => $options['pageSize'], 'sort' => $sort], 'warnings' => $warnings];
    }

    private function chart(int $systemId, array $definition, array $groups, string $scope, string $label = ''): array
    {
        $ids = []; foreach ($groups as $group) foreach ($group as $job) $ids[] = (int) $job->getId();
        $chart = ['id' => (string) ($definition['id'] ?? 'missing') . ($scope === 'job' ? '-' . ($ids[0] ?? 'empty') : ''), 'title' => Dto::text((string) ($definition['name'] ?? 'Untitled plot')), 'definition' => $definition, 'scope' => $scope, 'groupLabel' => $label, 'jobIds' => $ids, 'data' => ['labels' => [], 'datasets' => []]];
        try {
            if (!is_string($definition['type'] ?? null)) throw new HttpError(422, 'extension_unavailable', 'The plot type is missing.');
            $extensions = new Extensions($this->root, $systemId);
            $baseType = $extensions->plotBase($definition['type']);
            $chart['renderer'] = Extensions::PLOT_TYPES[$baseType];
            $chart['definition']['baseType'] = $baseType;
            if ($groups === []) return $chart;
            if (!BuiltinPlots::supports($definition['type'])) $chart['data'] = $extensions->plotData($definition['type'], $groups, $definition);
            else {
                $chart['data'] = BuiltinPlots::render($baseType, $groups, $definition);
            }
        } catch (HttpError $error) { $chart['error'] = $error->errorCode === 'extension_unavailable' ? $error->getMessage() : 'The plot adapter returned invalid data. Its definition has been preserved.'; }
        catch (\Throwable) { $chart['error'] = 'The plot data could not be calculated. Check the configured metric names and available results.'; }
        return $chart;
    }
}
