<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
class ProjectService
{
    private BaseConnection $db;private ProjectPolicy $policy;private ?\Closure $userResolver;
    public function __construct(?BaseConnection $db=null,?\Closure $userResolver=null){$this->db=$db??db_connect();$this->policy=new ProjectPolicy();$this->userResolver=$userResolver;}
    private function input(array $input,string $key):string{return is_string($input[$key]??null)?trim($input[$key]):'';}
    private function positive(mixed $value,string $field='version'):int
    {
        if((!is_string($value)&&!is_int($value))||!ctype_digit((string)$value)||(int)$value<1||(int)$value>2147483646){throw new ProjectInputException([$field=>'A valid '.$field.' is required.']);}return (int)$value;
    }
    private function scoped(User $user)
    {
        $this->policy->require($user,'view');$query=$this->db->table('projects p')->join('project_members mine','mine.project_id=p.id AND mine.user_id='.(int)$user->id,'left');
        if(!$this->policy->administrator($user)){$query->groupStart()->where('p.owner_id',(int)$user->id)->orGroupStart()->where('mine.user_id',(int)$user->id)->where('p.archived_at',null)->groupEnd()->groupEnd();}return $query;
    }
    private function joined(User $user){return $this->scoped($user)->join('project_statuses s','s.id=p.status_id')->join('users owner','owner.id=p.owner_id')->join('users creator','creator.id=p.created_by');}
    private function columns():string{return 'p.*,s.name AS status_name,s.slug AS status_slug,owner.username AS owner_name,creator.username AS creator_name,mine.role AS member_role';}
    public function find(User $user,int $id):array
    {
        $project=$this->joined($user)->select($this->columns())->where('p.id',$id)->get()->getRowArray();if(!$project){throw PageNotFoundException::forPageNotFound();}$this->policy->require($user,'view',$project);return $project;
    }
    public function client(User $user,array $project):?array
    {
        try{return (new ClientService($this->db))->find($user,(int)$project['client_id']);}catch(PageNotFoundException){return null;}
    }
    public function listing(User $user,array $input):array
    {
        $q=mb_substr($this->input($input,'q'),0,100);$status=ctype_digit($this->input($input,'status'))?(int)$input['status']:0;
        $owner=$this->policy->administrator($user)&&ctype_digit($this->input($input,'owner'))?(int)$input['owner']:0;$client=ctype_digit($this->input($input,'client'))?(int)$input['client']:0;
        if($client){(new ClientService($this->db))->find($user,$client);}
        $archive=$this->input($input,'archive')==='archived'?'archived':'active';$query=$this->joined($user)->where('p.archived_at IS '.($archive==='archived'?'NOT NULL':'NULL'),null,false);
        if($status){$query->where('p.status_id',$status);}if($owner){$query->where('p.owner_id',$owner);}if($client){$query->where('p.client_id',$client);}
        if($q!==''){$query->groupStart()->like('p.name',$q)->orLike('p.code',$q)->groupEnd();}
        $total=(clone $query)->countAllResults();$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,min(100000,(int)$this->input($input,'page'))));
        $rows=$query->select($this->columns())->orderBy('p.name')->orderBy('p.id')->limit(20,($page-1)*20)->get()->getResultArray();
        foreach($rows as &$row){$row['client_display']=$this->client($user,$row);}unset($row);return compact('rows','total','pages','page','q','status','owner','client','archive');
    }
    public function statuses():array{return $this->db->table('project_statuses')->orderBy('position')->orderBy('id')->get()->getResultArray();}
    public function details(User $user,int $id):array
    {
        $project=$this->find($user,$id);return ['project'=>$project,'linkedClient'=>$this->client($user,$project),
            'members'=>$this->db->table('project_members m')->select('m.*,u.username')->join('users u','u.id=m.user_id')->where('m.project_id',$id)->orderBy('u.username')->get()->getResultArray(),
            'history'=>$this->db->table('project_history h')->select('h.*,u.username AS actor_name')->join('users u','u.id=h.actor_id')->where('h.project_id',$id)->orderBy('h.version','DESC')->limit(50)->get()->getResultArray()];
    }
    private function eligible(int $id):bool
    {
        if(!$this->db->table('users')->where(['id'=>$id,'active'=>1])->countAllResults()){return false;}$actor=$this->userResolver?($this->userResolver)($id):auth()->getProvider()->findById($id);return $actor instanceof User&&$actor->can('projects.view');
    }
    public function people(User $user,string $q=''):array
    {
        $this->policy->require($user,'view');$query=$this->db->table('users')->select('id,username')->where('active',1);$q=mb_substr(trim($q),0,60);if($q!==''){$query->like('username',$q);}
        return array_values(array_filter($query->orderBy('username')->limit(20)->get()->getResultArray(),fn($row)=>$this->eligible((int)$row['id'])));
    }
    public function clients(User $user,string $q='',?array $project=null):array
    {
        if(!$user->can('clients.view')){return [];}$rows=(new ClientService($this->db))->listing($user,['q'=>$q])['rows'];
        if($project&&($current=$this->client($user,$project))&&!in_array((int)$current['id'],array_map('intval',array_column($rows,'id')),true)){$rows[]=$current;}return $rows;
    }
    private function record(User $user,int $id,int $version,string $event,array $metadata=[]):void
    {
        $summary=match($event){'created'=>'Project created.','updated'=>'Project information updated.','archived'=>'Project archived.','restored'=>'Project restored.','member_added'=>'Project membership added.','member_removed'=>'Project membership removed.',default=>'Project changed.'};$now=date('Y-m-d H:i:s');
        $this->db->table('project_history')->insert(['project_id'=>$id,'actor_id'=>(int)$user->id,'version'=>$version,'event'=>$event,'summary'=>$summary,'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now]);
    }
    private function write(callable $callback):mixed
    {
        try{return DatabaseUnitOfWork::run($this->db,$callback);}catch(\CodeIgniter\Database\Exceptions\DatabaseException $e){
            if(str_contains($e->getMessage(),'Duplicate entry')||str_contains($e->getMessage(),'UNIQUE constraint')){throw new ProjectInputException(['duplicate'=>'This project code or membership already exists. No changes were saved.'],409);}
            if(in_array((int)$e->getCode(),[1205,1213],true)||str_contains($e->getMessage(),'Deadlock')||str_contains($e->getMessage(),'Lock wait timeout')){throw new ProjectInputException(['version'=>'Another update is in progress. Reload and review before retrying.'],409);}throw $e;
        }
    }
    private function locked(User $user,int $id,string $action,mixed $version):array
    {
        DatabaseUnitOfWork::lock($this->db,'projects',$id);$project=$this->find($user,$id);$this->policy->require($user,$action,$project);
        if((int)$project['version']!==$this->positive($version)){throw new ProjectInputException(['version'=>'This project changed. Reload and review the latest information.'],409);}return $project;
    }
    private function bump(int $id,int $version,array $data=[]):int
    {
        $this->db->table('projects')->where(['id'=>$id,'version'=>$version])->update($data+['version'=>$version+1,'updated_at'=>date('Y-m-d H:i:s')]);if($this->db->affectedRows()!==1){throw new ProjectInputException(['version'=>'This project changed. Reload before retrying.'],409);}return $version+1;
    }
    public function save(User $user,array $input,?int $id=null):int
    {
        $project=$id?$this->find($user,$id):null;$this->policy->require($user,$id?'edit':'create',$project);
        $data=[];foreach(['name','description','status_id','start_date','end_date'] as $key){$data[$key]=$this->input($input,$key);}
        $validator=service('validation');$validator->reset();$validator->setRules(['name'=>'required|max_length[180]','description'=>'permit_empty|max_length[10000]','status_id'=>'required|is_natural_no_zero','start_date'=>'permit_empty|valid_date[Y-m-d]','end_date'=>'permit_empty|valid_date[Y-m-d]']);
        if(!$validator->run($data)){throw new ProjectInputException($validator->getErrors());}
        foreach(['start_date','end_date'] as $key){if($data[$key]!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$data[$key])||$data[$key]<'1000-01-01')){throw new ProjectInputException([$key=>'Use a valid date in YYYY-MM-DD format, year 1000 or later.']);}}
        if($data['start_date']!==''&&$data['end_date']!==''&&$data['end_date']<$data['start_date']){throw new ProjectInputException(['end_date'=>'End date must be on or after the start date.']);}
        $requestedClient=$this->input($input,'client_id');$clientId=$requestedClient===''&&$project?(int)$project['client_id']:$this->positive($requestedClient,'client');
        $owner=(int)($project['owner_id']??$user->id);$requestedOwner=$this->input($input,'owner_id');
        if($requestedOwner!==''&&(int)$requestedOwner!==$owner){if(!$this->policy->administrator($user)||!$user->can('projects.assign')){throw PageNotFoundException::forPageNotFound();}$owner=$this->positive($requestedOwner,'owner');}
        if(!$this->eligible($owner)){throw new ProjectInputException(['owner'=>'Choose an active user with project viewing permission.']);}
        $changingClient=!$project||$clientId!==(int)$project['client_id'];
        if($changingClient){if($project&&!$this->policy->principal($user,$project)){throw PageNotFoundException::forPageNotFound();}(new ClientService($this->db))->find($user,$clientId);}
        return $this->write(function()use($user,$input,$id,$data,$clientId,$owner,$changingClient){
            if($changingClient){DatabaseUnitOfWork::lock($this->db,'clients',$clientId);$client=(new ClientService($this->db))->find($user,$clientId);if($client['archived_at']){throw new ProjectInputException(['client'=>'Choose an authorized, non-archived client.']);}}
            $before=$id?$this->locked($user,$id,'edit',$input['version']??null):null;
            if(!$id){$this->policy->require($user,'create');}else{
                if($clientId!==(int)$before['client_id']&&!$this->policy->principal($user,$before)){throw PageNotFoundException::forPageNotFound();}
                if($owner!==(int)$before['owner_id']&&(!$this->policy->administrator($user)||!$user->can('projects.assign'))){throw PageNotFoundException::forPageNotFound();}
            }
            if(!$this->eligible($owner)){throw new ProjectInputException(['owner'=>'The selected owner is no longer eligible.']);}
            $status=$this->db->table('project_statuses')->where('id',(int)$data['status_id'])->get()->getRowArray();if(!$status||(!(int)$status['active']&&(!$before||(int)$before['status_id']!==(int)$status['id']))){throw new ProjectInputException(['status'=>'Choose an active project status.']);}
            $data['status_id']=(int)$data['status_id'];foreach(['description','start_date','end_date'] as $key){if($data[$key]===''){$data[$key]=null;}}
            $data['client_id']=$clientId;$data['owner_id']=$owner;
            if($id){$version=$this->bump($id,(int)$before['version'],$data);if($owner!==(int)$before['owner_id']){$this->db->table('project_members')->where(['project_id'=>$id,'user_id'=>$owner])->delete();}
                $meta=['owner_changed'=>$owner!==(int)$before['owner_id'],'owner_from'=>(int)$before['owner_id'],'owner_to'=>$owner,'owner_from_name'=>$before['owner_name'],'owner_to_name'=>$this->db->table('users')->where('id',$owner)->get()->getRow('username'),'status_from'=>$before['status_name'],'status_to'=>$status['name'],'client_changed'=>$clientId!==(int)$before['client_id']];
            }else{$version=1;$now=date('Y-m-d H:i:s');$this->db->table('projects')->insert($data+['code'=>'PR-'.strtoupper(bin2hex(random_bytes(6))),'created_by'=>(int)$user->id,'version'=>1,'created_at'=>$now,'updated_at'=>$now]);$id=(int)$this->db->insertID();$meta=[];}
            $this->record($user,$id,$version,$before?'updated':'created',$meta);return $id;
        });
    }
    public function archive(User $user,int $id,bool $archive,mixed $version):void
    {
        $this->find($user,$id);$this->write(function()use($user,$id,$archive,$version){$project=$this->locked($user,$id,'archive',$version);$next=$this->bump($id,(int)$project['version'],['archived_at'=>$archive?date('Y-m-d H:i:s'):null]);$this->record($user,$id,$next,$archive?'archived':'restored');});
    }
    public function addMember(User $user,int $id,array $input):void
    {
        $this->find($user,$id);$this->write(function()use($user,$id,$input){$project=$this->locked($user,$id,'members',$input['version']??null);$member=$this->positive($input['user_id']??null,'member');$role=$this->input($input,'role');
            if(!in_array($role,['member','manager'],true)){throw new ProjectInputException(['role'=>'Choose Member or Manager.']);}
            if($role==='manager'&&!$this->policy->principal($user,$project)){throw PageNotFoundException::forPageNotFound();}
            if($member===(int)$project['owner_id']){throw new ProjectInputException(['member'=>'The project owner already has access.']);}
            if(!$this->eligible($member)){throw new ProjectInputException(['member'=>'Choose an active user with project viewing permission.']);}
            $next=$this->bump($id,(int)$project['version']);$now=date('Y-m-d H:i:s');$this->db->table('project_members')->insert(['project_id'=>$id,'user_id'=>$member,'role'=>$role,'assigned_by'=>(int)$user->id,'created_at'=>$now,'updated_at'=>$now]);$this->record($user,$id,$next,'member_added',['username'=>$this->db->table('users')->where('id',$member)->get()->getRow('username'),'role'=>$role]);
        });
    }
    public function removeMember(User $user,int $id,int $member,mixed $version):void
    {
        $this->find($user,$id);$this->write(function()use($user,$id,$member,$version){$project=$this->locked($user,$id,'members',$version);$row=$this->db->table('project_members')->where(['project_id'=>$id,'user_id'=>$member])->get()->getRowArray();if(!$row){throw PageNotFoundException::forPageNotFound();}
            if($row['role']==='manager'&&!$this->policy->principal($user,$project)){throw PageNotFoundException::forPageNotFound();}$next=$this->bump($id,(int)$project['version']);$this->db->table('project_members')->where(['project_id'=>$id,'user_id'=>$member])->delete();$this->record($user,$id,$next,'member_removed',['username'=>$this->db->table('users')->where('id',$member)->get()->getRow('username'),'role'=>$row['role']]);
        });
    }
}
