<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Exceptions\PageNotFoundException;

class ClientPolicy
{
    public function allows(User $user, string $action, ?array $client = null): bool
    {
        return $user->can('clients.'.$action) && ($client === null || $user->can('clients.manageall') || (int)$client['owner_id'] === (int)$user->id);
    }
    public function require(User $user, string $action, ?array $client = null): void
    {
        if (!$this->allows($user,$action,$client)) { throw PageNotFoundException::forPageNotFound(); }
    }
}
