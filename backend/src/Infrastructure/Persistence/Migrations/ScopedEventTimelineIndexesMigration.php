<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Migrations;

use PDO;

/** Keep bounded node histories out of a full Event-table scan and sort. */
final class ScopedEventTimelineIndexesMigration implements Migration
{
    public const VERSION = '202610200003_scoped_event_timeline_indexes';
    private const INDEX = 'chronos_event_node_timeline';

    public function version(): string
    {
        return self::VERSION;
    }

    public function capture(PDO $database): array
    {
        $timeline = new EventTimelineIndexMigration();
        $context = ['timeline' => $timeline->capture($database), 'existingIndex' => null];
        $timeline->assertState($database, $context['timeline'], true);
        $indexes = $this->indexes($database);
        if (isset($indexes[self::INDEX]) && !$this->coversTimeline($indexes[self::INDEX])) {
            throw new MigrationException('The reserved node event timeline index name is already used by a different definition.');
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
        if (array_keys($context) !== ['timeline', 'existingIndex'] || !is_array($context['timeline'])) {
            throw new MigrationException('The node event timeline index recovery context is malformed.');
        }
        (new EventTimelineIndexMigration())->validateContext($context['timeline']);
        if ($context['existingIndex'] !== null && (!is_array($context['existingIndex']) || !$this->coversTimeline($context['existingIndex']))) {
            throw new MigrationException('The preserved node event timeline index recovery context is malformed.');
        }
    }

    public function apply(PDO $database, array $context): void
    {
        $this->validateContext($context);
        if (!$this->isApplied($database, $context)) {
            $this->assertState($database, $context, false);
            // One atomic InnoDB DDL statement gives the runner exactly two
            // recoverable boundaries, including a crash before journal updates.
            $database->exec('ALTER TABLE `Event` ADD INDEX `' . self::INDEX . '` (`nodeId`,`time`,`eventId`), ALGORITHM=INPLACE, LOCK=NONE');
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
        (new EventTimelineIndexMigration())->assertState($database, $context['timeline'], true);
        $indexes = $this->indexes($database);
        if ($context['existingIndex'] !== null) {
            $original = $context['existingIndex'];
            if (($indexes[strtolower($original['name'])] ?? null) !== $original) {
                throw new MigrationException('The preserved node event timeline index has changed.');
            }
            return;
        }
        $expected = $applied ? $this->addedIndex() : null;
        if (($indexes[self::INDEX] ?? null) !== $expected) {
            throw new MigrationException('The node event timeline index does not match its recorded schema version.');
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
            'columns' => [['name' => 'nodeId', 'direction' => 'A', 'prefix' => null, 'expression' => null],
                ['name' => 'time', 'direction' => 'A', 'prefix' => null, 'expression' => null],
                ['name' => 'eventId', 'direction' => 'A', 'prefix' => null, 'expression' => null]]];
    }

    private function coversTimeline(array $index): bool
    {
        if (array_keys($index) !== ['name', 'unique', 'type', 'visible', 'comment', 'columns']
            || !is_string($index['name']) || $index['name'] === '' || strlen($index['name']) > 256
            || !is_bool($index['unique']) || $index['type'] !== 'BTREE' || $index['visible'] !== true
            || !is_string($index['comment']) || strlen($index['comment']) > 4096
            || !is_array($index['columns']) || !array_is_list($index['columns']) || count($index['columns']) < 3) {
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
        [$node, $time, $event] = $index['columns'];
        return $node['name'] === 'nodeId' && $node['prefix'] === null && $node['expression'] === null
            && $time['name'] === 'time' && $event['name'] === 'eventId'
            && $time['direction'] === $event['direction'] && $time['prefix'] === null && $event['prefix'] === null
            && $time['expression'] === null && $event['expression'] === null;
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
