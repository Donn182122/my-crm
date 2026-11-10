<?php

use CodeIgniter\Test\CIUnitTestCase;

final class FoundationConfigTest extends CIUnitTestCase
{
    public function testSessionCsrfIsGloballyEnforced(): void
    {
        $this->assertSame('session', config('Security')->csrfProtection);
        $this->assertContains('csrf', config('Filters')->globals['before']);
        $this->assertContains('secureheaders', config('Filters')->globals['after']);
    }

    public function testRegistrationAndEmailRecoveryStayDisabledUntilProvisioned(): void
    {
        $this->assertFalse(config('Auth')->allowRegistration);
        $this->assertFalse(config('Auth')->allowMagicLinkLogins);
        $this->assertGreaterThanOrEqual(12, config('Auth')->minimumPasswordLength);
    }

    public function testAdministratorAccessIsNotGrantedToOrdinaryUsers(): void
    {
        $groups = config('AuthGroups');
        $this->assertContains('admin.access', $groups->matrix['admin']);
        $this->assertNotContains('admin.access', $groups->matrix['user']);
        $this->assertSame('user', $groups->defaultGroup);
    }

    public function testSessionCookieDoesNotCollideWithReferenceApplication(): void
    {
        $this->assertSame('my_crm_session', config('Session')->cookieName);
        $this->assertTrue(config('Session')->regenerateDestroy);
        $this->assertTrue(config('Cookie')->httponly);
        $this->assertSame('Lax', config('Cookie')->samesite);
    }

    public function testPhpunitUsesAnIsolatedMemoryDatabase(): void
    {
        $this->assertSame('tests', config('Database')->defaultGroup);
        $this->assertSame(':memory:', config('Database')->tests['database']);
    }
}
