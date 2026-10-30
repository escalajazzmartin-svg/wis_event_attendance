<?php

/**
 * Query builders shared by the admin list pages and their CSV exports, so the
 * export always matches what the filters on screen are showing.
 */

/**
 * Students: search + department + account status filters.
 *
 * @return array{0:string,1:array<string,mixed>,2:array{q:string,department_id:int,status:string}}
 */
function adminStudentFilter(array $get): array
{
    $q = trim(is_string($get['q'] ?? null) ? $get['q'] : '');
    $departmentId = filter_var($get['department_id'] ?? null, FILTER_VALIDATE_INT);
    $departmentId = $departmentId !== false && $departmentId > 0 ? $departmentId : 0;
    $status = is_string($get['status'] ?? null) && in_array($get['status'], ['active', 'inactive', 'locked'], true)
        ? $get['status']
        : '';

    $where = "r.role_name = 'student'";
    $params = [];
    if ($q !== '') {
        $where .= " AND (u.username LIKE :q_user OR u.email LIKE :q_email
                    OR s.student_number LIKE :q_number
                    OR s.first_name LIKE :q_first OR s.last_name LIKE :q_last
                    OR CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) LIKE :q_full)";
        foreach (['q_user', 'q_email', 'q_number', 'q_first', 'q_last', 'q_full'] as $key) {
            $params[$key] = '%' . $q . '%';
        }
    }
    if ($departmentId > 0) {
        $where .= ' AND s.department_id = :department_id';
        $params['department_id'] = $departmentId;
    }
    if ($status !== '') {
        $where .= ' AND u.account_status = :status';
        $params['status'] = $status;
    }

    return [$where, $params, ['q' => $q, 'department_id' => $departmentId, 'status' => $status]];
}

const ADMIN_STUDENT_FROM = "FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    INNER JOIN students s ON s.user_id = u.user_id
    INNER JOIN departments d ON d.department_id = s.department_id";

/** "2nd year · IT-2A": year level and section, skipping whichever is blank. */
function adminYearSection(?string $yearLevel, ?string $section): string
{
    $year = trim((string) $yearLevel);
    $year = preg_match('/^(\d+(?:st|nd|rd|th))\s+year$/i', $year, $m) ? $m[1] . ' year' : $year;
    $parts = array_filter([$year, trim((string) $section)], static fn (string $p): bool => $p !== '');

    return $parts === [] ? '—' : implode(' · ', $parts);
}

function adminFullName(?string $first, ?string $middle, ?string $last): string
{
    return trim(implode(' ', array_filter([trim((string) $first), trim((string) $middle), trim((string) $last)], static fn (string $p): bool => $p !== '')));
}

/**
 * Events: search + department + academic period (status is applied separately
 * so the status tabs can show counts for everything else that is selected).
 *
 * @return array{0:string,1:array<string,mixed>,2:array{q:string,department_id:int,period_id:int,period_all:bool}}
 */
function adminEventFilter(PDO $pdo, array $get): array
{
    $q = trim(is_string($get['q'] ?? null) ? $get['q'] : '');
    $departmentId = filter_var($get['department_id'] ?? null, FILTER_VALIDATE_INT);
    $departmentId = $departmentId !== false && $departmentId > 0 ? $departmentId : 0;

    // No period in the URL means "the current academic period".
    $periodAll = ($get['academic_period_id'] ?? null) === 'all';
    $periodId = filter_var($get['academic_period_id'] ?? null, FILTER_VALIDATE_INT);
    $periodId = $periodId !== false && $periodId > 0 ? $periodId : 0;
    if ($periodId === 0 && !$periodAll) {
        $current = $pdo->query('SELECT academic_period_id FROM academic_periods WHERE is_current = 1 ORDER BY start_date DESC LIMIT 1')->fetchColumn();
        $periodId = $current !== false ? (int) $current : 0;
        $periodAll = $periodId === 0;
    }

    $eventDate = is_string($get['event_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['event_date']) ? $get['event_date'] : '';

    $where = '1 = 1';
    $params = [];
    if ($q !== '') {
        $where .= ' AND (e.event_title LIKE :q_title OR e.venue LIKE :q_venue)';
        $params['q_title'] = '%' . $q . '%';
        $params['q_venue'] = '%' . $q . '%';
    }
    if ($eventDate !== '') {
        $where .= ' AND DATE(e.start_at) = :event_date';
        $params['event_date'] = $eventDate;
    }
    if ($departmentId > 0) {
        $where .= ' AND e.department_id = :department_id';
        $params['department_id'] = $departmentId;
    }
    if ($periodId > 0) {
        $where .= ' AND e.academic_period_id = :period_id';
        $params['period_id'] = $periodId;
    }

    return [$where, $params, ['q' => $q, 'department_id' => $departmentId, 'period_id' => $periodId, 'period_all' => $periodAll, 'event_date' => $eventDate]];
}

const ADMIN_EVENT_FROM = "FROM events e
    INNER JOIN departments d ON d.department_id = e.department_id
    INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
    LEFT JOIN department_heads dh ON dh.department_head_id = e.requested_by";

/** "1st Semester, 2026–2027" */
function adminPeriodLabel(string $academicYear, string $semester): string
{
    $semesterLabel = in_array($semester, ['1st', '2nd'], true) ? $semester . ' Semester' : $semester;

    return $semesterLabel . ', ' . str_replace('-', '–', $academicYear);
}
