-- Version 3: auditable edits from the data-management page.
-- Apply once to ahl_el_kheir_monthly_data only.
CREATE TABLE IF NOT EXISTS mde_change_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type ENUM('sponsor','mother','orphan','monthly_entry') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL DEFAULT 'update',
    before_data LONGTEXT NOT NULL,
    after_data LONGTEXT NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mde_change_log_entity (entity_type, entity_id),
    KEY idx_mde_change_log_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mde_schema_version (version) VALUES (3);
