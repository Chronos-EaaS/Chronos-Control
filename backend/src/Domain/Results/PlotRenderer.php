<?php
declare(strict_types=1);

namespace Chronos\Domain\Results;

interface PlotRenderer
{
    /** @param array<array<object>> $jobs */
    public function render(array $jobs, array $definition): array;
}
