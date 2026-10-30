<?php

$pageTitle = 'Events';
$pageClass = 'dh-events-page';
$shellNavActive = 'events';
require_once __DIR__ . '/../../includes/department_head_shell_start.php';

const DH_EVENTS_PER_PAGE = 10;

$statusTabs = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'completed' => 'Completed',
    'rejected' => 'Rejected',
    'cancellation_requested' => 'Cancellation requested',
    'cancelled' => 'Cancelled',
];
$phases = ['upcoming' => 'Upcoming', 'ongoing' => 'Ongoing', 'completed' => 'Completed'];

$statusFilter = is_string($_GET['status'] ?? null) && isset($statusTabs[$_GET['status']]) ? $_GET['status'] : '';
$phaseFilter = is_string($_GET['phase'] ?? null) && isset($phases[$_GET['phase']]) ? $_GET['phase'] : '';
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$periodFilter = filter_var($_GET['period'] ?? null, FILTER_VALIDATE_INT);
$periodFilter = $periodFilter !== false && $periodFilter > 0 ? $periodFilter : 0;

$academicPeriods = $pdo->query(
    'SELECT academic_period_id, academic_year, semester, is_current
     FROM academic_periods
     ORDER BY academic_year DESC, semester'
)->fetchAll(PDO::FETCH_ASSOC);

// Everything except the status tab, so the tab counts follow the other filters.
$from = ' FROM events e
    INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id';
$where = ['e.department_id = :department_id'];
$params = ['department_id' => $departmentId];

if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where[] = '(e.event_title LIKE :q1 OR e.venue LIKE :q2)';
    $params['q1'] = $like;
    $params['q2'] = $like;
}
if ($phaseFilter === 'upcoming') {
    $where[] = "e.event_status = 'approved' AND e.start_at > NOW()";
} elseif ($phaseFilter === 'ongoing') {
    $where[] = "e.event_status = 'approved' AND e.start_at <= NOW() AND e.end_at >= NOW()";
} elseif ($phaseFilter === 'completed') {
    $where[] = "(e.event_status = 'completed' OR (e.event_status = 'approved' AND e.end_at < NOW()))";
}
if ($periodFilter > 0) {
    $where[] = 'e.academic_period_id = :period';
    $params['period'] = $periodFilter;
}
$baseWhere = implode(' AND ', $where);

$countStmt = $pdo->prepare('SELECT e.event_status, COUNT(*)' . $from . ' WHERE ' . $baseWhere . ' GROUP BY e.event_status');
$countStmt->execute($params);
$statusCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$allCount = array_sum($statusCounts);

$listWhere = $baseWhere;
$listParams = $params;
if ($statusFilter !== '') {
    $listWhere .= ' AND e.event_status = :event_status';
    $listParams['event_status'] = $statusFilter;
}
$total = $statusFilter !== '' ? (int) ($statusCounts[$statusFilter] ?? 0) : $allCount;

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page !== false && $page > 0 ? $page : 1;
$page = min($page, max(1, (int) ceil($total / DH_EVENTS_PER_PAGE)));
$offset = ($page - 1) * DH_EVENTS_PER_PAGE;

$stmt = $pdo->prepare(
    'SELECT e.event_id, e.event_title, e.venue, e.start_at, e.end_at,
            e.event_status, e.cancellation_request_remarks,
            ap.academic_year, ap.semester'
    . $from . ' WHERE ' . $listWhere . '
     ORDER BY e.start_at DESC, e.event_id DESC
     LIMIT ' . DH_EVENTS_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($listParams);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tabHref = static function (string $status): string {
    $query = $_GET;
    unset($query['page'], $query['notice'], $query['status']);
    if ($status !== '') {
        $query['status'] = $status;
    }

    return '?' . http_build_query($query);
};

$notices = [
    'created' => 'Event request created and submitted for review.',
    'updated' => 'Event details updated.',
    'requested' => 'Event resubmitted for review.',
    'cancelled' => 'Pending event request withdrawn.',
    'cancellation_requested' => 'Cancellation request submitted for administrator review.',
    'change_requested' => 'Schedule change submitted for administrator review.',
    'change_approved' => 'Schedule changes were approved and applied.',
    'change_rejected' => 'Schedule change request was rejected.',
];
$notice = is_string($_GET['notice'] ?? null) ? ($notices[$_GET['notice']] ?? '') : '';
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Events</h1>
            <p>Request events and track their approval for your department.</p>
        </div>
        <div class="ad-head-actions">
            <a class="ad-btn ad-btn-solid" href="<?= dhEscape($baseUrl) ?>/department-head/events/create.php"><?= dhIcon('plus') ?>Request event</a>
        </div>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="ad-notice" role="status"><?= dhEscape($notice) ?></div>
    <?php endif; ?>

    <div class="ad-card">
        <nav class="ad-tabs" aria-label="Filter events by status">
            <a href="<?= dhEscape($tabHref('')) ?>" <?= $statusFilter === '' ? 'aria-current="page"' : '' ?>>All <?= (int) $allCount ?></a>
            <?php foreach ($statusTabs as $status => $label): ?>
                <?php $count = (int) ($statusCounts[$status] ?? 0); ?>
                <?php if ($count > 0 || $statusFilter === $status || in_array($status, ['pending', 'approved', 'completed', 'rejected'], true)): ?>
                    <a href="<?= dhEscape($tabHref($status)) ?>" <?= $statusFilter === $status ? 'aria-current="page"' : '' ?>><?= dhEscape($label) ?> <?= $count ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <form class="ad-toolbar" method="get" action="<?= dhEscape($baseUrl) ?>/department-head/events/index.php">
            <?php if ($statusFilter !== ''): ?>
                <input type="hidden" name="status" value="<?= dhEscape($statusFilter) ?>">
            <?php endif; ?>
            <label class="ad-search">
                <?= dhIcon('search') ?>
                <span class="visually-hidden">Search events</span>
                <input type="search" name="q" value="<?= dhEscape($search) ?>" placeholder="Search event title or venue">
            </label>
            <label>
                <span class="visually-hidden">Filter by schedule</span>
                <select name="phase" data-autosubmit>
                    <option value="">All schedules</option>
                    <?php foreach ($phases as $key => $label): ?>
                        <option value="<?= dhEscape($key) ?>" <?= $phaseFilter === $key ? 'selected' : '' ?>><?= dhEscape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="visually-hidden">Filter by academic period</span>
                <select name="period" data-autosubmit>
                    <option value="">All academic periods</option>
                    <?php foreach ($academicPeriods as $period): ?>
                        <option value="<?= (int) $period['academic_period_id'] ?>" <?= $periodFilter === (int) $period['academic_period_id'] ? 'selected' : '' ?>><?= dhEscape($period['semester'] . ' Semester, ' . $period['academic_year']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="visually-hidden" type="submit">Apply filters</button>
        </form>

        <?php if ($events === []): ?>
            <div class="ad-empty">
                <strong>No events found</strong>
                <?= $total === 0 && $statusFilter === '' && $search === '' && $phaseFilter === '' && $periodFilter === 0 ? 'Request an event to get started.' : 'Try a different search or clear the filters.' ?>
            </div>
        <?php else: ?>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Date</th>
                            <th scope="col">Academic period</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php $eventStatus = strtolower($event['event_status']); ?>
                            <tr>
                                <td>
                                    <span class="ad-strong"><?= dhEscape($event['event_title']) ?></span><br>
                                    <span class="ad-muted"><?= dhEscape($event['venue'] ?: 'Venue not specified') ?></span>
                                </td>
                                <td>
                                    <?= dhEscape(date('M j, Y · g:i A', strtotime($event['start_at']))) ?><br>
                                    <span class="ad-muted">to <?= dhEscape(date('M j, Y · g:i A', strtotime($event['end_at']))) ?></span>
                                </td>
                                <td><?= dhEscape($event['semester'] . ' Sem ' . $event['academic_year']) ?></td>
                                <td>
                                    <span class="ad-pill <?= dhEventPill($eventStatus) ?>"><?= dhEscape(ucwords(str_replace('_', ' ', $eventStatus))) ?></span>
                                    <?php if ($eventStatus === 'cancellation_requested' && $event['cancellation_request_remarks']): ?>
                                        <br><span class="ad-muted">Cancellation requested</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="ad-actions">
                                        <a class="ad-link" href="<?= dhEscape($baseUrl) ?>/department-head/events/view.php?id=<?= (int) $event['event_id'] ?>">View</a>

                                        <?php if (in_array($eventStatus, ['pending', 'rejected'], true)): ?>
                                            <span class="sep" aria-hidden="true">·</span>
                                            <a class="ad-link" href="<?= dhEscape($baseUrl) ?>/department-head/events/edit.php?id=<?= (int) $event['event_id'] ?>">Edit</a>
                                        <?php endif; ?>

                                        <?php if ($eventStatus === 'rejected'): ?>
                                            <span class="sep" aria-hidden="true">·</span>
                                            <form method="post" action="<?= dhEscape($baseUrl) ?>/department-head/events/request.php">
                                                <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                                                <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                                <button class="ad-link" type="submit">Resubmit</button>
                                            </form>
                                        <?php elseif (in_array($eventStatus, ['pending', 'approved'], true)): ?>
                                            <span class="sep" aria-hidden="true">·</span>
                                            <form class="ad-inline-form" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/events/cancel.php">
                                                <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                                                <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                                <?php if ($eventStatus === 'approved'): ?>
                                                    <label class="visually-hidden" for="cancel-remarks-<?= (int) $event['event_id'] ?>">Cancellation reason</label>
                                                    <input id="cancel-remarks-<?= (int) $event['event_id'] ?>" name="remarks" type="text" maxlength="1000" required placeholder="Reason for cancellation">
                                                <?php endif; ?>
                                                <button class="ad-link" type="submit"><?= $eventStatus === 'approved' ? 'Request cancellation' : 'Withdraw request' ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php dhListFooter($page, DH_EVENTS_PER_PAGE, $total, count($events), 'events'); ?>
    </div>
</section>

<script>
document.querySelectorAll('[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
});
</script>

<?php require __DIR__ . '/../../includes/department_head_shell_end.php'; ?>
