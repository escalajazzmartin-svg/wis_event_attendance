<?php

$studentPageTitle = 'My Attendance Appeals';
require_once __DIR__ . '/../../includes/student_layout_start.php';

$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.appeal_reason, aa.appeal_status,
            aa.evidence_path, aa.submitted_at, aa.reviewed_at,
            aa.reviewer_remarks, ea.attendance_id, ea.attendance_status,
            e.event_id, e.event_title, e.start_at
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE aa.student_id = ? AND ea.student_id = ?
       AND e.department_id = ?
     ORDER BY aa.submitted_at DESC, aa.appeal_id DESC'
);
$stmt->execute([$studentId, $studentId, $studentDepartmentId]);
$appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading"><div><h2>My Attendance Appeals</h2><p>Track appeal decisions and reviewer remarks. Pending appeals may be edited or withdrawn.</p></div></div>
    <?php if ($appeals === []): ?>
        <div class="student-content-panel empty-state"><strong>No appeals submitted</strong><p>You can appeal a disputed attendance record from My Attendance.</p><a class="management-button" href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/index.php">My Attendance</a></div>
    <?php else: ?>
        <div class="student-content-panel student-table-wrap">
            <table class="student-table">
                <thead><tr><th scope="col">Event</th><th scope="col">Attendance</th><th scope="col">Reason</th><th scope="col">Submitted</th><th scope="col">Appeal status</th><th scope="col">Review remarks</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($appeals as $appeal): ?>
                        <tr>
                            <td data-label="Event"><a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $appeal['event_id'] ?>"><?= studentEscape($appeal['event_title']) ?></a></td>
                            <td data-label="Attendance"><span class="status-pill status-<?= studentEscape($appeal['attendance_status']) ?>"><?= studentEscape(ucfirst($appeal['attendance_status'])) ?></span></td>
                            <td data-label="Reason"><?= studentEscape(substr($appeal['appeal_reason'], 0, 140)) ?></td>
                            <td data-label="Submitted"><?= studentEscape(studentDateTime($appeal['submitted_at'])) ?></td>
                            <td data-label="Appeal status"><span class="status-pill status-<?= studentEscape($appeal['appeal_status']) ?>"><?= studentEscape(ucfirst($appeal['appeal_status'])) ?></span></td>
                            <td data-label="Review remarks"><?= studentEscape($appeal['reviewer_remarks'] ?: 'Not reviewed yet') ?></td>
                            <td data-label="Actions">
                                <a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/view.php?id=<?= (int) $appeal['appeal_id'] ?>">Details</a>
                                <?php if ($appeal['appeal_status'] === 'pending'): ?>
                                    <a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/edit.php?id=<?= (int) $appeal['appeal_id'] ?>">Edit</a>
                                    <form method="post" action="<?= studentEscape($studentBaseUrl) ?>/student/appeals/withdraw.php">
                                        <input type="hidden" name="csrf_token" value="<?= studentEscape(studentCsrfToken()) ?>">
                                        <input type="hidden" name="appeal_id" value="<?= (int) $appeal['appeal_id'] ?>">
                                        <button class="student-inline-action" type="submit">Withdraw</button>
                                    </form>
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
