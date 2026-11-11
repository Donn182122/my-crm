<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
class TaskService
{
    private BaseConnection $db;private TaskPolicy $policy;private ProjectService $projects;private ?\Closure $userResolver;
    public function __construct(?BaseConnection $db=null,?\Closure $userResolver=null){$this->db=$db??db_connect();$this->policy=new TaskPolicy();$this->projects=new ProjectService($this->db,$userResolver);$this->userResolver=$userResolver;}
    private function input(array $input,string $key,string $default=''):string{return array_key_exists($key,$input)?(is_string($input[$key])?trim($input[$key]):''):$default;}
    private function positive(mixed $value,string $field='version'):int{if((!is_string($value)&&!is_int($value))||!ctype_digit((string)$value)||(int)$value<1||(int)$value>2147483646){throw new TaskInputException([$field=>'A valid '.$field.' is required.']);}return (int)$value;}
    private function scoped(User $user)
    {
        if(!$user->can('projects.view')||!$user->can('tasks.view')){throw PageNotFoundException::forPageNotFound();}
        $q=$this->db->table('tasks t')->join('projects p','p.id=t.project_id')->join('project_statuses ps','ps.id=p.status_id')->join('project_members mine','mine.project_id=p.id AND mine.user_id='.(int)$user->id,'left')->join('task_statuses s','s.id=t.status_id')->join('task_priorities pr','pr.id=t.priority_id')->join('users creator','creator.id=t.created_by')->join('users assignee','assignee.id=t.assignee_id','left');
        if(!$user->can('projects.manageall')){$q->groupStart()->where('p.owner_id',(int)$user->id)->orGroupStart()->where('mine.user_id',(int)$user->id)->where('p.archived_at',null)->groupEnd()->groupEnd();}
        if(!$this->policy->administrator($user)){$q->groupStart()->where('p.owner_id',(int)$user->id)->orWhere('mine.role','manager')->orWhere('t.assignee_id',(int)$user->id)->orWhere('t.created_by',(int)$user->id)->groupEnd();$q->groupStart()->where('t.archived_at',null)->orWhere('p.owner_id',(int)$user->id)->orWhere('mine.role','manager')->groupEnd();}return $q;
    }
    private function columns():string{return 't.*,p.name AS project_name,p.archived_at AS project_archived_at,ps.slug AS project_status_slug,s.name AS status_name,s.category,s.slug AS status_slug,pr.name AS priority_name,pr.slug AS priority_slug,creator.username AS creator_name,assignee.username AS assignee_name';}
    public function find(User $user,int $id):array
    {
        $task=$this->scoped($user)->select($this->columns())->where('t.id',$id)->get()->getRowArray();if(!$task){throw PageNotFoundException::forPageNotFound();}$project=$this->projects->find($user,(int)$task['project_id']);$this->policy->require($user,'view',$project,$task);return $task;
    }
    public function listing(User $user,array $input):array
    {
        $q=mb_substr($this->input($input,'q'),0,100);$project=ctype_digit($this->input($input,'project'))?(int)$input['project']:0;if($project){$this->projects->find($user,$project);}
        $status=ctype_digit($this->input($input,'status'))?(int)$input['status']:0;$priority=ctype_digit($this->input($input,'priority'))?(int)$input['priority']:0;$assignee=ctype_digit($this->input($input,'assignee'))?(int)$input['assignee']:0;$archive=$this->input($input,'archive')==='archived'?'archived':'active';
        $query=$this->scoped($user)->where('t.archived_at IS '.($archive==='archived'?'NOT NULL':'NULL'),null,false);foreach(['project_id'=>$project,'status_id'=>$status,'priority_id'=>$priority,'assignee_id'=>$assignee] as $field=>$value){if($value){$query->where('t.'.$field,$value);}}
        if($q!==''){$query->groupStart()->like('t.title',$q)->orLike('t.code',$q)->groupEnd();}$total=(clone $query)->countAllResults();$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,min(100000,(int)$this->input($input,'page'))));$rows=$query->select($this->columns())->orderBy('t.title')->orderBy('t.code')->orderBy('t.id')->limit(20,($page-1)*20)->get()->getResultArray();return compact('rows','total','pages','page','q','project','status','priority','assignee','archive');
    }
    /** Read-only board projection; all status writes still use status(). */
    public function board(User $user,array $input):array
    {
        // Normalize filters and authorize even when no status column matches.
        $filters=$this->listing($user,array_replace($input,['page'=>'1']));
        $pages=is_array($input['columns']??null)?$input['columns']:[];
        $columns=[];$projects=[];$statuses=$this->lookups()['statuses'];
        $activeIds=array_map('intval',array_column(array_filter($statuses,static fn($status)=>(int)$status['active']===1),'id'));
        foreach($statuses as $status){
            if($filters['status']&&(int)$status['id']!==$filters['status']){continue;}
            $args=$input;$args['status']=(string)$status['id'];$args['page']=is_scalar($pages[$status['id']]??null)?(string)$pages[$status['id']]:'1';
            $listing=$this->listing($user,$args);
            // Inactive statuses retain visible records, but never accept drops.
            if(!(int)$status['active']&&!$listing['total']&&!$filters['status']){continue;}
            foreach($listing['rows'] as &$task){
                $projectId=(int)$task['project_id'];$projects[$projectId]??=$this->projects->find($user,$projectId);$project=$projects[$projectId];
                $task['can_move']=count(array_diff($activeIds,[(int)$task['status_id']]))>0&&$task['archived_at']===null&&!$this->policy->readonlyProject($project)&&$this->policy->allows($user,'status',$project,$task);
            }unset($task);
            $columns[]=['status'=>$status,'listing'=>$listing];
        }
        return ['columns'=>$columns,'filters'=>$filters];
    }
    public function lookups():array{return ['statuses'=>$this->db->table('task_statuses')->orderBy('position')->orderBy('id')->get()->getResultArray(),'priorities'=>$this->db->table('task_priorities')->orderBy('position')->orderBy('id')->get()->getResultArray()];}
    public function details(User $user,int $id):array
    {
        $task=$this->find($user,$id);$project=$this->projects->find($user,(int)$task['project_id']);return compact('task','project')+['assigneeEligible'=>$task['assignee_id']===null||$this->eligible((int)$task['assignee_id'],$project),'history'=>$this->db->table('task_history h')->select('h.*,u.username AS actor_name')->join('users u','u.id=h.actor_id')->where('h.task_id',$id)->orderBy('h.version','DESC')->limit(50)->get()->getResultArray()];
    }
    public function projectChoices(User $user,string $query='',?int $selected=null,bool $creating=false):array
    {
        $rows=$this->projects->listing($user,['q'=>$query])['rows'];if($selected&&!in_array($selected,array_map('intval',array_column($rows,'id')),true)){try{$rows[]=$this->projects->find($user,$selected);}catch(PageNotFoundException){}}
        return $creating?array_values(array_filter($rows,fn($p)=>!$this->policy->readonlyProject($p)&&$this->policy->allows($user,'create',$p))):$rows;
    }
    private function eligible(int $id,array $project):bool
    {
        if(!$this->db->table('users')->where(['id'=>$id,'active'=>1])->countAllResults()){return false;}
        if($id!==(int)$project['owner_id']&&!$this->db->table('project_members')->where(['project_id'=>$project['id'],'user_id'=>$id])->countAllResults()){return false;}
        $actor=$this->userResolver?($this->userResolver)($id):auth()->getProvider()->findById($id);return $actor instanceof User&&$actor->can('projects.view')&&$actor->can('tasks.view');
    }
    public function assignees(User $user,array $project,string $query=''):array
    {
        $project=$this->projects->find($user,(int)$project['id']);if(!$this->policy->allows($user,'assign',$project)){return [];}
        $memberIds=array_map('intval',array_column($this->db->table('project_members')->select('user_id')->where('project_id',$project['id'])->get()->getResultArray(),'user_id'));$memberIds[]=(int)$project['owner_id'];$q=$this->db->table('users')->select('id,username')->whereIn('id',$memberIds)->where('active',1);$query=mb_substr(trim($query),0,60);if($query!==''){$q->like('username',$query);}return array_values(array_filter($q->orderBy('username')->limit(20)->get()->getResultArray(),fn($r)=>$this->eligible((int)$r['id'],$project)));
    }
    public function assigneeFilters(User $user,string $query='',?int $selected=null):array
    {
        $q=$this->scoped($user)->select('assignee.id,assignee.username')->distinct()->where('t.assignee_id IS NOT NULL',null,false);if(trim($query)!==''){$q->like('assignee.username',mb_substr(trim($query),0,60));}$rows=$q->orderBy('assignee.username')->limit(20)->get()->getResultArray();
        if($selected&&!in_array($selected,array_map('intval',array_column($rows,'id')),true)){$current=$this->scoped($user)->select('assignee.id,assignee.username')->distinct()->where('t.assignee_id',$selected)->get()->getRowArray();if($current){$rows[]=$current;}}return $rows;
    }
    private function writable(array $project,?array $task=null,string $action='edit'):void
    {
        if($this->policy->readonlyProject($project)){throw new TaskInputException(['project'=>'Restore or reopen the parent project before changing tasks.']);}
        if($task&&$task['archived_at']!==null&&$action!=='archive'){throw new TaskInputException(['archive'=>'Restore this task before changing it.']);}
    }
    private function reference(string $table,mixed $id,?int $current=null):array
    {
        if(!in_array($table,['task_statuses','task_priorities'],true)){throw new \LogicException('Unsupported task reference.');}$id=$this->positive($id,$table==='task_statuses'?'status':'priority');$row=$this->db->table($table)->where('id',$id)->get()->getRowArray();if(!$row||(!(int)$row['active']&&$current!==$id)){throw new TaskInputException(['reference'=>'Choose an active task status and priority.']);}return $row;
    }
    private function defaultId(string $table,string $slug):string
    {
        $row=$this->db->table($table)->where(['slug'=>$slug,'active'=>1])->get()->getRowArray();if(!$row){$query=$this->db->table($table)->where('active',1);if($table==='task_statuses'){$query->where('category','open');}$row=$query->orderBy('position')->get()->getRowArray();}return (string)($row['id']??'');
    }
    public function defaults():array{return ['status_id'=>$this->defaultId('task_statuses','todo'),'priority_id'=>$this->defaultId('task_priorities','normal')];}
    private function assignment(User $user,array $project,mixed $value,?array $task):?int
    {
        if($value===null||$value===''){$id=null;}else{$id=$this->positive($value,'assignee');}$current=$task&&$task['assignee_id']!==null?(int)$task['assignee_id']:null;
        if($id!==$current){$this->policy->require($user,'assign',$project,$task);if($id!==null&&!$this->eligible($id,$project)){throw new TaskInputException(['assignee'=>'Choose an active project participant with Projects and Tasks viewing permissions.']);}}return $id;
    }
    private function write(callable $callback):mixed
    {
        try{return DatabaseUnitOfWork::run($this->db,$callback);}catch(\CodeIgniter\Database\Exceptions\DatabaseException $e){if(str_contains($e->getMessage(),'Duplicate entry')||str_contains($e->getMessage(),'UNIQUE constraint')){throw new TaskInputException(['duplicate'=>'A conflicting task already exists. No changes were saved.'],409);}if(in_array((int)$e->getCode(),[1205,1213],true)||str_contains($e->getMessage(),'Deadlock')||str_contains($e->getMessage(),'Lock wait timeout')){throw new TaskInputException(['version'=>'Another update is in progress. Reload and review before retrying.'],409);}throw $e;}
    }
    private function mutate(User $user,int $id,mixed $version,string $action,string $event,callable $change):void
    {
        $initial=$this->find($user,$id);$this->write(function()use($user,$id,$version,$action,$event,$change,$initial){DatabaseUnitOfWork::lock($this->db,'projects',(int)$initial['project_id']);DatabaseUnitOfWork::lock($this->db,'tasks',$id);$task=$this->find($user,$id);$project=$this->projects->find($user,(int)$task['project_id']);$this->policy->require($user,$action,$project,$task);$this->writable($project,$task,$action);if($this->positive($version)!==(int)$task['version']){throw new TaskInputException(['version'=>'This task changed. Reload and review the latest information.'],409);}$data=$change($project,$task);$this->persist($user,$project,$task,$data,$event);});
    }
    private function persist(User $user,array $project,?array $task,array $data,string $event):int
    {
        $now=date('Y-m-d H:i:s');$id=$task?(int)$task['id']:0;$version=$task?(int)$task['version']+1:1;$data['updated_at']=$now;
        if($task){$this->db->table('tasks')->where(['id'=>$id,'version'=>$task['version']])->update($data+['version'=>$version]);if($this->db->affectedRows()!==1){throw new TaskInputException(['version'=>'Task changed. Reload before retrying.'],409);}}
        else{$this->db->table('tasks')->insert($data+['code'=>'TK-'.strtoupper(bin2hex(random_bytes(6))),'project_id'=>(int)$project['id'],'created_by'=>(int)$user->id,'version'=>1,'created_at'=>$now]);$id=(int)$this->db->insertID();}
        $metadata=[];if(array_key_exists('status_id',$data)){$metadata['status_from']=$task['status_name']??null;$metadata['status_to']=$this->db->table('task_statuses')->where('id',$data['status_id'])->get()->getRow('name');}
        if(array_key_exists('assignee_id',$data)){$metadata['assignee_from']=$task['assignee_name']??null;$metadata['assignee_to']=$data['assignee_id']?$this->db->table('users')->where('id',$data['assignee_id'])->get()->getRow('username'):null;}
        $summary=match($event){'created'=>'Task created.','status_changed'=>'Task status changed.','assigned'=>'Task assignment changed.','archived'=>'Task archived.','restored'=>'Task restored.',default=>'Task information updated.'};
        $this->db->table('task_history')->insert(['task_id'=>$id,'actor_id'=>(int)$user->id,'version'=>$version,'event'=>$event,'summary'=>$summary,'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now]);return $id;
    }
    private function completion(array $status,?array $task):?string{return $status['category']==='done'?($task&&$task['category']==='done'?$task['completed_at']:date('Y-m-d H:i:s')):null;}
    public function save(User $user,array $input,?int $id=null):int
    {
        $task=$id?$this->find($user,$id):null;$projectId=$task?(int)$task['project_id']:$this->positive($input['project_id']??null,'project');$project=$this->projects->find($user,$projectId);$this->policy->require($user,$id?'edit':'create',$project,$task);
        if($id&&array_key_exists('project_id',$input)&&$this->positive($input['project_id'],'project')!==$projectId){throw new TaskInputException(['project'=>'Moving tasks between projects is not enabled.']);}
        $data=[];foreach(['title','description','start_date','due_date'] as $key){if(array_key_exists($key,$input)&&!is_string($input[$key])&&$input[$key]!==null){throw new TaskInputException([$key=>'Use a text value for '.$key.'.']);}$data[$key]=$this->input($input,$key,(string)($task[$key]??''));}
        $v=service('validation');$v->reset();$v->setRules(['title'=>'required|max_length[180]','description'=>'permit_empty|max_length[10000]','start_date'=>'permit_empty|valid_date[Y-m-d]','due_date'=>'permit_empty|valid_date[Y-m-d]']);if(!$v->run($data)){throw new TaskInputException($v->getErrors());}
        foreach(['start_date','due_date'] as $key){if($data[$key]!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$data[$key])||$data[$key]<'1000-01-01')){throw new TaskInputException([$key=>'Use a valid date in YYYY-MM-DD format, year 1000 or later.']);}}
        if($data['start_date']!==''&&$data['due_date']!==''&&$data['due_date']<$data['start_date']){throw new TaskInputException(['due_date'=>'Due date must be on or after the start date.']);}
        $apply=function($project,$current)use($user,$input,$data){$defaults=$this->defaults();$status=$this->reference('task_statuses',$input['status_id']??($current['status_id']??$defaults['status_id']),$current?(int)$current['status_id']:null);if((!$current&&(string)$status['id']!==$defaults['status_id'])||($current&&(int)$status['id']!==(int)$current['status_id'])){$this->policy->require($user,'status',$project,$current);}
            $priority=$this->reference('task_priorities',$input['priority_id']??($current['priority_id']??$defaults['priority_id']),$current?(int)$current['priority_id']:null);$assignee=$this->assignment($user,$project,array_key_exists('assignee_id',$input)?$input['assignee_id']:($current['assignee_id']??null),$current);$result=$data;foreach(['description','start_date','due_date'] as $key){if($result[$key]===''){$result[$key]=null;}}return $result+['status_id'=>(int)$status['id'],'priority_id'=>(int)$priority['id'],'assignee_id'=>$assignee,'completed_at'=>$this->completion($status,$current)];};
        if($id){$this->mutate($user,$id,$input['version']??null,'edit','updated',$apply);return $id;}
        return $this->write(function()use($user,$projectId,$apply){DatabaseUnitOfWork::lock($this->db,'projects',$projectId);$project=$this->projects->find($user,$projectId);$this->policy->require($user,'create',$project);$this->writable($project);return $this->persist($user,$project,null,$apply($project,null),'created');});
    }
    public function status(User $user,int $id,mixed $statusId,mixed $version):void{$this->mutate($user,$id,$version,'status','status_changed',function($p,$task)use($statusId){$status=$this->reference('task_statuses',$statusId,(int)$task['status_id']);return ['status_id'=>(int)$status['id'],'completed_at'=>$this->completion($status,$task)];});}
    public function assign(User $user,int $id,mixed $assignee,mixed $version):void{$this->mutate($user,$id,$version,'assign','assigned',fn($p,$task)=>['assignee_id'=>$this->assignment($user,$p,$assignee,$task)]);}
    public function archive(User $user,int $id,bool $archive,mixed $version):void{$this->mutate($user,$id,$version,'archive',$archive?'archived':'restored',fn()=>['archived_at'=>$archive?date('Y-m-d H:i:s'):null]);}
}
