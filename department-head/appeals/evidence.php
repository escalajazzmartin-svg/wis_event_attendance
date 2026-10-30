<?php

require_once __DIR__ . '/../../includes/department_head_context.php';

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
     WHERE aa.appeal_id = ? AND e.department_id = ?'
);
$stmt->execute([$appealId, $departmentId]);
$storedPath = $stmt->fetchColumn();
if (!is_string($storedPath) || $storedPath === '') {
    http_response_code(404);
    exit('Evidence was not found for this department appeal.');
}

$uploadsRoot = realpath(__DIR__ . '/../../uploads');
$relativePath = str_replace('\\', '/', $storedPath);
$marker = strpos($relativePath, 'uploads/');
if ($marker !== false) {
    $relativePath = substr($relativePath, $marker + strlen('uploads/'));
}
$relativePath = ltrim($relativePath, '/');
$filePath = $uploadsRoot === false
    ? false
    : realpath($uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
$rootPrefix = $uploadsRoot === false ? '' : rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

if (
    $filePath === false ||
    !str_starts_with(strtolower($filePath), strtolower($rootPrefix)) ||
    !is_file($filePath)
) {
    http_response_code(404);
    exit('The appeal evidence file is unavailable.');
}

$mimeType = mime_content_type($filePath);
$allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(415);
    exit('This evidence file type cannot be previewed.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
$extension = match ($mimeType) {
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
};
header('Content-Disposition: inline; filename="appeal-evidence.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
