# Phase 3 dashboard proposal — no dashboard implementation authorized yet

## Existing shared interface

- `app/Views/auth/layout.php` supplies the HTML document, viewport, title, local Bootstrap CSS/JS and page style/script sections. Keep the authentication layout separate from the signed-in application shell.
- `app/Views/foundation.php` supplies the current authenticated card, administrator link and CSRF-protected sign-out form. It is a foundation verification view, not a dashboard.
- `app/Controllers/BaseController.php` supplies framework initialization; keep business logic out of it.
- `app/Config/Routes.php`, `Auth.php`, `AuthGroups.php`, `Filters.php`, `Security.php` and `Session.php` provide explicit routes, authentication, authorization, CSRF and session policy.
- Bootstrap 5.3.8 is installed locally with its MIT notice. No existing sidebar, top navigation, account menu, widget system, data-table abstraction or chart library exists.

## Proposed architecture

Use a CodeIgniter modular monolith. A thin Dashboard controller asks a DashboardService for a typed, permission-scoped view model. Query services provide aggregate results; controllers and templates do not issue ad-hoc queries. Navigation configuration is shared by desktop and mobile renderers, while server policies independently protect every destination and data endpoint.

Proposed files (not created):

```text
app/Controllers/Dashboard.php
app/Services/DashboardService.php
app/Services/DashboardQueryService.php
app/Config/Navigation.php
app/Views/layouts/app.php
app/Views/partials/sidebar.php
app/Views/partials/top_navigation.php
app/Views/partials/account_menu.php
app/Views/components/stat_card.php
app/Views/components/empty_state.php
app/Views/components/data_table.php
app/Views/dashboard/index.php
public/assets/css/app.css
public/assets/js/app.js
public/assets/js/dashboard.js
```

Keep the application shell and presentation tokens independent of the reference system. Use original spacing, typography, colors and assets, while preserving agreed navigation concepts and permitted workflows. Theme customizations belong in app.css rather than editing Bootstrap. Do not query reference tables or copy RISE bundles, templates, branding or document assets.

Only implemented routes are clickable. Future CRM modules may be documented as planned; they must not appear to work. Do not invent financial or operational figures. Until corresponding modules exist, widgets show explicit empty/unavailable states. Decide the chart dependency and license before implementation; load it locally and expose a small chart adapter so the library does not dictate business services.

## Development checklist

### Shared shell and responsive navigation

- [ ] Define original design tokens, breakpoints, content width, focus styles and spacing.
- [ ] Build one navigation registry with labels, route names, permission keys, icons and active state.
- [ ] Add a desktop sidebar and a mobile offcanvas menu from the same registry.
- [ ] Make collapse state optional and store only nonsensitive preferences locally.
- [ ] Support keyboard navigation, focus restoration, Escape dismissal and visible active states.
- [ ] Verify 320px, 768px, 1024px and wide desktop layouts without horizontal page overflow.

### Top navigation and account menu

- [ ] Add page title, breadcrumbs, mobile menu control and permitted actions.
- [ ] Display the authenticated user's escaped identity; render only implemented account actions.
- [ ] Keep logout POST-only with a session CSRF token.
- [ ] Verify screen-reader labels, keyboard focus and touch target sizes.
- [ ] Re-check server authorization for all links and menu actions.

### Dashboard widgets

- [ ] Agree each widget's business definition, permission scope, date range, timezone and source module.
- [ ] Separate widget presentation from aggregation queries and financial calculations.
- [ ] Provide loading, empty, unavailable, error and populated states.
- [ ] Ensure filters apply consistently and never expose unauthorized aggregates.
- [ ] Treat client, project, task and sales widgets as dependent on their future modules.

### Tables

- [ ] Define reusable column, formatting, empty-state and action contracts.
- [ ] Add server-validated pagination, sorting and search with allowlisted columns.
- [ ] Escape values and authorize row actions, exports and underlying requests.
- [ ] Provide semantic headings and a usable narrow-screen presentation.
- [ ] Test large result sets, invalid filters, unavailable data and empty results.

### Charts

- [ ] Review and pin an upstream chart package and license before installation.
- [ ] Use permission-filtered chart DTOs with explicit units, labels and date intervals.
- [ ] Render accessible text/table equivalents and avoid color-only meaning.
- [ ] Resize without layout overflow and respect reduced-motion preferences.
- [ ] Avoid inline unescaped JavaScript; encode data safely and dispose chart instances on refresh.

### Acceptance and scope gates

- [ ] Confirm permanent administrator setup and authentication verification first.
- [ ] Commit the reviewed foundation only after all verification gates pass.
- [ ] Obtain approval for dashboard implementation; this document authorizes no dashboard code.
- [ ] Test desktop/mobile, keyboard access, guest rejection, ordinary-user denial and CSRF handling.
- [ ] Confirm no secrets, runtime artifacts, database exports or proprietary assets enter Git.
- [ ] Keep the original CRM and its database untouched. No GitHub push is included.
