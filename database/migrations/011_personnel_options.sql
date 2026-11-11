-- Version 11: persist operator and supervisor dropdown additions independently.
-- Apply once to ahl_el_kheir_monthly_data after migration 010.

CREATE TABLE mde_personnel_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role ENUM('operator','supervisor') NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    normalized_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mde_personnel_role_name (role, normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mde_schema_version (version) VALUES (11);
