<?php

require_once __DIR__ . '/student_context.php';

$studentPageTitle = $studentPageTitle ?? 'Student Portal';
$studentPageCss = $studentPageCss ?? null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= studentEscape($studentPageTitle) ?> | WEAMMS</title>

    <link rel="icon" type="image/png" href="<?= studentEscape($studentBaseUrl) ?>/assets/images/wisnoname.png">

    <link rel="stylesheet" href="<?= studentEscape($studentBaseUrl) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= studentEscape($studentBaseUrl) ?>/assets/css/header.css">
    <link rel="stylesheet" href="<?= studentEscape($studentBaseUrl) ?>/assets/css/student/student.css">

    <?php if ($studentPageCss !== null): ?>
        <link rel="stylesheet"
            href="<?= studentEscape($studentBaseUrl) ?>/assets/css/student/<?= studentEscape($studentPageCss) ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body class="student-dashboard-page">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="dashboard-main student-module-main">
        <?php
        $userRole = 'Student';
        require __DIR__ . '/header.php';
        ?>