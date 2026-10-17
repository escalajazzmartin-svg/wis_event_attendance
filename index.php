<?php
session_start();

require_once __DIR__ . '/config/database.php';

$roles = [];
$error = '';
$selectedRole = '';

try {
    $stmt = $pdo->query("
        SELECT role_id, role_name, description
        FROM roles
        ORDER BY role_id ASC
    ");

    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Role loading failed: ' . $e->getMessage());
    $error = 'Unable to load roles. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedRole = $_POST['role'] ?? '';

    $validRoleIds = array_map(
        'strval',
        array_column($roles, 'role_id')
    );

    if (
        is_string($selectedRole) &&
        in_array($selectedRole, $validRoleIds, true)
    ) {
        header(
            'Location: auth/login.php?role_id=' .
                urlencode($selectedRole)
        );
        exit;
    }

    $error = 'Please select a valid role.';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>WEAMMS | Westmead International School</title>
    <link rel="icon" type="image/png" href="assets/images/wisnoname.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <main class="container">

        <header class="branding">
            <div class="school-logo">
                <img
                    src="assets/images/wisnoname.png"
                    alt="Westmead International School Logo">
            </div>

            <div class="branding-text">
                <h1>WEAMMS</h1>
            </div>
        </header>

        <p class="subtitle">
            WIS Event Attendance Monitoring and Management System
        </p>

        <h2>
            Welcome to WEAMMS
        </h2>

        <form method="POST" action="">

            <div class="select-field">
                <label for="role">Log in as</label>

                <select name="role" id="role" required>
                    <option
                        value=""
                        hidden
                        <?= $selectedRole === '' ? 'selected' : '' ?>>
                        Select Role
                    </option>

                    <?php foreach ($roles as $role): ?>
                        <option
                            value="<?= htmlspecialchars(
                                        (string) $role['role_id'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                            <?= $selectedRole === (string) $role['role_id']
                                ? 'selected'
                                : '' ?>>

                            <?= htmlspecialchars(
                                ucwords(str_replace(
                                    ['_', '-'],
                                    ' ',
                                    $role['role_name']
                                )),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <?php if ($error !== ''): ?>
                <p class="error" role="alert">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
            <?php endif; ?>

            <button
                type="submit"
                <?= empty($roles) ? 'disabled' : '' ?>>
                Proceed
            </button>

        </form>

    </main>

</body>

</html>