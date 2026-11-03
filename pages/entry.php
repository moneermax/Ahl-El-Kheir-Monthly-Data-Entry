<?php
declare(strict_types=1);

$error = '';
$existingSponsors = $pdo->query('SELECT id, sponsor_name FROM mde_sponsors ORDER BY sponsor_name')->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        mde_verify_csrf();
        $sponsorName = trim((string) ($_POST['sponsor_name'] ?? ''));
        $fileNumber = trim((string) ($_POST['file_number'] ?? ''));
        $selectedSponsorId = filter_var($_POST['existing_sponsor_id'] ?? null, FILTER_VALIDATE_INT);
        $entryDate = (string) ($_POST['entry_date'] ?? '');
        $enteredBy = trim((string) ($_POST['entered_by'] ?? ''));
        $supervisor = trim((string) ($_POST['supervisor_name'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $families = $_POST['families'] ?? [];

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
            $pdo->prepare('UPDATE mde_sponsors SET is_active = 1, file_number = COALESCE(NULLIF(?, \'\'), file_number) WHERE id = ?')->execute([$fileNumber, $sponsorId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO mde_sponsors (sponsor_name, file_number) VALUES (?, ?)');
            $stmt->execute([$sponsorName, $fileNumber ?: null]);
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
        mde_redirect('entry');
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
<form method="post" class="card p-3 p-md-4">
<input type="hidden" name="_csrf" value="<?= mde_h(mde_csrf_token()) ?>">
<div class="row g-3 mb-4">
 <div class="col-md-6"><label class="form-label">إضافة إلى كفيل موجود (اختياري)</label><select class="form-select" name="existing_sponsor_id" id="existingSponsor"><option value="">— تسجيل كفيل جديد —</option><?php foreach ($existingSponsors as $existing): ?><option value="<?= (int)$existing['id'] ?>"><?= mde_h($existing['sponsor_name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-6"><label class="form-label" id="sponsorNameLabel">اسم الكفيل الجديد</label><input class="form-control" name="sponsor_name" id="sponsorName" maxlength="255"></div>
 <div class="col-md-3"><label class="form-label">رقم الملف (إن وجد)</label><input class="form-control" name="file_number" maxlength="100"></div>
 <div class="col-md-3"><label class="form-label required">تاريخ الانضمام</label><input type="date" class="form-control" name="entry_date" required value="<?= date('Y-m-d') ?>"></div>
 <div class="col-md-3"><label class="form-label">بواسطة</label><input class="form-control" name="entered_by"></div>
 <div class="col-md-6"><label class="form-label">المشرف</label><input class="form-control" name="supervisor_name"></div>
 <div class="col-12"><label class="form-label">ملاحظات عامة</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
</div>
<div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h5">الأمهات والأيتام</h2><button type="button" class="btn btn-outline-success btn-sm" id="addFamily">إضافة أم أخرى</button></div>
<div id="families"></div>
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
 function addFamily(){const node=familyTpl.content.cloneNode(true),block=node.querySelector('.family-block');families.append(node);addOrphan(block);reindex();}
 document.getElementById('addFamily').addEventListener('click',addFamily);
 document.getElementById('addDonation').addEventListener('click',()=>{donations.append(donationTpl.content.cloneNode(true));reindex();});
 document.addEventListener('click',e=>{
  if(e.target.matches('.add-orphan')){addOrphan(e.target.closest('.family-block'));reindex();}
  if(e.target.matches('.remove-family')){e.target.closest('.family-block').remove();reindex();}
  if(e.target.matches('.remove-orphan')){const block=e.target.closest('.family-block');e.target.closest('.orphan-row').remove();if(!block.querySelector('.orphan-row'))addOrphan(block);reindex();}
  if(e.target.matches('.remove-donation')){e.target.closest('.donation-row').remove();reindex();}
 });
 const existing=document.getElementById('existingSponsor'), sponsorName=document.getElementById('sponsorName');
 function syncSponsorMode(){const isExisting=!!existing.value;sponsorName.required=!isExisting;sponsorName.disabled=isExisting;if(isExisting)sponsorName.value='';}
 existing.addEventListener('change',syncSponsorMode);syncSponsorMode();
 addFamily();
})();
</script>
