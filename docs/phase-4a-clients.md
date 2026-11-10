# Phase 4A Clients management

Implemented and verified on 2026-11-10 in the independent my-crm project. This phase includes Clients only. No reference CRM code, assets, customer data or schema were copied.

## Architecture and authorization

Explicit authenticated routes call a thin Clients controller. ClientService owns validation, scoped query-builder reads and transactional writes. ClientPolicy combines Shield permissions with ownership checks. Views reuse the existing authenticated layout, navigation, dark palette and secure POST logout. No new frontend dependencies or uploads were added.

Administrators have clients.view/create/edit/archive/manageall/lookups and can manage all clients. Ordinary users have view/create/edit/archive for their own records only. An account with view but without edit/archive cannot mutate even its own records. Permission checks happen on the server for every operation. Contacts and addresses are checked against both their parent client and the current account. Unauthorized records return 404. Ownership, generated codes and archive timestamps cannot be assigned through client forms.

Shield permission keys are lowercase, as required by Shield normalization. The permanent administrator's credentials and database group assignment were not changed; the role's allowed Clients operations are defined in AuthGroups configuration.

## Migration and data design

Applied additive migration: app/Database/Migrations/2026-11-10-090000_CreateClientManagement.php.

- client_statuses: reusable account statuses with unique normalized names; Active, Prospect and Inactive are the initial reference values.
- client_groups: reusable groups with unique normalized names.
- clients: company/individual identity, generated stable code, display name, optional unique normalized email, phone, website, notes, status, owner, archive and audit timestamps.
- client_group_memberships: many-to-many membership with composite primary key.
- client_contacts: client-scoped named contacts, job titles, email and phone; unique nonempty email within each client.
- client_addresses: billing/shipping/other addresses with postal information.

All six tables use InnoDB/utf8mb4 in MariaDB, with foreign keys and indexes for ownership/archive/status, names and memberships. The migration retains existing authentication/settings/migration tables. Referential restrictions protect owners and lookups; dependent records cascade only when an explicitly targeted client is deleted (no client-delete UI or endpoint exists). Client forms use archive/restore instead of deletion. The migration contains a conventional down() for isolated tooling, but no rollback was run against my_crm_db.

Application uniqueness checks provide friendly errors and database unique indexes protect against races. Client names are intentionally not unique: different people/companies may share names. Optional email fields store NULL; duplicate nonempty client emails are rejected even for archived clients. Status and group classification is independent of the archived flag.

## Implemented workflows

- Company and individual creation, profile viewing and editing.
- Paginated listing (20 per page), literal search by name/email/code/phone, combined status/group filters and archived-record filtering.
- Archive/restore with retained related records; archived records are read-only until restored.
- Contact and address creation, editing and confirmed removal.
- Multiple group memberships and administrator creation/renaming of groups/statuses.
- Required/type/length/reference validation, duplicate handling, escaped output, CSRF tokens, success notices and error summaries.
- Profile basic/contact details, addresses, groups, status, notes and timestamps.
- Stable client IDs and a clearly marked future-connections section for projects/invoices/estimates/tickets; those modules are not implemented.

## Verification

- PHPUnit: 30 tests, 152 assertions passed, including all existing tests and 16 new Clients tests using isolated SQLite memory databases.
- Real MariaDB/HTTP: php spark clients:check passed 72 checks covering CRUD, contact/address management, duplicate handling, validation, archive/restore, search/filtering/pagination, guest denial, CSRF, escaped content, ownership isolation and real-Shield administrator cross-owner access.
- Existing php spark foundation:check passed, including database encoding/strict mode/FKs, migration status, authentication, permissions, login/logout/session behavior and password-debug privacy protections.
- Original dashboard Chrome regression: 21 checks passed.
- Clients Chrome: 30 checks passed. Actual mobile form creation/editing, contacts/addresses, archive/restore, search, navigation, logout and responsive form/profile/list checks at 320/390/768/1024/1440px passed. No page overflow or JavaScript/console/network errors observed in these runs.
- Screenshots inspected: desktop client listing and mobile profile; additional form/list screenshots are under ignored .tools/screenshots/.
- New PHP files passed syntax checks; Git whitespace checks passed.
- Fictional test users, clients, contacts, addresses, groups and statuses were removed after testing. Password-free hashes verified permanent users, authentication identities and group assignments unchanged from before migration.

During development, tests exposed literal wildcard handling and Shield permission-key normalization; both were fixed and regression-tested. One verification snapshot initially overlapped a separate test's temporary users; rerunning the checks sequentially passed. Logs include expected negative CSRF and database-constraint probes and the resolved harness failure. No unresolved runtime error was found in the final successful verification flows.

Run HTTP verification commands sequentially while dev.ps1 serves localhost:8081. They create/remove fictional fixtures in my_crm_db; PHPUnit uses memory SQLite. Browser tooling, generated temporary credentials, reports, screenshots and runtime logs remain ignored by Git. The real administrator's private password was not entered during these tests; generated test administrators exercised the same Shield login flow.

## URLs

- http://localhost:8081/clients
- http://localhost:8081/clients/new
- http://localhost:8081/clients/{id}
- http://localhost:8081/clients/{id}/edit
- http://localhost:8081/clients/lookups (administrator)

## Changed application files

- app/Commands/FoundationCheck.php
- app/Commands/ClientsCheck.php
- app/Config/AuthGroups.php
- app/Config/Navigation.php
- app/Config/Routes.php
- app/Controllers/Dashboard.php
- app/Controllers/Clients.php
- app/Database/Migrations/2026-11-10-090000_CreateClientManagement.php
- app/Services/NavigationService.php
- app/Services/ClientPolicy.php
- app/Services/ClientService.php
- app/Services/ClientValidationException.php
- app/Views/layouts/app.php
- app/Views/clients/alerts.php
- app/Views/clients/index.php
- app/Views/clients/form.php
- app/Views/clients/show.php
- app/Views/clients/child_form.php
- app/Views/clients/lookups.php
- public/assets/css/clients.css
- tests/clients/ClientServiceTest.php
- README.md
- docs/phase-4a-clients.md

## Deliberate limits

No attachments/uploads, import/export, owner reassignment UI, contact login accounts or third-party integrations. Shared groups/statuses can be added and renamed, but not deleted, to preserve assignments. No Projects, Tasks, Leads, Sales or Invoices implementation. Dashboard business widgets remain clearly labeled presentation demos; they do not claim to aggregate these client records. The data model uses account ownership rather than organizations/tenants, so a future multi-tenant deployment needs a separate authorization design. Browser validation covered Chrome, not every browser or assistive technology.

Final commit verification: all 23 Phase 4A files and their full diff were reviewed. The HTTP command additionally verifies every contact/address create/edit/remove path across owners and mismatched parent IDs, foreign client update/restore POSTs, forged ownership/parent fields, unchanged foreign records after denied attempts, and CSRF on each write category. Actual MariaDB foreign-key mappings, unique indexes and migration records match the migration. The dedicated account has no privileges on the reference database.

The reference CRM Git working tree was clean before and after verification at commit 60676a6a401c58e6624ac47409e168006fabc0e7. Reference files were inspected read-only for Git status; no reference application or database writes were performed. No historical reference-database snapshot was available, so database protection is evidenced by connection/privilege isolation and the scope of executed commands, not a historical byte-for-byte database comparison.

Permanent authentication invariants passed and all generated fixtures were removed. Secrets, environment files, private uploads, logs and generated tooling are excluded from Git. Automated tests contain fictional fixture definitions, not persisted customer/test records or credentials. This report accompanies the reviewed Phase 4A local commit; no GitHub push is authorized. Phase 4B remains planning only.
