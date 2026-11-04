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
- After a successful database commit, the last saved sponsor name is stored in `$_SESSION['mde_last_saved_sponsor']` and displayed in a prominent red alert immediately before the entry register heading. New and existing sponsors are both supported. Failed saves must never update the indicator.
- Monthly reports and PDF/XLSX/CSV exports include mother/orphan details for sponsorship-linked other donations. Historical sponsor-level donations with a NULL sponsorship link remain supported.
- No request-time DDL. Schema changes are static SQL migrations.

## Database migrations

Existing installations must apply migrations in order, once each, to `ahl_el_kheir_monthly_data`.

- 003: audit log.
- 004: nullable `sponsorship_id` on `mde_monthly_other_donations`, foreign key to `mde_sponsorships`. Required for per-orphan other donations. It does not rewrite legacy rows.

Fresh installations should import `database/schema.sql`, which includes the latest schema version.

## Immediate next verification

The latest UI update places the last-saved sponsor indicator immediately before the `سجل الكفالة` heading, in a red high-visibility alert. The save handler explicitly writes the session before redirecting. Verify it after a successful save and redirect, and confirm it displays the correct name for both new and existing sponsors. The WhatsApp import panel no longer shows the `تجريبي` badge, explanatory paragraph, or textarea label; routine extraction/allocation/success explanations were removed while actionable validation and manual-allocation warnings remain.

Then verify:
1. One sponsor, one mother, two orphans.
2. Give each orphan at least two different other donations.
3. Save and verify each donation remains attached to the correct orphan in reports and exports.
4. Paste a WhatsApp message containing multiple sponsors and one shared `بواسطة` and one shared `المشرف`; verify both values are identical across all queued sponsor records while remaining independent of each other.
5. Save an existing sponsor and verify the red indicator shows that sponsor's actual stored name.
6. Cause a validation failure and verify the last-saved indicator does not change.

## Working rules

- Inspect the current GitHub `main` branch before editing; do not assume local files or prior chat summaries are current.
- Keep all work on `main`; do not create branches unless explicitly requested.
- Never touch the separate Ahl El Kheir management-system project/database.
- Do not invent tables or execute runtime CREATE/ALTER statements.
- Review all affected paths end-to-end: HTML, JavaScript, PHP save handler, schema/migration, reports and exports.
- Run PHP lint and JavaScript tests where the available environment permits. Clearly distinguish code inspection from tests actually executed.
- Do not discard local changes, reset, force-push, or overwrite unknown work. If Git reports divergence or conflicts, stop and inspect.
- Commit and push complete, verified changes to GitHub; report exact commit hashes and any migration/test steps.
