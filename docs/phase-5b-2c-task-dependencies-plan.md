# Phase 5B-2C Task Dependencies proposal — planning only

Prepared 2026-11-11. No dependency schema, endpoint or workflow is implemented or authorized by this document.

## Actual starting architecture

Tasks now share one project-first transactional mutation protocol, a task-wide optimistic version and immutable history. Tasks may have one immutable same-project root parent; subtasks have independent assignment/completion. Checklist and comment writes also advance the task version. Kanban and native/list status forms reuse TaskService::status(); edits may change status through TaskService::save(). Any dependency completion rule must cover both methods, not a new board-only API. Existing project/task archives and permission revocation rules remain authoritative.

## Scope decisions requiring approval

Agree finish-to-start only or additional types, whether Cancelled/archived predecessors count as satisfied, whether parent/child dependency edges are permitted, and who may link/unlink. Decide whether dependencies only block completion or also selected active stages. Recommend blocking only Done-category transitions initially; allow ordinary discussion/checklist work while blocked. Keep hierarchy and dependencies distinct: creating a subtask must not invent an edge or auto-complete its parent.

Begin with same-project edges. Cross-project dependencies, auto-scheduling, recurrence, Gantt, billing and time tracking remain separate scope decisions. Do not broaden visibility to reveal inaccessible predecessors.

## Proposed additive schema

Add task_dependencies: unsigned id, predecessor_id and successor_id FKs to tasks, created_by FK, row version, timestamps and optional archived_at/archived_by according to the approved removal policy. Use InnoDB/utf8mb4, unique predecessor/successor pair, indexes in each direction and restrictive actor/parent deletion. Existing Task records require no rewrite. Enforce matching project IDs, distinct endpoints and acyclicity in the service while holding the project lock. If DB-level composite project FKs are chosen, rehearse supporting indexes on a disposable MariaDB database first.

Audit additions/removals in task_history without private predecessor names or comment text. Choose before implementation whether linking advances both endpoint Task versions or only the successor; recommend explicit expected versions for both when confirming an edge, with one outer transaction and deterministic task lock order. Preserve existing history uniqueness and never let nested services commit the caller's transaction.

## Service and permission design

Introduce narrowly scoped tasks.dependencies for actors already authorized to administer the selected tasks. A task ID, parent link or assignee never grants access to the other endpoint or the underlying client. Read graph/count/lookup data only through current TaskPolicy and ProjectPolicy scopes. Consider manager-only edge administration initially; allow current authorized task viewers to read only independently visible edges.

Serialize graph edits and completion checks on the existing project lock. Detect cycles with bounded traversal in the same transaction; reject self-links, duplicates, cross-project edges, hidden endpoints and archived/readonly states. Acquire task locks in ascending ID order when touching multiple tasks. Compare submitted Task/edge versions and return 409 on stale writes. Existing TaskService save/status entry points must check approved completion constraints under that same lock before updating status/completed_at/history. Do not trigger network delivery inside the transaction.

Changes to predecessor completion must affect the successor's computed readiness immediately without silently rewriting unrelated task state. Never auto-reopen or auto-complete successors unless the user approves a separate automation rule. Clarify what reopening a previously satisfied predecessor means for an already Done successor before coding.

## UI/UX

Add bounded predecessor/successor lists to task details, an authorized searchable picker, explicit edge preview/confirmation and unlink/archive confirmation. Explain blocked/ready states using visible records only; do not expose hidden names/counts through error messages or badges. Provide native accessible forms before any optional graph. Kanban cards may show a scoped readiness indicator, and attempted completion should return a clear validation message while preserving card position. Keep mobile widths, keyboard focus, CSRF and unsaved draft/reload guidance consistent with collaboration.

## Migration, testing and regression gates

Use an additive migration and optional idempotent reference configuration; never reset production/permanent data. Rehearse up/down, schema constraints, failure injection and forward recovery on disposable databases. Snapshot protected records and credentials before local verification; clean only guarded fictional fixtures afterward.

Test self/duplicate/cross-project edges, hidden endpoint lookups, direct API requests, membership/global permission revocation, archived tasks/parents/projects, cycles and bounded traversal. Run actual simultaneous MariaDB edge additions that would form a cycle, completion/reopen races, stale Task/edge edits and injected history failures. Prove all-or-nothing Task/history/edge persistence and no silent overwrite. Cover native and Kanban completion routes, task edits with status fields and any future bulk APIs.

Keep complete Tasks CRUD/Kanban, subtasks, checklist ordering/progress, author/moderator comment rules, XSS/CSRF, Projects/Clients, Leads/conversion, Dashboard and authentication suites green. Browser gates cover pointer/keyboard, native forms, error recovery, independent pagination and 320/390/768/1024/1440 px. Require a dedicated verification report and explicit approval before implementing dependencies, committing that milestone, pushing or merging.
