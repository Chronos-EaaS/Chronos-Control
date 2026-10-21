<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Migrations;

use PDO;

/** Keep the designated owner while adding independently managed system access. */
final class SystemMaintainersMigration implements Migration
{
    public const VERSION = '202610210001_system_maintainers';

    public function version(): string
    {
        return self::VERSION;
    }

    public function capture(PDO $database): array
    {
        $resource = new ResourceEventTimelineIndexMigration();
        $context = ['resource' => $resource->capture($database), 'source' => $this->source($database), 'existingTable' => $this->exists($database)];
        $this->assertState($database, $context, false);
        return $context;
    }

    public function validateContext(array $context): void
    {
        if (array_keys($context) !== ['resource', 'source', 'existingTable'] || !is_array($context['resource'])
            || !is_array($context['source']) || !is_bool($context['existingTable'])
            || array_keys($context['source']) !== ['System', 'User']) {
            throw new MigrationException('The system-maintainer recovery context is malformed.');
        }
        (new ResourceEventTimelineIndexMigration())->validateContext($context['resource']);
        foreach (['System' => ['systemId', 'userId'], 'User' => ['userId']] as $table => $names) {
            if (!is_array($context['source'][$table]) || array_keys($context['source'][$table]) !== $names) {
                throw new MigrationException('The system-maintainer source context is malformed.');
            }
            foreach ($context['source'][$table] as $name => $column) {
                if (!is_array($column) || array_keys($column) !== ['name', 'type', 'null', 'default', 'extra', 'comment']
                    || $column['name'] !== $name || !in_array($column['type'], ['int', 'int(11)'], true)
                    || $column['null'] !== 'NO' || !is_string($column['extra']) || !is_string($column['comment'])
                    || ($column['default'] !== null && !is_string($column['default']))) {
                    throw new MigrationException('The system-maintainer source-column context is malformed.');
                }
            }
        }
    }

    public function apply(PDO $database, array $context): void
    {
        $this->validateContext($context);
        if (!$this->exists($database)) {
            $this->assertState($database, $context, false);
            $database->exec('CREATE TABLE `SystemMaintainer` (`systemId` INT NOT NULL,`userId` INT NOT NULL,PRIMARY KEY (`systemId`,`userId`),KEY `chronos_system_maintainer_user` (`userId`,`systemId`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        $this->assertState($database, $context, true);
        // The runner always re-enters apply after an interruption, including the
        // boundary between atomic DDL and this single atomic DML statement.
        // Disabled owners remain owners; only dangling legacy user IDs are omitted.
        $database->exec('INSERT INTO `SystemMaintainer` (`systemId`,`userId`) SELECT s.systemId,s.userId FROM `System` s INNER JOIN `User` u ON u.userId=s.userId LEFT JOIN `SystemMaintainer` m ON m.systemId=s.systemId AND m.userId=s.userId WHERE m.systemId IS NULL');
    }

    public function revert(PDO $database, array $context): void
    {
        $this->validateContext($context);
        if (!$context['existingTable'] && $this->exists($database)) {
            $this->assertState($database, $context, true);
            // Hold the membership write boundary through DROP: a concurrent add
            // must not slip between the loss check and removal of the table.
            $database->exec('LOCK TABLES `System` READ, `SystemMaintainer` WRITE');
            try {
                $additional = $database->query('SELECT COUNT(*) FROM `SystemMaintainer` LEFT JOIN `System` ON `System`.systemId=`SystemMaintainer`.systemId WHERE `System`.systemId IS NULL OR `System`.userId<>`SystemMaintainer`.userId')->fetchColumn();
                if ((int) $additional !== 0) {
                    throw new MigrationException('Remove additional system maintainers explicitly before downgrading; rollback would otherwise lose their access associations.');
                }
                $database->exec('DROP TABLE `SystemMaintainer`');
            } finally {
                $database->exec('UNLOCK TABLES');
            }
        }
        $this->assertState($database, $context, false);
    }

    public function assertState(PDO $database, array $context, bool $applied): void
    {
        $this->validateContext($context);
        (new ResourceEventTimelineIndexMigration())->assertState($database, $context['resource'], true);
        if ($this->source($database) !== $context['source']) {
            throw new MigrationException('The system-maintainer source columns have changed.');
        }
        if ($this->exists($database) !== ($applied || $context['existingTable'])) {
            throw new MigrationException('The system-maintainer table does not match its recorded schema version.');
        }
        if ($applied || $context['existingTable']) $this->assertTable($database);
    }

    /** Capture only stable schema, never membership rows or optimizer statistics. */
    private function source(PDO $database): array
    {
        $source = [];
        foreach (['System' => ['systemId', 'userId'], 'User' => ['userId']] as $table => $names) {
            $metadata = $database->query("SELECT TABLE_TYPE,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $table . "'")->fetch(PDO::FETCH_ASSOC);
            if (!is_array($metadata) || $metadata['TABLE_TYPE'] !== 'BASE TABLE' || $metadata['ENGINE'] !== 'InnoDB') {
                throw new MigrationException('System-maintainer source tables must use InnoDB.');
            }
            $columns = array_column($database->query('SHOW FULL COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC), null, 'Field');
            foreach ($names as $name) {
                $row = $columns[$name] ?? null;
                if (!is_array($row) || !in_array(strtolower((string) $row['Type']), ['int', 'int(11)'], true)
                    || $row['Null'] !== 'NO' || !in_array($row['Extra'], ['', 'auto_increment'], true)) {
                    throw new MigrationException('System-maintainer IDs require ordinary signed, non-null INT source columns.');
                }
                $source[$table][$name] = ['name' => $name, 'type' => strtolower((string) $row['Type']), 'null' => $row['Null'],
                    'default' => $row['Default'] === null ? null : (string) $row['Default'], 'extra' => $row['Extra'], 'comment' => $row['Comment']];
            }
        }
        return $source;
    }

    private function exists(PDO $database): bool
    {
        return (int) $database->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='SystemMaintainer'")->fetchColumn() === 1;
    }

    private function assertTable(PDO $database): void
    {
        $table = $database->query("SELECT TABLE_TYPE,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='SystemMaintainer'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($table) || $table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') {
            throw new MigrationException('The system-maintainer table must use InnoDB.');
        }
        $columns = $database->query('SHOW FULL COLUMNS FROM `SystemMaintainer`')->fetchAll(PDO::FETCH_ASSOC);
        $shape = array_map(static fn(array $row): array => [$row['Field'], preg_replace('/^int\(11\)$/', 'int', strtolower($row['Type'])), $row['Null'], $row['Default'], $row['Extra'], $row['Comment']], $columns);
        if ($shape !== [['systemId', 'int', 'NO', null, '', ''], ['userId', 'int', 'NO', null, '', '']]) {
            throw new MigrationException('The system-maintainer columns have an incompatible definition.');
        }
        $indexes = [];
        foreach ($database->query('SHOW INDEX FROM `SystemMaintainer`')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexes[$row['Key_name']][(int) $row['Seq_in_index']] = [$row['Column_name'], (int) $row['Non_unique'], $row['Index_type'], $row['Collation'], $row['Sub_part'], $row['Expression'] ?? null, $row['Visible'] ?? null, $row['Index_comment']];
        }
        ksort($indexes);
        foreach ($indexes as &$index) { ksort($index); $index = array_values($index); }
        unset($index);
        $column = static fn(string $name, int $nonUnique): array => [$name, $nonUnique, 'BTREE', 'A', null, null, 'YES', ''];
        $expected = ['PRIMARY' => [$column('systemId', 0), $column('userId', 0)],
            'chronos_system_maintainer_user' => [$column('userId', 1), $column('systemId', 1)]];
        if ($indexes !== $expected || (int) $database->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='SystemMaintainer' AND CONSTRAINT_TYPE<>'PRIMARY KEY'")->fetchColumn() !== 0) {
            throw new MigrationException('The system-maintainer keys or constraints have an incompatible definition.');
        }
    }
}
