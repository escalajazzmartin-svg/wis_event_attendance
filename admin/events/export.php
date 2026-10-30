<?php

require_once __DIR__ . '/../../includes/admin_context.php';
require_once __DIR__ . '/../../includes/admin_lists.php';

[$where, $params] = adminEventFilter($pdo, $_GET);
$exportEventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
if ($exportEventId !== false && $exportEventId > 0) {
    $where .= ' AND e.event_id = :event_id';
    $params['event_id'] = $exportEventId;
}
$statuses = ['pending', 'approved', 'completed', 'rejected', 'cancellation_requested', 'cancelled'];
if (is_string($_GET['status'] ?? null) && in_array($_GET['status'], $statuses, true)) {
    $where .= ' AND e.event_status = :status';
    $params['status'] = $_GET['status'];
}

$stmt = $pdo->prepare(
    'SELECT e.event_title, d.department_name, ap.academic_year, ap.semester, e.venue,
            e.start_at, e.end_at, e.event_status, dh.first_name, dh.middle_name, dh.last_name '
    . ADMIN_EVENT_FROM . ' WHERE ' . $where . ' ORDER BY e.start_at DESC, e.event_id DESC'
);
$stmt->execute($params);

adminStreamCsv(
    'events-' . date('Ymd') . '.csv',
    ['Event', 'Department', 'Academic period', 'Venue', 'Starts', 'Ends', 'Requested by', 'Status'],
    (static function () use ($stmt): Generator {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield [
                $row['event_title'],
                $row['department_name'],
                adminPeriodLabel($row['academic_year'], $row['semester']),
                $row['venue'],
                $row['start_at'],
                $row['end_at'],
                adminFullName($row['first_name'], $row['middle_name'], $row['last_name']),
                ucwords(str_replace('_', ' ', $row['event_status'])),
            ];
        }
    })()
);
