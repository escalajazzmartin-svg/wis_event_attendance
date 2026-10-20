<?php

require_once __DIR__ . '/../../includes/department_head_context.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

if (!dhVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request token. Return to the event list and try again.');
}

$eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
if ($eventId === false || $eventId < 1) {
    http_response_code(400);
    exit('A valid event was not specified.');
}

$stmt = $pdo->prepare(
    "UPDATE events
     SET event_status = 'pending',
         admin_reviewed_by = NULL,
         admin_reviewed_at = NULL,
         admin_remarks = NULL
     WHERE event_id = ? AND department_id = ?
       AND event_status = 'rejected'"
);
$stmt->execute([$eventId, $departmentId]);

if ($stmt->rowCount() !== 1) {
    http_response_code(409);
    exit('This event cannot be resubmitted. It may no longer be rejected or may not belong to your department.');
}

header('Location: ' . $baseUrl . '/department-head/events/index.php?notice=requested');
exit;