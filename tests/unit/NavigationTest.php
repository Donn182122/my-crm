<?php

use App\Services\NavigationService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;

final class NavigationTest extends CIUnitTestCase
{
    private function user(bool $administrator): User
    {
        $user = $this->createMock(User::class);
        $user->method('can')->willReturnCallback(static fn(string $permission): bool => $permission === 'foundation.access' || $administrator);
        return $user;
    }

    public function testAdministratorCanFindEveryRequestedModule(): void
    {
        $service = new NavigationService();
        $user = $this->user(true);
        foreach (['dashboard','events','clients','projects','tasks','leads','subscriptions','sales','estimates','proposals','notes','messages','team','tickets','knowledge-base','files','expenses','reports','settings'] as $slug) {
            $this->assertNotNull($service->find($slug, $user), $slug);
        }
    }

    public function testOrdinaryUserCannotDiscoverAdministratorSettings(): void
    {
        $service = new NavigationService();
        $this->assertNull($service->find('settings', $this->user(false)));
        $this->assertNull($service->find('projects', $this->user(false)));
        $this->assertSame(site_url('projects'), $service->find('projects', $this->user(true))['url']);
        $this->assertFalse($service->find('projects', $this->user(true))['comingSoon']);
    }

    public function testUnknownAndUnsafeModuleSlugsCannotResolve(): void
    {
        $service = new NavigationService();
        foreach (['unknown', '../settings', '<script>alert(1)</script>'] as $slug) {
            $this->assertNull($service->find($slug, $this->user(true)));
        }
    }

    public function testEveryDestinationHasAUniqueSlugAndAnInternalUrl(): void
    {
        $destinations = (new NavigationService())->destinations($this->user(true));
        $slugs = array_column($destinations, 'slug');
        $this->assertSame(count($slugs), count(array_unique($slugs)));
        foreach ($destinations as $destination) {
            $this->assertStringStartsWith(site_url(), $destination['url']);
        }
    }
}
