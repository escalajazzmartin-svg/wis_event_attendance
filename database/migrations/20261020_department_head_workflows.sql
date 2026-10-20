ALTER TABLE departments
    ADD COLUMN department_head_can_edit TINYINT(1) NOT NULL DEFAULT 0
        AFTER is_active,
    ADD COLUMN department_head_can_edit_attendance TINYINT(1) NOT NULL DEFAULT 0
        AFTER department_head_can_edit;

ALTER TABLE events
    MODIFY COLUMN event_status
        ENUM(
            'pending',
            'approved',
            'rejected',
            'cancelled',
            'cancellation_requested',
            'completed'
        ) NOT NULL DEFAULT 'pending',
    ADD COLUMN cancellation_requested_at DATETIME NULL
        AFTER admin_remarks,
    ADD COLUMN cancellation_requested_by BIGINT(20) UNSIGNED NULL
        AFTER cancellation_requested_at,
    ADD COLUMN cancellation_request_remarks TEXT NULL
        AFTER cancellation_requested_by,
    ADD CONSTRAINT fk_events_cancellation_requester
        FOREIGN KEY (cancellation_requested_by)
        REFERENCES department_heads (department_head_id)
        ON UPDATE CASCADE;

CREATE TABLE event_participants (
    event_participant_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT(20) UNSIGNED NOT NULL,
    participant_type ENUM('student', 'year_level', 'section') NOT NULL,
    student_id BIGINT(20) UNSIGNED NULL,
    year_level VARCHAR(50) NULL,
    section VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_participant_id),
    KEY idx_event_participants_event (event_id),
    KEY idx_event_participants_student (student_id),
    CONSTRAINT fk_event_participants_event
        FOREIGN KEY (event_id) REFERENCES events (event_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_event_participants_student
        FOREIGN KEY (student_id) REFERENCES students (student_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_event_participants_target CHECK (
        (participant_type = 'student' AND student_id IS NOT NULL
            AND year_level IS NULL AND section IS NULL)
        OR
        (participant_type = 'year_level' AND student_id IS NULL
            AND year_level IS NOT NULL AND section IS NULL)
        OR
        (participant_type = 'section' AND student_id IS NULL
            AND year_level IS NOT NULL AND section IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
