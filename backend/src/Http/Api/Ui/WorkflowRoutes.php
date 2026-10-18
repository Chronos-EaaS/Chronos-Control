<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

use Chronos\Http\JsonResponse;

use Chronos\Http\Request;
use Chronos\Application\Shared\Input;
use Chronos\Application\Shared\Context;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

use Chronos\Application\Workflows\EvaluationService;
use Chronos\Application\Workflows\ExperimentDefinition;
use Chronos\Application\Workflows\ExperimentService;
use Chronos\Application\Workflows\JobService;
use Chronos\Application\Workflows\WorkflowEnvironment;
use Chronos\Application\Workflows\WorkflowInput;
use Chronos\Application\Workflows\WorkflowProjection;

/** HTTP methods, request decoding and response envelopes for workflow operations. */
final class WorkflowRoutes
{
    private readonly Context $context;
    private readonly WorkflowProjection $projection;
    private readonly ExperimentDefinition $definition;
    private readonly WorkflowEnvironment $environment;
    private readonly ExperimentService $experiments;
    private readonly EvaluationService $evaluations;
    private readonly JobService $jobs;

    public function __construct(private readonly Request $request, Store $db, array $actor, string $root, Sessions $sessions)
    {
        $this->context = new Context($db, $actor, $root);
        $this->projection = new WorkflowProjection($this->context);
        $this->definition = new ExperimentDefinition($root);
        $this->environment = new WorkflowEnvironment($this->context, $this->definition);
        $this->experiments = new ExperimentService($this->context, $this->projection, $this->definition, $this->environment);
        $this->evaluations = new EvaluationService($this->context, $this->projection, $this->definition, $this->environment);
        $this->jobs = new JobService($this->context, $this->projection);
    }

    public function handle(string $path): ?array
    {
        if (preg_match('#^/projects/([1-9][0-9]*)/(experiments|experiment-options|evaluations|events)$#D', $path, $match)) {
            $id = Input::id($match[1], 'projectId');
            $project = $this->context->project($id);
            switch ($match[2]) {
                case 'experiments':
                    $this->request->assertMethod(['GET', 'POST']);
                    if ($this->request->method === 'GET') return JsonResponse::success($this->experiments->list($project, $this->context->options($this->request->query, ['id' => 'x.experimentId', 'name' => 'x.name', 'createdAt' => 'x.created'])));
                    $body = $this->body(['name', 'description', 'runs', 'distribution', 'warmUp', 'environment', 'parameters', 'sourceExperimentId']);
                    return JsonResponse::success($this->experiments->create($project, $body), 201);
                case 'experiment-options':
                    $this->request->assertMethod(['GET']);
                    Input::fields($this->request->query, ['copy']);
                    return JsonResponse::success($this->experiments->options($project, isset($this->request->query['copy']) ? Input::id($this->request->query['copy'], 'copy') : null));
                case 'evaluations':
                    $this->request->assertMethod(['GET']);
                    return JsonResponse::success($this->evaluationList('project', $id));
                case 'events': return $this->events('project', $id);
            }
        }
        if (preg_match('#^/experiments/([1-9][0-9]*)(?:/(evaluations|run-options|events|parameters))?$#D', $path, $match)) {
            $id = Input::id($match[1], 'experimentId');
            $experiment = $this->context->experiment($id);
            switch ($match[2] ?? '') {
                case '':
                    $this->request->assertMethod(['GET', 'PATCH']);
                    return JsonResponse::success($this->request->method === 'GET' ? $this->projection->experiment($experiment)
                        : $this->experiments->update($id, $this->body(['name', 'description', 'archived', 'resultId', 'defaultEnvironment'])));
                case 'evaluations':
                    $this->request->assertMethod(['GET', 'POST']);
                    return $this->request->method === 'GET' ? JsonResponse::success($this->evaluationList('experiment', $id))
                        : JsonResponse::success($this->evaluations->run($id, $this->body(['name', 'description', 'environment', 'versions'])), 201);
                case 'parameters':
                    $this->request->assertMethod(['GET']);
                    return JsonResponse::success(['groups' => $this->definition->groups($experiment), 'values' => $this->definition->values($experiment)]);
                case 'run-options':
                    $this->request->assertMethod(['GET']);
                    return JsonResponse::success($this->environment->options($experiment));
                case 'events': return $this->events('experiment', $id);
            }
        }
        if (preg_match('#^/evaluations/([1-9][0-9]*)(?:/(jobs|events|actions))?$#D', $path, $match)) {
            $id = Input::id($match[1], 'evaluationId');
            $evaluation = $this->context->evaluation($id);
            switch ($match[2] ?? '') {
                case '':
                    $this->request->assertMethod(['GET', 'PATCH']);
                    return JsonResponse::success($this->request->method === 'GET' ? $this->projection->evaluation($evaluation)
                        : $this->evaluations->update($id, $this->body(['name', 'description', 'archived', 'starred'])));
                case 'jobs':
                    $this->request->assertMethod(['GET']);
                    return JsonResponse::success($this->jobList($id));
                case 'actions':
                    $this->request->assertMethod(['POST']);
                    $action = $this->body(['action'])['action'] ?? null;
                    if (!in_array($action, ['abort', 'reschedule'], true)) Input::invalid('action', 'Choose abort or reschedule.');
                    return JsonResponse::success($this->evaluations->action($id, $action));
                case 'events': return $this->events('evaluation', $id);
            }
        }
        if (preg_match('#^/jobs/([1-9][0-9]*)(?:/(log|log/download|events|cdl))?$#D', $path, $match)) {
            $id = Input::id($match[1], 'jobId');
            $job = $this->context->job($id);
            switch ($match[2] ?? '') {
                case '':
                    $this->request->assertMethod(['GET', 'PATCH']);
                    return JsonResponse::success($this->request->method === 'GET' ? $this->projection->job($job)
                        : $this->jobs->update($id, $this->body(['description', 'status', 'progress', 'phases'])));
                case 'log':
                    $this->request->assertMethod(['GET', 'DELETE']);
                    Input::fields($this->request->query, ['offset', 'limit']);
                    $offset = Input::integer($this->request->query['offset'] ?? '0', 'offset', 0, 2147483647);
                    $limit = Input::integer($this->request->query['limit'] ?? '262144', 'limit', 1, 1048576);
                    if ($this->request->method === 'DELETE') $this->jobs->clearLog($id);
                    return JsonResponse::success($this->jobs->log($id, $offset, $limit));
                case 'log/download':
                    $this->request->assertMethod(['GET']);
                    $file = $this->jobs->logFile($id);
                    return ['status' => 200, 'headers' => ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="job-' . $id . '.log"'],
                        'stream' => static function () use ($file): void { readfile($file); }];
                case 'cdl':
                    $this->request->assertMethod(['GET']);
                    return JsonResponse::success(['xml' => $this->jobs->cdl($id)]);
                case 'events': return $this->events('job', $id);
            }
        }
        if ($path === '/jobs' || $path === '/evaluations' || $path === '/status') {
            $this->request->assertMethod(['GET']);
            if ($path === '/jobs') return JsonResponse::success($this->jobList());
            if ($path === '/evaluations') return JsonResponse::success($this->evaluationList());
            Input::fields($this->request->query, ['all']);
            return JsonResponse::success($this->projection->status(Input::queryBoolean($this->request->query['all'] ?? 'false', 'all')));
        }
        return null;
    }

    private function body(array $fields): array
    {
        $body = $this->request->json();
        Input::fields($body, $fields);
        return $body;
    }

    private function events(string $type, int $id): array
    {
        $this->request->assertMethod(['GET']);
        return JsonResponse::success($this->projection->events($type, $id, $this->context->options($this->request->query, ['id' => 'v.eventId', 'at' => 'v.time'], '-id')));
    }

    private function evaluationList(?string $parent = null, ?int $id = null): array
    {
        $query = $this->request->query;
        $state = $query['state'] ?? 'all'; unset($query['state']);
        if (!in_array($state, ['all', 'running', 'starred', 'running-or-starred', 'completed'], true)) Input::invalid('state', 'Choose a supported evaluation state.');
        $options = $this->context->options($query, ['id' => 'e.evaluationId', 'hierarchy' => "CONCAT(LPAD(x.internalId,10,'0'),'.',LPAD(e.internalId,10,'0'))", 'internalId' => 'e.internalId', 'name' => 'e.name', 'description' => 'e.description', 'experiment' => 'x.name', 'experimentInternalId' => 'x.internalId', 'project' => 'p.name', 'system' => 's.name', 'starred' => 'e.isStarred', 'status' => '(SELECT COUNT(*) FROM `Job` statusJobs WHERE statusJobs.evaluationId=e.evaluationId AND statusJobs.status IN (-2,0,1,2))']);
        return $this->evaluations->list($parent, $id, $options, $state);
    }

    private function jobList(?int $id = null): array
    {
        $query = $this->request->query;
        $status = $query['status'] ?? null; unset($query['status']);
        $options = $this->context->options($query, ['id' => 'j.jobId', 'internalId' => 'j.internalId', 'status' => 'j.status', 'progress' => 'j.progress', 'description' => 'j.description', 'createdAt' => 'j.created', 'phase' => 'j.currentPhase', 'evaluation' => 'e.name', 'experiment' => 'x.name', 'project' => 'p.name', 'system' => 's.name', 'owner' => "CONCAT_WS(' ',owner.firstname,owner.lastname,owner.username)", 'type' => "(j.environment LIKE 'cem-%')"], 'internalId');
        $active = $status === 'active';
        $names = ['failed' => -2, 'aborted' => -1, 'scheduled' => 0, 'setup' => 1, 'running' => 2, 'finished' => 3];
        $filter = $status === null || $status === 'all' || $active ? null
            : (is_string($status) && isset($names[$status]) ? $names[$status] : WorkflowInput::jobStatus($status));
        return $this->jobs->list($id, $options, $filter, $active);
    }
}
