<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Deployment;

use Closure;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Runs a verified target release's isolated migration runtime, without booting the application. */
final class DatabaseDeployment
{
    /** Hold one database lock across preparation, filesystem activation and recovery. Never persist this value. */
    public static function lease(string $root, string $bundle, string $sha256, ?callable $connect = null): ?array
    {
        $runtime = self::runtime($bundle, $sha256);
        if (!($runtime['lock'] ?? null) instanceof Closure) {
            throw new RuntimeException('The database migration runtime cannot lock the deployment.');
        }
        $database = self::connect($root, $connect);
        if ($database === null) return null;
        try { $release = ($runtime['lock'])($database); }
        catch (PDOException) { throw new RuntimeException('The database deployment lock failed. Check database access and retry after other migrations finish.'); }
        if (!$release instanceof Closure) throw new RuntimeException('The database migration runtime did not provide a lock release.');
        return ['connection' => $database, 'release' => $release];
    }

    /** The optional connector is a trusted filesystem-test boundary, never request input. */
    public static function prepare(string $root, string $bundle, string $sha256, string $target, ?callable $connect = null): ?array
    {
        $runtime = self::runtime($bundle, $sha256, $target);
        $database = self::connect($root, $connect);
        if ($database === null) return null;
        $status = self::run($runtime, $database, 'status', null);
        self::assertStatus($status, $target);
        return ['before' => $status['version'], 'after' => $target, 'bundleSha256' => $sha256];
    }

    /** Reversal uses the same retained target runtime before any previous files are restored. */
    public static function migrate(string $root, string $bundle, array $plan, bool $forward, ?callable $connect = null): array
    {
        if (array_keys($plan) !== ['before', 'after', 'bundleSha256']
            || !self::version($plan['before'] ?? null) || !self::version($plan['after'] ?? null)
            || !is_string($plan['bundleSha256'] ?? null)) {
            throw new RuntimeException('The saved database deployment plan is invalid.');
        }
        $runtime = self::runtime($bundle, $plan['bundleSha256'], $plan['after']);
        $database = self::connect($root, $connect);
        if ($database === null) throw new RuntimeException('The configured database is unavailable; its deployment cannot be skipped.');
        $target = $plan[$forward ? 'after' : 'before'];
        $status = self::run($runtime, $database, 'migrate', $target);
        self::assertStatus($status, $plan['after'], $target);
        return $status;
    }

    /** Loading remains safe in a process that already loaded another release's normal class names. */
    private static function runtime(string $bundle, string $sha256, ?string $target = null): array
    {
        if (($target !== null && !self::version($target)) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new RuntimeException('The database package version or checksum is invalid.');
        }
        self::regular($bundle, 'The retained database migration runtime is unsafe or unavailable.');
        $actual = hash_file('sha256', $bundle);
        if (!is_string($actual) || !hash_equals($sha256, $actual)) {
            throw new RuntimeException('The retained database migration runtime failed its integrity check.');
        }
        $runtime = (static fn(string $file): mixed => require $file)($bundle);
        if (!is_array($runtime) || ($runtime['format'] ?? null) !== 1 || !self::version($runtime['version'] ?? null)
            || ($target !== null && $runtime['version'] !== $target) || !($runtime['run'] ?? null) instanceof Closure) {
            throw new RuntimeException('The database migration runtime does not match the approved package version.');
        }
        return $runtime;
    }

    private static function run(array $runtime, PDO $database, string $action, ?string $target): array
    {
        try {
            $status = ($runtime['run'])($database, $action, $target);
        } catch (PDOException) {
            // Driver errors can contain private connection details. Keep them out of operator/UI output.
            throw new RuntimeException('The database migration failed. Check database access, schema compatibility and migration privileges.');
        }
        if (!is_array($status)) throw new RuntimeException('The database migration runtime returned an invalid status.');
        return $status;
    }

    private static function assertStatus(array $status, string $latest, ?string $target = null): void
    {
        if (!self::version($status['version'] ?? null) || ($status['clean'] ?? null) !== true
            || !array_key_exists('inProgress', $status) || $status['inProgress'] !== null
            || (array_key_exists('latestVersion', $status) && $status['latestVersion'] !== $latest)) {
            throw new RuntimeException('The database migration history is unfinished or does not match the approved runtime. Recover the matching deployment before continuing.');
        }
        if ($target !== null && $status['version'] !== $target) {
            throw new RuntimeException('The database migration did not reach the approved schema version. Maintenance must remain active.');
        }
    }

    private static function version(mixed $value): bool
    {
        return is_string($value) && ($value === '0' || preg_match('/^[0-9]{12}_[a-z0-9_]{1,114}$/D', $value) === 1);
    }

    private static function connect(string $root, ?callable $connect): ?PDO
    {
        $resolved = realpath($root);
        if ($resolved === false || $resolved === '/' || !is_dir($resolved)) {
            throw new RuntimeException('The configured installation directory is unavailable.');
        }
        if ($connect !== null) {
            try { $database = $connect(); }
            catch (Throwable) { throw new RuntimeException('The database connection failed. Check the retained installation configuration and database access.'); }
            if ($database !== null && !$database instanceof PDO) throw new RuntimeException('The trusted database connector must return a PDO connection or null.');
            return $database;
        }
        $config = $resolved . '/config.php';
        if (!file_exists($config) && !is_link($config)) return null;
        self::regular($config, 'The retained database configuration is unsafe or unavailable.');
        if (defined('SERVER_ROOT') && (!is_string(SERVER_ROOT) || realpath(SERVER_ROOT) !== $resolved)) {
            throw new RuntimeException('The loaded database configuration belongs to another installation.');
        }
        if (!defined('SERVER_ROOT')) define('SERVER_ROOT', $resolved);
        $names = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'];
        if (array_filter($names, static fn(string $name): bool => !defined($name)) !== []) {
            if (is_file($resolved . '/constants.php') || is_link($resolved . '/constants.php')) {
                self::regular($resolved . '/constants.php', 'The retained installation constants are unsafe or unavailable.');
                require_once $resolved . '/constants.php';
            }
            require_once $config;
        }
        foreach ($names as $name) {
            if (!defined($name)) throw new RuntimeException('The retained database configuration is incomplete.');
        }
        if (!is_string(DB_HOST) || DB_HOST === '' || !is_string(DB_NAME) || DB_NAME === ''
            || preg_match('/[;\x00\r\n]/', DB_HOST . DB_NAME)
            || !is_string(DB_USER) || !is_string(DB_PASSWORD) || str_contains(DB_USER . DB_PASSWORD, "\0")) {
            throw new RuntimeException('The retained database connection settings are invalid.');
        }
        $port = filter_var(DB_PORT, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) throw new RuntimeException('The retained database connection port is invalid.');
        try {
            return new PDO('mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException) {
            throw new RuntimeException('The database connection failed. Check the retained installation configuration and database access.');
        }
    }

    private static function regular(string $path, string $message): void
    {
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\0")) throw new RuntimeException($message);
        $parent = $path;
        do {
            if (is_link($parent)) throw new RuntimeException($message);
            $next = dirname($parent);
            if ($parent === $next) break;
            $parent = $next;
        } while (true);
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException($message);
    }
}
