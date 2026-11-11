# Phase 5B Tasks: proposed technical plan only

Prepared 2026-11-11. No Tasks schema, routes, controllers or behavior is implemented by this document. Separate implementation authorization is required after Phase 5A review.

## Starting architecture and scope decisions

Projects now have a required client, owner, creator, versioned details, manager/member relationships, archive state and atomic history. ProjectPolicy and ProjectService enforce Shield permissions and live membership. Client access is independent. Tasks should reference the project rather than duplicate project/client ownership or contact data.

Recommended first scope: project-required tasks with one optional assignee, CRUD, statuses/priorities, dates, search/pagination, archive/restore, history and accessible board/list views. Confirm before implementation whether members see all project tasks or only assigned/created tasks, whether multiple assignees are needed, whether due times or date-only deadlines are required, and whether a Kanban board belongs in the first increment. Standalone lead/client follow-ups are a separate scope decision.

Exclude billing, time tracking, file uploads, dependencies, recurring tasks, external notifications and automatic scheduling from the first increment. Do not create speculative cross-module tables.

## Additive schema

- `task_statuses`: unsigned ID; unique slug; name; ordered position; open/done/cancelled category; active flag; timestamps. Seed reviewed defaults idempotently without changing customizations.
- `tasks`: unsigned ID; unique random task code; required project_id FK; title VARCHAR(180); plain-text description; status_id FK; priority value from a server allowlist; optional assignee_id FK; created_by FK; optional start_date/due_date; server-derived completed_at; unsigned version; archived_at; timestamps. Match existing FK types and use InnoDB/utf8mb4.
- `task_history`: task_id/actor_id FKs; unique task_id/version; event; summary; minimal metadata; timestamps. Preserve attribution and retain records through archive/restore.
- If multiple assignees are approved, replace the single assignee field with a unique task/user relation and explicitly version assignment changes. Do not implement both representations.
- Index project/archive/status, assignee/archive/status and due dates according to scoped listing queries; confirm plans with EXPLAIN on fictional data. Avoid unconstrained lists and duplicate client_id columns.

Restrict deletion of referenced projects/users/statuses. No destructive migration runs on the development database. Verify upgrades, constraints and rollback in disposable databases; do not reseed or reset business records.

## Authorization and membership integration

Add distinct Shield task view/create/edit/archive/assign permissions and an explicitly defined administrative permission. Combine global permission, live ProjectPolicy access and task-level scope in TaskPolicy; a task ID or assignee ID never grants project/client access.

Recommended matrix for approval:

- Administrators require the appropriate Projects and Tasks permissions and may administer authorized tasks.
- Project owners/managers may manage project tasks if their Shield action permission permits it.
- Members may view assigned/created tasks and edit tasks assigned to themselves if their Shield permissions permit; they cannot assign work to other users or administer project membership.
- Unassigned staff have no task access. Creator/assignee references alone cannot bypass revoked project membership.

Resolve assignees from eligible active project members or the owner, with task viewing permission. Never trust submitted creator, project owner, role or permission flags. Check both source and destination projects if task movement is introduced; preferably defer cross-project moves initially.

Removing project membership must immediately revoke task access on every direct URL, board query and mutation. Preserve task/history records and prior assignment attribution; flag an ineligible assignee to authorized managers for explicit reassignment rather than silently deleting/reassigning work. Project archive blocks task mutations and ordinary member access. Restoring a project must not silently restore tasks individually archived earlier.

Define Completed/Cancelled project task rules before implementation. A safe initial rule is to require authorized project reopening before new task work, while preserving read access. Do not silently complete tasks when the project status changes.

## Service and concurrency design

Use TaskService/TaskPolicy and thin explicit controllers. Reuse DatabaseUnitOfWork. Lock the project before the task for every task mutation, then recheck project state, live membership, action permission, assignee eligibility and expected task version. Project membership/archive operations already lock the same parent, serializing permission revocation with task writes. Extend the lock allowlist explicitly for tasks.

Use a task version CAS; return 409 on stale requests. Commit task fields, assignment/status changes and task history together. Do not bump the project version for every task edit unless an explicit project-history event is also required; otherwise it causes unnecessary project-form conflicts. A failed child/history write must roll back the whole task operation.

Use required session CSRF for all POST writes, server validation and escaped plain text. Do not blindly retry stale transitions. If a board is approved, reuse the existing successful-update/reload pattern, keyboard controls, bounded columns and accessible conflict feedback.

## UI and integration

- Activate Tasks navigation only with its permission; preserve the former placeholder with an authorized redirect.
- Add a real Tasks section to project profiles with scoped counts/list and clear empty states. Provide title/status/priority/assignee/date filters and 20-row pagination.
- Build responsive create/edit forms and a task profile with assignment, dates, status, history and archive/restore confirmation. Picker results must contain eligible project participants only.
- Keep current dark tokens, shared alerts, explicit labels, focus handling, keyboard navigation and internal scrolling on small screens. Display a clear recovery link after 409.
- Keep Clients authorization independent. Task/project visibility must not disclose client contacts, email, addresses or unauthorized profile links.
- Dashboard task widgets remain labeled demos unless replacement with a real permission-scoped query service is expressly included. Do not mix demo and real operational totals.

## Test and delivery gates

1. Verify and commit the accepted Phase 5A checkpoint before branching; branch from that exact commit, not older main.
2. Test migration/seeder idempotency, all FKs/unique keys and preservation of preexisting records. Run destructive rollback/failure injection only in disposable databases.
3. Test CRUD, assignment, valid/invalid dates, status/completion behavior, duplicates, filters, pagination, archive/restore and one history event per committed version.
4. Test every role against direct/mismatched task/project IDs; membership removal, Shield revocation, archived parents, forged roles/owners, unauthorized client links and cross-project moves if approved.
5. Test overlapping status/assignment edits, task writes racing project archive/member removal, stale 409 responses, lock handling and rollback after each persistence stage using isolated processes/connections.
6. Test desktop/mobile, keyboard-only forms/board, validation feedback, token rotation, console/network errors and protected POST logout.
7. Run all existing Foundation, Dashboard, Clients, Leads, conversion and Projects unit/HTTP/browser regressions sequentially. Update migration/navigation expectations only for implemented behavior.
8. Remove generated fixtures; compare protected-data/credential digests; review full diff and secret exclusions; document changed files, migrations, URLs, limits and results. No automatic commit, push or merge.
