<?php
declare(strict_types=1);

/**
 * Build the XLSX report from the organization's original workbook template.
 * The template is stored as base64 so the repository can carry the binary
 * workbook without GitHub's text-file API corrupting it.
 */
function mde_xlsx_xml_escape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function mde_xlsx_excel_date(mixed $value): ?int
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10));
    if (!$date) {
        return null;
    }
    $base = new DateTimeImmutable('1899-12-30');
    return (int) $base->diff($date)->format('%r%a');
}

function mde_xlsx_cell(string $reference, int $style, mixed $value, bool $date = false): string
{
    if ($date) {
        $serial = mde_xlsx_excel_date($value);
        return $serial === null
            ? '<c r="' . $reference . '" s="' . $style . '"/>'
            : '<c r="' . $reference . '" s="' . $style . '"><v>' . $serial . '</v></c>';
    }
    if ($value === null || $value === '') {
        return '<c r="' . $reference . '" s="' . $style . '"/>';
    }
    if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/D', $value))) {
        return '<c r="' . $reference . '" s="' . $style . '"><v>' . (string) $value . '</v></c>';
    }
    return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
        . mde_xlsx_xml_escape($value) . '</t></is></c>';
}

function mde_xlsx_column_name(int $number): string
{
    $name = '';
    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $name = chr(65 + $remainder) . $name;
        $number = intdiv($number - 1, 26);
    }
    return $name;
}

/** Extract the cell styles from the blank prototype row in the supplied sheet. */
function mde_xlsx_prototype_styles(string $sheetXml, int $columnCount): array
{
    if (!preg_match('/<row\b(?=[^>]*\br="5")[^>]*>(.*?)<\/row>/s', $sheetXml, $rowMatch)) {
        throw new RuntimeException('The Excel template is missing its styled prototype row.');
    }
    $styles = [];
    preg_match_all('/<c\b([^>]*)\/?>/s', $rowMatch[1], $cells);
    foreach ($cells[1] as $attributes) {
        if (preg_match('/\br="([A-Z]+)5"/', $attributes, $ref)
            && preg_match('/\bs="(\d+)"/', $attributes, $style)) {
            $letters = $ref[1];
            $number = 0;
            foreach (str_split($letters) as $letter) {
                $number = $number * 26 + ord($letter) - 64;
            }
            if ($number >= 1 && $number <= $columnCount) {
                $styles[$number] = (int) $style[1];
            }
        }
    }
    for ($i = 1; $i <= $columnCount; $i++) {
        if (!isset($styles[$i])) {
            throw new RuntimeException('The Excel template has an incomplete prototype row.');
        }
    }
    return $styles;
}

/**
 * Replace only the data region. The original header, merged headings,
 * styles, widths, RTL view, page margins and print setup remain untouched.
 */
function mde_xlsx_rebuild_sheet(
    string $sheetXml,
    array $dataRows,
    int $columnCount,
    string $monthLabel,
    array $extraMerges = [],
    bool $removeValidations = false
): string {
    $styles = mde_xlsx_prototype_styles($sheetXml, $columnCount);

    if (!preg_match('/<sheetData>(.*?)<\/sheetData>/s', $sheetXml, $sheetDataMatch)) {
        throw new RuntimeException('Invalid worksheet XML in the Excel template.');
    }
    $headerRows = [];
    preg_match_all('/<row\b(?=[^>]*\br="[1-4]")[^>]*>.*?<\/row>/s', $sheetDataMatch[1], $headerMatches);
    foreach ($headerMatches[0] as $headerRow) {
        $headerRows[] = $headerRow;
    }
    if (count($headerRows) !== 4) {
        throw new RuntimeException('The Excel template must contain four fixed heading rows.');
    }

    // The selected month belongs in the existing yellow month band (row 2).
    $headerRows[1] = preg_replace_callback(
        '/(<row\b(?=[^>]*\br="2")[^>]*>)(.*?)(<\/row>)/s',
        static function (array $match) use ($monthLabel): string {
            $body = preg_replace(
                '/<t(?:\s[^>]*)?>.*?<\/t>/s',
                '<t>' . mde_xlsx_xml_escape('الشهر / ' . $monthLabel) . '</t>',
                $match[2],
                1
            );
            return $match[1] . $body . $match[3];
        },
        $headerRows[1],
        1
    ) ?? $headerRows[1];

    $lastRow = 4 + count($dataRows);
    $dataXml = '';
    $rowNumber = 5;
    foreach ($dataRows as $values) {
        $dataXml .= '<row r="' . $rowNumber . '" ht="30.75" customHeight="1" s="57" thickBot="1">';
        for ($column = 1; $column <= $columnCount; $column++) {
            $value = $values[$column - 1] ?? null;
            $isDate = ($column === 3 && $columnCount === 12)
                || ($column === 4 && $columnCount === 9);
            $reference = mde_xlsx_column_name($column) . $rowNumber;
            $dataXml .= mde_xlsx_cell($reference, $styles[$column], $value, $isDate);
        }
        $dataXml .= '</row>';
        $rowNumber++;
    }

    $xml = str_replace(
        $sheetDataMatch[0],
        '<sheetData>' . implode('', $headerRows) . $dataXml . '</sheetData>',
        $sheetXml
    );

    // Keep the template's fixed header merges and append only data-region merges.
    $existingMerges = [];
    if (preg_match('/<mergeCells\b[^>]*>(.*?)<\/mergeCells>/s', $xml, $mergeMatch)) {
        preg_match_all('/<mergeCell\b[^>]*\bref="([^"]+)"/', $mergeMatch[1], $refs);
        $existingMerges = $refs[1] ?? [];
        $xml = str_replace($mergeMatch[0], '', $xml);
    }
    $allMerges = array_values(array_unique(array_merge($existingMerges, $extraMerges)));
    if ($allMerges) {
        $mergeXml = '<mergeCells count="' . count($allMerges) . '">';
        foreach ($allMerges as $reference) {
            $mergeXml .= '<mergeCell ref="' . $reference . '"/>';
        }
        $mergeXml .= '</mergeCells>';
        $xml = str_replace('</sheetData>', '</sheetData>' . $mergeXml, $xml);
    }

    $endColumn = mde_xlsx_column_name($columnCount);
    $xml = preg_replace(
        '/<dimension\b[^>]*\/>/',
        '<dimension ref="A1:' . $endColumn . max(4, $lastRow) . '"/>',
        $xml,
        1
    ) ?? $xml;
    if ($removeValidations) {
        $xml = preg_replace('/<dataValidations\b[^>]*>.*?<\/dataValidations>/s', '', $xml) ?? $xml;
    }
    return $xml;
}

/**
 * Stream a styled workbook using the original two-sheet register template.
 *
 * @param array $sponsors Grouped sponsor data built by export.php.
 * @param array $departures Departure records for the selected month.
 */
function mde_output_template_xlsx(
    array $sponsors,
    array $departures,
    DateTimeImmutable $monthDate,
    string $filenameMonth
): never {
    if (!class_exists(ZipArchive::class)) {
        http_response_code(503);
        exit('امتداد ZIP غير مفعل في PHP.');
    }

    $monthNames = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];
    $monthLabel = $monthNames[(int) $monthDate->format('n')] ?? $monthDate->format('m/Y');

    $templatePath = __DIR__ . '/../templates/monthly-register-template.xlsx.b64';
    $encoded = is_file($templatePath) ? file_get_contents($templatePath) : false;
    $binary = is_string($encoded) ? base64_decode(trim($encoded), true) : false;
    if ($binary === false || strlen($binary) < 1000) {
        http_response_code(500);
        exit('قالب Excel الأصلي غير موجود أو تالف.');
    }

    $sourcePath = tempnam(sys_get_temp_dir(), 'mde_template_');
    $outputPath = tempnam(sys_get_temp_dir(), 'mde_export_');
    if ($sourcePath === false || $outputPath === false) {
        if (is_string($sourcePath)) @unlink($sourcePath);
        if (is_string($outputPath)) @unlink($outputPath);
        http_response_code(500);
        exit('تعذر إنشاء ملف Excel مؤقت.');
    }
    if (file_put_contents($sourcePath, $binary) === false) {
        @unlink($sourcePath);
        @unlink($outputPath);
        http_response_code(500);
        exit('تعذر تجهيز قالب Excel.');
    }

    $source = new ZipArchive();
    $target = new ZipArchive();
    if ($source->open($sourcePath) !== true || $target->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        if ($source->status === ZIPARCHIVE::ER_OK) $source->close();
        @unlink($sourcePath);
        @unlink($outputPath);
        http_response_code(500);
        exit('تعذر فتح قالب Excel.');
    }

    try {
        $newRows = [];
        $newMerges = [];
        $serial = 0;
        $excelRow = 5;

        foreach ($sponsors as $sponsor) {
            $serial++;
            $rows = $sponsor['rows'] ?? [];
            $count = count($rows);
            if ($count === 0) {
                continue;
            }
            $firstRow = $excelRow;
            $lastRow = $excelRow + $count - 1;
            if ($count > 1) {
                foreach (['A', 'B', 'C'] as $column) {
                    $newMerges[] = $column . $firstRow . ':' . $column . $lastRow;
                }
            }

            $motherGroups = [];
            for ($i = 0; $i < $count; $i++) {
                $motherId = (string) ($rows[$i]['mother_id'] ?? '');
                $motherGroups[$motherId][] = $i;
            }

            foreach ($rows as $rowIndex => $row) {
                $donations = $row['other_donation_items'] ?? [];
                $donationTypes = [];
                $donationAmounts = [];
                foreach ($donations as $donation) {
                    $type = trim((string) ($donation['type'] ?? ''));
                    $amount = (int) ($donation['amount'] ?? 0);
                    if ($type !== '') $donationTypes[] = $type;
                    $donationAmounts[] = number_format($amount);
                }
                $newRows[] = [
                    $rowIndex === 0 ? $serial : null,
                    $rowIndex === 0 ? (string) $sponsor['sponsor_name'] : null,
                    $rowIndex === 0 ? (string) $sponsor['joining_date'] : null,
                    (string) ($row['mother_name'] ?? ''),
                    (string) ($row['orphan_name'] ?? ''),
                    (int) ($row['sponsorship_amount'] ?? 0),
                    (int) ($row['services_amount'] ?? 0),
                    implode("\n", $donationTypes),
                    implode("\n", $donationAmounts),
                    (string) ($row['entered_by'] ?? ''),
                    (string) ($row['supervisor_name'] ?? ''),
                    (string) ($row['notes'] ?? ''),
                ];
            }

            foreach ($motherGroups as $indices) {
                if (count($indices) > 1) {
                    $start = $firstRow + min($indices);
                    $end = $firstRow + max($indices);
                    $newMerges[] = 'D' . $start . ':D' . $end;
                }
            }
            $excelRow += $count;
        }

        $departureRows = [];
        $departureMerges = [];
        $sponsorshipTotal = 0;
        $servicesTotal = 0;
        foreach ($departures as $index => $row) {
            $sponsorship = (int) ($row['sponsorship_amount_snapshot'] ?? 0);
            $services = (int) ($row['services_amount_snapshot'] ?? 0);
            $sponsorshipTotal += $sponsorship;
            $servicesTotal += $services;
            $departureRows[] = [
                $index + 1,
                (string) ($row['file_number_snapshot'] ?? ''),
                (string) ($row['sponsor_name'] ?? ''),
                (string) ($row['departure_date'] ?? ''),
                (int) ($row['orphan_count_snapshot'] ?? 0),
                $sponsorship,
                $services,
                (string) ($row['last_paid_month'] ?? ''),
                (string) ($row['notes'] ?? ''),
            ];
        }
        if ($departureRows) {
            $totalRow = 5 + count($departureRows);
            $departureRows[] = ['الإجمالي', null, null, null, null, $sponsorshipTotal, $servicesTotal, null, null];
            $departureMerges[] = 'A' . $totalRow . ':E' . $totalRow;
        }

        $sheet1Source = $source->getFromName('xl/worksheets/sheet1.xml');
        $sheet2Source = $source->getFromName('xl/worksheets/sheet2.xml');
        if (!is_string($sheet1Source) || !is_string($sheet2Source)) {
            throw new RuntimeException('The template workbook is missing a worksheet.');
        }
        $sheet1 = mde_xlsx_rebuild_sheet($sheet1Source, $newRows, 12, $monthLabel, $newMerges);
        $sheet2 = mde_xlsx_rebuild_sheet($sheet2Source, $departureRows, 9, $monthLabel, $departureMerges, true);

        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);
            if ($name === false || $name === 'xl/worksheets/sheet1.xml' || $name === 'xl/worksheets/sheet2.xml') {
                continue;
            }
            $contents = $source->getFromIndex($i);
            if ($contents === false || !$target->addFromString($name, $contents)) {
                throw new RuntimeException('Could not copy a template workbook component.');
            }
        }
        if (!$target->addFromString('xl/worksheets/sheet1.xml', $sheet1)
            || !$target->addFromString('xl/worksheets/sheet2.xml', $sheet2)) {
            throw new RuntimeException('Could not write the report worksheets.');
        }
        $target->close();
        $source->close();
    } catch (Throwable $error) {
        $target->close();
        $source->close();
        @unlink($sourcePath);
        @unlink($outputPath);
        error_log('Monthly Data Entry XLSX template export: ' . $error->getMessage());
        http_response_code(500);
        exit('تعذر إنشاء ملف Excel من القالب الأصلي.');
    }

    @unlink($sourcePath);
    if (!is_file($outputPath) || filesize($outputPath) < 1000) {
        @unlink($outputPath);
        http_response_code(500);
        exit('ملف Excel الناتج غير صالح.');
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="monthly-registers-' . $filenameMonth . '.xlsx"');
    header('Content-Length: ' . filesize($outputPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($outputPath);
    @unlink($outputPath);
    exit;
}
