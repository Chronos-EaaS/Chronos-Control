<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Migrations;

use PDO;

/** A pending-row outbox preserves concurrent commits without a lossy monotonically consumed cursor. */
final class LiveUpdatesMigration implements Migration
{
    public const VERSION = '202610200001_live_updates';

    public function version(): string
    {
        return self::VERSION;
    }

    public function capture(PDO $database): array
    {
        $context = ['account' => (new UserEnabledMigration())->capture($database)];
        $this->assertState($database, $context, false);
        return $context;
    }

    public function validateContext(array $context): void
    {
        if (array_keys($context) !== ['account'] || !is_array($context['account'])) {
            throw new MigrationException('The live-update migration recovery context is malformed.');
        }
        (new UserEnabledMigration())->validateContext($context['account']);
    }

    public function apply(PDO $database, array $context): void
    {
        $this->validateContext($context);
        $database->exec('CREATE TABLE IF NOT EXISTS `LiveChange` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `topic` VARCHAR(64) NOT NULL, `resourceId` VARCHAR(64) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->assertState($database, $context, true);
    }

    public function revert(PDO $database, array $context): void
    {
        $this->validateContext($context);
        if ($this->exists($database)) {
            $this->assertState($database, $context, true);
            $database->exec('DROP TABLE `LiveChange`');
        }
        $this->assertState($database, $context, false);
    }

    private function exists(PDO $database): bool
    {
        $statement = $database->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='LiveChange' AND TABLE_TYPE='BASE TABLE'");
        return (int) $statement->fetchColumn() === 1;
    }

    public function assertState(PDO $database, array $context, bool $applied): void
    {
        $this->validateContext($context);
        // This additive migration preserves, and must continue checking, the preceding account boundary.
        (new UserEnabledMigration())->assertState($database, $context['account'], true);
        if ($this->exists($database) !== $applied) {
            throw new MigrationException('The live-update outbox does not match its recorded schema version.');
        }
        if (!$applied) {
            return;
        }
        $columns = $database->query('SHOW COLUMNS FROM `LiveChange`')->fetchAll(PDO::FETCH_ASSOC);
        $shape = array_map(static fn(array $row): array => [$row['Field'], strtolower($row['Type']), $row['Null'], $row['Key'], $row['Extra']], $columns);
        if ($shape !== [['id', 'bigint unsigned', 'NO', 'PRI', 'auto_increment'], ['topic', 'varchar(64)', 'NO', '', ''], ['resourceId', 'varchar(64)', 'YES', '', '']]) {
            throw new MigrationException('The live-update outbox schema has changed.');
        }
        if (strtolower((string) $database->query("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='LiveChange'")->fetchColumn()) !== 'innodb') {
            throw new MigrationException('The live-update outbox requires transactional InnoDB storage.');
        }
    }
}
