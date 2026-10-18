<?php
declare(strict_types=1);

/*
The MIT License (MIT)

Copyright (c) 2018 Sein Coray

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 */

namespace Chronos\Infrastructure\Persistence\Repository;

use Chronos\Infrastructure\Persistence\Connection;
use Chronos\Infrastructure\Persistence\Model\AbstractModel;
use Chronos\Infrastructure\Persistence\Query\OrderFilter;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/** Persistence operations used by authentication, settings and the agent API. */
abstract class AbstractRepository
{
    protected const TABLE = '';
    protected const PRIMARY_KEY = '';
    protected const COLUMNS = [];

    public function __construct(private readonly ?PDO $connection = null) {}

    abstract protected function hydrate(array $row): AbstractModel;

    public function getModelTable(): string { return static::TABLE; }
    public function getDB(): PDO { return $this->connection ?? Connection::get(); }

    public function get(mixed $id): ?AbstractModel { return $this->getFromDB($id); }

    public function getFromDB(mixed $id): ?AbstractModel
    {
        $statement = $this->getDB()->prepare('SELECT ' . implode(',', static::COLUMNS)
            . ' FROM `' . static::TABLE . '` WHERE ' . static::PRIMARY_KEY . '=?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function save(AbstractModel $model): ?AbstractModel
    {
        $values = $model->getKeyValueDict();
        $statement = $this->getDB()->prepare('INSERT INTO `' . static::TABLE . '` ('
            . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
        $statement->execute(array_values($values));
        $id = $this->getDB()->lastInsertId();
        if ($id != 0) { $model->setId($id); return $model; }
        return $model->getId() != 0 ? $model : null;
    }

    public function update(AbstractModel $model): PDOStatement
    {
        $values = $model->getKeyValueDict();
        $assignments = array_map(static fn(string $column): string => $column . '=?', array_keys($values));
        $statement = $this->getDB()->prepare('UPDATE `' . static::TABLE . '` SET ' . implode(',', $assignments)
            . ' WHERE ' . $model->getPrimaryKey() . '=?');
        $statement->execute([...array_values($values), $model->getPrimaryKeyValue()]);
        return $statement;
    }

    /** @return list<AbstractModel>|AbstractModel|null */
    public function filter(array $options, bool $single = false): array|AbstractModel|null
    {
        [$query, $values] = $this->selection($options, implode(',', static::COLUMNS));
        $statement = $this->getDB()->prepare($query);
        $statement->execute($values);
        $objects = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $objects[] = $this->hydrate($row);
        }
        return $single ? ($objects[0] ?? null) : $objects;
    }

    public function idFilter(array $options): array
    {
        [$query, $values] = $this->selection($options, static::PRIMARY_KEY);
        $statement = $this->getDB()->prepare($query);
        $statement->execute($values);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Reserve selected jobs under the established MySQL row lock and timeout. */
    public function filterWithTimeout(array $options, int $lockTimeout, bool $single = false): array|AbstractModel|null
    {
        $this->checkOptions($options, ['filter', 'order']);
        $values = [];
        $where = $this->filters($values, $options['filter'] ?? []);
        $now = (int) round(microtime(true) * 1000);
        $where .= ($where === '' ? ' WHERE ' : ' AND ') . 'lockColumn< ' . $now;
        $query = 'SELECT ' . implode(',', static::COLUMNS) . ',lockColumn FROM `' . static::TABLE . '`'
            . $where . $this->ordering($options) . ($single ? ' LIMIT 1' : '') . ' FOR UPDATE';
        $db = $this->getDB();
        $db->beginTransaction();
        try {
            $statement = $db->prepare($query);
            $statement->execute($values);
            $update = $db->prepare('UPDATE `' . static::TABLE . '` SET lockColumn = ? WHERE ' . static::PRIMARY_KEY . ' = ?');
            $objects = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $objects[] = $this->hydrate($row);
                $update->execute([$now + $lockTimeout * 1000, $row[static::PRIMARY_KEY]]);
            }
            if (!$db->commit()) return null;
            return $single ? ($objects[0] ?? null) : $objects;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    public function delete(?AbstractModel $model): bool
    {
        if ($model === null) return false;
        $statement = $this->getDB()->prepare('DELETE FROM `' . static::TABLE . '` WHERE ' . $model->getPrimaryKey() . '=?');
        return $statement->execute([$model->getPrimaryKeyValue()]);
    }

    public function massDeletion(array $options): PDOStatement
    {
        $this->checkOptions($options, ['filter']);
        $values = [];
        $query = 'DELETE FROM `' . static::TABLE . '`' . $this->filters($values, $options['filter'] ?? []);
        $statement = $this->getDB()->prepare($query);
        $statement->execute($values);
        return $statement;
    }

    public function massUpdate(array $options): bool
    {
        $this->checkOptions($options, ['filter', 'update']);
        $updates = $options['update'] ?? [];
        if (!is_array($updates)) $updates = [$updates];
        if ($updates === []) throw new InvalidArgumentException('An update needs at least one assignment.');
        $values = []; $assignments = [];
        foreach ($updates as $update) { $values[] = $update->getValue(); $assignments[] = $update->getQuery(); }
        $query = 'UPDATE `' . static::TABLE . '` SET ' . implode(',', $assignments)
            . $this->filters($values, $options['filter'] ?? []);
        return $this->getDB()->prepare($query)->execute($values);
    }

    private function selection(array $options, string $columns): array
    {
        $this->checkOptions($options, ['filter', 'order']);
        $values = [];
        $where = $this->filters($values, $options['filter'] ?? []);
        return ['SELECT ' . $columns . ' FROM `' . static::TABLE . '`' . $where . $this->ordering($options), $values];
    }

    private function filters(array &$values, mixed $filters): string
    {
        if (!is_array($filters)) $filters = [$filters];
        $parts = [];
        foreach ($filters as $filter) {
            $parts[] = $filter->getQueryString();
            if (!$filter->getHasValue()) continue;
            $value = $filter->getValue();
            if (is_array($value)) array_push($values, ...$value); else $values[] = $value;
        }
        return $parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts);
    }

    private function ordering(array $options): string
    {
        $orders = $options['order'] ?? [new OrderFilter(static::PRIMARY_KEY, 'ASC')];
        if (!is_array($orders)) $orders = [$orders];
        $parts = [];
        foreach ($orders as $order) $parts[] = $order->getQueryString(static::TABLE);
        return $parts === [] ? '' : ' ORDER BY ' . implode(',', $parts);
    }

    private function checkOptions(array $options, array $allowed): void
    {
        if (array_diff(array_keys($options), $allowed) !== []) throw new InvalidArgumentException('Unsupported repository query option.');
    }
}
