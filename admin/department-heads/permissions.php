<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

if (
    empty($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role_name'] ?? $_SESSION['role'] ?? '')) !== 'admin'
) {
    header('Location: ' . BASE_URL . '/auth/admin-login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$token = $_POST['csrf_token'] ?? null;
if (
    !is_string($token) ||
    empty($_SESSION['admin_csrf_token']) ||
    !hash_equals($_SESSION['admin_csrf_token'], $token)
) {
    http_response_code(400);
    exit('Invalid request token. Refresh the department list and try again.');
}

$departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
$permission = $_POST['permission'] ?? '';
$enabledValue = $_POST['enabled'] ?? null;

$permissionColumns = [
    'department' => 'department_head_can_edit',
    'attendance' => 'department_head_can_edit_attendance',
];

if (
    $departmentId === false ||
    $departmentId < 1 ||
    !is_string($permission) ||
    !isset($permissionColumns[$permission]) ||
    !in_array($enabledValue, ['0', '1'], true)
) {
    http_response_code(400);
    exit('Invalid department permission request.');
}

$column = $permissionColumns[$permission];
$stmt = $pdo->prepare(
    "UPDATE departments SET $column = ? WHERE department_id = ?"
);
$stmt->execute([(int) $enabledValue, $departmentId]);

if ($stmt->rowCount() === 0) {
    $check = $pdo->prepare('SELECT department_id FROM departments WHERE department_id = ?');
    $check->execute([$departmentId]);
    if ($check->fetchColumn() === false) {
        http_response_code(404);
        exit('Department not found.');
    }
}

header('Location: ' . BASE_URL . '/admin/departments/index.php?notice=permission_updated');
exit;
