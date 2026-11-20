<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Agents;

use Chronos\Http\HttpError;

final class AttemptFiles
{
    public static function log(string $data, int $attemptId): string
    {
        return rtrim($data, '/') . '/log/attempt-' . $attemptId . '.log';
    }

    public static function archive(string $data, int $attemptId, string $sha256): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sha256)) throw new \LogicException('Invalid stored archive digest.');
        return rtrim($data, '/') . '/attempt-archives/attempt-' . $attemptId . '-' . $sha256 . '.zip';
    }

    public static function readableArchive(string $data, int $attemptId, array $identity): string
    {
        $file = self::archive($data, $attemptId, $identity['sha256']);
        $base = realpath(rtrim($data, '/') . '/attempt-archives');
        if ($base === false || !is_file($file) || is_link($file) || dirname(realpath($file) ?: '') !== $base
            || filesize($file) !== $identity['sizeBytes'] || hash_file('sha256', $file) !== $identity['sha256']) {
            throw new HttpError(404, 'archive_unavailable', 'The attempt archive is missing or its digest does not match the recorded evidence.');
        }
        return $file;
    }
}
