<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;
use RuntimeException;

/** Local integration checks; creates and removes two fictional auth users. */
class FoundationCheck extends BaseCommand
{
    protected $group = 'Foundation';
    protected $name = 'foundation:check';
    protected $description = 'Verify isolated database, schema, authentication, CSRF and role access.';

    public function run(array $params): void
    {
        $db = db_connect();
        $row = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@SESSION.sql_mode AS mode, @@character_set_connection AS charset, @@foreign_key_checks AS fk')->getRowArray();
        $this->check($row['db'] === 'my_crm_db', 'Dedicated database selected');
        $this->check($row['account'] === 'my_crm_app@localhost', 'Dedicated database account');
        $this->check(str_contains($row['mode'], 'STRICT_'), 'Strict SQL mode');
        $this->check($row['charset'] === 'utf8mb4' && (int) $row['fk'] === 1, 'utf8mb4 connection and foreign-key enforcement');
        $tables = $db->query("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->getResultArray();
        $this->check(count($tables) >= 9, 'Authentication, settings and migration tables exist');
        foreach ($tables as $table) {
            $this->check($table['ENGINE'] === 'InnoDB' && str_starts_with($table['TABLE_COLLATION'], 'utf8mb4_'), 'Schema: '.$table['TABLE_NAME']);
        }
        $fk = $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY'")->getRow()->n;
        $this->check((int) $fk >= 4, 'Authentication foreign keys exist');
        $this->check(count($db->table('migrations')->get()->getResultArray()) === 4, 'All four upstream migrations recorded');

        $debugBefore = glob(WRITEPATH.'debugbar/*.json') ?: [];
        $provider = auth()->getProvider();
        $ids = [];
        $emails = [];
        $curl = curl_init();
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15]);
        $base = 'http://localhost:8081/';
        $http = static function (string $method, string $path, array $data = []) use ($curl, $base): array {
            curl_setopt($curl, CURLOPT_URL, $base.$path);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
            } else {
                curl_setopt($curl, CURLOPT_POSTFIELDS, null);
                curl_setopt($curl, CURLOPT_HTTPGET, true);
            }
            $body = curl_exec($curl);
            if ($body === false) { throw new RuntimeException('HTTP request failed: '.curl_error($curl)); }
            return [(int) curl_getinfo($curl, CURLINFO_HTTP_CODE), $body];
        };
        $token = static function (string $html): string {
            if (! preg_match('/name="my_crm_csrf_token" value="([^"]+)"/', $html, $m)) {
                throw new RuntimeException('Expected session CSRF field missing.');
            }
            return html_entity_decode($m[1]);
        };
        try {
            [$status] = $http('GET', '');
            $this->check($status === 302, 'Guest redirected from protected home');
            [$status, $login] = $http('GET', 'login');
            $this->check($status === 200 && str_contains($login, '/assets/bootstrap/bootstrap.min.css'), 'Login loads with local Bootstrap');
            $csrf = $token($login);
            $probePassword = bin2hex(random_bytes(20));
            [$failedStatus] = $http('POST', 'login', [
                'email' => 'missing.'.bin2hex(random_bytes(6)).'@example.invalid',
                'password' => $probePassword, 'my_crm_csrf_token' => $csrf,
            ]);
            $this->check(in_array($failedStatus, [302, 303], true), 'Invalid credentials rejected');
            foreach (glob(WRITEPATH.'session/*') ?: [] as $sessionFile) {
                if (is_file($sessionFile)) {
                    $this->check(! str_contains((string) file_get_contents($sessionFile), $probePassword), 'Failed-login password absent from session storage');
                }
            }
            unset($probePassword);
            [$status] = $http('POST', 'login', ['email' => 'nobody@example.invalid', 'password' => 'invalid']);
            $this->check($status === 403, 'Login POST without CSRF rejected');
            [$status] = $http('GET', 'register');
            $this->check($status === 404, 'Public registration disabled');
            [$status] = $http('GET', '.env');
            $this->check($status === 404, 'Environment outside public web root');
            [$status] = $http('GET', 'assets/bootstrap/bootstrap.min.css');
            $this->check($status === 200, 'Local Bootstrap asset served');

            foreach (['user', 'admin'] as $group) {
                $suffix = bin2hex(random_bytes(5));
                $email = 'verify.'.$suffix.'@example.invalid';
                $password = bin2hex(random_bytes(20)).'!aA9';
                $entity = new User(['username' => 'verify'.$suffix, 'email' => $email, 'password' => $password]);
                if (! $provider->save($entity)) { throw new RuntimeException('Unable to create fictional verification user.'); }
                $id = $provider->getInsertID();
                $ids[] = $id;
                $emails[] = $email;
                $user = $provider->findById($id);
                $user->activate();
                $user->addGroup($group);
                [$status, $login] = $http('GET', 'login');
                $this->check($status === 200, 'Login form available for '.$group);
                [$status] = $http('POST', 'login', ['email' => $email, 'password' => $password, 'my_crm_csrf_token' => $token($login)]);
                $this->check(in_array($status, [302, 303], true), 'Shield login accepted for '.$group.' (HTTP '.$status.')');
                [$status, $home] = $http('GET', '');
                $this->check($status === 200 && str_contains($home, 'verify'.$suffix), 'Authenticated session for '.$group);
                [$status, $dashboard] = $http('GET', 'dashboard');
                $this->check($status === 200 && str_contains($dashboard, 'illustrative demo data'), 'Dashboard preview is authenticated and labeled');
                foreach ((new \App\Services\NavigationService())->destinations($user) as $destination) {
                    if ($destination['slug'] === 'dashboard') { continue; }
                    [$pageStatus, $page] = $http('GET', 'modules/'.$destination['slug']);
                    $this->check($pageStatus === 200 && str_contains($page, 'Coming Soon'), 'Placeholder route: '.$destination['slug']);
                }
                [$unknownStatus] = $http('GET', 'modules/not-a-module');
                $this->check($unknownStatus === 404, 'Unknown module returns 404');
                if ($group === 'user') {
                    [$settingsStatus] = $http('GET', 'modules/settings');
                    $this->check($settingsStatus === 404, 'Ordinary users cannot access Settings placeholder');
                }
                [$status, $account] = $http('GET', 'account');
                $this->check($status === 200 && str_contains($account, 'My account'), 'Account summary remains authenticated');
                [$status, $admin] = $http('GET', 'admin');
                $this->check($group === 'admin' ? ($status === 200 && str_contains($admin, 'Administrator access verified')) : $status === 302, 'Administrator role enforced for '.$group);
                [$status] = $http('GET', 'logout');
                $this->check($status === 404, 'GET cannot log out');
                [$status] = $http('POST', 'logout', ['my_crm_csrf_token' => $token($home)]);
                $this->check(in_array($status, [302, 303], true), 'CSRF-protected POST logout for '.$group);
                [$status] = $http('GET', '');
                $this->check($status === 302, 'Session removed after logout');
            }
        } finally {
            foreach ($ids as $id) {
                $db->table('auth_logins')->where('user_id', $id)->delete();
                $provider->delete($id, true);
            }
            foreach ($emails as $email) { $db->table('auth_logins')->where('identifier', $email)->delete(); }
            curl_close($curl);
        }
        $this->check($debugBefore === (glob(WRITEPATH.'debugbar/*.json') ?: []), 'Authentication requests do not persist debug payloads');
        CLI::write('All foundation integration checks passed. Fictional users removed; no administrator password was generated for daily use.', 'green');
    }

    private function check(bool $condition, string $label): void
    {
        if (! $condition) { throw new RuntimeException('FAIL: '.$label); }
        CLI::write('PASS: '.$label, 'green');
    }
}
