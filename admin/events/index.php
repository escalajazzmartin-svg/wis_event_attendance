<?php

$adminPageTitle = 'Events';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
require_once __DIR__ . '/../../includes/admin_lists.php';

const EVENTS_PER_PAGE = 10;

$statusTabs = [
    'pending' => ['Pending', 'ad-pill-yellow'],
    'approved' => ['Approved', 'ad-pill-green'],
    'completed' => ['Completed', 'ad-pill-blue'],
    'rejected' => ['Rejected', 'ad-pill-red'],
    'cancellation_requested' => ['Cancellation requested', 'ad-pill-yellow'],
    'cancelled' => ['Cancelled', 'ad-pill-gray'],
];
$statusFilter = is_string($_GET['status'] ?? null) && isset($statusTabs[$_GET['status']]) ? $_GET['status'] : '';

[$where, $params, $filters] = adminEventFilter($pdo, $_GET);
[$page, $offset] = adminPage(EVENTS_PER_PAGE);

$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);
$periods = $pdo->query('SELECT academic_period_id, academic_year, semester FROM academic_periods ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);

// Tab counts reflect the search, department and period that are selected.
$countStmt = $pdo->prepare('SELECT e.event_status, COUNT(*) ' . ADMIN_EVENT_FROM . ' WHERE ' . $where . ' GROUP BY e.event_status');
$countStmt->execute($params);
$statusCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$allCount = array_sum(array_map('intval', $statusCounts));

$listWhere = $where;
$listParams = $params;
if ($statusFilter !== '') {
    $listWhere .= ' AND e.event_status = :status';
    $listParams['status'] = $statusFilter;
}
$total = $statusFilter === '' ? $allCount : (int) ($statusCounts[$statusFilter] ?? 0);
[$page, $offset] = adminClampPage($page, EVENTS_PER_PAGE, $total);

$stmt = $pdo->prepare(
    'SELECT e.event_id, e.event_title, e.start_at, e.event_status, d.department_name,
            dh.first_name, dh.middle_name, dh.last_name '
    . ADMIN_EVENT_FROM . ' WHERE ' . $listWhere . '
     ORDER BY FIELD(e.event_status, \'pending\', \'cancellation_requested\', \'approved\', \'completed\', \'rejected\', \'cancelled\'),
              IF(e.event_status IN (\'pending\', \'cancellation_requested\'), UNIX_TIMESTAMP(e.start_at), -UNIX_TIMESTAMP(e.start_at)),
              e.event_id DESC
     LIMIT ' . EVENTS_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($listParams);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$changeRequests = $pdo->query(
    "SELECT cr.change_request_id, cr.event_id, cr.requested_at,
            e.event_title, e.start_at, d.department_name,
            CONCAT_WS(' ', dh.first_name, dh.middle_name, dh.last_name) AS requester
     FROM event_change_requests cr
     INNER JOIN events e ON e.event_id = cr.event_id
     INNER JOIN departments d ON d.department_id = e.department_id
     INNER JOIN department_heads dh ON dh.department_head_id = cr.requested_by_department_head_id
     WHERE cr.request_status = 'pending'
     ORDER BY cr.requested_at ASC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

$notice = match ($_GET['notice'] ?? '') {
    'approved' => 'Event request approved.',
    'rejected' => 'Event request rejected.',
    'cancellation_approved' => 'Event cancellation approved.',
    'cancellation_rejected' => 'Cancellation request declined; event remains approved.',
    'cancelled' => 'Event cancelled by an administrator.',
    'change_approved' => 'Event schedule changes approved.',
    'change_rejected' => 'Event schedule changes rejected.',
    default => '',
};

// Query string shared by tabs, filters and export so they stay in step.
$sharedQuery = array_filter([
    'q' => $filters['q'],
    'department_id' => $filters['department_id'] ?: '',
    'academic_period_id' => $filters['period_all'] ? 'all' : $filters['period_id'],
    'event_date' => $filters['event_date'],
], static fn ($v): bool => $v !== '' && $v !== 0);
$tabHref = static fn (string $status): string => '?' . http_build_query($status === '' ? $sharedQuery : $sharedQuery + ['status' => $status]);
$exportQuery = http_build_query($statusFilter === '' ? $sharedQuery : $sharedQuery + ['status' => $statusFilter]);
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Events</h1>
            <p>Review requests and monitor events for the current academic period.</p>
        </div>
        <div class="ad-head-actions">
            <a class="ad-btn" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/export.php<?= $exportQuery !== '' ? '?' . adminEscape($exportQuery) : '' ?>">Export CSV</a>
        </div>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="ad-notice" role="status"><?= adminEscape($notice) ?></div>
    <?php endif; ?>

    <?php if ($changeRequests !== []): ?>
        <div class="ad-card">
            <h2 class="ad-card-title">Schedule change requests</h2>
            <p class="ad-card-sub"><?= count($changeRequests) ?> awaiting review.</p>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Department</th>
                            <th scope="col">Requested by</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changeRequests as $changeRequest): ?>
                            <tr>
                                <td>
                                    <span class="ad-strong"><?= adminEscape($changeRequest['event_title']) ?></span>
                                    <div class="ad-muted"><?= adminEscape(date('M j, Y', strtotime($changeRequest['start_at']))) ?></div>
                                </td>
                                <td><?= adminEscape($changeRequest['department_name']) ?></td>
                                <td><?= adminEscape($changeRequest['requester']) ?></td>
                                <td><?= adminEscape(date('M j, Y · g:i A', strtotime($changeRequest['requested_at']))) ?></td>
                                <td><a class="ad-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/view.php?id=<?= (int) $changeRequest['event_id'] ?>&amp;change_request_id=<?= (int) $changeRequest['change_request_id'] ?>">Review</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="ad-card">
        <nav class="ad-tabs" aria-label="Filter events by status">
            <a href="<?= adminEscape($tabHref('')) ?>" <?= $statusFilter === '' ? 'aria-current="page"' : '' ?>>All <?= $allCount ?></a>
            <?php foreach ($statusTabs as $status => [$label]): ?>
                <?php $count = (int) ($statusCounts[$status] ?? 0); ?>
                <?php if ($count > 0 || $statusFilter === $status || in_array($status, ['pending', 'approved', 'completed', 'rejected'], true)): ?>
                    <a href="<?= adminEscape($tabHref($status)) ?>" <?= $statusFilter === $status ? 'aria-current="page"' : '' ?>><?= adminEscape($label) ?> <?= $count ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <form class="ad-toolbar" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php">
            <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= adminEscape($statusFilter) ?>"><?php endif; ?>
            <label class="ad-search">
                <?= adminIcon('search') ?>
                <span class="visually-hidden">Search events</span>
                <input type="search" name="q" value="<?= adminEscape($filters['q']) ?>" placeholder="Search event title or venue">
            </label>
            <label>
                <span class="visually-hidden">Filter by department</span>
                <select name="department_id" data-autosubmit>
                    <option value="">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['department_id'] ?>" <?= $filters['department_id'] === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="visually-hidden">Filter by academic period</span>
                <select name="academic_period_id" data-autosubmit>
                    <option value="all" <?= $filters['period_all'] ? 'selected' : '' ?>>All academic periods</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= (int) $period['academic_period_id'] ?>" <?= $filters['period_id'] === (int) $period['academic_period_id'] ? 'selected' : '' ?>><?= adminEscape(adminPeriodLabel($period['academic_year'], $period['semester'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <span class="ad-date-picker">
                <button class="ad-date-btn<?= $filters['event_date'] !== '' ? ' is-set' : '' ?>" type="button" data-date-open aria-label="Filter by event date<?= $filters['event_date'] !== '' ? ' (' . adminEscape($filters['event_date']) . ')' : '' ?>" title="<?= $filters['event_date'] !== '' ? adminEscape(date('M j, Y', strtotime($filters['event_date']))) : 'Filter by event date' ?>"><?= adminIcon('calendar') ?></button>
                <input class="ad-date-input" type="date" name="event_date" value="<?= adminEscape($filters['event_date']) ?>" tabindex="-1" aria-hidden="true">
                <?php if ($filters['event_date'] !== ''): ?>
                    <?php $noDateQuery = array_diff_key($sharedQuery, ['event_date' => true]) + ($statusFilter !== '' ? ['status' => $statusFilter] : []); ?>
                    <a class="ad-date-clear" href="?<?= adminEscape(http_build_query($noDateQuery)) ?>">Clear date</a>
                <?php endif; ?>
            </span>
            <button class="visually-hidden" type="submit">Apply filters</button>
        </form>

        <?php if ($events === []): ?>
            <div class="ad-empty">
                <strong>No events found</strong>
                Try a different tab, search or period.
            </div>
        <?php else: ?>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Department</th>
                            <th scope="col">Date</th>
                            <th scope="col">Requested by</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php
                            $status = $event['event_status'];
                            $needsReview = in_array($status, ['pending', 'cancellation_requested'], true);
                            $headName = adminFullName($event['first_name'], $event['middle_name'], $event['last_name']);
                            ?>
                            <tr>
                                <td class="ad-strong"><?= adminEscape($event['event_title']) ?></td>
                                <td><?= adminEscape($event['department_name']) ?></td>
                                <td><?= adminEscape(date('M j, Y', strtotime($event['start_at']))) ?></td>
                                <td><?= adminEscape($headName ?: '—') ?></td>
                                <td><span class="ad-pill <?= adminEscape($statusTabs[$status][1] ?? 'ad-pill-gray') ?>"><?= adminEscape($statusTabs[$status][0] ?? ucwords(str_replace('_', ' ', $status))) ?></span></td>
                                <td><a class="ad-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/view.php?id=<?= (int) $event['event_id'] ?>"><?= $needsReview ? 'Review' : 'View' ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php adminListFooter($page, EVENTS_PER_PAGE, $total, count($events), 'events'); ?>
    </div>
</section>

<script>
document.querySelectorAll('[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
});
document.querySelectorAll('[data-date-open]').forEach(function (button) {
    var input = button.parentNode.querySelector('input[type="date"]');
    button.addEventListener('click', function () {
        if (typeof input.showPicker === 'function') { input.showPicker(); } else { input.focus(); input.click(); }
    });
    input.addEventListener('change', function () { input.form.submit(); });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
