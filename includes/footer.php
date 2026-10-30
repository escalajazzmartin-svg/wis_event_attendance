<?php

$footerRole = strtolower(trim(
    $_SESSION['role_name'] ?? $_SESSION['role'] ?? ''
));
$footerRole = str_replace([' ', '-'], '_', $footerRole);
$footerRoleLabel = match ($footerRole) {
    'admin' => 'Administrator',
    'department_head' => 'Department Head',
    'student' => 'Student',
    default => 'User',
};
?>
<?php if ($footerRole === 'admin'): ?>
<footer class="admin-footer">
    <span><strong>Westmead International School</strong> · WEAMMS, Web-Based Event Attendance Management System</span>
    <span>© <?= date('Y') ?> Westmead International School</span>
</footer>
<?php else: ?>
<footer class="dashboard-footer">
    <span>WEAMMS · WESTMEAD INTERNATIONAL SCHOOL</span>
    <span><?= htmlspecialchars($footerRoleLabel, ENT_QUOTES, 'UTF-8') ?> Account</span>
</footer>
<?php endif; ?>
