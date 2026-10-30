<?php

require_once __DIR__ . '/../../includes/admin_context.php';
require_once __DIR__ . '/../../includes/admin_lists.php';

[$where, $params] = adminStudentFilter($_GET);
$stmt = $pdo->prepare(
    'SELECT s.student_number, s.first_name, s.middle_name, s.last_name, u.email,
            d.department_name, s.year_level, s.section, u.account_status, s.enrollment_status '
    . ADMIN_STUDENT_FROM . ' WHERE ' . $where . ' ORDER BY s.last_name, s.first_name, s.student_id'
);
$stmt->execute($params);

adminStreamCsv(
    'students-' . date('Ymd') . '.csv',
    ['Student number', 'Name', 'Email', 'Department', 'Year level', 'Section', 'Account status', 'Enrollment status'],
    (static function () use ($stmt): Generator {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield [
                $row['student_number'],
                adminFullName($row['first_name'], $row['middle_name'], $row['last_name']),
                $row['email'],
                $row['department_name'],
                $row['year_level'],
                $row['section'],
                $row['account_status'],
                $row['enrollment_status'],
            ];
        }
    })()
);
