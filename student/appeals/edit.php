<?php

require_once __DIR__ . '/../../includes/student_context.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$appealId = filter_var(
    $method === 'POST'
        ? ($_POST['appeal_id'] ?? null)
        : ($_GET['id'] ?? null),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($appealId === false || $appealId === null) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}

$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.attendance_id, aa.appeal_reason,
            aa.evidence_path, aa.appeal_status, ea.attendance_status,
            e.event_title
     FROM attendance_appeals aa
     INNER JOIN event_attendance ea
         ON ea.attendance_id = aa.attendance_id
     INNER JOIN events e
         ON e.event_id = ea.event_id
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

if ($appeal['appeal_status'] !== 'pending') {
    http_response_code(409);
    exit('Only pending appeals can be edited.');
}

$reason = $appeal['appeal_reason'];
$errors = [];

if ($method === 'POST') {
    $reason = trim(
        is_string($_POST['appeal_reason'] ?? null)
            ? $_POST['appeal_reason']
            : ''
    );

    if (!studentVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token expired. Refresh the page and try again.';
    }

    if (
        $reason === '' ||
        studentCharacterLength($reason) > 4000
    ) {
        $errors[] = 'Explain the attendance issue in 1–4,000 characters.';
    }

    $removeEvidence = (
        $_POST['remove_evidence'] ?? ''
    ) === '1';

    $uploadError = $_FILES['evidence']['error']
        ?? UPLOAD_ERR_NO_FILE;

    if (
        $uploadError !== UPLOAD_ERR_NO_FILE &&
        $uploadError !== UPLOAD_ERR_OK
    ) {
        $errors[] = 'The proof upload failed. Please try again.';
    }

    if (
        $appeal['attendance_status'] === 'absent' &&
        $removeEvidence &&
        $uploadError === UPLOAD_ERR_NO_FILE
    ) {
        $errors[] = 'Supporting proof is required for an absence appeal.';
    }

    if ($errors === []) {
        $newEvidence = null;

        try {
            $newEvidence = studentStoreEvidenceUpload(
                $_FILES['evidence'] ?? [
                    'error' => UPLOAD_ERR_NO_FILE
                ]
            );

            $evidencePath = $newEvidence
                ?? (
                    $removeEvidence
                    ? null
                    : $appeal['evidence_path']
                );

            if (
                $appeal['attendance_status'] === 'absent' &&
                $evidencePath === null
            ) {
                throw new RuntimeException(
                    'Supporting proof is required for an absence appeal.'
                );
            }

            $pdo->beginTransaction();

            // Lock the appeal and verify its current status.
            $lock = $pdo->prepare(
                'SELECT appeal_status, evidence_path
                 FROM attendance_appeals
                 WHERE appeal_id = ?
                   AND student_id = ?
                 FOR UPDATE'
            );

            $lock->execute([$appealId, $studentId]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);

            if (
                !$locked ||
                $locked['appeal_status'] !== 'pending'
            ) {
                throw new RuntimeException(
                    'This appeal is no longer pending and cannot be edited.'
                );
            }

            $update = $pdo->prepare(
                'UPDATE attendance_appeals
                 SET appeal_reason = ?,
                     evidence_path = ?
                 WHERE appeal_id = ?
                   AND student_id = ?
                   AND appeal_status = ?'
            );

            $update->execute([
                $reason,
                $evidencePath,
                $appealId,
                $studentId,
                'pending'
            ]);

            $pdo->commit();

            // Delete the old file only after the database update succeeds.
            if (
                !empty($locked['evidence_path']) &&
                $locked['evidence_path'] !== $evidencePath
            ) {
                $oldFile = studentEvidenceFilePath(
                    $locked['evidence_path']
                );

                if ($oldFile !== null && is_file($oldFile)) {
                    if (!unlink($oldFile)) {
                        error_log(
                            'Could not delete replaced appeal evidence file.'
                        );
                    }
                }
            }

            header(
                'Location: ' . BASE_URL .
                    '/student/appeals/view.php?id=' . $appealId
            );
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($newEvidence !== null) {
                $newFile = studentEvidenceFilePath($newEvidence);

                if ($newFile !== null && is_file($newFile)) {
                    unlink($newFile);
                }
            }

            error_log('Appeal update failed: ' . $e->getMessage());

            http_response_code(500);
            exit('Unable to save your appeal right now. Please try again later.');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($newEvidence !== null) {
                $newFile = studentEvidenceFilePath($newEvidence);

                if ($newFile !== null && is_file($newFile)) {
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

                if ($newFile !== null && is_file($newFile)) {
                    unlink($newFile);
                }
            }

            throw $e;
        }
    }
}

$studentPageTitle = 'Edit Attendance Appeal';
$studentPageCss = 'student-appeals.css';

require_once __DIR__ . '/../../includes/student_layout_start.php';

$isAbsent = $appeal['attendance_status'] === 'absent';
$hasEvidence = !empty($appeal['evidence_path']);
?>

<section class="dashboard-section student-module-page">

    <div class="section-heading">
        <div>
            <h2>Edit Attendance Appeal</h2>
            <p>
                <?= studentEscape($appeal['event_title']) ?> ·
                Recorded as
                <span class="status-pill status-<?= studentEscape($appeal['attendance_status']) ?>">
                    <?= studentEscape(ucfirst($appeal['attendance_status'])) ?>
                </span>
            </p>
        </div>

        <a class="student-appeal-link"
            href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/index.php">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            Back to appeals
        </a>
    </div>

    <div class="student-content-panel">

        <?php if ($errors !== []): ?>
            <div class="dashboard-alert student-appeal-errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <div><?= studentEscape($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form
            class="student-appeal-form"
            method="post"
            enctype="multipart/form-data"
            action="<?= studentEscape($studentBaseUrl) ?>/student/appeals/edit.php">

            <input type="hidden" name="csrf_token"
                value="<?= studentEscape(studentCsrfToken()) ?>">
            <input type="hidden" name="appeal_id"
                value="<?= (int) $appealId ?>">

            <div class="student-appeal-field">
                <label for="appeal_reason">Explain what happened</label>
                <textarea
                    id="appeal_reason"
                    name="appeal_reason"
                    rows="6"
                    maxlength="4000"
                    required><?= studentEscape($reason) ?></textarea>
                <small>Up to 4,000 characters.</small>
            </div>

            <div class="student-appeal-field">
                <label for="evidence">
                    <?= $hasEvidence ? 'Replace supporting proof' : 'Supporting proof' ?>
                    <?= $isAbsent ? '(required)' : '(optional)' ?>
                </label>

                <?php if ($hasEvidence): ?>
                    <p class="student-appeal-current">
                        <i class="fa-solid fa-paperclip" aria-hidden="true"></i>
                        <a href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/evidence.php?id=<?= (int) $appealId ?>"
                            target="_blank" rel="noopener">View current proof</a>
                    </p>
                <?php endif; ?>

                <input
                    id="evidence"
                    type="file"
                    name="evidence"
                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                <small>PDF, JPG, or PNG. Maximum 5 MB.</small>

                <?php if ($hasEvidence && !$isAbsent): ?>
                    <label class="student-appeal-check">
                        <input type="checkbox" name="remove_evidence" value="1">
                        Remove the current proof
                    </label>
                <?php endif; ?>
            </div>

            <div class="student-appeal-form-actions">
                <button class="student-appeal-button" type="submit">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    Save changes
                </button>
                <a class="student-appeal-button student-appeal-button-secondary"
                    href="<?= studentEscape($studentBaseUrl) ?>/student/appeals/index.php">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>

</html>
