# Phase 5A Projects Foundation: implementation and verification

Date: 2026-11-11. Project: `C:\xampp\htdocs\my-crm`; database: `my_crm_db`.

## Baseline and scope

Verified clean Phase 4B-2 HEAD `0ad02821e2e14f562fced85c4a8b7effc2c1fa34`. Baseline suite passed: 59 tests, 331 assertions. Created `feature/phase-5a-projects-foundation` directly from that commit. No commit, push, merge or main-branch change is part of this delivery.

Reviewed current migrations, Clients/Leads services and policies, Shield configuration, shared layout, existing tests and Phase 4B-2 verification before implementation. No reference CRM source/assets were used. No Tasks, billing, time tracking or financial calculations were implemented.

## Delivered functionality

- Create/view/edit projects; required existing authorized non-archived client at creation; controlled client relinking; unique server-generated codes, creator/default owner, statuses, description, dates and timestamps.
- Scoped name/code search, status/client/administrator-owner filters, 20-row pagination, bounded searchable client/user choices and preserved current client/owner selections.
- Archive/restore confirmation, retained memberships/history, explicit status badges, responsive shared dark layout, accessible forms, keyboard archive confirmation and clear validation/409 recovery.
- Add/remove manager/member relationships; restricted owner assignment; live membership checks; atomic versioned history for details, ownership, client association, status, membership and archive changes.
- Project profile with client link only when independently authorized, owner/creator, members, dates, description and latest 50 history events. Future Tasks/Milestones/Files/Timesheets/Expenses/Invoices are visibly Coming Soon without misleading action buttons.
- Projects sidebar activation, permission filtering and authorized `/modules/projects` redirect.
- Client-profile Projects section checks Clients authorization first, then shows only independently authorized unarchived projects, with a scoped full-list link. Archived clients cannot receive new projects. Client archive never cascades to project archive.

## Authorization contract

All roles also require the corresponding Shield action permission. Project roles are stored per membership, not added as broad global groups.

| Actor | View | Edit details | Membership administration | Archive/restore | Change owner/client |
|---|---|---|---|---|---|
| Administrator with projects.manageall | All | All | All | All | Owner requires projects.assign; client requires separate Clients access |
| Project owner | Own, including archived | Unarchived own | Add/remove regular members or managers | Own | Client relink requires independent authorized active client; owner reassignment is admin-only |
| Assigned manager | Assigned unarchived | Assigned unarchived | Add/remove regular members only; cannot promote/remove managers | No | No |
| Assigned member | Assigned unarchived | No in foundation | No | No | No |
| Unassigned staff | No | No | No | No | No |

Creator tracking does not grant access after ownership transfer. Membership does not grant Clients access; unauthorized client names/contact fields/profile links are hidden, and unauthorized client filters/relinks are rejected. Managers may edit permitted project details without being able to access its client. Archive hides projects from members/managers until authorized restoration. Current Completed/Cancelled statuses do not independently freeze unarchived project details; archive is the foundation's read-only boundary.

Permissions and membership are rechecked under the project lock on every mutation. Revocation is effective on the next HTTP request; stale forms cannot retain removed membership/global privileges. Owner/membership/status changes share one versioned history transaction. CAS version checks and lock timeout/deadlock handling return safe 409 conflicts. Existing client locks are acquired before project locks during creation/relinking; no existing client data is overwritten. State-changing endpoints are POST-only and globally session-CSRF protected.

## Additive schema

Migration: `app/Database/Migrations/2026-11-11-090000_CreateProjectManagement.php`.

- `project_statuses`: unique slug, label, ordered position, active flag, timestamps.
- `projects`: unique code, name/description, required client/owner/creator/status FKs, optional valid date range, unsigned version, archive state and timestamps.
- `project_members`: unique project/user primary key, role and assigning actor, timestamps.
- `project_history`: unique project/version, actor, event/summary/metadata, timestamps.

All four tables use InnoDB/utf8mb4. Nine foreign keys use matching unsigned types; parent user/client/status references restrict deletion; project children cascade only on explicit project deletion (there is no project-delete endpoint). Appropriate owner/client/status/archive, member and history indexes are present. No migration rollback/reset was run on the development database.

Applied successfully to my_crm_db. `ProjectReferenceSeeder` was run twice: Planning, Active, On Hold, Completed and Cancelled remain single rows. The seeder preserves custom labels/positions/state. Eight total migration records are expected. All previous business/security invariants passed before and after migration.

## Test evidence

- Baseline: 59 tests, 331 assertions passed before changes.
- Current isolated automated suite: **78 tests, 436 assertions passed**; nineteen new Projects tests cover ownership/membership/independent Clients access, validation, dates/statuses, archive/restore, version conflicts, reassignment, revocation, filtering/pagination, duplicate membership, FK enforcement, idempotent seeds and nested transaction ownership.
- Failure-injection and migration-down/up tests use disposable SQLite memory databases. History failure rolls back project creation; membership history failure rolls back membership, version and history. Migration rollback preserves existing Clients in that isolated database.
- First real Projects HTTP/concurrency run: **72 checks passed**, generated fixtures removed. Final run passed **73 checks**, including real Shield permission revocation on the next request.
- Real simultaneous Project edits run separate PHP processes/connections behind a shared start barrier: execution overlaps; exactly one update succeeds and one receives 409.
- Projects browser: **33 checks passed**, zero unexpected console/network errors, widths 1440/1024/768/390/320. Covers create/edit/date validation, client integration, manager addition/removal, keyboard archive/restore, searching, navigation, Coming Soon tools and POST logout. Desktop/mobile screenshots reviewed under ignored `.tools/screenshots`.
- Final existing Foundation, Clients, Leads, pipeline/conversion and browser regressions all passed sequentially; results appear below.

The initial new test property conflicted with CIUnitTestCase's inherited `$db`; it was renamed before the suite passed. A browser assertion initially used the wrong active-menu class; it was corrected to the existing accessible aria-current convention. No application workaround or security relaxation was introduced for those test errors.

## Data protection and limits

Permanent credentials were never entered or modified. Existing business rows and stable authentication/security fields are checked against preexisting digests, excluding routine Shield login timestamps. Temporary verification accounts/business records are generated with fictional data and removed in finally blocks. Browser credentials remain in ignored local tooling only during a run, then their fixture file is deleted. No runtime file, environment, dump, fixture export or secret belongs in the source diff.

All application/database writes target my-crm and my_crm_db. The original RISE CRM was not modified; its database was never targeted. Its historical byte-for-byte database equality cannot be independently established without an earlier snapshot; protection is evidenced by execution scope, dedicated-schema privileges and unchanged original Git state.

Limits: owner-only account model rather than multi-tenancy; members have view-only foundation behavior until Tasks; owner reassignment admin-only; role changes use explicit remove/add; archived projects are owner/admin-only; reference statuses are seeded but no status-administration UI; no hard project deletion; latest 50 history events in the profile; twenty choices per username/client search; Chrome-only browser coverage. Project members and client-contact lists are not independently paginated. Dashboard business widgets remain honest demos; no project/task/financial statistics are fabricated as live totals. No automatic project creation on lead conversion. No production deployment is included.

## Local URLs

- http://localhost:8081/projects
- http://localhost:8081/projects/new
- `/projects/{id}` and `/projects/{id}/edit`
- http://localhost:8081/modules/projects (authorized redirect)
- `/clients/{id}` (independently authorized Projects section)

With the public-root dev server and MariaDB running: `test.ps1`, then sequential `spark foundation:check`, `spark clients:check`, `spark leads:check`, `spark pipeline:check`, `spark projects:check`. Login throttling stays enabled; fixture suites must not overlap.

The Phase 5B Tasks proposal is in `docs/phase-5b-tasks-plan.md`; no Tasks implementation is included.

## Final regression results

| Check | Result |
|---|---|
| Complete automated suite | 78 tests, 436 assertions passed |
| Foundation/authentication/database | Passed |
| Clients HTTP | 72 checks passed |
| Leads HTTP | 87 checks passed |
| Kanban/conversion HTTP/concurrency | 44 checks passed |
| Projects HTTP/concurrency | 73 checks passed |
| Dashboard browser | 21 checks passed |
| Clients browser | 30 checks passed |
| Leads browser | 34 checks passed |
| Kanban/conversion browser | 25 checks passed |
| Projects browser | 33 checks passed |
| Browser total | 143 checks; zero unexpected console/network errors |
| Database schema | Nine Projects FKs, uniqueness, InnoDB/utf8mb4 and seeded statuses verified |
| Data/security protection | Preexisting business rows and permanent authentication invariants passed |

Desktop/mobile browser regressions were rerun after final selector/history/form refinements. The generic Coming Soon regression now uses Events because Projects is implemented; Projects receives its own authorized legacy-redirect test. The project form preserves authorized selected clients/owners when lookup results are bounded and includes a recovery link for stale submissions. Ownership history records previous/resulting owner identities; client linkage metadata displayed to members never discloses private client fields.

No unresolved blocking issue was identified in this local milestone. Destructive/failure-injection tests ran only in disposable databases. Real MariaDB concurrent tests used guarded fictional fixtures and removed them after all workers completed. The original CRM received no writes. Remaining operating limits are documented above.

## Complete changed-file inventory

- `app/Commands/FoundationCheck.php`
- `app/Commands/ProjectsCheck.php`
- `app/Config/AuthGroups.php`
- `app/Config/Navigation.php`
- `app/Config/Routes.php`
- `app/Controllers/Clients.php`
- `app/Controllers/Dashboard.php`
- `app/Controllers/Projects.php`
- `app/Database/Migrations/2026-11-11-090000_CreateProjectManagement.php`
- `app/Database/Seeds/ProjectReferenceSeeder.php`
- `app/Services/DatabaseUnitOfWork.php`
- `app/Services/NavigationService.php`
- `app/Services/ProjectInputException.php`
- `app/Services/ProjectPolicy.php`
- `app/Services/ProjectService.php`
- `app/Views/clients/show.php`
- `app/Views/layouts/app.php`
- `app/Views/projects/error.php`
- `app/Views/projects/form.php`
- `app/Views/projects/index.php`
- `app/Views/projects/show.php`
- `docs/phase-5a-projects-verification.md`
- `docs/phase-5b-tasks-plan.md`
- `README.md`
- `tests/projects/ProjectServiceTest.php`
- `tests/unit/NavigationTest.php`

All 26 files are Phase 5A implementation, tests or requested documentation. New migrations/status data were applied locally; no Git commit, staging, push or merge was performed.

## Authorized Phase 5A checkpoint review

All 26 files, tracked diff and new source/tests were reviewed again. The checkpoint rerun passed 78 tests/436 assertions, 73 Projects HTTP/concurrency checks and all 143 browser checks. Protected-data and schema/fixture-cleanup checks passed. An initial browser run hit HTTP 429 after the login-heavy CLI suite; waiting for the throttle window resolved it without changing authentication limits.

The user subsequently authorized the exact local commit `feat: implement Phase 5A projects management`, followed by a new Tasks feature branch from that verified commit. The earlier no-commit statements describe the original implementation handoff. No push or merge is authorized. No credentials, database exports or generated fixture/runtime files belong to the checkpoint.
