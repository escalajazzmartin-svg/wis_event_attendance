<?php

require_once __DIR__ . '/../config/session.php';

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/event_lifecycle.php';

$adminRole = strtolower(trim($_SESSION['role_name'] ?? $_SESSION['role'] ?? ''));
$adminRole = str_replace([' ', '-'], '_', $adminRole);
if (empty($_SESSION['user_id']) || $adminRole !== 'admin') {
    header('Location: ' . BASE_URL . '/auth/admin-login.php');
    exit;
}

$adminAccountStmt = $pdo->prepare(
    "SELECT 1
     FROM users u
     INNER JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = ? AND u.account_status = 'active' AND r.role_name = 'admin'"
);
$adminAccountStmt->execute([(int) $_SESSION['user_id']]);
if ($adminAccountStmt->fetchColumn() === false) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/admin-login.php');
    exit;
}

synchronizeEventLifecycle($pdo);

if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}

function adminEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function adminVerifyCsrf(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['admin_csrf_token'])
        && hash_equals($_SESSION['admin_csrf_token'], $token);
}

function adminLogActivity(
    PDO $pdo,
    string $action,
    string $entity,
    ?int $entityId = null,
    ?string $details = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO admin_activity_logs
            (actor_user_id, action_type, entity_type, entity_id, details)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int) $_SESSION['user_id'],
        $action,
        $entity,
        $entityId,
        $details,
    ]);
}

function adminRedirect(string $path, array $query = []): void
{
    $url = BASE_URL . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Where a POST handler should send the admin afterwards. Only whitelisted
 * list pages are accepted so the "return" field cannot be used as an open redirect.
 */
function adminReturnPath(string $default): string
{
    $targets = [
        'students' => '/admin/students/index.php',
        'department_heads' => '/admin/department-heads/index.php',
        'departments' => '/admin/departments/index.php',
    ];
    $requested = $_POST['return'] ?? null;

    return is_string($requested) && isset($targets[$requested]) ? $targets[$requested] : $default;
}

/**
 * Initials for list avatars: first letter of the first and last word.
 */
function adminInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($parts === []) {
        return '?';
    }
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

    return mb_strtoupper($first . $last);
}

/**
 * Current page number from ?page= and the SQL offset for it.
 *
 * @return array{0:int,1:int} [page, offset]
 */
function adminPage(int $perPage): array
{
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $page = $page !== false && $page > 0 ? $page : 1;

    return [$page, ($page - 1) * $perPage];
}

/**
 * Pull a too-high ?page= back to the last page that has rows.
 *
 * @return array{0:int,1:int} [page, offset]
 */
function adminClampPage(int $page, int $perPage, int $total): array
{
    $lastPage = max(1, (int) ceil($total / $perPage));
    $page = min($page, $lastPage);

    return [$page, ($page - 1) * $perPage];
}

/**
 * "Showing 1-6 of 1,248 students" plus Previous / Next links that keep the
 * current filters. Echoes the footer markup shared by every admin list.
 */
function adminListFooter(int $page, int $perPage, int $total, int $shown, string $noun): void
{
    $first = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $last = $total === 0 ? 0 : $first + $shown - 1;
    $query = $_GET;
    unset($query['page'], $query['notice']);
    $link = static function (int $target) use ($query): string {
        return '?' . http_build_query($query + ['page' => $target]);
    };
    $hasPrev = $page > 1;
    $hasNext = $last < $total;
    echo '<div class="ad-foot"><span>';
    if ($total > 0 && $first === 1 && $last === $total) {
        echo 'Showing ' . $total . ' of ' . number_format($total) . ' ' . $noun;
    } else {
        echo 'Showing ' . number_format($first) . '–' . number_format($last) . ' of ' . number_format($total) . ' ' . $noun;
    }
    echo '</span><nav class="ad-pager" aria-label="Pagination">';
    echo $hasPrev ? '<a href="' . adminEscape($link($page - 1)) . '">‹ Previous</a>' : '<span aria-disabled="true">‹ Previous</span>';
    echo $hasNext ? '<a href="' . adminEscape($link($page + 1)) . '">Next ›</a>' : '<span aria-disabled="true">Next ›</span>';
    echo '</nav></div>';
}

/**
 * Stream rows as a CSV download (UTF-8 with BOM so Excel opens it correctly).
 * Cells starting with = + - @ are prefixed to prevent spreadsheet formula injection.
 */
function adminStreamCsv(string $filename, array $headers, iterable $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static function ($cell): string {
            $cell = (string) $cell;

            return $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false ? "'" . $cell : $cell;
        }, $row));
    }
    fclose($out);
    exit;
}

/**
 * Outline (24x24, stroke) icons used by the admin sidebar and dashboard.
 */
function adminIcon(string $name, string $class = 'admin-icon'): string
{
    static $icons = [
        'dashboard' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'building' => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'check' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'file-warning' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M12 12v4"/><path d="M12 19h.01"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'cap' => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'calendar-check' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    ];

    return '<svg class="' . adminEscape($class) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" '
        . 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
        . 'stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($icons[$name] ?? '') . '</svg>';
}
