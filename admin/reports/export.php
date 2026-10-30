<?php

require_once __DIR__ . '/../../includes/admin_context.php';
$report = $_GET['report'] ?? 'events';
if (!is_string($report) || !in_array($report, ['events', 'attendance', 'students', 'departments'], true)) {
    http_response_code(400);
    exit('Invalid report type.');
}
$academicYear = trim(is_string($_GET['academic_year'] ?? null) ? $_GET['academic_year'] : '');
$semester = $_GET['semester'] ?? '';
if (!is_string($semester) || !in_array($semester, ['', '1st', '2nd', 'Summer', 'Other'], true)) {
    $semester = '';
}
$departmentId = filter_var($_GET['department_id'] ?? null, FILTER_VALIDATE_INT);
$departmentId = $departmentId !== false && $departmentId > 0 ? $departmentId : 0;
$conditions = [];
$params = [];
if ($academicYear !== '') {
    $conditions[] = 'ap.academic_year = :academic_year';
    $params['academic_year'] = $academicYear;
}
if ($semester !== '') {
    $conditions[] = 'ap.semester = :semester';
    $params['semester'] = $semester;
}
if ($departmentId > 0) {
    $conditions[] = match ($report) {
        'events' => 'd.department_id = :department_id',
        'students' => 'r.department_id = :department_id',
        default => 'e.department_id = :department_id',
    };
    $params['department_id'] = $departmentId;
}
$where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
if ($report === 'students') {
    $sql = "SELECT d.department_name, r.student_number,
                   CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name) AS student_name,
                   COUNT(DISTINCT CASE
                       WHEN ea.attendance_id IS NOT NULL THEN e.event_id
                   END) AS recorded_events,
                   COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
                   COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
                   COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
                   COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused
            FROM event_attendance_roster r
            INNER JOIN departments d ON d.department_id = r.department_id
            INNER JOIN events e ON e.event_id = r.event_id
            LEFT JOIN event_attendance ea
                ON ea.event_id = r.event_id AND ea.student_id = r.student_id
            LEFT JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
            $where
            GROUP BY r.student_id, r.department_id, d.department_name,
                     r.student_number, r.first_name, r.middle_name, r.last_name
            ORDER BY d.department_name, r.last_name, r.first_name";
} elseif ($report === 'attendance') {
    $sql = "SELECT d.department_name, e.event_title, e.start_at,
                   COUNT(DISTINCT ea.student_id) AS total_records,
                   COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
                   COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
                   COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
                   COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused,
                   (SELECT COUNT(*) FROM event_attendance_roster eligible
                    WHERE eligible.event_id = e.event_id
                   ) AS eligible_students
            FROM events e
            INNER JOIN departments d ON d.department_id = e.department_id
            INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
            LEFT JOIN event_attendance ea ON ea.event_id = e.event_id
            $where
            GROUP BY e.event_id, d.department_name, e.event_title, e.start_at
            ORDER BY e.start_at DESC";
} elseif ($report === 'departments') {
    $sql = "SELECT summary.department_name,
                   COUNT(*) AS total_events, SUM(summary.eligible_students) AS eligible_students,
                   SUM(summary.total_records) AS total_records,
                   SUM(summary.present) AS present, SUM(summary.late) AS late,
                   SUM(summary.absent) AS absent, SUM(summary.excused) AS excused
            FROM (
                SELECT d.department_id, d.department_name, e.event_id,
                       COUNT(DISTINCT ea.student_id) AS total_records,
                       COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
                       COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
                       COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
                       COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused,
                       (SELECT COUNT(*) FROM event_attendance_roster eligible
                        WHERE eligible.event_id = e.event_id
                       ) AS eligible_students
                FROM events e
                INNER JOIN departments d ON d.department_id = e.department_id
                INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
                LEFT JOIN event_attendance ea ON ea.event_id = e.event_id
                WHERE e.event_status IN ('approved', 'completed') " . ($conditions === [] ? '' : 'AND ' . implode(' AND ', $conditions)) . "
                GROUP BY e.event_id, d.department_id, d.department_name
            ) summary
            GROUP BY summary.department_id, summary.department_name
            ORDER BY summary.department_name";
} else {
    $sql = "SELECT d.department_name,
                   COUNT(e.event_id) AS total_events,
                   COALESCE(SUM(e.event_status = 'pending'), 0) AS pending,
                   COALESCE(SUM(e.event_status = 'approved'), 0) AS approved,
                   COALESCE(SUM(e.event_status = 'rejected'), 0) AS rejected,
                   COALESCE(SUM(e.event_status = 'cancelled'), 0) AS cancelled,
                   COALESCE(SUM(e.event_status = 'completed' OR (e.event_status = 'approved' AND e.end_at < NOW())), 0) AS completed
            FROM departments d
            LEFT JOIN events e ON e.department_id = d.department_id
            LEFT JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
            $where
            GROUP BY d.department_id
            ORDER BY d.department_name";
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (in_array($report, ['attendance', 'departments'], true)) {
    foreach ($rows as &$row) {
        $eligible = (int) $row['eligible_students'];
        $attended = (int) $row['present'] + (int) $row['late'] + (int) $row['excused'];
        $row['attendance_rate_percent'] = $eligible > 0 ? round($attended / $eligible * 100, 1) : 0;
    }
    unset($row);
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="weamms-' . $report . '-report-' . date('Ymd') . '.csv"');
$output = fopen('php://output', 'wb');
if ($output === false) {
    throw new RuntimeException('Unable to open report output stream.');
}
if ($rows === []) {
    fputcsv($output, ['No report rows found']);
} else {
    fputcsv($output, array_keys($rows[0]));
    foreach ($rows as $row) {
        $safeRow = array_map(
            static function (mixed $value): mixed {
                if (is_string($value) && preg_match('/^\s*[=+\-@]/u', $value)) {
                    return "'" . $value;
                }
                return $value;
            },
            $row
        );
        fputcsv($output, $safeRow);
    }
}
fclose($output);
exit;
