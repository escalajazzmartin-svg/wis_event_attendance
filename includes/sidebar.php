<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$baseUrl = '/wis_event_attendance';

// Use the role key established by your login system.
$role = strtolower($_SESSION['role_name'] ?? $_SESSION['role'] ?? '');
$role = str_replace([' ', '-'], '_', $role);

$menus = [
    'admin' => [
        ['Dashboard', 'fa-house', '/admin/dashboard.php'],
        ['User Management', 'fa-users', '/admin/students/index.php'],
        ['Department Management', 'fa-building', '/admin/department-heads/index.php'],
        ['Event Management', 'fa-calendar-days', '/admin/events/index.php'],
        ['Attendance Monitoring', 'fa-clipboard-check', '/admin/reports/index.php'],
        ['Reports and Analytics', 'fa-chart-column', '/admin/reports/index.php'],
    ],

    'department_head' => [
        ['Dashboard', 'fa-house', '/department-head/dashboard.php'],
        ['Department Management', 'fa-building', '/department-head/dashboard.php'],
        ['Event Management', 'fa-calendar-days', '/department-head/events/index.php'],
        ['Attendance Monitoring', 'fa-clipboard-check', '/department-head/attendance/index.php'],
        ['Reports and Analytics', 'fa-chart-column', '/department-head/reports/index.php'],
    ],

    'student' => [
        ['Dashboard', 'fa-house', '/student/dashboard.php'],
        ['Event Schedules', 'fa-calendar-days', '/student/events/index.php'],
        ['My Attendance', 'fa-clipboard-check', '/student/attendance/index.php'],
        ['Reports and Analytics', 'fa-chart-column', '/student/attendance/history.php'],
        ['Attendance Appeals', 'fa-file-circle-exclamation', '/student/appeals/index.php'],
        ['My Profile', 'fa-user', '/student/profile/index.php'],
    ],
];

if (!isset($menus[$role])) {
    http_response_code(403);
    exit('Access denied. Please log in with an authorized account.');
}

$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
$currentPath = rtrim($currentPath, '/') ?: '/';

$logoutUrl = $baseUrl . '/auth/logout.php';
?>

<aside class="student-sidebar">
    <div class="sidebar-brand">
        <img
            src="<?= $baseUrl ?>/assets/images/wisnoname.png"
            alt="WIS Logo">

        <div>
            <h2>WEAMMS</h2>
            <span><?= htmlspecialchars(ucwords(str_replace('_', ' ', $role))) ?> Account</span>
        </div>
    </div>

    <nav class="student-navigation" aria-label="Main navigation">
        <?php foreach ($menus[$role] as [$label, $icon, $path]): ?>
            <?php
            $url = $baseUrl . $path;
            $isActive = $currentPath === $path;
            ?>

            <a
                href="<?= htmlspecialchars($url) ?>"
                class="<?= $isActive ? 'active' : '' ?>"
                <?= $isActive ? 'aria-current="page"' : '' ?>>

                <i class="fa-solid <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-bottom">
        <a href="<?= htmlspecialchars($logoutUrl) ?>" class="logout-link">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>