<?php

$adminPageTitle = 'Edit User';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$userId || $userId < 1) {
    http_response_code(400);
    exit('A valid user account is required.');
}
$stmt = $pdo->prepare(
    "SELECT u.user_id, u.username, u.email, r.role_name AS role, u.account_status,
            s.student_id, s.student_number, s.department_id, s.year_level, s.section,
            s.first_name AS student_first_name, s.middle_name AS student_middle_name, s.last_name AS student_last_name,
            dh.department_head_id, dh.employee_number, dh.department_id AS head_department_id,
            dh.first_name AS head_first_name, dh.middle_name AS head_middle_name, dh.last_name AS head_last_name
     FROM users u
     INNER JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN students s ON s.user_id = u.user_id
     LEFT JOIN department_heads dh ON dh.user_id = u.user_id
     WHERE u.user_id = ? AND r.role_name IN ('student', 'department_head')"
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    exit('User account not found.');
}
$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);
$isStudent = $user['role'] === 'student';
$listPath = $isStudent ? '/admin/students/index.php' : '/admin/department-heads/index.php';
$permissions = null;
if (!$isStudent) {
    $permissionStmt = $pdo->prepare('SELECT department_head_can_edit, department_head_can_edit_attendance FROM departments WHERE department_id = ?');
    $permissionStmt->execute([(int) $user['head_department_id']]);
    $permissions = $permissionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<section class="admin-content-section">
    <?php if (($_GET['notice'] ?? '') === 'permission_updated'): ?><div class="ad-notice" role="status">Permissions updated.</div><?php endif; ?>
    <div class="admin-page-heading"><div><span class="admin-eyebrow">People and access</span><h2>Edit <?= $isStudent ? 'Student' : 'Department Head' ?></h2><p>Update account details and department assignment.</p></div></div>
    <form class="admin-panel admin-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/update.php">
        <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>"><input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
        <div class="admin-form-grid">
            <label>Username<input name="username" maxlength="100" required value="<?= adminEscape($user['username']) ?>"></label>
            <label>Email<input type="email" name="email" maxlength="190" required value="<?= adminEscape($user['email']) ?>"></label>
            <label>First name<input name="first_name" maxlength="100" required value="<?= adminEscape($isStudent ? $user['student_first_name'] : $user['head_first_name']) ?>"></label>
            <label>Middle name<input name="middle_name" maxlength="100" value="<?= adminEscape($isStudent ? $user['student_middle_name'] : $user['head_middle_name']) ?>"></label>
            <label>Last name<input name="last_name" maxlength="100" required value="<?= adminEscape($isStudent ? $user['student_last_name'] : $user['head_last_name']) ?>"></label>
            <label>Department<select name="department_id" required><?php foreach ($departments as $department): ?><option value="<?= (int) $department['department_id'] ?>" <?= (int) ($isStudent ? $user['department_id'] : $user['head_department_id']) === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option><?php endforeach; ?></select></label>
            <?php if ($isStudent): ?>
                <label>Student number<input name="student_number" maxlength="50" required value="<?= adminEscape($user['student_number']) ?>"></label>
                <label>Year level<input name="year_level" maxlength="50" value="<?= adminEscape($user['year_level']) ?>"></label>
                <label>Section<input name="section" maxlength="100" value="<?= adminEscape($user['section']) ?>"></label>
            <?php else: ?>
                <label>Employee number<input name="employee_number" maxlength="50" value="<?= adminEscape($user['employee_number']) ?>"></label>
            <?php endif; ?>
            <label>Account status<select name="account_status" required><?php foreach (['active', 'inactive', 'locked'] as $status): ?><option value="<?= $status ?>" <?= $user['account_status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="admin-form-actions"><button class="admin-filter-button" type="submit">Save changes</button><a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl . $listPath) ?>">Cancel</a></div>
    </form>
    <?php if ($isStudent): ?>
        <div class="ad-card ad-card-form">
            <h3 class="ad-card-title">Attendance QR code</h3>
            <p class="ad-card-sub">Regenerating the QR code stops the old code from working.</p>
            <form class="ad-perm-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/regenerate-barcode.php" onsubmit="return confirm('Regenerate this student QR code? The old code will stop working.');">
                <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                <input type="hidden" name="return" value="students">
                <span>Issue a new QR code for this student</span>
                <button class="ad-btn" type="submit">Regenerate QR code</button>
            </form>
        </div>
    <?php elseif ($permissions !== null): ?>
        <div class="ad-card ad-card-form">
            <h3 class="ad-card-title">Permissions</h3>
            <p class="ad-card-sub">These apply to every head of this department.</p>
            <?php foreach ([
                'department' => ['Edit department details', (int) $permissions['department_head_can_edit'] === 1],
                'attendance' => ['Correct attendance', (int) $permissions['department_head_can_edit_attendance'] === 1],
            ] as $permission => [$label, $enabled]): ?>
                <form class="ad-perm-form" method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/department-heads/permissions.php">
                    <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                    <input type="hidden" name="department_id" value="<?= (int) $user['head_department_id'] ?>">
                    <input type="hidden" name="permission" value="<?= adminEscape($permission) ?>">
                    <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                    <input type="hidden" name="return" value="user_edit">
                    <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                    <span><?= adminEscape($label) ?><small><?= $enabled ? 'Allowed' : 'View only' ?></small></span>
                    <button class="ad-btn" type="submit"><?= $enabled ? 'Revoke' : 'Allow' ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
