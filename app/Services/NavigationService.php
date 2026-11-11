<?php

declare(strict_types=1);
namespace App\Services;

use CodeIgniter\Shield\Entities\User;

class NavigationService
{
    public function sections(User $user): array
    {
        $sections = [];
        foreach (config('Navigation')->sections as $title => $items) {
            foreach ($items as $item) {
                [$slug, $label, $icon] = $item;
                if (isset($item[4]) && ! $user->can($item[4])) { continue; }
                $children = [];
                foreach ($item[3] ?? [] as $childSlug => $childLabel) {
                    $children[] = ['slug' => $childSlug, 'label' => $childLabel, 'url' => site_url('modules/'.$childSlug), 'parent' => $slug];
                }
                $sections[$title][] = ['slug' => $slug, 'label' => $label, 'icon' => $icon,
                    'url' => in_array($slug, ['dashboard', 'clients', 'leads', 'projects'], true) ? site_url($slug) : site_url('modules/'.$slug),
                    'comingSoon' => !in_array($slug, ['dashboard', 'clients', 'leads', 'projects'], true), 'children' => $children];
            }
        }
        return $sections;
    }

    public function destinations(User $user): array
    {
        $result = [];
        foreach ($this->sections($user) as $items) {
            foreach ($items as $item) {
                $result[] = $item;
                foreach ($item['children'] as $child) { $result[] = $child + ['comingSoon' => true]; }
            }
        }
        return $result;
    }

    public function find(string $slug, User $user): ?array
    {
        foreach ($this->destinations($user) as $item) {
            if ($item['slug'] === $slug) { return $item; }
        }
        return null;
    }
}
