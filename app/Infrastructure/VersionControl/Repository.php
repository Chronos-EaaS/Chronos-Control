<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\VersionControl;

use Chronos\Http\HttpError;

/** Narrow, argv-based boundary for system repositories. Never discovers a parent. */
final class Repository
{
    private static function systemPath(string $path, string $root): string
    {
        $real = realpath($path);
        $base = realpath($root . '/webroot/systems');
        if ($real === false || $base === false || is_link($path) || dirname($real) !== $base
            || !preg_match('/^[1-9][0-9]*$/D', basename($real))) {
            throw new \RuntimeException('VCS operations require an isolated system directory.');
        }
        return $real;
    }

    public static function assertSystem(string $path, string $root): string
    {
        $real = self::systemPath($path, $root);
        // Linked/shared Git metadata could alter the application or another
        // checkout. Legacy configuration commits support an owned .git folder.
        if (!is_dir($real . '/.git') || is_link($real . '/.git') || is_file($real . '/.git/commondir')) {
            throw new \RuntimeException('The system does not have its own Git repository.');
        }
        [$code, $top] = self::run($real, ['rev-parse', '--show-toplevel']);
        if ($code !== 0 || realpath(trim($top)) !== $real) {
            throw new \RuntimeException('The system repository boundary could not be verified.');
        }
        return $real;
    }

    public static function initialize(string $path, string $root): void
    {
        $real = self::systemPath($path, $root);
        [$code] = self::run($real, ['init', '--quiet', '--initial-branch=master']);
        if ($code !== 0) {
            throw new HttpError(503, 'repository_unavailable', 'The local system repository could not be initialized.');
        }
        self::assertSystem($real, $root);
    }

    /** @return array{int,string} */
    public static function run(string $path, array $arguments): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        foreach (array_keys($environment) as $name) {
            if (str_starts_with($name, 'GIT_')) {
                unset($environment[$name]);
            }
        }
        $environment['GIT_TERMINAL_PROMPT'] = '0';
        $process = proc_open(
            ['git', '-C', $path, '-c', 'core.hooksPath=/dev/null', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes, $path, $environment, ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            return [127, 'Git is unavailable.'];
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        // Operational feedback must never echo credentials embedded in a remote URL.
        $output = (string) preg_replace('#(https?://)[^\s/@]+(?::[^\s/@]*)?@#i', '$1[redacted]@', $output);
        return [$code, $output];
    }
}
