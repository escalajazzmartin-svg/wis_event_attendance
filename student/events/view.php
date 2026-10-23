<?php

require_once __DIR__ . '/../../includes/student_context.php';

$studentPageTitle = 'Event Details';
$studentPageCss = 'student-events.css';

require_once __DIR__ . '/../../includes/student_layout_start.php';

$eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($eventId === false || $eventId < 1) {
    http_response_code(400);
    exit('A valid event was not specified.');
}

$stmt = $pdo->prepare(
    "SELECT e.event_id, e.event_title, e.event_description, e.venue,
            e.start_at, e.end_at, e.event_status, e.updated_at,
            e.admin_remarks, e.cancellation_request_remarks,
            e.cancellation_requested_at,
            ap.academic_year, ap.semester,
            settings.present_until, settings.late_until, settings.absent_after
     FROM events e
     INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
     LEFT JOIN attendance_settings settings ON settings.event_id = e.event_id
     WHERE e.event_id = ? AND e.department_id = ?
       AND e.event_status IN ('approved', 'cancellation_requested', 'cancelled', 'completed')
       AND " . studentParticipantCondition('e') . "
     LIMIT 1"
);
$stmt->execute(array_merge(
    [$eventId, $studentDepartmentId],
    studentParticipantParams($studentProfile)
));
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(404);
    exit('Event not found among the events available to you.');
}

$attendanceStmt = $pdo->prepare(
    'SELECT attendance_id, attendance_status, check_in_at, remarks
     FROM event_attendance
     WHERE event_id = ? AND student_id = ?
     LIMIT 1'
);
$attendanceStmt->execute([$eventId, $studentId]);
$attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC);
$status = $event['event_status'];
if ($status === 'approved') {
    $now = time();
    $start = strtotime($event['start_at']);
    $end = strtotime($event['end_at']);
    $phase = $start > $now ? 'Upcoming' : ($end >= $now ? 'Ongoing' : 'Past');
} else {
    $phase = ucwords(str_replace('_', ' ', $status));
}
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading">
        <div>
            <h2><?= studentEscape($event['event_title']) ?></h2>
            <p>Event schedule and attendance requirements.</p>
        </div>
        <a class="management-link" href="<?= studentEscape($studentBaseUrl) ?>/student/events/index.php">Back to schedules</a>
    </div>
    <article class="student-content-panel student-event-detail">
        <div class="student-schedule-heading">
            <span class="status-pill status-<?= studentEscape($status) ?>"><?= studentEscape($phase) ?></span>
            <span><?= studentEscape($event['academic_year']) ?> · <?= studentEscape($event['semester']) ?></span>
        </div>
        <dl class="student-detail-grid">
            <div>
                <dt>Starts</dt>
                <dd><?= studentEscape(studentDateTime($event['start_at'])) ?></dd>
            </div>
            <div>
                <dt>Ends</dt>
                <dd><?= studentEscape(studentDateTime($event['end_at'])) ?></dd>
            </div>
            <div>
                <dt>Venue</dt>
                <dd><?= studentEscape($event['venue'] ?: 'To be announced') ?></dd>
            </div>
            <div>
                <dt>Academic period</dt>
                <dd><?= studentEscape($event['academic_year']) ?> · <?= studentEscape($event['semester']) ?></dd>
            </div>
        </dl>
        <section class="student-detail-section">
            <h3>Description</h3>
            <p><?= nl2br(studentEscape($event['event_description'] ?: 'No event description provided.')) ?></p>
        </section>
        <section class="student-detail-section">
            <h3>Attendance requirements</h3>
            <?php if ($event['present_until'] && $event['late_until'] && $event['absent_after']): ?>
                <ul>
                    <li>Present cutoff: <?= studentEscape(studentDateTime($event['present_until'])) ?></li>
                    <li>Late cutoff: <?= studentEscape(studentDateTime($event['late_until'])) ?></li>
                    <li>Absent cutoff / attendance closing: <?= studentEscape(studentDateTime($event['absent_after'])) ?></li>
                </ul>
            <?php else: ?>
                <p>Attendance time rules have not been published for this event. Present your barcode at an authorized attendance station during the event.</p>
            <?php endif; ?>
        </section>
        <?php if ($event['event_status'] === 'cancellation_requested'): ?>
            <section class="student-detail-section student-event-notice">
                <h3>Schedule change notice</h3>
                <p>A cancellation request is under administrator review. <?= nl2br(studentEscape($event['cancellation_request_remarks'] ?: '')) ?></p>
            </section>
        <?php endif; ?>
        <?php if ($event['event_status'] === 'cancelled'): ?>
            <section class="student-detail-section student-event-notice">
                <h3>Event cancelled</h3>
                <p><?= nl2br(studentEscape($event['admin_remarks'] ?: 'This event has been cancelled.')) ?></p>
            </section>
        <?php elseif ($event['admin_remarks']): ?>
            <section class="student-detail-section">
                <h3>Schedule update</h3>
                <p><?= nl2br(studentEscape($event['admin_remarks'])) ?></p><small>Updated <?= studentEscape(studentDateTime($event['updated_at'])) ?></small>
            </section>
        <?php endif; ?>
        <?php if ($attendance): ?>
            <section class="student-detail-section">
                <h3>Your attendance</h3>
                <p><span class="status-pill status-<?= studentEscape($attendance['attendance_status']) ?>"><?= studentEscape(ucfirst($attendance['attendance_status'])) ?></span>
                    <?php if ($attendance['check_in_at']): ?> · recorded <?= studentEscape(studentDateTime($attendance['check_in_at'])) ?><?php endif; ?>
                </p>
                <?php if ($attendance['remarks']): ?><p><?= studentEscape($attendance['remarks']) ?></p><?php endif; ?>
                <a class="management-link" href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/create.php?attendance_id=<?= (int) $attendance['attendance_id'] ?>">Appeal this record</a>
            </section>
        <?php endif; ?>
    </article>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>

</html>