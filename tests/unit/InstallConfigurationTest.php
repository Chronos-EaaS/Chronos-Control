<?php
declare(strict_types=1);

namespace Chronos\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/libraries/install.php';

final class InstallConfigurationTest extends TestCase
{
    public static function credentials(): iterable
    {
        yield 'quoted Unicode credentials' => ['db.internal', 'chronos', "admin'Zürich", "secret'\\quoted!", 3307];
        yield 'username resembles another placeholder' => ['db.internal', 'chronos', '__DB_PASS__', 'synthetic-password', 3306];
        yield 'database resembles another placeholder' => ['db.internal', '__DB_USER__', 'chronos', 'synthetic-password', 3308];
    }

    #[DataProvider('credentials')]
    public function testConfigurationRoundTripsCredentialsWithoutReinterpretingTheirContents(string $host, string $database, string $username, string $password, int $port): void
    {
        $credentials = ['DB_HOST' => $host, 'DB_NAME' => $database, 'DB_USER' => $username, 'DB_PASS' => $password];
        $template = file_get_contents(CHRONOS_TEST_ROOT . '/config.template.php');
        $render = new \ReflectionMethod(\Install_Library::class, 'configuration');
        $configuration = $render->invoke(null, $template, $credentials, $port);

        // Compile in a fresh interpreter: configuration constants never enter the
        // domain-only test process, and no operational bootstrap is loaded.
        $process = proc_open([PHP_BINARY, '-n'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], '<?php define("SERVER_ROOT", "/synthetic-installation"); ?>' . $configuration
            . "\necho json_encode([DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_PORT], JSON_THROW_ON_ERROR);");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('', $errors);
        self::assertSame([$host, $database, $username, $password, $port], json_decode($output, true, 16, JSON_THROW_ON_ERROR));
    }

    public function testAnIncompletePortTemplateCannotSilentlyIgnoreTheRequestedPort(): void
    {
        $template = str_replace("define('DB_PORT', 3306);", '', file_get_contents(CHRONOS_TEST_ROOT . '/config.template.php'));
        $render = new \ReflectionMethod(\Install_Library::class, 'configuration');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The package configuration template is invalid.');
        $render->invoke(null, $template, ['DB_HOST' => 'db.internal', 'DB_NAME' => 'chronos', 'DB_USER' => 'chronos', 'DB_PASS' => 'synthetic-password'], 3307);
    }
}
