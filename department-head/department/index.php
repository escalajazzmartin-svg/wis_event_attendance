<?php

$pageTitle = 'Department';
$pageClass = 'dh-department-page';
$shellNavActive = 'department';
require_once __DIR__ . '/../../includes/department_head_shell_start.php';

$membersStmt = $pdo->prepare(
    'SELECT dh.employee_number, dh.first_name, dh.middle_name, dh.last_name
     FROM department_heads dh
     WHERE dh.department_id = ?
     ORDER BY dh.last_name, dh.first_name'
);
$membersStmt->execute([$departmentId]);
$departmentMembers = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

$eventsStmt = $pdo->prepare(
    'SELECT event_title, start_at, event_status
     FROM events
     WHERE department_id = ?
     ORDER BY start_at DESC, event_id DESC
     LIMIT 6'
);
$eventsStmt->execute([$departmentId]);
$departmentEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

$canEdit = (int) $departmentHead['department_head_can_edit'] === 1;
$isActive = (int) $departmentHead['is_active'] === 1;
$departmentNotice = ($_GET['notice'] ?? '') === 'updated';
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Department</h1>
            <p>Details, members, and schedule for <?= dhEscape($departmentHead['department_name']) ?>.</p>
        </div>
        <?php if ($canEdit): ?>
            <div class="ad-head-actions">
                <button class="ad-btn ad-btn-solid" type="submit" form="department-form">Save changes</button>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($departmentNotice): ?>
        <div class="ad-notice" role="status">Department details updated.</div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="ad-note"><strong>You can edit this department.</strong> Granted by the administrator. Department code and head are managed by the admin.</div>
    <?php else: ?>
        <div class="ad-note">Department details are read-only. Contact an administrator to request changes.</div>
    <?php endif; ?>

    <div class="ad-card">
        <div class="ad-card-head">
            <h2>Department details</h2>
            <span class="ad-pill <?= $isActive ? 'ad-pill-green' : 'ad-pill-gray' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
        </div>
        <form id="department-form" class="ad-pad" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/department/update.php">
            <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
            <div class="ad-fields">
                <div>
                    <label for="department_name">Department name</label>
                    <input id="department_name" name="department_name" type="text" maxlength="150" required
                        value="<?= dhEscape($departmentHead['department_name']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
                </div>
                <div>
                    <label for="department_code">Code</label>
                    <input id="department_code" type="text" value="<?= dhEscape($departmentHead['department_code']) ?>" readonly>
                </div>
                <div class="full">
                    <label for="department_description">Description</label>
                    <textarea id="department_description" name="description" maxlength="2000" rows="4" <?= $canEdit ? '' : 'readonly' ?>><?= dhEscape($departmentHead['description'] ?? '') ?></textarea>
                </div>
            </div>
        </form>
    </div>

    <div class="ad-split">
        <div class="ad-card">
            <div class="ad-card-head"><h2>Department members</h2></div>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Employee no.</th>
                            <th scope="col">Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departmentMembers as $member): ?>
                            <tr>
                                <td class="ad-strong"><?= dhEscape(dhFullName($member['first_name'], $member['middle_name'], $member['last_name'])) ?></td>
                                <td><?= dhEscape($member['employee_number'] ?: '—') ?></td>
                                <td>Department Head</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($departmentMembers === []): ?>
                            <tr><td colspan="3">No department member records are available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card-head">
                <h2>Event schedule &amp; history</h2>
                <a class="ad-link" href="<?= dhEscape($baseUrl) ?>/department-head/events/index.php">View all</a>
            </div>
            <?php foreach ($departmentEvents as $departmentEvent): ?>
                <?php $eventStatus = strtolower($departmentEvent['event_status']); ?>
                <div class="ad-row">
                    <div>
                        <strong><?= dhEscape($departmentEvent['event_title']) ?></strong>
                        <small><?= dhEscape(date('M j, Y', strtotime($departmentEvent['start_at']))) ?></small>
                    </div>
                    <span class="ad-pill <?= dhEventPill($eventStatus) ?>"><?= dhEscape(ucwords(str_replace('_', ' ', $eventStatus))) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($departmentEvents === []): ?>
                <div class="ad-empty">
                    <strong>No department events</strong>
                    Event schedules and history will appear here.
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../../includes/department_head_shell_end.php'; ?>
