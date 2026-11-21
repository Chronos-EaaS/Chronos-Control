<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Persistence\Query;

/** Old agents must never see attempt-managed jobs in the unversioned queue. */
final class LegacyJobFilter extends Filter
{
    public function __construct(private readonly array $identifiers = [], private readonly array $historicalSystems = []) {}

    public function getQueryString(string $table = ''): string
    {
        $spec = 'SELECT 1 FROM JobSpecification spec WHERE spec.jobId=Job.jobId';
        if ($this->identifiers === []) return 'NOT EXISTS (' . $spec . ' AND spec.adapter IS NOT NULL)';
        $names = implode(',', array_fill(0, count($this->identifiers), '?'));
        $frozen = 'EXISTS (' . $spec . ' AND spec.adapter IS NULL AND spec.systemIdentifier IN (' . $names . '))';
        if ($this->historicalSystems === []) return $frozen;
        $ids = implode(',', array_fill(0, count($this->historicalSystems), '?'));
        return '(' . $frozen . ' OR (NOT EXISTS (' . $spec . ') AND Job.systemId IN (' . $ids . ')))';
    }

    public function getValue(): array { return [...$this->identifiers, ...$this->historicalSystems]; }
    public function getHasValue(): bool { return $this->identifiers !== []; }
}
