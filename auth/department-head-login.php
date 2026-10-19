<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Redirect authenticated department heads.
if (
    isset($_SESSION['user_id'], $_SESSION['role_name']) &&
    $_SESSION['role_name'] === 'department_head'
) {
    header('Location: ../department-head/dashboard.php');
    exit;
}

$error = '';
$login = '';

// Generate CSRF token.
if (empty($_SESSION['department_head_login_csrf'])) {
    $_SESSION['department_head_login_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['department_head_login_csrf'], $csrfToken)
    ) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($login === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    u.user_id,
                    u.username,
                    u.password_hash,
                    u.account_status,
                    u.role_id,
                    r.role_name
                FROM users AS u
                INNER JOIN roles AS r
                    ON u.role_id = r.role_id
                WHERE (u.username = :username)
                  AND r.role_name = 'department_head'
                LIMIT 1
            ");

            $stmt->execute([
                'username' => $login,
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $user &&
                strtolower($user['account_status']) === 'active' &&
                password_verify($password, $user['password_hash'])
            ) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role_id'] = (int) $user['role_id'];
                $_SESSION['role_name'] = strtolower(trim($user['role_name']));
                $_SESSION['role'] = $_SESSION['role_name'];

                $_SESSION['user'] = [
                    'user_id' => (int) $user['user_id'],
                    'username' => $user['username'],
                    'role_name' => $_SESSION['role_name']
                ];

                $update = $pdo->prepare("
                    UPDATE users
                    SET last_login_at = NOW()
                    WHERE user_id = :user_id
                ");

                $update->execute([
                    'user_id' => $user['user_id']
                ]);

                unset($_SESSION['department_head_login_csrf']);

                header('Location: ../department-head/dashboard.php');
                exit;
            }

            $error = 'Invalid credentials or inactive department head account.';
        } catch (PDOException $e) {
            error_log('Department head login failed: ' . $e->getMessage());
            $error = 'Unable to process your login. Please try again.';
        }
    }
}

$csrfToken = $_SESSION['department_head_login_csrf'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Department Head Login | WEAMMS</title>

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <link rel="icon" type="image/png"
        href="../assets/images/wisnoname.png">

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/department-head.css">
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
            WIS Event and Attendance Monitoring and Management System
        </p>

        <h2>Department Head Login</h2>

        <p class="login-description">
            Welcome, Department Head! Sign in to manage your department,
            review assigned subjects, and monitor event attendance.
        </p>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form method="POST" action="">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="select-field">
                <label for="login">Username</label>
                <input
                    type="text"
                    id="login"
                    name="login"
                    placeholder="Enter your username"
                    value="<?= htmlspecialchars($login, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                    required
                    autofocus>
            </div>

            <div class="select-field password-field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required>

                <button
                    type="button"
                    class="toggle-password"
                    id="togglePassword"
                    aria-label="Show password"
                    aria-pressed="false">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                </button>
            </div>

            <button type="submit">Log In</button>
        </form>

        <footer class="login-footer">
            &copy; <?= date('Y') ?> Westmead International School
        </footer>

    </main>

    <script src="../assets/js/login.js"></script>
</body>

</html>