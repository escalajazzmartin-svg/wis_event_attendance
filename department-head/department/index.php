<?php

$pageTitle = 'Department Management';
$pageClass = 'department-management-page';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$studentStmt = $pdo->prepare(
    'SELECT student_number, first_name, middle_name, last_name,
            year_level, section, enrollment_status
     FROM students
     WHERE department_id = ?
     ORDER BY last_name, first_name, student_number'
);
$studentStmt->execute([$departmentId]);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

$enrolledCount = 0;
foreach ($students as $student) {
    if ($student['enrollment_status'] === 'enrolled') {
        $enrolledCount++;
    }
}
$membersStmt = $pdo->prepare(
    'SELECT dh.employee_number, dh.first_name, dh.middle_name,
            dh.last_name, u.email
     FROM department_heads dh
     INNER JOIN users u ON u.user_id = dh.user_id
     WHERE dh.department_id = ?
     ORDER BY dh.last_name, dh.first_name'
);
$membersStmt->execute([$departmentId]);
$departmentMembers = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
$eventsStmt = $pdo->prepare(
    'SELECT event_title, venue, start_at, end_at, event_status
     FROM events
     WHERE department_id = ?
     ORDER BY start_at DESC, event_id DESC'
);
$eventsStmt->execute([$departmentId]);
$departmentEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
$departmentNotice = ($_GET['notice'] ?? '') === 'updated';
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div>
            <h2>Department Management</h2>
            <p>View your assigned department, members, students, and event history.</p>
        </div>
    </div>

    <div class="department-panel management-department-card">
        <div class="management-department-heading">
            <div>
                <span class="management-eyebrow">Department</span>
                <h3><?= dhEscape($departmentHead['department_name']) ?></h3>
            </div>
            <span class="status-pill <?= (int) $departmentHead['is_active'] === 1 ? 'status-approved' : 'status-cancelled' ?>">
                <?= (int) $departmentHead['is_active'] === 1 ? 'Active' : 'Inactive' ?>
            </span>
        </div>

        <dl class="management-detail-grid">
            <div>
                <dt>Department code</dt>
                <dd><?= dhEscape($departmentHead['department_code']) ?></dd>
            </div>
            <div>
                <dt>Department head</dt>
                <dd>
                    <?= dhEscape(trim(
                        $departmentHead['first_name'] . ' ' .
                        ($departmentHead['middle_name'] ? $departmentHead['middle_name'] . ' ' : '') .
                        $departmentHead['last_name']
                    )) ?>
                </dd>
            </div>
            <div class="management-detail-full">
                <dt>Description</dt>
                <dd><?= nl2br(dhEscape($departmentHead['description'] ?: 'No department description has been added.')) ?></dd>
            </div>
        </dl>
        <?php if ($departmentNotice): ?>
            <div class="management-alert management-alert-success" role="status">Department details updated.</div>
        <?php endif; ?>
        <?php if ((int) $departmentHead['department_head_can_edit'] === 1): ?>
            <form class="management-form department-panel" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/department/update.php">
                <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                <div class="management-field">
                    <label for="department_name">Department name</label>
                    <input id="department_name" name="department_name" type="text" maxlength="150" required value="<?= dhEscape($departmentHead['department_name']) ?>">
                </div>
                <div class="management-field management-field-full">
                    <label for="department_description">Description</label>
                    <textarea id="department_description" name="description" maxlength="2000" rows="4"><?= dhEscape($departmentHead['description'] ?? '') ?></textarea>
                </div>
                <div class="management-form-actions">
                    <button class="management-button" type="submit">Save Department Details</button>
                </div>
            </form>
        <?php else: ?>
            <p class="management-readonly-note">
                Department details are read-only. Contact an administrator to request changes.
            </p>
        <?php endif; ?>
    </div>

    <div class="section-heading management-roster-heading">
        <div>
            <h2>Department Members</h2>
            <p><?= count($departmentMembers) ?> assigned <?= count($departmentMembers) === 1 ? 'member' : 'members' ?></p>
        </div>
    </div>
    <div class="management-table-wrap">
        <table class="management-table">
            <thead>
                <tr><th scope="col">Name</th><th scope="col">Employee number</th><th scope="col">Role</th><th scope="col">Email</th></tr>
            </thead>
            <tbody>
                <?php foreach ($departmentMembers as $member): ?>
                    <tr>
                        <td data-label="Name"><?= dhEscape(trim(
                            $member['first_name'] . ' ' .
                            ($member['middle_name'] ? $member['middle_name'] . ' ' : '') .
                            $member['last_name']
                        )) ?></td>
                        <td data-label="Employee number"><?= dhEscape($member['employee_number'] ?: '—') ?></td>
                        <td data-label="Role">Department Head</td>
                        <td data-label="Email"><?= dhEscape($member['email'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($departmentMembers === []): ?>
                    <tr><td colspan="4">No department member records are available.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="section-heading management-roster-heading">
        <div>
            <h2>Department Event Schedule and History</h2>
            <p><?= count($departmentEvents) ?> total events</p>
        </div>
    </div>
    <?php if ($departmentEvents === []): ?>
        <div class="department-panel empty-state">
            <strong>No department events</strong>
            <p>Event schedules and history will appear here.</p>
        </div>
    <?php else: ?>
        <div class="management-table-wrap department-roster-scroll">
            <table class="management-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">Date and time</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departmentEvents as $departmentEvent): ?>
                        <tr>
                            <td data-label="Event">
                                <strong><?= dhEscape($departmentEvent['event_title']) ?></strong>
                                <span><?= dhEscape($departmentEvent['venue'] ?: 'Venue not specified') ?></span>
                            </td>
                            <td data-label="Date and time">
                                <?= dhEscape(date('M j, Y · g:i A', strtotime($departmentEvent['start_at']))) ?>
                                <span>to <?= dhEscape(date('M j, Y · g:i A', strtotime($departmentEvent['end_at']))) ?></span>
                            </td>
                            <td data-label="Status">
                                <span class="status-pill status-<?= dhEscape(strtolower($departmentEvent['event_status'])) ?>">
                                    <?= dhEscape(ucwords(str_replace('_', ' ', $departmentEvent['event_status']))) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="section-heading management-roster-heading">
        <div>
            <h2>Student Roster</h2>
            <p><?= count($students) ?> total students · <?= $enrolledCount ?> currently enrolled</p>
        </div>
    </div>

    <?php if ($students === []): ?>
        <div class="department-panel empty-state">
            <i class="fa-solid fa-user-group" aria-hidden="true"></i>
            <strong>No students assigned</strong>
            <p>There are currently no student records for this department.</p>
        </div>
    <?php else: ?>
        <div class="management-table-wrap department-roster-scroll">
            <table class="management-table">
                <thead>
                    <tr>
                        <th scope="col">Student number</th>
                        <th scope="col">Student name</th>
                        <th scope="col">Year level</th>
                        <th scope="col">Section</th>
                        <th scope="col">Enrollment status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <?php
                        $studentName = trim(
                            $student['first_name'] . ' ' .
                            ($student['middle_name'] ? $student['middle_name'] . ' ' : '') .
                            $student['last_name']
                        );
                        ?>
                        <tr>
                            <td data-label="Student number"><?= dhEscape($student['student_number']) ?></td>
                            <td data-label="Student name"><?= dhEscape($studentName) ?></td>
                            <td data-label="Year level"><?= dhEscape($student['year_level'] ?: '—') ?></td>
                            <td data-label="Section"><?= dhEscape($student['section'] ?: '—') ?></td>
                            <td data-label="Enrollment status">
                                <span class="status-pill <?= $student['enrollment_status'] === 'enrolled' ? 'status-approved' : 'status-cancelled' ?>">
                                    <?= dhEscape(ucfirst($student['enrollment_status'])) ?>
                                </span>
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
