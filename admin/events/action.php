<?php

require_once __DIR__ . '/../../includes/admin_context.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
$token = $_POST['csrf_token'] ?? null;
if (!adminVerifyCsrf($token)) {
    http_response_code(400);
    exit('Invalid request token. Refresh the event and try again.');
}

$eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
$decision = $_POST['decision'] ?? '';
$remarks = trim(is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : '');
if (
    $eventId === false || $eventId < 1 ||
    !in_array($decision, ['approved', 'rejected', 'cancel'], true) ||
    $remarks === '' || strlen($remarks) > 2000
) {
    http_response_code(400);
    exit('Provide a valid event decision and administrator remarks.');
}

try {
    $pdo->beginTransaction();
    $eventStmt = $pdo->prepare(
        'SELECT event_status, admin_remarks
         FROM events WHERE event_id = ? FOR UPDATE'
    );
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event || (
        !in_array($event['event_status'], ['pending', 'cancellation_requested'], true)
        && !($decision === 'cancel' && $event['event_status'] === 'approved')
    )) {
        $pdo->rollBack();
        http_response_code($event ? 409 : 404);
        exit($event ? 'This event is not in a state that permits this action.' : 'Event not found.');
    }

    $wasCancellation = $event['event_status'] === 'cancellation_requested';
    $newStatus = $decision === 'cancel'
        ? 'cancelled'
        : ($wasCancellation
            ? ($decision === 'approved' ? 'cancelled' : 'approved')
            : $decision);
    $newRemarks = trim(
        ($event['admin_remarks'] ? $event['admin_remarks'] . "\n\n" : '') .
        ($decision === 'cancel' ? 'Administrator cancellation: ' : ($wasCancellation ? 'Cancellation review: ' : '')) .
        $remarks
    );
    $update = $pdo->prepare(
        'UPDATE events
         SET event_status = ?, admin_remarks = ?,
             admin_reviewed_by = ?, admin_reviewed_at = NOW()
         WHERE event_id = ?'
    );
    $update->execute([$newStatus, $newRemarks, $_SESSION['user_id'], $eventId]);
    if ($newStatus === 'approved') {
        snapshotEventAttendanceRoster($pdo, (int) $eventId);
    }
    if ($newStatus === 'cancelled') {
        $pdo->prepare(
            "UPDATE event_change_requests
             SET request_status = 'rejected', reviewed_by_user_id = ?, reviewed_at = NOW(),
                 reviewer_remarks = 'Event was cancelled before the requested changes could be applied.'
             WHERE event_id = ? AND request_status = 'pending'"
        )->execute([(int) $_SESSION['user_id'], $eventId]);
    }
    adminLogActivity($pdo, $decision === 'cancel' ? 'cancelled event' : ($wasCancellation ? 'reviewed cancellation request' : 'reviewed event request'), 'event', (int) $eventId, $decision . ': ' . $remarks);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$notice = $wasCancellation
    ? ($decision === 'approved' ? 'cancellation_approved' : 'cancellation_rejected')
    : ($decision === 'cancel' ? 'cancelled' : ($decision === 'approved' ? 'approved' : 'rejected'));
header('Location: ' . BASE_URL . '/admin/events/index.php?notice=' . $notice);
exit;
