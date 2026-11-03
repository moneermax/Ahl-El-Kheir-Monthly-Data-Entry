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
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h3 mb-0">إدخال كفالة جديدة</h1><span class="badge text-bg-secondary">المبالغ بالجنيه السوداني</span></div>
<?php if ($error): ?><div class="alert alert-danger"><?= mde_h($error) ?></div><?php endif; ?>
<?php $whatsappImportProfile = 'entry'; require __DIR__ . '/../includes/whatsapp_import.php'; ?>
<form method="post" id="entryForm" class="card p-3 p-md-4">
<input type="hidden" name="_csrf" value="<?= mde_h(mde_csrf_token()) ?>">
<div class="row g-3 mb-4">
 <div class="col-md-6"><label class="form-label">إضافة إلى كفيل موجود (اختياري)</label><select class="form-select" name="existing_sponsor_id" id="existingSponsor"><option value="">— تسجيل كفيل جديد —</option><?php foreach ($existingSponsors as $existing): ?><option value="<?= (int)$existing['id'] ?>" data-type="<?= mde_h($existing['sponsor_type']) ?>"><?= mde_h($existing['sponsor_name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-6"><label class="form-label" id="sponsorNameLabel">اسم الكفيل الجديد</label><input class="form-control" name="sponsor_name" id="sponsorName" maxlength="255"></div>
 <div class="col-md-4"><label class="form-label">رقم الملف (إن وجد)</label><input class="form-control" name="file_number" maxlength="100"></div>
</div>
<div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h5">الأمهات والأيتام</h2><button type="button" class="btn btn-outline-success btn-sm" id="addFamily">إضافة أم أخرى</button></div>
<div id="families"></div>
<div class="card border border-success-subtle bg-success-subtle p-3 mb-3">
 <h3 class="h6 mb-2">مراجعة إجمالي المبالغ الواردة</h3>
 <p class="small text-secondary mb-3">أدخل الإجمالي المشترك الوارد من المصدر (إن توفر). وزّع المبلغ يدوياً على الأيتام؛ لن يقسمه النظام تلقائياً. سيتحقق من تطابق مجموع مبالغ الأيتام مع الإجمالي.</p>
 <div class="row g-3">
  <div class="col-md-4"><label class="form-label">إجمالي الكفالة الوارد</label><input type="number" min="0" step="1" class="form-control" id="expectedSponsorship" name="expected_sponsorship_total" placeholder="اختياري"></div>
  <div class="col-md-4"><label class="form-label">إجمالي الخدمات الوارد</label><input type="number" min="0" step="1" class="form-control" id="expectedServices" name="expected_services_total" placeholder="اختياري"></div>
  <div class="col-md-4"><div class="small">المجموع الموزع حالياً</div><div class="fw-bold">الكفالة: <span id="actualSponsorship">0</span></div><div class="fw-bold">الخدمات: <span id="actualServices">0</span></div></div>
 </div>
 <div class="small mt-2" id="amountCheck" aria-live="polite">يمكنك ترك الإجماليين فارغين إذا لم يرد إجمالي مشترك في المصدر.</div>
</div>
<div class="card border p-3 mb-3">
 <h3 class="h6 mb-3">بيانات الكفالة</h3>
 <div class="row g-3">
  <div class="col-md-4"><label class="form-label required" for="sponsorType">نوع الكفيل</label><select class="form-select" name="sponsor_type" id="sponsorType" required><option value="individual">فردي</option><option value="tree">🌳 شجرة (داخلي)</option></select></div>
  <div class="col-md-4"><label class="form-label required" for="entryDate">تاريخ الانضمام</label><input type="date" class="form-control" name="entry_date" id="entryDate" required value="<?= date('Y-m-d') ?>"></div>
 </div>
 <div class="row g-3 mt-1">
  <div class="col-md-6"><label class="form-label" for="enteredBy">بواسطة</label><select class="form-select" name="entered_by" id="enteredBy"><option value="">اختر الاسم</option><?php foreach ($mdeEnteredByOptions as $person): ?><option value="<?= mde_h($person) ?>"><?= mde_h($person) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label" for="supervisorName">المشرف</label><select class="form-select" name="supervisor_name" id="supervisorName"><option value="">اختر الاسم</option><?php foreach ($mdeSupervisorOptions as $person): ?><option value="<?= mde_h($person) ?>"><?= mde_h($person) ?></option><?php endforeach; ?></select></div>
 </div>
</div>
<div class="row g-3 mb-3">
 <div class="col-12"><label class="form-label" for="entryNotes">ملاحظات عامة</label><textarea class="form-control" name="notes" id="entryNotes" rows="2"></textarea></div>
</div>
<hr><div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h5">تبرعات أخرى (اختياري)</h2><button type="button" class="btn btn-outline-secondary btn-sm" id="addDonation">إضافة تبرع</button></div>
<div id="donations"></div>
<div class="mt-4"><button class="btn btn-success px-5" type="submit">حفظ البيانات</button></div>
</form>
<template id="familyTemplate">
 <section class="border rounded-3 p-3 mb-3 family-block bg-light">
  <div class="d-flex justify-content-between align-items-center mb-2"><strong class="family-title">بيانات الأم</strong><button type="button" class="btn btn-sm btn-outline-danger remove-family">حذف الأسرة</button></div>
  <label class="form-label required">اسم الأم</label><input class="form-control mb-3 mother-name" required>
  <div class="orphans"></div><button type="button" class="btn btn-sm btn-outline-primary add-orphan">إضافة يتيم لهذه الأم</button>
 </section>
</template>
<template id="orphanTemplate">
 <div class="row g-2 align-items-end mb-2 orphan-row">
  <div class="col-md-6"><label class="form-label required">اسم اليتيم</label><input class="form-control orphan-name" required></div>
  <div class="col-md-2"><label class="form-label required">قيمة الكفالة</label><input type="number" min="0" step="1" class="form-control orphan-amount" value="0" required></div>
  <div class="col-md-2"><label class="form-label">الخدمات</label><input type="number" min="0" step="1" class="form-control orphan-services" value="0"></div>
  <div class="col-md-2"><button type="button" class="btn btn-outline-danger w-100 remove-orphan">إزالة</button></div>
 </div>
</template>
<template id="donationTemplate"><div class="row g-2 mb-2 donation-row"><div class="col-md-8"><input class="form-control donation-type" placeholder="نوع التبرع"></div><div class="col-md-3"><input type="number" min="0" step="1" class="form-control donation-amount" placeholder="القيمة"></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger remove-donation">×</button></div></div></template>
<script>
(() => {
 const families=document.getElementById('families'), donations=document.getElementById('donations');
 const familyTpl=document.getElementById('familyTemplate'), orphanTpl=document.getElementById('orphanTemplate'), donationTpl=document.getElementById('donationTemplate');
 function addOrphan(block){block.querySelector('.orphans').append(orphanTpl.content.cloneNode(true));}
 function reindex(){
  [...families.querySelectorAll('.family-block')].forEach((block,fi)=>{
   block.querySelector('.family-title').textContent='بيانات الأم '+(fi+1);
   block.querySelector('.mother-name').name=`families[${fi}][mother_name]`;
   [...block.querySelectorAll('.orphan-row')].forEach((row,oi)=>{
    row.querySelector('.orphan-name').name=`families[${fi}][orphans][${oi}][name]`;
    row.querySelector('.orphan-amount').name=`families[${fi}][orphans][${oi}][amount]`;
    row.querySelector('.orphan-services').name=`families[${fi}][orphans][${oi}][services]`;
   });
  });
  [...donations.querySelectorAll('.donation-row')].forEach((row,i)=>{
   row.querySelector('.donation-type').name=`donation_type[${i}]`;
   row.querySelector('.donation-amount').name=`donation_amount[${i}]`;
  });
 }
 function addFamily(){const node=familyTpl.content.cloneNode(true),block=node.querySelector('.family-block');families.append(node);addOrphan(block);reindex();updateAmountTotals();}
 document.getElementById('addFamily').addEventListener('click',addFamily);
 document.getElementById('addDonation').addEventListener('click',()=>{donations.append(donationTpl.content.cloneNode(true));reindex();});
 document.addEventListener('click',e=>{
  if(e.target.matches('.add-orphan')){addOrphan(e.target.closest('.family-block'));reindex();updateAmountTotals();}
  if(e.target.matches('.remove-family')){e.target.closest('.family-block').remove();reindex();updateAmountTotals();}
  if(e.target.matches('.remove-orphan')){const block=e.target.closest('.family-block');e.target.closest('.orphan-row').remove();if(!block.querySelector('.orphan-row'))addOrphan(block);reindex();updateAmountTotals();}
  if(e.target.matches('.remove-donation')){e.target.closest('.donation-row').remove();reindex();}
 });

 const expectedSponsorship=document.getElementById('expectedSponsorship');
 const expectedServices=document.getElementById('expectedServices');
 const actualSponsorship=document.getElementById('actualSponsorship');
 const actualServices=document.getElementById('actualServices');
 const amountCheck=document.getElementById('amountCheck');
 const entryForm=document.querySelector('form.card');
 function updateAmountTotals(){
  let sponsorship=0,services=0;
  families.querySelectorAll('.orphan-row').forEach(row=>{
   sponsorship+=Math.max(0,Number(row.querySelector('.orphan-amount').value)||0);
   services+=Math.max(0,Number(row.querySelector('.orphan-services').value)||0);
  });
  actualSponsorship.textContent=sponsorship.toLocaleString('en-US');
  actualServices.textContent=services.toLocaleString('en-US');
  const expectedS=expectedSponsorship.value===''?null:Number(expectedSponsorship.value);
  const expectedV=expectedServices.value===''?null:Number(expectedServices.value);
  const errors=[];
  if(expectedS!==null && sponsorship!==expectedS) errors.push('إجمالي الكفالة الموزع لا يطابق الإجمالي الوارد');
  if(expectedV!==null && services!==expectedV) errors.push('إجمالي الخدمات الموزع لا يطابق الإجمالي الوارد');
  amountCheck.textContent=errors.length?errors.join('، '):'الإجماليات متطابقة أو لم يتم إدخال إجمالي مرجعي.';
  amountCheck.className=errors.length?'small mt-2 text-danger fw-bold':'small mt-2 text-success';
  return errors.length===0;
 }
 families.addEventListener('input',updateAmountTotals);
 expectedSponsorship.addEventListener('input',updateAmountTotals);
 expectedServices.addEventListener('input',updateAmountTotals);
 entryForm.addEventListener('submit',event=>{
  if(!updateAmountTotals()){event.preventDefault();amountCheck.scrollIntoView({behavior:'smooth',block:'center'});}
 });

 const existing=document.getElementById('existingSponsor'), sponsorName=document.getElementById('sponsorName'), sponsorType=document.getElementById('sponsorType');
 function syncSponsorMode(){
  const isExisting=!!existing.value;
  sponsorName.required=!isExisting;
  sponsorName.disabled=isExisting;
  if(isExisting){
   sponsorName.value='';
   const option=existing.selectedOptions[0];
   sponsorType.value=option.dataset.type || 'individual';
  }else{
   sponsorType.value='individual';
  }
 }
 existing.addEventListener('change',syncSponsorMode);syncSponsorMode();
 addFamily();
 updateAmountTotals();
})();
</script>
