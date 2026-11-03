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

const missingMother = parser.entry('الكفيل ناصر كباشي احمد ملف 2026 اليتيم سارة محمد', 0);
assert.equal(parser.hasMotherNames(missingMother), false, 'a record without a mother name must not be loadable');

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
