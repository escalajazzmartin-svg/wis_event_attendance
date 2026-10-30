<?php

require_once __DIR__ . '/../../includes/department_head_context.php';

const DH_ATTENDANCE_PER_PAGE = 10;

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$dateOptions = ['today' => 'Today', 'upcoming' => 'Upcoming', 'past' => 'Past'];
$dateFilter = is_string($_GET['date'] ?? null) && isset($dateOptions[$_GET['date']]) ? $_GET['date'] : '';
$academicYearFilter = is_string($_GET['academic_year'] ?? null) ? trim($_GET['academic_year']) : '';
$semesterFilter = is_string($_GET['semester'] ?? null) ? trim($_GET['semester']) : '';

$periods = $pdo->query(
    'SELECT DISTINCT academic_year, semester FROM academic_periods ORDER BY academic_year DESC, semester'
)->fetchAll(PDO::FETCH_ASSOC);

$from = "
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
        INNER JOIN event_attendance_roster r
            ON r.event_id = ea.event_id AND r.student_id = ea.student_id
        INNER JOIN events scoped_event ON scoped_event.event_id = ea.event_id
        WHERE scoped_event.department_id = :stats_department_id
          AND r.department_id = scoped_event.department_id
        GROUP BY ea.event_id
    ) a ON a.event_id = e.event_id";
$where = ['e.department_id = :department_id'];
$params = ['stats_department_id' => $departmentId, 'department_id' => $departmentId];

if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where[] = '(e.event_title LIKE :search_title OR e.venue LIKE :search_venue)';
    $params['search_title'] = $like;
    $params['search_venue'] = $like;
}
if ($dateFilter === 'today') {
    $where[] = 'DATE(e.start_at) = CURDATE()';
} elseif ($dateFilter === 'upcoming') {
    $where[] = 'e.start_at > NOW()';
} elseif ($dateFilter === 'past') {
    $where[] = 'e.end_at < NOW()';
}
if ($academicYearFilter !== '') {
    $where[] = 'ap.academic_year = :academic_year';
    $params['academic_year'] = $academicYearFilter;
}
if ($semesterFilter !== '') {
    $where[] = 'ap.semester = :semester';
    $params['semester'] = $semesterFilter;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$select = 'SELECT e.event_id, e.event_title, e.venue, e.start_at, e.end_at, e.event_status,
        ap.academic_year, ap.semester,
        COALESCE(a.record_count, 0) AS record_count,
        COALESCE(a.present_count, 0) AS present_count,
        COALESCE(a.late_count, 0) AS late_count,
        COALESCE(a.absent_count, 0) AS absent_count,
        COALESCE(a.excused_count, 0) AS excused_count';

if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare($select . $from . $whereSql . ' ORDER BY e.start_at DESC, e.event_id DESC');
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="department-attendance-' . date('Ymd') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Event', 'Venue', 'Date', 'Event status', 'Present', 'Late', 'Absent', 'Excused']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Prefix cells that start with = + - @ so spreadsheets do not run them as formulas.
        fputcsv($out, array_map(static function ($cell): string {
            $cell = (string) $cell;

            return $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false ? "'" . $cell : $cell;
        }, [
            $row['event_title'],
            $row['venue'],
            date('Y-m-d', strtotime($row['start_at'])),
            $row['event_status'],
            $row['present_count'],
            $row['late_count'],
            $row['absent_count'],
            $row['excused_count'],
        ]));
    }
    fclose($out);
    exit;
}

$countStmt = $pdo->prepare('SELECT COUNT(*)' . $from . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page !== false && $page > 0 ? $page : 1;
$page = min($page, max(1, (int) ceil($total / DH_ATTENDANCE_PER_PAGE)));
$offset = ($page - 1) * DH_ATTENDANCE_PER_PAGE;

$stmt = $pdo->prepare(
    $select . $from . $whereSql . '
     ORDER BY e.start_at DESC, e.event_id DESC
     LIMIT ' . DH_ATTENDANCE_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// "Open scanner": the approved event running now, else the next approved one.
$scannerStmt = $pdo->prepare(
    "SELECT event_id FROM events
     WHERE department_id = ? AND event_status = 'approved' AND end_at >= NOW()
     ORDER BY (start_at <= NOW()) DESC, start_at ASC
     LIMIT 1"
);
$scannerStmt->execute([$departmentId]);
$scannerEventId = (int) $scannerStmt->fetchColumn();

$notice = ($_GET['notice'] ?? '') === 'updated' ? 'Attendance record updated.' : '';
$exportQuery = http_build_query(array_filter(
    ['q' => $search, 'date' => $dateFilter, 'academic_year' => $academicYearFilter, 'semester' => $semesterFilter, 'export' => 'csv'],
    static fn ($v): bool => $v !== ''
));

$pageTitle = 'Attendance';
$pageClass = 'dh-attendance-page';
$shellNavActive = 'attendance';
require_once __DIR__ . '/../../includes/department_head_shell_start.php';
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Attendance</h1>
            <p>Monitor attendance for events in <?= dhEscape($departmentHead['department_name']) ?>.</p>
        </div>
        <div class="ad-head-actions">
            <a class="ad-btn" href="?<?= dhEscape($exportQuery) ?>">Export CSV</a>
            <?php if ($scannerEventId > 0): ?>
                <a class="ad-btn ad-btn-solid" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/scan.php?event_id=<?= $scannerEventId ?>">Open scanner</a>
            <?php else: ?>
                <span class="ad-btn ad-btn-solid ad-btn-disabled" aria-disabled="true" title="No approved event to scan">Open scanner</span>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="ad-notice" role="status"><?= dhEscape($notice) ?></div>
    <?php endif; ?>

    <div class="ad-card">
        <form class="ad-toolbar" method="get" action="<?= dhEscape($baseUrl) ?>/department-head/attendance/index.php">
            <label class="ad-search">
                <?= dhIcon('search') ?>
                <span class="visually-hidden">Search events</span>
                <input type="search" name="q" value="<?= dhEscape($search) ?>" placeholder="Search event title">
            </label>
            <label>
                <span class="visually-hidden">Filter by date</span>
                <select name="date" data-autosubmit>
                    <option value="">All dates</option>
                    <?php foreach ($dateOptions as $key => $label): ?>
                        <option value="<?= dhEscape($key) ?>" <?= $dateFilter === $key ? 'selected' : '' ?>><?= dhEscape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="visually-hidden">Filter by academic year</span>
                <select name="academic_year" data-autosubmit>
                    <option value="">All years</option>
                    <?php foreach (array_unique(array_column($periods, 'academic_year')) as $academicYear): ?>
                        <option value="<?= dhEscape($academicYear) ?>" <?= $academicYearFilter === $academicYear ? 'selected' : '' ?>><?= dhEscape($academicYear) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="visually-hidden">Filter by semester</span>
                <select name="semester" data-autosubmit>
                    <option value="">All semesters</option>
                    <?php foreach (array_unique(array_column($periods, 'semester')) as $semester): ?>
                        <option value="<?= dhEscape($semester) ?>" <?= $semesterFilter === $semester ? 'selected' : '' ?>><?= dhEscape($semester) ?> Semester</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="visually-hidden" type="submit">Apply filters</button>
        </form>

        <?php if ($events === []): ?>
            <div class="ad-empty">
                <strong>No matching events</strong>
                Events and their attendance summaries will appear here.
            </div>
        <?php else: ?>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Date</th>
                            <th scope="col">Event status</th>
                            <th scope="col">Attendance summary</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php
                            $eventStatus = strtolower($event['event_status']);
                            $started = strtotime($event['start_at']) <= time();
                            ?>
                            <tr>
                                <td>
                                    <span class="ad-strong"><?= dhEscape($event['event_title']) ?></span><br>
                                    <span class="ad-muted"><?= dhEscape($event['venue'] ?: 'Venue not specified') ?></span>
                                </td>
                                <td><?= dhEscape(date('M j, Y', strtotime($event['start_at']))) ?></td>
                                <td><span class="ad-pill <?= dhEventPill($eventStatus) ?>"><?= dhEscape(ucwords(str_replace('_', ' ', $eventStatus))) ?></span></td>
                                <td>
                                    <?php if ((int) $event['record_count'] > 0): ?>
                                        <div class="ad-pill-row">
                                            <span class="ad-pill ad-pill-green" title="Present"><?= (int) $event['present_count'] ?> P</span>
                                            <span class="ad-pill ad-pill-yellow" title="Late"><?= (int) $event['late_count'] ?> L</span>
                                            <span class="ad-pill ad-pill-red" title="Absent"><?= (int) $event['absent_count'] ?> A</span>
                                            <span class="ad-pill ad-pill-blue" title="Excused"><?= (int) $event['excused_count'] ?> E</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="ad-muted"><?= $started ? 'No records' : 'Not started' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="ad-actions">
                                        <a class="ad-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/records.php?event_id=<?= (int) $event['event_id'] ?>">Records</a>
                                        <?php if ($eventStatus === 'approved'): ?>
                                            <span class="sep" aria-hidden="true">·</span>
                                            <a class="ad-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/scan.php?event_id=<?= (int) $event['event_id'] ?>">Scan</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php dhListFooter($page, DH_ATTENDANCE_PER_PAGE, $total, count($events), 'events'); ?>
    </div>
</section>

<script>
document.querySelectorAll('[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
});
</script>

<?php require __DIR__ . '/../../includes/department_head_shell_end.php'; ?>
