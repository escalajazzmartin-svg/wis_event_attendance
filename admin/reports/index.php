<?php

$adminPageTitle = 'Reports and Analytics';
require_once __DIR__ . '/../../includes/admin_layout_start.php';

$report = $_GET['report'] ?? 'events';
if (!is_string($report) || !in_array($report, ['events', 'attendance', 'students', 'departments'], true)) {
    $report = 'events';
}
$academicYear = trim(is_string($_GET['academic_year'] ?? null) ? $_GET['academic_year'] : '');
$semester = $_GET['semester'] ?? '';
if (!is_string($semester) || !in_array($semester, ['', '1st', '2nd', 'Summer', 'Other'], true)) {
    $semester = '';
}
$departmentId = filter_var($_GET['department_id'] ?? null, FILTER_VALIDATE_INT);
$departmentId = $departmentId !== false && $departmentId > 0 ? $departmentId : 0;
$conditions = [];
$params = [];
if ($academicYear !== '') {
    $conditions[] = 'ap.academic_year = :academic_year';
    $params['academic_year'] = $academicYear;
}
if ($semester !== '') {
    $conditions[] = 'ap.semester = :semester';
    $params['semester'] = $semester;
}
if ($departmentId > 0) {
    $conditions[] = ($report === 'students' ? 's.department_id' : 'e.department_id') . ' = :department_id';
    $params['department_id'] = $departmentId;
}
$where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);
$years = $pdo->query('SELECT DISTINCT academic_year FROM academic_periods ORDER BY academic_year DESC')->fetchAll(PDO::FETCH_COLUMN);

if ($report === 'students') {
    $sql = "SELECT d.department_name, s.student_number,
                   CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) AS student_name,
                   COUNT(DISTINCT ea.event_id) AS recorded_events,
                   COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
                   COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
                   COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
                   COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused
            FROM students s
            INNER JOIN departments d ON d.department_id = s.department_id
            LEFT JOIN event_attendance ea ON ea.student_id = s.student_id
            LEFT JOIN events e ON e.event_id = ea.event_id
            LEFT JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
            $where
            GROUP BY s.student_id, d.department_name, s.student_number,
                     s.first_name, s.middle_name, s.last_name
            ORDER BY d.department_name, s.last_name, s.first_name";
} elseif ($report === 'attendance') {
    $sql = "SELECT d.department_name, e.event_id, e.event_title, e.start_at,
                   COUNT(DISTINCT ea.student_id) AS total_records,
                   COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
                   COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
                   COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
                   COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused,
                   (SELECT COUNT(*) FROM students eligible
                    WHERE eligible.department_id = e.department_id
                      AND eligible.enrollment_status = 'enrolled'
                      AND (
                        NOT EXISTS (SELECT 1 FROM event_participants ep0 WHERE ep0.event_id = e.event_id)
                        OR EXISTS (
                            SELECT 1 FROM event_participants ep
                            WHERE ep.event_id = e.event_id
                              AND ((ep.participant_type = 'student' AND ep.student_id = eligible.student_id)
                                OR (ep.participant_type = 'year_level' AND ep.year_level = eligible.year_level)
                                OR (ep.participant_type = 'section' AND ep.year_level = eligible.year_level AND ep.section = eligible.section))
                        )
                      )
                   ) AS eligible_students
            FROM events e
            INNER JOIN departments d ON d.department_id = e.department_id
            INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
            LEFT JOIN event_attendance ea ON ea.event_id = e.event_id
            $where
            GROUP BY e.event_id, d.department_name, e.event_title, e.start_at
            ORDER BY e.start_at DESC";
} elseif ($report === 'departments') {
    $sql = "SELECT summary.department_id, summary.department_name,
                   COUNT(*) AS total_events, SUM(summary.eligible_students) AS eligible_students,
                   SUM(summary.total_records) AS total_records,
                   SUM(summary.present) AS present, SUM(summary.late) AS late,
                   SUM(summary.absent) AS absent, SUM(summary.excused) AS excused
            FROM (
                SELECT d.department_id, d.department_name, e.event_id,
                       COUNT(DISTINCT ea.student_id) AS total_records,
                       COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
                       COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
                       COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
                       COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused,
                       (SELECT COUNT(*) FROM students eligible
                        WHERE eligible.department_id = e.department_id
                          AND eligible.enrollment_status = 'enrolled'
                          AND (
                            NOT EXISTS (SELECT 1 FROM event_participants ep0 WHERE ep0.event_id = e.event_id)
                            OR EXISTS (
                                SELECT 1 FROM event_participants ep
                                WHERE ep.event_id = e.event_id
                                  AND ((ep.participant_type = 'student' AND ep.student_id = eligible.student_id)
                                    OR (ep.participant_type = 'year_level' AND ep.year_level = eligible.year_level)
                                    OR (ep.participant_type = 'section' AND ep.year_level = eligible.year_level AND ep.section = eligible.section))
                            )
                          )
                       ) AS eligible_students
                FROM events e
                INNER JOIN departments d ON d.department_id = e.department_id
                INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
                LEFT JOIN event_attendance ea ON ea.event_id = e.event_id
                WHERE e.event_status IN ('approved', 'completed') " . ($conditions === [] ? '' : 'AND ' . implode(' AND ', $conditions)) . "
                GROUP BY e.event_id, d.department_id, d.department_name
            ) summary
            GROUP BY summary.department_id, summary.department_name
            ORDER BY summary.department_name";
} else {
    $sql = "SELECT d.department_name,
                   COUNT(e.event_id) AS total_events,
                   COALESCE(SUM(e.event_status = 'pending'), 0) AS pending,
                   COALESCE(SUM(e.event_status = 'approved'), 0) AS approved,
                   COALESCE(SUM(e.event_status = 'rejected'), 0) AS rejected,
                   COALESCE(SUM(e.event_status = 'cancelled'), 0) AS cancelled,
                   COALESCE(SUM(e.event_status = 'completed' OR (e.event_status = 'approved' AND e.end_at < NOW())), 0) AS completed
            FROM departments d
            LEFT JOIN events e ON e.department_id = d.department_id
            LEFT JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
            " . ($conditions === [] ? '' : 'WHERE ' . str_replace('e.department_id', 'd.department_id', implode(' AND ', $conditions))) . "
            GROUP BY d.department_id
            ORDER BY d.department_name";
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$exportParams = $_GET;
unset($exportParams['notice']);
?>
<section class="admin-content-section">
    <div class="admin-page-heading"><div><span class="admin-eyebrow">School performance</span><h2>Reports and Analytics</h2><p>Review event volumes and attendance across departments and academic periods.</p></div></div>
    <section class="admin-panel admin-report-controls">
        <form class="admin-filters" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/reports/index.php">
            <label><span class="visually-hidden">Report type</span><select name="report"><option value="events" <?= $report === 'events' ? 'selected' : '' ?>>Events per department</option><option value="attendance" <?= $report === 'attendance' ? 'selected' : '' ?>>Event attendance</option><option value="departments" <?= $report === 'departments' ? 'selected' : '' ?>>Attendance by department</option><option value="students" <?= $report === 'students' ? 'selected' : '' ?>>Student summaries</option></select></label>
            <label><span class="visually-hidden">Academic year</span><select name="academic_year"><option value="">All academic years</option><?php foreach ($years as $year): ?><option value="<?= adminEscape($year) ?>" <?= $academicYear === $year ? 'selected' : '' ?>><?= adminEscape($year) ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Semester</span><select name="semester"><option value="">All semesters</option><?php foreach (['1st', '2nd', 'Summer', 'Other'] as $option): ?><option value="<?= $option ?>" <?= $semester === $option ? 'selected' : '' ?>><?= $option === 'Other' ? $option : $option . ' semester' ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Department</span><select name="department_id"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['department_id'] ?>" <?= $departmentId === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option><?php endforeach; ?></select></label>
            <button class="admin-filter-button" type="submit">Apply filters</button>
            <a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/reports/index.php">Clear</a>
        </form>
        <div class="admin-report-actions">
            <a class="admin-action-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/reports/export.php?<?= adminEscape(http_build_query($exportParams)) ?>">Export CSV</a>
            <button class="admin-action-link admin-print-button" type="button" onclick="window.print()">Print / Save PDF</button>
        </div>
    </section>
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><h3><?= $report === 'students' ? 'Student attendance summaries' : ($report === 'attendance' ? 'Attendance by event' : ($report === 'departments' ? 'Attendance comparisons by department' : 'Event totals by department')) ?></h3><p>Filtered to the selected department and academic period.</p></div></div>
        <?php if ($rows === []): ?><div class="admin-empty-state"><strong>No report data found</strong><p>Try a different academic period or department.</p></div><?php elseif ($report === 'students'): ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Student</th><th>Department</th><th>Events recorded</th><th>Present</th><th>Late</th><th>Absent</th><th>Excused</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td data-label="Student"><?= adminEscape($row['student_name']) ?><small><?= adminEscape($row['student_number']) ?></small></td><td data-label="Department"><?= adminEscape($row['department_name']) ?></td><td data-label="Events recorded"><?= (int) $row['recorded_events'] ?></td><td data-label="Present"><?= (int) $row['present'] ?></td><td data-label="Late"><?= (int) $row['late'] ?></td><td data-label="Absent"><?= (int) $row['absent'] ?></td><td data-label="Excused"><?= (int) $row['excused'] ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php elseif ($report === 'attendance'): ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Event</th><th>Department</th><th>Records / eligible</th><th>Present</th><th>Late</th><th>Absent</th><th>Excused</th><th>Attendance rate</th></tr></thead><tbody><?php foreach ($rows as $row): $eligible = (int) $row['eligible_students']; $rate = $eligible > 0 ? round(((int) $row['present'] + (int) $row['late'] + (int) $row['excused']) / $eligible * 100, 1) : 0; ?><tr><td data-label="Event"><?= adminEscape($row['event_title']) ?><small><?= adminEscape(date('M j, Y', strtotime($row['start_at']))) ?></small></td><td data-label="Department"><?= adminEscape($row['department_name']) ?></td><td data-label="Records / eligible"><?= (int) $row['total_records'] ?> / <?= $eligible ?></td><td data-label="Present"><?= (int) $row['present'] ?></td><td data-label="Late"><?= (int) $row['late'] ?></td><td data-label="Absent"><?= (int) $row['absent'] ?></td><td data-label="Excused"><?= (int) $row['excused'] ?></td><td data-label="Attendance rate"><?= $rate ?>%</td></tr><?php endforeach; ?></tbody></table></div>
        <?php elseif ($report === 'departments'): ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Department</th><th>Events</th><th>Records / eligible</th><th>Present</th><th>Late</th><th>Absent</th><th>Excused</th><th>Average attendance</th></tr></thead><tbody><?php foreach ($rows as $row): $eligible = (int) $row['eligible_students']; $rate = $eligible > 0 ? round(((int) $row['present'] + (int) $row['late'] + (int) $row['excused']) / $eligible * 100, 1) : 0; ?><tr><td data-label="Department"><?= adminEscape($row['department_name']) ?></td><td data-label="Events"><?= (int) $row['total_events'] ?></td><td data-label="Records / eligible"><?= (int) $row['total_records'] ?> / <?= $eligible ?></td><td data-label="Present"><?= (int) $row['present'] ?></td><td data-label="Late"><?= (int) $row['late'] ?></td><td data-label="Absent"><?= (int) $row['absent'] ?></td><td data-label="Excused"><?= (int) $row['excused'] ?></td><td data-label="Average attendance"><?= $rate ?>%</td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Department</th><th>Events</th><th>Pending</th><th>Approved</th><th>Rejected</th><th>Cancelled</th><th>Completed</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td data-label="Department"><?= adminEscape($row['department_name']) ?></td><td data-label="Events"><?= (int) $row['total_events'] ?></td><td data-label="Pending"><?= (int) $row['pending'] ?></td><td data-label="Approved"><?= (int) $row['approved'] ?></td><td data-label="Rejected"><?= (int) $row['rejected'] ?></td><td data-label="Cancelled"><?= (int) $row['cancelled'] ?></td><td data-label="Completed"><?= (int) $row['completed'] ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
