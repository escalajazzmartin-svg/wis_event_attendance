# Database Reference

Source of truth: `database/wis_event_attendance.sql` plus `database/migrations/` (apply in date order; never edit an applied migration, add a new dated one). MySQL/MariaDB via PDO, charset utf8mb4. Verified by reading the SQL; not run.

## Tables
| Table | Key columns / notes |
|---|---|
| `roles` | `role_id`, `role_name` (unique: `admin`, `department_head`, `student`), `description` |
| `users` | `user_id`, `username` (unique), `email` (unique), `password_hash`, `account_status` enum(active, inactive, locked), `last_login_at`, `role_id` (NOT NULL) |
| `students` | `student_id`, `user_id` (unique), `student_number` (unique), `barcode_token` char(64) (unique), `department_id`, `section`, `year_level`, names, `enrollment_status` enum(enrolled, inactive, graduated, transferred) |
| `departments` | `department_id`, `department_code` (unique), `department_name` (unique), `description`, `is_active`, `department_head_can_edit`, `department_head_can_edit_attendance` |
| `department_heads` | `department_head_id`, `user_id` (unique), `department_id`, `employee_number` (unique), names |
| `academic_periods` | `academic_period_id`, `academic_year`, `semester` enum(1st, 2nd, Summer, Other), `start_date`, `end_date`, `is_current`; unique (year, semester). No admin UI yet. |
| `events` | `event_id`, `department_id`, `requested_by` (dept head), `academic_period_id`, `event_title`, `venue`, `start_at`, `end_at`, `attendance_opens_at`, `attendance_closes_at`, `event_status` enum(pending, approved, rejected, cancelled, cancellation_requested, completed), admin review fields, cancellation request fields |
| `event_participants` | rules for who is eligible: `participant_type` enum(student, year_level, section) + matching value |
| `event_attendance_roster` | snapshot of eligible students per event (PK event+student; copies student number, names, year level, section) |
| `event_attendance` | `attendance_id`, `event_id`, `student_id` (unique together), `attendance_status` enum(present, late, absent, excused), `check_in_at`, `recorded_by_user_id`, `record_source` enum(barcode, department_head, appeal, system), `remarks` |
| `attendance_settings` | one row per event (unique `event_id`): `present_until`, `late_until`, `absent_after`, `updated_by` |
| `attendance_appeals` | `attendance_id`, `student_id`, `appeal_reason`, `evidence_path` (nullable), `appeal_status` enum(pending, approved, rejected, withdrawn), reviewer id/remarks/time |
| `attendance_audit_logs` | `attendance_id`, `event_id`, `actor_user_id`, `action_type`, `old_value`/`new_value` (JSON), `notes` |
| `event_change_requests` | `event_id`, `requested_by_department_head_id`, `request_payload` (JSON), `request_status` enum(pending, approved, rejected), reviewer fields |
| `admin_activity_logs` | `actor_user_id`, `action_type`, `entity_type`, `entity_id`, `details` |
| `login_rate_limits` | `scope_hash` (PK), `attempts`, `window_started_at`, `blocked_until` |

## Migrations (chronological)
`20261020_admin_activity_log`, `20261020_department_head_workflows` (permission flags, cancellation fields, `event_participants`), `20261021_event_change_requests`, `20261030_attendance_integrity` (roster table, `record_source` enum, nullable evidence), `20261031_role_auth_login_limits` (`login_rate_limits`, `users.role_id` NOT NULL). The base schema already includes all of these; migrations are for upgrading older databases.

## Business rules enforced in code (not by the schema)
- One attendance row per student per event (unique key `uq_event_student`).
- Event completion and system `absent` rows come from `synchronizeEventLifecycle()`.
- Appeal approval writes attendance with `record_source = 'appeal'` and an audit log row.
- Department heads may only touch their own department; correction needs `department_head_can_edit_attendance = 1`.
