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
<footer class="dashboard-footer">
    <span>WEAMMS · WESTMEAD INTERNATIONAL SCHOOL</span>
    <span><?= htmlspecialchars($footerRoleLabel, ENT_QUOTES, 'UTF-8') ?> Account</span>
</footer>