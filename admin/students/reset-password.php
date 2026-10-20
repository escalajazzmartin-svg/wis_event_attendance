<?php

require_once __DIR__ . '/../../includes/admin_context.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
if (!adminVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request token.');
}
$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$userId || $userId < 1) {
    http_response_code(400);
    exit('A valid account is required.');
}
$temporaryPassword = bin2hex(random_bytes(12));
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ? AND role IN ('student', 'department_head')");
$stmt->execute([password_hash($temporaryPassword, PASSWORD_DEFAULT), $userId]);
if ($stmt->rowCount() === 0) {
    http_response_code(404);
    exit('User account not found.');
}
adminLogActivity($pdo, 'reset password for', 'user', $userId);
$_SESSION['admin_temporary_password'] = $temporaryPassword;
adminRedirect('/admin/students/index.php', ['notice' => 'password_reset']);
