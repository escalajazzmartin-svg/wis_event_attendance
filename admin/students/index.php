<?php

$adminPageTitle = 'User Management';
require_once __DIR__ . '/../../includes/admin_layout_start.php';

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$validRoles = ['admin', 'department_head', 'student'];
$validStatuses = ['active', 'inactive', 'locked'];

if (!is_string($roleFilter) || !in_array($roleFilter, $validRoles, true)) {
    $roleFilter = '';
}
if (!is_string($statusFilter) || !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$sql = "
    SELECT u.user_id, u.username, u.email, u.role, u.account_status,
           u.last_login_at,
           COALESCE(
               NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)), ''),
               NULLIF(TRIM(CONCAT_WS(' ', dh.first_name, dh.middle_name, dh.last_name)), ''),
               u.username
           ) AS display_name,
           COALESCE(d_student.department_name, d_head.department_name) AS department_name,
           s.student_number, dh.employee_number
    FROM users u
    LEFT JOIN students s ON s.user_id = u.user_id
    LEFT JOIN departments d_student ON d_student.department_id = s.department_id
    LEFT JOIN department_heads dh ON dh.user_id = u.user_id
    LEFT JOIN departments d_head ON d_head.department_id = dh.department_id
    WHERE 1 = 1";
$params = [];

if ($search !== '') {
    $sql .= " AND (
        u.username LIKE :search_username
        OR u.email LIKE :search_email
        OR s.student_number LIKE :search_student
        OR dh.employee_number LIKE :search_employee
        OR s.first_name LIKE :search_student_first
        OR s.last_name LIKE :search_student_last
        OR dh.first_name LIKE :search_head_first
        OR dh.last_name LIKE :search_head_last
    )";
    $searchTerm = '%' . $search . '%';
    $params += [
        'search_username' => $searchTerm,
        'search_email' => $searchTerm,
        'search_student' => $searchTerm,
        'search_employee' => $searchTerm,
        'search_student_first' => $searchTerm,
        'search_student_last' => $searchTerm,
        'search_head_first' => $searchTerm,
        'search_head_last' => $searchTerm,
    ];
}

if ($roleFilter !== '') {
    $sql .= ' AND u.role = :role_filter';
    $params['role_filter'] = $roleFilter;
}
if ($statusFilter !== '') {
    $sql .= ' AND u.account_status = :status_filter';
    $params['status_filter'] = $statusFilter;
}
$sql .= ' ORDER BY u.created_at DESC, u.user_id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(account_status = 'active') AS active,
            SUM(role = 'student') AS students,
            SUM(role = 'department_head') AS department_heads
     FROM users"
)->fetch(PDO::FETCH_ASSOC);
?>

<section class="admin-content-section">
    <div class="admin-page-heading">
        <div>
            <span class="admin-eyebrow">People and access</span>
            <h2>User Management</h2>
            <p>Review user accounts, roles, and department assignments.</p>
        </div>
        <a class="admin-filter-button admin-button-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/students/create.php">Create account</a>
    </div>

    <?php if (isset($_SESSION['admin_temporary_password'])): ?>
        <div class="admin-panel admin-password-notice" role="status">
            <strong>Temporary password (shown once):</strong>
            <code><?= adminEscape($_SESSION['admin_temporary_password']) ?></code>
            <p>Copy and securely provide this password to the account owner.</p>
        </div>
        <?php unset($_SESSION['admin_temporary_password']); ?>
    <?php endif; ?>
    <?php if (($_GET['notice'] ?? '') !== ''): ?>
        <div class="admin-panel admin-permission-notice" role="status"><?= adminEscape(ucwords(str_replace('_', ' ', (string) $_GET['notice']))) ?></div>
    <?php endif; ?>

    <div class="admin-summary-grid">
        <article class="admin-summary-card">
            <span class="admin-summary-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
            <div><span>Total accounts</span><strong><?= (int) $summary['total'] ?></strong></div>
        </article>
        <article class="admin-summary-card">
            <span class="admin-summary-icon admin-icon-green"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span>
            <div><span>Active accounts</span><strong><?= (int) $summary['active'] ?></strong></div>
        </article>
        <article class="admin-summary-card">
            <span class="admin-summary-icon admin-icon-blue"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i></span>
            <div><span>Students</span><strong><?= (int) $summary['students'] ?></strong></div>
        </article>
        <article class="admin-summary-card">
            <span class="admin-summary-icon admin-icon-gold"><i class="fa-solid fa-user-tie" aria-hidden="true"></i></span>
            <div><span>Department heads</span><strong><?= (int) $summary['department_heads'] ?></strong></div>
        </article>
    </div>

    <section class="admin-panel">
        <div class="admin-panel-heading">
            <div>
                <h3>All user accounts</h3>
                <p><?= count($users) ?> matching <?= count($users) === 1 ? 'account' : 'accounts' ?></p>
            </div>
        </div>

        <form class="admin-filters" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/index.php">
            <label class="admin-search-field">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="visually-hidden">Search users</span>
                <input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search name, username, email, ID">
            </label>
            <label>
                <span class="visually-hidden">Filter by role</span>
                <select name="role">
                    <option value="">All roles</option>
                    <?php foreach ($validRoles as $role): ?>
                        <option value="<?= adminEscape($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>>
                            <?= adminEscape(ucwords(str_replace('_', ' ', $role))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="visually-hidden">Filter by account status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($validStatuses as $status): ?>
                        <option value="<?= adminEscape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= adminEscape(ucfirst($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="admin-filter-button" type="submit">Filter</button>
            <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
                <a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/students/index.php">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($users === []): ?>
            <div class="admin-empty-state">
                <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
                <strong>No matching accounts</strong>
                <p>Try adjusting your search or filters.</p>
            </div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Role</th>
                            <th scope="col">Department</th>
                            <th scope="col">Account status</th>
                            <th scope="col">Last login</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $accountStatus = strtolower($user['account_status']);
                            $roleLabel = ucwords(str_replace('_', ' ', $user['role']));
                            ?>
                            <tr>
                                <td data-label="User">
                                    <div class="admin-user-cell">
                                        <span class="admin-user-avatar"><?= adminEscape(strtoupper(substr($user['display_name'], 0, 1))) ?></span>
                                        <span>
                                            <strong><?= adminEscape($user['display_name']) ?></strong>
                                            <small><?= adminEscape($user['email']) ?></small>
                                            <small>
                                                <?= adminEscape($user['username']) ?>
                                                <?php if ($user['student_number']): ?> · <?= adminEscape($user['student_number']) ?><?php endif; ?>
                                                <?php if ($user['employee_number']): ?> · <?= adminEscape($user['employee_number']) ?><?php endif; ?>
                                            </small>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Role"><span class="admin-role-badge"><?= adminEscape($roleLabel) ?></span></td>
                                <td data-label="Department"><?= adminEscape($user['department_name'] ?: '—') ?></td>
                                <td data-label="Account status">
                                    <span class="admin-status-badge admin-status-<?= adminEscape($accountStatus) ?>">
                                        <i class="fa-solid fa-circle" aria-hidden="true"></i>
                                        <?= adminEscape(ucfirst($accountStatus)) ?>
                                    </span>
                                </td>
                                <td data-label="Last login">
                                    <?= $user['last_login_at'] ? adminEscape(date('M j, Y · g:i A', strtotime($user['last_login_at']))) : 'Never' ?>
                                </td>
                                <td data-label="Actions">
                                    <?php if ($user['role'] !== 'admin'): ?>
                                        <div class="admin-row-actions">
                                            <a href="<?= adminEscape($adminBaseUrl) ?>/admin/students/edit.php?id=<?= (int) $user['user_id'] ?>">Edit</a>
                                            <form method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/status.php">
                                                <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                                                <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                                <input type="hidden" name="status" value="<?= $accountStatus === 'active' ? 'inactive' : 'active' ?>">
                                                <button type="submit"><?= $accountStatus === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                            </form>
                                            <form method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/reset-password.php" onsubmit="return confirm('Reset this user password?');">
                                                <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                                                <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                                <button type="submit">Reset password</button>
                                            </form>
                                            <?php if ($user['role'] === 'student'): ?>
                                                <form method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/regenerate-barcode.php" onsubmit="return confirm('Regenerate this student barcode? The old code will stop working.');">
                                                    <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                                                    <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                                    <button type="submit">Regenerate barcode</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span>Protected</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>