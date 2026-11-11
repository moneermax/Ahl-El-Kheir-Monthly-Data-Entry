-- Version 13: restore the original sponsor/orphan/report-month uniqueness rule.
-- Migration 012 temporarily replaced this unique key with a non-unique index.
-- Preflight first: confirm there are no duplicate sponsor/orphan/report-month groups.
-- This migration does not delete, merge, or rewrite monthly financial rows.
-- If duplicates exist, the UNIQUE index creation fails safely; reconcile them manually first.

ALTER TABLE mde_monthly_sponsorship_entries
    DROP INDEX idx_mde_monthly_sponsor_orphan_month,
    ADD UNIQUE KEY uq_mde_monthly_sponsor_orphan_month (sponsor_id, orphan_id, report_month);

INSERT IGNORE INTO mde_schema_version (version) VALUES (13);
