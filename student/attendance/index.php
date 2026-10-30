<?php

require_once __DIR__ . '/../../includes/student_context.php';

$studentPageTitle = 'My Attendance';
$studentPageCss = 'student-attendance.css';

require_once __DIR__ . '/../../includes/student_layout_start.php';

$attendanceStmt = $pdo->prepare(
    "SELECT ea.attendance_id, ea.attendance_status, ea.check_in_at,
            ea.remarks, ea.record_source, e.event_id, e.event_title,
            e.start_at, e.end_at, ap.academic_year, ap.semester,
            aa.appeal_id, aa.appeal_status, aa.reviewer_remarks
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
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
     ORDER BY e.start_at DESC, ea.attendance_id DESC"
);
$attendanceStmt->execute([$studentId]);
$records = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
$summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
foreach ($records as $record) {
    if (isset($summary[$record['attendance_status']])) {
        $summary[$record['attendance_status']]++;
    }
}
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading">
        <div>
            <h2>My Attendance</h2>
            <p>View your personal event attendance and show your QR code at an authorized station.</p>
        </div>
        <a class="management-button" href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/scan.php">View My QR Code</a>
    </div>
    <div class="student-attendance-stats student-attendance-summary">
        <div>
            <dt>Present</dt>
            <dd><?= $summary['present'] ?></dd>
        </div>
        <div>
            <dt>Late</dt>
            <dd><?= $summary['late'] ?></dd>
        </div>
        <div>
            <dt>Absent</dt>
            <dd><?= $summary['absent'] ?></dd>
        </div>
        <div>
            <dt>Excused</dt>
            <dd><?= $summary['excused'] ?></dd>
        </div>
    </div>
    <div class="student-content-panel">
        <div class="section-heading">
            <div>
                <h2>Attendance Records</h2>
                <p><?= count($records) ?> records · Attendance status changes must be reviewed by authorized staff.</p>
            </div>
        </div>
        <?php if ($records === []): ?>
            <div class="empty-state"><strong>No attendance records yet</strong>
                <p>Once an authorized attendance station records your scan, your record will appear here.</p>
            </div>
        <?php else: ?>
            <div class="student-table-wrap">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Schedule</th>
                            <th scope="col">Academic period</th>
                            <th scope="col">Status</th>
                            <th scope="col">Timestamp</th>
                            <th scope="col">Appeal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td data-label="Event"><a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $record['event_id'] ?>"><?= studentEscape($record['event_title']) ?></a></td>
                                <td data-label="Schedule"><?= studentEscape(studentDateTime($record['start_at'])) ?></td>
                                <td data-label="Academic period"><?= studentEscape($record['academic_year']) ?> · <?= studentEscape($record['semester']) ?></td>
                                <td data-label="Status"><span class="status-pill status-<?= studentEscape($record['attendance_status']) ?>"><?= studentEscape(ucfirst($record['attendance_status'])) ?></span></td>
                                <td data-label="Timestamp"><?= studentEscape(studentDateTime($record['check_in_at'])) ?></td>
                                <td data-label="Appeal">
                                    <?php
                                    $latestAppealStatus = (string) ($record['appeal_status'] ?? '');
                                    $canAppeal = in_array($record['attendance_status'], ['absent', 'late'], true)
                                        && (!$record['appeal_id'] || $latestAppealStatus === 'withdrawn');
                                    ?>
                                    <?php if ($record['appeal_id'] && $latestAppealStatus !== 'withdrawn'): ?>
                                        <a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/view.php?id=<?= (int) $record['appeal_id'] ?>">
                                            <?= studentEscape(ucfirst($latestAppealStatus)) ?>
                                        </a>
                                    <?php elseif ($canAppeal): ?>
                                        <a class="student-appeal-link" href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/create.php?attendance_id=<?= (int) $record['attendance_id'] ?>">
                                            <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                                            <?= $record['appeal_id'] ? 'Appeal again' : 'Appeal' ?>
                                        </a>
                                    <?php else: ?>
                                        <span aria-label="No appeal available">—</span>
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
