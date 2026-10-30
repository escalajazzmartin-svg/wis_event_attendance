
<?php

require_once __DIR__ . '/../config/session.php';

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

$loginPage = match ($role) {
    'admin' => '/auth/admin-login.php',
    'department_head' => '/auth/department-head-login.php',
    default => '/auth/student-login.php',
};
header('Location: ' . BASE_URL . $loginPage);
exit;
