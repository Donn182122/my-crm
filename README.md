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

## Phase 5B-2A: Tasks Kanban

The authenticated board is available at http://localhost:8081/tasks/kanban. Filter by project, assignee, priority, status or title/code and switch between List/Kanban while retaining filters. Each configured status column has independent 20-card pagination. Project profiles link to `/tasks/kanban?project={id}` under the same membership and task permissions.

Drag an editable task to an active status or use its native status selector and Move button, including without JavaScript. Every change uses the existing CSRF-protected TaskService status operation, version check, completion rules and atomic history. Archived/closed parents and archived tasks remain read-only. A stale move refreshes authoritative data and requires review. Cards stay in the previous column until confirmation; rejected moves never fabricate a successful update.

There is no custom in-column reordering or new migration. Both views use persistent server ordering by title/task code/ID. Inactive statuses retain authorized existing cards but cannot receive drops. Tasks without an available alternative active status have no movement controls. All existing records, reference data and administrator credentials are preserved.

Run `C:\xampp\php\php.exe spark tasks:kanban-check` sequentially with the other generated-fixture verification commands; `tasks:check` retains real overlapping MariaDB status tests. See [Kanban verification](docs/phase-5b-2a-tasks-kanban-verification.md) for the complete changed-file inventory and test evidence. The historical [collaboration proposal](docs/phase-5b-2b-tasks-collaboration-plan.md) informed the collaboration milestone below; dependencies are described in their own milestone. Phase 5B-1 is committed locally as `687031c9dd8349bc541218c4a41f16041d4ac774`; Kanban is a reviewed local checkpoint on `feature/phase-5b-2a-tasks-kanban`; see Git history for its commit. Nothing is pushed or merged.

## Phase 5B-2B: Task collaboration

Task profiles at http://localhost:8081/tasks/{id} now include independently scoped subtasks, checklist progress and chronological comments. Create a one-level subtask from an authorized writable root task; its project and parent are fixed. Assignment, priority, dates, status, history and archive/restore reuse Tasks services. Child access never grants parent or Client access. Archiving a parent hides children from ordinary members and makes them read-only for authorized managers until restoration, without changing their own archive/completion state. Completing a child or checklist never completes the parent.

Apply the reviewed additive CreateTaskCollaboration migration using `C:\xampp\php\php.exe spark migrate` against the independent database only. It adds nullable tasks.parent_id and its FK/index, task_checklist_items and task_comments. New tables use InnoDB/utf8mb4 with actor/task foreign keys and row versions. No seed, existing-record rewrite or credential change is needed. There are now ten applied migrations including upstream foundation migrations.

Checklist add/edit/complete/reopen/archive and persistent Up/Down ordering require tasks.checklist and an existing task editor/assignee role. At most 100 active items are supported. Task viewers with tasks.comment can add and edit/hide their own comments while writable. Authorized task managers additionally need tasks.comments.moderate to hide others' comments; nobody may edit another author's text. Hidden bodies are retained privately and excluded from profile/history/direct endpoints. Comments are plain text, escaped on output, and paginated 20 per page. The default user group gains checklist/comment permissions; admin also gains moderation. Existing record isolation still applies.

Each collaboration write shares the existing project-first transaction, current task policy/state checks, CSRF, task version and atomic history. Stale submissions return 409 with reload guidance and escaped unsaved text. Related row versions also advance. Native forms work without JavaScript and support keyboard ordering. Run `C:\xampp\php\php.exe spark tasks:collaboration-check` sequentially with the existing HTTP verifiers; generated fixtures are removed afterward. `test.ps1` includes isolated rollback, migration, pagination and policy tests.

See [collaboration verification](docs/phase-5b-2b-task-collaboration-verification.md) for evidence, changed files and limitations, and [Phase 5B-2C dependency proposal](docs/phase-5b-2c-task-dependencies-plan.md) for the original design proposal. Kanban checkpoint is `6b4c0557a6c4746a09d7a8bdb03786ed8c946abb`; collaboration is a verified local checkpoint on feature/phase-5b-2b-task-collaboration; see Git history for its commit. No push or merge occurs. Dependencies are covered by the following milestone; recurrence, Gantt, attachments, billing and time tracking are not implemented.

## Phase 5B-2C: Task dependencies

Authorized top-level Task profiles at http://localhost:8081/tasks/{id} provide prerequisites, dependent tasks, scoped search, bounded pagination and native add/remove controls. Ready/Blocked badges appear in Tasks, Kanban and Project Tasks. Only top-level tasks in the same project can be explicitly linked. Subtasks keep independent completion and inherit no implicit graph relationships.

Apply the reviewed additive CreateTaskDependencies migration with `C:\xampp\php\php.exe spark migrate` against the independent database only. It adds task_dependencies and a unique tasks(project_id,id) index for same-project composite FKs, plus unique relationship, distinct-endpoint CHECK, actor/project FKs and active forward/reverse indexes. New data uses InnoDB/utf8mb4. No reference seed or rewrite of existing Task fields is required; the ledger now contains eleven migrations.

Only Done-category prerequisites satisfy completion. All completion paths, including CRUD, native status forms and Kanban, use the same guard under the existing project/task locks. A Done predecessor cannot reopen while any dependent remains Done. Reopen dependents in reverse dependency order or explicitly remove an eligible relationship first. No task is automatically completed, reopened or moved. Archived incomplete prerequisites continue blocking; archived Done prerequisites remain satisfied. Restore archived endpoints and restore/reopen their project before adding/removing relationships.

Graph management requires tasks.dependencies plus current owner/manager/administrator authority on both independently authorized tasks. Default groups gain the capability, while ordinary assigned members remain unable to manage the graph. Visible lists and counts are independently scoped, and generic errors/readiness never expose hidden names or IDs. Task access never grants Clients access. Mutations use POST/CSRF, both Task versions, removal edge version, deterministic task locks, post-lock cycle validation and atomic edge plus two Task/history updates. Removed edges are retained privately; re-adding reactivates the unique pair. Stale updates return 409 and require review. Active graph capacity is 2000 edges per project, and visible lists/search use 20 rows.

Run `C:\xampp\php\php.exe spark tasks:dependencies-check` sequentially with all other generated-fixture verifiers. It tests direct HTTP isolation/CSRF and actual overlapping MariaDB reverse-edge, completion and reopening races. `test.ps1` includes disposable rollback, schema, graph-limit and policy cases. See [dependency verification](docs/phase-5b-2c-task-dependencies-verification.md) and the [next milestone proposal](docs/next-milestone-operational-dashboard-proposal.md), which is planning only.

Collaboration is committed locally as a2ccf8d8dbc3f229623d7dbec85214ed2b5d4e43. Dependencies are a verified local checkpoint on feature/phase-5b-2c-task-dependencies; see Git history for the authorized commit. No push or merge occurs. Gantt, recurrence, billing and time tracking are not implemented.
