<?php

require_once __DIR__ . '/../../includes/department_head_context.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
if (!dhVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request token. Refresh the appeal and try again.');
}

$appealId = filter_var($_POST['appeal_id'] ?? null, FILTER_VALIDATE_INT);
$decision = $_POST['decision'] ?? '';
$approvedStatus = $_POST['approved_status'] ?? 'excused';
$remarks = trim(is_string($_POST['reviewer_remarks'] ?? null) ? $_POST['reviewer_remarks'] : '');

if (
    $appealId === false || $appealId < 1 ||
    !in_array($decision, ['approved', 'rejected'], true) ||
    ($decision === 'approved' && !in_array($approvedStatus, ['present', 'late', 'excused'], true)) ||
    $remarks === '' || dhCharacterLength($remarks) > 2000 ||
    ($_POST['proof_verified'] ?? null) !== '1'
) {
    http_response_code(400);
    exit('Provide a valid decision, review remarks, and confirmation that the appeal evidence was reviewed.');
}

try {
    $pdo->beginTransaction();
    $appealStmt = $pdo->prepare(
        'SELECT aa.appeal_id, aa.attendance_id, aa.student_id,
                aa.appeal_reason, aa.evidence_path, aa.appeal_status,
                ea.event_id, ea.attendance_status, ea.record_source,
                ea.remarks AS attendance_remarks
         FROM attendance_appeals aa
         INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
         INNER JOIN events e ON e.event_id = ea.event_id
         WHERE aa.appeal_id = ? AND e.department_id = ?
           AND ea.student_id = aa.student_id
         FOR UPDATE'
    );
    $appealStmt->execute([$appealId, $departmentId]);
    $appeal = $appealStmt->fetch(PDO::FETCH_ASSOC);

    if (!$appeal || $appeal['appeal_status'] !== 'pending') {
        $pdo->rollBack();
        http_response_code(409);
        exit('This appeal is no longer pending or does not belong to your department.');
    }

    $review = $pdo->prepare(
        'UPDATE attendance_appeals
         SET appeal_status = ?, reviewed_by_department_head_id = ?,
             reviewed_at = NOW(), reviewer_remarks = ?
         WHERE appeal_id = ? AND appeal_status = ?'
    );
    $review->execute([$decision, $departmentHeadId, $remarks, $appealId, 'pending']);

    if ($decision === 'approved') {
        $newAttendanceRemarks = trim(
            ($appeal['attendance_remarks'] ? $appeal['attendance_remarks'] . "\n" : '') .
            ucfirst($approvedStatus) . ' after approved appeal: ' . $remarks
        );
        $attendanceUpdate = $pdo->prepare(
            "UPDATE event_attendance
             SET attendance_status = ?, record_source = 'appeal',
                 recorded_by_user_id = ?, remarks = ?
             WHERE attendance_id = ?"
        );
        $attendanceUpdate->execute([
            $approvedStatus,
            $_SESSION['user_id'],
            substr($newAttendanceRemarks, 0, 500),
            $appeal['attendance_id'],
        ]);
        $audit = $pdo->prepare(
            'INSERT INTO attendance_audit_logs
                (attendance_id, event_id, actor_user_id, action_type,
                 old_value, new_value, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $audit->execute([
            $appeal['attendance_id'],
            $appeal['event_id'],
            $_SESSION['user_id'],
            'appeal_approved',
            json_encode(['attendance_status' => $appeal['attendance_status'], 'record_source' => $appeal['record_source']]),
            json_encode(['attendance_status' => $approvedStatus, 'record_source' => 'appeal']),
            $remarks,
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

header('Location: ' . $baseUrl . '/department-head/appeals/view.php?id=' . $appealId);
exit;
