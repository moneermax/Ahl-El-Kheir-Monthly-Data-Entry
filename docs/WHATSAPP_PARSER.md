# WhatsApp Parser Architecture and Regression Tests

## Purpose and safety contract

The WhatsApp import is a local, deterministic parser. It does not send message text to an AI service or any external endpoint. Parsing never saves records. The original pasted message is retained on every parsed record so the operator can review the source when extraction is incomplete.

The parser must prefer an explicit warning over inventing a sponsor, family relationship, amount, or date. A parsed record is not considered safe to load merely because the parser returned an object.

## Architecture

The implementation is split into two browser modules:

1. `assets/js/whatsapp-parser.js` contains pure parsing and form-payload logic. It has no DOM dependency and exposes `window.MDEWhatsAppParser`.
2. `assets/js/whatsapp-import.js` owns the page controls, review queue, DOM adapter, and save/next-record session handling. It calls the parser module and applies its prepared payload to the existing entry form.
3. `includes/whatsapp_import.php` loads the parser before the DOM integration script. Both scripts use file modification timestamps for cache busting.

The parser stages are:

- Normalize Arabic-Indic/Persian digits and common Arabic spelling variants for matching, without rewriting the stored source message.
- Split explicitly labelled sponsor records.
- Extract a labelled sponsor name, or a safe unlabelled first-line sponsor candidate when no family label has appeared.
- Detect mother boundaries and attach orphan names to the appropriate family. It supports both mother-first and the existing orphan-first layouts.
- Extract amounts, services, file number, date, operator, supervisor, and recognized supplementary notes independently.
- Retain unrecognized lines as warnings and keep the original raw message.
- Build a form payload containing scalar fields, family/orphan arrays, expected totals, and per-orphan allocation results.
- Pass that payload through a small adapter contract. The browser adapter writes the actual controls and rebuilds the dynamic family/orphan rows; tests exercise the same payload/population contract with a recording adapter.

## Supported input patterns

### Names and family structure

- Sponsor labels: `الكفيل`, `الكفيلة`, `الكافل`, `الكافلة`, and `اسم الكفيل/الكافلة` variants.
- An unlabelled sponsor name on the first meaningful line is accepted only when it looks like a person/entity name and appears before family labels.
- Mother labels include `الأم`, `الام`, `اسم الأم`, `الوالدة`, and spelling variants.
- Orphan labels include `الأيتام`, `الايتام`, `اليتيم`, `الطفل`, `الأطفال`, and spelling variants.
- Orphans can be on the same line as the label, on subsequent lines, or numbered with slash, hyphen, dot, or underscore separators.
- Multiple mothers and multiple orphans per mother are supported.
- A bare continuation line is treated as an orphan only when it resembles a multi-word Arabic name. Ambiguous lines are not force-classified.
- Emoji and common punctuation around names are tolerated.

### Amounts and file numbers

- Western, Arabic-Indic, and Persian numerals are normalized.
- Commas, Arabic thousands separators, dots, and spaces are accepted as amount separators.
- Sponsorship and services are extracted separately, including the compact `10000خدمات` form.
- File labels include `رقم الملف`, `ملف`, and the phrase `رجعت لملفها 24`.
- A total is distributed only when it divides evenly across all extracted orphans. Otherwise its per-orphan values remain blank for manual allocation and the existing expected-total validation remains authoritative.

### Dates

- Supported explicit formats include `YYYY-MM-DD`, `DD/MM/YYYY`, and `DD-MM-YYYY`.
- Arabic month names such as `3نوفمبر` and `3 نوفمبر` are supported.
- If the year is absent, it is taken only from the entry/departure date currently selected in the form. The parser never uses the computer's current year as an implicit fallback.
- If no valid context year exists, the date remains unresolved, a warning is shown, and the record cannot be loaded into the form.
- Calendar-invalid dates are rejected.

### Operator and supervisor

- Operator labels include `عن طريق`, `بواسطة`, `إدخال بواسطة`, and spelling variants.
- Supervisor labels include `مشرف`, `مشرفه`, `مشرفة`, and `مشرفه ح` / `مشرفة ح`. The role marker `ح` is not included in the supervisor's name.
- Operator and supervisor are independent fields.
- In a multi-sponsor message, a field is applied as a shared message-level value only when exactly one occurrence exists in the complete message and that occurrence is in the trailing message footer. If multiple occurrences exist, the parser preserves each sponsor record's own value. A missing shared value never overwrites a record-level value.

## Departure-message parsing

Departure messages use a separate segmentation path; the entry parser remains independent.

- A heading such as `⛔كفلاء طلعو⛔` or `إلغاء كفالة` is message context, never a sponsor name.
- Numbered lines such as `1• اسم الكفيل`, `1/ اسم الكفيل`, and `1／ اسم الكفيل` start records only when the text after the separator is a plausible Arabic sponsor name. Amount pairs such as `100.000/20.000` and dates such as `31/10/2026` cannot start records. Repeated or skipped sequence numbers do not merge or discard records.
- A standalone cancellation message starts at the first unlabelled Arabic sponsor-name line after its title and file heading.
- `ملف 25` and `ملف 24` carry forward until the next file heading. A file number written inside a sponsor's block (including compact `ملف24`) belongs to that sponsor. A heading such as `ملف حرف ص ض ط ظ ق ف ك ل نساء` is a group heading, not a sponsor or numeric file. `ملف 2024` is preserved literally when supplied; it is not silently changed to `24`.
- `ملف الشجرة` establishes a tree section for the following tree record(s). A mention such as `داخل قروب الشجرة رجال` is explanatory text and does not reclassify an individual sponsor.
- Tree sponsors may have no individual orphan count and may omit service fees. Both values remain blank when absent; the parser does not fabricate zero. A standalone tree amount (for example `2000`) is sponsorship. A separately supplied service fee is extracted independently. Migration 007 makes the departure service snapshot nullable so an omitted fee remains NULL in the database.
- A single unlabelled departure date at the end of a message is shared across records when it is the only date candidate. Repeated identical record dates are also safe to share. Conflicting dates are never merged.
- Arabic month names use the selected departure-date year. When a message contains explicit `اخر سداد` labels, otherwise-unlabelled month-only lines in other records are interpreted as last-payment values. A range such as `سبتمبر/اكتوبر` is stored as the latest month in the single-month database field (October here), while the original wording is retained in notes. Without that message-level cue, an unlabelled month remains ambiguous.
- Amount labels may be directly adjacent to their values, for example `الكفالة62000` and `خدمات8000`. Unlabelled slash pairs such as `100.000/20.000`, `27.000/2000`, and `28000/2000` are interpreted as sponsorship/services.
- Unlabelled explanatory lines are retained in departure notes. The original raw message remains attached to every record.
- File headings are read only when they occupy their own line; a mention of another file inside a note must not silently change the record's file context.

The workbook and messages contain two source discrepancies that are deliberately not auto-corrected: one message says file `2024` where the October workbook row shows file `24`, and one tree-message name says `محمد عثمان جمعة` where the October workbook row shows `الطيب عثمان جمعه`. The parser preserves the text actually pasted. These require human reconciliation, not fuzzy name/file substitution.

## Review and failure behaviour

- Missing sponsor, mother, or orphan data prevents loading an entry record. A departure with a sponsor may be loaded for review even if the source omits its departure date or (for a tree sponsor) orphan count; missing required values remain blank and must be completed before saving. Individual sponsors still require an orphan count.
- Missing or ambiguous values remain blank; the parser does not invent replacements. A blank tree service fee is distinct from an explicit zero and is saved as NULL.
- Unrecognized non-empty lines are surfaced in the warning area. The original message remains in the textarea and in each parsed record for review.
- If an operator/supervisor name does not match exactly one available dropdown option, the dropdown is cleared and the value is copied into the notes for manual review. A stale previously selected person must never be silently retained.
- The existing multi-record queue, explicit user confirmation, save button, expected-total validation, and remove-after-successful-save flow remain in the DOM integration module.

## Known limitations

- Multiple sponsor records are split using explicit sponsor labels. Multiple unlabelled sponsor names in one paste cannot be separated safely and require manual correction.
- An unlabelled sponsor is accepted only at the beginning of a record, before family labels. A name appearing after an orphan/mother label is not guessed to be a sponsor.
- A bare orphan continuation is accepted only when it resembles a multi-word Arabic name. One-word or ambiguous continuation lines require a label or manual correction.
- Dates without a year require a valid selected form date for year context.
- Shared message-level fields are identified conservatively by unique occurrence and trailing position. If the source does not make shared-vs-record-specific intent clear, review the result before saving.
- Equal allocation is not inferred when a total has a remainder or the per-orphan amount conflicts with the stated combined total.

## Regression test corpus

Run from the repository root:

```bash
node tests/whatsapp-import.test.cjs
```

The suite covers the historical cases and the exact reported failure, including:

- Labelled and unlabelled sponsor names.
- Mother-first and orphan-first layouts, multiple mothers, multiple orphans, numbered orphans, same-line names, and unindented continuation names.
- Arabic and Western numerals, comma/dot/space thousands separators, combined sponsorship and services, and compact `10000خدمات`.
- Arabic label spelling variants, file-number phrases, explicit and month-name dates, and unresolved dates without context.
- Operator/supervisor variants, independent values, shared trailing values, and preservation of per-record values when no shared footer exists.
- Multiple sponsor segmentation and isolation of one sponsor's family/amount fields from the next.
- Missing optional values, unrecognized-line warnings, and preservation of raw text.
- Mixed-format previous-month departure messages, shared final departure dates, last-payment month ranges, and October workbook-derived tree rows with absent/present service fees and absent/explicit-zero orphan counts.
- The form payload and the adapter calls used to populate sponsor, file, date, operator, supervisor, notes, families, orphan names, amount allocations, and totals.

JavaScript syntax checks:

```bash
node --check assets/js/whatsapp-parser.js
node --check assets/js/whatsapp-import.js
```

PHP syntax checks for the affected PHP templates:

```bash
php -l includes/whatsapp_import.php
php -l pages/entry.php
```

The automated form-adapter test is not a substitute for a live browser test. A browser verification must still confirm the generated dynamic rows and dropdown labels on the actual entry page before claiming end-to-end UI verification.
