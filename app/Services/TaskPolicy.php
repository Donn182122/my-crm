<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
class TaskPolicy
{
    public function administrator(User $user):bool{return $user->can('projects.manageall')&&$user->can('tasks.manageall');}
    public function manager(User $user,array $project):bool{return $this->administrator($user)||(int)$project['owner_id']===(int)$user->id||($project['member_role']??null)==='manager';}
    public function readonlyProject(array $project):bool{return $project['archived_at']!==null||in_array($project['status_slug'],['completed','cancelled'],true);}
    public function allows(User $user,string $action,array $project,?array $task=null):bool
    {
        if(!(new ProjectPolicy())->allows($user,'view',$project)||!$user->can('tasks.view')||!$user->can('tasks.'.$action)){return false;}
        if($task&&(int)$task['project_id']!==(int)$project['id']){return false;}$manager=$this->manager($user,$project);
        if($action==='view'){return $manager||($task&&$task['archived_at']===null&&((int)($task['assignee_id']??0)===(int)$user->id||(int)$task['created_by']===(int)$user->id));}
        return match($action){'create','assign','archive'=>$manager,'edit','status'=>$manager||($task&&(int)($task['assignee_id']??0)===(int)$user->id),default=>false};
    }
    public function require(User $user,string $action,array $project,?array $task=null):void{if(!$this->allows($user,$action,$project,$task)){throw PageNotFoundException::forPageNotFound();}}
}
