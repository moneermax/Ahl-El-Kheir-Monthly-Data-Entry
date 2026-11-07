-- Version 10: enforce one monthly financial allocation per sponsor/orphan relationship and report month.
-- Apply once to ahl_el_kheir_monthly_data after migration 009.
--
-- SAFETY: the diagnostic SELECT must return zero rows before the ALTER can succeed.
-- Existing repeated rows are NOT deleted or merged automatically because their
-- amounts may represent either accidental duplicates or intentional corrections.
-- Review and reconcile any returned rows before retrying this migration.

SELECT
    sp.id AS sponsor_id,
    sp.sponsor_name,
    mo.mother_name,
    o.orphan_name,
    me.report_month,
    COUNT(*) AS monthly_row_count,
    GROUP_CONCAT(me.id ORDER BY me.id) AS monthly_entry_ids,
    SUM(me.sponsorship_amount) AS sponsorship_amount_sum,
    SUM(me.services_amount) AS services_amount_sum
FROM mde_monthly_sponsorship_entries AS me
JOIN mde_sponsorships AS rel ON rel.id = me.sponsorship_id
JOIN mde_sponsors AS sp ON sp.id = rel.sponsor_id
JOIN mde_orphans AS o ON o.id = rel.orphan_id
JOIN mde_mothers AS mo ON mo.id = o.mother_id
GROUP BY rel.sponsor_id, rel.orphan_id, me.report_month
HAVING COUNT(*) > 1
ORDER BY me.report_month, sp.sponsor_name, mo.mother_name, o.orphan_name;

ALTER TABLE mde_monthly_sponsorship_entries
    ADD UNIQUE KEY uq_mde_monthly_sponsorship_report_month (sponsorship_id, report_month);

INSERT IGNORE INTO mde_schema_version (version) VALUES (10);
