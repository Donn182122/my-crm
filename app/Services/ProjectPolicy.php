<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
class ProjectPolicy
{
    public function administrator(User $user):bool{return $user->can('projects.manageall');}
    public function principal(User $user,array $project):bool{return $this->administrator($user)||(int)$project['owner_id']===(int)$user->id;}
    public function allows(User $user,string $action,?array $project=null):bool
    {
        if(!$user->can('projects.view')||!$user->can('projects.'.$action)){return false;}
        if($project===null){return true;}
        $principal=$this->principal($user,$project);$manager=($project['member_role']??null)==='manager';$member=in_array($project['member_role']??null,['member','manager'],true);
        if(!$principal&&!$member){return false;}
        if($action==='view'){return !$project['archived_at']||$principal;}
        if($action==='archive'){return $principal;}
        if($project['archived_at']){return false;}
        return match($action){'edit','members'=>$principal||$manager,'assign'=>$this->administrator($user),default=>false};
    }
    public function require(User $user,string $action,?array $project=null):void{if(!$this->allows($user,$action,$project)){throw PageNotFoundException::forPageNotFound();}}
}
