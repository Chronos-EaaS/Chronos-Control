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
}
