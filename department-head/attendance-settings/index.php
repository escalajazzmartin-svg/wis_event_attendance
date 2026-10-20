<?php

$pageTitle = 'Attendance Time Rules';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$errors = [];
$notice = ($_GET['notice'] ?? '') === 'saved';
$eventStmt = $pdo->prepare(
    "SELECT e.event_id, e.event_title, e.start_at, e.end_at,
            s.present_until, s.late_until, s.absent_after
     FROM events e
     LEFT JOIN attendance_settings s ON s.event_id = e.event_id
     WHERE e.department_id = ? AND e.event_status = 'approved'
     ORDER BY e.start_at DESC"
);
$eventStmt->execute([$departmentId]);
$events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dhVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token expired. Refresh the page and try again.';
    } else {
        $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
        $times = [];
        foreach (['present_until', 'late_until', 'absent_after'] as $field) {
            $value = $_POST[$field] ?? '';
            $parsedValue = dhParseLocalDateTime($value);
            if ($parsedValue === null) {
                $errors[] = 'Enter valid present, late, and attendance closing times.';
                break;
            }
            $times[$field] = $parsedValue;
        }

        $event = null;
        if ($eventId === false || $eventId < 1) {
            $errors[] = 'Select a valid event.';
        } else {
            $check = $pdo->prepare(
                "SELECT event_id, start_at, end_at
                 FROM events
                 WHERE event_id = ? AND department_id = ?
                   AND event_status = 'approved'"
            );
            $check->execute([$eventId, $departmentId]);
            $event = $check->fetch(PDO::FETCH_ASSOC);
            if (!$event) {
                $errors[] = 'Attendance rules can only be set for an approved event in your department.';
            }
        }

        if ($event && count($times) === 3) {
            $cutoffs = array_values($times);
            if (
                !($cutoffs[0] < $cutoffs[1] && $cutoffs[1] < $cutoffs[2]) ||
                $cutoffs[0] < $event['start_at'] ||
                $cutoffs[2] > $event['end_at']
            ) {
                $errors[] = 'Rules must be ordered present, late, then closing, and fall within the event schedule.';
            }
        }

        if ($errors === [] && $event) {
            $save = $pdo->prepare(
                'INSERT INTO attendance_settings
                    (event_id, present_until, late_until, absent_after, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    present_until = VALUES(present_until),
                    late_until = VALUES(late_until),
                    absent_after = VALUES(absent_after),
                    updated_by = VALUES(updated_by)'
            );
            $save->execute([
                $eventId,
                $cutoffs[0],
                $cutoffs[1],
                $cutoffs[2],
                $departmentHeadId,
            ]);
            header('Location: ' . $baseUrl . '/department-head/attendance-settings/index.php?notice=saved');
            exit;
        }
    }
}
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div>
            <h2>Attendance Time Rules</h2>
            <p>Set the present cutoff, late cutoff, and absent cutoff / attendance closing time for approved department events. Scans after the late cutoff and through the absent cutoff are marked Absent; later scans are closed.</p>
        </div>
    </div>

    <?php if ($notice): ?>
        <div class="management-alert management-alert-success" role="status">Attendance rules saved.</div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="management-alert" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= dhEscape($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($events === []): ?>
        <div class="department-panel empty-state">
            <strong>No approved events</strong>
            <p>Approved department events will appear here for attendance rule configuration.</p>
        </div>
    <?php else: ?>
        <div class="management-table-wrap">
            <table class="management-table">
                <thead>
                    <tr><th scope="col">Event</th><th scope="col">Event schedule</th><th scope="col">Attendance cutoffs</th><th scope="col">Save rules</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <?php
                        $timeValue = static fn(?string $value): string => $value
                            ? date('Y-m-d\TH:i', strtotime($value))
                            : '';
                        ?>
                        <tr>
                            <td data-label="Event">
                                <strong><?= dhEscape($event['event_title']) ?></strong>
                                <span><a href="<?= dhEscape($baseUrl) ?>/department-head/attendance/records.php?event_id=<?= (int) $event['event_id'] ?>">View barcode attendance</a></span>
                                <span><a href="<?= dhEscape($baseUrl) ?>/department-head/attendance/scan.php?event_id=<?= (int) $event['event_id'] ?>">Open barcode scanner</a></span>
                            </td>
                            <td data-label="Event schedule">
                                <?= dhEscape(date('M j, Y · g:i A', strtotime($event['start_at']))) ?>
                                <span>to <?= dhEscape(date('g:i A', strtotime($event['end_at']))) ?></span>
                            </td>
                            <td data-label="Attendance cutoffs">
                                <form id="rules-<?= (int) $event['event_id'] ?>" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/attendance-settings/index.php">
                                    <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                                    <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                    <label>Present cutoff
                                        <input type="datetime-local" name="present_until" required value="<?= dhEscape($timeValue($event['present_until'])) ?>">
                                    </label>
                                    <label>Late cutoff
                                        <input type="datetime-local" name="late_until" required value="<?= dhEscape($timeValue($event['late_until'])) ?>">
                                    </label>
                                    <label>Attendance closing
                                        <input type="datetime-local" name="absent_after" required value="<?= dhEscape($timeValue($event['absent_after'])) ?>">
                                    </label>
                                </form>
                            </td>
                            <td data-label="Save rules">
                                <button class="management-button" type="submit" form="rules-<?= (int) $event['event_id'] ?>">Save</button>
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
