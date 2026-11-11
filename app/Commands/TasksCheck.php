<?php

declare(strict_types=1);
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use CodeIgniter\Shield\Entities\User;
use App\Services\{TaskService,ProjectService,ClientService,TaskInputException,DatabaseUnitOfWork};

class TasksCheck extends BaseCommand
{
    protected $group='Verification';protected $name='tasks:check';protected $description='Verify Tasks ownership, membership, HTTP security and concurrent fixture operations.';
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
    private function worker(array $params):void
    {
        [$actorId,$projectId,$version,$start]=array_pad($params,4,'');foreach([$actorId,$projectId,$version] as $value){if(!ctype_digit($value)){throw new \RuntimeException('Invalid fixture worker parameters.');}}
        $db=db_connect();$actor=auth()->getProvider()->findById((int)$actorId);$row=$db->table('tasks')->where('id',(int)$projectId)->get()->getRowArray();
        if($db->getDatabase()!=='my_crm_db'||!$actor||!str_starts_with($actor->username,'taskcheck')||!$row||(int)$row['created_by']!==(int)$actorId||abs((float)$start-microtime(true))>30){throw new \RuntimeException('Worker fixture guard failed.');}
        while(microtime(true)<(float)$start){usleep(10000);}$began=microtime(true);
        try{DatabaseUnitOfWork::run($db,function()use($db,$actor,$projectId,$version){$task=$db->table('tasks')->where('id',(int)$projectId)->get()->getRowArray();DatabaseUnitOfWork::lock($db,'projects',(int)$task['project_id']);usleep(600000);(new TaskService($db))->status($actor,(int)$projectId,'2',$version);});$status=200;}catch(TaskInputException $e){$status=$e->status;}
        CLI::write(json_encode(['status'=>$status,'began'=>$began,'ended'=>microtime(true)],JSON_THROW_ON_ERROR));
    }
    private function race(User $actor,int $id,string $version):void
    {
        $processes=[];$start=(string)(microtime(true)+2);$results=[];$failed=false;
        for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,ROOTPATH.'spark','tasks:check','worker',(string)$actor->id,(string)$id,$version,$start],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOTPATH);if(!is_resource($process)){$failed=true;break;}fclose($pipes[0]);$processes[]=[$process,$pipes];}
        foreach($processes as [$process,$pipes]){$output=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!preg_match('/\{"status":.*\}/',$output,$match)){$failed=true;continue;}$results[]=json_decode($match[0],true);}
        if($failed||count($results)!==2){throw new \RuntimeException('Fixture worker failed; all launched workers finished.');}
        $this->check(max($results[0]['began'],$results[1]['began'])<min($results[0]['ended'],$results[1]['ended']),'Task workers overlapped on separate MariaDB connections');$statuses=array_column($results,'status');sort($statuses);$this->check($statuses===[200,409],'Simultaneous task edits yield one success and one stale conflict');
    }
    public function run(array $params):void
    {
        if(($params[0]??'')==='worker'){$this->worker(array_slice($params,1));return;}
        $db=db_connect();$this->check($db->getDatabase()==='my_crm_db','Dedicated independent database');$provider=auth()->getProvider();$s=new TaskService();$projects=new ProjectService();$clients=new ClientService();$ids=[];$emails=[];$tag='taskcheck'.bin2hex(random_bytes(4));$before=$this->authenticationDigest($db);
        $this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);
        try{
            foreach(['tasks','tasks/new','tasks/999'] as $path){[$status]=$this->http('GET',$path);$this->check($status===302,'Guest rejected '.$path);}
            foreach(['owner','member','outsider','manager'] as $role){$email=$tag.'.'.$role.'@example.invalid';$password=bin2hex(random_bytes(20)).'aA9!';$provider->save(new User(['username'=>$tag.$role,'email'=>$email,'password'=>$password]));$userId=(int)$provider->getInsertID();$ids[]=$userId;$emails[]=$email;$actor=$provider->findById($userId);$actor->activate();$actor->addGroup('user');$actors[$role]=$actor;$credentials[$role]=[$email,$password];}
            $owner=$actors['owner'];$client=$clients->save($owner,['type'=>'company','company_name'=>$tag.' private client','status_id'=>'1']);$project=$projects->save($owner,['name'=>$tag.' project','client_id'=>(string)$client,'status_id'=>'1']);
            $pv=fn()=>(string)$projects->find($owner,$project)['version'];foreach(['member','manager'] as $role){$projects->addMember($owner,$project,['user_id'=>(string)$actors[$role]->id,'role'=>$role,'version'=>$pv()]);}
            $data=['project_id'=>(string)$project,'title'=>$tag.' task','description'=>'<script>fictional</script>','assignee_id'=>(string)$actors['member']->id,'start_date'=>'2026-11-01','due_date'=>'2026-12-01'];
            $this->login($credentials['owner']);[$status]=$this->http('GET','modules/tasks');$this->check($status===302,'Legacy Tasks destination redirects');[$status,$body]=$this->http('GET','tasks/new?project='.$project);$this->check($status===200&&str_contains($body,'Create task'),'Task form renders eligible project');
            [$status]=$this->http('POST','tasks',$data);$this->check($status===403,'Task creation CSRF');
            foreach([['title'=>''],['status_id'=>'999'],['priority_id'=>'999'],['due_date'=>'2026-01-01'],['assignee_id'=>(string)$actors['outsider']->id]] as $invalid){[$status]=$this->post('tasks',array_replace($data,$invalid),true);$this->check($status===422,'Task validation rejects invalid fields');}
            [$status]=$this->post('tasks',$data+['created_by'=>(string)$actors['outsider']->id,'code'=>'FORGED','completed_at'=>'2020-01-01']);$this->check(in_array($status,[302,303],true),'HTTP task creation');
            $id=(int)$db->table('tasks')->where('title',$data['title'])->get()->getRow('id');$version=fn()=>(string)$db->table('tasks')->where('id',$id)->get()->getRow('version');
            $row=$s->find($owner,$id);$this->check((int)$row['created_by']===(int)$owner->id&&$row['completed_at']===null&&str_starts_with($row['code'],'TK-'),'Server derives identity and completion');
            [$status,$body]=$this->http('GET','tasks/'.$id);$this->check($status===200&&str_contains($body,'&lt;script&gt;fictional')&&!str_contains($body,'<script>fictional'),'Task profile escapes content');
            foreach(['','/status','/assign','/archive','/restore'] as $suffix){[$status]=$this->http('POST','tasks/'.$id.$suffix,['version'=>$version()]);$this->check($status===403,'CSRF protects task mutation '.$suffix);}
            [$status]=$this->post('tasks/'.$id,['title'=>$tag.' edited','version'=>$version()]);$this->check(in_array($status,[302,303],true),'Edit task');
            foreach(['','/status','/assign','/archive','/restore'] as $suffix){[$status]=$this->post('tasks/'.$id.$suffix,['title'=>'Stale','status_id'=>'2','assignee_id'=>'','version'=>'1'],true);$this->check($status===409,'HTTP stale mutation returns 409 '.$suffix);}
            [$status]=$this->post('tasks/'.$id.'/status',['status_id'=>'4','version'=>$version()]);$this->check(in_array($status,[302,303],true)&&$s->find($owner,$id)['completed_at']!==null,'Complete task');
            [$status]=$this->post('tasks/'.$id.'/status',['status_id'=>'1','version'=>$version()]);$this->check(in_array($status,[302,303],true)&&$s->find($owner,$id)['completed_at']===null,'Reopen task');
            $hidden=$s->save($owner,['project_id'=>(string)$project,'title'=>$tag.' hidden']);
            $this->login($credentials['member']);[$status,$body]=$this->http('GET','tasks/'.$id);$this->check($status===200&&!str_contains($body,$tag.' private client'),'Assigned member views without private Client data');
            [$status]=$this->http('GET','tasks/'.$hidden);$this->check($status===404,'Member cannot view unassigned sibling task');[$status]=$this->http('GET','clients/'.$client);$this->check($status===404,'Task assignment grants no Client access');
            [$status]=$this->post('tasks/'.$id,['title'=>$tag.' member edited','version'=>$version()]);$this->check(in_array($status,[302,303],true),'Assigned member edits task');
            foreach(['/assign','/archive','/restore'] as $suffix){[$status]=$this->post('tasks/'.$id.$suffix,['assignee_id'=>(string)$owner->id,'version'=>$version()],true);$this->check($status===404,'Member cannot administer task '.$suffix);}
            $projects->removeMember($owner,$project,(int)$actors['member']->id,$pv());[$status]=$this->http('GET','tasks/'.$id);$this->check($status===404,'Membership revocation immediately denies task');[$status]=$this->post('tasks/'.$id.'/status',['status_id'=>'4','version'=>$version()],true);$this->check($status===404,'Revoked member cannot submit stale task form');
            $this->login($credentials['outsider']);foreach(['','/edit'] as $suffix){[$status]=$this->http('GET','tasks/'.$id.$suffix);$this->check($status===404,'Foreign direct task URL denied');}
            foreach(['','/status','/assign','/archive','/restore'] as $suffix){[$status]=$this->post('tasks/'.$id.$suffix,['title'=>'Foreign','status_id'=>'4','assignee_id'=>(string)$actors['outsider']->id,'version'=>$version()],true);$this->check($status===404,'Foreign mutation denied '.$suffix);}
            $this->login($credentials['manager']);[$status]=$this->post('tasks/'.$id.'/assign',['assignee_id'=>(string)$owner->id,'version'=>$version()]);$this->check(in_array($status,[302,303],true),'Project manager reassigns task');
            $actors['manager']->removeGroup('user');$actors['manager']->addPermission('foundation.access','projects.view','tasks.view');[$status]=$this->post('tasks/'.$id.'/status',['status_id'=>'4','version'=>$version()],true);$this->check($status===404,'Missing Shield capability overrides project manager role');
            $this->login($credentials['owner']);[$status]=$this->post('tasks/'.$id.'/archive',['version'=>$version()]);$this->check(in_array($status,[302,303],true),'Archive task');[$status]=$this->post('tasks/'.$id.'/status',['status_id'=>'4','version'=>$version()],true);$this->check($status===422,'Archived task cannot change status');
            $projects->archive($owner,$project,true,$pv());[$status]=$this->post('tasks/'.$id.'/restore',['version'=>$version()],true);$this->check($status===422,'Archived parent blocks task restore');$projects->archive($owner,$project,false,$pv());
            [$status]=$this->post('tasks/'.$id.'/restore',['version'=>$version()]);$this->check(in_array($status,[302,303],true),'Restore task');
            for($i=0;$i<22;$i++){$s->save($owner,['project_id'=>(string)$project,'title'=>$tag.' page '.$i,'priority_id'=>'3']);}[$status,$body]=$this->http('GET','tasks?q='.$tag.'&project='.$project.'&priority=3&status=1&page=2');$this->check($status===200&&str_contains($body,'Page 2 of 2'),'Search project status priority filters and pagination');
            [$status,$body]=$this->http('GET','projects/'.$project);$this->check($status===200&&str_contains($body,$tag.' member edited'),'Project profile lists authorized real tasks');
            $race=$s->save($owner,['project_id'=>(string)$project,'title'=>$tag.' concurrent']);$this->race($owner,$race,'1');$this->check($db->table('task_history')->where('task_id',$race)->countAllResults()===2,'Concurrent write creates one history event');
            $this->check($db->table('task_history')->where('task_id',$id)->countAllResults()===(int)$version(),'Each committed task version has one event');
        }finally{
            if($ids){$db->table('tasks')->whereIn('created_by',$ids)->delete();$db->table('projects')->whereIn('created_by',$ids)->delete();$db->table('clients')->whereIn('owner_id',$ids)->delete();}foreach($ids as $userId){$db->table('auth_logins')->where('user_id',$userId)->delete();$provider->delete($userId,true);}foreach($emails as $email){$db->table('auth_logins')->where('identifier',$email)->delete();}curl_close($this->curl);unset($credentials);
        }
        $this->check(hash_equals($before,$this->authenticationDigest($db)),'Permanent authentication security unchanged');CLI::write('Tasks HTTP/concurrency checks passed: '.$this->checks.'. Fictional fixtures removed.','green');
    }
}