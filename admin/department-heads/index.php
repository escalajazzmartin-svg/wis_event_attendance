<?php

$adminPageTitle = 'Department Heads';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
require_once __DIR__ . '/../../includes/admin_lists.php';

const HEADS_PER_PAGE = 10;

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$departmentId = filter_var($_GET['department_id'] ?? null, FILTER_VALIDATE_INT);
$departmentId = $departmentId !== false && $departmentId > 0 ? $departmentId : 0;
$statusFilter = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['active', 'inactive', 'locked'], true) ? $_GET['status'] : '';
[$page, $offset] = adminPage(HEADS_PER_PAGE);

$from = "FROM department_heads dh
         INNER JOIN users u ON u.user_id = dh.user_id
         INNER JOIN roles r ON r.role_id = u.role_id AND r.role_name = 'department_head'
         INNER JOIN departments d ON d.department_id = dh.department_id";
$where = '1 = 1';
$params = [];
if ($search !== '') {
    $where .= " AND (u.email LIKE :q_email OR u.username LIKE :q_user OR dh.employee_number LIKE :q_number
                OR CONCAT_WS(' ', dh.first_name, dh.middle_name, dh.last_name) LIKE :q_name)";
    foreach (['q_email', 'q_user', 'q_number', 'q_name'] as $key) {
        $params[$key] = '%' . $search . '%';
    }
}
if ($departmentId > 0) {
    $where .= ' AND dh.department_id = :department_id';
    $params['department_id'] = $departmentId;
}
if ($statusFilter !== '') {
    $where .= ' AND u.account_status = :status';
    $params['status'] = $statusFilter;
}

$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $pdo->prepare("SELECT COUNT(*) $from WHERE $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
[$page, $offset] = adminClampPage($page, HEADS_PER_PAGE, $total);

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.email, u.account_status, dh.employee_number,
            dh.first_name, dh.middle_name, dh.last_name,
            d.department_name, d.department_head_can_edit, d.department_head_can_edit_attendance
     $from
     WHERE $where
     ORDER BY d.department_name, dh.last_name, dh.first_name
     LIMIT " . HEADS_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($params);
$heads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notice = match ($_GET['notice'] ?? '') {
    'user_created' => 'Department head account created.',
    'user_updated' => 'Department head details saved.',
    'status_updated' => 'Account status updated.',
    'permission_updated' => 'Permissions updated.',
    default => '',
};
$statusPill = ['active' => 'ad-pill-green', 'inactive' => 'ad-pill-gray', 'locked' => 'ad-pill-red'];
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Department Heads</h1>
            <p>Accounts and edit permissions for each department head.</p>
        </div>
        <div class="ad-head-actions">
            <a class="ad-btn ad-btn-solid" href="<?= adminEscape($adminBaseUrl) ?>/admin/students/create.php?role=department_head"><?= adminIcon('plus') ?>Add department head</a>
        </div>
    </header>

    <?php if (isset($_SESSION['admin_temporary_password'])): ?>
        <div class="ad-notice" role="status">
            <strong>Temporary password (shown once):</strong>
            <code><?= adminEscape($_SESSION['admin_temporary_password']) ?></code>
            Copy it and give it to the account owner securely.
        </div>
        <?php unset($_SESSION['admin_temporary_password']); ?>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="ad-notice" role="status"><?= adminEscape($notice) ?></div>
    <?php endif; ?>

    <div class="ad-card">
        <form class="ad-toolbar" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/department-heads/index.php">
            <label class="ad-search">
                <?= adminIcon('search') ?>
                <span class="visually-hidden">Search department heads</span>
                <input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search name, employee number or email">
            </label>
            <label>
                <span class="visually-hidden">Filter by department</span>
                <select name="department_id" data-autosubmit>
                    <option value="">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['department_id'] ?>" <?= $departmentId === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="visually-hidden">Filter by status</span>
                <select name="status" data-autosubmit>
                    <option value="">All statuses</option>
                    <?php foreach (['active', 'inactive', 'locked'] as $status): ?>
                        <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="visually-hidden" type="submit">Apply filters</button>
        </form>

        <?php if ($heads === []): ?>
            <div class="ad-empty">
                <strong>No department heads found</strong>
                Try a different search or clear the filters.
            </div>
        <?php else: ?>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Department head</th>
                            <th scope="col">Department</th>
                            <th scope="col">Permissions</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($heads as $head): ?>
                            <?php
                            $name = adminFullName($head['first_name'], $head['middle_name'], $head['last_name']);
                            $accountStatus = strtolower($head['account_status']);
                            $canEditDepartment = (int) $head['department_head_can_edit'] === 1;
                            $canEditAttendance = (int) $head['department_head_can_edit_attendance'] === 1;
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-person">
                                        <span class="ad-avatar" aria-hidden="true"><?= adminEscape(adminInitials($name)) ?></span>
                                        <span>
                                            <strong><?= adminEscape($name) ?></strong>
                                            <small><?= adminEscape(($head['employee_number'] ?: '—') . ' · ' . $head['email']) ?></small>
                                        </span>
                                    </div>
                                </td>
                                <td><?= adminEscape($head['department_name']) ?></td>
                                <td>
                                    <div class="ad-pill-stack">
                                        <?php if ($canEditDepartment): ?><span class="ad-pill ad-pill-yellow">Can edit department</span><?php endif; ?>
                                        <?php if ($canEditAttendance): ?><span class="ad-pill ad-pill-blue">Can edit attendance</span><?php endif; ?>
                                        <?php if (!$canEditDepartment && !$canEditAttendance): ?><span class="ad-pill ad-pill-gray">View only</span><?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="ad-pill <?= $statusPill[$accountStatus] ?? 'ad-pill-gray' ?>"><?= adminEscape(ucfirst($accountStatus)) ?></span></td>
                                <td>
                                    <div class="ad-actions">
                                        <a class="ad-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/students/edit.php?id=<?= (int) $head['user_id'] ?>">Edit</a>
                                        <span class="sep" aria-hidden="true">·</span>
                                        <form method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/status.php">
                                            <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $head['user_id'] ?>">
                                            <input type="hidden" name="status" value="<?= $accountStatus === 'active' ? 'inactive' : 'active' ?>">
                                            <input type="hidden" name="return" value="department_heads">
                                            <button class="ad-link" type="submit"><?= $accountStatus === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php adminListFooter($page, HEADS_PER_PAGE, $total, count($heads), 'department heads'); ?>
    </div>
</section>

<script>
document.querySelectorAll('[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
