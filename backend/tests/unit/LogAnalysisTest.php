<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\LogAnalysis\LogAnalysis;
use Chronos\Domain\LogAnalysis\SignatureDetector;
use Chronos\Infrastructure\Logging\FileLogSource;
use Chronos\Infrastructure\Logging\JobLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogAnalysisTest extends TestCase
{
    private string $directory;
    private string $file;
    private LogAnalysis $analysis;
    private FileLogSource $source;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/chronos-log-analysis-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->file = $this->directory . '/11.log';
        $this->source = new FileLogSource($this->file);
        $this->analysis = new LogAnalysis();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) unlink($file);
        rmdir($this->directory);
    }

    public static function diagnostics(): iterable
    {
        yield ["\x1b[31m[2026-11-20T15:20:01.032Z] [ERROR] could not open fixture\x1b[0m\r\n", 'log-level', 'error'];
        yield ["ERROR:root:SQL fixture connection refused\n", 'log-level', 'error'];
        yield ["FATAL - worker could not initialize\n", 'log-level', 'critical'];
        yield ["./run-fixture.sh: line 8: 723 Segmentation fault (core dumped)\n", 'native-crash', 'critical'];
        yield ["[FATAL] Connection timed out\n", 'timeout', 'critical'];
        yield ["PHP Fatal error: Uncaught RuntimeException\n", 'runtime-panic', 'critical'];
        yield ["panic: runtime error: index out of range\n", 'runtime-panic', 'critical'];
        yield ["timeout: sending signal TERM to command ‘sleep’\n", 'timeout', 'error'];
        yield ["[ERROR] Connection timed out after 30 seconds\n", 'timeout', 'error'];
        yield ["Traceback (most recent call last):\n", 'python-traceback', 'error'];
    }

    #[DataProvider('diagnostics')]
    public function testSignaturesHaveReproducibleRawByteRanges(string $line, string $rule, string $severity): void
    {
        $prefix = "INFO préparation démarrée 🙂\r\n";
        file_put_contents($this->file, $prefix . $line);
        $page = $this->analysis->scan($this->source);
        $finding = $page['findings'][0];
        self::assertCount(1, $page['findings']);
        self::assertSame($rule, $finding['rule']);
        self::assertSame($severity, $finding['severity']);
        self::assertSame(SignatureDetector::VERSION, $finding['detectorVersion']);
        self::assertSame($page['epoch'], $finding['epoch']);
        self::assertNotEmpty($finding['reason']);
        self::assertSame(strlen($prefix), $finding['byteStart']);
        self::assertSame($line, file_get_contents($this->file, false, null, $finding['byteStart'], $finding['byteEnd'] - $finding['byteStart']));
        self::assertSame(hash('sha256', $line), $finding['lineSha256']);
        self::assertStringNotContainsString("\x1b", $finding['excerpt']);
        self::assertSame($page, $this->analysis->scan($this->source, $page['startCursor']));
    }

    public function testNormalLogsAndHarmlessDiagnosticWords(): void
    {
        $log = "INFO timeout configured to 30 seconds\nTimeout configured to 30 seconds\n"
            . "error margin 0.04\nERROR_COUNT=0\nINFO no segmentation fault observed\n"
            . "[INFO] Expected error, continuing\nRequest did not time out\nFinished workload ✓\n";
        file_put_contents($this->file, $log);
        $result = $this->analysis->scan($this->source);
        self::assertSame([], $result['findings']);
        self::assertSame(strlen($log), $result['nextOffset']);
        self::assertFalse($result['hasMore']);
        self::assertFalse($result['pendingLine']);
    }

    public function testWaitsForCompleteLineIncludingASplitUtf8CharacterAndDoesNotRepeatFindings(): void
    {
        $initial = "ERROR: first\n";
        file_put_contents($this->file, $initial . "ERROR: fixture \xf0\x9f");
        $first = $this->analysis->scan($this->source);
        self::assertCount(1, $first['findings']);
        self::assertTrue($first['pendingLine']);
        self::assertFalse($first['hasMore']);
        self::assertSame(strlen($initial), $first['nextOffset']);
        self::assertSame([], $this->analysis->scan($this->source, $first['cursor'])['findings']);

        file_put_contents($this->file, "\x99\x82\r\n", FILE_APPEND);
        $second = $this->analysis->scan($this->source, $first['cursor']);
        self::assertSame(strlen($initial), $second['findings'][0]['byteStart']);
        self::assertSame('ERROR: fixture 🙂', $second['findings'][0]['excerpt']);
        self::assertFalse($second['pendingLine']);
        self::assertFalse($second['reset']);
        self::assertSame([], $this->analysis->scan($this->source, $second['cursor'])['findings']);
    }

    public function testMalformedUtf8KeepsLaterOffsetsAndProducesValidJson(): void
    {
        $line = "ERROR: broken \xff byte " . str_repeat('é', 300) . "\n";
        file_put_contents($this->file, $line . "ERROR: next\n");
        $result = $this->analysis->scan($this->source);
        self::assertCount(2, $result['findings']);
        self::assertSame(strlen($line), $result['findings'][1]['byteStart']);
        self::assertLessThanOrEqual(243, strlen($result['findings'][0]['excerpt']));
        self::assertStringEndsWith('…', $result['findings'][0]['excerpt']);
        self::assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testOversizedRecordSpanningPagesIsSkippedOnceAndFindingsResumeAtLineBoundary(): void
    {
        $remainder = "ERROR: a fragment of the long line\n";
        $long = "ERROR: " . str_repeat('x', LogAnalysis::MAX_PAGE_BYTES - 7) . $remainder;
        file_put_contents($this->file, $long . "ERROR: actual line\n");
        $first = $this->analysis->scan($this->source);
        self::assertTrue($first['hasMore']);
        self::assertSame(LogAnalysis::MAX_PAGE_BYTES, $first['nextOffset']);
        self::assertSame(1, $first['skippedLongLines']);
        self::assertSame([], $first['findings']);
        $second = $this->analysis->scan($this->source, $first['cursor']);
        self::assertCount(1, $second['findings']);
        self::assertSame(strlen($long), $second['findings'][0]['byteStart']);
        self::assertSame(0, $second['skippedLongLines']);
    }

    public function testPageBoundaryRewindsShortFragmentAndSupportsOffsetsAboveTwoGiB(): void
    {
        $offset = 3 * 1024 * 1024 * 1024;
        $handle = fopen($this->file, 'wb');
        fseek($handle, $offset - 1);
        fwrite($handle, "\nERROR: at a large offset\n");
        fclose($handle);
        $first = $this->analysis->scan($this->source);
        $page = $this->analysis->scan($this->source, $first['epoch'] . ':' . $offset . ':' . $first['size']);
        self::assertSame($offset, $page['findings'][0]['byteStart']);

        $normal = str_repeat(str_repeat('x', 1023) . "\n", 127) . str_repeat('x', 1020) . "\n";
        file_put_contents($this->file, $normal . "ERROR: crossing scan boundary\n");
        $first = $this->analysis->scan($this->source);
        self::assertTrue($first['hasMore']);
        self::assertFalse($first['pendingLine']);
        self::assertSame(strlen($normal), $first['nextOffset']);
        $second = $this->analysis->scan($this->source, $first['cursor']);
        self::assertSame(strlen($normal), $second['findings'][0]['byteStart']);
    }

    public function testFindingLimitCanBePagedWithoutDroppingARecord(): void
    {
        $line = "ERROR: repeated fixture\n";
        file_put_contents($this->file, str_repeat($line, LogAnalysis::MAX_FINDINGS + 1));
        $first = $this->analysis->scan($this->source);
        self::assertCount(LogAnalysis::MAX_FINDINGS, $first['findings']);
        self::assertTrue($first['hasMore']);
        $second = $this->analysis->scan($this->source, $first['cursor']);
        self::assertCount(1, $second['findings']);
        self::assertSame(LogAnalysis::MAX_FINDINGS * strlen($line), $second['findings'][0]['byteStart']);
        self::assertFalse($second['hasMore']);
    }

    public function testMissingLogThenReplacementAndClearResetTheCursor(): void
    {
        $missing = $this->analysis->scan($this->source);
        self::assertFalse($missing['available']);
        file_put_contents($this->file, "ERROR: first log\n");
        $appeared = $this->analysis->scan($this->source, $missing['cursor']);
        self::assertTrue($appeared['available']);
        self::assertTrue($appeared['reset']);
        self::assertSame(0, $appeared['findings'][0]['byteStart']);

        JobLog::resetEpoch($this->file);
        file_put_contents($this->file, "ERROR: rewritten after clear\n");
        $cleared = $this->analysis->scan($this->source, $appeared['cursor']);
        self::assertTrue($cleared['reset']);
        self::assertSame(0, $cleared['offset']);

        rename($this->file, $this->file . '.old');
        file_put_contents($this->file, "ERROR: replacement has equal or greater length\n");
        $rotated = $this->analysis->scan($this->source, $cleared['cursor']);
        self::assertTrue($rotated['reset']);
        self::assertSame(0, $rotated['findings'][0]['byteStart']);
    }

    public function testInPlaceTruncationResetsEvenIfPreviousOffsetIsStillInBounds(): void
    {
        $line = "ERROR: repeated fixture\n";
        file_put_contents($this->file, str_repeat($line, LogAnalysis::MAX_FINDINGS + 10));
        $first = $this->analysis->scan($this->source);
        file_put_contents($this->file, str_repeat($line, LogAnalysis::MAX_FINDINGS + 5));
        $second = $this->analysis->scan($this->source, $first['cursor']);
        self::assertLessThan($second['size'], $first['nextOffset']);
        self::assertSame($first['epoch'], $second['epoch']);
        self::assertTrue($second['reset']);
        self::assertSame(0, $second['findings'][0]['byteStart']);
    }
}
