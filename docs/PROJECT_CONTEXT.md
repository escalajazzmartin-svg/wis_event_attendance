# WEAMMS — Project Context (single-file summary)

Upload this file to a Claude Project (or paste it at the start of a chat) so Claude knows the system without the codebase. Verified against the source on 2026-10-30. The code was read but never executed (no PHP in the review environment, no automated tests). **If this file and the code disagree, the code wins.** For details see `ARCHITECTURE.md`, `DATABASE.md`, `SYSTEM_FEATURES.md`, `UI_GUIDELINES.md`, `PROJECT_STATUS.md`, and `CLAUDE.md` (working rules).

## What it is
WEAMMS — Web-Based Event Attendance Management System for Westmead International School. Plain PHP + MySQL (PDO), server-rendered pages, no framework, no Composer, no npm, no build step. Apache (XAMPP-style). Three roles: `admin`, `department_head`, `student`.

## Stack and setup
- `BASE_URL` is computed from the folder's position under the web root (`config/app.php`); always build URLs with it.
- Config from server environment variables (`WEAMMS_ENV`, `WEAMMS_DB_*`, `WEAMMS_TIMEZONE`); `.env` is not auto-loaded. Timezone `Asia/Manila` (default) or `UTC`.
- Install: import `database/wis_event_attendance.sql`, create first admin with `php scripts/create-admin.php` (CLI, env vars `WEAMMS_INITIAL_ADMIN_*`). Upgrades: apply `database/migrations/` in date order. `demo_seed.sql` is for disposable test DBs only.
- Optional scheduler: `php scripts/finalize-events.php` every 5 minutes.
- Frontend: hand-written CSS (`assets/css/`), Font Awesome / Chart.js / qrcode-generator / jsQR from CDNs, fonts Roboto + Cinzel.

## Folder map
`admin/` · `department-head/` · `student/` (role pages) · `auth/` (3 login pages + logout) · `includes/` (role context + layout files, `event_lifecycle.php`, `login_rate_limit.php`) · `assets/{css,js,images}` · `database/` · `scripts/` · `uploads/student-appeals/` (private evidence).
**Stubs returning HTTP 501 (48 files, no logic):** all of `api/`, `classes/`, root `reports/`, `includes/{auth,csrf,functions,role_guard}.php`, `config/constants.php`, `auth/forgot-password.php`, `student/profile/index.php`, `admin/academic-periods/*`. Real logic is in the page files and `includes/*_context.php`.

## Page pattern
Page → `require_once` role context (guard, `$pdo`, helpers, lifecycle sync) → set title vars → require layout start → HTML → layout end. POST: verify CSRF → PDO prepared statements → redirect with `?notice=`.
- Admin: `adminEscape`, `adminVerifyCsrf`, `$adminCsrfToken`, `adminLogActivity`; vars `$adminPageTitle`, `$adminRefreshCss = true` (refreshed look).
- Dept head: `dhEscape`, `dhCsrfToken`/`dhVerifyCsrf`, `dhParseLocalDateTime`, `dhValidateEventSubmission`; vars `$pageTitle`, `$pageClass`, `$shellNavActive`; data always scoped to `$departmentId`. Three layouts coexist (shell, older layout, self-contained dashboard).
- Student: `studentEscape`, `studentCsrfToken`/`studentVerifyCsrf`; vars `$studentPageTitle`, `$studentPageCss`.

## Features (all present in code)
- **Admin**: dashboard (pending-requests strip, KPI links, attendance by department, today's events, requests to review, recent activity); students CRUD/status/password reset/QR regenerate/CSV; department heads CRUD + permissions; departments CRUD; events (approve/reject/cancel, review change requests, export); attendance monitoring (status overview + filters + integrity check) and change history; appeals (read-only view of every department's appeals + evidence); reports (4 types; KPI cards + main chart + status donut + table; CSV, print); security (read-only: rate limits, locked accounts, activity log); activity logging.
- **Department head**: dashboard; students (own dept, CSV); department edit (needs `department_head_can_edit`); events create/edit/cancel/request change (admin approves); Time Rules per event; QR scan (camera or USB/Bluetooth scanner), records, history, correction (needs `department_head_can_edit_attendance`); appeals review (approval writes attendance with source `appeal` + audit log); reports.
- **Student**: dashboard, events, attendance + history, own QR code page (students do not self check-in), appeals with evidence upload, personal reports.
- **Backend**: `synchronizeEventLifecycle()` completes approved events past `end_at` and inserts system `absent` rows for roster students without a record; login rate limiting (5 failures per user+IP / 30 per IP / 15 min); CSRF everywhere; event rosters snapshotted.
- Statuses — events: pending, approved, rejected, cancelled, cancellation_requested, completed. Attendance: present, late, absent, excused (sources: barcode, department_head, appeal, system). Appeals: pending, approved, rejected, withdrawn.

## Database (16 tables)
roles, users, students, departments, department_heads, academic_periods, events, event_participants, event_attendance_roster, event_attendance (unique event+student), attendance_settings (one per event), attendance_appeals, attendance_audit_logs, event_change_requests, admin_activity_logs, login_rate_limits. Details in `DATABASE.md`.

## UI rules
Maroon sidebar (don't change it or its collapse behaviour unless asked); Roboto text + Cinzel brand headings; reuse `ad-*` components and the shared stylesheets; no per-page one-off styles. Brand maroon `#8B1E1E`, gold `#F2C300`, admin background `#f3f0ed`. Admin Attendance/Reports/Security use `admin-refresh.css` (`.admin-refresh` scope); no yellow bar before panel titles anywhere; admin sidebar has no "Academic Periods" item (module still a 501 placeholder). Admin Attendance has a "Status overview" (proportion bar + clickable status filters) at the bottom of the records panel instead of five summary cards; the records table scrolls in a fixed-height area (`.admin-records-scroll`) and the filter bar is compact (`.admin-filters-compact`, `admin-filter-button-sm`).

## QR attendance (changed 2026-10-30)
Barcodes were replaced by QR codes in the UI. The QR encodes the same 64-hex `students.barcode_token`, so existing tokens keep working; the column name, the `record_source = 'barcode'` value and the `barcode_scan` audit action were intentionally NOT renamed (no migration). Screens show it as "QR code". Student page `student/attendance/scan.php` draws the QR (qrcode-generator). Dept-head `department-head/attendance/scan.php` reads QR codes with the device camera (jsQR + `getUserMedia`; needs HTTPS or localhost; auto-restarts after each scan via `localStorage` key `weamms_qr_camera`) and still accepts a typed/USB-scanned token in the same CSRF-protected POST form.

## Shared look (changed 2026-10-30)
`assets/css/ui-refresh.css` is linked last by every layout (admin, dept-head shell, dept-head older layout, dept-head dashboard, student). The student and older dept-head pages no longer render the `includes/header.php` top bar (the file remains, now unused); the student sidebar shows the account name above Logout and is 249px like the others, collapsing to a 72px rail at <=760px. Not browser-tested.

## Known limitations
Academic Periods module, student profile and forgot-password are placeholders (periods must be managed in the DB, and other features depend on them). No automated tests; no Git repo in the uploaded copy. `.htaccess` sets `WEAMMS_ENV development` — override in production. Legacy unlinked CSS: `theme.css`, `theme-refresh.css`, `minimal-direct.css`. Recent UI changes (QR scanning, shared `ui-refresh.css` look, fonts, logout buttons, Time Rules inputs, admin refresh, Attendance status overview) are not yet browser-tested.

## How to work on this project
Inspect the real files you will touch and the includes/CSS they use; make the smallest coherent change; keep routes, permissions, CSRF and unrelated features; run `php -l` on changed PHP where PHP exists, otherwise say it was not run; never print secrets; add a new dated migration instead of editing applied ones; update docs only when state or durable rules change.
