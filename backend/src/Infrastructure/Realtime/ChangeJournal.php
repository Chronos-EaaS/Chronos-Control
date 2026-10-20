<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use Chronos\Infrastructure\Persistence\Connection;
use PDO;

/** Transactional outbox. Pending rows are claimed by identity, never by a commit-unsafe ID cursor. */
final class ChangeJournal
{
    public function __construct(private readonly PDO $database, private readonly ?string $root = null)
    {
    }

    public function record(string $topic, ?string $resourceId = null): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $topic)) {
            throw new \InvalidArgumentException('Invalid live change topic.');
        }
        $statement = $this->database->prepare('INSERT INTO `LiveChange` (topic,resourceId) VALUES (?,?)');
        $statement->execute([$topic, $resourceId]);
    }

    /** @return list<array{id:int,topic:string,resourceId:?string}> */
    public function drain(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid change batch size.');
        }
        $rows = $this->database->query('SELECT id,topic,resourceId FROM `LiveChange` ORDER BY id LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'topic' => $row['topic'], 'resourceId' => $row['resourceId']], $rows);
    }

    public function acknowledge(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        if (count($ids) > 1000 || array_filter($ids, static fn($id): bool => !is_int($id) || $id < 1)) {
            throw new \InvalidArgumentException('Invalid change identities.');
        }
        $statement = $this->database->prepare('DELETE FROM `LiveChange` WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $statement->execute($ids);
    }

    /** A best-effort wake-up never changes the outcome of a committed business write. */
    public static function notify(string $root): void
    {
        $path = rtrim($root, '/') . '/locks/live-notify.sock';
        $socket = @stream_socket_client('udg://' . $path, $number, $message, 0.05);
        if (is_resource($socket)) {
            stream_set_blocking($socket, false);
            @fwrite($socket, 'changed');
            fclose($socket);
        }
    }

    /** Filesystem writes cannot participate in SQL rollback; the heartbeat also reconciles them. */
    public static function fileChanged(string $root, string $topic, ?string $id = null): void
    {
        // Standalone domain/unit consumers do not configure an application database.
        if (!defined('SERVER_ROOT') || realpath($root) !== realpath(SERVER_ROOT)) {
            return;
        }
        try {
            (new self(Connection::get(), $root))->record($topic, $id);
        } catch (\Throwable $error) {
            error_log('Chronos live file notification could not be journaled: ' . get_class($error));
        }
        self::notify($root);
    }
}
