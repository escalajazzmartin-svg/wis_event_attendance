<?php

require_once __DIR__ . '/../../includes/student_context.php';

$studentPageTitle = 'Attendance Appeal Details';
$studentPageCss = 'student-appeals.css';

$appealId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($appealId === false || $appealId === null || $appealId < 1) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}

$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.attendance_id, aa.appeal_reason,
            aa.evidence_path, aa.appeal_status, aa.submitted_at,
            aa.reviewed_at, aa.reviewer_remarks, ea.attendance_status,
            e.event_id, e.event_title, e.start_at,
            ap.academic_year, ap.semester
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea
        ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
     WHERE aa.appeal_id = ?
       AND aa.student_id = ?
       AND ea.student_id = ?
     LIMIT 1'
);

$stmt->execute([
    $appealId,
    $studentId,
    $studentId
]);

$appeal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appeal) {
    http_response_code(404);
    exit('Appeal not found in your account.');
}

require_once __DIR__ . '/../../includes/student_layout_start.php';

$appealStatus = strtolower((string) $appeal['appeal_status']);
$remarks = trim((string) ($appeal['reviewer_remarks'] ?? ''));
?>

<section class="dashboard-section student-module-page">

    <div class="section-heading">
        <div>
            <h2>Attendance Appeal Details</h2>
            <p>
                <a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $appeal['event_id'] ?>">
                    <?= studentEscape($appeal['event_title']) ?>
                </a>
                · <?= studentEscape(studentDateTime($appeal['start_at'])) ?>
            </p>
        </div>

        <a class="student-appeal-link"
            href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/index.php">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            Back to appeals
        </a>
    </div>

    <div class="student-content-panel">
        <dl class="student-appeal-detail">
            <div>
                <dt>Appeal status</dt>
                <dd>
                    <span class="status-pill status-<?= studentEscape(preg_replace('/[^a-z0-9_-]/', '', $appealStatus)) ?>">
                        <?= studentEscape(ucfirst($appealStatus)) ?>
                    </span>
                </dd>
            </div>
            <div>
                <dt>Attendance record</dt>
                <dd>
                    <span class="status-pill status-<?= studentEscape($appeal['attendance_status']) ?>">
                        <?= studentEscape(ucfirst($appeal['attendance_status'])) ?>
                    </span>
                </dd>
            </div>
            <div>
                <dt>Academic period</dt>
                <dd><?= studentEscape($appeal['academic_year']) ?> · <?= studentEscape($appeal['semester']) ?></dd>
            </div>
            <div>
                <dt>Submitted</dt>
                <dd><?= studentEscape(studentDateTime($appeal['submitted_at'])) ?></dd>
            </div>
            <div class="student-appeal-detail-full">
                <dt>Your reason</dt>
                <dd><?= nl2br(studentEscape($appeal['appeal_reason'])) ?></dd>
            </div>
            <div class="student-appeal-detail-full">
                <dt>Supporting proof</dt>
                <dd>
                    <?php if (!empty($appeal['evidence_path'])): ?>
                        <a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/evidence.php?id=<?= (int) $appeal['appeal_id'] ?>"
                            target="_blank" rel="noopener">
                            <i class="fa-solid fa-paperclip" aria-hidden="true"></i>
                            Open submitted proof
                        </a>
                    <?php else: ?>
                        No supporting proof attached.
                    <?php endif; ?>
                </dd>
            </div>
            <div class="student-appeal-detail-full">
                <dt>Reviewer remarks</dt>
                <dd>
                    <?= $remarks !== ''
                        ? nl2br(studentEscape($remarks))
                        : 'Not reviewed yet.' ?>
                    <?php if (!empty($appeal['reviewed_at'])): ?>
                        <br><small>Reviewed <?= studentEscape(studentDateTime($appeal['reviewed_at'])) ?></small>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>

        <?php if ($appealStatus === 'pending'): ?>
            <div class="student-appeal-form-actions">
                <a class="student-appeal-button"
                    href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/edit.php?id=<?= (int) $appeal['appeal_id'] ?>">
                    <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                    Edit appeal
                </a>

                <form method="post"
                    action="<?= studentEscape($studentBaseUrl) ?>/student/appeals/withdraw.php"
                    onsubmit="return confirm('Are you sure you want to withdraw this appeal?');">
                    <input type="hidden" name="csrf_token"
                        value="<?= studentEscape(studentCsrfToken()) ?>">
                    <input type="hidden" name="appeal_id"
                        value="<?= (int) $appeal['appeal_id'] ?>">
                    <button class="student-appeal-button student-appeal-button-secondary" type="submit">
                        <i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i>
                        Withdraw
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>

</html>
