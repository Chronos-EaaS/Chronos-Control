<?php
declare(strict_types=1);

/** Synthetic module integration only: no config.php, production DB, VCS or network. */
use Chronos\Api\V3\{HttpError,Request,Sessions,Store,WorkflowRoutes};

$source=dirname(__DIR__,2);
foreach(['HttpError','Request','Input','Policy','Dto','Store','Context','Sessions','SystemRoutes','WorkflowRoutes']as$name)require_once$source.'/api/v3/'.$name.'.php';
date_default_timezone_set('Europe/Zurich');
// SQLite supplies real atomic rollback; this fixture removes only MySQL's lock suffix.
trait FixtureLockSyntax
{
    public function prepare(string $query,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$query),$options); }
}
if(class_exists(Pdo\Sqlite::class)){class JobFixturePdo extends Pdo\Sqlite{use FixtureLockSyntax;}}
else{class JobFixturePdo extends PDO{use FixtureLockSyntax;}}
$pdo=new JobFixturePdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$concat=static fn(string $separator,mixed ...$parts):string=>implode($separator,array_filter($parts,static fn(mixed $part):bool=>$part!==null));
if($pdo instanceof Pdo\Sqlite)$pdo->createFunction('CONCAT_WS',$concat);else$pdo->sqliteCreateFunction('CONCAT_WS',$concat);
$checks=0;
function check(bool $condition,string $message):void{global$checks;++$checks;if(!$condition)throw new RuntimeException($message);}
function rejected(callable $operation,int $status,string $code):void{try{$operation();throw new RuntimeException('Expected rejection.');}catch(HttpError$error){check($error->status===$status&&$error->errorCode===$code,'Expected '.$code);}}
$root=sys_get_temp_dir().'/chronos-v3-job-fixture-'.bin2hex(random_bytes(8));mkdir($root.'/webroot/data/evaluation',0700,true);
try{
    foreach([
        'User(userId INTEGER PRIMARY KEY,username TEXT,firstname TEXT,lastname TEXT,email TEXT,role INTEGER,gender INTEGER,alive INTEGER,activated INTEGER,created TEXT,lastLogin TEXT)',
        'System(systemId INTEGER PRIMARY KEY,userId INTEGER,name TEXT,cem INTEGER)',
        'Project(projectId INTEGER PRIMARY KEY,userId INTEGER,systemId INTEGER,name TEXT,description TEXT,isArchived INTEGER,isFinished INTEGER,environment TEXT)',
        'ProjectUser(projectUserId INTEGER PRIMARY KEY,projectId INTEGER,userId INTEGER)',
        'Experiment(experimentId INTEGER PRIMARY KEY,projectId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT)',
        'Evaluation(evaluationId INTEGER PRIMARY KEY,experimentId INTEGER,systemId INTEGER,internalId INTEGER,name TEXT)',
        'Job(jobId INTEGER PRIMARY KEY,userId INTEGER,description TEXT,systemId INTEGER,environment TEXT,phases INTEGER,currentPhase INTEGER,configuration TEXT,status INTEGER,progress INTEGER,result TEXT,created TEXT,started TEXT,finished TEXT,evaluationId INTEGER,internalId INTEGER)',
        'Event(eventId INTEGER PRIMARY KEY,title TEXT,time TEXT,eventText TEXT,eventType TEXT,relatedId INTEGER,userId INTEGER,nodeId TEXT)',
        'Node(nodeId TEXT,hostname TEXT,lastUpdate TEXT,currentJob INTEGER)',
        'Setting(settingId INTEGER PRIMARY KEY,systemId INTEGER,section TEXT,item TEXT,value TEXT)',
    ]as$table)$pdo->exec('CREATE TABLE '.$table);
    $pdo->exec("INSERT INTO User VALUES(1,'member','Synthetic','Member','fixture@example.test',0,0,1,1,'2026-01-15 12:00:00',NULL)");
    $pdo->exec("INSERT INTO System VALUES(1,1,'Synthetic system',0)");$pdo->exec("INSERT INTO Project VALUES(1,1,1,'Synthetic project','',0,0,'')");$pdo->exec("INSERT INTO Experiment VALUES(1,1,1,1,'Synthetic experiment')");$pdo->exec("INSERT INTO Evaluation VALUES(1,1,1,1,'Synthetic evaluation')");
    $store=new Store($pdo);$sessions=(new ReflectionClass(Sessions::class))->newInstanceWithoutConstructor();
    $call=static function(int $id,array $body,array $actor=['id'=>1,'role'=>0])use($store,$sessions,$root):array{
        $request=new Request('PATCH','/api/v3/jobs/'.$id,['content-type'=>'application/json'],json_encode((object)$body,JSON_THROW_ON_ERROR));
        return(new WorkflowRoutes($request,$store,$actor,$root,$sessions))->handle('/jobs/'.$id)??throw new RuntimeException('Unmatched route.');
    };
    foreach([-2,-1,0,1,2,3]as$index=>$status){
        $id=$index+1;$store->insert('Job',['jobId'=>$id,'userId'=>1,'description'=>'Synthetic job','systemId'=>1,'environment'=>'system-local','phases'=>0,'currentPhase'=>3,'configuration'=>'{"parameters":{"workers":2}}','status'=>$status,'progress'=>45,'result'=>'{"latency":0,"flag":false}','created'=>'2026-01-15 12:00:00','started'=>'2026-01-15 12:01:00','finished'=>$status===3?'2026-01-15 12:02:00':null,'evaluationId'=>1,'internalId'=>$id]);
        file_put_contents($root.'/webroot/data/evaluation/'.$id.'.zip','Synthetic archive '.$id);
        $before=$store->row('SELECT * FROM Job WHERE jobId=?',[$id]);$archive=hash_file('sha256',$root.'/webroot/data/evaluation/'.$id.'.zip');$events=(int)$pdo->query('SELECT COUNT(*) FROM Event')->fetchColumn();
        if(in_array($status,[-1,3],true)){
            rejected(fn()=>$call($id,['status'=>-1,'description'=>'Must roll back together']),409,'job_not_abortable');
            check($store->row('SELECT * FROM Job WHERE jobId=?',[$id])===$before,'Rejected terminal abort preserves the complete job row, including metadata and raw result.');
            check((int)$pdo->query('SELECT COUNT(*) FROM Event')->fetchColumn()===$events,'Rejected terminal abort creates no misleading event.');
        }else{
            $response=$call($id,['status'=>-1]);check($response['status']===200&&$response['body']['data']['status']===-1,'Eligible job is aborted.');
            check($response['body']['data']['permissions']['canAbort']===false,'Aborted job no longer offers Abort.');
            check($store->row('SELECT result FROM Job WHERE jobId=?',[$id])['result']===$before['result'],'Abort retains raw zero/false result bytes.');
            check((int)$pdo->query('SELECT COUNT(*) FROM Event')->fetchColumn()===$events+1,'Successful abort records exactly one event.');
        }
        check(hash_file('sha256',$root.'/webroot/data/evaluation/'.$id.'.zip')===$archive,'Archive bytes are unchanged.');
    }
    $finished=$store->row('SELECT * FROM Job WHERE jobId=6');
    rejected(fn()=>$call(6,['status'=>-1],['id'=>2,'role'=>0]),403,'forbidden');check($store->row('SELECT * FROM Job WHERE jobId=6')===$finished,'Forbidden actor cannot alter a finished job.');
    $rescheduled=$call(6,['status'=>0])['body']['data'];check($rescheduled['status']===0&&$rescheduled['currentPhase']===null&&$rescheduled['progress']===0,'Finished job still supports explicit rescheduling with reset phase/progress.');
    check($store->row('SELECT result FROM Job WHERE jobId=6')['result']===$finished['result'],'Rescheduling preserves existing raw result bytes.');
    // SYSTEM-10: raw legacy local names must propagate through experiment creation defaults.
    mkdir($root.'/webroot/systems/1',0700,true);file_put_contents($root.'/webroot/systems/1/parameters.json','[]');
    foreach(['A first environment','Zürich second environment','system-prefixed name']as$name)$store->insert('Setting',['systemId'=>1,'section'=>'environments','item'=>$name,'value'=>'']);
    foreach([
        ['Zürich second environment','unchecked','system-Zürich second environment',false],
        ['system-Zürich second environment','checked','system-Zürich second environment',true],
        ['system-prefixed name','0','system-system-prefixed name',false],
        ['Missing legacy environment','false','system-A first environment',false],
        ['','checked','system-A first environment',true],
    ]as[$savedEnvironment,$savedWarmUp,$expectedEnvironment,$expectedWarmUp]){
        $pdo->exec("DELETE FROM Setting WHERE section='defaultValues'");
        $store->insert('Setting',['systemId'=>1,'section'=>'defaultValues','item'=>'environment','value'=>$savedEnvironment]);
        $store->insert('Setting',['systemId'=>1,'section'=>'defaultValues','item'=>'phase_warmUp','value'=>$savedWarmUp]);
        $beforeSettings=$store->rows('SELECT * FROM Setting ORDER BY settingId');
        $request=new Request('GET','/api/v3/projects/1/experiment-options',[],'');
        $defaults=(new WorkflowRoutes($request,$store,['id'=>1,'role'=>0],$root,$sessions))->handle('/projects/1/experiment-options')['body']['data']['defaults'];
        check($defaults['environment']===$expectedEnvironment,'Saved environment propagates to canonical creation input: '.$savedEnvironment);
        check($defaults['warmUp']===$expectedWarmUp,'Saved legacy warm-up preference propagates independently.');
        check($store->rows('SELECT * FROM Setting ORDER BY settingId')===$beforeSettings,'Reading creation defaults never rewrites legacy settings.');
    }
    echo$checks.' job-transition/default assertions passed on PHP '.PHP_VERSION.".\n";
}finally{
    if(is_file($root.'/webroot/systems/1/parameters.json')){unlink($root.'/webroot/systems/1/parameters.json');rmdir($root.'/webroot/systems/1');rmdir($root.'/webroot/systems');}
    foreach(glob($root.'/webroot/data/evaluation/*')as$file)unlink($file);
    foreach(['/webroot/data/evaluation','/webroot/data','/webroot','']as$directory)rmdir($root.$directory);
}
