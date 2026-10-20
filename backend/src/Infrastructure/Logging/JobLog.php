<?php
/*
The MIT License (MIT)

Copyright (c) 2018 The Chronos Project

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 */

declare(strict_types=1);
namespace Chronos\Infrastructure\Logging;

use Chronos\Infrastructure\Realtime\ChangeJournal;

/** Appends agent log fragments without changing their ordering or text. */
final class JobLog
{
    public function __construct(private readonly string $dataDirectory) {}

    public function append(int $jobId, string $message): void
    {
        $directory = rtrim($this->dataDirectory, '/') . '/log';
        if (!is_dir($directory)) mkdir($directory);
        if (file_put_contents($directory . '/' . $jobId . '.log', $message, FILE_APPEND | LOCK_EX) !== strlen($message)) throw new \RuntimeException('Unable to append job log.');
        ChangeJournal::fileChanged(dirname(dirname(rtrim($this->dataDirectory, '/'))), 'JobLog', (string) $jobId);
    }

    public static function epoch(string $file): string
    {
        $marker = $file . '.epoch';
        if (!is_link($marker) && is_file($marker) && filesize($marker) === 64) {
            $value = (string) file_get_contents($marker);
            if (preg_match('/^[a-f0-9]{64}$/D', $value)) return $value;
        }
        $stat = is_file($file) ? stat($file) : false;
        return hash('sha256', $file . ':' . ($stat === false ? 'missing' : $stat['dev'] . ':' . $stat['ino']));
    }

    public static function resetEpoch(string $file): void
    {
        $marker = $file . '.epoch';
        if (is_link($marker)) throw new \RuntimeException('Unable to reset job log generation.');
        $temporary = tempnam(dirname($file), '.log-epoch-');
        if ($temporary === false) throw new \RuntimeException('Unable to reset job log generation.');
        try {
            if (file_put_contents($temporary, bin2hex(random_bytes(32))) !== 64 || !chmod($temporary, 0600) || !rename($temporary, $marker)) throw new \RuntimeException('Unable to reset job log generation.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
}
