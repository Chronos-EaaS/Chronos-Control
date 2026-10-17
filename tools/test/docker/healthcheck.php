<?php
declare(strict_types=1);
$body = @file_get_contents('http://127.0.0.1:8080/healthz.txt');
if ($body !== "chronos-test-ready\n") {
    exit(1);
}
try {
    $db = new PDO('mysql:host=db;dbname=chronos_test;charset=utf8mb4', 'chronos_test', 'chronos_test_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,
    ]);
    $username = $db->query('SELECT username FROM `User` WHERE userId=1')->fetchColumn();
    exit($username === 'superadmin' ? 0 : 1);
} catch (Throwable $error) {
    exit(1);
}
