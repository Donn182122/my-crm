# Proposed next milestone: operational dashboard integration (planning only)

Prepared 2026-11-11 after Task Dependencies. This proposal does not authorize implementation, a new branch/commit, push or merge. Confirm the desired next milestone before development; the phase name is provisional.

## Why this is a useful next step

The independent CRM now has Clients, assigned Leads/conversion, Projects, Tasks/Kanban, subtasks, checklists, comments and dependencies. The Phase 3 dashboard deliberately still contains demo/empty operational widgets. A read-only integration milestone can make this existing interface useful with authorized real data, without prematurely starting billing, time tracking, Gantt or another business module. Project milestones or Tickets remain valid alternatives if those workflows are the user's priority; their requirements should be agreed separately.

## Proposed scope and user decisions

Replace selected demo/empty widgets with real scoped Clients/Leads/Projects/Tasks summaries, upcoming authorized due tasks, my open tasks, blocked/ready root tasks and recent permitted activity. Keep financial, attendance, calendar and ticket widgets visibly unavailable wherever no real module exists. Do not infer invoice amounts, attendance hours or revenue from unrelated data. Decide which cards are useful, default due-date range/timezone, whether archived/completed projects contribute to each metric and whether managers see project-wide or only personal summaries. Recommend clear personal/project scopes and explicit date windows rather than broad organization totals.

## Database and service design

Start with no new business tables or data rewrite. Introduce query/read-model services over existing migrations, with a reviewed reusable authorization scope shared with current services. Do not compute totals from the first paginated page. Aggregate via bounded SQL under the same ProjectPolicy/TaskPolicy/LeadPolicy/ClientPolicy scopes; dependency readiness must reuse the actual predecessor rule and never reveal hidden task names/counts. Add indexes only after EXPLAIN and synthetic-data evidence justify them, through a separate additive migration. Reuse existing task status categories; do not maintain duplicated operational counters or stored blocked flags.

User-specific cache, if necessary, must be keyed by actor and permission/membership context with immediate revocation behavior. Prefer uncached correct queries initially. Never cache one administrator's totals for ordinary users. No new database grants or credential change is needed. Saved filters/preferences are optional schema work requiring a separate decision; defer them for the initial read-only scope.

## UI/UX

Reuse the current original dark layout, responsive cards, tables, status badges and account/navigation behavior. Label each card with scope and date window; show empty/error states and an authorized filtered destination link. Use semantic headings, accessible chart/table alternatives, keyboard controls and native links. Keep no-data distinct from zero. Do not show hidden-user/client information in labels, tooltips or activity feeds. Hide unavailable actions rather than using functional-looking placeholders. Test 320/390/768/1024/1440 px and slow/error responses without exposing debug traces.

## Security and compatibility

Require authentication and existing action permissions on page and any JSON endpoints. Read-only requests must not mutate task statuses, graph relationships, sessions beyond normal login behavior or existing records. Revalidate current memberships and record scope, not merely a dashboard permission. Avoid organization-wide user/client lookups. Existing CSRF, logout, assignment, archive, completed-project, dependency completion and collaboration rules remain unchanged. Any future exports or notifications require separate authorization and privacy design.

## Implementation sequence and acceptance

1. Agree card definitions and scopes with the user; document exact SQL-level inclusion rules and empty-state behavior.
2. Add read-model tests with fixtures that distinguish owner, manager, assigned member, outsider, revoked member, archived records and completed/cancelled statuses.
3. Implement small scoped aggregate services, with no unrelated controller/service changes and no data rewrites.
4. Connect selected dashboard cards and drill-down URLs; preserve existing demo/coming-soon labels for unsupported widgets.
5. Verify that counts match authorized list queries across pagination, date boundaries and dependency changes. Test immediate membership revocation and no cross-user cache leakage.
6. Run full PHPUnit, all HTTP/MariaDB and browser suites including graph reverse-edge/completion/reopening races, collaboration, Kanban, Clients/Leads/conversion, Projects and authentication.
7. Review query plans on fictional high-volume fixtures; verify response bounds, error handling, browser console, keyboard/mobile behavior and cleanup/protected-record hashes.
8. Produce a dedicated verification report and changed-file inventory. Do not commit, push, merge or expand scope without explicit approval.

Gantt, recurrence, billing, time tracking, file uploads, task automation and a new business module remain outside this proposal.
