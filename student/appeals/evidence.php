<?php

require_once __DIR__ . '/../../includes/student_context.php';

$appealId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($appealId === false || $appealId === null || $appealId < 1) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}

$stmt = $pdo->prepare(
    'SELECT aa.evidence_path
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea
        ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e
        ON e.event_id = ea.event_id
     WHERE aa.appeal_id = ?
       AND aa.student_id = ?
       AND ea.student_id = ?'
);

$stmt->execute([
    $appealId,
    $studentId,
    $studentId
]);

$storedPath = $stmt->fetchColumn();

if (!is_string($storedPath) || $storedPath === '') {
    http_response_code(404);
    exit('Evidence not found in your appeal.');
}

$file = studentEvidenceFilePath($storedPath);

if ($file === null || !is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit('Evidence file is unavailable.');
}

$mimeType = mime_content_type($file);

$allowedTypes = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

if (!is_string($mimeType) || !isset($allowedTypes[$mimeType])) {
    http_response_code(415);
    exit('This evidence type cannot be previewed.');
}

$fileSize = filesize($file);

if ($fileSize === false) {
    http_response_code(500);
    exit('Unable to read the evidence file.');
}

$extension = $allowedTypes[$mimeType];

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline; filename="appeal-proof.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

if (readfile($file) === false) {
    // Headers may already have been sent; log the failure for server diagnostics.
    error_log('Failed to read attendance appeal evidence file.');
    exit;
}
