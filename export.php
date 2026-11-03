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
$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->modify('last day of this month')->format('Y-m-d');

$stmt = $pdo->prepare("
 SELECT sp.sponsor_name, sp.sponsor_type, mo.mother_name, o.orphan_name,
        me.sponsorship_amount, me.services_amount, me.entered_by, me.supervisor_name
 FROM mde_monthly_sponsorship_entries me
 JOIN mde_sponsorships ss ON ss.id=me.sponsorship_id
 JOIN mde_sponsors sp ON sp.id=ss.sponsor_id
 JOIN mde_orphans o ON o.id=ss.orphan_id
 JOIN mde_mothers mo ON mo.id=o.mother_id
 WHERE me.report_month=?
 ORDER BY sp.sponsor_name, mo.mother_name, o.orphan_name
");
$stmt->execute([$monthStart]);
$rows = $stmt->fetchAll();

$departuresStmt = $pdo->prepare("
 SELECT sp.sponsor_name, d.file_number_snapshot, d.departure_date, d.orphan_count_snapshot,
        d.sponsorship_amount_snapshot, d.services_amount_snapshot, d.last_paid_month
 FROM mde_sponsor_departures d
 JOIN mde_sponsors sp ON sp.id=d.sponsor_id
 WHERE d.departure_date BETWEEN ? AND ?
 ORDER BY d.departure_date, sp.sponsor_name
");
$departuresStmt->execute([$monthStart, $monthEnd]);
$departures = $departuresStmt->fetchAll();

$donationsStmt = $pdo->prepare("
 SELECT sp.sponsor_name, d.donation_type, d.donation_date, d.amount, d.notes
 FROM mde_monthly_other_donations d
 LEFT JOIN mde_sponsors sp ON sp.id=d.sponsor_id
 WHERE d.report_month=?
 ORDER BY d.donation_type, sp.sponsor_name
");
$donationsStmt->execute([$monthStart]);
$donations = $donationsStmt->fetchAll();


$newSponsorStmt = $pdo->prepare("
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
$newSponsorStmt->execute([$monthStart, $monthEnd, $monthStart]);
$newSponsorRawRows = $newSponsorStmt->fetchAll();
$newSponsors = [];
foreach ($newSponsorRawRows as $detailRow) {
    $sponsorId = (int) $detailRow['sponsor_id'];
    if (!isset($newSponsors[$sponsorId])) {
        $newSponsors[$sponsorId] = [
            'sponsor_name' => $detailRow['sponsor_name'],
            'file_number' => $detailRow['file_number'],
            'sponsor_type' => $detailRow['sponsor_type'] === 'tree' ? 'شجرة' : 'فردي',
            'joining_date' => $detailRow['joining_date'],
            'families' => [],
            'sponsorship_total' => 0,
            'services_total' => 0,
            'entered_by' => [],
            'supervisor_name' => [],
        ];
    }
    $motherId = (int) $detailRow['mother_id'];
    if (!isset($newSponsors[$sponsorId]['families'][$motherId])) {
        $newSponsors[$sponsorId]['families'][$motherId] = [
            'mother_name' => $detailRow['mother_name'],
            'orphans' => [],
        ];
    }
    $newSponsors[$sponsorId]['families'][$motherId]['orphans'][] = $detailRow['orphan_name'];
    $newSponsors[$sponsorId]['sponsorship_total'] += (int) $detailRow['sponsorship_amount'];
    $newSponsors[$sponsorId]['services_total'] += (int) $detailRow['services_amount'];
    if (trim((string) ($detailRow['entered_by'] ?? '')) !== '') {
        $newSponsors[$sponsorId]['entered_by'][(string) $detailRow['entered_by']] = true;
    }
    if (trim((string) ($detailRow['supervisor_name'] ?? '')) !== '') {
        $newSponsors[$sponsorId]['supervisor_name'][(string) $detailRow['supervisor_name']] = true;
    }
}
$newSponsors = array_values($newSponsors);
$formatFamilyDetails = static function (array $families): string {
    $parts = [];
    foreach ($families as $family) {
        $parts[] = $family['mother_name'] . ': ' . implode('، ', $family['orphans']);
    }
    return implode(' | ', $parts);
};

$filenameMonth = $monthDate->format('Y-m');

if ($format === 'xlsx') {
    if (!class_exists(ZipArchive::class)) {
        http_response_code(503);
        exit('امتداد ZIP غير مفعل في PHP؛ استخدم تصدير CSV مؤقتاً أو فعّل ZipArchive.');
    }

    $sheetRows = [];
    $sheetRows[] = [['التقرير الشهري - ' . $filenameMonth, 's']];
    $sheetRows[] = [];
    $sheetRows[] = [['الكفالات والخدمات', 's']];
    $sheetRows[] = [['الكفيل','s'],['نوع الكفيل','s'],['الأم','s'],['اليتيم','s'],['قيمة الكفالة','s'],['الخدمات','s'],['بواسطة','s'],['المشرف','s']];
    foreach ($rows as $row) {
        $sheetRows[] = [
            [$row['sponsor_name'],'s'],[$row['sponsor_type'] === 'tree' ? 'شجرة' : 'فردي','s'],[$row['mother_name'],'s'],[$row['orphan_name'],'s'],
            [(int)$row['sponsorship_amount'],'n'],[(int)$row['services_amount'],'n'],
            [$row['entered_by'],'s'],[$row['supervisor_name'],'s']
        ];
    }
    $sheetRows[] = [];
    $sheetRows[] = [['سجل الكفلاء الجدد','s']];
    $sheetRows[] = [['م','s'],['الكفيل','s'],['رقم الملف','s'],['نوع الكفيل','s'],['تاريخ الانضمام','s'],['الأمهات والأيتام','s'],['إجمالي الكفالة','s'],['إجمالي الخدمات','s'],['بواسطة','s'],['المشرف','s']];
    foreach ($newSponsors as $newSponsorIndex => $newSponsor) {
        $sheetRows[] = [
            [$newSponsorIndex + 1,'n'],[$newSponsor['sponsor_name'],'s'],[$newSponsor['file_number'],'s'],
            [$newSponsor['sponsor_type'],'s'],[$newSponsor['joining_date'],'s'],[$formatFamilyDetails($newSponsor['families']),'s'],
            [(int)$newSponsor['sponsorship_total'],'n'],[(int)$newSponsor['services_total'],'n'],
            [implode('، ', array_keys($newSponsor['entered_by'])),'s'],[implode('، ', array_keys($newSponsor['supervisor_name'])),'s']
        ];
    }
    $sheetRows[] = [];
    $sheetRows[] = [['التبرعات الأخرى','s']];
    $sheetRows[] = [['الكفيل','s'],['نوع التبرع','s'],['التاريخ','s'],['القيمة','s'],['ملاحظات','s']];
    foreach ($donations as $row) {
        $sheetRows[] = [
            [$row['sponsor_name'],'s'],[$row['donation_type'],'s'],[$row['donation_date'],'s'],
            [(int)$row['amount'],'n'],[$row['notes'],'s']
        ];
    }
    $sheetRows[] = [];
    $sheetRows[] = [['مغادرات الكفلاء','s']];
    $sheetRows[] = [['الكفيل','s'],['رقم الملف','s'],['تاريخ المغادرة','s'],['عدد الأيتام','s'],['قيمة الكفالة','s'],['الخدمات','s'],['آخر سداد','s']];
    foreach ($departures as $row) {
        $sheetRows[] = [
            [$row['sponsor_name'],'s'],[$row['file_number_snapshot'],'s'],[$row['departure_date'],'s'],[(int)$row['orphan_count_snapshot'],'n'],
            [(int)$row['sponsorship_amount_snapshot'],'n'],[(int)$row['services_amount_snapshot'],'n'],[$row['last_paid_month'],'s']
        ];
    }

    $columnName = static function (int $number): string {
        $name = '';
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $name = chr(65 + $remainder) . $name;
            $number = intdiv($number - 1, 26);
        }
        return $name;
    };
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews><sheetFormatPr defaultRowHeight="18"/><sheetData>';
    foreach ($sheetRows as $rowIndex => $cells) {
        $excelRow = $rowIndex + 1;
        $sheetXml .= '<row r="' . $excelRow . '">';
        foreach ($cells as $columnIndex => $cell) {
            $reference = $columnName($columnIndex + 1) . $excelRow;
            $value = $cell[0] ?? '';
            $type = $cell[1] ?? 's';
            if ($type === 'n' && $value !== null && $value !== '') {
                $sheetXml .= '<c r="' . $reference . '"><v>' . (int)$value . '</v></c>';
            } else {
                $safe = htmlspecialchars((string)($value ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $sheetXml .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">' . $safe . '</t></is></c>';
            }
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'mde_xlsx_');
    if ($tmpFile === false) {
        http_response_code(500);
        exit('تعذر إنشاء ملف Excel مؤقت.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        http_response_code(500);
        exit('تعذر تجهيز ملف Excel.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="التقرير الشهري" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Arial"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf xfId="0"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="monthly-report-' . $filenameMonth . '.xlsx"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

if ($format === 'csv') {
    $csvText = static function (mixed $value): string {
        $text = (string)($value ?? '');
        return preg_match('/^[\\s]*[=+@-]/u', $text) ? "'" . $text : $text;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="monthly-report-' . $filenameMonth . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['التقرير الشهري', $filenameMonth]);
    fputcsv($out, []);
    fputcsv($out, ['الكفالات والخدمات']);
    fputcsv($out, ['الكفيل', 'نوع الكفيل', 'الأم', 'اليتيم', 'قيمة الكفالة', 'الخدمات', 'بواسطة', 'المشرف']);
    foreach ($rows as $row) {
        fputcsv($out, [$csvText($row['sponsor_name']), ($row['sponsor_type'] === 'tree' ? 'شجرة' : 'فردي'), $csvText($row['mother_name']), $csvText($row['orphan_name']), $row['sponsorship_amount'], $row['services_amount'], $csvText($row['entered_by']), $csvText($row['supervisor_name'])]);
    }
    fputcsv($out, []);
    fputcsv($out, ['سجل الكفلاء الجدد']);
    fputcsv($out, ['م', 'الكفيل', 'رقم الملف', 'نوع الكفيل', 'تاريخ الانضمام', 'الأمهات والأيتام', 'إجمالي الكفالة', 'إجمالي الخدمات', 'بواسطة', 'المشرف']);
    foreach ($newSponsors as $newSponsorIndex => $newSponsor) {
        fputcsv($out, [
            $newSponsorIndex + 1, $csvText($newSponsor['sponsor_name']), $csvText($newSponsor['file_number']),
            $newSponsor['sponsor_type'], $newSponsor['joining_date'], $csvText($formatFamilyDetails($newSponsor['families'])),
            $newSponsor['sponsorship_total'], $newSponsor['services_total'],
            $csvText(implode('، ', array_keys($newSponsor['entered_by']))),
            $csvText(implode('، ', array_keys($newSponsor['supervisor_name'])))
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['التبرعات الأخرى']);
    fputcsv($out, ['الكفيل', 'نوع التبرع', 'التاريخ', 'القيمة', 'ملاحظات']);
    foreach ($donations as $row) {
        fputcsv($out, [$csvText($row['sponsor_name']), $csvText($row['donation_type']), $row['donation_date'], $row['amount'], $csvText($row['notes'])]);
    }
    fputcsv($out, []);
    fputcsv($out, ['مغادرات الكفلاء']);
    fputcsv($out, ['الكفيل', 'رقم الملف', 'تاريخ المغادرة', 'عدد الأيتام', 'قيمة الكفالة', 'الخدمات', 'آخر سداد']);
    foreach ($departures as $row) {
        fputcsv($out, [$csvText($row['sponsor_name']), $csvText($row['file_number_snapshot']), $row['departure_date'], $row['orphan_count_snapshot'], $row['sponsorship_amount_snapshot'], $row['services_amount_snapshot'], $row['last_paid_month']]);
    }
    fclose($out);
    exit;
}

if ($format === 'pdf') {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        http_response_code(503);
        exit('PDF export requires Composer dependencies. Run: composer install');
    }
    require_once $autoload;
    if (!class_exists('TCPDF')) {
        http_response_code(503);
        exit('TCPDF dependency is missing. Run: composer install');
    }
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Ahl El Kheir Monthly Data Entry');
    $pdf->SetTitle('Monthly report ' . $filenameMonth);
    $pdf->SetMargins(8, 10, 8);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->setRTL(true);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->AddPage();
    $html = '<h2 style="text-align:center">التقرير الشهري - ' . htmlspecialchars($filenameMonth, ENT_QUOTES, 'UTF-8') . '</h2>';
    $html .= '<h3>الكفالات والخدمات</h3><table border="1" cellpadding="4"><thead><tr style="font-weight:bold;background-color:#eeeeee"><th>الكفيل</th><th>نوع الكفيل</th><th>الأم</th><th>اليتيم</th><th>قيمة الكفالة</th><th>الخدمات</th><th>بواسطة</th><th>المشرف</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ([$row['sponsor_name'], ($row['sponsor_type'] === 'tree' ? 'شجرة' : 'فردي'), $row['mother_name'], $row['orphan_name'], number_format((int)$row['sponsorship_amount']), number_format((int)$row['services_amount']), $row['entered_by'], $row['supervisor_name']] as $value) {
            $html .= '<td>' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table><h3>سجل الكفلاء الجدد</h3><table border="1" cellpadding="4"><tr style="font-weight:bold;background-color:#eeeeee"><th>م</th><th>الكفيل</th><th>رقم الملف</th><th>النوع</th><th>تاريخ الانضمام</th><th>الأمهات والأيتام</th><th>إجمالي الكفالة</th><th>الخدمات</th><th>بواسطة</th><th>المشرف</th></tr>';
    foreach ($newSponsors as $newSponsorIndex => $newSponsor) {
        $values = [
            $newSponsorIndex + 1, $newSponsor['sponsor_name'], $newSponsor['file_number'],
            $newSponsor['sponsor_type'], $newSponsor['joining_date'], $formatFamilyDetails($newSponsor['families']),
            number_format((int)$newSponsor['sponsorship_total']), number_format((int)$newSponsor['services_total']),
            implode('، ', array_keys($newSponsor['entered_by'])), implode('، ', array_keys($newSponsor['supervisor_name']))
        ];
        $html .= '<tr>';
        foreach ($values as $value) {
            $html .= '<td>' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table><h3>التبرعات الأخرى</h3><table border="1" cellpadding="4"><tr style="font-weight:bold;background-color:#eeeeee"><th>الكفيل</th><th>نوع التبرع</th><th>التاريخ</th><th>القيمة</th></tr>';
    foreach ($donations as $row) {
        $html .= '<tr><td>' . htmlspecialchars((string)$row['sponsor_name'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$row['donation_type'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$row['donation_date'], ENT_QUOTES, 'UTF-8') . '</td><td>' . number_format((int)$row['amount']) . '</td></tr>';
    }
    $html .= '</table><h3>مغادرات الكفلاء</h3><table border="1" cellpadding="4"><tr style="font-weight:bold;background-color:#eeeeee"><th>الكفيل</th><th>رقم الملف</th><th>تاريخ المغادرة</th><th>عدد الأيتام</th><th>قيمة الكفالة</th><th>الخدمات</th><th>آخر سداد</th></tr>';
    foreach ($departures as $row) {
        $html .= '<tr><td>' . htmlspecialchars((string)$row['sponsor_name'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$row['file_number_snapshot'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$row['departure_date'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int)$row['orphan_count_snapshot'] . '</td><td>' . number_format((int)$row['sponsorship_amount_snapshot']) . '</td><td>' . number_format((int)$row['services_amount_snapshot']) . '</td><td>' . htmlspecialchars((string)$row['last_paid_month'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    $html .= '</table>';
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('monthly-report-' . $filenameMonth . '.pdf', 'D');
    exit;
}

http_response_code(400);
echo 'Unsupported export format.';
