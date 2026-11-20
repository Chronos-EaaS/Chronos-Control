<?php
declare(strict_types=1);

use Chronos\Http\Api\V1\AgentRouter;
use Chronos\Infrastructure\Persistence\Connection;

require dirname(__DIR__, 3) . '/backend/vendor/autoload.php';

$fixtureManifest = getenv('CHRONOS_TEST_MANIFEST');
define('LOG_DIRECTORY', dirname($fixtureManifest) . '/logs');

Connection::configure('sqlite::memory:');
$db = Connection::get();
$db->exec(<<<'SQL'
    CREATE TABLE JobSpecification (jobId INTEGER PRIMARY KEY, specification TEXT, adapter TEXT);
    CREATE TABLE System (
        systemId INTEGER PRIMARY KEY, name TEXT, description TEXT, userId INT, vcsUrl TEXT, vcsBranch TEXT,
        vcsType TEXT, vcsUser TEXT, vcsPassword TEXT, created TEXT, lastEdit TEXT, isArchived INT, cem INT
    );
    INSERT INTO System VALUES
        (1, 'Synthetic CEM', '', 1, '', '', '', '', '', '', '', 0, 1),
        (2, 'Legacy CEM', '', 1, '', '', '', '', '', '', '', 0, 1),
        (3, 'Invalid CEM', '', 1, '', '', '', '', '', '', '', 0, 1);
    CREATE TABLE Job (
        jobId INTEGER PRIMARY KEY, userId INT, description TEXT, systemId INT, environment TEXT, phases INT,
        currentPhase INT, configuration TEXT, status INT, progress INT, result TEXT, created TEXT, started TEXT,
        finished TEXT, evaluationId INT, internalId INT, configurationIdentifier TEXT
    );
    INSERT INTO Job
        SELECT systemId, 1, '', systemId, 'cem-fixture', 5, 0, '{"parameters":{}}', 0, 0, '', '2026-01-01', NULL,
            NULL, 1, 1, 'synthetic' FROM System;
    CREATE TABLE Setting (settingId INTEGER PRIMARY KEY, section TEXT, item TEXT, value TEXT, systemId INT);
    INSERT INTO Setting (section, item, value, systemId) VALUES ('cem', 'minVersion', '2', 0),
        ('cem', 'environments', '["fixture"]', 0), ('setup', 'mode', 'synthetic', 1),
        ('setup', 'mode', 'legacy', 2), ('setup', 'artifactManifestV1', '{}', 3);
    CREATE TABLE Node (
        nodeId TEXT PRIMARY KEY, environment TEXT, version TEXT, currentJob INT, cpu REAL, memoryUsed INT,
        memoryTotal INT, hostname TEXT, ip TEXT, os TEXT, healthStatus TEXT, lastUpdate TEXT
    );
    SQL);
$insert = $db->prepare("INSERT INTO Setting (section, item, value, systemId) VALUES ('setup', 'artifactManifestV1', ?, 1)");
$insert->execute([file_get_contents($fixtureManifest)]);

AgentRouter::dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
