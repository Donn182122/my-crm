# Phase 4B-1 Leads foundation: implementation and verification

Verified on 2026-11-10 in C:/xampp/htdocs/my-crm, database my_crm_db. Feature branch: feature/phase-4b-1-leads. Stable main remains at 96d59cc11cff26df7bb849cd8f5cd3b05cbba5d2. No commit or push was performed.

## Architecture

Leads uses an explicit authenticated controller, LeadService and LeadPolicy. It reuses the existing signed-in layout, navigation, original dark theme, Clients form/table styling and shared alert view. Clients persistence and business records were not changed. There are no new third-party dependencies, proprietary assets, uploads, Kanban endpoints or conversion actions.

## Applied additive migration

app/Database/Migrations/2026-11-10-100000_CreateLeadManagement.php creates:

- leads: unique stable LD code; company/individual identity; display name; optional normalized unique email; phone, website, notes; required source/status/owner/creator foreign keys; version; archive state; created/updated timestamps.
- lead_sources: unique immutable slug, name, active flag and timestamps.
- lead_statuses: unique slug, name, ordering, outcome (open/lost/converted), reserved flag and timestamps.
- lead_contacts: parent FK, person/job/contact details, timestamps and unique nonempty email per lead.
- lead_addresses: parent FK, billing/shipping/other address fields and timestamps.
- lead_history: parent and actor FKs, event, summary, metadata, timestamps and unique (lead_id, version).

All six tables use InnoDB/utf8mb4 in MariaDB. Eight foreign keys protect ownership, creator, references, children and history. Indexes cover assigned-owner/archive/status, source/archive, names and history order. No existing table was dropped or overwritten. No migration rollback was run against the application database. lead_conversions is deliberately absent until Phase 4B-2.

LeadReferenceSeeder inserts missing slugs only, using safe duplicate-ignore insertion for concurrent seed runs; it preserves existing names, ordering and activation settings. It was run twice successfully. Sources: Manual, Website, Referral, Email, Phone, Event. Statuses: New, Contacted, Qualified, Negotiation, Lost, Converted (reserved). Converted cannot be selected or submitted through CRUD. New leads must start in an open status.

Lead names need not be unique. Nonempty lead email is unique across all leads, including archived records; empty email is NULL. Leads may share email with an existing Client because cross-module linking/conversion is deferred. Multiple campaigns/opportunities for the same lead email are not modeled in this foundation.

## Functionality and security

- Company/individual creation, viewing and editing; search by name/code/email/phone; source/status filters, administrator owner filter, archive filter and pagination (20 per page).
- Profile shows identity/contact information, source, pipeline status, assigned user, creator, contacts, addresses, timestamps and the most recent 50 activity events.
- Contacts and addresses can be added, edited and removed with explicit removal confirmation.
- Archive/restore retains children/history. Archived information and related entries cannot be edited.
- Open leads may move to Lost through an authorized edit. Terminal leads are read-only, including for administrators, until an explicit authorized reopen operation. Reopening supports Lost only; it cannot reopen a future Converted record. Authorized archive/restore changes archive state without bypassing terminal edit restrictions.
- Administrators receive leads.view/create/edit/archive/manageall/assign/reopen. Ordinary users receive view/create/edit/archive for assigned records only. Read-only assignees cannot mutate. Only leads.assign holders may change ownership, and targets must be active users with leads.view. Immutable creator/code/archive fields and permission flags are never mass assigned.
- Every profile, related-record and mutation request independently checks Shield action permission plus record ownership. Parent/child mismatches return 404. Former assignees lose access after reassignment. Global CSRF protection applies to every write; GET cannot mutate.
- Every update requires the displayed parent lead version. A snapshot mismatch or atomic conditional-update failure returns HTTP 409. The conditional write includes id/version and ownership where applicable; missing/invalid version is rejected. This applies to edits, assignment, contacts, addresses, removal, archive, restore and reopening.
- Parent version, child writes and one history event commit together. History failures roll back the entire operation. Conflict forms preserve the old token and offer an explicit reload link rather than automatically resubmitting stale data. AJAX validation/conflict errors return JSON with the appropriate status.
- Values are validated with allowlisted rules, database queries use bindings/query builders, search wildcard characters are literal, and template output is escaped. Source/status/user IDs are validated server-side.

## Verification results

- Full PHPUnit suite: 43 tests, 255 assertions passed (all 30 existing tests plus 13 Leads tests). Tests use isolated SQLite memory databases.
- Leads real MariaDB/HTTP: php spark leads:check passed 87 checks covering creation/editing, children, duplicates, source/status validation, ownership, read-only users, reassignment, direct URL/AJAX attacks, CSRF, filters/pagination, optimistic conflicts, archive/restore, terminal protections, history version integrity and deferred endpoints.
- Existing php spark foundation:check passed, including dedicated database, strict SQL, encoding/FKs, all six migration records, authentication, sessions/logout, permissions and password/debug privacy checks.
- Existing php spark clients:check passed all 72 checks.
- Chrome: 21 Dashboard regression checks and 30 Clients regression checks passed.
- Leads Chrome: 34 checks passed, including actual mobile creation/editing, contact/address creation, source/assignment/status controls, stale-form feedback, terminal-state controls, explicit reopening, archive/restore, search and logout.
- Form/profile/list layouts passed 320, 390, 768, 1024 and 1440px checks without whole-page overflow. Desktop listing and mobile profile screenshots were visually inspected. Internal tables scroll on narrow screens.
- No unexpected JavaScript, console or network errors were observed. The intentional stale-form browser test produces an expected HTTP 409 and is distinguished from unexpected console errors.
- Actual MariaDB audit verified all eight foreign keys, required uniqueness indexes, InnoDB/utf8mb4 tables and absence of lead_conversions. The dedicated account remains limited to my_crm_db plus global USAGE.
- New/modified PHP syntax checks and Git whitespace checks passed.
- Hashes of all pre-existing Clients tables and permanent authentication identities, group/permission assignments and user identity fields matched the pre-migration baseline after verification. Generated users, leads, contacts, addresses and history were removed. Existing client data was preserved.

The first HTTP harness exhausted the existing login rate limit while switching roles. The verifier now respects HTTP 429 with bounded seven-second waits/retries; no rate-limit or authentication setting was weakened. An initial browser harness type-name typo was corrected; final successful runs are reported above. Expected negative CSRF/constraint/conflict tests and the resolved harness failures appear in ignored development logs. No unresolved application failure was found in the final verification flows.

Run integration commands sequentially to avoid overlapping disposable authentication snapshots. The HTTP verifier may take longer while honoring rate limits. Browser scripts, generated temporary credential files, reports, screenshots and password-free invariant snapshots remain in ignored .tools; logs remain ignored under writable. The permanent administrator's password was neither requested nor changed; generated administrators exercised the same Shield flow.

## Local URLs

- http://localhost:8081/leads
- http://localhost:8081/leads/new
- http://localhost:8081/leads/{id}
- http://localhost:8081/leads/{id}/edit
- http://localhost:8081/leads/{id}/contacts/new
- http://localhost:8081/leads/{id}/addresses/new

All require authentication and appropriate permissions. Restart with dev.ps1; public/ remains the web root.

## Changed project files

- app/Commands/FoundationCheck.php
- app/Commands/LeadsCheck.php
- app/Config/AuthGroups.php
- app/Config/Navigation.php
- app/Config/Routes.php
- app/Controllers/Dashboard.php
- app/Controllers/Leads.php
- app/Database/Migrations/2026-11-10-100000_CreateLeadManagement.php
- app/Database/Seeds/LeadReferenceSeeder.php
- app/Services/NavigationService.php
- app/Services/LeadInputException.php
- app/Services/LeadPolicy.php
- app/Services/LeadService.php
- app/Views/layouts/app.php
- app/Views/leads/index.php
- app/Views/leads/form.php
- app/Views/leads/show.php
- app/Views/leads/child_form.php
- tests/leads/LeadServiceTest.php
- README.md
- docs/phase-4b-1-leads.md
- docs/phase-4b-2-plan.md

## Deliberate limitations

No Kanban, conversion, conversion table, email/marketing integration, attachments, source/status administration UI, unassigned queue or multi-tenant organization boundary. Reference data is managed by reviewed seed/database changes; slugs and reserved semantics must be preserved. Activity display is limited to the latest 50 events, while all events remain stored. Assignment selection lists eligible active users and is intended for the current small local installation. Closed leads need an authorized explicit reopening; ordinary users cannot reopen them. Browser coverage is Chrome, not every browser or assistive technology.

All project edits remain uncommitted on the feature branch. No GitHub push was made. The original RISE CRM and its database were not modified or used by this implementation. Phase 4B-2 is planning only; see phase-4b-2-plan.md.
