<?php

$pageTitle = 'Reports and Analytics';
$pageClass = 'department-head-reports-page';
$pageUseStudentReportsCss = true;
$pageCss = 'department-head-reports.css';

require_once __DIR__ . '/../../includes/department_head_context.php';

/* --------------------------------------------------------------------------
   Filters
-------------------------------------------------------------------------- */

$periodStmt = $pdo->query(
    'SELECT DISTINCT academic_year, semester
     FROM academic_periods
     ORDER BY academic_year DESC, semester ASC'
);

$periodOptions = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

$academicYears = array_values(array_unique(array_map(
    'strval',
    array_column($periodOptions, 'academic_year')
)));

$semesters = array_values(array_unique(array_map(
    'strval',
    array_column($periodOptions, 'semester')
)));

$academicYearFilter = $_GET['academic_year'] ?? '';
$semesterFilter = $_GET['semester'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$academicYearFilter = is_string($academicYearFilter)
    ? trim($academicYearFilter) : '';

$semesterFilter = is_string($semesterFilter)
    ? trim($semesterFilter) : '';

$statusFilter = is_string($statusFilter)
    ? trim($statusFilter) : '';

if (
    $academicYearFilter !== ''
    && !in_array($academicYearFilter, $academicYears, true)
) {
    $academicYearFilter = '';
}

if (
    $semesterFilter !== ''
    && !in_array($semesterFilter, $semesters, true)
) {
    $semesterFilter = '';
}

$eventStatuses = [
    'pending',
    'approved',
    'completed',
    'rejected',
    'cancelled',
    'cancellation_requested',
];

if (
    $statusFilter !== ''
    && !in_array($statusFilter, $eventStatuses, true)
) {
    $statusFilter = '';
}

/* --------------------------------------------------------------------------
   Events assigned to this Department Head's department only
-------------------------------------------------------------------------- */

$eventSql = "
    SELECT
        e.event_id,
        e.event_title,
        e.venue,
        e.start_at,
        e.event_status,
        ap.academic_year,
        ap.semester,
        CASE
            WHEN e.event_status = 'completed'
                 OR (
                     e.event_status = 'approved'
                     AND e.end_at < NOW()
                 )
            THEN 1 ELSE 0
        END AS attendance_finalized
    FROM events e
    INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
    WHERE e.department_id = :department_id
";

$eventParams = ['department_id' => $departmentId];

if ($academicYearFilter !== '') {
    $eventSql .= ' AND ap.academic_year = :academic_year';
    $eventParams['academic_year'] = $academicYearFilter;
}

if ($semesterFilter !== '') {
    $eventSql .= ' AND ap.semester = :semester';
    $eventParams['semester'] = $semesterFilter;
}

if ($statusFilter !== '') {
    $eventSql .= ' AND e.event_status = :event_status';
    $eventParams['event_status'] = $statusFilter;
}

$eventSql .= ' ORDER BY e.start_at DESC, e.event_id DESC';

$eventStmt = $pdo->prepare($eventSql);
$eventStmt->execute($eventParams);
$events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

$studentsStmt = $pdo->prepare("
    SELECT
        student_id,
        student_number,
        first_name,
        middle_name,
        last_name,
        year_level,
        section,
        enrollment_status
    FROM students
    WHERE department_id = ?
    ORDER BY last_name, first_name, middle_name, student_number
");

$studentsStmt->execute([$departmentId]);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
$currentStudents = $students;
$studentsById = [];
foreach ($currentStudents as $student) {
    $studentsById[(int) $student['student_id']] = $student;
}

$eventIds = array_map('intval', array_column($events, 'event_id'));

$participantRules = [];
$rosterStudentsByEvent = [];
$attendanceByEventStudent = [];

if ($eventIds !== []) {
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $rosterStmt = $pdo->prepare("
        SELECT event_id, student_id, student_number, first_name,
               middle_name, last_name, year_level, section
        FROM event_attendance_roster
        WHERE department_id = ? AND event_id IN ($placeholders)
    ");
    $rosterStmt->execute(array_merge([$departmentId], $eventIds));

    foreach ($rosterStmt->fetchAll(PDO::FETCH_ASSOC) as $rosterStudent) {
        $eventKey = (int) $rosterStudent['event_id'];
        $studentKey = (int) $rosterStudent['student_id'];
        $rosterStudentsByEvent[$eventKey][$studentKey] = $rosterStudent;
        if (!isset($studentsById[$studentKey])) {
            $studentsById[$studentKey] = $rosterStudent;
        }
    }

    $students = array_values($studentsById);

    $rulesStmt = $pdo->prepare("
        SELECT
            event_id,
            participant_type,
            student_id,
            year_level,
            section
        FROM event_participants
        WHERE event_id IN ($placeholders)
        ORDER BY event_id, event_participant_id
    ");

    $rulesStmt->execute($eventIds);

    foreach ($rulesStmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        $participantRules[(int) $rule['event_id']][] = $rule;
    }

    $attendanceStmt = $pdo->prepare("
        SELECT
            ea.event_id,
            ea.student_id,
            LOWER(ea.attendance_status) AS attendance_status
        FROM event_attendance ea
        INNER JOIN events e
            ON e.event_id = ea.event_id
        INNER JOIN event_attendance_roster r
            ON r.event_id = ea.event_id AND r.student_id = ea.student_id
        WHERE e.department_id = ?
          AND r.department_id = ?
          AND ea.event_id IN ($placeholders)
    ");

    $attendanceStmt->execute(array_merge(
        [$departmentId, $departmentId],
        $eventIds
    ));

    foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $attendance) {
        $attendanceByEventStudent[
            (int) $attendance['event_id']
        ][
            (int) $attendance['student_id']
        ] = $attendance['attendance_status'];
    }
}

/**
 * Check whether a department student is included in an event's participant rules.
 */
function dhReportStudentIsEligible(array $student, array $rules): bool
{
    if ($rules === []) {
        return true;
    }

    foreach ($rules as $rule) {
        $type = $rule['participant_type'] ?? '';

        if (
            $type === 'student'
            && (int) ($rule['student_id'] ?? 0)
                === (int) $student['student_id']
        ) {
            return true;
        }

        if (
            $type === 'year_level'
            && (string) ($rule['year_level'] ?? '')
                === (string) ($student['year_level'] ?? '')
        ) {
            return true;
        }

        if (
            $type === 'section'
            && (string) ($rule['year_level'] ?? '')
                === (string) ($student['year_level'] ?? '')
            && (string) ($rule['section'] ?? '')
                === (string) ($student['section'] ?? '')
        ) {
            return true;
        }
    }

    return false;
}

/* --------------------------------------------------------------------------
   Event attendance, inferred absences, and period comparisons
-------------------------------------------------------------------------- */

$statuses = ['present', 'late', 'absent', 'excused'];
$statusTotals = array_fill_keys($statuses, 0);
$eventRows = [];
$eligibleStudentIdsByEvent = [];
$finalizedRateNumerator = 0;
$finalizedRateDenominator = 0;
$periodSummaries = [];

foreach ($events as $event) {
    $eventId = (int) $event['event_id'];
    $eventStatus = (string) $event['event_status'];

    $attendanceExcluded = in_array(
        $eventStatus,
        ['cancelled', 'rejected'],
        true
    );

    $isFinalized =
        (int) $event['attendance_finalized'] === 1
        && !$attendanceExcluded;

    $rules = $participantRules[$eventId] ?? [];
    $eligibleStudentIds = [];

    if ($isFinalized) {
        $eligibleStudentIds = array_map(
            'intval',
            array_keys($rosterStudentsByEvent[$eventId] ?? [])
        );
    } else {
        foreach ($currentStudents as $student) {
            if (($student['enrollment_status'] ?? 'enrolled') !== 'enrolled') {
                continue;
            }

            if (dhReportStudentIsEligible($student, $rules)) {
                $eligibleStudentIds[] = (int) $student['student_id'];
            }
        }
    }

    $eligibleStudentIdsByEvent[$eventId] = $eligibleStudentIds;

    $counts = array_fill_keys($statuses, 0);
    $recordCount = 0;

    /*
     * Cancelled/rejected events remain listed but do not contribute
     * attendance outcomes or attendance rates.
     */
    if (!$attendanceExcluded) {
        foreach ($eligibleStudentIds as $studentId) {
            $recordedStatus =
                $attendanceByEventStudent[$eventId][$studentId] ?? null;

            if (
                $recordedStatus !== null
                && isset($counts[$recordedStatus])
            ) {
                $counts[$recordedStatus]++;
                $recordCount++;
            } elseif ($isFinalized) {
                // Missing check-ins are counted absent only after finalization.
                $counts['absent']++;
            }
        }
    }

    /*
     * Excused students remain visible in totals but are excluded
     * from the attendance-rate denominator.
     */
    $eligibleForRate = max(
        0,
        count($eligibleStudentIds) - $counts['excused']
    );

    $rateNumerator = $counts['present'] + $counts['late'];
    $rate = null;

    if ($isFinalized && $eligibleForRate > 0) {
        $rate = round(
            ($rateNumerator / $eligibleForRate) * 100,
            1
        );

        $finalizedRateNumerator += $rateNumerator;
        $finalizedRateDenominator += $eligibleForRate;
    }

    if (!$attendanceExcluded) {
        foreach ($statuses as $status) {
            $statusTotals[$status] += $counts[$status];
        }
    }

    $periodKey = $event['academic_year'] . '||' . $event['semester'];

    if (!isset($periodSummaries[$periodKey])) {
        $periodSummaries[$periodKey] = [
            'academic_year' => $event['academic_year'],
            'semester' => $event['semester'],
            'event_count' => 0,
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'excused' => 0,
            'rate_numerator' => 0,
            'rate_denominator' => 0,
        ];
    }

    // The event count includes all events matching the selected filters.
    $periodSummaries[$periodKey]['event_count']++;

    if (!$attendanceExcluded) {
        foreach ($statuses as $status) {
            $periodSummaries[$periodKey][$status] += $counts[$status];
        }
    }

    if ($isFinalized && $eligibleForRate > 0) {
        $periodSummaries[$periodKey]['rate_numerator'] += $rateNumerator;
        $periodSummaries[$periodKey]['rate_denominator'] += $eligibleForRate;
    }

    $eventRows[] = [
        'event_id' => $eventId,
        'event_title' => $event['event_title'],
        'venue' => $event['venue'],
        'start_at' => $event['start_at'],
        'event_status' => $eventStatus,
        'academic_year' => $event['academic_year'],
        'semester' => $event['semester'],
        'attendance_finalized' => $isFinalized,
        'attendance_excluded' => $attendanceExcluded,
        'eligible_students' => count($eligibleStudentIds),
        'record_count' => $recordCount,
        'present' => $counts['present'],
        'late' => $counts['late'],
        'absent' => $counts['absent'],
        'excused' => $counts['excused'],
        'attendance_rate' => $rate,
    ];
}

/* Weighted overall rate from finalized events, excluding excused records. */
$averageAttendanceRate = $finalizedRateDenominator > 0
    ? round(
        ($finalizedRateNumerator / $finalizedRateDenominator) * 100,
        1
    )
    : null;

foreach ($periodSummaries as &$periodSummary) {
    $periodSummary['average_rate'] =
        $periodSummary['rate_denominator'] > 0
            ? round(
                (
                    $periodSummary['rate_numerator']
                    / $periodSummary['rate_denominator']
                ) * 100,
                1
            )
            : null;
}
unset($periodSummary);

/* --------------------------------------------------------------------------
   Student attendance percentages across finalized events in the filter scope
-------------------------------------------------------------------------- */

$studentRows = [];

$finalizedEvents = array_values(array_filter(
    $eventRows,
    static fn(array $event): bool =>
        $event['attendance_finalized']
        && !$event['attendance_excluded']
));

if ($finalizedEvents !== []) {
    $studentStats = [];

    foreach ($students as $student) {
        $studentId = (int) $student['student_id'];

        $studentStats[$studentId] = [
            'student_number' => $student['student_number'],
            'student_name' => trim(implode(' ', array_filter([
                $student['first_name'],
                $student['middle_name'],
                $student['last_name'],
            ], static fn($part): bool =>
                $part !== null && trim((string) $part) !== ''
            ))),
            'eligible_events' => 0,
            'rate_eligible_events' => 0,
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'excused' => 0,
        ];
    }

    foreach ($finalizedEvents as $event) {
        $eventId = (int) $event['event_id'];

        foreach ($eligibleStudentIdsByEvent[$eventId] ?? [] as $studentId) {
            if (!isset($studentStats[$studentId])) {
                continue;
            }

            $studentStats[$studentId]['eligible_events']++;

            $recordedStatus =
                $attendanceByEventStudent[$eventId][$studentId] ?? 'absent';

            if ($recordedStatus === 'excused') {
                $studentStats[$studentId]['excused']++;
                continue;
            }

            $studentStats[$studentId]['rate_eligible_events']++;

            if (isset($studentStats[$studentId][$recordedStatus])) {
                $studentStats[$studentId][$recordedStatus]++;
            }
        }
    }

    foreach ($studentStats as $studentStat) {
        if ($studentStat['eligible_events'] < 1) {
            continue;
        }

        $studentStat['attended'] =
            $studentStat['present'] + $studentStat['late'];

        $studentStat['attendance_rate'] =
            $studentStat['rate_eligible_events'] > 0
                ? round(
                    (
                        $studentStat['attended']
                        / $studentStat['rate_eligible_events']
                    ) * 100,
                    1
                )
                : null;

        $studentRows[] = $studentStat;
    }

    usort(
        $studentRows,
        static fn(array $a, array $b): int =>
            strcasecmp($a['student_name'], $b['student_name'])
    );
}

/* --------------------------------------------------------------------------
   Export helpers
-------------------------------------------------------------------------- */

function dhReportCsvSafe(mixed $value): string
{
    $value = (string) ($value ?? '');

    if (preg_match('/^[\s\x00-\x20]*[=+\-@\t\r]/u', $value)) {
        return "'" . $value;
    }

    return $value;
}

function dhReportShortText(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }

    return substr($value, 0, $length);
}

function dhReportPdfEscape(string $value): string
{
    if (function_exists('iconv')) {
        $converted = @iconv(
            'UTF-8',
            'Windows-1252//TRANSLIT//IGNORE',
            $value
        );

        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = preg_replace(
        '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
        '',
        $value
    ) ?? '';

    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $value
    );
}

function dhReportMakePdf(array $lines): string
{
    $wrappedLines = [];

    foreach ($lines as $line) {
        $line = (string) $line;

        if ($line === '') {
            $wrappedLines[] = '';
            continue;
        }

        foreach (explode("\n", wordwrap($line, 105, "\n", true)) as $part) {
            $wrappedLines[] = $part;
        }
    }

    $pages = array_chunk(
        $wrappedLines ?: ['No report data available.'],
        50
    );

    $pageCount = count($pages);
    $fontObjectId = 3 + (2 * $pageCount);
    $objects = [];
    $kids = [];

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

    for ($i = 0; $i < $pageCount; $i++) {
        $pageObjectId = 3 + ($i * 2);
        $contentObjectId = $pageObjectId + 1;

        $kids[] = $pageObjectId . ' 0 R';
        $stream = "BT\n/F1 9 Tf\n45 755 Td\n13 TL\n";

        foreach ($pages[$i] as $line) {
            $stream .= '(' . dhReportPdfEscape($line) . ") Tj\nT*\n";
        }

        $stream .= "ET\n";

        $objects[$pageObjectId] =
            '<< /Type /Page /Parent 2 0 R '
            . '/MediaBox [0 0 612 792] '
            . '/Resources << /Font << /F1 '
            . $fontObjectId
            . ' 0 R >> >> /Contents '
            . $contentObjectId
            . ' 0 R >>';

        $objects[$contentObjectId] =
            '<< /Length ' . strlen($stream) . " >>\n"
            . "stream\n" . $stream . 'endstream';
    }

    $objects[2] =
        '<< /Type /Pages /Kids ['
        . implode(' ', $kids)
        . '] /Count ' . $pageCount . ' >>';

    $objects[$fontObjectId] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica '
        . '/Encoding /WinAnsiEncoding >>';

    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];

    foreach ($objects as $objectId => $body) {
        $offsets[$objectId] = strlen($pdf);
        $pdf .= $objectId . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $objectCount = max(array_keys($objects)) + 1;

    $pdf .= "xref\n0 " . $objectCount . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i < $objectCount; $i++) {
        $offset = $offsets[$i] ?? 0;

        $pdf .= sprintf(
            '%010d 00000 %s ',
            $offset,
            isset($offsets[$i]) ? 'n' : 'f'
        ) . "\n";
    }

    $pdf .= 'trailer << /Size ' . $objectCount
        . " /Root 1 0 R >>\nstartxref\n"
        . $xrefOffset . "\n%%EOF";

    return $pdf;
}

/* --------------------------------------------------------------------------
   Filter description
-------------------------------------------------------------------------- */

$selectedFilterParts = [];

if ($academicYearFilter !== '') {
    $selectedFilterParts[] = $academicYearFilter;
}

if ($semesterFilter !== '') {
    $selectedFilterParts[] = $semesterFilter . ' Semester';
}

if ($statusFilter !== '') {
    $selectedFilterParts[] = ucwords(
        str_replace('_', ' ', $statusFilter)
    );
}

$filterDescription = $selectedFilterParts === []
    ? 'All academic periods and event statuses'
    : implode(' · ', $selectedFilterParts);

/* --------------------------------------------------------------------------
   CSV export
-------------------------------------------------------------------------- */

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');

    header(
        'Content-Disposition: attachment; filename="department-reports-'
        . date('Ymd') . '.csv"'
    );

    $output = fopen('php://output', 'wb');

    if ($output === false) {
        throw new RuntimeException('Unable to create the CSV export.');
    }

    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['WEAMMS Department Reports and Analytics']);
    fputcsv($output, ['Department', $departmentHead['department_name']]);
    fputcsv($output, ['Filters', $filterDescription]);
    fputcsv($output, ['Generated', date('M j, Y g:i A')]);
    fputcsv($output, []);

    fputcsv($output, ['OVERVIEW']);
    fputcsv($output, ['Total events', count($eventRows)]);

    fputcsv($output, [
        'Combined attendance rate (finalized events; Excused excluded; weighted by eligible student-event opportunities)',
        $averageAttendanceRate === null
            ? 'N/A'
            : $averageAttendanceRate . '%'
    ]);

    fputcsv($output, [
        'Rate formula',
        '(Present + Late) / (Eligible students - Excused)'
    ]);

    fputcsv($output, [
        'Excluded events',
        'Cancelled and rejected events do not contribute attendance outcomes or rates.'
    ]);

    foreach ($statuses as $status) {
        fputcsv($output, [ucfirst($status), $statusTotals[$status]]);
    }

    fputcsv($output, []);
    fputcsv($output, ['EVENT ATTENDANCE REPORT']);

    fputcsv($output, [
        'Event', 'Date', 'Academic year', 'Semester', 'Event status',
        'Eligible students', 'Recorded entries', 'Present', 'Late',
        'Absent', 'Excused', 'Attendance rate'
    ]);

    foreach ($eventRows as $row) {
        fputcsv($output, array_map('dhReportCsvSafe', [
            $row['event_title'],
            date('Y-m-d', strtotime($row['start_at'])),
            $row['academic_year'],
            $row['semester'],
            $row['event_status'],
            $row['eligible_students'],
            $row['record_count'],
            $row['present'],
            $row['late'],
            $row['absent'],
            $row['excused'],
            $row['attendance_excluded']
                ? 'Excluded'
                : (
                    $row['attendance_rate'] === null
                        ? 'N/A'
                        : $row['attendance_rate'] . '%'
                ),
        ]));
    }

    fputcsv($output, []);

    fputcsv($output, [
        'ATTENDANCE BY ACADEMIC PERIOD (combined rate weighted by rate-eligible student-event pairs)'
    ]);

    fputcsv($output, [
        'Academic year', 'Semester', 'Events', 'Combined attendance rate',
        'Present', 'Late', 'Absent', 'Excused'
    ]);

    foreach ($periodSummaries as $row) {
        fputcsv($output, array_map('dhReportCsvSafe', [
            $row['academic_year'],
            $row['semester'],
            $row['event_count'],
            $row['average_rate'] === null
                ? 'N/A'
                : $row['average_rate'] . '%',
            $row['present'],
            $row['late'],
            $row['absent'],
            $row['excused'],
        ]));
    }

    fputcsv($output, []);
    fputcsv($output, ['STUDENT ATTENDANCE PERCENTAGES']);

    fputcsv($output, [
        'Student name', 'Student number', 'Eligible completed events',
        'Rate-eligible events', 'Present', 'Late', 'Absent', 'Excused',
        'Attendance percentage'
    ]);

    foreach ($studentRows as $row) {
        fputcsv($output, array_map('dhReportCsvSafe', [
            $row['student_name'],
            $row['student_number'],
            $row['eligible_events'],
            $row['rate_eligible_events'],
            $row['present'],
            $row['late'],
            $row['absent'],
            $row['excused'],
            $row['attendance_rate'] === null
                ? 'N/A'
                : $row['attendance_rate'] . '%',
        ]));
    }

    fclose($output);
    exit;
}

/* --------------------------------------------------------------------------
   PDF export (built in; no external PDF library required)
-------------------------------------------------------------------------- */

if (($_GET['export'] ?? '') === 'pdf') {
    $pdfLines = [
        'WEAMMS - DEPARTMENT REPORTS AND ANALYTICS',
        'Department: ' . $departmentHead['department_name'],
        'Filters: ' . $filterDescription,
        'Generated: ' . date('M j, Y g:i A'),
        '',
        'OVERVIEW',
        'Total events: ' . count($eventRows),
        'Combined attendance rate: '
            . ($averageAttendanceRate === null
                ? 'N/A'
                : $averageAttendanceRate . '%')
            . ' (finalized events; Excused excluded; weighted)',
        'Present: ' . $statusTotals['present']
            . ' | Late: ' . $statusTotals['late']
            . ' | Absent: ' . $statusTotals['absent']
            . ' | Excused: ' . $statusTotals['excused'],
        'Rate = (Present + Late) divided by (Eligible students - Excused).',
        'Excused students are excluded from the rate denominator; all-excused events have N/A rate.',
        'Missing check-ins count as absent only for completed or past approved events.',
        'Cancelled and rejected events are excluded from attendance analytics.',
        '',
        'EVENT ATTENDANCE REPORT',
        str_pad('Event', 23)
            . str_pad('Date', 12)
            . str_pad('Year/Sem.', 15)
            . str_pad('Status', 15)
            . str_pad('Elig.', 6)
            . str_pad('P', 4)
            . str_pad('L', 4)
            . str_pad('A', 4)
            . str_pad('E', 4)
            . 'Rate',
        str_repeat('-', 96),
    ];

    foreach ($eventRows as $row) {
        $yearSemester = $row['academic_year'] . ' ' . $row['semester'];

        $pdfLines[] =
            str_pad(dhReportShortText((string) $row['event_title'], 22), 23)
            . str_pad(date('Y-m-d', strtotime($row['start_at'])), 12)
            . str_pad(dhReportShortText($yearSemester, 14), 15)
            . str_pad(
                dhReportShortText(
                    ucwords(str_replace('_', ' ', $row['event_status'])),
                    14
                ),
                15
            )
            . str_pad((string) $row['eligible_students'], 6)
            . str_pad((string) $row['present'], 4)
            . str_pad((string) $row['late'], 4)
            . str_pad((string) $row['absent'], 4)
            . str_pad((string) $row['excused'], 4)
            . (
                $row['attendance_excluded']
                    ? 'EXCLUDED'
                    : (
                        $row['attendance_rate'] === null
                            ? 'N/A'
                            : $row['attendance_rate'] . '%'
                    )
            );
    }

    $pdfLines[] = '';
    $pdfLines[] = 'ATTENDANCE BY ACADEMIC PERIOD';

    $pdfLines[] =
        'Academic year | Semester | Events | Combined rate | Present | Late | Absent | Excused';

    foreach ($periodSummaries as $row) {
        $pdfLines[] =
            $row['academic_year'] . ' | '
            . $row['semester'] . ' | '
            . $row['event_count'] . ' | '
            . (
                $row['average_rate'] === null
                    ? 'N/A'
                    : $row['average_rate'] . '%'
            ) . ' | '
            . $row['present'] . ' | '
            . $row['late'] . ' | '
            . $row['absent'] . ' | '
            . $row['excused'];
    }

    $pdfLines[] = '';
    $pdfLines[] = 'STUDENT ATTENDANCE PERCENTAGES';

    $pdfLines[] =
        'Student | Student No. | Eligible events | Rate-eligible | Present | Late | Absent | Excused | Rate';

    foreach ($studentRows as $row) {
        $pdfLines[] =
            dhReportShortText($row['student_name'], 25)
            . ' | ' . $row['student_number']
            . ' | ' . $row['eligible_events']
            . ' | ' . $row['rate_eligible_events']
            . ' | ' . $row['present']
            . ' | ' . $row['late']
            . ' | ' . $row['absent']
            . ' | ' . $row['excused']
            . ' | '
            . (
                $row['attendance_rate'] === null
                    ? 'N/A'
                    : $row['attendance_rate'] . '%'
            );
    }

    if ($studentRows === []) {
        $pdfLines[] =
            'No completed or past approved events match the selected filters.';
    }

    $pdf = dhReportMakePdf($pdfLines);

    header('Content-Type: application/pdf');

    header(
        'Content-Disposition: attachment; filename="department-reports-'
        . date('Ymd') . '.pdf"'
    );

    header('Content-Length: ' . strlen($pdf));

    echo $pdf;
    exit;
}

/* --------------------------------------------------------------------------
   Build filter URLs
-------------------------------------------------------------------------- */

$filterQuery = http_build_query(array_filter([
    'academic_year' => $academicYearFilter,
    'semester' => $semesterFilter,
    'status' => $statusFilter,
], static fn(string $value): bool => $value !== ''));

$exportPrefix = $filterQuery !== ''
    ? '?' . $filterQuery . '&'
    : '?';

require_once __DIR__ . '/../../includes/department_head_layout_start.php';

?>

<section class="dashboard-section management-page student-report-page dh-report-page">

    <div class="section-heading">
        <div>
            <h2>Reports and Analytics</h2>
        </div>

        <div class="student-report-actions dh-report-actions">
            <a
                class="management-button"
                href="<?= dhEscape($baseUrl) ?>/department-head/reports/index.php<?= dhEscape($exportPrefix) ?>export=csv">
                Export CSV
            </a>

            <a
                class="management-button management-button-secondary"
                href="<?= dhEscape($baseUrl) ?>/department-head/reports/index.php<?= dhEscape($exportPrefix) ?>export=pdf">
                Export PDF
            </a>
        </div>
    </div>

    <?php
    $statusChartData = array_values($statusTotals);
    $hasStatusData = array_sum($statusChartData) > 0;

    $overallRateLabel = $averageAttendanceRate === null
        ? '—'
        : number_format($averageAttendanceRate, 1) . '%';
    ?>

    <!-- Department banner with floating filter -->
    <div class="dh-report-scope">
        <span>
            <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
        </span>

        <div class="dh-report-scope-text">
            <strong><?= dhEscape($departmentHead['department_name']) ?></strong>
            <small>
                Department-level report · <?= dhEscape($filterDescription) ?>
            </small>
        </div>

        <details class="dh-filter-menu">
            <summary class="management-button management-button-secondary">
                <i class="fa-solid fa-filter" aria-hidden="true"></i>
                Filter
                <?php if ($filterQuery !== ''): ?>
                    <span class="dh-filter-dot" aria-label="Filters active"></span>
                <?php endif; ?>
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </summary>

            <form
                method="get"
                class="dh-filter-dropdown"
                action="<?= dhEscape($baseUrl) ?>/department-head/reports/index.php">

                <p class="dh-filter-hint">
                    Choose an academic year, semester, and event status
                    to update all report sections.
                </p>

                <div class="dh-filter-field">
                    <label for="academic_year">Academic Year</label>
                    <select name="academic_year" id="academic_year">
                        <option value="">All academic years</option>
                        <?php foreach ($academicYears as $year): ?>
                            <option
                                value="<?= dhEscape($year) ?>"
                                <?= $academicYearFilter === $year ? 'selected' : '' ?>>
                                <?= dhEscape($year) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dh-filter-field">
                    <label for="semester">Semester</label>
                    <select name="semester" id="semester">
                        <option value="">All semesters</option>
                        <?php foreach ($semesters as $semester): ?>
                            <?php
                            $semesterText = dhEscape($semester);
                            $semesterLabel = match ($semesterText) {
                                '1st' => '1st Semester',
                                '2nd' => '2nd Semester',
                                default => $semesterText,
                            };
                            ?>
                            <option
                                value="<?= $semesterText ?>"
                                <?= $semesterFilter === $semester ? 'selected' : '' ?>>
                                <?= $semesterLabel ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dh-filter-field">
                    <label for="status">Event Status</label>
                    <select name="status" id="status">
                        <option value="">All statuses</option>
                        <?php foreach ($eventStatuses as $status): ?>
                            <option
                                value="<?= dhEscape($status) ?>"
                                <?= $statusFilter === $status ? 'selected' : '' ?>>
                                <?= dhEscape(ucwords(str_replace('_', ' ', $status))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dh-filter-actions">
                    <?php if ($filterQuery !== ''): ?>
                        <a
                            class="management-link dh-clear-filters"
                            href="<?= dhEscape($baseUrl) ?>/department-head/reports/index.php">
                            Clear filters
                        </a>
                    <?php endif; ?>

                    <button
                        class="management-button dh-apply-filters"
                        type="submit">
                        Apply Filters
                    </button>
                </div>
            </form>
        </details>
    </div>

    <!-- Main overview: donut on the left, status bar chart on the right -->
    <div class="dh-overview-grid">

        <article class="student-content-panel dh-chart-card dh-overview-card">
            <h3>Attendance Overview</h3>

            <p class="dh-overview-subtitle">
                Department attendance rate and total events
            </p>

            <div class="dh-overview-donut-wrap">
                <canvas
                    id="dhAttendanceRateDonut"
                    aria-label="Overall department attendance rate"
                    role="img"></canvas>

                <div class="dh-overview-donut-center">
                    <strong><?= dhEscape($overallRateLabel) ?></strong>
                    <span>overall rate</span>
                </div>
            </div>

            <div class="dh-overview-total">
                <span>Total Events</span>
                <strong><?= number_format(count($eventRows)) ?></strong>
            </div>

            <p class="dh-overview-note">
                Weighted finalized-event rate; excused attendance is
                excluded from the denominator.
            </p>
        </article>

        <article class="student-content-panel dh-chart-card dh-overview-card">
            <h3>Attendance Status</h3>

            <p class="dh-overview-subtitle">
                Attendance outcomes matching your selected filters
            </p>

            <?php if ($hasStatusData): ?>
                <div class="dh-chart-box dh-status-bar-box">
                    <canvas
                        id="dhAttendanceStatusBar"
                        aria-label="Bar chart showing Present, Late, Absent, and Excused totals"
                        role="img"></canvas>
                </div>

                <div class="dh-status-legend">
                    <div>
                        <span class="dh-status-legend-dot present"></span>
                        <span>Present:</span>
                        <strong><?= number_format($statusTotals['present']) ?></strong>
                    </div>

                    <div>
                        <span class="dh-status-legend-dot late"></span>
                        <span>Late:</span>
                        <strong><?= number_format($statusTotals['late']) ?></strong>
                    </div>

                    <div>
                        <span class="dh-status-legend-dot absent"></span>
                        <span>Absent:</span>
                        <strong><?= number_format($statusTotals['absent']) ?></strong>
                    </div>

                    <div>
                        <span class="dh-status-legend-dot excused"></span>
                        <span>Excused:</span>
                        <strong><?= number_format($statusTotals['excused']) ?></strong>
                    </div>
                </div>
            <?php else: ?>
                <p class="dh-chart-empty">No attendance outcomes yet.</p>
            <?php endif; ?>
        </article>

    </div>

    <!-- Event Attendance Report: existing detailed report -->
    <div class="student-content-panel dh-report-panel">
        <div class="section-heading">
            <div>
                <h2>Event Attendance Report</h2>
            </div>
        </div>

        <?php if ($eventRows === []): ?>
            <div class="empty-state">
                <strong>No matching events</strong>
                <p>Try changing the filters to view events for this department.</p>
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
                            <th scope="col">Eligible</th>
                            <th scope="col">Present</th>
                            <th scope="col">Late</th>
                            <th scope="col">Absent</th>
                            <th scope="col">Excused</th>
                            <th scope="col">Attendance Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventRows as $event): ?>
                            <tr>
                                <td data-label="Event">
                                    <strong><?= dhEscape($event['event_title']) ?></strong>
                                    <small class="dh-event-venue">
                                        <?= dhEscape($event['venue'] ?: 'Venue not specified') ?>
                                    </small>
                                </td>

                                <td data-label="Date">
                                    <?= dhEscape(date('M j, Y', strtotime($event['start_at']))) ?>
                                </td>

                                <td data-label="Academic Period">
                                    <?= dhEscape($event['academic_year']) ?><br>
                                    <?= dhEscape($event['semester']) ?>
                                </td>

                                <td data-label="Status">
                                    <span class="status-pill status-<?= dhEscape($event['event_status']) ?>">
                                        <?= dhEscape(ucwords(str_replace('_', ' ', $event['event_status']))) ?>
                                    </span>
                                </td>

                                <td data-label="Eligible">
                                    <?= (int) $event['eligible_students'] ?>
                                </td>

                                <td data-label="Present">
                                    <?= (int) $event['present'] ?>
                                </td>

                                <td data-label="Late">
                                    <?= (int) $event['late'] ?>
                                </td>

                                <td data-label="Absent">
                                    <?= (int) $event['absent'] ?>
                                </td>

                                <td data-label="Excused">
                                    <?= (int) $event['excused'] ?>
                                </td>

                                <td data-label="Attendance Rate">
                                    <?php if ($event['attendance_excluded']): ?>
                                        <span class="dh-rate-pending">Excluded</span>
                                    <?php elseif (!$event['attendance_finalized']): ?>
                                        <span class="dh-rate-pending">Not finalized</span>
                                    <?php elseif ($event['attendance_rate'] === null): ?>
                                        <span class="dh-rate-pending">
                                            N/A (all excused or no eligible students)
                                        </span>
                                    <?php else: ?>
                                        <strong class="dh-rate-value">
                                            <?= number_format((float) $event['attendance_rate'], 1) ?>%
                                        </strong>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Attendance by Academic Period -->
    <div class="student-content-panel dh-report-panel">
        <div class="section-heading">
            <div>
                <h2>Attendance by Academic Period</h2>
            </div>
        </div>

        <?php if ($periodSummaries === []): ?>
            <div class="empty-state">
                <strong>No academic-period data</strong>
                <p>
                    Period comparisons will appear when matching department
                    events are available.
                </p>
            </div>
        <?php else: ?>
            <div class="student-table-wrap">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th scope="col">Academic Period</th>
                            <th scope="col">Events</th>
                            <th scope="col">Combined Attendance Rate</th>
                            <th scope="col">Present</th>
                            <th scope="col">Late</th>
                            <th scope="col">Absent</th>
                            <th scope="col">Excused</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periodSummaries as $period): ?>
                            <tr>
                                <td data-label="Academic Period">
                                    <?= dhEscape($period['academic_year']) ?>
                                    · <?= dhEscape($period['semester']) ?>
                                </td>
                                <td data-label="Events">
                                    <?= (int) $period['event_count'] ?>
                                </td>
                                <td data-label="Combined Attendance Rate">
                                    <?= $period['average_rate'] === null
                                        ? '—'
                                        : number_format((float) $period['average_rate'], 1) . '%' ?>
                                </td>
                                <td data-label="Present">
                                    <?= (int) $period['present'] ?>
                                </td>
                                <td data-label="Late">
                                    <?= (int) $period['late'] ?>
                                </td>
                                <td data-label="Absent">
                                    <?= (int) $period['absent'] ?>
                                </td>
                                <td data-label="Excused">
                                    <?= (int) $period['excused'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Student Attendance Percentages -->
    <div class="student-content-panel dh-report-panel">
        <div class="section-heading">
            <div>
                <h2>Student Attendance Percentages</h2>
            </div>
        </div>

        <?php if ($studentRows === []): ?>
            <div class="empty-state">
                <strong>No student attendance percentages yet</strong>
                <p>
                    Student percentages will appear after matching department
                    events are completed or approved events have ended.
                </p>
            </div>
        <?php else: ?>
            <div class="student-table-wrap">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th scope="col">Student</th>
                            <th scope="col">Student Number</th>
                            <th scope="col">Eligible Events</th>
                            <th scope="col">Present</th>
                            <th scope="col">Late</th>
                            <th scope="col">Absent</th>
                            <th scope="col">Excused</th>
                            <th scope="col">Attendance %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studentRows as $student): ?>
                            <tr>
                                <td data-label="Student">
                                    <strong><?= dhEscape($student['student_name']) ?></strong>
                                </td>

                                <td data-label="Student Number">
                                    <?= dhEscape($student['student_number']) ?>
                                </td>

                                <td data-label="Eligible Events">
                                    <?= (int) $student['eligible_events'] ?>
                                </td>

                                <td data-label="Present">
                                    <?= (int) $student['present'] ?>
                                </td>

                                <td data-label="Late">
                                    <?= (int) $student['late'] ?>
                                </td>

                                <td data-label="Absent">
                                    <?= (int) $student['absent'] ?>
                                </td>

                                <td data-label="Excused">
                                    <?= (int) $student['excused'] ?>
                                </td>

                                <td data-label="Attendance %">
                                    <?php if ($student['attendance_rate'] === null): ?>
                                        <span class="dh-rate-pending">N/A</span>
                                    <?php else: ?>
                                        <strong class="dh-rate-value">
                                            <?= number_format((float) $student['attendance_rate'], 1) ?>%
                                        </strong>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var menu = document.querySelector('.dh-filter-menu');

    // Close the floating filter after clicking outside.
    document.addEventListener('click', function (event) {
        if (menu && !menu.contains(event.target)) {
            menu.open = false;
        }
    });

    // Escape also closes the filter.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && menu) {
            menu.open = false;
        }
    });

    if (typeof Chart === 'undefined') {
        console.error('Chart.js is not loaded.');
        return;
    }

    var C = {
        present: '#1e8b39',
        late: '#F2C300',
        absent: '#641414',
        excused: '#5985c5',
        text: '#171717',
        grid: '#E3E7EC',
        brand: '#8B1E1E',
        ring: '#EEE7E4'
    };

    var names = ['Present', 'Late', 'Absent', 'Excused'];

    // Values come from the existing PHP calculations.
    var totals = <?= json_encode($statusChartData) ?>;
    var rateValue = <?= json_encode($averageAttendanceRate) ?>;

    /*
     * LEFT: Overall attendance rate donut.
     * Total Events is displayed separately in the same card.
     */
    var rateDonut = document.getElementById('dhAttendanceRateDonut');

    if (rateDonut) {
        var hasRate =
            rateValue !== null
            && rateValue !== undefined
            && Number.isFinite(Number(rateValue));

        var rate = hasRate
            ? Math.max(0, Math.min(100, Number(rateValue)))
            : 0;

        new Chart(rateDonut, {
            type: 'doughnut',

            data: {
                labels: ['Attendance rate', 'Remaining'],

                datasets: [{
                    data: hasRate ? [rate, 100 - rate] : [0, 100],
                    backgroundColor: [C.brand, C.ring],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '73%',

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                if (!hasRate) {
                                    return 'Attendance rate: N/A';
                                }

                                return context.label + ': '
                                    + Number(context.raw).toFixed(1) + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    /*
     * RIGHT: Attendance status bar chart.
     * The values are Present, Late, Absent, and Excused.
     */
    var statusBar = document.getElementById('dhAttendanceStatusBar');

    if (statusBar) {
        var largest = Math.max(0, ...totals);
        var targetMax = Math.max(5, largest);

        var step = targetMax <= 5 ? 1
            : targetMax <= 10 ? 2
            : targetMax <= 25 ? 5
            : targetMax <= 50 ? 10
            : Math.pow(10, Math.floor(Math.log10(targetMax))) / 5;

        var axisMax = Math.ceil(targetMax / step) * step;

        new Chart(statusBar, {
            type: 'bar',

            data: {
                labels: names,

                datasets: [{
                    label: 'Attendance outcomes',
                    data: totals,

                    backgroundColor: [
                        C.present,
                        C.late,
                        C.absent,
                        C.excused
                    ],

                    borderWidth: 0,
                    categoryPercentage: 0.7,
                    barPercentage: 0.7,
                    maxBarThickness: 48
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                scales: {
                    x: {
                        grid: {
                            display: false
                        },

                        ticks: {
                            color: C.text,
                            font: {
                                size: 12
                            }
                        }
                    },

                    y: {
                        beginAtZero: true,
                        min: 0,
                        max: axisMax,

                        ticks: {
                            stepSize: step,
                            precision: 0,
                            color: C.text,
                            font: {
                                size: 11
                            }
                        },

                        grid: {
                            color: C.grid
                        },

                        border: {
                            display: false
                        }
                    }
                },

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.label + ': '
                                    + Number(context.raw || 0);
                            },

                            footer: function (items) {
                                var total = items.length
                                    ? items[0].chart.data.datasets[0].data
                                        .reduce(function (sum, value) {
                                            return sum + Number(value || 0);
                                        }, 0)
                                    : 0;

                                return 'Total attendance outcomes: ' + total;
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>

</body>
</html>