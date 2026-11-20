<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Query;

/** Old agents must never see attempt-managed jobs in the unversioned queue. */
final class LegacyJobFilter extends Filter
{
    public function getQueryString(string $table = ''): string
    {
        return 'NOT EXISTS (SELECT 1 FROM JobSpecification spec WHERE spec.jobId=Job.jobId AND spec.adapter IS NOT NULL)';
    }

    public function getValue(): mixed { return null; }
    public function getHasValue(): bool { return false; }
}
