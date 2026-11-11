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
        'tasks.view' => 'View tasks within current project and task scope.',
        'tasks.create' => 'Create tasks as project owner/manager.',
        'tasks.edit' => 'Edit managed tasks or tasks assigned to you.',
        'tasks.status' => 'Change the status of permitted tasks.',
        'tasks.assign' => 'Assign managed tasks to eligible participants.',
        'tasks.archive' => 'Archive/restore managed tasks.',
        'tasks.manageall' => 'Administer tasks with project administration permission.',
        'projects.view' => 'View explicitly authorized projects.',
        'projects.create' => 'Create projects for authorized clients.',
        'projects.edit' => 'Edit owned projects or assigned manager projects.',
        'projects.archive' => 'Archive and restore owned projects.',
        'projects.members' => 'Manage project memberships within project role limits.',
        'projects.assign' => 'Assign project ownership as an administrator.',
        'projects.manageall' => 'Administer all projects.',
        'leads.convert' => 'Convert authorized Qualified leads to authorized clients.',
        'leads.view' => 'View assigned leads.',
        'leads.create' => 'Create assigned leads.',
        'leads.edit' => 'Edit authorized open leads and their related entries.',
        'leads.archive' => 'Archive and restore authorized leads.',
        'leads.manageall' => 'View and manage leads assigned to others.',
        'leads.assign' => 'Assign authorized leads to eligible active users.',
        'leads.reopen' => 'Explicitly reopen lost leads.',
        'clients.view' => 'View owned clients, or all clients with manageall.',
        'clients.create' => 'Create clients owned by this account.',
        'clients.edit' => 'Edit authorized clients and manage their contacts/addresses.',
        'clients.archive' => 'Archive and restore authorized clients.',
        'clients.manageall' => 'Access clients owned by other accounts.',
        'clients.lookups' => 'Manage shared client groups and statuses.',
    ];
    public array $matrix = [
        'admin' => ['tasks.view','tasks.create','tasks.edit','tasks.status','tasks.assign','tasks.archive','tasks.manageall','projects.view','projects.create','projects.edit','projects.archive','projects.members','projects.assign','projects.manageall','leads.convert', 'leads.view', 'leads.create', 'leads.edit', 'leads.archive', 'leads.manageall', 'leads.assign', 'leads.reopen', 'foundation.access', 'admin.access', 'clients.view', 'clients.create', 'clients.edit', 'clients.archive', 'clients.manageall', 'clients.lookups'],
        'user' => ['tasks.view','tasks.create','tasks.edit','tasks.status','tasks.assign','tasks.archive','projects.view','projects.create','projects.edit','projects.archive','projects.members','leads.convert', 'leads.view', 'leads.create', 'leads.edit', 'leads.archive', 'foundation.access', 'clients.view', 'clients.create', 'clients.edit', 'clients.archive'],
    ];
}
