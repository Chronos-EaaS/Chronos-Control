<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Migrations;

use PDO;

/** Rename the account flag without reinterpreting or copying any account data. */
final class UserEnabledMigration implements Migration
{
    public const VERSION = '202610180001_user_enabled';

    public function version(): string
    {
        return self::VERSION;
    }

    public function capture(PDO $database): array
    {
        return ['column' => $this->definition($database, $this->column($database))];
    }

    public function apply(PDO $database, array $context): void
    {
        $this->move($database, $context, 'alive', 'enabled');
    }

    public function validateContext(array $context): void
    {
        if (array_keys($context) !== ['column'] || !is_array($context['column'])
            || array_keys($context['column']) !== ['attributes', 'indexes']
            || !is_array($context['column']['attributes']) || !is_array($context['column']['indexes'])
            || !array_is_list($context['column']['indexes'])) {
            throw new MigrationException('The User account migration recovery context is malformed.');
        }
        $expected = ['ORDINAL_POSITION', 'COLUMN_DEFAULT', 'IS_NULLABLE', 'DATA_TYPE', 'COLUMN_TYPE', 'CHARACTER_MAXIMUM_LENGTH', 'CHARACTER_OCTET_LENGTH', 'NUMERIC_PRECISION', 'NUMERIC_SCALE', 'DATETIME_PRECISION', 'CHARACTER_SET_NAME', 'COLLATION_NAME', 'EXTRA', 'COLUMN_COMMENT', 'GENERATION_EXPRESSION'];
        if (array_keys($context['column']['attributes']) !== $expected) throw new MigrationException('The User account migration attributes are incomplete.');
        foreach ($context['column']['attributes'] as $value) {
            if ($value !== null && !is_string($value)) throw new MigrationException('The User account migration attributes are malformed.');
        }
        foreach ($context['column']['indexes'] as $index) {
            if (!is_array($index) || !isset($index['Key_name'], $index['Seq_in_index']) || array_is_list($index)) {
                throw new MigrationException('The User account migration indexes are malformed.');
            }
            foreach ($index as $value) {
                if ($value !== null && !is_string($value)) throw new MigrationException('The User account migration index attributes are malformed.');
            }
        }
    }

    public function revert(PDO $database, array $context): void
    {
        $this->move($database, $context, 'enabled', 'alive');
    }

    public function assertState(PDO $database, array $context, bool $applied): void
    {
        $column = $applied ? 'enabled' : 'alive';
        if ($this->column($database) !== $column) {
            throw new MigrationException('The User account schema does not match its recorded database version.');
        }
        $this->assertDefinition($database, $context, $column);
    }

    private function move(PDO $database, array $context, string $source, string $target): void
    {
        $column = $this->column($database);
        $this->assertDefinition($database, $context, $column);
        if ($column === $source) {
            $this->assertRenameDependencies($database, $source);
            // MySQL commits DDL implicitly. The runner persists recovery context first.
            $database->exec('ALTER TABLE `User` RENAME COLUMN `' . $source . '` TO `' . $target . '`');
        }
        $this->assertState($database, $context, $target === 'enabled');
    }

    private function assertDefinition(PDO $database, array $context, string $column): void
    {
        $this->validateContext($context);
        if ($this->definition($database, $column) !== $context['column']) {
            throw new MigrationException('The User account column changed outside the recorded migration.');
        }
    }

    private function column(PDO $database): string
    {
        $table = $database->query("SELECT TABLE_TYPE,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='User'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($table) || $table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') {
            throw new MigrationException('The User table must use InnoDB for atomic schema changes.');
        }
        $statement = $database->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='User' AND COLUMN_NAME IN ('alive','enabled') ORDER BY COLUMN_NAME");
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($columns) !== 1 || !in_array($columns[0], ['alive', 'enabled'], true)) {
            throw new MigrationException('Expected exactly one User account column: alive or enabled.');
        }
        return $columns[0];
    }

    private function definition(PDO $database, string $column): array
    {
        $statement = $database->prepare('SELECT ORDINAL_POSITION,COLUMN_DEFAULT,IS_NULLABLE,DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_OCTET_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,DATETIME_PRECISION,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,COLUMN_COMMENT,GENERATION_EXPRESSION FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'User\' AND COLUMN_NAME=?');
        $statement->execute([$column]);
        $definition = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($definition) || $definition['DATA_TYPE'] !== 'tinyint'
            || ($definition['COLUMN_DEFAULT'] !== null && !in_array((string) $definition['COLUMN_DEFAULT'], ['0', '1'], true))
            || $definition['EXTRA'] !== '' || $definition['GENERATION_EXPRESSION'] !== '') {
            throw new MigrationException('The User account column must be a stored boolean tinyint with a boolean default.');
        }
        // PDO/MySQL can expose numeric metadata as strings or ints across connections.
        foreach ($definition as $name => $value) $definition[$name] = $value === null ? null : (string) $value;
        $invalid = $database->query('SELECT EXISTS(SELECT 1 FROM `User` WHERE `' . $column . '` IS NOT NULL AND `' . $column . '` NOT IN (0,1))')->fetchColumn();
        if ((int) $invalid !== 0) throw new MigrationException('The User account column contains non-boolean values.');

        $indexes = $database->query('SHOW INDEX FROM `User`')->fetchAll(PDO::FETCH_ASSOC);
        $affected = [];
        foreach ($indexes as $index) {
            if (($index['Column_name'] ?? null) === $column) $affected[(string) $index['Key_name']] = true;
        }
        $normalized = [];
        foreach ($indexes as $index) {
            if (!isset($affected[(string) $index['Key_name']])) continue;
            // Cardinality is optimizer statistics and may change without a schema change.
            unset($index['Cardinality']);
            foreach ($index as $name => $value) $index[$name] = $value === null ? null : (string) $value;
            if ($index['Column_name'] === $column) $index['Column_name'] = '<account-state>';
            ksort($index);
            $normalized[] = $index;
        }
        usort($normalized, static fn(array $left, array $right): int => [$left['Key_name'], (int) $left['Seq_in_index']] <=> [$right['Key_name'], (int) $right['Seq_in_index']]);
        return ['attributes' => $definition, 'indexes' => $normalized];
    }

    private function assertRenameDependencies(PDO $database, string $source): void
    {
        // MySQL updates indexes/FKs when renaming a column, but not stored SQL.
        // Refuse custom dependent objects instead of silently leaving broken SQL.
        $triggers = $database->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='User'")->fetchColumn();
        if ((int) $triggers !== 0) throw new MigrationException('Review custom User triggers before renaming the account column.');
        $expressions = $database->query("SELECT GENERATION_EXPRESSION AS definition FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='User' AND GENERATION_EXPRESSION<>'' UNION ALL SELECT PARTITION_EXPRESSION AS definition FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='User' AND PARTITION_EXPRESSION IS NOT NULL UNION ALL SELECT SUBPARTITION_EXPRESSION AS definition FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='User' AND SUBPARTITION_EXPRESSION IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $views = $database->query("SELECT V.VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEW_TABLE_USAGE U LEFT JOIN INFORMATION_SCHEMA.VIEWS V ON V.TABLE_SCHEMA=U.VIEW_SCHEMA AND V.TABLE_NAME=U.VIEW_NAME WHERE U.TABLE_SCHEMA=DATABASE() AND U.TABLE_NAME='User'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_merge($expressions, $views) as $definition) {
            if (!is_string($definition) || preg_match('/(?<![a-z0-9_])' . $source . '(?![a-z0-9_])/i', $definition)) {
                throw new MigrationException('Review custom views or expressions that reference the User account column before renaming it.');
            }
        }
    }
}
