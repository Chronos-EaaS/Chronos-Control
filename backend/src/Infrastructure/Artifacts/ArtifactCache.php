<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Artifacts;

use Chronos\Domain\Artifacts\ArtifactManifest;
use InvalidArgumentException;
use RuntimeException;

/** Content-addressed storage inside a directory controlled by the bootstrapper user. */
final class ArtifactCache
{
    private readonly string $root;

    public function __construct(string $directory)
    {
        // Resolve relative CLI paths as local paths, never stream wrappers.
        if (!str_starts_with($directory, '/')) {
            $directory = getcwd() . '/' . $directory;
        }
        $directory = rtrim($directory, '/');
        if (!is_dir($directory)) {
            @mkdir($directory, 0700);
        }
        $root = realpath($directory);
        $mode = $root === false ? false : @fileperms($root);
        if ($root === false || !is_dir($root) || is_link($directory) || $mode === false || ($mode & 0022) !== 0) {
            throw new RuntimeException('Use a cache directory controlled by the bootstrapper, without group or public write access.');
        }
        $this->root = $root;
    }

    /** @param callable(resource): void $fetch */
    public function store(string $digest, int $size, callable $fetch): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest) || $size < 0 || $size > ArtifactManifest::MAX_ARTIFACT_BYTES) {
            throw new InvalidArgumentException('Invalid artifact cache identity.');
        }
        $path = $this->root . '/' . $digest;
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            $this->verifyPublished($path, $digest, $size);
            return $path;
        }

        $temporary = $this->root . '/.artifact-' . bin2hex(random_bytes(16));
        $output = @fopen($temporary, 'x+b');
        if ($output === false) {
            throw new RuntimeException('Cannot stage a file in the artifact cache.');
        }
        try {
            if (!@chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot set permissions on the staged artifact.');
            }
            $fetch($output);
            if (!@fflush($output) || !rewind($output)) {
                throw new RuntimeException('Cannot read the staged artifact.');
            }
            self::verify($output, $digest, $size);
            if (!@chmod($temporary, 0400) || !@fsync($output)) {
                throw new RuntimeException('Cannot finalize the staged artifact.');
            }
            // Publish only verified bytes. A concurrent writer may have published first.
            if (!@link($temporary, $path)) {
                clearstatcache(true, $path);
                if (@lstat($path) === false) {
                    throw new RuntimeException('Cannot publish to the artifact cache. Check permissions and hard-link support.');
                }
                $this->verifyPublished($path, $digest, $size);
            }
            return $path;
        } finally {
            fclose($output);
            @unlink($temporary);
        }
    }

    private function verifyPublished(string $path, string $digest, int $size): void
    {
        try {
            $input = ArtifactFiles::openRegular($path);
            try {
                self::verify($input, $digest, $size);
            } finally {
                fclose($input);
            }
        } catch (RuntimeException $error) {
            throw new RuntimeException('Cache entry ' . $digest . ' could not be used: ' . $error->getMessage(), 0, $error);
        }
    }

    /** @param resource $input */
    private static function verify($input, string $digest, int $size): void
    {
        $actual = ArtifactFiles::measure($input, $size);
        if ($actual['sizeBytes'] !== $size) {
            throw new RuntimeException('Size mismatch: expected ' . $size . ' bytes, got ' . $actual['sizeBytes'] . '.');
        }
        if ($actual['sha256'] !== $digest) {
            throw new RuntimeException('SHA-256 mismatch: expected ' . $digest . ', got ' . $actual['sha256'] . '.');
        }
    }
}
