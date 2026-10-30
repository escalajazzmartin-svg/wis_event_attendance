<?php

require_once __DIR__ . '/../../includes/department_head_context.php';

$requestInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$eventId = filter_var($requestInput['event_id'] ?? null, FILTER_VALIDATE_INT);
if ($eventId === false || $eventId < 1) {
    http_response_code(400);
    exit('Select a valid event to scan attendance QR codes.');
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
        $error = 'Only approved events can receive QR attendance.';
    } elseif (!$hasRules) {
        $error = 'Configure attendance time rules for this event before scanning students.';
    } elseif (!preg_match('/\A[a-fA-F0-9]{64}\z/', $barcode)) {
        $error = 'The scanned code is not a valid student QR code.';
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
                throw new DomainException('QR check-in is not open yet. It opens at the event start time.');
            }
            if ($now > $lockedEvent['end_at'] || $now > $lockedEvent['absent_after']) {
                throw new DomainException('QR check-in is closed for this event.');
            }

            $studentStmt = $pdo->prepare(
                "SELECT s.student_id, s.student_number, s.first_name,
                        s.middle_name, s.last_name, s.year_level, s.section
                 FROM students s
                 INNER JOIN users u ON u.user_id = s.user_id
                 WHERE s.barcode_token = ?
                   AND s.enrollment_status = 'enrolled'
                   AND u.account_status = 'active'
                 LIMIT 1
                 FOR UPDATE"
            );
            $studentStmt->execute([$barcode]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                throw new DomainException('This QR code does not match an enrolled student in your department.');
            }

            $rosterStmt = $pdo->prepare(
                'SELECT 1 FROM event_attendance_roster
                 WHERE event_id = ? AND student_id = ?
                 FOR UPDATE'
            );
            $rosterStmt->execute([$eventId, $student['student_id']]);
            if ($rosterStmt->fetchColumn() === false) {
                throw new DomainException('This student was not eligible when the event was approved.');
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
                'QR attendance submitted by Department Head.',
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
            r.student_number, r.first_name, r.middle_name, r.last_name,
            u.username AS recorded_by
     FROM event_attendance ea
     INNER JOIN events e ON e.event_id = ea.event_id
     INNER JOIN event_attendance_roster r
        ON r.event_id = ea.event_id AND r.student_id = ea.student_id
     LEFT JOIN users u ON u.user_id = ea.recorded_by_user_id
     WHERE ea.event_id = ? AND e.department_id = ?
       AND ea.record_source = 'barcode'
     ORDER BY ea.check_in_at DESC, ea.attendance_id DESC
     LIMIT 15"
);
$recentStmt->execute([$eventId, $departmentId]);
$recentScans = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Scan Attendance QR Code';
require_once __DIR__ . '/../../includes/department_head_layout_start.php';
?>

<section class="dashboard-section management-page barcode-scan-page">
    <div class="section-heading">
        <div>
            <h2>Scan Attendance QR Code</h2>
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
                Scan a student's QR code with this device's camera, or with a USB/Bluetooth scanner that types the student's 64-character code and sends Enter. Scans through the present cutoff are Present; scans through the late cutoff are Late; scans after that through the absent cutoff are recorded Absent. Scanning closes after the absent cutoff. Duplicate scans are not recorded twice.
            </p>
            <div class="qr-camera" id="qrCamera">
                <div class="qr-camera-view" id="qrCameraView" hidden>
                    <video id="qrVideo" playsinline muted></video>
                    <span class="qr-camera-frame" aria-hidden="true"></span>
                </div>
                <p class="qr-camera-status" id="qrCameraStatus" role="status">Camera is off.</p>
                <div class="qr-camera-actions">
                    <button class="management-button" type="button" id="qrCameraToggle">Start camera</button>
                </div>
            </div>
            <form class="barcode-scan-form" id="qrScanForm" method="post" action="<?= dhEscape($baseUrl) ?>/department-head/attendance/scan.php?event_id=<?= (int) $eventId ?>">
                <input type="hidden" name="csrf_token" value="<?= dhEscape(dhCsrfToken()) ?>">
                <label for="barcode">Or enter / scan the student code</label>
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
                    value="<?= dhEscape($barcode) ?>">
                <button class="management-button" type="submit">Record scan</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="barcode-scan-monitor">
        <div class="attendance-monitor-panel-heading">
            <div>
                <h3>Recent QR submissions</h3>
                <p>Latest 15 scans for this department event, including the staff account that recorded each scan.</p>
            </div>
            <a class="management-link" href="<?= dhEscape($baseUrl) ?>/department-head/attendance/records.php?event_id=<?= (int) $eventId ?>">Open full roster</a>
        </div>
        <?php if ($recentScans === []): ?>
            <div class="department-panel empty-state"><strong>No QR scans yet</strong><p>Successful student scans will be listed here.</p></div>
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

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script>
    (() => {
        const form = document.getElementById('qrScanForm');
        const input = document.getElementById('barcode');
        const toggle = document.getElementById('qrCameraToggle');
        const view = document.getElementById('qrCameraView');
        const video = document.getElementById('qrVideo');
        const statusEl = document.getElementById('qrCameraStatus');
        if (!form || !input || !toggle || !video) {
            return;
        }

        const PREF_KEY = 'weamms_qr_camera';
        const TOKEN_PATTERN = /^[a-fA-F0-9]{64}$/;
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { willReadFrequently: true });
        let stream = null;
        let frameRequest = null;
        let submitted = false;

        const setStatus = (message) => { statusEl.textContent = message; };
        const remember = (on) => {
            try { window.localStorage.setItem(PREF_KEY, on ? '1' : '0'); } catch (error) { /* storage unavailable */ }
        };

        const stopCamera = () => {
            if (frameRequest !== null) {
                window.cancelAnimationFrame(frameRequest);
                frameRequest = null;
            }
            if (stream) {
                stream.getTracks().forEach((track) => track.stop());
                stream = null;
            }
            video.srcObject = null;
            view.hidden = true;
            toggle.textContent = 'Start camera';
        };

        const tick = () => {
            if (!stream || submitted) {
                return;
            }
            if (video.readyState === video.HAVE_ENOUGH_DATA && video.videoWidth > 0) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                const image = context.getImageData(0, 0, canvas.width, canvas.height);
                const code = window.jsQR ? window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' }) : null;
                if (code) {
                    const value = code.data.trim();
                    if (TOKEN_PATTERN.test(value)) {
                        submitted = true;
                        input.value = value;
                        setStatus('QR code read. Recording attendance…');
                        stopCamera();
                        form.submit();
                        return;
                    }
                    setStatus('That QR code is not a WEAMMS student code. Keep scanning.');
                }
            }
            frameRequest = window.requestAnimationFrame(tick);
        };

        const startCamera = async () => {
            if (!window.jsQR) {
                setStatus('The QR reader could not be loaded. Use the code field below instead.');
                return;
            }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                setStatus('Camera access needs HTTPS (or localhost). Use the code field below instead.');
                return;
            }
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false
                });
                video.srcObject = stream;
                await video.play();
                view.hidden = false;
                toggle.textContent = 'Stop camera';
                setStatus('Point the camera at the student\'s QR code.');
                remember(true);
                frameRequest = window.requestAnimationFrame(tick);
            } catch (error) {
                stopCamera();
                setStatus('Camera unavailable or permission denied. Use the code field below instead.');
            }
        };

        toggle.addEventListener('click', () => {
            if (stream) {
                stopCamera();
                remember(false);
                setStatus('Camera is off.');
            } else {
                startCamera();
            }
        });

        window.addEventListener('pagehide', stopCamera);

        input.addEventListener('focus', () => input.select());

        // Keep scanning after each recorded student: restart the camera if it was on.
        let wasOn = false;
        try { wasOn = window.localStorage.getItem(PREF_KEY) === '1'; } catch (error) { /* ignore */ }
        if (wasOn) {
            startCamera();
        } else {
            input.focus();
        }
    })();
</script>

</main>
</body>
</html>
