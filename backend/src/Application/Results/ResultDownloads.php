<?php
declare(strict_types=1);

namespace Chronos\Application\Results;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Application\Workflows\JobSpecifications;
use Chronos\Infrastructure\Agents\AttemptFiles;
use Chronos\Http\HttpError;

final class ResultDownloads
{
    public function __construct(private readonly Context $context) {}

    public function evaluation(int $id): PreparedDownload { return $this->archive($this->context->evaluation($id), false); }
    public function job(int $id): PreparedDownload { return $this->archive($this->context->job($id), true); }
    public function evaluationCsv(int $id): PreparedDownload
    {
        $this->context->evaluation($id);
        return $this->csv($this->context->db->rows('SELECT j.*,a.attemptId FROM `Job` j LEFT JOIN JobAttempt a ON a.jobId=j.jobId AND a.selected=1 WHERE j.evaluationId=? ORDER BY j.internalId,j.jobId', [$id]), 'evaluation' . $id . '.csv');
    }
    public function jobCsv(int $id): PreparedDownload { return $this->csv([$this->context->job($id)], 'job' . $id . '.csv'); }

    private function archive(array $resource, bool $single): PreparedDownload
    {
        $rows = $single ? [$resource] : $this->context->db->rows('SELECT j.jobId,j.status,s.adapter,a.attemptId,a.archive FROM `Job` j
            LEFT JOIN JobSpecification s ON s.jobId=j.jobId LEFT JOIN JobAttempt a ON a.jobId=j.jobId AND a.selected=1
            WHERE j.evaluationId=? ORDER BY j.internalId,j.jobId', [(int) $resource['evaluationId']]);
        $data = defined('UPLOADED_DATA_PATH') ? rtrim(UPLOADED_DATA_PATH, '/') : $this->context->root . '/webroot/data';
        $paths = [];
        foreach ($rows as $row) {
            if ((int) $row['status'] !== 3) throw new HttpError(409, 'results_incomplete', 'All jobs must be finished before downloading their result archives.');
            $jobId = (int) $row['jobId'];
            $managed = $single ? JobSpecifications::managed(ResultRows::jsonObject($row['configuration'])) : isset($row['adapter']);
            if ($managed) {
                $attempt = $single ? (new JobSpecifications($this->context->db))->selectedAttempt($jobId) : $row;
                if (!isset($attempt['archive'])) throw new HttpError(404, 'archive_unavailable', 'The selected attempt has no result archive.');
                $paths[$jobId . '.zip'] = AttemptFiles::readableArchive($data, (int) $attempt['attemptId'], ResultRows::jsonObject($attempt['archive']));
                continue;
            }
            $base = realpath($data . '/evaluation');
            $file = $data . '/evaluation/' . $jobId . '.zip';
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
            $configuration = ResultRows::jsonObject($row['configuration']);
            $managed = JobSpecifications::managed($configuration);
            if ($managed) {
                $record['repetitionId'] = (int) $row['jobId'];
                $attemptId = array_key_exists('attemptId', $row) ? $row['attemptId']
                    : ((new JobSpecifications($this->context->db))->selectedAttempt((int) $row['jobId'])['attemptId'] ?? null);
                $record['selectedAttemptId'] = $attemptId === null ? null : (int) $attemptId;
            }
            foreach ($configuration['parameters'] ?? [] as $key => $value) $record['parameter.' . $key] = $value;
            foreach (ResultRows::regularResult($row, $managed) as $key => $value) $record['result.' . $key] = $value;
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
