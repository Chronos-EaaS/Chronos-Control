<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Api\V3\HttpError;
use Chronos\Api\V3\SessionEpoch;
use PHPUnit\Framework\TestCase;

require_once CHRONOS_TEST_ROOT . '/api/v3/HttpError.php';
require_once CHRONOS_TEST_ROOT . '/api/v3/SessionEpoch.php';

final class V3SessionEpochTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/chronos-session-epoch-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root . '/locks')) {
            foreach (glob($this->root . '/locks/*') as $file) unlink($file);
            rmdir($this->root . '/locks');
        }
        if (is_file($this->root . '/outside')) unlink($this->root . '/outside');
        rmdir($this->root);
    }

    public function testGenerationIsStableAcrossReadsAndChangesOnlyOnRotation(): void
    {
        $initial = SessionEpoch::current($this->root);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $initial);
        self::assertSame($initial, SessionEpoch::current($this->root));
        $next = SessionEpoch::rotate($this->root);
        self::assertNotSame($initial, $next);
        self::assertSame($next, SessionEpoch::current($this->root));
        self::assertSame(0600, fileperms($this->root . '/locks/v3-session-epoch') & 0777);
    }

    public function testCorruptEpochFailsClosedWithoutReplacingItsContents(): void
    {
        SessionEpoch::current($this->root);
        $file = $this->root . '/locks/v3-session-epoch';
        file_put_contents($file, 'invalid');
        try {
            SessionEpoch::current($this->root);
            self::fail('A corrupt session generation was accepted.');
        } catch (HttpError $error) {
            self::assertSame(503, $error->status);
            self::assertSame('invalid', file_get_contents($file));
        }
    }

    public function testLinkedEpochCannotModifyAnyOutsideFile(): void
    {
        mkdir($this->root . '/locks', 0700);
        file_put_contents($this->root . '/outside', 'untouched');
        symlink($this->root . '/outside', $this->root . '/locks/v3-session-epoch');
        try {
            SessionEpoch::rotate($this->root);
            self::fail('A linked session generation was accepted.');
        } catch (HttpError $error) {
            self::assertSame(503, $error->status);
            self::assertSame('untouched', file_get_contents($this->root . '/outside'));
        }
    }

    public function testLinkedLockCannotBeOpenedOrTruncated(): void
    {
        mkdir($this->root . '/locks', 0700);
        file_put_contents($this->root . '/outside', 'untouched');
        symlink($this->root . '/outside', $this->root . '/locks/v3-session-epoch.lock');
        try {
            SessionEpoch::rotate($this->root);
            self::fail('A linked lock was accepted.');
        } catch (HttpError $error) {
            self::assertSame(503, $error->status);
            self::assertSame('untouched', file_get_contents($this->root . '/outside'));
        }
    }
}
