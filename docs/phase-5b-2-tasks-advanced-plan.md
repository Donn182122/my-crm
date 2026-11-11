# Phase 5B-2: Advanced Tasks proposal (planning only)

Date: 2026-11-11. Requires scope approval; nothing below is implemented or authorized by the foundation delivery.

## Existing foundation and compatibility contract

Reuse TaskService, TaskPolicy, Tasks controller, Tasks lookups/history, shared dashboard components and DatabaseUnitOfWork. Every task has one immutable required project, one optional assignee and a version. Keep existing URLs, forms, task codes and foreign keys compatible. Retain independent Projects and Clients authorization. Parent project archive/terminal-state guards apply to every advanced mutation. Never infer client, billing or time permissions from a task.

Approve workflow rules before schema work: allowed stage transitions, who may create child tasks or comments, whether children block parent completion, dependency types, retention/edit policy, checklist completion behavior, notification audiences, and whether multi-assignee tasks are actually needed. Single assignment remains the default; do not create a second assignment source of truth.

## Increment 1: Accessible Kanban

Use configured active task statuses ordered by position, with labels/categories from the existing lookup table. Reuse scoped TaskService filters and the existing transactional status method. Start with the existing category semantics; do not silently introduce restrictive transitions. If a transition matrix is approved, add task_status_transitions with unique from/to IDs and explicit permission rules, preserving existing transitions during rollout.

Bound each column to 20 cards with independent pagination or explicit Load more. Cards show only authorized task/project fields, assignee, priority and dates. Offer drag/drop plus labeled select/Move controls and keyboard operation. Mobile uses horizontally scrollable labeled columns or a list alternative. All requests carry CSRF and task version. On 409 refresh that card from the authorized server and require review; never retry automatically or optimistically claim success. Keep listing/details available without JavaScript.

## Increment 2: Subtasks and checklists

Proposed additive task parent_id nullable FK tasks.id with an index, plus a separate task_checklist_items table (task_id, label, position, checked_at, checked_by, version, creator, timestamps). Use same-project parent validation, explicit cycle prevention and bounded nesting; agree a small depth limit first. Existing tasks remain roots. Do not allow a child link to grant access to its parent or siblings. Return only independently authorized children and avoid leaking hidden-child counts.

Task checklist mutations lock project then task before checklist row, check live access/version and write atomic task activity. Decide whether checklist edits advance the task version or use a distinct version contract before exposing endpoints. Preserve completion/archive state on rollout; do not backfill invented checklist completion. Parent completion blocking must be an explicit approved business rule, not inferred from checklist presence.

## Increment 3: Dependencies

Add task_dependencies with predecessor_id, successor_id, created_by and timestamps, unique predecessor/successor, foreign keys and indexes for both directions. Initially support only same-project finish-to-start dependencies if approved; reject self-links and cycles transactionally. Serialize edge modifications on the project lock; acquire task locks in ascending ID order. Respect visibility when showing blockers; never expose hidden task names. Define archive and completion semantics and prohibit destructive cascades that erase audit evidence. Dependency completion guards must execute inside all TaskService status/save paths, not just the board endpoint.

## Increment 4: Comments and activity

Add task_comments (task_id, author_id, plain-text body, version, edited_at, optional deleted_at, timestamps), with indexed task/time. Decide deletion retention explicitly. Add narrowly scoped tasks.comment and tasks.comments.moderate capabilities. Ordinary users may comment only on tasks they can currently view; editing additionally checks author/moderator and live project/task state. Escape output, validate length, use CSRF and concurrency checks, and append atomic activity events. Do not accept arbitrary HTML. Attachments and uploads remain a separate approval requiring private storage, file-type/size validation and authorized downloads.

History remains the audit source, not editable comments. Paginate comments/history with stable ordering and bounded queries; keep the current latest-50 history behavior compatible until a dedicated history endpoint is approved.

## Security and transaction design

Centralize every new action in policy/service methods; route filters supplement record policies. Require Projects viewing and Tasks action permissions on every request, including AJAX, lookup, graph and count APIs. Recheck membership after project lock. Project managers do not gain owner/client administration. Global Tasks administration still requires Projects administration. New advanced permissions should be least privilege; do not grant moderation merely through assignment.

Maintain project-first lock order, deterministic task order for multi-task operations and compare-and-swap versions. Use one outer transaction for task, child/checklist/dependency changes and history. No nested service may commit its caller's transaction. Translate stale writes/deadlocks to reviewable conflicts. Avoid network/notification delivery inside transactions; if notifications are approved, use an additive outbox with idempotency and delivery authorization.

## Migration and release strategy

Use independent additive migrations per approved increment, InnoDB/utf8mb4 and consistent unsigned FK types. Nullable parent fields preserve current records. Seed reference rules idempotently without replacing administrator customizations. Rehearse up/down only on disposable databases; production rollback uses a reviewed forward fix unless a data-preserving rollback is proven. Inspect query plans and indexes using synthetic data. No automatic deletion or rewrite of permanent tasks, clients, projects or accounts.

## Test and acceptance gates

Keep the full foundation unit, HTTP/MariaDB concurrency and desktop/mobile browser suites green. Extend tests for same-project constraints, hidden counts, membership/permission revocation, archived/closed projects, cycle prevention, dependency races, simultaneous comment/checklist/status edits, CSRF, XSS, validation, stale versions and rollback injection in disposable databases. Exercise real overlapping MariaDB connections for graph changes and status races. Assert one history event per committed version, no partial side effects and no cross-project or client information leakage.

Browser gates cover pointer drag/drop, keyboard controls, focus management, accessible feedback, pagination, touch widths 320/390/768/1024/1440 and graceful operation without JavaScript. Preserve Clients, Leads, conversion, Projects, authentication and Dashboard regressions. Snapshot protected permanent records before verification; remove only guarded fictional fixtures afterward. Require a complete verification report and explicit approval before committing, advancing scope, pushing or merging.

## Proposed order and scope boundary

Deliver Kanban first, then approved subtasks/checklists, then dependencies, then comments. Each increment has a review gate and separate migration/test evidence. Recurring tasks, Gantt, time tracking, billing and invoicing are outside this proposal unless separately requested. No advanced implementation starts automatically after the foundation.