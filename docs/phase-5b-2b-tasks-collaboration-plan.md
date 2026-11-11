# Phase 5B-2B: Tasks collaboration proposal (planning only)

Prepared 2026-11-11. This document does not authorize implementation. Phase 5B-2A implements Kanban only; billing, time tracking, recurrence and Gantt remain excluded.

## Existing contracts to preserve

Reuse TaskService/TaskPolicy and DatabaseUnitOfWork, the shared Tasks list/filters/board, task statuses/priorities and existing versioned task_history. Every task has one required immutable project and at most one assignee. Project ownership/membership and Shield action permissions are checked together; task access never grants private Clients access. Archived tasks and archived/Completed/Cancelled projects remain read-only. Kanban and native status forms share TaskService::status() and completion rules. No manual card ordering is currently stored.

Confirm before implementation: nesting depth, who can create child tasks, whether children/checklist items block completion, comment edit/delete/retention rules, dependency types and archive behavior. Do not infer workflow restrictions or broaden ordinary-member visibility without approval.

## 1. Subtasks

Propose an additive nullable tasks.parent_id FK referencing tasks.id, indexed with project_id. Existing tasks remain roots. Keep parent/child in the same project, reject self-parenting/cycles and enforce an agreed depth cap. A parent link cannot grant access to a child or reveal hidden sibling counts. Decide whether assignment is independent or explicitly copied at creation; never auto-enroll staff in projects. Preserve existing task codes, URLs and audit history. Parent completion blocking, if approved, must run in the shared service for list, board and native endpoints.

UI: authorized child list on task details, clear parent breadcrumb, dedicated Create subtask action and a bounded hierarchical list. Add keyboard-accessible hierarchy controls. Do not add draggable parent changes without a separately defined permission and concurrency contract.

## 2. Checklists

Propose task_checklist_items: unsigned id/task_id/created_by, text label, position, checked_at/checked_by nullable, row version and timestamps. Add task FK and actor FKs, unique task/position only if ordering design warrants it; avoid floating browser-only positions. Choose whether each item mutation advances the task version/history or uses independent row versions with atomic audit events before implementation.

UI: labeled native checkboxes, add/edit actions, explicit removal confirmation and clear completion progress computed from visible authorized records. Checkbox operations need CSRF, live membership and action permissions. Never change task completion automatically unless that behavior is explicitly approved.

## 3. Comments

Propose task_comments: id, task_id, author_id, plain-text body, version, edited_at, optional deleted_at, timestamps; index task_id/created_at/id. Define tasks.comment and tasks.comments.moderate independently from task assignment. Current viewers may comment only if separately permitted; editing checks author/moderator and current record state. Decide retention/moderation and edit-window rules explicitly. Keep immutable activity separate from editable comments.

UI: paginated comment stream, escaped text, visible edited state, author/timestamps, validation and focus/announcement support. Rich HTML, mentions, uploads and external delivery are not implicit scope. Future attachments require private storage and authorized downloads.

## 4. Dependencies

Propose task_dependencies: predecessor_id, successor_id, created_by, timestamps, unique predecessor/successor, indexes in both directions and restrictive FKs. Initially allow same-project finish-to-start relationships only if approved. Serialize edge changes with the project lock, lock task IDs in deterministic ascending order, and validate acyclicity within the transaction. Reject self-links and inaccessible tasks. Do not leak hidden blocker names/counts. Specify whether archived or Cancelled tasks satisfy blockers before implementing completion guards.

UI: bounded searchable authorized predecessor picker, clear blocked/ready explanations and explicit unlink confirmation. Provide accessible lists first; graph visualization is optional later work. No dependency request may move a task between projects.

## Permissions, transactions and compatibility

Add narrowly scoped capabilities only for approved actions. Recheck current Projects view, Tasks view, record scope, relevant action permission and writable state on all native/AJAX endpoints, lookups and count queries. Preserve owner/manager/member differences and require both Projects/Tasks administration for global administration. Assignment never grants project or Clients administration.

Retain project-first locking and optimistic versions. Multi-task operations use deterministic task locks; write related data and history atomically in a single outer transaction. No nested service may commit its caller's work. Return 409 for stale writes/deadlocks with explicit review rather than automatic resubmission. Notification delivery must be outside transactions; an outbox is a separate approved schema change if needed.

## Additive migration and testing strategy

Use separate additive migrations for each approved feature, matching current unsigned IDs, InnoDB and utf8mb4. Seed permissions/reference data idempotently and preserve customizations. Rehearse up/down and failure injection only in disposable databases. Do not delete/reset permanent data or run destructive rollback on the local business database. Preserve current forms/routes and backward-compatible default behavior for all existing tasks.

Add unit/service tests for policy combinations, cross-project attempts, cycles/depth, assignment eligibility, comment moderation/XSS, checkbox versions, dependency completion guards, archives and live revocation. Use real overlapping MariaDB connections for edge cycles, stale checklist/comment changes, parent completion races and rollback. Assert no partial writes and audit consistency. Extend browser tests for keyboard/native forms, loading/error states, mobile widths 320/390/768/1024/1440 and accessible focus. Keep Tasks CRUD/Kanban, Projects, Clients, Leads/conversion, Dashboard, authentication and CSRF regressions green.

Release in individually approved increments: subtasks, checklists, comments, then dependencies. Each needs schema review, disposable migration rehearsal, complete verification report, fixture cleanup and protected-record hash checks. Committing, pushing, merging and expanding scope require separate authorization.
