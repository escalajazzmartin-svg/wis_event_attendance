CREATE TABLE admin_activity_logs (
    activity_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT(20) UNSIGNED NOT NULL,
    action_type VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT(20) UNSIGNED NULL,
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (activity_id),
    KEY idx_admin_activity_created (created_at),
    KEY idx_admin_activity_entity (entity_type, entity_id),
    CONSTRAINT fk_admin_activity_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
