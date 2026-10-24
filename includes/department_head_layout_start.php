<?php

require_once __DIR__ . '/department_head_context.php';

$pageTitle = $pageTitle ?? 'Department Head';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= dhEscape($pageTitle) ?> | WEAMMS</title>
    <link rel="icon" type="image/png" href="<?= dhEscape($baseUrl) ?>/assets/images/wisnoname.png">
    <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/header.css">
    <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/department-head.css">
    <?php if (!empty($pageUseStudentReportsCss)): ?>
        <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/student/student-reports.css">
    <?php endif; ?>
    <?php if (!empty($pageCss)): ?>
        <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/<?= dhEscape($pageCss) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body class="department-head-dashboard-page <?= dhEscape($pageClass ?? '') ?>">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main class="dashboard-main">
        <?php require __DIR__ . '/header.php'; ?>
