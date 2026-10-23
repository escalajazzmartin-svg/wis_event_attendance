<?php

require_once __DIR__ . '/../../includes/student_context.php';

$studentPageTitle = 'Attendance Appeal Details';
$studentPageCss = 'student-appeals.css';

$appealId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($appealId === false || $appealId === null || $appealId < 1) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}

$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.attendance_id, aa.appeal_reason,
            aa.evidence_path, aa.appeal_status, aa.submitted_at,
            aa.reviewed_at, aa.reviewer_remarks, ea.attendance_status,
            e.event_id, e.event_title, e.start_at,
            ap.academic_year, ap.semester
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea
        ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
     WHERE aa.appeal_id = ?
       AND aa.student_id = ?
       AND ea.student_id = ?
       AND e.department_id = ?
     LIMIT 1'
);

$stmt->execute([
    $appealId,
    $studentId,
    $studentId,
    $studentDepartmentId
]);

$appeal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appeal) {
    http_response_code(404);
    exit('Appeal not found in your account.');
}

require_once __DIR__ . '/../../includes/student_layout_start.php';
