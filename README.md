# My CRM foundation

Independent CodeIgniter 4 / PHP 8.4 / MariaDB foundation. No reference CRM source, assets, schema or customer records are included.

## Local development

Open this directory in VS Code. Run `powershell -NoProfile -ExecutionPolicy RemoteSigned -File dev.ps1`, then visit http://localhost:8081/login.
The server binds to localhost and serves only `public/`. Stop it with Ctrl+C. Do not change the shared XAMPP Apache configuration. The root `.htaccess` denies access via the shared htdocs web root.

PHP is at `C:\xampp\php\php.exe`. The launch/test scripts enable the existing ZIP, Sodium and SQLite3 DLLs per process, without editing XAMPP php.ini. GD and IMAP are not needed by this foundation; enable/install them before introducing features that require them.

Composer is project-local: `powershell -NoProfile -ExecutionPolicy RemoteSigned -File composer.ps1 --version`. Its PHAR, cache and home are under ignored `.tools/`. On another machine install official Composer, run `composer install`, and copy `.env.example` to `.env` with fresh credentials and an encryption key. Never disable TLS verification for dependency downloads.

## Database and secrets

Only `my_crm_db` is used. The `my_crm_app` localhost account has database-level permissions limited to that schema, including DDL for local migrations. It has no global privileges or grant option. Production should split migration and runtime accounts.

The database password and encryption key are randomly generated and stored only in ignored `.env`. The template contains placeholders. Never commit `.env`, local tooling or runtime files. All tables use InnoDB/utf8mb4, and the application connection enables strict SQL mode and foreign keys. File sessions are private under `writable/session` with a distinct `my_crm_session` cookie.

Apply reviewed migrations with `C:\xampp\php\php.exe spark migrate --all`. Check status with `C:\xampp\php\php.exe spark migrate:status`. Do not point this project at the reference database. PHPUnit uses SQLite memory storage and cannot run its sample migrations against the development schema by default.

## Authentication

Shield supplies session authentication, login throttling, password hashing and session rotation. CSRF is globally enabled with session tokens. Logout accepts POST only. Routes enforce both authentication and permissions. Public registration and email magic links are disabled; SMTP, account recovery and MFA need configuration in a later phase.

The `admin` group has `foundation.access` and `admin.access`; the ordinary `user` group has only `foundation.access`. The foundation ships without an administrator or default password; the local setup tool creates the account you choose.

Create and verify your first permanent local administrator from your own VS Code PowerShell terminal:

```powershell
powershell -NoProfile -ExecutionPolicy RemoteSigned -File setup-admin-console.ps1
```

The console script asks for username and email, then reads and confirms the password using masked SecureString prompts. Choose your credentials only there. An optional graphical form is available as setup-admin.ps1. The installed Shield CLI uses ordinary unmasked prompts, so do not use `shield:user create` or `shield:user password` for private password entry in this environment. The setup scripts pass credentials to the CLI-only `local:admin` command through a private stdin pipe; passwords never enter command arguments, files or chat. Shield validates and hashes the password. The setup tool refuses to create a second administrator, activates the first account, assigns the `admin` group and verifies its permissions, real HTTP login, session rotation, logout and old-session rejection. Retrying the same existing administrator verifies it without changing its password.

The private result at `writable/cache/admin-setup-result.json` contains only account identity, permissions and pass/fail check names; it is ignored by Git. No plaintext passwords or session identifiers are included. `/admin` is an authorization verification page, not a business module.

## Verification

```powershell
powershell -NoProfile -ExecutionPolicy RemoteSigned -File test.ps1
C:\xampp\php\php.exe spark foundation:check
powershell -NoProfile -ExecutionPolicy RemoteSigned -File composer.ps1 validate --strict
powershell -NoProfile -ExecutionPolicy RemoteSigned -File composer.ps1 audit
```

Start the development server before `foundation:check`. It verifies the dedicated MariaDB connection, schema, migrations, CSRF rejection, login/logout, private environment, local assets and administrator access. It creates two fictional authentication users and removes them in a finally block. Login rate limits remain active; wait at least one minute between repeated runs. Interrupted runs may require removal of `verify*` users from this new database only.

## Structure and scope

- `app/Config`: independent environment, security, routes and role configuration
- `app/Commands/FoundationCheck.php`: MariaDB and HTTP integration verification
- `app/Controllers/Home.php`: authenticated foundation and administrator checks
- `app/Views`: foundation screen and local Bootstrap auth layout
- `public/assets/bootstrap`: upstream Bootstrap distribution and MIT license
- `app/Database/Migrations`: future application migrations
- `tests`: isolated PHPUnit tests
- `writable`: private local runtime data
- `composer.json` / `composer.lock`: reproducible upstream dependencies

Business modules, customer-data imports, payments, external integrations and production deployment are outside this phase. Before any production use, configure HTTPS/Secure cookies, SMTP/recovery, MFA and least-privilege runtime credentials.

PowerShell commands above use RemoteSigned only for the launched process, allowing these locally authored scripts without changing machine or user policy. Authentication request-debug capture is disabled in development so passwords and session cookies cannot be persisted by the debug toolbar. Exception argument traces are also disabled.

The application login controller wraps Shield's login action and removes raw old-form input before session persistence. This prevents failed-login passwords from entering file sessions. The HTTP regression test checks a generated invalid password is absent from session storage and verifies authentication requests do not create debug payload files.

## Phase 4A: Clients

Clients management is available at http://localhost:8081/clients. Administrators manage all clients and shared groups/statuses. Ordinary users manage only clients they create. Company/individual records support contacts, addresses, group memberships, search/filtering/pagination and archive/restore. No other business modules have been implemented.

Apply reviewed application migrations with `C:\xampp\php\php.exe spark migrate` on a newly configured independent database after upstream Shield/settings migrations. Do not point this project at the reference CRM database. The Clients migration adds six independent tables and three initial account statuses; it does not copy records or alter administrator credentials.

With the local development server running, run `C:\xampp\php\php.exe spark clients:check` for the real MariaDB/HTTP Clients regression checks. Run this separately from `foundation:check` so their temporary authentication fixtures do not overlap. Both commands remove their fictional fixtures. The regular `test.ps1` suite tests Clients services/policies with isolated SQLite memory databases.

See [Phase 4A implementation and verification](docs/phase-4a-clients.md) for the schema, permissions, changed files, test results and current limits. The authenticated UI now uses `Dashboard` and `Clients` controllers with shared layout views. `ClientService` owns Clients validation and persistence; `ClientPolicy` enforces action permissions and record ownership. Authentication/session/CSRF configuration and the private environment file remain in place.

## Phase 4B-1: Leads foundation

Leads management is available at http://localhost:8081/leads. Administrators manage all leads and may assign eligible active users; ordinary users manage assigned leads only. Every mutation uses a version token, and stale changes return HTTP 409. Contacts, addresses, archive/restore and history are supported. Lost leads are read-only until an authorized explicit reopen operation; Converted is reserved for the dedicated conversion workflow.

On the independent database only, apply the reviewed additive migration with `C:\xampp\php\php.exe spark migrate`, then run `C:\xampp\php\php.exe spark db:seed LeadReferenceSeeder`. The seeder is idempotent and preserves existing reference customizations. It does not copy or overwrite Clients records.

Run `C:\xampp\php\php.exe spark leads:check` while dev.ps1 is serving the application. Run it sequentially with foundation:check and clients:check; it uses generated fictional fixtures and honors real login rate limits with bounded waits. test.ps1 includes isolated Leads tests.

See [Phase 4B-1 verification](docs/phase-4b-1-leads.md) and the historical [Phase 4B-2 plan](docs/phase-4b-2-plan.md). The verified Phase 4B-1 checkpoint is ae20a3e889735dafbc0ed1fecdafbeca8b894906; Phase 4B-2 branches directly from it.

## Phase 4B-2: Pipeline and conversion

The authenticated pipeline is at http://localhost:8081/leads/kanban. Columns follow configured status order and use the existing scoped search/source/status/owner filters, with at most 20 records per column and independent pagination. Drag an editable open lead or use its labeled stage selector and Move button. A conflict refreshes server state and requires review; it never silently retries a stale write. Lost leads require explicit authorized reopening, and Converted cannot be selected directly.

An unarchived Qualified lead offers Review conversion. Choose Create or Link, review the server-generated mapping and related-record plan, then explicitly confirm. Searches expose authorized active clients only. New clients belong to the converting account. Linking preserves existing client fields and ownership. Optional contact/address copying requires editing permission; matching contact emails or identical full records/addresses are retained, including existing conflicting contact details. There is no automatic name-based company/person merge.

Apply the reviewed additive CreateLeadConversions migration with `C:\xampp\php\php.exe spark migrate` against the independent database only. It adds one table with a unique lead and confirmation key plus three foreign keys. Conversion locks the lead and destination client and coordinates both modules in one transaction. Retrying an authorized conversion returns its original destination; viewing that destination always requires independent Clients authorization.

Run `C:\xampp\php\php.exe spark pipeline:check` with the development server running, sequentially after the other verification commands. It checks actual HTTP security and runs simultaneous operations on separate MariaDB connections using generated fixture records, then removes those records. The private worker mode is for guarded generated verification jobs only. `test.ps1` includes disposable SQLite failure-injection/rollback tests and module regressions. Keep `.tools/`, sessions, logs and `.env` ignored.

See [Phase 4B-2 verification](docs/phase-4b-2-verification.md) for changed files, migration details, security behavior, test evidence and limits. Phase 4B-2 is delivered as a reviewed local feature-branch checkpoint. See Git history for its commit; nothing is pushed or merged by this delivery.

## Phase 5A: Projects foundation

Projects are available at http://localhost:8081/projects. Creation requires an authorized active client. Projects support statuses, dates, owner assignment, manager/member relationships, scoped search/filtering/pagination, archive/restore and atomic versioned activity history. Every write is CSRF protected; stale forms return 409. Project membership does not grant access to the client's private information. The Client profile includes only independently authorized projects.

Apply the reviewed additive migration with `C:\xampp\php\php.exe spark migrate`, then run `C:\xampp\php\php.exe spark db:seed ProjectReferenceSeeder`. Do not change the reference CRM database. The five initial statuses are seeded idempotently; existing records and authentication credentials are preserved.

Owners and administrators manage project administration. Assigned managers can edit unarchived project details and manage regular members; members are read-only in this foundation. Shield action permissions remain mandatory for every role. Owner changes require administrator assignment privileges; client changes require separate Clients authorization. Archived projects are visible only to their authorized owner/administrator. Client archiving does not archive projects.

Run `C:\xampp\php\php.exe spark projects:check` sequentially with the other HTTP verifiers; it includes actual overlapping MariaDB writes and cleans its fictional fixtures. See [Phase 5A verification](docs/phase-5a-projects-verification.md) and [Phase 5B Tasks proposal](docs/phase-5b-tasks-plan.md). The Tasks foundation is described below; billing, files and time tracking remain unimplemented. Phase 5A is a reviewed local feature-branch checkpoint; no push or merge is performed.

## Phase 5B-1: Tasks foundation

Tasks are available at http://localhost:8081/tasks and inside authorized Project profiles. A task belongs to one fixed project and supports an optional eligible assignee, reference status/priority, dates, completion, archive/restore, filtered pagination and atomic activity history. Owners/project managers administer tasks subject to Shield action permissions; regular members see created/assigned tasks and can edit/change status only when assigned. Membership never grants private Clients access. Archived or Completed/Cancelled projects make Tasks read-only; parent restoration preserves each task's own state.

Apply the reviewed additive CreateTaskManagement migration using `C:\xampp\php\php.exe spark migrate` against the independent database only, then run `C:\xampp\php\php.exe spark db:seed TaskReferenceSeeder`. Reference seeds are idempotent. Authentication configuration, permanent accounts and existing business records are retained.

Run `C:\xampp\php\php.exe spark tasks:check` with the development server running, sequentially with the other verifiers. It checks HTTP security and overlapping MariaDB writes using guarded fictional fixtures and cleans them afterward. `test.ps1` includes disposable SQLite policy/rollback/integrity tests. Login rate limits remain enforced; verification may wait for their window to clear.

See [Tasks foundation verification](docs/phase-5b-1-tasks-verification.md) and [advanced Tasks proposal](docs/phase-5b-2-tasks-advanced-plan.md). Phase 5B-1 is a reviewed local feature-branch checkpoint; see Git history for its commit. No push or merge is performed. Kanban, subtasks, dependencies, comments, recurring tasks, billing and time tracking are not part of this implementation.
