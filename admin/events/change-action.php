<?php

require_once __DIR__ . '/../../includes/admin_context.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
if (!adminVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid request token.');
}
$requestId = filter_var($_POST['change_request_id'] ?? null, FILTER_VALIDATE_INT);
$decision = $_POST['decision'] ?? '';
$remarks = trim(is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : '');
if (!$requestId || $requestId < 1
    || !in_array($decision, ['approved', 'rejected'], true)
    || $remarks === '' || strlen($remarks) > 2000) {
    http_response_code(400);
    exit('Provide valid change-request review details and administrator remarks.');
}

try {
    $pdo->beginTransaction();
    $requestStmt = $pdo->prepare(
        'SELECT cr.event_id, cr.request_status, cr.request_payload,
            e.department_id, e.event_status, e.start_at AS current_start_at,
            e.end_at AS current_end_at
         FROM event_change_requests cr
         INNER JOIN events e ON e.event_id = cr.event_id
         WHERE cr.change_request_id = ?
         FOR UPDATE'
    );
    $requestStmt->execute([$requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$request || $request['request_status'] !== 'pending') {
        $pdo->rollBack();
        http_response_code($request ? 409 : 404);
        exit($request ? 'This change request has already been reviewed.' : 'Change request not found.');
    }

    if ($decision === 'approved') {
        if ($request['event_status'] !== 'approved') {
            throw new RuntimeException('Only an approved event can receive schedule changes.');
        }
        $currentNow = $pdo->query('SELECT NOW()')->fetchColumn();
        $attendanceCheck = $pdo->prepare(
            'SELECT 1 FROM event_attendance WHERE event_id = ? LIMIT 1'
        );
        $attendanceCheck->execute([(int) $request['event_id']]);
        if ($request['current_start_at'] <= $currentNow || $attendanceCheck->fetchColumn() !== false) {
            throw new RuntimeException('Events cannot be changed after they begin or after attendance exists.');
        }
        $payload = json_decode($request['request_payload'], true, 512, JSON_THROW_ON_ERROR);
        $values = $payload['values'] ?? null;
        $participants = $payload['participants'] ?? null;
        $reason = $payload['reason'] ?? null;
        $periodId = is_array($values)
            ? filter_var($values['academic_period_id'] ?? null, FILTER_VALIDATE_INT)
            : false;
        if (!is_array($values) || !is_array($participants)
            || !isset($values['event_title'], $values['academic_period_id'], $values['start_at'], $values['end_at'])
            || !is_string($values['event_title']) || trim($values['event_title']) === ''
            || strlen($values['event_title']) > 200
            || $periodId === false || $periodId < 1
            || !is_string($values['start_at']) || !is_string($values['end_at'])
            || strtotime($values['start_at']) === false || strtotime($values['end_at']) === false
            || strtotime($values['end_at']) <= strtotime($values['start_at'])
            || !is_string($values['event_description'] ?? null)
            || !is_string($values['venue'] ?? null)
            || strlen($values['venue']) > 200
            || !is_string($reason) || trim($reason) === '' || strlen($reason) > 1000
            || count($participants) > 2000) {
            throw new UnexpectedValueException('The change request contains invalid event details.');
        }
        $periodStmt = $pdo->prepare(
            'SELECT start_date, end_date FROM academic_periods WHERE academic_period_id = ?'
        );
        $periodStmt->execute([$periodId]);
        $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
        if (!$period
            || substr($values['start_at'], 0, 10) < $period['start_date']
            || substr($values['end_at'], 0, 10) > $period['end_date']) {
            throw new UnexpectedValueException('The requested event dates do not fit the selected academic period.');
        }

        $studentIds = [];
        $yearLevels = [];
        $sections = [];
        foreach ($participants as $participant) {
            if (!is_array($participant) || !isset($participant['participant_type'])) {
                throw new UnexpectedValueException('The change request participant list is invalid.');
            }
            if ($participant['participant_type'] === 'student') {
                $studentId = filter_var($participant['student_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$studentId || $studentId < 1) {
                    throw new UnexpectedValueException('A requested student participant is invalid.');
                }
                $studentIds[] = $studentId;
            } elseif ($participant['participant_type'] === 'year_level') {
                if (!is_string($participant['year_level'] ?? null) || $participant['year_level'] === '') {
                    throw new UnexpectedValueException('A requested year-level participant is invalid.');
                }
                $yearLevels[] = $participant['year_level'];
            } elseif ($participant['participant_type'] === 'section') {
                if (!is_string($participant['year_level'] ?? null) || $participant['year_level'] === ''
                    || !is_string($participant['section'] ?? null) || $participant['section'] === '') {
                    throw new UnexpectedValueException('A requested section participant is invalid.');
                }
                $sections[] = [$participant['year_level'], $participant['section']];
            } else {
                throw new UnexpectedValueException('A requested participant type is invalid.');
            }
        }
        if ($studentIds !== []) {
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $studentsStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM students
                 WHERE department_id = ? AND enrollment_status = 'enrolled'
                   AND student_id IN ($placeholders)"
            );
            $studentsStmt->execute(array_merge([(int) $request['department_id']], $studentIds));
            if ((int) $studentsStmt->fetchColumn() !== count(array_unique($studentIds))) {
                throw new UnexpectedValueException('A requested student is no longer enrolled in the event department.');
            }
        }
        $groupsStmt = $pdo->prepare(
            "SELECT DISTINCT year_level, section FROM students
             WHERE department_id = ? AND enrollment_status = 'enrolled'"
        );
        $groupsStmt->execute([(int) $request['department_id']]);
        $availableYears = [];
        $availableSections = [];
        foreach ($groupsStmt->fetchAll(PDO::FETCH_ASSOC) as $group) {
            if ($group['year_level'] !== null) {
                $availableYears[(string) $group['year_level']] = true;
            }
            if ($group['year_level'] !== null && $group['section'] !== null) {
                $availableSections[base64_encode($group['year_level'] . "\0" . $group['section'])] = true;
            }
        }
        foreach ($yearLevels as $yearLevel) {
            if (!isset($availableYears[$yearLevel])) {
                throw new UnexpectedValueException('A requested year group is no longer available.');
            }
        }
        foreach ($sections as [$yearLevel, $section]) {
            if (!isset($availableSections[base64_encode($yearLevel . "\0" . $section)])) {
                throw new UnexpectedValueException('A requested section is no longer available.');
            }
        }

        $update = $pdo->prepare(
            'UPDATE events SET academic_period_id = ?, event_title = ?,
                event_description = ?, venue = ?, start_at = ?, end_at = ?
             WHERE event_id = ? AND event_status = \'approved\''
        );
        $update->execute([
            $periodId,
            trim($values['event_title']),
            is_string($values['event_description'] ?? null) && $values['event_description'] !== ''
                ? $values['event_description'] : null,
            is_string($values['venue'] ?? null) && $values['venue'] !== ''
                ? $values['venue'] : null,
            $values['start_at'],
            $values['end_at'],
            (int) $request['event_id'],
        ]);
        $attendanceRulesReset = (
            $request['current_start_at'] !== $values['start_at']
            || $request['current_end_at'] !== $values['end_at']
        );
        if ($attendanceRulesReset) {
            $pdo->prepare('DELETE FROM attendance_settings WHERE event_id = ?')
                ->execute([(int) $request['event_id']]);
            $remarks .= "\n\nAttendance time rules were cleared because the event schedule changed. Reconfigure the cutoffs before QR check-in.";
        }
        $pdo->prepare('DELETE FROM event_participants WHERE event_id = ?')->execute([(int) $request['event_id']]);
        $participantInsert = $pdo->prepare(
            'INSERT INTO event_participants
                (event_id, participant_type, student_id, year_level, section)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($participants as $participant) {
            $type = $participant['participant_type'];
            $participantInsert->execute([
                (int) $request['event_id'],
                $type,
                $type === 'student' ? (int) $participant['student_id'] : null,
                in_array($type, ['year_level', 'section'], true) ? $participant['year_level'] : null,
                $type === 'section' ? $participant['section'] : null,
            ]);
        }
        snapshotEventAttendanceRoster($pdo, (int) $request['event_id'], true);
    }

    $review = $pdo->prepare(
        'UPDATE event_change_requests
         SET request_status = ?, reviewed_by_user_id = ?, reviewed_at = NOW(),
             reviewer_remarks = ?
         WHERE change_request_id = ? AND request_status = \'pending\''
    );
    $review->execute([$decision, (int) $_SESSION['user_id'], $remarks, $requestId]);
    adminLogActivity(
        $pdo,
        $decision === 'approved' ? 'approved event schedule changes' : 'rejected event schedule changes',
        'event',
        (int) $request['event_id'],
        $remarks
    );
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

adminRedirect('/admin/events/view.php', [
    'id' => (int) $request['event_id'],
    'change_request_id' => (int) $requestId,
    'notice' => $decision === 'approved' ? 'change_approved' : 'change_rejected',
]);
