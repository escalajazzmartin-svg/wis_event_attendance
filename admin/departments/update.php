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
$id = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
$code = trim(is_string($_POST['department_code'] ?? null) ? $_POST['department_code'] : '');
$name = trim(is_string($_POST['department_name'] ?? null) ? $_POST['department_name'] : '');
$description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
if (!$id || $id < 1 || $code === '' || strlen($code) > 30 || $name === '' || strlen($name) > 150) {
    http_response_code(400);
    exit('Enter valid department details.');
}
$stmt = $pdo->prepare('UPDATE departments SET department_code = ?, department_name = ?, description = ? WHERE department_id = ?');
try {
    $stmt->execute([$code, $name, $description !== '' ? $description : null, $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(409);
        exit('That department code or name is already in use.');
    }
    throw $e;
}
if ($stmt->rowCount() === 0) {
    $check = $pdo->prepare('SELECT department_id FROM departments WHERE department_id = ?');
    $check->execute([$id]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        exit('Department not found.');
    }
}
adminLogActivity($pdo, 'updated', 'department', $id, $name);
adminRedirect('/admin/departments/index.php', ['notice' => 'department_updated']);
