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
        'clients.view' => 'View owned clients, or all clients with manageall.',
        'clients.create' => 'Create clients owned by this account.',
        'clients.edit' => 'Edit authorized clients and manage their contacts/addresses.',
        'clients.archive' => 'Archive and restore authorized clients.',
        'clients.manageall' => 'Access clients owned by other accounts.',
        'clients.lookups' => 'Manage shared client groups and statuses.',
    ];
    public array $matrix = [
        'admin' => ['foundation.access', 'admin.access', 'clients.view', 'clients.create', 'clients.edit', 'clients.archive', 'clients.manageall', 'clients.lookups'],
        'user' => ['foundation.access', 'clients.view', 'clients.create', 'clients.edit', 'clients.archive'],
    ];
}
