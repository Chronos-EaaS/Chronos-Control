<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Infrastructure\Persistence\Store;

final class JobSpecifications
{
    public function __construct(private readonly Store $db)
    {
    }

    public function forJob(int $jobId, bool $lock = false): ?array
    {
        $row = $this->db->row('SELECT specification FROM JobSpecification WHERE jobId=?' . ($lock ? ' FOR UPDATE' : ''), [$jobId]);
        return $row === null ? null : json_decode($row['specification'], true, 64, JSON_THROW_ON_ERROR);
    }

    /** Return the persisted JSON types, including empty maps, at public API boundaries. */
    public function document(int $jobId): ?\stdClass
    {
        $row = $this->db->row('SELECT specification FROM JobSpecification WHERE jobId=?', [$jobId]);
        return $row === null ? null : json_decode($row['specification'], false, 64, JSON_THROW_ON_ERROR);
    }

    public function usesAttempts(int $jobId): bool
    {
        return $this->db->row('SELECT jobId FROM JobSpecification WHERE jobId=? AND adapter IS NOT NULL', [$jobId]) !== null;
    }

    public function latestAttempt(int $jobId, bool $lock = false): ?array
    {
        return $this->db->row('SELECT * FROM JobAttempt WHERE jobId=? ORDER BY attemptId DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''), [$jobId]);
    }

    public static function managed(mixed $configuration): bool
    {
        return is_array($configuration) && ($configuration['executionProtocol'] ?? null) === ExecutionAttempts::PROTOCOL;
    }

    public function selectedAttempt(int $jobId): ?array
    {
        return $this->db->row('SELECT attemptId,archive FROM JobAttempt WHERE jobId=? AND selected=1', [$jobId]);
    }
}
