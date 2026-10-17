<?php
declare(strict_types=1);

/** Installs immutable CI packages; mutable application data never enters a package. */
final class Release_Library
{
    private const REPOSITORY = 'Chronos-EaaS/Chronos-Control';
    private const MAX_ARCHIVE_BYTES = 268435456;
    private const MANIFEST = '.chronos-release.json';

    public static function inspect(string $root): array
    {
        $state = self::state($root);
        $branch = $state['branch'] ?? 'master';
        if (!isset($state['branch']) && class_exists('Settings_Library', false)) {
            $setting = Settings_Library::getInstance(0)->get('vcs', 'repoBranch');
            if ($setting !== null && is_string($setting->getValue()) && $setting->getValue() !== '') $branch = $setting->getValue();
        }
        $enabled = !defined('CHRONOS_ENABLE_UPDATES') || CHRONOS_ENABLE_UPDATES;
        return [
            'branch' => $branch, 'commit' => $state['commit'] ?? null, 'uiBuildId' => $state['uiBuildId'] ?? null,
            'branches' => array_values(array_unique([$branch, 'master'])),
            'rollbackAvailable' => isset($state['deployment']) && self::rollbackJournal($root, $state['deployment']) !== null,
            'capabilities' => ['update' => $enabled && is_writable($root) && extension_loaded('zip') && extension_loaded('curl'), 'rollback' => $enabled && isset($state['deployment']) && self::rollbackJournal($root, $state['deployment']) !== null],
        ];
    }

    public static function plan(string $root, string $action, array $params): array
    {
        if (defined('CHRONOS_ENABLE_UPDATES') && !CHRONOS_ENABLE_UPDATES) throw new RuntimeException('Application updates are disabled in this installation.');
        if ($action === 'rollback') {
            $state = self::state($root);
            $id = $state['deployment'] ?? '';
            $journal = self::rollbackJournal($root, $id);
            if ($journal === null) throw new RuntimeException('No verified rollback is available.');
            return ['action' => 'rollback', 'deployment' => $id, 'currentCommit' => $state['commit'] ?? null, 'targetCommit' => $journal['previous']['commit'] ?? null];
        }
        if ($action !== 'update') throw new InvalidArgumentException('Unsupported release operation.');
        $branch = $params['branch'] ?? 'master';
        if (!is_string($branch) || $branch === '' || strlen($branch) > 250 || preg_match('/[\x00-\x20\x7f]/', $branch)) throw new InvalidArgumentException('Choose a valid repository branch.');
        $commit = self::remoteJson('https://api.github.com/repos/' . self::REPOSITORY . '/commits/' . rawurlencode($branch));
        $sha = $commit['sha'] ?? '';
        if (!is_string($sha) || !preg_match('/^[a-f0-9]{40}$/D', $sha)) throw new RuntimeException('The branch commit could not be verified.');
        $release = self::remoteJson('https://api.github.com/repos/' . self::REPOSITORY . '/releases/tags/chronos-build-' . $sha);
        $manifestUrl = self::asset($release, 'chronos-control-manifest.json');
        $manifest = self::remoteJson($manifestUrl);
        self::validateManifest($manifest);
        if ($manifest['commit'] !== $sha) throw new RuntimeException('The package does not match the selected branch commit.');
        return ['action' => 'update', 'branch' => $branch, 'commit' => $sha, 'manifest' => $manifest,
            'archiveUrl' => self::asset($release, 'chronos-control.zip'), 'currentCommit' => self::state($root)['commit'] ?? null];
    }

    public static function execute(string $root, array $plan): array
    {
        if (defined('CHRONOS_ENABLE_UPDATES') && !CHRONOS_ENABLE_UPDATES) throw new RuntimeException('Application updates are disabled in this installation.');
        if (function_exists('set_time_limit')) set_time_limit(300);
        ignore_user_abort(true);
        if (($plan['action'] ?? null) === 'rollback') return self::rollback($root, $plan['deployment'] ?? '', $plan['currentCommit'] ?? null);
        if (($plan['action'] ?? null) !== 'update') throw new InvalidArgumentException('Unsupported release operation.');
        $manifest = $plan['manifest'] ?? [];
        self::validateManifest($manifest);
        if (($plan['commit'] ?? null) !== $manifest['commit']) throw new RuntimeException('The approved package changed. Prepare a new update plan.');
        $directory = self::privateDirectory($root);
        $archive = tempnam($directory, 'download-');
        if ($archive === false) throw new RuntimeException('The package download cannot be staged.');
        try {
            self::download($plan['archiveUrl'] ?? '', $archive, self::MAX_ARCHIVE_BYTES);
            return self::installArchive($root, $archive, $manifest, $plan['branch'] ?? 'master', $plan['currentCommit'] ?? null);
        } finally { if (is_file($archive)) unlink($archive); }
    }

    /** Pure filesystem boundary, also used by the offline installer and disposable tests. */
    public static function installArchive(string $root, string $archive, array $manifest, string $branch = 'master', ?string $expectedCommit = null, ?callable $beforeWrite = null): array
    {
        self::validateManifest($manifest);
        $root = self::root($root);
        if (filesize($archive) !== $manifest['archive']['bytes'] || !hash_equals($manifest['archive']['sha256'], hash_file('sha256', $archive))) throw new RuntimeException('The downloaded archive failed its integrity check.');
        $directory = self::privateDirectory($root);
        $lock = fopen($directory . '/deployment.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another deployment is already running.');
        $id = bin2hex(random_bytes(16));
        $work = $directory . '/' . $id;
        $maintenance = $root . '/.chronos-maintenance.json';
        $modified = false;
        try {
            $previous = self::state($root);
            if (($previous['commit'] ?? null) !== $expectedCommit) throw new RuntimeException('The installation changed since this operation was prepared.');
            if (!mkdir($work, 0700) || !mkdir($work . '/stage', 0700) || !mkdir($work . '/backup', 0700)) throw new RuntimeException('The update cannot be staged.');
            self::extract($archive, $work . '/stage', $manifest);
            $retainedUiFiles = array_filter($previous['files'] ?? [], static fn ($value, $path): bool => !isset($manifest['files'][$path]) && preg_match('#^webroot/ui/(?:chunk|main|polyfills|styles)-[A-Za-z0-9_-]{8,}\.(?:js|css)$#D', $path) === 1, ARRAY_FILTER_USE_BOTH);
            $affected = array_values(array_unique([...array_keys($manifest['files']), ...array_keys($previous['files'] ?? []), ...array_keys($previous['retainedUiFiles'] ?? []), ...($manifest['removedFiles'] ?? []), self::MANIFEST]));
            $backup = [];
            foreach ($affected as $path) {
                if ($path !== self::MANIFEST) self::path($path);
                self::safeParent($root, $path, false);
                $file = $root . '/' . $path;
                if (is_link($file) || (file_exists($file) && !is_file($file))) throw new RuntimeException('A package destination is not a regular file.');
                if (is_file($file)) {
                    self::parents($work . '/backup/' . $path);
                    if (!copy($file, $work . '/backup/' . $path)) throw new RuntimeException('The current installation could not be backed up.');
                    $backup[$path] = ['sha256' => hash_file('sha256', $file), 'mode' => fileperms($file) & 0777];
                } else $backup[$path] = null;
            }
            $journal = ['id' => $id, 'previous' => $previous, 'backup' => $backup, 'status' => 'prepared'];
            self::jsonFile($work . '/journal.json', $journal);
            self::jsonFile($maintenance, ['deployment' => $id]);
            $modified = true;
            self::maintenanceGate($root, $work);
            $ordered = array_values(array_diff($affected, ['webroot/index.php'])); $ordered[] = 'webroot/index.php';
            foreach ($ordered as $path) {
                if ($path === self::MANIFEST) continue;
                if ($beforeWrite !== null) $beforeWrite($path);
                self::safeParent($root, $path, true);
                if (isset($manifest['files'][$path])) self::replace($work . '/stage/' . $path, $root . '/' . $path, 0644);
                elseif (!isset($retainedUiFiles[$path]) && is_file($root . '/' . $path) && !unlink($root . '/' . $path)) throw new RuntimeException('A superseded package file could not be removed.');
                if (function_exists('opcache_invalidate')) opcache_invalidate($root . '/' . $path, true);
            }
            self::jsonFile($root . '/' . self::MANIFEST, array_replace($manifest, ['branch' => $branch, 'deployment' => $id, 'retainedUiFiles' => $retainedUiFiles]));
            $journal['status'] = 'applied'; self::jsonFile($work . '/journal.json', $journal);
            unlink($maintenance); $modified = false;
            return ['success' => true, 'commit' => $manifest['commit'], 'uiBuildId' => $manifest['uiBuildId'], 'changedFiles' => count($affected) - 1, 'rollbackAvailable' => true];
        } catch (Throwable $error) {
            if ($modified && isset($journal)) {
                try { self::restore($root, $work, $journal); $journal['status'] = 'recovered'; self::jsonFile($work . '/journal.json', $journal); unlink($maintenance); }
                catch (Throwable) { throw new RuntimeException('Deployment failed and automatic recovery could not finish. Maintenance remains active; restore the private deployment backup before serving requests.', 0, $error); }
            }
            throw $error;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function rollback(string $root, string $id, ?string $expectedCommit): array
    {
        $root = self::root($root); $directory = self::privateDirectory($root);
        $lock = fopen($directory . '/deployment.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another deployment is already running.');
        try {
            $state = self::state($root);
            if (($state['commit'] ?? null) !== $expectedCommit || ($state['deployment'] ?? null) !== $id) throw new RuntimeException('The installation changed. Prepare a new rollback plan.');
            $journal = self::rollbackJournal($root, $id);
            if ($journal === null) throw new RuntimeException('The rollback backup is unavailable.');
            self::verifyBackup($directory . '/' . $id, $journal);
            self::jsonFile($root . '/.chronos-maintenance.json', ['deployment' => $id, 'action' => 'rollback']);
            self::maintenanceGate($root, $directory . '/' . $id);
            self::restore($root, $directory . '/' . $id, $journal);
            $journal['status'] = 'rolled_back'; self::jsonFile($directory . '/' . $id . '/journal.json', $journal);
            unlink($root . '/.chronos-maintenance.json');
            return ['success' => true, 'commit' => $journal['previous']['commit'] ?? null, 'rollbackAvailable' => false];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function validateManifest(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== 1 || !is_string($manifest['commit'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $manifest['commit'])
            || ($manifest['uiBuildId'] ?? null) !== $manifest['commit'] || !is_array($manifest['files'] ?? null) || count($manifest['files']) > 50000 || !$manifest['files']) throw new RuntimeException('The package manifest is invalid.');
        if (!is_array($manifest['archive'] ?? null) || !is_int($manifest['archive']['bytes'] ?? null) || $manifest['archive']['bytes'] < 1 || $manifest['archive']['bytes'] > self::MAX_ARCHIVE_BYTES || !preg_match('/^[a-f0-9]{64}$/D', $manifest['archive']['sha256'] ?? '')) throw new RuntimeException('The archive manifest is invalid.');
        if (isset($manifest['phpMinimum']) && (!is_string($manifest['phpMinimum']) || version_compare(PHP_VERSION, $manifest['phpMinimum'], '<'))) throw new RuntimeException('This package requires a newer PHP runtime.');
        $total = 0;
        foreach ($manifest['files'] as $path => $file) {
            self::path($path);
            if (!is_array($file) || !is_int($file['bytes'] ?? null) || $file['bytes'] < 0 || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'] ?? '')) throw new RuntimeException('A package file description is invalid.');
            $total += $file['bytes'];
            if ($total > 536870912) throw new RuntimeException('The expanded package is too large.');
        }
        foreach ($manifest['removedFiles'] ?? [] as $path) self::path($path);
        foreach (['webroot/ui/index.html', 'webroot/ui/build.json', 'webroot/index.php', 'constants.php', 'chronos.sql', 'config.template.php', 'vendor/autoload.php', 'vendor/composer/autoload_real.php', 'api/v3/bootstrap.php', 'core/browserui.php', 'libraries/release.php', 'libraries/install.php'] as $required) {
            if (!isset($manifest['files'][$required])) throw new RuntimeException('The package does not contain a complete Chronos runtime.');
        }
    }

    private static function extract(string $archive, string $stage, array $manifest): void
    {
        $zip = new ZipArchive(); if ($zip->open($archive) !== true) throw new RuntimeException('The package archive cannot be opened.');
        $seen = [];
        try {
            if ($zip->numFiles > 50001) throw new RuntimeException('The package contains too many entries.');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i); $stat = $zip->statIndex($i);
                if ($name === 'manifest.json') continue;
                self::path($name);
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000 || isset($seen[$name]) || !isset($manifest['files'][$name]) || $stat['size'] !== $manifest['files'][$name]['bytes']) throw new RuntimeException('The archive contains an unexpected entry.');
                $stream = $zip->getStream($name); if ($stream === false) throw new RuntimeException('A package file cannot be read.');
                self::parents($stage . '/' . $name); $target = fopen($stage . '/' . $name, 'xb');
                if ($target === false) { fclose($stream); throw new RuntimeException('A package file cannot be staged.'); }
                try { $bytes = stream_copy_to_stream($stream, $target, $manifest['files'][$name]['bytes'] + 1); } finally { fclose($stream); fclose($target); }
                if ($bytes !== $manifest['files'][$name]['bytes'] || !hash_equals($manifest['files'][$name]['sha256'], hash_file('sha256', $stage . '/' . $name))) throw new RuntimeException('A package file failed its integrity check.');
                $seen[$name] = true;
            }
            if (count($seen) !== count($manifest['files'])) throw new RuntimeException('The archive is missing package files.');
            $build = json_decode((string) file_get_contents($stage . '/webroot/ui/build.json'), true);
            if (($build['id'] ?? null) !== $manifest['uiBuildId']) throw new RuntimeException('The compiled UI has another build identifier.');
        } finally { $zip->close(); }
    }

    private static function path(mixed $path): void
    {
        if (!is_string($path) || $path === '' || strlen($path) > 500 || preg_match('/[\x00-\x1f\x7f\\\\:]/', $path) || str_starts_with($path, '/') || str_ends_with($path, '/')) throw new RuntimeException('The package contains an unsafe path.');
        foreach (explode('/', $path) as $part) if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.git') || $part === '.hg') throw new RuntimeException('The package contains an unsafe path.');
        if (!preg_match('#^(?:api/|controllers/|core/|libraries/|templates/|views/|vendor/|webroot/|constants\.php$|chronos\.sql$|config\.template\.php$|\.htaccess$|install\.php$)#D', $path)
            || preg_match('#^(?:webroot/(?:systems|data|media)(?:/|$)|config\.php$)#D', $path)) throw new RuntimeException('A package attempts to modify persistent or unsupported paths.');
    }

    private static function root(string $root): string
    {
        $resolved = realpath($root);
        if ($resolved === false || $resolved === '/' || !is_dir($resolved)) throw new RuntimeException('The installation directory is unavailable.');
        return $resolved;
    }

    private static function privateDirectory(string $root): string
    {
        $root = self::root($root); $path = $root . '/.chronos-deployments';
        if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('The private deployment directory is unsafe.');
        if (!is_dir($path) && !mkdir($path, 0700)) throw new RuntimeException('The private deployment directory cannot be created.');
        chmod($path, 0700); return $path;
    }

    private static function state(string $root): array
    {
        $file = $root . '/' . self::MANIFEST;
        if (is_link($file)) throw new RuntimeException('The release state is unsafe.');
        if (!is_file($file)) return [];
        $state = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new RuntimeException('The release state is invalid.');
        return $state;
    }

    private static function rollbackJournal(string $root, mixed $id): ?array
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
        $base = $root . '/.chronos-deployments'; $work = $base . '/' . $id;
        if (is_link($base) || is_link($work) || is_link($work . '/journal.json') || !is_file($work . '/journal.json')) return null;
        $journal = json_decode((string) file_get_contents($work . '/journal.json'), true);
        return is_array($journal) && ($journal['status'] ?? null) === 'applied' && ($journal['id'] ?? null) === $id ? $journal : null;
    }

    private static function parents(string $file): void
    {
        $parent = dirname($file);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) throw new RuntimeException('A package directory cannot be created.');
    }

    private static function safeParent(string $root, string $path, bool $create): void
    {
        $parts = explode('/', $path); array_pop($parts); $parent = $root;
        foreach ($parts as $part) {
            $parent .= '/' . $part;
            if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) throw new RuntimeException('A package path passes through an unsafe directory.');
            if ($create && !is_dir($parent) && !mkdir($parent, 0755)) throw new RuntimeException('A package directory cannot be created.');
        }
    }

    private static function replace(string $source, string $target, int $mode): void
    {
        $temporary = $target . '.chronos-' . bin2hex(random_bytes(8));
        try {
            if (!copy($source, $temporary) || !chmod($temporary, $mode) || !rename($temporary, $target)) throw new RuntimeException('A package file could not be installed.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    private static function maintenanceGate(string $root, string $work): void
    {
        $source = $work . '/maintenance.php';
        $php = '<?php http_response_code(503); header("Retry-After: 30"); header("Cache-Control: no-store"); header("Content-Type: application/json; charset=utf-8"); echo \'{"error":{"code":"maintenance","message":"Chronos is being updated. Please retry shortly."}}\'; exit;';
        if (file_put_contents($source, $php, LOCK_EX) === false) throw new RuntimeException('The maintenance gate could not be staged.');
        self::safeParent($root, 'webroot/index.php', true);
        self::replace($source, $root . '/webroot/index.php', 0644);
        if (function_exists('opcache_invalidate')) opcache_invalidate($root . '/webroot/index.php', true);
    }

    private static function jsonFile(string $path, array $value): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($temporary, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $path)) throw new RuntimeException('Deployment state could not be saved.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    private static function verifyBackup(string $work, array $journal): void
    {
        if (!is_array($journal['backup'] ?? null)) throw new RuntimeException('The rollback journal is invalid.');
        $backup = $journal['backup'];
        if (array_key_exists('webroot/index.php', $backup)) { $index = $backup['webroot/index.php']; unset($backup['webroot/index.php']); $backup['webroot/index.php'] = $index; }
        foreach ($backup as $path => $entry) {
            if ($path !== self::MANIFEST) self::path($path);
            self::safeParent($work . '/backup', $path, false);
            if ($entry !== null && (!is_file($work . '/backup/' . $path) || is_link($work . '/backup/' . $path) || !hash_equals($entry['sha256'], hash_file('sha256', $work . '/backup/' . $path)))) throw new RuntimeException('The rollback backup failed its integrity check.');
        }
    }

    private static function restore(string $root, string $work, array $journal): void
    {
        self::verifyBackup($work, $journal);
        $backup = $journal['backup'];
        if (array_key_exists('webroot/index.php', $backup)) { $index = $backup['webroot/index.php']; unset($backup['webroot/index.php']); $backup['webroot/index.php'] = $index; }
        foreach ($backup as $path => $entry) {
            self::safeParent($root, $path, true);
            if (is_link($root . '/' . $path)) throw new RuntimeException('A rollback destination is unsafe.');
            if ($entry === null) {
                if (is_file($root . '/' . $path) && !unlink($root . '/' . $path)) throw new RuntimeException('A new package file could not be removed during recovery.');
            } else self::replace($work . '/backup/' . $path, $root . '/' . $path, $entry['mode']);
            if (function_exists('opcache_invalidate')) opcache_invalidate($root . '/' . $path, true);
        }
    }

    private static function asset(array $release, string $name): string
    {
        foreach ($release['assets'] ?? [] as $asset) if (($asset['name'] ?? null) === $name && is_string($asset['browser_download_url'] ?? null)) return $asset['browser_download_url'];
        throw new RuntimeException('A completed CI package is not available for this branch commit. Build and publish the branch package before updating.');
    }

    private static function remoteJson(string $url): array
    {
        $file = tempnam(sys_get_temp_dir(), 'chronos-release-');
        if ($file === false) throw new RuntimeException('The release metadata cannot be staged.');
        try {
            self::download($url, $file, 8388608);
            $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('The release metadata is invalid.');
            return $data;
        } finally { if (is_file($file)) unlink($file); }
    }

    private static function download(string $url, string $destination, int $limit): void
    {
        // Follow redirects manually: never allow a release URL to reach a local
        // service, use another protocol, or leave GitHub's download hosts.
        for ($redirect = 0; $redirect < 6; $redirect++) {
            $parts = parse_url($url); $host = strtolower($parts['host'] ?? '');
            $allowed = ['api.github.com', 'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'];
            if (($parts['scheme'] ?? null) !== 'https' || !in_array($host, $allowed, true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) throw new RuntimeException('The package download address is not trusted.');
            if ($host === 'github.com' && !str_starts_with($parts['path'] ?? '', '/' . self::REPOSITORY . '/releases/download/')) throw new RuntimeException('The package is outside the Chronos repository.');
            if ($host === 'api.github.com' && !str_starts_with($parts['path'] ?? '', '/repos/' . self::REPOSITORY . '/')) throw new RuntimeException('The release metadata is outside the Chronos repository.');
            $stream = fopen($destination, 'wb'); if ($stream === false) throw new RuntimeException('The download cannot be written.');
            $curl = curl_init($url); $bytes = 0; $location = null;
            curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 180, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS_STR => 'https',
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: Chronos-Control-v3'],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int { if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9)); return strlen($header); },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($stream, &$bytes, $limit): int { $bytes += strlen($data); return $bytes <= $limit ? (int) fwrite($stream, $data) : 0; },
            ]);
            try { $success = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); }
            finally { unset($curl); fclose($stream); }
            if ($success === false) throw new RuntimeException('The CI package could not be downloaded. Check connectivity and the available disk space.');
            if ($status >= 300 && $status < 400 && $location !== null) { $url = $location; continue; }
            if ($status !== 200) throw new RuntimeException('GitHub did not provide a completed CI package for the requested branch.');
            return;
        }
        throw new RuntimeException('The package download redirected too many times.');
    }
}
