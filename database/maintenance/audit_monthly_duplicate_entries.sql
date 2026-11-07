-- Read-only preflight for migration 010.
-- Any returned row must be reviewed before enforcing one row per relationship/month.
SELECT
    sp.id AS sponsor_id,
    sp.sponsor_name,
    mo.mother_name,
    o.orphan_name,
    me.report_month,
    COUNT(*) AS monthly_row_count,
    GROUP_CONCAT(me.id ORDER BY me.id) AS monthly_entry_ids,
    SUM(me.sponsorship_amount) AS sponsorship_amount_sum,
    SUM(me.services_amount) AS services_amount_sum,
    MIN(me.entry_date) AS earliest_entry_date,
    MAX(me.entry_date) AS latest_entry_date
FROM mde_monthly_sponsorship_entries AS me
JOIN mde_sponsorships AS rel ON rel.id = me.sponsorship_id
JOIN mde_sponsors AS sp ON sp.id = rel.sponsor_id
JOIN mde_orphans AS o ON o.id = rel.orphan_id
JOIN mde_mothers AS mo ON mo.id = o.mother_id
GROUP BY rel.sponsor_id, rel.orphan_id, me.report_month
HAVING COUNT(*) > 1
ORDER BY me.report_month, sp.sponsor_name, mo.mother_name, o.orphan_name;
