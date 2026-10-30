<?php

$pageTitle = 'Attendance Appeals';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$statuses = ['pending', 'approved', 'rejected'];
$statusFilter = $_GET['status'] ?? 'pending';
if (!is_string($statusFilter) || !in_array($statusFilter, $statuses, true)) {
    $statusFilter = 'pending';
}

$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.appeal_reason, aa.appeal_status,
            aa.submitted_at, e.event_title, e.start_at,
            r.student_number, r.first_name, r.middle_name, r.last_name
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN event_attendance_roster r
        ON r.event_id = ea.event_id AND r.student_id = aa.student_id
     WHERE e.department_id = ? AND aa.appeal_status = ?
     ORDER BY aa.submitted_at DESC'
);
$stmt->execute([$departmentId, $statusFilter]);
$appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div>
            <h2>Attendance Appeals</h2>
            <p>Review student reasons and supporting proof for attendance appeals in your department.</p>
        </div>
    </div>

    <form class="management-filter" method="get" action="<?= dhEscape($baseUrl) ?>/department-head/appeals/index.php">
        <label for="appeal-status">Appeal status</label>
        <select id="appeal-status" name="status" onchange="this.form.requestSubmit()">
            <?php foreach ($statuses as $status): ?>
                <option value="<?= dhEscape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                    <?= dhEscape(ucfirst($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="management-button management-button-secondary" type="submit">Filter</button></noscript>
    </form>

    <?php if ($appeals === []): ?>
        <div class="department-panel empty-state">
            <strong>No <?= dhEscape($statusFilter) ?> appeals</strong>
            <p>Student attendance appeals will appear here.</p>
        </div>
    <?php else: ?>
        <div class="management-table-wrap">
            <table class="management-table">
                <thead>
                    <tr><th scope="col">Student</th><th scope="col">Event</th><th scope="col">Reason</th><th scope="col">Submitted</th><th scope="col">Review</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($appeals as $appeal): ?>
                        <?php $studentName = trim($appeal['first_name'] . ' ' . ($appeal['middle_name'] ? $appeal['middle_name'] . ' ' : '') . $appeal['last_name']); ?>
                        <tr>
                            <td data-label="Student">
                                <strong><?= dhEscape($studentName) ?></strong>
                                <span><?= dhEscape($appeal['student_number']) ?></span>
                            </td>
                            <td data-label="Event">
                                <?= dhEscape($appeal['event_title']) ?>
                                <span><?= dhEscape(date('M j, Y', strtotime($appeal['start_at']))) ?></span>
                            </td>
                            <td data-label="Reason"><?= dhEscape(substr($appeal['appeal_reason'], 0, 120)) ?></td>
                            <td data-label="Submitted"><?= dhEscape(date('M j, Y · g:i A', strtotime($appeal['submitted_at']))) ?></td>
                            <td data-label="Review">
                                <a class="management-button management-button-secondary" href="<?= dhEscape($baseUrl) ?>/department-head/appeals/view.php?id=<?= (int) $appeal['appeal_id'] ?>">Review</a>
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
