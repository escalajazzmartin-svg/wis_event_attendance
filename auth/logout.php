
<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/app.php';

$role = strtolower(trim($_SESSION['role_name'] ?? $_SESSION['role'] ?? ''));
$role = str_replace([' ', '-'], '_', $role);
// Clear all session data.
$_SESSION = [];

// Remove the session cookie.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

// Destroy the session.
session_destroy();

$loginPage = $role === 'admin' ? '/auth/admin-login.php' : '/auth/student-login.php';
header('Location: ' . BASE_URL . $loginPage);
exit;
