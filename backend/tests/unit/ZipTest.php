<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/legacy/libraries/zip.php';

final class ZipTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/chronos-zip-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            if (is_link($path) || !is_dir($path)) { unlink($path); return; }
            foreach (new \FilesystemIterator($path) as $entry) $remove($entry->getPathname());
            rmdir($path);
        };
        $remove($this->directory);
    }

    private function archive(callable $entries): \ZipArchive
    {
        $writer = new \Zip_Library('Fixture Zürich.zip', false);
        $entries($writer); $writer->prepare();
        $stream = fopen($this->directory . '/actual.zip', 'wb');
        try { $writer->done($stream); } finally { fclose($stream); }
        $read = new \ZipArchive(); self::assertTrue($read->open($this->directory . '/actual.zip'));
        return $read;
    }

    public function testGeneratedTextBinaryAndFileEntriesRoundTripExactly(): void
    {
        $binary = "\0\xff\x80PK\x03\x04\r\n";
        file_put_contents($this->directory . '/source', $binary);
        $read = $this->archive(function (\Zip_Library $writer) use ($binary): void {
            $writer->addFile("Zürich & XML <value>\r\n0", 'settings/Name with spaces.json');
            $writer->addFile('', 'empty.txt');
            $writer->addFilePath($this->directory . '/source', 'results/Zürich.zip');
        });
        try {
            self::assertSame(3, $read->numFiles);
            self::assertSame("Zürich & XML <value>\r\n0", $read->getFromName('settings/Name with spaces.json'));
            self::assertSame('', $read->getFromName('empty.txt'));
            self::assertSame($binary, $read->getFromName('results/Zürich.zip'));
            self::assertSame(\ZipArchive::CM_STORE, $read->statName('results/Zürich.zip')['comp_method']);
        } finally { $read->close(); }
    }

    public function testEmptyArchiveIsValidWithoutAPlaceholderEntry(): void
    {
        $read = $this->archive(static function (\Zip_Library $writer): void {});
        try { self::assertSame(0, $read->numFiles); self::assertSame(22, filesize($this->directory . '/actual.zip')); }
        finally { $read->close(); }
    }

    public static function unsafeEntryNames(): array
    {
        return array_map(static fn(string $name): array => [$name], ['', '/root', '../outside', 'safe/../outside', './file', 'safe//file', 'safe/', 'C:drive', 'safe\\file', "line\nfile", "nul\0file", "invalid\xff"]);
    }

    #[DataProvider('unsafeEntryNames')]
    public function testUnsafeEntryNamesCannotEnterADownload(string $name): void
    {
        $writer = new \Zip_Library('fixture.zip', false);
        $this->expectException(\InvalidArgumentException::class);
        $writer->addFile('synthetic', $name);
    }

    public function testDuplicateEntriesAreRejectedWithoutReplacingTheFirstBytes(): void
    {
        $read = $this->archive(static function (\Zip_Library $writer): void {
            $writer->addFile('first', 'same.json');
            try { $writer->addFile('replacement', 'same.json'); self::fail('Duplicate entry accepted.'); }
            catch (\InvalidArgumentException) {}
        });
        try { self::assertSame(1, $read->numFiles); self::assertSame('first', $read->getFromName('same.json')); }
        finally { $read->close(); }
    }

    public function testMissingFilesAndSourceLinksAreRejectedBeforeAnyOutput(): void
    {
        $writer = new \Zip_Library('fixture.zip', false);
        try { $writer->addFilePath($this->directory . '/absent', 'missing.zip'); self::fail('Missing source accepted.'); }
        catch (\RuntimeException $error) { self::assertSame('A download source file is unavailable.', $error->getMessage()); }
        file_put_contents($this->directory . '/real', 'synthetic'); symlink($this->directory . '/real', $this->directory . '/link');
        $this->expectException(\RuntimeException::class);
        $writer->addFilePath($this->directory . '/link', 'linked.zip');
    }

    public function testPreparingDetectsAFileRemovedAfterItWasQueued(): void
    {
        $source = $this->directory . '/vanishes'; file_put_contents($source, 'synthetic');
        $writer = new \Zip_Library('fixture.zip', false); $writer->addFilePath($source, 'vanishes.zip'); unlink($source);
        $this->expectException(\RuntimeException::class); $writer->prepare();
    }

    public function testHeaderInjectionCannotCreateADownload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \Zip_Library("fixture.zip\r\nX-Injected: yes", false);
    }

    public function testPreparationFailureProducesNoOutputOrPrivateStagingFiles(): void
    {
        $source = $this->directory . '/vanishes'; file_put_contents($source, 'synthetic'); mkdir($this->directory . '/stage', 0700);
        $script = 'require $argv[1];$zip=new Zip_Library("failure.zip",false);$zip->addFilePath($argv[2],"source.zip");unlink($argv[2]);try{$zip->prepare();exit(51);}catch(RuntimeException){}';
        $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/legacy/libraries/zip.php', $source], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/failure-output', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $this->directory . '/stage']);
        self::assertIsResource($process); $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors); self::assertSame('', $errors);
        self::assertSame(0, filesize($this->directory . '/failure-output'));
        self::assertSame([], glob($this->directory . '/stage/*'));
    }

    public function testFinishedArchiveCannotBeWrittenOrStreamedTwice(): void
    {
        $writer = new \Zip_Library('fixture.zip', false); $stream = fopen($this->directory . '/once.zip', 'wb');
        try { $writer->done($stream); } finally { fclose($stream); }
        $this->expectException(\LogicException::class); $writer->done();
    }

    public function testLargeFileDownloadUsesBoundedMemoryAndRemovesItsPrivateStage(): void
    {
        $source = $this->directory . '/large-result.zip'; $file = fopen($source, 'wb');
        try { self::assertTrue(ftruncate($file, 32 * 1024 * 1024)); } finally { fclose($file); }
        mkdir($this->directory . '/stage', 0700);
        $script = 'require $argv[1];$zip=new Zip_Library("large.zip",false);$zip->addFilePath($argv[2],"result.zip");$zip->prepare();$zip->done();fwrite(STDERR,json_encode(["peakBytes"=>memory_get_peak_usage(true)]));';
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=16M', '-r', $script, dirname(__DIR__, 2) . '/legacy/libraries/zip.php', $source], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/large-download.zip', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $this->directory . '/stage']);
        self::assertIsResource($process); $report = stream_get_contents($pipes[2]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $report);
        self::assertLessThan(8 * 1024 * 1024, json_decode($report, true, 8, JSON_THROW_ON_ERROR)['peakBytes']);
        self::assertSame([], glob($this->directory . '/stage/*'));
        $read = new \ZipArchive(); self::assertTrue($read->open($this->directory . '/large-download.zip'));
        try {
            self::assertSame(1, $read->numFiles); self::assertSame(32 * 1024 * 1024, $read->statName('result.zip')['size']);
            $entry = $read->getStream('result.zip'); self::assertIsResource($entry);
            try { $hash = hash_init('sha256'); hash_update_stream($hash, $entry); self::assertSame(hash_file('sha256', $source), hash_final($hash)); }
            finally { fclose($entry); }
        } finally { $read->close(); }
    }
}
