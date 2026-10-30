<?php

$pageTitle = 'Review Attendance Appeal';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$appealId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($appealId === false || $appealId < 1) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}

$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.attendance_id, aa.student_id,
            aa.appeal_reason, aa.evidence_path, aa.appeal_status,
            aa.submitted_at, aa.reviewed_at, aa.reviewer_remarks,
            ea.attendance_status, ea.event_id,
            e.event_title, e.start_at, e.department_id,
            r.student_number, r.first_name, r.middle_name, r.last_name
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN event_attendance_roster r
        ON r.event_id = ea.event_id AND r.student_id = aa.student_id
     WHERE aa.appeal_id = ? AND e.department_id = ?
     LIMIT 1'
);
$stmt->execute([$appealId, $departmentId]);
$appeal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$appeal) {
    http_response_code(404);
    exit('Appeal not found in your department.');
}

$studentName = trim(
    $appeal['first_name'] . ' ' .
    ($appeal['middle_name'] ? $appeal['middle_name'] . ' ' : '') .
    $appeal['last_name']
);
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div>
            <h2>Review Attendance Appeal</h2>
            <p><?= dhEscape($studentName) ?> · <?= dhEscape($appeal['student_number']) ?></p>
        </div>
        <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/appeals/index.php">Back to appeals</a>
    </div>

    <div class="management-detail department-panel">
        <div class="management-detail-heading">
            <span class="status-pill status-<?= dhEscape($appeal['appeal_status']) ?>"><?= dhEscape(ucfirst($appeal['appeal_status'])) ?></span>
            <span><?= dhEscape($appeal['event_title']) ?> · <?= dhEscape(date('M j, Y', strtotime($appeal['start_at']))) ?></span>
        </div>
        <dl class="management-detail-grid">
            <div><dt>Attendance status</dt><dd><?= dhEscape(ucfirst($appeal['attendance_status'])) ?></dd></div>
            <div><dt>Submitted</dt><dd><?= dhEscape(date('M j, Y · g:i A', strtotime($appeal['submitted_at']))) ?></dd></div>
            <div class="management-detail-full"><dt>Student's reason</dt><dd><?= nl2br(dhEscape($appeal['appeal_reason'])) ?></dd></div>
            <div class="management-detail-full">
                <dt>Supporting proof</dt>
                <dd>
                    <?php if (!empty($appeal['evidence_path'])): ?>
                        <a href="<?= dhEscape($baseUrl) ?>/department-head/appeals/evidence.php?id=<?= (int) $appealId ?>" target="_blank" rel="noopener">Open submitted proof</a>
                    <?php else: ?>
                        No supporting proof attached.
                    <?php endif; ?>
                </dd>
            </div>
            <?php if ($appeal['reviewer_remarks']): ?>
                <div class="management-detail-full"><dt>Previous review remarks</dt><dd><?= nl2br(dhEscape($appeal['reviewer_remarks'])) ?></dd></div>
            <?php endif; ?>
        </dl>

        <?php if ($appeal['appeal_status'] === 'pending'): ?>
            <form class="management-form" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/appeals/decision.php">
                <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                <input type="hidden" name="appeal_id" value="<?= (int) $appealId ?>">
                <div class="management-field management-field-full">
                    <label for="reviewer_remarks">Review remarks</label>
                    <textarea id="reviewer_remarks" name="reviewer_remarks" maxlength="2000" required rows="4"></textarea>
                </div>
                <div class="management-field">
                    <label for="approved_status">Corrected status if approved</label>
                    <select id="approved_status" name="approved_status">
                        <option value="excused">Excused</option>
                        <option value="present">Present</option>
                        <option value="late">Late</option>
                    </select>
                </div>
                <label class="management-field-full">
                    <input type="checkbox" name="proof_verified" value="1" required>
                    <?= !empty($appeal['evidence_path'])
                        ? "I reviewed the student's reason and supporting proof."
                        : "I reviewed the student's reason; no supporting proof was attached." ?>
                </label>
                <div class="management-form-actions">
                    <button class="management-button" type="submit" name="decision" value="approved">Approve appeal</button>
                    <button class="management-button management-button-secondary" type="submit" name="decision" value="rejected">Reject appeal</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
