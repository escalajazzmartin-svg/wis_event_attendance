# Project Status

Last updated: 2026-10-30 (second session). Evidence level: source code was read; **nothing was executed or browser-tested** (no PHP in the review environment; no automated tests exist).

## Verified completed (present in code)
See `docs/SYSTEM_FEATURES.md` for the full list. In short: all three role portals (admin, department head, student), event request/approval workflow, QR attendance with Time Rules, appeals with evidence upload, attendance correction + audit log, CSV exports, login rate limiting, event lifecycle auto-completion.

## Session: Admin can view appeals (not run with PHP)
- New read-only admin pages: `admin/appeals/index.php` (status tabs All/Pending/Approved/Rejected/Withdrawn with counts, search by student name/number/event, department filter, 10 per page), `admin/appeals/view.php` (student, event, department, recorded attendance, reason, reviewer, remarks, link to proof) and `admin/appeals/evidence.php` (same safe file-serving as the department-head one, but not department-scoped).
- Admins cannot approve/reject: decisions remain in `department-head/appeals/decision.php`. No schema change, no CSRF/POST endpoints added.
- Sidebar: "Appeals" link added in `includes/admin_layout_start.php`; new `file-warning` icon in `adminIcon()` (`includes/admin_context.php`). No CSS added (reuses `ad-*` classes).
- Not verified: `php -l` and browser testing (no PHP available); only a bracket-balance check was done.

## Session 3 (2026-10-30) — QR scanning + shared look (not yet browser-tested; `php -l` not run, no PHP available)
- **Barcode → QR**: `student/attendance/scan.php` now renders a QR (qrcode-generator 1.4.4) of the existing `barcode_token`; `department-head/attendance/scan.php` adds a camera scanner (jsQR 1.4.0) that posts the token through the unchanged POST/CSRF/Time-Rules logic, keeping the typed/USB-reader field as a fallback. Labels changed in student, dept-head and admin pages; attendance source `barcode` is displayed as "QR code". DB column, enum value, audit action and file name `regenerate-barcode.php` deliberately unchanged. Camera needs HTTPS or localhost.
- **Shared look**: new `assets/css/ui-refresh.css` linked last in all layouts. Student: warm `#f3f0ed` background, top bar removed (`includes/student_layout_start.php`), account name in sidebar (`includes/sidebar.php`), 249px sidebar with 72px rail at <=760px, 30px titles with divider, 14px bordered panels/cards, tinted uppercase table headers with zebra rows. Dept-head older layout and dashboard: top bar removed, same panel/heading/table styling. Admin `.ad-*` pages and dept-head shell pages: same heading/card/table values as the refreshed admin pages. `includes/header.php` is now unused.
- Unverified: visual result of all of the above, camera behaviour on real devices, and whether any page-specific CSS overrides the new rules.

## Changes made in the earlier session (not yet browser-tested)
- **Fonts unified**: Roboto (text) + Cinzel (brand headings) now linked in all 8 page heads (admin, department head x3, student, three login pages). `Inter` (never loaded) and Arial primary stacks replaced with Roboto in `style.css`, `admin.css`, `department-head.css`, `header.css`, `student/student.css`, `department-head-sidebar.css`.
- **Time Rules**: single-day events show time-only inputs; server adds the event's date. Relies on `dhParseLocalDateTime()` accepting `YYYY-MM-DDTHH:MM`.
- **Logout button unified**: admin and department head sidebars now use the student-style logout (full-width row, icon + "Logout", white hover). Previously both were a small underlined "Log out" link. Markup in `includes/admin_layout_start.php` and `includes/department_head_sidebar.php`; styles in `.admin-logout` of `admin.css` and `department-head-sidebar.css`.

- **Admin Attendance, Reports, Security refreshed** (new `assets/css/admin-refresh.css`, loaded only when a page sets `$adminRefreshCss = true`; sidebar untouched). Attendance: colored summary icons, student avatars. Reports: summary cards + stacked CSS bar chart on "Attendance by department". Security: now a real read-only page (was a 501 placeholder). Design mockup: Claude Design canvas "WEAMMS Admin Mockups". `php -l` passes on every PHP file; pages were **not** run against a database or browser.

## Latest session: Attendance summary redesign + documentation audit (not yet browser-tested)
- **Admin Attendance page**: the five summary cards (Records, Present, Late, Absent, Excused) were replaced by a "Status overview" (proportion bar + four clickable status cells), now merged into the bottom of the "Attendance records" panel (records on top), the records panel emphasised (maroon top border, record-count pill), its table scrolling inside a fixed-height area with a sticky header (about 5 rows visible, so the panel no longer grows as records are added), and a compact one-row filter bar with a smaller Filter button (`admin-filters-compact`, `admin-filter-button-sm`). Files changed: `admin/attendance/index.php` (markup block only; queries, filters, table, pagination untouched) and `assets/css/admin-refresh.css` (new `.admin-status-*` rules). Layout previewed in headless Chromium with the real CSS; the PHP itself was **not executed** (no PHP available).
- **Docs audited against source**: stub list (48 files), tables/enums, helper function names, sidebar menus all matched. Corrections: the department-head dashboard does NOT use the older layout (it is self-contained; 10 pages use the older layout, 5 use the shell); admin CSRF mechanism documented. New docs: `PROJECT_CONTEXT.md`, `ARCHITECTURE.md`, `DATABASE.md`.

## Session: Attendance page matched to preview image (not yet browser-tested with PHP)
- Cause of the mismatch: global `button { width: 100% }` made the Filter button full-width, so "Clear" wrapped to its own line; table cells wrapped (two-line event/timestamp/period cells); History link used default blue underline; timestamp showed year and source; badges had a dot.
- Fix: `assets/css/admin-refresh.css` (appended block at the end) and the row markup in `admin/attendance/index.php` (timestamp format `M j, g:i A`, source moved to a `title` tooltip, link text `View` with `aria-label`). Queries, filters, pagination, permissions untouched. Sidebar untouched.
- Verified by rendering the real CSS with mock data in headless Chromium at 1366x768 and comparing with the image. PHP itself was not executed (no PHP available).
- Note: the ZIP used to contain a stale nested copy `for_testing-main/for_testing-main/`; it was deleted on 2026-10-30 at the owner's request.

## Session: Department Heads list, Attendance titles, admin sidebar weights, Events date filter (not run with PHP)
- `admin/department-heads/index.php`: Email column removed (email now shown with the employee number under the name, like the Students page's two-line person cell); permission pills now sit in a row (`.ad-pill-stack` is `flex-flow: row wrap`).
- Attendance Monitoring: yellow bar before panel titles hidden via `.admin-attendance-page` (Reports/Security keep it).
- Admin sidebar (`admin.css`): nav links, brand subtitle and username are weight 400; only the active nav item is 500.
- Events: icon-only date button next to the academic-period dropdown (`.ad-date-btn`); opens the native picker (`showPicker()`), submits `event_date=YYYY-MM-DD`, filters `DATE(e.start_at)` in `adminEventFilter()` (`includes/admin_lists.php`), carried through tabs/export via `$sharedQuery`; "Clear date" link appears when set.

## Session: Admin event view redesign (not run with PHP)
- `admin/events/view.php` markup rebuilt to match the provided mockup (top bar with search/bell/user menu, back link, Cinzel title, Export CSV + Cancel Event buttons, overview card, Event Details + Quick Actions side cards). Queries, change-request review, approve/reject and cancel forms/actions are unchanged (same POST targets, CSRF, field names).
- New `assets/css/admin-event-view.css` (loaded through the new optional `$adminPageCss` variable in `includes/admin_layout_start.php`); new icons in `adminIcon()`; `admin/events/export.php` accepts `event_id` for a single-event CSV.
- Not in the mockup because the app has no such feature: "Edit Event" and "Manage Participants" (admins cannot edit events/participants); "View Attendance Records" replaces the latter. The banner photo is a CSS gradient (no campus image in the project).

## Session: Event view Quick Actions made functional (not run with PHP)
- View Registered Participants -> scrolls to a new "Registered Participants" card on the page (roster from `event_attendance_roster` with each student's recorded attendance status, max 500 rows, scrollable).
- View Attendance Records -> `admin/attendance/index.php?event_id=<id>` (new `event_id` filter; kept through the form, pagination and status overview).
- View Reports -> `admin/reports/index.php?report=attendance` pre-filtered to the event's department, academic year and semester.

## Session: Reports page layout (not run with PHP)
- `admin/reports/index.php`: report type is now a tab bar (keeps the year/semester/department filters), one compact filter row with Apply/Clear and Export CSV / Print buttons on the right, panel subtitle shows row count and active filters, numeric columns right-aligned and colour-coded (present/late/absent/excused), attendance rate shows a progress bar. Queries, export parameters and print behaviour unchanged. Styles: end of `assets/css/admin-refresh.css`, scoped to `.admin-reports-page`.

## Session: Reports analytics layout + event view top bar removed (not run with PHP)
- **Admin Reports** (`admin/reports/index.php`, end of `assets/css/admin-refresh.css`): rebuilt as filters -> 4 KPI cards (now with a context line) -> chart row (ranked bars: events per department / attendance rate by event (latest 8) / most absences per student (top 8); stacked mix bars kept for "Attendance by department"; plus a status-mix donut) -> detail table. Export CSV and Print moved to the page heading. SQL, filters, tab links, export parameters and the four detail tables are unchanged. Layout informed by web research on dashboard best practice (KPI row, one main chart, supporting chart, table). Previewed with real CSS + mock data in headless Chromium (desktop/tablet/mobile); the PHP was **not executed** (no PHP in the sandbox) - only a bracket-balance check was done.
- Known nuance: on the Events report, "Approved" and "Completed" can overlap (completed counts approved events past their end time, as before); the donut percentages are of the slices shown.
- **Admin event view**: removed the top search bar, notification bell, user/logout menu and the line beneath them (markup in `view.php`, `.ev-topbar/.ev-search/.ev-bell/.ev-user` CSS, and the now-unused pending-count query). Nothing else on the page changed.
- Docs audited again against source: 48 stubs, 16 tables, 10/5 department-head layout split, refresh pages (Attendance, Reports, Security) all still match.

## Session: Security layout, yellow title bar removed, Academic Periods link removed (not run with PHP)
- `admin/security/index.php`: markup only (queries, filters, pagination untouched) - KPI cards with context lines, accounts table + icon rule list side by side, activity table with action chips; styles at the end of `admin-refresh.css`.
- Yellow bar before panel titles: the `.admin-panel-heading h3::before` rule was deleted from `admin-refresh.css` (it affected Reports and Security; Attendance already hid it), so no admin page shows it.
- `includes/admin_layout_start.php`: "Academic Periods" removed from `$adminNav`. The `admin/academic-periods/*` files are untouched and still return 501 if opened by URL. Consequence: periods cannot be reached from the UI, which was already a placeholder.
- Previewed with real CSS + mock data in headless Chromium; PHP not executed (bracket-balance check only).

## Session: Admin dashboard redesign + nested copy deleted (not run with PHP)
- Inspiration (web search): school-admin dashboards (openSIS, EduRegister, Dribbble school-admin shots) lead with pending work and KPI cards, then an attendance chart, a schedule list and an activity feed. Chosen as the best fit because the admin's main job here is approving events.
- `admin/dashboard.php`: hero kept; new "requests waiting" strip (only when `pending_requests` > 0); KPI cards are now links (students, departments, attendance, pending events); two rows with a wide + narrow column: attendance by department | **Today's events** (new, up to 5, Upcoming/Ongoing/Ended by current time), event requests to review | **Recent activity** (new, last 6 rows of `admin_activity_logs`, links to Security). Two new read-only queries; all existing queries unchanged. Styles: "Dashboard v2" block in `assets/css/admin.css` (`.dash-alert`, `.dash-grid-wide`, `.dash-today`, `.dash-state`, `.dash-activity`, `a.dash-stat-link`).
- Today's-events "All events" link uses `?event_date=YYYY-MM-DD&academic_period_id=all`.
- Deleted `for_testing-main/for_testing-main/` (stale older duplicate); nothing in the code referenced it.
- Previewed with real CSS + mock data in headless Chromium; PHP not executed (bracket-balance check only).

## In progress
Unknown — nothing in the repo records in-progress work.

## Known limitations (confirmed in code)
- Placeholders (501): Academic Periods, student Profile, forgot-password, all of `api/`, `classes/`, root `reports/`.
- No automated tests; no Git repo in the uploaded copy.
- `.htaccess` sets `WEAMMS_ENV development`; README says production is the default and rejects dev DB defaults. Make sure deployment overrides this.
- `.gitignore` only lists `.env`; `uploads/student-appeals/` contents are not ignored.
- Legacy unlinked CSS: `theme.css`, `theme-refresh.css`, `minimal-direct.css` (still reference Inter).
- Three department-head layouts coexist (see `docs/ARCHITECTURE.md`): older `department_head_layout_start.php` (10 pages), `department_head_shell_start.php` (5 pages), and the self-contained `dashboard.php`.

## Decisions
- Department head UI reuses admin look (`ad-*` classes, `admin-sidebar`) via `department-head-shell.css` / `department-head-sidebar.css`.
- Stub files intentionally fail with 501 rather than blank success.

## Next steps
Verified need: browser-test the new Attendance status overview (click each status; combine with a search/department filter; check an empty database); browser-test the changes above on all three roles; confirm Time Rules save for a single-day and a multi-day event.
Suggestions (not verified requirements): implement Academic Periods admin module (other features depend on periods); add a minimal test/lint routine; initialize Git (ask first); tighten `.gitignore` for uploads and local files; remove or archive unused CSS files.
