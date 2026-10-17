<?php
declare(strict_types=1);

/** Fresh-install boundary: refuses existing configuration or any nonempty database. */
final class Install_Library
{
    public static function install(string $root, array $input): array
    {
        $root = realpath($root) ?: throw new RuntimeException('The installation package is unavailable.');
        if (file_exists($root . '/config.php') || is_link($root . '/config.php')) throw new RuntimeException('This installation is already configured.');
        foreach (['pdo_mysql', 'mbstring', 'dom', 'curl', 'zip', 'gd'] as $extension) if (!extension_loaded($extension)) throw new RuntimeException('The PHP ' . $extension . ' extension is required.');
        if (version_compare(PHP_VERSION, '8.3', '<')) throw new RuntimeException('Chronos requires PHP 8.3 or newer.');
        $host = self::text($input, 'dbHost', 250); $database = self::text($input, 'dbName', 64); $username = self::text($input, 'dbUser', 250);
        $dbPassword = $input['dbPassword'] ?? null;
        if (!is_string($dbPassword) || str_contains($dbPassword, "\0") || preg_match('/[;=\r\n]/', $host) || !preg_match('/^[A-Za-z0-9_-]+$/D', $database)) throw new InvalidArgumentException('Enter a valid database host, name and password.');
        $port = filter_var($input['dbPort'] ?? 3306, FILTER_VALIDATE_INT); if ($port === false || $port < 1 || $port > 65535) throw new InvalidArgumentException('Enter a valid database port.');
        $admin = self::text($input, 'username', 50); $first = self::text($input, 'firstName', 50); $last = self::text($input, 'lastName', 50); $email = self::text($input, 'email', 100);
        $first = htmlspecialchars($first, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $last = htmlspecialchars($last, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (mb_strlen($first) > 50 || mb_strlen($last) > 50) throw new InvalidArgumentException('The encoded first and last names must fit 50 characters.');
        if (is_numeric($admin) || preg_match('/[<>&\r\n\t]/', $admin) || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid administrator username and email address.');
        $password = $input['password'] ?? null;
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0") || $password !== ($input['passwordConfirmation'] ?? null)) throw new InvalidArgumentException('Choose a matching administrator password of 12 to 72 bytes.');
        $branch = isset($input['branch']) ? self::text($input, 'branch', 250) : 'master';
        foreach (['chronos.sql', 'config.template.php', 'webroot/ui/index.html', 'webroot/ui/build.json', 'vendor/autoload.php'] as $file) if (!is_file($root . '/' . $file)) throw new RuntimeException('Use a complete CI build package, including the compiled UI and PHP dependencies.');
        $lock = fopen($root . '/.chronos-install.lock', 'c'); if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another installation is running.');
        $db = null; $databaseStarted = false; $databaseLock = null; $configStaged = null;
        try {
            if (file_exists($root . '/config.php')) throw new RuntimeException('This installation is already configured.');
            $db = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $username, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $databaseLock = 'chronos.install.' . substr(hash('sha256', $database), 0, 40);
            $statement = $db->prepare('SELECT GET_LOCK(?, 0)'); $statement->execute([$databaseLock]);
            if ((int) $statement->fetchColumn() !== 1) { $databaseLock = null; throw new RuntimeException('Another installation is using this database.'); }
            if ($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) !== []) throw new RuntimeException('The selected database must be empty. Existing data will not be replaced.');
            $template = self::configuration((string) file_get_contents($root . '/config.template.php'),
                ['DB_HOST' => $host, 'DB_NAME' => $database, 'DB_USER' => $username, 'DB_PASS' => $dbPassword], $port);
            $configStaged = $root . '/.chronos-config-' . bin2hex(random_bytes(8));
            if (file_put_contents($configStaged, $template, LOCK_EX) === false || !chmod($configStaged, 0600)) throw new RuntimeException('The configuration could not be staged.');
            $sql = (string) file_get_contents($root . '/chronos.sql');
            $databaseStarted = true; $db->exec($sql); $db->exec('SET autocommit=1'); $db->exec("SET time_zone='SYSTEM'");
            $db->beginTransaction();
            $at = (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->format('Y-m-d H:i:s');
            $statement = $db->prepare('INSERT INTO `User` (username,password,email,firstname,lastname,gender,role,alive,activated,created,lastEdit) VALUES (?,?,?,?,?,0,2,1,1,?,?)');
            $statement->execute([$admin, password_hash($password, PASSWORD_BCRYPT), $email, $first, $last, $at, $at]);
            $settings = ['vcs' => ['repoType' => 'git', 'repoUrl' => 'https://github.com/Chronos-EaaS/Chronos-Control.git', 'repoUsername' => '', 'repoPassword' => '', 'repoBranch' => $branch], 'ftp' => ['ftpServer' => '', 'ftpPort' => '21', 'ftpUsername' => '', 'ftpPassword' => '', 'localNetworkCIDR' => '', 'useFtpUploadForLocalClients' => '0'], 'other' => ['rowsPerPage' => '20', 'maxJobsPerEvaluation' => '1000', 'uploadedDataHostname' => ''], 'cem' => ['minVersion' => '0', 'nodeMissingTimeout' => '600', 'environments' => '[]']];
            $statement = $db->prepare('INSERT INTO `Setting` (systemId,section,item,value) VALUES (0,?,?,?)');
            foreach ($settings as $section => $items) foreach ($items as $item => $value) $statement->execute([$section, $item, $value]);
            foreach (['logs', 'locks', 'temp', 'webroot/systems', 'webroot/media', 'webroot/data/evaluation', 'webroot/data/log'] as $directory) if (!is_dir($root . '/' . $directory) && !mkdir($root . '/' . $directory, 0770, true)) throw new RuntimeException('The application data directories could not be created.');
            if (!is_dir($root . '/data/presets') && !mkdir($root . '/data/presets', 0700, true)) throw new RuntimeException('The private parameter preset directory could not be created.');
            $db->commit();
            if (!rename($configStaged, $root . '/config.php')) throw new RuntimeException('The configuration could not be activated.');
            $configStaged = null;
            return ['success' => true, 'username' => $admin];
        } catch (Throwable $error) {
            if ($db !== null && $databaseStarted) {
                if ($db->inTransaction()) $db->rollBack();
                // Only this request's originally empty database can reach cleanup.
                $tables = $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
                foreach (['VIEW', 'BASE TABLE'] as $kind) foreach ($tables as [$name, $type]) if ($type === $kind && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) $db->exec(($kind === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . $name . '`');
            }
            throw $error;
        } finally { if ($db !== null && $databaseLock !== null) { $statement = $db->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$databaseLock]); } if ($configStaged !== null && is_file($configStaged)) unlink($configStaged); flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Render only the package placeholders; credentials are always PHP literals. */
    private static function configuration(string $template, array $credentials, int $port): string
    {
        $replacements = [];
        foreach ($credentials as $key => $value) {
            $needle = "'__" . $key . "__'";
            if (substr_count($template, $needle) !== 1) throw new RuntimeException('The package configuration template is invalid.');
            $replacements[$needle] = var_export($value, true);
        }
        $portPlaceholder = "define('DB_PORT', 3306);";
        if (substr_count($template, $portPlaceholder) !== 1) throw new RuntimeException('The package configuration template is invalid.');
        $replacements[$portPlaceholder] = "define('DB_PORT', " . $port . ');';
        // A credential can itself contain another placeholder. Replace only
        // matches in the original template, never text inserted by a prior key.
        return strtr($template, $replacements);
    }

    private static function text(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new InvalidArgumentException('Enter a valid ' . $key . '.');
        return trim($value);
    }
}
