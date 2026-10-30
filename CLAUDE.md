# CLAUDE.md — WEAMMS

Persistent instructions for Claude Code sessions. Docs can be outdated: **always inspect the current source before implementing.** Source code is the source of truth.

## Project overview (verified by reading the code)
WEAMMS — Web-Based Event Attendance Management System for Westmead International School. Plain **PHP + MySQL (PDO)** app, server-rendered pages, no framework, no Composer, no npm. Three roles: `admin`, `department_head`, `student` (table `roles`).

- Runs on Apache (XAMPP-style); `.htaccess` blocks `config/`, `includes/`, `classes/`, `database/` and sets `WEAMMS_ENV development`.
- `BASE_URL` is derived from the folder's position under the web document root (`config/app.php`), so the app can live in a subfolder. Always build URLs with `BASE_URL`; never hard-code a folder name.
- Config comes from **server environment variables** (`WEAMMS_ENV`, `WEAMMS_DB_*`, `WEAMMS_TIMEZONE`); `.env` is NOT auto-loaded. See `README.md` and `.env.example` (names only).
- Timezone: `Asia/Manila` (default) or `UTC` only (`config/timezone.php`).
- Frontend: hand-written CSS in `assets/css/`, Font Awesome (CDN), Chart.js, qrcode-generator and jsQR (CDN). No build step.
- Run: put the folder under the web root, set env vars, import `database/wis_event_attendance.sql`, create the first admin with `scripts/create-admin.php` (CLI). Not run/verified by Claude — PHP is not available in the Claude sandbox.
- **No automated tests exist** (no PHPUnit/composer). Verification = `php -l <file>` on changed files plus manual checks in the browser.
- **Not a Git repository** in the uploaded copy. Do not run `git init` without asking.

## Structure (real)
- `admin/` — admin pages (students, department-heads, departments, events, attendance, appeals (read-only), reports, security, academic-periods*). `*` = 501 placeholder.
- `department-head/` — dashboard, students, department, events, attendance (+scan/records/correct/history), attendance-settings (Time Rules), appeals, reports.
- `student/` — dashboard, events, attendance (+scan.php = shows own QR code), appeals, reports; `profile/` is a placeholder.
- `auth/` — `admin-login.php`, `department-head-login.php`, `student-login.php`, `logout.php`; `forgot-password.php` is a placeholder.
- `includes/` — per-role context + layout: `admin_context.php`/`admin_layout_start.php`, `department_head_context.php` + `department_head_shell_start|end.php` + `department_head_sidebar.php` (+ older `department_head_layout_start.php`), `student_context.php`/`student_layout_start.php`/`sidebar.php`/`header.php`, `event_lifecycle.php`, `login_rate_limit.php`.
- `api/`, `classes/`, `reports/`, `config/constants.php`, `includes/{auth,csrf,functions,role_guard}.php` — **all stubs** that return HTTP 501 via `includes/unimplemented.php`. Real logic lives in the page files and `includes/*_context.php`. Do not assume these stubs hold logic.
- `database/` — schema `wis_event_attendance.sql`, `demo_seed.sql` (testing only, never production), `migrations/` (apply in date order).
- `scripts/` — `create-admin.php`, `finalize-events.php` (CLI, schedulable).
- `assets/css/`, `assets/js/`, `assets/images/wisnoname.png` (logo). `uploads/student-appeals/` is private (appeal evidence).

## Conventions observed
- Each page starts by `require_once` the role context file (it does the role/session guard, loads `$pdo`, and defines helpers), sets page-title variables, then requires the layout start file and the matching end file.
  - Admin: `adminEscape`, `adminVerifyCsrf`, `adminLogActivity`; vars `$adminPageTitle`, optional `$adminRefreshCss = true` (loads `assets/css/admin-refresh.css`, used by Attendance, Reports, Security; wrap content in `<section class="admin-content-section admin-refresh">`).
  - Department head: `dhEscape`, `dhCsrfToken`/`dhVerifyCsrf`, `dhParseLocalDateTime`, `dhValidateEventSubmission`; vars `$pageTitle`, `$pageClass`, `$shellNavActive`.
  - Student: `studentEscape`, `studentCsrfToken`/`studentVerifyCsrf`; vars `$studentPageTitle`, `$studentPageCss`.
- Admin CSRF: token lives in `$_SESSION['admin_csrf_token']` and is exposed to pages as `$adminCsrfToken` (hidden input `csrf_token`); verify with `adminVerifyCsrf()`.
- Always: PDO prepared statements, per-role CSRF tokens on POST, escape output with the role's escape helper, POST-then-redirect (`?notice=...`).
- Event statuses: `pending, approved, rejected, cancelled, cancellation_requested, completed`. Attendance: `present, late, absent, excused`; sources `barcode` (= QR scan; the stored value was kept, shown as "QR code"), `department_head, appeal, system`. `synchronizeEventLifecycle()` runs on protected requests and by cron.
- Department head permissions are per department flags set by admin: `department_head_can_edit` (edit department) and `department_head_can_edit_attendance` (correct attendance). Respect them.
- Department head data is always scoped by `$departmentId`; never query across departments.

## Where to look (read the one you need, not all)
- `docs/PROJECT_CONTEXT.md` — one-file summary of the whole system (best starting point, and the file to upload to a Claude Project).
- `docs/ARCHITECTURE.md` — request flow, which layout each page uses, full page map, list of 501 stubs.
- `docs/DATABASE.md` — tables, enums, migrations, rules enforced only in code.
- `docs/SYSTEM_FEATURES.md` (features per role), `docs/UI_GUIDELINES.md` (visual rules/components), `docs/PROJECT_STATUS.md` (what changed, limitations, next steps).

## UI rules
See `docs/UI_GUIDELINES.md`. Short version: maroon sidebar (do not change sidebar UI unless asked), **Roboto** text + **Cinzel** brand headings, load fonts via the shared Google Fonts link already present in every layout head. Reuse `ad-*` components and the shared sidebar files; do not create per-page one-off styles. Department-head pages use three different layouts (shell, older layout, self-contained dashboard) — check `docs/ARCHITECTURE.md` before editing shared layout files. Do not change sidebar expand/collapse behavior unless asked. All three portals share `assets/css/ui-refresh.css` (loaded last in every layout; no top bar, `#f3f0ed` background, 14px panels) — put cross-role look changes there.

## Workflow for every task
1. Read this file and the relevant `docs/*.md`.
2. Inspect the real source files involved, plus the shared includes/CSS they use (trace dependencies before editing).
3. Make the smallest coherent change. Preserve routes, permissions, CSRF checks, and unrelated features.
4. Verify: `php -l` on changed PHP (if PHP is available), re-read the diff, check affected roles/pages manually when possible. Say plainly what could not be run.
5. Update docs only when project state or durable rules change (`PROJECT_STATUS.md` for progress, `SYSTEM_FEATURES.md` for features, `UI_GUIDELINES.md` for visuals, this file for rules).
6. Final report: what changed, files changed, checks run + results, unresolved issues.

## Safety
- Never print, copy, or commit secrets (`.env`, DB passwords, tokens). `.env.example` shows names only.
- No destructive Git commands; don't revert uncommitted user work; commit only when asked. Don't edit `.gitignore` blindly (currently only ignores `.env`).
- Don't import `demo_seed.sql` into production; don't edit already-applied migrations — add a new dated one.
