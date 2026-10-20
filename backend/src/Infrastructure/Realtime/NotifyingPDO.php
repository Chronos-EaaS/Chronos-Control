<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use PDO;
use PDOStatement;

/** Both browser Store and v1 repositories share this transaction-aware write boundary. */
final class NotifyingPDO extends PDO
{
    private int $journalDepth = 0;
    private bool $changed = false;
    private string|false|null $businessInsertId = null;
    public function __construct(string $dsn, string $username = '', string $password = '', array $options = [], private readonly ?string $root = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [NotifyingStatement::class, [$this]]);
    }

    /** SQL comes exclusively from trusted application code; values remain bound parameters. */
    public static function changedTable(string $sql): ?string
    {
        if (!preg_match('/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z][A-Za-z0-9_]*)`?\b/i', $sql, $match)) {
            return null;
        }
        return in_array($match[1], ['User', 'Session', 'Project', 'ProjectUser', 'System', 'Setting', 'Experiment', 'Evaluation', 'Job', 'Event', 'Node'], true) ? $match[1] : null;
    }

    public function businessWrite(string $sql, callable $execute): mixed
    {
        $table = self::changedTable($sql);
        if ($this->journalDepth > 0 || $table === null) {
            return $execute();
        }
        $ownTransaction = !$this->inTransaction();
        if ($ownTransaction) {
            parent::beginTransaction();
        }
        try {
            $result = $execute();
            if ($result === false) {
                if ($ownTransaction) {
                    parent::rollBack();
                }
                return $result;
            }
            $this->businessInsertId = parent::lastInsertId();
            ++$this->journalDepth;
            try {
                (new ChangeJournal($this))->record($table);
            } finally {
                --$this->journalDepth;
            }
            $this->changed = true;
            if ($ownTransaction) {
                $this->commit();
            }
            return $result;
        } catch (\Throwable $error) {
            if ($ownTransaction && $this->inTransaction()) {
                $this->rollBack();
            }
            throw $error;
        }
    }

    public function exec(string $statement): int|false
    {
        return $this->businessWrite($statement, fn() => parent::exec($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->businessWrite($query, fn() => $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs));
    }

    public function commit(): bool
    {
        $result = parent::commit();
        if ($result && $this->changed) {
            $this->changed = false;
            if ($this->root !== null) {
                ChangeJournal::notify($this->root);
            }
        }
        return $result;
    }

    public function rollBack(): bool
    {
        $result = parent::rollBack();
        $this->changed = false;
        $this->businessInsertId = null;
        return $result;
    }
    // Journal inserts and MySQL COMMIT must not replace the ID returned to Store/repositories.
    public function lastInsertId(?string $name = null): string|false
    {
        return $name === null && $this->businessInsertId !== null ? $this->businessInsertId : parent::lastInsertId($name);
    }
}
