<?php
declare(strict_types=1);

$monthInput = (string) ($_GET['month'] ?? date('Y-m'));
$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $monthInput);
if (!$monthDate || $monthDate->format('Y-m') !== $monthInput) $monthDate = new DateTimeImmutable('first day of this month');
$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->modify('last day of this month')->format('Y-m-d');

$summaryStmt = $pdo->prepare("
 SELECT
  (SELECT COUNT(*) FROM mde_monthly_sponsorship_entries WHERE report_month = ?) AS sponsorship_rows,
  (SELECT COUNT(DISTINCT s.orphan_id) FROM mde_sponsorships s JOIN mde_monthly_sponsorship_entries me ON me.sponsorship_id=s.id WHERE me.report_month = ?) AS sponsored_orphans,
  (SELECT COALESCE(SUM(sponsorship_amount),0) FROM mde_monthly_sponsorship_entries WHERE report_month = ?) AS sponsorship_total,
  (SELECT COALESCE(SUM(services_amount),0) FROM mde_monthly_sponsorship_entries WHERE report_month = ?) AS services_total,
  (SELECT COUNT(*) FROM mde_sponsor_departures WHERE departure_date BETWEEN ? AND ?) AS departures,
  (SELECT COALESCE(SUM(amount),0) FROM mde_monthly_other_donations WHERE report_month = ?) AS other_donations_total
");
$summaryStmt->execute([$monthStart,$monthStart,$monthStart,$monthStart,$monthStart,$monthEnd,$monthStart]);
$summary = $summaryStmt->fetch();

$newSponsorsStmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT sponsor_id, MIN(start_date) AS first_start FROM mde_sponsorships GROUP BY sponsor_id HAVING MIN(start_date) BETWEEN ? AND ?) first_sponsor_dates');
$newSponsorsStmt->execute([$monthStart, $monthEnd]);
$newSponsors = (int) $newSponsorsStmt->fetchColumn();

$newSponsorDetailStmt = $pdo->prepare("
 SELECT sp.id AS sponsor_id, sp.sponsor_name, sp.file_number, sp.sponsor_type,
        first_dates.first_start AS joining_date, mo.id AS mother_id, mo.mother_name,
        o.orphan_name, COALESCE(me.sponsorship_amount, 0) AS sponsorship_amount,
        COALESCE(me.services_amount, 0) AS services_amount, me.entered_by, me.supervisor_name
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
 LEFT JOIN mde_monthly_sponsorship_entries me ON me.sponsorship_id = ss.id AND me.report_month = ?
 ORDER BY first_dates.first_start, sp.sponsor_name, mo.mother_name, o.orphan_name
");
$newSponsorDetailStmt->execute([$monthStart, $monthEnd, $monthStart]);
$newSponsorDetailRows = $newSponsorDetailStmt->fetchAll();
$newSponsorDetails = [];
foreach ($newSponsorDetailRows as $detailRow) {
    $sponsorId = (int) $detailRow['sponsor_id'];
    if (!isset($newSponsorDetails[$sponsorId])) {
        $newSponsorDetails[$sponsorId] = [
            'sponsor_name' => $detailRow['sponsor_name'],
            'file_number' => $detailRow['file_number'],
            'sponsor_type' => $detailRow['sponsor_type'],
            'joining_date' => $detailRow['joining_date'],
            'families' => [],
            'sponsorship_total' => 0,
            'services_total' => 0,
            'entered_by' => [],
            'supervisor_name' => [],
        ];
    }
    $motherId = (int) $detailRow['mother_id'];
    if (!isset($newSponsorDetails[$sponsorId]['families'][$motherId])) {
        $newSponsorDetails[$sponsorId]['families'][$motherId] = [
            'mother_name' => $detailRow['mother_name'],
            'orphans' => [],
        ];
    }
    $newSponsorDetails[$sponsorId]['families'][$motherId]['orphans'][] = $detailRow['orphan_name'];
    $newSponsorDetails[$sponsorId]['sponsorship_total'] += (int) $detailRow['sponsorship_amount'];
    $newSponsorDetails[$sponsorId]['services_total'] += (int) $detailRow['services_amount'];
    if (trim((string) ($detailRow['entered_by'] ?? '')) !== '') {
        $newSponsorDetails[$sponsorId]['entered_by'][(string) $detailRow['entered_by']] = true;
    }
    if (trim((string) ($detailRow['supervisor_name'] ?? '')) !== '') {
        $newSponsorDetails[$sponsorId]['supervisor_name'][(string) $detailRow['supervisor_name']] = true;
    }
}
$newSponsorDetails = array_values($newSponsorDetails);

$departureTotalsStmt = $pdo->prepare('SELECT COALESCE(SUM(sponsorship_amount_snapshot),0) AS sponsorship_total, COALESCE(SUM(services_amount_snapshot),0) AS services_total FROM mde_sponsor_departures WHERE departure_date BETWEEN ? AND ?');
$departureTotalsStmt->execute([$monthStart, $monthEnd]);
$departureTotals = $departureTotalsStmt->fetch();

$listStmt = $pdo->prepare("
 SELECT sp.sponsor_name, sp.sponsor_type, mo.mother_name, o.orphan_name, me.sponsorship_amount, me.services_amount, me.entered_by, me.supervisor_name
 FROM mde_monthly_sponsorship_entries me
 JOIN mde_sponsorships ss ON ss.id=me.sponsorship_id
 JOIN mde_sponsors sp ON sp.id=ss.sponsor_id
 JOIN mde_orphans o ON o.id=ss.orphan_id
 JOIN mde_mothers mo ON mo.id=o.mother_id
 WHERE me.report_month=?
 ORDER BY sp.sponsor_name, mo.mother_name, o.orphan_name
");
$listStmt->execute([$monthStart]);
$rows = $listStmt->fetchAll();

$donationStmt = $pdo->prepare('SELECT donation_type, SUM(amount) AS total FROM mde_monthly_other_donations WHERE report_month=? GROUP BY donation_type ORDER BY donation_type');
$donationStmt->execute([$monthStart]);
$donations = $donationStmt->fetchAll();

$donationDetailStmt = $pdo->prepare("
 SELECT sp.sponsor_name, mo.mother_name, o.orphan_name, d.donation_type,
        d.donation_date, d.amount, d.notes
 FROM mde_monthly_other_donations d
 LEFT JOIN mde_sponsors sp ON sp.id = d.sponsor_id
 LEFT JOIN mde_sponsorships ss ON ss.id = d.sponsorship_id
 LEFT JOIN mde_orphans o ON o.id = ss.orphan_id
 LEFT JOIN mde_mothers mo ON mo.id = o.mother_id
 WHERE d.report_month = ?
 ORDER BY sp.sponsor_name, mo.mother_name, o.orphan_name, d.donation_type
");
$donationDetailStmt->execute([$monthStart]);
$donationDetails = $donationDetailStmt->fetchAll();

$departureStmt = $pdo->prepare('SELECT sp.sponsor_name, d.file_number_snapshot, d.departure_date, d.orphan_count_snapshot, d.sponsorship_amount_snapshot, d.services_amount_snapshot FROM mde_sponsor_departures d JOIN mde_sponsors sp ON sp.id=d.sponsor_id WHERE d.departure_date BETWEEN ? AND ? ORDER BY d.departure_date, sp.sponsor_name');
$departureStmt->execute([$monthStart,$monthEnd]);
$departureRows = $departureStmt->fetchAll();

function mde_money(mixed $amount): string { return number_format((int)$amount) . ' ج.س'; }
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
 <h1 class="h3 mb-0">التقرير الشهري</h1>
 <div class="d-flex flex-wrap gap-2">
  <form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="reports"><input type="month" name="month" class="form-control" value="<?= mde_h($monthInput) ?>"><button class="btn btn-primary">عرض</button></form>
  <a class="btn btn-success" href="export.php?format=xlsx&amp;month=<?= mde_h($monthInput) ?>">تصدير Excel (.xlsx)</a><a class="btn btn-outline-success" href="export.php?format=csv&amp;month=<?= mde_h($monthInput) ?>">CSV</a>
  <a class="btn btn-outline-danger" href="export.php?format=pdf&amp;month=<?= mde_h($monthInput) ?>">تصدير PDF</a>
 </div>
</div>
<div class="row g-3 mb-4">
 <?php foreach([
  ['الكفلاء الجدد',$newSponsors],
  ['عدد سجلات الكفالة',$summary['sponsorship_rows']],
  ['عدد الأيتام المكفولين',$summary['sponsored_orphans']],
  ['إجمالي الكفالات',mde_money($summary['sponsorship_total'])],
  ['إجمالي الخدمات',mde_money($summary['services_total'])],
  ['التبرعات الأخرى',mde_money($summary['other_donations_total'])],
  ['مغادرات الكفلاء',$summary['departures']],
  ['قيمة الكفالات عند المغادرة',mde_money($departureTotals['sponsorship_total'])],
  ['الخدمات عند المغادرة',mde_money($departureTotals['services_total'])]
 ] as [$label,$value]): ?>
 <div class="col-6 col-lg-4"><div class="card p-3 h-100"><div class="text-secondary small"><?= mde_h($label) ?></div><div class="h4 mb-0 mt-2"><?= mde_h($value) ?></div></div></div>
 <?php endforeach; ?>
</div>

<div class="card p-3 mb-4">
 <h2 class="h5">سجل الكفلاء الجدد</h2>
 <p class="small text-secondary">يُدرج الكفيل مرة واحدة حسب أول تاريخ كفالة مسجل له خلال الشهر المحدد. الرقم التسلسلي خاص بالكفيل، حتى إذا كان له أكثر من أم أو يتيم.</p>
 <div class="table-responsive"><table class="table table-striped align-middle">
  <thead><tr><th>م</th><th>الكفيل</th><th>رقم الملف</th><th>النوع</th><th>تاريخ الانضمام</th><th>الأمهات والأيتام</th><th>إجمالي الكفالة</th><th>الخدمات</th><th>بواسطة</th><th>المشرف</th></tr></thead><tbody>
  <?php if (!$newSponsorDetails): ?><tr><td colspan="10" class="text-center text-secondary">لا يوجد كفلاء جدد لهذا الشهر.</td></tr><?php endif; ?>
  <?php foreach ($newSponsorDetails as $newSponsorIndex => $newSponsor): ?>
   <tr>
    <td><?= $newSponsorIndex + 1 ?></td>
    <td><?= mde_h($newSponsor['sponsor_name']) ?><?php if ($newSponsor['sponsor_type'] === 'tree'): ?> <span class="badge text-bg-success">🌳 شجرة</span><?php endif; ?></td>
    <td><?= mde_h($newSponsor['file_number']) ?></td>
    <td><?= $newSponsor['sponsor_type'] === 'tree' ? 'شجرة' : 'فردي' ?></td>
    <td><?= mde_h($newSponsor['joining_date']) ?></td>
    <td><?php foreach ($newSponsor['families'] as $newFamily): ?><div><strong><?= mde_h($newFamily['mother_name']) ?>:</strong> <?= mde_h(implode('، ', $newFamily['orphans'])) ?></div><?php endforeach; ?></td>
    <td><?= mde_money($newSponsor['sponsorship_total']) ?></td>
    <td><?= mde_money($newSponsor['services_total']) ?></td>
    <td><?= mde_h(implode('، ', array_keys($newSponsor['entered_by']))) ?></td>
    <td><?= mde_h(implode('، ', array_keys($newSponsor['supervisor_name']))) ?></td>
   </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<div class="card p-3 mb-4">
 <h2 class="h5">تفاصيل الكفالات والخدمات</h2>
 <div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>الكفيل</th><th>الأم</th><th>اليتيم</th><th>الكفالة</th><th>الخدمات</th><th>بواسطة</th><th>المشرف</th></tr></thead><tbody>
 <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-secondary">لا توجد بيانات لهذا الشهر.</td></tr><?php endif; ?>
 <?php foreach($rows as $row): ?><tr><td><span class="<?= $row['sponsor_type'] === 'tree' ? 'text-success fw-bold' : '' ?>"><?=mde_h($row['sponsor_name'])?></span><?php if ($row['sponsor_type'] === 'tree'): ?> <span class="badge rounded-pill text-bg-success" title="كفيل داخلي من نوع شجرة">🌳 شجرة</span><?php endif; ?></td><td><?=mde_h($row['mother_name'])?></td><td><?=mde_h($row['orphan_name'])?></td><td><?=mde_money($row['sponsorship_amount'])?></td><td><?=mde_money($row['services_amount'])?></td><td><?=mde_h($row['entered_by'])?></td><td><?=mde_h($row['supervisor_name'])?></td></tr><?php endforeach; ?>
 </tbody></table></div>
</div>
<div class="card p-3 mb-4">
 <h2 class="h5">تفاصيل التبرعات الأخرى حسب اليتيم</h2>
 <div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>الكفيل</th><th>الأم</th><th>اليتيم</th><th>نوع التبرع</th><th>التاريخ</th><th>القيمة</th></tr></thead><tbody>
 <?php if (!$donationDetails): ?><tr><td colspan="6" class="text-center text-secondary">لا توجد تبرعات أخرى لهذا الشهر.</td></tr><?php endif; ?>
 <?php foreach ($donationDetails as $d): ?><tr><td><?=mde_h($d['sponsor_name'] ?? '')?></td><td><?=mde_h($d['mother_name'] ?? '—')?></td><td><?=mde_h($d['orphan_name'] ?? '—')?></td><td><?=mde_h($d['donation_type'])?></td><td><?=mde_h($d['donation_date'])?></td><td><?=mde_money($d['amount'])?></td></tr><?php endforeach; ?>
 </tbody></table></div>
</div>
<div class="row g-3">
 <div class="col-lg-6"><div class="card p-3 h-100"><h2 class="h5">إجمالي التبرعات الأخرى حسب النوع</h2><table class="table"><thead><tr><th>النوع</th><th>الإجمالي</th></tr></thead><tbody><?php foreach($donations as $d): ?><tr><td><?=mde_h($d['donation_type'])?></td><td><?=mde_money($d['total'])?></td></tr><?php endforeach; ?><?php if(!$donations): ?><tr><td colspan="2">لا توجد تبرعات أخرى.</td></tr><?php endif; ?></tbody></table></div></div>
 <div class="col-lg-6"><div class="card p-3 h-100"><h2 class="h5">مغادرات الكفلاء</h2><div class="table-responsive"><table class="table"><thead><tr><th>الكفيل</th><th>رقم الملف</th><th>التاريخ</th><th>الأيتام</th><th>الكفالة</th><th>الخدمات</th></tr></thead><tbody><?php foreach($departureRows as $d): ?><tr><td><?=mde_h($d['sponsor_name'])?></td><td><?=mde_h($d['file_number_snapshot'])?></td><td><?=mde_h($d['departure_date'])?></td><td><?=mde_h($d['orphan_count_snapshot'])?></td><td><?=mde_money($d['sponsorship_amount_snapshot'])?></td><td><?=mde_money($d['services_amount_snapshot'])?></td></tr><?php endforeach; ?><?php if(!$departureRows): ?><tr><td colspan="6">لا توجد مغادرات في هذا الشهر.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div>
<p class="text-secondary small mt-3">تعتمد الأرقام على شهر التقرير المحدد. الكفالات والخدمات والتبرعات مرتبطة بشهر الإدخال، بينما المغادرات مرتبطة بتاريخ المغادرة الفعلي.</p>
