-- One-time repair for archived October 2026 imports.
-- Run against ahl_el_kheir_monthly_data in phpMyAdmin.
-- This corrects the reporting month from the saved effective dates.
-- It deliberately preserves created_at as the real database insertion timestamp.
--
-- Review the two preview result sets first. The updates affect only rows whose
-- effective date is in October 2026 and whose report_month is not October.

SELECT
    report_month,
    COUNT(*) AS rows_to_move_to_october
FROM mde_monthly_sponsorship_entries
WHERE entry_date >= '2026-10-01'
  AND entry_date < '2026-11-01'
  AND report_month <> '2026-10-01'
GROUP BY report_month
ORDER BY report_month;

SELECT
    report_month,
    COUNT(*) AS donations_to_move_to_october
FROM mde_monthly_other_donations
WHERE donation_date >= '2026-10-01'
  AND donation_date < '2026-11-01'
  AND report_month <> '2026-10-01'
GROUP BY report_month
ORDER BY report_month;

START TRANSACTION;

UPDATE mde_monthly_sponsorship_entries
SET report_month = '2026-10-01'
WHERE entry_date >= '2026-10-01'
  AND entry_date < '2026-11-01'
  AND report_month <> '2026-10-01';

SELECT ROW_COUNT() AS monthly_sponsorship_rows_corrected;

UPDATE mde_monthly_other_donations
SET report_month = '2026-10-01'
WHERE donation_date >= '2026-10-01'
  AND donation_date < '2026-11-01'
  AND report_month <> '2026-10-01';

SELECT ROW_COUNT() AS donation_rows_corrected;

COMMIT;

-- Verification: both queries should return zero rows_to_correct.
SELECT COUNT(*) AS october_sponsorship_rows_still_misfiled
FROM mde_monthly_sponsorship_entries
WHERE entry_date >= '2026-10-01'
  AND entry_date < '2026-11-01'
  AND report_month <> '2026-10-01';

SELECT COUNT(*) AS october_donations_still_misfiled
FROM mde_monthly_other_donations
WHERE donation_date >= '2026-10-01'
  AND donation_date < '2026-11-01'
  AND report_month <> '2026-10-01';
