<?php

require_once __DIR__ . '/../../includes/student_context.php';

$appealId = filter_var(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['appeal_id'] ?? null)
        : ($_GET['id'] ?? null),
    FILTER_VALIDATE_INT
);
if ($appealId === false || $appealId < 1) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}

$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.attendance_id, aa.appeal_reason,
            aa.evidence_path, aa.appeal_status, ea.attendance_status,
            e.event_title
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE aa.appeal_id = ? AND aa.student_id = ?
       AND ea.student_id = ? AND e.department_id = ?
     LIMIT 1'
);
$stmt->execute([$appealId, $studentId, $studentId, $studentDepartmentId]);
$appeal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$appeal) {
    http_response_code(404);
    exit('Appeal not found in your account.');
}
if ($appeal['appeal_status'] !== 'pending') {
    http_response_code(409);
    exit('Only pending appeals can be edited.');
}

$reason = $appeal['appeal_reason'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim(is_string($_POST['appeal_reason'] ?? null) ? $_POST['appeal_reason'] : '');
    if (!studentVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token expired. Refresh the page and try again.';
    }
    if ($reason === '' || studentCharacterLength($reason) > 4000) {
        $errors[] = 'Explain the attendance issue in 1–4,000 characters.';
    }
    $removeEvidence = ($_POST['remove_evidence'] ?? '') === '1';
    $newEvidence = null;
    if (
        $appeal['attendance_status'] === 'absent' &&
        empty($appeal['evidence_path']) &&
        $removeEvidence &&
        ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        $errors[] = 'Supporting proof is required for an absence appeal.';
    }
    if ($errors === []) {
        try {
            $newEvidence = studentStoreEvidenceUpload($_FILES['evidence'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            $evidencePath = $newEvidence ?? ($removeEvidence ? null : $appeal['evidence_path']);
            if ($appeal['attendance_status'] === 'absent' && $evidencePath === null) {
                throw new RuntimeException('Supporting proof is required for an absence appeal.');
            }

            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                'SELECT appeal_status, evidence_path FROM attendance_appeals
                 WHERE appeal_id = ? AND student_id = ? FOR UPDATE'
            );
            $lock->execute([$appealId, $studentId]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$locked || $locked['appeal_status'] !== 'pending') {
                throw new RuntimeException('This appeal is no longer pending and cannot be edited.');
            }
            $update = $pdo->prepare(
                'UPDATE attendance_appeals
                 SET appeal_reason = ?, evidence_path = ?
                 WHERE appeal_id = ? AND student_id = ? AND appeal_status = ?'
            );
            $update->execute([$reason, $evidencePath, $appealId, $studentId, 'pending']);
            $pdo->commit();

            if ($locked['evidence_path'] && $locked['evidence_path'] !== $evidencePath) {
                $oldFile = studentEvidenceFilePath($locked['evidence_path']);
                if ($oldFile !== null) {
                    unlink($oldFile);
                }
            }
            header('Location: ' . BASE_URL . '/student/appeals/view.php?id=' . $appealId);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newEvidence !== null) {
                $newFile = studentEvidenceFilePath($newEvidence);
                if ($newFile !== null) {
                    unlink($newFile);
                }
            }
            throw $e;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newEvidence !== null) {
                $newFile = studentEvidenceFilePath($newEvidence);
                if ($newFile !== null) {
                    unlink($newFile);
                }
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newEvidence !== null) {
                $newFile = studentEvidenceFilePath($newEvidence);
                if ($newFile !== null) {
                    unlink($newFile);
                }
            }
            throw $e;
        }
    }
}

$studentPageTitle = 'Edit Attendance Appeal';
require_once __DIR__ . '/../../includes/student_layout_start.php';
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading"><div><h2>Edit Pending Appeal</h2><p><?= studentEscape($appeal['event_title']) ?></p></div><a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/index.php">Back to appeals</a></div>
    <?php if ($errors !== []): ?><div class="management-alert" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= studentEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="student-content-panel student-form" method="post" enctype="multipart/form-data" action="<?= studentEscape($studentBaseUrl) ?>/student/appeals/edit.php">
        <input type="hidden" name="csrf_token" value="<?= studentEscape(studentCsrfToken()) ?>">
        <input type="hidden" name="appeal_id" value="<?= (int) $appealId ?>">
        <label for="appeal_reason">Explanation</label>
        <textarea id="appeal_reason" name="appeal_reason" maxlength="4000" required rows="6"><?= studentEscape($reason) ?></textarea>
        <?php if ($appeal['evidence_path']): ?><p>Proof attached · <a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/evidence.php?id=<?= (int) $appealId ?>">View proof</a></p><label class="student-checkbox"><input type="checkbox" name="remove_evidence" value="1"> Remove current proof</label><?php endif; ?>
        <label for="evidence">Replace or add proof (PDF, JPG, or PNG; maximum 5 MB)</label>
        <input id="evidence" type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        <button class="management-button" type="submit">Save Appeal</button>
    </form>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>
</html>
