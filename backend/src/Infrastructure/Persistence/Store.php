<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence;

use Chronos\Application\Shared\Input;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\Repository\UserRepository;

final class Store
{
    private array $textCharsets = [];

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
        if ($table === 'User') $values = UserRepository::storageValues($values);
        $this->assertTexts($table, $values);
        $keys = array_keys($values);
        $this->execute('INSERT INTO `' . $table . '` (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')', array_values($values));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, string $idColumn, int $id, array $values): void
    {
        if ($values === []) {
            Input::invalid('body', 'Submit at least one field to update.');
        }
        if ($table === 'User') $values = UserRepository::storageValues($values);
        $this->assertTexts($table, $values);
        $sets = array_map(static fn(string $key): string => '`' . $key . '`=?', array_keys($values));
        $this->execute('UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE `' . $idColumn . '`=?', [...array_values($values), $id]);
    }

    /** Preflight raw identifiers before predicates or writes can fail in MySQL's legacy charset. */
    public function assertText(string $table, string $column, string $value, string $field): void
    {
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $value) !== 1 || $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        if (!array_key_exists($table, $this->textCharsets)) {
            try {
                $rows = $this->rows('SELECT COLUMN_NAME,CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
                $this->textCharsets[$table] = array_column($rows, 'CHARACTER_SET_NAME', 'COLUMN_NAME');
            } catch (\PDOException $error) {
                throw new HttpError(503, 'storage_validation_unavailable', 'The database character set could not be verified. Ask an administrator to check access to column metadata.');
            }
        }
        if (!array_key_exists($column, $this->textCharsets[$table])) {
            throw new HttpError(503, 'storage_validation_unavailable', 'The database column character set is unavailable. Ask an administrator to check the application schema and metadata permissions.');
        }
        $charset = $this->textCharsets[$table][$column];
        // Binary columns have no character set. Existing utf8mb4 deployments
        // keep accepting supplementary characters in their raw identifiers.
        if ($charset === null || in_array(strtolower($charset), ['utf8mb4', 'utf16', 'utf16le', 'utf32', 'gb18030'], true)) return;
        Input::invalid($field, 'This database field cannot store supplementary Unicode characters. Choose a different value or upgrade the column to utf8mb4.');
    }

    private function assertTexts(string $table, array $values): void
    {
        $fields = ['firstname' => 'firstName', 'lastname' => 'lastName', UserRepository::ENABLED_COLUMN => 'enabled',
            'vcsUrl' => 'repository.url', 'vcsBranch' => 'repository.branch', 'vcsType' => 'repository.type',
            'vcsUser' => 'repository.username', 'vcsPassword' => 'repository.password'];
        foreach ($values as $column => $value) {
            if (is_string($value)) $this->assertText($table, $column, $value, $fields[$column] ?? $column);
        }
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
