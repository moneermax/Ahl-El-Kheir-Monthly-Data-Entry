<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$format = (string) ($_GET['format'] ?? '');
$monthInput = (string) ($_GET['month'] ?? date('Y-m'));
$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $monthInput);
if (!$monthDate || $monthDate->format('Y-m') !== $monthInput) {
    http_response_code(400);
    exit('Invalid report month.');
}
if (!in_array($format, ['xlsx', 'csv', 'pdf'], true)) {
    http_response_code(400);
    exit('Unsupported export format.');
}
$reportType = (string) ($_GET['report'] ?? 'both');
if (!in_array($reportType, ['new', 'departures', 'both'], true)) {
    http_response_code(400);
    exit('Invalid report type.');
}
$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->modify('last day of this month')->format('Y-m-d');
$filenameMonth = $monthDate->format('Y-m');
$year = $monthDate->format('Y');

$newStmt = $pdo->prepare("
 SELECT sp.id AS sponsor_id, sp.sponsor_name, ss.id AS sponsorship_id,
        me.entry_date AS entry_date, mo.id AS mother_id,
        mo.mother_name, o.orphan_name,
        COALESCE(me.sponsorship_amount, 0) AS sponsorship_amount,
        COALESCE(me.services_amount, 0) AS services_amount,
        COALESCE(me.entered_by, '') AS entered_by,
        COALESCE(me.supervisor_name, '') AS supervisor_name,
        COALESCE(me.notes, '') AS notes
 FROM mde_monthly_sponsorship_entries me
 JOIN mde_sponsorships ss ON ss.id = me.sponsorship_id
 JOIN mde_sponsors sp ON sp.id = ss.sponsor_id
 JOIN mde_orphans o ON o.id = ss.orphan_id
 JOIN mde_mothers mo ON mo.id = o.mother_id
 WHERE me.report_month = ?
 ORDER BY me.entry_date ASC, me.id ASC
");
$newStmt->execute([$monthStart]);
$newRows = $newStmt->fetchAll();

$donationStmt = $pdo->prepare("
 SELECT sponsor_id, sponsorship_id, donation_type, amount
 FROM mde_monthly_other_donations
 WHERE report_month = ?
 ORDER BY donation_type, amount
");
$donationStmt->execute([$monthStart]);
$donationsBySponsorship = [];
$legacyDonationsBySponsor = [];
foreach ($donationStmt->fetchAll() as $donation) {
    $sponsorId = (int) ($donation['sponsor_id'] ?? 0);
    $sponsorshipId = (int) ($donation['sponsorship_id'] ?? 0);
    $item = ['type' => trim((string) $donation['donation_type']), 'amount' => (int) $donation['amount']];
    if ($sponsorshipId > 0) {
        $donationsBySponsorship[$sponsorshipId][] = $item;
    } elseif ($sponsorId > 0) {
        $legacyDonationsBySponsor[$sponsorId][] = $item;
    }
}

$sponsors = [];
foreach ($newRows as $row) {
    $sponsorId = (int) $row['sponsor_id'];
    $groupKey = $sponsorId . '|' . $row['entry_date'];
    if (!isset($sponsors[$groupKey])) {
        $sponsors[$groupKey] = [
            'sponsor_id' => $sponsorId,
            'sponsor_name' => $row['sponsor_name'],
            'entry_date' => $row['entry_date'],
            'rows' => [],
        ];
    }
    $row['other_donation_items'] = $donationsBySponsorship[(int) $row['sponsorship_id']] ?? [];
    $row['other_donations'] = implode('، ', array_map(
        static fn(array $item): string => $item['type'] . ' - ' . number_format((int) $item['amount']),
        $row['other_donation_items']
    ));
    $row['other_donation_types'] = implode("\n", array_column($row['other_donation_items'], 'type'));
    $row['other_donation_amounts'] = implode("\n", array_map(
        static fn(array $item): string => number_format((int) $item['amount']),
        $row['other_donation_items']
    ));
    $sponsors[$groupKey]['rows'][] = $row;
}
$legacyDonationAttached = [];
foreach ($sponsors as &$sponsor) {
    $sponsorId = (int) $sponsor['sponsor_id'];
    if (!empty($sponsor['rows']) && !empty($legacyDonationsBySponsor[$sponsorId]) && !isset($legacyDonationAttached[$sponsorId])) {
        $legacyItems = $legacyDonationsBySponsor[$sponsorId];
        $sponsor['rows'][0]['other_donation_items'] = array_merge($sponsor['rows'][0]['other_donation_items'], $legacyItems);
        $allItems = $sponsor['rows'][0]['other_donation_items'];
        $sponsor['rows'][0]['other_donations'] = implode('، ', array_map(
            static fn(array $item): string => $item['type'] . ' - ' . number_format((int) $item['amount']),
            $allItems
        ));
        $sponsor['rows'][0]['other_donation_types'] = implode("\n", array_column($allItems, 'type'));
        $sponsor['rows'][0]['other_donation_amounts'] = implode("\n", array_map(
            static fn(array $item): string => number_format((int) $item['amount']),
            $allItems
        ));
        $legacyDonationAttached[$sponsorId] = true;
    }
}
unset($sponsor);
$sponsors = array_values($sponsors);

$departureStmt = $pdo->prepare("
 SELECT d.file_number_snapshot, COALESCE(NULLIF(d.sponsor_name_snapshot, ''), sp.sponsor_name) AS sponsor_name, d.departure_date,
        d.orphan_count_snapshot, d.sponsorship_amount_snapshot,
        d.services_amount_snapshot, d.last_paid_month,
        CASE
          WHEN NULLIF(d.reason, '') IS NULL THEN COALESCE(d.notes, '')
          WHEN NULLIF(d.notes, '') IS NULL THEN CONCAT('السبب: ', d.reason)
          ELSE CONCAT('السبب: ', d.reason, ' | ', d.notes)
        END AS notes
 FROM mde_sponsor_departures d
 JOIN mde_sponsors sp ON sp.id = d.sponsor_id
 WHERE d.departure_date BETWEEN ? AND ?
 ORDER BY d.departure_date, sp.sponsor_name
");
$departureStmt->execute([$monthStart, $monthEnd]);
$departures = $departureStmt->fetchAll();

$dateText = static function (?string $value): string {
    if (!$value) return '';
    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $date ? $date->format('d/m/Y') : $value;
};
$amountText = static fn(mixed $value): string => number_format((int) $value);
$escape = static fn(mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$newHeaders = ['الرقم','إسم الكفيل','تاريخ الانضمام','إسم الأم','إسم اليتيم','قيمة الكفالة','الخدمات','نوع التبرع','قيمة التبرع','بواسطة','المشرف','ملاحظات'];
$departureHeaders = ['الرقم','ملف','إسم الكفيل','تاريخ المغادرة','عدد الأيتام','قيمة الكفالة','خدمات','آخر سداد','ملاحظات'];
$newFlatRows = [];
$serial = 0;
foreach ($sponsors as $sponsor) {
    $serial++;
    foreach ($sponsor['rows'] as $row) {
        $newFlatRows[] = [
            $serial, $sponsor['sponsor_name'], $dateText($sponsor['entry_date']),
            $row['mother_name'], $row['orphan_name'], (int) $row['sponsorship_amount'],
            (int) $row['services_amount'], $row['other_donation_types'], $row['other_donation_amounts'],
            $row['entered_by'], $row['supervisor_name'], $row['notes'],
        ];
    }
}
$departureFlatRows = [];
foreach ($departures as $i => $row) {
    $departureFlatRows[] = [
        $i + 1, $row['file_number_snapshot'], $row['sponsor_name'],
        $dateText($row['departure_date']), $row['orphan_count_snapshot'] === null ? '' : (int) $row['orphan_count_snapshot'],
        (int) $row['sponsorship_amount_snapshot'], $row['services_amount_snapshot'] === null ? '' : (int) $row['services_amount_snapshot'],
        $row['last_paid_month'], $row['notes'],
    ];
}

if ($format === 'csv') {
    $csvSafe = static function (mixed $value): string {
        $value = (string) ($value ?? '');
        return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="monthly-' . $reportType . '-' . $filenameMonth . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['تقرير شهر', $monthDate->format('m/Y')]);
    fputcsv($out, []);
    if ($reportType !== 'departures') {
        fputcsv($out, ['كفلاء جدد ' . $year]);
        fputcsv($out, $newHeaders);
        foreach ($newFlatRows as $row) fputcsv($out, array_map($csvSafe, $row));
        $newTotalSponsorship = array_sum(array_column($newFlatRows, 5));
        $newTotalServices = array_sum(array_column($newFlatRows, 6));
        fputcsv($out, array_map($csvSafe, ['', '', '', '', 'الإجمالي', $newTotalSponsorship, $newTotalServices, '', '', '', '', '']));
    }
    if ($reportType !== 'new') {
        if ($reportType === 'both') fputcsv($out, []);
        fputcsv($out, ['كفلاء طلعوا ' . $year]);
        fputcsv($out, $departureHeaders);
        foreach ($departureFlatRows as $row) fputcsv($out, array_map($csvSafe, $row));
        $departureTotalSponsorship = array_sum(array_column($departureFlatRows, 5));
        $departureTotalServices = array_sum(array_column($departureFlatRows, 6));
        fputcsv($out, array_map($csvSafe, ['', '', '', '', 'الإجمالي', $departureTotalSponsorship, $departureTotalServices, '', '']));
    }
    fclose($out);
    exit;
}

if ($format === 'xlsx') {
    require_once __DIR__ . '/includes/xlsx_template_export.php';
    mde_export_xlsx_from_template($monthInput, $sponsors, $departures, $dateText, $reportType);
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) { http_response_code(503); exit('PDF export requires Composer dependencies.'); }
require_once $autoload;
if (!class_exists('TCPDF')) { http_response_code(503); exit('TCPDF dependency is missing.'); }
$pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8', false);
$pdf->SetCreator('Ahl El Kheir Monthly Data Entry');
$pdf->SetTitle('Monthly ' . $reportType . ' report ' . $filenameMonth);
$pdf->SetMargins(8, 10, 8);
$pdf->SetAutoPageBreak(true, 12);
$pdf->setRTL(true);
$pdf->SetFont('dejavusans', '', 8);
$pdf->AddPage();
$html = '<h2 style="text-align:center;background-color:#f6df78;padding:8px">تقرير شهر ' . $escape($monthDate->format('m/Y')) . '</h2>';
if ($reportType !== 'departures') {
$html .= '<h3>كفلاء جدد ' . $escape($year) . '</h3><table border="1" cellpadding="4"><tr style="background-color:#17365d;color:#ffffff;font-weight:bold">';
foreach ($newHeaders as $header) $html .= '<th>' . $escape($header) . '</th>';
$html .= '</tr>';
foreach ($newFlatRows as $row) {
    $html .= '<tr>';
    foreach ($row as $value) $html .= '<td>' . $escape(is_int($value) ? $amountText($value) : $value) . '</td>';
    $html .= '</tr>';
}
$newTotalSponsorship = array_sum(array_column($newFlatRows, 5));
$newTotalServices = array_sum(array_column($newFlatRows, 6));
$html .= '<tr style="font-weight:bold;background-color:#e2f0e6"><td colspan="5">الإجمالي</td><td>' . $amountText($newTotalSponsorship) . '</td><td>' . $amountText($newTotalServices) . '</td><td colspan="5"></td></tr></table>';
}
if ($reportType !== 'new') {
$html .= '<br/><h3>كفلاء طلعوا ' . $escape($year) . '</h3><table border="1" cellpadding="4"><tr style="background-color:#17365d;color:#ffffff;font-weight:bold">';
foreach ($departureHeaders as $header) $html .= '<th>' . $escape($header) . '</th>';
$html .= '</tr>';
foreach ($departureFlatRows as $row) {
    $html .= '<tr>';
    foreach ($row as $value) $html .= '<td>' . $escape(is_int($value) ? $amountText($value) : $value) . '</td>';
    $html .= '</tr>';
}
$departureTotalSponsorship = array_sum(array_column($departureFlatRows, 5));
$departureTotalServices = array_sum(array_column($departureFlatRows, 6));
$html .= '<tr style="font-weight:bold;background-color:#e2f0e6"><td colspan="5">الإجمالي</td><td>' . $amountText($departureTotalSponsorship) . '</td><td>' . $amountText($departureTotalServices) . '</td><td colspan="2"></td></tr></table>';
}
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('monthly-' . $reportType . '-' . $filenameMonth . '.pdf', 'D');
exit;
