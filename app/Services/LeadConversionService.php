<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;

class LeadConversionService
{
    private BaseConnection $db;
    private LeadService $leads;
    private ClientService $clients;
    public function __construct(?BaseConnection $db=null){$this->db=$db??db_connect();$this->leads=new LeadService($this->db);$this->clients=new ClientService($this->db);}
    private function input(array $data,string $key):string{return is_string($data[$key]??null)?trim($data[$key]):'';}
    private function authorize(User $user,int $id):array
    {
        $lead=$this->leads->find($user,$id);(new LeadPolicy())->require($user,'convert',$lead);(new ClientPolicy())->require($user,'view');return $lead;
    }
    private function eligible(array $lead,mixed $version):void
    {
        if($lead['archived_at']!==null||$lead['status_slug']!=='qualified'||$lead['outcome']!=='open'){throw new LeadInputException(['lead'=>'Only an unarchived lead in the Qualified stage can be converted.']);}
        if((!is_string($version)&&!is_int($version))||!ctype_digit((string)$version)||(int)$version<1){throw new LeadInputException(['version'=>'A valid lead version is required.']);}
        if((int)$version!==(int)$lead['version']){throw new LeadInputException(['version'=>'The lead changed. Reload and prepare a new conversion preview.'],409);}
    }
    public function candidates(User $user,int $id,string $query=''):array
    {
        $lead=$this->authorize($user,$id);$this->eligible($lead,$lead['version']);$query=mb_substr(trim($query),0,100);
        if($query===''){$listing=$this->clients->conversionCandidates($user,$lead['email'],$lead['display_name']);return $listing+['query'=>''];}
        $listing=$this->clients->listing($user,['q'=>$query]);return ['rows'=>array_slice($listing['rows'],0,10),'query'=>$query,'total'=>$listing['total']];
    }
    private function mapping(array $lead,string $status):array
    {
        $data=['status_id'=>$status,'groups'=>[]];foreach(['type','company_name','first_name','last_name','email','phone','website','notes'] as $field){$data[$field]=(string)($lead[$field]??'');}return $data;
    }
    private function normalize(mixed $value):string{return mb_strtolower(preg_replace('/\s+/u',' ',trim((string)($value??''))));}
    private function equal(array $left,array $right,array $fields):bool{foreach($fields as $field){if($this->normalize($left[$field]??null)!==$this->normalize($right[$field]??null)){return false;}}return true;}
    private function plans(array $leadDetails,?array $clientDetails,bool $copy):array
    {
        $plans=['contacts'=>[],'addresses'=>[]];if(!$copy){return $plans;}
        foreach(['contacts'=>['first_name','last_name','job_title','email','phone'],'addresses'=>['kind','line1','line2','city','region','postal_code','country']] as $kind=>$fields){
            foreach($leadDetails[$kind] as $source){$match=null;$payload=[];foreach($fields as $field){$payload[$field]=(string)($source[$field]??'');}
                foreach($clientDetails[$kind]??[] as $existing){
                    $same=$kind==='contacts'&&$payload['email']!==''?$this->normalize($payload['email'])===$this->normalize($existing['email']):$this->equal($payload,$existing,$fields);
                    if($same){$match=$existing;break;}
                }
                $plans[$kind][]=['source_id'=>(int)$source['id'],'action'=>$match?'keep':'add','existing_id'=>$match?(int)$match['id']:null,'conflict'=>$match&&!$this->equal($payload,$match,$fields),'payload'=>$payload];
            }
        }return $plans;
    }
    private function clientDigest(?array $details):?string
    {
        if($details===null){return null;}foreach(['contacts','addresses','memberships'] as $key){usort($details[$key],static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);}return hash('sha256',json_encode($details,JSON_THROW_ON_ERROR));
    }
    public function preview(User $user,int $id,array $input):array
    {
        $lead=$this->authorize($user,$id);$this->eligible($lead,$input['version']??null);
        $mode=$this->input($input,'mode');if(!in_array($mode,['create','link'],true)){throw new LeadInputException(['mode'=>'Choose Create new client or Link existing client.']);}
        $copy=$this->input($input,'copy_related')==='1';$client=null;$target=null;$status=$this->input($input,'client_status_id');
        if($mode==='create'){
            (new ClientPolicy())->require($user,'create');
            if(!ctype_digit($status)||!$this->db->table('client_statuses')->where('id',(int)$status)->countAllResults()){throw new LeadInputException(['status'=>'Choose an existing client account status.']);}
            if($copy){(new ClientPolicy())->require($user,'edit');}
        }else{
            $clientId=$this->input($input,'client_id');if(!ctype_digit($clientId)||(int)$clientId<1){throw new LeadInputException(['client'=>'Select an authorized existing client.']);}
            $target=$this->clients->details($user,(int)$clientId);$client=$target['client'];
            if($client['archived_at']!==null){throw new LeadInputException(['client'=>'Restore the selected client separately before converting into it.']);}
            if($copy){(new ClientPolicy())->require($user,'edit',$client);}
            $status=(string)$client['status_id'];
        }
        $details=$this->leads->details($user,$id);$mapping=$this->mapping($lead,$status);$plans=$this->plans($details,$target,$copy);
        $command=['version'=>(string)$lead['version'],'mode'=>$mode,'client_id'=>$client?(string)$client['id']:'','client_status_id'=>$status,'copy_related'=>$copy?'1':'0'];
        $digest=hash('sha256',json_encode([$command,$details,$this->clientDigest($target),$mapping,$plans],JSON_THROW_ON_ERROR));$command['digest']=$digest;
        $clientStatusName=(string)$this->db->table('client_statuses')->where('id',(int)$status)->get()->getRow('name');return compact('lead','client','mapping','plans','command','clientStatusName');
    }
    public function result(User $user,int $id):?array
    {
        $this->leads->find($user,$id);$row=$this->db->table('lead_conversions')->where('lead_id',$id)->get()->getRowArray();if(!$row){return null;}
        // Returning an ID/link requires independent access to the resulting client.
        $client=$this->clients->find($user,(int)$row['client_id']);return ['conversion'=>$row,'client'=>$client];
    }
    public function convert(User $user,int $id,array $command,string $requestKey,bool $confirmed):array
    {
        $this->authorize($user,$id);
        if(!$confirmed){throw new LeadInputException(['confirmation'=>'Explicit confirmation is required.']);}
        if(!preg_match('/^[a-f0-9]{64}$/D',$requestKey)){throw new LeadInputException(['request'=>'Prepare a valid conversion preview.']);}
        try{return DatabaseUnitOfWork::run($this->db,function()use($user,$id,$command,$requestKey){
            DatabaseUnitOfWork::lock($this->db,'leads',$id);$lead=$this->authorize($user,$id);
            $existing=$this->db->table('lead_conversions')->where('lead_id',$id)->get()->getRowArray();
            if($existing){DatabaseUnitOfWork::lock($this->db,'clients',(int)$existing['client_id']);$result=$this->result($user,$id);$result['replayed']=true;return $result;}
            $this->eligible($lead,$command['version']??null);
            if($this->db->table('lead_conversions')->where('request_key',$requestKey)->countAllResults()){throw new LeadInputException(['request'=>'This confirmation key has already been used. Prepare a new preview.'],409);}
            if(($command['mode']??'')==='link'){
                $clientId=$this->input($command,'client_id');if(!ctype_digit($clientId)){throw new LeadInputException(['client'=>'Choose an existing client.']);}
                // Authorize before locking; recheck under the lock below.
                $this->clients->find($user,(int)$clientId);DatabaseUnitOfWork::lock($this->db,'clients',(int)$clientId);
            }
            $preview=$this->preview($user,$id,$command);
            if(!is_string($command['digest']??null)||!hash_equals($preview['command']['digest'],$command['digest'])){throw new LeadInputException(['preview'=>'Lead or client information changed. Prepare a new preview and review the mapping.'],409);}
            $clientId=$preview['client']?(int)$preview['client']['id']:$this->clients->save($user,$preview['mapping']);
            $counts=['contacts_added'=>0,'contacts_kept'=>0,'addresses_added'=>0,'addresses_kept'=>0];
            foreach($preview['plans'] as $kind=>$entries){foreach($entries as $entry){if($entry['action']==='add'){$this->clients->saveChild($user,$clientId,$kind,$entry['payload']);$counts[$kind.'_added']++;}else{$counts[$kind.'_kept']++;}}}
            $now=date('Y-m-d H:i:s');$this->db->table('lead_conversions')->insert(['lead_id'=>$id,'client_id'=>$clientId,'actor_id'=>(int)$user->id,'request_key'=>$requestKey,
                'mode'=>$preview['command']['mode'],'lead_version'=>(int)$lead['version'],'preview_digest'=>$command['digest'],'details'=>json_encode($counts+['copied_related'=>$preview['command']['copy_related']==='1'],JSON_THROW_ON_ERROR),'converted_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
            $this->leads->finishConversion($user,$id,$lead['version'],$clientId);
            $result=$this->result($user,$id);$result['replayed']=false;return $result;
        });}catch(ClientValidationException $e){throw new LeadInputException($e->errors);}
        catch(\CodeIgniter\Database\Exceptions\DatabaseException $e){
            if(in_array((int)$e->getCode(),[1205,1213],true)||str_contains($e->getMessage(),'Deadlock')||str_contains($e->getMessage(),'Lock wait timeout')){throw new LeadInputException(['concurrency'=>'Another update is in progress. Reload and retry the same confirmation.'],409);}
            if(str_contains($e->getMessage(),'Duplicate entry')||str_contains($e->getMessage(),'UNIQUE constraint')){throw new LeadInputException(['duplicate'=>'A conflicting conversion or client already exists. Prepare a new preview.'],409);}throw $e;
        }
    }
}
