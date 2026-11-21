<?php
declare(strict_types=1);

namespace Chronos\Application\Benchmarks;

use Chronos\Application\Security\Policy;
use Chronos\Application\Shared\{Context, Dto, Input};
use Chronos\Application\Systems\ConfigurationStore;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\DatabaseJson;
use Chronos\Infrastructure\VersionControl\RepositoryInspector;

final class BenchmarkCatalog
{
    private const BINDINGS = 'SELECT b.*,r.benchmarkKey,r.version benchmarkVersion,r.name benchmarkName,r.contract,s.name systemName,s.isArchived FROM BenchmarkBinding b JOIN BenchmarkRevision r ON r.benchmarkRevisionId=b.benchmarkRevisionId JOIN `System` s ON s.systemId=b.systemId';

    public function __construct(private readonly Context $context)
    {
    }

    public function list(array $query = []): array
    {
        Input::fields($query, ['page', 'pageSize']);
        $page = Input::integer($query['page'] ?? 1, 'page', 1, 1000000);
        $size = Input::integer($query['pageSize'] ?? 5, 'pageSize', 1, 100);
        $limit = ' LIMIT ' . ($size + 1) . ' OFFSET ' . (($page - 1) * $size);
        $db = $this->context->db;
        $scope = Policy::admin($this->context->actor) ? '' : ' AND (s.userId=? OR EXISTS(SELECT 1 FROM SystemMaintainer m WHERE m.systemId=s.systemId AND m.userId=?))';
        $args = $scope === '' ? [] : array_fill(0, 2, $this->context->actor['id']);
        $benchmarks = $db->rows('SELECT * FROM BenchmarkRevision ORDER BY benchmarkKey,benchmarkRevisionId DESC' . $limit);
        $bindings = $db->rows(self::BINDINGS . ' WHERE s.isArchived=0' . $scope . ' ORDER BY r.name,s.name,b.bindingId DESC' . $limit, $args);
        $systems = $db->rows('SELECT s.systemId,s.name FROM `System` s WHERE s.isArchived=0' . $scope . ' ORDER BY s.name,s.systemId' . $limit, $args);
        $more = max(count($benchmarks), count($bindings), count($systems)) > $size;
        return [
            'benchmarks' => array_map(static fn(array $row): array => self::benchmarkDocument(self::benchmark($row)), array_slice($benchmarks, 0, $size)),
            'bindings' => array_map(static fn(array $row): array => self::bindingDocument(self::binding($row)), array_slice($bindings, 0, $size)),
            'systems' => array_map(static fn(array $row): array => ['id' => (int) $row['systemId'], 'name' => Dto::text($row['name'])], array_slice($systems, 0, $size)),
            'page' => $page, 'pageSize' => $size, 'nextPage' => $more && $page < 1000000 ? $page + 1 : null,
        ];
    }

    public function publishBenchmark(array $body): array
    {
        Input::fields($body, ['key', 'version', 'name', 'contract']);
        $contract = BenchmarkContract::validate(BenchmarkContract::map($body['contract'] ?? null, 'contract'));
        $row = [
            'benchmarkKey' => self::version($body['key'] ?? null, 'key'),
            'version' => self::version($body['version'] ?? null, 'version'),
            'name' => Input::text($body['name'] ?? null, 'name', 250),
            'contract' => DatabaseJson::encode(self::contractDocument($contract), 'contract'),
        ];
        $row['benchmarkRevisionId'] = $this->insertRevision('BenchmarkRevision', $row);
        return self::benchmarkDocument(self::benchmark($row));
    }

    public function publishBinding(int $benchmarkId, array $body): array
    {
        Input::fields($body, ['systemId', 'version', 'definition']);
        $systemId = Input::id($body['systemId'] ?? null, 'systemId');
        $this->context->system($systemId);
        $benchmark = $this->context->db->row('SELECT * FROM BenchmarkRevision WHERE benchmarkRevisionId=?', [$benchmarkId])
            ?? throw new HttpError(404, 'not_found', 'The benchmark version does not exist.');
        $definition = AdapterBinding::validate(BenchmarkContract::map($body['definition'] ?? null, 'definition'), self::benchmark($benchmark)['contract']);
        $definition['systemIdentifier'] = self::systemIdentifier($this->context->root, $systemId);
        $row = [
            'benchmarkRevisionId' => $benchmarkId,
            'systemId' => $systemId,
            'version' => self::version($body['version'] ?? null, 'version'),
            'definition' => DatabaseJson::encode(self::definitionDocument($definition), 'definition'),
        ];
        $id = $this->context->db->transaction(function () use ($systemId, $row): int {
            if ((bool) $this->context->system($systemId, true)['isArchived']) Input::invalid('systemId', 'Choose a current system.');
            return $this->insertRevision('BenchmarkBinding', $row);
        });
        return self::bindingDocument($this->resolve($id, false));
    }

    public function resolve(int $id, bool $requireSystemPermission): array
    {
        $row = $this->context->db->row(self::BINDINGS . ' WHERE b.bindingId=?', [$id]);
        if ($row === null || (bool) $row['isArchived']) Input::invalid('bindingId', 'Choose a supported binding for a current system.');
        if ($requireSystemPermission) $this->context->system((int) $row['systemId']);
        return self::binding($row);
    }

    public static function systemIdentifier(string $root, int $id): string
    {
        $directory = (new RepositoryInspector($root))->directory($id);
        $document = ConfigurationStore::readJson($directory . '/config.json');
        $identifier = $document['identifier'] ?? null;
        if (!is_string($identifier) || strlen($identifier) > 100 || trim($identifier) === '' || str_contains($identifier, ',')) {
            throw new HttpError(409, 'system_identifier_unavailable', 'The system needs a valid agent identifier in config.json.');
        }
        return Input::text($identifier, 'systemIdentifier', 100);
    }

    private function insertRevision(string $table, array $row): int
    {
        try {
            return $this->context->db->insert($table, $row);
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) throw $error;
            throw new HttpError(409, 'version_exists', 'This version already exists. Publish a new version to change its definition.');
        }
    }

    private static function version(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]{0,99}$/D', $value)) {
            Input::invalid($field, 'Use a version identifier of up to 100 characters.');
        }
        return $value;
    }

    /** Keep map fields as JSON objects, including maps in revisions saved before this normalization. */
    public static function benchmarkDocument(array $benchmark): array
    {
        $benchmark['contract'] = self::contractDocument($benchmark['contract']);
        return $benchmark;
    }

    public static function bindingDocument(array $binding): array
    {
        $binding['benchmark'] = self::benchmarkDocument($binding['benchmark']);
        $binding['definition'] = self::definitionDocument($binding['definition']);
        return $binding;
    }

    private static function contractDocument(array $contract): array
    {
        $contract['parameters'] = (object) $contract['parameters'];
        $contract['results'] = (object) $contract['results'];
        return $contract;
    }

    private static function definitionDocument(array $definition): array
    {
        $definition['parameterMap'] = (object) $definition['parameterMap'];
        $definition['constants'] = (object) $definition['constants'];
        return $definition;
    }

    private static function benchmark(array $row): array
    {
        return ['id' => (int) $row['benchmarkRevisionId'], 'key' => $row['benchmarkKey'], 'version' => $row['version'],
            'name' => $row['name'], 'contract' => json_decode($row['contract'], true, 32, JSON_THROW_ON_ERROR)];
    }

    private static function binding(array $row): array
    {
        return [
            'id' => (int) $row['bindingId'], 'version' => $row['version'],
            'systemId' => (int) $row['systemId'], 'systemName' => Dto::text($row['systemName']),
            'benchmark' => self::benchmark(['version' => $row['benchmarkVersion'], 'name' => $row['benchmarkName']] + $row),
            'definition' => json_decode($row['definition'], true, 32, JSON_THROW_ON_ERROR),
        ];
    }
}
