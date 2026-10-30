<?php

require_once __DIR__ . '/../../includes/department_head_context.php';

if ((int) $departmentHead['department_head_can_edit_attendance'] !== 1) {
    http_response_code(403);
    exit('Your administrator has not authorized attendance corrections.');
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$eventId = filter_var($input['event_id'] ?? null, FILTER_VALIDATE_INT);
$studentId = filter_var($input['student_id'] ?? null, FILTER_VALIDATE_INT);
if ($eventId === false || $eventId < 1 || $studentId === false || $studentId < 1) {
    http_response_code(400);
    exit('A valid event and student must be specified.');
}

$recordStmt = $pdo->prepare(
    'SELECT e.event_id, e.event_title, e.department_id,
            r.student_id, r.student_number, r.first_name, r.middle_name,
            r.last_name, ea.attendance_id, ea.attendance_status,
            ea.check_in_at, ea.record_source, ea.remarks
     FROM events e
     INNER JOIN event_attendance_roster r ON r.event_id = e.event_id
     LEFT JOIN event_attendance ea
        ON ea.event_id = e.event_id AND ea.student_id = r.student_id
     WHERE e.event_id = ? AND e.department_id = ?
       AND r.student_id = ?
     LIMIT 1'
);
$recordStmt->execute([$eventId, $departmentId, $studentId]);
$record = $recordStmt->fetch(PDO::FETCH_ASSOC);
if (!$record) {
    http_response_code(404);
    exit('Student or event not found in your department.');
}

$errors = [];
$statuses = ['present', 'late', 'absent', 'excused'];
$statusValue = $_POST['attendance_status'] ?? ($record['attendance_status'] ?? 'present');
$remarksValue = $_POST['remarks'] ?? ($record['remarks'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dhVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token expired. Refresh the page and try again.';
    } elseif (
        !is_string($statusValue) ||
        !in_array($statusValue, $statuses, true) ||
        !is_string($remarksValue) ||
        dhCharacterLength(trim($remarksValue)) > 500 ||
        ($statusValue === 'excused' && trim($remarksValue) === '')
    ) {
        $errors[] = 'Choose a valid attendance status, provide remarks for an Excused correction, and keep remarks to 500 characters or fewer.';
    } else {
        $pdo->beginTransaction();
        try {
            $scopeLock = $pdo->prepare(
                'SELECT e.event_id
                 FROM events e
                 INNER JOIN event_attendance_roster r ON r.event_id = e.event_id
                 WHERE e.event_id = ? AND e.department_id = ? AND r.student_id = ?
                 FOR UPDATE'
            );
            $scopeLock->execute([$eventId, $departmentId, $studentId]);
            if ($scopeLock->fetchColumn() === false) {
                throw new RuntimeException('This student or event is no longer assigned to your department.');
            }
            $currentStmt = $pdo->prepare(
                'SELECT attendance_id, attendance_status, record_source,
                        check_in_at, remarks
                 FROM event_attendance
                 WHERE event_id = ? AND student_id = ?
                 FOR UPDATE'
            );
            $currentStmt->execute([$eventId, $studentId]);
            $old = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $newRemarks = trim($remarksValue);

            if ($old) {
                $save = $pdo->prepare(
                    'UPDATE event_attendance
                     SET attendance_status = ?, record_source = ?,
                         recorded_by_user_id = ?, remarks = ?
                     WHERE attendance_id = ?'
                );
                $save->execute([
                    $statusValue,
                    'department_head',
                    $_SESSION['user_id'],
                    $newRemarks !== '' ? $newRemarks : null,
                    $old['attendance_id'],
                ]);
                $attendanceId = (int) $old['attendance_id'];
                $oldValues = [
                    'attendance_status' => $old['attendance_status'],
                    'record_source' => $old['record_source'],
                    'check_in_at' => $old['check_in_at'],
                    'remarks' => $old['remarks'],
                ];
            } else {
                $save = $pdo->prepare(
                    'INSERT INTO event_attendance
                        (event_id, student_id, attendance_status, check_in_at,
                         recorded_by_user_id, record_source, remarks)
                     VALUES (?, ?, ?, NULL, ?, ?, ?)'
                );
                $save->execute([
                    $eventId,
                    $studentId,
                    $statusValue,
                    $_SESSION['user_id'],
                    'department_head',
                    $newRemarks !== '' ? $newRemarks : null,
                ]);
                $attendanceId = (int) $pdo->lastInsertId();
                $oldValues = null;
            }

            $audit = $pdo->prepare(
                'INSERT INTO attendance_audit_logs
                    (attendance_id, event_id, actor_user_id, action_type,
                     old_value, new_value, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $audit->execute([
                $attendanceId,
                $eventId,
                $_SESSION['user_id'],
                $old ? 'attendance_corrected' : 'attendance_recorded',
                $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR),
                json_encode([
                    'attendance_status' => $statusValue,
                    'record_source' => 'department_head',
                    'remarks' => $newRemarks,
                ], JSON_THROW_ON_ERROR),
                $newRemarks !== '' ? $newRemarks : null,
            ]);
            $pdo->commit();
            header('Location: ' . $baseUrl . '/department-head/attendance/records.php?event_id=' . $eventId . '&notice=updated');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                $errors[] = $e->getMessage();
            } else {
                throw $e;
            }
        }
    }
}

$pageTitle = 'Correct Attendance';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';
$studentName = trim(
    $record['first_name'] . ' ' .
    ($record['middle_name'] ? $record['middle_name'] . ' ' : '') .
    $record['last_name']
);
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div><h2>Correct Attendance</h2><p><?= dhEscape($record['event_title']) ?> · <?= dhEscape($studentName) ?> · <?= dhEscape($record['student_number']) ?></p></div>
        <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/records.php?event_id=<?= (int) $eventId ?>">Back to attendance</a>
    </div>
    <?php if ($errors !== []): ?>
        <div class="management-alert" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= dhEscape($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form class="management-form department-panel" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/attendance/correct.php">
        <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
        <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
        <input type="hidden" name="student_id" value="<?= (int) $studentId ?>">
        <div class="management-field">
            <label for="attendance_status">Attendance status</label>
            <select id="attendance_status" name="attendance_status" required>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= dhEscape($status) ?>" <?= $statusValue === $status ? 'selected' : '' ?>><?= dhEscape(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="management-field management-field-full">
            <label for="remarks">Correction remarks</label>
            <textarea id="remarks" name="remarks" maxlength="500" rows="4"><?= dhEscape(is_string($remarksValue) ? $remarksValue : '') ?></textarea>
        </div>
        <div class="management-form-actions"><button class="management-button" type="submit">Save Correction</button></div>
    </form>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
