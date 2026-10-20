<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Infrastructure\Logging\JobLog;
use PHPUnit\Framework\TestCase;

final class LiveLogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/chronos-live-log-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/webroot/data/log', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testOrdinaryAppendsKeepTheGenerationAndExactUnicodeBytes(): void
    {
        $log = new JobLog($this->root . '/webroot/data');
        $log->append(3, "First line Zürich\n");
        $file = $this->root . '/webroot/data/log/3.log';
        $epoch = JobLog::epoch($file);
        $log->append(3, "Second line 🕰️\n");
        self::assertSame($epoch, JobLog::epoch($file));
        self::assertSame("First line Zürich\nSecond line 🕰️\n", file_get_contents($file));
    }

    public function testClearAndRegrowBeyondTheOldOffsetStillChangesGeneration(): void
    {
        $file = $this->root . '/webroot/data/log/3.log';
        file_put_contents($file, 'old');
        $before = JobLog::epoch($file);
        file_put_contents($file, '');
        JobLog::resetEpoch($file);
        file_put_contents($file, str_repeat('new', 100));
        self::assertNotSame($before, JobLog::epoch($file));
        self::assertSame(0600, fileperms($file . '.epoch') & 0777);
    }

    public function testLinkedGenerationCannotOverwriteAnotherFile(): void
    {
        $file = $this->root . '/webroot/data/log/3.log';
        $outside = $this->root . '/outside';
        file_put_contents($outside, 'retained');
        symlink($outside, $file . '.epoch');
        try {
            JobLog::resetEpoch($file);
            self::fail('An unsafe generation marker was accepted.');
        } catch (\RuntimeException) {
            self::assertSame('retained', file_get_contents($outside));
        }
    }
}
