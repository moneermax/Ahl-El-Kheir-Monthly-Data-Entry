<?php
declare(strict_types=1);

$monthInput = (string) ($_GET['month'] ?? date('Y-m'));
$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $monthInput);
if (!$monthDate || $monthDate->format('Y-m') !== $monthInput) {
    $monthDate = new DateTimeImmutable('first day of this month');
    $monthInput = $monthDate->format('Y-m');
}
$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->modify('last day of this month')->format('Y-m-d');
$monthLabel = $monthDate->format('m/Y');

$newStmt = $pdo->prepare("
 SELECT sp.id AS sponsor_id, sp.sponsor_name, ss.id AS sponsorship_id,
        first_dates.first_start AS joining_date, mo.id AS mother_id,
        mo.mother_name, o.orphan_name,
        COALESCE(me.sponsorship_amount, 0) AS sponsorship_amount,
        COALESCE(me.services_amount, 0) AS services_amount,
        COALESCE(me.entered_by, '') AS entered_by,
        COALESCE(me.supervisor_name, '') AS supervisor_name,
        COALESCE(me.notes, '') AS notes
 FROM (
   SELECT sponsor_id, MIN(start_date) AS first_start
   FROM mde_sponsorships
   GROUP BY sponsor_id
   HAVING MIN(start_date) BETWEEN ? AND ?
 ) first_dates
 JOIN mde_sponsors sp ON sp.id = first_dates.sponsor_id
 JOIN mde_sponsorships ss ON ss.sponsor_id = sp.id
 JOIN mde_orphans o ON o.id = ss.orphan_id
 JOIN mde_mothers mo ON mo.id = o.mother_id
 LEFT JOIN mde_monthly_sponsorship_entries me
   ON me.sponsorship_id = ss.id AND me.report_month = ?
 ORDER BY first_dates.first_start, sp.sponsor_name, mo.id, o.orphan_name
");
$newStmt->execute([$monthStart, $monthEnd, $monthStart]);
$newRows = $newStmt->fetchAll();

$donationStmt = $pdo->prepare("
 SELECT d.sponsor_id, d.sponsorship_id, d.donation_type, d.amount
 FROM mde_monthly_other_donations d
 WHERE d.report_month = ?
 ORDER BY d.id
");
$donationStmt->execute([$monthStart]);
$donationsBySponsorship = [];
$legacyDonationsBySponsor = [];
foreach ($donationStmt->fetchAll() as $donation) {
    $sid = (int) ($donation['sponsor_id'] ?? 0);
    $spid = (int) ($donation['sponsorship_id'] ?? 0);
    $label = trim((string) $donation['donation_type']) . ' - ' . number_format((int) $donation['amount']);
    if ($spid > 0) {
        $donationsBySponsorship[$spid][] = $label;
    } elseif ($sid > 0) {
        $legacyDonationsBySponsor[$sid][] = $label;
    }
}

$sponsors = [];
foreach ($newRows as $row) {
    $sponsorId = (int) $row['sponsor_id'];
    if (!isset($sponsors[$sponsorId])) {
        $sponsors[$sponsorId] = [
            'sponsor_name' => $row['sponsor_name'],
            'joining_date' => $row['joining_date'],
            'rows' => [],
        ];
    }
    $row['other_donations'] = implode('، ', $donationsBySponsorship[(int) $row['sponsorship_id']] ?? []);
    $sponsors[$sponsorId]['rows'][] = $row;
}
foreach ($sponsors as $sponsorId => &$sponsor) {
    if (!empty($sponsor['rows'])) {
        $firstSponsorshipId = (int) $sponsor['rows'][0]['sponsorship_id'];
        $legacy = $legacyDonationsBySponsor[(int) $sponsorId] ?? [];
        if ($legacy) {
            $sponsor['rows'][0]['other_donations'] = trim(
                $sponsor['rows'][0]['other_donations'] . ( $sponsor['rows'][0]['other_donations'] !== '' ? '؛ ' : '') . implode('، ', $legacy)
            );
        }
    }
}
unset($sponsor);
$sponsors = array_values($sponsors);

$departureStmt = $pdo->prepare("
 SELECT d.file_number_snapshot, sp.sponsor_name, d.departure_date,
        d.orphan_count_snapshot, d.sponsorship_amount_snapshot,
        d.services_amount_snapshot, d.last_paid_month, d.notes
 FROM mde_sponsor_departures d
 JOIN mde_sponsors sp ON sp.id = d.sponsor_id
 WHERE d.departure_date BETWEEN ? AND ?
 ORDER BY d.departure_date, sp.sponsor_name
");
$departureStmt->execute([$monthStart, $monthEnd]);
$departures = $departureStmt->fetchAll();

function mde_report_date(?string $value): string {
    if (!$value) return '';
    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $date ? $date->format('d/m/Y') : $value;
}
function mde_report_amount(mixed $value): string {
    return number_format((int) $value);
}
?>
<style>
.report-register { direction:rtl; }
.report-register .month-band { background:#f6df78; color:#172b4d; font-weight:700; text-align:center; padding:.65rem; margin-bottom:1rem; }
.report-register table { width:100%; border-collapse:collapse; }
.report-register th { background:#17365d; color:#fff; white-space:nowrap; }
.report-register th,.report-register td { border:1px solid #9aa8b7; padding:.48rem .4rem; vertical-align:middle; }
.report-register td.grouped { background:#eef2f7; font-weight:600; }
.report-register tbody tr:nth-child(even) td:not(.grouped) { background:#f7f9fb; }
.report-register .table-responsive { margin-bottom:2rem; }
@media print { .report-controls,.sidebar,.navbar { display:none!important; } .report-register { font-size:10px; } }
</style>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3 report-controls">
 <h1 class="h3 mb-0">التقرير الشهري</h1>
 <div class="d-flex flex-wrap gap-2">
  <form method="get" class="d-flex gap-2">
   <input type="hidden" name="page" value="reports">
   <input type="month" name="month" class="form-control" value="<?= mde_h($monthInput) ?>">
   <button class="btn btn-primary" type="submit">عرض</button>
  </form>
  <a class="btn btn-success" href="export.php?format=xlsx&amp;month=<?= mde_h($monthInput) ?>">Excel</a>
  <a class="btn btn-outline-success" href="export.php?format=csv&amp;month=<?= mde_h($monthInput) ?>">CSV</a>
  <a class="btn btn-outline-danger" href="export.php?format=pdf&amp;month=<?= mde_h($monthInput) ?>">PDF</a>
  <button class="btn btn-outline-secondary" type="button" onclick="window.print()">طباعة</button>
 </div>
</div>

<div class="report-register">
 <div class="month-band">تقرير شهر <?= mde_h($monthLabel) ?></div>
 <h2 class="h5 mb-2">كفلاء جدد <?= mde_h($monthDate->format('Y')) ?></h2>
 <div class="table-responsive">
  <table class="table table-sm align-middle">
   <thead><tr>
    <th>الرقم</th><th>إسم الكفيل</th><th>تاريخ الإنضمام</th><th>إسم الأم</th><th>إسم اليتيم</th>
    <th>قيمة الكفالة</th><th>الخدمات</th><th>تبرعات أخرى (نوع التبرع والقيمة)</th><th>بواسطة</th><th>المشرف</th><th>ملاحظات</th>
   </tr></thead>
   <tbody>
   <?php $serial = 0; foreach ($sponsors as $sponsor): $serial++; $rowCount = count($sponsor['rows']); $motherRuns = []; foreach ($sponsor['rows'] as $i => $r) { $mid = (string) $r['mother_id']; $motherRuns[$mid][] = $i; } $printedMothers = []; ?>
    <?php foreach ($sponsor['rows'] as $i => $r): ?>
     <tr>
      <?php if ($i === 0): ?>
       <td class="grouped" rowspan="<?= $rowCount ?>"><?= $serial ?></td>
       <td class="grouped" rowspan="<?= $rowCount ?>"><?= mde_h($sponsor['sponsor_name']) ?></td>
       <td class="grouped" rowspan="<?= $rowCount ?>"><?= mde_h(mde_report_date($sponsor['joining_date'])) ?></td>
      <?php endif; ?>
      <?php $mid = (string) $r['mother_id']; if (!isset($printedMothers[$mid])): $printedMothers[$mid] = true; ?>
       <td class="grouped" rowspan="<?= count($motherRuns[$mid]) ?>"><?= mde_h($r['mother_name']) ?></td>
      <?php endif; ?>
      <td><?= mde_h($r['orphan_name']) ?></td>
      <td><?= mde_report_amount($r['sponsorship_amount']) ?></td>
      <td><?= mde_report_amount($r['services_amount']) ?></td>
      <td><?= mde_h($r['other_donations']) ?></td>
      <td><?= mde_h($r['entered_by']) ?></td>
      <td><?= mde_h($r['supervisor_name']) ?></td>
      <td><?= mde_h($r['notes']) ?></td>
     </tr>
    <?php endforeach; ?>
   <?php endforeach; ?>
   <?php if (!$sponsors): ?><tr><td colspan="11" class="text-center">لا توجد سجلات كفلاء جدد لهذا الشهر.</td></tr><?php endif; ?>
   </tbody>
  </table>
 </div>

 <h2 class="h5 mb-2">كفلاء طلعوا <?= mde_h($monthDate->format('Y')) ?></h2>
 <div class="table-responsive">
  <table class="table table-sm align-middle">
   <thead><tr>
    <th>الرقم</th><th>ملف</th><th>إسم الكفيل</th><th>تاريخ المغادرة</th><th>عدد الأيتام</th>
    <th>قيمة الكفالة</th><th>خدمات</th><th>آخر سداد</th><th>ملاحظات</th>
   </tr></thead>
   <tbody>
   <?php foreach ($departures as $i => $d): ?>
    <tr>
     <td><?= $i + 1 ?></td><td><?= mde_h($d['file_number_snapshot']) ?></td>
     <td><?= mde_h($d['sponsor_name']) ?></td><td><?= mde_h(mde_report_date($d['departure_date'])) ?></td>
     <td><?= (int) $d['orphan_count_snapshot'] ?></td>
     <td><?= mde_report_amount($d['sponsorship_amount_snapshot']) ?></td>
     <td><?= mde_report_amount($d['services_amount_snapshot']) ?></td>
     <td><?= mde_h($d['last_paid_month']) ?></td><td><?= mde_h($d['notes']) ?></td>
    </tr>
   <?php endforeach; ?>
   <?php if (!$departures): ?><tr><td colspan="9" class="text-center">لا توجد مغادرات لهذا الشهر.</td></tr><?php endif; ?>
   </tbody>
  </table>
 </div>
</div>
