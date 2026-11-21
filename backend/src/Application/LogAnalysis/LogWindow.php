<?php
declare(strict_types=1);

namespace Chronos\Application\LogAnalysis;

final readonly class LogWindow
{
    public function __construct(
        public string $bytes,
        public int $offset,
        public int $size,
        public string $epoch,
        public bool $reset,
        public bool $startsAtLine,
        public bool $available,
    ) {
    }
}
