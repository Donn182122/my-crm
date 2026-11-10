<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Controllers\LoginController as ShieldLoginController;

/** Keep Shield authentication while preventing plaintext form input in file sessions. */
class LoginController extends ShieldLoginController
{
    public function loginAction(): RedirectResponse
    {
        try {
            return parent::loginAction();
        } finally {
            // Shield uses withInput() on failure; remove all raw form values
            // before PHP persists the session at the end of the request.
            session()->remove('_ci_old_input');
        }
    }
}
