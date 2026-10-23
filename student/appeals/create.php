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
       AND e.department_id = ?
     LIMIT 1'
);

$recordStmt->execute([
    $attendanceId,
    $studentId,
    $studentDepartmentId
]);

$record = $recordStmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    exit('Attendance record not found in your account.');
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
                   AND e.department_id = ?
                 FOR UPDATE'
            );

            $lock->execute([
                $attendanceId,
                $studentId,
                $studentDepartmentId
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
