<?php

$studentPageTitle = 'Attendance Appeal Details';
require_once __DIR__ . '/../../includes/student_layout_start.php';

$appealId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($appealId === false || $appealId < 1) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}
$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.attendance_id, aa.appeal_reason,
            aa.evidence_path, aa.appeal_status, aa.submitted_at,
            aa.reviewed_at, aa.reviewer_remarks, ea.attendance_status,
            e.event_id, e.event_title, e.start_at, ap.academic_year, ap.semester
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
     WHERE aa.appeal_id = ? AND aa.student_id = ?
       AND ea.student_id = ? AND e.department_id = ?
     LIMIT 1'
);
$stmt->execute([$appealId, $studentId, $studentId, $studentDepartmentId]);
$appeal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$appeal) {
    http_response_code(404);
    exit('Appeal not found in your account.');
}
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading"><div><h2>Appeal Details</h2><p><?= studentEscape($appeal['event_title']) ?> · <?= studentEscape($appeal['academic_year']) ?> / <?= studentEscape($appeal['semester']) ?></p></div><a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/index.php">Back to appeals</a></div>
    <article class="student-content-panel student-event-detail">
        <div class="student-schedule-heading"><span class="status-pill status-<?= studentEscape($appeal['appeal_status']) ?>"><?= studentEscape(ucfirst($appeal['appeal_status'])) ?></span><span>Attendance: <?= studentEscape(ucfirst($appeal['attendance_status'])) ?></span></div>
        <dl class="student-detail-grid">
            <div><dt>Submitted</dt><dd><?= studentEscape(studentDateTime($appeal['submitted_at'])) ?></dd></div>
            <div><dt>Reviewed</dt><dd><?= studentEscape(studentDateTime($appeal['reviewed_at'])) ?></dd></div>
            <div class="student-detail-wide"><dt>Your explanation</dt><dd><?= nl2br(studentEscape($appeal['appeal_reason'])) ?></dd></div>
            <div class="student-detail-wide"><dt>Supporting proof</dt><dd><?php if ($appeal['evidence_path']): ?><a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/evidence.php?id=<?= (int) $appealId ?>" target="_blank" rel="noopener">View submitted proof</a><?php else: ?>No proof attached.<?php endif; ?></dd></div>
            <div class="student-detail-wide"><dt>Reviewer remarks</dt><dd><?= $appeal['reviewer_remarks'] ? nl2br(studentEscape($appeal['reviewer_remarks'])) : 'No review decision yet.' ?></dd></div>
        </dl>
        <?php if ($appeal['appeal_status'] === 'pending'): ?>
            <div class="student-report-actions">
                <a class="management-button" href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/edit.php?id=<?= (int) $appealId ?>">Edit pending appeal</a>
                <form method="post" action="<?= studentEscape($studentBaseUrl) ?>/student/appeals/withdraw.php">
                    <input type="hidden" name="csrf_token" value="<?= studentEscape(studentCsrfToken()) ?>">
                    <input type="hidden" name="appeal_id" value="<?= (int) $appealId ?>">
                    <button class="management-button management-button-secondary" type="submit">Withdraw appeal</button>
                </form>
            </div>
        <?php endif; ?>
    </article>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>
</html>
