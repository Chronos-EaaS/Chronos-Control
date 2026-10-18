<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Deployment;

use InvalidArgumentException;
use RuntimeException;

/** Cooperating requests keep schema and application files stable until they finish. */
final class ApplicationLock
{
    private static mixed $request = null;
    private static ?string $requestRoot = null;
    private static bool $shutdownRegistered = false;

    public static function enterRequest(string $root): bool
    {
        $root = self::root($root);
        if (self::maintenance($root)) {
            self::leaveRequest();
            return false;
        }
        if (is_resource(self::$request) && self::$requestRoot === $root) return true;
        self::leaveRequest();
        $handle = self::open($root);
        if (!flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        // An updater can enable maintenance between the first check and flock.
        if (self::maintenance($root)) {
            self::release($handle);
            return false;
        }
        self::$request = $handle;
        self::$requestRoot = $root;
        if (!self::$shutdownRegistered) {
            register_shutdown_function(self::leaveRequest(...));
            self::$shutdownRegistered = true;
        }
        return true;
    }

    public static function leaveRequest(): void
    {
        $handle = self::$request;
        self::$request = null;
        self::$requestRoot = null;
        self::release($handle);
    }

    /**
     * Maintenance must be active before draining cooperating requests.
     *
     * @return resource Caller must release the exclusive handle in a finally block.
     */
    public static function acquireExclusive(string $root, int $timeout = 30): mixed
    {
        if ($timeout < 0 || $timeout > 300) throw new InvalidArgumentException('The request drain timeout must be between 0 and 300 seconds.');
        $root = self::root($root);
        if (!self::maintenance($root)) throw new RuntimeException('Maintenance must be active before draining application requests.');
        // A peer may already hold a shared request lock while waiting for this
        // request's PHP session. Let it finish before waiting for exclusivity.
        $resumeSession = session_status() === PHP_SESSION_ACTIVE;
        if ($resumeSession && !session_write_close()) throw new RuntimeException('The updating session could not be saved before draining requests.');
        $handle = null;
        try {
            self::leaveRequest();
            $handle = self::open($root);
            $deadline = hrtime(true) + $timeout * 1_000_000_000;
            while (true) {
                if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) {
                    self::release($handle);
                    throw new RuntimeException('Active application requests did not finish before the update timeout.');
                }
                usleep((int) min(50_000, max(1, intdiv($remaining, 1_000))));
            }
        } finally {
            if ($resumeSession) {
                try {
                    // Read the latest session; never restore a cached identity or
                    // discard another request's edits, logout or session rotation.
                    if (!session_start()) throw new RuntimeException('The updating session could not be reopened after draining requests.');
                } catch (\Throwable $error) {
                    self::release($handle);
                    throw $error;
                }
            }
        }
    }

    public static function release(mixed $handle): void
    {
        if (!is_resource($handle) || get_resource_type($handle) !== 'stream') return;
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private static function root(string $root): string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if (is_link($root) || ($resolved = realpath($root)) === false || !is_dir($resolved)) {
            throw new RuntimeException('Application request lock storage is unavailable.');
        }
        return $resolved;
    }

    private static function maintenance(string $root): bool
    {
        $path = $root . '/.chronos-maintenance.json';
        clearstatcache(true, $path);
        return file_exists($path) || is_link($path);
    }

    /** @return resource */
    private static function open(string $root): mixed
    {
        $directory = $root . '/locks';
        clearstatcache(true, $directory);
        if (!file_exists($directory) && !is_link($directory)) @mkdir($directory, 0700);
        $parent = @lstat($directory);
        if ($parent === false || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0002) !== 0) {
            throw new RuntimeException('Application request lock directory is unsafe or unavailable.');
        }
        $path = $directory . '/.application-runtime.lock';
        clearstatcache(true, $path);
        $existing = @lstat($path);
        if ($existing !== false && !self::safeFile($existing)) {
            throw new RuntimeException('Application request lock file is unsafe or unavailable.');
        }
        if ($existing === false) {
            $mask = umask(0077);
            try { $handle = @fopen($path, 'x+b'); }
            finally { umask($mask); }
        }
        // Existing private lock files need no write access to application files.
        if (!is_resource($handle ?? null)) {
            clearstatcache(true, $path);
            $existing = @lstat($path);
            if ($existing === false || !self::safeFile($existing)) {
                throw new RuntimeException('Application request lock file is unsafe or unavailable.');
            }
            $handle = @fopen($path, 'rb');
        }
        clearstatcache(true, $path);
        clearstatcache(true, $directory);
        $file = @lstat($path);
        $opened = is_resource($handle) ? fstat($handle) : false;
        $currentParent = @lstat($directory);
        if ($file === false || $opened === false || $currentParent === false
            || !self::safeFile($file)
            || $file['dev'] !== $opened['dev'] || $file['ino'] !== $opened['ino']
            || $parent['dev'] !== $currentParent['dev'] || $parent['ino'] !== $currentParent['ino']
            || ($currentParent['mode'] & 0170000) !== 0040000) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Application request lock file is unsafe or unavailable.');
        }
        return $handle;
    }

    private static function safeFile(array $file): bool
    {
        return ($file['mode'] & 0170000) === 0100000 && ($file['mode'] & 0777) === 0600 && $file['nlink'] === 1;
    }
}
