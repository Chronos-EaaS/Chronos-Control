<?php
declare(strict_types=1);

namespace Chronos\Application\Results;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;

/** Raw result browsing is independent of chart calculations and configuration files. */
final class ResultRows
{
    public const SORTS = ['id'=>'id', 'internalId'=>'internalId', 'name'=>'label', 'parameters'=>'parameters', 'result'=>'result', 'status'=>'status', 'finishedAt'=>'finishedAt'];
    private const COLUMNS = 'jobId,internalId,description,configuration,result,status,finished';

    public function __construct(private readonly Context $context) {}

    public static function jsonObject(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        try { $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : []; }
        catch (\JsonException) { return []; }
    }

    public function summary(int $evaluationId, ?array $rows = null): array
    {
        if ($rows === null) {
            $counts = $this->context->db->row('SELECT COUNT(*) AS totalJobs,COALESCE(SUM(CASE WHEN status=3 THEN 1 ELSE 0 END),0) AS finishedJobs,COALESCE(SUM(CASE WHEN status>=0 AND status<3 THEN 1 ELSE 0 END),0) AS activeJobs FROM `Job` WHERE evaluationId=?', [$evaluationId]);
            return array_map('intval', $counts);
        }
        $finished = $active = 0;
        foreach ($rows as $row) {
            $status = (int) $row['status'];
            if ($status === 3) ++$finished;
            elseif ($status >= 0 && $status < 3) ++$active;
        }
        return ['totalJobs'=>count($rows), 'finishedJobs'=>$finished, 'activeJobs'=>$active];
    }

    public function page(int $evaluationId, array $options, string $sort, int $total, ?array $rows = null): array
    {
        // The common numeric orders can page in SQL before reading large JSON
        // blobs. Natural text/JSON orders and search retain their exact existing
        // PHP semantics, including German display dates and ascending ID ties.
        $column = ['id'=>'jobId', 'internalId'=>'internalId', 'status'=>'status'][ltrim($sort, '-')] ?? null;
        if ($rows === null && $options['search'] === '' && $column !== null) {
            $page = min($options['page'], max(1, (int) ceil($total / $options['pageSize'])));
            $order = $column . (str_starts_with($sort, '-') ? ' DESC' : ' ASC') . ',jobId ASC';
            $rows = $this->context->db->rows('SELECT ' . self::COLUMNS . ' FROM `Job` WHERE evaluationId=? ORDER BY ' . $order . ' LIMIT ' . $options['pageSize'] . ' OFFSET ' . (($page - 1) * $options['pageSize']), [$evaluationId]);
            return $this->result(array_map(self::dto(...), $rows), $total, $page, $options['pageSize'], $sort);
        }
        $rows ??= $this->context->db->rows('SELECT ' . self::COLUMNS . ' FROM `Job` WHERE evaluationId=? ORDER BY internalId,jobId', [$evaluationId]);
        $raw = array_map(self::dto(...), $rows);
        if ($options['search'] !== '') {
            $needle = mb_strtolower($options['search'], 'UTF-8');
            $raw = array_values(array_filter($raw, static fn(array $job): bool => str_contains(mb_strtolower(json_encode([$job['id'], $job['internalId'], $job['label'], $job['parameters'], $job['result'], $job['status'], $job['finishedAt'], $job['finishedAt'] === null ? '' : (new \DateTimeImmutable($job['finishedAt']))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i:s')], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'UTF-8'), $needle)));
        }
        $key = self::SORTS[ltrim($sort, '-')]; $direction = str_starts_with($sort, '-') ? -1 : 1;
        $keys = [];
        foreach ($raw as $job) {
            $value = $job[$key];
            $keys[$job['id']] = in_array($key, ['parameters', 'result'], true) ? json_encode($value, JSON_UNESCAPED_UNICODE) : ($key === 'finishedAt' ? ($value === null ? PHP_INT_MIN : strtotime($value)) : $value);
        }
        usort($raw, static function (array $left, array $right) use ($keys, $direction): int {
            $a = $keys[$left['id']]; $b = $keys[$right['id']];
            $comparison = is_int($a) && is_int($b) ? $a <=> $b : strnatcasecmp((string) $a, (string) $b);
            return $direction * $comparison ?: $left['id'] <=> $right['id'];
        });
        $total = count($raw); $page = min($options['page'], max(1, (int) ceil($total / $options['pageSize'])));
        return $this->result(array_slice($raw, ($page - 1) * $options['pageSize'], $options['pageSize']), $total, $page, $options['pageSize'], $sort);
    }

    private function result(array $jobs, int $total, int $page, int $pageSize, string $sort): array
    {
        return ['jobs'=>$jobs, 'jobPage'=>['total'=>$total, 'page'=>$page, 'pageSize'=>$pageSize, 'sort'=>$sort]];
    }

    private static function dto(array $row): array
    {
        $configuration = self::jsonObject($row['configuration']);
        return ['id'=>(int) $row['jobId'], 'internalId'=>(int) $row['internalId'], 'label'=>Dto::text($row['description']), 'parameters'=>(object) ($configuration['parameters'] ?? []), 'result'=>(object) self::jsonObject($row['result']), 'status'=>(int) $row['status'], 'finishedAt'=>Dto::instant($row['finished'] ?? null), 'downloadUrl'=>'/api/ui/jobs/' . (int) $row['jobId'] . '/download'];
    }
}
