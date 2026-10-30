<?php

$pageTitle = 'Attendance Records';
$pageClass = 'attendance-records-page';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$eventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
if ($eventId === false || $eventId < 1) {
    http_response_code(400);
    exit('Select a valid event to view its attendance records.');
}

$eventStmt = $pdo->prepare(
    'SELECT e.event_id, e.event_title, e.venue, e.start_at, e.end_at,
            e.event_status, ap.academic_year, ap.semester
     FROM events e
     INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
     WHERE e.event_id = ? AND e.department_id = ?
     LIMIT 1'
);
$eventStmt->execute([$eventId, $departmentId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    exit('Event not found in your department.');
}

$attendanceStatuses = ['present', 'late', 'absent', 'excused'];
$statusFilter = $_GET['status'] ?? '';
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');

if (
    !is_string($statusFilter) ||
    ($statusFilter !== '' && $statusFilter !== 'not_recorded' && !in_array($statusFilter, $attendanceStatuses, true))
) {
    $statusFilter = '';
}

$sql = "
    SELECT r.student_id, r.student_number, r.first_name, r.middle_name,
           r.last_name, r.year_level, r.section,
           ea.attendance_id, ea.attendance_status, ea.check_in_at,
           ea.record_source, ea.remarks, recorder.username AS recorded_by
    FROM event_attendance_roster r
    LEFT JOIN event_attendance ea
        ON ea.student_id = r.student_id
       AND ea.event_id = r.event_id
    LEFT JOIN users recorder ON recorder.user_id = ea.recorded_by_user_id
    WHERE r.event_id = :record_event_id";
$params = [
    'record_event_id' => $eventId,
];

if ($statusFilter === 'not_recorded') {
    $sql .= ' AND ea.attendance_id IS NULL';
} elseif ($statusFilter !== '') {
    $sql .= ' AND ea.attendance_status = :attendance_status';
    $params['attendance_status'] = $statusFilter;
}

if ($search !== '') {
    $sql .= " AND (
        r.student_number LIKE :search_number
        OR r.first_name LIKE :search_first
        OR r.middle_name LIKE :search_middle
        OR r.last_name LIKE :search_last
        OR r.section LIKE :search_section
        OR r.year_level LIKE :search_year
    )";
    $searchTerm = '%' . $search . '%';
    $params += [
        'search_number' => $searchTerm,
        'search_first' => $searchTerm,
        'search_middle' => $searchTerm,
        'search_last' => $searchTerm,
        'search_section' => $searchTerm,
        'search_year' => $searchTerm,
    ];
}

$sql .= ' ORDER BY r.last_name, r.first_name, r.student_number';
$recordsStmt = $pdo->prepare($sql);
$recordsStmt->execute($params);
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->prepare(
    "SELECT COUNT(*) AS roster_count,
            COALESCE(SUM(ea.attendance_id IS NOT NULL), 0) AS recorded_count,
            COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present_count,
            COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late_count,
            COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent_count,
            COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused_count
     FROM event_attendance_roster r
     LEFT JOIN event_attendance ea
        ON ea.student_id = r.student_id
       AND ea.event_id = r.event_id
     WHERE r.event_id = :summary_event_id"
);
$summaryStmt->execute([
    'summary_event_id' => $eventId,
]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
$notRecordedCount = max(0, (int) $summary['roster_count'] - (int) $summary['recorded_count']);
?>

<section class="dashboard-section management-page attendance-monitoring-content">
    <div class="section-heading">
        <div>
            <h2>Attendance Records</h2>
            <p>
                <?= dhEscape($event['event_title']) ?>
                · <?= dhEscape(date('M j, Y', strtotime($event['start_at']))) ?>
                · <?= dhEscape($event['academic_year']) ?> / <?= dhEscape($event['semester']) ?>
            </p>
        </div>
        <a class="management-button management-button-secondary" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/index.php">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            All Events
        </a>
        <a class="management-button management-button-secondary" href="<?= dhEscape($baseUrl) ?>/department-head/attendance-settings/index.php">Attendance rules</a>
        <a class="management-button management-button-secondary" href="<?= dhEscape($baseUrl) ?>/department-head/appeals/index.php">Review appeals</a>
        <?php if (strtolower($event['event_status']) === 'approved'): ?>
            <a class="management-button" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/scan.php?event_id=<?= (int) $eventId ?>">Scan QR code</a>
        <?php endif; ?>
    </div>

    <div class="attendance-monitor-summary attendance-record-summary">
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
            <div><span>Students in roster</span><strong><?= (int) $summary['roster_count'] ?></strong></div>
        </article>
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon monitor-icon-green"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span>
            <div><span>Present</span><strong><?= (int) $summary['present_count'] ?></strong></div>
        </article>
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon monitor-icon-gold"><i class="fa-solid fa-clock" aria-hidden="true"></i></span>
            <div><span>Late</span><strong><?= (int) $summary['late_count'] ?></strong></div>
        </article>
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon monitor-icon-red"><i class="fa-solid fa-user-xmark" aria-hidden="true"></i></span>
            <div><span>Absent</span><strong><?= (int) $summary['absent_count'] ?></strong></div>
        </article>
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon monitor-icon-blue"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span>
            <div><span>Excused</span><strong><?= (int) $summary['excused_count'] ?></strong></div>
        </article>
        <article class="attendance-monitor-card">
            <span class="attendance-monitor-icon monitor-icon-red"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i></span>
            <div><span>Not recorded</span><strong><?= $notRecordedCount ?></strong></div>
        </article>
    </div>

    <div class="attendance-monitor-panel attendance-record-panel">
        <div class="attendance-monitor-panel-heading">
            <div>
                <h3>Student attendance</h3>
                <p><?= count($records) ?> matching <?= count($records) === 1 ? 'student' : 'students' ?> · <?= dhEscape(ucfirst($event['event_status'])) ?> event</p>
            </div>
            <span class="status-pill status-<?= dhEscape(strtolower($event['event_status'])) ?>">
                <?= dhEscape(ucfirst($event['event_status'])) ?>
            </span>
        </div>

        <form class="management-filter attendance-monitor-filter" method="get" action="<?= dhEscape($baseUrl) ?>/department-head/attendance/records.php">
            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
            <label class="attendance-monitor-search">
                <span class="visually-hidden">Search students</span>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= dhEscape($search) ?>" placeholder="Search student name, number, year or section">
            </label>
            <label for="attendance-status">Filter by attendance</label>
            <select id="attendance-status" name="status" onchange="this.form.requestSubmit()">
                <option value="">All students</option>
                <option value="not_recorded" <?= $statusFilter === 'not_recorded' ? 'selected' : '' ?>>Not recorded</option>
                <?php foreach ($attendanceStatuses as $status): ?>
                    <option value="<?= dhEscape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= dhEscape(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($search !== '' || $statusFilter !== ''): ?>
                <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/records.php?event_id=<?= (int) $eventId ?>">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($records === []): ?>
            <div class="department-panel empty-state">
                <i class="fa-solid fa-user-group" aria-hidden="true"></i>
                <strong>No matching students</strong>
                <p>Try adjusting the search or attendance filter.</p>
            </div>
        <?php else: ?>
            <div class="management-table-wrap attendance-record-scroll">
                <table class="management-table">
                    <thead>
                        <tr>
                            <th scope="col">Student</th>
                            <th scope="col">Year / section</th>
                            <th scope="col">Attendance</th>
                            <th scope="col">Check-in time</th>
                            <th scope="col">Source</th>
                            <th scope="col">Recorded by</th>
                            <th scope="col">Remarks</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <?php
                            $studentName = trim(
                                $record['first_name'] . ' ' .
                                ($record['middle_name'] ? $record['middle_name'] . ' ' : '') .
                                $record['last_name']
                            );
                            $recordStatus = $record['attendance_status'] ?? '';
                            ?>
                            <tr>
                                <td data-label="Student">
                                    <strong><?= dhEscape($studentName) ?></strong>
                                    <span><?= dhEscape($record['student_number']) ?></span>
                                </td>
                                <td data-label="Year / section">
                                    <?= dhEscape($record['year_level'] ?: '—') ?>
                                    <span><?= dhEscape($record['section'] ?: '—') ?></span>
                                </td>
                                <td data-label="Attendance">
                                    <?php if ($recordStatus === ''): ?>
                                        <span class="monitor-unrecorded">Not recorded</span>
                                    <?php else: ?>
                                        <span class="status-pill status-<?= dhEscape($recordStatus) ?>">
                                            <?= dhEscape(ucfirst($recordStatus)) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Check-in time">
                                    <?= $record['check_in_at'] ? dhEscape(date('M j, Y · g:i A', strtotime($record['check_in_at']))) : '—' ?>
                                </td>
                                <td data-label="Source">
                                    <?= $record['record_source'] ? dhEscape(ucwords(str_replace(['_', 'barcode'], [' ', 'QR code'], $record['record_source']))) : '—' ?>
                                </td>
                                <td data-label="Recorded by"><?= dhEscape($record['recorded_by'] ?: '—') ?></td>
                                <td data-label="Remarks"><?= dhEscape($record['remarks'] ?: '—') ?></td>
                                <td data-label="Actions" class="management-actions">
                                    <?php if ((int) $departmentHead['department_head_can_edit_attendance'] === 1): ?>
                                        <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/correct.php?event_id=<?= (int) $eventId ?>&amp;student_id=<?= (int) $record['student_id'] ?>">Correct</a>
                                    <?php endif; ?>
                                    <?php if ($record['attendance_id']): ?>
                                        <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/history.php?attendance_id=<?= (int) $record['attendance_id'] ?>">History</a>
                                    <?php endif; ?>
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
