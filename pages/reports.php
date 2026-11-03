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

$departureTotalsStmt = $pdo->prepare('SELECT COALESCE(SUM(sponsorship_amount_snapshot),0) AS sponsorship_total, COALESCE(SUM(services_amount_snapshot),0) AS services_total FROM mde_sponsor_departures WHERE departure_date BETWEEN ? AND ?');
$departureTotalsStmt->execute([$monthStart, $monthEnd]);
$departureTotals = $departureTotalsStmt->fetch();

$listStmt = $pdo->prepare("
 SELECT sp.sponsor_name, mo.mother_name, o.orphan_name, me.sponsorship_amount, me.services_amount, me.entered_by, me.supervisor_name
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
 <h2 class="h5">تفاصيل الكفالات والخدمات</h2>
 <div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>الكفيل</th><th>الأم</th><th>اليتيم</th><th>الكفالة</th><th>الخدمات</th><th>بواسطة</th><th>المشرف</th></tr></thead><tbody>
 <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-secondary">لا توجد بيانات لهذا الشهر.</td></tr><?php endif; ?>
 <?php foreach($rows as $row): ?><tr><td><?=mde_h($row['sponsor_name'])?></td><td><?=mde_h($row['mother_name'])?></td><td><?=mde_h($row['orphan_name'])?></td><td><?=mde_money($row['sponsorship_amount'])?></td><td><?=mde_money($row['services_amount'])?></td><td><?=mde_h($row['entered_by'])?></td><td><?=mde_h($row['supervisor_name'])?></td></tr><?php endforeach; ?>
 </tbody></table></div>
</div>
<div class="row g-3">
 <div class="col-lg-6"><div class="card p-3 h-100"><h2 class="h5">التبرعات الأخرى</h2><table class="table"><thead><tr><th>النوع</th><th>الإجمالي</th></tr></thead><tbody><?php foreach($donations as $d): ?><tr><td><?=mde_h($d['donation_type'])?></td><td><?=mde_money($d['total'])?></td></tr><?php endforeach; ?><?php if(!$donations): ?><tr><td colspan="2">لا توجد تبرعات أخرى.</td></tr><?php endif; ?></tbody></table></div></div>
 <div class="col-lg-6"><div class="card p-3 h-100"><h2 class="h5">مغادرات الكفلاء</h2><div class="table-responsive"><table class="table"><thead><tr><th>الكفيل</th><th>رقم الملف</th><th>التاريخ</th><th>الأيتام</th><th>الكفالة</th><th>الخدمات</th></tr></thead><tbody><?php foreach($departureRows as $d): ?><tr><td><?=mde_h($d['sponsor_name'])?></td><td><?=mde_h($d['file_number_snapshot'])?></td><td><?=mde_h($d['departure_date'])?></td><td><?=mde_h($d['orphan_count_snapshot'])?></td><td><?=mde_money($d['sponsorship_amount_snapshot'])?></td><td><?=mde_money($d['services_amount_snapshot'])?></td></tr><?php endforeach; ?><?php if(!$departureRows): ?><tr><td colspan="6">لا توجد مغادرات في هذا الشهر.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div>
<p class="text-secondary small mt-3">تعتمد الأرقام على شهر التقرير المحدد. الكفالات والخدمات والتبرعات مرتبطة بشهر الإدخال، بينما المغادرات مرتبطة بتاريخ المغادرة الفعلي.</p>
