-- Version 8: preserve the sponsor name exactly as reviewed for each departure record.
-- Apply once to ahl_el_kheir_monthly_data after migration 007.
ALTER TABLE mde_sponsor_departures
    ADD COLUMN sponsor_name_snapshot VARCHAR(255) NULL AFTER sponsor_id;

UPDATE mde_sponsor_departures d
JOIN mde_sponsors s ON s.id = d.sponsor_id
SET d.sponsor_name_snapshot = s.sponsor_name
WHERE d.sponsor_name_snapshot IS NULL OR d.sponsor_name_snapshot = '';

INSERT IGNORE INTO mde_schema_version (version) VALUES (8);
