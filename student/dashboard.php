<?php

$studentPageTitle = 'Student Dashboard';
$studentPageCss = 'student-dashboard.css';

require_once __DIR__ . '/../includes/student_context.php';
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

$recentEvents = array_reverse($recentEvents);

usort(
    $announcements,
    static fn(array $left, array $right): int =>
    strcmp($right['updated_at'], $left['updated_at'])
);

$announcements = array_slice($announcements, 0, 4);

$attendanceStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
            COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
            COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
            COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE ea.student_id = ?"
);

$attendanceStmt->execute([$studentId]);
$attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC);

// Load per-event attendance totals for the student attendance chart.
$attendanceChartStmt = $pdo->prepare(
    "SELECT
        e.event_id,
        e.academic_period_id AS academicPeriodId,
        ap.academic_year AS academicYear,
        ap.semester,
        COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
        COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
        COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
        COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
     WHERE ea.student_id = ?
     GROUP BY e.event_id, e.academic_period_id, ap.academic_year, ap.semester
     ORDER BY e.start_at ASC, e.event_id ASC"
);

$attendanceChartStmt->execute([$studentId]);
$attendanceChartEvents = $attendanceChartStmt->fetchAll(PDO::FETCH_ASSOC);

$attendanceTotal = (int) $attendance['total'];
$attended = (int) $attendance['present']
    + (int) $attendance['late']
    + (int) $attendance['excused'];

$unrecordedMissedStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM events e
     WHERE e.department_id = ?
       AND (
           e.event_status = 'completed'
           OR (e.event_status = 'approved' AND e.end_at < NOW())
       )
       AND " . studentParticipantCondition('e') . "
       AND NOT EXISTS (
           SELECT 1
           FROM event_attendance ea
           WHERE ea.event_id = e.event_id
             AND ea.student_id = ?
       )"
);

$unrecordedMissedStmt->execute(
    array_merge(
        [$studentDepartmentId],
        $participantParams,
        [$studentId]
    )
);

$unrecordedMissed = (int) $unrecordedMissedStmt->fetchColumn();
$missed = (int) $attendance['absent'] + $unrecordedMissed;
$eligiblePastTotal = $attendanceTotal + $unrecordedMissed;

$attendanceRate = $eligiblePastTotal > 0
    ? (int) round(($attended / $eligiblePastTotal) * 100)
    : 0;

$appealStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(aa.appeal_status = 'pending'), 0) AS pending,
            COALESCE(SUM(aa.appeal_status IN ('approved', 'rejected', 'withdrawn')), 0) AS resolved
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE aa.student_id = ?"
);

$appealStmt->execute([$studentId]);
$appealStats = $appealStmt->fetch(PDO::FETCH_ASSOC);

$studentName = studentName($studentProfile);
?>

<section class="dashboard-section student-dashboard-welcome">
    <div class="section-heading">
        <div>
            <span class="student-eyebrow">
                <?= studentEscape($studentProfile['department_name']) ?>
            </span>
            <h2>Welcome, <?= studentEscape($studentName) ?></h2>
            <p>Stay up to date with your events, attendance, and appeals.</p>
        </div>
    </div>
</section>

<section class="dashboard-section">
    <div class="student-summary-grid">
        <article class="student-summary-card">
            <span>Attendance rate</span>
            <strong><?= $attendanceRate ?>%</strong>
            <small><?= $attended ?> attended out of <?= $attendanceTotal ?> recorded</small>
        </article>

        <article class="student-summary-card">
            <span>Events attended</span>
            <strong><?= $attended ?></strong>
            <small>Present, Late, or Excused</small>
        </article>

        <article class="student-summary-card">
            <span>Events missed</span>
            <strong><?= $missed ?></strong>
            <small>Marked absent</small>
        </article>

        <article class="student-summary-card">
            <span>My Appeals</span>
            <strong><?= (int) $appealStats['pending'] ?> pending</strong>
            <small><?= (int) $appealStats['resolved'] ?> resolved</small>
        </article>
    </div>
</section>

<div class="student-dashboard-columns">
    <div>
        <section class="dashboard-section student-content-panel student-events-browser">
            <div class="section-heading">
                <div>
                    <h2>Events</h2>
                    <p>Browse upcoming, ongoing, and recently completed events.</p>
                </div>
                <a
                    class="text-link"
                    href="<?= studentEscape($studentBaseUrl) ?>/student/events/index.php">
                    All events →
                </a>
            </div>

            <div class="student-event-tabs" role="tablist" aria-label="Event categories">
                <button type="button" class="student-event-tab is-active"
                    id="tab-upcoming" role="tab" aria-selected="true"
                    aria-controls="panel-upcoming" data-event-tab="upcoming">
                    Upcoming <span><?= count($upcomingEvents) ?></span>
                </button>
                <button type="button" class="student-event-tab"
                    id="tab-current" role="tab" aria-selected="false"
                    aria-controls="panel-current" data-event-tab="current" tabindex="-1">
                    Current <span><?= count($ongoingEvents) ?></span>
                </button>
                <button type="button" class="student-event-tab"
                    id="tab-recent" role="tab" aria-selected="false"
                    aria-controls="panel-recent" data-event-tab="recent" tabindex="-1">
                    Recent <span><?= count($recentEvents) ?></span>
                </button>
            </div>

            <div class="student-event-tab-panel" id="panel-upcoming" role="tabpanel"
                aria-labelledby="tab-upcoming" data-event-panel="upcoming">
                <?php if ($upcomingEvents === []): ?>
                    <div class="empty-state">
                        <strong>No upcoming events</strong>
                        <p>Relevant approved events will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="student-event-grid">
                        <?php foreach ($upcomingEvents as $event): ?>
                            <article class="student-event-card">
                                <div>
                                    <span class="student-event-date">
                                        <?= studentEscape(studentDateTime($event['start_at'], 'M j · g:i A')) ?>
                                    </span>
                                    <h3>
                                        <a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $event['event_id'] ?>">
                                            <?= studentEscape($event['event_title']) ?>
                                        </a>
                                    </h3>
                                    <p>
                                        <?= studentEscape($event['venue'] ?: 'Venue to be announced') ?>
                                        · <?= studentEscape($event['academic_year']) ?>
                                        / <?= studentEscape($event['semester']) ?>
                                    </p>
                                </div>
                                <span class="status-pill status-approved">Upcoming</span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="student-event-tab-panel" id="panel-current" role="tabpanel"
                aria-labelledby="tab-current" data-event-panel="current" hidden>
                <?php if ($ongoingEvents === []): ?>
                    <div class="empty-state">
                        <strong>No current events</strong>
                        <p>Events that are currently in progress will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="student-event-grid">
                        <?php foreach ($ongoingEvents as $event): ?>
                            <article class="student-event-card">
                                <div>
                                    <span class="student-event-date">
                                        <?= studentEscape(studentDateTime($event['start_at'], 'M j · g:i A')) ?>
                                        – <?= studentEscape(studentDateTime($event['end_at'], 'g:i A')) ?>
                                    </span>
                                    <h3>
                                        <a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $event['event_id'] ?>">
                                            <?= studentEscape($event['event_title']) ?>
                                        </a>
                                    </h3>
                                    <p><?= studentEscape($event['venue'] ?: 'Venue to be announced') ?></p>
                                </div>
                                <span class="status-pill status-approved">Ongoing</span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="student-event-tab-panel" id="panel-recent" role="tabpanel"
                aria-labelledby="tab-recent" data-event-panel="recent" hidden>
                <div class="student-event-grid">
                    <?php if ($recentEvents === []): ?>
                        <div class="empty-state">
                            <strong>No recently completed events</strong>
                            <p>Your recent completed events will appear here.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentEvents as $event): ?>
                            <?php
                            $eventAttendanceStmt = $pdo->prepare(
                                'SELECT attendance_status
                                 FROM event_attendance
                                 WHERE event_id = ? AND student_id = ?'
                            );
                            $eventAttendanceStmt->execute([
                                $event['event_id'],
                                $studentId
                            ]);
                            $eventAttendanceStatus = $eventAttendanceStmt->fetchColumn();
                            ?>
                            <article class="student-event-card">
                                <div>
                                    <span class="student-event-date">
                                        <?= studentEscape(studentDateTime($event['start_at'], 'M j, Y')) ?>
                                    </span>
                                    <h3>
                                        <a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $event['event_id'] ?>">
                                            <?= studentEscape($event['event_title']) ?>
                                        </a>
                                    </h3>
                                    <p><?= studentEscape($event['venue'] ?: 'Venue not specified') ?></p>
                                </div>
                                <span class="status-pill status-<?= studentEscape($eventAttendanceStatus ?: 'absent') ?>">
                                    <?= studentEscape(ucfirst($eventAttendanceStatus ?: 'No record')) ?>
                                </span>
                            </article>
                        <?php endforeach; ?>
                        <a class="text-link" href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/history.php">
                            View attendance history →
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const eventTabs = document.querySelectorAll('[data-event-tab]');
                const eventPanels = document.querySelectorAll('[data-event-panel]');

                eventTabs.forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        const selected = tab.dataset.eventTab;

                        eventTabs.forEach(function(item) {
                            const active = item === tab;
                            item.classList.toggle('is-active', active);
                            item.setAttribute('aria-selected', active ? 'true' : 'false');
                            item.tabIndex = active ? 0 : -1;
                        });

                        eventPanels.forEach(function(panel) {
                            panel.hidden = panel.dataset.eventPanel !== selected;
                        });
                    });
                });
            });
        </script>
    </div>

    <aside class="student-dashboard-side">
        <section class="attendance-chart-panel">
            <div class="attendance-card-header">
                <div class="attendance-card-title">
                    <h2>Attendance Summary</h2>
                    <p>Your recorded personal attendance results.</p>
                </div>

                <div class="attendance-filters">
                    <label for="academicPeriodFilter">Academic Period</label>
                    <select id="academicPeriodFilter" class="form-control">
                        <option value="all">All</option>
                    </select>
                </div>
            </div>

            <div class="attendance-chart-container">
                <canvas id="attendanceStatusChart" aria-label="Student attendance status chart" role="img"></canvas>
            </div>
        </section>
    </aside>
</div>

<script>
    window.attendanceEvents = <?= json_encode(
                                    $attendanceChartEvents,
                                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR
                                ) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?= studentEscape($studentBaseUrl) ?>/assets/js/attendance-charts.js"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
</main>
</body>

</html>
