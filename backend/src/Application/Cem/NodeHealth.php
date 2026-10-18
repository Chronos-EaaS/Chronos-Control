<?php
declare(strict_types=1);

namespace Chronos\Application\Cem;

use Chronos\Infrastructure\Persistence\GlobalSettings;

final class NodeHealth
{
    public function __construct(private readonly GlobalSettings $settings)
    {
    }

    public function missingAfter(): int
    {
        $value = $this->settings->get('cem', 'nodeMissingTimeout', '86400');
        return ctype_digit($value) ? max(1, min((int) $value, 31536000)) : 86400;
    }

    public function cutoff(): string
    {
        return date('Y-m-d H:i:s', time() - $this->missingAfter());
    }
}
