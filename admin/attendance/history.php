<?php

$adminPageTitle = 'Attendance Change History';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
$attendanceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$attendanceId || $attendanceId < 1) {
    http_response_code(400);
    exit('A valid attendance record is required.');
}
$recordStmt = $pdo->prepare(
    'SELECT ea.attendance_id, ea.attendance_status, ea.record_source, ea.check_in_at,
            e.event_title, r.student_number, r.first_name, r.last_name
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN event_attendance_roster r
        ON r.event_id = ea.event_id AND r.student_id = ea.student_id
     WHERE ea.attendance_id = ?'
);
$recordStmt->execute([$attendanceId]);
$record = $recordStmt->fetch(PDO::FETCH_ASSOC);
if (!$record) {
    http_response_code(404);
    exit('Attendance record not found.');
}
$historyStmt = $pdo->prepare(
    'SELECT al.action_type, al.old_value, al.new_value, al.notes, al.created_at, u.username
     FROM attendance_audit_logs al
     LEFT JOIN users u ON u.user_id = al.actor_user_id
     WHERE al.attendance_id = ?
     ORDER BY al.created_at DESC'
);
$historyStmt->execute([$attendanceId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="admin-content-section">
    <div class="admin-page-heading"><div><span class="admin-eyebrow">Attendance audit</span><h2>Change History</h2><p><?= adminEscape($record['event_title']) ?> · <?= adminEscape($record['first_name'] . ' ' . $record['last_name']) ?> (<?= adminEscape($record['student_number']) ?>)</p></div><a class="admin-action-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/attendance/index.php">Back to attendance</a></div>
    <section class="admin-panel"><div class="admin-panel-heading"><div><h3>Current record</h3><p><?= adminEscape(ucfirst($record['attendance_status'])) ?> · <?= adminEscape(ucwords(str_replace(['_', 'barcode'], [' ', 'QR code'], $record['record_source']))) ?></p></div></div>
        <?php if ($history === []): ?><p>No change history is recorded for this entry.</p><?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Changed</th><th>Staff member</th><th>Action</th><th>Before</th><th>After</th><th>Remarks</th></tr></thead><tbody><?php foreach ($history as $item): ?><tr><td data-label="Changed"><?= adminEscape(date('M j, Y · g:i A', strtotime($item['created_at']))) ?></td><td data-label="Staff member"><?= adminEscape($item['username'] ?: 'Unknown') ?></td><td data-label="Action"><?= adminEscape($item['action_type']) ?></td><td data-label="Before"><?= adminEscape($item['old_value'] ?: '—') ?></td><td data-label="After"><?= adminEscape($item['new_value'] ?: '—') ?></td><td data-label="Remarks"><?= adminEscape($item['notes'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
