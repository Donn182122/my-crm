<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Services\{TaskDependencyService,TaskInputException};
class TaskDependencies extends Tasks
{
    private function change(int $id,?int $edge=null)
    {
        try{$s=new TaskDependencyService();if($edge===null){$s->add(auth()->user(),$id,$this->request->getPost());}else{$s->remove(auth()->user(),$id,$edge,$this->request->getPost());}if($this->request->isAJAX()){return $this->response->setJSON(['message'=>'Dependency updated.','csrf'=>csrf_hash()]);}return redirect()->to(site_url('tasks/'.$id.'#task-dependencies'))->with('success','Dependency updated.');}
        catch(TaskInputException $e){if($this->request->isAJAX()){return $this->response->setStatusCode($e->status)->setJSON(['errors'=>$e->errors,'csrf'=>csrf_hash()]);}return $this->render('error','Dependency change needs review',['errors'=>$e->errors,'taskId'=>$id],$e->status);}
    }
    public function add(int $id){return $this->change($id);}
    public function remove(int $id,int $edge){return $this->change($id,$edge);}
}