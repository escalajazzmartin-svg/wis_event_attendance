<?php

require_once __DIR__ . '/../../includes/student_context.php';

$studentPageTitle = 'My Attendance Appeals';
$studentPageCss = 'student-appeals.css';

$stmt = $pdo->prepare(
    'SELECT
        aa.appeal_id,
        aa.appeal_reason,
        aa.appeal_status,
        aa.evidence_path,
        aa.submitted_at,
        aa.reviewed_at,
        aa.reviewer_remarks,
        ea.attendance_id,
        ea.attendance_status,
        e.event_id,
        e.event_title,
        e.start_at
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea
        ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e
        ON e.event_id = ea.event_id
     WHERE aa.student_id = ?
       AND ea.student_id = ?
     ORDER BY aa.submitted_at DESC, aa.appeal_id DESC'
);

$stmt->execute([
    $studentId,
    $studentId
]);

$appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/student_layout_start.php';

$statusClass = static function ($status): string {
    $status = strtolower(trim((string) $status));

    return preg_replace('/[^a-z0-9_-]/', '', $status);
};
?>

<section class="dashboard-section student-module-page">

    <div class="section-heading">
        <div>
            <h2>My Attendance Appeals</h2>
            <p>
                Track appeal decisions and reviewer remarks.
                Pending appeals may be edited or withdrawn.
            </p>
        </div>
    </div>

    <?php if (empty($appeals)): ?>

        <div class="student-content-panel empty-state">
            <strong>No appeals submitted</strong>

            <p>
                You can appeal a disputed attendance record from My Attendance.
            </p>

            <a
                class="management-button"
                href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/index.php">
                <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>
                My Attendance
            </a>
        </div>

    <?php else: ?>

        <div class="student-content-panel student-table-wrap">
            <table class="student-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">Attendance</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Submitted</th>
                        <th scope="col">Appeal status</th>
                        <th scope="col">Review remarks</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($appeals as $appeal): ?>
                        <?php
                        $appealStatus = strtolower(
                            trim((string) ($appeal['appeal_status'] ?? ''))
                        );

                        $attendanceStatus = strtolower(
                            trim((string) ($appeal['attendance_status'] ?? ''))
                        );

                        $reason = (string) ($appeal['appeal_reason'] ?? '');
                        $remarks = trim(
                            (string) ($appeal['reviewer_remarks'] ?? '')
                        );
                        ?>

                        <tr>
                            <td data-label="Event">
                                <a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $appeal['event_id'] ?>">
                                    <?= studentEscape($appeal['event_title'] ?? 'Untitled event') ?>
                                </a>
                            </td>

                            <td data-label="Attendance">
                                <span class="status-pill status-<?= studentEscape($statusClass($attendanceStatus)) ?>">
                                    <?= studentEscape(ucfirst($attendanceStatus ?: 'Unknown')) ?>
                                </span>
                            </td>

                            <td data-label="Reason">
                                <span title="<?= studentEscape($reason) ?>">
                                    <?= studentEscape(
                                        strlen($reason) > 140
                                            ? substr($reason, 0, 137) . '...'
                                            : $reason
                                    ) ?>
                                </span>
                            </td>

                            <td data-label="Submitted">
                                <?= studentEscape(studentDateTime($appeal['submitted_at'])) ?>
                            </td>

                            <td data-label="Appeal status">
                                <span class="status-pill status-<?= studentEscape($statusClass($appealStatus)) ?>">
                                    <?= studentEscape(ucfirst($appealStatus ?: 'Unknown')) ?>
                                </span>
                            </td>

                            <td data-label="Review remarks">
                                <?= studentEscape($remarks !== '' ? $remarks : 'Not reviewed yet') ?>
                            </td>

                            <td data-label="Actions">
                                <div class="student-appeal-actions">

                                    <a
                                        class="student-icon-action"
                                        href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/view.php?id=<?= (int) $appeal['appeal_id'] ?>"
                                        title="View details"
                                        aria-label="View appeal details">
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                    </a>

                                    <?php if ($appealStatus === 'pending'): ?>

                                        <a
                                            class="student-icon-action"
                                            href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/edit.php?id=<?= (int) $appeal['appeal_id'] ?>"
                                            title="Edit appeal"
                                            aria-label="Edit appeal">
                                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                        </a>

                                        <form
                                            method="post"
                                            action="<?= studentEscape($studentBaseUrl) ?>/student/appeals/withdraw.php"
                                            onsubmit="return confirm('Are you sure you want to withdraw this appeal?');">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= studentEscape(studentCsrfToken()) ?>">

                                            <input
                                                type="hidden"
                                                name="appeal_id"
                                                value="<?= (int) $appeal['appeal_id'] ?>">

                                            <button
                                                class="student-icon-action student-icon-withdraw"
                                                type="submit"
                                                title="Withdraw appeal"
                                                aria-label="Withdraw appeal">
                                                <i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i>
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                </div>
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
