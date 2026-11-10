<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Validation\ValidationRules;
use RuntimeException;
use Throwable;

/** Receives credentials only over a private stdin pipe from setup-admin.ps1. */
class LocalAdminSetup extends BaseCommand
{
    protected $group = 'Foundation';
    protected $name = 'local:admin';
    protected $description = 'Private stdin backend for the masked local administrator setup window.';

    public function run(array $params)
    {
        ini_set('zend.exception_ignore_args', '1');
        // Spark leaves its display-only global option in command parameters.
        unset($params['no-header']);
        $data = [];
        $created = false;
        try {
            if ($params !== [] || ENVIRONMENT !== 'development' || config('App')->baseURL !== 'http://localhost:8081/') {
                throw new RuntimeException('Use setup-admin.ps1 in the local development project.');
            }
            $db = db_connect();
            if ($db->query('SELECT DATABASE() AS name')->getRow()->name !== 'my_crm_db') {
                throw new RuntimeException('Refusing to operate outside my_crm_db.');
            }
            $input = stream_get_contents(STDIN, 16384);
            $data = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
            unset($input);
            if (! is_array($data) || array_diff(['username', 'email', 'password', 'password_confirm'], array_keys($data))) {
                throw new RuntimeException('Complete all four local setup fields.');
            }
            foreach (['username', 'email', 'password', 'password_confirm'] as $field) {
                if (! is_string($data[$field])) { throw new RuntimeException('Invalid setup fields.'); }
            }
            if (! hash_equals($data['password'], $data['password_confirm'])) {
                throw new RuntimeException('The password confirmation does not match.');
            }
            $provider = auth()->getProvider();
            $user = $provider->where('username', $data['username'])->first();
            if ($user !== null) {
                if ($user->email !== $data['email'] || ! $user->inGroup('admin') || ! $user->active) {
                    throw new RuntimeException('That account cannot be used for first-administrator setup.');
                }
            } else {
                if ($db->table('auth_groups_users')->where('group', 'admin')->countAllResults() > 0) {
                    throw new RuntimeException('An administrator already exists. Use its username and email to verify it.');
                }
                $rules = (new ValidationRules())->getRegistrationRules();
                $validation = service('validation');
                $validation->setRules($rules);
                if (! $validation->run($data)) {
                    throw new RuntimeException('Validation failed. Use a unique username (3-30 letters, numbers or dots), a valid unique email and a strong password of at least 12 characters.');
                }
                $db->transBegin();
                try {
                    $entity = new User(['username' => $data['username'], 'email' => $data['email'], 'password' => $data['password']]);
                    if (! $provider->save($entity)) { throw new RuntimeException('Account creation failed.'); }
                    $user = $provider->findById($provider->getInsertID());
                    $user->activate();
                    $user->addGroup('admin');
                    if (! $db->transStatus()) { throw new RuntimeException('Account creation failed.'); }
                    $db->transCommit();
                    $created = true;
                    unset($entity);
                } catch (Throwable $e) {
                    $db->transRollback();
                    throw new RuntimeException('Account creation failed; no partial account was retained.');
                }
            }
            if (! $user->can('foundation.access') || ! $user->can('admin.access')) {
                throw new RuntimeException('Administrator permission verification failed.');
            }
            $checks = $this->verifyHttp($data['email'], $data['password'], $data['username']);
            $result = ['ok' => true, 'user_id' => $user->id, 'username' => $data['username'], 'group' => 'admin',
                'permissions' => ['foundation.access', 'admin.access'], 'checks' => $checks];
            file_put_contents(WRITEPATH.'cache/admin-setup-result.json', json_encode($result, JSON_PRETTY_PRINT));
            $data = [];
            echo json_encode($result), PHP_EOL;
            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Setup could not complete. Check the local server and retry.';
            if ($created) { $message = 'Account created, but verification failed. Retry with the same credentials. '.$message; }
            $data = [];
            // Do not log the exception or input; only a sanitized status crosses the pipe.
            echo json_encode(['ok' => false, 'message' => $message]), PHP_EOL;
            return EXIT_ERROR;
        }
    }

    private function verifyHttp(string $email, string $password, string $username): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15]);
        $request = static function (string $method, string $path, array $fields = []) use ($curl): array {
            curl_setopt($curl, CURLOPT_URL, 'http://localhost:8081/'.$path);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
            } else {
                curl_setopt($curl, CURLOPT_POSTFIELDS, null);
                curl_setopt($curl, CURLOPT_HTTPGET, true);
            }
            $body = curl_exec($curl);
            if ($body === false) { throw new RuntimeException('The local HTTP server is unavailable.'); }
            return [(int) curl_getinfo($curl, CURLINFO_HTTP_CODE), $body];
        };
        $token = static function (string $html): string {
            if (! preg_match('/name="my_crm_csrf_token" value="([^"]+)"/', $html, $m)) {
                throw new RuntimeException('The expected local login form was not found.');
            }
            return html_entity_decode($m[1]);
        };
        $sessionId = static function () use ($curl): string {
            foreach (curl_getinfo($curl, CURLINFO_COOKIELIST) as $cookie) {
                $parts = explode("\t", $cookie);
                if (($parts[5] ?? '') === 'my_crm_session') { return $parts[6] ?? ''; }
            }
            return '';
        };
        $checks = [];
        $require = static function (bool $condition, string $name) use (&$checks): void {
            if (! $condition) { throw new RuntimeException('Verification failed: '.$name.'.'); }
            $checks[] = $name;
        };
        try {
            [$status] = $request('GET', 'admin');
            $require($status === 302, 'unauthenticated administrator route rejected');
            [$status, $login] = $request('GET', 'login');
            $require($status === 200, 'login form available');
            $before = $sessionId();
            [$status] = $request('POST', 'login', ['email' => $email, 'password' => $password, 'my_crm_csrf_token' => $token($login)]);
            $password = '';
            $require(in_array($status, [302, 303], true), 'administrator login response');
            [$status, $home] = $request('GET', '');
            $require($status === 200 && str_contains($home, esc($username)), 'permanent administrator authenticated');
            $authenticated = $sessionId();
            $require($before !== '' && $authenticated !== '' && $before !== $authenticated, 'session identifier rotated after login');
            [$status, $admin] = $request('GET', 'admin');
            $require($status === 200 && str_contains($admin, 'Administrator access verified'), 'administrator-only page allowed');
            [$status] = $request('POST', 'logout', ['my_crm_csrf_token' => $token($home)]);
            $require(in_array($status, [302, 303], true), 'CSRF-protected logout');
            [$status] = $request('GET', 'admin');
            $require($status === 302, 'protected route rejected after logout');
            curl_setopt($curl, CURLOPT_COOKIELIST, 'ALL');
            curl_setopt($curl, CURLOPT_COOKIE, 'my_crm_session='.$authenticated);
            [$status] = $request('GET', 'admin');
            $require($status === 302, 'old authenticated session invalid after logout');
            return $checks;
        } finally {
            curl_close($curl);
        }
    }
}
