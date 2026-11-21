<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Artifacts;

use Chronos\Domain\Artifacts\ArtifactUri;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Resolve against a directory selected by the build or bootstrapper operator. */
final class ArtifactSource
{
    private readonly ?string $remoteBase;

    public function __construct(private readonly string $base)
    {
        if (str_starts_with($base, '/') && !str_starts_with($base, '//') && !str_contains($base, "\0")) {
            $this->remoteBase = null;
            return;
        }
        [$origin, $path] = ArtifactUri::remote(rtrim($base, '/') . '/');
        $path = rtrim($path, '/');
        if ($path !== '' && !ArtifactUri::isRelative($path)) {
            throw new InvalidArgumentException('Invalid artifact source URL directory.');
        }
        $this->remoteBase = $origin . '/' . ($path === '' ? '' : $path . '/');
    }

    /** Validate source policy before any cache lookup. */
    public function fetcher(string $uri, int $size): Closure
    {
        $location = $this->locate($uri);
        return $this->remoteBase === null
            ? fn ($target) => $this->copyLocal($location, $target, $size)
            : fn ($target) => $this->download($location, $target, $size);
    }

    private function locate(string $uri): string
    {
        ArtifactUri::validate($uri);
        if (ArtifactUri::isRelative($uri)) {
            return ($this->remoteBase ?? '') . $uri;
        }
        if ($this->remoteBase !== null && str_starts_with($uri, $this->remoteBase)) {
            return $uri;
        }
        throw new RuntimeException('Artifact URL is outside the configured source directory.');
    }

    /** @return resource */
    public function openLocal(string $relative)
    {
        if ($this->remoteBase !== null || !ArtifactUri::isRelative($relative)) {
            throw new InvalidArgumentException('Expected a relative file path under the local artifact source directory.');
        }
        clearstatcache(true);
        $root = realpath($this->base);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Local artifact source directory is unavailable.');
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $candidate = realpath($prefix . $relative);
        if ($candidate === false) {
            throw new RuntimeException('Artifact file is missing from the local source.');
        }
        if (!str_starts_with($candidate, $prefix)) {
            throw new RuntimeException('Artifact path resolves outside the local source directory.');
        }
        return ArtifactFiles::openRegular($candidate);
    }

    /** @param resource $target */
    private function copyLocal(string $relative, $target, int $size): void
    {
        $input = $this->openLocal($relative);
        try {
            $bytes = @stream_copy_to_stream($input, $target, $size + 1);
            if ($bytes === false) {
                throw new RuntimeException('Cannot copy the local artifact.');
            }
            if ($bytes > $size) {
                throw new RuntimeException('Source exceeds the declared sizeBytes (' . $size . ').');
            }
        } finally {
            fclose($input);
        }
    }

    /** @param resource $target */
    private function download(string $url, $target, int $size): void
    {
        if (!defined('CURLOPT_PROTOCOLS_STR')) {
            throw new RuntimeException('HTTP artifact downloads require PHP cURL with libcurl 7.85 or newer.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize artifact download.');
        }
        $remaining = $size;
        $writeError = null;
        $configured = curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS_STR => str_starts_with($url, 'https://') ? 'HTTPS' : 'HTTP',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_CONTENT_DECODING => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($target, $size, &$remaining, &$writeError): int {
                $bytes = strlen($chunk);
                if ($bytes > $remaining) {
                    $writeError = 'HTTP body exceeds the declared sizeBytes (' . $size . ').';
                    return 0;
                }
                if (@fwrite($target, $chunk) !== $bytes) {
                    $writeError = 'Cannot write downloaded bytes to the artifact cache.';
                    return 0;
                }
                $remaining -= $bytes;
                return $bytes;
            },
        ]);
        if (!$configured) {
            throw new RuntimeException('Cannot configure artifact download.');
        }
        $result = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status !== 0 && $status !== 200) {
            throw new RuntimeException('Artifact source returned HTTP ' . $status . '. A direct HTTP 200 response is required.');
        }
        if ($writeError !== null) {
            throw new RuntimeException($writeError);
        }
        if ($result === false || $status !== 200) {
            throw new RuntimeException('Artifact download failed: ' . curl_strerror(curl_errno($curl)) . '.');
        }
    }
}
