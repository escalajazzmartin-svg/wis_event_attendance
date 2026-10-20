<?php

$adminPageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/admin_layout_start.php';

$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM students) AS students,
        (SELECT COUNT(*) FROM department_heads) AS department_heads,
        (SELECT COUNT(*) FROM departments) AS departments,
        (SELECT COUNT(*) FROM events WHERE event_status = 'approved') AS approved_events,
        (SELECT COUNT(*) FROM events WHERE event_status = 'approved' AND start_at > NOW()) AS upcoming_events,
        (SELECT COUNT(*) FROM events WHERE event_status = 'approved' AND start_at <= NOW() AND end_at >= NOW()) AS ongoing_events,
        (SELECT COUNT(*) FROM events WHERE event_status = 'completed' OR (event_status = 'approved' AND end_at < NOW())) AS completed_events,
        ((SELECT COUNT(*) FROM events WHERE event_status IN ('pending', 'cancellation_requested'))
          + (SELECT COUNT(*) FROM event_change_requests WHERE request_status = 'pending')) AS pending_requests,
        (SELECT COUNT(*) FROM event_attendance) AS attendance_records,
        (SELECT COALESCE(SUM(attendance_status = 'present'), 0) FROM event_attendance) AS present,
        (SELECT COALESCE(SUM(attendance_status = 'late'), 0) FROM event_attendance) AS late,
        (SELECT COALESCE(SUM(attendance_status = 'absent'), 0) FROM event_attendance) AS absent,
        (SELECT COALESCE(SUM(attendance_status = 'excused'), 0) FROM event_attendance) AS excused"
)->fetch(PDO::FETCH_ASSOC);

$pendingStmt = $pdo->query(
    "SELECT e.event_id, e.event_title, e.start_at, d.department_name
     FROM events e
     INNER JOIN departments d ON d.department_id = e.department_id
     WHERE e.event_status IN ('pending', 'cancellation_requested')
     ORDER BY e.created_at ASC
     LIMIT 6"
);
$pendingEvents = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingChanges = $pdo->query(
    "SELECT cr.change_request_id, e.event_id, e.event_title, cr.requested_at, d.department_name
     FROM event_change_requests cr
     INNER JOIN events e ON e.event_id = cr.event_id
     INNER JOIN departments d ON d.department_id = e.department_id
     WHERE cr.request_status = 'pending'
     ORDER BY cr.requested_at ASC
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);
$activityStmt = $pdo->query(
    "SELECT activity, created_at
     FROM (
        SELECT CONCAT_WS(' ', u.username, al.action_type, al.entity_type,
                         COALESCE(CONCAT('#', al.entity_id), '')) AS activity,
               al.created_at
        FROM admin_activity_logs al
        LEFT JOIN users u ON u.user_id = al.actor_user_id
        UNION ALL
        SELECT CONCAT_WS(' ', u.username, 'recorded', al.action_type,
                         'for event', COALESCE(e.event_title, '')) AS activity,
               al.created_at
        FROM attendance_audit_logs al
        LEFT JOIN users u ON u.user_id = al.actor_user_id
        LEFT JOIN events e ON e.event_id = al.event_id
        UNION ALL
        SELECT CONCAT_WS(' ', u.username, 'reviewed event', e.event_title,
                         'as', e.event_status) AS activity,
               e.admin_reviewed_at AS created_at
        FROM events e
        LEFT JOIN users u ON u.user_id = e.admin_reviewed_by
        WHERE e.admin_reviewed_at IS NOT NULL
     ) recent
     ORDER BY created_at DESC
     LIMIT 12"
);
$activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
$attendanceTotal = (int) $counts['attendance_records'];
$checkedIn = (int) $counts['present'] + (int) $counts['late'] + (int) $counts['excused'];
$attendanceRate = $attendanceTotal > 0 ? round(($checkedIn / $attendanceTotal) * 100, 1) : 0;
?>

<section class="admin-content-section">
    <div class="admin-page-heading"><div><span class="admin-eyebrow">School overview</span><h2>Admin Dashboard</h2><p>Current users, events, and school-wide attendance.</p></div></div>
    <div class="admin-summary-grid admin-summary-grid-wide">
        <?php foreach ([
            ['Students', 'students', 'fa-graduation-cap', 'admin-icon-blue'],
            ['Department Heads', 'department_heads', 'fa-user-tie', 'admin-icon-gold'],
            ['Departments', 'departments', 'fa-building', ''],
            ['Approved events', 'approved_events', 'fa-calendar-check', 'admin-icon-green'],
            ['Upcoming events', 'upcoming_events', 'fa-calendar-days', 'admin-icon-blue'],
            ['Ongoing events', 'ongoing_events', 'fa-person-running', 'admin-icon-gold'],
            ['Completed events', 'completed_events', 'fa-calendar-xmark', ''],
            ['Pending requests', 'pending_requests', 'fa-hourglass-half', 'admin-icon-gold'],
        ] as [$label, $key, $icon, $tone]): ?>
            <article class="admin-summary-card">
                <span class="admin-summary-icon <?= adminEscape($tone) ?>"><i class="fa-solid <?= adminEscape($icon) ?>" aria-hidden="true"></i></span>
                <div><span><?= adminEscape($label) ?></span><strong><?= (int) $counts[$key] ?></strong></div>
            </article>
        <?php endforeach; ?>
    </div>

    <section class="admin-panel admin-school-attendance">
        <div class="admin-panel-heading"><div><h3>School-wide attendance</h3><p><?= number_format($attendanceRate, 1) ?>% present, late, or excused among recorded attendance entries.</p></div><a class="admin-action-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/attendance/index.php">Monitor attendance →</a></div>
        <div class="admin-attendance-summary">
            <div><span>Records</span><strong><?= $attendanceTotal ?></strong></div>
            <div><span>Present</span><strong><?= (int) $counts['present'] ?></strong></div>
            <div><span>Late</span><strong><?= (int) $counts['late'] ?></strong></div>
            <div><span>Absent</span><strong><?= (int) $counts['absent'] ?></strong></div>
            <div><span>Excused</span><strong><?= (int) $counts['excused'] ?></strong></div>
        </div>
    </section>

    <div class="admin-dashboard-columns">
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><h3>Pending event requests</h3><p>Requests waiting for administrator review.</p></div><a class="admin-action-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php?status=pending">All requests →</a></div>
            <?php if ($pendingEvents === []): ?>
                <p>No pending event or cancellation requests.</p>
            <?php else: ?>
                <div class="admin-recent-list">
                    <?php foreach ($pendingEvents as $event): ?>
                        <article><div><strong><?= adminEscape($event['event_title']) ?></strong><span><?= adminEscape($event['department_name']) ?> · <?= adminEscape(date('M j, Y', strtotime($event['start_at']))) ?></span></div><a href="<?= adminEscape($adminBaseUrl) ?>/admin/events/view.php?id=<?= (int) $event['event_id'] ?>">Review</a></article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><h3>Recent system activities</h3><p>Latest account, attendance, and event review changes.</p></div></div>
            <?php if ($activities === []): ?>
                <p>No recent activities have been recorded.</p>
            <?php else: ?>
                <div class="admin-recent-list">
                    <?php foreach ($activities as $activity): ?>
                        <article><div><strong><?= adminEscape($activity['activity']) ?></strong><span><?= adminEscape(date('M j, Y · g:i A', strtotime($activity['created_at']))) ?></span></div></article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php if ($pendingChanges !== []): ?>
        <section class="admin-panel admin-integrity-panel">
            <div class="admin-panel-heading"><div><h3>Approved event changes awaiting review</h3><p>Schedule updates submitted by Department Heads.</p></div></div>
            <div class="admin-recent-list">
                <?php foreach ($pendingChanges as $change): ?>
                    <article><div><strong><?= adminEscape($change['event_title']) ?></strong><span><?= adminEscape($change['department_name']) ?> · <?= adminEscape(date('M j, Y · g:i A', strtotime($change['requested_at']))) ?></span></div><a href="<?= adminEscape($adminBaseUrl) ?>/admin/events/view.php?id=<?= (int) $change['event_id'] ?>&change_request_id=<?= (int) $change['change_request_id'] ?>">Review</a></article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
</main>
</body>
</html>
