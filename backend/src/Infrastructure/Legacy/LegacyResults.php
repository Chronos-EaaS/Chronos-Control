<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Legacy;

/** Preserve the job objects and raw JSON expected by installed result processors. */
final class LegacyResults
{
    public static function job(array $row, array $configuration): \DBA\Job
    {
        return new \DBA\Job($row['jobId'], $row['userId'], $row['description'], $row['systemId'], $row['environment'], $row['phases'], $row['currentPhase'], $configuration, $row['status'], $row['progress'], $row['result'], $row['created'], $row['started'], $row['finished'], $row['evaluationId'], $row['internalId'], $row['configurationIdentifier']);
    }

    public static function plot(string $root, string $directory, array $groups, array $definition): string
    {
        return (new \Plot($root . '/libraries/graphs/' . $directory))->process($groups, $definition);
    }
}
