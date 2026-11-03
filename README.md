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
- The entry form follows the source-message workflow: sponsor and file details, mothers and orphans, combined-total checks and per-orphan allocation, then sponsor type, joining date, entry operator, and supervisor. Optional other donations remain a separate section. This is a presentation-only change; database fields, validation, and report/export column mappings are unchanged.
- Record sponsor departure with date, file number, last-paid month, orphan count, and financial snapshots. Active sponsorships are ended without deleting historical records.
- Paste WhatsApp messages into either the new-sponsorship or sponsor-departure form. A shared browser-local parser extracts recognizable fields, separates multiple sponsor records into a review queue, and preserves the remaining queue across individual saves in the current browser tab. Nothing is sent to an external AI/service and parsing never saves data.
- WhatsApp extraction is deliberately conservative: missing or ambiguous fields remain for manual review. Combined sponsorship/service totals are loaded only as reference totals; per-orphan allocations are never invented and must be entered and reconciled manually. Review every extracted value and use the form's normal Save button.
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

The two original CSV files are retained under \`reference-csv/\` without importing their rows. They are reference material only; the application database starts with its schema and new entries. The shared dropdown choices for the “بواسطة” and supervisor fields are maintained in `includes/reference_options.php` and are used by both the entry and data-management pages.

## Requirements

- PHP 8.2+ with PDO MySQL and ZipArchive.
- MariaDB 10.4+ or compatible MySQL.
- Composer (installs TCPDF for PDF exports).

## Reporting rules

- A report month is stored as the first calendar day of that month.
- Sponsorship, service, and other-donation values belong to an explicit report month.
- Departures are grouped by the actual departure date.
- Historical monthly entries remain reportable after a sponsorship ends.
- Amounts are integer SDG units; no floating-point arithmetic is used.

## Privacy and separation

This repository is private. The CSV reference folder is denied direct HTTP access by Apache. Database credentials are ignored by Git. The application does not read or write the original management system's database, sessions, permissions, or workflows.
