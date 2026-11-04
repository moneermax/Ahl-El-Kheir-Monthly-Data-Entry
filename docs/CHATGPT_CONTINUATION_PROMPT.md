# New Chat Prompt — Continue Monthly Data Entry Project

Continue work on my **separate Ahl El Kheir Monthly Data Entry** project. Read `README.md` and `docs/PROJECT_STATUS.md` first, then inspect the current GitHub repository and latest `main` commit before making changes.

## Environment
- GitHub: `moneermax/Ahl-El-Kheir-Monthly-Data-Entry`
- Local path: `/opt/lampp/htdocs/MonthlyDataEntry`
- URL: `http://localhost/MonthlyDataEntry/`
- Database: `ahl_el_kheir_monthly_data`
- Branch: `main` only
- Linux Mint 22.3, XAMPP, PHP 8.2, MariaDB 10.4, procedural PHP, Bootstrap 5.3 RTL, vanilla JavaScript, TCPDF.
- This project is completely separate from `/opt/lampp/htdocs/AhlElKheir`. Do not modify the main management system or its database.

## Work already completed
1. Redesigned the new-entry page as an Excel-style sponsor → mother → orphan register.
2. Added independent other-donation controls for each orphan. Multiple donations per orphan are supported and linked to the exact sponsor/orphan relationship through `mde_monthly_other_donations.sponsorship_id`.
3. Added static migration `database/migrations/004_link_other_donations_to_sponsorship.sql`; apply it once to an existing database after migration 003. Fresh schema includes it. Existing sponsor-level donation records remain supported.
4. Updated reports and PDF/XLSX/CSV exports to show mother/orphan details for linked donations.
5. WhatsApp parsing extracts one `بواسطة` and one independent `المشرف` value from the entire pasted message and applies both to every sponsor record split from that message.
6. Added tests for shared message-level fields.
7. After a successful DB commit, the last saved sponsor name is stored in the session and displayed in a prominent **red alert immediately above the entry register table**. It must show the actual stored name for both new and existing sponsors and must not change on a failed save.

## Immediate task
Verify that the red last-saved sponsor indicator appears exactly above the table after a successful save. The user specifically wants it red and highly visible to prevent accidental duplicate/re-entry. Do not move it above the WhatsApp panel or leave it hidden elsewhere.

Then perform a full consistency review of per-orphan donations from entry HTML/JS through PHP persistence, schema, reports, and all exports. Check nested input reindexing after adding/removing mothers, orphans, and donation rows. Verify WhatsApp multi-sponsor shared values. Run available PHP lint and JavaScript tests. Do not claim browser/database tests were run unless they actually were.

## User's working preferences and strict rules
- Be direct and rigorous; challenge assumptions and verify instead of reassuring.
- Do not ask me to repeat findings or run long sequences of manual troubleshooting.
- Inspect source code and docs before editing.
- Do not invent schema objects or execute DDL during web requests.
- Do not touch the original Ahl El Kheir management system.
- Keep changes on `main`; inspect local changes before pulling or overwriting anything.
- Commit and push complete changes so I can pull and test. If authentication fails, report the exact error; don't improvise destructive Git operations.
- Give me one concise, complete test plan after the changes are ready.

Start by checking the latest repository state and confirming the red-indicator update is present on `main`.