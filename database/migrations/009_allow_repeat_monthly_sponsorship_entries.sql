-- Version 9: allow intentional repeat entries for the same sponsor/orphan/month.
-- Duplicate detection is informational in the entry UI; operators may save a repeat.
-- Existing monthly rows are preserved. Apply once after migration 008.
-- Keep a standalone sponsorship_id index so the foreign key remains supported
-- after removing the old unique (sponsorship_id, report_month) index.
ALTER TABLE mde_monthly_sponsorship_entries
    ADD INDEX idx_mde_monthly_sponsorship_sponsor (sponsorship_id);

ALTER TABLE mde_monthly_sponsorship_entries
    DROP INDEX uq_mde_monthly_sponsorship;

INSERT IGNORE INTO mde_schema_version (version) VALUES (9);
