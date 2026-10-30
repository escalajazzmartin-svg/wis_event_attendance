<?php

$adminPageTitle = 'Event Request Review';
$adminPageCss = 'admin-event-view.css';
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

$statusLabel = ucwords(str_replace('_', ' ', $status));
$venueLabel = $event['venue'] ?: 'Not specified';
$periodLabel = $event['academic_year'] . ' · ' . $event['semester'];
$startLabel = date('M j, Y · g:i A', strtotime($event['start_at']));
$endLabel = date('M j, Y · g:i A', strtotime($event['end_at']));
$adminRemarks = trim((string) $event['admin_remarks']);
$adminCancelReason = '';
$cancelMarker = 'Administrator cancellation: ';
if ($status === 'cancelled' && ($markerAt = strrpos($adminRemarks, $cancelMarker)) !== false) {
    $adminCancelReason = trim(substr($adminRemarks, $markerAt + strlen($cancelMarker)));
    $adminRemarks = trim(substr($adminRemarks, 0, $markerAt));
}
$exportHref = $adminBaseUrl . '/admin/events/export.php?' . http_build_query(['event_id' => $eventId, 'academic_period_id' => 'all']);
$infoCell = static function (string $icon, string $label, string $valueHtml, string $id = ''): string {
    return '<div class="ev-cell"' . ($id !== '' ? ' id="' . $id . '"' : '') . '><span class="ev-icon">' . adminIcon($icon) . '</span><div><span class="ev-label">'
        . adminEscape($label) . '</span><div class="ev-value">' . $valueHtml . '</div></div></div>';
};
$dash = '—';
$rosterStmt = $pdo->prepare(
    'SELECT r.student_number, r.first_name, r.middle_name, r.last_name, r.year_level, r.section,
            ea.attendance_status
     FROM event_attendance_roster r
     LEFT JOIN event_attendance ea ON ea.event_id = r.event_id AND ea.student_id = r.student_id
     WHERE r.event_id = ?
     ORDER BY r.last_name, r.first_name, r.student_id
     LIMIT 500'
);
$rosterStmt->execute([$eventId]);
$roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);
$reportHref = $adminBaseUrl . '/admin/reports/index.php?' . http_build_query([
    'report' => 'attendance',
    'department_id' => (int) $event['department_id'],
    'academic_year' => $event['academic_year'],
    'semester' => $event['semester'],
]);
$attendanceHref = $adminBaseUrl . '/admin/attendance/index.php?' . http_build_query(['event_id' => $eventId]);
?>

<section class="ev-view">
    <a class="ev-back" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php"><?= adminIcon('arrow-left') ?>Back to events</a>

    <header class="ev-head">
        <div>
            <h1><?= adminEscape($event['event_title']) ?></h1>
            <p><?= adminEscape($event['department_name']) ?><?= $headName !== '' ? ' · ' . adminEscape($headName) : '' ?></p>
        </div>
        <div class="ev-head-actions">
            <a class="ev-btn" href="<?= adminEscape($exportHref) ?>"><?= adminIcon('download') ?>Export CSV</a>
            <?php if ($status === 'approved'): ?>
                <a class="ev-btn ev-btn-solid" href="#cancel-remarks"><?= adminIcon('calendar-check') ?>Cancel Event</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="ev-grid">
        <div class="ev-main">
            <section class="ev-card ev-overview">
                <div class="ev-banner">
                    <span class="ev-banner-icon"><?= adminIcon('calendar-check') ?></span>
                    <div>
                        <span class="status-pill status-<?= adminEscape($status) ?>"><?= adminEscape($statusLabel) ?></span>
                        <span class="ev-banner-label">Venue</span>
                        <span class="ev-banner-venue"><?= adminIcon('pin') ?><?= adminEscape($venueLabel) ?></span>
                    </div>
                </div>
                <div class="ev-times">
                    <?= $infoCell('calendar', 'Starts', adminEscape($startLabel)) ?>
                    <?= $infoCell('calendar', 'Ends', adminEscape($endLabel)) ?>
                    <?= $infoCell('cap', 'Academic period', adminEscape($periodLabel)) ?>
                </div>
                <div class="ev-detail-grid">
                    <?= $infoCell('file', 'Description', nl2br(adminEscape($event['event_description'] ?: 'No description provided.'))) ?>
                    <?= $infoCell('shield', 'Previous administrator remarks', $adminRemarks !== '' ? nl2br(adminEscape($adminRemarks)) : $dash) ?>
                    <?php
                    ob_start();
                    if ($participants === []) {
                        echo 'All department students';
                    } else {
                        echo '<ul>';
                        foreach ($participants as $participant) {
                            echo '<li>';
                            if ($participant['participant_type'] === 'student') {
                                echo adminEscape(trim($participant['first_name'] . ' ' . ($participant['middle_name'] ? $participant['middle_name'] . ' ' : '') . $participant['last_name'])) . ' · ' . adminEscape($participant['student_number']);
                            } elseif ($participant['participant_type'] === 'year_level') {
                                echo adminEscape($participant['year_level']) . ' year level';
                            } else {
                                echo adminEscape($participant['year_level']) . ' · ' . adminEscape($participant['section']);
                            }
                            echo '</li>';
                        }
                        echo '</ul>';
                    }
                    $participantsHtml = (string) ob_get_clean();
                    ?>
                    <?= $infoCell('users', 'Participants', $participantsHtml, 'participants') ?>
                    <?php if ($status === 'cancellation_requested'): ?>
                        <?= $infoCell('x-circle', 'Cancellation request reason', nl2br(adminEscape($event['cancellation_request_remarks'] ?: 'No reason was supplied.'))) ?>
                    <?php else: ?>
                        <?= $infoCell('x-circle', 'Administrator cancellation reason', $adminCancelReason !== '' ? nl2br(adminEscape($adminCancelReason)) : $dash) ?>
                    <?php endif; ?>
                </div>
            </section>


            <section class="ev-card ev-panel" id="registered-participants">
                <h2 class="ev-panel-title"><?= adminIcon('users') ?>Registered Participants <span class="ev-count"><?= count($roster) ?></span></h2>
                <?php if ($roster === []): ?>
                    <p class="ev-empty">No registered participants yet. The roster is created for the event's eligible students.</p>
                <?php else: ?>
                    <div class="ev-roster-scroll" tabindex="0" role="region" aria-label="Registered participants, scrollable">
                        <table class="ev-roster">
                            <thead><tr><th>Student</th><th>Student no.</th><th>Year &amp; section</th><th>Attendance</th></tr></thead>
                            <tbody>
                            <?php foreach ($roster as $row): ?>
                                <?php $rowStatus = $row['attendance_status'] ?: ''; ?>
                                <tr>
                                    <td><?= adminEscape(trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name'])) ?></td>
                                    <td><?= adminEscape($row['student_number']) ?></td>
                                    <td><?= adminEscape(trim(($row['year_level'] ?? '') . ' ' . ($row['section'] ?? '')) ?: '—') ?></td>
                                    <td><?php if ($rowStatus !== ''): ?><span class="admin-status-badge admin-status-<?= adminEscape($rowStatus) ?>"><?= adminEscape(ucfirst($rowStatus)) ?></span><?php else: ?><span class="ev-muted">Not recorded</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        <?php if ($changeRequest && $changePayload): ?>
            <?php $requestedValues = $changePayload['values']; ?>
            <section class="ev-card ev-panel admin-change-request">
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
            <section class="ev-card ev-panel" id="review">
                <h2 class="ev-panel-title"><?= adminIcon('shield') ?><?= $status === 'pending' ? 'Event Request Review' : 'Cancellation Request Review' ?></h2>
                <form class="ev-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/events/action.php">
                    <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                    <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                    <label class="visually-hidden" for="remarks">Administrator remarks (required)</label>
                    <textarea id="remarks" name="remarks" maxlength="2000" required rows="3" placeholder="Enter administrator remarks (required)..."></textarea>
                    <div class="ev-form-actions">
                        <button class="ev-submit ev-submit-approve" type="submit" name="decision" value="approved"><?= $status === 'pending' ? 'Approve event' : 'Approve cancellation' ?></button>
                        <button class="ev-submit ev-submit-danger" type="submit" name="decision" value="rejected"><?= $status === 'pending' ? 'Reject event' : 'Decline cancellation' ?></button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($status === 'approved'): ?>
            <section class="ev-card ev-panel">
                <h2 class="ev-panel-title"><?= adminIcon('x-circle') ?>Administrator Cancellation Reason</h2>
                <form class="ev-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/events/action.php" onsubmit="return confirm('Cancel this approved event now?');">
                    <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                    <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                    <label class="visually-hidden" for="cancel-remarks">Administrator cancellation reason</label>
                    <textarea id="cancel-remarks" name="remarks" maxlength="2000" required rows="3" placeholder="Enter reason for cancellation (required)..."></textarea>
                    <button class="ev-submit ev-submit-cancel" type="submit" name="decision" value="cancel"><?= adminIcon('x-circle') ?>Cancel event</button>
                </form>
            </section>
        <?php endif; ?>
        </div>

        <aside class="ev-side">
            <section class="ev-card ev-side-card">
                <h2>Event Details</h2>
                <dl class="ev-facts">
                    <div><span class="ev-icon"><?= adminIcon('file') ?></span><div><dt>Event Title</dt><dd><?= adminEscape($event['event_title']) ?></dd></div></div>
                    <div><span class="ev-icon"><?= adminIcon('building') ?></span><div><dt>Department</dt><dd class="ev-accent"><?= adminEscape($event['department_name']) ?></dd></div></div>
                    <div><span class="ev-icon"><?= adminIcon('pin') ?></span><div><dt>Venue</dt><dd><?= adminEscape($venueLabel) ?></dd></div></div>
                    <div><span class="ev-icon"><?= adminIcon('cap') ?></span><div><dt>Academic Period</dt><dd><?= adminEscape($periodLabel) ?></dd></div></div>
                    <div><span class="ev-icon"><?= adminIcon('calendar') ?></span><div><dt>Starts</dt><dd><?= adminEscape($startLabel) ?></dd></div></div>
                    <div><span class="ev-icon"><?= adminIcon('calendar') ?></span><div><dt>Ends</dt><dd><?= adminEscape($endLabel) ?></dd></div></div>
                    <div><span class="ev-icon"><?= adminIcon('check') ?></span><div><dt>Status</dt><dd><span class="status-pill status-<?= adminEscape($status) ?>"><?= adminEscape($statusLabel) ?></span></dd></div></div>
                </dl>
            </section>
            <section class="ev-card ev-side-card">
                <h2>Quick Actions</h2>
                <div class="ev-quick">
                    <a class="ev-quick-btn ev-quick-solid" href="#registered-participants"><?= adminIcon('calendar-check') ?>View Registered Participants</a>
                    <a class="ev-quick-btn" href="<?= adminEscape($attendanceHref) ?>"><?= adminIcon('user') ?>View Attendance Records</a>
                    <a class="ev-quick-btn" href="<?= adminEscape($reportHref) ?>"><?= adminIcon('file') ?>View Reports</a>
                </div>
            </section>
        </aside>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
