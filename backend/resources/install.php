<?php
declare(strict_types=1);
require_once __DIR__ . '/libraries/release.php';
require_once __DIR__ . '/libraries/install.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
$_SESSION['chronosInstallCsrf'] ??= bin2hex(random_bytes(32));
$target = defined('CHRONOS_INSTALL_ROOT') ? CHRONOS_INSTALL_ROOT : (is_file(__DIR__ . '/webroot/ui/index.html') ? __DIR__ : __DIR__ . '/chronos');
$error = ''; $success = false; $installed = is_file($target . '/config.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['chronosInstallCsrf'], $_POST['csrf'])) throw new RuntimeException('The installation session expired. Reload this page and try again.');
        if (!is_dir($target) && !mkdir($target, 0755)) throw new RuntimeException('The installation directory could not be created.');
        $branch = $_POST['branch'] ?? 'master';
        if (!is_string($branch) || trim($branch) === '') throw new InvalidArgumentException('Choose a repository branch.');
        $state = is_file($target . '/.chronos-release.json') ? json_decode((string) file_get_contents($target . '/.chronos-release.json'), true) : [];
        if (!is_file($target . '/webroot/ui/index.html') || ($state['branch'] ?? 'master') !== $branch) {
            $plan = Release_Library::plan($target, 'update', ['branch' => $branch]);
            Release_Library::execute($target, $plan);
        }
        Install_Library::install($target, $_POST);
        $success = true; $installed = true; $_SESSION['chronosInstallCsrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        error_log('Chronos installation failed: ' . get_class($exception));
        $error = $exception instanceof PDOException ? 'The database setup failed. Check the connection details and an empty dedicated database; consult the private server log for details.' : $exception->getMessage();
    }
}
function installValue(string $key, string $default = ''): string { return htmlspecialchars(is_string($_POST[$key] ?? null) ? $_POST[$key] : $default, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Install Chronos Control</title><style>
:root{font:16px system-ui,sans-serif;color:#253448;background:#f3f6fa}*{box-sizing:border-box}body{margin:0}header{background:#1d304d;color:#fff;padding:2rem max(1rem,calc((100vw - 900px)/2))}header span{font-size:.8rem;letter-spacing:.15em;color:#b7c8df}h1{font-size:2rem;font-weight:600;letter-spacing:-.03em;margin:.6rem 0}main{max-width:940px;margin:2rem auto;padding:0 1rem 3rem}.panel{background:white;border:1px solid #dce3ec;border-radius:12px;padding:1.5rem;margin:1.4rem 0}h2{font-size:1.1rem;margin:0 0 1.2rem}.grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}label{display:block;font-weight:600;font-size:.9rem;margin-bottom:.4rem}input{width:100%;padding:.7rem;border:1px solid #bdc9d8;border-radius:6px;font:inherit}input:focus{outline:3px solid #91b9d7;outline-offset:2px}button,a.button{display:inline-block;padding:.8rem 1.2rem;background:#304c71;color:white;border:0;border-radius:7px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}.message{border-radius:8px;padding:1rem;background:#e8f4ed;color:#23503b}.error{background:#fcebea;color:#842b2b}.muted{color:#61738b;line-height:1.6;font-size:.9rem}.full{grid-column:1/-1}@media(max-width:600px){.grid{grid-template-columns:1fr}.panel{padding:1rem}button{width:100%}}
</style></head><body><header><span>CHRONOS CONTROL</span><h1>Set up your evaluation workspace.</h1></header><main>
<?php if ($error !== ''): ?><div class="message error" role="alert"><?=htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></div><?php endif; ?>
<?php if ($installed): ?><section class="panel"><h2><?=$success ? 'Installation completed' : 'Already configured'?></h2><p>Chronos is configured. The installer cannot replace its database or configuration.</p><?php if ($success): ?><p>Configure your web server to serve the installation’s <code>webroot</code> directory, then sign in with the administrator account you created.</p><p class="muted">Remove the standalone installer from the public directory after setup.</p><?php if (defined('CHRONOS_INSTALL_ROOT')): ?><a class="button" href="/login">Sign in to Chronos</a><?php endif; ?><?php endif; ?></section>
<?php else: ?><p class="muted">Requires PHP 8.3 or newer with PDO MySQL, mbstring, DOM, cURL, ZIP and GD. The server receives a complete CI package with the compiled UI and PHP libraries. Choose an empty dedicated MySQL database and your initial administrator account.</p><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['chronosInstallCsrf'], ENT_QUOTES, 'UTF-8')?>"><section class="panel"><h2>Build package</h2><label for="branch">Repository branch</label><input id="branch" name="branch" value="<?=installValue('branch', 'master')?>" required maxlength="250"><p class="muted">The selected branch commit must have a published Chronos CI build package. Installation never builds JavaScript on this server.</p></section><section class="panel"><h2>Database connection</h2><div class="grid"><div><label for="db-host">Host</label><input id="db-host" name="dbHost" value="<?=installValue('dbHost', 'localhost')?>" required></div><div><label for="db-port">Port</label><input id="db-port" name="dbPort" type="number" min="1" max="65535" value="<?=installValue('dbPort', '3306')?>" required></div><div><label for="db-name">Database</label><input id="db-name" name="dbName" value="<?=installValue('dbName', 'chronos')?>" required></div><div><label for="db-user">User</label><input id="db-user" name="dbUser" value="<?=installValue('dbUser', 'chronos')?>" required></div><div class="full"><label for="db-password">Database password</label><input id="db-password" name="dbPassword" type="password" autocomplete="off"></div></div></section><section class="panel"><h2>Initial administrator</h2><div class="grid"><div><label for="admin-username">Username</label><input id="admin-username" name="username" autocomplete="username" value="<?=installValue('username')?>" required maxlength="50"></div><div><label for="admin-email">Email</label><input id="admin-email" name="email" type="email" autocomplete="email" value="<?=installValue('email')?>" required maxlength="100"></div><div><label for="admin-first">First name</label><input id="admin-first" name="firstName" autocomplete="given-name" value="<?=installValue('firstName')?>" required maxlength="50"></div><div><label for="admin-last">Last name</label><input id="admin-last" name="lastName" autocomplete="family-name" value="<?=installValue('lastName')?>" required maxlength="50"></div><div><label for="admin-password">Password (12–72 bytes)</label><input id="admin-password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="72"></div><div><label for="admin-confirm">Repeat password</label><input id="admin-confirm" name="passwordConfirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="72"></div></div></section><button type="submit">Install Chronos</button></form><?php endif; ?>
</main></body></html>
