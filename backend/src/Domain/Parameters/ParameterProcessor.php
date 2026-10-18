<?php
declare(strict_types=1);

namespace Chronos\Domain\Parameters;

interface ParameterProcessor
{
    /** Expand job configurations while preserving the caller's reference. */
    public function process(array $data, string $parameter, array &$configurations): void;
}
