<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

class AuthGroups extends ShieldAuthGroups
{
    public string $defaultGroup = 'user';
    public array $groups = [
        'admin' => ['title' => 'Administrator', 'description' => 'Foundation administration.'],
        'user' => ['title' => 'User', 'description' => 'Authenticated foundation access.'],
    ];
    public array $permissions = [
        'foundation.access' => 'Access the authenticated foundation.',
        'admin.access' => 'Access the administrator verification page.',
    ];
    public array $matrix = [
        'admin' => ['foundation.access', 'admin.access'],
        'user' => ['foundation.access'],
    ];
}
