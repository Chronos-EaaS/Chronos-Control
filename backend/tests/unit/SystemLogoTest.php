<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Shared\Context;
use Chronos\Application\Systems\SystemFiles;
use Chronos\Http\HttpError;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\VersionControl\Repository;
use PHPUnit\Framework\TestCase;

final class SystemLogoTest extends TestCase
{
    private string $root;
    private string $repository;
    private \PDO $pdo;
    private SystemFiles $files;
    private string|false|null $previousPath = null;

    protected function setUp(): void
    {
        if (!extension_loaded('gd') || !extension_loaded('pdo_sqlite')) self::markTestSkipped('The isolated logo regression requires GD and pdo_sqlite.');
        self::assertTrue(is_executable('/usr/bin/git'), 'The logo fixture requires native Git, independently of the web fixture command stubs.');
        $this->root = sys_get_temp_dir() . '/chronos-logo-test-' . bin2hex(random_bytes(12));
        $this->repository = $this->root . '/webroot/systems/1';
        self::assertTrue(mkdir($this->repository, 0700, true));
        self::assertTrue(mkdir($this->root . '/bin', 0700));
        self::assertTrue(symlink('/usr/bin/git', $this->root . '/bin/git'));
        $this->previousPath = getenv('PATH');
        putenv('PATH=' . $this->root . '/bin');
        [$code, $version] = Repository::run($this->repository, ['--version']);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^git version [0-9]/', $version);
        Repository::initialize($this->repository, $this->root);
        self::assertSame(0, Repository::run($this->repository, ['config', '--local', 'commit.gpgsign', 'false'])[0]);
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new \Pdo\Sqlite('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION])
            : new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $concat = static fn(string $separator, mixed ...$values): string => implode($separator, array_filter($values, static fn(mixed $value): bool => $value !== null));
        if ($this->pdo instanceof \Pdo\Sqlite) $this->pdo->createFunction('CONCAT_WS', $concat);
        else $this->pdo->sqliteCreateFunction('CONCAT_WS', $concat);
        $this->pdo->exec('CREATE TABLE User(userId INTEGER PRIMARY KEY,firstname TEXT,lastname TEXT)');
        $this->pdo->exec("INSERT INTO User VALUES(1,'Synthetic','Owner')");
        $this->pdo->exec('CREATE TABLE System(systemId INTEGER PRIMARY KEY,userId INTEGER,vcsUrl TEXT)');
        $this->pdo->exec("INSERT INTO System VALUES(1,1,'')");
        $this->pdo->exec('CREATE TABLE Event(eventId INTEGER PRIMARY KEY,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId TEXT)');
        $context = new Context(new Store($this->pdo), ['id' => 1, 'role' => 0], $this->root);
        $this->files = new SystemFiles($context, $this->root);
    }

    protected function tearDown(): void
    {
        if ($this->previousPath !== null) putenv($this->previousPath === false ? 'PATH' : 'PATH=' . $this->previousPath);
        if (!isset($this->root) || !is_dir($this->root)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testCompressedPhotographCanBeSavedAndServedAfterNormalization(): void
    {
        $image = imagecreatetruecolor(1400, 1400);
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(20261018, MT_RAND_MT19937));
        for ($y = 0; $y < 1400; ++$y) for ($x = 0; $x < 1400; ++$x) imagesetpixel($image, $x, $y, $random->getInt(0, 0xffffff));
        $jpeg = self::encode($image, 'jpeg');
        unset($image);
        self::assertLessThanOrEqual(524288, strlen($jpeg), 'The source photograph meets the upload limit.');
        $decoded = imagecreatefromstring($jpeg);
        self::assertGreaterThan(2097152, strlen(self::encode($decoded)), 'The original full-size normalization exceeds the serving limit.');
        unset($decoded);

        $bytes = $this->saveAndRead($jpeg, 'jpeg');
        self::assertLessThanOrEqual(2097152, strlen($bytes));
        self::assertSame([512, 512], array_slice(getimagesizefromstring($bytes), 0, 2));
        [$code, $committed] = Repository::run($this->repository, ['show', 'HEAD:logo.png']);
        self::assertSame(0, $code);
        self::assertSame($bytes, $committed, 'The served logo is the exact version recorded in local history.');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM Event')->fetchColumn());
    }

    public function testLargeTransparentPortraitRetainsAspectRatioAndAlpha(): void
    {
        $image = self::transparentImage(1024, 2048);
        imagefilledrectangle($image, 256, 512, 767, 1535, imagecolorallocatealpha($image, 12, 90, 170, 64));
        $bytes = $this->saveAndRead(self::encode($image), 'png');
        unset($image);
        self::assertSame([256, 512], array_slice(getimagesizefromstring($bytes), 0, 2));
        $saved = imagecreatefromstring($bytes);
        self::assertSame(127, imagecolorsforindex($saved, imagecolorat($saved, 0, 0))['alpha']);
        self::assertSame(['red' => 12, 'green' => 90, 'blue' => 170, 'alpha' => 64], imagecolorsforindex($saved, imagecolorat($saved, 128, 256)));
    }

    public function testSmallTransparentImageKeepsItsDimensionsAndAlpha(): void
    {
        $image = self::transparentImage(80, 40);
        imagefilledrectangle($image, 20, 10, 59, 29, imagecolorallocatealpha($image, 12, 90, 170, 64));
        $bytes = $this->saveAndRead(self::encode($image), 'png');
        unset($image);
        self::assertSame([80, 40], array_slice(getimagesizefromstring($bytes), 0, 2));
        $saved = imagecreatefromstring($bytes);
        self::assertSame(127, imagecolorsforindex($saved, imagecolorat($saved, 0, 0))['alpha']);
        self::assertSame(64, imagecolorsforindex($saved, imagecolorat($saved, 40, 20))['alpha']);
    }

    public function testPaletteGifKeepsTransparencyWhenResizedToPng(): void
    {
        $image = imagecreate(1024, 512);
        $transparent = imagecolorallocate($image, 255, 0, 255);
        imagecolortransparent($image, $transparent);
        imagefilledrectangle($image, 256, 128, 767, 383, imagecolorallocate($image, 12, 90, 170));
        $bytes = $this->saveAndRead(self::encode($image, 'gif'), 'gif');
        unset($image);
        self::assertSame([512, 256], array_slice(getimagesizefromstring($bytes), 0, 2));
        $saved = imagecreatefromstring($bytes);
        self::assertSame(127, imagecolorsforindex($saved, imagecolorat($saved, 0, 0))['alpha']);
        self::assertSame(['red' => 12, 'green' => 90, 'blue' => 170, 'alpha' => 0], imagecolorsforindex($saved, imagecolorat($saved, 256, 128)));
    }

    public function testRejectedReplacementPreservesPreviousLogoHistoryAndEvent(): void
    {
        $image = self::transparentImage(80, 40);
        $original = $this->saveAndRead(self::encode($image), 'png');
        unset($image);
        $wide = imagecreatetruecolor(4097, 1);
        $widePng = self::encode($wide);
        unset($wide);
        [$code, $head] = Repository::run($this->repository, ['rev-parse', 'HEAD']);
        self::assertSame(0, $code);
        foreach (['not an image', $widePng, $original . str_repeat('x', 524289)] as $invalid) {
            try {
                $this->files->saveLogo(1, ['dataUrl' => 'data:image/png;base64,' . base64_encode($invalid)]);
                self::fail('An invalid replacement must be rejected before updating the existing logo.');
            } catch (HttpError $error) {
                self::assertSame(422, $error->status);
                self::assertArrayHasKey('dataUrl', $error->fields);
            }
            self::assertSame($original, $this->readLogo());
            self::assertSame([0, $head], Repository::run($this->repository, ['rev-parse', 'HEAD']));
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM Event')->fetchColumn());
            self::assertSame([], glob($this->repository . '/.v3-*'));
        }
    }

    private function saveAndRead(string $source, string $mime): string
    {
        self::assertSame(['logoUrl' => '/api/ui/systems/1/logo'], $this->files->saveLogo(1, ['dataUrl' => 'data:image/' . $mime . ';base64,' . base64_encode($source)]));
        $bytes = $this->readLogo();
        self::assertSame('image/png', getimagesizefromstring($bytes)['mime']);
        self::assertSame([0, ''], Repository::run($this->repository, ['status', '--porcelain']), 'Successful save leaves a clean local repository.');
        return $bytes;
    }

    private function readLogo(): string
    {
        clearstatcache(true, $this->repository . '/logo.png');
        $download = $this->files->logo(1);
        self::assertSame('image/png', $download->contentType);
        self::assertNull($download->filename);
        ob_start();
        try { ($download->stream)(); $bytes = (string) ob_get_contents(); }
        finally { ob_end_clean(); }
        self::assertSame(file_get_contents($this->repository . '/logo.png'), $bytes);
        return $bytes;
    }

    private static function transparentImage(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        return $image;
    }

    private static function encode(\GdImage $image, string $format = 'png'): string
    {
        ob_start();
        try {
            if ($format === 'jpeg') self::assertTrue(imagejpeg($image, null, 12));
            elseif ($format === 'gif') self::assertTrue(imagegif($image));
            else self::assertTrue(imagepng($image));
            return (string) ob_get_contents();
        } finally { ob_end_clean(); }
    }
}
