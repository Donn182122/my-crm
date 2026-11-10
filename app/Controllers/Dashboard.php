<?php

declare(strict_types=1);
namespace App\Controllers;

use App\Services\DashboardService;
use App\Services\NavigationService;
use CodeIgniter\Exceptions\PageNotFoundException;

class Dashboard extends BaseController
{
    private function shell(string $title, string $active, ?string $current = null): array
    {
        helper('crm');
        $user = auth()->user();
        $navigation = new NavigationService();
        return ['title' => $title, 'active' => $active, 'current' => $current ?? $active, 'user' => $user,
            'navigation' => $navigation->sections($user), 'destinations' => $navigation->destinations($user)];
    }

    public function index(): string
    {
        return view('dashboard/index', $this->shell('Dashboard', 'dashboard') + (new DashboardService())->preview());
    }

    public function comingSoon(string $slug)
    {
        if ($slug === 'clients') { return redirect()->to(site_url('clients')); }
        $item = (new NavigationService())->find($slug, auth()->user());
        if ($item === null || $slug === 'dashboard') { throw PageNotFoundException::forPageNotFound(); }
        return view('dashboard/coming_soon', $this->shell($item['label'], $item['parent'] ?? $slug, $slug) + ['module' => $item]);
    }

    public function admin(): string
    {
        return view('dashboard/admin', $this->shell('Administrator access verified', 'settings', 'admin'));
    }

    public function account(): string
    {
        return view('dashboard/account', $this->shell('My account', 'account'));
    }
}
