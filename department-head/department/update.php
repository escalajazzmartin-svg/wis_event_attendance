<?php

require_once __DIR__ . '/../../includes/department_head_context.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

if (!dhVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request token. Refresh the department page and try again.');
}

if ((int) $departmentHead['department_head_can_edit'] !== 1) {
    http_response_code(403);
    exit('Your administrator has not authorized department detail changes.');
}

$name = trim(is_string($_POST['department_name'] ?? null) ? $_POST['department_name'] : '');
$description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');

if ($name === '' || dhCharacterLength($name) > 150 || dhCharacterLength($description) > 2000) {
    http_response_code(400);
    exit('Enter a department name of 1–150 characters and a description of no more than 2,000 characters.');
}

$stmt = $pdo->prepare(
    'UPDATE departments
     SET department_name = ?, description = ?
     WHERE department_id = ?'
);
$stmt->execute([
    $name,
    $description !== '' ? $description : null,
    $departmentId,
]);

header('Location: ' . $baseUrl . '/department-head/department/index.php?notice=updated');
exit;
