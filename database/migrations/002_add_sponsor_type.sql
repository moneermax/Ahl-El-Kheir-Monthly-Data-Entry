-- Apply once to the dedicated ahl_el_kheir_monthly_data database.
-- Existing sponsor rows are classified as individual by default.
ALTER TABLE mde_sponsors
    ADD COLUMN sponsor_type ENUM('individual','tree') NOT NULL DEFAULT 'individual' AFTER file_number;

INSERT IGNORE INTO mde_schema_version (version) VALUES (2);
