-- Version 10: enforce one monthly allocation per sponsor/orphan/report-month.
-- Apply once to ahl_el_kheir_monthly_data after migration 009.
--
-- REQUIRED PREFLIGHT: run database/maintenance/audit_monthly_duplicate_entries.sql.
-- It must return ZERO rows before this migration is applied.
-- Existing duplicates are never deleted or merged automatically: their amounts
-- may represent accidental repeats or intentional historical corrections.
--
-- Identity columns are stored on each monthly row and protected by a composite
-- foreign key, so they cannot disagree with the linked sponsorship record.
-- The unique key prevents a repeat for the same sponsor/orphan/month, including
-- across separate historical sponsorship episodes.

ALTER TABLE mde_sponsorships
    ADD UNIQUE KEY uq_mde_sponsorship_identity (id, sponsor_id, orphan_id);

ALTER TABLE mde_monthly_sponsorship_entries
    ADD COLUMN sponsor_id BIGINT UNSIGNED NULL AFTER sponsorship_id,
    ADD COLUMN orphan_id BIGINT UNSIGNED NULL AFTER sponsor_id;

UPDATE mde_monthly_sponsorship_entries AS me
JOIN mde_sponsorships AS rel ON rel.id = me.sponsorship_id
SET me.sponsor_id = rel.sponsor_id,
    me.orphan_id = rel.orphan_id;

ALTER TABLE mde_monthly_sponsorship_entries
    MODIFY sponsor_id BIGINT UNSIGNED NOT NULL,
    MODIFY orphan_id BIGINT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uq_mde_monthly_sponsor_orphan_month (sponsor_id, orphan_id, report_month),
    DROP FOREIGN KEY fk_mde_monthly_sponsorship,
    ADD CONSTRAINT fk_mde_monthly_sponsorship_identity
        FOREIGN KEY (sponsorship_id, sponsor_id, orphan_id)
        REFERENCES mde_sponsorships(id, sponsor_id, orphan_id)
        ON UPDATE CASCADE ON DELETE RESTRICT;

INSERT IGNORE INTO mde_schema_version (version) VALUES (10);
