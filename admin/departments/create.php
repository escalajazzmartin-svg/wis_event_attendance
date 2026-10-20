<?php

$adminPageTitle = 'Add Department';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
?>
<section class="admin-content-section">
    <div class="admin-page-heading"><div><span class="admin-eyebrow">School structure</span><h2>Add Department</h2><p>Create a department. Assign a Department Head from User Management.</p></div></div>
    <form class="admin-panel admin-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/departments/save.php">
        <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
        <div class="admin-form-grid">
            <label>Department code<input name="department_code" maxlength="30" required></label>
            <label>Department name<input name="department_name" maxlength="150" required></label>
            <label class="admin-form-wide">Description<textarea name="description" rows="4"></textarea></label>
        </div>
        <div class="admin-form-actions"><button class="admin-filter-button" type="submit">Create department</button><a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/index.php">Cancel</a></div>
    </form>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
