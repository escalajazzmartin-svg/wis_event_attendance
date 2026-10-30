<?php

$adminPageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/admin_layout_start.php';

// Current academic period (shown in the banner and used to scope attendance rates).
$period = $pdo->query(
    'SELECT academic_period_id, academic_year, semester
     FROM academic_periods
     WHERE is_current = 1
     ORDER BY start_date DESC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: null;

$periodLabel = 'No current academic period is set';
if ($period) {
    $semesterLabel = in_array($period['semester'], ['1st', '2nd'], true)
        ? $period['semester'] . ' Semester'
        : $period['semester'];
    $periodLabel = $semesterLabel . ', ' . str_replace('-', '–', $period['academic_year']);
}

$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM students) AS students,
        (SELECT COUNT(*)
           FROM students s
           INNER JOIN users u ON u.user_id = s.user_id
          WHERE s.enrollment_status = 'enrolled' AND u.account_status = 'active') AS active_students,
        (SELECT COUNT(*) FROM departments) AS departments,
        (SELECT COUNT(*) FROM department_heads) AS department_heads,
        ((SELECT COUNT(*) FROM events WHERE event_status IN ('pending', 'cancellation_requested'))
          + (SELECT COUNT(*) FROM event_change_requests WHERE request_status = 'pending')) AS pending_requests"
)->fetch(PDO::FETCH_ASSOC);

// Attendance across today's approved/completed events.
$today = $pdo->query(
    "SELECT COUNT(DISTINCT e.event_id) AS events,
            COUNT(ea.attendance_id) AS records,
            COALESCE(SUM(ea.attendance_status IN ('present', 'late', 'excused')), 0) AS attended
     FROM events e
     LEFT JOIN event_attendance ea ON ea.event_id = e.event_id
     WHERE e.event_status IN ('approved', 'completed')
       AND DATE(e.start_at) = CURDATE()"
)->fetch(PDO::FETCH_ASSOC);
$todayEvents = (int) $today['events'];
$todayRate = (int) $today['records'] > 0
    ? (int) round(((int) $today['attended'] / (int) $today['records']) * 100)
    : null;

// Attendance rate per department (present, late, or excused count as attended).
$departmentRatesStmt = $pdo->prepare(
    "SELECT d.department_name,
            ROUND(SUM(ea.attendance_status IN ('present', 'late', 'excused')) / COUNT(*) * 100, 1) AS rate
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN students s ON s.student_id = ea.student_id
     LEFT JOIN event_attendance_roster r
            ON r.event_id = ea.event_id AND r.student_id = ea.student_id
     INNER JOIN departments d ON d.department_id = COALESCE(r.department_id, s.department_id)
     WHERE e.event_status IN ('approved', 'completed')"
    . ($period ? ' AND e.academic_period_id = :period_id' : '') .
    " GROUP BY d.department_id, d.department_name
     ORDER BY rate DESC, d.department_name
     LIMIT 5"
);
$departmentRatesStmt->execute($period ? ['period_id' => (int) $period['academic_period_id']] : []);
$departmentRates = $departmentRatesStmt->fetchAll(PDO::FETCH_ASSOC);

// Oldest requests first: new events, cancellation requests, and schedule changes.
$requests = $pdo->query(
    "SELECT e.event_id, e.event_title, e.start_at, d.department_name,
            NULL AS change_request_id, e.event_status AS kind, e.created_at AS sort_at
     FROM events e
     INNER JOIN departments d ON d.department_id = e.department_id
     WHERE e.event_status IN ('pending', 'cancellation_requested')
     UNION ALL
     SELECT e.event_id, e.event_title, e.start_at, d.department_name,
            cr.change_request_id, 'change' AS kind, cr.requested_at AS sort_at
     FROM event_change_requests cr
     INNER JOIN events e ON e.event_id = cr.event_id
     INNER JOIN departments d ON d.department_id = e.department_id
     WHERE cr.request_status = 'pending'
     ORDER BY sort_at ASC
     LIMIT 3"
)->fetchAll(PDO::FETCH_ASSOC);

// Today's approved/completed events, in start-time order.
$todayList = $pdo->query(
    "SELECT e.event_id, e.event_title, e.start_at, e.end_at, e.venue, d.department_name,
            (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id = e.event_id) AS records
     FROM events e
     INNER JOIN departments d ON d.department_id = e.department_id
     WHERE e.event_status IN ('approved', 'completed') AND DATE(e.start_at) = CURDATE()
     ORDER BY e.start_at ASC, e.event_id ASC
     LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

// Latest administrator actions (same source as the Security page).
$recentActivity = $pdo->query(
    "SELECT l.created_at, l.action_type, l.entity_type, l.entity_id, u.username AS actor
     FROM admin_activity_logs l
     LEFT JOIN users u ON u.user_id = l.actor_user_id
     ORDER BY l.created_at DESC, l.activity_id DESC
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);
$activityLabel = static fn (string $value): string => ucwords(str_replace('_', ' ', $value));

$exportQuery = ['report' => 'attendance'];
if ($period) {
    $exportQuery['academic_year'] = $period['academic_year'];
    $exportQuery['semester'] = $period['semester'];
}

$statCards = [
    [
        'label' => 'Students',
        'value' => number_format((int) $stats['students']),
        'note' => number_format((int) $stats['active_students']) . ' active',
        'icon' => 'users',
        'tone' => 'maroon',
        'href' => '/admin/students/index.php',
    ],
    [
        'label' => 'Departments',
        'value' => number_format((int) $stats['departments']),
        'note' => number_format((int) $stats['department_heads']) . ' department heads',
        'icon' => 'building',
        'tone' => 'gold',
        'href' => '/admin/departments/index.php',
    ],
    [
        'label' => 'Attendance today',
        'value' => $todayRate === null ? '—' : $todayRate . '%',
        'note' => $todayEvents === 0
            ? 'No events today'
            : 'Across ' . $todayEvents . ($todayEvents === 1 ? ' event' : ' events'),
        'icon' => 'check',
        'tone' => 'green',
        'href' => '/admin/attendance/index.php',
    ],
    [
        'label' => 'Pending requests',
        'value' => number_format((int) $stats['pending_requests']),
        'note' => 'Events awaiting approval',
        'icon' => 'calendar',
        'tone' => 'indigo',
        'href' => '/admin/events/index.php?status=pending',
    ],
];

$requestKinds = [
    'cancellation_requested' => 'Cancellation request',
    'change' => 'Schedule change',
];
?>

<section class="admin-content-section">
    <div class="dash-hero">
        <div>
            <span class="dash-eyebrow"><?= adminEscape(date('l, F j, Y')) ?></span>
            <h1>Dashboard</h1>
            <p>Overview of the current academic period · <?= adminEscape($periodLabel) ?></p>
        </div>
        <div class="dash-hero-actions">
            <a class="dash-btn dash-btn-gold" href="<?= adminEscape($adminBaseUrl) ?>/admin/students/create.php">Add student</a>
            <a class="dash-btn dash-btn-ghost" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php">Create event</a>
            <a class="dash-btn dash-btn-ghost" href="<?= adminEscape($adminBaseUrl) ?>/admin/reports/export.php?<?= adminEscape(http_build_query($exportQuery)) ?>">Export CSV</a>
        </div>
    </div>

    <?php if ((int) $stats['pending_requests'] > 0): ?>
        <div class="dash-alert" role="status">
            <span class="dash-alert-icon"><?= adminIcon('calendar') ?></span>
            <p><strong><?= (int) $stats['pending_requests'] ?> <?= (int) $stats['pending_requests'] === 1 ? 'request is' : 'requests are' ?> waiting for your review.</strong> Approve or reject event requests, cancellations and schedule changes.</p>
            <a class="dash-outline-btn dash-solid-btn" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php?status=pending">Review now</a>
        </div>
    <?php endif; ?>

    <div class="dash-stats">
        <?php foreach ($statCards as $card): ?>
            <a class="dash-card dash-stat dash-stat-link" href="<?= adminEscape($adminBaseUrl . $card['href']) ?>">
                <span class="dash-stat-icon dash-tone-<?= adminEscape($card['tone']) ?>"><?= adminIcon($card['icon']) ?></span>
                <div class="dash-stat-body">
                    <span class="dash-stat-label"><?= adminEscape($card['label']) ?></span>
                    <strong class="dash-stat-value"><?= adminEscape($card['value']) ?></strong>
                    <span class="dash-stat-note"><?= adminEscape($card['note']) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="dash-grid dash-grid-wide">
        <section class="dash-panel">
            <div class="dash-panel-head">
                <h2>Attendance rate by department</h2>
                <a class="dash-outline-btn" href="<?= adminEscape($adminBaseUrl) ?>/admin/reports/index.php?report=attendance">Full report</a>
            </div>
            <div class="dash-bars">
                <?php if ($departmentRates === []): ?>
                    <p class="dash-empty">No attendance has been recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($departmentRates as $row): ?>
                        <?php $rate = max(0, min(100, (float) $row['rate'])); ?>
                        <div class="dash-bar-row">
                            <span class="dash-bar-label" title="<?= adminEscape($row['department_name']) ?>"><?= adminEscape($row['department_name']) ?></span>
                            <span class="dash-bar-track" role="img" aria-label="<?= adminEscape($row['department_name']) ?>: <?= (int) round($rate) ?>%">
                                <span class="dash-bar-fill" style="display:block;width:<?= adminEscape(number_format($rate, 1, '.', '')) ?>%"></span>
                            </span>
                            <span class="dash-bar-value"><?= (int) round($rate) ?>%</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <p class="dash-note"><?= $period ? 'Current academic period, all approved events' : 'All approved events' ?></p>
            </div>
        </section>

        <section class="dash-panel">
            <div class="dash-panel-head">
                <h2>Today's events</h2>
                <a class="dash-text-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php?<?= adminEscape(http_build_query(['event_date' => date('Y-m-d'), 'academic_period_id' => 'all'])) ?>">All events</a>
            </div>
            <?php if ($todayList === []): ?>
                <p class="dash-empty">No events are scheduled for today.</p>
            <?php else: ?>
                <ul class="dash-today">
                    <?php foreach ($todayList as $event): ?>
                        <?php
                        $nowTs = time();
                        $startTs = strtotime($event['start_at']);
                        $endTs = strtotime($event['end_at']);
                        [$stateLabel, $stateClass] = $nowTs < $startTs ? ['Upcoming', 'is-upcoming'] : ($nowTs <= $endTs ? ['Ongoing', 'is-live'] : ['Ended', 'is-ended']);
                        ?>
                        <li>
                            <span class="dash-today-time"><?= adminEscape(date('g:i A', $startTs)) ?></span>
                            <a class="dash-today-info" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/view.php?id=<?= (int) $event['event_id'] ?>">
                                <strong title="<?= adminEscape($event['event_title']) ?>"><?= adminEscape($event['event_title']) ?></strong>
                                <span><?= adminEscape($event['department_name']) ?><?= $event['venue'] ? ' · ' . adminEscape($event['venue']) : '' ?> · <?= (int) $event['records'] ?> recorded</span>
                            </a>
                            <span class="dash-state <?= $stateClass ?>"><?= $stateLabel ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="dash-grid dash-grid-wide">
        <section class="dash-panel">
            <div class="dash-panel-head">
                <h2>Event requests to review</h2>
                <a class="dash-text-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php?status=pending">View all</a>
            </div>
            <?php if ($requests === []): ?>
                <p class="dash-empty">No event requests are waiting for review.</p>
            <?php else: ?>
                <ul class="dash-requests">
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $isChange = $request['kind'] === 'change';
                        $reviewUrl = $adminBaseUrl . '/admin/events/view.php?id=' . (int) $request['event_id']
                            . ($isChange ? '&change_request_id=' . (int) $request['change_request_id'] : '')
                            . '#' . ($isChange ? 'change-review-remarks' : 'remarks');
                        $detail = $request['department_name'] . ' · ' . date('M j', strtotime($request['start_at']));
                        if (isset($requestKinds[$request['kind']])) {
                            $detail .= ' · ' . $requestKinds[$request['kind']];
                        }
                        ?>
                        <li class="dash-request">
                            <div class="dash-request-info">
                                <strong title="<?= adminEscape($request['event_title']) ?>"><?= adminEscape($request['event_title']) ?></strong>
                                <span><?= adminEscape($detail) ?></span>
                            </div>
                            <div class="dash-request-actions">
                                <a class="dash-outline-btn dash-solid-btn" href="<?= adminEscape($reviewUrl) ?>">Approve</a>
                                <a class="dash-outline-btn" href="<?= adminEscape($reviewUrl) ?>">Reject</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dash-panel">
            <div class="dash-panel-head">
                <h2>Recent activity</h2>
                <a class="dash-text-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/security/index.php">Security log</a>
            </div>
            <?php if ($recentActivity === []): ?>
                <p class="dash-empty">No administrator activity has been logged yet.</p>
            <?php else: ?>
                <ul class="dash-activity">
                    <?php foreach ($recentActivity as $activity): ?>
                        <li>
                            <span class="dash-activity-dot" aria-hidden="true"></span>
                            <div>
                                <strong><?= adminEscape($activityLabel($activity['action_type'])) ?> · <?= adminEscape($activityLabel($activity['entity_type'])) ?><?= $activity['entity_id'] !== null ? ' #' . (int) $activity['entity_id'] : '' ?></strong>
                                <span><?= adminEscape($activity['actor'] ?: 'Unknown') ?> · <?= adminEscape(date('M j, g:i A', strtotime($activity['created_at']))) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
</main>
</body>
</html>
