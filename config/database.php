<?php

require_once __DIR__ . '/timezone.php';

$host = getenv('WEAMMS_DB_HOST') ?: 'localhost';
$dbname = getenv('WEAMMS_DB_NAME') ?: 'wis_event_attendance';
$username = getenv('WEAMMS_DB_USER');
$username = $username === false || $username === '' ? 'root' : $username;
$password = getenv('WEAMMS_DB_PASSWORD');
$password = $password === false ? '' : $password;
$environment = strtolower(getenv('WEAMMS_ENV') ?: 'production');

if (!in_array($environment, ['development', 'production'], true)) {
    throw new RuntimeException(
        'WEAMMS_ENV must be set to development or production.'
    );
}

if (
    $environment === 'production' &&
    ($username === 'root' || $password === '')
) {
    throw new RuntimeException(
        'Production requires a dedicated database user and a non-empty password.'
    );
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    $pdo->exec('SET time_zone = ' . $pdo->quote(WEAMMS_DATABASE_TIMEZONE));

} catch (PDOException $e) {
    error_log($e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed. Check your MySQL settings.\n");
        exit(1);
    }

    http_response_code(500);
    exit('Database connection failed. Check your MySQL settings.');
}
