<?php

/**
 * Department head sidebar shared by every department head page.
 * Set $shellNavActive to a nav key to force the highlighted item; otherwise
 * it is worked out from the URL.
 */
require_once __DIR__ . '/../config/app.php';

$sidebarBaseUrl = BASE_URL;
$sidebarEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

if (!function_exists('dhIcon')) {
    function dhIcon(string $name, string $class = 'admin-icon'): string
    {
        static $icons = [
            'dashboard' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'building' => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M12 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M12 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/>',
            'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
            'check' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
            'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        ];

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" '
            . 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
            . 'stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . ($icons[$name] ?? '') . '</svg>';
    }
}

$shellNav = [
    ['dashboard', 'Dashboard', 'dashboard', '/department-head/dashboard.php'],
    ['students', 'Students', 'users', '/department-head/students/index.php'],
    ['department', 'Department', 'building', '/department-head/department/index.php'],
    ['events', 'Events', 'calendar', '/department-head/events/index.php'],
    ['attendance', 'Attendance', 'check', '/department-head/attendance/index.php'],
    ['time-rules', 'Time Rules', 'clock', '/department-head/attendance-settings/index.php'],
    ['appeals', 'Appeals', 'message', '/department-head/appeals/index.php'],
    ['reports', 'Reports', 'file', '/department-head/reports/index.php'],
];


// Works on pages with the shared context ($departmentHead) and on the
// stand-alone dashboard ($headName / session name).
$shellHeadName = isset($departmentHead['first_name'])
    ? trim($departmentHead['first_name'] . ' ' . $departmentHead['last_name'])
    : (string) ($headName ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? 'Department Head');

if (empty($shellNavActive)) {
    $shellNavActive = '';
    $shellPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    foreach ($shellNav as [$sbKey, , , $sbPath]) {
        $matchesPage = str_ends_with($shellPath, $sbPath);
        $matchesFolder = basename($sbPath) === 'index.php'
            && str_contains($shellPath, dirname($sbPath) . '/');
        if ($matchesPage || $matchesFolder) {
            $shellNavActive = $sbKey;
            break;
        }
    }
}
?>
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <img src="<?= $sidebarEscape($sidebarBaseUrl) ?>/assets/images/wisnoname.png" alt="Westmead International School logo">
            <div>
                <strong>WEAMMS</strong>
                <span>Department Head</span>
            </div>
        </div>

        <nav class="admin-nav" aria-label="Main navigation">
            <?php foreach ($shellNav as [$sbKey, $sbLabel, $sbIcon, $sbPath]): ?>
                <?php $sbActive = $sbKey === $shellNavActive; ?>
                <a href="<?= $sidebarEscape($sidebarBaseUrl . $sbPath) ?>" <?= $sbActive ? 'class="active" aria-current="page"' : '' ?>>
                    <?= dhIcon($sbIcon) ?>
                    <span><?= $sidebarEscape($sbLabel) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="admin-sidebar-footer">
            <div class="admin-sidebar-user">
                <strong><?= $sidebarEscape($shellHeadName) ?></strong>
                <span>Department Head</span>
            </div>
            <a class="admin-logout" href="<?= $sidebarEscape($sidebarBaseUrl) ?>/auth/logout.php">
                <svg class="admin-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>
