<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;
class LeadPolicy
{
    public function allows(User $user,string $action,?array $lead=null):bool{return $user->can('leads.'.$action)&&($lead===null||$user->can('leads.manageall')||(int)$lead['owner_id']===(int)$user->id);}
    public function require(User $user,string $action,?array $lead=null):void{if(!$this->allows($user,$action,$lead)){throw PageNotFoundException::forPageNotFound();}}
}
