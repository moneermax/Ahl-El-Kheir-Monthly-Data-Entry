# Ahl El Kheir — Monthly Data Entry

A standalone Arabic RTL application for recording sponsors, mothers, orphans, monthly sponsorships, other donations, sponsor departures, and monthly reports.

**This is a separate local-only project.** It has its own Git repository, directory, and MariaDB database (\`ahl_el_kheir_monthly_data\`). It does not load or modify the original Ahl El Kheir management system.

## Local setup (Linux Mint + XAMPP)

1. Clone this repository into its own web directory, for example \`/opt/lampp/htdocs/MonthlyDataEntry\`.
2. Ensure the dedicated database \`ahl_el_kheir_monthly_data\` exists in the XAMPP MariaDB server.
3. Import the schema once:
   \`\`\`bash
   /opt/lampp/bin/mysql -u root ahl_el_kheir_monthly_data < database/schema.sql
   \`\`\`
   If your MariaDB root account has a password, add \`-p\`.
4. Copy \`config/database.example.php\` to \`config/database.php\` and set the local connection details. For the default XAMPP setup, host is \`127.0.0.1\`, port \`3306\`, database \`ahl_el_kheir_monthly_data\`, user \`root\`, and password is empty.
5. Install the PDF dependency:
   \`\`\`bash
   composer install
   \`\`\`
6. Open \`http://localhost/MonthlyDataEntry/\`.

The application opens directly at its entry page. There is no login system, as requested for this local-only tool. Keep Apache bound to localhost and do not expose this directory to the public internet.

## Features

- Register a new sponsor or add sponsorships to an existing sponsor, with optional sponsor file number and sponsor type (individual or internal tree). The entry page displays the most recently created sponsor master record (highest sponsor ID) prominently in a red alert immediately before the entry register heading, so the operator can resume the source list without confusing it with the latest monthly row for an existing sponsor. The indicator is database-backed and does not depend on the browser session.
- Enter multiple mothers for one sponsor, with one or more orphans under each mother.
- Each orphan belongs to one mother; one mother can have multiple orphans.
- Each sponsor/orphan relationship is stored separately, so one sponsor can sponsor multiple orphans and one orphan can have more than one sponsor.
- Record monthly sponsorship and service amounts per sponsorship. When a source gives a combined family total, split it into per-orphan amounts and ensure the per-orphan values add back to the supplied total.
- Search by sponsor, mother, or orphan and edit master data or a specific monthly entry from the data-management page. Operator and supervisor are edited once per saved submission group and applied to all orphan rows in that group. Deletion is available separately for one monthly entry, one sponsor/orphan relationship, and an empty sponsor master record; dependent records are protected and deletion snapshots are audited.
- Record one or more other donations against the exact sponsor/orphan sponsorship. Older sponsor-level donation records remain readable and included in totals.
- The new-sponsorship entry form follows the October Excel register layout in a full-browser-width table on the entry page (other pages retain the standard 1200px content width): sponsor number/name, joining date, mother, orphan, sponsorship, services, other-donation type/value, operator, supervisor, and notes. Sponsor-level cells are visually merged across all orphan rows, and each mother's cell is merged across that mother's orphans. Orphans remain one row each so their amounts are individually editable. Sponsor type and optional file number sit inside the sponsor cell; optional other donations are entered in the matching Excel columns. The WhatsApp import panel, combined-total allocation/checks, existing-sponsor workflow, and original save/database structure are preserved.
- Record sponsor departure with date, file number, last-paid month, orphan count, and financial snapshots. Tree sponsors may omit an individual orphan count and service fee; omitted values stay blank (service fee is stored as NULL), while individual sponsors still require an orphan count. Active sponsorships are ended without deleting historical records.
- Paste WhatsApp messages into either the new-sponsorship or sponsor-departure form. A shared browser-local parser extracts recognizable fields, separates multiple sponsor records into a review queue, and preserves the remaining queue across individual saves in the current browser tab. Nothing is sent to an external AI/service and parsing never saves data.
- WhatsApp extraction is deterministic and conservative. The parser is separated from the DOM adapter and retains the original message. Entry records can be loaded for review once sponsor/family/orphan names are recognized even if the joining date is absent; the date stays blank, is flagged for manual entry, and remains mandatory before saving. Departure records can also be loaded for review with missing dates or tree-only missing orphan counts, but saving still enforces the required values. Supported formats, parser stages, known limitations, and the regression corpus are documented in `docs/WHATSAPP_PARSER.md`. Run the suite with `node tests/whatsapp-import.test.cjs`. Supported real-message labels include الأم/الام, الطفل, numbered اليتيم entries using separators such as hyphen, slash, dot, or underscore (including numbered entries immediately following the mother field), التاريخ, خدمات, عن طريق, and مشرفه. Multiple numbered orphans remain attached to the most recently named mother until the next mother label. Indented or unindented multi-word continuation lines immediately following a labelled orphan are recognized as additional orphans under that same mother. In the supervisor field, مشرفه ح is a role/designation prefix; the supervisor's name starts after ح, which is not part of the name. Recognized supplementary details such as مقدم كفالة سنه, كرتونه رمضان, فرحة عيدين, and متبقي المبلغ are retained in the general notes rather than being silently discarded or misclassified as per-orphan sponsorship/service amounts. When a message includes both التواصل عن طريق and a later عن طريق operator, the latter fills the operator field and the contact detail is preserved in notes; additional-amount instructions are also preserved in notes. Field labels can appear inline without punctuation, and sponsor names stop at the next recognized field label so file numbers remain separate. Combined sponsorship/service totals are divided equally among all detected orphans when each total is exactly divisible by the orphan count; any total with a remainder is left unallocated for manual per-orphan entry. Sponsorship and service totals are evaluated independently, and the form validates each allocated sum against its source total. Review every extracted value and use the form's normal Save button.
- Monthly reports include a dedicated new-sponsor register. Each new sponsor receives one sequential number for the selected month, with mothers and orphans grouped under the sponsor, joining date, file number/type, sponsor-level sponsorship and service totals, operator, and supervisor. The register is included in the on-screen report and PDF, XLSX, and CSV exports.
- Monthly reports and PDF, XLSX, and CSV exports.

## Database

The SQL installation script is \`database/schema.sql\`. It creates the dedicated schema and tables using InnoDB, utf8mb4, foreign keys, integer SDG amounts, and restrictive deletes to preserve history. Application requests never execute DDL.

For an existing installation, apply each migration in \`database/migrations/\` exactly once, in version order, using the dedicated \`ahl_el_kheir_monthly_data\` database. Migration 003 adds the audit log used by data-management edits.

Main tables:
- \`mde_sponsors\`
- \`mde_mothers\`
- \`mde_orphans\`
- \`mde_sponsorships\`
- \`mde_monthly_sponsorship_entries\`
- \`mde_monthly_other_donations\`
- \`mde_sponsor_departures\`
- \`mde_change_log\`
- \`mde_schema_version\`

## CSV reference files

The two original CSV files are retained under \`reference-csv/\` without importing their rows. They are reference material only; the application database starts with its schema and new entries. The shared “بواسطة” and supervisor choices are maintained in `includes/reference_options.php` and reused across entry, departure, and data-management pages. The new-sponsor form uses native selects inside the horizontally scrollable register and lets the operator add a missing operator or supervisor; additions are persisted separately in `mde_personnel_options` and do not merge the two roles. Other pages retain the Bootstrap dropdown controls backed by hidden form values. The combined supervisor cell from the source CSV is not treated as an individual person.

## Requirements

- PHP 8.2+ with PDO MySQL and ZipArchive.
- MariaDB 10.4+ or compatible MySQL.
- Composer (installs TCPDF for PDF exports).

## Reporting rules

- A report month is stored as the first calendar day of that month.
- Sponsorship, service, and other-donation values belong to an explicit report month.
- A sponsor is classified as new only in the month containing that sponsor's earliest recorded sponsorship start date. The new-sponsor register includes all of that sponsor's family relationships, not only relationships that began on the exact earliest start date.
- Departures are grouped by the actual departure date, independently of the date the departure was entered. Historical departures must not overwrite a later reactivation or later sponsorship activity.
- Historical monthly entries remain reportable after a sponsorship ends.
- Amounts are integer SDG units; no floating-point arithmetic is used.

For existing installations, apply migrations in order: `004_link_other_donations_to_sponsorship.sql`, `005_audit_deletions.sql`, `006_audit_departure_deletions.sql`, `007_nullable_departure_services.sql`, `008_departure_sponsor_name_snapshot.sql`, `009_allow_repeat_monthly_sponsorship_entries.sql`, `010_unique_monthly_sponsorship_allocation.sql`, and `011_personnel_options.sql`. Migrations `012_allow_repeat_monthly_sponsorship_entries.sql` and `013_restore_monthly_sponsorship_uniqueness.sql` are historical follow-up migrations: apply both, in order, only on databases that previously applied migration 012; migration 013 restores the strict monthly uniqueness rule. Do not apply migration 012 alone as the final schema state. Migration 004 adds a nullable sponsorship link; migrations 005 and 006 extend audit entity types for deletion snapshots, including sponsor departures; migration 007 makes the departure service-fee snapshot nullable so an omitted optional tree fee is not stored as zero; migration 008 preserves the reviewed sponsor name on departure records; migration 009 temporarily permits repeat monthly rows; migration 010 adds sponsor/orphan identity columns, a composite foreign key, and a unique sponsor+orphan+report-month constraint. Before applying migration 010, run `database/maintenance/audit_monthly_duplicate_entries.sql`; it must return zero rows. Do not delete or merge returned rows automatically. Historical sponsor-level donations and departure rows are not rewritten. Fresh installations receive the latest schema from `database/schema.sql`.

## Privacy and separation

This repository is private. The CSV reference folder is denied direct HTTP access by Apache. Database credentials are ignored by Git. The application does not read or write the original management system's database, sessions, permissions, or workflows.


**Departure snapshot update:** Existing installations must apply `database/migrations/008_departure_sponsor_name_snapshot.sql` once, after migration 007, before using the editable departure sponsor-name field. The migration backfills existing departure names and does not rename sponsor master records.


- Same-month duplicate detection on the new-sponsor page remains visible as a review warning, but saving is idempotent. An existing sponsor+orphan+report-month allocation with identical sponsorship and service amounts is skipped; it is not inserted again. If the amounts differ, the entire submission is rejected without changing any row, and the operator must review/correct the existing record explicitly from data management. Existing multiple rows for the same key also stop the submission for manual reconciliation. Identical other-donation lines are counted by sponsorship, report month, date, type, and amount so re-importing the same message does not add them again. Migration 010 enforces the unique sponsor+orphan+report-month key at database level. Each prior row also has a confirmed delete action that removes only that monthly entry and writes an audit snapshot. Sponsor, mother, orphan, and sponsorship relationship records are retained, and other donations are always preserved when deleting a monthly entry.
