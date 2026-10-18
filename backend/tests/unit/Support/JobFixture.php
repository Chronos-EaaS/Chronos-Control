<?php

declare(strict_types=1);

namespace Chronos\Tests\Support;

use \Chronos\Infrastructure\Persistence\Model\Job;

final class JobFixture
{
    public static function make(int $id, array $parameters, array $results, bool $serialized = true): \Chronos\Infrastructure\Persistence\Model\Job
    {
        $configuration = ['parameters' => $parameters, 'title' => 'Synthetic job'];

        return new \Chronos\Infrastructure\Persistence\Model\Job(
            $id, 1, 'Synthetic job', 1, 'system-fixture', 0, 5,
            $serialized ? json_encode($configuration, JSON_THROW_ON_ERROR) : $configuration,
            \Chronos\Domain\Workflows\JobStatus::FINISHED, 100, json_encode($results, JSON_THROW_ON_ERROR),
            '2026-01-15 12:00:00', '2026-01-15 12:01:00', '2026-01-15 12:02:00',
            1, $id, 'synthetic-configuration',
        );
    }

    /** Four configurations, three repeated runs each; no database or factories. */
    public static function groups(): array
    {
        $groups = [];
        $id = 1;
        foreach ([
            [1, 'small', [0, 4, 8], [30, 60, 90]],
            [2, 'small', [0, 8, 16], [12, 24, 36]],
            [1, 'large', [0, 12, 24], [3, 6, 9]],
            [2, 'large', [0, 16, 32], [15, 30, 45]],
        ] as [$workers, $batch, $latencies, $throughputs]) {
            $group = [];
            foreach ($latencies as $run => $latency) {
                $group[] = self::make(
                    $id++,
                    ['workers' => $workers, 'batch' => $batch, 'run' => $run],
                    ['latency' => (string) $latency, 'throughput' => (string) $throughputs[$run]],
                );
            }
            $groups[] = $group;
        }

        return $groups;
    }
}
