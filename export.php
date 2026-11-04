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
$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->modify('last day of this month')->format('Y-m-d');
$filenameMonth = $monthDate->format('Y-m');
$year = $monthDate->format('Y');

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
    $label = trim((string) $donation['donation_type']) . ' - ' . number_format((int) $donation['amount']);
    if ($sponsorshipId > 0) {
        $donationsBySponsorship[$sponsorshipId][] = $label;
    } elseif ($sponsorId > 0) {
        $legacyDonationsBySponsor[$sponsorId][] = $label;
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
    if (!empty($sponsor['rows']) && !empty($legacyDonationsBySponsor[(int) $sponsorId])) {
        $existing = $sponsor['rows'][0]['other_donations'];
        $legacy = implode('، ', $legacyDonationsBySponsor[(int) $sponsorId]);
        $sponsor['rows'][0]['other_donations'] = $existing === '' ? $legacy : $existing . '؛ ' . $legacy;
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

$dateText = static function (?string $value): string {
    if (!$value) return '';
    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $date ? $date->format('d/m/Y') : $value;
};
$amountText = static fn(mixed $value): string => number_format((int) $value);
$escape = static fn(mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$newHeaders = ['الرقم','إسم الكفيل','تاريخ الإنضمام','إسم الأم','إسم اليتيم','قيمة الكفالة','الخدمات','تبرعات أخرى (نوع التبرع والقيمة)','بواسطة','المشرف','ملاحظات'];
$departureHeaders = ['الرقم','ملف','إسم الكفيل','تاريخ المغادرة','عدد الأيتام','قيمة الكفالة','خدمات','آخر سداد','ملاحظات'];
$newFlatRows = [];
$serial = 0;
foreach ($sponsors as $sponsor) {
    $serial++;
    foreach ($sponsor['rows'] as $row) {
        $newFlatRows[] = [
            $serial, $sponsor['sponsor_name'], $dateText($sponsor['joining_date']),
            $row['mother_name'], $row['orphan_name'], (int) $row['sponsorship_amount'],
            (int) $row['services_amount'], $row['other_donations'], $row['entered_by'],
            $row['supervisor_name'], $row['notes'],
        ];
    }
}
$departureFlatRows = [];
foreach ($departures as $i => $row) {
    $departureFlatRows[] = [
        $i + 1, $row['file_number_snapshot'], $row['sponsor_name'],
        $dateText($row['departure_date']), (int) $row['orphan_count_snapshot'],
        (int) $row['sponsorship_amount_snapshot'], (int) $row['services_amount_snapshot'],
        $row['last_paid_month'], $row['notes'],
    ];
}

if ($format === 'csv') {
    $csvSafe = static function (mixed $value): string {
        $value = (string) ($value ?? '');
        return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="monthly-registers-' . $filenameMonth . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['تقرير شهر', $monthDate->format('m/Y')]);
    fputcsv($out, []);
    fputcsv($out, ['كفلاء جدد ' . $year]);
    fputcsv($out, $newHeaders);
    foreach ($newFlatRows as $row) fputcsv($out, array_map($csvSafe, $row));
    fputcsv($out, []);
    fputcsv($out, ['كفلاء طلعوا ' . $year]);
    fputcsv($out, $departureHeaders);
    foreach ($departureFlatRows as $row) fputcsv($out, array_map($csvSafe, $row));
    fclose($out);
    exit;
}

if ($format === 'xlsx') {
    if (!class_exists(ZipArchive::class)) {
        http_response_code(503);
        exit('امتداد ZIP غير مفعل في PHP.');
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
    $makeSheet = static function (array $headers, array $rows, string $title, int $columnCount, array $mergeRefs = []) use ($columnName): string {
        $allRows = [array_merge([$title], array_fill(0, $columnCount - 1, '')), $headers, ...$rows];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews><sheetFormatPr defaultRowHeight="22"/><sheetData>';
        foreach ($allRows as $ri => $cells) {
            $rowNum = $ri + 1;
            $xml .= '<row r="' . $rowNum . '">';
            foreach ($cells as $ci => $value) {
                $ref = $columnName($ci + 1) . $rowNum;
                $style = $ri === 0 ? 1 : ($ri === 1 ? 2 : 0);
                if (is_int($value)) {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
                } else {
                    $safe = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
                    $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $safe . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($mergeRefs) {
            $xml .= '<mergeCells count="' . count($mergeRefs) . '">';
            foreach ($mergeRefs as $ref) $xml .= '<mergeCell ref="' . $ref . '"/>';
            $xml .= '</mergeCells>';
        }
        $xml .= '</worksheet>';
        return $xml;
    };

    $newRowsForXlsx = [];
    $mergeRefs = [];
    $excelRow = 3;
    foreach ($sponsors as $index => $sponsor) {
        $count = count($sponsor['rows']);
        if ($count > 1) {
            foreach (['A', 'B', 'C'] as $column) $mergeRefs[] = $column . $excelRow . ':' . $column . ($excelRow + $count - 1);
        }
        $motherGroups = [];
        foreach ($sponsor['rows'] as $row) {
            $motherId = (string) $row['mother_id'];
            $motherGroups[$motherId] = ($motherGroups[$motherId] ?? 0) + 1;
        }
        $motherStarted = [];
        foreach ($sponsor['rows'] as $rowIndex => $row) {
            $motherId = (string) $row['mother_id'];
            $newRowsForXlsx[] = [
                $rowIndex === 0 ? $index + 1 : '',
                $rowIndex === 0 ? $sponsor['sponsor_name'] : '',
                $rowIndex === 0 ? $dateText($sponsor['joining_date']) : '',
                isset($motherStarted[$motherId]) ? '' : $row['mother_name'],
                $row['orphan_name'],
                (int) $row['sponsorship_amount'], (int) $row['services_amount'],
                $row['other_donations'], $row['entered_by'], $row['supervisor_name'], $row['notes'],
            ];
            if (!isset($motherStarted[$motherId])) {
                $motherStarted[$motherId] = $excelRow + $rowIndex;
                if ($motherGroups[$motherId] > 1) {
                    $first = $excelRow + $rowIndex;
                    $last = $first + $motherGroups[$motherId] - 1;
                    $mergeRefs[] = 'D' . $first . ':D' . $last;
                }
            }
        }
        $excelRow += $count;
    }
    $sheet1 = $makeSheet($newHeaders, $newRowsForXlsx, 'كفلاء جدد ' . $year, 11, $mergeRefs);
    $sheet2 = $makeSheet($departureHeaders, $departureFlatRows, 'كفلاء طلعوا ' . $year, 9, []);
    $tmp = tempnam(sys_get_temp_dir(), 'mde_report_');
    if ($tmp === false) { http_response_code(500); exit('تعذر إنشاء ملف مؤقت.'); }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { @unlink($tmp); http_response_code(500); exit('تعذر إنشاء ملف Excel.'); }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="كفلاء جدد" sheetId="1" r:id="rId1"/><sheet name="كفلاء طلعوا" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF17365D"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF6DF78"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FF9AA8B7"/></left><right style="thin"><color rgb="FF9AA8B7"/></right><top style="thin"><color rgb="FF9AA8B7"/></top><bottom style="thin"><color rgb="FF9AA8B7"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf xfId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"/><xf xfId="0" fontId="1" fillId="2" borderId="1" applyFill="1" applyFont="1" applyBorder="1" alignment="1"/><xf xfId="0" fontId="2" fillId="1" borderId="1" applyFill="1" applyFont="1" applyBorder="1" alignment="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="monthly-registers-' . $filenameMonth . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) { http_response_code(503); exit('PDF export requires Composer dependencies.'); }
require_once $autoload;
if (!class_exists('TCPDF')) { http_response_code(503); exit('TCPDF dependency is missing.'); }
$pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8', false);
$pdf->SetCreator('Ahl El Kheir Monthly Data Entry');
$pdf->SetTitle('Monthly registers ' . $filenameMonth);
$pdf->SetMargins(8, 10, 8);
$pdf->SetAutoPageBreak(true, 12);
$pdf->setRTL(true);
$pdf->SetFont('dejavusans', '', 8);
$pdf->AddPage();
$html = '<h2 style="text-align:center;background-color:#f6df78;padding:8px">تقرير شهر ' . $escape($monthDate->format('m/Y')) . '</h2>';
$html .= '<h3>كفلاء جدد ' . $escape($year) . '</h3><table border="1" cellpadding="4"><tr style="background-color:#17365d;color:#ffffff;font-weight:bold">';
foreach ($newHeaders as $header) $html .= '<th>' . $escape($header) . '</th>';
$html .= '</tr>';
foreach ($newFlatRows as $row) {
    $html .= '<tr>';
    foreach ($row as $value) $html .= '<td>' . $escape(is_int($value) ? $amountText($value) : $value) . '</td>';
    $html .= '</tr>';
}
$html .= '</table><br/><h3>كفلاء طلعوا ' . $escape($year) . '</h3><table border="1" cellpadding="4"><tr style="background-color:#17365d;color:#ffffff;font-weight:bold">';
foreach ($departureHeaders as $header) $html .= '<th>' . $escape($header) . '</th>';
$html .= '</tr>';
foreach ($departureFlatRows as $row) {
    $html .= '<tr>';
    foreach ($row as $value) $html .= '<td>' . $escape(is_int($value) ? $amountText($value) : $value) . '</td>';
    $html .= '</tr>';
}
$html .= '</table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('monthly-registers-' . $filenameMonth . '.pdf', 'D');
exit;
