<?php

$pageTitle = 'Event Details';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($eventId === false || $eventId < 1) {
    http_response_code(400);
    exit('A valid event was not specified.');
}

$stmt = $pdo->prepare(
    'SELECT e.event_id, e.event_title, e.event_description, e.venue,
            e.start_at, e.end_at, e.event_status, e.admin_remarks,
            e.cancellation_request_remarks, e.cancellation_requested_at,
            e.created_at, e.updated_at, ap.academic_year, ap.semester
     FROM events e
     INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
     WHERE e.event_id = ? AND e.department_id = ?
     LIMIT 1'
);
$stmt->execute([$eventId, $departmentId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$eventStatus = strtolower($event['event_status']);
$participantStmt = $pdo->prepare(
    'SELECT participant_type, student_id, year_level, section
     FROM event_participants WHERE event_id = ?'
);
$participantStmt->execute([$eventId]);
$participants = $participantStmt->fetchAll(PDO::FETCH_ASSOC);
$changeStmt = $pdo->prepare(
    'SELECT request_status, requested_at, reviewed_at, reviewer_remarks
     FROM event_change_requests
     WHERE event_id = ?
     ORDER BY requested_at DESC'
);
$changeStmt->execute([$eventId]);
$changeRequests = $changeStmt->fetchAll(PDO::FETCH_ASSOC);
$hasPendingChange = false;
foreach ($changeRequests as $changeRequest) {
    if ($changeRequest['request_status'] === 'pending') {
        $hasPendingChange = true;
        break;
    }
}
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div>
            <h2><?= dhEscape($event['event_title']) ?></h2>
            <p>Event request details and administrator review.</p>
        </div>
        <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/events/index.php">Back to events</a>
    </div>

    <div class="management-detail department-panel">
        <div class="management-detail-heading">
            <span class="status-pill status-<?= dhEscape($eventStatus) ?>"><?= dhEscape(ucwords(str_replace('_', ' ', $eventStatus))) ?></span>
            <?php if (in_array($eventStatus, ['pending', 'rejected'], true) || ($eventStatus === 'approved' && !$hasPendingChange)): ?>
                <a class="management-button" href="<?= dhEscape($baseUrl) ?>/department-head/events/edit.php?id=<?= (int) $eventId ?>">Edit Event</a>
            <?php endif; ?>
        </div>

        <dl class="management-detail-grid">
            <div>
                <dt>Academic period</dt>
                <dd><?= dhEscape($event['academic_year']) ?> · <?= dhEscape($event['semester']) ?></dd>
            </div>
            <div>
                <dt>Venue</dt>
                <dd><?= dhEscape($event['venue'] ?: 'Not specified') ?></dd>
            </div>
            <div>
                <dt>Starts</dt>
                <dd><?= dhEscape(date('M j, Y · g:i A', strtotime($event['start_at']))) ?></dd>
            </div>
            <div>
                <dt>Ends</dt>
                <dd><?= dhEscape(date('M j, Y · g:i A', strtotime($event['end_at']))) ?></dd>
            </div>
            <div>
                <dt>Submitted</dt>
                <dd><?= dhEscape(date('M j, Y · g:i A', strtotime($event['created_at']))) ?></dd>
            </div>
            <div>
                <dt>Last updated</dt>
                <dd><?= dhEscape(date('M j, Y · g:i A', strtotime($event['updated_at']))) ?></dd>
            </div>
        </dl>

        <div class="management-detail-section">
            <h3>Description</h3>
            <p><?= nl2br(dhEscape($event['event_description'] ?: 'No description provided.')) ?></p>
        </div>

        <div class="management-detail-section">
            <h3>Participating students or groups</h3>
            <?php if ($participants === []): ?>
                <p>All students in the assigned department.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($participants as $participant): ?>
                        <?php if ($participant['participant_type'] === 'student'): ?>
                            <?php
                            $participantStudentStmt = $pdo->prepare(
                                'SELECT student_number, first_name, middle_name, last_name
                                 FROM students WHERE student_id = ? AND department_id = ?'
                            );
                            $participantStudentStmt->execute([$participant['student_id'], $departmentId]);
                            $participantStudent = $participantStudentStmt->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <?php if ($participantStudent): ?>
                                <li><?= dhEscape(trim(
                                    $participantStudent['first_name'] . ' ' .
                                    ($participantStudent['middle_name'] ? $participantStudent['middle_name'] . ' ' : '') .
                                    $participantStudent['last_name']
                                )) ?> · <?= dhEscape($participantStudent['student_number']) ?></li>
                            <?php endif; ?>
                        <?php elseif ($participant['participant_type'] === 'year_level'): ?>
                            <li><?= dhEscape($participant['year_level']) ?> year level</li>
                        <?php else: ?>
                            <li><?= dhEscape($participant['year_level']) ?> · <?= dhEscape($participant['section']) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($eventStatus === 'approved'): ?>
            <?php
            $now = time();
            $eventPhase = $now < strtotime($event['start_at'])
                ? 'Upcoming'
                : ($now <= strtotime($event['end_at']) ? 'Ongoing' : 'Completed');
            ?>
            <div class="management-detail-section">
                <h3>Schedule</h3>
                <p><?= dhEscape($eventPhase) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($event['cancellation_request_remarks'])): ?>
            <div class="management-detail-section">
                <h3>Cancellation request history</h3>
                <p><?= nl2br(dhEscape($event['cancellation_request_remarks'] ?: 'No reason provided.')) ?></p>
                <?php if ($event['cancellation_requested_at']): ?>
                    <small>Submitted <?= dhEscape(date('M j, Y · g:i A', strtotime($event['cancellation_requested_at']))) ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($event['admin_remarks'])): ?>
            <div class="management-detail-section">
                <h3>Administrator remarks</h3>
                <p><?= nl2br(dhEscape($event['admin_remarks'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($changeRequests !== []): ?>
            <div class="management-detail-section">
                <h3>Schedule change request history</h3>
                <?php foreach ($changeRequests as $changeRequest): ?>
                    <p>
                        <?= dhEscape(ucfirst($changeRequest['request_status'])) ?> ·
                        <?= dhEscape(date('M j, Y · g:i A', strtotime($changeRequest['requested_at']))) ?>
                        <?php if ($changeRequest['reviewed_at']): ?>
                            · reviewed <?= dhEscape(date('M j, Y · g:i A', strtotime($changeRequest['reviewed_at']))) ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($changeRequest['reviewer_remarks']): ?>
                        <small><?= nl2br(dhEscape($changeRequest['reviewer_remarks'])) ?></small>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (in_array($eventStatus, ['pending', 'approved'], true)): ?>
            <form class="management-form-actions" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/events/cancel.php">
                <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                <?php if ($eventStatus === 'approved'): ?>
                    <label for="cancellation-remarks">Reason for cancellation</label>
                    <textarea id="cancellation-remarks" name="remarks" maxlength="1000" required></textarea>
                    <button class="management-button management-button-danger" type="submit">Request Cancellation</button>
                <?php else: ?>
                    <button class="management-button management-button-danger" type="submit">Withdraw Request</button>
                <?php endif; ?>
            </form>
        <?php elseif ($eventStatus === 'rejected'): ?>
            <form class="management-form-actions" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/events/request.php">
                <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                <button class="management-button" type="submit">Resubmit for Review</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>