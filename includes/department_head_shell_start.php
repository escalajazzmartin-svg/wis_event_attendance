<?php

/**
 * Department head page shell that matches the WEAMMS admin look
 * (maroon sidebar, ad-* components). Pages require this file, render their
 * content, then require department_head_shell_end.php.
 */
require_once __DIR__ . '/department_head_context.php';

$pageTitle = $pageTitle ?? 'Department Head';
$pageClass = $pageClass ?? '';
$shellNavActive = $shellNavActive ?? '';

require_once __DIR__ . '/department_head_students.php';

if (!function_exists('dhInitials')) {
    function dhInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '?';
        }
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . $last);
    }

    /** Pill class for an event_status value. */
    function dhEventPill(string $status): string
    {
        return [
            'pending' => 'ad-pill-yellow',
            'approved' => 'ad-pill-green',
            'completed' => 'ad-pill-blue',
            'rejected' => 'ad-pill-red',
            'cancellation_requested' => 'ad-pill-yellow',
            'cancelled' => 'ad-pill-gray',
        ][strtolower($status)] ?? 'ad-pill-gray';
    }

    /** "Showing 1–6 of 312 students" plus Previous / Next that keep the filters. */
    function dhListFooter(int $page, int $perPage, int $total, int $shown, string $noun): void
    {
        $first = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $last = $total === 0 ? 0 : $first + $shown - 1;
        $query = $_GET;
        unset($query['page'], $query['notice']);
        $link = static fn (int $target): string => '?' . http_build_query($query + ['page' => $target]);

        echo '<div class="ad-foot"><span>Showing ' . number_format($first) . '–' . number_format($last)
            . ' of ' . number_format($total) . ' ' . dhEscape($noun) . '</span><nav class="ad-pager" aria-label="Pagination">';
        echo $page > 1
            ? '<a href="' . dhEscape($link($page - 1)) . '">‹ Previous</a>'
            : '<span aria-disabled="true">‹ Previous</span>';
        echo $last < $total
            ? '<a href="' . dhEscape($link($page + 1)) . '">Next ›</a>'
            : '<span aria-disabled="true">Next ›</span>';
        echo '</nav></div>';
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= dhEscape($pageTitle) ?> | WEAMMS</title>
    <link rel="icon" type="image/png" href="<?= dhEscape($baseUrl) ?>/assets/images/wisnoname.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&amp;family=Roboto:wght@400;500;700&amp;display=swap">
    <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/department-head-sidebar.css">
    <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/department-head-shell.css">
    <link rel="stylesheet" href="<?= dhEscape($baseUrl) ?>/assets/css/ui-refresh.css">
</head>

<body class="admin-dashboard-page department-head-shell <?= dhEscape($pageClass) ?>">
    <?php require __DIR__ . '/department_head_sidebar.php'; ?>
    <main class="admin-main">
