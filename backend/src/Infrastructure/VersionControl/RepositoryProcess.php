<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\VersionControl;

use Chronos\Http\HttpError;

final class RepositoryProcess
{
    public static function run(string $path, string $type, array $arguments, array $environment = [], int $maxOutput = 1048576): array
    {
        if ($type === 'git' && $environment === [] && $maxOutput === 1048576) return Repository::run($path, $arguments);
        $inherited = getenv(); $inherited = is_array($inherited) ? $inherited : [];
        foreach (array_keys($inherited) as $key) if (str_starts_with($key, 'GIT_') || str_starts_with($key, 'HG')) unset($inherited[$key]);
        $inherited += ['GIT_TERMINAL_PROMPT' => '0', 'HGRCPATH' => '/dev/null', 'HGRCSKIPREPO' => '1', 'HGPLAIN' => '+strictflags', 'HGENCODING' => 'utf-8'];
        $command = $type === 'hg' ? ['hg', '--cwd', $path, '--noninteractive', ...$arguments] : ['git', '-C', $path, '-c', 'core.hooksPath=/dev/null', ...$arguments];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $path, array_replace($inherited, $environment), ['bypass_shell' => true]);
        if (!is_resource($process)) return [127, 'The repository command is unavailable.'];
        fclose($pipes[0]); stream_set_blocking($pipes[1], false);
        $output = ''; $started = microtime(true); $exit = -1;
        try {
            do {
                $output .= (string) stream_get_contents($pipes[1]);
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                if (strlen($output) > $maxOutput || microtime(true) - $started > 30) { proc_terminate($process, 9); $exit = 124; break; }
                usleep(10000);
            } while (true);
            $output .= (string) stream_get_contents($pipes[1]);
        } finally { fclose($pipes[1]); $closed = proc_close($process); }
        if (strlen($output) > $maxOutput) $exit = 124;
        if ($exit < 0) $exit = $closed;
        return [$exit, (string) preg_replace('#(https?://)[^\s/@]+(?::[^\s/@]*)?@#i', '$1[redacted]@', substr($output, 0, $maxOutput))];
    }

    public static function authenticated(string $path, string $type, array $arguments, array $system, ?string $credentialDirectory = null): array
    {
        $username = (string) ($system['vcsUser'] ?? ''); $password = (string) ($system['vcsPassword'] ?? '');
        if ($username === '' && $password === '') return self::run($path, $type, $arguments, ['GIT_TERMINAL_PROMPT' => '0']);
        if (preg_match('/[\r\n\x00]/', $username . $password . $system['vcsUrl'])) throw new HttpError(409, 'invalid_repository_credentials', 'Repository credentials must not contain line breaks.');
        $file = tempnam($credentialDirectory ?? $path . '/.' . $type, 'chronos-auth-');
        if (!$file) throw new HttpError(503, 'repository_unavailable', 'Temporary repository credentials could not be prepared.');
        try {
            if ($type === 'hg') {
                $contents = "[auth]\nchronos.prefix = " . $system['vcsUrl'] . "\nchronos.schemes = http https\nchronos.username = " . $username . "\nchronos.password = " . $password . "\n";
                if (file_put_contents($file, $contents) !== strlen($contents)) throw new HttpError(503, 'repository_unavailable', 'Temporary credentials could not be prepared.');
                return self::run($path, 'hg', $arguments, ['HGRCPATH' => $file]);
            }
            $contents = "#!/usr/bin/env php\n<?php\necho stripos(\$argv[1] ?? '', 'username') !== false ? getenv('CHRONOS_VCS_USERNAME') : getenv('CHRONOS_VCS_PASSWORD');\n";
            if (file_put_contents($file, $contents) !== strlen($contents) || !chmod($file, 0700)) throw new HttpError(503, 'repository_unavailable', 'Temporary credentials could not be prepared.');
            return self::run($path, 'git', ['-c', 'credential.helper=', ...$arguments], ['GIT_ASKPASS' => $file, 'CHRONOS_VCS_USERNAME' => $username, 'CHRONOS_VCS_PASSWORD' => $password]);
        } finally { unlink($file); }
    }
}
