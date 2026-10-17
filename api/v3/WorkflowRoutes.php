<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

/** Project-scoped experiment, evaluation and job operations. */
final class WorkflowRoutes
{
    private readonly Context $context;

    public function __construct(private readonly Request $request, private readonly Store $db, private readonly array $actor, private readonly string $root, private readonly Sessions $sessions)
    {
        $this->context = new Context($db, $actor, $root);
    }

    public function handle(string $path): ?array
    {
        if (preg_match('#^/projects/([1-9][0-9]*)/(experiments|experiment-options|evaluations|events)$#D', $path, $m)) {
            $id = Input::id($m[1], 'projectId');
            $project = $this->context->project($id);
            if ($m[2] === 'experiments') {
                $this->request->assertMethod(['GET', 'POST']);
                return Context::success($this->request->method === 'GET' ? $this->experiments($project) : $this->createExperiment($project), $this->request->method === 'POST' ? 201 : 200);
            }
            $this->request->assertMethod(['GET']);
            return Context::success(match ($m[2]) {
                'experiment-options' => $this->experimentOptions($project),
                'evaluations' => $this->evaluations('project', $id),
                'events' => $this->events('project', $id),
            });
        }
        if (preg_match('#^/experiments/([1-9][0-9]*)(?:/(evaluations|run-options|events|parameters))?$#D', $path, $m)) {
            $id = Input::id($m[1], 'experimentId');
            $row = $this->context->experiment($id);
            $suffix = $m[2] ?? '';
            if ($suffix === '') {
                $this->request->assertMethod(['GET', 'PATCH']);
                return Context::success($this->request->method === 'GET' ? $this->experimentDto($row) : $this->updateExperiment($id));
            }
            if ($suffix === 'evaluations') {
                $this->request->assertMethod(['GET', 'POST']);
                return Context::success($this->request->method === 'GET' ? $this->evaluations('experiment', $id) : $this->run($id), $this->request->method === 'POST' ? 201 : 200);
            }
            if ($suffix === 'parameters') {
                $this->request->assertMethod(['GET']);
                $data = $this->postData($row);
                return Context::success(['groups' => $this->groups($row), 'values' => $data['_v3Values'] ?? $data]);
            }
            $this->request->assertMethod(['GET']);
            return Context::success($suffix === 'events' ? $this->events('experiment', $id) : $this->runOptions($row));
        }
        if (preg_match('#^/evaluations/([1-9][0-9]*)(?:/(jobs|events|actions))?$#D', $path, $m)) {
            $id = Input::id($m[1], 'evaluationId');
            $row = $this->context->evaluation($id);
            $suffix = $m[2] ?? '';
            if ($suffix === '') {
                $this->request->assertMethod(['GET', 'PATCH']);
                return Context::success($this->request->method === 'GET' ? $this->evaluationDto($row) : $this->updateEvaluation($id));
            }
            if ($suffix === 'actions') {
                $this->request->assertMethod(['POST']);
                return Context::success($this->evaluationAction($id));
            }
            $this->request->assertMethod(['GET']);
            return Context::success($suffix === 'events' ? $this->events('evaluation', $id) : $this->jobs($id));
        }
        if (preg_match('#^/jobs/([1-9][0-9]*)(?:/(log|log/download|events|cdl))?$#D', $path, $m)) {
            $id = Input::id($m[1], 'jobId');
            $row = $this->context->job($id);
            if (isset($m[2])) {
                $this->request->assertMethod($m[2] === 'log' ? ['GET','DELETE'] : ['GET']);
                if ($m[2] === 'log/download') {
                    $file=$this->root.'/webroot/data/log/'.$id.'.log';
                    if(!is_file($file)){throw new HttpError(404,'not_found','The job log is not available.');}
                    return ['status'=>200,'headers'=>['Content-Type'=>'text/plain; charset=utf-8','Content-Disposition'=>'attachment; filename="job-'.$id.'.log"'],'stream'=>static function()use($file):void{readfile($file);}];
                }
                if ($m[2] === 'cdl') {
                    return Context::success(['xml' => \Util::jobToCDL(\DBA\Factory::getJobFactory()->get($id))]);
                }
                if ($m[2] === 'log' && $this->request->method === 'DELETE') {
                    $file = $this->root . '/webroot/data/log/' . $id . '.log';
                    if (is_file($file) && file_put_contents($file, '', LOCK_EX) === false) { throw new \RuntimeException('Unable to clear job log.'); }
                    $this->context->event('job',$id,'Job log cleared','The stored job log was cleared.');
                }
                return Context::success($m[2] === 'events' ? $this->events('job', $id) : $this->log($id));
            }
            $this->request->assertMethod(['GET', 'PATCH']);
            return Context::success($this->request->method === 'GET' ? $this->jobDto($row) : $this->updateJob($id));
        }
        if ($path === '/jobs') {
            $this->request->assertMethod(['GET']);
            return Context::success($this->jobs());
        }
        if ($path === '/evaluations') {
            $this->request->assertMethod(['GET']);
            return Context::success($this->evaluations());
        }
        if ($path === '/status') {
            $this->request->assertMethod(['GET']);
            return Context::success($this->status());
        }
        return null;
    }

    private function experimentDto(array $row): array
    {
        return [
            'id' => (int) $row['experimentId'], 'name' => Dto::text($row['name']), 'description' => Dto::text($row['description']),
            'projectId' => (int) $row['projectId'], 'projectName' => Dto::text($row['projectName'] ?? ''),
            'systemId' => (int) $row['systemId'], 'systemName' => Dto::text($row['systemName'] ?? ''),
            'internalId' => (int) $row['internalId'], 'archived' => (bool) $row['isArchived'], 'createdAt' => Dto::instant($row['created']),
            'resultId' => $row['resultId'], 'defaultEnvironment' => $row['defaultEnvironment'], 'phases' => (int) $row['phases'], 'status' => (int) $row['status'],
            'permissions' => ['canEdit' => true, 'canArchive' => true, 'canRun' => !(bool) $row['isArchived'], 'canCopy' => true],
        ];
    }

    private function counts(int $id): array
    {
        $row = $this->db->row('SELECT COUNT(*) total,COALESCE(SUM(status=3),0) finished,COALESCE(SUM(status=-2),0) failed,COALESCE(SUM(status IN (0,1,2)),0) active,COALESCE(SUM(status=-1),0) aborted,COALESCE(AVG(CASE WHEN status=3 THEN 100 WHEN status=-1 THEN 100 ELSE progress END),0) progress FROM `Job` WHERE evaluationId=?', [$id]);
        return array_map(static fn(mixed $value): int => (int) $value, $row);
    }

    private function evaluationDto(array $row): array
    {
        $counts = $this->counts((int) $row['evaluationId']);
        $environments = array_map(static fn(array $value): string => Dto::text($value['environment']), $this->db->rows('SELECT DISTINCT environment FROM `Job` WHERE evaluationId=? ORDER BY environment', [(int) $row['evaluationId']]));
        $progress = $counts['progress'];
        unset($counts['progress']);
        return [
            'id' => (int) $row['evaluationId'], 'name' => Dto::text($row['name']), 'description' => Dto::text($row['description']),
            'experimentId' => (int) $row['experimentId'], 'experimentInternalId' => (int) ($row['experimentInternalId'] ?? 0), 'experimentName' => Dto::text($row['experimentName'] ?? ''),
            'projectId' => (int) $row['projectId'], 'projectName' => Dto::text($row['projectName'] ?? ''),
            'systemId' => (int) $row['systemId'], 'systemName' => Dto::text($row['systemName'] ?? ''),
            'internalId' => (int) $row['internalId'], 'archived' => (bool) $row['isArchived'], 'starred' => (bool) $row['isStarred'],
            'resultId' => $row['resultId'], 'counts' => $counts, 'progress' => $progress, 'environment' => implode(', ', $environments), 'environments' => $environments,
            'permissions' => ['canEdit' => true, 'canArchive' => $counts['active'] + $counts['failed'] === 0, 'canAbort' => $counts['active'] + $counts['failed'] > 0, 'canReschedule' => $counts['aborted'] + $counts['failed'] > 0, 'canDownload' => $counts['total'] > 0 && $counts['finished'] === $counts['total']],
        ];
    }

    private function jobDto(array $row): array
    {
        $node=$this->db->row('SELECT nodeId,hostname,lastUpdate FROM `Node` WHERE currentJob=? ORDER BY lastUpdate DESC LIMIT 1',[(int)$row['jobId']]);
        $owner=$this->context->user((int)$row['userId']);$system=$this->db->row('SELECT cem FROM `System` WHERE systemId=?',[(int)$row['systemId']]);
        $previous=$this->db->row('SELECT jobId FROM `Job` WHERE evaluationId=? AND (internalId<? OR (internalId=? AND jobId<?)) ORDER BY internalId DESC,jobId DESC LIMIT 1',[$row['evaluationId'],$row['internalId'],$row['internalId'],$row['jobId']]);
        $next=$this->db->row('SELECT jobId FROM `Job` WHERE evaluationId=? AND (internalId>? OR (internalId=? AND jobId>?)) ORDER BY internalId,jobId LIMIT 1',[$row['evaluationId'],$row['internalId'],$row['internalId'],$row['jobId']]);
        $lastContact=$node ? Dto::instant($node['lastUpdate']) : null;
        $clientStatus=$node ? (strtotime($node['lastUpdate']) < time()-max(1,(int)$this->context->setting(0,'cem','nodeMissingTimeout','300')) ? 'missing' : 'connected') : 'unassigned';
        return [
            'owner' => array_intersect_key(Dto::user($owner),array_flip(['id','username','firstName','lastName'])), 'cem'=>(bool)($system['cem']??false), 'setupRequired'=>(bool)($system['cem']??false),
            'previousId'=>$previous?(int)$previous['jobId']:null,'nextId'=>$next?(int)$next['jobId']:null,
            'id' => (int) $row['jobId'], 'internalId' => (int) $row['internalId'], 'description' => Dto::text($row['description']),
            'evaluationId' => (int) $row['evaluationId'], 'evaluationInternalId' => (int) ($row['evaluationInternalId'] ?? 0), 'evaluationName' => Dto::text($row['evaluationName'] ?? ''),
            'experimentId' => (int) $row['experimentId'], 'experimentInternalId' => (int) ($row['experimentInternalId'] ?? 0), 'experimentName' => Dto::text($row['experimentName'] ?? ''),
            'projectId' => (int) $row['projectId'], 'projectName' => Dto::text($row['projectName'] ?? ''),
            'systemId' => (int) $row['systemId'], 'systemName' => Dto::text($row['systemName'] ?? ''),
            'environment' => $row['environment'], 'client' => $node ? ($node['hostname'] ?: $node['nodeId']) : '', 'clientStatus' => $clientStatus, 'lastContactAt' => $lastContact, 'status' => (int) $row['status'],
            'statusName' => [-2 => 'failed', -1 => 'aborted', 0 => 'scheduled', 1 => 'setup', 2 => 'running', 3 => 'finished'][(int) $row['status']] ?? 'unknown',
            'progress' => (int) $row['progress'], 'phases' => (int) $row['phases'], 'currentPhase' => $row['currentPhase'] === null ? null : (int) $row['currentPhase'],
            'configuration' => json_decode($row['configuration'], true) ?? [], 'createdAt' => Dto::instant($row['created']), 'startedAt' => Dto::instant($row['started']), 'finishedAt' => Dto::instant($row['finished']),
            'permissions' => ['canEdit' => true, 'canAbort' => in_array((int) $row['status'], [-2,0,1,2], true), 'canReschedule' => true, 'canDownload' => (int) $row['status'] === 3 && is_file($this->root . '/webroot/data/evaluation/' . (int) $row['jobId'] . '.zip')],
        ];
    }

    private function experiments(array $project): array
    {
        $options = $this->context->options($this->request->query, ['id' => 'x.experimentId', 'name' => 'x.name', 'createdAt' => 'x.created']);
        $where = 'x.projectId=?'; $args = [$project['projectId']];
        $this->filters($options, 'x', $where, $args);
        return $this->context->page('FROM `Experiment` x JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=x.systemId', $where, $args, $options, 'x.*,p.name projectName,s.name systemName', fn(array $row): array => $this->experimentDto($row), 'x.experimentId');
    }

    private function filters(array $options, string $alias, string &$where, array &$args): void
    {
        if ($options['archived'] !== 'all') {
            $where .= ' AND ' . $alias . '.isArchived=?'; $args[] = $options['archived'] === 'true' ? 1 : 0;
        }
        if ($options['search'] !== '') {
            $where .= ' AND (' . $alias . ".name LIKE ? ESCAPE '=' OR " . $alias . ".description LIKE ? ESCAPE '=')";
            array_push($args, Context::search($options['search']), Context::search($options['search']));
        }
    }

    private function evaluations(?string $parent = null, ?int $id = null): array
    {
        $query = $this->request->query;
        $state = $query['state'] ?? 'all'; unset($query['state']);
        if (!in_array($state, ['all', 'running', 'starred', 'running-or-starred', 'completed'], true)) { Input::invalid('state', 'Choose a supported evaluation state.'); }
        $options = $this->context->options($query, ['id' => 'e.evaluationId', 'hierarchy' => "CONCAT(LPAD(x.internalId,10,'0'),'.',LPAD(e.internalId,10,'0'))", 'internalId' => 'e.internalId', 'name' => 'e.name', 'description' => 'e.description', 'experiment' => 'x.name', 'experimentInternalId' => 'x.internalId', 'project' => 'p.name', 'system' => 's.name', 'starred' => 'e.isStarred', 'status' => '(SELECT COUNT(*) FROM `Job` statusJobs WHERE statusJobs.evaluationId=e.evaluationId AND statusJobs.status IN (-2,0,1,2))']);
        [$where,$args] = $parent === null ? $this->context->scope('p', $options['all']) : [$parent === 'project' ? 'p.projectId=?' : 'x.experimentId=?', [$id]];
        $filterOptions = $options; $filterOptions['search'] = ''; $this->filters($filterOptions, 'e', $where, $args);
        if ($options['search'] !== '') { $where .= " AND (e.name LIKE ? ESCAPE '=' OR e.description LIKE ? ESCAPE '=' OR x.name LIKE ? ESCAPE '=' OR p.name LIKE ? ESCAPE '=' OR s.name LIKE ? ESCAPE '=')"; array_push($args, ...array_fill(0, 5, Context::search($options['search']))); }
        $running = 'EXISTS (SELECT 1 FROM `Job` running WHERE running.evaluationId=e.evaluationId AND running.status IN (-2,0,1,2))';
        $where .= match ($state) {'running' => ' AND ' . $running, 'starred' => ' AND e.isStarred=1', 'running-or-starred' => ' AND (' . $running . ' OR e.isStarred=1)', 'completed' => ' AND NOT ' . $running, default => ''};
        return $this->context->page('FROM `Evaluation` e JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=e.systemId', $where, $args, $options, 'e.*,x.projectId,x.internalId experimentInternalId,x.name experimentName,x.resultId,p.name projectName,s.name systemName', fn(array $row): array => $this->evaluationDto($row), 'e.evaluationId');
    }

    private function jobs(?int $id = null): array
    {
        $query = $this->request->query;
        $status = $query['status'] ?? null; unset($query['status']);
        $options = $this->context->options($query, ['id' => 'j.jobId', 'internalId' => 'j.internalId', 'status' => 'j.status', 'progress' => 'j.progress', 'description' => 'j.description', 'createdAt' => 'j.created', 'phase' => 'j.currentPhase', 'evaluation' => 'e.name', 'experiment' => 'x.name', 'project' => 'p.name', 'system' => 's.name', 'owner' => "CONCAT_WS(' ',owner.firstname,owner.lastname,owner.username)", 'type' => "(j.environment LIKE 'cem-%')"], 'internalId');
        [$where,$args] = $id === null ? $this->context->scope('p',$options['all']) : ['j.evaluationId=?',[$id]];
        if ($status === 'active') { $where .= ' AND j.status IN (-2,0,1,2)'; }
        elseif ($status !== null && $status !== 'all') { $where .= ' AND j.status=?'; $names=['failed'=>-2,'aborted'=>-1,'scheduled'=>0,'setup'=>1,'running'=>2,'finished'=>3]; $args[] = is_string($status)&&isset($names[$status])?$names[$status]:$this->jobStatus($status); }
        if ($options['search'] !== '') {
            $where .= " AND (j.description LIKE ? ESCAPE '=' OR e.name LIKE ? ESCAPE '=' OR x.name LIKE ? ESCAPE '=' OR p.name LIKE ? ESCAPE '=' OR s.name LIKE ? ESCAPE '=' OR CONCAT_WS(' ',owner.firstname,owner.lastname,owner.username) LIKE ? ESCAPE '=' OR j.environment LIKE ? ESCAPE '=')";
            array_push($args, ...array_fill(0, 7, Context::search($options['search'])));
        }
        return $this->context->page('FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=j.systemId LEFT JOIN `User` owner ON owner.userId=j.userId', $where, $args, $options, 'j.*,e.internalId evaluationInternalId,e.name evaluationName,e.experimentId,x.internalId experimentInternalId,x.projectId,x.name experimentName,p.name projectName,s.name systemName', fn(array $row): array => $this->jobDto($row), 'j.jobId');
    }

    private function events(string $type, int $id): array
    {
        $options = $this->context->options($this->request->query, ['id' => 'v.eventId', 'at' => 'v.time'], '-id');
        $where = match ($type) {
            'project' => "((v.eventType='project' AND v.relatedId=?) OR (v.eventType='experiment' AND v.relatedId IN (SELECT experimentId FROM `Experiment` WHERE projectId=?)) OR (v.eventType='evaluation' AND v.relatedId IN (SELECT e.evaluationId FROM `Evaluation` e JOIN `Experiment` x ON x.experimentId=e.experimentId WHERE x.projectId=?)) OR (v.eventType='job' AND v.relatedId IN (SELECT j.jobId FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId WHERE x.projectId=?)))",
            'experiment' => "((v.eventType='experiment' AND v.relatedId=?) OR (v.eventType='evaluation' AND v.relatedId IN (SELECT evaluationId FROM `Evaluation` WHERE experimentId=?)) OR (v.eventType='job' AND v.relatedId IN (SELECT j.jobId FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId WHERE e.experimentId=?)))",
            'evaluation' => "((v.eventType='evaluation' AND v.relatedId=?) OR (v.eventType='job' AND v.relatedId IN (SELECT jobId FROM `Job` WHERE evaluationId=?)))",
            'job' => "v.eventType='job' AND v.relatedId=?",
        };
        $args = array_fill(0, substr_count($where, '?'), $id);
        if ($options['search'] !== '') { $where .= " AND (v.title LIKE ? ESCAPE '=' OR v.eventText LIKE ? ESCAPE '=')"; array_push($args, Context::search($options['search']), Context::search($options['search'])); }
        return $this->context->page('FROM `Event` v LEFT JOIN `User` u ON u.userId=v.userId', $where, $args, $options, 'v.*,u.firstname actorFirstName,u.lastname actorLastName', (new EventProjection($this->context))->event(...), 'v.eventId');
    }

    private function updateExperiment(int $id): array
    {
        $body = $this->request->json(); Input::fields($body, ['name','description','archived','resultId','defaultEnvironment']);
        $this->db->transaction(function () use ($id,$body): void {
            $row = $this->context->experiment($id, true); $changes = $this->metadata($body, 50);
            if (array_key_exists('archived',$body)) {
                $archive = Input::boolean($body['archived'],'archived');
                if ($archive && $this->db->row('SELECT j.jobId FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId WHERE e.experimentId=? AND j.status IN (-2,0,1,2) LIMIT 1',[$id])) { throw new HttpError(409,'evaluation_running','Finish or abort the active evaluations before archiving this experiment.'); }
                $changes['isArchived'] = (int) $archive;
            }
            if (array_key_exists('resultId',$body)) {
                $result = Input::text($body['resultId'],'resultId',50,false);
                $config = SystemRoutes::resultDocument($this->root, (int) $row['systemId']);
                if ($result !== '' && (!isset($config['elements'][$result]) || (!str_starts_with($result,'system-') && !str_starts_with($result,'experiment-' . $id . '-')))) { Input::invalid('resultId','Choose an available result configuration.'); }
                $changes['resultId'] = $result;
            }
            if (array_key_exists('defaultEnvironment',$body)) { $this->assertEnvironment($row,Input::text($body['defaultEnvironment'],'defaultEnvironment',100)); $changes['defaultEnvironment']=$body['defaultEnvironment']; }
            $this->db->update('Experiment','experimentId',$id,$changes); $this->context->event('experiment',$id,'Experiment updated','The experiment settings were updated.');
        });
        return $this->experimentDto($this->context->experiment($id));
    }

    private function metadata(array $body, int $nameMax = 250): array
    {
        $out=[];
        foreach (['name','description'] as $key) { if (array_key_exists($key,$body)) { $max=$key==='name'?$nameMax:65535; $out[$key]=Dto::storedText(Input::text($body[$key],$key,$max,$key==='name'),$key,$key==='name'?($nameMax===50?50:256):65535); } }
        return $out;
    }

    private function updateEvaluation(int $id): array
    {
        $body=$this->request->json(); Input::fields($body,['name','description','archived','starred']);
        $this->db->transaction(function () use($id,$body):void {
            $this->context->evaluation($id,true); $changes=$this->metadata($body);
            if(array_key_exists('starred',$body)){$changes['isStarred']=(int)Input::boolean($body['starred'],'starred');}
            if(array_key_exists('archived',$body)){
                $archive=Input::boolean($body['archived'],'archived');$counts=$this->counts($id);
                if($archive&&$counts['active']+$counts['failed']>0){throw new HttpError(409,'evaluation_running','Finish or abort the active jobs before archiving this evaluation.');}
                $changes['isArchived']=(int)$archive;
            }
            $this->db->update('Evaluation','evaluationId',$id,$changes);$this->context->event('evaluation',$id,'Evaluation updated','The evaluation settings were updated.');
        });
        return $this->evaluationDto($this->context->evaluation($id));
    }

    private function jobStatus(mixed $status): int
    {
        if ((!is_int($status)&&!is_string($status))||!in_array((string)$status,['-2','-1','0','1','2','3'],true)){Input::invalid('status','Choose a supported job status.');}
        return (int)$status;
    }

    private function updateJob(int $id): array
    {
        $body=$this->request->json();Input::fields($body,['description','status','progress','phases']);
        $this->db->transaction(function()use($id,$body):void{
            $row=$this->context->job($id,true);$changes=[];
            if(array_key_exists('phases',$body)){$changes['phases']=Input::integer($body['phases'],'phases',0,31);}
            if(array_key_exists('description',$body)){$changes['description']=Dto::storedText(Input::text($body['description'],'description',65535,false),'description',65535);}
            if(array_key_exists('progress',$body)){$changes['progress']=Input::integer($body['progress'],'progress',0,100);}
            if(array_key_exists('status',$body)){$status=$this->jobStatus($body['status']);$changes['status']=$status;if($status===0){$changes['currentPhase']=null;$changes['progress']=0;$changes['started']=null;$changes['finished']=null;} $this->context->event('job',$id,'Job status changed','The job status changed from '.$row['status'].' to '.$status.'.');}
            $this->db->update('Job','jobId',$id,$changes);
        });return $this->jobDto($this->context->job($id));
    }

    private function evaluationAction(int $id): array
    {
        $body=$this->request->json();Input::fields($body,['action']);$action=$body['action']??null;
        if(!in_array($action,['abort','reschedule'],true)){Input::invalid('action','Choose abort or reschedule.');}
        $changed=$this->db->transaction(function()use($id,$action):int{
            $this->context->evaluation($id,true);$statuses=$action==='abort'?[-2,0,1,2]:[-2,-1];$next=$action==='abort'?-1:0;
            $rows=$this->db->rows('SELECT jobId,status FROM `Job` WHERE evaluationId=? AND status IN ('.implode(',',$statuses).') FOR UPDATE',[$id]);
            foreach($rows as$row){$values=['status'=>$next];if($next===0){$values+=['currentPhase'=>null,'progress'=>0,'started'=>null,'finished'=>null];}$this->db->update('Job','jobId',(int)$row['jobId'],$values);$this->context->event('job',(int)$row['jobId'],$action==='abort'?'Job aborted':'Job rescheduled','The job status changed from '.$row['status'].' to '.$next.'.');}
            return count($rows);
        });return ['changedJobs'=>$changed,'evaluation'=>$this->evaluationDto($this->context->evaluation($id))];
    }

    private function log(int $id): array
    {
        Input::fields($this->request->query,['offset','limit']);
        $offset=Input::integer($this->request->query['offset']??'0','offset',0,2147483647);
        $limit=Input::integer($this->request->query['limit']??'262144','limit',1,1048576);
        $file=$this->root.'/webroot/data/log/'.$id.'.log';
        $size=is_file($file)?filesize($file):0;
        // Another browser or the agent may truncate a log between polls.
        if($offset>$size){$offset=0;}
        $text='';
        if($size&&$offset<$size){
            $handle=fopen($file,'rb');
            if($handle===false){throw new \RuntimeException('Unable to read job log.');}
            try{
                fseek($handle,$offset);
                $bytes=(string)fread($handle,$limit+4);
                $text=mb_strcut($bytes,0,$limit,'UTF-8');
                // A limit smaller than the first code point still makes progress.
                if($text===''&&$bytes!==''){for($length=2;$length<=4&&$text==='';$length++){$text=mb_strcut($bytes,0,$length,'UTF-8');}}
            }finally{fclose($handle);}
        }
        $next=$offset+strlen($text);
        return ['text'=>$text,'offset'=>$offset,'nextOffset'=>$next,'size'=>$size,'truncated'=>$next<$size,'complete'=>$next>=$size];
    }

    private function postData(array $experiment): array
    {
        $data=json_decode($experiment['postData'],true);
        if(!is_array($data)){throw new HttpError(409,'invalid_experiment','The saved experiment parameters cannot be read.');}
        return $data;
    }

    private function groups(array $experiment): array
    {
        $data=$this->postData($experiment);
        return isset($data['_v3Groups'])&&is_array($data['_v3Groups'])?$data['_v3Groups']:SystemRoutes::parameterGroups($this->root,(int)$experiment['systemId']);
    }

    private function experimentOptions(array $project): array
    {
        Input::fields($this->request->query,['copy']);
        $systemId=(int)$project['systemId'];
        $values=[];
        $groups=SystemRoutes::parameterGroups($this->root,$systemId);
        $warmUp=$this->context->setting($systemId,'defaultValues','phase_warmUp','1');
        $environment=$this->context->setting($systemId,'defaultValues','environment','');
        $available=$this->runOptions(['systemId'=>$systemId,'postData'=>'{}','defaultEnvironment'=>null]);
        if(!in_array($environment,array_column($available['environments'],'key'),true)){$environment=$available['environments'][0]['key']??null;}
        $defaults=['runs'=>1,'distribution'=>'alter','warmUp'=>!in_array(strtolower($warmUp),['0','false','off','unchecked',''],true),'environment'=>$environment];
        if(isset($this->request->query['copy'])){
            $source=$this->context->experiment(Input::id($this->request->query['copy'],'copy'));
            if((int)$source['systemId']!==$systemId){Input::invalid('copy','Choose an experiment for the same system.');}
            $data=$this->postData($source);
            $groups=$this->groups($source);
            $values=$data['_v3Values']??$data;
            $defaults=['runs'=>(int)($data['runs']??1),'distribution'=>$data['run-distribution']??'alter','warmUp'=>!(bool)((int)$source['phases']&2),'environment'=>$source['defaultEnvironment']];
        }
        return ['project'=>Dto::project($project,$this->actor),'groups'=>$groups,'values'=>$values,'defaults'=>$defaults,'maxJobs'=>$this->maxJobs()];
    }

    private function maxJobs(): int
    {
        return max(1,min(100000,(int)$this->context->setting(0,'other','maxJobsPerEvaluation','1000')));
    }

    private function createExperiment(array $project): array
    {
        $body=$this->request->json();Input::fields($body,['name','description','runs','distribution','warmUp','environment','parameters','sourceExperimentId']);
        $source=null;
        if(isset($body['sourceExperimentId'])){$source=$this->context->experiment(Input::id($body['sourceExperimentId'],'sourceExperimentId'));if((int)$source['systemId']!==(int)$project['systemId']){Input::invalid('sourceExperimentId','Choose an experiment for this system.');}}
        $metadata=$this->metadata(['name'=>$body['name']??null,'description'=>$body['description']??''],50);$groups=$source!==null?$this->groups($source):SystemRoutes::parameterGroups($this->root,(int)$project['systemId']);
        $runs=Input::integer($body['runs']??1,'runs',1,$this->maxJobs());$distribution=$body['distribution']??'alter';if(!in_array($distribution,['order','alter','rand'],true)){Input::invalid('distribution','Choose a supported run distribution.');}
        $warmUp=isset($body['warmUp'])?Input::boolean($body['warmUp'],'warmUp'):true;$environment=isset($body['environment'])?Input::text($body['environment'],'environment',100):null;
        if($environment!==null){$this->assertEnvironment(['systemId'=>$project['systemId']],$environment);}
        $extensions=new Extensions($this->root,(int)$project['systemId']);$values=ParameterCompiler::values($groups,$body['parameters']??[],$extensions);$data=ParameterCompiler::legacy($groups,$values,$extensions);$data+=['runs'=>$runs,'run-distribution'=>$distribution,'phase_warmUp'=>$warmUp,'_v3Groups'=>$groups,'_v3Values'=>$values];
        ParameterCompiler::configurations($this->root,$data,$this->maxJobs(),$extensions);
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(strlen($json)>65535){Input::invalid('parameters','The saved experiment exceeds the storage limit.');}
        $id=$this->db->transaction(function()use($project,$metadata,$json,$warmUp,$environment):int{
            $this->context->project((int)$project['projectId'],true);$next=(int)$this->db->row('SELECT COALESCE(MAX(internalId),0)+1 n FROM `Experiment` WHERE projectId=?',[$project['projectId']])['n'];
            $id=$this->db->insert('Experiment',$metadata+['userId'=>$this->actor['id'],'systemId'=>(int)$project['systemId'],'phases'=>$warmUp?0:2,'status'=>0,'created'=>date('Y-m-d H:i:s'),'projectId'=>(int)$project['projectId'],'postData'=>$json,'internalId'=>$next,'isArchived'=>0,'resultId'=>'','defaultEnvironment'=>$environment]);
            $this->context->event('experiment',$id,'Experiment created','A new experiment was created.');return $id;
        });return $this->experimentDto($this->context->experiment($id));
    }

    private function runOptions(array $experiment): array
    {
        $environments=[];$system=$this->db->row('SELECT cem FROM `System` WHERE systemId=?',[$experiment['systemId']]);
        foreach($this->db->rows("SELECT item FROM `Setting` WHERE systemId=? AND section='environments' ORDER BY item",[$experiment['systemId']])as$row){$environments[]=['key'=>'system-'.$row['item'],'name'=>$row['item'],'type'=>'system'];}
        if($system&&(bool)$system['cem']){$cem=json_decode($this->context->setting(0,'cem','environments','[]'),true);$timeout=max(1,(int)$this->context->setting(0,'cem','nodeMissingTimeout','300'));foreach(is_array($cem)?$cem:[]as$name){if(!is_string($name)){continue;}$count=(int)$this->db->row('SELECT COUNT(*) n FROM `Node` WHERE environment=? AND lastUpdate>?',[$name,date('Y-m-d H:i:s',time()-$timeout)])['n'];$environments[]=['key'=>'cem-'.$name,'name'=>$name,'type'=>'cem','nodeCount'=>$count];}}
        $data=$this->postData($experiment);$versions=[];
        foreach($data['_v3Elements']??array_map(static fn($id)=>['type'=>$data[$id.'-type']??'','parameter'=>$data[$id.'-parameter']??''],$data['elements']??[])as$element){try{$baseType=(new Extensions($this->root,(int)$experiment['systemId']))->parameterBase((string)$element['type']);}catch(HttpError){continue;}if($baseType!=='software-versions-list'){continue;}$parameter=$element['parameter'];$count=(int)($data[$parameter.'-numberOfVersions']??0);$values=[];for($i=0;$i<$count&&$i<1000;$i++){$values[]=(string)($data[$parameter.'-version-'.$i]??'');}$versions[]=['parameter'=>$parameter,'label'=>$parameter,'values'=>$values];}
        return ['environments'=>$environments,'versions'=>$versions,'defaultEnvironment'=>$experiment['defaultEnvironment']??($environments[0]['key']??null)];
    }

    private function assertEnvironment(array $experiment,string $environment): void
    {
        $options=$this->runOptions($experiment+['postData'=>'{}','defaultEnvironment'=>null]);
        if(!in_array($environment,array_column($options['environments'],'key'),true)){Input::invalid('environment','Choose an available environment for this system.');}
    }

    private function run(int $id): array
    {
        $body=$this->request->json();Input::fields($body,['name','description','environment','versions']);$experiment=$this->context->experiment($id);
        if((bool)$experiment['isArchived']){throw new HttpError(409,'experiment_archived','Restore this experiment before starting another evaluation.');}
        $environment=Input::text($body['environment']??null,'environment',100);$this->assertEnvironment($experiment,$environment);$data=$this->postData($experiment);
        if(isset($body['versions'])){
            if(!is_array($body['versions'])){Input::invalid('versions','Provide a map of software parameter version lists.');}
            $allowed=[];foreach($this->runOptions($experiment)['versions']as$version){$allowed[]=$version['parameter'];}
            Input::fields($body['versions'],$allowed);foreach($body['versions']as$parameter=>$versions){if(!is_array($versions)||!array_is_list($versions)||$versions===[]||count($versions)>1000){Input::invalid('versions','Choose one or more software versions.');}$data[$parameter.'-numberOfVersions']=count($versions);foreach($versions as$i=>$version){$data[$parameter.'-version-'.$i]=Input::text($version,'versions',256);}}
        }
        $configurations=ParameterCompiler::configurations($this->root,$data,$this->maxJobs(),new Extensions($this->root,(int)$experiment['systemId']));$metadata=$this->metadata(['name'=>$body['name']??date('d.m.Y - H:i'),'description'=>$body['description']??Dto::text($experiment['description'])]);
        $evaluationId=$this->db->transaction(function()use($id,$experiment,$environment,$configurations,$metadata):int{
            $current=$this->context->experiment($id,true);if((bool)$current['isArchived']){throw new HttpError(409,'experiment_archived','Restore this experiment before starting another evaluation.');}
            $next=(int)$this->db->row('SELECT COALESCE(MAX(internalId),0)+1 n FROM `Evaluation` WHERE experimentId=?',[$id])['n'];$evaluationId=$this->db->insert('Evaluation',$metadata+['systemId'=>(int)$experiment['systemId'],'experimentId'=>$id,'internalId'=>$next,'isArchived'=>0,'isStarred'=>0]);
            foreach($configurations as$i=>$configuration){$parameters=$configuration['parameters'];$identity=$parameters;unset($identity['run']);$label=[];foreach($parameters as$key=>$value){$label[]=$key.': '.(is_scalar($value)?(string)$value:json_encode($value));}$configuration['title']='Job['.implode(', ',$label).']';$jobId=$this->db->insert('Job',['userId'=>$this->actor['id'],'description'=>Dto::storedText($configuration['title'],'parameters',65535),'systemId'=>(int)$experiment['systemId'],'environment'=>$environment,'phases'=>(int)$experiment['phases'],'currentPhase'=>null,'configuration'=>json_encode($configuration,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'status'=>0,'progress'=>0,'result'=>'','created'=>date('Y-m-d H:i:s'),'started'=>null,'finished'=>null,'evaluationId'=>$evaluationId,'internalId'=>$i+1,'configurationIdentifier'=>sha1(json_encode($identity,JSON_THROW_ON_ERROR))]);$this->context->event('job',$jobId,'Job created','A new job was scheduled for the evaluation.');}
            $this->db->update('Experiment','experimentId',$id,['defaultEnvironment'=>$environment]);$this->context->event('evaluation',$evaluationId,'Evaluation started','A new evaluation was started.');return $evaluationId;
        });return $this->evaluationDto($this->context->evaluation($evaluationId));
    }

    private function status(): array
    {
        $query=$this->request->query;Input::fields($query,['all']);$all=Input::queryBoolean($query['all']??'false','all');[$scope,$args]=$this->context->scope('p',$all);
        $rows=$this->db->rows('SELECT j.*,e.internalId evaluationInternalId,e.name evaluationName,e.experimentId,x.internalId experimentInternalId,x.projectId,x.name experimentName,p.name projectName,s.name systemName FROM `Job` j JOIN `Evaluation` e ON e.evaluationId=j.evaluationId JOIN `Experiment` x ON x.experimentId=e.experimentId JOIN `Project` p ON p.projectId=x.projectId JOIN `System` s ON s.systemId=j.systemId WHERE '.$scope.' AND j.status IN (-2,0,1,2) ORDER BY j.jobId DESC LIMIT 500',$args);
        $counts=['scheduled'=>0,'setup'=>0,'running'=>0,'failed'=>0];foreach($rows as$row){$name=[-2=>'failed',0=>'scheduled',1=>'setup',2=>'running'][(int)$row['status']];$counts[$name]++;}
        return ['counts'=>$counts,'jobs'=>array_map(fn(array$row):array=>$this->jobDto($row),$rows),'limit'=>500,'at'=>date(DATE_ATOM)];
    }
}
