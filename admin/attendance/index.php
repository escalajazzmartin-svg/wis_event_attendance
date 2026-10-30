<?php

$adminPageTitle = 'Attendance Monitoring';
$adminRefreshCss = true;
require_once __DIR__ . '/../../includes/admin_layout_start.php';

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$departmentId = filter_var($_GET['department_id'] ?? null, FILTER_VALIDATE_INT);
$departmentId = $departmentId !== false && $departmentId > 0 ? $departmentId : 0;
$periodId = filter_var($_GET['academic_period_id'] ?? null, FILTER_VALIDATE_INT);
$periodId = $periodId !== false && $periodId > 0 ? $periodId : 0;
$eventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
$eventId = $eventId !== false && $eventId > 0 ? $eventId : 0;
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
    $conditions[] = '(r.student_number LIKE :search_number OR r.first_name LIKE :search_first OR r.last_name LIKE :search_last OR e.event_title LIKE :search_event OR d.department_name LIKE :search_department)';
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
if ($eventId > 0) {
    $conditions[] = 'e.event_id = :event_id';
    $params['event_id'] = $eventId;
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
     INNER JOIN event_attendance_roster r
        ON r.event_id = ea.event_id AND r.student_id = ea.student_id
     $where"
);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$recordsStmt = $pdo->prepare(
    "SELECT ea.attendance_id, ea.attendance_status, ea.check_in_at, ea.record_source, ea.remarks,
            e.event_id, e.event_title, e.start_at, d.department_name, d.department_code, ap.academic_year, ap.semester,
            r.student_id, r.student_number, r.first_name, r.middle_name, r.last_name,
            u.username AS recorded_by
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN departments d ON d.department_id = e.department_id
     INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
     INNER JOIN event_attendance_roster r
        ON r.event_id = ea.event_id AND r.student_id = ea.student_id
     LEFT JOIN users u ON u.user_id = ea.recorded_by_user_id
     $where
     ORDER BY e.start_at DESC, r.last_name, r.first_name
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
            COUNT(DISTINCT r.student_id) AS eligible_students,
            COUNT(DISTINCT ea.student_id) AS recorded_students,
            COUNT(ea.attendance_id) - COUNT(DISTINCT ea.student_id) AS duplicate_records
     FROM events e
     INNER JOIN departments d ON d.department_id = e.department_id
     LEFT JOIN event_attendance_roster r ON r.event_id = e.event_id
     LEFT JOIN event_attendance ea ON ea.event_id = e.event_id AND ea.student_id = r.student_id
     WHERE e.event_status IN ('approved', 'completed') AND e.end_at < NOW()
     GROUP BY e.event_id, e.event_title, e.start_at, d.department_name
     ORDER BY e.start_at DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="admin-content-section admin-refresh admin-attendance-page">
    <div class="admin-page-heading"><div><span class="admin-eyebrow">Admin · Monitoring</span><h2>Attendance Monitoring</h2><p>Search QR submissions, review statuses, and identify missing or duplicate records.</p></div></div>
    <?php
    $statusOverview = ['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused'];
    $overviewTotal = (int) $summary['records'];
    $overviewQuery = array_filter(
        ['q' => $search, 'event_id' => $eventId, 'department_id' => $departmentId, 'academic_period_id' => $periodId, 'date_from' => $dateFrom, 'date_to' => $dateTo],
        static fn ($value): bool => $value !== '' && $value !== 0
    );
    $overviewLabel = $overviewTotal === 0
        ? 'No attendance records yet'
        : implode(', ', array_map(static fn (string $key, string $name): string => (int) $summary[$key] . ' ' . strtolower($name), array_keys($statusOverview), $statusOverview));
    ?>
    <section class="admin-panel admin-records-panel">
        <div class="admin-panel-heading"><div><h3>Attendance records</h3><p>Showing <?= $totalRecords === 0 ? 0 : $offset + 1 ?>–<?= min($totalRecords, $offset + count($records)) ?> of <?= $totalRecords ?> matching entries.</p></div><span class="admin-records-count"><?= $totalRecords ?> <?= $totalRecords === 1 ? 'record' : 'records' ?></span></div>
        <form class="admin-filters admin-filters-compact" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/attendance/index.php">
            <?php if ($eventId > 0): ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><?php endif; ?>
            <label class="admin-search-field"><span class="visually-hidden">Search attendance</span><input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Student, event, or department"></label>
            <label><span class="visually-hidden">Department</span><select name="department_id"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['department_id'] ?>" <?= $departmentId === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Academic period</span><select name="academic_period_id"><option value="">All semesters / years</option><?php foreach ($periods as $period): ?><option value="<?= (int) $period['academic_period_id'] ?>" <?= $periodId === (int) $period['academic_period_id'] ? 'selected' : '' ?>><?= adminEscape($period['academic_year'] . ' · ' . $period['semester']) ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Attendance status</span><select name="status"><option value="">All statuses</option><?php foreach (['present', 'late', 'absent', 'excused'] as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Event date from</span><input type="date" name="date_from" value="<?= adminEscape($dateFrom) ?>"></label>
            <label><span class="visually-hidden">Event date to</span><input type="date" name="date_to" value="<?= adminEscape($dateTo) ?>"></label>
            <button class="admin-filter-button admin-filter-button-sm" type="submit">Filter</button>
            <a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/attendance/index.php">Clear</a>
        </form>
        <?php if ($records === []): ?><div class="admin-empty-state"><strong>No attendance records found</strong><p>Try changing your filters.</p></div><?php else: ?>
            <div class="admin-table-wrap admin-records-scroll" tabindex="0" role="region" aria-label="Attendance records, scrollable"><table class="admin-table"><thead><tr><th>Student</th><th>Event</th><th>Department</th><th>Period</th><th>Status</th><th>Timestamp / source</th><th>Recorded by</th><th>History</th></tr></thead><tbody>
                <?php foreach ($records as $record): ?>
                    <tr><td data-label="Student"><?php $studentFullName = trim($record['first_name'] . ' ' . ($record['middle_name'] ? $record['middle_name'] . ' ' : '') . $record['last_name']); ?><div class="admin-person"><span class="admin-person-avatar" aria-hidden="true"><?= adminEscape(mb_strtoupper(mb_substr($record['first_name'], 0, 1) . mb_substr($record['last_name'], 0, 1))) ?></span><div><?= adminEscape($studentFullName) ?><small><?= adminEscape($record['student_number']) ?></small></div></div></td><td data-label="Event"><?= adminEscape($record['event_title']) ?><small><?= adminEscape(date('M j, Y', strtotime($record['start_at']))) ?></small></td><td data-label="Department" title="<?= adminEscape($record['department_name']) ?>"><?= adminEscape($record['department_code']) ?></td><td data-label="Period"><?= adminEscape($record['academic_year'] . ' · ' . $record['semester']) ?></td><td data-label="Status"><span class="admin-status-badge admin-status-<?= adminEscape($record['attendance_status']) ?>"><?= adminEscape(ucfirst($record['attendance_status'])) ?></span></td><td data-label="Timestamp / source"><span title="Source: <?= adminEscape(ucwords(str_replace(['_', 'barcode'], [' ', 'QR code'], $record['record_source']))) ?>"><?= $record['check_in_at'] ? adminEscape(date('M j, g:i A', strtotime($record['check_in_at']))) : '—' ?></span></td><td data-label="Recorded by"><?= adminEscape($record['recorded_by'] ?: 'System') ?></td><td data-label="History"><a href="<?= adminEscape($adminBaseUrl) ?>/admin/attendance/history.php?id=<?= (int) $record['attendance_id'] ?>" aria-label="View attendance history">View</a></td></tr>
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
        <section class="admin-status-overview" aria-labelledby="admin-status-overview-title">
            <div class="admin-status-head">
                <h3 id="admin-status-overview-title">Status overview</h3>
                <p><strong><?= $overviewTotal ?></strong> <?= $overviewTotal === 1 ? 'record' : 'records' ?> across all events. Select a status to filter the records above.</p>
            </div>
            <div class="admin-bar-track" role="img" aria-label="<?= adminEscape($overviewLabel) ?>">
                <?php if ($overviewTotal > 0): foreach ($statusOverview as $key => $name): if ((int) $summary[$key] > 0): ?>
                    <span class="bar-<?= $key ?>" style="width: <?= round((int) $summary[$key] / $overviewTotal * 100, 2) ?>%"></span>
                <?php endif; endforeach; endif; ?>
            </div>
            <nav class="admin-status-list" aria-label="Filter attendance records by status">
                <?php foreach ($statusOverview as $key => $name):
                    $isActive = $status === $key;
                    $linkQuery = $isActive ? $overviewQuery : $overviewQuery + ['status' => $key];
                    $count = (int) $summary[$key];
                    $share = $overviewTotal > 0 ? round($count / $overviewTotal * 100) : 0;
                    ?>
                    <a class="admin-status-item<?= $isActive ? ' is-active' : '' ?>" href="<?= adminEscape($adminBaseUrl) ?>/admin/attendance/index.php<?= $linkQuery === [] ? '' : '?' . adminEscape(http_build_query($linkQuery)) ?>"<?= $isActive ? ' aria-current="true"' : '' ?>>
                        <span class="admin-status-name"><i class="bar-<?= $key ?>" aria-hidden="true"></i><?= adminEscape($name) ?></span>
                        <span class="admin-status-figure"><strong><?= $count ?></strong><small><?= $share ?>%</small></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </section>
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
