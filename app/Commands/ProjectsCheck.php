<?php

declare(strict_types=1);
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use CodeIgniter\Shield\Entities\User;
use App\Services\{ProjectService,ClientService,ProjectInputException,DatabaseUnitOfWork};

class ProjectsCheck extends BaseCommand
{
    protected $group='Verification';protected $name='projects:check';protected $description='Verify Projects ownership, membership, HTTP security and concurrent fixture operations.';
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
        [, $body]=$this->http('GET','projects');if(!preg_match('/name="my_crm_csrf_token" value="([^"]+)"/',$body,$m)){throw new \RuntimeException('Verification CSRF form missing.');}
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
        $db=db_connect();$actor=auth()->getProvider()->findById((int)$actorId);$row=$db->table('projects')->where('id',(int)$projectId)->get()->getRowArray();
        if($db->getDatabase()!=='my_crm_db'||!$actor||!str_starts_with($actor->username,'projcheck')||!$row||(int)$row['created_by']!==(int)$actorId||abs((float)$start-microtime(true))>30){throw new \RuntimeException('Worker fixture guard failed.');}
        while(microtime(true)<(float)$start){usleep(10000);}$began=microtime(true);
        try{DatabaseUnitOfWork::run($db,function()use($db,$actor,$projectId,$version){DatabaseUnitOfWork::lock($db,'projects',(int)$projectId);usleep(600000);(new ProjectService($db))->save($actor,['name'=>'Concurrent fictional project','status_id'=>'2','version'=>$version],(int)$projectId);});$status=200;}catch(ProjectInputException $e){$status=$e->status;}
        CLI::write(json_encode(['status'=>$status,'began'=>$began,'ended'=>microtime(true)],JSON_THROW_ON_ERROR));
    }
    private function race(User $actor,int $id,string $version):void
    {
        $processes=[];$start=(string)(microtime(true)+2);$results=[];$failed=false;
        for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,ROOTPATH.'spark','projects:check','worker',(string)$actor->id,(string)$id,$version,$start],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOTPATH);if(!is_resource($process)){$failed=true;break;}fclose($pipes[0]);$processes[]=[$process,$pipes];}
        foreach($processes as [$process,$pipes]){$output=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!preg_match('/\{"status":.*\}/',$output,$match)){$failed=true;continue;}$results[]=json_decode($match[0],true);}
        if($failed||count($results)!==2){throw new \RuntimeException('Fixture worker failed; all launched workers finished.');}
        $this->check(max($results[0]['began'],$results[1]['began'])<min($results[0]['ended'],$results[1]['ended']),'Project workers overlapped on separate MariaDB connections');$statuses=array_column($results,'status');sort($statuses);$this->check($statuses===[200,409],'Simultaneous project edits yield one success and one stale conflict');
    }
    public function run(array $params):void
    {
        if(($params[0]??'')==='worker'){$this->worker(array_slice($params,1));return;}
        $db=db_connect();$this->check($db->getDatabase()==='my_crm_db','Dedicated project database');$provider=auth()->getProvider();$s=new ProjectService();$clients=new ClientService();$ids=[];$emails=[];$tag='projcheck'.bin2hex(random_bytes(4));$before=$this->authenticationDigest($db);
        $this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);
        try{
            foreach(['projects','projects/new','projects/999'] as $path){[$status]=$this->http('GET',$path);$this->check($status===302,'Guests denied '.$path);}
            foreach(['admin','owner','manager','member','outsider','reader'] as $role){$email=$tag.'.'.$role.'@example.invalid';$password=bin2hex(random_bytes(20)).'aA9!';$provider->save(new User(['username'=>$tag.$role,'email'=>$email,'password'=>$password]));$id=(int)$provider->getInsertID();$ids[]=$id;$emails[]=$email;$actor=$provider->findById($id);$actor->activate();if($role==='reader'){$actor->addPermission('foundation.access','projects.view');}else{$actor->addGroup($role==='admin'?'admin':'user');}$actors[$role]=$actor;$credentials[$role]=[$email,$password];}
            $clientId=$clients->save($actors['owner'],['type'=>'company','company_name'=>$tag.' private client','email'=>$tag.'.private@example.invalid','status_id'=>'1']);$foreignClient=$clients->save($actors['admin'],['type'=>'company','company_name'=>$tag.' foreign client','status_id'=>'1']);
            $data=['name'=>$tag.' project','description'=>'<script>fictional</script>','client_id'=>(string)$clientId,'status_id'=>'1','start_date'=>'2026-11-01','end_date'=>'2026-12-01'];
            $this->login($credentials['owner']);[$status]=$this->http('GET','modules/projects');$this->check($status===302,'Legacy placeholder redirects through authorization');
            [$status,$body]=$this->http('GET','projects/new');$this->check($status===200&&str_contains($body,'project_id')===false&&str_contains($body,'client_id'),'Project form and eligible clients render');
            [$status]=$this->http('POST','projects',$data);$this->check($status===403,'Create requires CSRF');
            foreach([['name'=>''],['end_date'=>'2026-01-01'],['start_date'=>'2026-02-30'],['status_id'=>'9999'],['client_id'=>['1']]] as $invalid){[$status]=$this->post('projects',array_replace($data,$invalid));$this->check($status===422,'Invalid project fields rejected');}
            [$status]=$this->post('projects',array_replace($data,['client_id'=>(string)$foreignClient]));$this->check($status===404,'Foreign client creation denied');
            [$status]=$this->post('projects',array_replace($data,['owner_id'=>(string)$actors['admin']->id]));$this->check($status===404,'Forged owner denied');
            [$status]=$this->post('projects',$data+['created_by'=>(string)$actors['admin']->id,'code'=>'FORGED','version'=>'99']);$this->check(in_array($status,[302,303],true),'Create project through HTTP');
            $id=(int)$db->table('projects')->where('name',$data['name'])->get()->getRow('id');$version=fn()=>(string)$db->table('projects')->where('id',$id)->get()->getRow('version');
            $this->check((int)$s->find($actors['owner'],$id)['created_by']===(int)$actors['owner']->id&&$version()==='1','Server derives creator and version');
            [$status,$body]=$this->http('GET','projects/'.$id);$this->check($status===200&&str_contains($body,'&lt;script&gt;fictional')&&!str_contains($body,'<script>fictional'),'Profile escapes input and displays activity');
            $private=$s->save($actors['admin'],array_replace($data,['name'=>$tag.' hidden project']));[$status,$body]=$this->http('GET','clients/'.$clientId);$this->check($status===200&&str_contains($body,$tag.' project')&&!str_contains($body,$tag.' hidden project'),'Client profile enforces independent project scope');
            $old=$version();[$status]=$this->post('projects/'.$id,array_replace($data,['name'=>$tag.' updated','status_id'=>'2','version'=>$old]));$this->check(in_array($status,[302,303],true),'Edit project and status');
            [$status,$body]=$this->post('projects/'.$id,$data+['version'=>$old],true);$this->check($status===409&&isset(json_decode($body,true)['errors']),'Stale AJAX edit returns HTTP 409');
            foreach(['','/archive','/restore','/members','/members/'.$actors['member']->id.'/remove'] as $suffix){[$status]=$this->http('POST','projects/'.$id.$suffix,['version'=>$version(),'user_id'=>(string)$actors['member']->id,'role'=>'member']);$this->check($status===403,'CSRF protects project mutation '.$suffix);}
            $this->login($credentials['outsider']);$snapshot=$db->table('projects')->where('id',$id)->get()->getRowArray();
            foreach(['','/edit'] as $suffix){[$status]=$this->http('GET','projects/'.$id.$suffix);$this->check($status===404,'Unassigned direct project URL denied');}
            foreach(['','/archive','/restore','/members','/members/'.$actors['member']->id.'/remove'] as $suffix){[$status]=$this->post('projects/'.$id.$suffix,$data+['version'=>$version(),'user_id'=>(string)$actors['member']->id,'role'=>'manager'],true);$this->check($status===404,'Unassigned manipulated project mutation denied');}
            $this->check($snapshot===$db->table('projects')->where('id',$id)->get()->getRowArray(),'Denied requests preserve foreign project');
            $s->addMember($actors['owner'],$id,['version'=>$version(),'user_id'=>(string)$actors['manager']->id,'role'=>'manager']);$s->addMember($actors['owner'],$id,['version'=>$version(),'user_id'=>(string)$actors['member']->id,'role'=>'member']);
            $this->login($credentials['member']);[$status,$body]=$this->http('GET','projects/'.$id);$this->check($status===200&&str_contains($body,'Restricted client')&&!str_contains($body,$tag.' private client')&&!str_contains($body,$tag.'.private@example.invalid')&&!str_contains($body,'href="http://localhost:8081/clients/'.$clientId.'"'),'Assigned project does not leak private client fields or profile link');
            [$status]=$this->http('GET','clients/'.$clientId);$this->check($status===404,'Membership does not grant Clients permission');[$status]=$this->http('GET','projects?client='.$clientId);$this->check($status===404,'Client filter cannot probe unauthorized clients');
            foreach(['','/archive','/members'] as $suffix){[$status]=$this->post('projects/'.$id.$suffix,$data+['version'=>$version(),'user_id'=>(string)$actors['outsider']->id,'role'=>'member']);$this->check($status===404,'Ordinary member cannot edit administer or archive');}
            $this->login($credentials['manager']);[$status]=$this->post('projects/'.$id,['name'=>$tag.' manager update','status_id'=>'3','version'=>$version()]);$this->check(in_array($status,[302,303],true),'Manager edits details without Clients access');
            [$status]=$this->post('projects/'.$id,['name'=>'Forged owner','status_id'=>'1','version'=>$version(),'owner_id'=>(string)$actors['manager']->id]);$this->check($status===404,'Manager cannot change owner');
            [$status]=$this->post('projects/'.$id.'/members',['version'=>$version(),'user_id'=>(string)$actors['outsider']->id,'role'=>'manager']);$this->check($status===404,'Manager cannot promote another manager');
            [$status]=$this->post('projects/'.$id.'/members',['version'=>$version(),'user_id'=>(string)$actors['outsider']->id,'role'=>'member']);$this->check(in_array($status,[302,303],true),'Manager adds regular member');
            [$status]=$this->post('projects/'.$id.'/members/'.$actors['outsider']->id.'/remove',['version'=>$version()]);$this->check(in_array($status,[302,303],true),'Manager removes regular member');
            [$status]=$this->post('projects/'.$id.'/members/'.$actors['manager']->id.'/remove',['version'=>$version()]);$this->check($status===404,'Manager cannot remove a manager');
            $stale=$version();$s->removeMember($actors['owner'],$id,(int)$actors['manager']->id,$stale);[$status]=$this->post('projects/'.$id,['name'=>'Revoked attempt','status_id'=>'1','version'=>$stale]);$this->check($status===404,'Membership revocation immediately rejects stale manager form');
            $s->addMember($actors['owner'],$id,['version'=>$version(),'user_id'=>(string)$actors['manager']->id,'role'=>'manager']);$actors['manager']->removeGroup('user');$actors['manager']->addPermission('foundation.access','projects.view');
            [$status]=$this->post('projects/'.$id,['name'=>'Revoked global edit','status_id'=>'1','version'=>$version()]);$this->check($status===404,'Shield permission revocation is effective on the next HTTP request');
            $s->addMember($actors['owner'],$id,['version'=>$version(),'user_id'=>(string)$actors['reader']->id,'role'=>'manager']);$this->login($credentials['reader']);[$status]=$this->http('GET','projects/'.$id);$this->check($status===200,'Read-only Shield user can view assigned project');[$status]=$this->post('projects/'.$id,['name'=>'No permission','status_id'=>'1','version'=>$version()]);$this->check($status===404,'Project role cannot override missing Shield edit permission');
            $this->login($credentials['owner']);$old=$version();[$status]=$this->post('projects/'.$id.'/archive',['version'=>$old]);$this->check(in_array($status,[302,303],true),'Owner archives project');[$status]=$this->post('projects/'.$id.'/restore',['version'=>$old],true);$this->check($status===409,'Stale restoration is rejected');
            $this->login($credentials['member']);[$status]=$this->http('GET','projects/'.$id);$this->check($status===404,'Archived projects hidden from members');
            $this->login($credentials['owner']);[$status]=$this->post('projects/'.$id.'/restore',['version'=>$version()]);$this->check(in_array($status,[302,303],true),'Owner restores project and memberships');
            $clients->archive($actors['owner'],$clientId,true);$this->check($s->find($actors['owner'],$id)['archived_at']===null,'Client archive does not cascade to projects');[$status]=$this->post('projects',$data);$this->check($status===422,'Archived clients cannot receive new projects');$clients->archive($actors['owner'],$clientId,false);
            for($i=0;$i<22;$i++){$s->save($actors['owner'],array_replace($data,['name'=>$tag.' page '.$i]));}[$status,$body]=$this->http('GET','projects?q='.$tag.'&client='.$clientId.'&status=1&page=2');$this->check($status===200&&str_contains($body,'Page 2 of 2'),'Search client/status filters and pagination');
            $race=$s->save($actors['owner'],array_replace($data,['name'=>$tag.' race']));$this->race($actors['owner'],$race,'1');
            $this->login($credentials['admin']);[$status]=$this->post('projects/'.$id,['name'=>$tag.' assigned','status_id'=>'4','version'=>$version(),'owner_id'=>(string)$actors['outsider']->id]);$this->check(in_array($status,[302,303],true)&&(int)$s->find($actors['admin'],$id)['owner_id']===(int)$actors['outsider']->id,'Administrator assigns ownership');
            $this->login($credentials['owner']);[$status]=$this->http('GET','projects/'.$id);$this->check($status===404,'Former owner immediately loses access');
            $history=$db->table('project_history')->where('project_id',$id)->countAllResults();$this->check($history===(int)$version(),'Every committed version has exactly one history event');
            [$status]=$this->http('GET','projects/'.$id.'/archive');$this->check($status===404,'GET cannot archive');
        }finally{
            if($ids){$db->table('projects')->whereIn('created_by',$ids)->delete();$db->table('clients')->whereIn('owner_id',$ids)->delete();}foreach($ids as $id){$db->table('auth_logins')->where('user_id',$id)->delete();$provider->delete($id,true);}foreach($emails as $email){$db->table('auth_logins')->where('identifier',$email)->delete();}curl_close($this->curl);unset($credentials);
        }
        $this->check(hash_equals($before,$this->authenticationDigest($db)),'Permanent authentication security fields unchanged');CLI::write('Projects HTTP/concurrency checks passed: '.$this->checks.'. All generated project, membership, history, client and user fixtures removed.','green');
    }
}
