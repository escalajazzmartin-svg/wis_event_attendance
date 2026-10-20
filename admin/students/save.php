<?php

require_once __DIR__ . '/../../includes/admin_context.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
if (!adminVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request token.');
}

$role = $_POST['role'] ?? '';
$username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
$email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
$password = $_POST['password'] ?? '';
$firstName = trim(is_string($_POST['first_name'] ?? null) ? $_POST['first_name'] : '');
$middleName = trim(is_string($_POST['middle_name'] ?? null) ? $_POST['middle_name'] : '');
$lastName = trim(is_string($_POST['last_name'] ?? null) ? $_POST['last_name'] : '');
$departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
$studentNumber = trim(is_string($_POST['student_number'] ?? null) ? $_POST['student_number'] : '');
$employeeNumber = trim(is_string($_POST['employee_number'] ?? null) ? $_POST['employee_number'] : '');
$yearLevel = trim(is_string($_POST['year_level'] ?? null) ? $_POST['year_level'] : '');
$section = trim(is_string($_POST['section'] ?? null) ? $_POST['section'] : '');

$valid = in_array($role, ['student', 'department_head'], true)
    && $username !== '' && strlen($username) <= 100
    && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190
    && is_string($password) && strlen($password) >= 12
    && $firstName !== '' && strlen($firstName) <= 100
    && strlen($middleName) <= 100 && $lastName !== '' && strlen($lastName) <= 100
    && $departmentId !== false && $departmentId > 0
    && ($role !== 'student' || ($studentNumber !== '' && strlen($studentNumber) <= 50))
    && ($role !== 'department_head' || strlen($employeeNumber) <= 50);
if (!$valid) {
    http_response_code(400);
    exit('Enter valid account details. Passwords must contain at least 12 characters.');
}

$departmentCheck = $pdo->prepare('SELECT department_id FROM departments WHERE department_id = ? AND is_active = 1');
$departmentCheck->execute([$departmentId]);
if (!$departmentCheck->fetchColumn()) {
    http_response_code(400);
    exit('Select an active department.');
}

try {
    $pdo->beginTransaction();
    $roleStmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
    $roleStmt->execute([$role]);
    $roleId = $roleStmt->fetchColumn();
    if (!$roleId) {
        throw new RuntimeException('The selected account role is not configured.');
    }
    $insertUser = $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, account_status, role_id)
         VALUES (?, ?, ?, ?, 'active', ?)"
    );
    $insertUser->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $roleId]);
    $userId = (int) $pdo->lastInsertId();
    if ($role === 'student') {
        $stmt = $pdo->prepare(
            "INSERT INTO students
                (user_id, student_number, barcode_token, department_id, section, year_level,
                 first_name, middle_name, last_name, enrollment_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'enrolled')"
        );
        $stmt->execute([
            $userId, $studentNumber, bin2hex(random_bytes(32)), $departmentId,
            $section !== '' ? $section : null, $yearLevel !== '' ? $yearLevel : null,
            $firstName, $middleName !== '' ? $middleName : null, $lastName,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO department_heads
                (user_id, department_id, employee_number, first_name, middle_name, last_name)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $departmentId, $employeeNumber !== '' ? $employeeNumber : null,
            $firstName, $middleName !== '' ? $middleName : null, $lastName,
        ]);
    }
    adminLogActivity($pdo, 'created', 'user', $userId, 'Created ' . $role . ' account');
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getCode() === '23000') {
        http_response_code(409);
        exit('Username, email, student number, or employee number is already in use.');
    }
    throw $e;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

adminRedirect('/admin/students/index.php', ['notice' => 'user_created']);
