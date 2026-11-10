<?php

declare(strict_types=1);
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use CodeIgniter\Shield\Entities\User;
use App\Services\ClientService;

class ClientsCheck extends BaseCommand
{
    protected $group='Verification';
    protected $name='clients:check';
    protected $description='Exercise Clients over real HTTP with isolated fictional fixtures and cleanup.';
    private $curl;
    private int $checks=0;
    private function check(bool $condition,string $label):void { if(!$condition){throw new \RuntimeException('FAIL: '.$label);} $this->checks++;CLI::write('PASS: '.$label,'green'); }
    private function http(string $method,string $path,array $data=[]):array
    {
        curl_setopt($this->curl,CURLOPT_URL,'http://localhost:8081/'.$path);
        curl_setopt($this->curl,CURLOPT_POSTFIELDS,null);curl_setopt($this->curl,CURLOPT_HTTPGET,true);
        if($method==='POST'){curl_setopt($this->curl,CURLOPT_POST,true);curl_setopt($this->curl,CURLOPT_POSTFIELDS,http_build_query($data));}
        $body=curl_exec($this->curl);if($body===false){throw new \RuntimeException('HTTP verification request failed.');}
        return [(int)curl_getinfo($this->curl,CURLINFO_HTTP_CODE),$body];
    }
    private function token(string $path):string
    {
        [$status,$body]=$this->http('GET',$path);
        if(!preg_match('/name="my_crm_csrf_token" value="([^"]+)"/',$body,$match)){throw new \RuntimeException('Missing CSRF token for '.$path.' (HTTP '.$status.').');}
        return html_entity_decode($match[1]);
    }
    private function post(string $path,array $data=[],string $form='clients/new'):array {return $this->http('POST',$path,$data+['my_crm_csrf_token'=>$this->token($form)]);}
    private function login(string $email,string $password):void
    {
        curl_setopt($this->curl,CURLOPT_COOKIELIST,'ALL');
        [$status]=$this->post('login',['email'=>$email,'password'=>$password],'login');$this->check(in_array($status,[302,303],true),'Generated fixture login');
    }
    public function run(array $params):void
    {
        $db=db_connect();$this->check($db->getDatabase()==='my_crm_db','Dedicated database enforced');
        $snapshot=hash('sha256',json_encode($db->table('auth_identities')->orderBy('id')->get()->getResultArray()));
        $provider=auth()->getProvider();$ids=[];$clientIds=[];$groupIds=[];$statusIds=[];$emails=[];
        $tag='clientcheck'.bin2hex(random_bytes(5));
        $this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);
        try{
            [$status]=$this->http('GET','clients');$this->check($status===302,'Guest denied Clients listing');
            [$status]=$this->http('GET','clients/new');$this->check($status===302,'Guest denied creation form');
            foreach(['admin','user'] as $role){
                $email=$tag.'.'.$role.'@example.invalid';$password=bin2hex(random_bytes(20)).'!aA9';
                $provider->save(new User(['username'=>$tag.$role,'email'=>$email,'password'=>$password]));$id=(int)$provider->getInsertID();$ids[]=$id;$emails[]=$email;
                $actor=$provider->findById($id);$actor->activate();$actor->addGroup($role);$actors[$role]=$actor;$credentials[$role]=[$email,$password];
            }
            $this->login(...$credentials['admin']);
            [$status,$body]=$this->http('GET','clients');$this->check($status===200 && str_contains($body,'Search clients'),'Clients list renders');
            [$status]=$this->http('POST','clients',['type'=>'company']);$this->check($status===403,'Client write without CSRF rejected');
            [$status,$body]=$this->post('clients',['type'=>'company','company_name'=>'','status_id'=>'1']);$this->check($status===422&&str_contains($body,'Company name is required'),'Client validation errors render');
            $lookupName=$tag.' Partners';[$status]=$this->post('clients/lookups/groups',['name'=>$lookupName],'clients/lookups');$this->check($status===303||$status===302,'Group creation');
            $groupId=(int)$db->table('client_groups')->where('name',$lookupName)->get()->getRow('id');$groupIds[]=$groupId;
            [$status]=$this->post('clients/lookups/statuses',['name'=>$tag.' Engaged'],'clients/lookups');$this->check(in_array($status,[302,303],true),'Status creation');
            $statusId=(int)$db->table('client_statuses')->where('name',$tag.' Engaged')->get()->getRow('id');$statusIds[]=$statusId;
            [$status]=$this->post('clients/lookups/groups/'.$groupId,['name'=>$lookupName.' updated'],'clients/lookups');$this->check(in_array($status,[302,303],true),'Group rename');
            $email=$tag.'.company@example.invalid';$input=['type'=>'company','company_name'=>$tag.' <script>alert(1)</script>','email'=>$email,'status_id'=>(string)$statusId,'groups'=>[(string)$groupId],'phone'=>'555-0100','notes'=>'Fictional verification only'];
            [$status]=$this->post('clients',$input);$this->check(in_array($status,[302,303],true),'Company creation');
            $clientId=(int)$db->table('clients')->where('email',$email)->get()->getRow('id');$clientIds[]=$clientId;
            [$status,$body]=$this->http('GET','clients/'.$clientId);$this->check($status===200&&str_contains($body,'&lt;script&gt;')&&!str_contains($body,'<script>alert(1)</script>'),'Profile renders escaped client data');
            [$status]=$this->post('clients',$input);$this->check($status===422,'Duplicate client email rejected');
            $input['company_name']=$tag.' Updated Company';[$status]=$this->post('clients/'.$clientId,$input,'clients/'.$clientId.'/edit');$this->check(in_array($status,[302,303],true),'Client edit');
            $contact=['first_name'=>'Fictional','last_name'=>'Contact','email'=>$tag.'.contact@example.invalid'];
            [$status]=$this->post('clients/'.$clientId.'/contacts',$contact,'clients/'.$clientId.'/contacts/new');$this->check(in_array($status,[302,303],true),'Contact creation');
            $contactId=(int)$db->table('client_contacts')->where('client_id',$clientId)->get()->getRow('id');
            [$status]=$this->post('clients/'.$clientId.'/contacts',$contact,'clients/'.$clientId.'/contacts/new');$this->check($status===422,'Duplicate contact rejected');
            [$status]=$this->post('clients/'.$clientId.'/contacts/'.$contactId,$contact+['job_title'=>'Updated role'],'clients/'.$clientId.'/contacts/'.$contactId.'/edit');$this->check(in_array($status,[302,303],true),'Contact edit');
            $address=['kind'=>'billing','line1'=>'123 Fictional Road','city'=>'Sample City','country'=>'Philippines'];
            [$status]=$this->post('clients/'.$clientId.'/addresses',$address,'clients/'.$clientId.'/addresses/new');$this->check(in_array($status,[302,303],true),'Address creation');
            $addressId=(int)$db->table('client_addresses')->where('client_id',$clientId)->get()->getRow('id');
            [$status]=$this->post('clients/'.$clientId.'/addresses/'.$addressId,array_replace($address,['city'=>'Updated City']),'clients/'.$clientId.'/addresses/'.$addressId.'/edit');$this->check(in_array($status,[302,303],true),'Address edit');
            [$status]=$this->post('clients/'.$clientId.'/archive',[],'clients/'.$clientId);$this->check(in_array($status,[302,303],true),'Client archive');
            [$status,$body]=$this->http('GET','clients/'.$clientId.'/edit');$this->check($status===422&&str_contains($body,'Restore this client'),'Archived client editing blocked');
            [$status]=$this->post('clients/'.$clientId.'/restore',[],'clients/'.$clientId);$this->check(in_array($status,[302,303],true),'Client restore');
            [$status,$body]=$this->http('GET','clients?'.http_build_query(['q'=>$tag,'status'=>$statusId,'group'=>$groupId]));$this->check($status===200&&str_contains($body,'Updated Company'),'Search and combined status/group filters');
            $service=new ClientService();
            for($i=0;$i<21;$i++){$clientIds[]=$service->save($actors['admin'],['type'=>'individual','first_name'=>$tag,'last_name'=>sprintf('Person %02d',$i),'status_id'=>'1','email'=>$tag.'.person'.$i.'@example.invalid']);}
            [$status,$body]=$this->http('GET','clients?q='.$tag.'&page=2');$this->check($status===200&&str_contains($body,'Page 2 of 2'),'Pagination with search');
            $foreignBefore=hash('sha256',json_encode($service->details($actors['admin'],$clientId)));
            $this->login(...$credentials['user']);
            [$status]=$this->http('GET','clients/'.$clientId);$this->check($status===404,'Foreign client profile denied');
            [$status]=$this->http('GET','clients/'.$clientId.'/edit');$this->check($status===404,'Foreign client edit denied');
            [$status]=$this->post('clients/'.$clientId.'/archive');$this->check($status===404,'Foreign archive denied');
            [$status]=$this->http('GET','clients/'.$clientId.'/contacts/'.$contactId.'/edit');$this->check($status===404,'Foreign contact denied');
            [$status]=$this->http('GET','clients/lookups');$this->check($status===404,'Ordinary user lookup administration denied');
            [$status,$body]=$this->http('GET','clients?q='.$tag);$this->check($status===200&&!str_contains($body,'Updated Company'),'Listing hides other owners');
            $ownInput=['type'=>'individual','first_name'=>'Owned','last_name'=>$tag,'status_id'=>'1','email'=>$tag.'.owned@example.invalid'];
            [$status]=$this->post('clients',$ownInput);$this->check(in_array($status,[302,303],true),'Ordinary user can create owned client');
            $ownId=(int)$db->table('clients')->where('email',$ownInput['email'])->get()->getRow('id');$clientIds[]=$ownId;
            $this->check((int)$db->table('clients')->where('id',$ownId)->get()->getRow('owner_id')===(int)$actors['user']->id,'Server assigns ownership');
            [$status]=$this->post('clients/'.$clientId,$input);$this->check($status===404,'Foreign client direct update POST denied');
            [$status]=$this->post('clients/'.$clientId.'/restore');$this->check($status===404,'Foreign client restore POST denied');
            foreach(['contacts'=>$contactId,'addresses'=>$addressId] as $kind=>$childId){
                $data=$kind==='contacts'?$contact:$address;
                [$status]=$this->http('GET','clients/'.$clientId.'/'.$kind.'/new');$this->check($status===404,'Foreign '.$kind.' create form denied');
                [$status]=$this->http('GET','clients/'.$clientId.'/'.$kind.'/'.$childId.'/edit');$this->check($status===404,'Foreign '.$kind.' edit form denied');
                [$status]=$this->post('clients/'.$clientId.'/'.$kind,$data);$this->check($status===404,'Foreign '.$kind.' create POST denied');
                [$status]=$this->post('clients/'.$clientId.'/'.$kind.'/'.$childId,$data);$this->check($status===404,'Foreign '.$kind.' update POST denied');
                [$status]=$this->post('clients/'.$clientId.'/'.$kind.'/'.$childId.'/remove');$this->check($status===404,'Foreign '.$kind.' removal POST denied');
                [$status]=$this->http('GET','clients/'.$ownId.'/'.$kind.'/'.$childId.'/edit');$this->check($status===404,'Mismatched parent '.$kind.' form denied');
                [$status]=$this->post('clients/'.$ownId.'/'.$kind.'/'.$childId,$data);$this->check($status===404,'Mismatched parent '.$kind.' update denied');
                [$status]=$this->post('clients/'.$ownId.'/'.$kind.'/'.$childId.'/remove');$this->check($status===404,'Mismatched parent '.$kind.' removal denied');
            }
            [$status]=$this->post('clients/'.$ownId,$ownInput+['owner_id'=>(string)$actors['admin']->id,'archived_at'=>'2000-01-01','code'=>'FORGED'],'clients/'.$ownId.'/edit');
            $ownRow=$db->table('clients')->where('id',$ownId)->get()->getRowArray();
            $this->check(in_array($status,[302,303],true)&&(int)$ownRow['owner_id']===(int)$actors['user']->id&&$ownRow['archived_at']===null&&$ownRow['code']!=='FORGED','Forged client ownership and protected fields ignored');
            [$status]=$this->post('clients/'.$ownId.'/contacts',['first_name'=>'Owned','last_name'=>'Contact','email'=>$tag.'.owncontact@example.invalid','client_id'=>$clientId],'clients/'.$ownId.'/contacts/new');
            $this->check(in_array($status,[302,303],true)&&(int)$db->table('client_contacts')->where('email',$tag.'.owncontact@example.invalid')->get()->getRow('client_id')===$ownId,'Forged contact parent cannot change target client');
            $this->check(hash_equals($foreignBefore,hash('sha256',json_encode($service->details($actors['admin'],$clientId)))),'Denied requests leave foreign client and children unchanged');
            foreach(['clients/'.$ownId,'clients/'.$ownId.'/archive','clients/'.$ownId.'/restore','clients/'.$ownId.'/contacts','clients/'.$ownId.'/addresses','clients/lookups/groups','clients/lookups/statuses'] as $path){
                [$status]=$this->http('POST',$path,['name'=>'CSRF probe']);$this->check($status===403,'CSRF required: '.$path);
            }
            [$status]=$this->post('clients/'.$ownId.'/contacts/'.$contactId.'/remove');$this->check($status===404,'Cross-parent contact mutation denied');
            $this->login(...$credentials['admin']);
            $this->check($actors['admin']->can('clients.manageall'),'Shield grants administrator all-client permission');
            [$status,$body]=$this->http('GET','clients/'.$ownId);$this->check($status===200&&str_contains($body,'Owned'),'Administrator can view another owner client');
            [$status]=$this->post('clients/'.$ownId,array_replace($ownInput,['first_name'=>'Admin edited']),'clients/'.$ownId.'/edit');$this->check(in_array($status,[302,303],true),'Administrator can edit another owner client');
            [$status]=$this->post('clients/'.$ownId.'/archive',[],'clients/'.$ownId);$this->check(in_array($status,[302,303],true),'Administrator can archive another owner client');
            [$status]=$this->post('clients/'.$ownId.'/restore',[],'clients/'.$ownId);$this->check(in_array($status,[302,303],true),'Administrator can restore another owner client');
            [$status]=$this->post('clients/'.$clientId.'/contacts/'.$contactId.'/remove',[],'clients/'.$clientId);$this->check(in_array($status,[302,303],true),'Contact removal');
            [$status]=$this->post('clients/'.$clientId.'/addresses/'.$addressId.'/remove',[],'clients/'.$clientId);$this->check(in_array($status,[302,303],true),'Address removal');
            [$status]=$this->http('GET','clients/'.$clientId.'/archive');$this->check($status===404,'GET cannot archive');
        } finally {
            foreach($clientIds as $id){$db->table('clients')->where('id',$id)->whereIn('owner_id',$ids)->delete();}
            foreach($groupIds as $id){$db->table('client_groups')->where('id',$id)->delete();}
            foreach($statusIds as $id){$db->table('client_statuses')->where('id',$id)->delete();}
            foreach($ids as $id){$db->table('auth_logins')->where('user_id',$id)->delete();$provider->delete($id,true);}
            foreach($emails as $email){$db->table('auth_logins')->where('identifier',$email)->delete();}
            curl_close($this->curl);unset($credentials);
        }
        $this->check(hash_equals($snapshot,hash('sha256',json_encode($db->table('auth_identities')->orderBy('id')->get()->getResultArray()))),'Permanent authentication identities unchanged');
        CLI::write('Clients HTTP checks passed: '.$this->checks.'. Fictional clients, contacts, addresses, classifications and users removed.','green');
    }
}
