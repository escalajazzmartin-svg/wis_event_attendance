<?php

if (!function_exists('dhFullName')) {
    function dhFullName(?string $first, ?string $middle, ?string $last): string
    {
        return trim(implode(' ', array_filter(
            [$first, $middle, $last],
            static fn ($part): bool => $part !== null && $part !== ''
        )));
    }
}

/**
 * Shared filter for the department head student roster and its CSV export.
 * Always scoped to the signed-in department head's department.
 *
 * @return array{0:string,1:array<string,mixed>,2:array{q:string,year:string,status:string}}
 */
function dhStudentFilter(array $get, int $departmentId): array
{
    $statuses = ['enrolled', 'inactive', 'graduated', 'transferred'];
    $filters = [
        'q' => trim(is_string($get['q'] ?? null) ? $get['q'] : ''),
        'year' => trim(is_string($get['year'] ?? null) ? $get['year'] : ''),
        'status' => is_string($get['status'] ?? null) && in_array($get['status'], $statuses, true) ? $get['status'] : '',
    ];
    $where = ['s.department_id = :department_id'];
    $params = ['department_id' => $departmentId];

    if ($filters['q'] !== '') {
        $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
        $where[] = "(s.student_number LIKE :q1 OR CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) LIKE :q2)";
        $params['q1'] = $like;
        $params['q2'] = $like;
    }
    if ($filters['year'] !== '') {
        $where[] = 's.year_level = :year';
        $params['year'] = $filters['year'];
    }
    if ($filters['status'] !== '') {
        $where[] = 's.enrollment_status = :status';
        $params['status'] = $filters['status'];
    }

    return [implode(' AND ', $where), $params, $filters];
}

/** Share of recorded (non-excused) attendance that was present or late. */
const DH_STUDENT_RATE_SQL = "(SELECT ROUND(100 * SUM(ea.attendance_status IN ('present', 'late'))
        / NULLIF(SUM(ea.attendance_status <> 'excused'), 0))
     FROM event_attendance ea WHERE ea.student_id = s.student_id)";
