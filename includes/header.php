<?php

require_once __DIR__ . '/../config/session.php';

$loggedInUser = $_SESSION['user'] ?? [];

$username = $loggedInUser['username']
    ?? $_SESSION['username']
    ?? 'User';

$role = strtolower(trim(
    $loggedInUser['role_name']
        ?? $loggedInUser['role']
        ?? $_SESSION['role_name']
        ?? $_SESSION['role']
        ?? ''
));

$userRole = match ($role) {
    'admin' => 'Admin',
    'department_head' => 'Department Head',
    'student' => 'Student',
    default => 'User'
};

$initial = strtoupper(substr(trim($username), 0, 1));

if ($initial === '') {
    $initial = 'U';
}
?>

<header class="dashboard-topbar">
    <div>
        <h1>WESTMEAD INTERNATIONAL SCHOOL</h1>
    </div>

    <div class="student-account">
        <div class="student-avatar">
            <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
        </div>

        <div class="student-account-info">
            <strong><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></strong>
            <span><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>
</header>