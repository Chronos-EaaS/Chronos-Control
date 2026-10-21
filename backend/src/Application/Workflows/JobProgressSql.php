<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

/** Aggregate achieved work without confusing the agent's stage percentage with a whole job. */
final class JobProgressSql
{
    public static function overall(string $alias = ''): string
    {
        if (!in_array($alias, ['', 'j'], true)) throw new \InvalidArgumentException('Unsupported job projection alias.');
        $column = static fn(string $name): string => $alias === '' ? $name : $alias . '.' . $name;
        $status = $column('status'); $progress = $column('progress'); $phases = $column('phases');
        $environment = $column('environment'); $currentPhase = $column('currentPhase');
        $percentage = "CASE WHEN $progress<0 THEN 0 WHEN $progress>100 THEN 100 ELSE COALESCE($progress,0) END";
        // HEX keeps the persisted environment prefix case-sensitive on both MySQL and SQLite.
        $cem = "HEX(SUBSTR($environment,1,4))='63656D2D'";
        $validPhase = "$currentPhase BETWEEN 1 AND 5";
        $completed = ["CASE WHEN $cem THEN CASE WHEN $status=1 THEN $percentage WHEN $validPhase THEN 100 WHEN $status IN (-2,-1) AND $currentPhase IS NULL THEN $percentage ELSE 0 END ELSE 0 END"];
        $enabled = ["CASE WHEN $cem THEN 1 ELSE 0 END"];
        for ($phase = 1; $phase <= 5; ++$phase) {
            $bit = 1 << ($phase - 1);
            $included = "($phases & $bit)=0";
            $enabled[] = "CASE WHEN $included THEN 1 ELSE 0 END";
            $completed[] = "CASE WHEN $included AND $status NOT IN (0,1) AND $validPhase THEN CASE WHEN $currentPhase>$phase THEN 100 WHEN $currentPhase=$phase THEN $percentage ELSE 0 END ELSE 0 END";
        }
        return "CASE WHEN $status=3 THEN 100 WHEN $status=0 THEN 0 ELSE COALESCE(1.0*("
            . implode('+', $completed) . ')/NULLIF((' . implode('+', $enabled) . '),0),0) END';
    }
}
