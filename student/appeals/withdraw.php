<?php

require_once __DIR__ . '/../../includes/student_context.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Validate CSRF token.
if (!studentVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request token. Refresh your appeals list and try again.');
}

// Validate appeal ID.
$appealId = filter_var(
    $_POST['appeal_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($appealId === false || $appealId === null) {
    http_response_code(400);
    exit('A valid appeal was not specified.');
}

try {
    $update = $pdo->prepare(
        "UPDATE attendance_appeals AS aa
         INNER JOIN event_attendance AS ea
             ON ea.attendance_id = aa.attendance_id
         INNER JOIN events AS e
             ON e.event_id = ea.event_id
         SET aa.appeal_status = 'withdrawn'
         WHERE aa.appeal_id = ?
           AND aa.student_id = ?
           AND ea.student_id = ?
           AND aa.appeal_status = 'pending'"
    );

    $update->execute([
        $appealId,
        $studentId,
        $studentId
    ]);

    if ($update->rowCount() !== 1) {
        http_response_code(409);
        exit('This appeal is no longer pending or does not belong to your account.');
    }

    header('Location: ' . BASE_URL . '/student/appeals/index.php');
    exit;
} catch (PDOException $e) {
    error_log('Appeal withdrawal failed: ' . $e->getMessage());

    http_response_code(500);
    exit('Unable to withdraw your appeal right now. Please try again later.');
}
