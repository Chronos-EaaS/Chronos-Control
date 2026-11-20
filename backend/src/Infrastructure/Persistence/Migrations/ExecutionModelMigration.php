<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Migrations;

use PDO;

/** Add opt-in execution evidence without rewriting historical job rows. */
final class ExecutionModelMigration implements Migration
{
    public const VERSION = '202611200001_execution_model';

    private const TABLES = [
        'BenchmarkRevision' => [
            'benchmarkRevisionId INT NOT NULL AUTO_INCREMENT',
            'benchmarkKey VARCHAR(100) COLLATE utf8mb4_bin NOT NULL',
            'version VARCHAR(100) COLLATE utf8mb4_bin NOT NULL',
            'name VARCHAR(250) NOT NULL',
            'contract TEXT NOT NULL',
            'PRIMARY KEY (benchmarkRevisionId)',
            'UNIQUE KEY chronos_benchmark_version (benchmarkKey,version)',
        ],
        'BenchmarkBinding' => [
            'bindingId INT NOT NULL AUTO_INCREMENT',
            'benchmarkRevisionId INT NOT NULL',
            'systemId INT NOT NULL',
            'version VARCHAR(100) COLLATE utf8mb4_bin NOT NULL',
            'definition TEXT NOT NULL',
            'PRIMARY KEY (bindingId)',
            'UNIQUE KEY chronos_binding_version (benchmarkRevisionId,systemId,version)',
            'KEY chronos_binding_system (systemId)',
            'FOREIGN KEY (benchmarkRevisionId) REFERENCES BenchmarkRevision(benchmarkRevisionId)',
            'FOREIGN KEY (systemId) REFERENCES `System`(systemId) ON DELETE CASCADE',
        ],
        'ProjectComparison' => [
            'comparisonId INT NOT NULL AUTO_INCREMENT',
            'projectId INT NOT NULL',
            'name VARCHAR(250) NOT NULL',
            'evaluations TEXT NOT NULL',
            'PRIMARY KEY (comparisonId)',
            'KEY chronos_comparison_project (projectId)',
            'FOREIGN KEY (projectId) REFERENCES Project(projectId) ON DELETE CASCADE',
        ],
        'JobSpecification' => [
            'jobId INT NOT NULL',
            'adapter VARCHAR(100) COLLATE utf8mb4_bin DEFAULT NULL',
            'systemIdentifier VARCHAR(100) COLLATE utf8mb4_bin DEFAULT NULL',
            'specification MEDIUMTEXT NOT NULL',
            'PRIMARY KEY (jobId)',
            'KEY chronos_specification_adapter (adapter,systemIdentifier,jobId)',
            'FOREIGN KEY (jobId) REFERENCES Job(jobId) ON DELETE CASCADE',
        ],
        'JobAttempt' => [
            'attemptId INT NOT NULL AUTO_INCREMENT',
            'jobId INT NOT NULL',
            'tokenHash CHAR(64) COLLATE utf8mb4_bin NOT NULL',
            'progress INT NOT NULL',
            'currentPhase INT DEFAULT NULL',
            'workerKind VARCHAR(10) NOT NULL',
            'workerId VARCHAR(100) COLLATE utf8mb4_bin NOT NULL',
            'status INT NOT NULL',
            'claimed DATETIME NOT NULL',
            'started DATETIME DEFAULT NULL',
            'finished DATETIME DEFAULT NULL',
            'observedEnvironment TEXT DEFAULT NULL',
            'observedArtifacts TEXT DEFAULT NULL',
            'result MEDIUMTEXT DEFAULT NULL',
            'archive TEXT DEFAULT NULL',
            'reason TEXT DEFAULT NULL',
            'selected TINYINT DEFAULT NULL',
            'PRIMARY KEY (attemptId)',
            'UNIQUE KEY chronos_attempt_token (tokenHash)',
            'UNIQUE KEY chronos_attempt_selected (jobId,selected)',
            'KEY chronos_attempt_job (jobId,attemptId)',
            'KEY chronos_attempt_worker (workerKind,workerId,status)',
            'FOREIGN KEY (jobId) REFERENCES JobSpecification(jobId) ON DELETE CASCADE',
        ],
    ];

    public function version(): string
    {
        return self::VERSION;
    }

    public function capture(PDO $database): array
    {
        $context = ['maintainers' => (new SystemMaintainersMigration())->capture($database)];
        $this->assertState($database, $context, false);
        return $context;
    }

    public function validateContext(array $context): void
    {
        if (array_keys($context) !== ['maintainers'] || !is_array($context['maintainers'])) {
            throw new MigrationException('The execution-model recovery context is malformed.');
        }
        (new SystemMaintainersMigration())->validateContext($context['maintainers']);
    }

    public function apply(PDO $database, array $context): void
    {
        $this->validateContext($context);
        $this->assertParent($database, $context);
        foreach (self::TABLES as $table => $columns) {
            if (!$this->exists($database, $table)) {
                $database->exec('CREATE TABLE `' . $table . '` (' . implode(',', $columns)
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            }
            $this->assertTable($database, $table, $columns);
        }
    }

    public function revert(PDO $database, array $context): void
    {
        $this->validateContext($context);
        $this->assertParent($database, $context);
        $tables = [];
        foreach (self::TABLES as $table => $columns) {
            if (!$this->exists($database, $table)) continue;
            $this->assertTable($database, $table, $columns);
            $tables[] = '`' . $table . '`';
        }
        if ($tables === []) return;
        $database->exec('LOCK TABLES ' . implode(' WRITE, ', $tables) . ' WRITE');
        try {
            // Check all tables before DDL so a rejected downgrade loses no evidence.
            foreach ($tables as $table) {
                if ($database->query('SELECT 1 FROM ' . $table . ' LIMIT 1')->fetchColumn() !== false) {
                    throw new MigrationException('Export and explicitly remove execution-model data before downgrading.');
                }
            }
            $database->exec('DROP TABLE ' . implode(', ', array_reverse($tables)));
        } finally {
            $database->exec('UNLOCK TABLES');
        }
    }

    public function assertState(PDO $database, array $context, bool $applied): void
    {
        $this->validateContext($context);
        $this->assertParent($database, $context);
        foreach (self::TABLES as $table => $columns) {
            if ($this->exists($database, $table) !== $applied) {
                throw new MigrationException('The execution-model tables do not match the recorded schema version.');
            }
            if ($applied) $this->assertTable($database, $table, $columns);
        }
    }

    private function assertParent(PDO $database, array $context): void
    {
        (new SystemMaintainersMigration())->assertState($database, $context['maintainers'], true);
    }

    private function exists(PDO $database, string $table): bool
    {
        $statement = $database->prepare('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$table]);
        $engine = $statement->fetchColumn();
        if ($engine !== false && $engine !== 'InnoDB') throw new MigrationException('Execution-model tables must use InnoDB.');
        return $engine !== false;
    }

    private function assertTable(PDO $database, string $table, array $expected): void
    {
        $definitions = preg_split('/\R/', $database->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1]);
        $normalized = static function (string $line): string {
            $line = strtolower(str_replace('`', '', trim($line, " ,\r\n")));
            $line = preg_replace('/\b(int|tinyint)\((?:11|4)\)/', '$1', $line);
            $line = preg_replace('/\bconstraint \S+ foreign key/', 'foreign key', $line);
            $line = preg_replace('/ +\(/', '(', $line);
            $line = str_replace([' character set utf8mb4', ' collate utf8mb4_unicode_ci'], '', $line);
            return str_replace('text default null', 'text', $line);
        };
        $actual = array_map($normalized, array_slice($definitions, 1, -1));
        $wanted = array_map($normalized, $expected);
        sort($actual);
        sort($wanted);
        if ($actual !== $wanted) throw new MigrationException('The ' . $table . ' schema has an incompatible definition.');
    }
}
