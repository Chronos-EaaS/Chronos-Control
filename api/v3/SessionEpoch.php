<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

/** Deployment-local session generation, outside restorable demo database data. */
final class SessionEpoch
{
    public static function current(string $root): string
    {
        return self::locked($root, false);
    }

    public static function rotate(string $root): string
    {
        return self::locked($root, true);
    }

    private static function locked(string $root, bool $rotate): string
    {
        $root = realpath($root) ?: throw new HttpError(503, 'session_validation_unavailable', 'Session validation storage is unavailable.');
        $directory = $root . '/locks';
        if (defined('SERVER_ROOT') && defined('LOCK_DIRECTORY') && realpath(SERVER_ROOT) === $root) {
            $directory = rtrim((string) LOCK_DIRECTORY, DIRECTORY_SEPARATOR);
        }
        if ($directory === '') {
            throw new HttpError(503, 'session_validation_unavailable', 'Session validation storage is unavailable.');
        }
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700))) {
            throw new HttpError(503, 'session_validation_unavailable', 'Session validation storage is unavailable.');
        }
        $path = $directory . '/v3-session-epoch';
        $lockPath = $path . '.lock';
        if (is_link($path) || is_link($lockPath)) {
            throw new HttpError(503, 'session_validation_unavailable', 'Session validation storage is unavailable.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new HttpError(503, 'session_validation_unavailable', 'Session validation storage is unavailable.');
        }
        $temporary = null;
        try {
            $previous = null;
            if (file_exists($path)) {
                if (!is_file($path) || filesize($path) > 65) {
                    throw new HttpError(503, 'session_validation_unavailable', 'Session validation storage is invalid.');
                }
                $previous = trim((string) file_get_contents($path));
                if (!preg_match('/^[a-f0-9]{64}$/D', $previous)) {
                    throw new HttpError(503, 'session_validation_unavailable', 'Session validation storage is invalid.');
                }
            }
            if (!$rotate && $previous !== null) return $previous;
            $epoch = bin2hex(random_bytes(32));
            $temporary = tempnam($directory, '.v3-session-epoch-');
            if ($temporary === false || file_put_contents($temporary, $epoch . "\n") !== 65
                || !chmod($temporary, 0600) || !rename($temporary, $path)) {
                throw new HttpError(503, 'session_validation_unavailable', 'Session validation storage could not be updated.');
            }
            $temporary = null;
            return $epoch;
        } finally {
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
