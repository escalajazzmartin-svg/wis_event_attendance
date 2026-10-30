<?php

$adminPageTitle = 'Security';
$adminRefreshCss = true;
require_once __DIR__ . '/../../includes/admin_layout_start.php';

$actionFilter = is_string($_GET['action'] ?? null) ? trim($_GET['action']) : '';
$entityFilter = is_string($_GET['entity'] ?? null) ? trim($_GET['entity']) : '';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page !== false && $page > 0 ? $page : 1;
$pageSize = 25;

$actionOptions = $pdo->query('SELECT DISTINCT action_type FROM admin_activity_logs ORDER BY action_type')->fetchAll(PDO::FETCH_COLUMN);
$entityOptions = $pdo->query('SELECT DISTINCT entity_type FROM admin_activity_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($actionFilter, $actionOptions, true)) {
    $actionFilter = '';
}
if (!in_array($entityFilter, $entityOptions, true)) {
    $entityFilter = '';
}

$conditions = [];
$params = [];
if ($actionFilter !== '') {
    $conditions[] = 'l.action_type = :action_type';
    $params['action_type'] = $actionFilter;
}
if ($entityFilter !== '') {
    $conditions[] = 'l.entity_type = :entity_type';
    $params['entity_type'] = $entityFilter;
}
$where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_activity_logs l $where");
$countStmt->execute($params);
$totalEntries = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalEntries / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$logStmt = $pdo->prepare(
    "SELECT l.created_at, l.action_type, l.entity_type, l.entity_id, l.details, u.username AS actor
     FROM admin_activity_logs l
     LEFT JOIN users u ON u.user_id = l.actor_user_id
     $where
     ORDER BY l.created_at DESC, l.activity_id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $name => $value) {
    $logStmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
}
$logStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$logStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$logStmt->execute();
$logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

$blockedSources = (int) $pdo->query('SELECT COUNT(*) FROM login_rate_limits WHERE blocked_until > NOW()')->fetchColumn();
$accountCounts = $pdo->query(
    "SELECT COALESCE(SUM(account_status = 'locked'), 0) AS locked,
            COALESCE(SUM(account_status = 'inactive'), 0) AS inactive
     FROM users"
)->fetch(PDO::FETCH_ASSOC);
$actions24h = (int) $pdo->query(
    'SELECT COUNT(*) FROM admin_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
)->fetchColumn();

$flaggedAccounts = $pdo->query(
    "SELECT u.user_id, u.username, u.account_status, u.last_login_at, r.role_name
     FROM users u
     INNER JOIN roles r ON r.role_id = u.role_id
     WHERE u.account_status IN ('locked', 'inactive')
     ORDER BY FIELD(u.account_status, 'locked', 'inactive'), u.username
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

$label = static fn (string $value): string => ucwords(str_replace('_', ' ', $value));
?>
<section class="admin-content-section admin-refresh admin-security-page">
    <div class="admin-page-heading"><div><span class="admin-eyebrow">Admin · Protection</span><h2>Security</h2><p>Sign-in protection, flagged accounts, and administrator activity.</p></div></div>

    <div class="admin-summary-grid">
        <?php foreach ([
            ['Blocked sign-in sources', $blockedSources, 'admin-icon-red', 'fa-ban', 'Rate-limited right now'],
            ['Locked accounts', (int) $accountCounts['locked'], 'admin-icon-red', 'fa-lock', 'Cannot sign in'],
            ['Inactive accounts', (int) $accountCounts['inactive'], 'admin-icon-gold', 'fa-user-slash', 'Disabled by an administrator'],
            ['Admin actions (24 h)', $actions24h, 'admin-icon-blue', 'fa-clipboard-list', 'Logged in the last 24 hours'],
        ] as [$cardLabel, $cardValue, $cardTone, $cardIcon, $cardNote]): ?>
            <article class="admin-summary-card"><span class="admin-summary-icon <?= adminEscape($cardTone) ?>"><i class="fa-solid <?= adminEscape($cardIcon) ?>" aria-hidden="true"></i></span><div><span><?= adminEscape($cardLabel) ?></span><strong><?= $cardValue ?></strong><small><?= adminEscape($cardNote) ?></small></div></article>
        <?php endforeach; ?>
    </div>

    <div class="admin-security-grid">
        <section class="admin-panel admin-security-accounts">
            <div class="admin-panel-heading"><div><h3>Locked and inactive accounts <span class="admin-count-pill"><?= count($flaggedAccounts) ?></span></h3><p>Change an account's status from its edit page.</p></div></div>
            <?php if ($flaggedAccounts === []): ?>
                <div class="admin-empty-state"><strong>No flagged accounts</strong><p>All accounts are active.</p></div>
            <?php else: ?>
                <div class="admin-table-wrap admin-security-scroll"><table class="admin-table"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Manage</th></tr></thead><tbody>
                    <?php foreach ($flaggedAccounts as $account): ?>
                        <tr>
                            <td data-label="User"><span class="admin-person"><span class="admin-person-avatar" aria-hidden="true"><?= adminEscape(strtoupper(mb_substr($account['username'], 0, 2))) ?></span><strong><?= adminEscape($account['username']) ?></strong></span></td>
                            <td data-label="Role"><?= adminEscape($label($account['role_name'])) ?></td>
                            <td data-label="Status"><span class="admin-status-badge admin-status-<?= adminEscape($account['account_status']) ?>"><?= adminEscape(ucfirst($account['account_status'])) ?></span></td>
                            <td data-label="Last sign-in"><?= $account['last_login_at'] ? adminEscape(date('M j, Y · g:i A', strtotime($account['last_login_at']))) : 'Never' ?></td>
                            <td data-label="Manage"><?php if ($account['role_name'] !== 'admin'): ?><a href="<?= adminEscape($adminBaseUrl) ?>/admin/students/edit.php?id=<?= (int) $account['user_id'] ?>">Open</a><?php else: ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

        <section class="admin-panel admin-security-rules">
            <div class="admin-panel-heading"><div><h3>Sign-in protection</h3><p>Read-only. These rules are set in code.</p></div></div>
            <ul class="admin-rule-list">
                <li><span class="admin-rule-icon"><i class="fa-solid fa-user-lock" aria-hidden="true"></i></span><span>Failures per username + IP</span><strong>5</strong></li>
                <li><span class="admin-rule-icon"><i class="fa-solid fa-network-wired" aria-hidden="true"></i></span><span>Failures per IP</span><strong>30</strong></li>
                <li><span class="admin-rule-icon"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i></span><span>Counting window</span><strong>15 min</strong></li>
                <li><span class="admin-rule-icon"><i class="fa-solid fa-ban" aria-hidden="true"></i></span><span>Block length</span><strong>15 min</strong></li>
                <li><span class="admin-rule-icon"><i class="fa-solid fa-cookie-bite" aria-hidden="true"></i></span><span>Session cookie</span><strong>HttpOnly · SameSite=Lax</strong></li>
            </ul>
            <p class="admin-note">Rules live in <code>includes/login_rate_limit.php</code> and <code>config/session.php</code>.</p>
        </section>
    </div>

    <section class="admin-panel admin-security-activity">
        <div class="admin-panel-heading"><div><h3>Administrator activity</h3><p>Showing <?= $totalEntries === 0 ? 0 : $offset + 1 ?>–<?= min($totalEntries, $offset + count($logs)) ?> of <?= $totalEntries ?> entries.</p></div></div>
        <form class="admin-filters" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/security/index.php">
            <label><span class="visually-hidden">Action</span><select name="action"><option value="">All actions</option><?php foreach ($actionOptions as $option): ?><option value="<?= adminEscape($option) ?>" <?= $actionFilter === $option ? 'selected' : '' ?>><?= adminEscape($label($option)) ?></option><?php endforeach; ?></select></label>
            <label><span class="visually-hidden">Entity</span><select name="entity"><option value="">All entities</option><?php foreach ($entityOptions as $option): ?><option value="<?= adminEscape($option) ?>" <?= $entityFilter === $option ? 'selected' : '' ?>><?= adminEscape($label($option)) ?></option><?php endforeach; ?></select></label>
            <button class="admin-filter-button admin-filter-button-sm" type="submit">Filter</button>
            <a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/security/index.php">Clear</a>
        </form>
        <?php if ($logs === []): ?>
            <div class="admin-empty-state"><strong>No activity found</strong><p>Try changing your filters.</p></div>
        <?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>When</th><th>Administrator</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead><tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td data-label="When"><?= adminEscape(date('M j, Y · g:i A', strtotime($log['created_at']))) ?></td>
                        <td data-label="Administrator"><strong><?= adminEscape($log['actor'] ?: 'Unknown') ?></strong></td>
                        <td data-label="Action"><span class="admin-action-chip"><?= adminEscape($label($log['action_type'])) ?></span></td>
                        <td data-label="Entity"><?= adminEscape($label($log['entity_type'])) ?><?= $log['entity_id'] !== null ? '<small>#' . (int) $log['entity_id'] . '</small>' : '' ?></td>
                        <td data-label="Details"><?= adminEscape($log['details'] !== null ? mb_strimwidth($log['details'], 0, 140, '…') : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php if ($totalPages > 1): ?>
                <?php $paginationQuery = $_GET; ?>
                <nav class="admin-pagination" aria-label="Activity pages">
                    <span>Page <?= $page ?> of <?= $totalPages ?></span>
                    <span>
                        <?php if ($page > 1): $paginationQuery['page'] = $page - 1; ?><a href="?<?= adminEscape(http_build_query($paginationQuery)) ?>">Previous</a><?php endif; ?>
                        <?php if ($page < $totalPages): $paginationQuery['page'] = $page + 1; ?> <a href="?<?= adminEscape(http_build_query($paginationQuery)) ?>">Next</a><?php endif; ?>
                    </span>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
