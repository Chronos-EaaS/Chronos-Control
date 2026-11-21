<?php
declare(strict_types=1);

namespace Chronos\Application\Artifacts;

use Chronos\Domain\Artifacts\ArtifactManifest;
use InvalidArgumentException;
use RuntimeException;

final class CemArtifactSetup
{
    public const SETTING = 'artifactManifestV1';
    public const CAPABILITY = 'artifactManifest';

    public static function bindingManifest(array $manifest): ArtifactManifest
    {
        return ArtifactManifest::parse(json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    /** Returns the declared identities to freeze with the resolved CEM setup. */
    public static function configuredManifest(array $settings): ?ArtifactManifest
    {
        if (!array_key_exists(self::SETTING, $settings)) {
            return null;
        }
        try {
            if (!is_string($settings[self::SETTING])) {
                throw new InvalidArgumentException('Expected a manifest JSON string.');
            }
            return ArtifactManifest::parse($settings[self::SETTING]);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('Invalid CEM setting ' . self::SETTING . ': ' . $error->getMessage(), 0, $error);
        }
    }

    public static function requireCapability(?ArtifactManifest $manifest, ?string $capability): void
    {
        if ($manifest !== null && $capability !== (string) ArtifactManifest::VERSION) {
            throw new RuntimeException('This CEM job requires artifact verification. Use a bootstrapper supporting artifactManifest=1.');
        }
    }

    public static function scriptSettings(array $settings): array
    {
        unset($settings[self::SETTING]);
        return $settings;
    }
}
