<?php

require_once __DIR__ . '/../config/session.php';

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/event_lifecycle.php';

$role = strtolower(trim(
    $_SESSION['role_name'] ?? $_SESSION['role'] ?? ''
));
$role = str_replace([' ', '-'], '_', $role);

if (empty($_SESSION['user_id']) || $role !== 'department_head') {
    header('Location: ' . BASE_URL . '/auth/department-head-login.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT dh.department_head_id, dh.department_id,
            dh.first_name, dh.middle_name, dh.last_name,
            d.department_code, d.department_name, d.description,
            d.is_active, d.department_head_can_edit,
            d.department_head_can_edit_attendance
     FROM department_heads dh
     INNER JOIN departments d ON d.department_id = dh.department_id
     INNER JOIN users u ON u.user_id = dh.user_id
     WHERE dh.user_id = ?
       AND u.account_status = 'active'
       AND d.is_active = 1
     LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$departmentHead = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$departmentHead) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/department-head-login.php?notice=account_unavailable');
    exit;
}

$departmentId = (int) $departmentHead['department_id'];
$departmentHeadId = (int) $departmentHead['department_head_id'];
$baseUrl = BASE_URL;

synchronizeEventLifecycle($pdo);

function dhEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function dhCsrfToken(): string
{
    if (empty($_SESSION['department_head_csrf'])) {
        $_SESSION['department_head_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['department_head_csrf'];
}

function dhVerifyCsrf(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['department_head_csrf'])
        && hash_equals($_SESSION['department_head_csrf'], $token);
}

function dhParseLocalDateTime(mixed $value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        !$dateTime ||
        ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
        $dateTime->format('Y-m-d\TH:i') !== $value
    ) {
        return null;
    }

    return $dateTime->format('Y-m-d H:i:s');
}

function dhCharacterLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $length = preg_match_all('/./us', $value);
    return $length === false ? strlen($value) : $length;
}

function dhValidateEventSubmission(PDO $pdo, array $input): array
{
    $values = [
        'event_title' => trim(is_string($input['event_title'] ?? null) ? $input['event_title'] : ''),
        'event_description' => trim(is_string($input['event_description'] ?? null) ? $input['event_description'] : ''),
        'venue' => trim(is_string($input['venue'] ?? null) ? $input['venue'] : ''),
        'academic_period_id' => filter_var(
            $input['academic_period_id'] ?? null,
            FILTER_VALIDATE_INT
        ),
        'start_at' => dhParseLocalDateTime($input['start_at'] ?? null),
        'end_at' => dhParseLocalDateTime($input['end_at'] ?? null),
    ];
    $errors = [];

    if ($values['event_title'] === '' || dhCharacterLength($values['event_title']) > 200) {
        $errors[] = 'Enter an event title between 1 and 200 characters.';
    }

    if (dhCharacterLength($values['venue']) > 200) {
        $errors[] = 'The venue must be 200 characters or fewer.';
    }

    if ($values['academic_period_id'] === false || $values['academic_period_id'] < 1) {
        $errors[] = 'Select a valid academic period.';
        $period = false;
    } else {
        $stmt = $pdo->prepare(
            'SELECT start_date, end_date
             FROM academic_periods
             WHERE academic_period_id = ?
             LIMIT 1'
        );
        $stmt->execute([$values['academic_period_id']]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$period) {
            $errors[] = 'The selected academic period is no longer available.';
        }
    }

    if ($values['start_at'] === null || $values['end_at'] === null) {
        $errors[] = 'Enter valid event start and end times.';
    } elseif ($values['end_at'] <= $values['start_at']) {
        $errors[] = 'The event end time must be after its start time.';
    } elseif ($values['start_at'] <= $pdo->query('SELECT NOW()')->fetchColumn()) {
        $errors[] = 'The event start time must be in the future.';
    } elseif (
        $period &&
        (
            substr($values['start_at'], 0, 10) < $period['start_date'] ||
            substr($values['end_at'], 0, 10) > $period['end_date']
        )
    ) {
        $errors[] = 'The event dates must fall within the selected academic period.';
    }

    return [$values, $errors];
}

function dhValidateEventParticipants(PDO $pdo, int $departmentId, array $input): array
{
    $studentIds = $input['participant_students'] ?? [];
    $yearLevels = $input['participant_year_levels'] ?? [];
    $sectionKeys = $input['participant_sections'] ?? [];
    $errors = [];

    foreach (['participant_students' => $studentIds, 'participant_year_levels' => $yearLevels, 'participant_sections' => $sectionKeys] as $field => $values) {
        if (!is_array($values) || count($values) > 2000) {
            $errors[] = 'The selected event participants are invalid.';
            return [[], $errors];
        }
    }

    $students = [];
    foreach ($studentIds as $studentId) {
        $validatedId = filter_var($studentId, FILTER_VALIDATE_INT);
        if ($validatedId === false || $validatedId < 1) {
            $errors[] = 'One or more selected students are invalid.';
            return [[], $errors];
        }
        $students[$validatedId] = true;
    }

    if ($students !== []) {
        $placeholders = implode(',', array_fill(0, count($students), '?'));
        $stmt = $pdo->prepare(
            "SELECT student_id
             FROM students
             WHERE department_id = ?
               AND enrollment_status = 'enrolled'
               AND student_id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$departmentId], array_keys($students)));
        $validStudents = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($validStudents) !== count($students)) {
            $errors[] = 'Selected students must be currently enrolled in your department.';
        }
    }

    $availableYearsStmt = $pdo->prepare(
        "SELECT DISTINCT year_level
         FROM students
         WHERE department_id = ?
           AND enrollment_status = 'enrolled'
           AND year_level IS NOT NULL
           AND TRIM(year_level) <> ''"
    );
    $availableYearsStmt->execute([$departmentId]);
    $availableYears = array_fill_keys(
        array_map('strval', $availableYearsStmt->fetchAll(PDO::FETCH_COLUMN)),
        true
    );
    $years = [];
    foreach ($yearLevels as $yearLevel) {
        if (!is_string($yearLevel) || !isset($availableYears[$yearLevel])) {
            $errors[] = 'One or more selected year groups are invalid.';
            return [[], $errors];
        }
        $years[$yearLevel] = true;
    }

    $availableSectionsStmt = $pdo->prepare(
        "SELECT DISTINCT year_level, section
         FROM students
         WHERE department_id = ?
           AND enrollment_status = 'enrolled'
           AND year_level IS NOT NULL
           AND section IS NOT NULL
           AND TRIM(year_level) <> ''
           AND TRIM(section) <> ''"
    );
    $availableSectionsStmt->execute([$departmentId]);
    $availableSections = [];
    foreach ($availableSectionsStmt->fetchAll(PDO::FETCH_ASSOC) as $group) {
        $key = base64_encode($group['year_level'] . "\0" . $group['section']);
        $availableSections[$key] = [
            'year_level' => $group['year_level'],
            'section' => $group['section'],
        ];
    }

    $sections = [];
    foreach ($sectionKeys as $sectionKey) {
        if (!is_string($sectionKey) || !isset($availableSections[$sectionKey])) {
            $errors[] = 'One or more selected sections are invalid.';
            return [[], $errors];
        }
        $sections[$sectionKey] = $availableSections[$sectionKey];
    }

    $participants = [];
    foreach (array_keys($students) as $studentId) {
        $participants[] = [
            'participant_type' => 'student',
            'student_id' => $studentId,
            'year_level' => null,
            'section' => null,
        ];
    }
    foreach (array_keys($years) as $yearLevel) {
        $participants[] = [
            'participant_type' => 'year_level',
            'student_id' => null,
            'year_level' => $yearLevel,
            'section' => null,
        ];
    }
    foreach ($sections as $group) {
        $participants[] = [
            'participant_type' => 'section',
            'student_id' => null,
            'year_level' => $group['year_level'],
            'section' => $group['section'],
        ];
    }

    return [$participants, $errors];
}

function dhStoreEventParticipants(PDO $pdo, int $eventId, array $participants): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO event_participants
            (event_id, participant_type, student_id, year_level, section)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($participants as $participant) {
        $stmt->execute([
            $eventId,
            $participant['participant_type'],
            $participant['student_id'],
            $participant['year_level'],
            $participant['section'],
        ]);
    }
}
