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

- Register a new sponsor or add sponsorships to an existing sponsor, with optional sponsor file number and sponsor type (individual or internal tree).
- Enter multiple mothers for one sponsor, with one or more orphans under each mother.
- Each orphan belongs to one mother; one mother can have multiple orphans.
- Each sponsor/orphan relationship is stored separately, so one sponsor can sponsor multiple orphans and one orphan can have more than one sponsor.
- Record monthly sponsorship and service amounts per sponsorship. When a source gives a combined family total, split it into per-orphan amounts and ensure the per-orphan values add back to the supplied total.
- Search by sponsor, mother, or orphan and edit master data or a specific monthly entry from the data-management page. Edits are recorded in an audit log; records are not physically deleted.
- Record other donations separately.
- The new-sponsorship entry form follows the October Excel register layout in one wide, horizontally scrollable table: sponsor number/name, joining date, mother, orphan, sponsorship, services, other-donation type/value, operator, supervisor, and notes. Sponsor-level cells are visually merged across all orphan rows, and each mother's cell is merged across that mother's orphans. Orphans remain one row each so their amounts are individually editable. Sponsor type and optional file number sit inside the sponsor cell; optional other donations are entered in the matching Excel columns. The WhatsApp import panel, combined-total allocation/checks, existing-sponsor workflow, and original save/database structure are preserved.
- Record sponsor departure with date, file number, last-paid month, orphan count, and financial snapshots. Active sponsorships are ended without deleting historical records.
- Paste WhatsApp messages into either the new-sponsorship or sponsor-departure form. A shared browser-local parser extracts recognizable fields, separates multiple sponsor records into a review queue, and preserves the remaining queue across individual saves in the current browser tab. Nothing is sent to an external AI/service and parsing never saves data.
- WhatsApp extraction is deliberately conservative: missing or ambiguous fields remain for manual review. For new-sponsorship imports, every detected family must have an extracted mother name before the record can be loaded into the form; records with missing mother names are blocked rather than treated as complete. Supported real-message labels include الأم/الام, الطفل, numbered اليتيم entries using separators such as hyphen, slash, dot, or underscore (including numbered entries immediately following the mother field), التاريخ, خدمات, عن طريق, and مشرفه. Multiple numbered orphans remain attached to the most recently named mother until the next mother label. Indented continuation lines immediately following a labelled orphan are also recognized as additional orphans under that same mother. In the supervisor field, مشرفه ح is a role/designation prefix; the supervisor's name starts after ح, which is not part of the name. Recognized supplementary details such as مقدم كفالة سنه, كرتونه رمضان, فرحة عيدين, and متبقي المبلغ are retained in the general notes rather than being silently discarded or misclassified as per-orphan sponsorship/service amounts. When a message includes both التواصل عن طريق and a later عن طريق operator, the latter fills the operator field and the contact detail is preserved in notes; additional-amount instructions are also preserved in notes. Field labels can appear inline without punctuation, and sponsor names stop at the next recognized field label so file numbers remain separate. Combined sponsorship/service totals are divided equally among all detected orphans when each total is exactly divisible by the orphan count; any total with a remainder is left unallocated for manual per-orphan entry. Sponsorship and service totals are evaluated independently, and the form validates each allocated sum against its source total. Review every extracted value and use the form's normal Save button.
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

The two original CSV files are retained under \`reference-csv/\` without importing their rows. They are reference material only; the application database starts with its schema and new entries. The shared “بواسطة” and supervisor choices are maintained in `includes/reference_options.php` and reused across entry, departure, and data-management pages. These two fields use Bootstrap dropdown controls backed by hidden form values to avoid inconsistent native select-popup behavior; the combined supervisor cell from the source CSV is not treated as an individual person.

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

## Privacy and separation

This repository is private. The CSV reference folder is denied direct HTTP access by Apache. Database credentials are ignored by Git. The application does not read or write the original management system's database, sessions, permissions, or workflows.
