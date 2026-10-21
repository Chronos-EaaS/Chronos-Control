<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Migrations;

use PDO;

/** Cover resource event counts and identifier-ordered pages without loading Event payloads. */
final class ResourceEventTimelineIndexMigration implements Migration
{
    public const VERSION = '202610200004_resource_event_timeline_index';
    private const INDEX = 'chronos_event_resource_timeline';

    public function version(): string
    {
        return self::VERSION;
    }

    public function capture(PDO $database): array
    {
        $scoped = new ScopedEventTimelineIndexesMigration();
        $context = ['scoped' => $scoped->capture($database), 'existingIndex' => null];
        $scoped->assertState($database, $context['scoped'], true);
        $indexes = $this->indexes($database);
        if (isset($indexes[self::INDEX]) && !$this->coversTimeline($indexes[self::INDEX])) {
            throw new MigrationException('The reserved resource event timeline index name is already used by a different definition.');
        }
        foreach ($indexes as $index) {
            if ($this->coversTimeline($index)) {
                $context['existingIndex'] = $index;
                break;
            }
        }
        $this->assertState($database, $context, false);
        return $context;
    }

    public function validateContext(array $context): void
    {
        if (array_keys($context) !== ['scoped', 'existingIndex'] || !is_array($context['scoped'])) {
            throw new MigrationException('The resource event timeline index recovery context is malformed.');
        }
        (new ScopedEventTimelineIndexesMigration())->validateContext($context['scoped']);
        if ($context['existingIndex'] !== null && (!is_array($context['existingIndex']) || !$this->coversTimeline($context['existingIndex']))) {
            throw new MigrationException('The preserved resource event timeline index recovery context is malformed.');
        }
    }

    public function apply(PDO $database, array $context): void
    {
        $this->validateContext($context);
        if (!$this->isApplied($database, $context)) {
            $this->assertState($database, $context, false);
            // One atomic InnoDB DDL statement gives the runner exactly two
            // recoverable boundaries, including a crash before journal updates.
            $database->exec('ALTER TABLE `Event` ADD INDEX `' . self::INDEX . '` (`eventType`,`relatedId`,`eventId`,`time`), ALGORITHM=INPLACE, LOCK=NONE');
        }
        $this->assertState($database, $context, true);
    }

    public function revert(PDO $database, array $context): void
    {
        $this->validateContext($context);
        if ($context['existingIndex'] === null && isset($this->indexes($database)[self::INDEX])) {
            $this->assertState($database, $context, true);
            $database->exec('ALTER TABLE `Event` DROP INDEX `' . self::INDEX . '`, ALGORITHM=INPLACE, LOCK=NONE');
        }
        $this->assertState($database, $context, false);
    }

    public function assertState(PDO $database, array $context, bool $applied): void
    {
        $this->validateContext($context);
        (new ScopedEventTimelineIndexesMigration())->assertState($database, $context['scoped'], true);
        $indexes = $this->indexes($database);
        if ($context['existingIndex'] !== null) {
            $original = $context['existingIndex'];
            if (($indexes[strtolower($original['name'])] ?? null) !== $original) {
                throw new MigrationException('The preserved resource event timeline index has changed.');
            }
            return;
        }
        $expected = $applied ? $this->addedIndex() : null;
        if (($indexes[self::INDEX] ?? null) !== $expected) {
            throw new MigrationException('The resource event timeline index does not match its recorded schema version.');
        }
    }

    private function isApplied(PDO $database, array $context): bool
    {
        try {
            $this->assertState($database, $context, true);
            return true;
        } catch (MigrationException) {
            return false;
        }
    }

    private function addedIndex(): array
    {
        return ['name' => self::INDEX, 'unique' => false, 'type' => 'BTREE', 'visible' => true, 'comment' => '',
            'columns' => array_map(static fn(string $name): array => ['name' => $name, 'direction' => 'A', 'prefix' => null, 'expression' => null], ['eventType', 'relatedId', 'eventId', 'time'])];
    }

    private function coversTimeline(array $index): bool
    {
        if (array_keys($index) !== ['name', 'unique', 'type', 'visible', 'comment', 'columns']
            || !is_string($index['name']) || $index['name'] === '' || strlen($index['name']) > 256
            || !is_bool($index['unique']) || $index['type'] !== 'BTREE' || $index['visible'] !== true
            || !is_string($index['comment']) || strlen($index['comment']) > 4096
            || !is_array($index['columns']) || !array_is_list($index['columns']) || count($index['columns']) < 4) {
            return false;
        }
        foreach ($index['columns'] as $column) {
            if (!is_array($column) || array_keys($column) !== ['name', 'direction', 'prefix', 'expression']
                || !is_string($column['name']) || !in_array($column['direction'], ['A', 'D'], true)
                || ($column['prefix'] !== null && (!is_int($column['prefix']) || $column['prefix'] < 1))
                || ($column['expression'] !== null && !is_string($column['expression']))) {
                return false;
            }
        }
        // Equality fixes eventType and relatedId; eventId is unique. Either
        // direction supports identifier paging, and time remains a covered value.
        foreach (['eventType', 'relatedId', 'eventId', 'time'] as $position => $name) {
            $column = $index['columns'][$position];
            if ($column['name'] !== $name || $column['prefix'] !== null || $column['expression'] !== null) return false;
        }
        return true;
    }

    /** Ignore optimizer cardinalities, but preserve the complete chosen index definition. */
    private function indexes(PDO $database): array
    {
        $table = $database->query("SELECT TABLE_TYPE,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Event'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($table) || $table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') {
            throw new MigrationException('The Event table must use InnoDB for atomic index changes.');
        }
        $indexes = [];
        foreach ($database->query('SHOW INDEX FROM `Event`')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['Key_name'];
            // MySQL index identifiers are case-insensitive, including our reserved
            // ASCII name. Preserve the actual spelling in the recovery definition.
            $key = strtolower($name);
            $indexes[$key] ??= ['name' => $name, 'unique' => (int) $row['Non_unique'] === 0, 'type' => (string) $row['Index_type'], 'visible' => ($row['Visible'] ?? null) === 'YES', 'comment' => (string) $row['Index_comment'], 'columns' => []];
            $indexes[$key]['columns'][(int) $row['Seq_in_index']] = ['name' => $row['Column_name'], 'direction' => $row['Collation'], 'prefix' => $row['Sub_part'] === null ? null : (int) $row['Sub_part'], 'expression' => $row['Expression'] ?? null];
        }
        ksort($indexes);
        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }
        return $indexes;
    }
}
