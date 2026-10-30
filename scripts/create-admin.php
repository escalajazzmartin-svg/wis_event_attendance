<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../config/database.php';

$username = getenv('WEAMMS_INITIAL_ADMIN_USERNAME');
$email = getenv('WEAMMS_INITIAL_ADMIN_EMAIL');
$password = getenv('WEAMMS_INITIAL_ADMIN_PASSWORD');

if (
    !is_string($username) || trim($username) === '' || strlen($username) > 100 ||
    !is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 ||
    !is_string($password) || strlen($password) < 12 || strlen($password) > 4096
) {
    throw new RuntimeException(
        'Set a valid initial admin username, email, and password (at least 12 characters) in the WEAMMS_INITIAL_ADMIN_* environment variables.'
    );
}

$lock = $pdo->query(
    "SELECT GET_LOCK('weamms_initial_admin', 10)"
)->fetchColumn();

if ((int) $lock !== 1) {
    throw new RuntimeException('Could not acquire the initial-admin setup lock.');
}

try {
    $existingAdmin = $pdo->query(
        "SELECT 1
         FROM users u
         INNER JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name = 'admin'
         LIMIT 1"
    )->fetchColumn();

    if ($existingAdmin !== false) {
        throw new RuntimeException(
            'An administrator account already exists; bootstrap is disabled.'
        );
    }

    $role = $pdo->prepare(
        "SELECT role_id FROM roles WHERE role_name = 'admin' LIMIT 1"
    );
    $role->execute();
    $roleId = $role->fetchColumn();

    if ($roleId === false) {
        throw new RuntimeException('The administrator role is missing from the schema.');
    }

    $insert = $pdo->prepare(
        "INSERT INTO users
            (username, email, password_hash, account_status, role_id)
         VALUES (?, ?, ?, 'active', ?)"
    );
    $insert->execute([
        trim($username),
        trim($email),
        password_hash($password, PASSWORD_DEFAULT),
        $roleId,
    ]);

    fwrite(STDOUT, "Initial administrator created. Remove the bootstrap environment variables now.\n");
} finally {
    $pdo->query("SELECT RELEASE_LOCK('weamms_initial_admin')");
    putenv('WEAMMS_INITIAL_ADMIN_USERNAME');
    putenv('WEAMMS_INITIAL_ADMIN_EMAIL');
    putenv('WEAMMS_INITIAL_ADMIN_PASSWORD');
}
