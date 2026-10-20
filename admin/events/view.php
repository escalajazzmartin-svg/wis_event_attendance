<?php

$adminPageTitle = 'Event Request Review';
require_once __DIR__ . '/../../includes/admin_layout_start.php';

$eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($eventId === false || $eventId < 1) {
    http_response_code(400);
    exit('A valid event was not specified.');
}
$stmt = $pdo->prepare(
    'SELECT e.*, d.department_name, ap.academic_year, ap.semester,
            dh.first_name, dh.middle_name, dh.last_name
     FROM events e
     INNER JOIN departments d ON d.department_id = e.department_id
     INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
     LEFT JOIN department_heads dh ON dh.department_head_id = e.requested_by
     WHERE e.event_id = ?'
);
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}
$participantStmt = $pdo->prepare(
    'SELECT ep.participant_type, ep.year_level, ep.section,
            s.student_number, s.first_name, s.middle_name, s.last_name
     FROM event_participants ep
     LEFT JOIN students s ON s.student_id = ep.student_id
     WHERE ep.event_id = ?
     ORDER BY ep.participant_type, ep.year_level, ep.section, s.last_name'
);
$participantStmt->execute([$eventId]);
$participants = $participantStmt->fetchAll(PDO::FETCH_ASSOC);
$changeRequestId = filter_var($_GET['change_request_id'] ?? null, FILTER_VALIDATE_INT);
$changeRequest = null;
$changePayload = null;
if ($changeRequestId !== false && $changeRequestId > 0) {
    $changeStmt = $pdo->prepare(
        'SELECT cr.change_request_id, cr.request_status, cr.requested_at,
                cr.request_payload, cr.reviewer_remarks,
                CONCAT_WS(\' \', dh.first_name, dh.middle_name, dh.last_name) AS requester
         FROM event_change_requests cr
         INNER JOIN department_heads dh
            ON dh.department_head_id = cr.requested_by_department_head_id
         WHERE cr.change_request_id = ? AND cr.event_id = ?'
    );
    $changeStmt->execute([$changeRequestId, $eventId]);
    $changeRequest = $changeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$changeRequest) {
        http_response_code(404);
        exit('Event change request not found.');
    }
    $changePayload = json_decode($changeRequest['request_payload'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($changePayload) || !isset($changePayload['values']) || !is_array($changePayload['values'])) {
        throw new UnexpectedValueException('The stored event change request is invalid.');
    }
    $requestedPeriodStmt = $pdo->prepare(
        'SELECT academic_year, semester FROM academic_periods WHERE academic_period_id = ?'
    );
    $requestedPeriodStmt->execute([(int) ($changePayload['values']['academic_period_id'] ?? 0)]);
    $requestedPeriod = $requestedPeriodStmt->fetch(PDO::FETCH_ASSOC);
    if (!$requestedPeriod) {
        throw new UnexpectedValueException('The requested academic period no longer exists.');
    }
    $requestedParticipants = $changePayload['participants'] ?? [];
    if (!is_array($requestedParticipants)) {
        throw new UnexpectedValueException('The stored event participant changes are invalid.');
    }
    $requestedStudentIds = [];
    foreach ($requestedParticipants as $requestedParticipant) {
        if (is_array($requestedParticipant) && ($requestedParticipant['participant_type'] ?? '') === 'student') {
            $requestedStudentIds[] = (int) ($requestedParticipant['student_id'] ?? 0);
        }
    }
    $requestedStudents = [];
    if ($requestedStudentIds !== []) {
        $studentPlaceholders = implode(',', array_fill(0, count($requestedStudentIds), '?'));
        $requestedStudentsStmt = $pdo->prepare(
            "SELECT student_id, student_number, first_name, last_name
             FROM students WHERE department_id = ? AND student_id IN ($studentPlaceholders)"
        );
            $requestedStudentsStmt->execute(array_merge([(int) $event['department_id']], $requestedStudentIds));
        foreach ($requestedStudentsStmt->fetchAll(PDO::FETCH_ASSOC) as $requestedStudent) {
            $requestedStudents[(int) $requestedStudent['student_id']] = $requestedStudent;
        }
    }
}
$status = $event['event_status'];
$headName = trim($event['first_name'] . ' ' . ($event['middle_name'] ? $event['middle_name'] . ' ' : '') . $event['last_name']);
?>

<section class="admin-content-section">
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">Event review</span><h2><?= adminEscape($event['event_title']) ?></h2><p><?= adminEscape($event['department_name']) ?> · <?= adminEscape($headName) ?></p></div>
        <a class="admin-action-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php">Back to events</a>
    </div>
    <div class="admin-panel">
        <p><span class="status-pill status-<?= adminEscape($status) ?>"><?= adminEscape(ucwords(str_replace('_', ' ', $status))) ?></span></p>
        <dl class="admin-event-details">
            <div><dt>Venue</dt><dd><?= adminEscape($event['venue'] ?: 'Not specified') ?></dd></div>
            <div><dt>Academic period</dt><dd><?= adminEscape($event['academic_year']) ?> · <?= adminEscape($event['semester']) ?></dd></div>
            <div><dt>Starts</dt><dd><?= adminEscape(date('M j, Y · g:i A', strtotime($event['start_at']))) ?></dd></div>
            <div><dt>Ends</dt><dd><?= adminEscape(date('M j, Y · g:i A', strtotime($event['end_at']))) ?></dd></div>
            <div class="admin-event-wide"><dt>Description</dt><dd><?= nl2br(adminEscape($event['event_description'] ?: 'No description provided.')) ?></dd></div>
            <div class="admin-event-wide"><dt>Participants</dt><dd>
                <?php if ($participants === []): ?>All department students<?php else: ?>
                    <ul>
                        <?php foreach ($participants as $participant): ?>
                            <li>
                                <?php if ($participant['participant_type'] === 'student'): ?>
                                    <?= adminEscape(trim($participant['first_name'] . ' ' . ($participant['middle_name'] ? $participant['middle_name'] . ' ' : '') . $participant['last_name'])) ?> · <?= adminEscape($participant['student_number']) ?>
                                <?php elseif ($participant['participant_type'] === 'year_level'): ?>
                                    <?= adminEscape($participant['year_level']) ?> year level
                                <?php else: ?>
                                    <?= adminEscape($participant['year_level']) ?> · <?= adminEscape($participant['section']) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </dd></div>
            <?php if ($event['admin_remarks']): ?><div class="admin-event-wide"><dt>Previous administrator remarks</dt><dd><?= nl2br(adminEscape($event['admin_remarks'])) ?></dd></div><?php endif; ?>
            <?php if ($status === 'cancellation_requested'): ?>
                <div class="admin-event-wide"><dt>Cancellation reason</dt><dd><?= nl2br(adminEscape($event['cancellation_request_remarks'] ?: 'No reason was supplied.')) ?></dd></div>
            <?php endif; ?>
        </dl>

        <?php if ($changeRequest && $changePayload): ?>
            <?php $requestedValues = $changePayload['values']; ?>
            <section class="admin-change-request">
                <h3>Requested schedule changes</h3>
                <p>Submitted by <?= adminEscape($changeRequest['requester']) ?> on <?= adminEscape(date('M j, Y · g:i A', strtotime($changeRequest['requested_at']))) ?> · <?= adminEscape(ucfirst($changeRequest['request_status'])) ?></p>
                <?php if (is_string($changePayload['reason'] ?? null) && $changePayload['reason'] !== ''): ?><p><strong>Department Head reason:</strong> <?= nl2br(adminEscape($changePayload['reason'])) ?></p><?php endif; ?>
                <dl class="admin-event-details">
                    <div><dt>Title</dt><dd><?= adminEscape($requestedValues['event_title'] ?? '') ?></dd></div>
                    <div><dt>Venue</dt><dd><?= adminEscape($requestedValues['venue'] ?? 'Not specified') ?></dd></div>
                    <div><dt>Starts</dt><dd><?= adminEscape(date('M j, Y · g:i A', strtotime($requestedValues['start_at'] ?? ''))) ?></dd></div>
                    <div><dt>Ends</dt><dd><?= adminEscape(date('M j, Y · g:i A', strtotime($requestedValues['end_at'] ?? ''))) ?></dd></div>
                    <div class="admin-event-wide"><dt>Description</dt><dd><?= nl2br(adminEscape($requestedValues['event_description'] ?? '')) ?></dd></div>
                    <div><dt>Academic period</dt><dd><?= adminEscape($requestedPeriod['academic_year'] . ' · ' . $requestedPeriod['semester']) ?></dd></div>
                    <div class="admin-event-wide"><dt>Proposed participants</dt><dd>
                        <?php if ($requestedParticipants === []): ?>All students in the department<?php else: ?>
                            <ul><?php foreach ($requestedParticipants as $requestedParticipant): ?>
                                <?php if (!is_array($requestedParticipant)): ?><li>Invalid participant data</li>
                                <?php elseif (($requestedParticipant['participant_type'] ?? '') === 'student'): ?>
                                    <?php $requestedStudent = $requestedStudents[(int) ($requestedParticipant['student_id'] ?? 0)] ?? null; ?>
                                    <li><?= adminEscape($requestedStudent ? $requestedStudent['first_name'] . ' ' . $requestedStudent['last_name'] . ' · ' . $requestedStudent['student_number'] : 'Student no longer available') ?></li>
                                <?php elseif (($requestedParticipant['participant_type'] ?? '') === 'year_level'): ?>
                                    <li><?= adminEscape($requestedParticipant['year_level'] ?? '') ?> year level</li>
                                <?php elseif (($requestedParticipant['participant_type'] ?? '') === 'section'): ?>
                                    <li><?= adminEscape(($requestedParticipant['year_level'] ?? '') . ' · ' . ($requestedParticipant['section'] ?? '')) ?></li>
                                <?php else: ?><li>Invalid participant type</li><?php endif; ?>
                            <?php endforeach; ?></ul>
                        <?php endif; ?>
                    </dd></div>
                </dl>
                <?php if ($changeRequest['reviewer_remarks']): ?><p><strong>Review remarks:</strong> <?= nl2br(adminEscape($changeRequest['reviewer_remarks'])) ?></p><?php endif; ?>
                <?php if ($changeRequest['request_status'] === 'pending'): ?>
                    <form class="admin-event-review-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/events/change-action.php">
                        <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                        <input type="hidden" name="change_request_id" value="<?= (int) $changeRequest['change_request_id'] ?>">
                        <label for="change-review-remarks">Administrator review remarks (required)</label>
                        <textarea id="change-review-remarks" name="remarks" maxlength="2000" required rows="4"></textarea>
                        <div>
                            <button class="admin-event-action" type="submit" name="decision" value="approved">Approve changes</button>
                            <button class="admin-event-action admin-event-action-secondary" type="submit" name="decision" value="rejected">Reject changes</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (in_array($status, ['pending', 'cancellation_requested'], true)): ?>
            <form class="admin-event-review-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/events/action.php">
                <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                <label for="remarks">Administrator remarks (required)</label>
                <textarea id="remarks" name="remarks" maxlength="2000" required rows="4"></textarea>
                <div>
                    <button class="admin-event-action" type="submit" name="decision" value="approved">
                        <?= $status === 'pending' ? 'Approve event' : 'Approve cancellation' ?>
                    </button>
                    <button class="admin-event-action admin-event-action-secondary" type="submit" name="decision" value="rejected">
                        <?= $status === 'pending' ? 'Reject event' : 'Decline cancellation' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
        <?php if ($status === 'approved'): ?>
            <form class="admin-event-review-form admin-direct-cancel-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/events/action.php" onsubmit="return confirm('Cancel this approved event now?');">
                <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                <label for="cancel-remarks">Administrator cancellation reason</label>
                <textarea id="cancel-remarks" name="remarks" maxlength="2000" required rows="3"></textarea>
                <button class="admin-event-action admin-event-action-secondary" type="submit" name="decision" value="cancel">Cancel event</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
