<?php

$pageTitle = 'Event Management';
$pageClass = 'event-management-page';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$allowedStatuses = ['pending', 'approved', 'rejected', 'cancellation_requested', 'cancelled', 'completed'];
$statusFilter = $_GET['status'] ?? '';
if (!is_string($statusFilter) || !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
$phases = ['upcoming', 'ongoing', 'completed'];
$phaseFilter = $_GET['phase'] ?? '';
if (!is_string($phaseFilter) || !in_array($phaseFilter, $phases, true)) {
    $phaseFilter = '';
}
$academicYearFilter = trim(is_string($_GET['academic_year'] ?? null) ? $_GET['academic_year'] : '');
$semesterFilter = trim(is_string($_GET['semester'] ?? null) ? $_GET['semester'] : '');
$periodStmt = $pdo->query(
    'SELECT DISTINCT academic_year, semester
     FROM academic_periods
     ORDER BY academic_year DESC, semester'
);
$academicPeriods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "
    SELECT e.event_id, e.event_title, e.venue, e.start_at, e.end_at,
           e.event_status, e.cancellation_request_remarks,
           ap.academic_year, ap.semester
    FROM events e
    INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
    WHERE e.department_id = :department_id";
$params = ['department_id' => $departmentId];

if ($statusFilter !== '') {
    $sql .= ' AND e.event_status = :event_status';
    $params['event_status'] = $statusFilter;
}

if ($phaseFilter === 'upcoming') {
    $sql .= " AND e.event_status = 'approved' AND e.start_at > NOW()";
} elseif ($phaseFilter === 'ongoing') {
    $sql .= " AND e.event_status = 'approved' AND e.start_at <= NOW() AND e.end_at >= NOW()";
} elseif ($phaseFilter === 'completed') {
    $sql .= " AND (e.event_status = 'completed' OR (e.event_status = 'approved' AND e.end_at < NOW()))";
}

if ($academicYearFilter !== '') {
    $sql .= ' AND ap.academic_year = :academic_year';
    $params['academic_year'] = $academicYearFilter;
}
if ($semesterFilter !== '') {
    $sql .= ' AND ap.semester = :semester';
    $params['semester'] = $semesterFilter;
}

$sql .= ' ORDER BY e.start_at DESC, e.event_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
$notice = is_string($_GET['notice'] ?? null)
    ? ($notices[$_GET['notice']] ?? '')
    : '';
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div>
            <h2>Event Management</h2>
            <p>Create event requests and track their review status.</p>
        </div>
        <a class="management-button" href="<?= dhEscape($baseUrl) ?>/department-head/events/create.php">
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            Create Event
        </a>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="management-alert management-alert-success" role="status"><?= dhEscape($notice) ?></div>
    <?php endif; ?>

    <form class="management-filter" method="get" action="<?= dhEscape($baseUrl) ?>/department-head/events/index.php">
        <label for="status">Status</label>
        <select id="status" name="status" onchange="this.form.requestSubmit()">
            <option value="">All statuses</option>
            <?php foreach ($allowedStatuses as $status): ?>
                <option value="<?= dhEscape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                    <?= dhEscape(ucwords(str_replace('_', ' ', $status))) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="phase">Schedule</label>
        <select id="phase" name="phase" onchange="this.form.requestSubmit()">
            <option value="">All schedule phases</option>
            <?php foreach ($phases as $phase): ?>
                <option value="<?= dhEscape($phase) ?>" <?= $phaseFilter === $phase ? 'selected' : '' ?>>
                    <?= dhEscape(ucfirst($phase)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="academic_year">Academic year</label>
        <select id="academic_year" name="academic_year" onchange="this.form.requestSubmit()">
            <option value="">All years</option>
            <?php foreach (array_unique(array_column($academicPeriods, 'academic_year')) as $academicYear): ?>
                <option value="<?= dhEscape($academicYear) ?>" <?= $academicYearFilter === $academicYear ? 'selected' : '' ?>>
                    <?= dhEscape($academicYear) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="semester">Semester</label>
        <select id="semester" name="semester" onchange="this.form.requestSubmit()">
            <option value="">All semesters</option>
            <?php foreach (array_unique(array_column($academicPeriods, 'semester')) as $semester): ?>
                <option value="<?= dhEscape($semester) ?>" <?= $semesterFilter === $semester ? 'selected' : '' ?>>
                    <?= dhEscape($semester) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript>
            <button class="management-button management-button-secondary" type="submit">Apply</button>
        </noscript>
    </form>

    <?php if ($events === []): ?>
        <div class="department-panel empty-state">
            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
            <strong>No events found</strong>
            <p><?= $statusFilter === '' ? 'Create an event request to get started.' : 'There are no events with this status.' ?></p>
        </div>
    <?php else: ?>
        <div class="management-table-wrap event-list-scroll">
            <table class="management-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">Date and time</th>
                        <th scope="col">Academic period</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <?php $eventStatus = strtolower($event['event_status']); ?>
                        <tr>
                            <td data-label="Event">
                                <strong><?= dhEscape($event['event_title']) ?></strong>
                                <span><?= dhEscape($event['venue'] ?: 'Venue not specified') ?></span>
                                <?php if ($eventStatus === 'approved'): ?>
                                    <?php
                                    $now = time();
                                    $startTime = strtotime($event['start_at']);
                                    $endTime = strtotime($event['end_at']);
                                    $phase = $now < $startTime ? 'Upcoming' : ($now <= $endTime ? 'Ongoing' : 'Completed');
                                    ?>
                                    <span><?= dhEscape($phase) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Date and time">
                                <?= dhEscape(date('M j, Y · g:i A', strtotime($event['start_at']))) ?>
                                <span>to <?= dhEscape(date('M j, Y · g:i A', strtotime($event['end_at']))) ?></span>
                            </td>
                            <td data-label="Academic period">
                                <?= dhEscape($event['academic_year']) ?> · <?= dhEscape($event['semester']) ?>
                            </td>
                            <td data-label="Status">
                                <span class="status-pill status-<?= dhEscape($eventStatus) ?>">
                                    <?= dhEscape(ucwords(str_replace('_', ' ', $eventStatus))) ?>
                                </span>
                                <?php if ($eventStatus === 'cancellation_requested' && $event['cancellation_request_remarks']): ?>
                                    <span>Cancellation requested</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Actions" class="management-actions">
                                <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/events/view.php?id=<?= (int) $event['event_id'] ?>">View</a>

                                <?php if (in_array($eventStatus, ['pending', 'rejected'], true)): ?>
                                    <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/events/edit.php?id=<?= (int) $event['event_id'] ?>">Edit</a>
                                <?php endif; ?>

                                <?php if ($eventStatus === 'rejected'): ?>
                                    <form method="post" action="<?= dhEscape($baseUrl) ?>/department-head/events/request.php">
                                        <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                                        <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                        <button class="management-link-button" type="submit">Resubmit</button>
                                    </form>
                                <?php elseif (in_array($eventStatus, ['pending', 'approved'], true)): ?>
                                    <form method="post" action="<?= dhEscape($baseUrl) ?>/department-head/events/cancel.php">
                                        <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                                        <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                        <?php if ($eventStatus === 'approved'): ?>
                                            <label class="visually-hidden" for="cancel-remarks-<?= (int) $event['event_id'] ?>">Cancellation reason</label>
                                            <input id="cancel-remarks-<?= (int) $event['event_id'] ?>" name="remarks" type="text" maxlength="1000" required placeholder="Reason for cancellation">
                                        <?php endif; ?>
                                        <button class="management-link-button management-danger-link" type="submit">
                                            <?= $eventStatus === 'approved' ? 'Request cancellation' : 'Withdraw request' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>