<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\VersionControl;

use Chronos\Http\HttpError;

final class RepositoryInspector
{
    public function __construct(private readonly string $root) {}

    public function directory(int $id): string
    {
        $base = realpath($this->root . '/webroot/systems');
        $path = $this->root . '/webroot/systems/' . $id;
        $real = realpath($path);
        if ($base === false || $real === false || is_link($path) || dirname($real) !== $base) {
            throw new HttpError(409, 'system_files_unavailable', 'The isolated system directory is unavailable.');
        }
        return $real;
    }

    public function adapter(string $path): string
    {
        if (is_dir($path . '/.git') && !file_exists($path . '/.hg')) {
            try { Repository::assertSystem($path, $this->root); }
            catch (\RuntimeException) { throw new HttpError(409, 'repository_unavailable', 'The system does not have an isolated repository.'); }
            return 'git';
        }
        if (is_dir($path . '/.hg') && !is_link($path . '/.hg') && !file_exists($path . '/.git') && !file_exists($path . '/.hg/sharedpath')) {
            [$code, $top] = RepositoryProcess::run($path, 'hg', ['root']);
            if ($code === 0 && realpath(trim($top)) === $path) return 'hg';
        }
        throw new HttpError(409, 'repository_unavailable', 'The system does not have an isolated Git or Mercurial repository.');
    }

    public function assertIncomingTree(string $path, string $type, string $revision): void
    {
        [$code, $tree] = RepositoryProcess::run($path, $type, $type === 'git'
            ? ['ls-tree', '--full-tree', '-r', '-z', $revision]
            : ['--verbose', 'manifest', '--rev', $revision, '--template', '{path}\\0{type}\\0'], ['GIT_TERMINAL_PROMPT' => '0'], 16777216);
        if ($code !== 0) throw new HttpError(409, 'repository_tree_unavailable', 'The incoming repository tree could not be verified within the configured resource limit. No incoming files were applied.');
        $entries = explode("\0", $tree);
        if (array_pop($entries) !== '') throw new HttpError(409, 'repository_tree_unavailable', 'The incoming repository tree could not be verified. No incoming files were applied.');
        if ($type === 'git') {
            foreach ($entries as $entry) {
                if (!preg_match('/^([0-9]{6}) (?:blob|commit) [a-f0-9]+\t(.*)$/sD', $entry, $match)) throw new HttpError(409, 'repository_tree_unavailable', 'The incoming Git tree could not be verified. No incoming files were applied.');
                $this->assertIncomingFile($match[2], $match[1] === '120000');
            }
        } else {
            if (count($entries) % 2 !== 0) throw new HttpError(409, 'repository_tree_unavailable', 'The incoming Mercurial tree could not be verified. No incoming files were applied.');
            for ($index = 0; $index < count($entries); $index += 2) $this->assertIncomingFile($entries[$index], $entries[$index + 1] === '@');
        }
    }

    private function assertIncomingFile(string $path, bool $symlink): void
    {
        if ($symlink || in_array(strtolower(explode('/', $path, 2)[0]), ['.git', '.hg'], true) || in_array(strtolower(basename($path)), ['.htaccess', '.user.ini'], true)) throw new HttpError(422, 'unsafe_repository_file', 'The incoming repository contains a symbolic link, reserved VCS metadata or web-server override file. No incoming files were applied.');
    }
}
