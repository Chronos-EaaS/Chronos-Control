<?php
declare(strict_types=1);

namespace Chronos\Application\Results;

use Chronos\Infrastructure\Persistence\Model\Job;

final class ResultJobs
{
    public static function fromRow(array $row, array $configuration): Job
    {
        return new Job($row['jobId'], $row['userId'], $row['description'], $row['systemId'], $row['environment'], $row['phases'], $row['currentPhase'], $configuration, $row['status'], $row['progress'], $row['result'], $row['created'], $row['started'], $row['finished'], $row['evaluationId'], $row['internalId'], $row['configurationIdentifier']);
    }

    /** Decode once per snapshot; trusted legacy processors retain the original JSON string models. */
    public static function forBuiltinPlots(array $groups, array &$cache): array
    {
        return array_map(static function (array $group) use (&$cache): array {
            return array_map(static function (Job $job) use (&$cache): Job {
                $id = $job->getId();
                if (!isset($cache[$id])) {
                    $decoded = clone $job;
                    // Match Statistics' established decoding depth/shape;
                    // raw-row browsing intentionally has a separate limit.
                    $result = $job->getResult();
                    if (is_string($result)) $result = json_decode($result, true);
                    $decoded->setResult(is_array($result) ? $result : []);
                    $cache[$id] = $decoded;
                }
                return $cache[$id];
            }, $group);
        }, $groups);
    }
}
