<?php
declare(strict_types=1);

namespace Chronos\Application\Metadata;

use Chronos\Application\Extensions\Extensions;

final class MetadataService
{
    public function __construct(private readonly string $root)
    {
    }

    public function snapshot(): array
    {
        $buildId = null;
        $file = $this->root . '/webroot/ui/build.json';
        if (is_file($file) && filesize($file) < 65536) {
            $build = json_decode((string) file_get_contents($file), true);
            $buildId = is_array($build) && is_string($build['id'] ?? null) ? $build['id'] : null;
        }
        return [
            'api' => 'ui', 'applicationVersion' => APP_VERSION, 'uiBuildId' => $buildId,
            'siteName' => defined('SITE_NAME') && is_string(SITE_NAME) && trim(SITE_NAME) !== '' ? SITE_NAME : 'Chronos Control',
            'password' => ['minLength' => PASSWORDS_MIN_LENGTH, 'maxLength' => min(PASSWORDS_MAX_LENGTH, 72), 'requireComplexity' => REQUIRE_PASSWORD_COMPLEXITY],
            'timezone' => 'Europe/Zurich', 'features' => ['remoteRepositories' => defined('ENABLE_REMOTE_REPOSITORY') && ENABLE_REMOTE_REPOSITORY], 'extensions' => Extensions::capabilities(),
        ];
    }
}
