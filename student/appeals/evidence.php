<?php

require_once __DIR__ . '/../../includes/student_context.php';

$appealId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($appealId === false || $appealId < 1) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}
$stmt = $pdo->prepare(
    'SELECT aa.evidence_path
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE aa.appeal_id = ? AND aa.student_id = ?
       AND ea.student_id = ? AND e.department_id = ?'
);
$stmt->execute([$appealId, $studentId, $studentId, $studentDepartmentId]);
$storedPath = $stmt->fetchColumn();
if (!is_string($storedPath) || $storedPath === '') {
    http_response_code(404);
    exit('Evidence not found in your appeal.');
}

if (!is_string($storedPath)) {
    http_response_code(404);
    exit('Evidence not found in your appeal.');
}
$file = studentEvidenceFilePath($storedPath);
if ($file === null) {
    http_response_code(404);
    exit('Evidence file is unavailable.');
}
$mimeType = mime_content_type($file);
if (!in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('This evidence type cannot be previewed.');
}
$extension = $mimeType === 'application/pdf' ? 'pdf' : ($mimeType === 'image/jpeg' ? 'jpg' : 'png');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="appeal-proof.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
readfile($file);
