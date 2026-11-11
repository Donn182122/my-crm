<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
class TaskCollaborationService
{
    private BaseConnection $db;private TaskService $tasks;private ProjectService $projects;private TaskPolicy $policy;
    public function __construct(?BaseConnection $db=null,?\Closure $resolver=null){$this->db=$db??db_connect();$this->tasks=new TaskService($this->db,$resolver);$this->projects=new ProjectService($this->db,$resolver);$this->policy=new TaskPolicy();}
    private function text(array $input,string $key,int $max):string
    {
        $value=$input[$key]??null;if(!is_string($value)||trim($value)===''||mb_strlen(trim($value))>$max){throw new TaskInputException([$key=>'Enter '.$key.' between 1 and '.$max.' characters.']);}return trim($value);
    }
    private function row(string $table,int $task,int $id):array
    {
        if(!in_array($table,['task_checklist_items','task_comments'],true)){throw new \LogicException('Unsupported related table.');}
        $row=$this->db->table($table)->where(['id'=>$id,'task_id'=>$task,'deleted_at'=>null])->get()->getRowArray();if(!$row){throw PageNotFoundException::forPageNotFound();}return $row;
    }
    private function update(string $table,array $row,array $data):void
    {
        $this->db->table($table)->where(['id'=>$row['id'],'task_id'=>$row['task_id'],'version'=>$row['version'],'deleted_at'=>null])->update($data+['version'=>(int)$row['version']+1,'updated_at'=>date('Y-m-d H:i:s')]);
        if($this->db->affectedRows()!==1){throw new TaskInputException(['version'=>'This item changed. Reload before retrying.'],409);}
    }
    private function writable(array $task,array $project):bool{return !$this->policy->readonlyProject($project)&&$task['archived_at']===null&&($task['parent_archived_at']??null)===null;}
    public function details(User $user,int $id,array $input=[]):array
    {
        $task=$this->tasks->find($user,$id);$project=$this->projects->find($user,(int)$task['project_id']);$writable=$this->writable($task,$project);$parent=null;
        if($task['parent_id']!==null){try{$parent=$this->tasks->find($user,(int)$task['parent_id']);}catch(PageNotFoundException){}}
        $items=$this->db->table('task_checklist_items')->where(['task_id'=>$id,'deleted_at'=>null])->orderBy('position')->orderBy('id')->limit(100)->get()->getResultArray();$checked=count(array_filter($items,static fn($row)=>$row['checked_at']!==null));
        $query=$this->db->table('task_comments c')->join('users u','u.id=c.author_id')->where(['c.task_id'=>$id,'c.deleted_at'=>null]);$total=(clone $query)->countAllResults();$pages=max(1,(int)ceil($total/20));$requested=$input['comments_page']??1;$page=$requested==='last'?$pages:min($pages,max(1,is_scalar($requested)?(int)$requested:1));
        $comments=$query->select('c.*,u.username AS author_name')->orderBy('c.created_at')->orderBy('c.id')->limit(20,($page-1)*20)->get()->getResultArray();
        $canComment=$writable&&$this->policy->allows($user,'comment',$project,$task);$canModerate=$canComment&&$this->policy->allows($user,'comments.moderate',$project,$task);
        foreach($comments as &$comment){$own=(int)$comment['author_id']===(int)$user->id;$comment['can_edit']=$canComment&&$own;$comment['can_hide']=$canComment&&($own||$canModerate);}unset($comment);
        return ['parent'=>$parent,'children'=>$task['parent_id']===null?$this->tasks->children($user,$id,$input):null,'canCreateSubtask'=>$writable&&$task['parent_id']===null&&$this->policy->allows($user,'create',$project),'items'=>$items,'checked'=>$checked,'canChecklist'=>$writable&&$this->policy->allows($user,'checklist',$project,$task),'comments'=>$comments,'commentsTotal'=>$total,'commentsPages'=>$pages,'commentsPage'=>$page,'canComment'=>$canComment];
    }
    public function checklist(User $user,int $taskId,array $input,?int $itemId=null,string $operation='save'):void
    {
        $this->tasks->find($user,$taskId);if($itemId!==null){$this->row('task_checklist_items',$taskId,$itemId);}
        if(!in_array($operation,['save','toggle','archive','move'],true)||($itemId===null&&$operation!=='save')){throw new TaskInputException(['action'=>'Choose a valid checklist action.']);}
        $label=$operation==='save'?$this->text($input,'label',240):null;
        $completed=null;if($operation==='toggle'){if(!in_array($input['completed']??null,['0','1',0,1],true)){throw new TaskInputException(['completed'=>'Choose completed or incomplete.']);}$completed=(string)$input['completed']==='1';}
        $direction=$input['direction']??null;if($operation==='move'&&!in_array($direction,['up','down'],true)){throw new TaskInputException(['direction'=>'Choose Up or Down.']);}
        $event=match($operation){'toggle'=>$completed?'checklist_completed':'checklist_reopened','archive'=>'checklist_archived','move'=>'checklist_reordered',default=>$itemId===null?'checklist_added':'checklist_updated'};
        $this->tasks->collaborate($user,$taskId,$input['version']??null,'checklist',$event,function()use($user,$taskId,$itemId,$operation,$label,$completed,$direction){
            $now=date('Y-m-d H:i:s');$row=$itemId!==null?$this->row('task_checklist_items',$taskId,$itemId):null;
            if($row===null){
                if($this->db->table('task_checklist_items')->where(['task_id'=>$taskId,'deleted_at'=>null])->countAllResults()>=100){throw new TaskInputException(['checklist'=>'A task supports up to 100 active checklist items.']);}
                $last=(int)($this->db->table('task_checklist_items')->selectMax('position')->where('task_id',$taskId)->get()->getRow('position')??0);if($last>2147483630){throw new TaskInputException(['position'=>'No ordering space remains. Ask an administrator to review this task.']);}
                $this->db->table('task_checklist_items')->insert(['task_id'=>$taskId,'label'=>$label,'position'=>$last+10,'created_by'=>(int)$user->id,'version'=>1,'created_at'=>$now,'updated_at'=>$now]);return;
            }
            if($operation==='move'){
                $items=$this->db->table('task_checklist_items')->where(['task_id'=>$taskId,'deleted_at'=>null])->orderBy('position')->orderBy('id')->get()->getResultArray();$index=array_search((int)$row['id'],array_map('intval',array_column($items,'id')),true);$neighbor=$items[$index+($direction==='up'?-1:1)]??null;
                if(!$neighbor){throw new TaskInputException(['position'=>'This item is already at the requested end.']);}
                $this->update('task_checklist_items',$row,['position'=>(int)$neighbor['position']]);$this->update('task_checklist_items',$neighbor,['position'=>(int)$row['position']]);return;
            }
            $data=match($operation){'toggle'=>['checked_at'=>$completed?$now:null,'checked_by'=>$completed?(int)$user->id:null],'archive'=>['deleted_at'=>$now,'deleted_by'=>(int)$user->id],default=>['label'=>$label]};$this->update('task_checklist_items',$row,$data);
        });
    }
    private function commentAccess(User $user,array $project,array $task,array $comment,bool $hide):void
    {
        $this->policy->require($user,'comment',$project,$task);$own=(int)$comment['author_id']===(int)$user->id;
        if(!$own&&(!$hide||!$this->policy->allows($user,'comments.moderate',$project,$task))){throw PageNotFoundException::forPageNotFound();}
    }
    public function comment(User $user,int $taskId,array $input,?int $commentId=null,bool $hide=false):void
    {
        $task=$this->tasks->find($user,$taskId);$project=$this->projects->find($user,(int)$task['project_id']);if($commentId!==null){$this->commentAccess($user,$project,$task,$this->row('task_comments',$taskId,$commentId),$hide);}elseif($hide){throw new TaskInputException(['comment'=>'Choose an existing comment.']);}
        $body=$hide?null:$this->text($input,'body',5000);$event=$hide?'comment_hidden':($commentId===null?'comment_added':'comment_edited');
        $this->tasks->collaborate($user,$taskId,$input['version']??null,'comment',$event,function($project,$task)use($user,$taskId,$commentId,$hide,$body){
            $now=date('Y-m-d H:i:s');if($commentId===null){$this->db->table('task_comments')->insert(['task_id'=>$taskId,'author_id'=>(int)$user->id,'body'=>$body,'version'=>1,'created_at'=>$now,'updated_at'=>$now]);return;}
            $row=$this->row('task_comments',$taskId,$commentId);$this->commentAccess($user,$project,$task,$row,$hide);$this->update('task_comments',$row,$hide?['deleted_at'=>$now,'deleted_by'=>(int)$user->id]:['body'=>$body,'edited_at'=>$now,'edited_by'=>(int)$user->id]);
        });
    }
}
