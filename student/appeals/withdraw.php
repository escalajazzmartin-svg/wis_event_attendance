<?php

require_once __DIR__ . '/../../includes/student_context.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
if (!studentVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request token. Refresh your appeals list and try again.');
}
$appealId = filter_var($_POST['appeal_id'] ?? null, FILTER_VALIDATE_INT);
if ($appealId === false || $appealId < 1) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}

$update = $pdo->prepare(
    "UPDATE attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     SET aa.appeal_status = 'withdrawn'
     WHERE aa.appeal_id = ? AND aa.student_id = ?
       AND ea.student_id = ? AND e.department_id = ?
       AND aa.appeal_status = 'pending'"
);
$update->execute([$appealId, $studentId, $studentId, $studentDepartmentId]);
if ($update->rowCount() !== 1) {
    http_response_code(409);
    exit('This appeal is no longer pending or does not belong to your account.');
}

header('Location: ' . BASE_URL . '/student/appeals/index.php');
exit;
