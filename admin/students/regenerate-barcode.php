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
    exit('A valid student account is required.');
}
$token = bin2hex(random_bytes(32));
$stmt = $pdo->prepare('UPDATE students SET barcode_token = ? WHERE user_id = ?');
$stmt->execute([$token, $userId]);
if ($stmt->rowCount() === 0) {
    http_response_code(404);
    exit('Student account not found.');
}
adminLogActivity($pdo, 'regenerated barcode for', 'student', $userId);
adminRedirect('/admin/students/index.php', ['notice' => 'barcode_regenerated']);
