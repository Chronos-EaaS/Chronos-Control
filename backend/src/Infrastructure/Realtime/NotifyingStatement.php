<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use PDOStatement;
final class NotifyingStatement extends PDOStatement
{
    protected function __construct(private readonly NotifyingPDO $database)
    {
    }

    public function execute(?array $params = null): bool
    {
        return $this->database->businessWrite($this->queryString, fn(): bool => parent::execute($params));
    }
}
