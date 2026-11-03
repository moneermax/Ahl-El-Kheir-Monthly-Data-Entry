<?php
declare(strict_types=1);

$error = '';
require_once __DIR__ . '/../includes/reference_options.php';
$q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));

function mde_log_change(PDO $pdo, string $type, int $id, array $before, array $after): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO mde_change_log (entity_type, entity_id, action, before_data, after_data)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $type,
        $id,
        'update',
        json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        mde_verify_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new InvalidArgumentException('معرّف السجل غير صحيح.');
        }

        $pdo->beginTransaction();

        if ($action === 'sponsor') {
            $stmt = $pdo->prepare('SELECT id, sponsor_name, file_number, sponsor_type, notes FROM mde_sponsors WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $before = $stmt->fetch();
            if (!$before) throw new InvalidArgumentException('الكفيل غير موجود.');

            $name = trim((string) ($_POST['sponsor_name'] ?? ''));
            $file = trim((string) ($_POST['file_number'] ?? ''));
            $type = (string) ($_POST['sponsor_type'] ?? '');
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($name === '' || !in_array($type, ['individual', 'tree'], true)) {
                throw new InvalidArgumentException('أدخل اسم الكفيل واختر نوعاً صحيحاً.');
            }
            $duplicate = $pdo->prepare('SELECT id FROM mde_sponsors WHERE LOWER(TRIM(sponsor_name)) = LOWER(?) AND id <> ? LIMIT 1');
            $duplicate->execute([$name, $id]);
            if ($duplicate->fetch()) throw new InvalidArgumentException('يوجد كفيل آخر بالاسم نفسه. راجع السجل قبل تغيير الاسم.');

            $after = ['sponsor_name' => $name, 'file_number' => $file ?: null, 'sponsor_type' => $type, 'notes' => $notes ?: null];
            $pdo->prepare('UPDATE mde_sponsors SET sponsor_name=?, file_number=?, sponsor_type=?, notes=? WHERE id=?')
                ->execute([$name, $file ?: null, $type, $notes ?: null, $id]);
            mde_log_change($pdo, 'sponsor', $id, $before, $after);
            $message = 'تم تحديث بيانات الكفيل.';

        } elseif ($action === 'mother') {
            $stmt = $pdo->prepare('SELECT id, mother_name FROM mde_mothers WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $before = $stmt->fetch();
            if (!$before) throw new InvalidArgumentException('الأم غير موجودة.');
            $name = trim((string) ($_POST['mother_name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('اسم الأم مطلوب.');
            $after = ['mother_name' => $name];
            $pdo->prepare('UPDATE mde_mothers SET mother_name=? WHERE id=?')->execute([$name, $id]);
            mde_log_change($pdo, 'mother', $id, $before, $after);
            $message = 'تم تحديث اسم الأم. سيظهر التعديل في جميع سجلاتها المرتبطة.';

        } elseif ($action === 'orphan') {
            $stmt = $pdo->prepare('SELECT id, orphan_name FROM mde_orphans WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $before = $stmt->fetch();
            if (!$before) throw new InvalidArgumentException('اليتيم غير موجود.');
            $name = trim((string) ($_POST['orphan_name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('اسم اليتيم مطلوب.');
            $after = ['orphan_name' => $name];
            $pdo->prepare('UPDATE mde_orphans SET orphan_name=? WHERE id=?')->execute([$name, $id]);
            mde_log_change($pdo, 'orphan', $id, $before, $after);
            $message = 'تم تحديث اسم اليتيم. سيظهر التعديل في جميع سجلات كفالته.';

        } elseif ($action === 'monthly_entry') {
            $stmt = $pdo->prepare('SELECT id, entry_date, sponsorship_amount, services_amount, entered_by, supervisor_name, notes FROM mde_monthly_sponsorship_entries WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $before = $stmt->fetch();
            if (!$before) throw new InvalidArgumentException('سجل الشهر غير موجود.');

            $date = trim((string) ($_POST['entry_date'] ?? ''));
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $amount = filter_var($_POST['sponsorship_amount'] ?? null, FILTER_VALIDATE_INT);
            $services = filter_var($_POST['services_amount'] ?? null, FILTER_VALIDATE_INT);
            if (!$parsed || $parsed->format('Y-m-d') !== $date || $amount === false || $amount < 0 || $services === false || $services < 0) {
                throw new InvalidArgumentException('تحقق من التاريخ والمبالغ؛ يجب أن تكون المبالغ أعداداً صحيحة غير سالبة.');
            }
            $enteredBy = trim((string) ($_POST['entered_by'] ?? ''));
            $supervisor = trim((string) ($_POST['supervisor_name'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $after = [
                'entry_date' => $date,
                'sponsorship_amount' => $amount,
                'services_amount' => $services,
                'entered_by' => $enteredBy ?: null,
                'supervisor_name' => $supervisor ?: null,
                'notes' => $notes ?: null,
            ];
            $pdo->prepare('UPDATE mde_monthly_sponsorship_entries SET entry_date=?, sponsorship_amount=?, services_amount=?, entered_by=?, supervisor_name=?, notes=? WHERE id=?')
                ->execute([$date, $amount, $services, $enteredBy ?: null, $supervisor ?: null, $notes ?: null, $id]);
            mde_log_change($pdo, 'monthly_entry', $id, $before, $after);
            $message = 'تم تحديث المبالغ وبيانات سجل الشهر مع الاحتفاظ ببقية السجل.';

        } else {
            throw new InvalidArgumentException('نوع التعديل غير معروف.');
        }

        $pdo->commit();
        mde_flash('success', $message);
        mde_redirect('manage', $q !== '' ? ['q' => $q] : []);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception instanceof PDOException
            ? 'تعذر تحديث السجل بسبب خطأ في قاعدة البيانات. لم يتم حفظ التعديل.'
            : $exception->getMessage();
    }
}

$matches = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $find = $pdo->prepare(
        'SELECT DISTINCT s.id
         FROM mde_sponsors s
         LEFT JOIN mde_sponsorships ss ON ss.sponsor_id=s.id
         LEFT JOIN mde_orphans o ON o.id=ss.orphan_id
         LEFT JOIN mde_mothers mo ON mo.id=o.mother_id
         WHERE s.sponsor_name LIKE ? OR mo.mother_name LIKE ? OR o.orphan_name LIKE ?
         ORDER BY s.sponsor_name
         LIMIT 50'
    );
    $find->execute([$like, $like, $like]);
    $matches = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
}


?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
 <h1 class="h3 mb-0">بحث وإدارة البيانات</h1>
 <span class="badge text-bg-secondary">تعديل مع حفظ سجل التغييرات</span>
</div>
<p class="text-secondary">ابحث باسم الكفيل أو الأم أو اليتيم. يمكنك تصحيح البيانات الأساسية أو مبالغ شهر محدد دون إعادة إدخال السجلات.</p>
<form method="get" class="card p-3 mb-4">
 <input type="hidden" name="page" value="manage">
 <label class="form-label" for="manageSearch">اسم الكفيل أو الأم أو اليتيم</label>
 <div class="input-group"><input class="form-control" id="manageSearch" name="q" value="<?=mde_h($q)?>" placeholder="اكتب جزءاً من الاسم..." required><button class="btn btn-primary">بحث</button></div>
</form>
<?php if ($error): ?><div class="alert alert-danger"><?=mde_h($error)?></div><?php endif; ?>
<?php if ($q !== '' && !$matches): ?><div class="alert alert-warning">لم يتم العثور على سجلات مطابقة.</div><?php endif; ?>
<?php foreach ($matches as $sponsorId):
 $sponsorStmt=$pdo->prepare('SELECT id,sponsor_name,file_number,sponsor_type,notes FROM mde_sponsors WHERE id=?');
 $sponsorStmt->execute([$sponsorId]); $sponsor=$sponsorStmt->fetch(); if (!$sponsor) continue;
 $linksStmt=$pdo->prepare(
  'SELECT ss.id AS sponsorship_id, ss.status, ss.start_date, ss.end_date,
          mo.id AS mother_id, mo.mother_name, o.id AS orphan_id, o.orphan_name,
          me.id AS monthly_id, me.report_month, me.entry_date, me.sponsorship_amount, me.services_amount,
          me.entered_by, me.supervisor_name, me.notes AS monthly_notes
   FROM mde_sponsorships ss
   JOIN mde_orphans o ON o.id=ss.orphan_id
   JOIN mde_mothers mo ON mo.id=o.mother_id
   LEFT JOIN mde_monthly_sponsorship_entries me ON me.sponsorship_id=ss.id
   WHERE ss.sponsor_id=?
   ORDER BY me.report_month DESC, mo.mother_name, o.orphan_name'
 );
 $linksStmt->execute([$sponsorId]); $links=$linksStmt->fetchAll();
?>
<section class="card p-3 p-md-4 mb-4">
 <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h2 class="h5 mb-0"><?=mde_h($sponsor['sponsor_name'])?> <?php if($sponsor['sponsor_type']==='tree'): ?><span class="badge text-bg-success">🌳 شجرة</span><?php endif; ?></h2>
  <span class="text-secondary small">رقم الكفيل: #<?= (int)$sponsor['id'] ?></span>
 </div>
 <form method="post" class="border rounded-3 p-3 mb-3 bg-light">
  <input type="hidden" name="_csrf" value="<?=mde_h(mde_csrf_token())?>"><input type="hidden" name="action" value="sponsor"><input type="hidden" name="id" value="<?=(int)$sponsor['id']?>"><input type="hidden" name="q" value="<?=mde_h($q)?>">
  <div class="row g-2">
   <div class="col-md-5"><label class="form-label">اسم الكفيل</label><input class="form-control" name="sponsor_name" maxlength="255" required value="<?=mde_h($sponsor['sponsor_name'])?>"></div>
   <div class="col-md-2"><label class="form-label">رقم الملف</label><input class="form-control" name="file_number" maxlength="100" value="<?=mde_h($sponsor['file_number'])?>"></div>
   <div class="col-md-2"><label class="form-label">النوع</label><select class="form-select" name="sponsor_type"><option value="individual" <?=$sponsor['sponsor_type']==='individual'?'selected':''?>>فردي</option><option value="tree" <?=$sponsor['sponsor_type']==='tree'?'selected':''?>>🌳 شجرة</option></select></div>
   <div class="col-md-3"><label class="form-label">ملاحظات</label><input class="form-control" name="notes" value="<?=mde_h($sponsor['notes'])?>"></div>
  </div>
  <button class="btn btn-outline-primary btn-sm mt-3" type="submit">حفظ بيانات الكفيل</button>
 </form>
 <?php if (!$links): ?><p class="text-secondary mb-0">لا توجد روابط كفالة مسجلة لهذا الكفيل.</p><?php endif; ?>
 <?php foreach($links as $link): ?>
  <div class="border rounded-3 p-3 mb-3">
   <div class="row g-2 mb-3">
    <div class="col-md-6">
     <label class="form-label small">اسم الأم</label>
     <form method="post" class="input-group">
      <input type="hidden" name="_csrf" value="<?=mde_h(mde_csrf_token())?>"><input type="hidden" name="action" value="mother"><input type="hidden" name="id" value="<?=(int)$link['mother_id']?>"><input type="hidden" name="q" value="<?=mde_h($q)?>">
      <input class="form-control" name="mother_name" value="<?=mde_h($link['mother_name'])?>" required><button class="btn btn-outline-secondary" type="submit">تحديث الأم</button>
     </form>
    </div>
    <div class="col-md-6">
     <label class="form-label small">اسم اليتيم</label>
     <form method="post" class="input-group">
      <input type="hidden" name="_csrf" value="<?=mde_h(mde_csrf_token())?>"><input type="hidden" name="action" value="orphan"><input type="hidden" name="id" value="<?=(int)$link['orphan_id']?>"><input type="hidden" name="q" value="<?=mde_h($q)?>">
      <input class="form-control" name="orphan_name" value="<?=mde_h($link['orphan_name'])?>" required><button class="btn btn-outline-secondary" type="submit">تحديث اليتيم</button>
     </form>
    </div>
   </div>
   <div class="small text-secondary mb-2">حالة الكفالة: <?=mde_h($link['status'])?> · بداية الكفالة: <?=mde_h($link['start_date'])?> · رقم العلاقة: #<?=(int)$link['sponsorship_id']?></div>
   <?php if ($link['monthly_id']): ?>
    <form method="post" class="bg-light rounded-3 p-3">
     <input type="hidden" name="_csrf" value="<?=mde_h(mde_csrf_token())?>"><input type="hidden" name="action" value="monthly_entry"><input type="hidden" name="id" value="<?=(int)$link['monthly_id']?>"><input type="hidden" name="q" value="<?=mde_h($q)?>">
     <div class="d-flex justify-content-between align-items-center mb-2"><strong>بيانات شهر <?=mde_h(substr((string)$link['report_month'],0,7))?></strong><span class="small text-secondary">سجل #<?=(int)$link['monthly_id']?></span></div>
     <div class="row g-2">
      <div class="col-md-2"><label class="form-label small">تاريخ الإدخال</label><input type="date" class="form-control" name="entry_date" value="<?=mde_h($link['entry_date'])?>" required></div>
      <div class="col-md-2"><label class="form-label small">الكفالة</label><input type="number" min="0" step="1" class="form-control" name="sponsorship_amount" value="<?=(int)$link['sponsorship_amount']?>" required></div>
      <div class="col-md-2"><label class="form-label small">الخدمات</label><input type="number" min="0" step="1" class="form-control" name="services_amount" value="<?=(int)$link['services_amount']?>" required></div>
      <div class="col-md-2"><label class="form-label small">بواسطة</label><?php mde_person_dropdown('manageEnteredBy', 'entered_by', $mdeEnteredByOptions, '—', (string) ($link['entered_by'] ?? '')); ?></div>
      <div class="col-md-2"><label class="form-label small">المشرف</label><?php mde_person_dropdown('manageSupervisor', 'supervisor_name', $mdeSupervisorOptions, '—', (string) ($link['supervisor_name'] ?? '')); ?></div>
      <div class="col-md-2"><label class="form-label small">ملاحظات</label><input class="form-control" name="notes" value="<?=mde_h($link['monthly_notes'])?>"></div>
     </div>
     <button class="btn btn-outline-primary btn-sm mt-3" type="submit">حفظ تعديل الشهر</button>
    </form>
   <?php else: ?><p class="small text-secondary mb-0">لا يوجد سجل شهري لهذه العلاقة.</p><?php endif; ?>
  </div>
 <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php if ($q !== '' && $matches): ?><p class="small text-secondary">عُرضت حتى 50 نتيجة كفيل. التعديلات على اسم الأم أو اليتيم تنعكس على كل العلاقات المرتبطة بهما، ولا تحذف أي سجل تاريخي.</p><?php endif; ?>
