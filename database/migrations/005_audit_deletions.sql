-- Version 5: audit deletion snapshots for sponsorship relationships and donations.
-- Apply once to ahl_el_kheir_monthly_data after migration 004.
ALTER TABLE mde_change_log
    MODIFY entity_type ENUM('sponsor','mother','orphan','monthly_entry','sponsorship','donation') NOT NULL;

INSERT IGNORE INTO mde_schema_version (version) VALUES (5);
