<?php

declare(strict_types=1);
namespace App\Services;

/** Explicit presentation fixtures. No operational queries or business records. */
class DashboardService
{
    public function preview(): array
    {
        return [
            'projects' => [
                ['name' => 'Website refresh', 'client' => 'Example Studio', 'initials' => 'WR', 'tone' => 'teal', 'progress' => 72, 'status' => 'In progress', 'date' => 'Nov 20'],
                ['name' => 'Brand identity', 'client' => 'Sample Collective', 'initials' => 'BI', 'tone' => 'violet', 'progress' => 48, 'status' => 'In progress', 'date' => 'Nov 25'],
                ['name' => 'Customer portal', 'client' => 'Demo Company', 'initials' => 'CP', 'tone' => 'blue', 'progress' => 25, 'status' => 'Planning', 'date' => 'Dec 08'],
                ['name' => 'Product launch', 'client' => 'Example Studio', 'initials' => 'PL', 'tone' => 'amber', 'progress' => 100, 'status' => 'Completed', 'date' => 'Nov 12'],
            ],
            'team' => [
                ['name' => 'Alex Morgan', 'initials' => 'AM', 'role' => 'Project designer', 'tasks' => 5, 'status' => 'Available', 'tone' => 'violet'],
                ['name' => 'Jamie Rivera', 'initials' => 'JR', 'role' => 'Developer', 'tasks' => 8, 'status' => 'Focus time', 'tone' => 'teal'],
                ['name' => 'Taylor Chen', 'initials' => 'TC', 'role' => 'Account manager', 'tasks' => 3, 'status' => 'Available', 'tone' => 'blue'],
            ],
            'reminders' => [
                ['title' => 'Review the website direction', 'meta' => 'Website refresh', 'time' => '10:30 AM', 'tone' => 'teal'],
                ['title' => 'Prepare the launch checklist', 'meta' => 'Product launch', 'time' => '2:00 PM', 'tone' => 'violet'],
                ['title' => 'Share the weekly update', 'meta' => 'Team check-in', 'time' => '4:30 PM', 'tone' => 'amber'],
            ],
            'tasks' => [['label' => 'To do', 'count' => 12, 'tone' => 'blue'], ['label' => 'In progress', 'count' => 8, 'tone' => 'teal'], ['label' => 'Review', 'count' => 4, 'tone' => 'violet'], ['label' => 'Completed', 'count' => 18, 'tone' => 'muted']],
            'finance' => [
                'labels' => ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'],
                'income' => [18, 27, 22, 38, 32, 46], 'expenses' => [12, 16, 14, 21, 18, 25],
            ],
        ];
    }
}
