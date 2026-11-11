<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;

class LeadService
{
    private BaseConnection $db;
    private LeadPolicy $policy;
    private ?\Closure $ownerResolver;
    public function __construct(?BaseConnection $db=null,?\Closure $ownerResolver=null){$this->db=$db??db_connect();$this->policy=new LeadPolicy();$this->ownerResolver=$ownerResolver;}
    private function input(array $input,string $key):string{return isset($input[$key])&&is_string($input[$key])?trim($input[$key]):'';}
    private function validate(array $data,array $rules):void{$validator=service('validation');$validator->reset();$validator->setRules($rules);if(!$validator->run($data)){throw new LeadInputException($validator->getErrors());}}
    private function version(mixed $version):int
    {
        if((!is_string($version)&&!is_int($version))||!ctype_digit((string)$version)||(int)$version<1||(int)$version>2147483646){throw new LeadInputException(['version'=>'A valid record version is required. Reload the page.']);}return (int)$version;
    }
    private function write(callable $fn):mixed
    {
        try{return DatabaseUnitOfWork::run($this->db,$fn);}
        catch(\Throwable $e){if(str_contains($e->getMessage(),'Duplicate entry')||str_contains($e->getMessage(),'UNIQUE constraint')){throw new LeadInputException(['duplicate'=>'This lead or contact email already exists. No changes were saved.']);}throw $e;}
    }
    private function scoped(User $user)
    {
        $this->policy->require($user,'view');$builder=$this->db->table('leads l');if(!$user->can('leads.manageall')){$builder->where('l.owner_id',(int)$user->id);}return $builder;
    }
    private function joined(User $user)
    {
        return $this->scoped($user)->join('lead_sources src','src.id=l.source_id')->join('lead_statuses s','s.id=l.status_id')->join('users owner','owner.id=l.owner_id')->join('users creator','creator.id=l.created_by');
    }
    private function columns():string{return 'l.*, src.name AS source_name, s.name AS status_name, s.slug AS status_slug, s.outcome, s.reserved, owner.username AS owner_name, creator.username AS creator_name';}
    public function find(User $user,int $id):array
    {
        $lead=$this->joined($user)->select($this->columns())->where('l.id',$id)->get()->getRowArray();if(!$lead){throw PageNotFoundException::forPageNotFound();}return $lead;
    }
    public function listing(User $user,array $input):array
    {
        $q=mb_substr($this->input($input,'q'),0,100);$source=ctype_digit($this->input($input,'source'))?(int)$input['source']:0;$status=ctype_digit($this->input($input,'status'))?(int)$input['status']:0;
        $owner=$user->can('leads.manageall')&&ctype_digit($this->input($input,'owner'))?(int)$input['owner']:0;
        $archive=$this->input($input,'archive')==='archived'?'archived':'active';$builder=$this->joined($user)->where('l.archived_at IS '.($archive==='archived'?'NOT NULL':'NULL'),null,false);
        if($source){$builder->where('l.source_id',$source);}if($status){$builder->where('l.status_id',$status);}if($owner){$builder->where('l.owner_id',$owner);}
        if($q!==''){$match=$this->db->escapeLikeString($q);$builder->groupStart()->like('l.display_name',$match)->orLike('l.email',$match)->orLike('l.code',$match)->orLike('l.phone',$match)->groupEnd();}
        $total=(clone $builder)->countAllResults();$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,min(100000,(int)$this->input($input,'page'))));
        $rows=$builder->select($this->columns())->orderBy('l.display_name')->orderBy('l.id')->limit(20,($page-1)*20)->get()->getResultArray();return compact('rows','total','pages','page','q','source','status','owner','archive');
    }
    public function lookups():array{return ['sources'=>$this->db->table('lead_sources')->orderBy('name')->get()->getResultArray(),'statuses'=>$this->db->table('lead_statuses')->orderBy('position')->get()->getResultArray()];}
    private function eligibleOwner(int $id):bool
    {
        $row=$this->db->table('users')->where(['id'=>$id,'active'=>1])->get()->getRowArray();if(!$row){return false;}
        $actor=$this->ownerResolver?($this->ownerResolver)($id):auth()->getProvider()->findById($id);return $actor instanceof User&&$actor->can('leads.view');
    }
    public function owners(User $user):array
    {
        if(!$user->can('leads.assign')&&!$user->can('leads.manageall')){return [];}
        $rows=$this->db->table('users')->select('id,username')->where('active',1)->orderBy('username')->get()->getResultArray();return array_values(array_filter($rows,fn($row)=>$this->eligibleOwner((int)$row['id'])));
    }
    public function details(User $user,int $id):array
    {
        return ['lead'=>$this->find($user,$id),'contacts'=>$this->db->table('lead_contacts')->where('lead_id',$id)->orderBy('first_name')->get()->getResultArray(),
            'addresses'=>$this->db->table('lead_addresses')->where('lead_id',$id)->orderBy('id')->get()->getResultArray(),
            'history'=>$this->db->table('lead_history h')->select('h.*,u.username AS actor_name')->join('users u','u.id=h.actor_id')->where('h.lead_id',$id)->orderBy('h.version','DESC')->limit(50)->get()->getResultArray()];
    }
    private function editable(User $user,int $id,string $action='edit'):array
    {
        $lead=$this->find($user,$id);$this->policy->require($user,$action,$lead);
        if(in_array($action,['edit','reopen'],true)&&$lead['archived_at']!==null){throw new LeadInputException(['state'=>'Restore this lead before changing it.']);}
        if($action==='edit'&&$lead['outcome']!=='open'){throw new LeadInputException(['state'=>'Terminal leads are read-only. An authorized administrator must reopen a lost lead first.']);}return $lead;
    }
    private function history(User $user,int $id,int $version,string $event,string $summary,array $metadata=[]):void
    {
        $now=date('Y-m-d H:i:s');$this->db->table('lead_history')->insert(['lead_id'=>$id,'actor_id'=>(int)$user->id,'version'=>$version,'event'=>$event,'summary'=>$summary,'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now]);
    }
    private function mutate(User $user,array $lead,mixed $version,array $data,string $event,string $summary,?callable $fn=null,array $metadata=[]):mixed
    {
        $expected=$this->version($version);if($expected!==(int)$lead['version']){throw new LeadInputException(['version'=>'This lead changed. Reload and review the latest information before retrying.'],409);}$id=(int)$lead['id'];
        return $this->write(function()use($user,$lead,$expected,$id,$data,$event,$summary,$fn,$metadata){
            $query=$this->db->table('leads')->where(['id'=>$id,'version'=>$expected]);if(!$user->can('leads.manageall')){$query->where('owner_id',(int)$user->id);}
            $query->update($data+['version'=>$expected+1,'updated_at'=>date('Y-m-d H:i:s')]);
            if($this->db->affectedRows()!==1){throw new LeadInputException(['version'=>'This lead changed since you opened the page. Reload it and review the latest information before retrying.'],409);}
            $result=$fn?$fn():$id;$this->history($user,$id,$expected+1,$event,$summary,$metadata);return $result;
        });
    }
    public function save(User $user,array $input,?int $id=null):int
    {
        if($id===null){$this->policy->require($user,'create');$lead=null;}else{$lead=$this->editable($user,$id);}
        $data=[];foreach(['type','company_name','first_name','last_name','email','phone','website','notes','source_id','status_id'] as $field){$data[$field]=$this->input($input,$field);}$data['email']=mb_strtolower($data['email']);
        $this->validate($data,['type'=>'required|in_list[company,individual]','company_name'=>'permit_empty|max_length[180]','first_name'=>'permit_empty|max_length[80]','last_name'=>'permit_empty|max_length[80]',
            'email'=>'permit_empty|valid_email|max_length[190]','phone'=>'permit_empty|max_length[40]','website'=>'permit_empty|valid_url_strict[https,http]|max_length[255]','notes'=>'permit_empty|max_length[5000]',
            'source_id'=>'required|is_natural_no_zero','status_id'=>'required|is_natural_no_zero']);
        $errors=[];
        if($data['type']==='company'&&$data['company_name']===''){$errors['company_name']='Company name is required for a company lead.';}
        if($data['type']==='individual'&&($data['first_name']===''||$data['last_name']==='')){$errors['first_name']='First and last names are required for an individual lead.';}
        if(!$this->db->table('lead_sources')->where(['id'=>(int)$data['source_id'],'active'=>1])->countAllResults()){$errors['source_id']='Choose an active lead source.';}
        $status=$this->db->table('lead_statuses')->where('id',(int)$data['status_id'])->get()->getRowArray();
        if(!$status||(int)$status['reserved']===1||!in_array($status['outcome'],['open','lost'],true)||($id===null&&$status['outcome']!=='open')){$errors['status_id']='Choose an available pipeline status. New leads must start in an open stage; conversion is not enabled.';}
        $owner=$lead?(int)$lead['owner_id']:(int)$user->id;
        if($user->can('leads.assign')&&array_key_exists('owner_id',$input)){
            $submitted=$this->input($input,'owner_id');if(!ctype_digit($submitted)||!$this->eligibleOwner((int)$submitted)){$errors['owner_id']='Choose an active user authorized to view leads.';}else{$owner=(int)$submitted;}
        }
        if($data['email']!==''){$duplicate=$this->db->table('leads')->where('email',$data['email']);if($id){$duplicate->where('id !=',$id);}if($duplicate->countAllResults()){$errors['email']='That email is already assigned to a lead, including archived leads.';}}
        if($errors){throw new LeadInputException($errors);}
        $data['display_name']=$data['type']==='company'?$data['company_name']:$data['first_name'].' '.$data['last_name'];
        if($data['type']==='company'){$data['first_name']=$data['last_name']=null;}else{$data['company_name']=null;}
        foreach(['email','phone','website','notes'] as $field){if($data[$field]===''){$data[$field]=null;}}
        $data['source_id']=(int)$data['source_id'];$data['status_id']=(int)$data['status_id'];$data['owner_id']=$owner;
        if($id===null){return $this->write(function()use($user,$data){$now=date('Y-m-d H:i:s');$this->db->table('leads')->insert($data+['code'=>'LD-'.strtoupper(bin2hex(random_bytes(6))),'created_by'=>(int)$user->id,'version'=>1,'created_at'=>$now,'updated_at'=>$now]);$id=(int)$this->db->insertID();$this->history($user,$id,1,'created','Lead created.',['owner_id'=>$data['owner_id'],'status_id'=>$data['status_id']]);return $id;});}
        $metadata=['owner_from'=>(int)$lead['owner_id'],'owner_to'=>$owner,'status_from'=>(int)$lead['status_id'],'status_to'=>$data['status_id'],'owner_from_name'=>$lead['owner_name'],'owner_to_name'=>(string)$this->db->table('users')->where('id',$owner)->get()->getRow('username'),'status_from_name'=>$lead['status_name'],'status_to_name'=>$status['name']];
        $assigned=$owner!==(int)$lead['owner_id'];$stageChanged=$data['status_id']!==(int)$lead['status_id'];
        $event=$assigned?'assigned':($stageChanged?'status_changed':'updated');
        $summary=$assigned?'Lead reassigned.':($stageChanged?'Pipeline status changed.':'Lead information updated.');
        return $this->mutate($user,$lead,$input['version']??null,$data,$event,$summary,null,$metadata);
    }
    public function archive(User $user,int $id,bool $archive,mixed $version):void
    {
        $lead=$this->editable($user,$id,'archive');$this->mutate($user,$lead,$version,['archived_at'=>$archive?date('Y-m-d H:i:s'):null],$archive?'archived':'restored',$archive?'Lead archived.':'Lead restored.');
    }
    public function reopen(User $user,int $id,mixed $version):void
    {
        $lead=$this->editable($user,$id,'reopen');if($lead['outcome']!=='lost'){throw new LeadInputException(['state'=>'Only lost leads can be reopened. Converted leads are reserved for Phase 4B-2.']);}
        $status=$this->db->table('lead_statuses')->where(['slug'=>'new','outcome'=>'open','reserved'=>0])->get()->getRowArray();if(!$status){throw new LeadInputException(['status'=>'The New status is unavailable.']);}
        $this->mutate($user,$lead,$version,['status_id'=>(int)$status['id']],'reopened','Lost lead reopened.',null,['status_from'=>(int)$lead['status_id'],'status_to'=>(int)$status['id'],'status_from_name'=>$lead['status_name'],'status_to_name'=>$status['name']]);
    }
    private function childTable(string $kind):string{return match($kind){'contacts'=>'lead_contacts','addresses'=>'lead_addresses',default=>throw PageNotFoundException::forPageNotFound()};}
    public function child(User $user,int $leadId,string $kind,int $id):array
    {
        $this->find($user,$leadId);$row=$this->db->table($this->childTable($kind))->where(['id'=>$id,'lead_id'=>$leadId])->get()->getRowArray();if(!$row){throw PageNotFoundException::forPageNotFound();}return $row;
    }
    public function saveChild(User $user,int $leadId,string $kind,array $input,?int $id=null):int
    {
        $lead=$this->editable($user,$leadId);$table=$this->childTable($kind);if($id!==null){$this->child($user,$leadId,$kind,$id);}
        $rules=$kind==='contacts'?['first_name'=>'required|max_length[80]','last_name'=>'required|max_length[80]','job_title'=>'permit_empty|max_length[100]','email'=>'permit_empty|valid_email|max_length[190]','phone'=>'permit_empty|max_length[40]']:
            ['kind'=>'required|in_list[billing,shipping,other]','line1'=>'required|max_length[180]','line2'=>'permit_empty|max_length[180]','city'=>'required|max_length[100]','region'=>'permit_empty|max_length[100]','postal_code'=>'permit_empty|max_length[24]','country'=>'required|max_length[100]'];
        $data=[];foreach($rules as $field=>$rule){$data[$field]=$this->input($input,$field);}if(isset($data['email'])){$data['email']=mb_strtolower($data['email']);}$this->validate($data,$rules);
        if($kind==='contacts'&&$data['email']!==''){$duplicate=$this->db->table($table)->where(['lead_id'=>$leadId,'email'=>$data['email']]);if($id){$duplicate->where('id !=',$id);}if($duplicate->countAllResults()){throw new LeadInputException(['email'=>'That email already belongs to a contact of this lead.']);}}
        foreach($data as $field=>$value){if($value===''){$data[$field]=null;}}
        return $this->mutate($user,$lead,$input['version']??null,[],$kind.($id?'_updated':'_added'),ucfirst($kind).' '.($id?'updated.':'added.'),function()use($table,$data,$leadId,$id){
            $now=date('Y-m-d H:i:s');if($id===null){$this->db->table($table)->insert($data+['lead_id'=>$leadId,'created_at'=>$now,'updated_at'=>$now]);return (int)$this->db->insertID();}
            $this->db->table($table)->where(['id'=>$id,'lead_id'=>$leadId])->update($data+['updated_at'=>$now]);return $id;
        },['entry_id'=>$id]);
    }
    public function removeChild(User $user,int $leadId,string $kind,int $id,mixed $version):void
    {
        $lead=$this->editable($user,$leadId);$this->child($user,$leadId,$kind,$id);
        $this->mutate($user,$lead,$version,[],$kind.'_removed',ucfirst($kind).' removed.',function()use($kind,$id,$leadId){$this->db->table($this->childTable($kind))->where(['id'=>$id,'lead_id'=>$leadId])->delete();},['entry_id'=>$id]);
    }
    public function board(User $user,array $input):array
    {
        $columns=[];$pages=$input['columns']??[];if(!is_array($pages)){$pages=[];}
        foreach($this->lookups()['statuses'] as $status){
            $selected=is_string($input['status']??null)&&ctype_digit($input['status'])?(int)$input['status']:0;
            if($selected&&$selected!==(int)$status['id']){continue;}
            $args=$input;$args['status']=(string)$status['id'];$args['page']=is_scalar($pages[$status['id']]??null)?(string)$pages[$status['id']]:'1';
            $columns[]=['status'=>$status,'listing'=>$this->listing($user,$args)];
        }
        return $columns;
    }
    public function transition(User $user,int $id,mixed $version,mixed $statusId):int
    {
        $lead=$this->editable($user,$id);
        if(!is_string($statusId)||!ctype_digit($statusId)){throw new LeadInputException(['status'=>'Choose an available pipeline status.']);}
        $status=$this->db->table('lead_statuses')->where('id',(int)$statusId)->get()->getRowArray();
        if(!$status||(int)$status['reserved']||!in_array($status['outcome'],['open','lost'],true)){throw new LeadInputException(['status'=>'This status requires a dedicated conversion or reopening action.']);}
        return $this->mutate($user,$lead,$version,['status_id'=>(int)$status['id']],'status_changed','Pipeline status changed.',null,['status_from'=>(int)$lead['status_id'],'status_to'=>(int)$status['id'],'status_from_name'=>$lead['status_name'],'status_to_name'=>$status['name']]);
    }
    /** Used only by ConversionService inside its outer transaction. */
    public function finishConversion(User $user,int $id,mixed $version,int $clientId):int
    {
        if($this->db->transDepth<1){throw new \LogicException('Conversion requires an outer transaction.');}
        DatabaseUnitOfWork::lock($this->db,'leads',$id);
        $lead=$this->find($user,$id);$this->policy->require($user,'convert',$lead);
        (new ClientService($this->db))->find($user,$clientId);
        if($lead['archived_at']||$lead['status_slug']!=='qualified'||$lead['outcome']!=='open'){throw new LeadInputException(['state'=>'Only an unarchived Qualified lead can be converted.']);}
        $status=$this->db->table('lead_statuses')->where(['slug'=>'converted','outcome'=>'converted'])->get()->getRowArray();if(!$status){throw new LeadInputException(['status'=>'Converted status is unavailable.']);}
        return $this->mutate($user,$lead,$version,['status_id'=>(int)$status['id']],'converted','Lead converted to a client.',null,['client_id'=>$clientId,'status_from'=>(int)$lead['status_id'],'status_to'=>(int)$status['id'],'status_from_name'=>$lead['status_name'],'status_to_name'=>$status['name']]);
    }
}
