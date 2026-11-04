-- Version 6: allow deletion snapshots for sponsor departure records.
-- Apply once to ahl_el_kheir_monthly_data after migration 005.
ALTER TABLE mde_change_log
    MODIFY entity_type ENUM('sponsor','mother','orphan','monthly_entry','sponsorship','donation','departure') NOT NULL;

INSERT IGNORE INTO mde_schema_version (version) VALUES (6);
