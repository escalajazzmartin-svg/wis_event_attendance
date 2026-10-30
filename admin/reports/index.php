<?php

$adminPageTitle = 'Reports and Analytics';
$adminRefreshCss = true;
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
    $conditions[] = ($report === 'students' ? 'r.department_id' : 'e.department_id') . ' = :department_id';
    $params['department_id'] = $departmentId;
}
$where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);
$years = $pdo->query('SELECT DISTINCT academic_year FROM academic_periods ORDER BY academic_year DESC')->fetchAll(PDO::FETCH_COLUMN);

if ($report === 'students') {
    $sql = "SELECT d.department_name, r.student_number,
                   CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name) AS student_name,
                   COUNT(DISTINCT CASE
                       WHEN ea.attendance_id IS NOT NULL THEN e.event_id
                   END) AS recorded_events,
                   COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
                   COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
                   COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
                   COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused
            FROM event_attendance_roster r
            INNER JOIN departments d ON d.department_id = r.department_id
            INNER JOIN events e ON e.event_id = r.event_id
            LEFT JOIN event_attendance ea
                ON ea.event_id = r.event_id AND ea.student_id = r.student_id
            LEFT JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
            $where
            GROUP BY r.student_id, r.department_id, d.department_name,
                     r.student_number, r.first_name, r.middle_name, r.last_name
            ORDER BY d.department_name, r.last_name, r.first_name";
} elseif ($report === 'attendance') {
    $sql = "SELECT d.department_name, e.event_id, e.event_title, e.start_at,
                   COUNT(DISTINCT ea.student_id) AS total_records,
                   COALESCE(SUM(ea.attendance_status = 'present'), 0) AS present,
                   COALESCE(SUM(ea.attendance_status = 'late'), 0) AS late,
                   COALESCE(SUM(ea.attendance_status = 'absent'), 0) AS absent,
                   COALESCE(SUM(ea.attendance_status = 'excused'), 0) AS excused,
                   (SELECT COUNT(*) FROM event_attendance_roster eligible
                    WHERE eligible.event_id = e.event_id
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
                       (SELECT COUNT(*) FROM event_attendance_roster eligible
                        WHERE eligible.event_id = e.event_id
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

// Summary cards and chart data for the report currently shown. Everything below is
// derived from the rows already loaded above (no extra queries).
$sumCol = static fn (string $column): int => array_sum(array_map('intval', array_column($rows, $column)));
$pct = static fn (int $part, int $whole): string => $whole > 0 ? round($part / $whole * 100) . '%' : '0%';
if ($report === 'events') {
    $eventTotal = $sumCol('total_events');
    $summaryCards = [
        ['Events', $eventTotal, '', 'fa-calendar-days', count($rows) . (count($rows) === 1 ? ' department' : ' departments')],
        ['Approved', $sumCol('approved'), 'admin-icon-green', 'fa-circle-check', $pct($sumCol('approved'), $eventTotal) . ' of events'],
        ['Pending', $sumCol('pending'), 'admin-icon-gold', 'fa-hourglass-half', $pct($sumCol('pending'), $eventTotal) . ' of events'],
        ['Completed', $sumCol('completed'), 'admin-icon-blue', 'fa-flag-checkered', $pct($sumCol('completed'), $eventTotal) . ' of events'],
    ];
    $mixSpec = [
        'approved' => ['Approved', '#2e7d4f'], 'pending' => ['Pending', '#d9a400'], 'completed' => ['Completed', '#2f6db5'],
        'rejected' => ['Rejected', '#c0392b'], 'cancelled' => ['Cancelled', '#8a8582'],
    ];
    $mixUnit = 'events';
} else {
    $recordTotal = $sumCol('present') + $sumCol('late') + $sumCol('absent') + $sumCol('excused');
    $summaryCards = [
        ['Present', $sumCol('present'), 'admin-icon-green', 'fa-circle-check', $pct($sumCol('present'), $recordTotal) . ' of records'],
        ['Late', $sumCol('late'), 'admin-icon-gold', 'fa-clock', $pct($sumCol('late'), $recordTotal) . ' of records'],
        ['Absent', $sumCol('absent'), 'admin-icon-red', 'fa-circle-xmark', $pct($sumCol('absent'), $recordTotal) . ' of records'],
        ['Excused', $sumCol('excused'), 'admin-icon-blue', 'fa-user-check', $pct($sumCol('excused'), $recordTotal) . ' of records'],
    ];
    $mixSpec = [
        'present' => ['Present', '#2e7d4f'], 'late' => ['Late', '#d9a400'],
        'excused' => ['Excused', '#2f6db5'], 'absent' => ['Absent', '#c0392b'],
    ];
    $mixUnit = 'records';
}

// Status mix donut (percentages are of the slices shown, so the ring always adds up to 100%).
$mixData = [];
$mixSum = 0;
foreach ($mixSpec as $mixKey => [$mixLabel, $mixColor]) {
    $mixValue = $sumCol($mixKey);
    $mixData[] = [$mixLabel, $mixColor, $mixValue];
    $mixSum += $mixValue;
}
$donutStops = [];
$donutCursor = 0.0;
foreach ($mixData as [, $mixColor, $mixValue]) {
    if ($mixValue <= 0 || $mixSum <= 0) {
        continue;
    }
    $donutEnd = $donutCursor + $mixValue / $mixSum * 100;
    $donutStops[] = $mixColor . ' ' . round($donutCursor, 2) . '% ' . round($donutEnd, 2) . '%';
    $donutCursor = $donutEnd;
}
$donutStyle = $donutStops === [] ? '' : 'background: conic-gradient(' . implode(', ', $donutStops) . ')';

// Main chart: ranked bars (the Departments report uses the stacked mix bars instead).
$rateOf = static function (array $row): float {
    $eligible = (int) $row['eligible_students'];
    return $eligible > 0 ? round(((int) $row['present'] + (int) $row['late'] + (int) $row['excused']) / $eligible * 100, 1) : 0.0;
};
$rateTone = static fn (float $rate): string => $rate >= 85 ? 'is-good' : ($rate >= 60 ? 'is-fair' : 'is-low');
$rankBars = [];
$rankTitle = '';
$rankNote = '';
$rankEmpty = 'Nothing to chart for the current filters.';
if ($report === 'events') {
    $rankTitle = 'Events per department';
    $rankNote = 'Departments ranked by number of events.';
    $ranked = $rows;
    usort($ranked, static fn (array $a, array $b): int => (int) $b['total_events'] <=> (int) $a['total_events']);
    $rankMax = max(1, (int) ($ranked[0]['total_events'] ?? 0));
    foreach (array_slice($ranked, 0, 8) as $row) {
        $rankBars[] = [$row['department_name'], '', (int) $row['total_events'] / $rankMax * 100, (string) (int) $row['total_events'], 'is-brand'];
    }
} elseif ($report === 'attendance') {
    $rankTitle = 'Attendance rate by event';
    $rankNote = 'Most recent events (up to 8). Rate = present + late + excused out of eligible students.';
    foreach (array_slice($rows, 0, 8) as $row) {
        $rate = $rateOf($row);
        $rankBars[] = [$row['event_title'], date('M j, Y', strtotime($row['start_at'])), min(100, $rate), $rate . '%', $rateTone($rate)];
    }
} elseif ($report === 'students') {
    $rankTitle = 'Most absences';
    $rankNote = 'Students with the highest number of absences (up to 8).';
    $rankEmpty = 'No absences recorded for the current filters.';
    $ranked = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['absent'] > 0));
    usort($ranked, static fn (array $a, array $b): int => (int) $b['absent'] <=> (int) $a['absent']);
    $rankMax = max(1, (int) ($ranked[0]['absent'] ?? 0));
    foreach (array_slice($ranked, 0, 8) as $row) {
        $rankBars[] = [$row['student_name'], $row['department_name'], (int) $row['absent'] / $rankMax * 100, (string) (int) $row['absent'], 'is-low'];
    }
}
?>
<section class="admin-content-section admin-refresh admin-reports-page">
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">Admin · Analytics</span><h2>Reports and Analytics</h2><p>Review event volumes and attendance across departments and academic periods.</p></div>
        <span class="admin-report-actions">
            <a class="admin-report-btn" href="<?= adminEscape($adminBaseUrl) ?>/admin/reports/export.php?<?= adminEscape(http_build_query($exportParams)) ?>"><i class="fa-solid fa-file-csv" aria-hidden="true"></i>Export CSV</a>
            <button class="admin-report-btn admin-print-button" type="button" onclick="window.print()"><i class="fa-solid fa-print" aria-hidden="true"></i>Print / Save PDF</button>
        </span>
    </div>
    <?php
    $reportTabs = ['events' => 'Events per department', 'attendance' => 'Event attendance', 'departments' => 'Attendance by department', 'students' => 'Student summaries'];
    $tabFilters = array_filter(['academic_year' => $academicYear, 'semester' => $semester, 'department_id' => $departmentId], static fn ($value): bool => $value !== '' && $value !== 0);
    $departmentLabel = '';
    foreach ($departments as $departmentRow) {
        if ((int) $departmentRow['department_id'] === $departmentId) {
            $departmentLabel = $departmentRow['department_name'];
        }
    }
    $activeFilters = array_filter([
        $academicYear !== '' ? $academicYear : '',
        $semester !== '' ? ($semester === 'Other' ? 'Other' : $semester . ' semester') : '',
        $departmentLabel,
    ]);
    ?>
    <section class="admin-panel admin-report-controls">
        <nav class="admin-report-tabs" aria-label="Report type">
            <?php foreach ($reportTabs as $tabKey => $tabName): ?>
                <a href="?<?= adminEscape(http_build_query(['report' => $tabKey] + $tabFilters)) ?>"<?= $report === $tabKey ? ' class="is-active" aria-current="page"' : '' ?>><?= adminEscape($tabName) ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="admin-filters admin-report-filters" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/reports/index.php">
            <input type="hidden" name="report" value="<?= adminEscape($report) ?>">
            <label><span class="visually-hidden">Academic year</span><select name="academic_year"><option value="">All academic years</option><?php foreach ($years as $year): ?><option value="<?= adminEscape($year) ?>" <?= $academicYear === $year ? 'selected' : '' ?>><?= adminEscape($year) ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Semester</span><select name="semester"><option value="">All semesters</option><?php foreach (['1st', '2nd', 'Summer', 'Other'] as $option): ?><option value="<?= $option ?>" <?= $semester === $option ? 'selected' : '' ?>><?= $option === 'Other' ? $option : $option . ' semester' ?></option><?php endforeach; ?></select></label>
            <label class="admin-report-department"><span class="visually-hidden">Department</span><select name="department_id"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['department_id'] ?>" <?= $departmentId === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option><?php endforeach; ?></select></label>
            <button class="admin-filter-button admin-filter-button-sm" type="submit">Apply filters</button>
            <a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/reports/index.php?report=<?= adminEscape($report) ?>">Clear</a>
        </form>
    </section>
    <?php if ($rows !== []): ?>
        <div class="admin-summary-grid">
            <?php foreach ($summaryCards as [$cardLabel, $cardValue, $cardTone, $cardIcon, $cardNote]): ?>
                <article class="admin-summary-card"><span class="admin-summary-icon <?= adminEscape($cardTone) ?>"><i class="fa-solid <?= adminEscape($cardIcon) ?>" aria-hidden="true"></i></span><div><span><?= adminEscape($cardLabel) ?></span><strong><?= (int) $cardValue ?></strong><small><?= adminEscape($cardNote) ?></small></div></article>
            <?php endforeach; ?>
        </div>
        <div class="admin-insights">
            <section class="admin-panel admin-insight-main">
                <div class="admin-panel-heading"><div><h3><?= $report === 'departments' ? 'Attendance mix by department' : adminEscape($rankTitle) ?></h3><p><?= $report === 'departments' ? 'Share of present, late, excused and absent records per department.' : adminEscape($rankNote) ?></p></div></div>
                <?php if ($report === 'departments'): ?>
                    <div class="admin-bars">
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $mix = ['present' => (int) $row['present'], 'late' => (int) $row['late'], 'excused' => (int) $row['excused'], 'absent' => (int) $row['absent']];
                            $mixTotal = array_sum($mix);
                            ?>
                            <div class="admin-bar-row">
                                <span><?= adminEscape($row['department_name']) ?></span>
                                <div class="admin-bar-track" role="img" aria-label="<?= adminEscape($row['department_name'] . ': ' . $mix['present'] . ' present, ' . $mix['late'] . ' late, ' . $mix['excused'] . ' excused, ' . $mix['absent'] . ' absent') ?>">
                                    <?php if ($mixTotal > 0): foreach ($mix as $mixKey => $mixValue): ?>
                                        <span class="bar-<?= $mixKey ?>" style="width: <?= round($mixValue / $mixTotal * 100, 2) ?>%"></span>
                                    <?php endforeach; endif; ?>
                                </div>
                                <strong><?= $mixTotal ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="admin-bar-legend"><span><i class="bar-present"></i>Present</span><span><i class="bar-late"></i>Late</span><span><i class="bar-excused"></i>Excused</span><span><i class="bar-absent"></i>Absent</span></div>
                <?php elseif ($rankBars === []): ?>
                    <p class="admin-insight-empty"><?= adminEscape($rankEmpty) ?></p>
                <?php else: ?>
                    <ol class="admin-rank">
                        <?php foreach ($rankBars as [$rankLabel, $rankSub, $rankWidth, $rankValue, $rankTone]): ?>
                            <li>
                                <div class="admin-rank-label"><span title="<?= adminEscape($rankLabel) ?>"><?= adminEscape($rankLabel) ?></span><?php if ($rankSub !== ''): ?><small><?= adminEscape($rankSub) ?></small><?php endif; ?></div>
                                <div class="admin-rank-track" role="img" aria-label="<?= adminEscape($rankLabel . ': ' . $rankValue) ?>"><i class="<?= adminEscape($rankTone) ?>" style="width: <?= round(max(0, min(100, $rankWidth)), 2) ?>%"></i></div>
                                <strong><?= adminEscape($rankValue) ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
            <section class="admin-panel admin-insight-side">
                <div class="admin-panel-heading"><div><h3><?= $report === 'events' ? 'Event status mix' : 'Attendance status mix' ?></h3><p>Share of <?= adminEscape($mixUnit) ?> by status.</p></div></div>
                <div class="admin-donut-wrap">
                    <div class="admin-donut" role="img" aria-label="<?= adminEscape(implode(', ', array_map(static fn (array $slice): string => $slice[0] . ' ' . $slice[2], $mixData))) ?>"<?= $donutStyle !== '' ? ' style="' . adminEscape($donutStyle) . '"' : '' ?>>
                        <div class="admin-donut-hole"><strong><?= (int) $mixSum ?></strong><span><?= adminEscape($mixUnit) ?></span></div>
                    </div>
                    <ul class="admin-donut-legend">
                        <?php foreach ($mixData as [$mixLabel, $mixColor, $mixValue]): ?>
                            <li><i style="background: <?= adminEscape($mixColor) ?>"></i><span><?= adminEscape($mixLabel) ?></span><strong><?= (int) $mixValue ?></strong><em><?= adminEscape($pct((int) $mixValue, (int) $mixSum)) ?></em></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </div>
    <?php endif; ?>
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><h3><?= $report === 'students' ? 'Student attendance summaries' : ($report === 'attendance' ? 'Attendance by event' : ($report === 'departments' ? 'Attendance comparisons by department' : 'Event totals by department')) ?></h3><p><?= count($rows) ?> <?= count($rows) === 1 ? 'row' : 'rows' ?><?= $activeFilters === [] ? ' · all academic years, semesters and departments' : ' · ' . adminEscape(implode(' · ', $activeFilters)) ?></p></div></div>
        <?php if ($rows === []): ?><div class="admin-empty-state"><strong>No report data found</strong><p>Try a different academic period or department.</p></div><?php elseif ($report === 'students'): ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Student</th><th>Department</th><th class="num">Events recorded</th><th class="num">Present</th><th class="num">Late</th><th class="num">Absent</th><th class="num">Excused</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td data-label="Student"><?= adminEscape($row['student_name']) ?><small><?= adminEscape($row['student_number']) ?></small></td><td data-label="Department"><?= adminEscape($row['department_name']) ?></td><td class="num" data-label="Events recorded"><?= (int) $row['recorded_events'] ?></td><td class="num num-present" data-label="Present"><?= (int) $row['present'] ?></td><td class="num num-late" data-label="Late"><?= (int) $row['late'] ?></td><td class="num num-absent" data-label="Absent"><?= (int) $row['absent'] ?></td><td class="num num-excused" data-label="Excused"><?= (int) $row['excused'] ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php elseif ($report === 'attendance'): ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Event</th><th>Department</th><th class="num">Records / eligible</th><th class="num">Present</th><th class="num">Late</th><th class="num">Absent</th><th class="num">Excused</th><th class="num">Attendance rate</th></tr></thead><tbody><?php foreach ($rows as $row): $eligible = (int) $row['eligible_students']; $rate = $eligible > 0 ? round(((int) $row['present'] + (int) $row['late'] + (int) $row['excused']) / $eligible * 100, 1) : 0; ?><tr><td data-label="Event"><?= adminEscape($row['event_title']) ?><small><?= adminEscape(date('M j, Y', strtotime($row['start_at']))) ?></small></td><td data-label="Department"><?= adminEscape($row['department_name']) ?></td><td class="num" data-label="Records / eligible"><?= (int) $row['total_records'] ?> / <?= $eligible ?></td><td class="num num-present" data-label="Present"><?= (int) $row['present'] ?></td><td class="num num-late" data-label="Late"><?= (int) $row['late'] ?></td><td class="num num-absent" data-label="Absent"><?= (int) $row['absent'] ?></td><td class="num num-excused" data-label="Excused"><?= (int) $row['excused'] ?></td><td class="num" data-label="Attendance rate"><span class="admin-rate"><span class="admin-rate-bar"><i style="width: <?= min(100, $rate) ?>%"></i></span><strong><?= $rate ?>%</strong></span></td></tr><?php endforeach; ?></tbody></table></div>
        <?php elseif ($report === 'departments'): ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Department</th><th class="num">Events</th><th class="num">Records / eligible</th><th class="num">Present</th><th class="num">Late</th><th class="num">Absent</th><th class="num">Excused</th><th class="num">Average attendance</th></tr></thead><tbody><?php foreach ($rows as $row): $eligible = (int) $row['eligible_students']; $rate = $eligible > 0 ? round(((int) $row['present'] + (int) $row['late'] + (int) $row['excused']) / $eligible * 100, 1) : 0; ?><tr><td data-label="Department"><?= adminEscape($row['department_name']) ?></td><td class="num" data-label="Events"><?= (int) $row['total_events'] ?></td><td class="num" data-label="Records / eligible"><?= (int) $row['total_records'] ?> / <?= $eligible ?></td><td class="num num-present" data-label="Present"><?= (int) $row['present'] ?></td><td class="num num-late" data-label="Late"><?= (int) $row['late'] ?></td><td class="num num-absent" data-label="Absent"><?= (int) $row['absent'] ?></td><td class="num num-excused" data-label="Excused"><?= (int) $row['excused'] ?></td><td class="num" data-label="Average attendance"><span class="admin-rate"><span class="admin-rate-bar"><i style="width: <?= min(100, $rate) ?>%"></i></span><strong><?= $rate ?>%</strong></span></td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Department</th><th class="num">Events</th><th class="num">Pending</th><th class="num">Approved</th><th class="num">Rejected</th><th class="num">Cancelled</th><th class="num">Completed</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td data-label="Department"><?= adminEscape($row['department_name']) ?></td><td class="num" data-label="Events"><?= (int) $row['total_events'] ?></td><td class="num" data-label="Pending"><?= (int) $row['pending'] ?></td><td class="num" data-label="Approved"><?= (int) $row['approved'] ?></td><td class="num" data-label="Rejected"><?= (int) $row['rejected'] ?></td><td class="num" data-label="Cancelled"><?= (int) $row['cancelled'] ?></td><td class="num" data-label="Completed"><?= (int) $row['completed'] ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
