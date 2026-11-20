<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Artifacts;

use RuntimeException;

final class ArtifactFiles
{
    /** @return resource */
    public static function openRegular(string $path)
    {
        clearstatcache(true, $path);
        $entry = @lstat($path);
        if ($entry === false || ($entry['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('Expected an existing regular file.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot open file for reading.');
        }
        $opened = fstat($stream);
        if ($opened === false || $opened['dev'] !== $entry['dev'] || $opened['ino'] !== $entry['ino']) {
            fclose($stream);
            throw new RuntimeException('File was replaced during open.');
        }
        return $stream;
    }

    /**
     * @param resource $stream
     * @return array{sha256: string, sizeBytes: int}
     */
    public static function measure($stream, int $limit): array
    {
        $hash = hash_init('sha256');
        $bytes = hash_update_stream($hash, $stream, $limit + 1);
        if ($bytes > $limit) {
            throw new RuntimeException('File exceeds the byte limit of ' . $limit . '.');
        }
        if (!feof($stream)) {
            throw new RuntimeException('Unable to read the complete file.');
        }
        return ['sha256' => hash_final($hash), 'sizeBytes' => $bytes];
    }
}
