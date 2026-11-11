# New Chat Prompt — Redesign the Departures Page

Continue the Ahl El Kheir Monthly Data Entry project. Redesign the Departures page so it looks and feels as close as practical to the New Sponsors page, while preserving all departure-specific functionality, fields, validations, parsing, and save behaviour.

## Start immediately — required first actions

1. Read README.md, docs/PROJECT_STATUS.md, docs/WHATSAPP_PARSER.md, and this prompt completely.
2. Inspect the latest GitHub main commit and local repository state before editing. The user's latest known local update is d912bc6; confirm rather than assume.
3. Inspect the complete current source of pages/entry.php (visual reference), pages/departures.php (target), shared stylesheets/page CSS, assets/js/whatsapp-parser.js, assets/js/whatsapp-import.js, includes/whatsapp_import.php, and scripts loaded by either page.
4. Identify actual visual/layout differences from source. If screenshots or exact user-reported defects are unavailable, do not invent defects. Use New Sponsors as the reference.
5. THEN START IMPLEMENTING IMMEDIATELY. Do not stop after an audit, plan, or list of recommendations. Ask a question only if an essential requirement cannot be inferred from the existing implementation and project rules.

## Environment

- GitHub: moneermax/Ahl-El-Kheir-Monthly-Data-Entry
- Local path: /opt/lampp/htdocs/MonthlyDataEntry
- URL: http://localhost/MonthlyDataEntry/
- Database: ahl_el_kheir_monthly_data
- Branch: main only
- Linux Mint 22.3, XAMPP, PHP 8.2, MariaDB 10.4, procedural PHP, Bootstrap 5.3 RTL, vanilla JavaScript, TCPDF.
- This project is separate from /opt/lampp/htdocs/AhlElKheir. Never modify that project or its database.

## Design objective

Make Departures visually consistent with New Sponsors in page/container width, spacing, typography, heading hierarchy, RTL alignment, cards/panels, borders, padding, form labels and control sizing, help/validation text, button hierarchy, register/table density, and responsive behaviour. Wide registers must remain readable and may scroll horizontally on narrow screens.

Do not mechanically copy the New Sponsors form structure. Departure data is different. The goal is a consistent design system, not identical fields or workflow.

## Functionality that must remain unchanged

1. Sponsor mode: existing registered sponsor; register a new sponsor and depart them; mode switching and correct enabled/disabled/required field behaviour.
2. Sponsor identity/file: existing sponsor matching by name and file number; ambiguous-name handling; editable departure sponsor-name snapshot without renaming the master sponsor; existing/new file-number semantics.
3. Departure fields: departure date, last-paid month, orphan count (including existing tree-specific optional behaviour), sponsorship/services snapshots, notes/source details, and new-sponsor phone/address/type/details where applicable.
4. WhatsApp import: preserve shared import integration and departure profile. Preserve departure-specific parsing: numbered records, file-number context headings, letter-group headings, tree sections, standalone cancellation messages, shared dates, last-paid month handling, amount pairs, and notes. Preserve review-before-save; parsing must never autosave. Preserve multi-record batch review, sponsor matching, and return to single-record entry.
5. Save/data integrity: preserve POST names, IDs, data attributes, server-side validation, database writes, redirects/flash messages, and batch atomicity. Do not change schema or add migrations for a visual-only task. Do not weaken validation or silently convert missing values to zero.

## Critical technical guardrails

- Both pages use includes/whatsapp_import.php, with profiles entry and departure respectively.
- Both share assets/js/whatsapp-parser.js and assets/js/whatsapp-import.js, but the parser has separate entry/family and departure parsing paths. Do not replace departure parsing with entry parsing or refactor parser logic merely to make the pages look alike.
- New Sponsors uses #entryForm, .entry-register-wrap, and #entryRegister, with sponsor/mother/orphan rows and per-orphan amounts/donations.
- Departures uses #departureForm, .entry-register-wrap, #departureRegister, existing/new sponsor mode controls, and #departureBatchPanel / #departureBatchRows.
- The DOM adapter in assets/js/whatsapp-import.js depends on specific IDs/selectors. Preserve them unless every dependency is traced and updated together.
- Prefer shared design tokens/classes or tightly scoped departure-page CSS/markup changes. Avoid broad rewrites and conflicting duplicate CSS.
- Do not modify unrelated New Sponsors behaviour to achieve visual parity.

## Implementation and verification

- Implement the redesign after the initial source review; do not return with only an audit.
- Compare final Departures markup/styles directly against New Sponsors.
- Run: php -l pages/departures.php; node --check assets/js/whatsapp-parser.js; node --check assets/js/whatsapp-import.js; node tests/whatsapp-import.test.cjs. Also lint any other PHP files changed.
- Review the diff for accidental changes to form names, IDs, data attributes, parser logic, SQL, or save handlers.
- Provide one concise browser test sequence: existing sponsor, new sponsor, one imported departure, multi-record import/batch, return to single mode, validation failure, successful save, and narrow viewport.
- Separate automated checks from tests requiring the user's local browser/database. Never claim tests not actually performed.
- Update docs/PROJECT_STATUS.md, docs/CHATGPT_CONTINUATION_PROMPT.md, and affected feature documentation after implementation.

## Working rules

- Be direct, critical, and efficient. Challenge assumptions; no empty reassurance.
- Inspect current docs and source before editing.
- Keep work on main only. Do not create branches unless explicitly requested.
- Inspect local changes before pulling or overwriting anything. Preserve staged, untracked, ignored, backup, and configuration files.
- Never use destructive Git commands: no reset --hard, git clean, git restore, or force push.
- Do not modify local config/database.php.
- Do not touch the separate Ahl El Kheir management system/database.
- Commit and push complete changes to GitHub so the user can pull and test. If Git authentication fails, report the exact error; do not improvise destructive workarounds.
- Give one concise, complete test plan after implementation.

BEGIN NOW: read the listed documentation, inspect current main and both page implementations, then implement the Departures visual redesign in this session.