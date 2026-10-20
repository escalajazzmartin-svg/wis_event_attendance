<?php

$pageTitle = 'Create Event';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';

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
$selectedParticipants = [];
$selectedStudentIds = [];
$selectedYearLevels = [];
$selectedSectionKeys = [];
$errors = [];
$values = [
    'event_title' => '',
    'event_description' => '',
    'venue' => '',
    'academic_period_id' => false,
    'start_at' => null,
    'end_at' => null,
];

foreach ($periods as $period) {
    if ((int) $period['is_current'] === 1) {
        $values['academic_period_id'] = (int) $period['academic_period_id'];
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dhVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token expired. Refresh the page and try again.';
    } else {
        [$values, $errors] = dhValidateEventSubmission($pdo, $_POST);
        [$selectedParticipants, $participantErrors] = dhValidateEventParticipants(
            $pdo,
            $departmentId,
            $_POST
        );
        $errors = array_merge($errors, $participantErrors);
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
                $stmt = $pdo->prepare(
                    "INSERT INTO events
                        (department_id, requested_by, academic_period_id,
                         event_title, event_description, venue, start_at, end_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $departmentId,
                    $departmentHeadId,
                    $values['academic_period_id'],
                    $values['event_title'],
                    $values['event_description'] !== '' ? $values['event_description'] : null,
                    $values['venue'] !== '' ? $values['venue'] : null,
                    $values['start_at'],
                    $values['end_at'],
                ]);
                dhStoreEventParticipants($pdo, (int) $pdo->lastInsertId(), $selectedParticipants);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            header('Location: ' . $baseUrl . '/department-head/events/index.php?notice=created');
            exit;
        }
    }
}

function dhEventInputValue(?string $value): string
{
    return $value === null ? '' : date('Y-m-d\TH:i', strtotime($value));
}
?>

<section class="dashboard-section management-page">
    <div class="section-heading">
        <div>
            <h2>Create Event Request</h2>
            <p>New events are submitted to the administrator for review.</p>
        </div>
        <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/events/index.php">Back to events</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="management-alert" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= dhEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($periods === []): ?>
        <div class="management-alert" role="alert">
            No academic periods are configured. Contact an administrator before creating an event.
        </div>
    <?php else: ?>
        <form class="management-form department-panel" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/events/create.php">
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
                    <input id="start_at" name="start_at" type="datetime-local" required value="<?= dhEscape(dhEventInputValue($values['start_at'])) ?>">
                </div>

                <div class="management-field">
                    <label for="end_at">Ends</label>
                    <input id="end_at" name="end_at" type="datetime-local" required value="<?= dhEscape(dhEventInputValue($values['end_at'])) ?>">
                </div>

                <div class="management-field management-field-full">
                    <label for="event_description">Description</label>
                    <textarea id="event_description" name="event_description" rows="5"><?= dhEscape($values['event_description']) ?></textarea>
                </div>

                <fieldset class="management-participants management-field-full">
                    <legend>Participating students or groups</legend>
                    <p class="management-hint">Leave all boxes unchecked to include the entire department.</p>

                    <?php if ($yearLevels !== []): ?>
                        <div class="management-participant-group">
                            <strong>Year groups</strong>
                            <?php foreach (array_keys($yearLevels) as $yearLevel): ?>
                                <label>
                                    <input type="checkbox" name="participant_year_levels[]" value="<?= dhEscape($yearLevel) ?>" <?= in_array($yearLevel, $selectedYearLevels, true) ? 'checked' : '' ?>>
                                    <?= dhEscape($yearLevel) ?> year
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($sections !== []): ?>
                        <div class="management-participant-group">
                            <strong>Sections</strong>
                            <?php foreach ($sections as $sectionKey => $group): ?>
                                <label>
                                    <input type="checkbox" name="participant_sections[]" value="<?= dhEscape($sectionKey) ?>" <?= in_array($sectionKey, $selectedSectionKeys, true) ? 'checked' : '' ?>>
                                    <?= dhEscape($group['year_level']) ?> · <?= dhEscape($group['section']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

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

            <div class="management-form-actions">
                <button class="management-button" type="submit">Submit Event Request</button>
                <a class="management-button management-button-secondary" href="<?= dhEscape($baseUrl) ?>/department-head/events/index.php">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>