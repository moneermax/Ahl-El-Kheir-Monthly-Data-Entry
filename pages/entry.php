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
        $savedSponsorName = '';
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
            $savedSponsorName = (string) $existingSponsor['sponsor_name'];
            $pdo->prepare('UPDATE mde_sponsors SET is_active = 1, sponsor_type = ?, file_number = COALESCE(NULLIF(?, \'\'), file_number) WHERE id = ?')->execute([$sponsorType, $fileNumber, $sponsorId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO mde_sponsors (sponsor_name, sponsor_type, file_number) VALUES (?, ?, ?)');
            $stmt->execute([$sponsorName, $sponsorType, $fileNumber ?: null]);
            $sponsorId = (int) $pdo->lastInsertId();
            $savedSponsorName = $sponsorName;
        }

        $motherStmt = $pdo->prepare('INSERT INTO mde_mothers (mother_name) VALUES (?)');
        $orphanStmt = $pdo->prepare('INSERT INTO mde_orphans (mother_id, orphan_name) VALUES (?, ?)');
        $sponsorshipStmt = $pdo->prepare("INSERT INTO mde_sponsorships (sponsor_id, orphan_id, start_date, status) VALUES (?, ?, ?, 'active')");
        $monthlyStmt = $pdo->prepare('INSERT INTO mde_monthly_sponsorship_entries (sponsorship_id, report_month, entry_date, sponsorship_amount, services_amount, entered_by, supervisor_name, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $donationStmt = $pdo->prepare('INSERT INTO mde_monthly_other_donations (sponsor_id, sponsorship_id, report_month, donation_date, donation_type, amount, entered_by) VALUES (?, ?, ?, ?, ?, ?, ?)');

        $savedOrphans = 0;
        foreach ($families as $family) {
            $motherName = trim((string) ($family['mother_name'] ?? ''));
            $orphans = $family['orphans'] ?? [];
            if ($motherName === '' || !is_array($orphans) || count($orphans) < 1) {
                throw new InvalidArgumentException('كل أسرة تحتاج اسم الأم وطفلاً واحداً على الأقل.');
            }
            $motherStmt->execute([$motherName]);
            $motherId = (int) $pdo->lastInsertId();

            foreach ($orphans as $orphan) {
                $orphanName = trim((string) ($orphan['name'] ?? ''));
                $amount = filter_var($orphan['amount'] ?? null, FILTER_VALIDATE_INT);
                $services = filter_var($orphan['services'] ?? 0, FILTER_VALIDATE_INT);
                if ($orphanName === '' || $amount === false || $amount < 0 || $services === false || $services < 0) {
                    throw new InvalidArgumentException('راجع أسماء الأيتام والمبالغ؛ يجب أن تكون المبالغ أرقاماً صحيحة غير سالبة.');
                }
                $orphanStmt->execute([$motherId, $orphanName]);
                $orphanId = (int) $pdo->lastInsertId();
                $sponsorshipStmt->execute([$sponsorId, $orphanId, $entryDate]);
                $sponsorshipId = (int) $pdo->lastInsertId();
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
        $_SESSION['mde_last_saved_sponsor'] = [
            'name' => $savedSponsorName,
            'saved_at' => date('Y-m-d H:i:s'),
        ];
        mde_flash('success', 'تم حفظ الكفيل وبيانات الأيتام بنجاح (' . $savedOrphans . ' يتيم).');
        mde_redirect('entry', ['whatsapp_saved' => '1']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception instanceof PDOException ? 'تعذر حفظ البيانات. تحقق من عدم تكرار الإدخال وصحة قاعدة البيانات.' : $exception->getMessage();
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
 <h1 class="h3 mb-0">إدخال كفالة جديدة</h1><span class="badge text-bg-secondary">المبالغ بالجنيه السوداني</span>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= mde_h($error) ?></div><?php endif; ?>
<?php $lastSavedSponsor = $_SESSION['mde_last_saved_sponsor'] ?? null; ?>
<?php $whatsappImportProfile = 'entry'; require __DIR__ . '/../includes/whatsapp_import.php'; ?>
<form method="post" id="entryForm" class="card p-2 p-md-3">
<input type="hidden" name="_csrf" value="<?= mde_h(mde_csrf_token()) ?>">
<?php if (is_array($lastSavedSponsor) && trim((string) ($lastSavedSponsor['name'] ?? '')) !== ''): ?>
<div class="alert alert-danger border-danger border-2 py-2 px-3 mb-2 fw-bold text-center" role="status" aria-live="polite">
 <span>آخر كفيل تم إدخاله وحفظه بنجاح:</span>
 <span class="fs-5"><?= mde_h($lastSavedSponsor['name']) ?></span>
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
</table>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 my-2">
 <div class="small text-secondary">خلايا الكفيل والأم ممتدة على أيتامها. Enter ينقلك للحقل التالي، وEnter في خدمات آخر يتيم في الأسرة يضيف يتيماً جديداً.</div>
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
