<?php

$studentPageTitle = 'Student Dashboard';
require_once __DIR__ . '/../includes/student_layout_start.php';

$participantParams = studentParticipantParams($studentProfile);
$studentEventCondition = studentParticipantCondition('e');

$eventStmt = $pdo->prepare(
    "SELECT e.event_id, e.event_title, e.event_description, e.venue,
            e.start_at, e.end_at, e.event_status, e.updated_at, e.created_at,
            e.admin_remarks, e.cancellation_request_remarks,
            ap.academic_year, ap.semester
     FROM events e
     INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
     WHERE e.department_id = ?
       AND e.event_status IN ('approved', 'cancellation_requested', 'cancelled', 'completed')
       AND $studentEventCondition
     ORDER BY e.start_at ASC, e.event_id DESC"
);
$eventStmt->execute(array_merge([$studentDepartmentId], $participantParams));
$events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
$now = time();
$upcomingEvents = [];
$ongoingEvents = [];
$recentEvents = [];
$announcements = [];
foreach ($events as $event) {
    $start = strtotime($event['start_at']);
    $end = strtotime($event['end_at']);
    if (
        $event['event_status'] === 'approved' &&
        $event['admin_remarks'] &&
        strtotime($event['updated_at']) > strtotime($event['created_at'])
    ) {
        $announcements[] = $event;
    }
    if ($event['event_status'] === 'approved' && $start !== false && $end !== false) {
        if ($start > $now) {
            $upcomingEvents[] = $event;
        } elseif ($end >= $now) {
            $ongoingEvents[] = $event;
        } else {
            $recentEvents[] = $event;
        }
    } else {
        $announcements[] = $event;
    }
}
$upcomingEvents = array_slice($upcomingEvents, 0, 4);
$ongoingEvents = array_slice($ongoingEvents, 0, 3);
$recentEvents = array_slice(array_reverse($recentEvents), 0, 3);
usort($announcements, static fn(array $left, array $right): int => strcmp($right['updated_at'], $left['updated_at']));
$announcements = array_slice($announcements, 0, 4);

$attendanceStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
            COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
            COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
            COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE ea.student_id = ? AND e.department_id = ?"
);
$attendanceStmt->execute([$studentId, $studentDepartmentId]);
$attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC);
$attendanceTotal = (int) $attendance['total'];
$attended = (int) $attendance['present'] + (int) $attendance['late'] + (int) $attendance['excused'];
$unrecordedMissedStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM events e
     WHERE e.department_id = ?
       AND (e.event_status = 'completed' OR (e.event_status = 'approved' AND e.end_at < NOW()))
       AND " . studentParticipantCondition('e') . "
       AND NOT EXISTS (
           SELECT 1 FROM event_attendance ea
           WHERE ea.event_id = e.event_id AND ea.student_id = ?
       )"
);
$unrecordedMissedStmt->execute(array_merge(
    [$studentDepartmentId],
    $participantParams,
    [$studentId]
));
$unrecordedMissed = (int) $unrecordedMissedStmt->fetchColumn();
$missed = (int) $attendance['absent'] + $unrecordedMissed;
$eligiblePastTotal = $attendanceTotal + $unrecordedMissed;
$attendanceRate = $eligiblePastTotal > 0 ? (int) round(($attended / $eligiblePastTotal) * 100) : 0;

$appealStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(aa.appeal_status = 'pending'), 0) AS pending,
            COALESCE(SUM(aa.appeal_status IN ('approved', 'rejected', 'withdrawn')), 0) AS resolved
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE aa.student_id = ? AND e.department_id = ?"
);
$appealStmt->execute([$studentId, $studentDepartmentId]);
$appealStats = $appealStmt->fetch(PDO::FETCH_ASSOC);

$studentName = studentName($studentProfile);
?>

<section class="dashboard-section student-dashboard-welcome">
    <div class="section-heading">
        <div>
            <span class="student-eyebrow"><?= studentEscape($studentProfile['department_name']) ?></span>
            <h2>Welcome, <?= studentEscape($studentName) ?></h2>
            <p>Stay up to date with your events, attendance, and appeals.</p>
        </div>
        <a class="management-button" href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/index.php">View My Barcode</a>
    </div>
</section>

<section class="dashboard-section">
    <div class="student-summary-grid">
        <article class="student-summary-card"><span>Attendance rate</span><strong><?= $attendanceRate ?>%</strong><small><?= $attended ?> attended / excused out of <?= $attendanceTotal ?> recorded</small></article>
        <article class="student-summary-card"><span>Events attended</span><strong><?= $attended ?></strong><small>Present, Late, or Excused</small></article>
        <article class="student-summary-card"><span>Events missed</span><strong><?= $missed ?></strong><small>Marked absent</small></article>
        <article class="student-summary-card"><span>Appeals</span><strong><?= (int) $appealStats['pending'] ?> pending</strong><small><?= (int) $appealStats['resolved'] ?> resolved</small></article>
    </div>
</section>

<?php if ($announcements !== []): ?>
    <section class="dashboard-section">
        <div class="section-heading"><div><h2>Event Announcements and Changes</h2><p>Recent cancellations and event status updates relevant to you.</p></div></div>
        <div class="student-announcement-list">
            <?php foreach ($announcements as $event): ?>
                <article class="student-announcement">
                    <span class="status-pill status-<?= studentEscape($event['event_status']) ?>"><?= studentEscape(ucwords(str_replace('_', ' ', $event['event_status']))) ?></span>
                    <div>
                        <a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $event['event_id'] ?>"><?= studentEscape($event['event_title']) ?></a>
                        <p>
                            <?php if ($event['event_status'] === 'cancellation_requested'): ?>
                                A cancellation request is awaiting review.
                                <?= $event['cancellation_request_remarks'] ? studentEscape($event['cancellation_request_remarks']) : '' ?>
                            <?php elseif ($event['admin_remarks']): ?>
                                <?= $event['event_status'] === 'approved' ? 'Schedule update: ' : '' ?><?= studentEscape($event['admin_remarks']) ?>
                            <?php else: ?>
                                Event status changed on <?= studentEscape(studentDateTime($event['updated_at'])) ?>.
                            <?php endif; ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="student-dashboard-columns">
    <div>
        <section class="dashboard-section student-content-panel">
            <div class="section-heading"><div><h2>Upcoming Events</h2><p>Events scheduled for your department or group.</p></div><a class="text-link" href="<?= studentEscape($studentBaseUrl) ?>/student/events/index.php">All events →</a></div>
            <?php if ($upcomingEvents === []): ?>
                <div class="empty-state"><strong>No upcoming events</strong><p>Relevant approved events will appear here.</p></div>
            <?php else: ?>
                <div class="student-event-list">
                    <?php foreach ($upcomingEvents as $event): ?>
                        <article class="student-event-card">
                            <div><span class="student-event-date"><?= studentEscape(studentDateTime($event['start_at'], 'M j · g:i A')) ?></span><h3><a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $event['event_id'] ?>"><?= studentEscape($event['event_title']) ?></a></h3><p><?= studentEscape($event['venue'] ?: 'Venue to be announced') ?> · <?= studentEscape($event['academic_year']) ?> / <?= studentEscape($event['semester']) ?></p></div>
                            <span class="status-pill status-approved">Upcoming</span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="dashboard-section student-content-panel">
            <div class="section-heading"><div><h2>Current Events</h2><p>Relevant events in progress.</p></div></div>
            <?php if ($ongoingEvents === []): ?>
                <p class="student-muted">No events are currently in progress.</p>
            <?php else: ?>
                <div class="student-event-list">
                    <?php foreach ($ongoingEvents as $event): ?>
                        <article class="student-event-card"><div><span class="student-event-date"><?= studentEscape(studentDateTime($event['start_at'], 'M j · g:i A')) ?> – <?= studentEscape(studentDateTime($event['end_at'], 'g:i A')) ?></span><h3><a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $event['event_id'] ?>"><?= studentEscape($event['event_title']) ?></a></h3><p><?= studentEscape($event['venue'] ?: 'Venue to be announced') ?></p></div><span class="status-pill status-approved">Ongoing</span></article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="dashboard-section student-content-panel">
            <div class="section-heading"><div><h2>Recently Completed</h2><p>Your recent event attendance status.</p></div><a class="text-link" href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/history.php">Attendance history →</a></div>
            <?php if ($recentEvents === []): ?>
                <p class="student-muted">No completed events to show yet.</p>
            <?php else: ?>
                <div class="student-event-list">
                    <?php foreach ($recentEvents as $event): ?>
                        <?php
                        $eventAttendanceStmt = $pdo->prepare('SELECT attendance_status FROM event_attendance WHERE event_id = ? AND student_id = ?');
                        $eventAttendanceStmt->execute([$event['event_id'], $studentId]);
                        $eventAttendanceStatus = $eventAttendanceStmt->fetchColumn();
                        ?>
                        <article class="student-event-card"><div><span class="student-event-date"><?= studentEscape(studentDateTime($event['start_at'], 'M j, Y')) ?></span><h3><a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $event['event_id'] ?>"><?= studentEscape($event['event_title']) ?></a></h3></div><span class="status-pill status-<?= studentEscape($eventAttendanceStatus ?: 'absent') ?>"><?= studentEscape(ucfirst($eventAttendanceStatus ?: 'No record')) ?></span></article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="student-dashboard-side">
        <section class="dashboard-section student-content-panel">
            <div class="section-heading"><div><h2>Attendance Summary</h2><p>Your recorded personal results.</p></div></div>
            <dl class="student-attendance-stats">
                <div><dt>Present</dt><dd><?= (int) $attendance['present'] ?></dd></div>
                <div><dt>Late</dt><dd><?= (int) $attendance['late'] ?></dd></div>
                <div><dt>Absent</dt><dd><?= (int) $attendance['absent'] ?></dd></div>
                <div><dt>Excused</dt><dd><?= (int) $attendance['excused'] ?></dd></div>
            </dl>
            <a class="management-button management-button-secondary" href="<?= studentEscape($studentBaseUrl) ?>/student/reports/index.php">Personal report</a>
        </section>
        <section class="dashboard-section student-content-panel">
            <div class="section-heading"><div><h2>Quick Actions</h2></div></div>
            <div class="student-quick-links">
                <a href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/index.php"><i class="fa-solid fa-barcode"></i> My barcode and attendance</a>
                <a href="<?= studentEscape($studentBaseUrl) ?>/student/events/index.php"><i class="fa-solid fa-calendar-days"></i> Event schedules</a>
                <a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/index.php"><i class="fa-solid fa-file-circle-exclamation"></i> My appeals</a>
            </div>
        </section>
    </aside>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
</main>
</body>
</html>
