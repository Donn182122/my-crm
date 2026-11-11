<?php

declare(strict_types=1);
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use CodeIgniter\Shield\Entities\User;
use App\Services\{TaskService,ProjectService,ClientService,TaskInputException,DatabaseUnitOfWork};

class TasksKanbanCheck extends BaseCommand
{
    protected $group='Verification';protected $name='tasks:kanban-check';protected $description='Verify Tasks Kanban scope, filters, controls and shared status endpoint security.';
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
    public function run(array $params):void
    {
        $db=db_connect();$this->check($db->getDatabase()==='my_crm_db','Dedicated independent database');$s=new TaskService();$projects=new ProjectService();$clients=new ClientService();$provider=auth()->getProvider();$ids=[];$emails=[];$tag='taskboardcheck'.bin2hex(random_bytes(4));$before=$this->authenticationDigest($db);
        $this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);
        try{
            [$status]=$this->http('GET','tasks/kanban');$this->check($status===302,'Guest Kanban access rejected');
            foreach(['owner','member','outsider','reader'] as $role){$email=$tag.'.'.$role.'@example.invalid';$password=bin2hex(random_bytes(20)).'aA9!';$provider->save(new User(['username'=>$tag.$role,'email'=>$email,'password'=>$password]));$uid=(int)$provider->getInsertID();$ids[]=$uid;$emails[]=$email;$actor=$provider->findById($uid);$actor->activate();if($role==='reader'){$actor->addPermission('foundation.access','projects.view','tasks.view');}else{$actor->addGroup('user');}$actors[$role]=$actor;$credentials[$role]=[$email,$password];}
            $owner=$actors['owner'];$client=$clients->save($owner,['type'=>'company','company_name'=>$tag.' private client','status_id'=>'1']);$project=$projects->save($owner,['name'=>$tag.' project','client_id'=>(string)$client,'status_id'=>'1']);
            $pv=fn()=>(string)$projects->find($owner,$project)['version'];foreach(['member','reader'] as $role){$projects->addMember($owner,$project,['user_id'=>(string)$actors[$role]->id,'role'=>'member','version'=>$pv()]);}
            $first=$s->save($owner,['project_id'=>(string)$project,'title'=>$tag.' assigned','assignee_id'=>(string)$actors['member']->id,'priority_id'=>'3','due_date'=>'2026-12-01']);$readerTask=$s->save($owner,['project_id'=>(string)$project,'title'=>$tag.' reader','assignee_id'=>(string)$actors['reader']->id]);$hidden=$s->save($owner,['project_id'=>(string)$project,'title'=>$tag.' hidden']);
            $version=fn()=>(string)$db->table('tasks')->where('id',$first)->get()->getRow('version');
            for($i=0;$i<22;$i++){$s->save($owner,['project_id'=>(string)$project,'title'=>$tag.' page '.$i,'priority_id'=>'4']);}
            $this->login($credentials['owner']);[$status,$body]=$this->http('GET','tasks/kanban?project='.$project);$this->check($status===200&&str_contains($body,'data-task-board')&&str_contains($body,'tasks-kanban.js'),'Authorized Kanban renders');$this->check(str_contains($body,$s->find($owner,$first)['code'])&&str_contains($body,'Due: 2026-12-01')&&str_contains($body,$tag.' project'),'Cards contain task project assignee priority and date');
            [$status,$body]=$this->http('GET','tasks/kanban?'.http_build_query(['project'=>(string)$project,'q'=>$tag.' page','priority'=>'4','status'=>'1','columns'=>['1'=>'2']]));$this->check($status===200&&substr_count($body,'data-task="')===2&&str_contains($body,'Page 2 / 2'),'Bounded column pagination and combined filters');
            [$status,$body]=$this->http('GET','tasks/kanban?'.http_build_query(['project'=>(string)$project,'assignee'=>(string)$actors['member']->id,'priority'=>'3','status'=>'1']));$this->check($status===200&&substr_count($body,'data-task="')===1&&str_contains($body,$tag.' assigned'),'Assignee filter is scoped');
            [$status,$body]=$this->http('GET','tasks?project='.$project);$this->check($status===200&&str_contains($body,'aria-label="Tasks view"')&&str_contains($body,'tasks/kanban?'),'List/Kanban switcher renders');
            [$status,$body]=$this->http('GET','projects/'.$project);$this->check($status===200&&str_contains($body,'tasks/kanban?project='.$project),'Authorized project board link');
            [$status]=$this->http('POST','tasks/'.$first.'/status',['status_id'=>'4','version'=>$version()]);$this->check($status===403,'Board status endpoint requires CSRF');
            [$status]=$this->post('tasks/'.$first.'/status',['status_id'=>'99999','version'=>$version()],true);$this->check($status===422,'Invalid board destination rejected');
            $stale=$version();[$status,$body]=$this->post('tasks/'.$first.'/status',['status_id'=>'4','version'=>$stale,'project_id'=>'999999'],true);$this->check($status===200&&isset(json_decode($body,true)['csrf']),'Authorized move uses existing JSON status endpoint');$row=$s->find($owner,$first);$this->check($row['completed_at']!==null&&(int)$row['project_id']===$project,'Completion derived and forged project ignored');
            [$status]=$this->post('tasks/'.$first.'/status',['status_id'=>'2','version'=>$stale],true);$this->check($status===409,'Stale direct board request rejected');
            [$status,$body]=$this->http('GET','tasks/kanban?project='.$project.'&status=4');$this->check($status===200&&str_contains($body,'data-task="'.$first.'"'),'Board reflects saved status');[$status,$body]=$this->http('GET','tasks?project='.$project.'&status=4');$this->check($status===200&&str_contains($body,$tag.' assigned'),'List reflects saved board status');
            $this->login($credentials['member']);[$status,$body]=$this->http('GET','tasks/kanban?project='.$project);$this->check($status===200&&str_contains($body,'data-task="'.$first.'"')&&!str_contains($body,$tag.' hidden')&&!str_contains($body,$tag.' private client'),'Member cards and counts exclude unauthorized tasks and client data');
            [$status]=$this->post('tasks/'.$hidden.'/status',['status_id'=>'4','version'=>'1'],true);$this->check($status===404,'Member cannot mutate sibling task directly');
            [$status]=$this->post('tasks/'.$first.'/status',['status_id'=>'1','version'=>$version()],true);$this->check($status===200&&$s->find($owner,$first)['completed_at']===null,'Assigned member can reopen through existing rules');
            $projects->removeMember($owner,$project,(int)$actors['member']->id,$pv());[$status]=$this->http('GET','tasks/kanban?project='.$project);$this->check($status===404,'Revoked project membership denies board');[$status]=$this->post('tasks/'.$first.'/status',['status_id'=>'4','version'=>$version()],true);$this->check($status===404,'Revoked member direct move rejected');
            $this->login($credentials['outsider']);[$status,$body]=$this->http('GET','tasks/kanban');$this->check($status===200&&!str_contains($body,$tag.' assigned')&&!str_contains($body,$tag.' project'),'Foreign owner data and picker options hidden');[$status]=$this->http('GET','tasks/kanban?project='.$project);$this->check($status===404,'Foreign project filter rejected');[$status]=$this->post('tasks/'.$first.'/status',['status_id'=>'4','version'=>$version()],true);$this->check($status===404,'Foreign direct status request rejected');
            $this->login($credentials['reader']);[$status,$body]=$this->http('GET','tasks/kanban?project='.$project);$this->check($status===200&&str_contains($body,'data-task="'.$readerTask.'"')&&!str_contains($body,'class="task-move"'),'Read-only actor has no drag or Move controls');[$status]=$this->post('tasks/'.$readerTask.'/status',['status_id'=>'4','version'=>'1'],true);$this->check($status===404,'Missing Shield status permission cannot be bypassed');
            $this->login($credentials['owner']);$s->archive($owner,$first,true,$version());[$status,$body]=$this->http('GET','tasks/kanban?project='.$project.'&archive=archived');$this->check($status===200&&str_contains($body,'data-task="'.$first.'"')&&!str_contains($body,'class="task-move"'),'Archived board cards have no mutations');[$status]=$this->post('tasks/'.$first.'/status',['status_id'=>'4','version'=>$version()],true);$this->check($status===422,'Archived direct status write rejected');$s->archive($owner,$first,false,$version());
            $projects->archive($owner,$project,true,$pv());[$status,$body]=$this->http('GET','tasks/kanban?project='.$project);$this->check($status===200&&!str_contains($body,'class="task-move"'),'Archived parent board is read-only');[$status]=$this->post('tasks/'.$first.'/status',['status_id'=>'4','version'=>$version()],true);$this->check($status===422,'Archived parent direct write rejected');$projects->archive($owner,$project,false,$pv());
            [$status]=$this->post('tasks/'.$first.'/reorder',['version'=>$version(),'position'=>'1'],true);$this->check($status===404,'No misleading reorder endpoint exists');
            $this->check($db->table('task_history')->where('task_id',$first)->countAllResults()===(int)$version(),'Exactly one history event per committed task version');
        }finally{
            if($ids){$db->table('tasks')->whereIn('created_by',$ids)->delete();$db->table('projects')->whereIn('created_by',$ids)->delete();$db->table('clients')->whereIn('owner_id',$ids)->delete();}foreach($ids as $uid){$db->table('auth_logins')->where('user_id',$uid)->delete();$provider->delete($uid,true);}foreach($emails as $email){$db->table('auth_logins')->where('identifier',$email)->delete();}curl_close($this->curl);unset($credentials);
        }
        $this->check(hash_equals($before,$this->authenticationDigest($db)),'Permanent authentication unchanged');CLI::write('Tasks Kanban HTTP checks passed: '.$this->checks.'. Fictional fixtures removed.','green');
    }
}