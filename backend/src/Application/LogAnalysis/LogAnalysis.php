<?php
declare(strict_types=1);

namespace Chronos\Application\LogAnalysis;

use Chronos\Domain\LogAnalysis\SignatureDetector;

final class LogAnalysis
{
    public const MAX_PAGE_BYTES = 131072;
    public const MAX_FINDINGS = 100;
    // The byte range returned in JSON must remain exactly representable in the browser.
    private const MAX_SAFE_OFFSET = 9007199254740991;

    public function __construct(private readonly SignatureDetector $detector = new SignatureDetector())
    {
    }

    public static function validCursor(string $cursor): bool
    {
        if (!preg_match('/^[0-9a-f]{64}:(0|[1-9][0-9]{0,15}):(0|[1-9][0-9]{0,15})$/D', $cursor, $match)) return false;
        return (int) $match[1] <= (int) $match[2] && (int) $match[2] <= self::MAX_SAFE_OFFSET;
    }

    public function scan(LogSource $source, ?string $cursor = null): array
    {
        if ($cursor !== null && !self::validCursor($cursor)) throw new \InvalidArgumentException('Invalid log analysis cursor.');
        [$epoch, $offset, $previousSize] = $cursor === null ? [null, 0, 0] : explode(':', $cursor);
        $window = $source->read((int) $offset, self::MAX_PAGE_BYTES, $epoch, (int) $previousSize);
        $length = strlen($window->bytes);
        $findings = [];
        $skippedLongLines = 0;
        $pendingLine = false;
        $position = 0;
        // An oversized record can span multiple pages. A preceding non-LF byte
        // identifies its continuation without storing unbounded text or client state.
        if (!$window->startsAtLine) {
            $newline = strpos($window->bytes, "\n");
            $position = $newline === false ? $length : $newline + 1;
        }
        while ($position < $length && count($findings) < self::MAX_FINDINGS) {
            $newline = strpos($window->bytes, "\n", $position);
            $end = $newline === false ? $length : $newline + 1;
            $lineBytes = $end - $position;
            if ($newline === false && $lineBytes < SignatureDetector::MAX_LINE_BYTES) {
                $pendingLine = $window->offset + $length === $window->size;
                break;
            }

            if ($newline === false || $lineBytes > SignatureDetector::MAX_LINE_BYTES) {
                ++$skippedLongLines;
            } else {
                $line = substr($window->bytes, $position, $lineBytes);
                $match = $this->detector->detect($line);
                if ($match !== null) {
                    $findings[] = $match + [
                        'detectorVersion' => SignatureDetector::VERSION,
                        'epoch' => $window->epoch,
                        'byteStart' => $window->offset + $position,
                        'byteEnd' => $window->offset + $end,
                        'lineSha256' => hash('sha256', $line),
                    ];
                }
            }
            $position = $end;
        }

        $nextOffset = $window->offset + $position;
        return [
            'detectorVersion' => SignatureDetector::VERSION,
            'available' => $window->available,
            'reset' => $window->reset,
            'epoch' => $window->epoch,
            'offset' => $window->offset,
            'nextOffset' => $nextOffset,
            'size' => $window->size,
            'hasMore' => !$pendingLine && $nextOffset < $window->size,
            'pendingLine' => $pendingLine,
            'skippedLongLines' => $skippedLongLines,
            'findings' => $findings,
            'startCursor' => $window->epoch . ':' . $window->offset . ':' . $window->size,
            'cursor' => $window->epoch . ':' . $nextOffset . ':' . $window->size,
        ];
    }
}
