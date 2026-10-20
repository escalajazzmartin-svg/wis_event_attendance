<?php

require_once __DIR__ . '/../../includes/student_context.php';

$attendanceId = filter_var(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['attendance_id'] ?? null)
        : ($_GET['attendance_id'] ?? null),
    FILTER_VALIDATE_INT
);
if ($attendanceId === false || $attendanceId < 1) {
    http_response_code(400);
    exit('Select a valid attendance record to appeal.');
}

$recordStmt = $pdo->prepare(
    'SELECT ea.attendance_id, ea.attendance_status, ea.student_id,
            e.event_title, e.event_id
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE ea.attendance_id = ? AND ea.student_id = ?
       AND e.department_id = ?
     LIMIT 1'
);
$recordStmt->execute([$attendanceId, $studentId, $studentDepartmentId]);
$record = $recordStmt->fetch(PDO::FETCH_ASSOC);
if (!$record) {
    http_response_code(404);
    exit('Attendance record not found in your account.');
}

$pendingStmt = $pdo->prepare(
    "SELECT appeal_id FROM attendance_appeals
     WHERE attendance_id = ? AND student_id = ? AND appeal_status = 'pending'
     LIMIT 1"
);
$pendingStmt->execute([$attendanceId, $studentId]);
if ($pendingStmt->fetchColumn() !== false) {
    header('Location: ' . BASE_URL . '/student/appeals/index.php');
    exit;
}

$reason = '';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim(is_string($_POST['appeal_reason'] ?? null) ? $_POST['appeal_reason'] : '');
    if (!studentVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token expired. Refresh the page and try again.';
    }
    if ($reason === '' || studentCharacterLength($reason) > 4000) {
        $errors[] = 'Explain the attendance issue in 1–4,000 characters.';
    }
    if (($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE && $record['attendance_status'] === 'absent') {
        $errors[] = 'Attach supporting proof for an absence appeal.';
    }

    $evidencePath = null;
    if ($errors === []) {
        try {
            $evidencePath = studentStoreEvidenceUpload($_FILES['evidence'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                "SELECT attendance_id FROM event_attendance
                 WHERE attendance_id = ? AND student_id = ? FOR UPDATE"
            );
            $lock->execute([$attendanceId, $studentId]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('This attendance record is no longer available.');
            }
            $active = $pdo->prepare(
                "SELECT appeal_id FROM attendance_appeals
                 WHERE attendance_id = ? AND student_id = ? AND appeal_status = 'pending'
                 FOR UPDATE"
            );
            $active->execute([$attendanceId, $studentId]);
            if ($active->fetchColumn() !== false) {
                throw new RuntimeException('A pending appeal already exists for this attendance record.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO attendance_appeals
                    (attendance_id, student_id, appeal_reason, evidence_path, appeal_status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([$attendanceId, $studentId, $reason, $evidencePath, 'pending']);
            $pdo->commit();
            header('Location: ' . BASE_URL . '/student/appeals/index.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($evidencePath !== null) {
                $stored = studentEvidenceFilePath($evidencePath);
                if ($stored !== null) {
                    unlink($stored);
                }
            }
            throw $e;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($evidencePath !== null) {
                $stored = studentEvidenceFilePath($evidencePath);
                if ($stored !== null) {
                    unlink($stored);
                }
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($evidencePath !== null) {
                $stored = studentEvidenceFilePath($evidencePath);
                if ($stored !== null) {
                    unlink($stored);
                }
            }
            throw $e;
        }
    }
}

$studentPageTitle = 'Submit Attendance Appeal';
require_once __DIR__ . '/../../includes/student_layout_start.php';
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading"><div><h2>Submit Attendance Appeal</h2><p><?= studentEscape($record['event_title']) ?> · Current status: <?= studentEscape(ucfirst($record['attendance_status'])) ?></p></div><a href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/index.php">Back to attendance</a></div>
    <?php if ($errors !== []): ?><div class="management-alert" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= studentEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="student-content-panel student-form" method="post" enctype="multipart/form-data" action="<?= studentEscape($studentBaseUrl) ?>/student/appeals/create.php">
        <input type="hidden" name="csrf_token" value="<?= studentEscape(studentCsrfToken()) ?>">
        <input type="hidden" name="attendance_id" value="<?= (int) $attendanceId ?>">
        <label for="appeal_reason">Explain why this attendance record is incorrect or disputed</label>
        <textarea id="appeal_reason" name="appeal_reason" maxlength="4000" required rows="6"><?= studentEscape($reason) ?></textarea>
        <label for="evidence">Supporting proof <?= $record['attendance_status'] === 'absent' ? '(required for absence appeals)' : '(PDF, JPG, or PNG; maximum 5 MB)' ?></label>
        <input id="evidence" type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" <?= $record['attendance_status'] === 'absent' ? 'required' : '' ?>>
        <button class="management-button" type="submit">Submit Appeal</button>
    </form>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>
</html>
