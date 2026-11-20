<?php
declare(strict_types=1);

namespace Chronos\Domain\LogAnalysis;

/** Classifies a single complete record from the original log. */
final class SignatureDetector
{
    public const VERSION = 'signatures-v1';
    public const MAX_LINE_BYTES = 8192;

    private const RULES = [
        'native-crash' => [
            '~^(?:[^:\r\n]{1,256}: (?:line )?[0-9]{1,10}: +[0-9]{1,10} +)?(?:segmentation fault|bus error|illegal instruction)(?: \(core dumped\))?[ \t]*$~iD',
            'critical', 'The record matches a native crash diagnostic.',
        ],
        'runtime-panic' => [
            '~^(?:panic:|(?:PHP )?fatal error:)\h+\S~i',
            'critical', 'The record starts with a runtime panic or fatal error diagnostic.',
        ],
        'timeout' => [
            '~^(?:timeout(?: (?:reached|expired)\b|: sending signal\b)|(?:operation|request|connection|command|job|process) timed out\b|context deadline exceeded\b|(?:TimeoutError|(?:java\.util\.concurrent\.)?TimeoutException)(?::|$))~iD',
            'error', 'The record matches a timeout or deadline diagnostic.',
        ],
        'python-traceback' => [
            '~^Traceback \(most recent call last\):[ \t]*$~D',
            'error', 'The record is the header of a Python exception traceback.',
        ],
    ];

    /** @return array{rule: string, severity: string, reason: string, excerpt: string}|null */
    public function detect(string $line): ?array
    {
        if (strlen($line) > self::MAX_LINE_BYTES) return null;

        // Keep offsets in raw bytes. Normalization is only for matching and the excerpt.
        $display = trim(preg_replace('/\x1b\[[0-9;:]{0,48}m/', '', $line), " \t\r\n");
        if ($display === '') return null;
        if (str_starts_with($display, "\xef\xbb\xbf")) $display = substr($display, 3);
        $message = preg_replace('/^\[?[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}:[0-9]{2}(?:[.,][0-9]{1,9})?(?:Z|[+-][0-9]{2}:?[0-9]{2})?\]?[ \t]+/', '', $display);
        $level = null;
        if (preg_match('/^(?:\[(ERROR|FATAL|CRITICAL)\][ \t]*|(ERROR|FATAL|CRITICAL)(?::[ \t]*|[ \t]+-[ \t]+))(?=\S)/i', $message, $match)) {
            $level = strtoupper($match[1] ?: $match[2]);
            $message = substr($message, strlen($match[0]));
        }

        foreach (self::RULES as $rule => [$pattern, $severity, $reason]) {
            if (preg_match($pattern, $message)) {
                return $this->finding($rule, $level === 'FATAL' || $level === 'CRITICAL' ? 'critical' : $severity, $reason, $display);
            }
        }
        if ($level !== null) {
            $severity = $level === 'ERROR' ? 'error' : 'critical';
            return $this->finding('log-level', $severity, "The record has an explicit $level log level.", $display);
        }
        return null;
    }

    private function finding(string $rule, string $severity, string $reason, string $line): array
    {
        $valid = mb_scrub($line, 'UTF-8');
        $excerpt = mb_strcut($valid, 0, 240, 'UTF-8');
        if (strlen($excerpt) < strlen($valid)) $excerpt .= '…';
        return compact('rule', 'severity', 'reason', 'excerpt');
    }
}
