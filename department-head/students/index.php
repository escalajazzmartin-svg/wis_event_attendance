<?php

$pageTitle = 'Students';
$pageClass = 'dh-students-page';
$shellNavActive = 'students';
require_once __DIR__ . '/../../includes/department_head_shell_start.php';
require_once __DIR__ . '/../../includes/department_head_students.php';

const DH_STUDENTS_PER_PAGE = 10;

[$where, $params, $filters] = dhStudentFilter($_GET, $departmentId);

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page !== false && $page > 0 ? $page : 1;

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM students s WHERE ' . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / DH_STUDENTS_PER_PAGE)));
$offset = ($page - 1) * DH_STUDENTS_PER_PAGE;

$stmt = $pdo->prepare(
    'SELECT s.student_number, s.first_name, s.middle_name, s.last_name,
            s.year_level, s.section, s.enrollment_status,
            ' . DH_STUDENT_RATE_SQL . ' AS attendance_rate
     FROM students s
     WHERE ' . $where . '
     ORDER BY s.last_name, s.first_name, s.student_id
     LIMIT ' . DH_STUDENTS_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$yearStmt = $pdo->prepare(
    'SELECT DISTINCT year_level FROM students
     WHERE department_id = ? AND CHAR_LENGTH(year_level) > 0
     ORDER BY year_level'
);
$yearStmt->execute([$departmentId]);
$yearLevels = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

$statusPill = [
    'enrolled' => 'ad-pill-green',
    'inactive' => 'ad-pill-gray',
    'graduated' => 'ad-pill-blue',
    'transferred' => 'ad-pill-yellow',
];
$exportQuery = http_build_query(array_filter($filters, static fn ($v): bool => $v !== ''));
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Students</h1>
            <p>Read-only roster of students enrolled in <?= dhEscape($departmentHead['department_name']) ?>.</p>
        </div>
        <div class="ad-head-actions">
            <a class="ad-btn" href="<?= dhEscape($baseUrl) ?>/department-head/students/export.php<?= $exportQuery !== '' ? '?' . dhEscape($exportQuery) : '' ?>">Export CSV</a>
        </div>
    </header>

    <div class="ad-card">
        <form class="ad-toolbar" method="get" action="<?= dhEscape($baseUrl) ?>/department-head/students/index.php">
            <label class="ad-search">
                <?= dhIcon('search') ?>
                <span class="visually-hidden">Search students</span>
                <input type="search" name="q" value="<?= dhEscape($filters['q']) ?>" placeholder="Search name or student number">
            </label>
            <label>
                <span class="visually-hidden">Filter by year level</span>
                <select name="year" data-autosubmit>
                    <option value="">All year levels</option>
                    <?php foreach ($yearLevels as $yearLevel): ?>
                        <option value="<?= dhEscape($yearLevel) ?>" <?= $filters['year'] === $yearLevel ? 'selected' : '' ?>><?= dhEscape($yearLevel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="visually-hidden">Filter by status</span>
                <select name="status" data-autosubmit>
                    <option value="">All statuses</option>
                    <?php foreach (array_keys($statusPill) as $status): ?>
                        <option value="<?= dhEscape($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= dhEscape(ucfirst($status)) ?></option>
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
                            <th scope="col">Year &amp; section</th>
                            <th scope="col">Attendance rate</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <?php
                            $name = dhFullName($student['first_name'], $student['middle_name'], $student['last_name']);
                            $enrollment = strtolower($student['enrollment_status']);
                            $yearSection = trim(implode(' · ', array_filter([$student['year_level'], $student['section']])));
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-person">
                                        <span class="ad-avatar" aria-hidden="true"><?= dhEscape(dhInitials($name)) ?></span>
                                        <span>
                                            <strong><?= dhEscape($name) ?></strong>
                                            <small><?= dhEscape($student['student_number']) ?></small>
                                        </span>
                                    </div>
                                </td>
                                <td><?= dhEscape($yearSection !== '' ? $yearSection : '—') ?></td>
                                <td><?= $student['attendance_rate'] !== null ? (int) $student['attendance_rate'] . '%' : '—' ?></td>
                                <td><span class="ad-pill <?= $statusPill[$enrollment] ?? 'ad-pill-gray' ?>"><?= dhEscape(ucfirst($enrollment)) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php dhListFooter($page, DH_STUDENTS_PER_PAGE, $total, count($students), 'students'); ?>
    </div>
</section>

<script>
document.querySelectorAll('[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
});
</script>

<?php require __DIR__ . '/../../includes/department_head_shell_end.php'; ?>
