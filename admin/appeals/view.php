<?php

$adminPageTitle = 'Attendance Appeal';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
require_once __DIR__ . '/../../includes/admin_lists.php';

$appealId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($appealId === false || $appealId < 1) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}

$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.appeal_reason, aa.evidence_path, aa.appeal_status,
            aa.submitted_at, aa.reviewed_at, aa.reviewer_remarks,
            ea.attendance_status, ea.record_source,
            e.event_title, e.start_at, d.department_name,
            r.student_number, r.first_name, r.middle_name, r.last_name, r.year_level, r.section,
            dh.first_name AS rv_first, dh.middle_name AS rv_middle, dh.last_name AS rv_last
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN departments d ON d.department_id = e.department_id
     INNER JOIN event_attendance_roster r
        ON r.event_id = ea.event_id AND r.student_id = aa.student_id
     LEFT JOIN department_heads dh ON dh.department_head_id = aa.reviewed_by_department_head_id
     WHERE aa.appeal_id = ?
     LIMIT 1'
);
$stmt->execute([$appealId]);
$appeal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$appeal) {
    http_response_code(404);
    exit('Appeal not found.');
}

$pills = [
    'pending' => 'ad-pill-yellow',
    'approved' => 'ad-pill-green',
    'rejected' => 'ad-pill-red',
    'withdrawn' => 'ad-pill-gray',
];
$studentName = adminFullName($appeal['first_name'], $appeal['middle_name'], $appeal['last_name']);
$reviewerName = adminFullName($appeal['rv_first'], $appeal['rv_middle'], $appeal['rv_last']);
$status = $appeal['appeal_status'];
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Attendance Appeal</h1>
            <p><?= adminEscape($studentName) ?> · <?= adminEscape($appeal['student_number']) ?></p>
        </div>
        <div class="ad-head-actions">
            <a class="ad-btn" href="<?= adminEscape($adminBaseUrl) ?>/admin/appeals/index.php">Back to appeals</a>
        </div>
    </header>

    <div class="ad-card">
        <h2 class="ad-card-title">
            <?= adminEscape($appeal['event_title']) ?>
            <span class="ad-pill <?= adminEscape($pills[$status] ?? 'ad-pill-gray') ?>"><?= adminEscape(ucfirst($status)) ?></span>
        </h2>
        <p class="ad-card-sub"><?= adminEscape($appeal['department_name']) ?> · <?= adminEscape(date('M j, Y', strtotime($appeal['start_at']))) ?></p>

        <div class="ad-table-wrap">
            <table class="ad-table">
                <tbody>
                    <tr><th scope="row">Student</th><td><?= adminEscape($studentName) ?> (<?= adminEscape($appeal['student_number']) ?>)</td></tr>
                    <tr><th scope="row">Year / section</th><td><?= adminEscape(adminYearSection($appeal['year_level'], $appeal['section'])) ?></td></tr>
                    <tr><th scope="row">Recorded attendance</th><td><?= adminEscape(ucfirst($appeal['attendance_status'])) ?> · <?= adminEscape(ucwords(str_replace(['_', 'barcode'], [' ', 'QR code'], $appeal['record_source']))) ?></td></tr>
                    <tr><th scope="row">Submitted</th><td><?= adminEscape(date('M j, Y · g:i A', strtotime($appeal['submitted_at']))) ?></td></tr>
                    <tr><th scope="row">Student's reason</th><td><?= nl2br(adminEscape($appeal['appeal_reason'])) ?></td></tr>
                    <tr>
                        <th scope="row">Supporting proof</th>
                        <td>
                            <?php if (!empty($appeal['evidence_path'])): ?>
                                <a class="ad-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/appeals/evidence.php?id=<?= (int) $appealId ?>" target="_blank" rel="noopener">Open submitted proof</a>
                            <?php else: ?>
                                No supporting proof attached.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($appeal['reviewed_at'] !== null): ?>
                        <tr><th scope="row">Reviewed by</th><td><?= adminEscape($reviewerName !== '' ? $reviewerName : '—') ?> · <?= adminEscape(date('M j, Y · g:i A', strtotime($appeal['reviewed_at']))) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($appeal['reviewer_remarks'])): ?>
                        <tr><th scope="row">Review remarks</th><td><?= nl2br(adminEscape($appeal['reviewer_remarks'])) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($status === 'pending'): ?>
            <p class="ad-card-sub">This appeal is awaiting a decision from the <?= adminEscape($appeal['department_name']) ?> department head.</p>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
