<?php

require_once __DIR__ . '/../../includes/department_head_context.php';

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

        if ($event) {
            $eventDate = date('Y-m-d', strtotime($event['start_at']));
            $sameDay = $eventDate === date('Y-m-d', strtotime($event['end_at']));
            foreach (['present_until', 'late_until', 'absent_after'] as $field) {
                $value = trim((string) ($_POST[$field] ?? ''));
                // Same-day events post a time only; the event's date is added here.
                if ($sameDay && preg_match('/^\d{2}:\d{2}$/', $value)) {
                    $value = $eventDate . 'T' . $value;
                }
                $parsedValue = dhParseLocalDateTime($value);
                if ($parsedValue === null) {
                    $errors[] = 'Enter valid present, late, and attendance closing times.';
                    break;
                }
                $times[$field] = $parsedValue;
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

$pageTitle = 'Time Rules';
$pageClass = 'dh-time-rules-page';
$shellNavActive = 'time-rules';
require_once __DIR__ . '/../../includes/department_head_shell_start.php';

$timeValue = static fn (?string $value): string => $value ? date('Y-m-d\TH:i', strtotime($value)) : '';
$timeOnlyValue = static fn (?string $value): string => $value ? date('H:i', strtotime($value)) : '';
$isSameDay = static fn (array $event): bool => date('Y-m-d', strtotime($event['start_at'])) === date('Y-m-d', strtotime($event['end_at']));
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Time Rules</h1>
            <p>Set present, late, and closing cutoffs for approved events.</p>
        </div>
    </header>

    <?php if ($notice): ?>
        <div class="ad-notice" role="status">Attendance rules saved.</div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="ad-notice ad-notice-error" role="alert">
            <?php foreach ($errors as $error): ?><div><?= dhEscape($error) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="ad-note">Students arriving after the late cutoff are marked absent until attendance closes; later scans are closed.</div>

    <div class="ad-card">
        <?php if ($events === []): ?>
            <div class="ad-empty">
                <strong>No approved events</strong>
                Approved department events will appear here for attendance rule configuration.
            </div>
        <?php else: ?>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Present cutoff</th>
                            <th scope="col">Late cutoff</th>
                            <th scope="col">Attendance closing</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php
                            $formId = 'rules-' . (int) $event['event_id'];
                            $sameDay = $isSameDay($event);
                            $inputType = $sameDay ? 'time' : 'datetime-local';
                            $format = $sameDay ? $timeOnlyValue : $timeValue;
                            ?>
                            <tr>
                                <td>
                                    <span class="ad-strong"><?= dhEscape($event['event_title']) ?></span><br>
                                    <span class="ad-muted"><?= dhEscape(date('M j', strtotime($event['start_at']))) ?> · <?= dhEscape(date('g:i A', strtotime($event['start_at']))) ?>–<?= dhEscape(date('g:i A', strtotime($event['end_at']))) ?></span>
                                    <?php if ($sameDay): ?><br><span class="ad-muted">Single-day event: the date is set automatically; edit the time only.</span><?php endif; ?>
                                </td>
                                <td>
                                    <label class="visually-hidden" for="<?= $formId ?>-present">Present cutoff for <?= dhEscape($event['event_title']) ?></label>
                                    <input class="ad-time" id="<?= $formId ?>-present" type="<?= $inputType ?>" name="present_until" form="<?= $formId ?>" required value="<?= dhEscape($format($event['present_until'])) ?>">
                                </td>
                                <td>
                                    <label class="visually-hidden" for="<?= $formId ?>-late">Late cutoff for <?= dhEscape($event['event_title']) ?></label>
                                    <input class="ad-time" id="<?= $formId ?>-late" type="<?= $inputType ?>" name="late_until" form="<?= $formId ?>" required value="<?= dhEscape($format($event['late_until'])) ?>">
                                </td>
                                <td>
                                    <label class="visually-hidden" for="<?= $formId ?>-close">Attendance closing for <?= dhEscape($event['event_title']) ?></label>
                                    <input class="ad-time" id="<?= $formId ?>-close" type="<?= $inputType ?>" name="absent_after" form="<?= $formId ?>" required value="<?= dhEscape($format($event['absent_after'])) ?>">
                                </td>
                                <td>
                                    <form id="<?= $formId ?>" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/attendance-settings/index.php">
                                        <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                                        <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                        <button class="ad-btn ad-btn-solid sm" type="submit">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/department_head_shell_end.php'; ?>
