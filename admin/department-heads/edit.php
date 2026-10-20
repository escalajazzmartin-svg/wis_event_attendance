<?php

require_once __DIR__ . '/../../includes/admin_context.php';
$userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$userId || $userId < 1) {
    http_response_code(400);
    exit('A valid account is required.');
}
adminRedirect('/admin/students/edit.php', ['id' => $userId]);
