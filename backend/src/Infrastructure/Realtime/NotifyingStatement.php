<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use PDOStatement;
final class NotifyingStatement extends PDOStatement
{
    private readonly NotifyingPDO $database;

    protected function __construct(\WeakReference $database)
    {
        $connection = $database->get();
        if (!$connection instanceof NotifyingPDO) {
            throw new \LogicException('The statement connection is unavailable.');
        }
        $this->database = $connection;
    }

    public function execute(?array $params = null): bool
    {
        return $this->database->businessWrite($this->queryString, fn(): bool => parent::execute($params));
    }
}
