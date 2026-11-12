# Phase 5 integration verification

Updated 2026-11-12 after the approved guarded repair and final stabilization review.

**Final integration status: PASS.** The live named task-dependency CHECK is present and enforced. The original records and credentials are preserved, with one approved migration ledger addition. The four reviewed repair/documentation files are included in the authorized local stabilization checkpoint on integration/phase-5-complete. Its exact message is fix: restore task dependency constraint and finalize Phase 5 integration; resolve its identifier in Git history. Reviewed parent: 22837e5711ad0fb9e086198e64fd9520b891cb8c.

## Approved guarded schema repair — 2026-11-12

**Repair status: PASS.** The missing named CHECK was restored through the approved guarded additive migration and every required gate passed.

### Root cause and verified preflight

This was a genuine live-schema mismatch, not an incorrectly named constraint or a false audit match. Both SHOW CREATE TABLE and information_schema.CHECK_CONSTRAINTS/TABLE_CONSTRAINTS returned no CHECK for task_dependencies. The actual non-null unsigned endpoint columns are predecessor_id and successor_id. The existing migration declares task_dependencies_distinct CHECK (predecessor_id <> successor_id). Running that unchanged original migration on fresh disposable MariaDB creates the correctly named CHECK, and the new repair then makes no change. This rules out the original migration/Forge path as the reproduced cause. The established cause is live DDL drift relative to the applied migration contract; no retained schema-change audit identifies the action that removed/omitted the live constraint. No external actor or historical repair is assumed.

Preflight found zero self-links, no orphan/invalid endpoint/hierarchy/graph records, eleven original migration rows, and a matching 29-table record baseline. The actual application still targets my_crm_db through its restricted my_crm_app account on MariaDB 10.11.16; utf8mb4, InnoDB, application strict SQL and FK enforcement remain enabled. No original CRM connection or operation was made.

Before: no CHECK constraint in the table DDL or CHECK_CONSTRAINTS metadata. After:

```sql
CONSTRAINT `task_dependencies_distinct`
  CHECK (`predecessor_id` <> `successor_id`)
```

The earlier FAIL findings below remain as historical evidence. This approved repair update supersedes their current release status.

### Exact project changes and application method

1. app/Database/Migrations/2026-11-12-153000_EnsureTaskDependencyDistinctConstraint.php — new additive, forward-only migration, class App\Database\Migrations\EnsureTaskDependencyDistinctConstraint.
2. tests/tasks/TaskDependencyConstraintRepairTest.php — ten actual disposable MariaDB migration/integrity tests.
3. app/Commands/FoundationCheck.php — expected migration count advances from eleven to twelve and its explanatory label includes the repair. No authentication, route, permission or business behavior changes.
4. docs/phase-5-integration-verification.md — this report, repair evidence and retained detailed Phase 5C plan.

The migration verifies the table/columns and refuses existing self-links without changing any row. It accepts an already-correct named expression as a no-op, including repeated calls. Equivalent checks under another name, incorrect named expressions and unrecognized pre-existing checks require compatibility review instead of silently adding duplicates or removing anything. It refuses disabled session CHECK enforcement, unsupported repair drivers and MariaDB execution inside an ordinary transaction. Fresh SQLite tables with the original named CHECK are accepted without rebuild; a missing SQLite constraint requires separate review. down() refuses automatic integrity-constraint removal.

The approved migration's up() alone was invoked by an ignored, guarded local helper against the exact default MySQLi/my_crm_db target. It required the passing disposable-test result, verified backup manifest/file digest and unchanged affected records, and acquired a named advisory lock. It performed the single additive ALTER outside an ordinary transaction, verified the outcome and appended exactly one normal-format repair ledger row. No bulk spark migrate, force/toggling migration command, development down/rollback, history reset or pre-existing migration edit was used. MariaDB validates rows while applying the CHECK; no claim of transactional DDL rollback is made. A failure after DDL would require metadata inspection and an idempotent retry, not a rollback assumption.

The ledger now contains twelve rows. All eleven original migration rows remain identical. The original 29-table baseline file was retained unchanged. The comparison helper verifies the exact newly approved ledger record separately, excludes only that exact record when comparing the old migration hash, and requires exact baseline equality for the remaining 28 tables and all old ledger records. It does not replace the old baseline with the new database state.

### Backup and recovery proof

Before live DDL, only my_crm_db.task_dependencies was dumped with schema/data to ignored backups/task-dependency-check-repair-20261112.sql, using a temporary ignored option file so no database password appeared in command arguments/output. The temporary option file was removed. The backup SHA-256 and affected-table row hash were recorded locally.

The SQL dump was restored into the newly provisioned isolated my_crm_repair_test_* MariaDB database, with independent restricted test credentials and matching parent-key stubs. Restored SHOW CREATE TABLE and every affected row matched the development source exactly before applying repair. The restored fixture tables were then removed only from that disposable database. The development dependency table was empty at backup time, so recovery verified that exact empty state and complete DDL. Separate MariaDB tests verified preservation of nonempty fictional dependency rows during repair. The verified backup is retained for review; there was no restore, overwrite or rollback of a development table. Test database/account cleanup is recorded in final results below.

### Repair verification and limitations

PASS: all requested repair gates completed.

| Post-repair verification | Result |
| --- | --- |
| Full automated suite, including all ten MariaDB repair cases | 169 tests / 864 assertions, no skipped cases |
| Additional complete twelve-migration disposable SQLite rehearsal | 1 test / 30 assertions; forward-only down refusal verified |
| Total automated, counting each final suite once | **170 tests / 894 assertions** |
| Foundation authentication/security/configuration verifier | 184 checks |
| Projects / membership / concurrency | 73 HTTP/integration checks |
| Tasks CRUD / assignments / concurrency | 62 checks |
| Tasks Kanban | 37 checks |
| Task Collaboration | 66 checks |
| Task Dependencies / cycles / completion and reopening races | 67 checks |
| Actual login / dashboard / logout probe | 10 checks |
| Total newly rerun affected HTTP/integration checks | **499 = 184 Foundation + 305 business + 10 route checks** |
| Required live CHECK schema gate | PASS; named constraint/expression confirmed, enforcement enabled |
| Cumulative schema integrity | 34 InnoDB/utf8mb4 tables; 51 FKs; zero orphans or invalid endpoints/hierarchy/graph |
| Existing record preservation | All original 29-table baseline components match; one exact approved repair ledger entry accounted for separately |
| Backup recovery | PASS: isolated MariaDB restore matches original affected-table DDL and row hash |
| Fixture cleanup | All generated development business/auth fixtures removed; disposable MariaDB database/account and private credential/option JSON removed |

The ten MariaDB tests also passed before application (10 tests/36 assertions); that earlier run is not added again to final totals. Prior unaffected Clients, Leads/conversion and 314 desktop/mobile browser checks are retained as historical verification, not claimed as newly rerun tests. No frontend or business write path changed. Post-repair login and authenticated dashboard return HTTP 200; logout removes the session. No p.due_date exception was reproduced, and zero such entries exist in retained application logs.

Final review interval beginning 2026-11-12 15:35:54 has 24 expected CSRF error headers and 28 deliberate disposable constraint/fault-injection error headers; zero unexplained ERROR/CRITICAL headers. SQL fault tests and real MariaDB worker races passed. All generated development records and accounts were cleaned before the final baseline/schema comparison. Table DDL comparison confirms that only the approved CHECK was added; normal fixture AUTO_INCREMENT advancement is excluded. The retained SQL backup's SHA-256 is e15b0e7c6ed3cb5f40909dc5326fc7d60d3e51b1f24c9586467e8774681dedca.

The ten MariaDB cases exercise fresh original migration/no-op repair; missing CHECK repair preserving existing fictional rows and repeated execution; database rejection of self-link INSERT and UPDATE; invalid existing-row abort preserving the invalid fixture for review; equivalent alternate naming; incorrect named expression; unrecognized other CHECK; disabled enforcement; ordinary-transaction refusal; and forward-only down refusal. Tests require CRM_REPAIR_TEST_CONFIG pointing to explicit MySQLi configuration for a database named my_crm_repair_test_<8 hexadecimal characters>. They skip when this optional integration environment is not provisioned. This repair run provisioned it and executed every case; no skipped case is counted as a pass. All test table resets/removals and constraint-drop simulations occur only in the disposable database.

Initial local tooling issues were corrected before applying live DDL: MariaDB CREATE USER did not support prepared placeholders; the PHPUnit helper needed a property name distinct from CIUnitTestCase's inherited db property; the one-off console needed its environment constant; and PowerShell's UTF-16 log was normalized to UTF-8 for the application guard. Each relevant gate was rerun successfully. The first application-helper attempts stopped before DDL. No failed test or schema gate was disabled or relaxed.

The metadata audit's expected ledger count was updated to twelve; its CHECK-presence, FK, unique, engine and encoding requirements remain enforced. Actual named expression and session CHECK enforcement were additionally inspected. Direct invalid INSERT/UPDATE enforcement was tested on real disposable MariaDB, rather than experimenting on permanent development rows. High-volume locking/performance, production-load DDL recovery, other database engines needing constraint rebuilds, physical mobile devices and other browser engines remain outside this repair certification. Prior desktop/mobile browser results are retained; no frontend changed. The repair is deliberately forward-only. Its migration-history entry and normal fixture auto-increment/runtime timestamps are expected changes; permanent business/security fields are preserved.

The original RISE repository remains clean at its protected commit, and its database was never queried or modified. At the repair-only delivery, branch/HEAD remained integration/phase-5-complete at 22837e5711ad0fb9e086198e64fd9520b891cb8c with four unstaged paths. The subsequently authorized stabilization checkpoint commits exactly those reviewed paths. No branch switch, push, merge or Phase 5C implementation is performed. Further publication, merge and next-phase work require separate approval.

## Historical verification before the approved repair

The following records the earlier FAIL and its evidence. Current status and repair results are given above.


Prepared 2026-11-12. Scope: local Phase 5B-2C checkpoint, cumulative integration verification, and Phase 5C planning only. No push, merge into main, new business module, credential change, existing-data reset or Phase 5C implementation is authorized or performed.

**Historical pre-repair status: FAIL (superseded by the PASS repair update above).** The live MariaDB task_dependencies table is missing the task_dependencies_distinct CHECK constraint. All completed automated, HTTP and browser suites passed; the independent final schema acceptance gate failed and remains unresolved. Preserve the current branch/commit and require a separately approved schema repair before release.

### Release checkpoint and Git ancestry

Local commit: **22837e5711ad0fb9e086198e64fd9520b891cb8c**, message `feat: implement Phase 5B-2C task dependencies`, created on feature/phase-5b-2c-task-dependencies after passing review and fresh checks. The 23-file commit contains 665 insertions/27 deletions. The working tree was explicitly verified clean immediately after committing. Current branch is integration/phase-5-complete, created directly from that checkpoint. Both branch trees are 209d0883efaa52014e68c7ec15160c20742d0848. The cumulative diff against local main spans 95 files (4876 insertions/29 deletions).

The full 23-path dependency change set was reviewed, including the complete tracked diff and all new files. Changes are confined to dependency schema/services/controller/views, existing Task integration, permissions/routes, verification commands/tests and documentation. No proprietary reference code/assets, private configuration or unrelated module implementation is included. The exact committed inventory is available from `git show --name-status 22837e5711ad0fb9e086198e64fd9520b891cb8c`.

| Milestone | Local commit |
| --- | --- |
| Phase 3 dashboard (origin/main) | 993c8704194ebf003647ba01a835bdb1b33290b8 |
| Phase 4A Clients (main) | 96d59cc11cff26df7bb849cd8f5cd3b05cbba5d2 |
| Phase 4B-1 Leads | ae20a3e889735dafbc0ed1fecdafbeca8b894906 |
| Phase 4B-2 Kanban/conversion | 0ad02821e2e14f562fced85c4a8b7effc2c1fa34 |
| Phase 5A Projects | 310fc7f8b99285954e1aa3503af62163c47e42c9 |
| Phase 5B-1 Tasks | 687031c9dd8349bc541218c4a41f16041d4ac774 |
| Phase 5B-2A Tasks Kanban | 6b4c0557a6c4746a09d7a8bdb03786ed8c946abb |
| Phase 5B-2B Collaboration | a2ccf8d8dbc3f229623d7dbec85214ed2b5d4e43 |
| Phase 5B-2C Dependencies | 22837e5711ad0fb9e086198e64fd9520b891cb8c |

All feature milestones form one linear ancestor chain; the latest branch contains their cumulative implementation. The initial foundation commit is b57777c93f47c30e0d3234415c7a06c96b843673. Remote origin is https://github.com/Donn182122/my-crm.git. A normal fetch refreshed origin/main earlier in the ongoing verification. Final comparisons use that local tracking ref; this continuation performs no fetch or remote write, so later GitHub server changes are not independently checked. Local main is one commit ahead of origin/main and zero behind. The dependency checkpoint/integration tip is seven commits ahead of local main and eight ahead of origin/main, zero behind either. These missing-on-main commits are the completed feature milestones, with Clients also absent from origin/main. No divergent line, unresolved index conflict, missing feature ancestor, accidental main update or history rewrite was found. Unimplemented modules remain Coming Soon.

The integration branch is created directly from the verified new dependency commit, with identical application tree. Main, feature milestone branches and origin/main remain at their reviewed commits. This report is written afterward on integration and intentionally remains uncommitted; the dependency checkpoint was clean immediately after its commit.

### Executed verification

The completed results were retained; only the interrupted Collaboration suite, the remaining Dependencies suite and the new targeted route check were run on the final continuation. No completed full automated/browser suite was repeated unnecessarily.

| Verification | Result |
| --- | --- |
| Existing full automated suite | PASS: 159 tests / 828 assertions |
| Additional disposable eleven-migration rehearsal | PASS: 1 test / 28 assertions |
| Total automated including separate rehearsal | **160 tests / 856 assertions** |
| Foundation integration verifier | PASS: 184 checks |
| Clients | PASS: 72 business HTTP checks |
| Leads | PASS: 87 business HTTP checks |
| Lead Kanban / conversion / retries / concurrency | PASS: 44 business checks |
| Projects / membership / ownership / concurrency | PASS: 73 business checks |
| Tasks CRUD / assignment / status / concurrency | PASS: 62 business checks |
| Tasks Kanban | PASS: 37 business checks; completed result retained |
| Task Collaboration | PASS: 66 business/concurrency checks |
| Task Dependencies | PASS: 67 business/concurrency checks |
| Actual login / dashboard / logout probe | PASS: 10 targeted HTTP checks |
| Total HTTP/integration checks, counted once per suite | **702 = 184 Foundation + 508 business + 10 targeted** |
| Desktop/mobile browser suites | PASS: 314 checks; zero unexpected console/network errors |
| Final live dependency CHECK-constraint audit | **FAIL: exit 255, Distinct endpoint CHECK missing** |

Foundation's 184 assertions include its HTTP/authentication, routes/assets/navigation and configuration/database checks; 702 is the combined verifier-check total, not a claim that all 702 are distinct network requests or simultaneous races. Repeated checkpoint/integration runs are not added together. Browser suite breakdown: Dashboard 21, Clients 30, Leads 34, pipeline/conversion 25, Projects 33, Tasks 39, Tasks Kanban 40, collaboration 50, dependencies 42. Existing tests covered 320/390/768/1024/1440 px, native/keyboard/no-JavaScript controls, mobile focus/escape, drag/drop, stale rejection and logout. Desktop/mobile screenshots were inspected.

Application-log review from 2026-11-12 07:52:14 through final verification found 84 expected CSRF ERROR/CRITICAL headers and 52 deliberate disposable negative-constraint/fault-injection headers, with zero unexplained application ERROR/CRITICAL headers. The separate failing CLI schema audit is explicitly reported above; it is not ignored because application logs are otherwise clean. No failed gate is bypassed.

Local verification uses test.ps1 and the generated-fixture commands php spark foundation:check, clients:check, leads:check, pipeline:check, projects:check, tasks:check, tasks:kanban-check, tasks:collaboration-check and tasks:dependencies-check with the independent public-root server running. The completed logs are retained under ignored .tools/integration-final-*.log. Private browser/audit helpers stay under ignored .tools. Do not run generated database fixtures concurrently or disable login throttles.

Local URLs: http://localhost:8081/login, /dashboard, /clients, /leads, /leads/kanban, /projects, /tasks and /tasks/kanban. Profile URLs need an existing authorized record ID; no fictional profile remains after cleanup.

#### Reported p.due_date login/dashboard exception

At 2026-11-12 15:08:47 the targeted actual HTTP flow used a generated administrator-role account: guest login 200 with CSRF form; guest dashboard redirect; successful CSRF-protected login; authenticated dashboard 200 rendering the expected existing preview; POST logout; guest dashboard rejection; login 200 after logout. All ten assertions passed. The generated password stayed in ignored local fixture storage/process memory, was never printed, and its account/JSON were removed immediately afterward. The 29-table protected baseline matched again.

There are zero occurrences of Unknown column 'p.due_date' in all retained project application logs, and no such query in current app/tests/public source. Current Dashboard::index invokes DashboardService::preview, which does not query Project metrics. The exception is not reproduced on the actual current login/dashboard routes. The historical report has no matching retained stack/query here, so its original cause or prior repair cannot be established. No unapproved application fix was made. Projects provide start_date/end_date; Tasks provide due_date. The future dashboard plan must use projects.end_date rather than selecting p.due_date from Projects.

Security/source review confirmed explicit authenticated routes with action/record policies and global session CSRF; safe query bindings and escaped output; inaccessible records rejected before details are returned; current membership/permissions rechecked after locks; and no client access inherited through project/task membership. Administrator-role fixtures exercise configured capabilities. Permanent private passwords are never requested, printed or changed. This is not a new manual login with the owner's permanent private password.

Dependency edits serialize on the project lock, lock both endpoints in deterministic ID order, reload state/ownership/versions, validate the actual graph, and write the relationship plus both Task versions/history events atomically. Nested DatabaseUnitOfWork calls do not commit an outer transaction. Cycle, self, cross-project, subtask, archived and closed-project restrictions remain enforced. All Task completion paths use one prerequisite guard. Only Done satisfies; Cancelled/open does not. Archived incomplete prerequisites block; archived Done prerequisites satisfy. A Done predecessor cannot reopen while any dependent remains Done, including archived/hidden dependents. Generic state/errors/history expose no restricted counterpart names, IDs or counts.

Actual MariaDB worker tests overlap reverse-edge writes, edge creation against completion, and reopening against completion. They assert valid outcomes, stale 409 rejection or rule 422 rejection, no invalid completed dependent, and exactly one history event per committed Task version. Disposable unit cases inject second-history/removal failures to verify complete rollback and outer-transaction ownership. Conversion tests retain atomic lead/client/child persistence, unique conversion per lead, authorized retry results and no unauthorized existing-client overwrite.

### Data integrity, migration and reference-system protection

A fresh hash-only baseline covers 29 protected tables: Clients and related contacts/addresses/groups/statuses/memberships; Leads and sources/statuses/contacts/addresses/history/conversions; Projects/members/statuses/history; Tasks/statuses/priorities/history/checklist/comments/dependencies; users/stable authentication identities/groups/permissions; and migrations. Login legitimately updates identity last-used and user last-active timestamps; those fields and corresponding updated_at fields are excluded, while password hashes, security fields, ownership, membership, business values and migration rows remain protected. Final equality was verified again after all fixture cleanup and the targeted route probe. This is a record/ledger baseline, not a DDL fingerprint; matching hashes do not prove every constraint still exists. Auto-increment sequences, ephemeral sessions/login throttles/logs and permitted login timestamps naturally advance during tests; equality does not assert a byte-for-byte server snapshot.

During continuation, the earlier verification process had stopped in Kanban. Four exact taskboardcheckfe8e8187 test identities and their guarded fictional Clients/Projects/Tasks remained. Cleanup verified exact IDs, usernames, creation times and business-name prefixes before deleting only those fixture records and dependent test rows. All 29 protected-table hashes then matched the baseline. The interrupted Kanban run was rerun to completion. On the later continuation, Kanban already had a completed 37-check integration result; only Collaboration had stopped after fixture creation. Five exact collabcheck40da1a35 identities (IDs 400-404) and their guarded fictional rows were removed after username/creation-time/business-prefix checks. The same protected baseline matched again before rerunning only Collaboration and Dependencies. No permanent record was deleted or reset.

Eleven migrations remain recorded: four upstream Shield/Settings migrations plus Clients, Leads, conversions, Projects, Tasks, collaboration and dependencies. The cumulative read-only MariaDB audit passes 34 InnoDB/utf8mb4 tables, 51 foreign-key constraints with zero orphan records, required module uniqueness and current hierarchy/graph consistency. Existing Task/collaboration FKs, all five dependency FKs (including same-project composites), unique endpoint pair, supporting tasks(project_id,id) index and row versions pass. Actual CodeIgniter connection checks pass foreign_key_checks=1 and strict session SQL mode. The separate distinct-endpoint CHECK audit FAILS: the required live constraint is absent. Shared MariaDB global settings and persistent DDL were not modified during this verification.

A separate fully disposable SQLite :memory: rehearsal applies all eleven migrations in order, checks preservation of earlier fictional records and idempotent reference seeds, checks foreign_key_check, reverses all eleven, and confirms removal only in that in-memory database: 1 test/28 assertions. No down migration/reset is executed against my_crm_db. MariaDB schema/race verification and SQLite migration rehearsal are distinct evidence; this does not claim a fresh MariaDB install/rollback rehearsal or high-volume engine stress test.

All generated HTTP/browser business/user fixtures and private credential JSON are removed after verification. No database dump or production/customer data is committed. .env, local tooling/fixtures, SQL dumps, private keys, credentials folders, uploads, sessions, logs and runtime caches are ignored. .env.example is an explicitly inspected public template with placeholders, not the private environment. Secret scanning covers all tracked/current candidate paths, compares actual private environment secret values without outputting them, and checks token/private-key patterns. It is a scoped local scan, not a universal guarantee that arbitrary encoded historical secrets never existed.

Original C:/xampp/htdocs/crm is only inspected through non-locking read-only Git commands. Its tree remains clean at 60676a6a401c58e6624ac47409e168006fabc0e7. No command writes that repository or queries/writes its database. No historical original-database checksum exists in this verification, so none is invented. All persistent application operations target my_crm_db; destructive rollback tests use disposable in-memory databases only.

### Remaining limitations and safe release strategy

One unresolved release blocker remains: the missing live distinct-endpoint CHECK described below. The passing application guards and empty/valid fixture cleanup do not waive the database requirement. Existing limits also remain: dependencies cover top-level same-project explicit Finish-to-Start completion, not scheduling/subtask inheritance; graph cap 2000 active edges/project, related lists/search 20 rows, history latest 50; archived blockers require restoration/eligible removal and completed chains reopen in reverse order. Graph edits invalidate both Task versions, so a 409 after unrelated collaboration is expected. Removed edges retain private audit rows without a dedicated historical-edge UI. High-volume throughput, deadlock stress, production-load engine rollback and external deployment hardening are not certified by local regression tests. Browser evidence is local isolated Chrome 157 automation at five viewport widths, including screenshot inspection; this does not certify other browser engines or physical mobile devices. The local browser/audit helpers and generated reports remain ignored under .tools; they are local verification artifacts, not committed CI/browser infrastructure. The dashboard remains clearly labeled demonstration data until Phase 5C is separately approved. Unsupported finance/attendance/calendar/team/ticket features remain unimplemented.

Recommended future merge is on hold until the missing constraint is repaired and verification passes. After that and explicit approval: refresh origin, confirm its current main and local main remain ancestors of the verified integration commit, review the final cumulative diff and this report, and rerun affected gates if any new change appears. A local `git merge --ff-only integration/phase-5-complete` on main can preserve every feature commit while ancestry remains linear; do not execute it in this task. A later GitHub PR should use a normal merge preserving reviewed history if required by repository policy. Do not force push, squash away checkpoints, rebase published history or overwrite remote changes. If origin/main advances divergently, stop for review and integrate on a separate branch with a normal merge and fresh tests. This task performs no push or merge.


#### Unresolved schema blocker: required distinct-endpoint CHECK

The declared migration app/Database/Migrations/2026-11-11-120000_CreateTaskDependencies.php:14 adds CONSTRAINT task_dependencies_distinct CHECK (predecessor_id <> successor_id). The migration ledger still records eleven migrations, but current MariaDB 10.11.16 on 127.0.0.1:3306 does not contain that live constraint. The exact read-only TABLE_CONSTRAINTS query used by the verifier returns zero CHECK constraints for task_dependencies. SHOW CREATE TABLE lists its primary/unique/index definitions and five foreign keys, with no CHECK expression or task_dependencies_distinct entry. Thus the error is a genuine live-schema/migration-contract mismatch, not an audit whitespace/name/parser issue. The existing verifier remains failing with exit 255: Distinct endpoint CHECK missing.

Earlier verification output recorded a passing CHECK audit; current metadata supersedes that result for release acceptance. Record hashes and the migration ledger still match because they do not include DDL. There is no retained schema-change audit identifying how or when the constraint disappeared, and this task issued no persistent ALTER/DROP/rollback/reset to that table. No external actor/action is assumed. The reviewed application services reject self-links, and current integrity scans found no invalid endpoints or orphan data; direct database self-link rejection is not guaranteed without the required CHECK. No persistent self-link experiment was performed against development records.

Required next repair, subject to separate approval:

1. Review and preserve the current metadata and protected-record baseline; confirm there are no existing self-links with a read-only query. Do not delete or alter existing rows to force a migration through.
2. Implement a new additive, guarded repair migration that adds the missing equivalent constraint only when absent. Do not edit the applied migration ledger, rerun old migrations, reset the database or weaken the verifier. The intended DDL is ADD CONSTRAINT task_dependencies_distinct CHECK (predecessor_id <> successor_id), applied through the separately reviewed migration, not executed in this task.
3. Verify fresh/repair/already-correct paths on a separately provisioned disposable MariaDB schema, including real self-link rejection and preservation of existing fictional rows. Keep rollback/down tests isolated. Review DDL locking requirements before application to the development database.
4. After approved application, rerun the failed live schema gate and affected dependency/migration tests; compare protected records and cleanup again. Reassess broader regression needs if any application behavior changes. Only then issue an updated PASS report and review a future merge.

No repair file, DDL change, new application commit, push or merge is created by this finalization. At that pre-repair point this report was the sole new uncommitted file; the repair inventory above describes the current four paths.

## Phase 5C implementation plan (not implemented)

Implementation requires explicit approval after reviewing this guarded repair and its verification. The following is a technical plan only.

### Scope, architecture and migration policy

Use the existing operational modules to replace supported demonstration widgets with authorized read-only aggregates. Keep existing dark layout, reusable cards/status pills/tables, navigation and secure POST logout. No billing, time tracking, calendar, tickets, notifications delivery, employee presence or other new module is part of this plan. Existing DashboardService::preview and dashboard/index currently supply demonstration arrays; replace only after user approval.

Introduce a DashboardMetricsService/read-model layer and explicit widget DTOs containing availability, value/rows, scope, interval, as-of time and permitted drilldown. Extract/reuse tested query-scope helpers from existing Clients/Leads/Projects/Tasks services, whose scopes are currently private, rather than maintaining independent copies that can drift. Keep existing writes/policies/transactions compatible. Proposed code locations are app/Services/DashboardMetricsService.php plus reusable scope helpers alongside existing Services, a thin update to app/Controllers/Dashboard.php, reusable dashboard/component views, tests/dashboard/DashboardMetricsTest.php, a generated-fixture dashboard HTTP verifier and updates to the existing dashboard browser suite. These are planned paths only; no such implementation is created here. The existing foundation dashboard permission remains, with independent per-module view gates rather than a new broad all-data permission. Initial schema change: none. Existing sources are clients/client_contacts/client_addresses/client_groups/client_statuses/client_group_memberships; leads/lead_sources/lead_statuses/lead_contacts/lead_addresses/lead_history/lead_conversions; projects/project_members/project_statuses/project_history; tasks/task_statuses/task_priorities/task_history/task_checklist_items/task_comments/task_dependencies. No materialized counters, stored blocked flags, preference tables or grant changes. Add a narrowly justified index migration only after EXPLAIN plus synthetic-volume measurement shows a need; no existing data rewrite or destructive index replacement.

### Shared visibility and counting contract

Client counts require clients.view and owner_id=current actor unless clients.manageall. Lead counts require leads.view and current owner_id unless leads.manageall; created_by does not confer access after reassignment. Project rows require projects.view and current owner/membership unless projects.manageall; archive visibility still follows ProjectPolicy. Task metrics require projects.view and tasks.view, current Project visibility and TaskPolicy. Global Task administration requires both projects.manageall and tasks.manageall. Project owner/manager may view project Tasks; ordinary members see only their created/assigned unarchived Tasks, with archived-parent restrictions. Membership never grants Clients/Leads permission.

Define an operational Task pool: independently authorized Task, unarchived Task and parent, unarchived Project not Completed/Cancelled. It includes each root and subtask once; show this definition in the interface/help. My Open Tasks further requires assignee_id=current actor and Task status category=open, even for administrators. All Tasks Overview groups this same operational pool into open/done/cancelled configured statuses. Any later closed-project/archive summary is separately labeled and filtered, never silently mixed. Count distinct base record IDs, with EXISTS for membership/group/dependency filters; contact/address/group/checklist/comment/history joins must not multiply totals. Aggregate the complete authorized set, never the first list/Kanban page or records filtered only in JavaScript.

Return zero only for a successful authorized query with zero matches. Missing permission returns unavailable without a number; no eligible denominator returns N/A; query failure returns a retryable unavailable state, never fabricated zero. Do not return hidden record IDs/names, owner directories or restricted labels in JSON/tooltips. Prefer uncached queries initially so membership/permission revocation applies on the next request. Any later cache must include actor and current policy/membership context, with immediate invalidation; never share administrator totals with ordinary users.

### Widget-by-widget data mapping

| Existing widget | Proposed actual sources and definition | Visibility / empty behavior |
| --- | --- | --- |
| Clock In / Clock Out | No backing attendance/time module | Unavailable/Coming Soon, no fabricated hours or functional clock buttons |
| My Open Tasks | tasks + task_statuses + authorized projects/membership; operational pool, category=open, assignee=current actor | Personal for every role; total plus bounded due-priority list; no rows is a real empty state |
| Events Today | No calendar/events module | Unavailable; Task dates must not be presented as calendar events |
| Amount Due | No invoice/payments module | Unavailable, no invented currency amount or zero implying settled accounts |
| Projects Overview | projects + project_statuses + scoped project_members; unarchived visible Projects, status totals and bounded recent rows | Client name/link only with independent ClientPolicy permission; no cross-client inference |
| Invoice Overview | No invoice module | Unavailable; Leads/Projects values are not invoices |
| Income vs Expenses | No financial ledger/expense module | Unavailable chart/table; no inferred revenue, profit or sample values presented as real |
| Reminders | Rename to Due tasks: My Open Tasks with due_date before today (overdue), today, or next seven calendar days | Bounded five-row computed list, explicit date window; no reminder persistence/delivery promise |
| All Tasks Overview | operational Task pool + task_statuses, group by configured ID/name/category | Full authorized aggregate, include roots/subtasks once, label scope and cancelled category explicitly |
| Team Members Overview | No implemented Team/presence module | Unavailable; auth users/project members do not establish availability, attendance or global directory authorization |
| Ticket Status | No Tickets module | Unavailable, no sample support counts |
| Project Timeline | Authorized Project start/end dates, bounded date-ordered list | Label Project dates; unscheduled is explicit. No invented Gantt bars, auto-scheduling or project duration percentages |

Optional compact Clients/Leads summary cards may reuse the vacated operational space after approval: clients unarchived by current configured status/group; leads unarchived by lead_statuses.outcome and configured pipeline status/source. Do not hardcode the English status name Active. Conversion counts join visible Leads to unique lead_conversions.lead_id and use converted_at for the selected half-open period; related Client details require separate authorization. Avoid a conversion-rate percentage until the cohort and denominator are agreed. Do not draw historical pipeline trends from current statuses alone. An optional Recent activity list can reuse lead_history/project_history/task_history only after each current parent record passes its own scope; bound it to ten events, retain generic dependency events, and exclude hidden comment bodies, authentication logs and inaccessible related-client metadata. Revocation must remove activity on the next request.

Add Ready/Blocked root-task totals only for open root Tasks in the operational pool, reusing the actual active-edge predecessor-not-Done rule. Archived Done predecessors satisfy; Cancelled/open predecessors do not. Hidden prerequisites may affect a boolean workflow state but never disclose their identities or private counts. Ready+Blocked must equal the eligible open-root denominator. Subtasks have no inherited graph state. Project progress, if approved, is Done roots / (Open+Done roots), excluding Cancelled/archived; zero eligible roots is N/A. Only label it project-wide where the actor has full project Task visibility; otherwise label visible-task completion or omit the percentage. Project Completed does not imply every Task is Done.

### Dates, timezone and freshness

Confirm the effective runtime App timezone and existing DATETIME writer convention with a disposable fixture round trip before implementing predicates. Source config falls back to UTC, while current runtime verification reports UTC+08 and the local template specifies Asia/Manila. Existing services write offsetless PHP date values; do not silently reinterpret existing values as UTC or rewrite records. Use the confirmed effective application timezone consistently for this read-only phase and display it. Any future UTC-storage migration is a separate approved task.

Treat DATE fields as calendar dates with no timezone conversion. With today D in the effective timezone: overdue due_date<D, due today=D, upcoming D+1 through D+7 inclusive; null due dates belong in Unscheduled, never Overdue. For DATETIME aggregates use half-open [start,end) boundaries converted consistently to the existing storage convention, without DATE(column) functions that defeat indexes. Capture one as-of instant for all widgets and expose refresh time. If multi-query consistency is needed, evaluate a short bounded read snapshot without writer locks; otherwise document live-read behavior. Test midnight, month/year boundaries, leap days and alternate timezone/DST cases where supported.

### UI, drilldowns and performance

Preserve the responsive layout at 320/390/768/1024/1440 px, keyboard focus, semantic headings and accessible table alternatives to charts. Label actual database widgets as live/scoped and remove their demo arrays; unavailable modules remain visibly unavailable. Existing accessible charts can render scoped dynamic status counts with a textual legend/table, not hardcoded colors/categories or inaccessible canvas-only labels. Show loading, no permission, no records, N/A and retry states distinctly. No write action or business state changes occur on dashboard GET/JSON requests.

A metric drilldown must use the exact same authorization and predicate as its count. Existing Task filters do not yet express all proposed due/category/operational conditions. Plan minimal validated read-only list/Kanban filter extensions shared with the read model, or a scoped matching-records view, before publishing those links. Do not link a personal open/due count to an unfiltered all-Tasks list and imply equality. Bound result rows to five/ten and normal paginated drilldowns; never dump all records into the browser.

Batch aggregate queries per module, avoid per-card/per-row N+1 lookups, and profile current owner/archive/status/assignee/date/project-membership and dependency indexes with EXPLAIN. Candidate composite indexes require measured selectivity and actual plan evidence; add no speculative index by default. Dependencies have a 2000-edge supported project bound; benchmark realistic fictional upper-bound projects. Agree query-count/response-time targets on a stated machine/data volume before claiming performance acceptance. Keep SQL errors/credentials/PII out of browser payloads/logging.

### Test strategy, acceptance and delivery sequence

1. Approve metric definitions, operational/archive/closed-project scope, date window, timezone, optional Clients/Leads cards and progress/drilldowns. No implementation begins from this plan alone.
2. Add read-scope/read-model tests distinguishing administrator with individual capability revocation, owner, project manager, assigned member, creator-only member, read-only member, outsider and revoked membership. Verify private Clients/Leads are not exposed through related Projects/Tasks, and personal cards stay personal for admins.
3. Add exact-count fixtures exceeding pagination limits, multiple contacts/groups/history rows, custom/inactive statuses, null dates, roots/subtasks/archived parents, completed/cancelled Projects and Task categories. Verify distinct counts, disjoint denominators, empty/N/A states and dependency readiness equality with TaskService.
4. Test qualified/conversion/retry data without duplicate Lead/Client counts or hidden Client leakage. Exercise timezone boundaries with a deterministic clock; test failed queries and immediate revocation with no cache reuse.
5. Implement scoped SQL aggregates and inspect plans on disposable synthetic-volume fixtures; require count/drilldown equality and bounded rows/query count. Keep all existing write paths and authentication/CSRF behavior unchanged.
6. Replace supported dashboard views, add exact read-only drilldown predicates, test chart/table accessibility, native keyboard/navigation, mobile widths, console/network and error behavior. GET requests must preserve protected business/credential hashes.
7. Run the full existing automated suite, all nine HTTP/MariaDB suites (including rollback/conversion/dependency races), and all nine browser suites, plus new dashboard tests. Use sequential fixtures and disposable databases for destructive tests. Preserve old module contracts; no failed test may be bypassed.
8. Record changed files, schema/index justification, measured performance, test results and limitations in a dedicated Phase 5C verification report. Remove fictional fixtures and compare permanent hashes. Implementation, local commit, push and merge remain separately controlled by user approval.

## Final stabilization checkpoint review - 2026-11-12

The complete four-file candidate diff was reviewed, including all previously untracked migration/test/report content and the single FoundationCheck ledger-count change. No new business behavior or permanent-data change was introduced during this checkpoint review. The migration retains invalid-data, alternate-expression/naming, disabled-enforcement, ordinary-transaction and forward-only safeguards; no failing gate was weakened.

Fresh stabilization results: full PHPUnit 169 tests/864 assertions, with all ten MariaDB repair cases executed against a newly provisioned isolated database; separate twelve-migration SQLite rehearsal 1 test/30 assertions. Total 170 tests/894 assertions. Fresh Foundation 184 plus Dependency HTTP/concurrency 67 = 251 integration checks. The dependency workers overlapped in reverse-edge, edge-versus-completion and reopen-versus-completion cases, preserving valid graph/history/version outcomes. Prior affected Projects/Tasks/Kanban/Collaboration and 314 browser checks are retained from the repair/integration verification above; no unchanged frontend was retested unnecessarily.

The live named task_dependencies_distinct expression and session CHECK enforcement pass. Twelve migrations, 34 InnoDB/utf8mb4 tables and 51 FK constraints pass inspection, with zero orphan/invalid endpoint/hierarchy/graph records. The affected-table DDL differs from its verified backup only by the approved CHECK, excluding normal fixture auto-increment advancement. The original 29-table protected baseline still matches, accounting separately for the exact one previously approved repair ledger entry. All generated users/records, disposable test database/account and private test JSON were removed. The permanent administrator's security fields are unchanged; role login/session/logout checks use generated credentials rather than the owner's private password. Log review from 2026-11-12 15:57:35 found three expected CSRF error headers, 28 deliberate constraint/fault-injection headers and zero unexplained errors.

Current-path scans cover 242 files, including every commit candidate. Before this checkpoint the eight unpublished feature commits and 302 distinct Git blobs were additionally scanned for forbidden private/runtime paths, actual current private environment values and key/token patterns; none was found. The scanner initially treated a valid empty blob as a failed read; explicit Git size verification fixed that audit-only case and the complete scan passed. This is scoped evidence, not a guarantee that arbitrary unknown or encoded historical credentials are detectable. Private environments, temporary accounts/configuration, SQL dumps, logs, sessions, uploads and backups remain excluded. Only the four named repair/documentation files are staged for the authorized exact-message commit. Earlier commits are not amended or rewritten. This report is included as an intended deliverable; no separate untracked verification report is left behind.

Original C:/xampp/htdocs/crm remains clean at 60676a6a401c58e6624ac47409e168006fabc0e7, using only non-locking read-only Git inspection. No operation queries or changes its database. The protected development records and credentials remain unchanged as qualified above.

### Branch ancestry and GitHub readiness

A normal fetch confirmed origin/main at 993c8704194ebf003647ba01a835bdb1b33290b8; local main remains 96d59cc11cff26df7bb849cd8f5cd3b05cbba5d2, one ahead/zero behind origin/main. All feature commits from Clients through Dependencies form a linear chain into the reviewed parent 22837e5711ad0fb9e086198e64fd9520b891cb8c. The new stabilization commit is its child. After that commit, integration is eight ahead/zero behind local main and nine ahead/zero behind origin/main. Thus a clean fast-forward is possible while these refs stay unchanged. No completed feature ancestor, module entry point or unresolved index conflict is missing; this does not imply unimplemented Coming Soon modules exist.

Both fetch and push destinations are https://github.com/Donn182122/my-crm.git with no embedded credentials. Read-only GitHub REST metadata confirms Donn182122/my-crm is PUBLIC, active, not archived/disabled, default branch main. Public status is recorded explicitly because any future push publishes the pending source/history. A read-only remote-head inspection found main and no integration/phase-5-complete branch. The GitHub connector was unavailable; ordinary Git and the public REST API provided this evidence. Fetch/read access is confirmed. Actual write authentication, protected-branch rules, repository CI enforcement and server push hooks are not exercised; push success is not guaranteed by read access. No push, remote branch creation or merge occurs in this task.

### Safe backup, publication and merge procedure for later approval

1. Preserve a local Git bundle containing the verified integration/main/origin-main ancestry and verify it. Keep it under ignored local backups, outside the public web root. The targeted dependency-table backup above is verified, but is not a full application/database recovery image. Before a release/merge, take a separate consistent full backup of my_crm_db using private interactive/option-file credentials, preserve the private environment securely, and rehearse recovery on an isolated database. Never add SQL exports, environment files or secrets to Git; never test recovery by overwriting development/permanent records. The integrity repair is forward-only and requires forward correction rather than an automatic down migration.
2. After explicit push approval, refresh origin, check that remote main and any destination branch still satisfy the reviewed ancestry, and verify the clean local tree and checkpoint hash. Push only integration/phase-5-complete with a normal upstream-setting push. Do not push all branches, force-push or publish temporary fixtures. Stop on rejection or unexpected remote commits; review/fetch and test before a normal integration.
3. Review the complete cumulative feature diff against GitHub main, not only the four-file repair. Verify required CI and backups. If local main and current origin/main remain ancestors and policy permits a direct fast-forward, an explicitly approved git merge --ff-only integration/phase-5-complete on main preserves every reviewed commit. A later normal push must also be approved. If repository policy requires a PR, use its reviewed merge workflow preserving history; do not assume GitHub's PR UI performs a local-style fast-forward. Do not squash/rebase published checkpoints or bypass branch protection. If refs advance divergently, stop for a separate integration review and regression pass.
4. Verify local/remote hashes and working-tree state after any later approved operation. Keep the current integration branch as the review checkpoint. Phase 5C implementation remains separately gated by user approval.

### Phase 5C readiness against actual schema and policies

The read-only dashboard phase is technically ready to be scoped and approved. No new business table is needed. Real operational queries must use the existing schema and current policies, not the illustrative DashboardService::preview arrays or a broad administrator shortcut.

| Planned read model / query | Verified sources, predicates and output |
| --- | --- |
| clientSummary(actor) | clients c with c.archived_at IS NULL and ClientPolicy scope; COUNT(DISTINCT c.id), optional grouping by client_statuses and EXISTS client_group_memberships; contacts/addresses must not multiply client totals |
| leadSummary(actor) | leads l with current owner scope and unarchived rows; join lead_statuses for outcome/open-lost-converted and configured status IDs, lead_sources for source grouping; COUNT(DISTINCT l.id) |
| conversionSummary(actor, interval), optional | visible leads joined to lead_conversions on unique lead_id; converted_at uses a half-open interval; resulting Client details/link require independent ClientPolicy |
| projectSummary(actor) | projects p with ProjectPolicy and unarchived rows; project_statuses grouping and bounded recent projects; use EXISTS/current project_members membership and deduplicate by project ID; client labels require separate access |
| taskSummary(actor) | existing Project/Task scope plus the explicitly defined operational pool above; group by task_statuses ID/category, count each root/subtask record once; no contacts/comments/checklist/history join fan-out |
| myOpenTasks(actor) and dueTasks(actor, D) | same operational Task pool, assignee_id=current actor for every role, category=open; tasks.due_date calendar comparisons for overdue/today/next seven days; full-set counts and a five-row stable due_date/id list |
| rootReadiness(actor) | open root Tasks in that pool; EXISTS active task_dependencies whose predecessor task_statuses.category is not done; root ready+blocked equals the eligible root denominator; hidden prerequisite details/counts remain private |
| upcomingProjectDates(actor, interval) | authorized unarchived non-completed/non-cancelled Projects with qualifying start_date/end_date in the displayed interval; bounded date-ordered list, explicit unscheduled state; Projects have end_date, not p.due_date |
| recentActivity(actor), optional | bounded ten visible lead_history/project_history/task_history events only after current parent authorization, generic dependency summaries, no hidden comment bodies or inaccessible related-client metadata |

Proposed DashboardMetricsService and reusable tested read scopes should provide these projections to the thin Dashboard controller and existing original dark components. The current service scope methods are private; expose/extract them with list/board equivalence tests rather than copying their predicates independently. Apply filtering/counting on the server before pagination; no browser-side filtering of privileged payloads.

Actual visibility rules: clients.view/leads.view plus matching owner_id unless the respective manageall capability is present; lead created_by does not grant access after reassignment. Projects require projects.view and current owner/membership unless projects.manageall; archived visibility stays restricted. Tasks require both projects.view/tasks.view and independent Project/Task scope. Global Task administration requires both projects.manageall and tasks.manageall. Owners/managers can view their authorized project Tasks; ordinary members see only their created/assigned visible Tasks. Archived tasks/parents and closed Projects must match the chosen operational cohort. Project membership never grants private Client/Lead access. My Open Tasks stays personal even for admins. Permission revocation applies on the next request, and initial metrics should be uncached.

Ready widget candidates are My Open Tasks, Projects Overview, All Tasks Overview, derived Due tasks replacing Reminders, and a Project dates list replacing the illustrative timeline. Scoped Clients/Leads/conversion/ready-blocked summaries are optional additions for approval. Clock In/Out, Events Today, Amount Due, Invoice Overview, Income vs Expenses, Team availability/presence and Ticket Status remain unavailable because the corresponding modules/data contracts do not exist. Auth users are not an employee-presence module, and Project/Lead amounts must not be treated as invoices or revenue.

Performance acceptance must bound rows to five/ten and avoid N+1 queries. Proposed budget to confirm before implementation: at most twelve metrics queries excluding fixed authentication/session queries, with one captured as-of instant, reusable SQL aggregates and measured query plans on stated fictional volumes. Use existing owner/archive/status, project-member, Task assignee/date and dependency indexes before proposing new composites; add an index only through reviewed additive evidence-based migration. Profile the dependency EXISTS path at its supported 2000-edge/project bound. Agree and measure a latency target after establishing hardware/data-volume baselines; no production-load timing claim is made here. Zero, no permission, N/A denominator and query failure require different UI states. Date-only values stay calendar dates; confirm effective application timezone and legacy DATETIME convention before interval metrics.

Required tests include exact scope/count equivalence above twenty records, group/contact/history fan-out, administrator capability revocation, owner/manager/assigned/creator/read-only/outsider/revoked membership cases, inaccessible Client links, archive/closed-project/subtask behavior, custom/inactive statuses, dependency readiness, conversion retries and date/null/midnight/year boundaries. Verify exact drilldown predicates because current list filters do not express every proposed due/category cohort. Re-run existing automated/MariaDB suites with disposable repair configuration provisioned explicitly, all nine HTTP/business suites and all nine browser suites plus new dashboard cases. Browser acceptance covers 320/390/768/1024/1440 px, keyboard/focus/logout, chart-to-table accessibility, empty/error states and zero unexpected console/network errors. Require protected-record hash equality after read-only dashboard requests and fixture cleanup. No Phase 5C controller/service/view, query endpoint or new module is implemented by this checkpoint.
