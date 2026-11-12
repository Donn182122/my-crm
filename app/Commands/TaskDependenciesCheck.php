<?php

declare(strict_types=1);
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use CodeIgniter\Shield\Entities\User;
use App\Services\{TaskDependencyService,TaskCollaborationService,TaskService,ProjectService,ClientService,TaskInputException,DatabaseUnitOfWork};

class TaskDependenciesCheck extends BaseCommand
{
    protected $group='Verification';protected $name='tasks:dependencies-check';protected $description='Verify task dependency security, completion gates, cycles and actual concurrent MariaDB requests.';
    private $curl;private int $checks=0;
    private function check(bool $value,string $label):void{if(!$value){throw new \RuntimeException('FAIL: '.$label);}$this->checks++;CLI::write('PASS: '.$label,'green');}
    private function authenticationDigest($db):string
    {
        $rows=$db->table('auth_identities')->orderBy('id')->get()->getResultArray();
        // Shield legitimately touches these fields when a permanent user logs in.
        foreach($rows as &$row){unset($row['last_used_at'],$row['updated_at']);}unset($row);
        return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    }
    private function http(string $method,string $path,array $data=[],bool $ajax=false):array
    {
        curl_setopt_array($this->curl,[CURLOPT_URL=>'http://localhost:8081/'.$path,CURLOPT_HTTPHEADER=>$ajax?['X-Requested-With: XMLHttpRequest','Accept: application/json']:[]]);curl_setopt($this->curl,CURLOPT_POSTFIELDS,null);curl_setopt($this->curl,CURLOPT_HTTPGET,true);
        if($method==='POST'){curl_setopt($this->curl,CURLOPT_POST,true);curl_setopt($this->curl,CURLOPT_POSTFIELDS,http_build_query($data));}$body=curl_exec($this->curl);if($body===false){throw new \RuntimeException('HTTP verification request failed.');}return [(int)curl_getinfo($this->curl,CURLINFO_HTTP_CODE),$body];
    }
    private function post(string $path,array $data=[],bool $ajax=false):array
    {
        [, $body]=$this->http('GET','tasks');if(!preg_match('/name="my_crm_csrf_token" value="([^"]+)"/',$body,$m)){throw new \RuntimeException('Verification CSRF form missing.');}
        return $this->http('POST',$path,$data+['my_crm_csrf_token'=>html_entity_decode($m[1])],$ajax);
    }
    private function login(array $credentials):void
    {
        curl_setopt($this->curl,CURLOPT_COOKIELIST,'ALL');
        for($attempt=0;$attempt<15;$attempt++){[$status,$body]=$this->http('GET','login');if($status===429){sleep(7);continue;}
            if(!preg_match('/name="my_crm_csrf_token" value="([^"]+)"/',$body,$m)){throw new \RuntimeException('Login form unavailable.');}
            [$status]=$this->http('POST','login',['email'=>$credentials[0],'password'=>$credentials[1],'my_crm_csrf_token'=>html_entity_decode($m[1])]);if($status===429){sleep(7);continue;}$this->check(in_array($status,[302,303],true),'Generated fixture login');return;
        }throw new \RuntimeException('Login rate limit persisted; retry later.');
    }
    private function connectionChecks($db):void
    {
        $settings=$db->query('SELECT @@foreign_key_checks AS fk,@@SESSION.sql_mode AS modes')->getRowArray();
        $this->check((int)$settings['fk']===1,'Application connection enforces foreign keys');
        $this->check(str_contains($settings['modes'],'STRICT_TRANS_TABLES')||str_contains($settings['modes'],'STRICT_ALL_TABLES'),'Application connection enables strict SQL mode');
    }
    private function worker(array $params):void
    {
        [$actorId,$pre,$succ,$pv,$sv,$start,$mode]=array_pad($params,7,'');foreach([$actorId,$pre,$succ,$pv,$sv] as $value){if(!ctype_digit($value)){throw new \RuntimeException('Invalid worker input');}}
        $db=db_connect();$actor=auth()->getProvider()->findById((int)$actorId);$rows=$db->table('tasks')->whereIn('id',[(int)$pre,(int)$succ])->get()->getResultArray();
        if($db->getDatabase()!=='my_crm_db'||!$actor||!str_starts_with($actor->username,'depcheck')||count($rows)!==2||array_filter($rows,static fn($r)=>(int)$r['created_by']!==(int)$actorId)||count(array_unique(array_column($rows,'project_id')))!==1||!in_array($mode,['add','complete','reopen'],true)||abs((float)$start-microtime(true))>30){throw new \RuntimeException('Dependency worker fixture guard failed');}
        while(microtime(true)<(float)$start){usleep(10000);}$began=microtime(true);
        try{DatabaseUnitOfWork::run($db,function()use($db,$rows,$actor,$pre,$succ,$pv,$sv,$mode){DatabaseUnitOfWork::lock($db,'projects',(int)$rows[0]['project_id']);usleep(600000);if($mode==='add'){(new TaskDependencyService($db))->add($actor,(int)$succ,['predecessor_id'=>$pre,'version'=>$sv,'other_version'=>$pv]);}else{(new TaskService($db))->status($actor,(int)($mode==='reopen'?$pre:$succ),$mode==='reopen'?'1':'4',$mode==='reopen'?$pv:$sv);}});$status=200;}catch(TaskInputException $e){$status=$e->status;}
        CLI::write(json_encode(['status'=>$status,'began'=>$began,'ended'=>microtime(true)],JSON_THROW_ON_ERROR));
    }
    private function race(User $actor,int $pre,int $succ,string $kind):void
    {
        $db=db_connect();$version=static fn($id)=>(string)$db->table('tasks')->where('id',$id)->get()->getRow('version');$processes=[];$results=[];$failed=false;$start=(string)(microtime(true)+2);
        for($i=0;$i<2;$i++){$a=$pre;$b=$succ;$mode=$i===0?'add':($kind==='completion'?'complete':'add');if($kind==='reverse'&&$i===1){$a=$succ;$b=$pre;}if($kind==='reopen'){$mode=$i===0?'complete':'reopen';}$pipes=[];$process=proc_open([PHP_BINARY,ROOTPATH.'spark','tasks:dependencies-check','worker',(string)$actor->id,(string)$a,(string)$b,$version($a),$version($b),$start,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOTPATH);if(!is_resource($process)){$failed=true;break;}fclose($pipes[0]);$processes[]=[$process,$pipes];}
        foreach($processes as [$process,$pipes]){$out=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!preg_match('/\{"status":.*\}/',$out,$m)){$failed=true;continue;}$results[]=json_decode($m[0],true);}
        if($failed||count($results)!==2){throw new \RuntimeException('Dependency worker failed; all launched workers joined');}$this->check(max($results[0]['began'],$results[1]['began'])<min($results[0]['ended'],$results[1]['ended']),'Actual MariaDB '.$kind.' workers overlap');$statuses=array_column($results,'status');sort($statuses);$this->check($statuses===($kind==='reopen'?[200,422]:[200,409]),'Concurrent '.$kind.' operations preserve one valid outcome');
    }
    public function run(array $params):void
    {
        if(($params[0]??'')==='worker'){$this->worker(array_slice($params,1));return;}
        $db=db_connect();$this->check($db->getDatabase()==='my_crm_db','Dedicated independent database');$this->connectionChecks($db);if(($params[0]??'')==='connection'){return;}$s=new TaskService();$d=new TaskDependencyService();$projects=new ProjectService();$clients=new ClientService();$provider=auth()->getProvider();$ids=[];$emails=[];$tag='depcheck'.bin2hex(random_bytes(4));$before=$this->authenticationDigest($db);$projectIds=[];
        $this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);
        try{
            foreach(['tasks','tasks/99999'] as $path){[$status]=$this->http('GET',$path);$this->check($status===302,'Guest cannot access dependency context');}
            foreach(['owner','member','outsider','reader','manager'] as $role){$email=$tag.'.'.$role.'@example.invalid';$password=bin2hex(random_bytes(20)).'aA9!';$provider->save(new User(['username'=>$tag.$role,'email'=>$email,'password'=>$password]));$uid=(int)$provider->getInsertID();$ids[]=$uid;$emails[]=$email;$actor=$provider->findById($uid);$actor->activate();if($role==='reader'){$actor->addPermission('foundation.access','projects.view','tasks.view');}else{$actor->addGroup('user');}$actors[$role]=$actor;$credentials[$role]=[$email,$password];}
            $owner=$actors['owner'];$client=$clients->save($owner,['type'=>'company','company_name'=>$tag.' private client','status_id'=>'1']);$project=$projects->save($owner,['name'=>$tag.' project','client_id'=>(string)$client,'status_id'=>'1']);$projectIds[]=$project;$pv=fn()=>(string)$projects->find($owner,$project)['version'];foreach(['member','reader','manager'] as $role){$projects->addMember($owner,$project,['user_id'=>(string)$actors[$role]->id,'role'=>$role==='member'?'member':'manager','version'=>$pv()]);}
            $new=fn($title,$extra=[])=>$s->save($owner,['project_id'=>(string)$project,'title'=>$tag.' '.$title]+$extra);$version=fn($id)=>(string)$db->table('tasks')->where('id',$id)->get()->getRow('version');$pre=$new('private prerequisite');$succ=$new('dependent',['assignee_id'=>(string)$actors['member']->id]);$third=$new('third');$child=$new('child',['parent_id'=>(string)$succ]);$edgeId=fn($a,$b)=>(int)$db->table('task_dependencies')->where(['predecessor_id'=>$a,'successor_id'=>$b])->get()->getRow('id');
            $add=fn($a,$b)=>['predecessor_id'=>(string)$a,'version'=>$version($b),'other_version'=>$version($a)];$remove=fn($a,$b)=>['version'=>$version($b),'other_version'=>$version($a),'edge_version'=>(string)$db->table('task_dependencies')->where('id',$edgeId($a,$b))->get()->getRow('version')];
            $this->login($credentials['owner']);[$status,$body]=$this->http('GET','tasks/'.$succ);$this->check($status===200&&str_contains($body,'id="task-dependencies"')&&str_contains($body,'Add prerequisite'),'Task profile dependency controls');
            [$status]=$this->http('POST','tasks/'.$succ.'/dependencies',$add($pre,$succ));$this->check($status===403,'CSRF rejects dependency creation');[$status]=$this->post('tasks/'.$succ.'/dependencies',$add($pre,$succ));$this->check(in_array($status,[302,303],true),'Create dependency through native endpoint');$this->check($version($pre)==='2'&&$version($succ)==='2','Both endpoint task versions advance');
            [$status]=$this->post('tasks/'.$succ.'/dependencies',$add($pre,$succ),true);$this->check($status===422,'Duplicate relationship rejected');[$status]=$this->post('tasks/'.$pre.'/dependencies',$add($pre,$pre),true);$this->check($status===422,'Self dependency rejected');[$status]=$this->post('tasks/'.$pre.'/dependencies',$add($succ,$pre),true);$this->check($status===422,'Direct cycle rejected');
            [$status]=$this->post('tasks/'.$third.'/dependencies',$add($succ,$third));$this->check(in_array($status,[302,303],true),'Second chain edge added');[$status]=$this->post('tasks/'.$pre.'/dependencies',$add($third,$pre),true);$this->check($status===422,'Multi-step cycle rejected');
            foreach(['status','edit'] as $path){$url='tasks/'.$succ.($path==='status'?'/status':'');[$status,$body]=$this->post($url,['status_id'=>'4','version'=>$version($succ)],true);$this->check($status===422&&str_contains($body,'blocked'),'Completion cannot bypass via '.$path);}
            [$status,$body]=$this->http('GET','tasks/kanban?project='.$project);$this->check($status===200&&str_contains($body,'Blocked')&&str_contains($body,'/status'),'Kanban exposes computed blocked state and existing status route');[$status,$body]=$this->http('GET','tasks?project='.$project);$this->check($status===200&&str_contains($body,'Blocked'),'Task list readiness');[$status,$body]=$this->http('GET','projects/'.$project);$this->check($status===200&&str_contains($body,'Blocked'),'Project Task readiness');
            [$status]=$this->post('tasks/'.$child.'/dependencies',$add($pre,$child),true);$this->check($status===422,'Dependent subtask excluded');[$status]=$this->post('tasks/'.$pre.'/dependencies',$add($child,$pre),true);$this->check($status===422,'Predecessor subtask excluded');
            $otherProject=$projects->save($owner,['name'=>$tag.' second project','client_id'=>(string)$client,'status_id'=>'1']);$projectIds[]=$otherProject;$cross=$s->save($owner,['project_id'=>(string)$otherProject,'title'=>$tag.' cross']);[$status]=$this->post('tasks/'.$succ.'/dependencies',$add($cross,$succ),true);$this->check($status===422,'Authorized cross-project endpoints rejected');
            foreach([[],null,'0','-1','x',str_repeat('9',30)] as $bad){[$status]=$this->post('tasks/'.$succ.'/dependencies',['predecessor_id'=>$bad,'version'=>$version($succ),'other_version'=>'1'],true);$this->check($status===422,'Invalid predecessor ID rejected');}
            $stale=$add($pre,$third);$s->status($owner,$pre,'2',$version($pre));[$status]=$this->post('tasks/'.$third.'/dependencies',$stale,true);$this->check($status===409,'Stale other-task version rejected');
            $edge=$edgeId($pre,$succ);[$status]=$this->http('POST','tasks/'.$succ.'/dependencies/'.$edge.'/remove',$remove($pre,$succ));$this->check($status===403,'CSRF rejects dependency removal');[$status]=$this->post('tasks/'.$third.'/dependencies/'.$edge.'/remove',$remove($pre,$succ),true);$this->check($status===404,'Manipulated edge/current-task pair rejected');$staleRemove=$remove($pre,$succ);$staleRemove['edge_version']='999';[$status]=$this->post('tasks/'.$succ.'/dependencies/'.$edge.'/remove',$staleRemove,true);$this->check($status===409,'Stale edge version rejected');
            $this->login($credentials['member']);[$status,$body]=$this->http('GET','tasks/'.$succ);$this->check($status===200&&str_contains($body,'Completion is blocked')&&!str_contains($body,$tag.' private prerequisite')&&!str_contains($body,'id="dependency-add"'),'Restricted prerequisite hidden without bypassing actual blocking');[$status]=$this->post('tasks/'.$succ.'/dependencies',$add($pre,$succ),true);$this->check($status===404,'Assigned member cannot administer dependencies');[$status]=$this->post('tasks/'.$succ.'/status',['status_id'=>'4','version'=>$version($succ)],true);$this->check($status===422,'Assigned member cannot bypass blocked completion');
            $this->login($credentials['outsider']);foreach(['tasks/'.$succ,'tasks/'.$pre] as $path){[$status]=$this->http('GET',$path);$this->check($status===404,'Foreign task/dependency context rejected');}[$status]=$this->post('tasks/'.$succ.'/dependencies',$add($pre,$succ),true);$this->check($status===404,'Foreign direct graph mutation rejected');
            $this->login($credentials['reader']);[$status]=$this->post('tasks/'.$succ.'/dependencies',$add($pre,$succ),true);$this->check($status===404,'Project manager without Shield capability rejected');
            $this->login($credentials['manager']);[$status,$body]=$this->http('GET','tasks/'.$succ);$this->check($status===200&&str_contains($body,'Add prerequisite'),'Authorized project manager can manage');$projects->removeMember($owner,$project,(int)$actors['manager']->id,$pv());[$status]=$this->post('tasks/'.$succ.'/dependencies/'.$edge.'/remove',$remove($pre,$succ),true);$this->check($status===404,'Revoked membership immediately rejects stale dependency form');
            $this->login($credentials['owner']);$s->archive($owner,$pre,true,$version($pre));[$status]=$this->post('tasks/'.$succ.'/dependencies/'.$edge.'/remove',$remove($pre,$succ),true);$this->check($status===422,'Archived prerequisite requires restoration for removal');[$status]=$this->post('tasks/'.$succ.'/status',['status_id'=>'4','version'=>$version($succ)],true);$this->check($status===422,'Archived incomplete predecessor remains blocking');$s->archive($owner,$pre,false,$version($pre));
            $s->status($owner,$pre,'4',$version($pre));[$status]=$this->post('tasks/'.$succ.'/status',['status_id'=>'4','version'=>$version($succ)]);$this->check(in_array($status,[302,303],true),'Completion succeeds after prerequisite Done');foreach(['status','edit'] as $path){[$status]=$this->post('tasks/'.$pre.($path==='status'?'/status':''),['status_id'=>'1','version'=>$version($pre)],true);$this->check($status===422,'Done dependent prevents predecessor reopen via '.$path);}
            $s->archive($owner,$succ,true,$version($succ));[$status]=$this->post('tasks/'.$pre.'/status',['status_id'=>'1','version'=>$version($pre)],true);$this->check($status===422,'Archived Done dependent still prevents reopening');$s->archive($owner,$succ,false,$version($succ));$s->status($owner,$succ,'1',$version($succ));$s->status($owner,$pre,'1',$version($pre));$this->check($s->find($owner,$succ)['dependency_state']==='blocked','Reverse-order reopening preserves valid graph');
            [$status]=$this->post('tasks/'.$succ.'/dependencies/'.$edge.'/remove',$remove($pre,$succ));$this->check(in_array($status,[302,303],true),'Native removal soft-archives edge');$this->check($db->table('task_dependencies')->where('id',$edge)->get()->getRow('archived_at')!==null,'Removed edge retained for audit');[$status]=$this->post('tasks/'.$succ.'/dependencies',$add($pre,$succ));$this->check(in_array($status,[302,303],true)&&$edgeId($pre,$succ)===$edge,'Reactivation reuses unique relationship');
            $projects->archive($owner,$project,true,$pv());[$status]=$this->post('tasks/'.$succ.'/dependencies/'.$edge.'/remove',$remove($pre,$succ),true);$this->check($status===422,'Archived project graph read-only');$projects->archive($owner,$project,false,$pv());
            foreach(['reverse','completion','reopen'] as $kind){$a=$new('race '.$kind.' a');$b=$new('race '.$kind.' b');if($kind==='reopen'){$d->add($owner,$b,$add($a,$b));$s->status($owner,$a,'4',$version($a));}$this->race($owner,$a,$b,$kind);}
            $invalid=$db->table('task_dependencies d')->join('tasks a','a.id=d.predecessor_id')->join('task_statuses sa','sa.id=a.status_id')->join('tasks b','b.id=d.successor_id')->join('task_statuses sb','sb.id=b.status_id')->where(['d.project_id'=>$project,'d.archived_at'=>null,'sb.category'=>'done'])->where('sa.category !=','done')->countAllResults();$this->check($invalid===0,'Races leave no completed task with an incomplete predecessor');
            foreach($db->table('tasks')->whereIn('created_by',$ids)->get()->getResultArray() as $task){if($db->table('task_history')->where('task_id',$task['id'])->countAllResults()!==(int)$task['version']){throw new \RuntimeException('History/version integrity failed');}}$this->check(true,'Every committed endpoint version has exactly one history event');
        }finally{
            if($projectIds){$db->table('task_dependencies')->whereIn('project_id',$projectIds)->delete();}if($ids){$db->table('tasks')->whereIn('created_by',$ids)->where('parent_id IS NOT NULL',null,false)->delete();$db->table('tasks')->whereIn('created_by',$ids)->delete();$db->table('projects')->whereIn('created_by',$ids)->delete();$db->table('clients')->whereIn('owner_id',$ids)->delete();}foreach($ids as $uid){$db->table('auth_logins')->where('user_id',$uid)->delete();$provider->delete($uid,true);}foreach($emails as $email){$db->table('auth_logins')->where('identifier',$email)->delete();}curl_close($this->curl);unset($credentials);
        }
        $this->check(hash_equals($before,$this->authenticationDigest($db)),'Permanent authentication unchanged');CLI::write('Task dependency HTTP/concurrency checks passed: '.$this->checks.'. Fictional fixtures removed.','green');
    }
}