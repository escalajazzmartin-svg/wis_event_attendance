<?php

require_once __DIR__ . '/admin_context.php';

$adminBaseUrl = BASE_URL;
$adminPageTitle = $adminPageTitle ?? 'Administration';
$adminUsername = $_SESSION['username'] ?? 'Administrator';
$adminInitial = strtoupper(substr(trim($adminUsername), 0, 1)) ?: 'A';
$adminCsrfToken = $_SESSION['admin_csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= adminEscape($adminPageTitle) ?> | WEAMMS</title>
    <link rel="icon" type="image/png" href="<?= adminEscape($adminBaseUrl) ?>/assets/images/wisnoname.png">
    <link rel="stylesheet" href="<?= adminEscape($adminBaseUrl) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= adminEscape($adminBaseUrl) ?>/assets/css/header.css">
    <link rel="stylesheet" href="<?= adminEscape($adminBaseUrl) ?>/assets/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body class="admin-dashboard-page">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main class="dashboard-main">
        <header class="dashboard-topbar">
            <div>
                <span class="eyebrow">WEAMMS Administration</span>
                <h1>WESTMEAD INTERNATIONAL SCHOOL</h1>
            </div>
            <div class="student-account">
                <div class="student-avatar"><?= adminEscape($adminInitial) ?></div>
                <div class="student-account-info">
                    <strong><?= adminEscape($adminUsername) ?></strong>
                    <span>Administrator</span>
                </div>
            </div>
        </header>
