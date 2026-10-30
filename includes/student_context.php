<?php

require_once __DIR__ . '/../config/session.php';

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/event_lifecycle.php';

$studentRole = strtolower(trim(
    $_SESSION['role_name'] ?? $_SESSION['role'] ?? ''
));

if (empty($_SESSION['user_id']) || $studentRole !== 'student') {
    header('Location: ' . BASE_URL . '/auth/student-login.php');
    exit;
}

$studentStmt = $pdo->prepare(
    "SELECT s.student_id, s.user_id, s.student_number, s.barcode_token,
            s.department_id, s.year_level, s.section, s.first_name,
            s.middle_name, s.last_name, s.enrollment_status,
            d.department_name
     FROM students s
     INNER JOIN departments d ON d.department_id = s.department_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE s.user_id = ?
       AND u.account_status = 'active'
       AND d.is_active = 1
     LIMIT 1"
);

$studentStmt->execute([$_SESSION['user_id']]);
$studentProfile = $studentStmt->fetch(PDO::FETCH_ASSOC);

if (!$studentProfile) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/student-login.php?notice=account_unavailable');
    exit;
}

$studentId = (int) $studentProfile['student_id'];
$studentDepartmentId = (int) $studentProfile['department_id'];
$studentBaseUrl = BASE_URL;

synchronizeEventLifecycle($pdo);

if (empty($_SESSION['student_csrf_token'])) {
    $_SESSION['student_csrf_token'] = bin2hex(random_bytes(32));
}

function studentEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function studentCsrfToken(): string
{
    return $_SESSION['student_csrf_token'];
}

function studentVerifyCsrf(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['student_csrf_token'])
        && hash_equals($_SESSION['student_csrf_token'], $token);
}

function studentParticipantParams(array $profile): array
{
    return [
        (int) $profile['student_id'],
        $profile['year_level'],
        $profile['year_level'],
        $profile['section'],
    ];
}

function studentParticipantCondition(string $eventAlias = 'e'): string
{
    if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $eventAlias)) {
        throw new InvalidArgumentException('Invalid SQL table alias.');
    }

    return "(
        NOT EXISTS (
            SELECT 1 FROM event_participants ep_any
            WHERE ep_any.event_id = $eventAlias.event_id
        )
        OR EXISTS (
            SELECT 1 FROM event_participants ep
            WHERE ep.event_id = $eventAlias.event_id
              AND (
                (ep.participant_type = 'student' AND ep.student_id = ?)
                OR (ep.participant_type = 'year_level' AND ep.year_level = ?)
                OR (ep.participant_type = 'section'
                    AND ep.year_level = ?
                    AND ep.section = ?)
              )
        )
    )";
}

function studentDateTime(
    ?string $value,
    string $format = 'M j, Y · g:i A'
): string {
    if (!$value) {
        return 'Not available';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? 'Not available'
        : date($format, $timestamp);
}

function studentCharacterLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $length = preg_match_all('/./us', $value);

    return $length === false ? strlen($value) : $length;
}

function studentName(array $profile): string
{
    return trim(
        $profile['first_name'] . ' ' .
            ($profile['middle_name'] ? $profile['middle_name'] . ' ' : '') .
            $profile['last_name']
    );
}

function studentStoreEvidenceUpload(array $file): ?string
{
    if (
        !isset($file['error']) ||
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'The proof upload failed. Choose the file again and retry.'
        );
    }

    if (
        !isset($file['tmp_name'], $file['size']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        throw new RuntimeException(
            'The proof upload could not be verified.'
        );
    }

    if (
        (int) $file['size'] < 1 ||
        (int) $file['size'] > 5 * 1024 * 1024
    ) {
        throw new RuntimeException(
            'Supporting proof must be no larger than 5 MB.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    $extensions = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
        throw new RuntimeException(
            'Proof must be a PDF, JPG, or PNG file.'
        );
    }

    $directory = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'student-appeals';

    if (
        !is_dir($directory) &&
        !mkdir($directory, 0750, true) &&
        !is_dir($directory)
    ) {
        throw new RuntimeException(
            'The server could not prepare secure evidence storage.'
        );
    }

    $filename = bin2hex(random_bytes(24))
        . '.' . $extensions[$mimeType];

    $destination = $directory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException(
            'The server could not store the uploaded proof.'
        );
    }

    return 'uploads/student-appeals/' . $filename;
}

function studentEvidenceFilePath(string $storedPath): ?string
{
    $basename = basename(str_replace('\\', '/', $storedPath));

    if (!preg_match('/\A[a-f0-9]{48}\.(pdf|jpg|png)\z/', $basename)) {
        return null;
    }

    $directory = realpath(
        dirname(__DIR__)
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'student-appeals'
    );

    if ($directory === false) {
        return null;
    }

    $file = realpath($directory . DIRECTORY_SEPARATOR . $basename);

    if (
        $file === false ||
        !str_starts_with(
            strtolower($file),
            strtolower($directory . DIRECTORY_SEPARATOR)
        ) ||
        !is_file($file)
    ) {
        return null;
    }

    return $file;
}
