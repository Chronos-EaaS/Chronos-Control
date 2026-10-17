<?php
declare(strict_types=1);

namespace Chronos\Application\Results;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;

final class ResultDownloads
{
    public function __construct(private readonly Context $context) {}

    public function evaluation(int $id): PreparedDownload { return $this->archive($this->context->evaluation($id), false); }
    public function job(int $id): PreparedDownload { return $this->archive($this->context->job($id), true); }
    public function evaluationCsv(int $id): PreparedDownload
    {
        $this->context->evaluation($id);
        return $this->csv($this->context->db->rows('SELECT * FROM `Job` WHERE evaluationId=? ORDER BY internalId,jobId', [$id]), 'evaluation' . $id . '.csv');
    }
    public function jobCsv(int $id): PreparedDownload { return $this->csv([$this->context->job($id)], 'job' . $id . '.csv'); }

    private static function jsonObject(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        try { $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : []; }
        catch (\JsonException) { return []; }
    }

    private function archive(array $resource, bool $single): PreparedDownload
    {
        $rows = $single ? [$resource] : $this->context->db->rows('SELECT jobId,status FROM `Job` WHERE evaluationId=? ORDER BY internalId,jobId', [(int) $resource['evaluationId']]);
        $paths = [];
        foreach ($rows as $row) {
            if ((int) $row['status'] !== 3) throw new HttpError(409, 'results_incomplete', 'All jobs must be finished before downloading their result archives.');
            $base = realpath(UPLOADED_DATA_PATH . 'evaluation');
            $file = UPLOADED_DATA_PATH . 'evaluation/' . (int) $row['jobId'] . '.zip';
            if ($base === false || !is_file($file) || is_link($file) || dirname(realpath($file) ?: '') !== $base) throw new HttpError(404, 'archive_unavailable', 'A result archive is unavailable.');
            $paths[(int) $row['jobId'] . '.zip'] = $file;
        }
        if ($single) return new PreparedDownload('application/zip', 'job' . (int) $resource['jobId'] . '.zip', static function () use ($paths): void { readfile(reset($paths)); });
        return PreparedDownload::zip('evaluation' . (int) $resource['evaluationId'] . '.zip', $paths);
    }

    private function csv(array $rows, string $filename): PreparedDownload
    {
        $records = []; $columns = ['jobId', 'internalId', 'status', 'finishedAt'];
        foreach ($rows as $row) {
            $record = ['jobId' => (int) $row['jobId'], 'internalId' => (int) $row['internalId'], 'status' => (int) $row['status'], 'finishedAt' => Dto::instant($row['finished'] ?? null)];
            foreach (self::jsonObject($row['configuration'])['parameters'] ?? [] as $key => $value) $record['parameter.' . $key] = $value;
            foreach (self::jsonObject($row['result']) as $key => $value) $record['result.' . $key] = $value;
            $columns = array_values(array_unique([...$columns, ...array_keys($record)])); $records[] = $record;
        }
        return new PreparedDownload('text/csv; charset=utf-8', $filename, static function () use ($records, $columns): void {
            $out = fopen('php://output', 'wb'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';', '"', '', "\r\n");
            foreach ($records as $record) {
                $cells = [];
                foreach ($columns as $column) {
                    $value = $record[$column] ?? '';
                    if ($column === 'finishedAt' && $value !== '') $value = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i:s');
                    if (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    elseif (is_bool($value)) $value = $value ? 'true' : 'false';
                    elseif (is_float($value)) $value = str_replace('.', ',', (string) $value);
                    if (is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value)) $value = "'" . $value;
                    $cells[] = $value;
                }
                fputcsv($out, $cells, ';', '"', '', "\r\n");
            }
            fclose($out);
        });
    }
}
