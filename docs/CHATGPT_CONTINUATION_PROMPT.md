# Continuation Prompt — Verify the Departures Page Redesign

Continue the Ahl El Kheir Monthly Data Entry project after the departure-page visual alignment commits.

## Current baseline

- Repository: `moneermax/Ahl-El-Kheir-Monthly-Data-Entry`
- Branch: `main` only
- Local path: `/opt/lampp/htdocs/MonthlyDataEntry`
- URL: `http://localhost/MonthlyDataEntry/`
- Database: `ahl_el_kheir_monthly_data`
- Stack: Linux Mint 22.3, XAMPP, PHP 8.2, MariaDB 10.4, procedural PHP, Bootstrap 5.3 RTL, vanilla JavaScript, TCPDF.
- Separate management system at `/opt/lampp/htdocs/AhlElKheir` is off-limits.

The user confirmed the local checkout was clean and synchronized with GitHub at `31a0c57` before these changes. The following commits were then made on GitHub `main`:
- `7c13cd4` — align departure page heading, sponsor-mode section, register heading, batch table and submit area.
- `124c9d8` — make departure page full-width like New Sponsors and add scoped departure panel/batch-table styling.

## First actions

1. Inspect `git status --short --branch` and `git log -3 --oneline --decorate`.
2. Confirm the local checkout is still clean before pulling. Do not overwrite local work.
3. Pull the latest `main` normally.
4. Read `docs/PROJECT_STATUS.md`, `README.md`, `pages/departures.php`, `pages/entry.php`, and `index.php`.
5. Inspect the diff for the two UI commits. Confirm no parser, adapter, SQL, save-handler, POST-name, ID, or data-attribute changes slipped in.

## Required automated checks

Run from the repository root:

```bash
php -l pages/departures.php
php -l index.php
node --check assets/js/whatsapp-parser.js
node --check assets/js/whatsapp-import.js
node tests/whatsapp-import.test.cjs
```

Also inspect the exact diff and confirm the departure-specific DOM hooks still exist:
- `#departureForm`, `#departureBatchFlag`, `#singleDepartureFields`
- `#modeExisting`, `#modeNew`, `#departureSponsor`, `#departureSponsorName`, `#departureSponsorId`
- `#existingFileNumber`, `#newFileNumber`, `#departureDate`, `#lastPaidMonth`
- `#orphanCount`, `#sponsorshipAmount`, `#servicesAmount`, `#departureNotes`
- `#departureBatchPanel`, `#departureBatchRows`, `#departureBatchCancel`
- WhatsApp panel `data-profile="departure"`, `#whatsappAnalyze`, `#whatsappApply`, `#whatsappApplyAll`.

## Browser/database verification

Use the local browser at `http://localhost/MonthlyDataEntry/?page=departures` and verify:

1. Existing sponsor mode: match an existing sponsor by name/file; confirm its master sponsor name is not changed when editing the departure snapshot.
2. New sponsor mode: enter a genuinely new sponsor, type, file number, and optional contact details; switch modes both ways and confirm disabled/required fields behave correctly.
3. Import one departure message: inspect all extracted fields, correct them if needed, and save only through the normal submit button.
4. Import multiple departures: review every row, test sponsor matching and edits, remove a row, then save the batch once; verify atomic save behaviour.
5. Return to single-record mode and confirm the original form works.
6. Trigger a validation failure and confirm no partial write occurs.
7. Complete one successful save and verify the flash/redirect and departure report.
8. Test a narrow viewport: heading and controls should wrap cleanly; registers should scroll horizontally without squeezing text or breaking the page.

Do not claim any browser/database test passed unless it was actually performed. Report automated checks separately from local interactive tests.

## Guardrails

- Preserve departure-specific parsing in `assets/js/whatsapp-parser.js`; do not replace it with entry/family parsing.
- Preserve `assets/js/whatsapp-import.js` adapter selectors, batch queue, review-before-save, and return-to-single mode.
- Do not change server-side validations, SQL, transaction boundaries, redirect/flash behaviour, form names, IDs, data attributes, or schema for a visual-only task.
- Do not modify `config/database.php`.
- Keep all work on `main`; no branches unless explicitly requested.
- Never use destructive Git commands: no `reset --hard`, `git clean`, `git restore`, or force push.
- Preserve staged, untracked, ignored, backup, and configuration files.
- Do not touch the separate Ahl El Kheir management system or database.

After verification, update this document and `docs/PROJECT_STATUS.md` with actual results, then commit and push any necessary fixes to `main`.
