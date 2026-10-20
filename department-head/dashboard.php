<?php
session_start();

require_once __DIR__ . '/../config/database.php';

$role = strtolower(trim(
    $_SESSION['role_name'] ?? $_SESSION['role'] ?? ''
));

if (empty($_SESSION['user_id']) || $role !== 'department_head') {
    header('Location: ../auth/department-head-login.php');
    exit;
}

$baseUrl = '/wis_event_attendance';

$headName = $_SESSION['full_name']
    ?? $_SESSION['name']
    ?? 'Department Head';

$initial = strtoupper(substr($headName, 0, 1));

$departmentName = 'Department';
$departmentId = null;
$databaseWarning = false;

$stats = [
    'total_events' => 0,
    'pending_events' => 0,
    'approved_events' => 0,
    'attendance_records' => 0,
    'pending_appeals' => 0,
    'students' => 0,
];

$upcomingEvents = [];
$recentEvents = [];
$attendanceSummary = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'excused' => 0,
];

try {
    // Identify the logged-in Department Head.
    $stmt = $pdo->prepare(
        "SELECT dh.department_head_id, dh.department_id,
                dh.first_name, dh.last_name,
                d.department_name
         FROM department_heads dh
         INNER JOIN departments d
             ON d.department_id = dh.department_id
         WHERE dh.user_id = ?
         LIMIT 1"
    );

    $stmt->execute([$_SESSION['user_id']]);
    $head = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$head) {
        throw new RuntimeException(
            'Department Head profile was not found.'
        );
    }

    $headId = (int) $head['department_head_id'];
    $departmentId = (int) $head['department_id'];
    $departmentName = $head['department_name'];

    // Count events belonging to this department.
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_events,
            COALESCE(SUM(event_status = 'pending'), 0)
                AS pending_events,
            COALESCE(SUM(event_status = 'approved'), 0)
                AS approved_events
         FROM events
         WHERE department_id = ?"
    );

    $stmt->execute([$departmentId]);
    $eventStats = $stmt->fetch(PDO::FETCH_ASSOC);

    foreach ($eventStats as $key => $value) {
        $stats[$key] = (int) $value;
    }

    // Count students assigned to this department.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM students
         WHERE department_id = ?
           AND enrollment_status = 'enrolled'"
    );

    $stmt->execute([$departmentId]);
    $stats['students'] = (int) $stmt->fetchColumn();

    // Count attendance records for department events.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM event_attendance ea
         INNER JOIN events e ON e.event_id = ea.event_id
         WHERE e.department_id = ?"
    );

    $stmt->execute([$departmentId]);
    $stats['attendance_records'] = (int) $stmt->fetchColumn();

    // Count pending appeals for this department's events.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM attendance_appeals aa
         INNER JOIN event_attendance ea
             ON ea.attendance_id = aa.attendance_id
         INNER JOIN events e ON e.event_id = ea.event_id
         WHERE e.department_id = ?
           AND aa.appeal_status = 'pending'"
    );

    $stmt->execute([$departmentId]);
    $stats['pending_appeals'] = (int) $stmt->fetchColumn();

    // Upcoming approved events.
    $stmt = $pdo->prepare(
        "SELECT event_id, event_title, venue, start_at,
                end_at, event_status
         FROM events
         WHERE department_id = ?
           AND event_status = 'approved'
           AND end_at >= NOW()
         ORDER BY start_at ASC
         LIMIT 1"
    );

    $stmt->execute([$departmentId]);
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Most recently created department events.
    $stmt = $pdo->prepare(
        "SELECT event_id, event_title, start_at,
                event_status, created_at
         FROM events
         WHERE department_id = ?
         ORDER BY created_at DESC
         LIMIT 1"
    );

    $stmt->execute([$departmentId]);
    $recentEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attendance distribution for this department.
    $stmt = $pdo->prepare(
        "SELECT LOWER(ea.attendance_status) AS status,
                COUNT(*) AS total
         FROM event_attendance ea
         INNER JOIN events e ON e.event_id = ea.event_id
         WHERE e.department_id = ?
         GROUP BY LOWER(ea.attendance_status)"
    );

    $stmt->execute([$departmentId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = $row['status'];

        if (array_key_exists($status, $attendanceSummary)) {
            $attendanceSummary[$status] = (int) $row['total'];
        }
    }
} catch (Throwable $e) {
    error_log('Department Head Dashboard: ' . $e->getMessage());
    $databaseWarning = true;
}

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function eventDate(?string $date): string
{
    return $date
        ? date('M d, Y', strtotime($date))
        : 'Date TBA';
}

function eventTime(?string $date): string
{
    return $date
        ? date('h:i A', strtotime($date))
        : '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Department Head Dashboard | WEAMMS</title>

    <link rel="icon" type="image/png"
        href="<?= $baseUrl ?>.../assets/images/wisnoname.png">

    <link rel="stylesheet"
        href="<?= $baseUrl ?>/assets/css/style.css">

    <link rel="stylesheet"
        href="<?= $baseUrl ?>/assets/css/department-head.css">

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body class="department-head-dashboard-page">

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="dashboard-main">

        <?php
        $userRole = 'Department Head';
        require_once __DIR__ . '/../includes/header.php';
        ?>

        <?php if ($databaseWarning): ?>
            <div class="dashboard-alert">
                Dashboard information could not be loaded.
                Check the Department Head profile, database tables,
                and PHP error log.
            </div>
        <?php endif; ?>

        <!-- SUMMARY CARDS -->

        <!-- SUMMARY CARDS -->
        <section class="dashboard-section">
            <div class="section-heading">
                <div>
                    <h2>Department Overview</h2>
                    <p>Summary of your department's activities.</p>
                </div>

                <a class="primary-action"
                    href="<?= $baseUrl ?>/department-head/events/index.php">
                    <i class="fa-solid fa-calendar-plus"></i>
                    Manage Events
                </a>
            </div>

            <div class="department-stats-grid">

                <!-- TOTAL EVENTS -->
                <a class="department-stat-card"
                    href="<?= $baseUrl ?>/department-head/events/index.php"
                    aria-label="View all department events">
                    <div>
                        <span>Total Events</span>
                        <strong><?= $stats['total_events'] ?></strong>
                        <small>All department events</small>
                    </div>
                </a>

                <!-- PENDING REQUESTS -->
                <a class="department-stat-card"
                    href="<?= $baseUrl ?>/department-head/events/index.php"
                    aria-label="View pending event requests">
                    <div>
                        <span>Pending Requests</span>
                        <strong><?= $stats['pending_events'] ?></strong>
                        <small>Awaiting admin review</small>
                    </div>
                </a>

                <!-- PENDING APPEALS -->
                <a class="department-stat-card"
                    href="<?= $baseUrl ?>/department-head/attendance/index.php"
                    aria-label="Review pending attendance appeals">
                    <div>
                        <span>Pending Appeals</span>
                        <strong><?= $stats['pending_appeals'] ?></strong>
                        <small>Requiring review</small>
                    </div>
                </a>

            </div>
        </section>

        <!-- LOWER DASHBOARD -->
        <div class="department-dashboard-columns">

            <div class="department-dashboard-left">

                <section class="panel department-panel">
                    <div class="section-heading">
                        <div>
                            <h2>Upcoming Events</h2>
                            <p>Approved events in your department.</p>
                        </div>

                        <a class="text-link"
                            href="<?= $baseUrl ?>/department-head/events/index.php">
                            View all →
                        </a>
                    </div>

                    <?php if (empty($upcomingEvents)): ?>
                        <div class="empty-state">
                            <i class="fa-regular fa-calendar"></i>
                            <strong>No upcoming events</strong>
                            <p>Approved future events will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="department-event-list">
                            <?php foreach ($upcomingEvents as $event): ?>
                                <article class="department-event-item">
                                    <div class="department-event-date">
                                        <strong>
                                            <?= e(date(
                                                'd',
                                                strtotime($event['start_at'])
                                            )) ?>
                                        </strong>
                                        <span>
                                            <?= e(date(
                                                'M',
                                                strtotime($event['start_at'])
                                            )) ?>
                                        </span>
                                    </div>

                                    <div class="department-event-details">
                                        <h3><?= e($event['event_title']) ?></h3>
                                        <p>
                                            <?= e(eventDate($event['start_at'])) ?>
                                            · <?= e(eventTime($event['start_at'])) ?>
                                            · <?= e($event['venue'] ?: 'Venue TBA') ?>

                                        </p>
                                    </div>

                                    <span class="status-pill status-approved">
                                        Approved
                                    </span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel department-panel">
                    <div class="section-heading">
                        <div>
                            <h2>Recent Event Requests</h2>
                            <p>Track the latest event submissions.</p>
                        </div>

                        <a class="text-link"
                            href="<?= $baseUrl ?>/department-head/events/index.php">
                            View all →
                        </a>
                    </div>

                    <?php if (empty($recentEvents)): ?>
                        <div class="empty-state">
                            <i class="fa-regular fa-folder-open"></i>
                            <strong>No event requests yet</strong>
                            <p>Your submitted events will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="department-event-list">
                            <?php foreach ($recentEvents as $event): ?>
                                <?php
                                $status = strtolower($event['event_status']);
                                ?>
                                <article class="department-event-item">
                                    <div class="department-event-date">
                                        <i class="fa-regular fa-calendar"></i>
                                    </div>

                                    <div class="department-event-details">
                                        <h3><?= e($event['event_title']) ?></h3>
                                        <p><?= e(eventDate($event['start_at'])) ?></p>
                                    </div>

                                    <span class="status-pill status-<?= e($status) ?>">
                                        <?= e(ucfirst($status)) ?>
                                    </span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </div>

            <!-- ATTENDANCE CHART -->
            <section class="panel department-panel department-chart-panel">
                <div class="section-heading">
                    <div>
                        <h2>Attendance Status</h2>
                    </div>
                </div>

                <div class="attendance-chart-layout">
                    <div class="attendance-chart-container">
                        <canvas
                            id="departmentAttendanceChart"
                            aria-label="Stacked bar chart showing attendance status"
                            role="img">
                        </canvas>
                    </div>

                    <div class="attendance-status-legend">
                        <?php foreach (
                            [
                                'present' => 'Present',
                                'late' => 'Late',
                                'absent' => 'Absent',
                                'excused' => 'Excused',
                            ] as $key => $label
                        ): ?>
                            <div class="attendance-legend-item">
                                <span class="attendance-count-dot <?= e($key) ?>-dot"></span>
                                <span><?= e($label) ?>:</span>
                                <strong><?= $attendanceSummary[$key] ?? 0 ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

        </div>

        <footer class="dashboard-footer">
            <span>WEAMMS · WESTMEAD INTERNATIONAL SCHOOL</span>
            <span>Department Head Account</span>
        </footer>

    </main>

    <script>
        window.departmentAttendance = <?= json_encode(
                                            $attendanceSummary,
                                            JSON_HEX_TAG | JSON_HEX_APOS |
                                                JSON_HEX_AMP | JSON_HEX_QUOT
                                        ) ?>;
    </script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script src="<?= $baseUrl ?>/assets/js/attendance-charts.js"></script>

</body>

</html>