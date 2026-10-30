# Architecture and Page Map

Verified by reading the source (2026-10-30). Nothing was executed: PHP is not available in the review environment and no automated tests exist.

## Request flow
1. Every protected page starts with `require_once` of its role context file:
   - admin → `includes/admin_context.php` (checks session role AND that the account is `active` with role `admin` in the DB; creates `$_SESSION['admin_csrf_token']`, exposed to pages as `$adminCsrfToken`)
   - department head → `includes/department_head_context.php` (resolves `$departmentId`; all data is scoped to it)
   - student → `includes/student_context.php`
2. The context file loads `$pdo` (`config/database.php`), starts the session (`config/session.php`), defines `BASE_URL` (`config/app.php`) and calls `synchronizeEventLifecycle($pdo)` (`includes/event_lifecycle.php`).
3. The page sets title/CSS variables, requires its layout start file, renders HTML, then requires the layout end/footer.
4. POST handlers verify the CSRF token, act through PDO prepared statements, then redirect with `?notice=...`.

`synchronizeEventLifecycle()` is called from the three context files, from `department-head/dashboard.php` (which loads `event_lifecycle.php` directly) and from `scripts/finalize-events.php`.

## Layouts (which pages use which)
| Role | Layout file(s) | Pages |
|---|---|---|
| Admin | `includes/admin_layout_start.php` (+ `admin.css`; `admin-refresh.css` when `$adminRefreshCss = true`) | all admin pages |
| Student | `includes/student_layout_start.php` → `includes/sidebar.php` (menu array per role, `student-sidebar`) | all student pages |
| Dept head, shell | `includes/department_head_shell_start.php` / `_end.php` | attendance-settings, attendance/index, department/index, events/index, students/index |
| Dept head, older layout | `includes/department_head_layout_start.php` | appeals/index, appeals/view, attendance/{correct,history,records,scan}, events/{create,edit,view}, reports/index |
| Dept head, self-contained | builds its own `<head>` and includes `department_head_sidebar.php`, `footer.php` (top bar `header.php` no longer included) | dashboard.php |
| Dept head, no HTML | POST/download endpoints | events/request, events/cancel, appeals/decision, appeals/evidence, students/export, department/update |

Both department-head layouts include `department_head_sidebar.php` (single sidebar, menu array inside the file). `includes/sidebar.php` also still holds a `department_head` menu but only the student layout includes it.

## Page map
**Admin** (sidebar: Dashboard, Students, Department Heads, Departments, Events, Attendance, Appeals, Reports, Security; "Academic Periods" link removed 2026-10-30, the 501 placeholder files remain)
- `dashboard.php`; `students/` index, create, edit, save, update, delete, status, reset-password, regenerate-barcode (UI says QR code), export; `department-heads/` index, create, edit, delete, permissions; `departments/` index, create, edit, save, update, view, status; `events/` index, view, action (approve/reject/cancel), change-action (review change requests), export; `attendance/` index (+ `history.php`); `appeals/` index, view, evidence (read-only); `reports/` index, export; `security/index.php`; `academic-periods/*` (501 placeholder, but linked).

**Department head** (sidebar: Dashboard, Students, Department, Events, Attendance, Time Rules, Appeals, Reports)
- `dashboard.php`; `students/` index, export; `department/` index, update; `events/` index, create, edit, view, request (change request), cancel; `attendance/` index, scan, records, correct, history; `attendance-settings/index.php`; `appeals/` index, view, decision, evidence; `reports/index.php`.

**Student** (sidebar: Dashboard, Event Schedules, My Attendance, Reports and Analytics, Attendance Appeals)
- `dashboard.php`; `events/` index, view; `attendance/` index, history, scan (shows own QR code); `appeals/` index, view, create, edit, withdraw, evidence; `reports/index.php`; `profile/index.php` (501 placeholder, not in the sidebar).

**Auth**: `index.php` redirects to `auth/student-login.php`; `auth/{admin,department-head,student}-login.php`, `logout.php`; `forgot-password.php` is a 501 placeholder.

## Stubs (HTTP 501 through `includes/unimplemented.php`)
Exactly these 48 files: `api/**` (25), `classes/**` (8), `reports/**` (4), `includes/{auth,csrf,functions,role_guard}.php`, `config/constants.php`, `auth/forgot-password.php`, `student/profile/index.php`, `admin/academic-periods/{index,create,edit,delete}.php`. Real logic never lives there.

## Front end
Hand-written CSS in `assets/css/` (no build step). `assets/js/attendance-charts.js` (Chart.js) and `attendance.js`, `login.js`. Font Awesome, Chart.js, qrcode-generator (student QR) and jsQR (dept-head scanner) load from CDNs. `assets/css/ui-refresh.css` is the shared cross-role look, linked last in every layout. Unlinked legacy CSS: `theme.css`, `theme-refresh.css`, `minimal-direct.css`.
