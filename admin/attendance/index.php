<?php

$adminPageTitle = 'Attendance Monitoring';
require_once __DIR__ . '/../../includes/admin_layout_start.php';

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$departmentId = filter_var($_GET['department_id'] ?? null, FILTER_VALIDATE_INT);
$departmentId = $departmentId !== false && $departmentId > 0 ? $departmentId : 0;
$periodId = filter_var($_GET['academic_period_id'] ?? null, FILTER_VALIDATE_INT);
$periodId = $periodId !== false && $periodId > 0 ? $periodId : 0;
$status = $_GET['status'] ?? '';
if (!is_string($status) || !in_array($status, ['', 'present', 'late', 'absent', 'excused'], true)) {
    $status = '';
}
$dateFrom = is_string($_GET['date_from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = is_string($_GET['date_to'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']) ? $_GET['date_to'] : '';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page !== false && $page > 0 ? $page : 1;
$pageSize = 50;

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(s.student_number LIKE :search_number OR s.first_name LIKE :search_first OR s.last_name LIKE :search_last OR e.event_title LIKE :search_event OR d.department_name LIKE :search_department)';
    foreach (['number', 'first', 'last', 'event', 'department'] as $key) {
        $params['search_' . $key] = '%' . $search . '%';
    }
}
if ($departmentId > 0) {
    $conditions[] = 'd.department_id = :department_id';
    $params['department_id'] = $departmentId;
}
if ($periodId > 0) {
    $conditions[] = 'e.academic_period_id = :period_id';
    $params['period_id'] = $periodId;
}
if ($status !== '') {
    $conditions[] = 'ea.attendance_status = :attendance_status';
    $params['attendance_status'] = $status;
}
if ($dateFrom !== '') {
    $conditions[] = 'DATE(e.start_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = 'DATE(e.start_at) <= :date_to';
    $params['date_to'] = $dateTo;
}
$where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN departments d ON d.department_id = e.department_id
     INNER JOIN students s ON s.student_id = ea.student_id
     $where"
);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$recordsStmt = $pdo->prepare(
    "SELECT ea.attendance_id, ea.attendance_status, ea.check_in_at, ea.record_source, ea.remarks,
            e.event_id, e.event_title, e.start_at, d.department_name, ap.academic_year, ap.semester,
            s.student_id, s.student_number, s.first_name, s.middle_name, s.last_name,
            u.username AS recorded_by
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN departments d ON d.department_id = e.department_id
     INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
     INNER JOIN students s ON s.student_id = ea.student_id
     LEFT JOIN users u ON u.user_id = ea.recorded_by_user_id
     $where
     ORDER BY e.start_at DESC, s.last_name, s.first_name
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $name => $value) {
    $recordsStmt->bindValue(
        ':' . $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$recordsStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$recordsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$recordsStmt->execute();
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->query(
    "SELECT COUNT(*) AS records,
            COALESCE(SUM(attendance_status = 'present'), 0) AS present,
            COALESCE(SUM(attendance_status = 'late'), 0) AS late,
            COALESCE(SUM(attendance_status = 'absent'), 0) AS absent,
            COALESCE(SUM(attendance_status = 'excused'), 0) AS excused
     FROM event_attendance"
);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);
$periods = $pdo->query('SELECT academic_period_id, academic_year, semester FROM academic_periods ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$integrity = $pdo->query(
    "SELECT e.event_id, e.event_title, e.start_at, d.department_name,
            COUNT(DISTINCT CASE
                WHEN s.enrollment_status = 'enrolled'
                     AND (
                         NOT EXISTS (SELECT 1 FROM event_participants ep0 WHERE ep0.event_id = e.event_id)
                         OR EXISTS (
                             SELECT 1 FROM event_participants ep
                             WHERE ep.event_id = e.event_id
                               AND ((ep.participant_type = 'student' AND ep.student_id = s.student_id)
                                 OR (ep.participant_type = 'year_level' AND ep.year_level = s.year_level)
                                 OR (ep.participant_type = 'section' AND ep.year_level = s.year_level AND ep.section = s.section))
                         )
                     )
                THEN s.student_id END
            ) AS eligible_students,
            COUNT(DISTINCT ea.student_id) AS recorded_students,
            COUNT(ea.attendance_id) - COUNT(DISTINCT ea.student_id) AS duplicate_records
     FROM events e
     INNER JOIN departments d ON d.department_id = e.department_id
     LEFT JOIN students s ON s.department_id = e.department_id
     LEFT JOIN event_attendance ea ON ea.event_id = e.event_id AND ea.student_id = s.student_id
     WHERE e.event_status IN ('approved', 'completed') AND e.end_at < NOW()
     GROUP BY e.event_id, e.event_title, e.start_at, d.department_name
     ORDER BY e.start_at DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="admin-content-section">
    <div class="admin-page-heading"><div><span class="admin-eyebrow">School-wide records</span><h2>Attendance Monitoring</h2><p>Search barcode submissions, review statuses, and identify missing or duplicate records.</p></div></div>
    <div class="admin-summary-grid">
        <?php foreach ([['Records', 'records', ''], ['Present', 'present', 'admin-icon-green'], ['Late', 'late', 'admin-icon-gold'], ['Absent', 'absent', ''], ['Excused', 'excused', 'admin-icon-blue']] as [$label, $key, $tone]): ?>
            <article class="admin-summary-card"><span class="admin-summary-icon <?= adminEscape($tone) ?>"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span><div><span><?= adminEscape($label) ?></span><strong><?= (int) $summary[$key] ?></strong></div></article>
        <?php endforeach; ?>
    </div>
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><h3>Attendance records</h3><p>Showing <?= $totalRecords === 0 ? 0 : $offset + 1 ?>–<?= min($totalRecords, $offset + count($records)) ?> of <?= $totalRecords ?> matching entries.</p></div></div>
        <form class="admin-filters" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/attendance/index.php">
            <label class="admin-search-field"><span class="visually-hidden">Search attendance</span><input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Student, event, or department"></label>
            <label><span class="visually-hidden">Department</span><select name="department_id"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['department_id'] ?>" <?= $departmentId === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Academic period</span><select name="academic_period_id"><option value="">All semesters / years</option><?php foreach ($periods as $period): ?><option value="<?= (int) $period['academic_period_id'] ?>" <?= $periodId === (int) $period['academic_period_id'] ? 'selected' : '' ?>><?= adminEscape($period['academic_year'] . ' · ' . $period['semester']) ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Attendance status</span><select name="status"><option value="">All statuses</option><?php foreach (['present', 'late', 'absent', 'excused'] as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Event date from</span><input type="date" name="date_from" value="<?= adminEscape($dateFrom) ?>"></label>
            <label><span class="visually-hidden">Event date to</span><input type="date" name="date_to" value="<?= adminEscape($dateTo) ?>"></label>
            <button class="admin-filter-button" type="submit">Filter</button>
            <a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/attendance/index.php">Clear</a>
        </form>
        <?php if ($records === []): ?><div class="admin-empty-state"><strong>No attendance records found</strong><p>Try changing your filters.</p></div><?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Student</th><th>Event</th><th>Department</th><th>Period</th><th>Status</th><th>Timestamp / source</th><th>Recorded by</th><th>History</th></tr></thead><tbody>
                <?php foreach ($records as $record): ?>
                    <tr><td data-label="Student"><?= adminEscape(trim($record['first_name'] . ' ' . ($record['middle_name'] ? $record['middle_name'] . ' ' : '') . $record['last_name'])) ?><small><?= adminEscape($record['student_number']) ?></small></td><td data-label="Event"><?= adminEscape($record['event_title']) ?><small><?= adminEscape(date('M j, Y', strtotime($record['start_at']))) ?></small></td><td data-label="Department"><?= adminEscape($record['department_name']) ?></td><td data-label="Period"><?= adminEscape($record['academic_year'] . ' · ' . $record['semester']) ?></td><td data-label="Status"><span class="admin-status-badge admin-status-<?= adminEscape($record['attendance_status']) ?>"><?= adminEscape(ucfirst($record['attendance_status'])) ?></span></td><td data-label="Timestamp / source"><?= $record['check_in_at'] ? adminEscape(date('M j, Y · g:i A', strtotime($record['check_in_at']))) : '—' ?><small><?= adminEscape(ucwords(str_replace('_', ' ', $record['record_source']))) ?></small></td><td data-label="Recorded by"><?= adminEscape($record['recorded_by'] ?: 'System') ?></td><td data-label="History"><a href="<?= adminEscape($adminBaseUrl) ?>/admin/attendance/history.php?id=<?= (int) $record['attendance_id'] ?>">View history</a></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php if ($totalPages > 1): ?>
                <?php $paginationQuery = $_GET; ?>
                <nav class="admin-pagination" aria-label="Attendance pages">
                    <?php if ($page > 1): $paginationQuery['page'] = $page - 1; ?><a href="?<?= adminEscape(http_build_query($paginationQuery)) ?>">Previous</a><?php endif; ?>
                    <span>Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): $paginationQuery['page'] = $page + 1; ?><a href="?<?= adminEscape(http_build_query($paginationQuery)) ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <section class="admin-panel admin-integrity-panel">
        <div class="admin-panel-heading"><div><h3>Missing and duplicate record checks</h3><p>Completed or ended events compared against eligible, currently enrolled students.</p></div></div>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Event</th><th>Department</th><th>Eligible</th><th>Records</th><th>Missing</th><th>Duplicates</th></tr></thead><tbody>
            <?php foreach ($integrity as $event): $missing = max(0, (int) $event['eligible_students'] - (int) $event['recorded_students']); ?>
                <tr><td data-label="Event"><?= adminEscape($event['event_title']) ?><small><?= adminEscape(date('M j, Y', strtotime($event['start_at']))) ?></small></td><td data-label="Department"><?= adminEscape($event['department_name']) ?></td><td data-label="Eligible"><?= (int) $event['eligible_students'] ?></td><td data-label="Records"><?= (int) $event['recorded_students'] ?></td><td data-label="Missing"><?= $missing ?></td><td data-label="Duplicates"><?= (int) $event['duplicate_records'] ?></td></tr>
            <?php endforeach; ?>
            <?php if ($integrity === []): ?><tr><td colspan="6">No completed events have attendance to review.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
