<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use Amp\DeferredFuture;

/** Registered before its first snapshot so an overlapping change cannot be lost. */
final class ServerSubscription
{
    public ?string $hash = null;
    public int $revision = 0;
    public bool $reading = false;
    public bool $pending = false;
    public bool $queued = false;
    public ?DeferredFuture $completion = null;

    public function __construct(public readonly string $path, public array $query) {}
}
