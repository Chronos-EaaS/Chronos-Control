<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Shared\Context;
use Chronos\Application\Shared\Dto;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;

/** Job transitions and private log/CDL access. */
final class JobService
{
    public function __construct(private readonly Context $context, private readonly WorkflowProjection $projection)
    {
    }

    public function list(?int $evaluationId, array $options, ?int $status, bool $active): array
    {
        [$where, $args] = $evaluationId === null ? $this->context->scope('p', $options['all']) : ['j.evaluationId=?', [$evaluationId]];
        if ($active) $where .= ' AND j.status IN (-2,0,1,2)';
        elseif ($status !== null) { $where .= ' AND j.status=?'; $args[] = $status; }
        if ($options['search'] !== '') {
            $where .= " AND (j.description LIKE ? ESCAPE '=' OR e.name LIKE ? ESCAPE '=' OR x.name LIKE ? ESCAPE '=' OR p.name LIKE ? ESCAPE '=' OR s.name LIKE ? ESCAPE '=' OR CONCAT_WS(' ',owner.firstname,owner.lastname,owner.username) LIKE ? ESCAPE '=' OR j.environment LIKE ? ESCAPE '=')";
            array_push($args, ...array_fill(0, 7, Context::search($options['search'])));
        }
        return $this->context->page(
            'FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=j.systemId LEFT JOIN `User` owner ON owner.userId=j.userId',
            $where, $args, $options, 'j.*,e.internalId evaluationInternalId,e.name evaluationName,e.experimentId,x.internalId experimentInternalId,x.projectId,x.name experimentName,p.name projectName,s.name systemName',
            fn(array $row): array => $this->projection->job($row), 'j.jobId',
        );
    }

    public function update(int $id, array $body): array
    {
        $this->context->db->transaction(function () use ($id, $body): void {
            $job = $this->context->job($id, true);
            $changes = [];
            if (array_key_exists('phases', $body)) $changes['phases'] = Input::integer($body['phases'], 'phases', 0, 31);
            if (array_key_exists('description', $body)) $changes['description'] = Dto::storedText(Input::text($body['description'], 'description', 65535, false), 'description', 65535);
            if (array_key_exists('progress', $body)) $changes['progress'] = Input::integer($body['progress'], 'progress', 0, 100);
            if (array_key_exists('status', $body)) {
                $status = WorkflowInput::jobStatus($body['status']);
                if ($status === -1 && !in_array((int) $job['status'], [-2, 0, 1, 2], true)) {
                    throw new HttpError(409, 'job_not_abortable', 'Only scheduled, setup, running or failed jobs can be aborted. Existing results were retained.');
                }
                $changes['status'] = $status;
                if ($status === 0) $changes = array_replace($changes, ['currentPhase' => null, 'progress' => 0, 'started' => null, 'finished' => null]);
                $this->context->event('job', $id, 'Job status changed', 'The job status changed from ' . $job['status'] . ' to ' . $status . '.');
            }
            $this->context->db->update('Job', 'jobId', $id, $changes);
        });
        return $this->projection->job($this->context->job($id));
    }

    public function clearLog(int $id): void
    {
        $this->context->job($id);
        $file = $this->context->root . '/webroot/data/log/' . $id . '.log';
        if (is_file($file) && file_put_contents($file, '', LOCK_EX) === false) throw new \RuntimeException('Unable to clear job log.');
        $this->context->event('job', $id, 'Job log cleared', 'The stored job log was cleared.');
    }

    public function logFile(int $id): string
    {
        $this->context->job($id);
        $file = $this->context->root . '/webroot/data/log/' . $id . '.log';
        if (!is_file($file)) throw new HttpError(404, 'not_found', 'The job log is not available.');
        return $file;
    }

    public function cdl(int $id): string
    {
        $this->context->job($id);
        return \Util::jobToCDL(\DBA\Factory::getJobFactory()->get($id));
    }

    public function log(int $id, int $offset, int $limit): array
    {
        $this->context->job($id);
        $file = $this->context->root . '/webroot/data/log/' . $id . '.log';
        $size = is_file($file) ? filesize($file) : 0;
        // Another browser or the agent may truncate a log between polls.
        if ($offset > $size) $offset = 0;
        $text = '';
        if ($size && $offset < $size) {
            $handle = fopen($file, 'rb');
            if ($handle === false) throw new \RuntimeException('Unable to read job log.');
            try {
                fseek($handle, $offset);
                $bytes = (string) fread($handle, $limit + 4);
                $text = mb_strcut($bytes, 0, $limit, 'UTF-8');
                // A limit smaller than the first code point still makes progress.
                if ($text === '' && $bytes !== '') {
                    for ($length = 2; $length <= 4 && $text === ''; $length++) $text = mb_strcut($bytes, 0, $length, 'UTF-8');
                }
            } finally { fclose($handle); }
        }
        $next = $offset + strlen($text);
        return ['text' => $text, 'offset' => $offset, 'nextOffset' => $next, 'size' => $size, 'truncated' => $next < $size, 'complete' => $next >= $size];
    }
}
