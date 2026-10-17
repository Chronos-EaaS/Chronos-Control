<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

final class Store
{
    public function __construct(public readonly \PDO $pdo)
    {
    }

    public function rows(string $sql, array $args = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($args);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function row(string $sql, array $args = []): ?array
    {
        return $this->rows($sql, $args)[0] ?? null;
    }

    public function execute(string $sql, array $args = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($args);
        return $statement->rowCount();
    }

    public function insert(string $table, array $values): int
    {
        $keys = array_keys($values);
        $this->execute('INSERT INTO `' . $table . '` (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')', array_values($values));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, string $idColumn, int $id, array $values): void
    {
        if ($values === []) {
            Input::invalid('body', 'Submit at least one field to update.');
        }
        $sets = array_map(static fn(string $key): string => '`' . $key . '`=?', array_keys($values));
        $this->execute('UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE `' . $idColumn . '`=?', [...array_values($values), $id]);
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('A v3 unit of work must not join an implicit transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
