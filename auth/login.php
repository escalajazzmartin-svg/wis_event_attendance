<?php
session_start();

require_once __DIR__ . '/../config/database.php';

$error = '';

// Display errors stored after redirecting from a POST request.
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
$roleId = filter_input(INPUT_GET, 'role_id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roleId = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT);
}

$selectedRole = null;

try {
    // Load the selected role from the database.
    if ($roleId !== false && $roleId !== null && $roleId > 0) {
        $stmt = $pdo->prepare("
            SELECT role_id, role_name, description
            FROM roles
            WHERE role_id = :role_id
            LIMIT 1
        ");

        $stmt->execute(['role_id' => $roleId]);
        $selectedRole = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('Role lookup failed: ' . $e->getMessage());
    $error = 'Unable to load the selected role.';
}

if (!$selectedRole && $error === '') {
    $error = 'Invalid role selected. Please return to the role selection page.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedRole) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (
        !is_string($username) || !is_string($password) ||
        $username === '' || $password === ''
    ) {
        $error = 'Please enter your username and password.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    u.user_id,
                    u.username,
                    u.email,
                    u.password_hash,
                    u.account_status,
                    u.role_id,
                    r.role_name
                FROM users u
                INNER JOIN roles r ON r.role_id = u.role_id
                WHERE u.username = :username
                LIMIT 1
            ");

            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$user ||
                !password_verify($password, $user['password_hash'])
            ) {
                $error = 'Invalid username or password.';
            } elseif ($user['account_status'] !== 'active') {
                $error = 'Your account is not active. Please contact the administrator.';
            } elseif ((int) $user['role_id'] !== (int) $selectedRole['role_id']) {
                $error = 'This account does not belong to the selected role.';
            } else {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role_id'] = (int) $user['role_id'];
                $_SESSION['role_name'] = $user['role_name'];

                // Update the user's last login.
                $update = $pdo->prepare("
                    UPDATE users
                    SET last_login_at = CURRENT_TIMESTAMP
                    WHERE user_id = :user_id
                ");

                $update->execute(['user_id' => $user['user_id']]);

                // ========================================
                // REDIRECT TO THE CORRECT DASHBOARD
                // ========================================

                $dashboards = [
                    'admin' => '../admin/dashboard.php',
                    'department_head' => '../department-head/dashboard.php',
                    'student' => '../student/dashboard.php',
                ];

                $roleName = $user['role_name'];

                if (isset($dashboards[$roleName])) {
                    $dashboardPath = __DIR__ . '/' . $dashboards[$roleName];

                    if (is_file($dashboardPath)) {
                        // Redirect using HTTP 303 to prevent POST resubmission.
                        header('Location: ' . $dashboards[$roleName], true, 303);
                        exit;
                    }
                }

                // If the dashboard does not exist, clear the login session.
                unset(
                    $_SESSION['user_id'],
                    $_SESSION['username'],
                    $_SESSION['role_id'],
                    $_SESSION['role_name']
                );

                $error = 'A dashboard for this role was not found.';
            }
        } catch (PDOException $e) {
            error_log('Login failed: ' . $e->getMessage());
            $error = 'A system error occurred. Please try again.';
        }
    }

    // Redirect after every POST to prevent form resubmission.
    if ($error !== '') {
        $_SESSION['login_error'] = $error;

        header(
            'Location: login.php?role_id=' .
                (int) $selectedRole['role_id'],
            true,
            303
        );
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | WEAMMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/images/wisnoname.png">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <main class="container">
        <header class="branding">
            <div class="school-logo">
                <img
                    src="../assets/images/wisnoname.png"
                    alt="Westmead International School Logo">
            </div>

            <div class="branding-text">
                <h1>WEAMMS</h1>
            </div>
        </header>

        <p class="subtitle">
            WIS Event Attendance Monitoring and Management System
        </p>

        <?php if ($selectedRole): ?>
            <h2>
                Login as
                <?= htmlspecialchars(
                    ucwords(str_replace(
                        ['_', '-'],
                        ' ',
                        $selectedRole['role_name']
                    )),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </h2>

            <form method="POST" action="">
                <input
                    type="hidden"
                    name="role_id"
                    value="<?= (int) $selectedRole['role_id'] ?>">

                <div class="select-field">
                    <label for="username">Username</label>
                    <input
                        type="text"
                        name="username"
                        placeholder="Enter you username"
                        id="username"
                        autocomplete="username"
                        required>
                </div>

                <div class="select-field password-field">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        name="password"
                        placeholder="Enter your password"
                        id="password"
                        autocomplete="current-password"
                        required>

                    <button
                        type="button"
                        class="toggle-password"
                        id="togglePassword"
                        aria-label="Show password">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                    </button>
                </div>

                <?php if ($error !== ''): ?>
                    <p class="error" id="errorMessage" role="alert">
                        <?= htmlspecialchars(
                            $error,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                <?php endif; ?>

                <button type="submit">Log In</button>
            </form>
        <?php else: ?>
            <p class="error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <p>
            <a href="../index.php">Back to role selection</a>
        </p>
    </main>
    <script src="../assets/js/login.js"></script>

</body>

</html>