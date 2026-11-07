<?php
declare(strict_types=1);

/** Fill the original two-sheet Excel register without changing its design. */
function mde_export_xlsx_from_template(string $monthInput, array $sponsors, array $departures, callable $dateText, string $reportType = 'both'): never
{
    $templatePath = dirname(__DIR__) . '/templates/monthly-register-template.xlsx';
    if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class) || !is_file($templatePath)) {
        http_response_code(503);
        exit('قالب Excel أو امتدادات ZIP/XML غير متاحة.');
    }
    $month = DateTimeImmutable::createFromFormat('!Y-m', $monthInput);
    if (!$month || $month->format('Y-m') !== $monthInput) {
        http_response_code(400);
        exit('شهر التقرير غير صالح.');
    }
    $monthNames = [1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
    $monthTitle = 'الشـــــــــــــــهـــــــر / ' . $monthNames[(int)$month->format('n')];
    $epoch = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
    $excelDate = static function (?string $value) use ($epoch): ?int {
        if (!$value) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), new DateTimeZone('UTC'));
        return $date ? intdiv($date->getTimestamp() - $epoch->getTimestamp(), 86400) : null;
    };
    $columnIndex = static function (string $column): int {
        $n = 0;
        for ($i=0; $i<strlen($column); $i++) $n = $n*26 + ord($column[$i])-64;
        return $n-1;
    };
    $columnName = static function (int $number): string {
        $name = '';
        while ($number > 0) { $r=($number-1)%26; $name=chr(65+$r).$name; $number=intdiv($number-1,26); }
        return $name;
    };

    $tmp = tempnam(sys_get_temp_dir(), 'mde_xlsx_');
    $workDir = sys_get_temp_dir() . '/mde_template_' . bin2hex(random_bytes(8));
    if ($tmp === false || !mkdir($workDir, 0700, true)) {
        if ($tmp !== false) @unlink($tmp);
        http_response_code(500);
        exit('تعذر إنشاء ملف Excel مؤقت.');
    }

    $buildWorksheet = static function (string $path, int $columnCount, array $rows, array $dynamicMerges, string $title, ?array $total = null) use ($columnIndex, $columnName): void {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        if (!$dom->load($path)) throw new RuntimeException('تعذر قراءة ورقة Excel.');
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheetData = $dom->getElementsByTagNameNS($ns, 'sheetData')->item(0);
        if (!$sheetData) throw new RuntimeException('بيانات ورقة Excel غير موجودة.');
        $headers = [];
        $prototype = null;
        foreach ($sheetData->childNodes as $child) {
            if (!($child instanceof DOMElement) || $child->localName !== 'row') continue;
            $n = (int)$child->getAttribute('r');
            if ($n >= 1 && $n <= 4) $headers[] = $child->cloneNode(true);
            if ($n === 5) $prototype = $child->cloneNode(true);
        }
        if (!($prototype instanceof DOMElement)) throw new RuntimeException('صف تنسيق البيانات غير موجود.');
        while ($sheetData->firstChild) $sheetData->removeChild($sheetData->firstChild);
        foreach ($headers as $header) $sheetData->appendChild($header);

        $writeCell = static function (DOMElement $cell, mixed $value, string $kind) use ($dom, $ns): void {
            while ($cell->firstChild) $cell->removeChild($cell->firstChild);
            $cell->removeAttribute('t');
            if ($value === null || $value === '') return;
            if ($kind === 'number') {
                $v=$dom->createElementNS($ns,'v');
                $v->appendChild($dom->createTextNode((string)$value));
                $cell->appendChild($v);
            } else {
                $cell->setAttribute('t','inlineStr');
                $is=$dom->createElementNS($ns,'is');
                $t=$dom->createElementNS($ns,'t');
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace','xml:space','preserve');
                $t->appendChild($dom->createTextNode((string)$value));
                $is->appendChild($t);
                $cell->appendChild($is);
            }
        };

        $rowNumber=5;
        foreach ($rows as $values) {
            $row=$prototype->cloneNode(true);
            $row->setAttribute('r',(string)$rowNumber);
            foreach ($row->childNodes as $cell) {
                if (!($cell instanceof DOMElement) || $cell->localName !== 'c') continue;
                if (!preg_match('/^([A-Z]+)/',$cell->getAttribute('r'),$m)) continue;
                $col=$m[1];
                $cell->setAttribute('r',$col.$rowNumber);
                $value=$values[$columnIndex($col)] ?? null;
                $writeCell($cell,$value,is_int($value)||is_float($value)?'number':'text');
            }
            $sheetData->appendChild($row);
            $rowNumber++;
        }
        if ($total !== null) {
            $row=$prototype->cloneNode(true);
            $row->setAttribute('r',(string)$rowNumber);
            foreach ($row->childNodes as $cell) {
                if (!($cell instanceof DOMElement) || $cell->localName !== 'c') continue;
                if (!preg_match('/^([A-Z]+)/',$cell->getAttribute('r'),$m)) continue;
                $col=$m[1];
                $cell->setAttribute('r',$col.$rowNumber);
                if (isset($total['_styles'][$col])) $cell->setAttribute('s',(string)$total['_styles'][$col]);
                $value=$total[$columnIndex($col)] ?? null;
                $writeCell($cell,$value,is_int($value)||is_float($value)?'number':'text');
            }
            $sheetData->appendChild($row);
            $rowNumber++;
        }
        foreach ($sheetData->getElementsByTagNameNS($ns,'c') as $cell) {
            if ($cell instanceof DOMElement && $cell->getAttribute('r')==='A2') { $writeCell($cell,$title,'text'); break; }
        }
        $last=max(4,$rowNumber-1);
        $dimension=$dom->getElementsByTagNameNS($ns,'dimension')->item(0);
        if ($dimension) $dimension->setAttribute('ref','A1:'.$columnName($columnCount).$last);
        $mergeNode=$dom->getElementsByTagNameNS($ns,'mergeCells')->item(0);
        if (!$mergeNode) {
            $mergeNode=$dom->createElementNS($ns,'mergeCells');
            $sheetData->parentNode->insertBefore($mergeNode,$sheetData->nextSibling);
        }
        foreach (array_values(array_unique($dynamicMerges)) as $ref) {
            $merge=$dom->createElementNS($ns,'mergeCell');
            $merge->setAttribute('ref',$ref);
            $mergeNode->appendChild($merge);
        }
        $mergeNode->setAttribute('count',(string)$mergeNode->childNodes->length);
        foreach ($dom->getElementsByTagNameNS($ns,'pane') as $pane) {
            $pane->setAttribute('ySplit','4');
            $pane->setAttribute('topLeftCell','A5');
            $pane->setAttribute('activePane','bottomLeft');
            $pane->setAttribute('state','frozen');
        }
        foreach ($dom->getElementsByTagNameNS($ns,'selection') as $selection) {
            $selection->setAttribute('activeCell','A5');
            $selection->setAttribute('sqref','A5');
        }
        if ($dom->save($path)===false) throw new RuntimeException('تعذر حفظ ورقة Excel.');
    };

    try {
        $source=new ZipArchive();
        if ($source->open($templatePath)!==true || !$source->extractTo($workDir)) throw new RuntimeException('تعذر استخراج قالب Excel.');
        $source->close();

        // Keep the template's original joining-date heading; values come from entry_date.

        $rows1=[];
        $merges1=[];
        $sumSponsorship1=0; $sumServices1=0;
        $excelRow=5;
        foreach ($sponsors as $sponsorIndex=>$sponsor) {
            $sponsorRows=$sponsor['rows'] ?? [];
            $count=count($sponsorRows);
            if ($count===0) continue;
            $first=$excelRow;
            $last=$first+$count-1;
            foreach (['A','B','C'] as $col) if ($last>$first) $merges1[]=$col.$first.':'.$col.$last;
            $motherGroups=[];
            foreach ($sponsorRows as $i=>$row) $motherGroups[(string)($row['mother_id'] ?? '')][]=$i;
            foreach ($motherGroups as $indexes) {
                if (count($indexes)>1) $merges1[]='D'.($first+$indexes[0]).':D'.($first+$indexes[count($indexes)-1]);
            }
            $offset=count($rows1);
            foreach ($sponsorRows as $i=>$row) {
                $sumSponsorship1+=(int)($row['sponsorship_amount'] ?? 0);
                $sumServices1+=(int)($row['services_amount'] ?? 0);
                $items=$row['other_donation_items'] ?? [];
                $types=[]; $amounts=[];
                foreach ($items as $item) { $types[]=(string)($item['type'] ?? ''); $amounts[]=(int)($item['amount'] ?? 0); }
                $donationAmountCell = count($amounts) === 1
                    ? $amounts[0]
                    : implode("\n", array_map(static fn(int $amount): string => number_format($amount), $amounts));
                $rows1[]=[
                    $i===0 ? $sponsorIndex+1 : null,
                    $i===0 ? (string)($sponsor['sponsor_name'] ?? '') : null,
                    $i===0 ? $excelDate(is_string($sponsor['entry_date'] ?? null)?$sponsor['entry_date']:null) : null,
                    $row['mother_name'] ?? '',
                    $row['orphan_name'] ?? '',
                    (int)($row['sponsorship_amount'] ?? 0),
                    (int)($row['services_amount'] ?? 0),
                    implode("\n",$types),
                    $donationAmountCell,
                    $row['entered_by'] ?? '',
                    $row['supervisor_name'] ?? '',
                    $row['notes'] ?? '',
                ];
            }
            // Merge repeated operator/supervisor/notes only for adjacent equal non-empty values.
            $fields=[9=>'entered_by',10=>'supervisor_name',11=>'notes'];
            foreach ($fields as $colIndex=>$field) {
                $i=0;
                while ($i<$count) {
                    $value=trim((string)($sponsorRows[$i][$field] ?? ''));
                    $end=$i;
                    if ($value!=='') {
                        while ($end+1<$count && trim((string)($sponsorRows[$end+1][$field] ?? ''))===$value) $end++;
                    }
                    if ($value!=='' && $end>$i) {
                        $merges1[]=$columnName($colIndex+1).($first+$i).':'.$columnName($colIndex+1).($first+$end);
                        for ($k=$i+1;$k<=$end;$k++) $rows1[$offset+$k][$colIndex]=null;
                    }
                    $i=$end+1;
                }
            }
            foreach ($motherGroups as $indexes) {
                for ($j=1;$j<count($indexes);$j++) $rows1[$offset+$indexes[$j]][3]=null;
            }
            $excelRow+=$count;
        }

        $total1=[0=>'الإجمالي',5=>$sumSponsorship1,6=>$sumServices1,'_styles'=>['A'=>114,'B'=>115,'C'=>115,'D'=>115,'E'=>116,'F'=>54,'G'=>54,'H'=>69,'I'=>41,'J'=>41,'K'=>41,'L'=>41]];
        $merges1[]='A'.$excelRow.':E'.$excelRow;

        $rows2=[];
        $sumSponsorship=0; $sumServices=0;
        foreach ($departures as $i=>$row) {
            $sumSponsorship+=(int)($row['sponsorship_amount_snapshot'] ?? 0);
            $sumServices+=(int)($row['services_amount_snapshot'] ?? 0);
            $rows2[]=[
                $i+1,
                $row['file_number_snapshot'] ?? '',
                $row['sponsor_name'] ?? '',
                $excelDate(is_string($row['departure_date'] ?? null)?$row['departure_date']:null),
                ($row['orphan_count_snapshot'] ?? null) === null ? null : (int)$row['orphan_count_snapshot'],
                (int)($row['sponsorship_amount_snapshot'] ?? 0),
                ($row['services_amount_snapshot'] ?? null) === null ? null : (int)$row['services_amount_snapshot'],
                $row['last_paid_month'] ?? '',
                $row['notes'] ?? '',
            ];
        }
        $total=[0=>'المجموع',5=>$sumSponsorship,6=>$sumServices,'_styles'=>['A'=>114,'B'=>115,'C'=>115,'D'=>115,'E'=>116,'F'=>54,'G'=>54,'H'=>69,'I'=>41]];
        $merges2=['A'.(5+count($rows2)).':E'.(5+count($rows2))];
        $buildWorksheet($workDir.'/xl/worksheets/sheet1.xml',12,$rows1,$merges1,$monthTitle,$total1);
        $buildWorksheet($workDir.'/xl/worksheets/sheet2.xml',9,$rows2,$merges2,$monthTitle,$total);

        // The source workbook has a fixed print area from its old data. Expand it to this report.
        $workbookPath=$workDir.'/xl/workbook.xml';
        $workbookDom=new DOMDocument('1.0','UTF-8');
        $workbookDom->preserveWhiteSpace=false;
        if (!$workbookDom->load($workbookPath)) throw new RuntimeException('تعذر تحديث إعدادات طباعة Excel.');
        $mainNs='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        if (in_array($reportType, ['new', 'departures'], true)) {
            foreach ($workbookDom->getElementsByTagNameNS($mainNs, 'sheet') as $sheet) {
                if (!($sheet instanceof DOMElement)) continue;
                $isNewSheet = str_contains($sheet->getAttribute('name'), 'جدد');
                $sheet->setAttribute('state', ($reportType === 'new') === $isNewSheet ? 'visible' : 'hidden');
            }
            foreach ($workbookDom->getElementsByTagNameNS($mainNs, 'workbookView') as $view) {
                if ($view instanceof DOMElement) $view->setAttribute('activeTab', $reportType === 'new' ? '0' : '1');
            }
        }
        foreach ($workbookDom->getElementsByTagNameNS($mainNs,'definedName') as $definedName) {
            if (!($definedName instanceof DOMElement) || $definedName->getAttribute('name') !== '_xlnm.Print_Area') continue;
            if ($definedName->getAttribute('localSheetId') === '0') {
                $definedName->textContent = "'كفلاء جدد2026'!$A$1:$L$" . (5 + count($rows1));
            } elseif ($definedName->getAttribute('localSheetId') === '1') {
                $definedName->textContent = "'كفلاء طلعوا2026'!$A$1:$I$" . (5 + count($rows2));
            }
        }
        if ($workbookDom->save($workbookPath)===false) throw new RuntimeException('تعذر حفظ إعدادات طباعة Excel.');

        $output=new ZipArchive();
        if ($output->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('تعذر إنشاء ملف Excel النهائي.');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workDir,FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative=substr($file->getPathname(),strlen($workDir)+1);
                if (!$output->addFile($file->getPathname(),str_replace(DIRECTORY_SEPARATOR,'/',$relative))) throw new RuntimeException('تعذر إضافة مكون إلى ملف Excel.');
            }
        }
        if (!$output->close()) throw new RuntimeException('تعذر إغلاق ملف Excel النهائي.');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="monthly-'.$reportType.'-'.$monthInput.'.xlsx"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('Monthly Data Entry XLSX export: '.$e->getMessage());
        echo 'تعذر إنشاء ملف Excel. راجع سجل أخطاء PHP.';
    } finally {
        @unlink($tmp);
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workDir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());
        @rmdir($workDir);
    }
    exit;
}
