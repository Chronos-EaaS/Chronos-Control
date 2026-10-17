<?php
declare(strict_types=1);

namespace Chronos\Application\Maintenance;

interface AdminOperationExecutor
{
    public function capabilities(): array;
    public function plan(string $action, array $parameters): array;
    public function execute(string $action, array $plan): array;
}
