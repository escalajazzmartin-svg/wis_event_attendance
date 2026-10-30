<?php

/**
 * Capture the eligible students when an event is approved.  Attendance and
 * reporting must not change later merely because a student transfers or is
 * moved to another section.
 */
function snapshotEventAttendanceRoster(
    PDO $pdo,
    int $eventId,
    bool $replace = false
): void
{
    if ($replace) {
        $clear = $pdo->prepare(
            'DELETE FROM event_attendance_roster WHERE event_id = ?'
        );
        $clear->execute([$eventId]);
    }

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO event_attendance_roster
            (event_id, student_id, department_id, student_number, first_name,
             middle_name, last_name, year_level, section)
         SELECT e.event_id, s.student_id, s.department_id, s.student_number,
                s.first_name, s.middle_name, s.last_name, s.year_level, s.section
         FROM events e
         INNER JOIN students s ON s.department_id = e.department_id
         WHERE e.event_id = ?
           AND s.enrollment_status = 'enrolled'
           AND (
               NOT EXISTS (
                   SELECT 1 FROM event_participants ep_any
                   WHERE ep_any.event_id = e.event_id
               )
               OR EXISTS (
                   SELECT 1 FROM event_participants ep
                   WHERE ep.event_id = e.event_id
                     AND (
                         (ep.participant_type = 'student' AND ep.student_id = s.student_id)
                         OR (ep.participant_type = 'year_level' AND ep.year_level = s.year_level)
                         OR (ep.participant_type = 'section'
                             AND ep.year_level = s.year_level AND ep.section = s.section)
                     )
               )
           )"
    );
    $stmt->execute([$eventId]);
}

/**
 * Keep the event state and sparse attendance table in sync.  A completed
 * event receives one system absent record for every rostered student who
 * did not check in, so students, appeals, and reports use the same result.
 */
function synchronizeEventLifecycle(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE events
         SET event_status = 'completed'
         WHERE event_status = 'approved' AND end_at < NOW()"
    );

    $pdo->exec(
        "INSERT IGNORE INTO event_attendance
            (event_id, student_id, attendance_status, check_in_at,
             recorded_by_user_id, record_source, remarks)
         SELECT r.event_id, r.student_id, 'absent', NULL, NULL, 'system',
                'No check-in was recorded before the event closed.'
         FROM event_attendance_roster r
         INNER JOIN events e ON e.event_id = r.event_id
         LEFT JOIN event_attendance ea
           ON ea.event_id = r.event_id AND ea.student_id = r.student_id
         WHERE e.event_status = 'completed' AND ea.attendance_id IS NULL"
    );
}
