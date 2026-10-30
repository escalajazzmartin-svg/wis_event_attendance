<?php

$adminPageTitle = 'Departments';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
require_once __DIR__ . '/../../includes/admin_lists.php';

const DEPARTMENTS_PER_PAGE = 10;

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$statusFilter = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['active', 'inactive'], true) ? $_GET['status'] : '';
[$page, $offset] = adminPage(DEPARTMENTS_PER_PAGE);

$where = '1 = 1';
$params = [];
if ($search !== '') {
    $where .= ' AND (d.department_name LIKE :q_name OR d.department_code LIKE :q_code OR d.description LIKE :q_description)';
    $params = ['q_name' => '%' . $search . '%', 'q_code' => '%' . $search . '%', 'q_description' => '%' . $search . '%'];
}
if ($statusFilter !== '') {
    $where .= ' AND d.is_active = :is_active';
    $params['is_active'] = $statusFilter === 'active' ? 1 : 0;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM departments d WHERE $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
[$page, $offset] = adminClampPage($page, DEPARTMENTS_PER_PAGE, $total);

$stmt = $pdo->prepare(
    "SELECT d.department_id, d.department_code, d.department_name, d.is_active,
            (SELECT GROUP_CONCAT(CONCAT_WS(' ', dh.first_name, dh.middle_name, dh.last_name)
                                 ORDER BY dh.last_name, dh.first_name SEPARATOR ', ')
               FROM department_heads dh WHERE dh.department_id = d.department_id) AS department_heads,
            (SELECT COUNT(*) FROM students s WHERE s.department_id = d.department_id) AS student_count
     FROM departments d
     WHERE $where
     ORDER BY d.department_name
     LIMIT " . DEPARTMENTS_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($params);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notice = match ($_GET['notice'] ?? '') {
    'department_created' => 'Department added.',
    'department_updated' => 'Department details saved.',
    'department_archived' => 'Department archived.',
    'department_restored' => 'Department restored.',
    default => '',
};
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Departments</h1>
            <p>Academic departments and their assigned heads.</p>
        </div>
        <div class="ad-head-actions">
            <a class="ad-btn ad-btn-solid" href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/create.php"><?= adminIcon('plus') ?>Add department</a>
        </div>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="ad-notice" role="status"><?= adminEscape($notice) ?></div>
    <?php endif; ?>

    <div class="ad-card">
        <form class="ad-toolbar" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/departments/index.php">
            <label class="ad-search">
                <?= adminIcon('search') ?>
                <span class="visually-hidden">Search departments</span>
                <input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search departments">
            </label>
            <label>
                <span class="visually-hidden">Filter by status</span>
                <select name="status" data-autosubmit>
                    <option value="">All statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </label>
            <button class="visually-hidden" type="submit">Apply filters</button>
        </form>

        <?php if ($departments === []): ?>
            <div class="ad-empty">
                <strong>No departments found</strong>
                Try a different search or clear the filters.
            </div>
        <?php else: ?>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Department</th>
                            <th scope="col">Code</th>
                            <th scope="col">Department head</th>
                            <th scope="col">Students</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $department): ?>
                            <?php $isActive = (int) $department['is_active'] === 1; ?>
                            <tr>
                                <td class="ad-strong"><?= adminEscape($department['department_name']) ?></td>
                                <td><?= adminEscape($department['department_code']) ?></td>
                                <td><?= $department['department_heads'] ? adminEscape($department['department_heads']) : '<span class="ad-muted">Not assigned</span>' ?></td>
                                <td><?= number_format((int) $department['student_count']) ?></td>
                                <td><span class="ad-pill <?= $isActive ? 'ad-pill-green' : 'ad-pill-gray' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span></td>
                                <td>
                                    <div class="ad-actions">
                                        <a class="ad-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/view.php?id=<?= (int) $department['department_id'] ?>">View</a>
                                        <span class="sep" aria-hidden="true">·</span>
                                        <a class="ad-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/edit.php?id=<?= (int) $department['department_id'] ?>">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php adminListFooter($page, DEPARTMENTS_PER_PAGE, $total, count($departments), 'departments'); ?>
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
