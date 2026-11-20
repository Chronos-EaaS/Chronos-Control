<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Artifacts;

use Chronos\Domain\Artifacts\ArtifactManifest;
use RuntimeException;

final class ArtifactResolver
{
    public function __construct(private readonly ArtifactSource $source, private readonly ArtifactCache $cache) {}

    /** Return node-local paths with their declared identities after checking every role. */
    public function prepare(ArtifactManifest $manifest): array
    {
        $artifacts = [];
        foreach ($manifest->artifacts as $entry) {
            try {
                $size = $entry['sizeBytes'];
                $path = $this->cache->store(
                    $entry['sha256'],
                    $size,
                    $this->source->fetcher($entry['uri'], $size),
                );
                $artifacts[] = $entry + ['path' => $path];
            } catch (RuntimeException $error) {
                throw new RuntimeException('Artifact ' . $entry['role'] . ': ' . $error->getMessage(), 0, $error);
            }
        }
        return $artifacts;
    }
}
