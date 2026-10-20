<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Realtime;

use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\Connection;
use Chronos\Infrastructure\Security\SessionEpoch;
use PDO;

/** One-use browser tickets exchange an HTTP identity for a private, revocable daemon lease. */
final class LiveTickets
{
    public function __construct(private readonly string $root, private readonly ?PDO $database = null)
    {
    }

    private function db(): PDO
    {
        return $this->database ?? Connection::get();
    }

    public function issueForCurrentSession(array $actor, array $snapshot = []): array
    {
        $lock = $this->lock();
        try {
            return $this->issue($actor, $snapshot);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function issue(array $actor, array $snapshot): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            throw new HttpError(401, 'authentication_required', 'Please sign in to continue.');
        }
        $originalId = $snapshot['impersonation']['active'] ?? false ? $snapshot['impersonation']['originalUser']['id'] ?? null : null;
        $verified = $this->identity((int) $actor['id'], $originalId === null ? null : (int) $originalId);
        $proof = $_SESSION['v3Identity'] ?? null;
        if ($verified === null || !is_array($proof)
            || !hash_equals($verified['credentials'], (string) ($proof['credentials'] ?? ''))
            || !hash_equals(SessionEpoch::current($this->root), (string) ($proof['epoch'] ?? ''))) {
            throw new HttpError(401, 'authentication_required', 'Please sign in to continue.');
        }
        $sessionIdHash = hash('sha256', session_id());
        $generationPath = $this->path('sessions', $sessionIdHash);
        $generationRecord = $this->read($generationPath);
        $generation = $generationRecord['generation'] ?? null;
        $now = time();
        $expiresAt = $now + (defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 36000);
        if (!is_string($generation)) {
            $generation = bin2hex(random_bytes(32));
            $this->create($generationPath, ['generation' => $generation, 'expiresAt' => $expiresAt]);
        } else {
            // The generation outlives every lease issued from it; cleanup cannot revoke a live lease.
            $temporary = $this->path('sessions', bin2hex(random_bytes(32)));
            $this->create($temporary, ['generation' => $generation, 'expiresAt' => max($expiresAt, (int) ($generationRecord['expiresAt'] ?? 0))]);
            if (!@rename($temporary, $generationPath)) {
                @unlink($temporary);
                throw new HttpError(503, 'live_unavailable', 'Live authentication storage could not be renewed.');
            }
        }
        $ticket = bin2hex(random_bytes(32));
        $record = [
            'userId' => (int) $actor['id'],
            'originalUserId' => $originalId,
            'credentials' => $verified['credentials'],
            'epoch' => $proof['epoch'],
            'sessionIdHash' => $sessionIdHash,
            'generation' => $generation,
            'phpSessionFile' => $this->sessionFile(),
            'csrfToken' => (string) ($_SESSION['v3Csrf'] ?? ''),
            'ticketExpiresAt' => $now + 30,
            'expiresAt' => $expiresAt,
        ];
        $this->create($this->path('tickets', hash('sha256', $ticket)), $record);
        $this->prune();
        return [
            'ticket' => $ticket,
            'expiresAt' => $record['ticketExpiresAt'],
            'path' => '/api/ui/live',
            'heartbeatSeconds' => 15,
            'timeoutSeconds' => 45,
        ];
    }

    public function consume(string $ticket): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) {
            return null;
        }
        $source = $this->path('tickets', hash('sha256', $ticket));
        $record = $this->read($source);
        if ($record === null || ($record['ticketExpiresAt'] ?? 0) <= time()) {
            if (is_file($source) && !is_link($source)) {
                @unlink($source);
            }
            return null;
        }
        $lease = bin2hex(random_bytes(32));
        $target = $this->path('leases', $lease);
        // Atomic rename makes simultaneous attempts at the same ticket mutually exclusive.
        if (!@rename($source, $target)) {
            return null;
        }
        if ($this->validate($lease) === null) {
            @unlink($target);
            return null;
        }
        return $lease;
    }

    /** @return ?array{actor:array,session:array,sessionIdHash:string} */
    public function validate(?string $lease): ?array
    {
        if ($lease === null || !preg_match('/^[a-f0-9]{64}$/D', $lease)) {
            return null;
        }
        $record = $this->read($this->path('leases', $lease));
        if ($record === null || ($record['expiresAt'] ?? 0) <= time()
            || !preg_match('/^[a-f0-9]{64}$/D', $record['sessionIdHash'] ?? '')) {
            return null;
        }
        // File-backed PHP session garbage collection must also revoke an idle socket.
        $sessionFile = $record['phpSessionFile'] ?? null;
        if (is_string($sessionFile)) {
            clearstatcache(true, $sessionFile);
            if (is_link($sessionFile) || !is_file($sessionFile)) {
                return null;
            }
        }
        $generation = $this->read($this->path('sessions', $record['sessionIdHash']));
        if (!is_string($generation['generation'] ?? null)
            || !hash_equals($generation['generation'], (string) ($record['generation'] ?? ''))
            || !hash_equals(SessionEpoch::current($this->root), (string) ($record['epoch'] ?? ''))) {
            return null;
        }
        $verified = $this->identity((int) ($record['userId'] ?? 0), isset($record['originalUserId']) ? (int) $record['originalUserId'] : null);
        if ($verified === null || !hash_equals($verified['credentials'], (string) ($record['credentials'] ?? ''))) {
            return null;
        }
        return [
            'actor' => $verified['actor'],
            'session' => [
                'user' => $verified['actor'],
                'csrfToken' => $record['csrfToken'] ?? '',
                'impersonation' => [
                    'active' => $verified['original'] !== null,
                    'originalUser' => $verified['original'],
                ],
            ],
            'sessionIdHash' => $record['sessionIdHash'],
        ];
    }

    public function revokeCurrent(): void
    {
        if (session_id() === '') {
            return;
        }
        $path = $this->path('sessions', hash('sha256', session_id()));
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
        ChangeJournal::notify($this->root);
    }

    public function release(?string $lease): void
    {
        if ($lease === null || !preg_match('/^[a-f0-9]{64}$/D', $lease)) {
            return;
        }
        $path = $this->path('leases', $lease);
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    private function identity(int $userId, ?int $originalId): ?array
    {
        $statement = $this->db()->prepare('SELECT * FROM `User` WHERE userId=?');
        $statement->execute([$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$this->eligible($user)) {
            return null;
        }
        $parts = $this->credentialParts($user);
        $original = null;
        if ($originalId !== null) {
            $statement->execute([$originalId]);
            $original = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$this->eligible($original) || !in_array((int) $original['role'], [1, 2], true)) {
                return null;
            }
            array_push($parts, ...$this->credentialParts($original));
        }
        return [
            'credentials' => hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR)),
            'actor' => Dto::user($user),
            'original' => $original === null ? null : Dto::user($original),
        ];
    }

    private function eligible(array|false $row): bool
    {
        return $row !== false && (bool) $row['enabled'] && (!(defined('ONLY_ACTIVATED_USERS') && ONLY_ACTIVATED_USERS) || (bool) $row['activated']);
    }

    private function credentialParts(array $row): array
    {
        return [(int) $row['userId'], (string) $row['password'], (int) $row['role'], (bool) $row['enabled'], (bool) $row['activated']];
    }

    private function path(string $kind, string $id): string
    {
        $path = realpath($this->root);
        if ($path === false || is_link($this->root)) {
            throw new HttpError(503, 'live_unavailable', 'Live authentication storage is unavailable.');
        }
        foreach (['locks', 'live-auth', $kind] as $part) {
            $path .= '/' . $part;
            if (is_link($path) || file_exists($path) && !is_dir($path)) {
                throw new HttpError(503, 'live_unavailable', 'Live authentication storage is unsafe.');
            }
            if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) {
                throw new HttpError(503, 'live_unavailable', 'Live authentication storage is unavailable.');
            }
            if ((fileperms($path) & 02) !== 0) {
                throw new HttpError(503, 'live_unavailable', 'Live authentication storage is unsafe.');
            }
        }
        return $path . '/' . $id . '.json';
    }

    private function read(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 077) !== 0 || $stat['nlink'] !== 1 || $stat['size'] > 65536) {
            return null;
        }
        $value = json_decode((string) @file_get_contents($path), true);
        return is_array($value) ? $value : null;
    }

    private function create(string $path, array $value): void
    {
        $mask = umask(077);
        try {
            $file = @fopen($path, 'x+b');
        } finally {
            umask($mask);
        }
        if (!is_resource($file)) {
            throw new HttpError(503, 'live_unavailable', 'Live authentication storage could not be written.');
        }
        try {
            $bytes = json_encode($value, JSON_THROW_ON_ERROR);
            if (fwrite($file, $bytes) !== strlen($bytes)) {
                throw new HttpError(503, 'live_unavailable', 'Live authentication storage could not be written.');
            }
        } finally {
            fclose($file);
        }
    }

    private function prune(): void
    {
        foreach (['tickets' => 'ticketExpiresAt', 'leases' => 'expiresAt', 'sessions' => 'expiresAt'] as $kind => $expiry) {
            $directory = dirname($this->path($kind, str_repeat('0', 64)));
            $files = glob($directory . '/*.json') ?: [];
            shuffle($files);
            foreach (array_slice($files, 0, 200) as $file) {
                $record = $this->read($file);
                if ($record !== null && (int) ($record[$expiry] ?? 0) <= time()) {
                    @unlink($file);
                }
            }
        }
    }

    /** Serialize renewal and expiry cleanup so GC cannot delete a concurrently renewed generation. */
    private function lock(): mixed
    {
        $directory = dirname(dirname($this->path('sessions', str_repeat('0', 64))));
        $path = $directory . '/auth.lock';
        if (is_link($path)) {
            throw new HttpError(503, 'live_unavailable', 'Live authentication storage is unsafe.');
        }
        $mask = umask(077);
        try {
            $handle = @fopen($path, 'c+b');
        } finally {
            umask($mask);
        }
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new HttpError(503, 'live_unavailable', 'Live authentication storage is busy.');
        }
        return $handle;
    }

    /** The PHP session identifier stays exclusively in private server storage. */
    private function sessionFile(): ?string
    {
        if (session_module_name() !== 'files') {
            return null;
        }
        $parts = explode(';', session_save_path());
        $directory = array_pop($parts) ?: sys_get_temp_dir();
        $depth = $parts === [] ? 0 : (int) $parts[0];
        for ($index = 0; $index < $depth; ++$index) {
            $directory .= '/' . session_id()[$index];
        }
        return $directory . '/sess_' . session_id();
    }
}
