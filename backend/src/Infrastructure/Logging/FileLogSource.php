<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Logging;

use Chronos\Application\LogAnalysis\LogSource;
use Chronos\Application\LogAnalysis\LogWindow;
use Chronos\Http\HttpError;

/** Reads the existing job-log format without decoding or changing the stored bytes. */
final class FileLogSource implements LogSource
{
    public function __construct(private readonly string $file)
    {
    }

    public function read(int $offset, int $limit, ?string $epoch, int $previousSize): LogWindow
    {
        clearstatcache(true, $this->file);
        $handle = is_file($this->file) ? @fopen($this->file, 'rb') : false;
        if ($handle === false) {
            clearstatcache(true, $this->file);
            if (is_file($this->file)) throw new \RuntimeException('Unable to read job log.');
            $missingEpoch = hash('sha256', $this->file . ':missing-analysis');
            return new LogWindow('', 0, 0, $missingEpoch, $epoch !== null && $epoch !== $missingEpoch, true, false);
        }

        try {
            // Appenders and the normal log clear take an exclusive lock. Keep the
            // lock only through the bounded read and let the caller retry on contention.
            if (!flock($handle, LOCK_SH | LOCK_NB)) throw $this->retry();
            $stat = fstat($handle);
            if ($stat === false) throw new \RuntimeException('Unable to stat job log.');
            clearstatcache(true);
            // Include descriptor identity even when a marker from an earlier clear exists.
            $currentEpoch = hash('sha256', JobLog::epoch($this->file) . ':' . $stat['dev'] . ':' . $stat['ino']);
            $size = $stat['size'];
            $reset = ($epoch !== null && !hash_equals($currentEpoch, $epoch)) || $size < $previousSize || $offset > $size;
            if ($reset) $offset = 0;

            $lookBehind = $offset > 0 ? 1 : 0;
            $bytesToRead = min($limit, $size - $offset) + $lookBehind;
            if (fseek($handle, $offset - $lookBehind) !== 0) throw $this->retry();
            $bytes = stream_get_contents($handle, $bytesToRead);
            if ($bytes === false || strlen($bytes) !== $bytesToRead) throw $this->retry();

            return new LogWindow(substr($bytes, $lookBehind), $offset, $size, $currentEpoch, $reset, !$lookBehind || $bytes[0] === "\n", true);
        } finally {
            fclose($handle);
        }
    }

    private function retry(): HttpError
    {
        return new HttpError(409, 'log_read_conflict', 'The job log is being updated. Run the scan again.');
    }
}
