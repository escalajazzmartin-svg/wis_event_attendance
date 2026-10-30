<?php

require_once __DIR__ . '/../../includes/department_head_context.php';
require_once __DIR__ . '/../../includes/department_head_students.php';

[$where, $params] = dhStudentFilter($_GET, $departmentId);
$stmt = $pdo->prepare(
    'SELECT s.student_number, s.first_name, s.middle_name, s.last_name,
            s.year_level, s.section, s.enrollment_status,
            ' . DH_STUDENT_RATE_SQL . ' AS attendance_rate
     FROM students s
     WHERE ' . $where . '
     ORDER BY s.last_name, s.first_name, s.student_id'
);
$stmt->execute($params);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="department-students-' . date('Ymd') . '.csv"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Student number', 'Name', 'Year level', 'Section', 'Attendance rate', 'Enrollment status']);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Prefix cells that start with = + - @ so spreadsheets do not run them as formulas.
    fputcsv($out, array_map(static function ($cell): string {
        $cell = (string) $cell;

        return $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false ? "'" . $cell : $cell;
    }, [
        $row['student_number'],
        dhFullName($row['first_name'], $row['middle_name'], $row['last_name']),
        $row['year_level'],
        $row['section'],
        $row['attendance_rate'] !== null ? $row['attendance_rate'] . '%' : '',
        $row['enrollment_status'],
    ]));
}
fclose($out);
