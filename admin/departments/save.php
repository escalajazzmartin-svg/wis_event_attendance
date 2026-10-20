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
$code = trim(is_string($_POST['department_code'] ?? null) ? $_POST['department_code'] : '');
$name = trim(is_string($_POST['department_name'] ?? null) ? $_POST['department_name'] : '');
$description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
if ($code === '' || strlen($code) > 30 || $name === '' || strlen($name) > 150) {
    http_response_code(400);
    exit('Enter a valid department code and name.');
}
$stmt = $pdo->prepare('INSERT INTO departments (department_code, department_name, description, is_active) VALUES (?, ?, ?, 1)');
try {
    $stmt->execute([$code, $name, $description !== '' ? $description : null]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(409);
        exit('That department code or name is already in use.');
    }
    throw $e;
}
$departmentId = (int) $pdo->lastInsertId();
adminLogActivity($pdo, 'created', 'department', $departmentId, $name);
adminRedirect('/admin/departments/index.php', ['notice' => 'department_created']);
