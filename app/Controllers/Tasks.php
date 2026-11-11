<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Services\{TaskService,TaskPolicy,TaskInputException,ProjectService,NavigationService};
class Tasks extends BaseController
{
    private function service():TaskService{return new TaskService();}
    private function query(string $key):string{$value=$this->request->getGet($key);return is_string($value)?mb_substr($value,0,100):'';}
    private function render(string $view,string $title,array $data=[],int $status=200):string
    {
        helper(['crm','form']);$nav=new NavigationService();$user=auth()->user();$this->response->setStatusCode($status);return view('tasks/'.$view,$data+['title'=>$title,'active'=>'tasks','current'=>'tasks','user'=>$user,'policy'=>new TaskPolicy(),'errors'=>[],'navigation'=>$nav->sections($user),'destinations'=>$nav->destinations($user)]);
    }
    public function index():string
    {
        $s=$this->service();$user=auth()->user();$listing=$s->listing($user,$this->request->getGet());return $this->render('index','Tasks',$listing+$s->lookups()+['projects'=>$s->projectChoices($user,$this->query('project_q'),$listing['project']?:null),'assignees'=>$s->assigneeFilters($user,$this->query('assignee_q'),$listing['assignee']?:null),'projectQuery'=>$this->query('project_q'),'assigneeQuery'=>$this->query('assignee_q')]);
    }
    public function kanban():string
    {
        $s=$this->service();$user=auth()->user();$board=$s->board($user,$this->request->getGet());$filters=$board['filters'];
        return $this->render('kanban','Tasks Kanban',$filters+$s->lookups()+['columns'=>$board['columns'],'taskBoard'=>true,'projects'=>$s->projectChoices($user,$this->query('project_q'),$filters['project']?:null),'assignees'=>$s->assigneeFilters($user,$this->query('assignee_q'),$filters['assignee']?:null),'projectQuery'=>$this->query('project_q'),'assigneeQuery'=>$this->query('assignee_q')]);
    }
    public function show(int $id):string
    {
        $s=$this->service();$data=$s->details(auth()->user(),$id);return $this->render('show','Task details',$data+$s->lookups()+['assignees'=>$s->assignees(auth()->user(),$data['project'],$this->query('assignee_q')),'assigneeQuery'=>$this->query('assignee_q')]);
    }
    private function form(array $values,?int $id=null,array $errors=[],int $status=200):string
    {
        $s=$this->service();$user=auth()->user();$task=$id?$s->find($user,$id):null;$project=null;$projectId=$task?(int)$task['project_id']:(is_scalar($values['project_id']??null)&&ctype_digit((string)$values['project_id'])?(int)$values['project_id']:null);
        if($projectId){$project=(new ProjectService())->find($user,$projectId);(new TaskPolicy())->require($user,$id?'edit':'create',$project,$task);if((new TaskPolicy())->readonlyProject($project)||($task&&$task['archived_at'])){return $this->render('error','Task is read-only',['errors'=>['Restore the task and restore/reopen its parent project before editing.'],'taskId'=>$id,'projectId'=>$projectId],422);}}
        elseif(!$user->can('tasks.create')){throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();}
        return $this->render('form',$id?'Edit task':'Create task',compact('values','id','errors','task','project')+$s->lookups()+['projects'=>$id?[]:$s->projectChoices($user,$this->query('project_q'),$projectId,true),'assignees'=>$project?$s->assignees($user,$project,$this->query('assignee_q')):[],'projectQuery'=>$this->query('project_q'),'assigneeQuery'=>$this->query('assignee_q')],$status);
    }
    public function create():string{return $this->form(['project_id'=>$this->query('project')]+$this->service()->defaults());}
    public function edit(int $id):string{return $this->form($this->service()->find(auth()->user(),$id),$id);}
    public function store(){return $this->persist();}
    public function update(int $id){return $this->persist($id);}
    private function persist(?int $id=null)
    {
        $input=$this->request->getPost();try{$saved=$this->service()->save(auth()->user(),$input,$id);return redirect()->to(site_url('tasks/'.$saved))->with('success',$id?'Task updated.':'Task created.');}
        catch(TaskInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors,'csrf'=>csrf_hash()]);}return $this->form($input,$id,$e->errors,$e->status);}
    }
    private function mutate(int $id,callable $operation)
    {
        try{$operation();if($this->request->isAJAX()){return $this->response->setJSON(['message'=>'Task updated.','csrf'=>csrf_hash()]);}return redirect()->to(site_url('tasks/'.$id))->with('success','Task updated.');}
        catch(TaskInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors,'csrf'=>csrf_hash()]);}return $this->render('error','Task change needs review',['errors'=>$e->errors,'taskId'=>$id],$e->status);}
    }
    public function status(int $id){return $this->mutate($id,fn()=>$this->service()->status(auth()->user(),$id,$this->request->getPost('status_id'),$this->request->getPost('version')));}
    public function assign(int $id){return $this->mutate($id,fn()=>$this->service()->assign(auth()->user(),$id,$this->request->getPost('assignee_id'),$this->request->getPost('version')));}
    public function archive(int $id){return $this->mutate($id,fn()=>$this->service()->archive(auth()->user(),$id,true,$this->request->getPost('version')));}
    public function restore(int $id){return $this->mutate($id,fn()=>$this->service()->archive(auth()->user(),$id,false,$this->request->getPost('version')));}
}
