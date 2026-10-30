<?php

$pageTitle = 'Edit Event';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

$eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($eventId === false || $eventId < 1) {
    http_response_code(400);
    exit('A valid event was not specified.');
}

$stmt = $pdo->prepare(
    'SELECT event_id, academic_period_id, event_title,
            event_description, venue, start_at, end_at, event_status
     FROM events
     WHERE event_id = ? AND department_id = ?
     LIMIT 1'
);
$stmt->execute([$eventId, $departmentId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$pendingChangeStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM event_change_requests
     WHERE event_id = ? AND request_status = 'pending'"
);
$pendingChangeStmt->execute([$eventId]);
$hasPendingChange = (int) $pendingChangeStmt->fetchColumn() > 0;
$isApprovedEvent = $event['event_status'] === 'approved';
$databaseNow = $pdo->query('SELECT NOW()')->fetchColumn();
$hasAttendanceStmt = $pdo->prepare(
    'SELECT 1 FROM event_attendance WHERE event_id = ? LIMIT 1'
);
$hasAttendanceStmt->execute([$eventId]);
$hasAttendance = $hasAttendanceStmt->fetchColumn() !== false;
$canEdit = in_array(strtolower($event['event_status']), ['pending', 'rejected'], true)
    || ($isApprovedEvent && !$hasPendingChange && !$hasAttendance && $event['start_at'] > $databaseNow);
$periodStmt = $pdo->query(
    'SELECT academic_period_id, academic_year, semester,
            start_date, end_date, is_current
     FROM academic_periods
     ORDER BY is_current DESC, start_date DESC'
);
$periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);
$studentStmt = $pdo->prepare(
    "SELECT student_id, student_number, first_name, middle_name,
            last_name, year_level, section
     FROM students
     WHERE department_id = ? AND enrollment_status = 'enrolled'
     ORDER BY year_level, section, last_name, first_name"
);
$studentStmt->execute([$departmentId]);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
$yearLevels = [];
$sections = [];
foreach ($students as $student) {
    if ($student['year_level'] !== null && trim($student['year_level']) !== '') {
        $yearLevels[$student['year_level']] = true;
    }
    if (
        $student['year_level'] !== null &&
        $student['section'] !== null &&
        trim($student['year_level']) !== '' &&
        trim($student['section']) !== ''
    ) {
        $sectionKey = base64_encode($student['year_level'] . "\0" . $student['section']);
        $sections[$sectionKey] = [
            'year_level' => $student['year_level'],
            'section' => $student['section'],
        ];
    }
}
$participantStmt = $pdo->prepare(
    'SELECT participant_type, student_id, year_level, section
     FROM event_participants
     WHERE event_id = ?'
);
$participantStmt->execute([$eventId]);
$existingParticipants = $participantStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedStudentIds = [];
$selectedYearLevels = [];
$selectedSectionKeys = [];
foreach ($existingParticipants as $participant) {
    if ($participant['participant_type'] === 'student') {
        $selectedStudentIds[] = (string) $participant['student_id'];
    } elseif ($participant['participant_type'] === 'year_level') {
        $selectedYearLevels[] = $participant['year_level'];
    } else {
        $selectedSectionKeys[] = base64_encode(
            $participant['year_level'] . "\0" . $participant['section']
        );
    }
}
$errors = [];
$changeReason = trim(is_string($_POST['change_reason'] ?? null) ? $_POST['change_reason'] : '');
$values = [
    'event_title' => $event['event_title'],
    'event_description' => $event['event_description'] ?? '',
    'venue' => $event['venue'] ?? '',
    'academic_period_id' => (int) $event['academic_period_id'],
    'start_at' => $event['start_at'],
    'end_at' => $event['end_at'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!dhVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token expired. Refresh the page and try again.';
    } else {
        [$values, $errors] = dhValidateEventSubmission($pdo, $_POST);
        [$participants, $participantErrors] = dhValidateEventParticipants(
            $pdo,
            $departmentId,
            $_POST
        );
        $errors = array_merge($errors, $participantErrors);
        if ($isApprovedEvent && ($changeReason === '' || strlen($changeReason) > 1000)) {
            $errors[] = 'Explain the reason for the approved-event changes in 1,000 characters or fewer.';
        }
        $selectedStudentIds = is_array($_POST['participant_students'] ?? null)
            ? array_values(array_map(
                static fn(mixed $studentId): string => is_scalar($studentId) ? (string) $studentId : '',
                $_POST['participant_students']
            ))
            : [];
        $selectedYearLevels = is_array($_POST['participant_year_levels'] ?? null)
            ? $_POST['participant_year_levels']
            : [];
        $selectedSectionKeys = is_array($_POST['participant_sections'] ?? null)
            ? $_POST['participant_sections']
            : [];

        if ($errors === []) {
            $pdo->beginTransaction();
            try {
            if ($isApprovedEvent) {
                $lockEvent = $pdo->prepare(
                    'SELECT event_status FROM events
                     WHERE event_id = ? AND department_id = ? FOR UPDATE'
                );
                $lockEvent->execute([$eventId, $departmentId]);
                if ($lockEvent->fetchColumn() !== 'approved') {
                    throw new RuntimeException('This event is no longer approved.');
                }
                $pendingCheck = $pdo->prepare(
                    "SELECT change_request_id FROM event_change_requests
                     WHERE event_id = ? AND request_status = 'pending'
                     LIMIT 1"
                );
                $pendingCheck->execute([$eventId]);
                if ($pendingCheck->fetchColumn()) {
                    throw new RuntimeException('A change request is already pending.');
                }
                $payload = json_encode(
                    ['values' => $values, 'participants' => $participants, 'reason' => $changeReason],
                    JSON_THROW_ON_ERROR
                );
                $request = $pdo->prepare(
                    'INSERT INTO event_change_requests
                        (event_id, requested_by_department_head_id, request_payload)
                     VALUES (?, ?, ?)'
                );
                $request->execute([$eventId, $departmentHeadId, $payload]);
                $pdo->commit();
                header('Location: ' . $baseUrl . '/department-head/events/index.php?notice=change_requested');
                exit;
            }
            $update = $pdo->prepare(
                "UPDATE events
                 SET academic_period_id = ?, event_title = ?,
                     event_description = ?, venue = ?, start_at = ?, end_at = ?
                 WHERE event_id = ? AND department_id = ?
                   AND event_status IN ('pending', 'rejected')"
            );
            $update->execute([
                $values['academic_period_id'],
                $values['event_title'],
                $values['event_description'] !== '' ? $values['event_description'] : null,
                $values['venue'] !== '' ? $values['venue'] : null,
                $values['start_at'],
                $values['end_at'],
                $eventId,
                $departmentId,
            ]);

                if ($update->rowCount() === 0) {
                    $statusCheck = $pdo->prepare(
                        "SELECT event_status FROM events
                         WHERE event_id = ? AND department_id = ?
                           AND event_status IN ('pending', 'rejected')"
                    );
                    $statusCheck->execute([$eventId, $departmentId]);
                    if ($statusCheck->fetchColumn() === false) {
                        throw new RuntimeException(
                            'This event is no longer editable because its status changed.'
                        );
                    }
                }

                $deleteParticipants = $pdo->prepare(
                    'DELETE FROM event_participants WHERE event_id = ?'
                );
                $deleteParticipants->execute([$eventId]);
                dhStoreEventParticipants($pdo, $eventId, $participants);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof RuntimeException) {
                    $errors[] = $e->getMessage();
                } else {
                    throw $e;
                }
            }

            if ($errors === []) {
                header('Location: ' . $baseUrl . '/department-head/events/index.php?notice=updated');
                exit;
            }
        }
    }
}

function dhEditEventInputValue(?string $value): string
{
    return $value !== null ? date('Y-m-d\TH:i', strtotime($value)) : '';
}
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div>
            <h2><?= $isApprovedEvent ? 'Request Event Changes' : 'Edit Event Request' ?></h2>
            <p><?= $isApprovedEvent ? 'Changes to approved events must be reviewed by an administrator.' : 'Only pending or rejected requests can be changed.' ?></p>
        </div>
        <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/events/index.php">Back to events</a>
    </div>

    <?php if (!$canEdit): ?>
        <div class="management-alert" role="alert">
            <?= $hasPendingChange
                ? 'A schedule change request is already awaiting administrator review.'
                : ($hasAttendance || ($isApprovedEvent && $event['start_at'] <= $databaseNow)
                    ? 'Events cannot be changed once attendance has started or the event has begun.'
                    : 'This event is ' . dhEscape($event['event_status']) . ' and cannot be edited.') ?>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="management-alert" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= dhEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <form class="management-form department-panel" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/events/edit.php?id=<?= (int) $eventId ?>">
            <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">

            <div class="management-form-grid">
                <div class="management-field management-field-full">
                    <label for="event_title">Event title</label>
                    <input id="event_title" name="event_title" type="text" maxlength="200" required value="<?= dhEscape($values['event_title']) ?>">
                </div>

                <div class="management-field">
                    <label for="academic_period_id">Academic period</label>
                    <select id="academic_period_id" name="academic_period_id" required>
                        <option value="">Select a period</option>
                        <?php foreach ($periods as $period): ?>
                            <?php $periodId = (int) $period['academic_period_id']; ?>
                            <option value="<?= $periodId ?>" <?= (int) $values['academic_period_id'] === $periodId ? 'selected' : '' ?>>
                                <?= dhEscape($period['academic_year']) ?> · <?= dhEscape($period['semester']) ?>
                                (<?= dhEscape($period['start_date']) ?> — <?= dhEscape($period['end_date']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="management-field">
                    <label for="venue">Venue</label>
                    <input id="venue" name="venue" type="text" maxlength="200" value="<?= dhEscape($values['venue']) ?>">
                </div>

                <div class="management-field">
                    <label for="start_at">Starts</label>
                    <input id="start_at" name="start_at" type="datetime-local" required value="<?= dhEscape(dhEditEventInputValue($values['start_at'])) ?>">
                </div>

                <div class="management-field">
                    <label for="end_at">Ends</label>
                    <input id="end_at" name="end_at" type="datetime-local" required value="<?= dhEscape(dhEditEventInputValue($values['end_at'])) ?>">
                </div>

                <div class="management-field management-field-full">
                    <label for="event_description">Description</label>
                    <textarea id="event_description" name="event_description" rows="5"><?= dhEscape($values['event_description']) ?></textarea>
                </div>

                <fieldset class="management-participants management-field-full">
                    <legend>Participating students or groups</legend>
                    <p class="management-hint">Leave all boxes unchecked to include the entire department.</p>

                    <?php foreach (array_keys($yearLevels) as $yearLevel): ?>
                        <label>
                            <input type="checkbox" name="participant_year_levels[]" value="<?= dhEscape($yearLevel) ?>" <?= in_array($yearLevel, $selectedYearLevels, true) ? 'checked' : '' ?>>
                            <?= dhEscape($yearLevel) ?> year
                        </label>
                    <?php endforeach; ?>

                    <?php foreach ($sections as $sectionKey => $group): ?>
                        <label>
                            <input type="checkbox" name="participant_sections[]" value="<?= dhEscape($sectionKey) ?>" <?= in_array($sectionKey, $selectedSectionKeys, true) ? 'checked' : '' ?>>
                            <?= dhEscape($group['year_level']) ?> · <?= dhEscape($group['section']) ?>
                        </label>
                    <?php endforeach; ?>

                    <?php if ($students !== []): ?>
                        <details class="management-student-picker">
                            <summary>Select individual students</summary>
                            <div>
                                <?php foreach ($students as $student): ?>
                                    <?php
                                    $studentName = trim(
                                        $student['first_name'] . ' ' .
                                        ($student['middle_name'] ? $student['middle_name'] . ' ' : '') .
                                        $student['last_name']
                                    );
                                    ?>
                                    <label>
                                        <input type="checkbox" name="participant_students[]" value="<?= (int) $student['student_id'] ?>" <?= in_array((string) $student['student_id'], $selectedStudentIds, true) ? 'checked' : '' ?>>
                                        <?= dhEscape($studentName) ?> · <?= dhEscape($student['student_number']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </fieldset>
            </div>

            <?php if ($isApprovedEvent): ?>
                <div class="management-field management-field-full">
                    <label for="change_reason">Reason for requested changes</label>
                    <textarea id="change_reason" name="change_reason" maxlength="1000" required><?= dhEscape($changeReason) ?></textarea>
                </div>
            <?php endif; ?>

            <div class="management-form-actions">
                <button class="management-button" type="submit"><?= $isApprovedEvent ? 'Submit changes for review' : 'Save Changes' ?></button>
                <a class="management-button management-button-secondary" href="<?= dhEscape($baseUrl) ?>/department-head/events/index.php">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
