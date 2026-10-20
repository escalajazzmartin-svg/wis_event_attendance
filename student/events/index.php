<?php

$studentPageTitle = 'Event Schedules';
require_once __DIR__ . '/../../includes/student_layout_start.php';

$academicYear = is_string($_GET['academic_year'] ?? null) ? trim($_GET['academic_year']) : '';
$semester = is_string($_GET['semester'] ?? null) ? trim($_GET['semester']) : '';
$phase = $_GET['phase'] ?? '';
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$phases = ['upcoming', 'ongoing', 'past'];
if (!is_string($phase) || ($phase !== '' && !in_array($phase, $phases, true))) {
    $phase = '';
}
$periodStmt = $pdo->query(
    'SELECT DISTINCT academic_year, semester
     FROM academic_periods
     ORDER BY academic_year DESC, semester'
);
$periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);
$periodOptions = [];
foreach ($periods as $period) {
    $periodOptions[$period['academic_year'] . '|' . $period['semester']] = $period;
}
if ($academicYear !== '' && $semester !== '' && !isset($periodOptions[$academicYear . '|' . $semester])) {
    $academicYear = '';
    $semester = '';
}

$sql = "
    SELECT e.event_id, e.event_title, e.event_description, e.venue,
           e.start_at, e.end_at, e.event_status, e.updated_at, e.created_at,
           e.admin_remarks, e.cancellation_request_remarks,
           ap.academic_year, ap.semester
    FROM events e
    INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
    WHERE e.department_id = ?
      AND e.event_status IN ('approved', 'cancellation_requested', 'cancelled', 'completed')
      AND " . studentParticipantCondition('e');
$params = array_merge([$studentDepartmentId], studentParticipantParams($studentProfile));

if ($academicYear !== '') {
    $sql .= ' AND ap.academic_year = ?';
    $params[] = $academicYear;
}
if ($semester !== '') {
    $sql .= ' AND ap.semester = ?';
    $params[] = $semester;
}
if ($search !== '') {
    $sql .= ' AND (e.event_title LIKE ? OR e.event_description LIKE ? OR e.venue LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($phase === 'upcoming') {
    $sql .= " AND e.event_status = 'approved' AND e.start_at > NOW()";
} elseif ($phase === 'ongoing') {
    $sql .= " AND e.event_status = 'approved' AND e.start_at <= NOW() AND e.end_at >= NOW()";
} elseif ($phase === 'past') {
    $sql .= " AND (e.event_status IN ('completed', 'cancelled') OR (e.event_status = 'approved' AND e.end_at < NOW()))";
}
$sql .= ' ORDER BY e.start_at DESC, e.event_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading">
        <div><h2>Event Schedules</h2><p>Events for <?= studentEscape($studentProfile['department_name']) ?> and your participation group.</p></div>
    </div>

    <form class="student-filter-form" method="get" action="<?= studentEscape($studentBaseUrl) ?>/student/events/index.php">
        <label for="event-search">Search events</label>
        <input id="event-search" type="search" name="q" value="<?= studentEscape($search) ?>" placeholder="Title, description, or venue">
        <label for="event-phase">Schedule</label>
        <select id="event-phase" name="phase">
            <option value="">All schedule phases</option>
            <option value="upcoming" <?= $phase === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
            <option value="ongoing" <?= $phase === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
            <option value="past" <?= $phase === 'past' ? 'selected' : '' ?>>Past</option>
        </select>
        <label for="academic-year">Academic year</label>
        <select id="academic-year" name="academic_year">
            <option value="">All years</option>
            <?php foreach (array_unique(array_column($periods, 'academic_year')) as $year): ?>
                <option value="<?= studentEscape($year) ?>" <?= $academicYear === $year ? 'selected' : '' ?>><?= studentEscape($year) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="semester">Semester</label>
        <select id="semester" name="semester">
            <option value="">All semesters</option>
            <?php foreach (array_unique(array_column($periods, 'semester')) as $semesterOption): ?>
                <option value="<?= studentEscape($semesterOption) ?>" <?= $semester === $semesterOption ? 'selected' : '' ?>><?= studentEscape($semesterOption) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="management-button" type="submit">Apply filters</button>
        <?php if ($search !== '' || $phase !== '' || $academicYear !== '' || $semester !== ''): ?>
            <a class="management-link" href="<?= studentEscape($studentBaseUrl) ?>/student/events/index.php">Clear</a>
        <?php endif; ?>
    </form>

    <p class="student-results-count"><?= count($events) ?> matching <?= count($events) === 1 ? 'event' : 'events' ?></p>
    <?php if ($events === []): ?>
        <div class="student-content-panel empty-state"><strong>No matching events</strong><p>Try another filter or check back later for relevant events.</p></div>
    <?php else: ?>
        <div class="student-event-list student-event-grid">
            <?php foreach ($events as $event): ?>
                <?php
                $eventStatus = $event['event_status'];
                $now = time();
                $start = strtotime($event['start_at']);
                $end = strtotime($event['end_at']);
                $schedulePhase = $eventStatus === 'approved'
                    ? ($start > $now ? 'Upcoming' : ($end >= $now ? 'Ongoing' : 'Past'))
                    : ucwords(str_replace('_', ' ', $eventStatus));
                ?>
                <article class="student-event-card student-schedule-card">
                    <div class="student-schedule-heading">
                        <span class="status-pill status-<?= studentEscape($eventStatus) ?>"><?= studentEscape($schedulePhase) ?></span>
                        <span><?= studentEscape($event['academic_year']) ?> · <?= studentEscape($event['semester']) ?></span>
                    </div>
                    <h3><a href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $event['event_id'] ?>"><?= studentEscape($event['event_title']) ?></a></h3>
                    <p class="student-schedule-date"><?= studentEscape(studentDateTime($event['start_at'])) ?> – <?= studentEscape(studentDateTime($event['end_at'], 'g:i A')) ?></p>
                    <p><?= studentEscape($event['venue'] ?: 'Venue to be announced') ?></p>
                    <?php if ($event['event_status'] === 'cancellation_requested'): ?>
                        <p class="student-event-notice">Cancellation requested: <?= studentEscape($event['cancellation_request_remarks'] ?: 'Awaiting administrator review.') ?></p>
                    <?php elseif (in_array($event['event_status'], ['cancelled', 'completed'], true) && $event['admin_remarks']): ?>
                        <p class="student-event-notice"><?= studentEscape($event['admin_remarks']) ?></p>
                    <?php elseif ($event['updated_at'] && strtotime($event['updated_at']) > strtotime($event['created_at'] ?? $event['updated_at'])): ?>
                        <p class="student-event-notice">Schedule updated <?= studentEscape(studentDateTime($event['updated_at'])) ?>.</p>
                    <?php endif; ?>
                    <a class="management-link" href="<?= studentEscape($studentBaseUrl) ?>/student/events/view.php?id=<?= (int) $event['event_id'] ?>">Event details →</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>
</html>
