const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const context = { window: {}, document: { querySelector: () => null } };
vm.runInNewContext(fs.readFileSync('assets/js/whatsapp-import.js', 'utf8'), context);
const parser = context.window.MDEWhatsAppParser;
assert.ok(parser, 'parser API should be available for tests');

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
assert.equal(entryRecord.supervisor, 'ح ميساء');


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
assert.equal(numberedMotherRecord.supervisor, 'ح مها');
assert.equal(parser.hasMotherNames(numberedMotherRecord), true, 'the exact user message must be loadable after extracting its mother');

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
assert.equal(example1.supervisor, 'ح مها');
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
assert.equal(example2.supervisor, 'ح هبه خلف الله');
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
assert.equal(example3.supervisor, 'ح مها');
assert.match(example3.notes, /التواصل عن طريق: اماني محي الدين ابو الفتوح/, 'contact details should be preserved in notes');
assert.match(example3.notes, /أي مبلغ إضافي يوضع لتغطية الالتزامات السنويه/, 'additional-amount instruction should be preserved in notes');
assert.equal(parser.hasMotherNames(example3), true);

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

console.log('WhatsApp parser tests passed.');
