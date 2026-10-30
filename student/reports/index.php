<?php

require_once __DIR__ . '/../../includes/student_context.php';

$studentPageTitle = 'Personal Attendance Report';
$studentPageCss = 'student-reports.css';

/*
|--------------------------------------------------------------------------
| Academic Period Filter
|--------------------------------------------------------------------------
*/

$periodStmt = $pdo->query(
    "SELECT academic_period_id, academic_year, semester
     FROM academic_periods
     ORDER BY academic_year DESC, academic_period_id DESC"
);

$academicPeriods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedPeriodId = filter_var(
    $_GET['academic_period_id'] ?? 0,
    FILTER_VALIDATE_INT
);

$selectedPeriodId = ($selectedPeriodId !== false && $selectedPeriodId > 0)
    ? (int) $selectedPeriodId
    : 0;

$validPeriodIds = array_map(
    'intval',
    array_column($academicPeriods, 'academic_period_id')
);

if ($selectedPeriodId !== 0 && !in_array($selectedPeriodId, $validPeriodIds, true)) {
    $selectedPeriodId = 0;
}

$selectedPeriod = null;

foreach ($academicPeriods as $period) {
    if ((int) $period['academic_period_id'] === $selectedPeriodId) {
        $selectedPeriod = $period;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Attendance Records
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        ea.attendance_id,
        ea.attendance_status,
        e.event_id,
        e.event_title,
        e.start_at,
        ap.academic_period_id,
        ap.academic_year,
        ap.semester,
        aa.appeal_status,
        aa.reviewer_remarks
    FROM event_attendance ea
    INNER JOIN events e
        ON e.event_id = ea.event_id
    INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
    LEFT JOIN attendance_appeals aa
        ON aa.appeal_id = (
            SELECT aa_latest.appeal_id
            FROM attendance_appeals aa_latest
            WHERE aa_latest.attendance_id = ea.attendance_id
              AND aa_latest.student_id = ea.student_id
            ORDER BY aa_latest.submitted_at DESC, aa_latest.appeal_id DESC
            LIMIT 1
        )
    WHERE ea.student_id = ?
";

$params = [$studentId];

if ($selectedPeriodId !== 0) {
    $sql .= " AND ap.academic_period_id = ?";
    $params[] = $selectedPeriodId;
}

$sql .= " ORDER BY e.start_at DESC, ea.attendance_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Attendance Summary
|--------------------------------------------------------------------------
*/

$summary = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'excused' => 0,
];

$appealSummary = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'withdrawn' => 0,
];

foreach ($records as $record) {
    $status = $record['attendance_status'];

    if (isset($summary[$status])) {
        $summary[$status]++;
    }

    $appealStatus = $record['appeal_status'];

    if ($appealStatus && isset($appealSummary[$appealStatus])) {
        $appealSummary[$appealStatus]++;
    }
}

/*
|--------------------------------------------------------------------------
| Completed Events Without Attendance Records
|--------------------------------------------------------------------------
*/

$unrecordedSql = "
    SELECT
        e.event_id,
        e.event_title,
        e.start_at,
        ap.academic_period_id,
        ap.academic_year,
        ap.semester
    FROM events e
    INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
    WHERE e.department_id = ?
      AND (
          e.event_status = 'completed'
          OR (e.event_status = 'approved' AND e.end_at < NOW())
      )
      AND " . studentParticipantCondition('e') . "
      AND NOT EXISTS (
          SELECT 1
          FROM event_attendance ea
          WHERE ea.event_id = e.event_id
            AND ea.student_id = ?
      )
";

$unrecordedParams = array_merge(
    [$studentDepartmentId],
    studentParticipantParams($studentProfile),
    [$studentId]
);

if ($selectedPeriodId !== 0) {
    $unrecordedSql .= " AND ap.academic_period_id = ?";
    $unrecordedParams[] = $selectedPeriodId;
}

$unrecordedSql .= " ORDER BY e.start_at DESC";

$unrecordedStmt = $pdo->prepare($unrecordedSql);
$unrecordedStmt->execute($unrecordedParams);

$unrecordedEvents = $unrecordedStmt->fetchAll(PDO::FETCH_ASSOC);

$unrecordedMissed = count($unrecordedEvents);
$summary['absent'] += $unrecordedMissed;

foreach ($unrecordedEvents as $event) {
    $records[] = $event + [
        'attendance_status' => 'absent',
        'appeal_status' => null,
        'reviewer_remarks' => null,
        'inferred_absence' => true,
    ];
}

usort(
    $records,
    static fn(array $left, array $right): int =>
    strcmp($right['start_at'], $left['start_at'])
);

/*
|--------------------------------------------------------------------------
| Totals
|--------------------------------------------------------------------------
*/

$total = array_sum($summary);

$attended = $summary['present']
    + $summary['late']
    + $summary['excused'];

$percentage = $total > 0
    ? round(($attended / $total) * 100, 1)
    : 0;

/*
|--------------------------------------------------------------------------
| CSV Export
|--------------------------------------------------------------------------
*/

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="my-attendance-report.csv"'
    );

    $output = fopen('php://output', 'wb');

    if ($output === false) {
        throw new RuntimeException(
            'Unable to create the attendance report export.'
        );
    }

    fputcsv($output, [
        'Event',
        'Event date',
        'Academic year',
        'Semester',
        'Status',
        'Attendance record',
        'Appeal status',
        'Review remarks',
    ]);

    foreach ($records as $record) {
        $row = [
            $record['event_title'],
            $record['start_at'],
            $record['academic_year'],
            $record['semester'],
            $record['attendance_status'],
            !empty($record['inferred_absence'])
                ? 'No scan recorded'
                : 'Recorded',
            $record['appeal_status'] ?? '',
            $record['reviewer_remarks'] ?? '',
        ];

        // Prevent CSV formula injection.
        foreach ($row as &$value) {
            if (
                is_string($value)
                && preg_match('/^[=+\-@\t\r]/', $value)
            ) {
                $value = "'" . $value;
            }
        }

        unset($value);

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/*
|--------------------------------------------------------------------------
| Page Layout
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../includes/student_layout_start.php';

$filterQuery = http_build_query(
    $selectedPeriodId !== 0
        ? ['academic_period_id' => $selectedPeriodId]
        : []
);
?>

<section class="dashboard-section student-module-page student-report-page">

    <!-- Page Header -->
    <div class="section-heading">
        <div>
            <h2>Personal Attendance Report</h2>
            <p>Attendance and appeal outcomes for your account only.</p>
        </div>

        <div class="student-report-actions">
            <a
                class="management-button management-button-secondary"
                href="<?= studentEscape($studentBaseUrl) ?>/student/reports/index.php<?= $filterQuery !== '' ? '?' . studentEscape($filterQuery) . '&amp;' : '?' ?>export=csv">
                Export CSV
            </a>

            <button
                class="management-button"
                type="button"
                onclick="window.print()">
                Print
            </button>
        </div>
    </div>

    <!-- Attendance Summary Cards -->
    <div class="student-summary-grid">

        <article class="student-summary-card">
            <span>Attendance Percentage</span>
            <strong><?= number_format($percentage, 1) ?>%</strong>
            <small>Present, Late, or Excused</small>
        </article>

        <article class="student-summary-card">
            <span>Attended</span>
            <strong><?= $attended ?></strong>
            <small>Out of <?= $total ?> records</small>
        </article>

        <article class="student-summary-card">
            <span>Missed</span>
            <strong><?= $summary['absent'] ?></strong>
            <small>
                Marked absent<?= $unrecordedMissed > 0
                                    ? ' or no check-in recorded for a completed event'
                                    : '' ?>
            </small>
        </article>

        <article class="student-summary-card">
            <span>Late</span>
            <strong><?= $summary['late'] ?></strong>
            <small>Recorded late arrivals</small>
        </article>

    </div>


    <!-- Attendance Records -->
    <div class="student-content-panel">

        <div class="section-heading">
            <div>
                <h2>Attendance by Event</h2>

                <p>
                    <?= count($records) ?> events in this summary
                    · Excused absences: <?= $summary['excused'] ?>
                    · Events without a recorded check-in are counted as
                    missed after completion.
                </p>
            </div>
        </div>

        <!-- Single Academic Period Filter -->
        <form method="GET" class="student-filter-form" id="academicPeriodFilter">
            <label for="academic_period_id">Academic Period</label>

            <select
                name="academic_period_id"
                id="academic_period_id"
                onchange="this.form.submit()">
                <option value="">All Academic Periods</option>

                <?php foreach ($academicPeriods as $period): ?>
                    <option
                        value="<?= (int) $period['academic_period_id'] ?>"
                        <?= (int) $selectedPeriodId === (int) $period['academic_period_id'] ? 'selected' : '' ?>
                        <?= htmlspecialchars(
                            $period['academic_year'] . ' - ' . $period['semester'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                        </option>
                    <?php endforeach; ?>
            </select>
        </form>

        <?php if ($records === []): ?>

            <div class="empty-state">
                <strong>No attendance records</strong>
                <p>
                    There is no attendance history in the selected academic
                    period.
                </p>
            </div>

        <?php else: ?>

            <div class="student-table-wrap">
                <table class="student-table">

                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Date</th>
                            <th scope="col">Academic Period</th>
                            <th scope="col">Status</th>
                            <th scope="col">Appeal Outcome</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($records as $record): ?>

                            <tr>
                                <td data-label="Event">
                                    <a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $record['event_id'] ?>">
                                        <?= studentEscape($record['event_title']) ?>
                                    </a>
                                </td>

                                <td data-label="Date">
                                    <?= studentEscape(
                                        studentDateTime(
                                            $record['start_at'],
                                            'M j, Y'
                                        )
                                    ) ?>
                                </td>

                                <td data-label="Academic Period">
                                    <?= studentEscape($record['academic_year']) ?>
                                    ·
                                    <?= studentEscape($record['semester']) ?>
                                </td>

                                <td data-label="Status">
                                    <span class="status-pill status-<?= studentEscape($record['attendance_status']) ?>">
                                        <?= studentEscape(
                                            ucfirst($record['attendance_status'])
                                        ) ?>
                                    </span>
                                </td>

                                <td data-label="Appeal Outcome">
                                    <?php if (!empty($record['inferred_absence'])): ?>

                                        No attendance record. Contact attendance
                                        staff if this is incorrect.

                                    <?php else: ?>

                                        <?= $record['appeal_status']
                                            ? studentEscape(
                                                ucfirst($record['appeal_status'])
                                                    . (
                                                        $record['reviewer_remarks']
                                                        ? ': ' . $record['reviewer_remarks']
                                                        : ''
                                                    )
                                            )
                                            : '—' ?>

                                    <?php endif; ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    </tbody>

                </table>
            </div>

        <?php endif; ?>

        <p class="student-appeal-summary">
            Appeals:
            <?= $appealSummary['pending'] ?> pending ·
            <?= $appealSummary['approved'] ?> approved ·
            <?= $appealSummary['rejected'] ?> rejected ·
            <?= $appealSummary['withdrawn'] ?> withdrawn
        </p>

    </div>

</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>

</html>
