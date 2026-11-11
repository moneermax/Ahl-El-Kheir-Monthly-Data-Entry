const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const context = { window: {}, document: { querySelector: () => null } };
vm.runInNewContext(fs.readFileSync('assets/js/whatsapp-parser.js', 'utf8'), context);
vm.runInNewContext(fs.readFileSync('assets/js/whatsapp-import.js', 'utf8'), context);
const parser = context.window.MDEWhatsAppParser;
assert.ok(parser, 'parser API should be available for tests');
const importHelpers = context.window.MDEWhatsAppImportHelpers;
assert.ok(importHelpers, 'browser import helpers should be available for regression tests');
assert.equal(importHelpers.normalizePersonnelName('هناء خلف الله'), 'هناء', 'confirmed full-name alias resolves to the canonical short dropdown name');
const duplicateSponsorOptions = { options: [
  { value: '11', textContent: 'سلمي حسن الرشيد — ملف 24 · سجل #11', disabled: false },
  { value: '12', textContent: 'سلمي حسن الرشيد — ملف 25 · سجل #12', disabled: false }
] };
assert.equal(importHelpers.matchingSponsorOptions(duplicateSponsorOptions, 'سلمي حسن الرشيد').length, 2, 'same-name sponsor masters must be detected as ambiguous, not treated as a new sponsor');
assert.equal(importHelpers.matchingSponsorOptions({ options: [duplicateSponsorOptions.options[1]] }, 'سلمي حسن الرشيد').length, 1, 'file/record suffixes in option labels must not interfere with exact sponsor matching');

const sponsorResolutionSource = { options: [
 { value: '21', textContent: 'ناصر كباشي احمد — ملف 24 · سجل #21', disabled: false, dataset: { file: '24' } },
 { value: '22', textContent: 'سلمي حسن الرشيد — ملف 25 · سجل #22', disabled: false, dataset: { file: '25' } },
 { value: '23', textContent: 'سلمي حسن الرشيد — ملف 26 · سجل #23', disabled: false, dataset: { file: '26' } }
] };
assert.equal(importHelpers.resolveDepartureSponsor(sponsorResolutionSource, 'ناصر كباشي احمد', '999').id, '21', 'unique name match remains valid if the message file differs');
assert.equal(importHelpers.resolveDepartureSponsor(sponsorResolutionSource, 'اسم مكتوب بصورة مختلفة', '24').id, '21', 'unique file match can resolve a variant name');
assert.equal(importHelpers.resolveDepartureSponsor(sponsorResolutionSource, 'سلمي حسن الرشيد', '26').id, '23', 'file number disambiguates same-name master records');
assert.equal(importHelpers.resolveDepartureSponsor(sponsorResolutionSource, 'سلمي حسن الرشيد', '99').ambiguous, true, 'ambiguous same-name records are never guessed');

const entryPageSource = fs.readFileSync('pages/entry.php', 'utf8');
const departurePageSource = fs.readFileSync('pages/departures.php', 'utf8');
const importSource = fs.readFileSync('assets/js/whatsapp-import.js', 'utf8');
const entryRegisterSource = fs.readFileSync('assets/js/entry-register.js', 'utf8');
const reportPageSource = fs.readFileSync('pages/reports.php', 'utf8');
const exportSource = fs.readFileSync('export.php', 'utf8');
assert.match(entryPageSource, /FROM mde_sponsors\s+ORDER BY id DESC\s+LIMIT 1/, 'last sponsor indicator must show the most recently created sponsor master record');
assert.ok(entryPageSource.includes('id="sponsorName"') && entryPageSource.includes('id="fileNumber"'), 'new-entry form must expose editable sponsor-name and file-number fields');
assert.ok(entryPageSource.includes('$sponsorMatches = $findSponsorStmt->fetchAll();'), 'entry save must resolve sponsor masters from the exact editable name');
assert.ok(entryPageSource.includes('if (count($sponsorMatches) > 1)') && entryPageSource.includes('count($fileMatches) !== 1'), 'duplicate sponsor names must require a file number that selects exactly one master record');
assert.ok(entryPageSource.includes('لم يتم اختيار أي سجل تلقائياً ولم يتم إنشاء كفيل جديد'), 'ambiguous sponsor matches must fail safely instead of guessing or creating a duplicate master');
assert.ok(departurePageSource.includes('سجل #<?= (int) $s[\'id\'] ?>'), 'departure sponsor options must expose a stable record identifier when names repeat');
assert.ok(departurePageSource.includes("isset($_POST['departure_batch'])"), 'departure page must accept a multi-row batch submission');
assert.ok(departurePageSource.includes('foreach ($rows as $index => $row)'), 'every submitted departure row must be validated and persisted separately');
assert.ok(departurePageSource.includes('$pdo->beginTransaction();') && departurePageSource.includes('$pdo->rollBack();'), 'departure batches must be atomic');
assert.ok(departurePageSource.includes('last_paid_month <=> ?') && departurePageSource.includes('services_amount_snapshot <=> ?'), 'departure duplicate detection compares nullable fields rather than treating blanks as zero');
assert.ok(departurePageSource.includes('AND departure_date = ?') && departurePageSource.includes('sponsorship_amount_snapshot = ?') && departurePageSource.includes('COALESCE(notes,'), 'duplicate protection is scoped to exact same-date full records');
assert.ok(!departurePageSource.includes('$processedSponsorIds'), 'different departure records for the same sponsor are not rejected merely because the sponsor ID repeats');
assert.ok(departurePageSource.includes('$sponsorDepartureDates[$sponsorId]') && departurePageSource.includes('$effectiveDepartureDate'), 'multiple valid records for one sponsor close active sponsorships using the earliest date in the batch');
assert.ok(departurePageSource.includes('INSERT INTO mde_sponsor_departures'), 'each batch row must create an independent departure record');
assert.ok(departurePageSource.includes('id="departureBatchRows"'), 'departure page must render editable per-sponsor batch rows');
assert.ok(departurePageSource.includes('name="sponsor_name_snapshot"') && departurePageSource.includes('id="departureSponsorName"'), 'departure name is editable and saved as a per-departure snapshot');
assert.ok(departurePageSource.includes('sponsor_name_snapshot, file_number_snapshot'), 'departure save persists the edited name snapshot');
assert.ok(importSource.includes('function renderDepartureBatch(batchRecords)'), 'WhatsApp importer must populate every parsed departure in the batch editor');
assert.ok(importSource.includes("name(index,'sponsor_id')") && importSource.includes("name(index,'sponsor_name_snapshot')"), 'batch rows submit master ID and editable name snapshot');
assert.ok(importSource.includes("name(index,'sponsor_mode')") && importSource.includes("'new_sponsor_name'") && importSource.includes("name(index,'new_sponsor_type')"), 'each batch record can explicitly select an existing sponsor or create a new sponsor with a type');
assert.ok(importSource.includes("const name=(index,field)=>'departures['+index+']['+field+']';") && importSource.includes("add(f.date,'departure_date','date','تاريخ المغادرة')"), 'each batch row submits its departure date using the indexed departures field name');
assert.ok(importSource.includes('function resolveDepartureSponsor('), 'departure matching resolves name and file number without a visible sponsor menu');
assert.ok(importSource.includes('الاسم موجود لأكثر من كفيل'), 'ambiguous same-name records require a unique file number');
assert.ok(!importSource.includes('رقم الملف في الرسالة لا يطابق رقم الملف المسجل؛ راجع الاختيار'), 'a unique name match is not rejected for a differing file number');
assert.ok(!importSource.includes('سيُحفظ الاسم ورقم الملف في سجل المغادرة فقط.'), 'the test-only departure snapshot hint must not be shown');
assert.ok(importSource.includes("hint.textContent=result.message||'';hint.hidden=!result.message"), 'departure resolution hints appear only when there is an actual matching issue');

assert.ok(importSource.includes("success=set($('#sponsorName'),value)"), 'entry WhatsApp import must populate the editable sponsor name directly');
assert.ok(importSource.includes("const fileInput=$('#entryForm').elements.file_number"), 'entry WhatsApp import must populate the editable file-number field without sponsor dropdown resolution');
assert.ok(entryRegisterSource.includes("fileNumber=q('#fileNumber')"), 'entry register must bind the editable file-number field');
assert.match(entryPageSource, /FROM mde_sponsors\s+ORDER BY id DESC\s+LIMIT 1/, 'the last-sponsor indicator must follow the newest sponsor master ID, not a monthly row');
assert.ok(!entryPageSource.includes('FROM mde_monthly_sponsorship_entries AS monthly'), 'the last-sponsor indicator must not infer the last new sponsor from a monthly row');
assert.ok(entryPageSource.includes('departure_date >= rel.start_date'), 'historical departures must close only sponsorship episodes effective by their date');
assert.ok(entryPageSource.includes('هذا اليتيم مسجل بالفعل في شهر التقرير نفسه، لكن مبالغ الكفالة أو الخدمات مختلفة'), 'same-month repeats with different amounts must stop the whole save for review');
assert.ok(entryPageSource.includes('$existingMonthlyStmt') && entryPageSource.includes('$skipMonthlyInsert'), 'an exact same-month allocation is skipped instead of being inserted again');
assert.ok(entryPageSource.includes('توجد بالفعل عدة إدخالات شهرية لهذا الكفيل واليتيم'), 'pre-existing multiple rows for the same sponsor/orphan/month stop the whole save');
assert.ok(fs.existsSync('api/check_entry_duplicates.php'), 'entry page has a server-side duplicate lookup endpoint');
assert.ok(fs.readFileSync('api/check_entry_duplicates.php', 'utf8').includes('me.report_month = ?'), 'duplicate lookup is scoped to the selected reporting month');
assert.ok(fs.readFileSync('database/migrations/009_allow_repeat_monthly_sponsorship_entries.sql', 'utf8').includes('DROP INDEX uq_mde_monthly_sponsorship'), 'migration 009 remains recorded as historical schema history');
assert.ok(fs.readFileSync('database/migrations/013_restore_monthly_sponsorship_uniqueness.sql', 'utf8').includes('ADD UNIQUE KEY uq_mde_monthly_sponsor_orphan_month'), 'migration 013 restores database-level sponsor/orphan/month uniqueness');
assert.ok(fs.readFileSync('database/schema.sql', 'utf8').includes('UNIQUE KEY uq_mde_monthly_sponsor_orphan_month'), 'fresh database schema enforces sponsor/orphan/month uniqueness');
const duplicateWarningSource = fs.readFileSync('assets/js/entry-duplicate-warning.js', 'utf8');
assert.ok(duplicateWarningSource.includes('فسيتخطاه الحفظ ولن يكرر الإجمالي'), 'duplicate warning explains that an identical allocation is skipped');
assert.ok(duplicateWarningSource.includes('فسيُوقف الحفظ للمراجعة'), 'duplicate warning explains that differing amounts stop the save');
assert.ok(entryPageSource.includes("$link['status'] === 'ended'"), 'historical monthly entries must reuse the sponsorship episode whose dates cover the entry');
assert.ok(entryPageSource.includes('recorded_end_date'), 'relationship matching must respect the effective end date from departure history');
assert.ok(entryPageSource.includes('$futureLinks'), 'an earlier entry must not be attached to a later sponsorship episode');
assert.ok(departurePageSource.includes('start_date <= ?'), 'a past departure must not end a later sponsorship episode');
const departureColGroup = departurePageSource.match(/<colgroup>([\s\S]*?)<\/colgroup>/);
assert.ok(departureColGroup, 'departure register must retain a column group');
assert.equal((departureColGroup[1].match(/<col style=/g) || []).length, 10, 'departure register must retain one width per table column');
assert.ok(!departurePageSource.includes("if (!(int) $sponsor['is_active'])"), 'inactive sponsors must remain eligible for monthly departure entry');
assert.ok(!departurePageSource.includes('id="departureReason"'), 'departure form must not invent a separate leave-reason field');
assert.ok(!importSource.includes('مسجل كمغادر سابقاً. لم يتم تحويله'), 'the importer must not reject a previously departed sponsor');
assert.ok(reportPageSource.includes("COALESCE(NULLIF(d.sponsor_name_snapshot, ''), sp.sponsor_name) AS sponsor_name"), 'reports display the saved departure name snapshot with legacy fallback');
assert.ok(exportSource.includes("COALESCE(NULLIF(d.sponsor_name_snapshot, ''), sp.sponsor_name) AS sponsor_name"), 'exports display the saved departure name snapshot with legacy fallback');
assert.ok(!reportPageSource.includes('duplicate_in_other_month') && !reportPageSource.includes('مكرر في شهر آخر'), 'cross-month departures are valid monthly records and must not be labeled as duplicates in the report');
assert.ok(!exportSource.includes('duplicate_in_other_month') && !exportSource.includes('مكرر في شهر آخر'), 'downloaded reports must preserve valid cross-month departure rows without duplicate warnings');
assert.ok(exportSource.includes('$departures = $departureStmt->fetchAll();') && !exportSource.includes('مكرر في شهر آخر'), 'XLSX/CSV/PDF exports must preserve monthly departure rows without injecting cross-month duplicate text');

assert.deepEqual(Array.from(parser.allocateEvenly('20000', 2)), ['10000', '10000'], 'an evenly divisible sponsorship total should be split equally');
assert.deepEqual(Array.from(parser.allocateEvenly('4000', 2)), ['2000', '2000'], 'an evenly divisible service total should be split equally');
assert.equal(parser.allocateEvenly('20000', 3), null, 'a total with a remainder must require manual allocation');
assert.equal(parser.allocateEvenly('4001', 2), null, 'service totals with a remainder must require manual allocation');
assert.deepEqual(Array.from(parser.allocateEvenly('0', 3)), ['0', '0', '0'], 'zero totals should still allocate evenly');
assert.equal(parser.allocateEvenly('', 2), null, 'missing totals must not be allocated');
assert.equal(parser.allocateEvenly('100', 0), null, 'allocation must not proceed without orphans');
assert.equal(parser.allocateEvenly('-100', 2), null, 'negative totals must not be allocated');
assert.equal(parser.allocateEvenly('9007199254740992', 2), null, 'unsafe integer totals must not be allocated');

const entryMessage = 'السلام عليكم؛ الكفيلة = دعاء حسين شمس الدين حسين؛ اليتيم = رنا عبدالله محمد؛ الأم = عائشة عبدالله علي؛ كفالة 5000؛ خدمات 1000؛ 🌳؛ تاريخ الانضمام: 1/11/2026؛ عن طريق هناء خلف الله؛ مشرفه ح ميساء';
const entryRecord = parser.entry(parser.split(entryMessage)[0], 0);
assert.equal(entryRecord.sponsor, 'دعاء حسين شمس الدين حسين');
assert.equal(entryRecord.families[0].mother, 'عائشة عبدالله علي');
assert.equal(entryRecord.orphans[0], 'رنا عبدالله محمد');
assert.equal(entryRecord.sponsorship, '5000');
assert.equal(entryRecord.services, '1000');
assert.equal(entryRecord.type, 'tree');
assert.equal(entryRecord.date, '2026-11-01');
assert.equal(entryRecord.entered, 'هناء خلف الله');
assert.equal(entryRecord.supervisor, 'ميساء', 'the ح after مشرفه is a role descriptor, not part of the supervisor name');


const treeGroupMentionEntry = parser.entry('الكفيل: ناصر كباشي احمد\nالأم: عائشة محمد\nاليتيم: سارة محمد\nداخل قروب الشجرة رجال\nالتاريخ: 03/11/2026', 0);
assert.equal(treeGroupMentionEntry.type, '', 'mentioning a tree WhatsApp group in a note must not reclassify an individual sponsor');
const explicitTreeEntry = parser.entry('الكفيلة: دعاء حسين شمس الدين حسين؛ اليتيم: رنا عبدالله محمد؛ الأم: عائشة عبدالله علي؛ كفالة 5000؛ خدمات 1000؛ 🌳؛ تاريخ الانضمام: 1/11/2026', 0);
assert.equal(explicitTreeEntry.type, 'tree', 'an explicit tree marker must still classify a tree sponsor');

const inlineFieldsMessage = 'الكفيل ناصر كباشي احمد ملف 2026 اسم الوالدة عائشة محمد اسم اليتيم سارة محمد';
const inlineFields = parser.entry(parser.split(inlineFieldsMessage)[0], 0);
assert.equal(inlineFields.sponsor, 'ناصر كباشي احمد', 'sponsor name must stop before the file label');
assert.equal(inlineFields.file, '2026', 'file number must be extracted into its own field');
assert.equal(inlineFields.families[0].mother, 'عائشة محمد', 'mother aliases should be recognized without punctuation separators');
assert.equal(inlineFields.orphans[0], 'سارة محمد', 'orphan labels should be recognized without punctuation separators');
assert.equal(parser.hasMotherNames(inlineFields), true, 'a record with a recognized mother name may be loaded');

const hamzaVariant = parser.entry('الكفيل ناصر كباشي احمد ملف 2026 اسم الام عائشة محمد اسم اليتيم سارة محمد', 0);
assert.equal(hamzaVariant.families[0].mother, 'عائشة محمد', 'mother labels without hamza should be recognized');
const taaMarbutaVariant = parser.entry('الكفيل ناصر كباشي احمد ملف 2026 الوالده عائشة محمد اليتيم سارة محمد', 0);
assert.equal(taaMarbutaVariant.families[0].mother, 'عائشة محمد', 'mother labels using ه spelling should be recognized');


const numberedOrphansAfterMother = `الكفيل : ناصر كباشي احمد  ملف 2026
الام :حليمه عمر محمد
1/اليتيم : عائشه محمد
2/ اليتيم : عبدالحميد محمد
المبلغ : 20000
خدمات : 4000
تاريخ الانضمام: 1/11/2026
❤️‍🔥🧨
عن طريق مها
مشرفه ح مها`;
const numberedMotherRecord = parser.entry(parser.split(numberedOrphansAfterMother)[0], 0);
assert.equal(numberedMotherRecord.sponsor, 'ناصر كباشي احمد');
assert.equal(numberedMotherRecord.file, '2026');
assert.equal(numberedMotherRecord.families.length, 1);
assert.equal(numberedMotherRecord.families[0].mother, 'حليمه عمر محمد', 'mother value must stop before numbered orphan labels');
assert.deepEqual(Array.from(numberedMotherRecord.families[0].orphans), ['عائشه محمد', 'عبدالحميد محمد']);
assert.equal(numberedMotherRecord.sponsorship, '20000');
assert.equal(numberedMotherRecord.services, '4000');
assert.equal(numberedMotherRecord.date, '2026-11-01');
assert.equal(numberedMotherRecord.entered, 'مها');
assert.equal(numberedMotherRecord.supervisor, 'مها', 'the role descriptor ح must not be included in the supervisor name');
assert.equal(parser.hasMotherNames(numberedMotherRecord), true, 'the exact user message must be loadable after extracting its mother');


const continuationOrphansMessage = `الكفيلة: رندا سرالختم خوجلي
ملف 24
الام: خالدة بابكر عبدالعال
اليتيم: تقي سيف الدين
الام: زبيدة الحاج احمد
اليتيم: محمد بابكر
         افضال بابكر
الام: انتصار قسم السيد
اليتيم: مزار صبري
الام: نجلاء موسي احمد
اليتيم: رتاج مهند كمال
الام: مني الخير بابكر
اليتيم: علي عصام علي
الام: سارة محمد الحسن
اليتيم: احمد نصر الدين
الام: انعام علي سرالختم
اليتيم: مدثر جمال الدين
         متاب جمال الدين
الام: آمنة الشريف طيفور
اليتيم: علي محمد احمد
الام: افكار هاشم مجذوب
اليتيم: متوكل محمد الشيخ
المبلغ: 220000
الخدمات :22000
التاريخ 3/11/2026
عن طريق ميساء
مشرفة ح ميساء`;
const continuationRecord = parser.entry(parser.split(continuationOrphansMessage)[0], 0);
assert.equal(continuationRecord.families.length, 9, 'the source message has nine mothers');
assert.deepEqual(Array.from(continuationRecord.families[1].orphans), ['محمد بابكر', 'افضال بابكر'], 'an indented orphan continuation belongs to the preceding mother');
assert.deepEqual(Array.from(continuationRecord.families[6].orphans), ['مدثر جمال الدين', 'متاب جمال الدين'], 'a second indented orphan continuation belongs to its preceding mother');
assert.equal(continuationRecord.orphans.length, 11, 'all eleven orphans must be counted');
assert.equal(continuationRecord.sponsorship, '220000');
assert.equal(continuationRecord.services, '22000');
assert.deepEqual(Array.from(parser.allocateEvenly(continuationRecord.sponsorship, continuationRecord.orphans.length)), Array(11).fill('20000'), 'the combined sponsorship total must split into 20,000 per orphan');
assert.deepEqual(Array.from(parser.allocateEvenly(continuationRecord.services, continuationRecord.orphans.length)), Array(11).fill('2000'), 'the combined services total must split into 2,000 per orphan');
assert.equal(continuationRecord.date, '2026-11-03');
assert.equal(continuationRecord.entered, 'ميساء');
assert.equal(continuationRecord.supervisor, 'ميساء');
assert.equal(parser.hasMotherNames(continuationRecord), true, 'the complete message must be eligible for loading');

const missingMother = parser.entry('الكفيل ناصر كباشي احمد ملف 2026 اليتيم سارة محمد', 0);
assert.equal(parser.hasMotherNames(missingMother), false, 'a record without a mother name must not be loadable');


const whatsappExample1 = `الكفيل : ناصر كباشي احمد  ملف 2026
1/اليتيم : عائشه محمد
2/ اليتيم : عبدالحميد محمد
المبلغ : 20000
خدمات : 4000
تاريخ الانضمام: 1/11/2026
❤️‍🔥🧨
عن طريق مها
مشرفه ح مها`;
const example1 = parser.entry(parser.split(whatsappExample1)[0], 0);
assert.equal(example1.sponsor, 'ناصر كباشي احمد', 'example 1 sponsor must not absorb the file number');
assert.equal(example1.file, '2026', 'example 1 file number must be separate');
assert.deepEqual(Array.from(example1.orphans), ['عائشه محمد', 'عبدالحميد محمد'], 'numbered orphan labels should both be extracted');
assert.equal(example1.sponsorship, '20000');
assert.equal(example1.services, '4000');
assert.equal(example1.date, '2026-11-01');
assert.equal(example1.entered, 'مها');
assert.equal(example1.supervisor, 'مها');
assert.equal(parser.hasMotherNames(example1), false, 'example 1 has no mother label and must be blocked');

const whatsappExample2 = `الكفيل :سناء عبدالناصر بابكر
الام :رويدا بشير السماني
 الطفل :لنا
 المبلغ :10.000
الخدمات 2000
التاريخ :2/11/2026
❤️‍🔥
عن طريق لمياء
مشرفه ح هبه خلف الله`;
const example2 = parser.entry(parser.split(whatsappExample2)[0], 0);
assert.equal(example2.sponsor, 'سناء عبدالناصر بابكر');
assert.equal(example2.families[0].mother, 'رويدا بشير السماني', 'unhamzated mother label should be parsed');
assert.deepEqual(Array.from(example2.orphans), ['لنا'], 'child label should be treated as orphan name');
assert.equal(example2.sponsorship, '10000', 'thousands punctuation should be normalized');
assert.equal(example2.services, '2000');
assert.equal(example2.date, '2026-11-02');
assert.equal(example2.entered, 'لمياء');
assert.equal(example2.supervisor, 'هبه خلف الله');
assert.equal(parser.hasMotherNames(example2), true);

const whatsappExample3 = `الكفيل :ممدوح عصمت عبدالله
 الام :وصال هاشم سعد
 الطفل :تفاؤل
 المبلغ :20.000
الخدمات :2000
التاريخ :2/11/2026
❤️‍🔥
 التواصل عن طريق اماني محي الدين ابو الفتوح
 اي مبلغ اضافي يوضع لتغطية الالتزامات السنويه
عن طريق لمياء
مشرفه ح مها`;
const example3 = parser.entry(parser.split(whatsappExample3)[0], 0);
assert.equal(example3.sponsor, 'ممدوح عصمت عبدالله');
assert.equal(example3.families[0].mother, 'وصال هاشم سعد');
assert.deepEqual(Array.from(example3.orphans), ['تفاؤل']);
assert.equal(example3.sponsorship, '20000');
assert.equal(example3.services, '2000');
assert.equal(example3.date, '2026-11-02');
assert.equal(example3.entered, 'لمياء', 'the final operational entry-by label should win over the contact phrase');
assert.equal(example3.supervisor, 'مها');
assert.match(example3.notes, /التواصل عن طريق: اماني محي الدين ابو الفتوح/, 'contact details should be preserved in notes');
assert.match(example3.notes, /أي مبلغ إضافي يوضع لتغطية الالتزامات السنويه/, 'additional-amount instruction should be preserved in notes');
assert.equal(parser.hasMotherNames(example3), true);

const supervisorWithoutDescriptor = parser.entry('الكفيل: اختبار؛ الأم: أم اختبار؛ اليتيم: يتيم اختبار؛ مشرفه مها', 0);
assert.equal(supervisorWithoutDescriptor.supervisor, 'مها', 'the parser should also accept a supervisor name without the ح descriptor');

const groupedMessage = 'الكفيل: شركة الخير؛ اليتيم: سعيد وائل؛ الأم: عائشة محمد؛ اليتيم: أحمد محمد؛ الأم: أمل علي؛ اليتيم: سارة محمد';
const grouped = parser.entry(parser.split(groupedMessage)[0], 0);
assert.equal(grouped.families.length, 2, 'multiple mothers should remain separate families');
assert.equal(grouped.families[0].mother, 'عائشة محمد');
assert.equal(grouped.families[0].orphans[0], 'سعيد وائل', 'compound Arabic names must not be split on و');
assert.equal(grouped.families[0].orphans[1], 'أحمد محمد');
assert.equal(grouped.families[1].mother, 'أمل علي');
assert.equal(grouped.families[1].orphans[0], 'سارة محمد');

const multiMessage = 'الكفيل: ناصر كباشي احمد؛ الأم: عائشة محمد؛ 1/اليتيم: أحمد محمد؛ 2/اليتيم: عبدالحميد محمد؛ كفالة 20000؛ الكفيل: دعاء حسين؛ الأم: أمل محمد؛ اليتيم: سارة محمد؛ كفالة 5000';
const splitRecords = parser.split(multiMessage);
assert.equal(splitRecords.length, 2, 'multiple sponsor records should be separated');
assert.equal(parser.entry(splitRecords[0], 0).sponsor, 'ناصر كباشي احمد');
assert.equal(parser.entry(splitRecords[1], 1).sponsor, 'دعاء حسين');

const multiMotherNumberedMessage = `الكفيل جلال سعيد جلال عشري❤️‍🔥
الام: مروة محمد نصر
1-اليتيم :محمود مزمل محمد
الام: وصال هاشم سعد
2_اليتيم:صابرين ادم ادريس
3-اليتيم:رناد ادم إدريس
الكفالة: 60،000
الخدمات 6،000
تاريخ الانضمام  2/11/2026
مقدم كفالة سنه
كرتونه رمضان100،00لكل يتيم📦
فرحة عيدين 100،000 لكل يتيم❤️
متبقي المبلغ 108،000 مشروع سندس واستبرق👻`;
const multiMotherNumbered = parser.entry(parser.split(multiMotherNumberedMessage)[0], 0);
assert.equal(multiMotherNumbered.families.length, 2, 'the source message has two distinct mothers');
assert.equal(multiMotherNumbered.families[0].mother, 'مروة محمد نصر');
assert.deepEqual(Array.from(multiMotherNumbered.families[0].orphans), ['محمود مزمل محمد'], 'the first mother has one orphan');
assert.equal(multiMotherNumbered.families[1].mother, 'وصال هاشم سعد');
assert.deepEqual(Array.from(multiMotherNumbered.families[1].orphans), ['صابرين ادم ادريس', 'رناد ادم إدريس'], 'the second mother has two orphans, including underscore-numbered labels');
assert.deepEqual(Array.from(multiMotherNumbered.orphans), ['محمود مزمل محمد', 'صابرين ادم ادريس', 'رناد ادم إدريس']);
assert.equal(multiMotherNumbered.sponsorship, '60000');
assert.equal(multiMotherNumbered.services, '6000');
assert.equal(multiMotherNumbered.date, '2026-11-02');
assert.match(multiMotherNumbered.notes, /مقدم كفالة سنة/, 'standalone annual sponsorship note is preserved');
assert.match(multiMotherNumbered.notes, /كرتونه رمضان: 100،00لكل يتيم/);
assert.match(multiMotherNumbered.notes, /فرحة عيدين: 100،000 لكل يتيم/);
assert.match(multiMotherNumbered.notes, /متبقي المبلغ: 108،000 مشروع سندس واستبرق/);
assert.deepEqual(Array.from(parser.allocateEvenly(multiMotherNumbered.sponsorship, multiMotherNumbered.orphans.length)), ['20000', '20000', '20000'], 'the 60000 sponsorship total must allocate across all three orphans');
assert.deepEqual(Array.from(parser.allocateEvenly(multiMotherNumbered.services, multiMotherNumbered.orphans.length)), ['2000', '2000', '2000'], 'the 6000 services total must allocate across all three orphans');

const departureMessage = 'الكافل: ناصر كباشي احمد؛ رقم الملف 2026؛ تاريخ المغادرة: 2026-11-02؛ آخر سداد: 10/2026؛ عدد الأيتام: 2؛ قيمة الكفالة: 20000؛ قيمة الخدمات: 4000؛ سبب المغادرة: توقف الكفالة';
const departure = parser.departure(parser.split(departureMessage)[0], 0);
assert.equal(departure.sponsor, 'ناصر كباشي احمد');
assert.equal(departure.file, '2026');
assert.equal(departure.date, '2026-11-02');
assert.equal(departure.lastPaid, '2026-10');
assert.equal(departure.count, '2');
assert.equal(departure.sponsorship, '20000');
assert.equal(departure.services, '4000');
assert.equal(departure.reason, 'توقف الكفالة');


// Real message: orphan listed before each mother, spelling variants (الكفاله / الايتام), "10000خدمات", "لكل يتيم", "بتاريخ 3نوفمبر", emoji in sponsor name.
const pairedMessage = ['الكفيل ابوبكر حسين محمد ❤️‍🔥','الايتام محمد متولي','الأم هاديه عبد الجليل','اليتيم حاتم','الأم صفاء محمد احمد','اليتيم يوسف ابكر','الأم حليمه يوسف','اليتيم لينه صالح','الأم سناء عثمان','اليتيم زينب','الأم خديجه قسم السيد','مبلغ الكفاله 50000','10000خدمات','لكل يتيم 10000','بتاريخ 3نوفمبر','تواصل ابوبكر مع زوجته هبه عايزه قروب','عن طريق هبه خلف الله','مشرفه ح هناء خ / فاطمه'].join('\n');

const pairedRecord = parser.entry(pairedMessage, 0, '2026-11-04');
assert.equal(pairedRecord.sponsor, 'ابوبكر حسين محمد');
assert.deepEqual(JSON.parse(JSON.stringify(pairedRecord.families)), [
  { mother: 'هاديه عبد الجليل', orphans: ['محمد متولي'] },
  { mother: 'صفاء محمد احمد', orphans: ['حاتم'] },
  { mother: 'حليمه يوسف', orphans: ['يوسف ابكر'] },
  { mother: 'سناء عثمان', orphans: ['لينه صالح'] },
  { mother: 'خديجه قسم السيد', orphans: ['زينب'] }
]);
assert.equal(pairedRecord.sponsorship, '50000');
assert.equal(pairedRecord.services, '10000');
assert.equal(pairedRecord.each, '10000');
assert.equal(pairedRecord.date, '2026-11-03', 'a month-name date uses the selected form context year, never the computer clock');
assert.equal(pairedRecord.entered, 'هبه خلف الله');
assert.ok(pairedRecord.notes.includes('تواصل ابوبكر مع زوجته'));

const sharedFieldsMessage = multiMessage + '\nعن طريق هناء خلف الله\nمشرفه ح ميساء';
const sharedRecords = parser.applyMessageLevelFields(
  parser.split(sharedFieldsMessage).map((part, i) => parser.entry(part, i)),
  sharedFieldsMessage
);
assert.equal(sharedRecords.length, 2);
assert.deepEqual(Array.from(sharedRecords, r => r.entered), ['هناء خلف الله', 'هناء خلف الله'], 'all sponsor records from one paste share one operator');
assert.deepEqual(Array.from(sharedRecords, r => r.supervisor), ['ميساء', 'ميساء'], 'all sponsor records from one paste share one independent supervisor');




// Exact real-world failure reported on 2026-11-04: unlabelled sponsor,
// two unnumbered orphan lines, file wording variation, and a month-name date.
const exactWhatsAppMessage = [
  'سلمي حسن الرشيد',
  'الايتام حمود محمد',
  'مبارك محمد',
  'الأم اخلاص احمد محمد',
  'مبلغ الكفاله 80000',
  'الخدمات 5000',
  'بتاريخ 3نوفمبر',
  'رجعت لملفها 24',
  'عن طريق هبه خلف الله',
  'مشرفه ح هبه خلف الله'
].join('\n');
const exactRecord = parser.entry(exactWhatsAppMessage, 0, '2026-11-04');
assert.equal(exactRecord.sponsor, 'سلمي حسن الرشيد', 'first unlabelled name is the sponsor when it precedes all family labels');
assert.equal(exactRecord.families[0].mother, 'اخلاص احمد محمد');
assert.deepEqual(Array.from(exactRecord.families[0].orphans), ['حمود محمد', 'مبارك محمد'], 'unindented continuation line is a second orphan');
assert.equal(exactRecord.sponsorship, '80000');
assert.equal(exactRecord.services, '5000');
assert.equal(exactRecord.date, '2026-11-03', '3نوفمبر takes its year from the form/report context');
assert.equal(exactRecord.file, '24', 'رجعت لملفها 24 is a file-number label');
assert.equal(exactRecord.entered, 'هبه خلف الله');
assert.equal(exactRecord.supervisor, 'هبه خلف الله');
assert.equal(exactRecord.raw, exactWhatsAppMessage, 'the original message remains attached to the parsed record');
assert.equal(parser.hasMotherNames(exactRecord), true, 'the exact previously failing message remains loadable');
assert.deepEqual(Array.from(exactRecord.warnings), [], 'the complete exact message has no required-field warnings');

// A yearless date must not be guessed from the computer clock.
const unresolvedDate = parser.entry(exactWhatsAppMessage, 0);
assert.equal(unresolvedDate.date, '');
assert.ok(unresolvedDate.warnings.some(w => w.includes('التاريخ')));
assert.equal(unresolvedDate.raw, exactWhatsAppMessage);

// Date parsing supports Arabic and Western digits, month names, and explicit years.
assert.equal(parser.parseDate('3نوفمبر', '2027-11-01'), '2027-11-03');
assert.equal(parser.parseDate('٣ نوفمبر', '2027-11-01'), '2027-11-03');
assert.equal(parser.parseDate('03/11/2026', ''), '2026-11-03');
assert.equal(parser.parseDate('2026-11-03', ''), '2026-11-03');
assert.equal(parser.parseDate('31/02/2026', '2026-11-01'), '', 'invalid calendar dates are rejected');

// File-number wording variants.
const fileVariants = parser.entry('الكفيل: ناصر كباشي احمد\nالأم: عائشة محمد\nاليتيم: سارة محمد\nرجعت لملفها 24\nالتاريخ: 03/11/2026', 0);
assert.equal(fileVariants.file, '24');

// Do not mistake an orphan-first message for a sponsor.
const orphanFirstNoSponsor = parser.entry('الايتام حمود محمد\nمبارك محمد\nالأم اخلاص احمد محمد\nالتاريخ 03/11/2026', 0);
assert.equal(orphanFirstNoSponsor.sponsor, '');
assert.equal(orphanFirstNoSponsor.families[0].mother, 'اخلاص احمد محمد');
assert.deepEqual(Array.from(orphanFirstNoSponsor.orphans), ['حمود محمد', 'مبارك محمد']);

// Arabic-Indic and Western amounts, punctuation, and whitespace normalize identically.
const numericVariants = parser.entry('الكفيل: ناصر كباشي احمد\nالأم: عائشة محمد\nاليتيم: سارة محمد\nمبلغ الكفاله ٨٠٬٠٠٠\nالخدمات ٥,٠٠٠\nالتاريخ 3/11/2026', 0);
assert.equal(numericVariants.sponsorship, '80000');
assert.equal(numericVariants.services, '5000');


const femaleSponsorMessage = "الكفيلة/ شيماء عثمان عبدالقادر محمد\nالام ندي يوسف محمد\nاليتيم علي هاشم محمد سنادة\nاليتيم رزاز هاشم محمد سنادة\nالمبلغ: 26،000\nخدمات 4000\nالانضمام:4/11/2026\n\n❤️‍🔥\nعن طريق ايمان\nمشرفه ح هبه خلف الله";
const femaleSponsorRecord = parser.entry(femaleSponsorMessage, 0, '');
assert.equal(femaleSponsorRecord.sponsor, 'شيماء عثمان عبدالقادر محمد', 'الكفيلة/ must extract a female sponsor name');
assert.equal(femaleSponsorRecord.date, '2026-11-04', 'الانضمام with a full dd/mm/yyyy date must be parsed');
assert.equal(femaleSponsorRecord.mother, 'ندي يوسف محمد');
assert.deepEqual(Array.from(femaleSponsorRecord.orphans), ['علي هاشم محمد سنادة', 'رزاز هاشم محمد سنادة']);
assert.equal(femaleSponsorRecord.sponsorship, '26000');
assert.equal(femaleSponsorRecord.services, '4000');
assert.equal(femaleSponsorRecord.entered, 'ايمان');
assert.equal(femaleSponsorRecord.supervisor, 'هبه خلف الله');
assert.equal(femaleSponsorRecord.unrecognizedLines.length, 0, 'all labeled lines in the supplied message should be recognized');

// Message-level values override every record only when present in the trailing
// message-level footer; absent shared values never erase record-level values.
const recordsWithOwnPeople = [
  { sponsor: 'الأول', entered: 'مها', supervisor: 'سارة' },
  { sponsor: 'الثاني', entered: 'لمياء', supervisor: 'فاطمة' }
];
const noSharedFooter = 'الكفيل: الأول\nعن طريق مها\nمشرفه ح سارة\nالكفيل: الثاني\nعن طريق لمياء\nمشرفه ح فاطمة';
const preservedPeople = parser.applyMessageLevelFields(recordsWithOwnPeople, noSharedFooter);
assert.deepEqual(Array.from(preservedPeople, r => r.entered), ['مها', 'لمياء']);
assert.deepEqual(Array.from(preservedPeople, r => r.supervisor), ['سارة', 'فاطمة']);
const sharedFooter = 'الكفيل: الأول\nالأم: أم أولى\nاليتيم: يتيم أول\nالكفالة: 1000\nالكفيل: الثاني\nالأم: أم ثانية\nاليتيم: يتيم ثان\nالكفالة: 2000\nعن طريق هناء خلف الله\nمشرفه ح ميساء';
const sharedPeople = parser.applyMessageLevelFields(recordsWithOwnPeople, sharedFooter);
assert.deepEqual(Array.from(sharedPeople, r => r.entered), ['هناء خلف الله', 'هناء خلف الله']);
assert.deepEqual(Array.from(sharedPeople, r => r.supervisor), ['ميساء', 'ميساء']);

// Independent operator and supervisor extraction: one may be shared without
// forcing the other to change.
const sharedOperatorOnly = parser.applyMessageLevelFields(recordsWithOwnPeople,
  'الكفيل: الأول\nالكفيل: الثاني\nعن طريق هناء خلف الله');
assert.deepEqual(Array.from(sharedOperatorOnly, r => r.entered), ['هناء خلف الله', 'هناء خلف الله']);
assert.deepEqual(Array.from(sharedOperatorOnly, r => r.supervisor), ['سارة', 'فاطمة']);

// Form payload and population contract: the same adapter used by the browser
// receives the exact values, family structure, and allocation plan.
const formPayload = parser.buildEntryFormPayload(exactRecord);
assert.deepEqual(JSON.parse(JSON.stringify(formPayload.families)), [
  { mother: 'اخلاص احمد محمد', orphans: ['حمود محمد', 'مبارك محمد'] }
]);
assert.equal(formPayload.fields.sponsor, 'سلمي حسن الرشيد');
assert.equal(formPayload.fields.file, '24');
assert.equal(formPayload.fields.date, '2026-11-03');
assert.equal(formPayload.fields.entered, 'هبه خلف الله');
assert.equal(formPayload.fields.supervisor, 'هبه خلف الله');
assert.equal(formPayload.expectedSponsorship, '80000');
assert.equal(formPayload.expectedServices, '5000');
assert.deepEqual(Array.from(formPayload.sponsorshipAllocation), ['40000', '40000']);
assert.deepEqual(Array.from(formPayload.servicesAllocation), ['2500', '2500']);
const applied = { fields: {}, families: null, amounts: null, updated: false };
const populateResult = parser.populateEntryForm(formPayload, {
  setField(name, value) { applied.fields[name] = value; return true; },
  fieldWasSet() { return true; },
  setFamilies(value) { applied.families = value; },
  setAmounts(value) { applied.amounts = value; },
  updateTotals() { applied.updated = true; }
});
assert.equal(applied.fields.sponsor, 'سلمي حسن الرشيد');
assert.equal(applied.fields.file, '24');
assert.equal(applied.fields.date, '2026-11-03');
assert.equal(applied.fields.entered, 'هبه خلف الله');
assert.equal(applied.fields.supervisor, 'هبه خلف الله');
assert.equal(applied.fields.notes, '');
assert.equal(applied.families[0].mother, 'اخلاص احمد محمد');
assert.deepEqual(Array.from(applied.families[0].orphans), ['حمود محمد', 'مبارك محمد']);
assert.equal(applied.amounts.expectedSponsorship, '80000');
assert.equal(applied.amounts.expectedServices, '5000');
assert.equal(applied.updated, true);
assert.deepEqual(JSON.parse(JSON.stringify(populateResult)), { sponsorshipManual: false, servicesManual: false });

// Unknown optional values remain empty; no value is fabricated.
const optionalRecord = parser.entry('الكفيل: ناصر كباشي احمد\nالأم: عائشة محمد\nاليتيم: سارة محمد\nالتاريخ: 03/11/2026', 0);
const optionalPayload = parser.buildEntryFormPayload(optionalRecord);
assert.equal(optionalPayload.fields.file, '');
assert.equal(optionalPayload.fields.entered, '');
assert.equal(optionalPayload.fields.supervisor, '');
assert.equal(optionalPayload.expectedSponsorship, '');
assert.equal(optionalPayload.expectedServices, '');

// The shared supervisor can be supplied independently of the shared operator.
const sharedSupervisorOnly = parser.applyMessageLevelFields(recordsWithOwnPeople,
  'الكفيل: الأول\nالكفيل: الثاني\nمشرفه ح هناء خلف الله');
assert.deepEqual(Array.from(sharedSupervisorOnly, r => r.entered), ['مها', 'لمياء']);
assert.deepEqual(Array.from(sharedSupervisorOnly, r => r.supervisor), ['هناء خلف الله', 'هناء خلف الله']);

// Unknown non-name lines are surfaced for review while the raw message is retained.
const unknownLineMessage = 'ناصر كباشي احمد\nالأم: عائشة محمد\nاليتيم: سارة محمد\n!!! ???\nالتاريخ: 03/11/2026';
const unknownLineRecord = parser.entry(unknownLineMessage, 0);
assert.ok(unknownLineRecord.warnings.some(w => w.includes('أسطر غير مصنفة')));
assert.equal(unknownLineRecord.raw, unknownLineMessage);



// Dropdown values that do not match the configured options are not allowed to
// leave a stale selection behind; the original extracted names are retained in notes.
const fallbackPayload = parser.buildEntryFormPayload({
  ...exactRecord, entered: 'مستخدم غير موجود', supervisor: 'مشرف غير موجود'
});
const fallbackFields = {};
parser.populateEntryForm(fallbackPayload, {
  setField(name, value) { fallbackFields[name] = value; return name !== 'entered' && name !== 'supervisor'; },
  fieldWasSet(name) { return name !== 'entered' && name !== 'supervisor'; },
  setFamilies() {},
  setAmounts() {}
});
assert.match(fallbackFields.notes, /بواسطة: مستخدم غير موجود/);
assert.match(fallbackFields.notes, /المشرف: مشرف غير موجود/);


// Departure messages use numbered records, file-context headings, and a
// separate tree section rather than explicit "الكفيل:" labels.
const groupedDepartures = [
  '⛔كفلاء طلعو⛔',
  'ملف 25',
  '1• انس فتح الرحمن احمد محمد',
  'عدد الأيتام: 10',
  'قيمة الكفالة: 100000',
  'الخدمات: 20000',
  'آخر سداد: سبتمبر',
  'بتاريخ: 31/10/2026',
  'ملاحظات: ما داخل قروب والاتصال غير متاح',
  '2• التجاني محمد الشايب',
  'عدد الأيتام: 1',
  'قيمة الكفالة: 27000',
  'الخدمات: 2000',
  'آخر سداد: أكتوبر',
  'بتاريخ: 31/10/2026',
  'ملف 24',
  '3• نهي عثمان عبد الحليم',
  'عدد الأيتام: 1',
  'قيمة الكفالة: 10000',
  'الخدمات: 2000',
  'آخر سداد: أغسطس',
  'بتاريخ: 31/10/2026',
  'ملف الشجرة',
  'الطيب عثمان جمعه 🌳',
  '2000',
  'آخر سداد: أكتوبر',
  'بتاريخ: 31/10/2026'
].join('\n');
const groupedRecords = parser.splitDepartures(groupedDepartures, '2026-10-31');
assert.equal(groupedRecords.length, 4);
assert.deepEqual(Array.from(groupedRecords, r => r.sponsor), [
  'انس فتح الرحمن احمد محمد',
  'التجاني محمد الشايب',
  'نهي عثمان عبد الحليم',
  'الطيب عثمان جمعه'
]);
assert.deepEqual(Array.from(groupedRecords, r => r.file), ['25', '25', '24', '🌳']);
assert.deepEqual(Array.from(groupedRecords, r => r.sponsorship), ['100000', '27000', '10000', '2000']);
assert.deepEqual(Array.from(groupedRecords, r => r.services), ['20000', '2000', '2000', '']);
assert.deepEqual(Array.from(groupedRecords, r => r.lastPaid), ['2026-09', '2026-10', '2026-08', '2026-10']);
assert.equal(groupedRecords[0].notes.includes('ما داخل قروب'), true);
assert.equal(groupedRecords[3].type, 'tree');
assert.equal(groupedRecords[3].count, '', 'tree orphan count remains blank when the source omits it');
assert.ok(groupedRecords.every(r => r.date === '2026-10-31'));

// A single cancellation message must ignore its title/file heading and find
// the actual sponsor name; "ملف 2024" is retained as the supplied file value.
const departurePayload = parser.buildDepartureFormPayload({
  sponsor: 'انس فتح الرحمن احمد محمد', file: '25', date: '2026-10-31', lastPaid: '2026-09',
  count: '10', sponsorship: '100000', services: '20000', reason: 'عدم تواجده في الواتس والاتصال',
  notes: 'ما داخل قروب', entered: 'مها', supervisor: 'هبه', type: 'individual'
});
assert.equal(departurePayload.fields.sponsor, 'انس فتح الرحمن احمد محمد');
assert.equal(departurePayload.notes, 'عدم تواجده في الواتس والاتصال | ما داخل قروب', 'source reason must be retained without a label prefix');
assert.ok(!departurePayload.notes.includes('السبب كما ورد:'), 'departure notes must not add an explanatory prefix');

const groupMentionDeparture = parser.departure('انس فتح الرحمن احمد محمد\nملف 24\nداخل قروب الشجرة رجال 🌳\nتاريخ المغادرة: 31/10/2026\nعدد الأيتام: 1\nآخر سداد: سبتمبر', 0, '2026-11-04');
assert.equal(groupMentionDeparture.type, 'individual', 'a tree-group note, even with a decorative tree emoji, must not reclassify an individual departure');
assert.ok(!groupMentionDeparture.unrecognizedLines.includes('داخل قروب الشجرة رجال 🌳'), 'the tree-group note must be retained as explanatory text, not treated as an orphan sponsor name');

const singleCancellation = parser.splitDepartures([
  'إلغاء كفالة',
  'ملف 2024',
  'عثمان جمعة العجب',
  'عدد الأيتام: 1',
  'قيمة الكفالة: 10000',
  'الخدمات: 2000',
  'آخر سداد: أكتوبر',
  'بتاريخ: 31/10/2026',
  'عن طريق هناء عثمان',
  'ما داخل قروب'
].join('\n'), '2026-10-31');
assert.equal(singleCancellation.length, 1);
assert.equal(singleCancellation[0].sponsor, 'عثمان جمعة العجب');
assert.equal(singleCancellation[0].file, '2024');
assert.equal(singleCancellation[0].entered, 'هناء عثمان');
assert.equal(singleCancellation[0].notes.includes('ما داخل قروب'), true);
assert.equal(singleCancellation[0].lastPaid, '2026-10');


// Preserve the exact tree sponsor name from the pasted message even when the
// workbook contains a different name for the corresponding tree amount.
const pastedTreeName = parser.splitDepartures([
  'ملف الشجرة',
  'محمد عثمان جمعة 🌳',
  '2000',
  'آخر سداد: أكتوبر',
  'بتاريخ: 31/10/2026'
].join('\n'), '2026-10-31');
assert.equal(pastedTreeName[0].sponsor, 'محمد عثمان جمعة');
assert.equal(pastedTreeName[0].file, '🌳');


// Real-world October cancellation list: slash-numbered entries, a letter-file
// heading, compact amount labels, and file numbers inside each sponsor block.
const octoberCancellationMessage = [
  'قائمة إلغاء اكتوبر',
  '⛔',
  'ملف حرف ص ض ط ظ ق ف ك ل نساء',
  '1/ فاطمة هاشم محمد الامين',
  'عدد الايتام 4',
  'الكفالة62000',
  'خدمات8000',
  'اخر سداد اغسطس',
  'ملف24',
  'ماموجودة في القروب',
  '2/ لمياء عبدالرحمن بابكر',
  'عدد الايتام 1',
  'الكفالة20000',
  'الخدمات2000',
  'اخر سداد اغسطس',
  'ملف24',
  'ماموجودة في القروب',
  '3/ فاطمة هاشم ابوزلال',
  'عدد الايتام1',
  'كفالة10000',
  'خدمات 2000',
  'ملف26',
  'اخر سداد اغسطس',
  'ماموجودة في القروب',
  '4/ قنال عثمان عطاالعون',
  'عدد الايتام 4',
  'كفالة 40000',
  'خدمات8000',
  'ملف 26',
  'طلعت بدون عجز',
  'طلعت من القروب',
  '5/ كوثر عبدالله محمد',
  'عدد الايتام1',
  'كفالة20000',
  'خدمات3000',
  'ملف24',
  'اخر سداد سبتمبر',
  'ماموجودة في القروب',
  '6/ فاطمة عوض الطيب',
  'عدد الايتام 4',
  'كفالة 90000',
  'خدمات12000',
  'ملف24',
  'طلعت بدون عجز مسددة إلى شهر ديسمبر',
  'ماموجودة ف القروب'
].join('\n');
const octoberDepartures = parser.splitDepartures(octoberCancellationMessage, '2026-11-05');
assert.equal(octoberDepartures.length, 6, 'six numbered cancellations should produce six records');
assert.deepEqual(Array.from(octoberDepartures, r => r.sponsor), [
  'فاطمة هاشم محمد الامين',
  'لمياء عبدالرحمن بابكر',
  'فاطمة هاشم ابوزلال',
  'قنال عثمان عطاالعون',
  'كوثر عبدالله محمد',
  'فاطمة عوض الطيب'
]);
assert.deepEqual(Array.from(octoberDepartures, r => r.file), ['24','24','26','26','24','24']);
assert.deepEqual(Array.from(octoberDepartures, r => r.count), ['4','1','1','4','1','4']);
assert.deepEqual(Array.from(octoberDepartures, r => r.sponsorship), ['62000','20000','10000','40000','20000','90000']);
assert.deepEqual(Array.from(octoberDepartures, r => r.services), ['8000','2000','2000','8000','3000','12000']);
assert.deepEqual(Array.from(octoberDepartures, r => r.lastPaid), ['2026-08','2026-08','2026-08','','2026-09','']);
assert.ok(octoberDepartures[0].notes.includes('ماموجودة في القروب'));
assert.ok(octoberDepartures[3].notes.includes('طلعت بدون عجز'));
assert.ok(octoberDepartures[3].notes.includes('طلعت من القروب'));
assert.ok(octoberDepartures[5].notes.includes('مسددة إلى شهر ديسمبر'));
assert.ok(octoberDepartures.every(r => !r.sponsor.includes('قائمة إلغاء')));
assert.ok(octoberDepartures.every(r => !r.date), 'the source does not provide a day/date; do not invent one');
assert.ok(octoberDepartures.every(r => r.warnings.some(w => w.includes('تاريخ المغادرة'))), 'missing departure date must remain visible for manual completion');


// Real previous-month WhatsApp message: repeated/missing sequence numbers,
// unlabelled slash amount pairs, month-only last-payment values, one shared
// departure date at the end, and a tree sponsor with no service/count fields.
const mixedStyleDeparture = [
  'ملف 25',
  '1• انس فتح الرحمن احمد محمد',
  '100.000/20.000',
  'عدد الايتام 10',
  'ماداخل قروب',
  'السبب عدم تواجده في الواتس والاتصال',
  'غير متاح',
  'اخر سداد سبتمبر',
  '2•التجاني محمد الشايب',
  'سبتمبر /اكتوبر',
  'عدد الايتام 1',
  '27.000/2000',
  'داخل قروب الشجرة رجال ح ياخد فرصه لحين السداد',
  'السبب عدم الرد في الواتس والاتصال',
  '3• آمن عمر عبد الحليم',
  'اخر سداد اكتوبر',
  'عدد الايتام :2',
  '30000/4000',
  'طلب الالغاء ليس لديه علي المقدرة علي الالتزام',
  'ملف 24',
  '3• ابو عمر محمد يونس',
  'عددالايتام : 4',
  '50000/8000',
  'ماداخل قروب',
  'نسبة لتواجده في الخلا ح يرجع ويسدد',
  'اخر سداد سبتمبر',
  '5• ايهاب عمر علي',
  'عدد الايتام :1',
  'سبتمبر/اكتوبر',
  '28000/2000',
  'ما داخل قروب',
  'السبب عايز مهله لحين توفر المال وسيسدد',
  'ملف الشجرة',
  '6• الطيب عثمان جمعه',
  '2000',
  'اكتوبر',
  '31/10/2026'
].join('\n');
const mixedStyleRecords = parser.splitDepartures(mixedStyleDeparture, '2026-11-05');
assert.equal(mixedStyleRecords.length, 6, 'repeated/missing sequence numbers and numeric slash values must still produce six records');
assert.deepEqual(Array.from(mixedStyleRecords, r => r.sponsor), [
  'انس فتح الرحمن احمد محمد',
  'التجاني محمد الشايب',
  'آمن عمر عبد الحليم',
  'ابو عمر محمد يونس',
  'ايهاب عمر علي',
  'الطيب عثمان جمعه'
]);
assert.deepEqual(Array.from(mixedStyleRecords, r => r.file), ['25', '25', '25', '24', '24', '🌳']);
assert.deepEqual(Array.from(mixedStyleRecords, r => r.count), ['10', '1', '2', '4', '1', '']);
assert.deepEqual(Array.from(mixedStyleRecords, r => r.sponsorship), ['100000', '27000', '30000', '50000', '28000', '2000']);
assert.deepEqual(Array.from(mixedStyleRecords, r => r.services), ['20000', '2000', '4000', '8000', '2000', '']);
assert.deepEqual(Array.from(mixedStyleRecords, r => r.lastPaid), ['2026-09', '2026-10', '2026-10', '2026-09', '2026-10', '2026-10']);
assert.ok(mixedStyleRecords.every(r => r.date === '2026-10-31'), 'one final date applies to every record in this message');
assert.equal(mixedStyleRecords[1].type, 'individual', 'mentioning a tree group in a note does not make the sponsor a tree');
assert.equal(mixedStyleRecords[5].type, 'tree');
assert.equal(mixedStyleRecords[5].count, '', 'tree orphan count is not fabricated as zero');
assert.equal(mixedStyleRecords[5].sponsorship, '2000', 'a single tree amount is sponsorship');
assert.equal(mixedStyleRecords[5].services, '', 'an omitted tree service fee remains blank');
assert.match(mixedStyleRecords[1].notes, /سبتمبر.*اكتوبر/, 'the source month range is retained in notes');
assert.ok(mixedStyleRecords.every(r => r.warnings.length === 0), 'recognized source formats should not produce false unrecognized-line warnings');
const mixedStyleWithoutFormDate = parser.splitDepartures(mixedStyleDeparture, '');
assert.ok(mixedStyleWithoutFormDate.every(r => r.date === '2026-10-31'), 'the message date supplies the departure year when the form date is blank');
assert.deepEqual(Array.from(mixedStyleWithoutFormDate, r => r.lastPaid), ['2026-09', '2026-10', '2026-10', '2026-09', '2026-10', '2026-10'], 'the shared departure date also supplies the year for month-only last-payment values');


// October workbook-derived tree cases: fixed sponsorship-only rows, a tree row
// with an explicit service fee, and an explicit zero orphan count.
const octoberTreeCases = parser.splitDepartures([
  'ملف الشجرة',
  'سلافه عباس سيد',
  '5000',
  'اخر سداد اغسطس',
  'مرافئ علي محمد',
  '5000/1000',
  'اخر سداد اغسطس',
  'بتول النعيم الصديق احمد',
  'عدد الايتام 0',
  '5000',
  'اخر سداد اغسطس',
  'واقد الطيب عثمان',
  '5000',
  'اخر سداد اكتوبر',
  '31/10/2026'
].join('\n'), '');
assert.equal(octoberTreeCases.length, 4);
assert.deepEqual(Array.from(octoberTreeCases, r => r.sponsor), [
  'سلافه عباس سيد', 'مرافئ علي محمد', 'بتول النعيم الصديق احمد', 'واقد الطيب عثمان'
]);
assert.deepEqual(Array.from(octoberTreeCases, r => r.count), ['', '', '0', '']);
assert.deepEqual(Array.from(octoberTreeCases, r => r.sponsorship), ['5000', '5000', '5000', '5000']);
assert.deepEqual(Array.from(octoberTreeCases, r => r.services), ['', '1000', '', '']);
assert.deepEqual(Array.from(octoberTreeCases, r => r.lastPaid), ['2026-08', '2026-08', '2026-08', '2026-10']);
assert.ok(octoberTreeCases.every(r => r.type === 'tree' && r.date === '2026-10-31' && r.warnings.length === 0));


const twoSponsorNumberedMessage = `الكفيلة: محاسن عبدالرحمن علي
الام: انتصار عبدالله سليمان
اليتيم:١. سبا بلال
        ٢.  سجا بلال
         ٣. عبدالله بلال
الام: بلالة يوسف فضل الله
اليتيم :٤.صبري علي
          ٥.ابراهيم علي
المبلغ: 100000
الخدمات: 5000
=====
الكفيل: محمد حامد بدوي
الام :بثينة مهدي محمد
اليتيم : ١ مهدي مسعود
         ٢. وجن مسعود
          ٣.عثمان مسعود
الام: رشا الصادق
اليتيم : ٤.عصام هيثم عيسي
الام: نجوي فضل السيد
اليتيم : ٥.ايمن اسماعيل
المبلغ :100000
الخدمات: 5000
❤️‍🔥🧨
ملحوظة كل يتيم 20000 الف
كفلاء مغادرين حاينزلوا في ملف 26
عن طريق ساره خلف الله
مشرفه ح :ساره/ خ مها`;
const twoSponsorRecords = parser.applyMessageLevelFields(
  parser.split(twoSponsorNumberedMessage).map((part, index) => parser.entry(part, index, '2026-11-07')),
  twoSponsorNumberedMessage
);
assert.equal(twoSponsorRecords.length, 2, 'two sponsor labels must produce two independent entry records');
assert.deepEqual(Array.from(twoSponsorRecords, r => r.sponsor), ['محاسن عبدالرحمن علي', 'محمد حامد بدوي']);
assert.deepEqual(Array.from(twoSponsorRecords[0].families, f => f.mother), ['انتصار عبدالله سليمان', 'بلالة يوسف فضل الله']);
assert.deepEqual(Array.from(twoSponsorRecords[0].orphans), ['سبا بلال', 'سجا بلال', 'عبدالله بلال', 'صبري علي', 'ابراهيم علي'], 'Arabic-numbered and indented continuation names must be extracted');
assert.deepEqual(Array.from(twoSponsorRecords[1].families, f => f.mother), ['بثينة مهدي محمد', 'رشا الصادق', 'نجوي فضل السيد']);
assert.deepEqual(Array.from(twoSponsorRecords[1].orphans), ['مهدي مسعود', 'وجن مسعود', 'عثمان مسعود', 'عصام هيثم عيسي', 'ايمن اسماعيل'], 'Arabic and Western numbering styles must both be extracted');
assert.deepEqual(Array.from(twoSponsorRecords, r => r.sponsorship), ['100000', '100000']);
assert.deepEqual(Array.from(twoSponsorRecords, r => r.services), ['5000', '5000']);
assert.ok(twoSponsorRecords.every(r => r.date === ''), 'the parser must not invent a joining date absent from the source message');
assert.ok(twoSponsorRecords.every(r => !r.unrecognizedLines.some(line => /سجا بلال|عبدالله بلال|ابراهيم علي|وجن مسعود|عثمان مسعود/u.test(line))), 'numbered orphan continuation lines must not remain in the unclassified warning');

assert.ok(importSource.includes("if(profile==='entry')return !!record.sponsor&&Parser.hasMotherNames(record)&&record.orphans.length>0;"), 'a sponsor record with complete family names can be loaded for review when the joining date is missing');
assert.ok(importSource.includes('تاريخ الانضمام غير موجود في الرسالة؛ أدخله يدوياً قبل الحفظ.'), 'missing joining dates must be called out after loading and not silently inferred');


const singleWordOrphansMessage = `الكفيل :بيان محمد السماني
 الام :فاطمه جادو دليل
 الايتام :1-حامده
2-ميعاد
3-محمد
4-ابوبكر
 الام :عاىشه يوسف محمد
 5-عادل
6-عماد
7-وسام
8-احمد
 المبلغ :80.000
الخدمات :16.000
التاريخ :31/10/2026
❤️‍🔥

 اي مبلغ اصافي يوضع  رصيد  لتغطيه الالتزامات السنويه
عن طريق لمياء
مشرفه ح ميساء`;
const singleWordOrphans = parser.entry(singleWordOrphansMessage, 0);
assert.equal(singleWordOrphans.sponsor, 'بيان محمد السماني');
assert.deepEqual(Array.from(singleWordOrphans.families, f => f.mother), ['فاطمه جادو دليل', 'عاىشه يوسف محمد']);
assert.deepEqual(Array.from(singleWordOrphans.families[0].orphans), ['حامده', 'ميعاد', 'محمد', 'ابوبكر']);
assert.deepEqual(Array.from(singleWordOrphans.families[1].orphans), ['عادل', 'عماد', 'وسام', 'احمد']);
assert.deepEqual(Array.from(singleWordOrphans.orphans), ['حامده', 'ميعاد', 'محمد', 'ابوبكر', 'عادل', 'عماد', 'وسام', 'احمد']);
assert.equal(singleWordOrphans.sponsorship, '80000');
assert.equal(singleWordOrphans.services, '16000');
assert.equal(singleWordOrphans.date, '2026-10-31');
assert.equal(singleWordOrphans.entered, 'لمياء');
assert.equal(singleWordOrphans.supervisor, 'ميساء');
assert.match(singleWordOrphans.notes, /أي مبلغ إضافي يوضع\s+رصيد\s+لتغطيه الالتزامات السنويه/);
assert.equal(singleWordOrphans.unrecognizedLines.length, 0, 'numbered single-word orphans and the extra-amount instruction must not be left unclassified');
assert.equal(parser.hasMotherNames(singleWordOrphans), true);
assert.deepEqual(Array.from(parser.allocateEvenly(singleWordOrphans.sponsorship, singleWordOrphans.orphans.length)), Array(8).fill('10000'));
assert.deepEqual(Array.from(parser.allocateEvenly(singleWordOrphans.services, singleWordOrphans.orphans.length)), Array(8).fill('2000'));


const compactAmountMessage = `الكفيل= عبادة عبد الصامت قمر الدين
اليتيم =روعة النيل خالد
الام =ريان عبد الفاتح محمود
كفالة10.000
خدمات3000
🤍🧨
10/ 11 / 2026
عن طريق هناء خلف الله
مشرفه ح هناء خلف الله`;
const compactAmountEntry = parser.entry(compactAmountMessage, 0, '2026-11-11');
assert.equal(compactAmountEntry.sponsor, 'عبادة عبد الصامت قمر الدين');
assert.equal(compactAmountEntry.orphans[0], 'روعة النيل خالد');
assert.equal(compactAmountEntry.mother, 'ريان عبد الفاتح محمود');
assert.equal(compactAmountEntry.sponsorship, '10000', 'compact كفالة10.000 must normalize to 10000');
assert.equal(compactAmountEntry.services, '3000', 'compact خدمات3000 must normalize to 3000');
assert.equal(compactAmountEntry.date, '2026-11-10');
assert.equal(compactAmountEntry.entered, 'هناء خلف الله');
assert.equal(compactAmountEntry.supervisor, 'هناء خلف الله');


const feminineSpellingAndEmptyChildrenLabelMessage = `الكفيله :ريان الحافظ عبدالرحمن 
 الام :رحاب يحي موسي 
 الاطفال:
1-عبدالكريم
 2-مزن 
 3-محسن 
 الكفاله :30.000
الخدمات :6000
التاريخ :11/11/2026
🤍
عن طريق لمياء
مشرفه ح ميساء`;
const feminineSpellingAndEmptyChildrenLabel = parser.entry(feminineSpellingAndEmptyChildrenLabelMessage, 0, '2026-11-11');
assert.equal(feminineSpellingAndEmptyChildrenLabel.sponsor, 'ريان الحافظ عبدالرحمن', 'الكفيله spelling must be accepted as a sponsor label');
assert.equal(feminineSpellingAndEmptyChildrenLabel.mother, 'رحاب يحي موسي');
assert.deepEqual(Array.from(feminineSpellingAndEmptyChildrenLabel.orphans), ['عبدالكريم', 'مزن', 'محسن'], 'numbered one-word child names after an empty الاطفال label must remain in the orphan-list context');
assert.equal(feminineSpellingAndEmptyChildrenLabel.sponsorship, '30000');
assert.equal(feminineSpellingAndEmptyChildrenLabel.services, '6000');
assert.equal(feminineSpellingAndEmptyChildrenLabel.date, '2026-11-11');
assert.equal(feminineSpellingAndEmptyChildrenLabel.entered, 'لمياء');
assert.equal(feminineSpellingAndEmptyChildrenLabel.supervisor, 'ميساء');
assert.ok(!feminineSpellingAndEmptyChildrenLabel.warnings.some(w => /اسم الكفيل غير واضح|لم يتم استخراج أسماء الأيتام/u.test(w)), 'the reported sponsor and orphan extraction warnings must disappear');
assert.equal(feminineSpellingAndEmptyChildrenLabel.unrecognizedLines.length, 0, 'numbered one-word children must not be left as unrecognized lines');


const exactUserDepartureBatch = [
  '⛔كفلاء طلعو⛔',
  'ملف 25',
  '1• انس فتح الرحمن احمد محمد',
  '100.000/20.000',
  'عدد الايتام 10',
  'ماداخل قروب',
  'السبب عدم تواجده في الواتس والاتصال',
  'غير متاح',
  'اخر سداد سبتمبر',
  '2•التجاني محمد الشايب',
  'سبتمبر /اكتوبر',
  'عدد الايتام 1',
  '27.000/2000',
  'داخل قروب الشجرة رجال ح ياخد فرصه لحين السداد',
  'السبب عدم الرد في الواتس والاتصال',
  '3• آمن عمر  عبد الحليم',
  'اخر سداد اكتوبر',
  'عدد الايتام :2',
  '30000/4000',
  'طلب الالغاء ليس لديه علي المقدرة علي الالتزام',
  'ملف 24',
  '3• ابو عمر محمد يونس',
  'عددالايتام : 4',
  '50000/8000',
  'ماداخل قروب',
  'نسبة لتواجده في الخلا ح يرجع ويسدد',
  'اخر سداد سبتمبر',
  '5• ايهاب عمر علي',
  'عدد الايتام :1',
  'سبتمبر/اكتوبر',
  '28000/2000',
  'ما داخل قروب',
  'السبب عايز مهله لحين توفر المال وسيسدد',
  'ملف الشجرة',
  '6• الطيب عثمان جمعه',
  '2000',
  'اكتوبر',
  '31/10/2026'
].join('\n');
const exactUserDepartures = parser.splitDepartures(exactUserDepartureBatch, '2026-11-05');
assert.equal(exactUserDepartures.length, 6, 'the complete user-supplied WhatsApp batch must split into six sponsor departures');
assert.deepEqual(Array.from(exactUserDepartures, r => r.sponsor), [
  'انس فتح الرحمن احمد محمد', 'التجاني محمد الشايب', 'آمن عمر عبد الحليم',
  'ابو عمر محمد يونس', 'ايهاب عمر علي', 'الطيب عثمان جمعه'
]);
assert.deepEqual(Array.from(exactUserDepartures, r => r.file), ['25', '25', '25', '24', '24', '🌳']);
assert.deepEqual(Array.from(exactUserDepartures, r => r.count), ['10', '1', '2', '4', '1', '']);
assert.deepEqual(Array.from(exactUserDepartures, r => r.sponsorship), ['100000', '27000', '30000', '50000', '28000', '2000']);
assert.deepEqual(Array.from(exactUserDepartures, r => r.services), ['20000', '2000', '4000', '8000', '2000', '']);
assert.deepEqual(Array.from(exactUserDepartures, r => r.lastPaid), [
  '2026-09', '2026-10', '2026-10', '2026-09', '2026-10', '2026-10'
]);
assert.ok(exactUserDepartures.every(r => r.date === '2026-10-31'), 'the trailing date applies to every sponsor in the complete batch');
assert.equal(exactUserDepartures[5].type, 'tree');
assert.equal(exactUserDepartures[5].count, '', 'missing tree orphan count must remain blank, not become zero');
assert.equal(exactUserDepartures[5].services, '', 'missing tree services must remain blank, not become zero');
assert.ok(exactUserDepartures.every(r => r.raw.trim().length > 0), 'each review record retains its original source text');
assert.ok(!exactUserDepartures[0].unrecognizedLines.includes('غير متاح'), 'the standalone unavailable-contact status is recognized as a departure note');

const inlineDepartureReason = parser.departure([
  'انس فتح الرحمن احمد محمد',
  'عدد الايتام: 1',
  'قيمة الكفالة: 10000',
  'الخدمات: 1000',
  'تاريخ المغادرة: 31/10/2026',
  'ما داخل قروب السبب عايز مهله لحين توفر المال وسيسدد'
].join('\n'), 0, '2026-10-31');
assert.equal(inlineDepartureReason.reason, 'عايز مهله لحين توفر المال وسيسدد');
assert.equal(inlineDepartureReason.notes, 'ما داخل قروب', 'text before an inline reason remains a note without duplicating the reason');
assert.equal(parser.buildDepartureFormPayload(inlineDepartureReason).notes, 'عايز مهله لحين توفر المال وسيسدد | ما داخل قروب');


// Responsive departure review must use the existing page style and fit the viewport.
const indexSource = fs.readFileSync('index.php', 'utf8');
assert.ok(indexSource.includes('.departure-register-wrap{overflow:visible}'), 'departure single-record register must not use horizontal scrolling');
assert.ok(indexSource.includes('.departure-batch-wrap{overflow:visible}'), 'departure batch wrapper must not use horizontal scrolling');
assert.ok(indexSource.includes('#departureRegister td::before{content:attr(data-label)'), 'single departure fields become labelled stacked fields on narrow screens');
assert.ok(indexSource.includes('.departure-batch-table tr{display:grid;'), 'batch departures become stacked review cards on narrow screens');
assert.ok(!indexSource.includes('.departure-register{min-width:1280px}'), 'departure register must not force a 1280px minimum width');
assert.ok(!indexSource.includes('.departure-batch-table{min-width:1180px'), 'departure batch must not force a 1180px minimum width');
assert.ok(importSource.includes('td.dataset.label=label'), 'dynamic batch fields expose labels for their responsive card layout');
assert.ok(importSource.includes("row.querySelectorAll('[name]')") && importSource.includes("/^departures\\[\\d+\\]/"), 'removing a batch card reindexes all submitted field names as well as the visible row numbers');

console.log('WhatsApp parser tests passed.');
