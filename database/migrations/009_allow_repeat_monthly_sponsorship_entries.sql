-- Version 9: allow intentional repeat entries for the same sponsor/orphan/month.
-- Duplicate detection is informational in the entry UI; operators may save a repeat.
-- Existing monthly rows are preserved. Apply once after migration 008.
ALTER TABLE mde_monthly_sponsorship_entries
    DROP INDEX uq_mde_monthly_sponsorship;

INSERT IGNORE INTO mde_schema_version (version) VALUES (9);
