<?php
declare(strict_types=1);

// Read an actual CI artifact; install only into an internally generated /tmp
// directory. This test never includes operational configuration or opens a DB.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || !isset($argv[2])) {
    fwrite(STDERR, "Usage: php backend/tests/deployment/package.php --run <release-output-directory>\n"); exit(2);
}
use Chronos\Infrastructure\Deployment\ReleaseManager;

require_once __DIR__ . '/../../src/Infrastructure/Deployment/ReleaseManager.php';
$artifact = realpath($argv[2]);
if ($artifact === false || !is_dir($artifact)) throw new RuntimeException('The CI package directory is unavailable.');
foreach (['chronos-control.zip','chronos-control-manifest.json','chronos-installer.php'] as $file) {
    if (!is_file($artifact . '/' . $file) || is_link($artifact . '/' . $file)) throw new RuntimeException('A required CI package artifact is missing.');
}
$manifest=json_decode((string)file_get_contents($artifact.'/chronos-control-manifest.json'),true,64,JSON_THROW_ON_ERROR);
function packageCheck(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
packageCheck(($manifest['uiApiPath'] ?? null) === '/api/ui', 'The current package must identify its canonical browser API.');
function packageRemove(string $path,string $boundary):void{
    packageCheck(str_starts_with($path.'/',$boundary.'/'),'Unsafe package fixture cleanup rejected.');
    if(is_file($path)||is_link($path)){unlink($path);return;}
    foreach(new FilesystemIterator($path)as$entry)packageRemove($entry->getPathname(),$boundary);
    rmdir($path);
}
function packageCommand(array $arguments):string{
    $process=proc_open($arguments,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    packageCheck(is_resource($process),'Cannot start the isolated runtime smoke test.');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    packageCheck(proc_close($process)===0&&$error==='','The packaged runtime smoke failed: '.$error);return$output;
}
$base=sys_get_temp_dir().'/chronos-actual-package-'.bin2hex(random_bytes(8));packageCheck(mkdir($base,0700),'Cannot create a private package fixture.');
try{
    foreach ([
        ['environment' => 'CHRONOS_PREVIOUS_DEPLOYER', 'label' => 'BB5', 'class' => 'Release_Library', 'sha256' => 'bcb61ba39e5b3ca70e0b9bb4a67222c39c2a463ed07ad492127d8edb7191e0f4'],
        ['environment' => 'CHRONOS_PREVIOUS_UI_DEPLOYER', 'label' => '6d1', 'class' => ReleaseManager::class, 'sha256' => 'd13e42ed08c47e59356dd702d80e89f54d0d2983ddedf2c62dd32f32067f437a'],
    ] as $released) {
        $previousDeployer = getenv($released['environment']);
        if ($previousDeployer === false) continue;
        packageCheck(is_file($previousDeployer) && !is_link($previousDeployer)
            && hash_file('sha256', $previousDeployer) === $released['sha256'], 'Use the exact released ' . $released['label'] . ' deployer fixture.');
        $compatibilityRoot = $base . '/previous-deployer-' . strtolower($released['label']);
        packageCheck(mkdir($compatibilityRoot, 0700), 'Cannot create the previous-deployer fixture.');
        $previousFiles = [
            'webroot/index.php' => '<?php echo "previous released entry";',
            'core/autoloader.php' => '<?php /* superseded global autoloader */',
            'app/Infrastructure/Legacy/LegacyDatabase.php' => '<?php /* superseded adapter */',
            'app/Http/Api/V3/Kernel.php' => '<?php /* synthetic prior browser kernel */',
            'api/v3/bootstrap.php' => '<?php /* synthetic prior browser boundary */',
        ];
        foreach ($previousFiles as $path => $contents) {
            if (!is_dir(dirname($compatibilityRoot . '/' . $path))) mkdir(dirname($compatibilityRoot . '/' . $path), 0700, true);
            file_put_contents($compatibilityRoot . '/' . $path, $contents);
        }
        $previousState = ['format' => 1, 'commit' => str_repeat('0', 40), 'uiBuildId' => str_repeat('0', 40), 'branch' => 'fixture/released', 'files' => array_fill_keys(array_keys($previousFiles), [])];
        file_put_contents($compatibilityRoot . '/.chronos-release.json', json_encode($previousState, JSON_THROW_ON_ERROR));
        file_put_contents($compatibilityRoot . '/config.php', 'private existing configuration');
        $upgrade = <<<'PHP'
require $argv[1];
$deployer = $argv[4];
$manifest = json_decode(file_get_contents($argv[2] . '/chronos-control-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
foreach ([
    'core/browserui.php' => 'The package does not contain a complete Chronos runtime.',
    'libraries/release.php' => 'The package does not contain a complete Chronos runtime.',
    'libraries/install.php' => 'The package does not contain a complete Chronos runtime.',
    'api/v3/bootstrap.php' => 'The package does not contain a complete Chronos runtime.',
    'app/Http/Api/V3/Kernel.php' => 'The package does not contain a complete application runtime.',
] as $requiredBridge => $expectedError) {
    $incomplete = $manifest;
    unset($incomplete['files'][$requiredBridge]);
    try {
        $deployer::installArchive($argv[3], $argv[2] . '/chronos-control.zip', $incomplete, 'fixture/released', str_repeat('0', 40));
        throw new RuntimeException('A released updater accepted a missing compatibility entry.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== $expectedError) throw $error;
    }
    if (is_dir($argv[3] . '/.chronos-deployments') || is_file($argv[3] . '/.chronos-maintenance.json')) throw new RuntimeException('Released-deployer rejection modified the installation.');
}
$deployer::installArchive($argv[3], $argv[2] . '/chronos-control.zip', $manifest, 'fixture/released', str_repeat('0', 40));
echo 'released deployer accepted the modern package';
PHP;
        packageCheck(packageCommand([PHP_BINARY, '-d', 'allow_url_fopen=0', '-r', $upgrade, $previousDeployer, $artifact, $compatibilityRoot, $released['class']]) === 'released deployer accepted the modern package', 'The released ' . $released['label'] . ' deployer cannot install this package.');
        packageCheck(!is_file($compatibilityRoot . '/core/autoloader.php') && !is_file($compatibilityRoot . '/app/Infrastructure/Legacy/LegacyDatabase.php'), 'The released updater retained superseded implementation files.');
        $compatibilityState = json_decode((string) file_get_contents($compatibilityRoot . '/.chronos-release.json'), true, 64, JSON_THROW_ON_ERROR);
        ReleaseManager::rollback($compatibilityRoot, $compatibilityState['deployment'], $manifest['commit']);
        foreach ($previousFiles as $path => $contents) packageCheck(file_get_contents($compatibilityRoot . '/' . $path) === $contents, 'The modern rollback did not restore a released-deployer backup.');
        foreach (array_keys($manifest['files']) as $path) if (!isset($previousFiles[$path])) packageCheck(!is_file($compatibilityRoot . '/' . $path), 'Cross-version rollback retained an introduced runtime file.');
        packageCheck(json_decode((string) file_get_contents($compatibilityRoot . '/.chronos-release.json'), true) === $previousState
            && file_get_contents($compatibilityRoot . '/config.php') === 'private existing configuration', 'Cross-version rollback changed prior release state or configuration.');
        echo 'PASS: the exact released ' . $released['label'] . " deployer installed the canonical UI package and its journal rolled back with the modern deployment service.\n";
    }
    $root=$base.'/application';packageCheck(mkdir($root,0700),'Cannot create a package installation fixture.');
    foreach(['webroot/ui','webroot/systems/1','webroot/data/evaluation','data/presets','locks','logs','.git']as$path)mkdir($root.'/'.$path,0700,true);
    $oldCommit=str_repeat('0',40);$oldPhp='<?php echo "previous synthetic runtime";';$oldBuild=json_encode(['id'=>$oldCommit]);
    file_put_contents($root.'/webroot/index.php',$oldPhp);file_put_contents($root.'/webroot/ui/build.json',$oldBuild);file_put_contents($root.'/webroot/ui/main-PREVIOUS123.js','previous immutable asset');
    $obsolete='webroot/assets/datatables/extensions/TableTools/images/psd/copy document.psd';
    packageCheck(in_array($obsolete,$manifest['removedFiles']??[],true),'The CI package does not explicitly remove obsolete public UI assets.');
    mkdir(dirname($root.'/'.$obsolete),0700,true);file_put_contents($root.'/'.$obsolete,'obsolete synthetic UI asset');
    $previous=['format'=>1,'commit'=>$oldCommit,'uiBuildId'=>$oldCommit,'branch'=>'fixture/previous','files'=>['webroot/index.php'=>[],'webroot/ui/build.json'=>[],'webroot/ui/main-PREVIOUS123.js'=>[],$obsolete=>[]]];
    file_put_contents($root.'/.chronos-release.json',json_encode($previous,JSON_THROW_ON_ERROR));
    $persistent=['config.php'=>'<?php /* synthetic existing configuration; never included */','webroot/systems/1/parameters.json'=>'[]','webroot/data/evaluation/1.zip'=>'synthetic result','data/presets/1.json'=>'{"format":1,"items":[]}','locks/v3-session-epoch'=>str_repeat('a',64).'\n','.git/HEAD'=>'synthetic repository state'];
    foreach($persistent as$path=>$contents)file_put_contents($root.'/'.$path,$contents);
    $installed=ReleaseManager::installArchive($root,$artifact.'/chronos-control.zip',$manifest,'fixture/package-branch',$oldCommit);
    packageCheck($installed['success']===true&&$installed['commit']===$manifest['commit'],'The actual CI archive was not installed.');
    $state=json_decode((string)file_get_contents($root.'/.chronos-release.json'),true,64,JSON_THROW_ON_ERROR);
    packageCheck($state['branch']==='fixture/package-branch','Installation did not preserve the selected branch.');
    foreach($persistent as$path=>$contents)packageCheck(file_get_contents($root.'/'.$path)===$contents,'The archive changed persistent application data.');
    foreach($manifest['files']as$path=>$file)packageCheck(hash_equals($file['sha256'],hash_file('sha256',$root.'/'.$path)),'An installed package file differs from its manifest.');
    packageCheck(!file_exists($root.'/.chronos-maintenance.json'),'Successful package activation left maintenance enabled.');
    packageCheck(is_file($root.'/webroot/ui/main-PREVIOUS123.js'),'The preceding immutable UI generation was not retained.');
    packageCheck(!is_file($root.'/'.$obsolete),'The archive retained an obsolete legacy public UI asset.');
    $build=json_decode((string)file_get_contents($root.'/webroot/ui/build.json'),true,16,JSON_THROW_ON_ERROR);
    packageCheck($build['id']===$manifest['commit'],'Installed UI and PHP package have different identities.');
    $html=(string)file_get_contents($root.'/webroot/ui/index.html');packageCheck(str_contains($html,'<app-root'),'The archive does not contain the real compiled Angular entrypoint.');
    preg_match_all('/(?:src|href)="([^"<>]+\.(?:js|css))"/',$html,$assets);
    packageCheck(count($assets[1])>=2,'The compiled entrypoint has no JavaScript/CSS assets.');
    foreach($assets[1]as$asset){packageCheck(!preg_match('#^(?:https?:)?//#i',$asset),'A compiled entrypoint references a CDN.');packageCheck(!preg_match('#(?:^|/)\.\.(?:/|$)#',$asset),'An entrypoint asset traverses outside the UI.');$asset=ltrim($asset,'./');packageCheck(!str_contains($asset,'..')&&is_file($root.'/webroot/ui/'.$asset),'The compiled entrypoint references a missing package asset.');}
    $packages=json_decode((string)file_get_contents($root.'/vendor/composer/installed.json'),true,64,JSON_THROW_ON_ERROR);
    packageCheck(($packages['dev']??null)===false&&array_column($packages['packages']??[],'name')===['psr/log'],'The CI archive contains development PHP dependencies.');
    packageCommand([PHP_BINARY,'-l',$artifact.'/chronos-installer.php']);
    $installerSmoke='$_SERVER["REQUEST_METHOD"]="GET";ini_set("session.save_path",'.var_export($base,true).');ob_start();require '.var_export($artifact.'/chronos-installer.php',true).';$html=ob_get_clean();if(!str_contains($html,"Initial administrator")||!str_contains($html,"name=\"csrf\""))throw new RuntimeException("Standalone installer form unavailable");echo "standalone installer ready";';
    packageCheck(packageCommand([PHP_BINARY,'-d','allow_url_fopen=0','-r',$installerSmoke])==='standalone installer ready','The bundled standalone installer cannot show its independent setup form.');
    $smoke = 'define("SERVER_ROOT",' . var_export($root, true) . ');define("LOG_DIRECTORY",' . var_export($root . '/logs', true) . ');' . <<<'PHP'
require SERVER_ROOT . '/vendor/autoload.php';
$request = new Chronos\Http\Request('GET', '/api/ui/meta');
if ($request->path !== '/api/ui/meta') throw new RuntimeException('Application request class unavailable.');
$map = require SERVER_ROOT . '/vendor/composer/autoload_classmap.php';
$production = array_filter($map, static fn(string $file, string $class): bool => str_starts_with($class, 'Chronos\\'), ARRAY_FILTER_USE_BOTH);
$app = realpath(SERVER_ROOT . '/app');
$expected = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $relative = substr($file->getPathname(), strlen($app) + 1, -4);
    // Released package validators require this inert forwarding file, which
    // intentionally declares no old class and is outside canonical sources.
    if ($relative === 'Http/Api/V3/Kernel') continue;
    $expected['Chronos\\' . str_replace('/', '\\', $relative)] = realpath($file->getPathname());
}
if (!$expected || count($production) !== count($expected)) throw new RuntimeException('The production application classmap is incomplete or includes source/test classes.');
foreach ($expected as $class => $file) {
    if (!isset($production[$class]) || realpath($production[$class]) !== $file || !str_starts_with($file, $app . '/')) throw new RuntimeException('A production class points outside the installed app tree.');
    if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) throw new RuntimeException('A packaged application declaration cannot be loaded.');
    if (realpath((new ReflectionClass($class))->getFileName()) !== $file) throw new RuntimeException('A production declaration resolved to another source tree.');
}
require SERVER_ROOT . '/app/Http/Api/V3/Kernel.php';
if (!class_exists(Chronos\Http\Api\Ui\Kernel::class, false)) throw new RuntimeException('The old kernel path did not load the canonical implementation.');
foreach (['BrowserUi', 'Release_Library', 'Install_Library', 'Chronos\\Http\\Api\\V3\\Kernel'] as $oldName) {
    if (class_exists($oldName, false)) throw new RuntimeException('Normal application autoload activated a deployment compatibility alias.');
}
$logger = Chronos\Infrastructure\Logging\Logger::getInstance();
if (!$logger instanceof Psr\Log\LoggerInterface) throw new RuntimeException('PSR logger missing.');
$logger->notice('Actual CI package smoke');
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
if (!Chronos\Http\BrowserUi::serve(SERVER_ROOT, '/')) throw new RuntimeException('SPA missing.');
$html = ob_get_clean();
if (!str_contains($html, '<app-root')) throw new RuntimeException('Compiled UI missing.');
foreach (get_included_files() as $file) {
    if (str_ends_with($file, '/config.php') || str_contains($file, '/backend/')) throw new RuntimeException('Operational bootstrap loaded.');
}
echo 'packaged runtime ready';
PHP;
    packageCheck(packageCommand([PHP_BINARY,'-d','allow_url_fopen=0','-r',$smoke])==='packaged runtime ready','The actual packaged runtime failed to boot its public shell and Composer logger.');
    $bridgeSmoke = 'define("SERVER_ROOT",' . var_export($root, true) . ');' . <<<'PHP'
require SERVER_ROOT . '/core/browserui.php';
require SERVER_ROOT . '/libraries/release.php';
require SERVER_ROOT . '/libraries/install.php';
foreach (['BrowserUi' => Chronos\Http\BrowserUi::class, 'Release_Library' => Chronos\Infrastructure\Deployment\ReleaseManager::class, 'Install_Library' => Chronos\Infrastructure\Deployment\Installer::class] as $old => $modern) {
    if (!is_a($old, $modern, true)) throw new RuntimeException('An explicit deployment compatibility bridge is unavailable.');
}
if (class_exists('Chronos\\Infrastructure\\Persistence\\Store', false)) throw new RuntimeException('The deployment bridges loaded the application bootstrap.');
echo 'explicit deployment bridges ready';
PHP;
    packageCheck(packageCommand([PHP_BINARY, '-n', '-r', $bridgeSmoke]) === 'explicit deployment bridges ready', 'The standalone deployment compatibility paths require application bootstrap.');
    ReleaseManager::rollback($root,$state['deployment'],$manifest['commit']);
    packageCheck(file_get_contents($root.'/webroot/index.php')===$oldPhp&&file_get_contents($root.'/webroot/ui/build.json')===$oldBuild,'Rollback did not restore the previous runtime.');
    packageCheck(json_decode((string)file_get_contents($root.'/.chronos-release.json'),true)===$previous,'Rollback did not restore the previous release metadata.');
    packageCheck(file_get_contents($root.'/'.$obsolete)==='obsolete synthetic UI asset','Rollback did not restore the removed legacy asset with a space in its path.');
    foreach($persistent as$path=>$contents)packageCheck(file_get_contents($root.'/'.$path)===$contents,'Rollback changed persistent application data.');
    foreach(array_keys($manifest['files'])as$path)if(!isset($previous['files'][$path]))packageCheck(!is_file($root.'/'.$path),'Rollback retained a file introduced by the actual package.');
    echo 'PASS: actual CI archive integrity, compiled UI assets, no CDN, production-only PHP dependencies, all production app declarations resolved inside installed app, public runtime boot, standalone installer syntax, branch selection, persistent data and complete rollback.' . "\n";
}finally{packageRemove($base,$base);}
