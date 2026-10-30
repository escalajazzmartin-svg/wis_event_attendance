<?php

$studentPageTitle = 'Attendance History';
$studentPageCss = 'student-attendance.css';
require_once __DIR__ . '/../../includes/student_context.php';

$academicYear = is_string($_GET['academic_year'] ?? null) ? trim($_GET['academic_year']) : '';
$semester = is_string($_GET['semester'] ?? null) ? trim($_GET['semester']) : '';
$periodsStmt = $pdo->query('SELECT DISTINCT academic_year, semester FROM academic_periods ORDER BY academic_year DESC, semester');
$periods = $periodsStmt->fetchAll(PDO::FETCH_ASSOC);
$validYears = array_unique(array_column($periods, 'academic_year'));
$validSemesters = array_unique(array_column($periods, 'semester'));
if (!in_array($academicYear, $validYears, true)) {
    $academicYear = '';
}
if (!in_array($semester, $validSemesters, true)) {
    $semester = '';
}

$sql = "
    SELECT ea.attendance_id, ea.attendance_status, ea.check_in_at,
           ea.record_source, ea.remarks, e.event_id, e.event_title,
           e.start_at, ap.academic_year, ap.semester,
           aa.appeal_id, aa.appeal_status, aa.reviewer_remarks
    FROM event_attendance ea
    INNER JOIN events e ON e.event_id = ea.event_id
    INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
    LEFT JOIN attendance_appeals aa
        ON aa.appeal_id = (
            SELECT aa_latest.appeal_id FROM attendance_appeals aa_latest
            WHERE aa_latest.attendance_id = ea.attendance_id
              AND aa_latest.student_id = ea.student_id
            ORDER BY aa_latest.submitted_at DESC, aa_latest.appeal_id DESC
            LIMIT 1
        )
    WHERE ea.student_id = ?";
$params = [$studentId];
if ($academicYear !== '') {
    $sql .= ' AND ap.academic_year = ?';
    $params[] = $academicYear;
}
if ($semester !== '') {
    $sql .= ' AND ap.semester = ?';
    $params[] = $semester;
}
$sql .= ' ORDER BY e.start_at DESC, ea.attendance_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-attendance.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Event', 'Event date', 'Academic year', 'Semester', 'Status', 'Check-in time', 'Appeal status', 'Review remarks']);
    foreach ($records as $record) {
        $cells = [
            $record['event_title'],
            $record['start_at'],
            $record['academic_year'],
            $record['semester'],
            $record['attendance_status'],
            $record['check_in_at'] ?? '',
            $record['appeal_status'] ?? '',
            $record['reviewer_remarks'] ?? '',
        ];
        foreach ($cells as &$cell) {
            if (is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell)) {
                $cell = "'" . $cell;
            }
        }
        unset($cell);
        fputcsv($output, $cells);
    }
    fclose($output);
    exit;
}

$studentPageTitle = 'Attendance History';
require_once __DIR__ . '/../../includes/student_layout_start.php';

$query = http_build_query(array_filter([
    'academic_year' => $academicYear,
    'semester' => $semester,
]));
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading">
        <div>
            <h2>Attendance History</h2>
            <p>Filter your recorded attendance by semester and academic year.</p>
        </div>
        <div class="student-report-actions">
            <a class="management-button management-button-secondary" href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/history.php?<?= studentEscape($query) ?>&amp;export=csv">Export CSV</a>
            <button class="management-button" type="button" onclick="window.print()">Print</button>
        </div>
    </div>
    <form class="student-filter-form" method="get" action="<?= studentEscape($studentBaseUrl) ?>/student/attendance/history.php">
        <label for="academic-year">Academic year</label>
        <select id="academic-year" name="academic_year">
            <option value="">All years</option>
            <?php foreach ($validYears as $year): ?><option value="<?= studentEscape($year) ?>" <?= $academicYear === $year ? 'selected' : '' ?>><?= studentEscape($year) ?></option><?php endforeach; ?>
        </select>
        <label for="semester">Semester</label>
        <select id="semester" name="semester">
            <option value="">All semesters</option>
            <?php foreach ($validSemesters as $semesterOption): ?><option value="<?= studentEscape($semesterOption) ?>" <?= $semester === $semesterOption ? 'selected' : '' ?>><?= studentEscape($semesterOption) ?></option><?php endforeach; ?>
        </select>
        <button class="management-button" type="submit">Apply filters</button>
    </form>
    <?php if ($records === []): ?>
        <div class="student-content-panel empty-state"><strong>No attendance history found</strong>
            <p>No personal records match these academic-period filters.</p>
        </div>
    <?php else: ?>
        <div class="student-content-panel student-table-wrap">
            <table class="student-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">Date</th>
                        <th scope="col">Academic period</th>
                        <th scope="col">Status</th>
                        <th scope="col">Check-in</th>
                        <th scope="col">Appeal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td data-label="Event"><a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $record['event_id'] ?>"><?= studentEscape($record['event_title']) ?></a></td>
                            <td data-label="Date"><?= studentEscape(studentDateTime($record['start_at'], 'M j, Y')) ?></td>
                            <td data-label="Academic period"><?= studentEscape($record['academic_year']) ?> · <?= studentEscape($record['semester']) ?></td>
                            <td data-label="Status"><span class="status-pill status-<?= studentEscape($record['attendance_status']) ?>"><?= studentEscape(ucfirst($record['attendance_status'])) ?></span></td>
                            <td data-label="Check-in"><?= studentEscape(studentDateTime($record['check_in_at'])) ?></td>
                            <td data-label="Appeal">
                                <?php if ($record['appeal_id']): ?>
                                    <a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/view.php?id=<?= (int) $record['appeal_id'] ?>"><?= studentEscape(ucfirst($record['appeal_status'])) ?></a>
                                    <?php if ($record['appeal_status'] === 'approved'): ?><small> <?= studentEscape($record['reviewer_remarks'] ?: 'Appeal approved') ?></small><?php endif; ?>
                                <?php elseif (in_array($record['attendance_status'], ['absent', 'late'], true)): ?>
                                    <a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/create.php?attendance_id=<?= (int) $record['attendance_id'] ?>">Submit appeal</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>

</html>
