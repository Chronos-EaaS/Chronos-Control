<?php
declare(strict_types=1);
require_once __DIR__ . '/../../libraries/release.php';

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $operation): void { try { $operation(); } catch (Throwable) { return; } throw new RuntimeException('An unsafe deployment unexpectedly succeeded.'); }
function withoutExtensions(string $script, array $arguments): string {
    $process = proc_open([PHP_BINARY, '-n', '-r', $script, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Cannot start the isolated minimal PHP runtime.');
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && $errors === '', 'Minimal-runtime release check failed: ' . $errors . $output); return $output;
}
function cleanup(string $path): void { if (is_link($path) || is_file($path)) { unlink($path); return; } foreach (new FilesystemIterator($path) as $entry) cleanup($entry->getPathname()); rmdir($path); }
function package(string $directory, array $overrides = [], array $extras = [], array $removed = [], string $sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array {
    $files = array_replace(['webroot/index.php' => '<?php echo "new";', 'webroot/ui/index.html' => '<app-root></app-root>', 'webroot/ui/build.json' => json_encode(['id' => $sha]), 'webroot/ui/new.js' => 'console.log("new");'], $overrides);
    foreach (['constants.php','chronos.sql','config.template.php','vendor/autoload.php','vendor/composer/autoload_real.php','api/v3/bootstrap.php','core/browserui.php','libraries/release.php','libraries/install.php'] as $required) $files[$required] ??= 'fixture runtime';
    $zip = new ZipArchive(); $path = $directory . '/' . bin2hex(random_bytes(5)) . '.zip'; check($zip->open($path, ZipArchive::CREATE) === true, 'Cannot build a synthetic package.');
    $manifest = ['format' => 1, 'commit' => $sha, 'uiBuildId' => $sha, 'files' => [], 'removedFiles' => $removed];
    foreach ($files as $name => $value) { $zip->addFromString($name, $value); $manifest['files'][$name] = ['bytes' => strlen($value), 'sha256' => hash('sha256', $value)]; }
    foreach ($extras as $name => $value) $zip->addFromString($name, $value);
    $zip->close(); $manifest['archive'] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    return [$path, $manifest];
}
$base = sys_get_temp_dir() . '/chronos-release-test-' . bin2hex(random_bytes(8)); mkdir($base, 0700);
try {
    $root = $base . '/application'; mkdir($root); mkdir($root . '/webroot'); mkdir($root . '/webroot/systems'); mkdir($root . '/webroot/systems/1'); mkdir($root . '/webroot/data'); mkdir($root . '/.git');
    file_put_contents($root . '/webroot/index.php', '<?php echo "old";'); file_put_contents($root . '/config.php', 'private config'); file_put_contents($root . '/webroot/systems/1/parameters.json', 'private schema'); file_put_contents($root . '/webroot/data/result.zip', 'private result'); file_put_contents($root . '/.git/HEAD', 'private git state');
    [$archive, $manifest] = package($base);
    $preflightRoot = $base . '/preflight'; mkdir($preflightRoot); file_put_contents($preflightRoot . '/sentinel', 'unchanged');
    foreach (['pdo_mysql', 'mbstring', 'dom', 'curl', 'zip', 'gd'] as $missingExtension) {
        $preflightWrites = 0;
        try {
            Release_Library::installArchive($preflightRoot, $archive, $manifest, 'master', null, static function () use (&$preflightWrites): void { ++$preflightWrites; }, static fn(string $extension): bool => $extension !== $missingExtension);
            throw new RuntimeException('Missing extension accepted.');
        } catch (RuntimeException $error) { check($error->getMessage() === 'The PHP ' . $missingExtension . ' extension is required.', 'Missing runtime extension must have an actionable rejection.'); }
        check($preflightWrites === 0 && scandir($preflightRoot) === ['.', '..', 'sentinel'] && file_get_contents($preflightRoot . '/sentinel') === 'unchanged', 'Runtime rejection must create no staging, maintenance, release state or backups.');
    }
    $preflightManifest = $base . '/preflight-manifest.json'; file_put_contents($preflightManifest, json_encode($manifest, JSON_THROW_ON_ERROR));
    // Some PHP distributions compile every extension in; official CI runtimes
    // have shared modules and exercise the native rejection without an adapter.
    $nativeMissing = json_decode(withoutExtensions('echo json_encode(array_values(array_filter(["pdo_mysql","mbstring","dom","curl","zip","gd"],fn($name)=>!extension_loaded($name))));', []), true, 8, JSON_THROW_ON_ERROR);
    if ($nativeMissing !== []) {
        $preflight = 'require $argv[1];try{Release_Library::installArchive($argv[2],$argv[3],json_decode(file_get_contents($argv[4]),true,64,JSON_THROW_ON_ERROR));throw new RuntimeException("Missing extension accepted");}catch(RuntimeException $error){if($error->getMessage()!=="The PHP ".$argv[5]." extension is required.")throw $error;echo "rejected-before-writes";}';
        check(withoutExtensions($preflight, [dirname(__DIR__, 2) . '/libraries/release.php', $preflightRoot, $archive, $preflightManifest, $nativeMissing[0]]) === 'rejected-before-writes', 'Native missing runtime extensions must reject installation.');
    }
    check(scandir($preflightRoot) === ['.', '..', 'sentinel'] && file_get_contents($preflightRoot . '/sentinel') === 'unchanged', 'Runtime rejection must create no staging, maintenance, release state or backups.');
    $result = Release_Library::installArchive($root, $archive, $manifest, 'fixture-branch');
    check($result['success'] === true, 'Installation must succeed.'); check(file_get_contents($root . '/webroot/index.php') === '<?php echo "new";', 'New PHP was not activated.');
    check(file_get_contents($root . '/config.php') === 'private config' && file_get_contents($root . '/webroot/systems/1/parameters.json') === 'private schema' && file_get_contents($root . '/webroot/data/result.zip') === 'private result' && file_get_contents($root . '/.git/HEAD') === 'private git state', 'Deployment changed persistent data.');
    check(!file_exists($root . '/.chronos-maintenance.json'), 'A successful deployment must clear maintenance.');
    $state = json_decode(file_get_contents($root . '/.chronos-release.json'), true); check($state['branch'] === 'fixture-branch', 'Selected update branch was not retained.');
    rejects(fn() => Release_Library::installArchive($root, $archive, $manifest));
    $rollback = 'require $argv[1];echo json_encode(Release_Library::rollback($argv[2],$argv[3],$argv[4]),JSON_THROW_ON_ERROR);';
    $rollbackResult = json_decode(withoutExtensions($rollback, [dirname(__DIR__, 2) . '/libraries/release.php', $root, $state['deployment'], $manifest['commit']]), true, 16, JSON_THROW_ON_ERROR);
    check($rollbackResult['success'] === true && !is_file($root . '/.chronos-maintenance.json'), 'Recovery must remain available without update-only runtime prerequisites.');
    check(file_get_contents($root . '/webroot/index.php') === '<?php echo "old";' && !is_file($root . '/webroot/ui/new.js') && !is_file($root . '/.chronos-release.json'), 'Rollback must restore the previous complete file set.');
    foreach ([['../escape.php' => 'unsafe'], ['webroot/../../escape.php' => 'unsafe'], ['config.php' => 'override'], ['webroot/systems/1/parameters.json' => 'override'], ['.git/HEAD' => 'override'], ['webroot/ui/control\nfile.js' => 'unsafe']] as $invalid) {
        [$badArchive, $badManifest] = package($base, $invalid); rejects(fn() => Release_Library::installArchive($root, $badArchive, $badManifest));
    }
    [$extraArchive, $extraManifest] = package($base, [], ['webroot/ui/unlisted.js' => 'unlisted']); rejects(fn() => Release_Library::installArchive($root, $extraArchive, $extraManifest));
    [$buildArchive, $buildManifest] = package($base, ['webroot/ui/build.json' => '{"id":"different-build"}']); rejects(fn() => Release_Library::installArchive($root, $buildArchive, $buildManifest));
    [$removeArchive, $removeManifest] = package($base, [], [], ['webroot/data/result.zip']); rejects(fn() => Release_Library::installArchive($root, $removeArchive, $removeManifest));
    $tampered = $manifest; $tampered['archive']['sha256'] = str_repeat('0', 64); rejects(fn() => Release_Library::installArchive($root, $archive, $tampered));
    file_put_contents($root . '/templates', 'blocking parent'); [$parentArchive, $parentManifest] = package($base, ['templates/new.php' => 'blocked']); rejects(fn() => Release_Library::installArchive($root, $parentArchive, $parentManifest)); unlink($root . '/templates');
    mkdir($base . '/outside'); symlink($base . '/outside', $root . '/templates'); rejects(fn() => Release_Library::installArchive($root, $parentArchive, $parentManifest)); unlink($root . '/templates');
    $oldAsset = 'webroot/assets/datatables/extensions/TableTools/images/psd/copy document.psd'; mkdir(dirname($root . '/' . $oldAsset), 0755, true); file_put_contents($root . '/' . $oldAsset, 'legacy editor source');
    [$cleanupArchive, $cleanupManifest] = package($base, [], [], [$oldAsset]); Release_Library::installArchive($root, $cleanupArchive, $cleanupManifest);
    check(!is_file($root . '/' . $oldAsset), 'Legacy runtime paths containing ordinary spaces must be removable.'); $cleanupState = json_decode(file_get_contents($root . '/.chronos-release.json'), true); Release_Library::rollback($root, $cleanupState['deployment'], $cleanupManifest['commit']); check(file_get_contents($root . '/' . $oldAsset) === 'legacy editor source', 'Rollback must restore a removed legacy path containing spaces.');
    $missing = $manifest; unset($missing['files']['api/v3/bootstrap.php']); rejects(fn() => Release_Library::installArchive($root, $archive, $missing));
    // Versioned application layouts remain compatible with older v3 packages,
    // while a new-layout package must include its executable application layer.
    $unknownLayout = $manifest; $unknownLayout['runtimeLayout'] = 'unknown-future-layout';
    rejects(fn() => Release_Library::installArchive($root, $archive, $unknownLayout));
    $missingApplication = $manifest; $missingApplication['runtimeLayout'] = 'application-v1';
    rejects(fn() => Release_Library::installArchive($root, $archive, $missingApplication));
    $applicationFiles = [
        'app/Http/Api/V3/Kernel.php' => '<?php /* isolated controller fixture */',
        'app/Application/Shared/Context.php' => '<?php /* isolated context fixture */',
        'app/Infrastructure/Persistence/Store.php' => '<?php /* isolated persistence fixture */',
    ];
    [$applicationArchive, $applicationManifest] = package($base, $applicationFiles);
    $applicationManifest['runtimeLayout'] = 'application-v1';
    foreach (array_keys($applicationFiles) as $requiredApplication) {
        $incompleteApplication = $applicationManifest;
        unset($incompleteApplication['files'][$requiredApplication]);
        rejects(fn() => Release_Library::installArchive($root, $applicationArchive, $incompleteApplication));
    }
    Release_Library::installArchive($root, $applicationArchive, $applicationManifest);
    foreach ($applicationFiles as $name => $contents) check(file_get_contents($root . '/' . $name) === $contents, 'The application runtime was not installed.');
    $applicationState = json_decode(file_get_contents($root . '/.chronos-release.json'), true);
    Release_Library::rollback($root, $applicationState['deployment'], $applicationManifest['commit']);
    foreach (array_keys($applicationFiles) as $name) check(!is_file($root . '/' . $name), 'Rollback retained new application runtime files.');
    [$linkArchive, $linkManifest] = package($base, ['webroot/ui/unsafe-link.js' => '/outside']);
    $zip = new ZipArchive(); check($zip->open($linkArchive) === true, 'Cannot reopen link fixture.'); $zip->setExternalAttributesName('webroot/ui/unsafe-link.js', ZipArchive::OPSYS_UNIX, 0120777 << 16); $zip->close();
    $linkManifest['archive'] = ['bytes' => filesize($linkArchive), 'sha256' => hash_file('sha256', $linkArchive)]; rejects(fn() => Release_Library::installArchive($root, $linkArchive, $linkManifest));
    $writes = 0; rejects(function () use ($root, $archive, $manifest, &$writes) { Release_Library::installArchive($root, $archive, $manifest, 'master', null, function () use ($root, &$writes) { check(is_file($root . '/.chronos-maintenance.json') && str_contains(file_get_contents($root . '/webroot/index.php'), 'http_response_code(503)'), 'The v2 entry point must be gated before any runtime replacement.'); if (++$writes === 2) throw new RuntimeException('Injected filesystem failure.'); }); });
    check($writes === 2 && file_get_contents($root . '/webroot/index.php') === '<?php echo "old";' && !is_file($root . '/webroot/ui/new.js') && !is_file($root . '/.chronos-maintenance.json'), 'A failed deployment must recover the prior state.');
    Release_Library::installArchive($root, $archive, $manifest); $state = json_decode(file_get_contents($root . '/.chronos-release.json'), true); file_put_contents($root . '/.chronos-deployments/' . $state['deployment'] . '/backup/webroot/index.php', 'corrupted backup');
    rejects(fn() => Release_Library::rollback($root, $state['deployment'], $manifest['commit'])); check(file_get_contents($root . '/webroot/index.php') === '<?php echo "new";', 'A corrupted backup must not be applied.');
    $generationRoot = $base . '/generations'; mkdir($generationRoot); mkdir($generationRoot . '/webroot'); file_put_contents($generationRoot . '/webroot/index.php', '<?php echo "v2";'); mkdir($generationRoot . '/data'); mkdir($generationRoot . '/data/presets'); file_put_contents($generationRoot . '/data/presets/1.json', 'private shared values');
    [$firstArchive, $first] = package($base, ['webroot/ui/main-A_aAaAaA.js' => 'first generation']); Release_Library::installArchive($generationRoot, $firstArchive, $first);
    [$secondArchive, $second] = package($base, ['webroot/ui/chunk-B_bBbBbB.js' => 'second generation'], [], [], str_repeat('b', 40)); Release_Library::installArchive($generationRoot, $secondArchive, $second, 'master', $first['commit']);
    check(is_file($generationRoot . '/webroot/ui/main-A_aAaAaA.js'), 'An open client needs its previous hashed assets until it reloads.');
    [$thirdArchive, $third] = package($base, ['webroot/ui/main-C_cCcCcC.js' => 'third generation'], [], [], str_repeat('c', 40)); Release_Library::installArchive($generationRoot, $thirdArchive, $third, 'master', $second['commit']);
    check(!is_file($generationRoot . '/webroot/ui/main-A_aAaAaA.js') && is_file($generationRoot . '/webroot/ui/chunk-B_bBbBbB.js'), 'Keep exactly one previous hashed asset generation.');
    $generationState = json_decode(file_get_contents($generationRoot . '/.chronos-release.json'), true); Release_Library::rollback($generationRoot, $generationState['deployment'], $third['commit']);
    check(is_file($generationRoot . '/webroot/ui/main-A_aAaAaA.js') && is_file($generationRoot . '/webroot/ui/chunk-B_bBbBbB.js') && !is_file($generationRoot . '/webroot/ui/main-C_cCcCcC.js'), 'Rollback must restore the complete previously retained client asset set.');
    check(file_get_contents($generationRoot . '/data/presets/1.json') === 'private shared values', 'Package updates must retain private project presets.');
    echo "PASS: runtime preflight before writes, minimal-runtime rollback, package integrity, persistent-data protection, activation, stale-plan rejection, unsafe paths, unlisted files, build identity, symlink boundaries and injected failure recovery.\n";
} finally { cleanup($base); }
