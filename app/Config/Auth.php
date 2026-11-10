<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\Auth as ShieldAuth;

class Auth extends ShieldAuth
{
    public bool $allowRegistration = false;
    public bool $allowMagicLinkLogins = false;
    public int $minimumPasswordLength = 12;
    public array $sessionConfig = [
        'field' => 'my_crm_user',
        'allowRemembering' => false,
        'rememberCookieName' => 'my_crm_remember',
        'rememberLength' => 2592000,
    ];

    public function __construct()
    {
        parent::__construct();
        $this->views['layout'] = 'auth/layout';
    }
}
