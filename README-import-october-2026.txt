AHL EL KHEIR — OCTOBER 2026 IMPORT PACKAGE
==========================================

Files
-----
- october-2026-import.php
- october-2026-import.json

The original workbook is not modified. The JSON contains the normalized rows.
The PHP script uses the existing config/database.php and existing schema only.
It does not execute CREATE, ALTER, DROP, or other schema changes.

IMPORTANT SOURCE DECISIONS
--------------------------
- New sponsor groups: 92.
- Monthly orphan entries prepared: 280.
- October report month: 2026-10-01.
- New sponsorship total after the explicit corrections and one exact duplicate-orphan removal: 3,726,000 SDG.
- New services total after the explicit corrections and one exact duplicate-orphan removal: 494,300 SDG.
- Other donations imported: 39,300 SDG (4 complete type/value pairs).
- Departures imported: 102 (the two later exact duplicate rows were excluded).
- Departure sponsorship snapshots: 3,195,000 SDG.
- Departure services snapshots: 386,500 SDG.

Specific decisions:
1. Sponsor 15 (مها محمد محمود بشير): 2,000 services per orphan (26 orphans = 52,000); 2,000 of the source 54,000 remains unallocated. One orphan in source row 51 has no third sponsorship amount; that orphan is imported with 0 sponsorship and a note flag.
2. Sponsors 23–25 (دانا احمد عبدالسلام، دانه الفاتح عوض الله، رنا الفاتح عوض الله): one shared services total of 12,000 across 17 orphans. Because the database stores whole SDG units, 15 rows receive 706 and 2 rows receive 705 (exact total 12,000).
3. Sponsor 85 (مهند مامون الزبير): 20,000 sponsorship and 3,000 services per orphan (3 orphans).
4. The three 1900 Excel dates were corrected to 2026, retaining month/day.
5. One exact repeated orphan row in the new-sponsors sheet (same sponsor, mother, orphan and amounts) was excluded because the application prevents duplicate sponsor/orphan/month entries.
6. Departure duplicates were removed only when every field B:I matched: keep row 8/remove row 90; keep row 42/remove row 86.
7. The 1,000 entered in the donation-type column at source row 154 has no valid donation type/value pair and was not imported.
8. The workbook's bottom summary cells were not used because they do not match the detail rows.

LOCAL STEPS (Linux Mint + XAMPP)
--------------------------------
1. Copy both files from this package into:
   /opt/lampp/htdocs/MonthlyDataEntry/

2. Open a terminal and run:

   cd /opt/lampp/htdocs/MonthlyDataEntry

3. Back up the dedicated database before importing:

   /opt/lampp/bin/mysqldump -u root ahl_el_kheir_monthly_data > backup-before-october-2026-import.sql

   If your MariaDB root account has a password, use:
   /opt/lampp/bin/mysqldump -u root -p ahl_el_kheir_monthly_data > backup-before-october-2026-import.sql

4. Run the dry-run first (this makes no database changes):

   /opt/lampp/bin/php october-2026-import.php --dry-run

5. If the dry-run says DRY RUN PASSED and the counts are expected, commit the import:

   /opt/lampp/bin/php october-2026-import.php --import

6. Open:
   http://localhost/MonthlyDataEntry/

   Check the October 2026 report, new-sponsor register, monthly totals, other donations,
   and departures.

SAFETY
------
- The script refuses to run unless config/database.php points to
  ahl_el_kheir_monthly_data.
- It requires schema version 6.
- All inserts and relationship changes are in one database transaction.
- If a validation or duplicate check fails, the transaction is rolled back.
- A dry-run always rolls back.
- A second import attempt is blocked by existing October monthly rows / departure records.
- Do not run --import if the dry-run reports an error. Send the complete terminal output.
