<?php

$adminPageTitle = 'Attendance Appeals';
require_once __DIR__ . '/../../includes/admin_layout_start.php';
require_once __DIR__ . '/../../includes/admin_lists.php';

const APPEALS_PER_PAGE = 10;

$statusTabs = [
    'pending' => ['Pending', 'ad-pill-yellow'],
    'approved' => ['Approved', 'ad-pill-green'],
    'rejected' => ['Rejected', 'ad-pill-red'],
    'withdrawn' => ['Withdrawn', 'ad-pill-gray'],
];
$statusFilter = is_string($_GET['status'] ?? null) && isset($statusTabs[$_GET['status']]) ? $_GET['status'] : '';

$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$departmentId = filter_var($_GET['department_id'] ?? null, FILTER_VALIDATE_INT);
$departmentId = $departmentId !== false && $departmentId > 0 ? $departmentId : 0;

$from = 'FROM attendance_appeals aa
    INNER JOIN event_attendance ea ON ea.attendance_id = aa.attendance_id
    INNER JOIN events e ON e.event_id = ea.event_id
    INNER JOIN departments d ON d.department_id = e.department_id
    INNER JOIN event_attendance_roster r
        ON r.event_id = ea.event_id AND r.student_id = aa.student_id';

$where = '1 = 1';
$params = [];
if ($q !== '') {
    $where .= " AND (e.event_title LIKE :q_event OR r.student_number LIKE :q_number
                OR r.first_name LIKE :q_first OR r.last_name LIKE :q_last
                OR CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name) LIKE :q_full)";
    foreach (['q_event', 'q_number', 'q_first', 'q_last', 'q_full'] as $key) {
        $params[$key] = '%' . $q . '%';
    }
}
if ($departmentId > 0) {
    $where .= ' AND e.department_id = :department_id';
    $params['department_id'] = $departmentId;
}

$departments = $pdo->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);

// Tab counts reflect the search and department that are selected.
$countStmt = $pdo->prepare('SELECT aa.appeal_status, COUNT(*) ' . $from . ' WHERE ' . $where . ' GROUP BY aa.appeal_status');
$countStmt->execute($params);
$statusCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$allCount = array_sum(array_map('intval', $statusCounts));

$listWhere = $where;
$listParams = $params;
if ($statusFilter !== '') {
    $listWhere .= ' AND aa.appeal_status = :status';
    $listParams['status'] = $statusFilter;
}
$total = $statusFilter === '' ? $allCount : (int) ($statusCounts[$statusFilter] ?? 0);
[$page, $offset] = adminPage(APPEALS_PER_PAGE);
[$page, $offset] = adminClampPage($page, APPEALS_PER_PAGE, $total);

$stmt = $pdo->prepare(
    'SELECT aa.appeal_id, aa.appeal_reason, aa.appeal_status, aa.submitted_at,
            e.event_title, e.start_at, d.department_name,
            r.student_number, r.first_name, r.middle_name, r.last_name '
    . $from . ' WHERE ' . $listWhere . '
     ORDER BY FIELD(aa.appeal_status, \'pending\', \'approved\', \'rejected\', \'withdrawn\'),
              aa.submitted_at DESC, aa.appeal_id DESC
     LIMIT ' . APPEALS_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($listParams);
$appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sharedQuery = array_filter([
    'q' => $q,
    'department_id' => $departmentId ?: '',
], static fn ($v): bool => $v !== '' && $v !== 0);
$tabHref = static fn (string $status): string => '?' . http_build_query($status === '' ? $sharedQuery : $sharedQuery + ['status' => $status]);
?>

<section class="ad-page">
    <header class="ad-head">
        <div>
            <h1>Attendance Appeals</h1>
            <p>View student attendance appeals across all departments. Decisions are made by each department head.</p>
        </div>
    </header>

    <div class="ad-card">
        <nav class="ad-tabs" aria-label="Filter appeals by status">
            <a href="<?= adminEscape($tabHref('')) ?>" <?= $statusFilter === '' ? 'aria-current="page"' : '' ?>>All <?= $allCount ?></a>
            <?php foreach ($statusTabs as $status => [$label]): ?>
                <a href="<?= adminEscape($tabHref($status)) ?>" <?= $statusFilter === $status ? 'aria-current="page"' : '' ?>><?= adminEscape($label) ?> <?= (int) ($statusCounts[$status] ?? 0) ?></a>
            <?php endforeach; ?>
        </nav>

        <form class="ad-toolbar" method="get" action="<?= adminEscape($adminBaseUrl) ?>/admin/appeals/index.php">
            <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= adminEscape($statusFilter) ?>"><?php endif; ?>
            <label class="ad-search">
                <?= adminIcon('search') ?>
                <span class="visually-hidden">Search appeals</span>
                <input type="search" name="q" value="<?= adminEscape($q) ?>" placeholder="Search student name, number or event">
            </label>
            <label>
                <span class="visually-hidden">Filter by department</span>
                <select name="department_id" data-autosubmit>
                    <option value="">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['department_id'] ?>" <?= $departmentId === (int) $department['department_id'] ? 'selected' : '' ?>><?= adminEscape($department['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="visually-hidden" type="submit">Apply filters</button>
        </form>

        <?php if ($appeals === []): ?>
            <div class="ad-empty">
                <strong>No appeals found</strong>
                Try a different tab, search or department.
            </div>
        <?php else: ?>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th scope="col">Student</th>
                            <th scope="col">Event</th>
                            <th scope="col">Department</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appeals as $appeal): ?>
                            <?php $status = $appeal['appeal_status']; ?>
                            <tr>
                                <td>
                                    <span class="ad-strong"><?= adminEscape(adminFullName($appeal['first_name'], $appeal['middle_name'], $appeal['last_name'])) ?></span>
                                    <div class="ad-muted"><?= adminEscape($appeal['student_number']) ?></div>
                                </td>
                                <td>
                                    <?= adminEscape($appeal['event_title']) ?>
                                    <div class="ad-muted"><?= adminEscape(date('M j, Y', strtotime($appeal['start_at']))) ?></div>
                                </td>
                                <td><?= adminEscape($appeal['department_name']) ?></td>
                                <td><?= adminEscape(mb_strimwidth($appeal['appeal_reason'], 0, 100, '…', 'UTF-8')) ?></td>
                                <td><?= adminEscape(date('M j, Y · g:i A', strtotime($appeal['submitted_at']))) ?></td>
                                <td><span class="ad-pill <?= adminEscape($statusTabs[$status][1] ?? 'ad-pill-gray') ?>"><?= adminEscape($statusTabs[$status][0] ?? ucfirst($status)) ?></span></td>
                                <td><a class="ad-link" href="<?= adminEscape($adminBaseUrl) ?>/admin/appeals/view.php?id=<?= (int) $appeal['appeal_id'] ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php adminListFooter($page, APPEALS_PER_PAGE, $total, count($appeals), 'appeals'); ?>
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
