<?php

declare(strict_types=1);
namespace Config;

use CodeIgniter\Config\BaseConfig;

class Navigation extends BaseConfig
{
    /** Destinations are presentation-only until their business modules are implemented. */
    public array $sections = [
        'Workspace' => [
            ['dashboard', 'Dashboard', 'dashboard'], ['events', 'Events', 'calendar'],
            ['clients', 'Clients', 'users', [], 'clients.view'], ['projects', 'Projects', 'folder', [], 'projects.view'],
            ['tasks', 'Tasks', 'check'], ['leads', 'Leads', 'layers', [], 'leads.view'],
            ['subscriptions', 'Subscriptions', 'repeat'],
        ],
        'Business' => [
            ['sales', 'Sales', 'cart', ['invoices' => 'Invoices', 'payments' => 'Payments', 'orders' => 'Orders', 'items' => 'Items', 'contracts' => 'Contracts']],
            ['estimates', 'Estimates', 'file'], ['proposals', 'Proposals', 'pen'],
            ['expenses', 'Expenses', 'wallet'], ['reports', 'Reports', 'chart'],
        ],
        'Collaboration' => [
            ['notes', 'Notes', 'note'], ['messages', 'Messages', 'message'],
            ['team', 'Team', 'users', ['team-members' => 'Team members', 'attendance' => 'Attendance', 'leave' => 'Leave']],
            ['tickets', 'Tickets', 'ticket'], ['knowledge-base', 'Knowledge Base', 'book'], ['files', 'Files', 'folder'],
        ],
        'Administration' => [['settings', 'Settings', 'settings', [], 'admin.access']],
    ];
}
