<?php

require_once __DIR__ . '/admin_context.php';

$adminBaseUrl = BASE_URL;
$adminPageTitle = $adminPageTitle ?? 'Administration';
$adminUsername = $_SESSION['username'] ?? 'Administrator';
$adminCsrfToken = $_SESSION['admin_csrf_token'];

// [label, icon, path, unused role filter]
$adminNav = [
    ['Dashboard', 'dashboard', '/admin/dashboard.php', null],
    ['Students', 'users', '/admin/students/index.php', 'student'],
    ['Departments', 'building', '/admin/departments/index.php', null],
    ['Department Heads', 'user', '/admin/department-heads/index.php', 'department_head'],
    ['Events', 'calendar', '/admin/events/index.php', null],
    ['Attendance', 'check', '/admin/attendance/index.php', null],
    ['Appeals', 'file-warning', '/admin/appeals/index.php', null],
    ['Reports', 'file', '/admin/reports/index.php', null],
    ['Security', 'shield', '/admin/security/index.php', null],
];

$adminCurrentPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/', '/') ?: '/';
$adminBasePath = rtrim((string) (parse_url($adminBaseUrl, PHP_URL_PATH) ?? ''), '/');
if ($adminBasePath !== '' && str_starts_with($adminCurrentPath, $adminBasePath)) {
    $adminCurrentPath = substr($adminCurrentPath, strlen($adminBasePath)) ?: '/';
}
$adminRequestedRole = ($_GET['role'] ?? '') === 'department_head' ? 'department_head' : 'student';
// Create/edit forms live under /admin/students/ for both account types, so look at
// which kind of account is being edited to keep the matching menu item highlighted.
if (str_starts_with($adminCurrentPath, '/admin/students/') && basename($adminCurrentPath) === 'edit.php') {
    $adminEditedUserId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($adminEditedUserId) {
        $adminEditedRole = $pdo->prepare('SELECT r.role_name FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?');
        $adminEditedRole->execute([$adminEditedUserId]);
        $adminRequestedRole = $adminEditedRole->fetchColumn() === 'department_head' ? 'department_head' : 'student';
    }
}

$adminIsActive = static function (string $path, ?string $role) use ($adminCurrentPath, $adminRequestedRole): bool {
    $path = (string) parse_url($path, PHP_URL_PATH);
    if ($path === '/admin/dashboard.php') {
        return $adminCurrentPath === $path;
    }
    if ($role === 'department_head' && str_starts_with($adminCurrentPath, '/admin/students/')) {
        return $adminRequestedRole === 'department_head';
    }
    if (!str_starts_with($adminCurrentPath, dirname($path) . '/')) {
        return false;
    }
    if ($role === 'student') {
        return $adminRequestedRole !== 'department_head';
    }

    return true;
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= adminEscape($adminPageTitle) ?> | WEAMMS</title>
    <link rel="icon" type="image/png" href="<?= adminEscape($adminBaseUrl) ?>/assets/images/wisnoname.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&amp;family=Roboto:wght@400;500;700&amp;display=swap">
    <link rel="stylesheet" href="<?= adminEscape($adminBaseUrl) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= adminEscape($adminBaseUrl) ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?= adminEscape($adminBaseUrl) ?>/assets/css/ui-refresh.css">
    <?php if (!empty($adminRefreshCss)): ?>
        <link rel="stylesheet" href="<?= adminEscape($adminBaseUrl) ?>/assets/css/admin-refresh.css">
    <?php endif; ?>
    <?php if (!empty($adminPageCss)): ?>
        <link rel="stylesheet" href="<?= adminEscape($adminBaseUrl) ?>/assets/css/<?= adminEscape($adminPageCss) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body class="admin-dashboard-page">
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <img src="<?= adminEscape($adminBaseUrl) ?>/assets/images/wisnoname.png" alt="Westmead International School logo">
            <div>
                <strong>WEAMMS</strong>
                <span>Administrator</span>
            </div>
        </div>

        <nav class="admin-nav" aria-label="Main navigation">
            <?php foreach ($adminNav as [$label, $icon, $path, $role]): ?>
                <?php $active = $adminIsActive($path, $role); ?>
                <a href="<?= adminEscape($adminBaseUrl . $path) ?>"
                    <?= $active ? 'class="active" aria-current="page"' : '' ?>>
                    <?= adminIcon($icon) ?>
                    <span><?= adminEscape($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="admin-sidebar-footer">
            <div class="admin-sidebar-user">
                <strong><?= adminEscape($adminUsername) ?></strong>
                <span>Administrator</span>
            </div>
            <a class="admin-logout" href="<?= adminEscape($adminBaseUrl) ?>/auth/logout.php">
                <svg class="admin-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>
    <main class="admin-main">
