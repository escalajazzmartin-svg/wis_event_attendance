<?php

$pageTitle = 'Attendance Monitoring';
$pageClass = 'attendance-monitoring-page';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$eventStatuses = ['pending', 'approved', 'rejected', 'cancellation_requested', 'cancelled', 'completed'];
$statusFilter = $_GET['status'] ?? '';
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$dateFilter = $_GET['date'] ?? '';
$academicYearFilter = $_GET['academic_year'] ?? '';
$semesterFilter = $_GET['semester'] ?? '';
if (!is_string($dateFilter) || ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter))) {
    $dateFilter = '';
}
if (!is_string($academicYearFilter)) {
    $academicYearFilter = '';
}
if (!is_string($semesterFilter)) {
    $semesterFilter = '';
}
$periodStmt = $pdo->query(
    'SELECT DISTINCT academic_year, semester FROM academic_periods ORDER BY academic_year DESC, semester'
);
$periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

if (!is_string($statusFilter) || !in_array($statusFilter, $eventStatuses, true)) {
    $statusFilter = '';
}

$sql = "
    SELECT e.event_id, e.event_title, e.venue, e.start_at, e.end_at,
           e.event_status, ap.academic_year, ap.semester,
           COALESCE(a.record_count, 0) AS record_count,
           COALESCE(a.present_count, 0) AS present_count,
           COALESCE(a.late_count, 0) AS late_count,
           COALESCE(a.absent_count, 0) AS absent_count,
           COALESCE(a.excused_count, 0) AS excused_count
    FROM events e
    INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
    LEFT JOIN (
        SELECT ea.event_id,
               COUNT(*) AS record_count,
               SUM(ea.attendance_status = 'present') AS present_count,
               SUM(ea.attendance_status = 'late') AS late_count,
               SUM(ea.attendance_status = 'absent') AS absent_count,
               SUM(ea.attendance_status = 'excused') AS excused_count
        FROM event_attendance ea
        INNER JOIN students s ON s.student_id = ea.student_id
        INNER JOIN events scoped_event ON scoped_event.event_id = ea.event_id
        WHERE scoped_event.department_id = :stats_department_id
          AND s.department_id = scoped_event.department_id
        GROUP BY ea.event_id
    ) a ON a.event_id = e.event_id
    WHERE e.department_id = :department_id";
$params = [
    'stats_department_id' => $departmentId,
    'department_id' => $departmentId,
];

if ($statusFilter !== '') {
    $sql .= ' AND e.event_status = :event_status';
    $params['event_status'] = $statusFilter;
}

if ($search !== '') {
    $sql .= ' AND (e.event_title LIKE :search_title OR e.venue LIKE :search_venue)';
    $searchTerm = '%' . $search . '%';
    $params['search_title'] = $searchTerm;
    $params['search_venue'] = $searchTerm;
}
if ($dateFilter !== '') {
    $sql .= ' AND DATE(e.start_at) = :event_date';
    $params['event_date'] = $dateFilter;
}
if ($academicYearFilter !== '') {
    $sql .= ' AND ap.academic_year = :academic_year';
    $params['academic_year'] = $academicYearFilter;
}
if ($semesterFilter !== '') {
    $sql .= ' AND ap.semester = :semester';
    $params['semester'] = $semesterFilter;
}

$sql .= ' ORDER BY e.start_at DESC, e.event_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT e.event_id) AS event_count,
            COUNT(s.student_id) AS record_count,
            COALESCE(SUM(s.student_id IS NOT NULL AND ea.attendance_status = 'present'), 0) AS present_count,
            COALESCE(SUM(s.student_id IS NOT NULL AND ea.attendance_status = 'late'), 0) AS late_count,
            COALESCE(SUM(s.student_id IS NOT NULL AND ea.attendance_status = 'absent'), 0) AS absent_count,
            COALESCE(SUM(s.student_id IS NOT NULL AND ea.attendance_status = 'excused'), 0) AS excused_count
     FROM events e
     LEFT JOIN event_attendance ea ON ea.event_id = e.event_id
     LEFT JOIN students s
        ON s.student_id = ea.student_id
       AND s.department_id = e.department_id
     WHERE e.department_id = ?"
);
$summaryStmt->execute([$departmentId]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$notices = [
    'updated' => 'Attendance record updated.',
];
$notice = is_string($_GET['notice'] ?? null)
    ? ($notices[$_GET['notice']] ?? '')
    : '';
?>

<section class="dashboard-section management-page attendance-monitoring-content">
    <div class="section-heading">
        <div>
            <h2>Attendance Monitoring</h2>
            <p>Review attendance records for events in your department.</p>
        </div>
        <div class="management-form-actions">
            <a class="management-button management-button-secondary" href="<?= dhEscape($baseUrl) ?>/department-head/attendance-settings/index.php">Attendance time rules</a>
            <a class="management-button" href="<?= dhEscape($baseUrl) ?>/department-head/appeals/index.php">Review appeals</a>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="management-alert management-alert-success" role="status"><?= dhEscape($notice) ?></div>
    <?php endif; ?>

    <div class="attendance-monitor-summary">
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
            <div><span>Department events</span><strong><?= (int) $summary['event_count'] ?></strong></div>
        </article>
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon monitor-icon-blue"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span>
            <div><span>Attendance records</span><strong><?= (int) $summary['record_count'] ?></strong></div>
        </article>
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon monitor-icon-green"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span>
            <div><span>Present</span><strong><?= (int) $summary['present_count'] ?></strong></div>
        </article>
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon monitor-icon-gold"><i class="fa-solid fa-clock" aria-hidden="true"></i></span>
            <div><span>Late</span><strong><?= (int) $summary['late_count'] ?></strong></div>
        </article>
    </div>

    <div class="attendance-monitor-panel">
        <div class="attendance-monitor-panel-heading">
            <div>
                <h3>Event attendance</h3>
                <p><?= count($events) ?> matching <?= count($events) === 1 ? 'event' : 'events' ?></p>
            </div>
        </div>

        <form class="management-filter attendance-monitor-filter" method="get" action="<?= dhEscape($baseUrl) ?>/department-head/attendance/index.php">
            <label class="attendance-monitor-search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= dhEscape($search) ?>" placeholder="Search event or venue">
            </label>
            <select id="event-status" name="status" onchange="this.form.requestSubmit()">
                <option value="">All statuses</option>
                <?php foreach ($eventStatuses as $status): ?>
                    <option value="<?= dhEscape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= dhEscape(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="event-date">Event date</label>
            <input id="event-date" type="date" name="date" value="<?= dhEscape($dateFilter) ?>" onchange="this.form.requestSubmit()">
            <label for="academic-year">Academic year</label>
            <select id="academic-year" name="academic_year" onchange="this.form.requestSubmit()">
                <option value="">All years</option>
                <?php foreach (array_unique(array_column($periods, 'academic_year')) as $academicYear): ?>
                    <option value="<?= dhEscape($academicYear) ?>" <?= $academicYearFilter === $academicYear ? 'selected' : '' ?>><?= dhEscape($academicYear) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="semester">Semester</label>
            <select id="semester" name="semester" onchange="this.form.requestSubmit()">
                <option value="">All semesters</option>
                <?php foreach (array_unique(array_column($periods, 'semester')) as $semester): ?>
                    <option value="<?= dhEscape($semester) ?>" <?= $semesterFilter === $semester ? 'selected' : '' ?>><?= dhEscape($semester) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($search !== '' || $statusFilter !== '' || $dateFilter !== '' || $academicYearFilter !== '' || $semesterFilter !== ''): ?>
                <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/index.php">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($events === []): ?>
            <div class="department-panel empty-state">
                <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>
                <strong>No matching events</strong>
                <p>Events and their attendance summaries will appear here.</p>
            </div>
        <?php else: ?>
            <div class="management-table-wrap attendance-event-scroll">
                <table class="management-table">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Date</th>
                            <th scope="col">Event status</th>
                            <th scope="col">Attendance summary</th>
                            <th scope="col">Records</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php $eventStatus = strtolower($event['event_status']); ?>
                            <tr>
                                <td data-label="Event">
                                    <strong><?= dhEscape($event['event_title']) ?></strong>
                                    <span><?= dhEscape($event['venue'] ?: 'Venue not specified') ?></span>
                                    <span><?= dhEscape($event['academic_year']) ?> · <?= dhEscape($event['semester']) ?></span>
                                </td>
                                <td data-label="Date">
                                    <?= dhEscape(date('M j, Y', strtotime($event['start_at']))) ?>
                                    <span><?= dhEscape(date('g:i A', strtotime($event['start_at']))) ?>–<?= dhEscape(date('g:i A', strtotime($event['end_at']))) ?></span>
                                </td>
                                <td data-label="Event status">
                                    <span class="status-pill status-<?= dhEscape($eventStatus) ?>">
                                        <?= dhEscape(ucfirst($eventStatus)) ?>
                                    </span>
                                </td>
                                <td data-label="Attendance summary">
                                    <div class="attendance-status-counts">
                                        <span class="monitor-count-present">Present <strong><?= (int) $event['present_count'] ?></strong></span>
                                        <span class="monitor-count-late">Late <strong><?= (int) $event['late_count'] ?></strong></span>
                                        <span class="monitor-count-absent">Absent <strong><?= (int) $event['absent_count'] ?></strong></span>
                                        <span class="monitor-count-excused">Excused <strong><?= (int) $event['excused_count'] ?></strong></span>
                                    </div>
                                </td>
                                <td data-label="Records">
                                    <a class="management-button management-button-secondary" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/records.php?event_id=<?= (int) $event['event_id'] ?>">
                                        View <?= (int) $event['record_count'] ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>