<?php

$adminPageTitle = 'Event Management';
require_once __DIR__ . '/../../includes/admin_layout_start.php';

$statuses = ['pending', 'approved', 'rejected', 'cancellation_requested', 'cancelled', 'completed'];
$statusFilter = $_GET['status'] ?? '';
if (!is_string($statusFilter) || ($statusFilter !== '' && !in_array($statusFilter, $statuses, true))) {
    $statusFilter = '';
}
$query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$departmentId = filter_var($_GET['department_id'] ?? null, FILTER_VALIDATE_INT);
$departmentId = $departmentId !== false && $departmentId > 0 ? $departmentId : 0;
$periodId = filter_var($_GET['academic_period_id'] ?? null, FILTER_VALIDATE_INT);
$periodId = $periodId !== false && $periodId > 0 ? $periodId : 0;
$dateFrom = is_string($_GET['date_from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = is_string($_GET['date_to'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']) ? $_GET['date_to'] : '';
$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);
$periods = $pdo->query('SELECT academic_period_id, academic_year, semester FROM academic_periods ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);

$sql = 'SELECT e.event_id, e.event_title, e.start_at, e.end_at,
               e.event_status, d.department_name, ap.academic_year, ap.semester,
               dh.first_name, dh.middle_name, dh.last_name
        FROM events e
        INNER JOIN departments d ON d.department_id = e.department_id
        INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
        LEFT JOIN department_heads dh ON dh.department_head_id = e.requested_by
        WHERE 1 = 1';
$params = [];
if ($statusFilter !== '') {
    $sql .= ' AND e.event_status = :status';
    $params['status'] = $statusFilter;
}
if ($query !== '') {
    $sql .= ' AND (e.event_title LIKE :title OR e.venue LIKE :venue)';
    $params['title'] = '%' . $query . '%';
    $params['venue'] = '%' . $query . '%';
}
if ($departmentId > 0) {
    $sql .= ' AND e.department_id = :department_id';
    $params['department_id'] = $departmentId;
}
if ($periodId > 0) {
    $sql .= ' AND e.academic_period_id = :period_id';
    $params['period_id'] = $periodId;
}
if ($dateFrom !== '') {
    $sql .= ' AND DATE(e.start_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= ' AND DATE(e.start_at) <= :date_to';
    $params['date_to'] = $dateTo;
}
$sql .= ' ORDER BY e.created_at DESC, e.event_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
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
$phaseFilter = $_GET['phase'] ?? '';
if (!is_string($phaseFilter) || !in_array($phaseFilter, ['', 'upcoming', 'ongoing', 'completed'], true)) {
    $phaseFilter = '';
}
if ($phaseFilter !== '') {
    $now = time();
    $events = array_values(array_filter($events, static function (array $event) use ($phaseFilter, $now): bool {
        $start = strtotime($event['start_at']);
        $end = strtotime($event['end_at']);
        return match ($phaseFilter) {
            'upcoming' => $event['event_status'] === 'approved' && $start > $now,
            'ongoing' => $event['event_status'] === 'approved' && $start <= $now && $end >= $now,
            'completed' => $event['event_status'] === 'completed' || ($event['event_status'] === 'approved' && $end < $now),
        };
    }));
}
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
?>

<section class="admin-content-section">
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">Events</span><h2>Event Management</h2><p>Review Department Head event requests and cancellation requests.</p></div>
    </div>
    <?php if ($notice !== ''): ?><div class="admin-panel" role="status"><?= adminEscape($notice) ?></div><?php endif; ?>
    <?php if ($changeRequests !== []): ?>
        <section class="admin-panel admin-integrity-panel">
            <div class="admin-panel-heading"><div><h3>Approved event change requests</h3><p><?= count($changeRequests) ?> schedule change requests awaiting review.</p></div></div>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Event</th><th>Department</th><th>Requested by</th><th>Submitted</th><th>Review</th></tr></thead><tbody>
                <?php foreach ($changeRequests as $changeRequest): ?>
                    <tr><td data-label="Event"><?= adminEscape($changeRequest['event_title']) ?><small><?= adminEscape(date('M j, Y', strtotime($changeRequest['start_at']))) ?></small></td><td data-label="Department"><?= adminEscape($changeRequest['department_name']) ?></td><td data-label="Requested by"><?= adminEscape($changeRequest['requester']) ?></td><td data-label="Submitted"><?= adminEscape(date('M j, Y · g:i A', strtotime($changeRequest['requested_at']))) ?></td><td data-label="Review"><a class="admin-action-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/view.php?id=<?= (int) $changeRequest['event_id'] ?>&change_request_id=<?= (int) $changeRequest['change_request_id'] ?>">Review changes</a></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
    <form class="admin-filters" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php">
        <label class="admin-search-field"><span class="visually-hidden">Search event</span><input type="search" name="q" value="<?= adminEscape($query) ?>" placeholder="Search event or venue"></label>
        <label><span class="visually-hidden">Filter by status</span><select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= adminEscape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= adminEscape(ucwords(str_replace('_', ' ', $status))) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label><span class="visually-hidden">Filter by schedule phase</span><select name="phase"><option value="">All schedule phases</option><?php foreach (['upcoming', 'ongoing', 'completed'] as $phase): ?><option value="<?= $phase ?>" <?= $phaseFilter === $phase ? 'selected' : '' ?>><?= ucfirst($phase) ?></option><?php endforeach; ?></select></label>
        <label><span class="visually-hidden">Filter by department</span><select name="department_id"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['department_id'] ?>" <?= $departmentId === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option><?php endforeach; ?></select></label>
        <label><span class="visually-hidden">Filter by semester and academic year</span><select name="academic_period_id"><option value="">All academic periods</option><?php foreach ($periods as $period): ?><option value="<?= (int) $period['academic_period_id'] ?>" <?= $periodId === (int) $period['academic_period_id'] ? 'selected' : '' ?>><?= adminEscape($period['academic_year'] . ' · ' . $period['semester']) ?></option><?php endforeach; ?></select></label>
        <label><span class="visually-hidden">Start date from</span><input type="date" name="date_from" value="<?= adminEscape($dateFrom) ?>"></label>
        <label><span class="visually-hidden">Start date to</span><input type="date" name="date_to" value="<?= adminEscape($dateTo) ?>"></label>
        <button class="admin-filter-button" type="submit">Filter</button>
        <a class="admin-reset-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/index.php">Clear</a>
    </form>
    <div class="admin-panel">
        <?php if ($events === []): ?>
            <p>No matching events.</p>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th scope="col">Event</th><th scope="col">Department</th><th scope="col">Period</th><th scope="col">Requested by</th><th scope="col">Schedule</th><th scope="col">Status</th><th scope="col">Review</th></tr></thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php $headName = trim(($event['first_name'] ?? '') . ' ' . (($event['middle_name'] ?? '') ? $event['middle_name'] . ' ' : '') . ($event['last_name'] ?? '')); ?>
                            <tr>
                                <td data-label="Event"><strong><?= adminEscape($event['event_title']) ?></strong></td>
                                <td data-label="Department"><?= adminEscape($event['department_name']) ?></td>
                                <td data-label="Period"><?= adminEscape($event['academic_year'] . ' · ' . $event['semester']) ?></td>
                                <td data-label="Requested by"><?= adminEscape($headName ?: '—') ?></td>
                                <td data-label="Schedule"><?= adminEscape(date('M j, Y · g:i A', strtotime($event['start_at']))) ?><span><?= adminEscape(date('M j, Y · g:i A', strtotime($event['end_at']))) ?></span></td>
                                <td data-label="Status"><span class="status-pill status-<?= adminEscape($event['event_status']) ?>"><?= adminEscape(ucwords(str_replace('_', ' ', $event['event_status']))) ?></span></td>
                                <td data-label="Review"><a class="admin-action-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/events/view.php?id=<?= (int) $event['event_id'] ?>">View request</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
