<?php
declare(strict_types=1);

$error = '';
require_once __DIR__ . '/../includes/reference_options.php';
$existingSponsors = $pdo->query('SELECT id, sponsor_name, sponsor_type FROM mde_sponsors ORDER BY sponsor_name')->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        mde_verify_csrf();
        $sponsorName = trim((string) ($_POST['sponsor_name'] ?? ''));
        $fileNumber = trim((string) ($_POST['file_number'] ?? ''));
        $sponsorType = (string) ($_POST['sponsor_type'] ?? 'individual');
        if (!in_array($sponsorType, ['individual', 'tree'], true)) {
            throw new InvalidArgumentException('اختر نوع الكفيل بصورة صحيحة.');
        }
        $selectedSponsorId = filter_var($_POST['existing_sponsor_id'] ?? null, FILTER_VALIDATE_INT);
        $entryDate = (string) ($_POST['entry_date'] ?? '');
        $enteredBy = trim((string) ($_POST['entered_by'] ?? ''));
        $supervisor = trim((string) ($_POST['supervisor_name'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $families = $_POST['families'] ?? [];
        if (!is_array($families)) {
            throw new InvalidArgumentException('بيانات الأسر غير صالحة.');
        }

        $expectedSponsorship = trim((string) ($_POST['expected_sponsorship_total'] ?? ''));
        $expectedServices = trim((string) ($_POST['expected_services_total'] ?? ''));
        $actualSponsorship = 0;
        $actualServices = 0;
        foreach ($families as $family) {
            foreach (($family['orphans'] ?? []) as $orphan) {
                $amountCheck = filter_var($orphan['amount'] ?? null, FILTER_VALIDATE_INT);
                $servicesCheck = filter_var($orphan['services'] ?? 0, FILTER_VALIDATE_INT);
                if ($amountCheck === false || $amountCheck < 0 || $servicesCheck === false || $servicesCheck < 0) {
                    throw new InvalidArgumentException('راجع مبالغ الأيتام؛ يجب أن تكون أعداداً صحيحة غير سالبة.');
                }
                $actualSponsorship += $amountCheck;
                $actualServices += $servicesCheck;
            }
        }
        foreach (['إجمالي الكفالة' => $expectedSponsorship, 'إجمالي الخدمات' => $expectedServices] as $label => $expected) {
            if ($expected !== '' && (filter_var($expected, FILTER_VALIDATE_INT) === false || (int) $expected < 0)) {
                throw new InvalidArgumentException($label . ' الوارد يجب أن يكون عدداً صحيحاً غير سالب.');
            }
        }
        if ($expectedSponsorship !== '' && $actualSponsorship !== (int) $expectedSponsorship) {
            throw new InvalidArgumentException('مجموع الكفالات الموزعة على الأيتام لا يطابق إجمالي الكفالة الوارد.');
        }
        if ($expectedServices !== '' && $actualServices !== (int) $expectedServices) {
            throw new InvalidArgumentException('مجموع الخدمات الموزعة على الأيتام لا يطابق إجمالي الخدمات الوارد.');
        }

        if ((!$selectedSponsorId && $sponsorName === '') || count($families) < 1) {
            throw new InvalidArgumentException('اختر كفيلاً موجوداً أو أدخل اسم كفيل جديد، وأضف بيانات أسرة واحدة على الأقل.');
        }
        $month = mde_month_start($entryDate);
        $pdo->beginTransaction();

        if ($selectedSponsorId) {
            $sponsorCheck = $pdo->prepare('SELECT id, sponsor_name FROM mde_sponsors WHERE id = ?');
            $sponsorCheck->execute([$selectedSponsorId]);
            $existingSponsor = $sponsorCheck->fetch();
            if (!$existingSponsor) {
                throw new InvalidArgumentException('الكفيل المحدد غير موجود.');
            }
            $sponsorId = $selectedSponsorId;
            $pdo->prepare('UPDATE mde_sponsors SET is_active = 1, sponsor_type = ?, file_number = COALESCE(NULLIF(?, \'\'), file_number) WHERE id = ?')->execute([$sponsorType, $fileNumber, $sponsorId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO mde_sponsors (sponsor_name, sponsor_type, file_number) VALUES (?, ?, ?)');
            $stmt->execute([$sponsorName, $sponsorType, $fileNumber ?: null]);
            $sponsorId = (int) $pdo->lastInsertId();
            }

        // Reuse person identities and active sponsor/orphan relationships.
        // Names are only used for exact lookup; ambiguous matches stop the whole transaction.
        $findMotherStmt = $pdo->prepare('SELECT id FROM mde_mothers WHERE mother_name = ? ORDER BY id LIMIT 2 FOR UPDATE');
        $insertMotherStmt = $pdo->prepare('INSERT INTO mde_mothers (mother_name) VALUES (?)');
        $findOrphanStmt = $pdo->prepare('SELECT id FROM mde_orphans WHERE mother_id = ? AND orphan_name = ? ORDER BY id LIMIT 2 FOR UPDATE');
        $insertOrphanStmt = $pdo->prepare('INSERT INTO mde_orphans (mother_id, orphan_name) VALUES (?, ?)');
        $findLinksStmt = $pdo->prepare('SELECT id, status FROM mde_sponsorships WHERE sponsor_id = ? AND orphan_id = ? ORDER BY id FOR UPDATE');
        $insertSponsorshipStmt = $pdo->prepare("INSERT INTO mde_sponsorships (sponsor_id, orphan_id, start_date, status) VALUES (?, ?, ?, 'active')");
        $findMonthlyStmt = $pdo->prepare('SELECT id FROM mde_monthly_sponsorship_entries WHERE sponsorship_id = ? AND report_month = ? LIMIT 1 FOR UPDATE');
        $monthlyStmt = $pdo->prepare('INSERT INTO mde_monthly_sponsorship_entries (sponsorship_id, report_month, entry_date, sponsorship_amount, services_amount, entered_by, supervisor_name, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $donationStmt = $pdo->prepare('INSERT INTO mde_monthly_other_donations (sponsor_id, sponsorship_id, report_month, donation_date, donation_type, amount, entered_by) VALUES (?, ?, ?, ?, ?, ?, ?)');

        $savedOrphans = 0;
        $submittedPairs = [];
        foreach ($families as $family) {
            $motherName = trim((string) ($family['mother_name'] ?? ''));
            $orphans = $family['orphans'] ?? [];
            if ($motherName === '' || !is_array($orphans) || count($orphans) < 1) {
                throw new InvalidArgumentException('كل أسرة تحتاج اسم الأم وطفلاً واحداً على الأقل.');
            }

            $findMotherStmt->execute([$motherName]);
            $motherMatches = $findMotherStmt->fetchAll();
            if (count($motherMatches) > 1) {
                throw new InvalidArgumentException('يوجد أكثر من سجل للأم بالاسم نفسه. أوقفنا الحفظ لتجنب دمج أسرتين مختلفتين؛ راجع السجلات أولاً.');
            }
            if ($motherMatches) {
                $motherId = (int) $motherMatches[0]['id'];
            } else {
                $insertMotherStmt->execute([$motherName]);
                $motherId = (int) $pdo->lastInsertId();
            }

            foreach ($orphans as $orphan) {
                $orphanName = trim((string) ($orphan['name'] ?? ''));
                $amount = filter_var($orphan['amount'] ?? null, FILTER_VALIDATE_INT);
                $services = filter_var($orphan['services'] ?? 0, FILTER_VALIDATE_INT);
                if ($orphanName === '' || $amount === false || $amount < 0 || $services === false || $services < 0) {
                    throw new InvalidArgumentException('راجع أسماء الأيتام والمبالغ؛ يجب أن تكون المبالغ أرقاماً صحيحة غير سالبة.');
                }

                $pairKey = $motherId . "\\0" . strtolower($orphanName);
                if (isset($submittedPairs[$pairKey])) {
                    throw new InvalidArgumentException('اليتيم نفسه مكرر في نموذج الإدخال. راجع أسماء الأسر والأيتام قبل الحفظ.');
                }
                $submittedPairs[$pairKey] = true;

                $findOrphanStmt->execute([$motherId, $orphanName]);
                $orphanMatches = $findOrphanStmt->fetchAll();
                if (count($orphanMatches) > 1) {
                    throw new InvalidArgumentException('يوجد أكثر من سجل لليتيم بالاسم نفسه تحت الأم المحددة. أوقفنا الحفظ لتجنب ربط سجل خاطئ.');
                }
                if ($orphanMatches) {
                    $orphanId = (int) $orphanMatches[0]['id'];
                } else {
                    $insertOrphanStmt->execute([$motherId, $orphanName]);
                    $orphanId = (int) $pdo->lastInsertId();
                }

                $findLinksStmt->execute([$sponsorId, $orphanId]);
                $links = $findLinksStmt->fetchAll();
                $activeLinks = array_values(array_filter($links, static fn(array $link): bool => $link['status'] === 'active'));
                $pausedLinks = array_values(array_filter($links, static fn(array $link): bool => $link['status'] === 'paused'));
                if (count($activeLinks) > 1) {
                    throw new InvalidArgumentException('توجد علاقات كفالة نشطة مكررة لهذا الكفيل واليتيم. أوقفنا الحفظ حتى تتم مراجعتها.');
                }
                if ($pausedLinks) {
                    throw new InvalidArgumentException('توجد كفالة معلّقة لهذا الكفيل واليتيم. راجع حالتها في إدارة السجلات قبل إدخال شهر جديد.');
                }

                if ($activeLinks) {
                    $sponsorshipId = (int) $activeLinks[0]['id'];
                    $findMonthlyStmt->execute([$sponsorshipId, $month]);
                    if ($findMonthlyStmt->fetch()) {
                        throw new InvalidArgumentException('يوجد إدخال شهري مسجل بالفعل لهذا الكفيل واليتيم في الشهر المحدد. لم نكرر السجل.');
                    }
                } else {
                    // No active or paused episode exists. An ended relationship stays
                    // historical; a new episode gets its own sponsorship ID.
                    $insertSponsorshipStmt->execute([$sponsorId, $orphanId, $entryDate]);
                    $sponsorshipId = (int) $pdo->lastInsertId();
                }

                $monthlyStmt->execute([$sponsorshipId, $month, $entryDate, $amount, $services, $enteredBy ?: null, $supervisor ?: null, $notes ?: null]);

                $orphanDonations = $orphan['donations'] ?? [];
                if (!is_array($orphanDonations)) {
                    throw new InvalidArgumentException('تفاصيل التبرعات الأخرى لليتيم غير صالحة.');
                }
                foreach ($orphanDonations as $donation) {
                    if (!is_array($donation)) {
                        throw new InvalidArgumentException('تفاصيل التبرعات الأخرى لليتيم غير صالحة.');
                    }
                    $type = trim((string) ($donation['type'] ?? ''));
                    $rawAmount = trim((string) ($donation['amount'] ?? ''));
                    if ($type === '' && $rawAmount === '') {
                        continue;
                    }
                    $donationAmount = filter_var($rawAmount, FILTER_VALIDATE_INT);
                    if ($type === '' || $donationAmount === false || $donationAmount < 0) {
                        throw new InvalidArgumentException('راجع نوع وقيمة التبرع الآخر لكل يتيم.');
                    }
                    $donationStmt->execute([$sponsorId, $sponsorshipId, $month, $entryDate, $type, $donationAmount, $enteredBy ?: null]);
                }
                $savedOrphans++;
            }
        }

        $pdo->commit();
        mde_redirect('entry', ['whatsapp_saved' => '1']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception instanceof PDOException ? 'تعذر حفظ البيانات. تحقق من عدم تكرار الإدخال وصحة قاعدة البيانات.' : $exception->getMessage();
    }
}
$lastSavedSponsorName = $pdo->query(
    'SELECT s.sponsor_name
     FROM mde_monthly_sponsorship_entries AS monthly
     INNER JOIN mde_sponsorships AS sponsorship
        ON sponsorship.id = monthly.sponsorship_id
     INNER JOIN mde_sponsors AS s
        ON s.id = sponsorship.sponsor_id
     ORDER BY monthly.created_at DESC, monthly.id DESC
     LIMIT 1'
)->fetchColumn();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
 <h1 class="h3 mb-0">إدخال كفالة جديدة</h1><span class="badge text-bg-secondary">المبالغ بالجنيه السوداني</span>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= mde_h($error) ?></div><?php endif; ?>
<?php $whatsappImportProfile = 'entry'; require __DIR__ . '/../includes/whatsapp_import.php'; ?>
<form method="post" id="entryForm" class="card p-2 p-md-3">
<input type="hidden" name="_csrf" value="<?= mde_h(mde_csrf_token()) ?>">
<?php if (is_string($lastSavedSponsorName) && trim($lastSavedSponsorName) !== ''): ?>
<div class="alert alert-danger border-danger border-2 py-2 px-3 mb-2 fw-bold text-center" role="status" aria-live="polite">
 <span>آخر كفيل تم إدخاله وحفظه بنجاح:</span>
 <span class="fs-5"><?= mde_h($lastSavedSponsorName) ?></span>
</div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
 <h2 class="h5 mb-0">سجل الكفالة — كفيل ← أم ← يتيم</h2>
 <div class="d-flex gap-2 flex-wrap">
  <span class="badge text-bg-light border">كفيل واحد لكل عملية إدخال</span>
  <button type="button" class="btn btn-outline-success btn-sm" id="addFamily">إضافة أم</button>
 </div>
</div>

<div class="entry-register-wrap">
<table class="entry-register" id="entryRegister">
 <colgroup><col style="width:2.5%"><col style="width:12.5%"><col style="width:7.5%"><col style="width:8.5%"><col style="width:10.5%"><col style="width:14%"><col style="width:6.5%"><col style="width:6.5%"><col style="width:14%"><col style="width:9%"><col style="width:8.5%"></colgroup>
 <thead>
  <tr>
   <th class="h-sponsor">#</th><th class="h-sponsor">الكفيل</th><th class="h-sponsor">الملف / النوع</th><th class="h-sponsor">تاريخ الانضمام</th>
   <th class="h-mother">الأم</th>
   <th class="h-orphan">اليتيم</th><th class="h-orphan">الكفالة</th><th class="h-orphan">الخدمات</th><th class="h-orphan">تبرعات أخرى (لليتيم)</th>
   <th class="h-sponsor">بواسطة / المشرف</th><th class="h-sponsor">ملاحظات</th>
  </tr>
 </thead>
 <tbody id="families"></tbody>
 <tfoot><tr class="table-success fw-bold"><th colspan="6" class="text-end">الإجمالي</th><th class="text-center" id="entryTotalSponsorship">0</th><th class="text-center" id="entryTotalServices">0</th><th colspan="3"></th></tr></tfoot>
</table>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 my-2">

 <button type="button" class="btn btn-outline-success btn-sm" id="addFamilyBottom">إضافة أم أخرى</button>
</div>

<div class="totals-bar" id="totalsBar">
 <div class="totals-field"><label class="form-label" for="expectedSponsorship">إجمالي الكفالة الوارد</label><input type="number" min="0" step="1" class="form-control form-control-sm" id="expectedSponsorship" name="expected_sponsorship_total" placeholder="اختياري"></div>
 <div class="totals-field"><label class="form-label" for="expectedServices">إجمالي الخدمات الوارد</label><input type="number" min="0" step="1" class="form-control form-control-sm" id="expectedServices" name="expected_services_total" placeholder="اختياري"></div>
 <div class="totals-actual"><div class="small text-secondary">الموزع حالياً</div><div class="fw-bold">الكفالة: <span id="actualSponsorship">0</span> · الخدمات: <span id="actualServices">0</span></div></div>
 <div class="totals-status" id="amountCheck" aria-live="polite">يمكنك ترك الإجماليين فارغين إذا لم يرد إجمالي مشترك في المصدر.</div>
 <button class="btn btn-success px-4" type="submit">حفظ البيانات</button>
</div>
</form>

<template id="sponsorCellsTemplate">
 <table><tbody><tr>
 <td class="sponsor-span-cell seq-cell"><span class="fw-bold" title="يُحدَّد الرقم التسلسلي في التقرير الشهري">—</span></td>
 <td class="sponsor-span-cell">
  <select class="form-select form-select-sm mb-1" name="existing_sponsor_id" id="existingSponsor" aria-label="إضافة إلى كفيل موجود (اختياري)" title="إضافة إلى كفيل موجود (اختياري)"><option value="">— كفيل جديد —</option><?php foreach ($existingSponsors as $existing): ?><option value="<?= (int)$existing['id'] ?>" data-type="<?= mde_h($existing['sponsor_type']) ?>"><?= mde_h($existing['sponsor_name']) ?></option><?php endforeach; ?></select>
  <input class="form-control form-control-sm" name="sponsor_name" id="sponsorName" maxlength="255" placeholder="اسم الكفيل الجديد" aria-label="اسم الكفيل">
 </td>
 <td class="sponsor-span-cell">
  <input class="form-control form-control-sm mb-1" name="file_number" id="fileNumber" maxlength="100" placeholder="رقم الملف" aria-label="رقم الملف (إن وجد)">
  <select class="form-select form-select-sm" name="sponsor_type" id="sponsorType" aria-label="نوع الكفيل" required><option value="individual">فردي</option><option value="tree">🌳 شجرة</option></select>
 </td>
 <td class="sponsor-span-cell"><input type="date" class="form-control form-control-sm date-input" name="entry_date" id="entryDate" aria-label="تاريخ الانضمام" required value="<?= date('Y-m-d') ?>"></td>
 <td class="sponsor-span-cell">
  <?php mde_person_dropdown('enteredBy', 'entered_by', $mdeEnteredByOptions, 'بواسطة'); ?>
  <div class="mt-1"><?php mde_person_dropdown('supervisorName', 'supervisor_name', $mdeSupervisorOptions, 'المشرف'); ?></div>
 </td>
 <td class="sponsor-span-cell"><textarea class="form-control form-control-sm notes-input" name="notes" id="entryNotes" rows="3" aria-label="ملاحظات"></textarea></td>
 </tr></tbody></table>
</template>
<template id="motherCellTemplate">
 <table><tbody><tr>
 <td class="mother-span-cell" data-family="">
  <span class="family-title visually-hidden">الأم 1</span>
  <input class="form-control form-control-sm mother-name mb-1" aria-label="اسم الأم" placeholder="اسم الأم" required>
  <div class="mother-actions"><button type="button" class="btn btn-outline-primary btn-sm add-orphan">+ يتيم</button><button type="button" class="btn btn-outline-danger btn-sm remove-family" title="حذف الأم وأيتامها" aria-label="حذف الأم">×</button></div>
 </td>
 </tr></tbody></table>
</template>
<template id="orphanRowTemplate">
 <table><tbody><tr class="orphan-row" data-family="">
  <td class="orphan-cell"><div class="d-flex gap-1"><input class="form-control form-control-sm orphan-name" aria-label="اسم اليتيم" placeholder="اسم اليتيم" required><button type="button" class="btn btn-outline-danger btn-sm remove-orphan" tabindex="-1" aria-label="إزالة اليتيم">×</button></div></td>
  <td><input type="number" min="0" step="1" class="form-control form-control-sm orphan-amount" aria-label="قيمة الكفالة" value="0" required></td>
  <td><input type="number" min="0" step="1" class="form-control form-control-sm orphan-services" aria-label="الخدمات" value="0"></td>
  <td class="orphan-donations-cell"><div class="donations"></div><button type="button" class="btn btn-outline-secondary btn-sm w-100 add-donation">+ تبرع</button></td>
 </tr></tbody></table>
</template>
<template id="donationTemplate">
 <div class="donation-row">
  <input class="form-control form-control-sm donation-type" placeholder="النوع" aria-label="نوع التبرع">
  <input type="number" min="0" step="1" class="form-control form-control-sm donation-amount" placeholder="القيمة" aria-label="قيمة التبرع">
  <button type="button" class="btn btn-outline-danger btn-sm remove-donation" aria-label="حذف التبرع">×</button>
 </div>
</template>
<script src="assets/js/entry-register.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/entry-register.js') ?>" defer></script>
