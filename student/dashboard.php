<?php
session_start();

require_once __DIR__ . '/../config/database.php';

$role = strtolower(trim(
    $_SESSION['role_name'] ?? $_SESSION['role'] ?? ''
));

if (
    empty($_SESSION['user_id']) ||
    $role !== 'student'
) {
    header('Location: ../auth/student-login.php');
    exit;
}

$baseUrl = '/wis_event_attendance';

$studentName = $_SESSION['full_name']
    ?? $_SESSION['name']
    ?? 'Student';

$initial = strtoupper(substr($studentName, 0, 1));

$stats = [
    'total' => 0,
    'present' => 0,
    'absent' => 0,
    'upcoming' => 0
];

$upcomingEvents = [];
$recentAttendance = [];
$databaseWarning = false;


try {
    // Find the logged-in student's database ID.
    $studentQuery = $pdo->prepare(
        'SELECT student_id
         FROM students
         WHERE user_id = ?
         LIMIT 1'
    );

    $studentQuery->execute([$_SESSION['user_id']]);
    $studentId = $studentQuery->fetchColumn();

    if ($studentId !== false) {
        // Attendance totals.
        $query = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(attendance_status = 'present'), 0) AS present,
                COALESCE(SUM(attendance_status = 'absent'), 0) AS absent
             FROM event_attendance
             WHERE student_id = ?"
        );

        $query->execute([$studentId]);
        $result = $query->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $stats['total'] = (int) $result['total'];
            $stats['present'] = (int) $result['present'];
            $stats['absent'] = (int) $result['absent'];
        }

        // Upcoming approved events.
        $query = $pdo->prepare(
            "SELECT event_id, event_title, start_at
             FROM events
             WHERE start_at >= NOW()
               AND event_status = 'approved'
             ORDER BY start_at ASC
             LIMIT 5"
        );

        $query->execute();
        $upcomingEvents = $query->fetchAll(PDO::FETCH_ASSOC);

        $stats['upcoming'] = count($upcomingEvents);

        // Recent attendance records.
        $query = $pdo->prepare(
            "SELECT
                e.event_title,
                e.start_at,
                ea.attendance_status
             FROM event_attendance ea
             INNER JOIN events e
                ON e.event_id = ea.event_id
             WHERE ea.student_id = ?
             ORDER BY e.start_at DESC
             LIMIT 5"
        );

        $query->execute([$studentId]);
        $recentAttendance = $query->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('Student dashboard database error: ' . $e->getMessage());
    $databaseWarning = true;
}


$attendanceRate = $stats['total'] > 0
    ? round(($stats['present'] / $stats['total']) * 100)
    : 0;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | WEAMMS</title>

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/student.css">
</head>

<body class="student-dashboard-page">

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/header.php'; ?>

        <?php if ($databaseWarning): ?>
            <div class="dashboard-alert">
                Dashboard data could not be loaded. Check your database
                table and column names, then review your PHP error log.
            </div>
        <?php endif; ?>

        <section class="dashboard-section">
            <div class="section-heading">
                <div>
                    <h2>Attendance Overview</h2>
                    <p>Your recorded attendance at a glance.</p>
                </div>
                <a href="<?= $baseUrl ?>/student/attendance/index.php"
                    class="text-link">View attendance →</a>
            </div>

            <div class="stats-grid">
                <article class="stat-card">
                    <div class="stat-icon maroon-icon">◎</div>
                    <div class="stat-content">
                        <span>Total Records</span>
                        <strong><?= $stats['total'] ?></strong>
                        <small>Recorded attendance entries</small>
                    </div>
                </article>

                <article class="stat-card">
                    <div class="stat-icon green-icon">✓</div>
                    <div class="stat-content">
                        <span>Present</span>
                        <strong><?= $stats['present'] ?></strong>
                        <small>Events attended</small>
                    </div>
                </article>

                <article class="stat-card">
                    <div class="stat-icon red-icon">×</div>
                    <div class="stat-content">
                        <span>Absent</span>
                        <strong><?= $stats['absent'] ?></strong>
                        <small>Recorded absences</small>
                    </div>
                </article>

                <article class="stat-card">
                    <div class="stat-icon gold-icon">◷</div>
                    <div class="stat-content">
                        <span>Attendance Rate</span>
                        <strong><?= $attendanceRate ?>%</strong>
                        <small>Based on recorded entries</small>
                    </div>
                </article>
            </div>
        </section>

        <div class="dashboard-columns">
            <div class="dashboard-left-column">
                <section class="panel">
                    <div class="section-heading">
                        <div>
                            <h2>Upcoming Events</h2>
                            <p>Events scheduled for you to attend.</p>
                        </div>
                        <a href="<?= $baseUrl ?>/student/events/index.php"
                            class="text-link">View all →</a>
                    </div>

                    <?php if (empty($upcomingEvents)): ?>
                        <div class="empty-state">
                            <span class="empty-icon">▦</span>
                            <strong>No upcoming events</strong>
                            <p>Approved future events will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="event-list">
                            <?php foreach ($upcomingEvents as $event): ?>
                                <article class="event-item">
                                    <div class="event-date-box">
                                        <strong>
                                            <?= e(date('d', strtotime($event['start_at']))) ?>
                                        </strong>
                                        <span>
                                            <?= e(date('M', strtotime($event['start_at']))) ?>
                                        </span>
                                    </div>

                                    <div class="event-details">
                                        <h3><?= e($event['event_title']) ?></h3>
                                        <p>
                                            <?= e(eventDate($event['start_at'])) ?>
                                            <?php if (eventTime($event['start_at'])): ?>
                                                · <?= e(eventTime($event['start_at'])) ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <span class="event-status">Upcoming</span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel recent-panel">
                    <div class="section-heading">
                        <div>
                            <h2>Recent Attendance</h2>
                            <p>Your latest event attendance records.</p>
                        </div>

                        <a href="<?= $baseUrl ?>/student/attendance/history.php"
                            class="text-link">
                            Full history →
                        </a>
                    </div>

                    <?php if (empty($recentAttendance)): ?>
                        <div class="empty-state compact-empty">
                            <strong>No attendance records yet</strong>
                            <p>Your recorded attendance will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="event-list attendance-event-list">
                            <?php foreach ($recentAttendance as $record): ?>
                                <?php
                                $status = strtolower(trim(
                                    $record['attendance_status'] ?? 'pending'
                                ));

                                $allowedStatuses = [
                                    'present',
                                    'absent',
                                    'late',
                                    'excused',
                                    'pending'
                                ];

                                if (!in_array($status, $allowedStatuses, true)) {
                                    $status = 'pending';
                                }

                                $eventTimestamp = !empty($record['start_at'])
                                    ? strtotime($record['start_at'])
                                    : false;
                                ?>

                                <article class="event-item attendance-event-item">
                                    <div class="event-date-box">
                                        <strong>
                                            <?= $eventTimestamp
                                                ? date('d', $eventTimestamp)
                                                : '--' ?>
                                        </strong>
                                        <span>
                                            <?= $eventTimestamp
                                                ? e(date('M', $eventTimestamp))
                                                : 'TBA' ?>
                                        </span>
                                    </div>

                                    <div class="event-details">
                                        <h3><?= e($record['event_title']) ?></h3>

                                        <p>
                                            <?= e(eventDate($record['start_at'])) ?>

                                            <?php if (eventTime($record['start_at'])): ?>
                                                · <?= e(eventTime($record['start_at'])) ?>
                                            <?php endif; ?>
                                        </p>
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

            <section class="panel quick-panel">
                <div class="section-heading">
                    <div>
                        <h2>Quick Actions</h2>
                        <p>Common student activities.</p>
                    </div>
                </div>

                <a class="quick-action"
                    href="<?= $baseUrl ?>/student/attendance/scan.php">
                    <span class="quick-icon">▦</span>
                    <span>
                        <strong>Scan Event Barcode</strong>
                        <small>Submit your event attendance</small>
                    </span>
                    <span class="action-arrow">→</span>
                </a>

                <a class="quick-action"
                    href="<?= $baseUrl ?>/student/attendance/history.php">
                    <span class="quick-icon">◷</span>
                    <span>
                        <strong>Attendance History</strong>
                        <small>Review your past records</small>
                    </span>
                    <span class="action-arrow">→</span>
                </a>

                <a class="quick-action"
                    href="<?= $baseUrl ?>/student/appeals/index.php">
                    <span class="quick-icon">✎</span>
                    <span>
                        <strong>Attendance Appeals</strong>
                        <small>Submit or review an appeal</small>
                    </span>
                    <span class="action-arrow">→</span>
                </a>

                <a class="quick-action"
                    href="<?= $baseUrl ?>/student/profile/index.php">
                    <span class="quick-icon">♙</span>
                    <span>
                        <strong>My Profile</strong>
                        <small>View your student information</small>
                    </span>
                    <span class="action-arrow">→</span>
                </a>
            </section>
        </div>

        <footer class="dashboard-footer">
            <span>WEAMMS · WESTMEAD INTERNATIONAL SCHOOL</span>
            <span>Student Account</span>
        </footer>
    </main>
</body>

</html>