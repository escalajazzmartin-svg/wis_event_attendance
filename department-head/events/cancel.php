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

$remarks = trim(is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : '');

try {
    $pdo->beginTransaction();
    $eventStmt = $pdo->prepare(
        'SELECT event_status FROM events
         WHERE event_id = ? AND department_id = ?
         FOR UPDATE'
    );
    $eventStmt->execute([$eventId, $departmentId]);
    $eventStatus = $eventStmt->fetchColumn();

    if ($eventStatus === 'pending') {
        $update = $pdo->prepare(
            "UPDATE events SET event_status = 'cancelled'
             WHERE event_id = ? AND department_id = ? AND event_status = 'pending'"
        );
        $update->execute([$eventId, $departmentId]);
        $notice = 'cancelled';
    } elseif ($eventStatus === 'approved') {
        if ($remarks === '' || dhCharacterLength($remarks) > 1000) {
            $pdo->rollBack();
            http_response_code(400);
            exit('Provide a cancellation reason of no more than 1,000 characters.');
        }
        $update = $pdo->prepare(
            "UPDATE events
             SET event_status = 'cancellation_requested',
                 cancellation_requested_at = NOW(),
                 cancellation_requested_by = ?,
                 cancellation_request_remarks = ?
             WHERE event_id = ? AND department_id = ? AND event_status = 'approved'"
        );
        $update->execute([$departmentHeadId, $remarks, $eventId, $departmentId]);
        $notice = 'cancellation_requested';
    } else {
        $pdo->rollBack();
        http_response_code(409);
        exit('This event cannot be cancelled. It may already be closed or may not belong to your department.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

header('Location: ' . $baseUrl . '/department-head/events/index.php?notice=' . $notice);
exit;