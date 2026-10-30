<?php

$adminPageTitle = 'Edit Department';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
$departmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$departmentId || $departmentId < 1) {
    http_response_code(400);
    exit('A valid department is required.');
}
$stmt = $pdo->prepare('SELECT department_id, department_code, department_name, description, is_active FROM departments WHERE department_id = ?');
$stmt->execute([$departmentId]);
$department = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$department) {
    http_response_code(404);
    exit('Department not found.');
}
?>
<section class="admin-content-section">
    <div class="admin-page-heading"><div><span class="admin-eyebrow">School structure</span><h2>Edit Department</h2><p>Update department details and retain all associated records.</p></div></div>
    <form class="admin-panel admin-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/departments/update.php">
        <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>"><input type="hidden" name="department_id" value="<?= (int) $department['department_id'] ?>">
        <div class="admin-form-grid">
            <label>Department code<input name="department_code" maxlength="30" required value="<?= adminEscape($department['department_code']) ?>"></label>
            <label>Department name<input name="department_name" maxlength="150" required value="<?= adminEscape($department['department_name']) ?>"></label>
            <label class="admin-form-wide">Description<textarea name="description" rows="4"><?= adminEscape($department['description']) ?></textarea></label>
        </div>
        <div class="admin-form-actions"><button class="admin-filter-button" type="submit">Save changes</button><a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/index.php">Cancel</a></div>
    </form>
    <?php $isActive = (int) $department['is_active'] === 1; ?>
    <div class="ad-card ad-card-form">
        <h3 class="ad-card-title"><?= $isActive ? 'Archive department' : 'Restore department' ?></h3>
        <p class="ad-card-sub"><?= $isActive ? 'Archived departments are marked inactive. Students, events and attendance records are kept.' : 'This department is inactive. Restoring makes it active again.' ?></p>
        <form class="ad-perm-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/departments/status.php">
            <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
            <input type="hidden" name="department_id" value="<?= (int) $department['department_id'] ?>">
            <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
            <span>Status: <?= $isActive ? 'Active' : 'Inactive' ?></span>
            <button class="ad-btn" type="submit"><?= $isActive ? 'Archive' : 'Restore' ?></button>
        </form>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
