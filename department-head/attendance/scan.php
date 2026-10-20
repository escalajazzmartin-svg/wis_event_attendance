<?php

require_once __DIR__ . '/../../includes/department_head_context.php';

$requestInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$eventId = filter_var($requestInput['event_id'] ?? null, FILTER_VALIDATE_INT);
if ($eventId === false || $eventId < 1) {
    http_response_code(400);
    exit('Select a valid event to scan attendance barcodes.');
}

$eventStmt = $pdo->prepare(
    "SELECT e.event_id, e.event_title, e.start_at, e.end_at,
            e.event_status, s.present_until, s.late_until, s.absent_after
     FROM events e
     LEFT JOIN attendance_settings s ON s.event_id = e.event_id
     WHERE e.event_id = ? AND e.department_id = ?
     LIMIT 1"
);
$eventStmt->execute([$eventId, $departmentId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(404);
    exit('Event not found in your department.');
}

$error = '';
$result = null;
$barcode = '';
$isApproved = $event['event_status'] === 'approved';
$hasRules = !empty($event['present_until'])
    && !empty($event['late_until'])
    && !empty($event['absent_after']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barcode = trim(is_string($_POST['barcode'] ?? null) ? $_POST['barcode'] : '');

    if (!dhVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session token expired. Refresh this page and try again.';
    } elseif (!$isApproved) {
        $error = 'Only approved events can receive barcode attendance.';
    } elseif (!$hasRules) {
        $error = 'Configure attendance time rules for this event before scanning students.';
    } elseif (!preg_match('/\A[a-fA-F0-9]{64}\z/', $barcode)) {
        $error = 'The scanned barcode is not a valid student barcode.';
    } else {
        try {
            $pdo->beginTransaction();

            $lockedEventStmt = $pdo->prepare(
                "SELECT e.start_at, e.end_at, e.event_status,
                        s.present_until, s.late_until, s.absent_after
                 FROM events e
                 INNER JOIN attendance_settings s ON s.event_id = e.event_id
                 WHERE e.event_id = ? AND e.department_id = ?
                 FOR UPDATE"
            );
            $lockedEventStmt->execute([$eventId, $departmentId]);
            $lockedEvent = $lockedEventStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedEvent || $lockedEvent['event_status'] !== 'approved') {
                throw new DomainException('This event is no longer approved for attendance scanning.');
            }
            if (
                !(
                    $lockedEvent['start_at'] <= $lockedEvent['present_until']
                    && $lockedEvent['present_until'] < $lockedEvent['late_until']
                    && $lockedEvent['late_until'] < $lockedEvent['absent_after']
                    && $lockedEvent['absent_after'] <= $lockedEvent['end_at']
                )
            ) {
                throw new DomainException('The event attendance cutoffs are invalid. Review the attendance time rules before scanning.');
            }

            $now = $pdo->query('SELECT NOW()')->fetchColumn();
            if ($now < $lockedEvent['start_at']) {
                throw new DomainException('Barcode check-in is not open yet. It opens at the event start time.');
            }
            if ($now > $lockedEvent['end_at'] || $now > $lockedEvent['absent_after']) {
                throw new DomainException('Barcode check-in is closed for this event.');
            }

            $studentStmt = $pdo->prepare(
                "SELECT s.student_id, s.student_number, s.first_name,
                        s.middle_name, s.last_name, s.year_level, s.section
                 FROM students s
                 WHERE s.barcode_token = ?
                   AND s.department_id = ?
                   AND s.enrollment_status = 'enrolled'
                 LIMIT 1
                 FOR UPDATE"
            );
            $studentStmt->execute([$barcode, $departmentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                throw new DomainException('This barcode does not match an enrolled student in your department.');
            }

            $participantStmt = $pdo->prepare(
                "SELECT
                    NOT EXISTS (
                        SELECT 1 FROM event_participants WHERE event_id = ?
                    ) AS includes_all,
                    EXISTS (
                        SELECT 1 FROM event_participants ep
                        WHERE ep.event_id = ?
                          AND (
                            (ep.participant_type = 'student' AND ep.student_id = ?)
                            OR (ep.participant_type = 'year_level' AND ep.year_level = ?)
                            OR (ep.participant_type = 'section'
                                AND ep.year_level = ? AND ep.section = ?)
                          )
                    ) AS explicitly_included"
            );
            $participantStmt->execute([
                $eventId,
                $eventId,
                $student['student_id'],
                $student['year_level'],
                $student['year_level'],
                $student['section'],
            ]);
            $participant = $participantStmt->fetch(PDO::FETCH_ASSOC);
            if (!(int) $participant['includes_all'] && !(int) $participant['explicitly_included']) {
                throw new DomainException('This student is not listed as a participant for this event.');
            }

            $existingStmt = $pdo->prepare(
                'SELECT attendance_id FROM event_attendance
                 WHERE event_id = ? AND student_id = ?
                 FOR UPDATE'
            );
            $existingStmt->execute([$eventId, $student['student_id']]);
            if ($existingStmt->fetchColumn() !== false) {
                throw new DomainException('This student already has an attendance record for the event.');
            }

            if ($now <= $lockedEvent['present_until']) {
                $attendanceStatus = 'present';
            } elseif ($now <= $lockedEvent['late_until']) {
                $attendanceStatus = 'late';
            } else {
                $attendanceStatus = 'absent';
            }

            $insert = $pdo->prepare(
                "INSERT INTO event_attendance
                    (event_id, student_id, attendance_status, check_in_at,
                     recorded_by_user_id, record_source, remarks)
                 VALUES (?, ?, ?, NOW(), ?, 'barcode', NULL)"
            );
            $insert->execute([
                $eventId,
                $student['student_id'],
                $attendanceStatus,
                $_SESSION['user_id'],
            ]);
            $attendanceId = (int) $pdo->lastInsertId();
            $audit = $pdo->prepare(
                'INSERT INTO attendance_audit_logs
                    (attendance_id, event_id, actor_user_id, action_type,
                     old_value, new_value, notes)
                 VALUES (?, ?, ?, ?, NULL, ?, ?)'
            );
            $audit->execute([
                $attendanceId,
                $eventId,
                $_SESSION['user_id'],
                'barcode_scan',
                json_encode([
                    'attendance_status' => $attendanceStatus,
                    'check_in_at' => $now,
                    'record_source' => 'barcode',
                ], JSON_THROW_ON_ERROR),
                'Barcode attendance submitted by Department Head.',
            ]);

            $pdo->commit();
            $result = [
                'name' => trim(
                    $student['first_name'] . ' ' .
                    ($student['middle_name'] ? $student['middle_name'] . ' ' : '') .
                    $student['last_name']
                ),
                'student_number' => $student['student_number'],
                'status' => $attendanceStatus,
            ];
            $barcode = '';
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
            $barcode = '';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                $error = 'This student already has an attendance record for the event.';
                $barcode = '';
            } else {
                throw $e;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

$recentStmt = $pdo->prepare(
    "SELECT ea.attendance_status, ea.check_in_at,
            s.student_number, s.first_name, s.middle_name, s.last_name,
            u.username AS recorded_by
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN students s ON s.student_id = ea.student_id
     LEFT JOIN users u ON u.user_id = ea.recorded_by_user_id
     WHERE ea.event_id = ? AND e.department_id = ?
       AND s.department_id = ? AND ea.record_source = 'barcode'
     ORDER BY ea.check_in_at DESC, ea.attendance_id DESC
     LIMIT 15"
);
$recentStmt->execute([$eventId, $departmentId, $departmentId]);
$recentScans = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Scan Attendance Barcode';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';
?>

<section class="dashboard-section management-page barcode-scan-page">
    <div class="section-heading">
        <div>
            <h2>Scan Attendance Barcode</h2>
            <p><?= dhEscape($event['event_title']) ?> · <?= dhEscape(date('M j, Y', strtotime($event['start_at']))) ?></p>
        </div>
        <a class="management-button management-button-secondary" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/records.php?event_id=<?= (int) $eventId ?>">View attendance records</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="management-alert" role="alert"><?= dhEscape($error) ?></div>
    <?php endif; ?>
    <?php if ($result !== null): ?>
        <div class="management-alert management-alert-success barcode-scan-result" role="status">
            Recorded <?= dhEscape(ucfirst($result['status'])) ?>:
            <strong><?= dhEscape($result['name']) ?></strong>
            · <?= dhEscape($result['student_number']) ?>
        </div>
    <?php endif; ?>

    <div class="department-panel barcode-scan-panel">
        <p>
            <strong>Event status:</strong>
            <span class="status-pill status-<?= dhEscape($event['event_status']) ?>"><?= dhEscape(ucwords(str_replace('_', ' ', $event['event_status']))) ?></span>
        </p>
        <?php if (!$isApproved): ?>
            <div class="management-alert">Attendance scanning is available only for approved events.</div>
        <?php elseif (!$hasRules): ?>
            <div class="management-alert">
                Set this event's present, late, and absent cutoffs before scanning.
                <a href="<?= dhEscape($baseUrl) ?>/department-head/attendance-settings/index.php">Configure attendance rules</a>
            </div>
        <?php else: ?>
            <dl class="management-detail-grid barcode-cutoff-summary">
                <div><dt>Present through</dt><dd><?= dhEscape(date('M j, g:i A', strtotime($event['present_until']))) ?></dd></div>
                <div><dt>Late through</dt><dd><?= dhEscape(date('M j, g:i A', strtotime($event['late_until']))) ?></dd></div>
                <div><dt>Absent cutoff / check-in closes</dt><dd><?= dhEscape(date('M j, g:i A', strtotime($event['absent_after']))) ?></dd></div>
            </dl>
            <p class="barcode-scan-guidance">
                Scan a student's barcode using a USB/Bluetooth barcode reader. The reader should enter the student's 64-character barcode token and send Enter. Scans through the present cutoff are Present; scans through the late cutoff are Late; scans after that through the absent cutoff are recorded Absent. Scanning closes after the absent cutoff. Duplicate scans are not recorded twice.
            </p>
            <form class="barcode-scan-form" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/attendance/scan.php?event_id=<?= (int) $eventId ?>">
                <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                <label for="barcode">Student barcode</label>
                <input
                    id="barcode"
                    name="barcode"
                    type="password"
                    inputmode="text"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    maxlength="64"
                    pattern="[a-fA-F0-9]{64}"
                    required
                    autofocus
                    value="<?= dhEscape($barcode) ?>">
                <button class="management-button" type="submit">Record scan</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="barcode-scan-monitor">
        <div class="attendance-monitor-panel-heading">
            <div>
                <h3>Recent barcode submissions</h3>
                <p>Latest 15 scans for this department event, including the staff account that recorded each scan.</p>
            </div>
            <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/records.php?event_id=<?= (int) $eventId ?>">Open full roster</a>
        </div>
        <?php if ($recentScans === []): ?>
            <div class="department-panel empty-state"><strong>No barcode scans yet</strong><p>Successful student scans will be listed here.</p></div>
        <?php else: ?>
            <div class="management-table-wrap">
                <table class="management-table">
                    <thead><tr><th scope="col">Student</th><th scope="col">Scanned at</th><th scope="col">Status</th><th scope="col">Recorded by</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentScans as $scan): ?>
                            <?php $scanName = trim($scan['first_name'] . ' ' . ($scan['middle_name'] ? $scan['middle_name'] . ' ' : '') . $scan['last_name']); ?>
                            <tr>
                                <td data-label="Student"><strong><?= dhEscape($scanName) ?></strong><span><?= dhEscape($scan['student_number']) ?></span></td>
                                <td data-label="Scanned at"><?= dhEscape(date('M j, Y · g:i:s A', strtotime($scan['check_in_at']))) ?></td>
                                <td data-label="Status"><span class="status-pill status-<?= dhEscape($scan['attendance_status']) ?>"><?= dhEscape(ucfirst($scan['attendance_status'])) ?></span></td>
                                <td data-label="Recorded by"><?= dhEscape($scan['recorded_by'] ?: 'Unknown user') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

<script>
    const barcodeInput = document.getElementById('barcode');
    if (barcodeInput) {
        barcodeInput.focus();
        barcodeInput.addEventListener('focus', () => barcodeInput.select());
    }
</script>

</main>
</body>
</html>
