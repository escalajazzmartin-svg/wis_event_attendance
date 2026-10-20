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
$status = $_POST['status'] ?? '';
if (!$userId || $userId < 1 || !in_array($status, ['active', 'inactive'], true)) {
    http_response_code(400);
    exit('Invalid account status request.');
}
if ($userId === (int) $_SESSION['user_id']) {
    http_response_code(409);
    exit('You cannot deactivate your own administrator account.');
}
$stmt = $pdo->prepare("UPDATE users SET account_status = ? WHERE user_id = ? AND role IN ('student', 'department_head')");
$stmt->execute([$status, $userId]);
if ($stmt->rowCount() === 0) {
    $check = $pdo->prepare("SELECT account_status FROM users WHERE user_id = ? AND role IN ('student', 'department_head')");
    $check->execute([$userId]);
    if ($check->fetchColumn() === false) {
        http_response_code(404);
        exit('User account not found.');
    }
}
adminLogActivity($pdo, $status === 'active' ? 'activated' : 'deactivated', 'user', $userId);
adminRedirect('/admin/students/index.php', ['notice' => 'status_updated']);
