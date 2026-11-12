# Phase 5B-2C Task Dependencies verification

Selected rules recorded before implementation on 2026-11-11. Implementation and final verification complete; dependency changes remain uncommitted pending approval.

## Verified prerequisite

Collaboration checkpoint a2ccf8d8dbc3f229623d7dbec85214ed2b5d4e43, exact approved local commit message, clean tree verified before branching directly to feature/phase-5b-2c-task-dependencies. Fresh 131 tests/715 assertions and Foundation plus 441 business HTTP/concurrency checks passed; documented unchanged UI evidence covers 272 browser checks. Existing-record/credential hashes, schema, cleanup, secrets, lint and whitespace passed. No push or merge.

## Plan conflicts resolved before implementation

The earlier plan leaves dependency type, hierarchy, removal, completion and reopening undecided. The current request fixes Finish-to-Start and top-level same-project endpoints. This milestone implements prerequisite-aware completion only, as explicitly required; it does not restrict entering In Progress or schedule start dates. Ready means ready for completion, not automatic scheduling. No subtype column is needed while only this one dependency rule exists.

Only Done-category predecessors satisfy an edge. Cancelled/open predecessors do not. Archiving retains edges and completion: an archived Done predecessor still satisfies, and an archived incomplete predecessor still blocks. Dependency mutations require both unarchived top-level tasks and a writable project, so restore archived endpoints before adding/removing. Project archival/Completed/Cancelled makes graph mutations read-only and retains relationships.

A completed dependent cannot acquire an incomplete prerequisite. A Done predecessor cannot leave Done while any active dependent is Done, including an archived/independently inaccessible dependent. Reopen dependents in reverse dependency order or explicitly remove an eligible edge first. Errors reveal no hidden names, IDs or counts. No automatic completion, reopening, Kanban movement or data rewrite occurs. Existing completed tasks remain valid because migration introduces no edges.

Management requires tasks.dependencies plus current project owner/manager or administrator authority on both independently authorized tasks. Ordinary assigned members may see scoped related tasks/readiness but cannot administer relationships. A blocked boolean is the minimum necessary workflow signal, computed from all actual prerequisites; related lists/pagination/search expose independently authorized records only, with no hidden names/counts or Client access inheritance.

## Selected schema and concurrency model

Add task_dependencies with project_id, predecessor_id, successor_id, creator, archive actor/time, row version and timestamps. Unique predecessor/successor pair; removal soft-archives, re-adding reactivates the same pair with a new row version and audit event while retaining original creation attribution. Composite FKs (project_id, endpoint_id) reference a new additive unique tasks(project_id,id) index, enforcing same-project references in the database. Add distinct-endpoint CHECK, actor/project FKs and active forward/reverse indexes. InnoDB/utf8mb4. Top-level and cycle rules remain server-enforced; parents are immutable in existing services. No Task field values are rewritten.

All mutations use the existing outer DatabaseUnitOfWork. Lock project first, then both task IDs ascending, reload scope/role/state/versions, validate graph after locks, then mutate edge and advance both Task versions with one generic history event each. Require both submitted task versions; removal also checks edge version. Nested Task service calls never commit the outer transaction. Race completion and edge changes serialize on the same project lock; stale updates return 409, invalid transitions/cycles 422 and inaccessible endpoints 404. No automatic retry. Bound active graph to 2000 edges per project and visible lists/search to 20 rows, failing closed at traversal limits.

Shared TaskService completion guards cover both save() and status(), including direct HTTP/native/keyboard/Kanban flows. Internal graph checks include hidden records for integrity without exposing details. History never stores another endpoint's private title/code. Both endpoint versions invalidate stale collaboration/status/graph forms.

Subtasks are excluded from explicit edges and inherit no implicit graph relationship. Later support requires a separately approved policy for hierarchy cycles, parent archives/completion and visibility, followed by expanded migration/service/race tests. Dependencies do not change existing independent parent/child completion.

## Implemented data model and migration

Applied only to my_crm_db: `app/Database/Migrations/2026-11-11-120000_CreateTaskDependencies.php`, class `App\Database\Migrations\CreateTaskDependencies`. Eleven migrations are now recorded, including upstream foundation migrations. No seed is required. The migration adds one table and one unique supporting index; no existing Task fields, Clients/Leads/Projects records or authentication rows are rewritten.

Table task_dependencies: unsigned id, project_id, predecessor_id, successor_id, created_by, nullable archived_at/archived_by, version default 1, created_at/updated_at. InnoDB and utf8mb4_unicode_ci. Five FK constraints include two composite same-project Task FKs, one Project FK and two actor FKs, all RESTRICT. Unique predecessor/successor pair; distinct-endpoint CHECK; indexed active forward/reverse project edges. New tasks(project_id,id) unique index supports relational same-project integrity. Top-level endpoint and cycle rules are enforced transactionally by services because a cross-row parent/cycle rule cannot be expressed by a simple FK/CHECK.

Soft removal preserves audit evidence. Re-adding an inactive pair revives its existing row with an incremented row version, retaining original creator/time and recording a fresh activity event. No public hard-delete or unconstrained parent/project reassignment endpoint exists. Migration up/down, self/orphan/cross-project database failures and injected history failures ran only in disposable SQLite databases; local MariaDB received additive up migration only.

## Service, security and transaction integration

TaskDependencyService owns relationship management, while TaskDependencyRules supplies internal integrity checks called from TaskService::completion(). Both status() and save() invoke completion under the existing project/task lock and current optimistic version, so list/native forms, CRUD, direct POST, AJAX, keyboard Move and drag/drop cannot bypass the same gate. No separate board-only completion API was introduced.

Every graph change first authorizes current context and the other endpoint, then starts/reuses the outer DatabaseUnitOfWork. It acquires Project lock and both Task locks in ascending ID order, reloads both independently scoped Tasks and Project, requires tasks.dependencies plus current manager authority, checks root/unarchived/writable state and both submitted Task versions, and revalidates active graph under the lock. Removal also verifies active edge membership and row version. The edge and both Task/history versions commit together. A failure on the second history write rolls back the first event/version and relationship. Nested services retain the outer caller's transaction ownership. Uniqueness/deadlock/lock-wait conflicts map to reviewable 409 responses; no automatic retry occurs.

Shared project serialization also protects graph-versus-completion/reopen races. Cycle validation examines the actual project graph, including records a caller might not independently see, but returns no hidden endpoint names/IDs/counts. Visible prerequisite/dependent lists, their pagination totals and search reuse the established Task scope. A task's computed blocked boolean is the minimum workflow signal necessary to explain rejected completion; it does not grant access to hidden prerequisites or private Clients. Generic dependency history events avoid counterpart titles/codes. Membership/Shield permission revocation is effective on subsequent direct/normal requests.

Default admin/user groups receive tasks.dependencies, but ordinary assigned members still fail manager authorization. Existing admin/global-administration requirements and all other capabilities remain intact. Mutations are explicit POST routes under session/Tasks-view filters and global session CSRF. Input IDs/versions are strictly validated and never trusted as role, creator or owner claims. All displayed task names/codes/query text are escaped. No proprietary CRM code/assets or new third-party dependency is used.

## UI and local URLs

Original shared dark cards/forms/status pills/alerts/pagination are reused. Task details have prerequisites, dependent tasks, scoped title/code search, native add/remove confirmation controls, computed Ready/Blocked state and clear completion/reopening guidance. Related lists and searches are bounded to 20 authorized records. Ordinary viewers receive only independently visible relationships. Subtasks explain their exclusion without inheriting implicit constraints. Archive/project readonly controls remain authoritative.

Ready/Blocked badges are integrated into Tasks list, Kanban cards and Project Tasks. They describe prerequisite readiness; normal record/permission/project restrictions still govern allowed actions. Native versioned forms work with keyboard controls and without a JavaScript dependency. Kanban retains card position/selector when the server rejects blocked completion, and errors provide explicit feedback/reload guidance.

- Dashboard: http://localhost:8081/dashboard
- Tasks list: http://localhost:8081/tasks
- Kanban: http://localhost:8081/tasks/kanban
- Task details/dependencies: http://localhost:8081/tasks/{authorized-task-id}#task-dependencies
- Project Tasks: http://localhost:8081/projects/{authorized-project-id}
- POST add: /tasks/{id}/dependencies
- POST remove: /tasks/{id}/dependencies/{edge-id}/remove

Placeholder IDs describe routes, not permanent fictional records left behind.

## Automated and browser evidence

Full PHPUnit: **159 tests, 828 assertions**, all passed. The 28 new dependency cases cover create/remove/reactivation, both endpoint/history versions, duplicate/self/direct/multi-step cycles, cross-project/inaccessible endpoints, subtasks, assigned-member/capability restrictions, membership revocation, hidden related counts, both completion paths, reverse-order reopen, Done-dependent edge addition, archived endpoints/closed projects, stale task/edge versions, manipulated edge IDs, second-history rollback, failed removal/outer rollback, actual FK/CHECK integrity, migration roundtrip, bounded pagination/search, invalid IDs, Cancelled predecessor behavior and the 2000-edge graph safety limit.

New command `C:\xampp\php\php.exe spark tasks:dependencies-check`: **67 passed HTTP/MariaDB checks**. It first verifies actual CodeIgniter session foreign-key enforcement and strict SQL mode, then tests session authentication, both CSRF endpoints, direct/native/AJAX authorization, malformed IDs, duplicate/cycle prevention, completion via status and CRUD, list/board/Project indicators, subtask/cross-project restrictions, stale versions, hidden prerequisites, permission/membership revocation, archive/reopen/removal/reactivation and history integrity. Three pairs of real worker processes use independent MariaDB connections and measured overlapping intervals:

- Concurrent reverse edges: one commit and one stale 409; no cycle.
- Dependency add versus dependent completion: one commit and one stale 409; no Done successor with an incomplete predecessor.
- Completed predecessor reopen versus dependent completion: one succeeds and the other receives rule rejection 422; the graph remains valid.

Workers are guarded to generated depcheck-owned fictional Task pairs in my_crm_db, joined before cleanup, and do not output credentials. Destructive/fault-injection tests use disposable databases rather than resetting permanent data.

Final dependency browser suite passed **42 checks**, including keyboard creation, native/CRUD blocked-completion errors, keyboard Kanban rejection, computed Project/dependent indicators, successful completion after prerequisites, reopen rejection, stale relationship forms, removal without state rewrite, 21-row prerequisite pagination, native rendering without JavaScript, mobile Escape/focus, logout and unexpected console/network checks. All five widths (1440/1024/768/390/320 px) passed no-document-overflow assertions. Desktop/mobile screenshots are ignored local tooling; both desktop and mobile profiles were visually inspected. Final confirmation also verifies blocked drag/drop and exclusion of already linked prerequisite choices.

All existing browser suites passed against the finished dependency code: Dashboard 21, Clients 30, Leads 34, Pipeline/conversion 25, Projects 33, Tasks 39, Kanban 40 and collaboration 50: **272 regression checks**. Combined final browser total is 314. Final confirmation results are recorded in the final gate below.

Commands, run sequentially with dev.ps1 serving localhost:8081:

```powershell
powershell -NoProfile -ExecutionPolicy RemoteSigned -File test.ps1
C:\xampp\php\php.exe spark foundation:check
C:\xampp\php\php.exe spark clients:check
C:\xampp\php\php.exe spark leads:check
C:\xampp\php\php.exe spark pipeline:check
C:\xampp\php\php.exe spark projects:check
C:\xampp\php\php.exe spark tasks:check
C:\xampp\php\php.exe spark tasks:kanban-check
C:\xampp\php\php.exe spark tasks:collaboration-check
C:\xampp\php\php.exe spark tasks:dependencies-check
```

Real login rate limits remain active; generated-fixture verifiers wait within bounded intervals. Never run their fixtures concurrently. The permanent administrator's private password is neither entered nor changed; generated admin-role browser fixtures test the configured role, and protected hashes verify permanent identity/group/permission preservation. This does not claim a new manual login with the owner's private password.

## Remaining limitations and resolved test issue

- Only explicit same-project, top-level Finish-to-Start prerequisites for completion. In-progress work/start dates are not blocked or auto-scheduled. Subtask dependencies need a separately approved hierarchy/visibility policy and expanded graph/race tests.
- Done alone satisfies; Cancelled/open does not. Archived incomplete predecessors may require a manager to restore or remove them. Reopening completed prerequisites can require reopening multiple dependents in reverse dependency order. No automatic cascade or Task state rewrite occurs.
- At most 2000 active edges per project, 20 visible related records/search choices per page, latest 50 Task history events. The bound fails closed; large graph/high-throughput performance, deadlock stress and database-engine migration rollback under production load are not benchmarked here.
- Graph edits advance both endpoint Task versions, so unrelated collaboration/status changes may cause expected 409s. History messages deliberately omit private counterpart details. Removed relationships retain private audit rows; no separate historical-edge browser is included.
- No Gantt, recurrence, billing, time tracking, attachments or unrelated business modules. The separate next-milestone document is a proposal only.
- Visual review also identified an already linked prerequisite remaining in add choices; the picker now excludes active relationships, with a focused regression assertion while server-side duplicate rejection remains in place. One new closed-project unit test initially omitted the existing required Project name. Its fixture was corrected without changing Project behavior or local business data; the final full suite passes.

## Reference-system and Git protection

Original CRM files were not modified and no original-database operation was issued. Original Git tree remains clean at 60676a6a401c58e6624ac47409e168006fabc0e7. No invented original-database checksum comparison is claimed: evidence is command scope and absence of original-database operations.

Collaboration checkpoint is a2ccf8d8dbc3f229623d7dbec85214ed2b5d4e43, committed with the exact authorized message after complete fresh regression checks and a clean-tree gate. Dependencies branch derives directly from it. Stable main remains 96d59cc11cff26df7bb849cd8f5cd3b05cbba5d2. Dependency changes remain unstaged and uncommitted pending explicit approval. No push or merge occurred.

## Changed/new files (23)

- app/Commands/FoundationCheck.php
- app/Commands/TaskDependenciesCheck.php
- app/Config/AuthGroups.php
- app/Config/Routes.php
- app/Controllers/TaskDependencies.php
- app/Controllers/Tasks.php
- app/Database/Migrations/2026-11-11-120000_CreateTaskDependencies.php
- app/Services/TaskDependencyRules.php
- app/Services/TaskDependencyService.php
- app/Services/TaskPolicy.php
- app/Services/TaskService.php
- app/Views/projects/show.php
- app/Views/tasks/dependencies.php
- app/Views/tasks/dependency_badge.php
- app/Views/tasks/index.php
- app/Views/tasks/kanban.php
- app/Views/tasks/show.php
- docs/next-milestone-operational-dashboard-proposal.md
- docs/phase-5b-2c-task-dependencies-verification.md
- README.md
- tests/tasks/TaskCollaborationServiceTest.php
- tests/tasks/TaskDependencyServiceTest.php
- tests/tasks/TaskServiceTest.php
## Final verification gate

All gates passed against the completed implementation. Full automated result: **159 tests / 828 assertions**. Final business HTTP/concurrency matrix: Clients 72, Leads 87, Pipeline/conversion 44, Projects 73, Tasks 62, Kanban 37, collaboration 66 and dependencies 67: **508 business checks plus Foundation verification**. The dependency verifier was rerun after adding actual CodeIgniter connection/FK/strict-session checks; its latest result is 67. Actual reverse-edge, edge-versus-completion and reopen-versus-completion workers overlapped and preserved a valid graph/history.

All eight existing browser suites passed (272 checks); final dependency confirmation passed 42, including native/CRUD/keyboard/drag rejection, linked-choice exclusion, successful completion, reopen guards, 21-row pagination, stale forms and five responsive widths. **314 browser checks** passed in total, with no unexpected console/network errors. Desktop/mobile screenshots were reviewed. No claim of a new manual permanent-password login is made: generated administrator-role fixtures and unchanged permanent credential hashes verify authentication compatibility.

After all HTTP/browser workers finished, generated users, business records and private browser credential/member JSON were removed. Existing Clients, Leads/conversions, Projects, users/stable authentication/groups/permissions and Tasks/history/statuses/priorities matched protected hash baselines. All collaboration/dependency fixture rows were removed. Read-only schema checks passed eleven migrations, existing Task/collaboration integrity plus five dependency FK constraints, same-project composites, distinct-endpoint CHECK, required indexes/uniques, row versions and InnoDB/utf8mb4. Actual application connection has foreign-key enforcement and strict SQL mode enabled.

One additional raw-mysqli audit initially applied an application strict-mode assertion to the shared server default and failed. CodeIgniter's existing strictOn setting enables STRICT_ALL_TABLES for its own session; that actual connection was then checked directly and passed. The audit was corrected to distinguish server defaults from application-session settings. No shared MariaDB global setting or original CRM configuration was changed.

All 23 changed/new paths are inventoried above and reviewed. All 20 changed PHP files passed syntax checks, Git whitespace checks passed, and private-value/key/token scanning found no matching secret. Git ignores .env, private fixture JSON/local tooling, SQL dumps, uploads, logs, sessions and credential folders. The final log interval beginning 2026-11-11 16:33:28 contains 44 expected CSRF exceptions and 78 deliberate disposable negative-constraint/fault-injection error headers from automated tests; **zero unexplained error headers** were found. Resolved CLI audit/test-fixture issues are documented above.

Final HEAD remains **a2ccf8d8dbc3f229623d7dbec85214ed2b5d4e43**, branch **feature/phase-5b-2c-task-dependencies**. Collaboration was clean immediately after its authorized commit; current working tree intentionally contains 23 unstaged dependency/test/documentation paths with an unchanged index. No Phase 5B-2C commit, push or merge occurred. Stable main remains 96d59cc11cff26df7bb849cd8f5cd3b05cbba5d2; the original CRM Git tree remains clean at 60676a6a401c58e6624ac47409e168006fabc0e7, with no original-database operations.

No critical or high-severity unresolved issue was identified within the reviewed scope and executed tests. The documented scope/performance/audit-UI limitations remain. The separate operational-dashboard document is a proposal only; the next milestone has not been implemented.

## Authorized checkpoint review — 2026-11-12

The earlier uncommitted statements above describe the pre-approval delivery. The user has now explicitly authorized the local Phase 5B-2C checkpoint. All 23 paths and their complete tracked/new-file diff were reviewed again. Fresh full PHPUnit passed 159 tests/828 assertions; all nine Foundation/business HTTP suites passed, totaling 508 business checks plus Foundation. Dependency races overlapped and preserved graph/completion/history integrity. A separate disposable SQLite rehearsal passed all eleven migrations forward/reverse and idempotent reference seeds: 1 test/28 assertions. This does not claim a fresh MariaDB rollback rehearsal.

Continuation found a stopped Kanban verifier with four exact generated test users and their fictional rows. Guarded cleanup used their exact IDs, usernames, creation times and business prefixes, restored the fresh 29-table protected hash baseline, and the interrupted suite was rerun successfully. All final fixtures were removed; permanent credentials, business records and migration ledger match the baseline. Read-only MariaDB schema/foreign-key/index/encoding checks pass, and actual CodeIgniter sessions enforce foreign keys and strict SQL mode.

All tracked/current candidate files passed private-value/token/key scanning; .env.example was inspected as a placeholder-only public template. Private environments, runtime files, fixtures and dumps remain ignored. Original CRM Git status is clean at 60676a6a401c58e6624ac47409e168006fabc0e7; no original-database operation was issued. Existing source behavior is unchanged by this checkpoint review. Commit identity is recorded in Git history and the subsequent integration report. No push or merge is performed. No critical or high-severity unresolved issue was identified within this review and executed checks; documented limits above remain.