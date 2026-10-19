<?php
session_start();

require_once __DIR__ . '/../config/database.php';

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            $sql = "
                SELECT
                    u.user_id,
                    u.username,
                    u.email,
                    u.password_hash,
                    u.account_status,
                    r.role_name
                FROM users u
                INNER JOIN roles r ON u.role_id = r.role_id
                WHERE u.username = ?
                  AND r.role_name = 'student'
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$user ||
                !password_verify($password, $user['password_hash'])
            ) {
                $error = 'Invalid student username or password.';
            } elseif (
                strtolower($user['account_status']) !== 'active'
            ) {
                $error = 'Your account is inactive. Please contact the administrator.';
            } else {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = strtolower(trim($user['role_name']));
                $_SESSION['role_name'] = strtolower(trim($user['role_name']));

                $update = $pdo->prepare("
                    UPDATE users
                    SET last_login_at = NOW()
                    WHERE user_id = ?
                ");
                $update->execute([$user['user_id']]);

                header('Location: ../student/dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('Student login failed: ' . $e->getMessage());
            $error = 'Unable to log in right now. Please try again later.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Login | WEAMMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/images/wisnoname.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/student.css">
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

        <h2>Student Login</h2>

        <p class="login-description">
            Welcome! Log in to access your assigned subjects
            and event attendance information.
        </p>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="select-field">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Enter your username"
                    value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                    required
                    autofocus>
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

            <button type="submit">Log In</button>
        </form>

        <footer class="login-footer">
            &copy; <?= date('Y') ?> Westmead International School
        </footer>

    </main>

    <script src="../assets/js/login.js"></script>
</body>

</html>