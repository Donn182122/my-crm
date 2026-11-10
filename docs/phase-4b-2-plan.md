# Phase 4B-2 technical plan (not implemented)

Requires a separate implementation authorization. Phase 4B-1 provides versioned Leads CRUD, ownership, contacts/addresses and transactional activity history. This document creates no routes, tables or behavior.

## Kanban pipeline

- Use lead_statuses.position for column order and existing list filters/ownership scope for every column and count. Return bounded pages per column, not the entire database; start with deterministic updated_at/id ordering.
- Provide keyboard-accessible move controls alongside optional drag-and-drop, visible focus, mobile-friendly columns and a list fallback. Announce successful moves and conflicts accessibly. Do not add manual card ranking until a separately reviewed ordering requirement warrants it.
- Add a dedicated status-transition method in LeadService using the same policy, CAS version update and history transaction. A CSRF-protected POST endpoint accepts only target status and expected version; it cannot accept owner/creator flags or enable Converted.
- Optimistically update the display only with rollback/refetch on rejection. HTTP 409 must restore server state and require review, not silently retry an outdated move. Archived and terminal restrictions remain enforced; Lost reopening stays an explicit privileged operation.

## Conversion schema

- Add lead_conversions with unique lead_id, client_id, actor_id, mode (created/linked), converted_at, idempotency key and timestamps. Use InnoDB/utf8mb4, foreign keys and an index on client_id. Multiple leads may reference the same client; each lead converts at most once.
- Retain the original lead and immutable history as provenance; mark its pipeline state Converted only inside the conversion service. No deletion or implicit merging of the original lead.
- Use the conversion relationship to expose the target only when the viewer independently has Clients access. Lead visibility must never grant access to a client owned by another account.

## Conversion authorization and transaction

- Require leads.convert plus access to the source lead. Creating a new client also requires clients.create; linking requires authorized access to the selected client, and adding/changing related records requires clients.edit. Do not infer either module's permissions from the other.
- Start with an active open lead and an explicit mapping/preview step. Require a current lead version and a unique conversion request key. Existing archived clients need separate authorized restoration; do not silently restore them.
- Coordinate LeadService and ClientService under one explicit transaction boundary. Refactor reusable validation/persistence carefully so an inner service cannot commit or roll back work owned by the conversion operation.
- Lock/recheck the lead, version, current assignee and state; lock a selected existing client when merging related records. Revalidate permissions inside the transaction. Perform client creation/linking, related-record inserts, unique conversion row, Converted status/version and history atomically.
- Repeat submissions return the same authorized conversion result. Unique lead_id/request constraints and locking protect against concurrent conversions. Uniqueness races must produce a safe conflict or an authorized explicit linking choice, not disclose another owner's client.

## Reuse and deduplication

- Reuse ClientService normalization, field validation, ownership policies and contact/address persistence. Extract genuinely shared field components/rules where useful without coupling Clients data ownership to Leads.
- Preview company/individual mapping and distinguish company contact information from a person's details. Match normalized email only as a duplicate candidate; never merge companies/people on name alone.
- Search only clients the actor is allowed to access. Choose Create or Link explicitly. Linking keeps existing client ownership and information unless a separately authorized, clearly confirmed change is requested.
- Within the chosen client, reuse contacts by normalized nonempty email; resolve conflicting details explicitly. Contacts without email need confirmation rather than automatic name-based merging. Compare normalized address components before inserting; serialize additions through the client lock to prevent concurrent duplicate inserts.
- Preserve lead snapshots for history, but use the conversion relationship and client records for ongoing client management. Do not maintain two independently editable active copies after conversion.

## Delivery and tests

1. Approve API contracts, permission defaults, mapping UI and duplicate/conflict behavior.
2. Add reviewed additive conversion migrations and isolated migration tests; do not change existing Clients rows during migration.
3. Implement versioned Kanban transitions and accessible UI with tests.
4. Implement conversion unit-of-work/idempotency and preview/confirmation UI.
5. Test foreign owner/parent IDs, read-only users, reassignment races, CSRF, missing/stale versions, reserved-status bypass attempts and archived/terminal records.
6. Test duplicate contact/address behavior, existing-client linking, email collisions, repeated requests, concurrent same-lead conversion, two leads targeting one client and rollback after failures at every persistence stage. Use real MariaDB for transaction/locking tests and isolated SQLite for service validation tests.
7. Run all Dashboard, Clients and Leads regressions, keyboard/mobile/browser checks and protected-data invariants. Remove fictional fixtures and review the complete diff before requesting commit approval. No automatic push.
