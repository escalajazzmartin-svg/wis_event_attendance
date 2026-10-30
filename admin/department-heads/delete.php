<?php

require_once __DIR__ . '/../../includes/admin_context.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the Department Head account deactivation action; records are retained for history.');
}
if (!adminVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request token.');
}
$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$userId || $userId < 1 || $userId === (int) $_SESSION['user_id']) {
    http_response_code(400);
    exit('A valid Department Head account is required.');
}
$stmt = $pdo->prepare(
    "UPDATE users u
     INNER JOIN roles r ON r.role_id = u.role_id
     SET u.account_status = 'inactive'
     WHERE u.user_id = ? AND r.role_name = 'department_head'"
);
$stmt->execute([$userId]);
if ($stmt->rowCount() === 0) {
    $check = $pdo->prepare(
        "SELECT u.user_id FROM users u
         INNER JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = ? AND r.role_name = 'department_head'"
    );
    $check->execute([$userId]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        exit('Department Head account not found.');
    }
}
adminLogActivity($pdo, 'deactivated', 'user', $userId, 'Department Head');
adminRedirect('/admin/students/index.php', ['notice' => 'status_updated']);
