-- Version 12: allow multiple intentional monthly rows for one sponsor/orphan/report-month.
-- Apply once after migration 010. This preserves all existing rows and amounts.
-- Keep the composite sponsorship identity foreign key introduced by migration 010;
-- remove only its conflicting monthly uniqueness rule.

ALTER TABLE mde_monthly_sponsorship_entries
    DROP INDEX uq_mde_monthly_sponsor_orphan_month,
    ADD INDEX idx_mde_monthly_sponsor_orphan_month (sponsor_id, orphan_id, report_month);

INSERT IGNORE INTO mde_schema_version (version) VALUES (12);
