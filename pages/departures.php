<?php
declare(strict_types=1);

$error = '';
$sponsors = $pdo->query('SELECT id, sponsor_name, file_number FROM mde_sponsors WHERE is_active = 1 ORDER BY sponsor_name')->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        mde_verify_csrf();
        $sponsorId = filter_var($_POST['sponsor_id'] ?? null, FILTER_VALIDATE_INT);
        $departureDate = (string) ($_POST['departure_date'] ?? '');
        $fileNumber = trim((string) ($_POST['file_number'] ?? ''));
        $lastPaid = trim((string) ($_POST['last_paid_month'] ?? ''));
        $count = filter_var($_POST['orphan_count'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['sponsorship_amount'] ?? 0, FILTER_VALIDATE_INT);
        $services = filter_var($_POST['services_amount'] ?? 0, FILTER_VALIDATE_INT);
        if (!$sponsorId || !$departureDate || $count === false || $count < 0 || $amount === false || $amount < 0 || $services === false || $services < 0) {
            throw new InvalidArgumentException('أكمل الحقول المطلوبة وتأكد من صحة الأرقام.');
        }
        $pdo->beginTransaction();
        $check = $pdo->prepare('SELECT id FROM mde_sponsors WHERE id = ?');
        $check->execute([$sponsorId]);
        if (!$check->fetch()) {
            throw new InvalidArgumentException('الكفيل المحدد غير موجود.');
        }
        $stmt = $pdo->prepare('INSERT INTO mde_sponsor_departures (sponsor_id, file_number_snapshot, departure_date, last_paid_month, orphan_count_snapshot, sponsorship_amount_snapshot, services_amount_snapshot, entered_by, supervisor_name, reason, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $sponsorId, $fileNumber ?: null, $departureDate,
            $lastPaid !== '' ? mde_month_start($lastPaid . '-01') : null,
            $count, $amount, $services,
            trim((string) ($_POST['entered_by'] ?? '')) ?: null,
            trim((string) ($_POST['supervisor_name'] ?? '')) ?: null,
            trim((string) ($_POST['reason'] ?? '')) ?: null,
            trim((string) ($_POST['notes'] ?? '')) ?: null
        ]);
        $end = $pdo->prepare("UPDATE mde_sponsorships SET status = 'ended', end_date = ? WHERE sponsor_id = ? AND status = 'active'");
        $end->execute([$departureDate, $sponsorId]);
        $pdo->prepare('UPDATE mde_sponsors SET is_active = 0, file_number = COALESCE(NULLIF(?, \'\'), file_number) WHERE id = ?')->execute([$fileNumber, $sponsorId]);
        $pdo->commit();
        mde_flash('success', 'تم تسجيل مغادرة الكفيل وإنهاء كفالاته النشطة مع الاحتفاظ بالسجلات التاريخية.');
        mde_redirect('departures');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception instanceof PDOException ? 'تعذر حفظ المغادرة. تحقق مما إذا كانت مسجلة مسبقاً.' : $exception->getMessage();
    }
}
?>
<h1 class="h3 mb-3">تسجيل مغادرة كفيل</h1>
<?php if ($error): ?><div class="alert alert-danger"><?= mde_h($error) ?></div><?php endif; ?>
<?php if (!$sponsors): ?><div class="alert alert-info">لا يوجد كفلاء بعد. أضف كفيلاً أولاً من صفحة إدخال الكفالات.</div><?php else: ?>
<form method="post" class="card p-3 p-md-4">
<input type="hidden" name="_csrf" value="<?= mde_h(mde_csrf_token()) ?>">
<div class="row g-3">
 <div class="col-md-6"><label class="form-label required">الكفيل</label><select class="form-select" name="sponsor_id" id="departureSponsor" required><option value="">اختر الكفيل</option><?php foreach($sponsors as $s): ?><option value="<?= (int)$s['id'] ?>" data-file="<?= mde_h($s['file_number']) ?>"><?= mde_h($s['sponsor_name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-3"><label class="form-label">رقم الملف</label><input class="form-control" name="file_number" id="departureFileNumber" maxlength="100"></div>
 <div class="col-md-3"><label class="form-label required">تاريخ المغادرة</label><input type="date" class="form-control" name="departure_date" value="<?= date('Y-m-d') ?>" required></div>
 <div class="col-md-3"><label class="form-label">آخر سداد (الشهر)</label><input type="month" class="form-control" name="last_paid_month"></div>
 <div class="col-md-4"><label class="form-label">عدد الأيتام</label><input type="number" min="0" class="form-control" name="orphan_count" value="0"></div>
 <div class="col-md-4"><label class="form-label">قيمة الكفالة الإجمالية</label><input type="number" min="0" step="1" class="form-control" name="sponsorship_amount" value="0"></div>
 <div class="col-md-4"><label class="form-label">الخدمات</label><input type="number" min="0" step="1" class="form-control" name="services_amount" value="0"></div>
 <div class="col-md-6"><label class="form-label">بواسطة</label><input class="form-control" name="entered_by"></div>
 <div class="col-md-6"><label class="form-label">المشرف</label><input class="form-control" name="supervisor_name"></div>
 <div class="col-12"><label class="form-label">سبب المغادرة</label><input class="form-control" name="reason"></div>
 <div class="col-12"><label class="form-label">ملاحظات</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
 <div class="col-12"><button class="btn btn-danger px-5">تسجيل المغادرة</button><p class="form-text mt-2">سيتم إنهاء جميع الكفالات النشطة لهذا الكفيل، دون حذف أي سجل أو مبالغ شهرية سابقة.</p></div>
</div>
</form>
<script>
(() => {
 const select=document.getElementById('departureSponsor'), field=document.getElementById('departureFileNumber');
 select.addEventListener('change',()=>{field.value=select.selectedOptions[0]?.dataset.file || '';});
})();
</script>
<?php endif; ?>
