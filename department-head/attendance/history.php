<?php

$pageTitle = 'Attendance Change History';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$attendanceId = filter_var($_GET['attendance_id'] ?? null, FILTER_VALIDATE_INT);
if ($attendanceId === false || $attendanceId < 1) {
    http_response_code(400);
    exit('A valid attendance record must be specified.');
}

$scopeStmt = $pdo->prepare(
    'SELECT ea.attendance_id, ea.event_id, e.event_title,
            r.student_number, r.first_name, r.middle_name, r.last_name
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN event_attendance_roster r
        ON r.event_id = ea.event_id AND r.student_id = ea.student_id
     WHERE ea.attendance_id = ? AND e.department_id = ?
     LIMIT 1'
);
$scopeStmt->execute([$attendanceId, $departmentId]);
$attendance = $scopeStmt->fetch(PDO::FETCH_ASSOC);
if (!$attendance) {
    http_response_code(404);
    exit('Attendance record not found in your department.');
}

$historyStmt = $pdo->prepare(
    'SELECT al.action_type, al.old_value, al.new_value, al.notes,
            al.created_at, u.username
     FROM attendance_audit_logs al
     LEFT JOIN users u ON u.user_id = al.actor_user_id
     WHERE al.attendance_id = ? AND al.event_id = ?
     ORDER BY al.created_at DESC, al.audit_log_id DESC'
);
$historyStmt->execute([$attendanceId, $attendance['event_id']]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
$studentName = trim($attendance['first_name'] . ' ' . ($attendance['middle_name'] ? $attendance['middle_name'] . ' ' : '') . $attendance['last_name']);
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div><h2>Attendance Change History</h2><p><?= dhEscape($attendance['event_title']) ?> · <?= dhEscape($studentName) ?> (<?= dhEscape($attendance['student_number']) ?>)</p></div>
        <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/index.php">Back to monitoring</a>
    </div>
    <?php if ($history === []): ?>
        <div class="department-panel empty-state"><strong>No changes recorded</strong><p>Future corrections and appeal decisions will be listed here with the responsible staff member.</p></div>
    <?php else: ?>
        <div class="management-table-wrap">
            <table class="management-table">
                <thead><tr><th scope="col">When</th><th scope="col">Staff member</th><th scope="col">Action</th><th scope="col">Old value</th><th scope="col">New value</th><th scope="col">Remarks</th></tr></thead>
                <tbody>
                    <?php foreach ($history as $entry): ?>
                        <tr>
                            <td data-label="When"><?= dhEscape(date('M j, Y · g:i A', strtotime($entry['created_at']))) ?></td>
                            <td data-label="Staff member"><?= dhEscape($entry['username'] ?: 'Unknown user') ?></td>
                            <td data-label="Action"><?= dhEscape(ucwords(str_replace('_', ' ', $entry['action_type']))) ?></td>
                            <td data-label="Old value"><code><?= dhEscape($entry['old_value'] ?: '—') ?></code></td>
                            <td data-label="New value"><code><?= dhEscape($entry['new_value'] ?: '—') ?></code></td>
                            <td data-label="Remarks"><?= dhEscape($entry['notes'] ?: '—') ?></td>
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
