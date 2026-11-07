-- Version 9: allow intentional repeat entries for the same sponsor/orphan/month.
-- Duplicate detection is informational in the entry UI; operators may save a repeat.
-- Existing monthly rows are preserved. Apply once after migration 008.
-- Replace the unique composite index with a normal sponsorship_id index in one
-- ALTER so the foreign key always has a supporting index and the change is atomic.
ALTER TABLE mde_monthly_sponsorship_entries
    ADD INDEX idx_mde_monthly_sponsorship_sponsor (sponsorship_id),
    DROP INDEX uq_mde_monthly_sponsorship;

INSERT IGNORE INTO mde_schema_version (version) VALUES (9);
