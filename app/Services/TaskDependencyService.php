<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
final class TaskDependencyService
{
    private BaseConnection $db;private TaskService $tasks;private ProjectService $projects;private TaskPolicy $policy;
    public const MAX_EDGES=2000;
    public function __construct(?BaseConnection $db=null,?\Closure $resolver=null){$this->db=$db??db_connect();$this->tasks=new TaskService($this->db,$resolver);$this->projects=new ProjectService($this->db,$resolver);$this->policy=new TaskPolicy();}
    private function positive(mixed $value,string $field):int
    {
        if((!is_string($value)&&!is_int($value))||!ctype_digit((string)$value)||(int)$value<1||(int)$value>2147483646){throw new TaskInputException([$field=>'A valid '.$field.' is required.']);}return (int)$value;
    }
    private function writable(array $task,array $project):bool{return $task['parent_id']===null&&$task['archived_at']===null&&!$this->policy->readonlyProject($project);}
    private function edge(int $id,int $task):array
    {
        $row=$this->db->table('task_dependencies')->where(['id'=>$id,'archived_at'=>null])->groupStart()->where('predecessor_id',$task)->orWhere('successor_id',$task)->groupEnd()->get()->getRowArray();if(!$row){throw PageNotFoundException::forPageNotFound();}return $row;
    }
    public function details(User $user,int $id,array $input=[]):array
    {
        $task=$this->tasks->find($user,$id);$project=$this->projects->find($user,(int)$task['project_id']);$canManage=$this->writable($task,$project)&&$this->policy->allows($user,'dependencies',$project,$task);
        $prerequisites=$this->tasks->dependencyRelations($user,$id,true,$input);$dependents=$this->tasks->dependencyRelations($user,$id,false,$input);
        foreach(['prerequisites','dependents'] as $name){foreach($$name['rows'] as &$row){$row['can_remove']=$canManage&&$this->writable($row,$project)&&$this->policy->allows($user,'dependencies',$project,$row);}unset($row);}
        $q=$input['dependency_q']??'';$q=is_string($q)?mb_substr($q,0,100):'';
        return compact('canManage','prerequisites','dependents','q')+['choices'=>$canManage?$this->tasks->dependencyChoices($user,$id,$q):[]];
    }
    private function validateGraph(int $project,int $predecessor,int $successor):void
    {
        $rows=$this->db->table('task_dependencies')->select('predecessor_id,successor_id')->where(['project_id'=>$project,'archived_at'=>null])->limit(self::MAX_EDGES+1)->get()->getResultArray();
        if(count($rows)>=self::MAX_EDGES){throw new TaskInputException(['dependencies'=>'This project reached the supported dependency graph limit. Remove relationships before adding more.']);}
        $adj=[];foreach($rows as $row){$adj[(int)$row['predecessor_id']][]=(int)$row['successor_id'];}$stack=[$successor];$visited=[];
        while($stack){$node=array_pop($stack);if($node===$predecessor){throw new TaskInputException(['dependencies'=>'This dependency would introduce a circular chain.']);}if(isset($visited[$node])){continue;}$visited[$node]=true;if(count($visited)>self::MAX_EDGES+1){throw new TaskInputException(['dependencies'=>'Dependency traversal limit reached. Ask a project manager to review the graph.']);}foreach($adj[$node]??[] as $next){$stack[]=$next;}}
    }
    public function add(User $user,int $task,array $input):void{$this->change($user,$task,$this->positive($input['predecessor_id']??null,'predecessor'),$input,false);}
    public function remove(User $user,int $task,int $edge,array $input):void{$this->change($user,$task,$edge,$input,true);}
    private function change(User $user,int $taskId,int $target,array $input,bool $remove):void
    {
        $current=$this->tasks->find($user,$taskId);$edge=$remove?$this->edge($target,$taskId):null;
        $otherId=$edge?((int)$edge['predecessor_id']===$taskId?(int)$edge['successor_id']:(int)$edge['predecessor_id']):$target;
        $other=$this->tasks->find($user,$otherId);if($otherId===$taskId){throw new TaskInputException(['dependencies'=>'A task cannot depend on itself.']);}
        if((int)$current['project_id']!==(int)$other['project_id']){throw new TaskInputException(['dependencies'=>'Dependencies must remain within the same project.']);}
        $versions=[$taskId=>$this->positive($input['version']??null,'version'),$otherId=>$this->positive($input['other_version']??null,'other version')];$edgeVersion=$remove?$this->positive($input['edge_version']??null,'edge version'):null;
        try{DatabaseUnitOfWork::run($this->db,function()use($user,$taskId,$otherId,$current,$versions,$remove,$target,$edgeVersion){
            DatabaseUnitOfWork::lock($this->db,'projects',(int)$current['project_id']);$ids=[$taskId,$otherId];sort($ids,SORT_NUMERIC);foreach($ids as $id){DatabaseUnitOfWork::lock($this->db,'tasks',$id);}
            $tasks=[];$project=$this->projects->find($user,(int)$current['project_id']);
            foreach($ids as $id){$tasks[$id]=$this->tasks->find($user,$id);$this->policy->require($user,'dependencies',$project,$tasks[$id]);if(!$this->writable($tasks[$id],$project)){throw new TaskInputException(['dependencies'=>'Dependencies require two unarchived top-level tasks in a writable project.']);}if((int)$tasks[$id]['version']!==$versions[$id]){throw new TaskInputException(['version'=>'A related task changed. Reload both task records before retrying.'],409);}}
            $now=date('Y-m-d H:i:s');
            if($remove){
                $row=$this->edge($target,$taskId);$pair=[(int)$row['predecessor_id'],(int)$row['successor_id']];sort($pair,SORT_NUMERIC);if($pair!==$ids){throw PageNotFoundException::forPageNotFound();}if((int)$row['version']!==$edgeVersion){throw new TaskInputException(['version'=>'This dependency changed. Reload before retrying.'],409);}
                $this->db->table('task_dependencies')->where(['id'=>$row['id'],'version'=>$edgeVersion,'archived_at'=>null])->update(['archived_at'=>$now,'archived_by'=>(int)$user->id,'version'=>$edgeVersion+1,'updated_at'=>$now]);if($this->db->affectedRows()!==1){throw new TaskInputException(['version'=>'This dependency changed. Reload before retrying.'],409);}
            }else{
                $row=$this->db->table('task_dependencies')->where(['predecessor_id'=>$otherId,'successor_id'=>$taskId])->get()->getRowArray();if($row&&$row['archived_at']===null){throw new TaskInputException(['dependencies'=>'This prerequisite is already linked.']);}
                $this->validateGraph((int)$project['id'],$otherId,$taskId);
                if($tasks[$taskId]['category']==='done'&&$tasks[$otherId]['category']!=='done'){throw new TaskInputException(['dependencies'=>'A completed task cannot acquire an incomplete prerequisite. Reopen the dependent task first.']);}
                if($row){$this->db->table('task_dependencies')->where(['id'=>$row['id'],'version'=>$row['version']])->update(['archived_at'=>null,'archived_by'=>null,'version'=>(int)$row['version']+1,'updated_at'=>$now]);if($this->db->affectedRows()!==1){throw new TaskInputException(['version'=>'This relationship changed. Reload before retrying.'],409);}}
                else{$this->db->table('task_dependencies')->insert(['project_id'=>(int)$project['id'],'predecessor_id'=>$otherId,'successor_id'=>$taskId,'created_by'=>(int)$user->id,'version'=>1,'created_at'=>$now,'updated_at'=>$now]);}
            }
            foreach($ids as $id){$this->tasks->collaborate($user,$id,(string)$versions[$id],'dependencies',$remove?'dependency_removed':'dependency_added',static function(){});}
        });}catch(DatabaseException $e){if(str_contains($e->getMessage(),'Duplicate entry')||str_contains($e->getMessage(),'UNIQUE constraint')||in_array((int)$e->getCode(),[1205,1213],true)||str_contains($e->getMessage(),'Deadlock')||str_contains($e->getMessage(),'Lock wait timeout')){throw new TaskInputException(['version'=>'A conflicting dependency update occurred. Reload and review before retrying.'],409);}throw $e;}
    }
}