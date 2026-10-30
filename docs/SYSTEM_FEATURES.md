# System Features

Legend: **Verified in code** = I read the implementing source. **Not run:** nothing has been executed or browser-tested (no PHP in the review environment, no automated tests exist). **Placeholder** = file returns HTTP 501. **Needs verification** = seen but not traced.

## Roles and access
| Role | Login page | Guard |
|---|---|---|
| admin | `auth/admin-login.php` | `includes/admin_context.php` (session role + active account check in DB) |
| department_head | `auth/department-head-login.php` | `includes/department_head_context.php` (scoped to own department) |
| student | `auth/student-login.php` | `includes/student_context.php` |

Verified in code: login failures are rate-limited (`includes/login_rate_limit.php`, table `login_rate_limits`); logout (`auth/logout.php`) clears the session and redirects to the role's login page; session cookies HttpOnly/SameSite=Lax. Accounts have `account_status` active/inactive/locked.

## Admin — verified in code
- **Dashboard** (pending-requests strip, linked KPI cards, attendance by department, today's events, requests to review, recent admin activity), **Students** (list, create, edit, delete, status, reset password, regenerate barcode, CSV export), **Department Heads** (list, create/edit/delete, per-head permission toggles in `permissions.php`), **Departments** (CRUD, status, details), **Events** (list, view, approve/reject/cancel in `action.php`, review change requests in `change-action.php`, export), **Attendance** monitoring (status overview bar with click-to-filter counts, filters by search/department/period/status/date, paginated records, integrity table of events with missing/duplicate records) + change history, **Appeals** (read-only: all departments' appeals with status tabs, search, department filter, pagination; detail page and evidence viewer; decisions stay with department heads), **Reports** (4 report types: events per department, event attendance, attendance by department, student summaries; filters, 4 KPI cards with a share-of-total line, a ranked/stacked bar chart plus a status-mix donut (both CSS-only, derived from the same rows as the table), the detail table, CSV export, print). The admin event view page has no top search/notification/user bar (removed 2026-10-30); logout is in the sidebar.
- Admin activity logging via `adminLogActivity` → `admin_activity_logs`.
- **Security** (`admin/security/index.php`, read-only): summary cards (blocked sign-in sources from `login_rate_limits`, locked/inactive accounts from `users`, admin actions in 24 h), the sign-in protection rules (static text matching `includes/login_rate_limit.php`), locked/inactive account list with a link to the edit page, and a filterable, paginated `admin_activity_logs` view. No unlock/edit actions and no active-session count (not implemented).
- Placeholder (501): **Academic Periods** (`admin/academic-periods/*`), linked in the sidebar. Academic periods are read by other features, so they currently must be managed in the database.

## Department head — verified in code
- **Dashboard** (summary cards, Chart.js usage), **Students** (own department, CSV export), **Department** (edit only if `department_head_can_edit`=1).
- **Events**: create/edit/view/cancel/request-change. Validation in `dhValidateEventSubmission` (title ≤200, venue ≤200, within academic period, participants by student/year level/section). Approval is by admin.
- **Time Rules** (`attendance-settings/index.php`): per approved event, set present-until, late-until, closing times; must be ordered and inside the event window. Single-day events use time-only inputs (date added server-side); multi-day events use full date-time.
- **Attendance**: scan student QR code (camera, or USB/Bluetooth scanner typing the 64-hex token) for an approved event → status present/late by rules; records list; history; correct attendance only if `department_head_can_edit_attendance`=1; audit log in `attendance_audit_logs`.
- **Appeals**: review student appeals, approve/reject; approval updates attendance with `record_source='appeal'` and logs the change; evidence viewing.
- **Reports** page (department-head/reports/index.php) — present and uses Chart.js; **needs verification** of data accuracy.

## Student — verified in code
- **Dashboard**, **Events** (events for the student's department/participants), **Attendance** history, **My QR code** (`attendance/scan.php` draws the student's QR code (encodes `barcode_token`); students do not self-check-in), **Appeals** (create, edit, withdraw, view, upload evidence to private `uploads/student-appeals`), **Reports** (personal).
- Placeholder (501): **Profile** (`student/profile/index.php`).

## Backend behavior — verified in code
- `synchronizeEventLifecycle()` marks approved events `completed` when `end_at` has passed and inserts `absent` (`record_source='system'`) for roster students with no record. Runs on protected requests; also `scripts/finalize-events.php` for a scheduler.
- Event rosters snapshotted into `event_attendance_roster`; participant rules in `event_participants`.
- Event change requests table `event_change_requests` (pending/approved/rejected).

## Not implemented (stubs returning 501)
All of `api/**`, `classes/**`, `reports/**` (root-level), `auth/forgot-password.php`, `includes/{auth,csrf,functions,role_guard}.php`, `config/constants.php`. Reports are actually served by the role-specific pages above.

## Planned/requested but not implemented
- Academic Periods management, student profile, forgot-password (placeholders). Security: editable limits, account unlock action, active-session list (only a read-only page exists). No other requests are recorded in the repo.
