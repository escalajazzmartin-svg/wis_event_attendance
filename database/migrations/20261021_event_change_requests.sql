CREATE TABLE event_change_requests (
    change_request_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT(20) UNSIGNED NOT NULL,
    requested_by_department_head_id BIGINT(20) UNSIGNED NOT NULL,
    request_payload LONGTEXT NOT NULL,
    request_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by_user_id BIGINT(20) UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    reviewer_remarks TEXT NULL,
    PRIMARY KEY (change_request_id),
    KEY idx_event_change_status (request_status, requested_at),
    KEY idx_event_change_event (event_id, requested_at),
    CONSTRAINT fk_event_change_event
        FOREIGN KEY (event_id) REFERENCES events (event_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_event_change_requester
        FOREIGN KEY (requested_by_department_head_id)
        REFERENCES department_heads (department_head_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_event_change_reviewer
        FOREIGN KEY (reviewed_by_user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
