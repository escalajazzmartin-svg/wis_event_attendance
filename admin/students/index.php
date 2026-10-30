<?php

$adminPageTitle = 'Students';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
require_once __DIR__ . '/../../includes/admin_lists.php';

const STUDENTS_PER_PAGE = 10;

[$where, $params, $filters] = adminStudentFilter($_GET);
[$page, $offset] = adminPage(STUDENTS_PER_PAGE);

$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $pdo->prepare('SELECT COUNT(*) ' . ADMIN_STUDENT_FROM . ' WHERE ' . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
[$page, $offset] = adminClampPage($page, STUDENTS_PER_PAGE, $total);

$stmt = $pdo->prepare(
    'SELECT u.user_id, u.account_status, s.student_number, s.year_level, s.section,
            s.first_name, s.middle_name, s.last_name, d.department_name '
    . ADMIN_STUDENT_FROM . ' WHERE ' . $where . '
     ORDER BY s.last_name, s.first_name, s.student_id
     LIMIT ' . STUDENTS_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notice = match ($_GET['notice'] ?? '') {
    'user_created' => 'Student account created.',
    'user_updated' => 'Student details saved.',
    'status_updated' => 'Account status updated.',
    'barcode_regenerated' => 'QR code regenerated. The old code no longer works.',
    default => '',
};
$exportQuery = http_build_query(array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0));
$statusPill = ['active' => 'ad-pill-green', 'inactive' => 'ad-pill-gray', 'locked' => 'ad-pill-red'];
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Students</h1>
            <p>Manage student accounts, departments, and enrollment status.</p>
        </div>
        <div class="ad-head-actions">
            <a class="ad-btn" href="<?= adminEscape($adminBaseUrl) ?>/admin/students/export.php<?= $exportQuery !== '' ? '?' . adminEscape($exportQuery) : '' ?>">Export CSV</a>
            <a class="ad-btn ad-btn-solid" href="<?= adminEscape($adminBaseUrl) ?>/admin/students/create.php?role=student"><?= adminIcon('plus') ?>Add student</a>
        </div>
    </header>

    <?php if (isset($_SESSION['admin_temporary_password'])): ?>
        <div class="ad-notice" role="status">
            <strong>Temporary password (shown once):</strong>
            <code><?= adminEscape($_SESSION['admin_temporary_password']) ?></code>
            Copy it and give it to the account owner securely.
        </div>
        <?php unset($_SESSION['admin_temporary_password']); ?>
    <?php endif; ?>
    <?php if ($notice !== '' && ($_GET['notice'] ?? '') !== 'password_reset'): ?>
        <div class="ad-notice" role="status"><?= adminEscape($notice) ?></div>
    <?php endif; ?>

    <div class="ad-card">
        <form class="ad-toolbar" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/index.php">
            <label class="ad-search">
                <?= adminIcon('search') ?>
                <span class="visually-hidden">Search students</span>
                <input type="search" name="q" value="<?= adminEscape($filters['q']) ?>" placeholder="Search name, student number or email">
            </label>
            <label>
                <span class="visually-hidden">Filter by department</span>
                <select name="department_id" data-autosubmit>
                    <option value="">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['department_id'] ?>" <?= $filters['department_id'] === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="visually-hidden">Filter by status</span>
                <select name="status" data-autosubmit>
                    <option value="">All statuses</option>
                    <?php foreach (['active', 'inactive', 'locked'] as $status): ?>
                        <option value="<?= $status ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="visually-hidden" type="submit">Apply filters</button>
        </form>

        <?php if ($students === []): ?>
            <div class="ad-empty">
                <strong>No students found</strong>
                Try a different search or clear the filters.
            </div>
        <?php else: ?>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Student</th>
                            <th scope="col">Department</th>
                            <th scope="col">Year &amp; section</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <?php
                            $name = adminFullName($student['first_name'], $student['middle_name'], $student['last_name']);
                            $accountStatus = strtolower($student['account_status']);
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-person">
                                        <span class="ad-avatar" aria-hidden="true"><?= adminEscape(adminInitials($name)) ?></span>
                                        <span>
                                            <strong><?= adminEscape($name) ?></strong>
                                            <small><?= adminEscape($student['student_number']) ?></small>
                                        </span>
                                    </div>
                                </td>
                                <td><?= adminEscape($student['department_name']) ?></td>
                                <td><?= adminEscape(adminYearSection($student['year_level'], $student['section'])) ?></td>
                                <td><span class="ad-pill <?= $statusPill[$accountStatus] ?? 'ad-pill-gray' ?>"><?= adminEscape(ucfirst($accountStatus)) ?></span></td>
                                <td>
                                    <div class="ad-actions">
                                        <a class="ad-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/students/edit.php?id=<?= (int) $student['user_id'] ?>">Edit</a>
                                        <span class="sep" aria-hidden="true">·</span>
                                        <form method="post" action="<?= adminEscape($adminBaseUrl) ?>/admin/students/reset-password.php" onsubmit="return confirm('Reset this student\'s password?');">
                                            <input type="hidden" name="csrf_token" value="<?= adminEscape($adminCsrfToken) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $student['user_id'] ?>">
                                            <input type="hidden" name="return" value="students">
                                            <button class="ad-link" type="submit">Reset password</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php adminListFooter($page, STUDENTS_PER_PAGE, $total, count($students), 'students'); ?>
    </div>
</section>

<script>
document.querySelectorAll('[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

</main>
</body>
</html>
