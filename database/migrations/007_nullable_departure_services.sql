-- Version 7: preserve an omitted tree service fee as NULL instead of inventing zero.
-- Apply once to ahl_el_kheir_monthly_data after migration 006.
ALTER TABLE mde_sponsor_departures
    MODIFY services_amount_snapshot BIGINT UNSIGNED NULL DEFAULT NULL;

INSERT IGNORE INTO mde_schema_version (version) VALUES (7);
