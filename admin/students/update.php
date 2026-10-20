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
$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
$username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
$email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
$firstName = trim(is_string($_POST['first_name'] ?? null) ? $_POST['first_name'] : '');
$middleName = trim(is_string($_POST['middle_name'] ?? null) ? $_POST['middle_name'] : '');
$lastName = trim(is_string($_POST['last_name'] ?? null) ? $_POST['last_name'] : '');
$departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
$status = $_POST['account_status'] ?? '';
if (!$userId || $userId < 1 || $username === '' || strlen($username) > 100
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
    || $firstName === '' || strlen($firstName) > 100 || strlen($middleName) > 100
    || $lastName === '' || strlen($lastName) > 100 || !$departmentId || $departmentId < 1
    || !in_array($status, ['active', 'inactive', 'locked'], true)) {
    http_response_code(400);
    exit('Enter valid user details.');
}
$stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ? AND role IN ('student', 'department_head')");
$stmt->execute([$userId]);
$role = $stmt->fetchColumn();
if (!$role) {
    http_response_code(404);
    exit('User account not found.');
}
$departmentStmt = $pdo->prepare('SELECT department_id FROM departments WHERE department_id = ?');
$departmentStmt->execute([$departmentId]);
if (!$departmentStmt->fetchColumn()) {
    http_response_code(400);
    exit('Select a valid department.');
}
try {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET username = ?, email = ?, account_status = ? WHERE user_id = ?')
        ->execute([$username, $email, $status, $userId]);
    if ($role === 'student') {
        $studentNumber = trim(is_string($_POST['student_number'] ?? null) ? $_POST['student_number'] : '');
        $yearLevel = trim(is_string($_POST['year_level'] ?? null) ? $_POST['year_level'] : '');
        $section = trim(is_string($_POST['section'] ?? null) ? $_POST['section'] : '');
        if ($studentNumber === '' || strlen($studentNumber) > 50 || strlen($yearLevel) > 50 || strlen($section) > 100) {
            throw new InvalidArgumentException('Enter valid student details.');
        }
        $pdo->prepare(
            'UPDATE students SET student_number = ?, department_id = ?, year_level = ?, section = ?,
             first_name = ?, middle_name = ?, last_name = ? WHERE user_id = ?'
        )->execute([
            $studentNumber, $departmentId, $yearLevel !== '' ? $yearLevel : null,
            $section !== '' ? $section : null, $firstName, $middleName !== '' ? $middleName : null,
            $lastName, $userId,
        ]);
    } else {
        $employeeNumber = trim(is_string($_POST['employee_number'] ?? null) ? $_POST['employee_number'] : '');
        if (strlen($employeeNumber) > 50) {
            throw new InvalidArgumentException('Enter a valid employee number.');
        }
        $pdo->prepare(
            'UPDATE department_heads SET employee_number = ?, department_id = ?, first_name = ?,
             middle_name = ?, last_name = ? WHERE user_id = ?'
        )->execute([
            $employeeNumber !== '' ? $employeeNumber : null, $departmentId, $firstName,
            $middleName !== '' ? $middleName : null, $lastName, $userId,
        ]);
    }
    adminLogActivity($pdo, 'updated', 'user', $userId, 'Updated ' . $role . ' account');
    $pdo->commit();
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    exit($e->getMessage());
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
adminRedirect('/admin/students/index.php', ['notice' => 'user_updated']);
