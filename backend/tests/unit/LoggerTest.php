<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Logger_Library;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

// Class definition only; each test supplies its own temporary output directory.
require_once CHRONOS_TEST_ROOT . '/libraries/logger.php';

final class LoggerTest extends TestCase
{
    private string $directory;
    private string $filename;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/chronos-logger-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->filename = $this->directory . '/fixture.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->filename)) {
            unlink($this->filename);
        }
        rmdir($this->directory);
    }

    private function logger(array $options = []): Logger_Library
    {
        return new Logger_Library($this->directory, $options + [
            'filename' => 'fixture.log',
            'dateFormat' => 'd.m.Y H:i:s.u',
            'flushFrequency' => 1,
        ]);
    }

    public static function levels(): array
    {
        return array_map(static fn(string $level): array => [$level], [
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR,
            LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG,
        ]);
    }

    #[DataProvider('levels')]
    public function testEveryPsrLevelWritesThroughTheNamespacedInterface(string $level): void
    {
        $logger = $this->logger();
        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::assertInstanceOf(AbstractLogger::class, $logger);
        self::assertNull($logger->{$level}('Zürich & XML <value>'));
        self::assertSame(1, count(file($this->filename)));
        self::assertStringContainsString('[' . $level . '] Zürich & XML <value>', (string) file_get_contents($this->filename));
    }

    public function testStringableMessagesWorkWithInheritedAndArbitraryLevelMethods(): void
    {
        $message = new class implements Stringable {
            public function __toString(): string { return 'Synthetic Stringable message'; }
        };
        $logger = $this->logger();
        $logger->notice($message);
        self::assertNull($logger->log(LogLevel::INFO, $message));
        self::assertSame(2, count(file($this->filename)));
        self::assertSame(2, substr_count((string) file_get_contents($this->filename), 'Synthetic Stringable message'));
    }

    public function testThresholdFiltersLessSevereMessagesWithoutFormattingThem(): void
    {
        $logger = $this->logger();
        $logger->setLogLevelThreshold(LogLevel::WARNING);
        $unread = new class implements Stringable {
            public function __toString(): string { throw new \LogicException('Filtered messages must not be evaluated.'); }
        };
        $logger->debug($unread);
        $logger->warning('Visible warning');
        $logger->error('Visible error');
        self::assertSame(2, count(file($this->filename)));
        self::assertStringNotContainsString('debug', (string) file_get_contents($this->filename));
    }

    public static function invalidLevels(): array
    {
        return [['invalid'], ['WARNING'], [null], [false], [4], [[]]];
    }

    #[DataProvider('invalidLevels')]
    public function testUnknownLevelsProduceThePsrExceptionWithoutWriting(mixed $level): void
    {
        $logger = $this->logger();
        try {
            $logger->log($level, 'Must not be written');
            self::fail('The invalid log level was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, count(file($this->filename)));
            self::assertSame('', file_get_contents($this->filename));
        }
    }

    public function testInvalidThresholdDoesNotBreakThePreviouslyConfiguredThreshold(): void
    {
        $logger = $this->logger();
        $logger->setLogLevelThreshold(LogLevel::ERROR);
        try {
            $logger->setLogLevelThreshold('unsupported');
            self::fail('The invalid threshold was accepted.');
        } catch (InvalidArgumentException) {
            $logger->warning('Still filtered');
            $logger->error('Still visible');
        }
        self::assertSame(1, count(file($this->filename)));
        self::assertStringContainsString('Still visible', (string) file_get_contents($this->filename));
    }

    public function testLegacyCustomFormatContextAndAccessorsRemainUsable(): void
    {
        $logger = $this->logger(['logFormat' => '{level}|{priority}|{message}|{context}', 'appendContext' => false]);
        $logger->info(new class implements Stringable {
            public function __toString(): string { return 'Formatted message'; }
        }, ['zero' => 0, 'enabled' => false, 'details' => ['value']]);
        self::assertSame('INFO|6|Formatted message|{"zero":0,"enabled":false,"details":["value"]}', $logger->getLastLogLine());
        self::assertSame($this->filename, $logger->getLogFilePath());
        self::assertSame(1, count(file($this->filename)));
    }
}
