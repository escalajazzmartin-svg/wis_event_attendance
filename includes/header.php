<?php

/**
 * WIS Event Attendance Management System
 * File: includes/header.php
 */

if (!defined('APP_NAME')) {
    define('APP_NAME', 'WEAMMS');
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '/wis_event_attendance');
}

$pageTitle = $pageTitle ?? 'Student Dashboard';
$pageCss = $pageCss ?? '';
$baseUrl = rtrim(BASE_URL, '/');

$initial = $initial ?? 'S';
$studentName = $studentName ?? 'Student';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>
        | <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>
    </title>

    <link rel="icon" type="image/png"
        href="<?= $baseUrl; ?>/assets/images/wisnoname.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="stylesheet"
        href="<?= $baseUrl; ?>/assets/css/style.css">

    <link rel="stylesheet"
        href="<?= $baseUrl; ?>/assets/css/header.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <?php if (!empty($pageCss)): ?>
        <link rel="stylesheet"
            href="<?= $baseUrl; ?>/<?= htmlspecialchars($pageCss, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
</head>

<body>

    <header class="dashboard-topbar">
        <div>
            <h1>WESTMEAD INTERNATIONAL SCHOOL</h1>
        </div>

        <div class="student-account">
            <div class="student-avatar">
                <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <div class="student-account-info">
                <strong>
                    <?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?>
                </strong>
                <span>Student</span>
            </div>
        </div>
    </header>