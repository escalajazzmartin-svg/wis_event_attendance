<?php

require_once __DIR__ . '/../../includes/student_context.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$attendanceId = filter_var(
    $method === 'POST'
        ? ($_POST['attendance_id'] ?? null)
        : ($_GET['attendance_id'] ?? null),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($attendanceId === false || $attendanceId === null) {
    http_response_code(400);
    exit('Select a valid attendance record to appeal.');
}

$recordStmt = $pdo->prepare(
    'SELECT ea.attendance_id, ea.attendance_status, ea.student_id,
            e.event_title, e.event_id
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE ea.attendance_id = ?
       AND ea.student_id = ?
     LIMIT 1'
);

$recordStmt->execute([
    $attendanceId,
    $studentId
]);

$record = $recordStmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    exit('Attendance record not found in your account.');
}

// Only disputed statuses can be appealed.
if (!in_array($record['attendance_status'], ['absent', 'late'], true)) {
    http_response_code(409);
    exit('Only absent or late attendance records can be appealed.');
}

// Redirect if a pending appeal already exists.
$pendingStmt = $pdo->prepare(
    "SELECT appeal_id
     FROM attendance_appeals
     WHERE attendance_id = ?
       AND student_id = ?
       AND appeal_status = 'pending'
     LIMIT 1"
);

$pendingStmt->execute([$attendanceId, $studentId]);

if ($pendingStmt->fetchColumn() !== false) {
    header('Location: ' . BASE_URL . '/student/appeals/index.php');
    exit;
}

$reason = '';
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

    $uploadError = $_FILES['evidence']['error']
        ?? UPLOAD_ERR_NO_FILE;

    if (
        $uploadError !== UPLOAD_ERR_NO_FILE &&
        $uploadError !== UPLOAD_ERR_OK
    ) {
        $errors[] = 'The proof upload failed. Please select the file again.';
    }

    if (
        $record['attendance_status'] === 'absent' &&
        $uploadError === UPLOAD_ERR_NO_FILE
    ) {
        $errors[] = 'Attach supporting proof for an absence appeal.';
    }

    $evidencePath = null;

    if ($errors === []) {
        try {
            $evidencePath = studentStoreEvidenceUpload(
                $_FILES['evidence'] ?? [
                    'error' => UPLOAD_ERR_NO_FILE
                ]
            );

            if (
                $record['attendance_status'] === 'absent' &&
                $evidencePath === null
            ) {
                throw new RuntimeException(
                    'Supporting proof is required for an absence appeal.'
                );
            }

            $pdo->beginTransaction();

            // Lock the student's attendance record.
            $lock = $pdo->prepare(
                'SELECT ea.attendance_id
                 FROM event_attendance ea
                 INNER JOIN events e ON e.event_id = ea.event_id
                 WHERE ea.attendance_id = ?
                   AND ea.student_id = ?
                 FOR UPDATE'
            );

            $lock->execute([
                $attendanceId,
                $studentId
            ]);

            if ($lock->fetchColumn() === false) {
                throw new RuntimeException(
                    'This attendance record is no longer available.'
                );
            }

            // Recheck pending appeals inside the transaction.
            $active = $pdo->prepare(
                "SELECT appeal_id
                 FROM attendance_appeals
                 WHERE attendance_id = ?
                   AND student_id = ?
                   AND appeal_status = 'pending'
                 LIMIT 1
                 FOR UPDATE"
            );

            $active->execute([$attendanceId, $studentId]);

            if ($active->fetchColumn() !== false) {
                throw new RuntimeException(
                    'A pending appeal already exists for this attendance record.'
                );
            }

            $insert = $pdo->prepare(
                'INSERT INTO attendance_appeals
                    (attendance_id, student_id, appeal_reason,
                     evidence_path, appeal_status)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $insert->execute([
                $attendanceId,
                $studentId,
                $reason,
                $evidencePath,
                'pending'
            ]);

            $pdo->commit();

            header(
                'Location: ' . BASE_URL .
                    '/student/appeals/index.php'
            );
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($evidencePath !== null) {
                $stored = studentEvidenceFilePath($evidencePath);

                if ($stored !== null && is_file($stored)) {
                    unlink($stored);
                }
            }

            error_log('Appeal submission failed: ' . $e->getMessage());

            http_response_code(500);
            exit('Unable to submit your appeal right now. Please try again later.');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($evidencePath !== null) {
                $stored = studentEvidenceFilePath($evidencePath);

                if ($stored !== null && is_file($stored)) {
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

                if ($stored !== null && is_file($stored)) {
                    unlink($stored);
                }
            }

            throw $e;
        }
    }
}

$studentPageTitle = 'Submit Attendance Appeal';
$studentPageCss = 'student-appeals.css';

require_once __DIR__ . '/../../includes/student_layout_start.php';

$isAbsent = $record['attendance_status'] === 'absent';
?>

<section class="dashboard-section student-module-page">

    <div class="section-heading">
        <div>
            <h2>Submit Attendance Appeal</h2>
            <p>
                <?= studentEscape($record['event_title']) ?> ·
                Recorded as
                <span class="status-pill status-<?= studentEscape($record['attendance_status']) ?>">
                    <?= studentEscape(ucfirst($record['attendance_status'])) ?>
                </span>
            </p>
        </div>

        <a class="student-appeal-link"
            href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/index.php">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            Back to attendance
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
            action="<?= studentEscape($studentBaseUrl) ?>/student/appeals/create.php">

            <input type="hidden" name="csrf_token"
                value="<?= studentEscape(studentCsrfToken()) ?>">
            <input type="hidden" name="attendance_id"
                value="<?= (int) $attendanceId ?>">

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
                    Supporting proof
                    <?= $isAbsent ? '(required)' : '(optional)' ?>
                </label>
                <input
                    id="evidence"
                    type="file"
                    name="evidence"
                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                    <?= $isAbsent ? 'required' : '' ?>>
                <small>PDF, JPG, or PNG. Maximum 5 MB.</small>
            </div>

            <div class="student-appeal-form-actions">
                <button class="student-appeal-button" type="submit">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                    Submit appeal
                </button>
                <a class="student-appeal-button student-appeal-button-secondary"
                    href="<?= studentEscape($studentBaseUrl) ?>/student/attendance/index.php">
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
