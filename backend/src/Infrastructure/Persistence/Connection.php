<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence;

use LogicException;
use PDO;

/** Explicit request configuration; loading this class never opens a connection. */
final class Connection
{
    private static ?array $configuration = null;
    private static ?PDO $connection = null;

    public static function configureFromConstants(): void
    {
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'] as $constant) {
            if (!defined($constant)) throw new LogicException('Database configuration is incomplete.');
        }
        self::configure('mysql:dbname=' . DB_NAME . ';host=' . DB_HOST . ';port=' . DB_PORT,
            (string) DB_USER, (string) DB_PASSWORD);
    }

    public static function configure(string $dsn, string $user = '', string $password = ''): void
    {
        self::$configuration = [$dsn, $user, $password];
        self::$connection = null;
    }

    public static function get(): PDO
    {
        if (self::$connection !== null) return self::$connection;
        if (self::$configuration === null) throw new LogicException('Configure the database before requesting a connection.');
        [$dsn, $user, $password] = self::$configuration;
        return self::$connection = str_starts_with($dsn, 'mysql:')
            ? new \Chronos\Infrastructure\Realtime\NotifyingPDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], defined('SERVER_ROOT') ? SERVER_ROOT : null)
            : new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
