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
            $sponsorCheck = $pdo->prepare('SELECT id FROM mde_sponsors WHERE id = ?');
            $sponsorCheck->execute([$selectedSponsorId]);
            if (!$sponsorCheck->fetch()) {
                throw new InvalidArgumentException('الكفيل المحدد غير موجود.');
            }
            $sponsorId = $selectedSponsorId;
            $pdo->prepare('UPDATE mde_sponsors SET is_active = 1, sponsor_type = ?, file_number = COALESCE(NULLIF(?, \'\'), file_number) WHERE id = ?')->execute([$sponsorType, $fileNumber, $sponsorId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO mde_sponsors (sponsor_name, sponsor_type, file_number) VALUES (?, ?, ?)');
            $stmt->execute([$sponsorName, $sponsorType, $fileNumber ?: null]);
            $sponsorId = (int) $pdo->lastInsertId();
        }

        $motherStmt = $pdo->prepare('INSERT INTO mde_mothers (mother_name) VALUES (?)');
        $orphanStmt = $pdo->prepare('INSERT INTO mde_orphans (mother_id, orphan_name) VALUES (?, ?)');
        $sponsorshipStmt = $pdo->prepare("INSERT INTO mde_sponsorships (sponsor_id, orphan_id, start_date, status) VALUES (?, ?, ?, 'active')");
        $monthlyStmt = $pdo->prepare('INSERT INTO mde_monthly_sponsorship_entries (sponsorship_id, report_month, entry_date, sponsorship_amount, services_amount, entered_by, supervisor_name, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

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
                $savedOrphans++;
            }
        }

        $donationTypes = $_POST['donation_type'] ?? [];
        $donationAmounts = $_POST['donation_amount'] ?? [];
        $donationStmt = $pdo->prepare('INSERT INTO mde_monthly_other_donations (sponsor_id, report_month, donation_date, donation_type, amount, entered_by) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($donationTypes as $i => $type) {
            $type = trim((string) $type);
            $amount = filter_var($donationAmounts[$i] ?? null, FILTER_VALIDATE_INT);
            if ($type === '' && ($amount === false || $amount === 0)) {
                continue;
            }
            if ($type === '' || $amount === false || $amount < 0) {
                throw new InvalidArgumentException('راجع تفاصيل التبرعات الأخرى.');
            }
            $donationStmt->execute([$sponsorId, $month, $entryDate, $type, $amount, $enteredBy ?: null]);
        }

        $pdo->commit();
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
<?php $whatsappImportProfile = 'entry'; require __DIR__ . '/../includes/whatsapp_import.php'; ?>
<form method="post" id="entryForm" class="card p-3 p-md-4">
<input type="hidden" name="_csrf" value="<?= mde_h(mde_csrf_token()) ?>">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
 <h2 class="h5 mb-0">سجل الكفالة — بنفس ترتيب كشف Excel</h2>
 <div class="d-flex gap-2 flex-wrap">
  <span class="badge text-bg-light border">كفيل واحد لكل عملية إدخال</span>
  <button type="button" class="btn btn-outline-success btn-sm" id="addFamily">إضافة أم</button>
 </div>
</div>
<div class="alert alert-light border py-2 small mb-3">
تظهر بيانات الكفيل مرة واحدة وتمتد بصرياً على جميع الأيتام، وتظهر بيانات كل أم مرة واحدة أمام أيتامها. أدخل مبلغ كل يتيم في صف مستقل.
</div>
<div class="table-responsive entry-register-wrap">
<table class="table table-bordered table-sm align-middle entry-register mb-2" id="entryRegister">
 <colgroup><col style="width:48px"><col style="width:220px"><col style="width:135px"><col style="width:185px"><col style="width:220px"><col style="width:125px"><col style="width:110px"><col style="width:155px"><col style="width:95px"><col style="width:125px"><col style="width:125px"><col style="width:230px"></colgroup>
 <thead>
  <tr>
   <th rowspan="2" class="text-center">الرقم</th>
   <th rowspan="2">إسم الكفيل</th>
   <th rowspan="2">تاريخ الإنضمام</th>
   <th rowspan="2">إسم الأم</th>
   <th rowspan="2">إسم اليتيم</th>
   <th rowspan="2">قيمة الكفالة</th>
   <th rowspan="2">الخدمات</th>
   <th colspan="2" class="text-center">تبرعات أخرى</th>
   <th rowspan="2">بواسطة</th>
   <th rowspan="2">المشرف</th>
   <th rowspan="2">ملاحظات</th>
  </tr>
  <tr><th>نوع التبرع</th><th>القيمة</th></tr>
 </thead>
 <tbody id="families"></tbody>
</table>
</div>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
 <div class="small text-secondary">استخدم زر «إضافة يتيم» داخل خلية الأم لإضافة صف، أو «إضافة أم» لإضافة مجموعة أسرية أخرى.</div>
 <button type="button" class="btn btn-outline-success btn-sm" id="addFamilyBottom">إضافة أم أخرى</button>
</div>
<div class="card border border-success-subtle bg-success-subtle p-3 mb-3">
 <h3 class="h6 mb-2">مراجعة إجمالي المبالغ الواردة</h3>
 <p class="small text-secondary mb-3">أدخل الإجمالي المشترك الوارد من المصدر (إن توفر). إذا كان قابلاً للقسمة بالتساوي على عدد الأيتام فسيُوزّع تلقائياً؛ وإذا تبقى كسر فستحتاج إلى إدخال مبالغ الأيتام يدوياً. سيتحقق النظام من تطابق مجموع المبالغ مع الإجمالي.</p>
 <div class="row g-3">
  <div class="col-md-4"><label class="form-label">إجمالي الكفالة الوارد</label><input type="number" min="0" step="1" class="form-control" id="expectedSponsorship" name="expected_sponsorship_total" placeholder="اختياري"></div>
  <div class="col-md-4"><label class="form-label">إجمالي الخدمات الوارد</label><input type="number" min="0" step="1" class="form-control" id="expectedServices" name="expected_services_total" placeholder="اختياري"></div>
  <div class="col-md-4"><div class="small">المجموع الموزع حالياً</div><div class="fw-bold">الكفالة: <span id="actualSponsorship">0</span></div><div class="fw-bold">الخدمات: <span id="actualServices">0</span></div></div>
 </div>
 <div class="small mt-2" id="amountCheck" aria-live="polite">يمكنك ترك الإجماليين فارغين إذا لم يرد إجمالي مشترك في المصدر.</div>
</div>
<div class="d-flex justify-content-end mt-2"><button class="btn btn-success px-5" type="submit">حفظ البيانات</button></div>
</form>

<template id="sponsorCellsTemplate">
 <table><tbody><tr>
 <td class="sponsor-span-cell text-center sponsor-sequence-cell"><span class="fw-bold" title="يُحدَّد الرقم التسلسلي في التقرير الشهري">—</span></td>
 <td class="sponsor-span-cell sponsor-main-cell">
  <label class="form-label required small mb-1">إضافة إلى كفيل موجود (اختياري)</label>
  <select class="form-select form-select-sm mb-2" name="existing_sponsor_id" id="existingSponsor"><option value="">— تسجيل كفيل جديد —</option><?php foreach ($existingSponsors as $existing): ?><option value="<?= (int)$existing['id'] ?>" data-type="<?= mde_h($existing['sponsor_type']) ?>"><?= mde_h($existing['sponsor_name']) ?></option><?php endforeach; ?></select>
  <label class="form-label small mb-1" id="sponsorNameLabel">اسم الكفيل الجديد</label>
  <input class="form-control form-control-sm mb-2" name="sponsor_name" id="sponsorName" maxlength="255">
  <label class="form-label small mb-1">رقم الملف (إن وجد)</label><input class="form-control form-control-sm mb-2" name="file_number" maxlength="100">
  <label class="form-label small mb-1" for="sponsorType">نوع الكفيل</label>
  <select class="form-select form-select-sm" name="sponsor_type" id="sponsorType" required><option value="individual">فردي</option><option value="tree">🌳 شجرة (داخلي)</option></select>
 </td>
 <td class="sponsor-span-cell"><label class="form-label required small">تاريخ الانضمام</label><input type="date" class="form-control form-control-sm" name="entry_date" id="entryDate" required value="<?= date('Y-m-d') ?>"></td>
 <td class="sponsor-span-cell donation-span-cell" colspan="2">
  <div class="small fw-bold mb-2">التبرعات الأخرى</div>
  <div id="donations"></div>
  <button type="button" class="btn btn-outline-secondary btn-sm mt-1" id="addDonation">إضافة تبرع</button>
 </td>
 <td class="sponsor-span-cell"><label class="form-label small" for="enteredBy">بواسطة</label><?php mde_person_dropdown('enteredBy', 'entered_by', $mdeEnteredByOptions); ?></td>
 <td class="sponsor-span-cell"><label class="form-label small" for="supervisorName">المشرف</label><?php mde_person_dropdown('supervisorName', 'supervisor_name', $mdeSupervisorOptions); ?></td>
 <td class="sponsor-span-cell"><label class="form-label small" for="entryNotes">ملاحظات</label><textarea class="form-control form-control-sm" name="notes" id="entryNotes" rows="5"></textarea></td>
 </tr></tbody></table>
</template>

<template id="familyTemplate">
 <table><tbody><tr class="orphan-row family-block" data-family="">
  <td class="mother-span-cell" rowspan="1">
   <span class="family-title visually-hidden">بيانات الأم 1</span>
   <label class="form-label required small mb-1">اسم الأم</label>
   <input class="form-control form-control-sm mother-name" required>
   <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-2 add-orphan">إضافة يتيم</button>
   <button type="button" class="btn btn-outline-danger btn-sm w-100 mt-2 remove-family">حذف الأم</button>
  </td>
  <td class="orphan-name-cell"><div class="d-flex gap-1"><input class="form-control form-control-sm orphan-name" aria-label="اسم اليتيم" required><button type="button" class="btn btn-outline-danger btn-sm remove-orphan" aria-label="إزالة اليتيم">×</button></div></td>
  <td><input type="number" min="0" step="1" class="form-control form-control-sm orphan-amount" aria-label="قيمة الكفالة" value="0" required></td>
  <td><input type="number" min="0" step="1" class="form-control form-control-sm orphan-services" aria-label="الخدمات" value="0"></td>
 </tr></tbody></table>
</template>
<template id="orphanTemplate">
 <table><tbody><tr class="orphan-row">
  <td class="orphan-name-cell"><div class="d-flex gap-1"><input class="form-control form-control-sm orphan-name" aria-label="اسم اليتيم" required><button type="button" class="btn btn-outline-danger btn-sm remove-orphan" aria-label="إزالة اليتيم">×</button></div></td>
  <td><input type="number" min="0" step="1" class="form-control form-control-sm orphan-amount" aria-label="قيمة الكفالة" value="0" required></td>
  <td><input type="number" min="0" step="1" class="form-control form-control-sm orphan-services" aria-label="الخدمات" value="0"></td>
 </tr></tbody></table>
</template>
<template id="donationTemplate">
 <div class="row g-1 mb-2 donation-row">
  <div class="col-7"><input class="form-control form-control-sm donation-type" placeholder="نوع التبرع" aria-label="نوع التبرع"></div>
  <div class="col-4"><input type="number" min="0" step="1" class="form-control form-control-sm donation-amount" placeholder="القيمة" aria-label="قيمة التبرع"></div>
  <div class="col-1"><button type="button" class="btn btn-outline-danger btn-sm px-2 remove-donation" aria-label="حذف التبرع">×</button></div>
 </div>
</template>
<script src="assets/js/entry-register.js" defer></script>
