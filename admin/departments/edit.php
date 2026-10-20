<?php

$adminPageTitle = 'Edit Department';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
$departmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$departmentId || $departmentId < 1) {
    http_response_code(400);
    exit('A valid department is required.');
}
$stmt = $pdo->prepare('SELECT department_id, department_code, department_name, description FROM departments WHERE department_id = ?');
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
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
