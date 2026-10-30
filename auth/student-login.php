<?php
require_once __DIR__ . '/../config/session.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/login_rate_limit.php';

$error = '';
$username = '';

if (empty($_SESSION['student_login_csrf'])) {
    $_SESSION['student_login_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(
        is_string($_POST['username'] ?? null) ? $_POST['username'] : ''
    );
    $password = is_string($_POST['password'] ?? null)
        ? $_POST['password']
        : '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!is_string($csrfToken) || !hash_equals($_SESSION['student_login_csrf'], $csrfToken)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif (
        $username === '' || strlen($username) > 100 ||
        $password === '' || strlen($password) > 4096
    ) {
        $error = 'Please enter your username and password.';
    } else {
        try {
            if (loginRateLimitIsBlocked($pdo, $username)) {
                $error = 'Too many login attempts. Please wait 15 minutes and try again.';
            } else {
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
                    loginRateLimitRecordFailure($pdo, $username);
                    $error = 'Invalid student username or password.';
                } elseif (
                    strtolower($user['account_status']) !== 'active'
                ) {
                    loginRateLimitRecordFailure($pdo, $username);
                    $error = 'Your account is inactive. Please contact the administrator.';
                } else {
                    loginRateLimitClear($pdo, $username);
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int) $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = strtolower(trim($user['role_name']));
                    $_SESSION['role_name'] = strtolower(trim($user['role_name']));
                    unset($_SESSION['student_login_csrf']);

                    $update = $pdo->prepare("
                    UPDATE users
                    SET last_login_at = NOW()
                    WHERE user_id = ?
                ");
                    $update->execute([$user['user_id']]);

                    header('Location: ../student/dashboard.php');
                    exit;
                }
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&amp;family=Roboto:wght@400;500;700&amp;display=swap">
    <link rel="icon" type="image/png" href="../assets/images/wisnoname.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/student/student.css">
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
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['student_login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
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
