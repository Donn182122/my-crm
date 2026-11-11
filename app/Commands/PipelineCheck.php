<?php

declare(strict_types=1);
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use CodeIgniter\Shield\Entities\User;
use App\Services\{LeadService,ClientService,LeadConversionService,LeadInputException,DatabaseUnitOfWork};

class PipelineCheck extends BaseCommand
{
    protected $group='Verification';protected $name='pipeline:check';protected $description='Verify pipeline/conversion HTTP and parallel MariaDB fixture operations.';
    private $curl;private int $checks=0;private string $jobRoot;private array $jobFiles=[];
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
        [, $body]=$this->http('GET','leads');if(!preg_match('/name="my_crm_csrf_token" value="([^"]+)"/',$body,$m)){throw new \RuntimeException('Verification CSRF form missing.');}
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
    private function key(string $body):string{if(!preg_match('/name="request_key" value="([a-f0-9]{64})"/',$body,$m)){throw new \RuntimeException('Confirmation preview missing.');}return $m[1];}
    private function worker(string $name):void
    {
        if(!preg_match('/^[a-f0-9]{16}-[ab]$/D',$name)){throw new \RuntimeException('Invalid verification job name.');}
        $root=ROOTPATH.'.tools/pipeline-concurrency/';$job=json_decode(file_get_contents($root.$name.'.json'),true,32,JSON_THROW_ON_ERROR);$db=db_connect();
        if($db->getDatabase()!=='my_crm_db'){throw new \RuntimeException('Worker database isolation failed.');}
        $actor=auth()->getProvider()->findById((int)$job['actor_id']);$lead=$db->table('leads')->where('id',(int)$job['lead_id'])->get()->getRowArray();
        if(!$actor||!str_starts_with($actor->username,'pipecheck')||!$lead||(int)$lead['created_by']!==(int)$actor->id){throw new \RuntimeException('Worker fixture ownership failed.');}
        if(($job['command']['mode']??'')==='link'){$client=$db->table('clients')->where('id',(int)$job['command']['client_id'])->get()->getRowArray();if(!$client||(int)$client['owner_id']!==(int)$actor->id){throw new \RuntimeException('Worker client fixture ownership failed.');}}
        $start=(float)$job['start_at'];if(abs($start-microtime(true))>30){throw new \RuntimeException('Stale verification job.');}while(microtime(true)<$start){usleep(10000);}
        $began=microtime(true);try{$result=DatabaseUnitOfWork::run($db,function()use($db,$actor,$job){DatabaseUnitOfWork::lock($db,'leads',(int)$job['lead_id']);usleep(600000);
            if($job['kind']==='stage'){(new LeadService($db))->transition($actor,(int)$job['lead_id'],$job['version'],$job['status_id']);return ['stage'=>true];}
            $result=(new LeadConversionService($db))->convert($actor,(int)$job['lead_id'],$job['command'],$job['request_key'],true);return ['client_id'=>(int)$result['client']['id'],'conversion_id'=>(int)$result['conversion']['id'],'replayed'=>$result['replayed']];
        });$output=['status'=>200,'result'=>$result];}catch(LeadInputException $e){$output=['status'=>$e->status];}
        $output['began']=$began;$output['ended']=microtime(true);file_put_contents($root.$name.'.result.json',json_encode($output,JSON_THROW_ON_ERROR));CLI::write('Fixture worker completed.');
    }
    private function parallel(array $a,array $b):array
    {
        $batch=bin2hex(random_bytes(8));$start=microtime(true)+2;$processes=[];$failed=false;
        foreach(['a'=>$a,'b'=>$b] as $suffix=>$job){
            $name=$batch.'-'.$suffix;$job['start_at']=$start;
            $this->jobFiles[]=$this->jobRoot.$name.'.json';$this->jobFiles[]=$this->jobRoot.$name.'.result.json';
            file_put_contents($this->jobRoot.$name.'.json',json_encode($job,JSON_THROW_ON_ERROR));
            $pipes=[];$process=proc_open([PHP_BINARY,ROOTPATH.'spark','pipeline:check','worker',$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOTPATH);
            if(!is_resource($process)){$failed=true;break;}
            fclose($pipes[0]);$processes[]=[$process,$pipes,$name];
        }
        // Always join every launched worker before the caller removes fixture records.
        $results=[];
        foreach($processes as [$process,$pipes,$name]){
            stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
            if($exit!==0||!is_file($this->jobRoot.$name.'.result.json')){$failed=true;continue;}
            $result=json_decode(file_get_contents($this->jobRoot.$name.'.result.json'),true);
            if(!is_array($result)){$failed=true;continue;}$results[]=$result;
        }
        if($failed||count($results)!==2){throw new \RuntimeException('An isolated verification worker failed. All launched workers have finished.');}
        $this->check(max($results[0]['began'],$results[1]['began'])<min($results[0]['ended'],$results[1]['ended']),'Workers overlapped on independent MariaDB connections');return $results;
    }
    public function run(array $params):void
    {
        if(($params[0]??'')==='worker'){$this->worker($params[1]??'');return;}
        $db=db_connect();$this->check($db->getDatabase()==='my_crm_db','Dedicated database enforced');$provider=auth()->getProvider();$leads=new LeadService();$clients=new ClientService();$conversion=new LeadConversionService();
        $this->jobRoot=ROOTPATH.'.tools/pipeline-concurrency/';if(!is_dir($this->jobRoot)){mkdir($this->jobRoot,0700,true);}
        $tag='pipecheck'.bin2hex(random_bytes(4));$ids=[];$emails=[];$authBefore=$this->authenticationDigest($db);
        $this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);
        try{
            [$status]=$this->http('GET','leads/kanban');$this->check($status===302,'Guest pipeline access denied');
            foreach(['admin','user','reader'] as $role){$email=$tag.'.'.$role.'@example.invalid';$password=bin2hex(random_bytes(20)).'!aA9';$provider->save(new User(['username'=>$tag.$role,'email'=>$email,'password'=>$password]));$id=(int)$provider->getInsertID();$ids[]=$id;$emails[]=$email;$actor=$provider->findById($id);$actor->activate();if($role==='reader'){$actor->addPermission('foundation.access','leads.view','clients.view');}else{$actor->addGroup($role);}$actors[$role]=$actor;$credentials[$role]=[$email,$password];}
            $source=(string)$db->table('lead_sources')->where('slug','manual')->get()->getRow('id');$qualified=(string)$db->table('lead_statuses')->where('slug','qualified')->get()->getRow('id');$new=(string)$db->table('lead_statuses')->where('slug','new')->get()->getRow('id');$converted=(string)$db->table('lead_statuses')->where('slug','converted')->get()->getRow('id');$active=(string)$db->table('client_statuses')->where('name_key','active')->get()->getRow('id');
            $makeLead=function(User $actor,string $suffix,?string $status=null,?int $owner=null)use($leads,$tag,$source,$qualified){$data=['type'=>'company','company_name'=>$tag.' '.$suffix,'email'=>$tag.'.'.$suffix.'@example.invalid','source_id'=>$source,'status_id'=>$status??$qualified];if($owner){$data['owner_id']=(string)$owner;}return $leads->save($actor,$data);};
            $makeClient=fn(User $actor,string $suffix)=>$clients->save($actor,['type'=>'company','company_name'=>$tag.' '.$suffix,'email'=>$tag.'.client'.$suffix.'@example.invalid','status_id'=>$active]);
            $version=fn(int $id)=>(string)$db->table('leads')->where('id',$id)->get()->getRow('version');
            $foreign=$makeLead($actors['admin'],'foreign');$foreignClient=$makeClient($actors['admin'],'foreign');$own=$makeLead($actors['user'],'own',$new);
            $this->login($credentials['user']);
            [$status,$body]=$this->http('GET','leads/kanban');$this->check($status===200&&str_contains($body,'kanban-move')&&!str_contains($body,$tag.' foreign'),'Scoped board and keyboard move controls');
            [$status]=$this->post('leads/'.$foreign.'/stage',['version'=>$version($foreign),'status_id'=>$qualified],true);$this->check($status===404,'Foreign drag/AJAX stage mutation denied');
            [$status]=$this->http('POST','leads/'.$own.'/stage',['version'=>$version($own),'status_id'=>$qualified],true);$this->check($status===403,'Stage mutation requires CSRF');
            $old=$version($own);[$status]=$this->post('leads/'.$own.'/stage',['version'=>$old,'status_id'=>$qualified],true);$this->check($status===200,'Authorized AJAX stage move');
            [$status,$body]=$this->post('leads/'.$own.'/stage',['version'=>$old,'status_id'=>$new],true);$this->check($status===409&&isset(json_decode($body,true)['errors']),'Stale AJAX stage move returns 409');
            [$status]=$this->post('leads/'.$own.'/stage',['version'=>$version($own),'status_id'=>$converted],true);$this->check($status===422,'Converted stage cannot be dragged into');
            [$status]=$this->http('GET','leads/'.$foreign.'/convert');$this->check($status===404,'Foreign conversion form denied');
            [$status]=$this->post('leads/'.$foreign.'/convert/preview',['version'=>$version($foreign),'mode'=>'create','client_status_id'=>$active]);$this->check($status===404,'Foreign conversion preview denied');
            [$status]=$this->post('leads/'.$own.'/convert/preview',['version'=>$version($own),'mode'=>'link','client_id'=>(string)$foreignClient,'copy_related'=>'0']);$this->check($status===404,'Unauthorized client selection denied');
            [$status,$body]=$this->http('GET','leads/'.$own.'/convert?q='.$tag);$this->check($status===200&&!str_contains($body,$tag.' foreign'),'Duplicate/client search hides unauthorized clients');
            $leads->saveChild($actors['user'],$own,'contacts',['version'=>$version($own),'first_name'=>'Disposable','last_name'=>'Contact','email'=>$tag.'.contact@example.invalid']);
            $leads->saveChild($actors['user'],$own,'addresses',['version'=>$version($own),'kind'=>'billing','line1'=>'123 Fictional Lane','city'=>'Sample City','country'=>'Philippines']);
            $input=['version'=>$version($own),'mode'=>'create','client_status_id'=>$active,'copy_related'=>'1'];
            [$status]=$this->http('POST','leads/'.$own.'/convert/preview',$input);$this->check($status===403,'Preview POST requires CSRF');
            [$status,$body]=$this->post('leads/'.$own.'/convert/preview',$input);$this->check($status===200&&str_contains($body,'Review conversion mapping')&&str_contains($body,'Disposable Contact'),'Mapping and related-record plan render');$key=$this->key($body);
            [$status]=$this->post('leads/'.$own.'/convert/confirm',['request_key'=>$key]);$this->check($status===422,'Explicit confirmation required');
            [$status]=$this->http('POST','leads/'.$own.'/convert/confirm',['request_key'=>$key,'confirm'=>'yes']);$this->check($status===403,'Confirmation requires CSRF');
            [$status]=$this->post('leads/'.$own.'/convert/confirm',['request_key'=>$key,'confirm'=>'yes','mode'=>'link','client_id'=>(string)$foreignClient]);$this->check(in_array($status,[302,303],true),'Confirmed conversion uses server-bound mapping');
            $row=$db->table('lead_conversions')->where('lead_id',$own)->get()->getRowArray();$clientId=(int)$row['client_id'];$this->check($clientId!==$foreignClient&&(int)$clients->find($actors['user'],$clientId)['owner_id']===(int)$actors['user']->id,'Conversion creates independently owned client');
            $clientDetails=$clients->details($actors['user'],$clientId);$this->check(count($clientDetails['contacts'])===1&&count($clientDetails['addresses'])===1,'Selected contacts and addresses copied');
            [$status,$body]=$this->http('GET','leads/'.$own.'/conversion');$this->check($status===200&&str_contains($body,'Open client profile'),'Authorized conversion result and profile link');
            $count=$db->table('clients')->countAllResults();[$status]=$this->post('leads/'.$own.'/convert/confirm',['request_key'=>$key,'confirm'=>'yes']);$this->check(in_array($status,[302,303],true)&&$db->table('clients')->countAllResults()===$count,'HTTP retry returns existing result without duplication');
            $db->table('clients')->where('id',$clientId)->update(['owner_id'=>(int)$actors['admin']->id]);
            [$status]=$this->http('GET','leads/'.$own.'/conversion');$this->check($status===404,'Lead ownership does not expose another owners resulting client');
            [$status,$body]=$this->http('GET','leads/'.$own);$this->check($status===200&&!str_contains($body,'View conversion result'),'Lead profile hides an unauthorized destination link');
            [$status]=$this->post('leads/'.$own.'/convert/confirm',['request_key'=>$key,'confirm'=>'yes']);$this->check($status===404,'Conversion retries recheck independent client ownership');
            $db->table('clients')->where('id',$clientId)->update(['owner_id'=>(int)$actors['user']->id]);
            $linkedLead=$makeLead($actors['user'],'linked');$target=$makeClient($actors['user'],'target');$before=$clients->find($actors['user'],$target);
            [$status,$body]=$this->post('leads/'.$linkedLead.'/convert/preview',['version'=>$version($linkedLead),'mode'=>'link','client_id'=>(string)$target,'copy_related'=>'0']);$this->check($status===200,'Existing-client linking preview');$linkKey=$this->key($body);
            [$status]=$this->post('leads/'.$linkedLead.'/convert/confirm',['request_key'=>$linkKey,'confirm'=>'yes']);$this->check(in_array($status,[302,303],true)&&$clients->find($actors['user'],$target)===$before,'Linking preserves all existing client fields');
            $archived=$makeLead($actors['user'],'archived');$leads->archive($actors['user'],$archived,true,$version($archived));[$status]=$this->http('GET','leads/'.$archived.'/convert');$this->check($status===422,'Archived lead conversion denied');
            $activeLead=$makeLead($actors['user'],'active');$archivedClient=$makeClient($actors['user'],'archived');$clients->archive($actors['user'],$archivedClient,true);
            [$status]=$this->post('leads/'.$activeLead.'/convert/preview',['version'=>$version($activeLead),'mode'=>'link','client_id'=>(string)$archivedClient]);$this->check($status===422,'Archived client linking denied');
            [$status,$body]=$this->post('leads/'.$activeLead.'/convert/preview',['version'=>$version($activeLead),'mode'=>'create','client_status_id'=>$active,'copy_related'=>'0']);$staleKey=$this->key($body);$leads->transition($actors['user'],$activeLead,$version($activeLead),$new);
            [$status]=$this->post('leads/'.$activeLead.'/convert/confirm',['request_key'=>$staleKey,'confirm'=>'yes']);$this->check(in_array($status,[409,422],true),'Changed lead invalidates conversion confirmation');
            $readerLead=$makeLead($actors['admin'],'reader',null,(int)$actors['reader']->id);$this->login($credentials['reader']);
            [$status]=$this->http('GET','leads/'.$readerLead.'/convert');$this->check($status===404,'Read-only assignee cannot convert');
            [$status]=$this->post('leads/'.$readerLead.'/stage',['version'=>$version($readerLead),'status_id'=>$new],true);$this->check($status===404,'Read-only assignee cannot change stages');
            [$status]=$this->http('GET','leads/'.$own.'/conversion');$this->check($status===404,'Foreign conversion history denied');
            // Simultaneous operations run on separate real MariaDB connections, with fixture-only writes.
            $raceLead=$makeLead($actors['admin'],'race');$command=$conversion->preview($actors['admin'],$raceLead,['version'=>$version($raceLead),'mode'=>'create','client_status_id'=>$active,'copy_related'=>'0'])['command'];
            $job=['kind'=>'conversion','actor_id'=>(int)$actors['admin']->id,'lead_id'=>$raceLead,'command'=>$command,'request_key'=>bin2hex(random_bytes(32))];$pair=$this->parallel($job,array_replace($job,['request_key'=>bin2hex(random_bytes(32))]));
            $this->check($pair[0]['status']===200&&$pair[1]['status']===200&&$pair[0]['result']['client_id']===$pair[1]['result']['client_id'],'Concurrent same-lead conversion returns one client');
            $this->check((int)$pair[0]['result']['replayed']+(int)$pair[1]['result']['replayed']===1&&$db->table('lead_conversions')->where('lead_id',$raceLead)->countAllResults()===1,'Exactly one conversion commits and one replays');
            $stageLead=$makeLead($actors['admin'],'stage',$new);$stageJob=['kind'=>'stage','actor_id'=>(int)$actors['admin']->id,'lead_id'=>$stageLead,'version'=>$version($stageLead),'status_id'=>$qualified];$pair=$this->parallel($stageJob,array_replace($stageJob,['status_id'=>$new]));$statuses=array_column($pair,'status');sort($statuses);$this->check($statuses===[200,409],'Simultaneous edits reject one stale stage update');
            $shared=$makeClient($actors['admin'],'shared');$jobs=[];$sharedLeads=[];$payload=['first_name'=>'Shared','last_name'=>'Contact','email'=>$tag.'.shared@example.invalid'];
            foreach(['shareda','sharedb'] as $suffix){$lead=$makeLead($actors['admin'],$suffix);$sharedLeads[]=$lead;$leads->saveChild($actors['admin'],$lead,'contacts',$payload+['version'=>$version($lead)]);$plan=$conversion->preview($actors['admin'],$lead,['version'=>$version($lead),'mode'=>'link','client_id'=>(string)$shared,'copy_related'=>'1']);$jobs[]=['kind'=>'conversion','actor_id'=>(int)$actors['admin']->id,'lead_id'=>$lead,'command'=>$plan['command'],'request_key'=>bin2hex(random_bytes(32))];}
            $pair=$this->parallel($jobs[0],$jobs[1]);$statuses=array_column($pair,'status');sort($statuses);$this->check($statuses===[200,409],'Concurrent shared-client change invalidates one stale preview');
            foreach($sharedLeads as $lead){if(!$db->table('lead_conversions')->where('lead_id',$lead)->countAllResults()){$plan=$conversion->preview($actors['admin'],$lead,['version'=>$version($lead),'mode'=>'link','client_id'=>(string)$shared,'copy_related'=>'1']);$conversion->convert($actors['admin'],$lead,$plan['command'],bin2hex(random_bytes(32)),true);}}
            $this->check(count($clients->details($actors['admin'],$shared)['contacts'])===1,'Reviewed retry does not duplicate shared contact');
            $this->check($db->table('lead_conversions')->whereIn('lead_id',$sharedLeads)->countAllResults()===2,'Both qualified leads can link safely to one client');
        }finally{
            if($ids){$db->table('lead_conversions')->whereIn('actor_id',$ids)->delete();$db->table('leads')->whereIn('created_by',$ids)->delete();$db->table('clients')->whereIn('owner_id',$ids)->delete();}
            foreach($ids as $id){$db->table('auth_logins')->where('user_id',$id)->delete();$provider->delete($id,true);}foreach($emails as $email){$db->table('auth_logins')->where('identifier',$email)->delete();}curl_close($this->curl);unset($credentials);foreach($this->jobFiles as $file){if(is_file($file)){unlink($file);}}
        }
        $this->check(hash_equals($authBefore,$this->authenticationDigest($db)),'Permanent authentication identities unchanged');
        CLI::write('Pipeline HTTP/concurrency checks passed: '.$this->checks.'. Generated conversion, client, lead and user fixtures removed.','green');
    }
}
