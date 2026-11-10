<?php

declare(strict_types=1);
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use CodeIgniter\Shield\Entities\User;
use App\Services\LeadService;
class LeadsCheck extends BaseCommand
{
    protected $group='Verification';protected $name='leads:check';protected $description='Verify Leads using fictional fixtures, real HTTP and scoped cleanup.';
    private $curl;private int $checks=0;
    private function check(bool $value,string $message):void{if(!$value){throw new \RuntimeException('FAIL: '.$message);}$this->checks++;CLI::write('PASS: '.$message,'green');}
    private function http(string $method,string $path,array $data=[],bool $ajax=false):array
    {
        curl_setopt_array($this->curl,[CURLOPT_URL=>'http://localhost:8081/'.$path,CURLOPT_HTTPHEADER=>$ajax?['X-Requested-With: XMLHttpRequest','Accept: application/json']:[]]);
        curl_setopt($this->curl,CURLOPT_POSTFIELDS,null);curl_setopt($this->curl,CURLOPT_HTTPGET,true);
        if($method==='POST'){curl_setopt($this->curl,CURLOPT_POST,true);curl_setopt($this->curl,CURLOPT_POSTFIELDS,http_build_query($data));}
        $body=curl_exec($this->curl);if($body===false){throw new \RuntimeException('HTTP request failed.');}return [(int)curl_getinfo($this->curl,CURLINFO_HTTP_CODE),$body];
    }
    private function post(string $path,array $data=[],string $form='leads',bool $ajax=false):array
    {
        [, $body]=$this->http('GET',$form);if(!preg_match('/name="my_crm_csrf_token" value="([^"]+)"/',$body,$m)){throw new \RuntimeException('CSRF form missing: '.$form);}
        if(preg_match('~^leads/(\d+)~',$path,$ids)&&!array_key_exists('version',$data)){$data['version']=(string)db_connect()->table('leads')->where('id',(int)$ids[1])->get()->getRow('version');}
        return $this->http('POST',$path,$data+['my_crm_csrf_token'=>html_entity_decode($m[1])],$ajax);
    }
    private function login(array $credentials):void
    {
        curl_setopt($this->curl,CURLOPT_COOKIELIST,'ALL');
        for($attempt=0;$attempt<15;$attempt++){
            [$status,$body]=$this->http('GET','login');
            if($status===429){sleep(7);continue;}
            if(!preg_match('/name="my_crm_csrf_token" value="([^"]+)"/',$body,$m)){throw new \RuntimeException('Login form unavailable.');}
            [$status]=$this->http('POST','login',['email'=>$credentials[0],'password'=>$credentials[1],'my_crm_csrf_token'=>html_entity_decode($m[1])]);
            if($status===429){sleep(7);continue;}
            $this->check(in_array($status,[302,303],true),'Generated fixture login');return;
        }
        throw new \RuntimeException('Login throttling persisted; retry verification after the rate-limit window.');
    }
    public function run(array $params):void
    {
        $db=db_connect();$this->check($db->getDatabase()==='my_crm_db','Dedicated database');$provider=auth()->getProvider();$service=new LeadService();
        $snapshot=hash('sha256',json_encode($db->table('auth_identities')->orderBy('id')->get()->getResultArray()));$tag='leadtest'.bin2hex(random_bytes(5));$ids=[];$leadIds=[];$emails=[];
        $this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);
        try{
            foreach(['leads','leads/new','leads/999'] as $path){[$status]=$this->http('GET',$path);$this->check($status===302,'Guest denied '.$path);}
            foreach(['admin','user','reader'] as $role){$email=$tag.'.'.$role.'@example.invalid';$password=bin2hex(random_bytes(20)).'!aA9';$provider->save(new User(['username'=>$tag.$role,'email'=>$email,'password'=>$password]));$id=(int)$provider->getInsertID();$ids[]=$id;$emails[]=$email;$actor=$provider->findById($id);$actor->activate();if($role==='reader'){$actor->addPermission('foundation.access','leads.view');}else{$actor->addGroup($role);}$actors[$role]=$actor;$credentials[$role]=[$email,$password];}
            $this->login($credentials['admin']);
            [$status,$body]=$this->http('GET','leads/new');$this->check($status===200&&str_contains($body,'source_id')&&str_contains($body,'owner_id'),'Source and assignment form');
            $source=(string)$db->table('lead_sources')->where('slug','manual')->get()->getRow('id');$new=(string)$db->table('lead_statuses')->where('slug','new')->get()->getRow('id');$lost=(string)$db->table('lead_statuses')->where('slug','lost')->get()->getRow('id');$converted=(string)$db->table('lead_statuses')->where('slug','converted')->get()->getRow('id');
            $data=['type'=>'company','company_name'=>$tag.' <script>alert(1)</script>','email'=>$tag.'@example.invalid','source_id'=>$source,'status_id'=>$new];
            foreach([['source_id'=>'999999'],['status_id'=>$converted],['status_id'=>'999999'],['company_name'=>'']] as $change){[$status]=$this->post('leads',array_replace($data,$change));$this->check($status===422,'Invalid/reserved lead input rejected');}
            [$status]=$this->post('leads',$data);$this->check(in_array($status,[302,303],true),'Company lead creation');$leadId=(int)$db->table('leads')->where('email',$data['email'])->get()->getRow('id');$leadIds[]=$leadId;
            [$status,$body]=$this->http('GET','leads/'.$leadId);$this->check($status===200&&str_contains($body,'&lt;script&gt;')&&!str_contains($body,'<script>alert(1)</script>'),'Profile escapes data and renders');
            [$status]=$this->post('leads',$data);$this->check($status===422,'Duplicate normalized email rejected');
            $data['company_name']=$tag.' Updated';[$status]=$this->post('leads/'.$leadId,$data);$this->check(in_array($status,[302,303],true),'Lead edit');
            [$status,$body]=$this->post('leads/'.$leadId,$data+['version'=>'1']);$this->check($status===409&&str_contains($body,'Reload'),'Stale form returns conflict');
            [$status,$body]=$this->post('leads/'.$leadId,$data+['version'=>'1'],'leads',true);$this->check($status===409&&isset(json_decode($body,true)['errors']),'Stale AJAX returns JSON conflict');
            $contact=['first_name'=>'Fictional','last_name'=>'Contact','email'=>$tag.'.contact@example.invalid'];$address=['kind'=>'billing','line1'=>'123 Fictional Lane','city'=>'Sample City','country'=>'Philippines'];
            $children=[];
            foreach(['contacts'=>$contact,'addresses'=>$address] as $kind=>$payload){
                [$status]=$this->post('leads/'.$leadId.'/'.$kind,$payload);$this->check(in_array($status,[302,303],true),'Create '.$kind);$child=(int)$db->table('lead_'.$kind)->where('lead_id',$leadId)->get()->getRow('id');$children[$kind]=$child;
                [$status]=$this->post('leads/'.$leadId.'/'.$kind.'/'.$child,$payload);$this->check(in_array($status,[302,303],true),'Edit '.$kind);
                [$status]=$this->post('leads/'.$leadId.'/'.$kind.'/'.$child.'/remove',['version'=>'1']);$this->check($status===409,'Stale child removal denied');
            }
            [$status]=$this->post('leads/'.$leadId.'/contacts',$contact);$this->check($status===422,'Duplicate contact denied');
            $history=$db->table('lead_history')->where('lead_id',$leadId)->countAllResults();$this->check($history===(int)$db->table('leads')->where('id',$leadId)->get()->getRow('version'),'One history event per successful version');
            [$status]=$this->post('leads/'.$leadId.'/archive');$this->check(in_array($status,[302,303],true),'Archive lead');
            [$status]=$this->post('leads/'.$leadId,$data);$this->check($status===422,'Archived edit denied');
            [$status]=$this->post('leads/'.$leadId.'/contacts',$contact);$this->check($status===422,'Archived child write denied');
            [$status]=$this->post('leads/'.$leadId.'/restore');$this->check(in_array($status,[302,303],true),'Restore lead');
            [$status]=$this->post('leads/'.$leadId,array_replace($data,['status_id'=>$lost]));$this->check(in_array($status,[302,303],true),'Mark lost');
            [$status]=$this->post('leads/'.$leadId,$data);$this->check($status===422,'Terminal edit denied even for admin');
            [$status]=$this->post('leads/'.$leadId.'/addresses',$address);$this->check($status===422,'Terminal address write denied');
            [$status]=$this->post('leads/'.$leadId.'/reopen');$this->check(in_array($status,[302,303],true),'Explicit administrator reopening');
            [$status]=$this->post('leads/'.$leadId,array_replace($data,['owner_id'=>'999999']));$this->check($status===422,'Ineligible owner rejected');
            for($i=0;$i<21;$i++){$leadIds[]=$service->save($actors['admin'],['type'=>'individual','first_name'=>$tag,'last_name'=>'Person '.$i,'email'=>$tag.'.'.$i.'@example.invalid','source_id'=>$source,'status_id'=>$new]);}
            [$status,$body]=$this->http('GET','leads?'.http_build_query(['q'=>$tag,'source'=>$source,'status'=>$new,'page'=>'2']));$this->check($status===200&&str_contains($body,'Page 2 of 2'),'Search filters and pagination');
            $before=hash('sha256',json_encode($service->details($actors['admin'],$leadId)));
            $this->login($credentials['user']);
            foreach(['leads/'.$leadId,'leads/'.$leadId.'/edit'] as $path){[$status]=$this->http('GET',$path);$this->check($status===404,'Foreign direct URL denied');}
            foreach(['','/archive','/restore','/reopen'] as $suffix){[$status]=$this->post('leads/'.$leadId.$suffix,$data,'leads',true);$this->check($status===404,'Foreign AJAX mutation denied');}
            $own=$service->save($actors['user'],['type'=>'individual','first_name'=>'Owned','last_name'=>$tag,'email'=>$tag.'.owned@example.invalid','source_id'=>$source,'status_id'=>$new]);$leadIds[]=$own;
            foreach(['contacts'=>$contact,'addresses'=>$address] as $kind=>$payload){$child=$children[$kind];
                foreach(['leads/'.$leadId.'/'.$kind.'/new','leads/'.$leadId.'/'.$kind.'/'.$child.'/edit','leads/'.$own.'/'.$kind.'/'.$child.'/edit'] as $path){[$status]=$this->http('GET',$path);$this->check($status===404,'Foreign or mismatched '.$kind.' form denied');}
                foreach(['leads/'.$leadId.'/'.$kind,'leads/'.$leadId.'/'.$kind.'/'.$child,'leads/'.$leadId.'/'.$kind.'/'.$child.'/remove','leads/'.$own.'/'.$kind.'/'.$child,'leads/'.$own.'/'.$kind.'/'.$child.'/remove'] as $path){[$status]=$this->post($path,$payload,'leads',true);$this->check($status===404,'Foreign or mismatched '.$kind.' POST denied');}
            }
            $ownData=['type'=>'individual','first_name'=>'Owned','last_name'=>$tag,'email'=>$tag.'.owned@example.invalid','source_id'=>$source,'status_id'=>$new,'owner_id'=>(string)$actors['admin']->id,'created_by'=>(string)$actors['admin']->id,'code'=>'FORGED'];
            [$status]=$this->post('leads/'.$own,$ownData);$row=$db->table('leads')->where('id',$own)->get()->getRowArray();$this->check(in_array($status,[302,303],true)&&(int)$row['owner_id']===(int)$actors['user']->id&&(int)$row['created_by']===(int)$actors['user']->id&&$row['code']!=='FORGED','User cannot forge ownership or creator');
            foreach(['leads','leads/'.$own,'leads/'.$own.'/archive','leads/'.$own.'/restore','leads/'.$own.'/reopen','leads/'.$own.'/contacts','leads/'.$own.'/addresses'] as $path){[$status]=$this->http('POST',$path);$this->check($status===403,'CSRF enforced '.$path);}
            $this->check(hash_equals($before,hash('sha256',json_encode($service->details($actors['admin'],$leadId)))),'Foreign lead and history unchanged after denied requests');
            $this->login($credentials['admin']);
            [$status]=$this->post('leads/'.$leadId,array_replace($data,['owner_id'=>(string)$actors['user']->id]));$this->check(in_array($status,[302,303],true),'Administrator reassignment');
            $this->login($credentials['user']);[$status]=$this->http('GET','leads/'.$leadId);$this->check($status===200,'New assignee can view lead');
            [$status]=$this->post('leads/'.$leadId.'/reopen');$this->check($status===404,'Ordinary user cannot reopen terminal records');
            $this->login($credentials['admin']);[$status]=$this->post('leads/'.$leadId,array_replace($data,['owner_id'=>(string)$actors['reader']->id]));$this->check(in_array($status,[302,303],true),'Assign to view-authorized reader');
            $this->login($credentials['user']);[$status]=$this->http('GET','leads/'.$leadId);$this->check($status===404,'Former owner loses record access');
            $this->login($credentials['reader']);[$status]=$this->http('GET','leads/'.$leadId);$this->check($status===200,'Read-only assignee can view');
            foreach(['','/archive','/restore'] as $suffix){[$status]=$this->post('leads/'.$leadId.$suffix,$data);$this->check($status===404,'Read-only assignee cannot mutate');}
            $this->login($credentials['admin']);foreach($children as $kind=>$child){[$status]=$this->post('leads/'.$leadId.'/'.$kind.'/'.$child.'/remove');$this->check(in_array($status,[302,303],true),'Remove '.$kind);}
            foreach(['leads/'.$leadId.'/archive','leads/kanban','leads/'.$leadId.'/convert'] as $path){[$status]=$this->http('GET',$path);$this->check($status===404,'GET mutation or Phase 4B-2 endpoint unavailable');}
        }finally{
            foreach($leadIds as $id){$db->table('leads')->where('id',$id)->whereIn('created_by',$ids)->delete();}
            foreach($ids as $id){$db->table('auth_logins')->where('user_id',$id)->delete();$provider->delete($id,true);}foreach($emails as $email){$db->table('auth_logins')->where('identifier',$email)->delete();}curl_close($this->curl);unset($credentials);
        }
        $this->check(hash_equals($snapshot,hash('sha256',json_encode($db->table('auth_identities')->orderBy('id')->get()->getResultArray()))),'Permanent login identities unchanged');CLI::write('Leads HTTP checks passed: '.$this->checks.'. Generated leads, children, history and users removed.','green');
    }
}
