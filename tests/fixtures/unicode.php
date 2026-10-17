<?php
declare(strict_types=1);

// Prepare one historical BLOB-backed environment name only in the fixed v3 fixture.
if (PHP_SAPI !== 'cli' || getenv('CHRONOS_TEST_STACK') !== '1' || getenv('CHRONOS_TEST_V3') !== '1'
    || !is_file('/.dockerenv') || __FILE__ !== '/opt/chronos/tests/fixtures/unicode.php'
    || file_get_contents('/opt/chronos/webroot/healthz.txt') !== "chronos-test-ready\n") {
    throw new RuntimeException('Refusing Unicode fixtures outside the isolated v3 test container.');
}
$action = $argv[1] ?? '';
if (!in_array($action, ['seed-legacy-environment', 'remove-legacy-environment'], true)) throw new RuntimeException('Unsupported Unicode fixture action.');
$db = new PDO('mysql:host=db;dbname=chronos_test;charset=utf8mb4', 'chronos_test', 'chronos_test_only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_test') throw new RuntimeException('Unexpected fixture database.');
$db->beginTransaction();
try {
    $rows = $db->query("SELECT settingId,value FROM `Setting` WHERE systemId=0 AND section='cem' AND item='environments' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) throw new RuntimeException('Unexpected synthetic CEM settings.');
    $names = json_decode($rows[0]['value'], true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($names) || !array_is_list($names)) throw new RuntimeException('Unexpected synthetic environment list.');
    $legacy = 'legacy-review-🧪';
    $names = array_values(array_filter($names, static fn(string $name): bool => $name !== $legacy));
    if ($action === 'seed-legacy-environment') $names[] = $legacy;
    $db->prepare('UPDATE `Setting` SET value=? WHERE settingId=?')->execute([json_encode($names, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $rows[0]['settingId']]);
    $db->commit();
    echo "Synthetic legacy Unicode environment prepared.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
