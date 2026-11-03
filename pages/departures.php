<?php
declare(strict_types=1);

$error = '';
require_once __DIR__ . '/../includes/reference_options.php';
$old = $_POST ?? [];
$sponsors = $pdo->query(
    'SELECT id, sponsor_name, file_number, is_active
     FROM mde_sponsors
     ORDER BY sponsor_name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        mde_verify_csrf();

        $mode = (string) ($_POST['sponsor_mode'] ?? 'existing');
        if (!in_array($mode, ['existing', 'new'], true)) {
            throw new InvalidArgumentException('اختر طريقة تسجيل الكفيل.');
        }

        $departureDate = trim((string) ($_POST['departure_date'] ?? ''));
        $departureParsed = DateTimeImmutable::createFromFormat('!Y-m-d', $departureDate);
        if (!$departureParsed || $departureParsed->format('Y-m-d') !== $departureDate) {
            throw new InvalidArgumentException('أدخل تاريخ مغادرة صحيحاً.');
        }

        $lastPaid = trim((string) ($_POST['last_paid_month'] ?? ''));
        $lastPaidDate = null;
        if ($lastPaid !== '') {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $lastPaid)) {
                throw new InvalidArgumentException('أدخل شهر آخر سداد بصورة صحيحة.');
            }
            $lastPaidDate = mde_month_start($lastPaid . '-01');
        }

        $count = filter_var($_POST['orphan_count'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['sponsorship_amount'] ?? null, FILTER_VALIDATE_INT);
        $services = filter_var($_POST['services_amount'] ?? null, FILTER_VALIDATE_INT);
        if ($count === false || $count === null || $count < 0 ||
            $amount === false || $amount === null || $amount < 0 ||
            $services === false || $services === null || $services < 0) {
            throw new InvalidArgumentException('أكمل عدد الأيتام والمبالغ بأرقام صحيحة غير سالبة.');
        }

        $fileNumber = trim((string) ($_POST['file_number'] ?? ''));
        $newSponsorType = (string) ($_POST['new_sponsor_type'] ?? 'individual');
        if (!in_array($newSponsorType, ['individual', 'tree'], true)) {
            throw new InvalidArgumentException('اختر نوع الكفيل بصورة صحيحة.');
        }
        $pdo->beginTransaction();

        if ($mode === 'existing') {
            $sponsorId = filter_var($_POST['sponsor_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$sponsorId || $sponsorId < 1) {
                throw new InvalidArgumentException('اختر الكفيل المسجل.');
            }

            $check = $pdo->prepare('SELECT id, sponsor_name, file_number, is_active FROM mde_sponsors WHERE id = ? FOR UPDATE');
            $check->execute([$sponsorId]);
            $sponsor = $check->fetch();
            if (!$sponsor) {
                throw new InvalidArgumentException('الكفيل المحدد غير موجود.');
            }
            if (!(int) $sponsor['is_active']) {
                throw new InvalidArgumentException('هذا الكفيل مسجل كمغادر بالفعل. إذا عاد للتعامل مع المنظمة، سجّل كفالته الجديدة أولاً ثم سجّل مغادرته عند الحاجة.');
            }

            // The departure form stores a snapshot only; it never edits sponsor identity data.
            if ($fileNumber === '') {
                $fileNumber = (string) ($sponsor['file_number'] ?? '');
            }
        } else {
            $sponsorName = trim((string) ($_POST['new_sponsor_name'] ?? ''));
            if ($sponsorName === '') {
                throw new InvalidArgumentException('أدخل اسم الكفيل الجديد.');
            }

            // Avoid accidental duplicate master records; select the existing sponsor instead.
            $duplicate = $pdo->prepare('SELECT id FROM mde_sponsors WHERE LOWER(TRIM(sponsor_name)) = LOWER(?) LIMIT 1 FOR UPDATE');
            $duplicate->execute([$sponsorName]);
            if ($duplicate->fetch()) {
                throw new InvalidArgumentException('يوجد كفيل بهذا الاسم بالفعل. اختره من قائمة الكفلاء المسجلين بدلاً من إنشاء سجل مكرر.');
            }

            $insertSponsor = $pdo->prepare(
                'INSERT INTO mde_sponsors (sponsor_name, sponsor_type, file_number, phone, address, notes, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 0)'
            );
            $insertSponsor->execute([
                $sponsorName,
                $newSponsorType,
                $fileNumber !== '' ? $fileNumber : null,
                trim((string) ($_POST['new_sponsor_phone'] ?? '')) ?: null,
                trim((string) ($_POST['new_sponsor_address'] ?? '')) ?: null,
                trim((string) ($_POST['new_sponsor_notes'] ?? '')) ?: null,
            ]);
            $sponsorId = (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare(
            'INSERT INTO mde_sponsor_departures
             (sponsor_id, file_number_snapshot, departure_date, last_paid_month,
              orphan_count_snapshot, sponsorship_amount_snapshot, services_amount_snapshot,
              entered_by, supervisor_name, reason, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sponsorId,
            $fileNumber !== '' ? $fileNumber : null,
            $departureDate,
            $lastPaidDate,
            $count,
            $amount,
            $services,
            trim((string) ($_POST['entered_by'] ?? '')) ?: null,
            trim((string) ($_POST['supervisor_name'] ?? '')) ?: null,
            trim((string) ($_POST['reason'] ?? '')) ?: null,
            trim((string) ($_POST['notes'] ?? '')) ?: null,
        ]);

        $end = $pdo->prepare(
            "UPDATE mde_sponsorships
             SET status = 'ended', end_date = ?
             WHERE sponsor_id = ? AND status = 'active'"
        );
        $end->execute([$departureDate, $sponsorId]);

        $pdo->prepare('UPDATE mde_sponsors SET is_active = 0 WHERE id = ?')->execute([$sponsorId]);
        $pdo->commit();

        mde_flash(
            'success',
            $mode === 'new'
                ? 'تم تسجيل الكفيل الجديد ومغادرته في عملية واحدة، مع حفظ بياناته وسجل المغادرة.'
                : 'تم تسجيل مغادرة الكفيل وإنهاء كفالاته النشطة مع الاحتفاظ بالسجلات التاريخية.'
        );
        mde_redirect('departures', ['whatsapp_saved' => '1']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception instanceof PDOException
            ? 'تعذر حفظ المغادرة بسبب خطأ في قاعدة البيانات. لم يتم حفظ أي جزء من العملية.'
            : $exception->getMessage();
    }
}

$selectedMode = (string) ($old['sponsor_mode'] ?? 'existing');
if (!in_array($selectedMode, ['existing', 'new'], true)) {
    $selectedMode = 'existing';
}
?>
<h1 class="h3 mb-3">تسجيل مغادرة كفيل</h1>
<p class="text-muted mb-3">يمكنك تسجيل مغادرة كفيل موجود أو إدخال كفيل جديد لم يسبق تسجيله في هذه الأداة.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= mde_h($error) ?></div><?php endif; ?>

<?php $whatsappImportProfile = 'departure'; require __DIR__ . '/../includes/whatsapp_import.php'; ?>
<form method="post" class="card p-3 p-md-4">
    <input type="hidden" name="_csrf" value="<?= mde_h(mde_csrf_token()) ?>">

    <div class="mb-3">
        <label class="form-label required d-block">بيانات الكفيل</label>
        <div class="d-flex flex-wrap gap-3">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="sponsor_mode" id="modeExisting" value="existing" <?= $selectedMode === 'existing' ? 'checked' : '' ?>>
                <label class="form-check-label" for="modeExisting">كفيل مسجل</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="sponsor_mode" id="modeNew" value="new" <?= $selectedMode === 'new' ? 'checked' : '' ?>>
                <label class="form-check-label" for="modeNew">تسجيل كفيل جديد ومغادرته</label>
            </div>
        </div>
    </div>

    <div id="existingSponsorFields" class="<?= $selectedMode === 'new' ? 'd-none' : '' ?>">
        <div class="row g-3 mb-3">
            <div class="col-md-8">
                <label class="form-label required" for="departureSponsor">الكفيل المسجل</label>
                <select class="form-select" name="sponsor_id" id="departureSponsor" <?= $selectedMode === 'existing' ? 'required' : 'disabled' ?>>
                    <option value="">اختر الكفيل</option>
                    <?php foreach ($sponsors as $s): ?>
                        <option
                            value="<?= (int) $s['id'] ?>"
                            data-file="<?= mde_h($s['file_number'] ?? '') ?>"
                            data-active="<?= (int) $s['is_active'] ?>"
                            <?= (string) ($old['sponsor_id'] ?? '') === (string) $s['id'] ? 'selected' : '' ?>
                            <?= !(int) $s['is_active'] ? 'disabled' : '' ?>
                        >
                            <?= mde_h($s['sponsor_name']) ?><?= !(int) $s['is_active'] ? ' — مغادر سابقاً' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">تظهر السجلات المغادرة سابقاً للتوضيح فقط ولا يمكن تسجيل مغادرة مكررة لها. عند عودة الكفيل، سجّل كفالته الجديدة أولاً.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="existingFileNumber">رقم الملف (لقطة وقت المغادرة)</label>
                <input class="form-control" name="file_number" id="existingFileNumber" maxlength="100" value="<?= mde_h($old['file_number'] ?? '') ?>">
            </div>
        </div>
    </div>

    <div id="newSponsorFields" class="<?= $selectedMode === 'new' ? '' : 'd-none' ?>">
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label required" for="newSponsorName">اسم الكفيل الجديد</label>
                <input class="form-control" name="new_sponsor_name" id="newSponsorName" maxlength="255" value="<?= mde_h($old['new_sponsor_name'] ?? '') ?>" <?= $selectedMode === 'new' ? 'required' : 'disabled' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label required" for="newSponsorType">نوع الكفيل</label>
                <select class="form-select" name="new_sponsor_type" id="newSponsorType" <?= $selectedMode === 'new' ? '' : 'disabled' ?>><option value="individual">فردي</option><option value="tree" <?= ($old['new_sponsor_type'] ?? 'individual') === 'tree' ? 'selected' : '' ?>>🌳 شجرة (داخلي)</option></select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="newFileNumber">رقم الملف</label>
                <input class="form-control" name="file_number" id="newFileNumber" maxlength="100" value="<?= mde_h($old['file_number'] ?? '') ?>" <?= $selectedMode === 'new' ? '' : 'disabled' ?>>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="newSponsorPhone">رقم الهاتف</label>
                <input class="form-control" name="new_sponsor_phone" id="newSponsorPhone" maxlength="50" value="<?= mde_h($old['new_sponsor_phone'] ?? '') ?>" <?= $selectedMode === 'new' ? '' : 'disabled' ?>>
            </div>
            <div class="col-md-8">
                <label class="form-label" for="newSponsorAddress">العنوان</label>
                <input class="form-control" name="new_sponsor_address" id="newSponsorAddress" maxlength="500" value="<?= mde_h($old['new_sponsor_address'] ?? '') ?>" <?= $selectedMode === 'new' ? '' : 'disabled' ?>>
            </div>
            <div class="col-12">
                <label class="form-label" for="newSponsorNotes">ملاحظات عن الكفيل</label>
                <textarea class="form-control" name="new_sponsor_notes" id="newSponsorNotes" rows="2" <?= $selectedMode === 'new' ? '' : 'disabled' ?>><?= mde_h($old['new_sponsor_notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="alert alert-info py-2">سيُنشأ سجل للكفيل بحالة «غير نشط» مع تسجيل المغادرة مباشرة. لن تُنشأ له كفالات أو مبالغ شهرية تلقائياً.</div>
    </div>

    <hr class="my-2">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label required" for="departureDate">تاريخ المغادرة</label>
            <input type="date" class="form-control" name="departure_date" id="departureDate" value="<?= mde_h($old['departure_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="lastPaidMonth">آخر سداد (الشهر)</label>
            <input type="month" class="form-control" name="last_paid_month" id="lastPaidMonth" value="<?= mde_h($old['last_paid_month'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="orphanCount">عدد الأيتام</label>
            <input type="number" min="0" step="1" class="form-control" name="orphan_count" id="orphanCount" value="<?= mde_h($old['orphan_count'] ?? '0') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="sponsorshipAmount">قيمة الكفالة الإجمالية</label>
            <input type="number" min="0" step="1" class="form-control" name="sponsorship_amount" id="sponsorshipAmount" value="<?= mde_h($old['sponsorship_amount'] ?? '0') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="servicesAmount">قيمة الخدمات</label>
            <input type="number" min="0" step="1" class="form-control" name="services_amount" id="servicesAmount" value="<?= mde_h($old['services_amount'] ?? '0') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="enteredBy">بواسطة</label>
            <?php mde_person_dropdown('enteredBy', 'entered_by', $mdeEnteredByOptions, 'اختر الاسم', (string) ($old['entered_by'] ?? '')); ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="supervisorName">المشرف</label>
            <?php mde_person_dropdown('supervisorName', 'supervisor_name', $mdeSupervisorOptions, 'اختر الاسم', (string) ($old['supervisor_name'] ?? '')); ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="departureReason">سبب المغادرة</label>
            <input class="form-control" name="reason" id="departureReason" maxlength="500" value="<?= mde_h($old['reason'] ?? '') ?>">
        </div>
        <div class="col-12">
            <label class="form-label" for="departureNotes">ملاحظات المغادرة</label>
            <textarea class="form-control" name="notes" id="departureNotes" rows="2"><?= mde_h($old['notes'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-danger px-5">تسجيل المغادرة</button>
            <p class="form-text mt-2 mb-0">ستنتهي جميع الكفالات النشطة لهذا الكفيل فقط، مع الاحتفاظ بكل السجلات والمبالغ الشهرية السابقة. تتم العملية كاملة أو لا يُحفظ منها شيء.</p>
        </div>
    </div>
</form>

<script>
(() => {
    const existingMode = document.getElementById('modeExisting');
    const newMode = document.getElementById('modeNew');
    const existingFields = document.getElementById('existingSponsorFields');
    const newFields = document.getElementById('newSponsorFields');
    const sponsorSelect = document.getElementById('departureSponsor');
    const existingFile = document.getElementById('existingFileNumber');
    const newName = document.getElementById('newSponsorName');

    const syncMode = () => {
        const isNew = newMode.checked;
        existingFields.classList.toggle('d-none', isNew);
        newFields.classList.toggle('d-none', !isNew);
        sponsorSelect.disabled = isNew;
        sponsorSelect.required = !isNew;
        newName.disabled = !isNew;
        newName.required = isNew;
        document.getElementById('newSponsorType').disabled = !isNew;
        newFields.querySelectorAll('input:not(#newSponsorName), textarea').forEach((field) => {
            field.disabled = !isNew;
        });
        existingFile.disabled = isNew;
    };

    existingMode.addEventListener('change', syncMode);
    newMode.addEventListener('change', syncMode);
    sponsorSelect.addEventListener('change', () => {
        const option = sponsorSelect.selectedOptions[0];
        if (option && option.value) {
            existingFile.value = option.dataset.file || '';
        }
    });
    syncMode();
})();
</script>
