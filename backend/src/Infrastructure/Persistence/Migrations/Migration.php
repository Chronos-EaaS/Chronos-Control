<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Migrations;

use PDO;

/** A bundled, reversible schema change; recovery must accept either side of its DDL. */
interface Migration
{
    public function version(): string;

    /** Capture immutable recovery information before any DDL, including fresh baselines. */
    public function capture(PDO $database): array;

    /** Validate every historical recovery record without assuming its old schema still exists. */
    public function validateContext(array $context): void;

    public function apply(PDO $database, array $context): void;

    public function revert(PDO $database, array $context): void;

    public function assertState(PDO $database, array $context, bool $applied): void;
}
