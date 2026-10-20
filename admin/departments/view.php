<?php

$adminPageTitle = 'Department Details';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
$departmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$departmentId || $departmentId < 1) {
    http_response_code(400);
    exit('A valid department is required.');
}
$departmentStmt = $pdo->prepare(
    'SELECT department_id, department_code, department_name, description, is_active
     FROM departments WHERE department_id = ?'
);
$departmentStmt->execute([$departmentId]);
$department = $departmentStmt->fetch(PDO::FETCH_ASSOC);
if (!$department) {
    http_response_code(404);
    exit('Department not found.');
}
$headStmt = $pdo->prepare(
    "SELECT dh.employee_number, dh.first_name, dh.middle_name, dh.last_name,
            u.email, u.account_status, u.last_login_at
     FROM department_heads dh
     LEFT JOIN users u ON u.user_id = dh.user_id
     WHERE dh.department_id = ?
     ORDER BY dh.last_name, dh.first_name"
);
$headStmt->execute([$departmentId]);
$heads = $headStmt->fetchAll(PDO::FETCH_ASSOC);
$studentStmt = $pdo->prepare(
    "SELECT s.student_number, s.first_name, s.middle_name, s.last_name,
            s.year_level, s.section, s.enrollment_status, u.email,
            u.account_status, u.last_login_at
     FROM students s
     LEFT JOIN users u ON u.user_id = s.user_id
     WHERE s.department_id = ?
     ORDER BY s.year_level, s.section, s.last_name, s.first_name"
);
$studentStmt->execute([$departmentId]);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
$eventStmt = $pdo->prepare(
    "SELECT e.event_id, e.event_title, e.start_at, e.end_at, e.event_status,
            ap.academic_year, ap.semester, COUNT(DISTINCT ea.attendance_id) AS attendance_records
     FROM events e
     INNER JOIN academic_periods ap ON ap.academic_period_id = e.academic_period_id
     LEFT JOIN event_attendance ea ON ea.event_id = e.event_id
     WHERE e.department_id = ?
     GROUP BY e.event_id, e.event_title, e.start_at, e.end_at, e.event_status,
              ap.academic_year, ap.semester
     ORDER BY e.start_at DESC, e.event_id DESC"
);
$eventStmt->execute([$departmentId]);
$events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
$attendanceStmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT ea.attendance_id)
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     WHERE e.department_id = ?'
);
$attendanceStmt->execute([$departmentId]);
$attendanceCount = (int) $attendanceStmt->fetchColumn();
?>
<section class="admin-content-section">
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">Department details</span><h2><?= adminEscape($department['department_name']) ?></h2><p><?= adminEscape($department['department_code']) ?> · <?= adminEscape($department['description'] ?: 'No description') ?></p></div>
        <div class="admin-form-actions"><a class="admin-action-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/edit.php?id=<?= (int) $departmentId ?>">Edit department</a><a class="admin-action-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/departments/index.php">Back to departments</a></div>
    </div>
    <div class="admin-summary-grid">
        <article class="admin-summary-card"><span class="admin-summary-icon admin-icon-gold"><i class="fa-solid fa-user-tie" aria-hidden="true"></i></span><div><span>Department Heads</span><strong><?= count($heads) ?></strong></div></article>
        <article class="admin-summary-card"><span class="admin-summary-icon admin-icon-blue"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i></span><div><span>Students</span><strong><?= count($students) ?></strong></div></article>
        <article class="admin-summary-card"><span class="admin-summary-icon"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span><div><span>Events</span><strong><?= count($events) ?></strong></div></article>
        <article class="admin-summary-card"><span class="admin-summary-icon admin-icon-green"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span><div><span>Attendance records</span><strong><?= $attendanceCount ?></strong></div></article>
    </div>
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><h3>Department Heads</h3><p>Assigned staff and account details.</p></div></div>
        <?php if ($heads === []): ?><p>No Department Head is currently assigned.</p><?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Name</th><th>Employee number</th><th>Email</th><th>Account status</th><th>Last login</th></tr></thead><tbody><?php foreach ($heads as $head): ?><tr><td data-label="Name"><?= adminEscape(trim($head['first_name'] . ' ' . ($head['middle_name'] ? $head['middle_name'] . ' ' : '') . $head['last_name'])) ?></td><td data-label="Employee number"><?= adminEscape($head['employee_number'] ?: '—') ?></td><td data-label="Email"><?= adminEscape($head['email'] ?: '—') ?></td><td data-label="Account status"><?= adminEscape(ucfirst($head['account_status'] ?: 'unknown')) ?></td><td data-label="Last login"><?= $head['last_login_at'] ? adminEscape(date('M j, Y · g:i A', strtotime($head['last_login_at']))) : 'Never' ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
    <section class="admin-panel admin-integrity-panel">
        <div class="admin-panel-heading"><div><h3>Students</h3><p>Roster and account details.</p></div></div>
        <?php if ($students === []): ?><p>No students are assigned to this department.</p><?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Student</th><th>Year / section</th><th>Enrollment</th><th>Email</th><th>Account</th><th>Last login</th></tr></thead><tbody><?php foreach ($students as $student): ?><tr><td data-label="Student"><?= adminEscape(trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'])) ?><small><?= adminEscape($student['student_number']) ?></small></td><td data-label="Year / section"><?= adminEscape(trim(($student['year_level'] ?: '—') . ' · ' . ($student['section'] ?: '—'))) ?></td><td data-label="Enrollment"><?= adminEscape(ucfirst($student['enrollment_status'])) ?></td><td data-label="Email"><?= adminEscape($student['email'] ?: '—') ?></td><td data-label="Account"><?= adminEscape(ucfirst($student['account_status'] ?: 'unknown')) ?></td><td data-label="Last login"><?= $student['last_login_at'] ? adminEscape(date('M j, Y · g:i A', strtotime($student['last_login_at']))) : 'Never' ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
    <section class="admin-panel admin-integrity-panel">
        <div class="admin-panel-heading"><div><h3>Department events</h3><p>Schedules, approval status, and attendance history.</p></div></div>
        <?php if ($events === []): ?><p>No events belong to this department.</p><?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Event</th><th>Schedule</th><th>Period</th><th>Status</th><th>Attendance records</th></tr></thead><tbody><?php foreach ($events as $event): ?><tr><td data-label="Event"><a href="<?= adminEscape($adminBaseUrl) ?>/admin/events/view.php?id=<?= (int) $event['event_id'] ?>"><?= adminEscape($event['event_title']) ?></a></td><td data-label="Schedule"><?= adminEscape(date('M j, Y · g:i A', strtotime($event['start_at']))) ?><small>to <?= adminEscape(date('M j, Y · g:i A', strtotime($event['end_at']))) ?></small></td><td data-label="Period"><?= adminEscape($event['academic_year'] . ' · ' . $event['semester']) ?></td><td data-label="Status"><?= adminEscape(ucwords(str_replace('_', ' ', $event['event_status']))) ?></td><td data-label="Attendance records"><?= (int) $event['attendance_records'] ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?></main></body></html>
