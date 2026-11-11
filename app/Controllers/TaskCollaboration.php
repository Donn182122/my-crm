<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Services\{TaskCollaborationService,TaskInputException};
class TaskCollaboration extends Tasks
{
    private function change(int $id,callable $callback,bool $comment=false)
    {
        try{$callback();if($this->request->isAJAX()){return $this->response->setJSON(['message'=>'Collaboration updated.','csrf'=>csrf_hash()]);}return redirect()->to(site_url('tasks/'.$id.($comment?'?comments_page=last#task-comments':'#task-checklist')))->with('success','Collaboration updated.');}
        catch(TaskInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors,'csrf'=>csrf_hash()]);}$draft=$this->request->getPost($comment?'body':'label');$draft=is_string($draft)?mb_substr($draft,0,5000):'';return $this->render('collaboration_error','Collaboration change needs review',['errors'=>$e->errors,'taskId'=>$id,'draft'=>$draft],$e->status);}
    }
    private function service():TaskCollaborationService{return new TaskCollaborationService();}
    public function addItem(int $id){return $this->change($id,fn()=>$this->service()->checklist(auth()->user(),$id,$this->request->getPost()));}
    public function editItem(int $id,int $item){return $this->change($id,fn()=>$this->service()->checklist(auth()->user(),$id,$this->request->getPost(),$item));}
    public function toggleItem(int $id,int $item){return $this->change($id,fn()=>$this->service()->checklist(auth()->user(),$id,$this->request->getPost(),$item,'toggle'));}
    public function archiveItem(int $id,int $item){return $this->change($id,fn()=>$this->service()->checklist(auth()->user(),$id,$this->request->getPost(),$item,'archive'));}
    public function moveItem(int $id,int $item){return $this->change($id,fn()=>$this->service()->checklist(auth()->user(),$id,$this->request->getPost(),$item,'move'));}
    public function addComment(int $id){return $this->change($id,fn()=>$this->service()->comment(auth()->user(),$id,$this->request->getPost()),true);}
    public function editComment(int $id,int $comment){return $this->change($id,fn()=>$this->service()->comment(auth()->user(),$id,$this->request->getPost(),$comment),true);}
    public function hideComment(int $id,int $comment){return $this->change($id,fn()=>$this->service()->comment(auth()->user(),$id,$this->request->getPost(),$comment,true),true);}
}
