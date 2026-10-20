<?php

$adminPageTitle = 'Department Management';
require_once __DIR__ . '/../../includes/admin_layout_start.php';

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$statusFilter = $_GET['status'] ?? '';
if (!is_string($statusFilter) || !in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

$sql = "
    SELECT d.department_id, d.department_code, d.department_name,
           d.description, d.is_active, d.department_head_can_edit,
           d.department_head_can_edit_attendance,
           COUNT(DISTINCT dh.department_head_id) AS head_count,
           GROUP_CONCAT(
               DISTINCT CONCAT_WS(' ', dh.first_name, dh.middle_name, dh.last_name)
               ORDER BY dh.last_name, dh.first_name
               SEPARATOR ', '
           ) AS department_heads,
           COUNT(DISTINCT s.student_id) AS student_count,
           COUNT(DISTINCT e.event_id) AS event_count,
           COUNT(DISTINCT ea.attendance_id) AS attendance_count
    FROM departments d
    LEFT JOIN department_heads dh ON dh.department_id = d.department_id
    LEFT JOIN students s ON s.department_id = d.department_id
    LEFT JOIN events e ON e.department_id = d.department_id
    LEFT JOIN event_attendance ea ON ea.event_id = e.event_id
    WHERE 1 = 1";
$params = [];

if ($search !== '') {
    $sql .= ' AND (d.department_name LIKE :search_name OR d.department_code LIKE :search_code OR d.description LIKE :search_description)';
    $searchTerm = '%' . $search . '%';
    $params = [
        'search_name' => $searchTerm,
        'search_code' => $searchTerm,
        'search_description' => $searchTerm,
    ];
}

if ($statusFilter !== '') {
    $sql .= ' AND d.is_active = :is_active';
    $params['is_active'] = $statusFilter === 'active' ? 1 : 0;
}

$sql .= ' GROUP BY d.department_id, d.department_code, d.department_name,
                   d.description, d.is_active, d.department_head_can_edit,
                   d.department_head_can_edit_attendance
          ORDER BY d.department_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(is_active = 1) AS active,
            SUM(is_active = 0) AS inactive
     FROM departments"
)->fetch(PDO::FETCH_ASSOC);
$totalStudents = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$totalHeads = (int) $pdo->query('SELECT COUNT(*) FROM department_heads')->fetchColumn();
$permissionNotice = ($_GET['notice'] ?? '') === 'permission_updated';
?>

<section class="admin-content-section">
    <div class="admin-page-heading">
        <div>
            <span class="admin-eyebrow">School structure</span>
            <h2>Department Management</h2>
            <p>Manage departments, assignments, event totals, and attendance records.</p>
        </div>
        <a class="admin-filter-button admin-button-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/create.php">Add department</a>
    </div>

    <?php if ($permissionNotice): ?>
        <div class="admin-panel admin-permission-notice" role="status">
            Department Head permissions were updated.
        </div>
    <?php endif; ?>

    <div class="admin-summary-grid">
        <article class="admin-summary-card">
            <span class="admin-summary-icon"><i class="fa-solid fa-building" aria-hidden="true"></i></span>
            <div><span>Total departments</span><strong><?= (int) $summary['total'] ?></strong></div>
        </article>
        <article class="admin-summary-card">
            <span class="admin-summary-icon admin-icon-green"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
            <div><span>Active departments</span><strong><?= (int) $summary['active'] ?></strong></div>
        </article>
        <article class="admin-summary-card">
            <span class="admin-summary-icon admin-icon-blue"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i></span>
            <div><span>Students assigned</span><strong><?= $totalStudents ?></strong></div>
        </article>
        <article class="admin-summary-card">
            <span class="admin-summary-icon admin-icon-gold"><i class="fa-solid fa-user-tie" aria-hidden="true"></i></span>
            <div><span>Department heads</span><strong><?= $totalHeads ?></strong></div>
        </article>
    </div>

    <section class="admin-panel">
        <div class="admin-panel-heading">
            <div>
                <h3>Departments</h3>
                <p><?= count($departments) ?> matching <?= count($departments) === 1 ? 'department' : 'departments' ?></p>
            </div>
        </div>

        <form class="admin-filters" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/departments/index.php">
            <label class="admin-search-field">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="visually-hidden">Search departments</span>
                <input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search department name or code">
            </label>
            <label>
                <span class="visually-hidden">Filter by department status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </label>
            <button class="admin-filter-button" type="submit">Filter</button>
            <?php if ($search !== '' || $statusFilter !== ''): ?>
                <a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/index.php">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($departments === []): ?>
            <div class="admin-empty-state">
                <i class="fa-solid fa-building-circle-exclamation" aria-hidden="true"></i>
                <strong>No matching departments</strong>
                <p>Try adjusting your search or filters.</p>
            </div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Department</th>
                            <th scope="col">Department head</th>
                            <th scope="col">Students</th>
                            <th scope="col">Events / attendance</th>
                            <th scope="col">Status</th>
                            <th scope="col">Department Head permissions</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $department): ?>
                            <?php $isActive = (int) $department['is_active'] === 1; ?>
                            <tr>
                                <td data-label="Department">
                                    <div class="admin-department-cell">
                                        <span class="admin-department-icon"><i class="fa-solid fa-building" aria-hidden="true"></i></span>
                                        <span>
                                            <strong><?= adminEscape($department['department_name']) ?></strong>
                                            <small><?= adminEscape($department['department_code']) ?></small>
                                            <?php if ($department['description']): ?>
                                                <small><?= adminEscape($department['description']) ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Department head">
                                    <?= adminEscape($department['department_heads'] ?: 'Not assigned') ?>
                                    <?php if ((int) $department['head_count'] > 1): ?>
                                        <small class="admin-cell-note"><?= (int) $department['head_count'] ?> heads assigned</small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Students"><span class="admin-count-badge"><?= (int) $department['student_count'] ?></span></td>
                                <td data-label="Events / attendance"><?= (int) $department['event_count'] ?> events · <?= (int) $department['attendance_count'] ?> records</td>
                                <td data-label="Status">
                                    <span class="admin-status-badge <?= $isActive ? 'admin-status-active' : 'admin-status-inactive' ?>">
                                        <i class="fa-solid fa-circle" aria-hidden="true"></i>
                                        <?= $isActive ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td data-label="Department Head permissions">
                                    <div class="admin-permission-controls">
                                        <?php foreach (
                                            [
                                                'department' => [
                                                    'label' => 'Edit department details',
                                                    'enabled' => (int) $department['department_head_can_edit'] === 1,
                                                ],
                                                'attendance' => [
                                                    'label' => 'Correct attendance',
                                                    'enabled' => (int) $department['department_head_can_edit_attendance'] === 1,
                                                ],
                                            ] as $permission => $permissionData
                                        ): ?>
                                            <form method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/department-heads/permissions.php">
                                                <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                                                <input type="hidden" name="department_id" value="<?= (int) $department['department_id'] ?>">
                                                <input type="hidden" name="permission" value="<?= adminEscape($permission) ?>">
                                                <input type="hidden" name="enabled" value="<?= $permissionData['enabled'] ? '0' : '1' ?>">
                                                <span><?= adminEscape($permissionData['label']) ?>: <?= $permissionData['enabled'] ? 'Allowed' : 'Read-only' ?></span>
                                                <button class="admin-permission-button" type="submit">
                                                    <?= $permissionData['enabled'] ? 'Revoke' : 'Allow' ?>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td data-label="Actions">
                                    <div class="admin-row-actions">
                                        <a href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/view.php?id=<?= (int) $department['department_id'] ?>">View members</a>
                                        <a href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/edit.php?id=<?= (int) $department['department_id'] ?>">Edit</a>
                                        <form method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/departments/status.php">
                                            <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                                            <input type="hidden" name="department_id" value="<?= (int) $department['department_id'] ?>">
                                            <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
                                            <button type="submit"><?= $isActive ? 'Archive' : 'Restore' ?></button>
                                        </form>
                                    </div>
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