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

        $countRaw = trim((string) ($_POST['orphan_count'] ?? ''));
        $count = null;
        if ($countRaw !== '') {
            $count = filter_var($countRaw, FILTER_VALIDATE_INT);
            if ($count === false || $count < 0) {
                throw new InvalidArgumentException('أدخل عدد أيتام صحيحاً غير سالب.');
            }
        }

        $amount = filter_var($_POST['sponsorship_amount'] ?? null, FILTER_VALIDATE_INT);
        if ($amount === false || $amount === null || $amount < 0) {
            throw new InvalidArgumentException('أدخل مبلغ كفالة صحيحاً غير سالب.');
        }

        $servicesRaw = trim((string) ($_POST['services_amount'] ?? ''));
        $services = null;
        if ($servicesRaw !== '') {
            $services = filter_var($servicesRaw, FILTER_VALIDATE_INT);
            if ($services === false || $services < 0) {
                throw new InvalidArgumentException('أدخل مبلغ خدمات صحيحاً غير سالب أو اتركه فارغاً إذا لم يرد في المصدر.');
            }
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

            $check = $pdo->prepare('SELECT id, sponsor_name, file_number, sponsor_type, is_active FROM mde_sponsors WHERE id = ? FOR UPDATE');
            $check->execute([$sponsorId]);
            $sponsor = $check->fetch();
            if (!$sponsor) {
                throw new InvalidArgumentException('الكفيل المحدد غير موجود.');
            }
            $sponsorType = (string) ($sponsor['sponsor_type'] ?? 'individual');

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
            $sponsorType = $newSponsorType;
        }

        if ($count === null && $sponsorType !== 'tree') {
            throw new InvalidArgumentException('عدد الأيتام مطلوب للكفيل الفردي. يمكن تركه فارغاً لكفيل الشجرة إذا لم يرد في الرسالة.');
        }
        if ($services === null && $sponsorType !== 'tree') {
            throw new InvalidArgumentException('مبلغ الخدمات مطلوب للكفيل الفردي. يمكن تركه فارغاً لكفيل الشجرة إذا لم يرد في الرسالة.');
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
            null,
            trim((string) ($_POST['notes'] ?? '')) ?: null,
        ]);

        $end = $pdo->prepare(
            "UPDATE mde_sponsorships
             SET status = 'ended', end_date = ?
             WHERE sponsor_id = ? AND status = 'active' AND start_date <= ?"
        );
        $end->execute([$departureDate, $sponsorId, $departureDate]);

        $syncSponsorStatus = $pdo->prepare(
            "UPDATE mde_sponsors AS sponsor
             SET is_active = CASE WHEN EXISTS (
                SELECT 1 FROM mde_sponsorships AS rel
                WHERE rel.sponsor_id = sponsor.id AND rel.status = 'active'
             ) THEN 1 ELSE 0 END
             WHERE sponsor.id = ?"
        );
        $syncSponsorStatus->execute([$sponsorId]);
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
<form method="post" class="card p-2 p-md-3" id="departureForm">
    <input type="hidden" name="_csrf" value="<?= mde_h(mde_csrf_token()) ?>">

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <span class="form-label required mb-0">بيانات الكفيل</span>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="sponsor_mode" id="modeExisting" value="existing" <?= $selectedMode === 'existing' ? 'checked' : '' ?>>
            <label class="form-check-label" for="modeExisting">كفيل مسجل</label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="sponsor_mode" id="modeNew" value="new" <?= $selectedMode === 'new' ? 'checked' : '' ?>>
            <label class="form-check-label" for="modeNew">تسجيل كفيل جديد ومغادرته</label>
        </div>
    </div>

    <div class="entry-register-wrap">
        <table class="entry-register departure-register" id="departureRegister">
            <colgroup>
                <col style="width:3%">
                <col style="width:20%">
                <col style="width:8%">
                <col style="width:10%">
                <col style="width:8%">
                <col style="width:6%">
                <col style="width:9%">
                <col style="width:8%">
                <col style="width:28%">
            </colgroup>
            <thead>
                <tr>
                    <th class="h-sponsor">#</th>
                    <th class="h-sponsor">الكفيل</th>
                    <th class="h-sponsor">رقم الملف</th>
                    <th class="h-sponsor">تاريخ المغادرة</th>
                    <th class="h-sponsor">آخر سداد</th>
                    <th class="h-orphan">عدد الأيتام</th>
                    <th class="h-orphan">الكفالة</th>
                    <th class="h-orphan">الخدمات</th>
                    <th class="h-sponsor">بواسطة / المشرف</th>
                    <th class="h-sponsor">ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="seq-cell fw-bold text-center">1</td>
                    <td class="sponsor-span-cell">
                        <div id="existingSponsorFields" class="<?= $selectedMode === 'new' ? 'd-none' : '' ?>">
                            <label class="form-label required small" for="departureSponsor">الكفيل المسجل</label>
                            <select class="form-select form-select-sm" name="sponsor_id" id="departureSponsor" <?= $selectedMode === 'existing' ? 'required' : 'disabled' ?>>
                                <option value="">اختر الكفيل</option>
                                <?php foreach ($sponsors as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>"
                                        data-file="<?= mde_h($s['file_number'] ?? '') ?>"
                                        data-active="<?= (int) $s['is_active'] ?>"
                                        <?= (string) ($old['sponsor_id'] ?? '') === (string) $s['id'] ? 'selected' : '' ?>
                                        >
                                        <?= mde_h($s['sponsor_name']) ?><?= !(int) $s['is_active'] ? ' — مغادر سابقاً' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">يمكن اختيار الكفيل حتى لو كان مسجلاً كمغادر في شهر سابق؛ كل مغادرة تحفظ كسجل شهري مستقل.</div>
                        </div>
                        <div id="newSponsorFields" class="<?= $selectedMode === 'new' ? '' : 'd-none' ?>">
                            <label class="form-label required small" for="newSponsorName">اسم الكفيل الجديد</label>
                            <input class="form-control form-control-sm mb-1" name="new_sponsor_name" id="newSponsorName" maxlength="255" value="<?= mde_h($old['new_sponsor_name'] ?? '') ?>" <?= $selectedMode === 'new' ? 'required' : 'disabled' ?>>
                            <label class="form-label small" for="newSponsorType">نوع الكفيل</label>
                            <select class="form-select form-select-sm mb-1" name="new_sponsor_type" id="newSponsorType" <?= $selectedMode === 'new' ? '' : 'disabled' ?>>
                                <option value="individual">فردي</option>
                                <option value="tree" <?= ($old['new_sponsor_type'] ?? 'individual') === 'tree' ? 'selected' : '' ?>>🌳 شجرة (داخلي)</option>
                            </select>
                            <input class="form-control form-control-sm mb-1" name="new_sponsor_phone" id="newSponsorPhone" maxlength="50" placeholder="رقم الهاتف" value="<?= mde_h($old['new_sponsor_phone'] ?? '') ?>" <?= $selectedMode === 'new' ? '' : 'disabled' ?>>
                            <input class="form-control form-control-sm mb-1" name="new_sponsor_address" id="newSponsorAddress" maxlength="500" placeholder="العنوان" value="<?= mde_h($old['new_sponsor_address'] ?? '') ?>" <?= $selectedMode === 'new' ? '' : 'disabled' ?>>
                            <textarea class="form-control form-control-sm" name="new_sponsor_notes" id="newSponsorNotes" rows="2" placeholder="ملاحظات عن الكفيل" <?= $selectedMode === 'new' ? '' : 'disabled' ?>><?= mde_h($old['new_sponsor_notes'] ?? '') ?></textarea>
                        </div>
                    </td>
                    <td class="sponsor-span-cell">
                        <input class="form-control form-control-sm mb-1" name="file_number" id="existingFileNumber" maxlength="100" placeholder="الملف" value="<?= mde_h($old['file_number'] ?? '') ?>" <?= $selectedMode === 'new' ? 'disabled' : '' ?>>
                        <input class="form-control form-control-sm" name="file_number" id="newFileNumber" maxlength="100" placeholder="الملف" value="<?= mde_h($old['file_number'] ?? '') ?>" <?= $selectedMode === 'new' ? '' : 'disabled' ?>>
                    </td>
                    <td>
                        <input type="date" class="form-control form-control-sm date-input" name="departure_date" id="departureDate" value="<?= mde_h($old['departure_date'] ?? '') ?>" required>
                    </td>
                    <td>
                        <input type="month" class="form-control form-control-sm" name="last_paid_month" id="lastPaidMonth" value="<?= mde_h($old['last_paid_month'] ?? '') ?>">
                    </td>
                    <td>
                        <input type="number" min="0" step="1" class="form-control form-control-sm text-center" name="orphan_count" id="orphanCount" value="<?= mde_h($old['orphan_count'] ?? '') ?>">
                    </td>
                    <td>
                        <input type="number" min="0" step="1" class="form-control form-control-sm text-center" name="sponsorship_amount" id="sponsorshipAmount" value="<?= mde_h($old['sponsorship_amount'] ?? '0') ?>">
                    </td>
                    <td>
                        <input type="number" min="0" step="1" class="form-control form-control-sm text-center" name="services_amount" id="servicesAmount" value="<?= mde_h($old['services_amount'] ?? '') ?>">
                    </td>
                    <td class="sponsor-span-cell">
                        <div class="small mb-1">بواسطة</div>
                        <?php mde_person_dropdown('enteredBy', 'entered_by', $mdeEnteredByOptions, 'اختر الاسم', (string) ($old['entered_by'] ?? '')); ?>
                        <div class="small mt-2 mb-1">المشرف</div>
                        <?php mde_person_dropdown('supervisorName', 'supervisor_name', $mdeSupervisorOptions, 'اختر الاسم', (string) ($old['supervisor_name'] ?? '')); ?>
                    </td>
                    <td class="sponsor-span-cell">
                        <label class="form-label small" for="departureNotes">ملاحظات كما وردت في المصدر</label>
                        <textarea class="form-control form-control-sm notes-input" name="notes" id="departureNotes" rows="4"><?= mde_h($old['notes'] ?? '') ?></textarea>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
        <p class="form-text mb-0">لن تُحفظ البيانات إلا بعد استكمال تاريخ المغادرة والتحقق من القيم. تتم عملية إنهاء الكفالات النشطة للكفيل كاملة أو لا يُحفظ منها شيء.</p>
        <button type="submit" class="btn btn-danger px-5">تسجيل المغادرة</button>
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
    const newFile = document.getElementById('newFileNumber');
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
        newFile.disabled = !isNew;
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
