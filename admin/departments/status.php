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
$active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1 || !in_array($active, [0, 1], true)) {
    http_response_code(400);
    exit('Invalid department status request.');
}
$stmt = $pdo->prepare('UPDATE departments SET is_active = ? WHERE department_id = ?');
$stmt->execute([$active, $id]);
if ($stmt->rowCount() === 0) {
    $check = $pdo->prepare('SELECT department_id FROM departments WHERE department_id = ?');
    $check->execute([$id]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        exit('Department not found.');
    }
}
adminLogActivity($pdo, $active ? 'restored' : 'archived', 'department', $id);
adminRedirect('/admin/departments/index.php', ['notice' => $active ? 'department_restored' : 'department_archived']);
