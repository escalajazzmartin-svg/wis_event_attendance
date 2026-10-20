<?php

$adminPageTitle = 'Create User';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
$departments = $pdo->query('SELECT department_id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);
$selectedRole = ($_GET['role'] ?? '') === 'department_head' ? 'department_head' : 'student';
?>
<section class="admin-content-section">
    <div class="admin-page-heading"><div><span class="admin-eyebrow">People and access</span><h2>Create User Account</h2><p>Register a student or Department Head account.</p></div></div>
    <form class="admin-panel admin-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/save.php">
        <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
        <div class="admin-form-grid">
            <label>Account type<select name="role" required><option value="student" <?= $selectedRole === 'student' ? 'selected' : '' ?>>Student</option><option value="department_head" <?= $selectedRole === 'department_head' ? 'selected' : '' ?>>Department Head</option></select></label>
            <label>Username<input name="username" maxlength="100" required></label>
            <label>Email<input type="email" name="email" maxlength="190" required></label>
            <label>Initial password<input type="password" name="password" minlength="12" autocomplete="new-password" required><small>Use at least 12 characters.</small></label>
            <label>First name<input name="first_name" maxlength="100" required></label>
            <label>Middle name<input name="middle_name" maxlength="100"></label>
            <label>Last name<input name="last_name" maxlength="100" required></label>
            <label>Department<select name="department_id" required><option value="">Choose department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['department_id'] ?>"><?= adminEscape($department['department_name']) ?></option><?php endforeach; ?></select></label>
            <label>Student number<input name="student_number" maxlength="50" data-role="student"></label>
            <label>Employee number<input name="employee_number" maxlength="50" data-role="department_head"></label>
            <label>Year level<input name="year_level" maxlength="50" data-role="student"></label>
            <label>Section<input name="section" maxlength="100" data-role="student"></label>
        </div>
        <div class="admin-form-actions"><button class="admin-filter-button" type="submit">Create account</button><a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/students/index.php">Cancel</a></div>
    </form>
</section>
<script>
const roleSelect = document.querySelector('[name="role"]');
roleSelect.addEventListener('change', function () {
    document.querySelectorAll('[data-role="student"]').forEach(function (field) {
        field.required = this.value === 'student' && field.name === 'student_number';
        field.closest('label').hidden = this.value !== 'student';
    }, this);
    document.querySelectorAll('[data-role="department_head"]').forEach(function (field) {
        field.required = this.value === 'department_head' && field.name === 'employee_number';
        field.closest('label').hidden = this.value !== 'department_head';
    }, this);
});
roleSelect.dispatchEvent(new Event('change'));
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
