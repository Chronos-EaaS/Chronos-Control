<?php
declare(strict_types=1);

namespace Chronos\Application\LogAnalysis;

interface LogSource
{
    /**
     * Read raw bytes from one authorized log snapshot, bounded by $limit.
     * Epoch identifies the source and its generation. On generation change or
     * shrinkage, return a window from byte zero with reset=true.
     */
    public function read(int $offset, int $limit, ?string $epoch, int $previousSize): LogWindow;
}
