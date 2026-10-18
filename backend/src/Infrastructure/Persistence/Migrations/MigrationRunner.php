<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Migrations;

use JsonException;
use Closure;
use PDO;
use ReflectionClass;

/** Serializes explicit upgrades and preserves enough durable state to resume MySQL DDL. */
final class MigrationRunner
{
    public const TABLE = 'ChronosMigration';

    /** @var array<string,array{migration:Migration,checksum:string}> */
    private array $catalog = [];
    private string $lockName;

    /**
     * @param ?list<Migration> $migrations Trusted executable catalog only.
     * @param ?array<string,string> $sourceChecksums Trusted original class basenames to
     * SHA256s, used only by a verified package's isolated compiled migration bundle.
     */
    public function __construct(private readonly PDO $database, ?array $migrations = null, private readonly int $lockTimeout = 10, ?array $sourceChecksums = null)
    {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new MigrationException('Database migrations require MySQL 8.');
        }
        if ($lockTimeout < 0 || $lockTimeout > 60) throw new MigrationException('The migration lock timeout must be between 0 and 60 seconds.');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = $database->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($schema) || $schema === '') throw new MigrationException('Select a database before inspecting migrations.');
        $this->lockName = 'chronos:migrations:' . substr(hash('sha256', $schema), 0, 40);
        $previous = '';
        $sourceNames = [];
        foreach ($migrations ?? MigrationCatalog::migrations() as $migration) {
            if (!$migration instanceof Migration) throw new MigrationException('The migration catalog contains unsupported code.');
            $version = $migration->version();
            if (!preg_match('/^[0-9]{12}_[a-z0-9_]{1,114}$/D', $version) || strcmp($version, $previous) <= 0) {
                throw new MigrationException('The migration catalog must have unique, ordered immutable version IDs.');
            }
            $reflection = new ReflectionClass($migration);
            $name = $reflection->getShortName();
            if (in_array($name, $sourceNames, true)) throw new MigrationException('Migration class basenames must be unique.');
            $sourceNames[] = $name;
            $file = $reflection->getFileName();
            $checksum = $sourceChecksums !== null ? ($sourceChecksums[$name] ?? null) : (is_string($file) ? hash_file('sha256', $file) : false);
            if (!is_string($checksum)) throw new MigrationException('A migration must be backed by a packaged source file.');
            if (!preg_match('/^[a-f0-9]{64}$/D', $checksum)) throw new MigrationException('A trusted migration source checksum must be SHA256.');
            $this->catalog[$version] = ['migration' => $migration, 'checksum' => $checksum];
            $previous = $version;
        }
        if ($sourceChecksums !== null) {
            $keys = array_keys($sourceChecksums);
            sort($keys);
            sort($sourceNames);
            if ($keys !== $sourceNames) throw new MigrationException('Trusted source checksums must match the complete migration catalog.');
        }
    }

    public function latestVersion(): string
    {
        return array_key_last($this->catalog) ?? MigrationCatalog::BASELINE;
    }

    /** Inspecting or checking compatibility never creates tables or changes schema. */
    public function status(): array
    {
        return $this->locked(fn(): array => $this->inspect());
    }

    public function assertCurrent(): void
    {
        $status = $this->status();
        if (!$status['clean'] || $status['version'] !== $this->latestVersion()) {
            throw new MigrationException('The database needs an explicit migration before this application can serve requests.');
        }
    }

    /** Hold the schema lock across an update's filesystem and automatic recovery phases. */
    public function lease(): Closure
    {
        if ($this->database->inTransaction()) throw new MigrationException('Do not run migrations inside an application transaction.');
        if ((int) $this->database->query('SELECT @@SESSION.autocommit')->fetchColumn() !== 1) {
            throw new MigrationException('Database migrations require autocommit; do not disable it on the migration connection.');
        }
        $statement = $this->database->prepare('SELECT GET_LOCK(?,?)');
        $statement->execute([$this->lockName, $this->lockTimeout]);
        if ((int) $statement->fetchColumn() !== 1) throw new MigrationException('Another database migration is running; retry after it finishes.');
        $released = false;
        return function () use (&$released): void {
            if ($released) return;
            $released = true;
            $statement = $this->database->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$this->lockName]);
            if ((int) $statement->fetchColumn() !== 1) throw new MigrationException('The database migration lease was lost before release.');
        };
    }

    /** null selects this release's latest schema; "0" selects the original v2 schema. */
    public function migrateTo(?string $target = null): array
    {
        $target ??= $this->latestVersion();
        if ($target !== MigrationCatalog::BASELINE && !isset($this->catalog[$target])) {
            throw new MigrationException('The requested database version is not in this release catalog.');
        }
        return $this->locked(function () use ($target): array {
            $this->inspect(); // Reject unknown or corrupted history before even creating metadata.
            $this->createLedger();
            $rows = $this->records();
            $versions = array_keys($this->catalog);
            $targetCount = $target === MigrationCatalog::BASELINE ? 0 : array_search($target, $versions, true) + 1;
            if ($rows === [] && $targetCount === 0 && $versions !== []) {
                // An explicitly requested v2 baseline must really have the v2 column,
                // even if a fresh enabled schema has not been adopted yet.
                $version = $versions[0];
                $migration = $this->catalog[$version]['migration'];
                $context = $migration->capture($this->database);
                try {
                    $migration->assertState($this->database, $context, false);
                } catch (MigrationException) {
                    $this->insertRecord($version, $context, 'reverting');
                    $this->transition($version, $context, false);
                }
            }
            // Incomplete upward work must be reconciled before crossing its boundary.
            if ($rows !== []) {
                $last = $rows[array_key_last($rows)];
                if ($last['state'] !== 'applied') {
                    $version = $last['version'];
                    $position = array_search($version, $versions, true);
                    $this->transition($version, $last['context'], $targetCount > $position);
                    $rows = $this->records();
                }
            }
            while (count($rows) > $targetCount) {
                $last = $rows[array_key_last($rows)];
                $this->transition($last['version'], $last['context'], false);
                $rows = $this->records();
            }
            while (count($rows) < $targetCount) {
                $version = $versions[count($rows)];
                $context = $this->catalog[$version]['migration']->capture($this->database);
                $this->insertRecord($version, $context, 'applying');
                $this->transition($version, $context, true);
                $rows = $this->records();
            }
            $status = $this->inspect();
            if (!$status['clean'] || $status['version'] !== $target) throw new MigrationException('The requested schema was not verified after migration.');
            return $status;
        });
    }

    private function transition(string $version, array $context, bool $up): void
    {
        $statement = $this->database->prepare('UPDATE `ChronosMigration` SET `state`=?,`updatedAt`=UTC_TIMESTAMP(6) WHERE `version`=?');
        $statement->execute([$up ? 'applying' : 'reverting', $version]);
        $migration = $this->catalog[$version]['migration'];
        if ($up) $migration->apply($this->database, $context);
        else $migration->revert($this->database, $context);
        $migration->assertState($this->database, $context, $up);
        if ($up) {
            $statement = $this->database->prepare('UPDATE `ChronosMigration` SET `state`=\'applied\',`updatedAt`=UTC_TIMESTAMP(6) WHERE `version`=?');
        } else {
            // Keep only active history. Older catalogs can serve a successfully reverted DB.
            $statement = $this->database->prepare('DELETE FROM `ChronosMigration` WHERE `version`=?');
        }
        $statement->execute([$version]);
    }

    private function insertRecord(string $version, array $context, string $state): void
    {
        $statement = $this->database->prepare('INSERT INTO `ChronosMigration` (`version`,`checksum`,`state`,`context`,`startedAt`,`updatedAt`) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $statement->execute([$version, $this->catalog[$version]['checksum'], $state, $this->encodeContext($context)]);
    }

    private function inspect(): array
    {
        $rows = $this->records();
        $clean = true;
        $version = MigrationCatalog::BASELINE;
        if ($rows !== []) {
            $last = $rows[array_key_last($rows)];
            $clean = $last['state'] === 'applied';
            if ($clean) {
                $this->catalog[$last['version']]['migration']->assertState($this->database, $last['context'], true);
                $version = $last['version'];
            } else {
                // Inspecting recovery context must reject drift on either side without running DDL.
                $this->assertRecoverable($last);
                $version = count($rows) > 1 ? $rows[count($rows) - 2]['version'] : MigrationCatalog::BASELINE;
            }
        } elseif ($this->catalog !== []) {
            // A fresh schema is validated but is never silently adopted by a read-only check.
            $this->catalog[array_key_first($this->catalog)]['migration']->capture($this->database);
        }
        return [
            'version' => $version,
            'latestVersion' => $this->latestVersion(),
            'clean' => $clean,
            'initialized' => $this->ledgerExists(),
            'pending' => array_values(array_filter(array_keys($this->catalog), static fn(string $candidate): bool => strcmp($candidate, $version) > 0)),
            'inProgress' => !$clean ? ['version' => $last['version'], 'state' => $last['state']] : null,
        ];
    }

    private function assertRecoverable(array $record): void
    {
        $migration = $this->catalog[$record['version']]['migration'];
        try {
            $migration->assertState($this->database, $record['context'], true);
        } catch (MigrationException) {
            $migration->assertState($this->database, $record['context'], false);
        }
    }

    /** @return list<array{version:string,checksum:string,state:string,context:array}> */
    private function records(): array
    {
        if (!$this->ledgerExists()) return [];
        $this->assertLedger();
        $rows = $this->database->query('SELECT `version`,`checksum`,`state`,`context` FROM `ChronosMigration` ORDER BY `version`')->fetchAll(PDO::FETCH_ASSOC);
        $versions = array_keys($this->catalog);
        foreach ($rows as $index => &$row) {
            if (!isset($versions[$index]) || $row['version'] !== $versions[$index]
                || !is_string($row['checksum']) || !hash_equals($this->catalog[$row['version']]['checksum'], $row['checksum'])) {
                throw new MigrationException('Unknown, reordered, or modified database migration history; use the matching release catalog.');
            }
            if (!in_array($row['state'], ['applying', 'applied', 'reverting'], true)
                || ($index !== count($rows) - 1 && $row['state'] !== 'applied')) {
                throw new MigrationException('The database migration journal has an invalid transition state.');
            }
            try {
                if (strlen($row['context']) > 1048576) throw new JsonException('Recovery context is too large.');
                $row['context'] = json_decode($row['context'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new MigrationException('The database migration recovery context is invalid.', previous: $error);
            }
            if (!is_array($row['context'])) throw new MigrationException('The database migration recovery context must be an object.');
            $this->catalog[$row['version']]['migration']->validateContext($row['context']);
        }
        unset($row);
        return $rows;
    }

    private function ledgerExists(): bool
    {
        return (int) $this->database->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ChronosMigration'")->fetchColumn() === 1;
    }

    private function createLedger(): void
    {
        $this->database->exec("CREATE TABLE IF NOT EXISTS `ChronosMigration` (`version` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,`checksum` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,`state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,`context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,`startedAt` datetime(6) NOT NULL,`updatedAt` datetime(6) NOT NULL,PRIMARY KEY (`version`)) ENGINE=InnoDB");
        $this->assertLedger();
    }

    private function assertLedger(): void
    {
        $table = $this->database->query("SELECT TABLE_TYPE,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ChronosMigration'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($table) || $table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') {
            throw new MigrationException('The migration ledger must be an InnoDB table.');
        }
        $columns = $this->database->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME,EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ChronosMigration' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $expected = [['version', 'varchar(128)', 'ascii_bin'], ['checksum', 'char(64)', 'ascii_bin'], ['state', 'varchar(16)', 'ascii_bin'], ['context', 'longtext', 'utf8mb4_bin'], ['startedAt', 'datetime(6)', null], ['updatedAt', 'datetime(6)', null]];
        if (count($columns) !== count($expected)) throw new MigrationException('The database migration ledger has an unexpected schema.');
        foreach ($columns as $index => $column) {
            if ([$column['COLUMN_NAME'], $column['COLUMN_TYPE'], $column['COLLATION_NAME']] !== $expected[$index]
                || $column['IS_NULLABLE'] !== 'NO' || $column['COLUMN_DEFAULT'] !== null || $column['EXTRA'] !== '') {
                throw new MigrationException('The database migration ledger has an unexpected schema.');
            }
        }
        $indexes = $this->database->query('SHOW INDEX FROM `ChronosMigration`')->fetchAll(PDO::FETCH_ASSOC);
        if (count($indexes) !== 1 || $indexes[0]['Key_name'] !== 'PRIMARY' || $indexes[0]['Column_name'] !== 'version') {
            throw new MigrationException('The database migration ledger has an unexpected primary key.');
        }
    }

    private function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 1048576) throw new MigrationException('The migration recovery context is too large.');
        return $encoded;
    }

    private function locked(callable $operation): array
    {
        $release = $this->lease();
        $oldTimeout = null;
        try {
            $oldTimeout = (int) $this->database->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
            // Bound metadata reads and ledger creation too, before any schema access.
            $this->database->exec('SET SESSION lock_wait_timeout = ' . max(1, $this->lockTimeout));
            return $operation();
        } finally {
            try {
                if ($oldTimeout !== null) $this->database->exec('SET SESSION lock_wait_timeout = ' . $oldTimeout);
            } finally {
                $release();
            }
        }
    }
}
