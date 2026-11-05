# Monthly Data Entry — Project Status

**Repository:** `moneermax/Ahl-El-Kheir-Monthly-Data-Entry`  
**Branch:** `main` only  
**Local path:** `/opt/lampp/htdocs/MonthlyDataEntry`  
**Local URL:** `http://localhost/MonthlyDataEntry/`  
**Database:** `ahl_el_kheir_monthly_data`  
**Stack:** Linux Mint + XAMPP, PHP 8.2, MariaDB 10.4, procedural PHP, Bootstrap 5.3 RTL, vanilla JavaScript, TCPDF.

This is a standalone data-entry project. Never modify the separate Ahl El Kheir management-system repository or database.

## Current implementation

- Sponsor → mother → orphan entry register, with one row per orphan and merged sponsor/mother cells.
- Existing sponsor selection and new sponsor entry.
- Per-orphan sponsorship and services amounts, with optional combined-total checks.
- One or more other donations per orphan, stored against the exact `mde_sponsorships.id`.
- WhatsApp parser queue for multiple sponsor records. The `بواسطة` operator and `المشرف` are independently extracted once from the complete pasted message and copied to every sponsor record split from that message.
- The entry page reads the most recently inserted monthly sponsorship entry from the database (`mde_monthly_sponsorship_entries.created_at`, with `id` as a tie-breaker), joins it to its sponsorship and sponsor, and displays that sponsor's current name in a prominent red alert immediately before the entry register heading. This works across browser sessions and for both new and existing sponsors; failed saves do not affect the database result. The display does not depend on PHP session state.
- Monthly reports and PDF/XLSX/CSV exports include mother/orphan details for sponsorship-linked other donations. Historical sponsor-level donations with a NULL sponsorship link remain supported.
- No request-time DDL. Schema changes are static SQL migrations.

## Existing-record reuse and duplicate protection — 2026-11-04

The entry save handler now reuses exact, unambiguous existing mother and orphan records instead of creating new person IDs for every monthly entry. It then:
- Reuses the sponsor/orphan relationship when exactly one active relationship exists and no monthly row exists for the selected report month.
- Stops the transaction if that active relationship already has a monthly row for the selected month.
- Preserves ended relationships and creates a new sponsorship episode when no active or paused episode exists.
- Stops when duplicate active relationships, paused relationships, or ambiguous same-name mother/orphan records are found.
- Reuses an existing orphan identity across sponsors when its mother/orphan name match is unambiguous, preserving the one-orphan/multiple-sponsors data model.
- Rejects the same mother/orphan pair repeated inside one submitted form.

Matching is based on exact names under the database's configured collation because the current form/schema do not provide a unique person identifier. A single name match is therefore a cautious heuristic, not proof of identity; ambiguous matches are blocked rather than guessed. This limitation should be revisited if stable person identifiers or explicit existing-person selectors are added.

No schema migration is required for this change. The save remains transactional, so any validation failure rolls back all changes in the submission.

### Verification after the change

- GitHub Actions run 124 (commit 937be01e222f52acaa68478dcb93af3bdab64a89) passed PHP lint, JavaScript syntax checks, WhatsApp parser tests, and personnel-dropdown tests.
- This is automated static/regression verification only. It does not replace live MariaDB transaction tests or browser testing of the entry, departure, and report workflows.

## Database migrations

Existing installations must apply migrations in order, once each, to `ahl_el_kheir_monthly_data`.

- 003: audit log.
- 004: nullable `sponsorship_id` on `mde_monthly_other_donations`, foreign key to `mde_sponsorships`. Required for per-orphan other donations. It does not rewrite legacy rows.

Fresh installations should import `database/schema.sql`, which includes the latest schema version.

## WhatsApp parser reliability update — 2026-11-04

The current parser regression was traced to commit `c08f04c86085c34f3b6cdfadf9e569ccb72306ba`. Its message-level field helper assigned empty shared values over each record's existing `entered` and `supervisor` values. The parser update now preserves record-level values unless a unique trailing message-level value is present.

The parser is now split into:
- `assets/js/whatsapp-parser.js`: deterministic normalization, record splitting, family/name/amount/date/operator/supervisor extraction, warnings, and form-payload construction.
- `assets/js/whatsapp-import.js`: review queue, DOM form adapter, dynamic family/orphan row creation, and successful-save queue handling.
- `includes/whatsapp_import.php`: loads the pure parser module before the DOM adapter.

The parser uses the selected entry/departure date only as year context for yearless dates such as `3نوفمبر`; it no longer falls back to the computer's current year. It recognizes an unlabelled first-line sponsor when safe, consecutive unindented multi-word orphan names, the `رجعت لملفها 24` file-number form, Arabic numerals, and compact service amounts. Unrecognized lines remain visible as warnings, and each record retains the raw source message.

The existing parser test corpus has been extended with the exact reported message, shared-field preservation/override cases, year-context tests, unknown-line warnings, and a form-payload/adapter population test. See `docs/WHATSAPP_PARSER.md` for the full architecture, supported formats, limitations, and commands.

### Verification status

- Parser regression assertions were executed in a JavaScript runtime and passed.
- The parser and DOM-adapter JavaScript source compiled successfully in that runtime.
- The repository's Node command `node tests/whatsapp-import.test.cjs` has not been executed on the user's Linux machine in this session.
- PHP lint, live browser interaction, and database/save testing have not been performed. Do not describe those as verified.

### Next verification

1. Pull the latest `main`.
2. Run `node tests/whatsapp-import.test.cjs`.
3. Run `node --check assets/js/whatsapp-parser.js` and `node --check assets/js/whatsapp-import.js`.
4. Run `php -l includes/whatsapp_import.php` and `php -l pages/entry.php`.
5. In the browser, paste the exact sample from the parser documentation, confirm the sponsor, both orphans, mother, 80,000/5,000 totals, date, file number, operator, and supervisor, then load the record and inspect the actual form rows.
6. Verify one multi-sponsor message with shared footer fields and another with record-specific operator/supervisor fields. Save one queued record and confirm the remaining queue is retained only after a successful save.

## Working rules

- Inspect the current GitHub `main` branch before editing; do not assume local files or prior chat summaries are current.
- Keep all work on `main`; do not create branches unless explicitly requested.
- Never touch the separate Ahl El Kheir management-system project/database.
- Do not invent tables or execute runtime CREATE/ALTER statements.
- Review all affected paths end-to-end: HTML, JavaScript, PHP save handler, schema/migration, reports and exports.
- Run PHP lint and JavaScript tests where the available environment permits. Clearly distinguish code inspection from tests actually executed.
- Do not discard local changes, reset, force-push, or overwrite unknown work. If Git reports divergence or conflicts, stop and inspect.
- Commit and push complete, verified changes to GitHub; report exact commit hashes and any migration/test steps.


## Search and inline register editing — 2026-11-04

The data-management page now uses the same 11-column register layout as the entry page, including sponsor and mother row grouping. Search filters as the user types (debounced server-side request, no page reload) across sponsor name, file number, mother/orphan names, report month, entry date, amounts, operator, supervisor, notes, and sponsorship-linked other-donation descriptions. Results show historical monthly rows, ordered newest month first, with a 500-row cap.

Inline editing is grouped by sponsor: choose “تعديل هذا الكفيل” to edit the sponsor details, displayed mother/orphan names, and monthly entry date, sponsorship/services amounts, operator, supervisor, and notes together; Save commits the group in one transaction and writes supported before/after audit records. Cancel reloads the displayed search results. Other donations are shown per sponsorship and report month but remain read-only; sponsor departures are not yet included in this register editor. Sponsorship start dates and statuses are displayed as historical relationship data and are not editable here.

Automated GitHub Actions PHP lint / JavaScript syntax / parser / reference-option checks passed on commit `e5475a0dff55bf02141697c8d727dc9ab01f11ea` (run 129). This does not verify SQL execution against MariaDB or actual browser behavior. Live local database and browser testing remain required before treating the editor as production-ready.


## Message-level personnel editing and audited deletion — 2026-11-04

The management register now presents operator and supervisor once for a saved single-sponsor submission group rather than as independent controls on each orphan row. Existing rows are grouped conservatively by sponsor, reporting month, database creation timestamp, and identical operator/supervisor values. Changing either name updates every monthly orphan row in that group. The legacy schema does not retain a WhatsApp source-message identifier across different sponsors, so this reconstructs the single-sponsor form submission boundary and does not claim cross-sponsor edit grouping.

Deletion scopes are separate:
- Monthly entry: removes one orphan's monthly sponsorship row and other donations linked to that sponsorship/month; the relationship and other months remain.
- Sponsorship relationship: removes that sponsor/orphan relationship, its monthly entries, and donations linked to that relationship; the orphan and its other sponsor relationships remain.
- Sponsor: removes only an empty sponsor master record. If relationships, departures, or donations remain, deletion is blocked with dependency counts.

Successful deletions run in a transaction and record before-data snapshots in the audit log. Migration 005 extends audit entity types for sponsorship and donation deletion records. No request-time DDL is used.

Automated PHP lint and JavaScript checks do not replace live MariaDB/browser verification of all three delete scopes and message-group editing.


## Sponsor deletion behavior — 2026-11-04

The data-management page now supports deleting a selected sponsor together with all records owned by that sponsor in one database transaction. The confirmation shows live counts for sponsorship relationships, monthly sponsorship entries, other donations, and departure records. The delete operation snapshots every removed sponsor, relationship, monthly entry, donation, and departure in `mde_change_log` before removing dependent rows in foreign-key-safe order.

Orphan and mother master records are deliberately retained, as are any sponsorship relationships those orphans have with other sponsors. Sponsor-level legacy donations and sponsorship-linked donations are both included in the deletion scope. A failed delete rolls back the entire transaction.

Existing databases must apply migration 006 after migrations 004 and 005 to allow departure deletion snapshots. Fresh installations receive the expanded audit enum from `database/schema.sql`. Automated checks and live MariaDB/browser deletion tests are pending; do not treat the feature as runtime-verified until those tests are completed.

## Monthly report separation and totals — 2026-11-04

- The entry register footer displays live totals for the currently entered sponsorship and service amounts.
- Each sponsor group in the data-management search register displays totals for the monthly rows shown in that group.
- Monthly reporting is split into separate navigation pages for new sponsors and sponsor departures. Each report retains its own month selector and report-specific Excel, CSV, PDF, and print actions; the legacy `?page=reports` URL opens the new-sponsor report.
- Both report tables display sponsorship and services totals. CSV and PDF downloads contain only the selected register and include totals. XLSX downloads keep the original workbook template but hide the unselected worksheet and add totals to the new-sponsor worksheet as well as the existing departure worksheet.
- No schema change or database migration is required.


## Departure WhatsApp parsing — 2026-11-05

The departure page now uses a dedicated parser segmentation path instead of the new-sponsor parser's explicit-`الكفيل:` segmentation. It handles numbered departure records, file-number context headings, standalone `إلغاء كفالة` messages, and separate `ملف الشجرة` sections. It extracts sponsor, file, departure date, orphan count, sponsorship/services, last-payment month, operator, and notes; yearless payment months use only the selected departure-date year. Tree rows without an orphan count are represented as zero to satisfy the existing numeric departure field, and a standalone tree amount is read as sponsorship with zero services when no services value is provided.

The regression suite now includes grouped file-25/file-24 records, a tree row, and a standalone cancellation message using file value `2024`. The complete WhatsApp parser regression suite was executed against the current GitHub source in an isolated JavaScript runtime and passed. This does not replace browser testing of the departure page and actual form population. No database schema or data was changed, and no October records were re-imported.

Two differences between the supplied messages and the October workbook remain intentionally unresolved: file `2024` in a message versus `24` in the workbook, and tree sponsor name `محمد عثمان جمعة` in a message versus `الطيب عثمان جمعه` in the workbook. The parser preserves the pasted values rather than silently substituting workbook values.
