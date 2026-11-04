<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reference_options.php';

$error = '';
$q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));

function mde_manage_log(PDO $pdo, string $type, int $id, array $before, array $after): void
{
    if ($before === $after) {
        return;
    }
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

function mde_manage_delete_log(PDO $pdo, string $type, int $id, array $before): void
{
    $stmt = $pdo->prepare('INSERT INTO mde_change_log (entity_type, entity_id, action, before_data, after_data) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$type, $id, 'delete', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'null']);
}

function mde_manage_sponsor_delete_counts(PDO $pdo, int $sponsorId): array
{
    $counts = [];
    $queries = [
        'relationships' => 'SELECT COUNT(*) FROM mde_sponsorships WHERE sponsor_id = ?',
        'monthly_entries' => 'SELECT COUNT(*) FROM mde_monthly_sponsorship_entries me JOIN mde_sponsorships ss ON ss.id = me.sponsorship_id WHERE ss.sponsor_id = ?',
        'donations' => 'SELECT COUNT(*) FROM mde_monthly_other_donations d WHERE d.sponsor_id = ? OR d.sponsorship_id IN (SELECT ss.id FROM mde_sponsorships ss WHERE ss.sponsor_id = ?)',
        'departures' => 'SELECT COUNT(*) FROM mde_sponsor_departures WHERE sponsor_id = ?',
    ];
    foreach ($queries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($key === 'donations' ? [$sponsorId, $sponsorId] : [$sponsorId]);
        $counts[$key] = (int) $stmt->fetchColumn();
    }
    return $counts;
}

function mde_manage_message_key(array $row): string
{
    return hash('sha256', implode("\0", [
        (string) ($row['sponsor_id'] ?? ''),
        (string) ($row['report_month'] ?? ''),
        (string) ($row['monthly_created_at'] ?? ''),
        (string) ($row['entered_by'] ?? ''),
        (string) ($row['supervisor_name'] ?? ''),
    ]));
}

function mde_manage_date(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('تحقق من ' . $label . '.');
    }
    return $value;
}

function mde_manage_rows(PDO $pdo, string $query): array
{
    $sql = 'SELECT
              s.id AS sponsor_id, s.sponsor_name, s.file_number, s.sponsor_type,
              ss.id AS sponsorship_id, ss.status AS sponsorship_status, ss.start_date, ss.end_date,
              mo.id AS mother_id, mo.mother_name,
              o.id AS orphan_id, o.orphan_name,
              me.id AS monthly_id, me.report_month, me.entry_date,
              me.sponsorship_amount, me.services_amount, me.entered_by,
              me.supervisor_name, me.created_at AS monthly_created_at,
              me.notes AS monthly_notes,
              COALESCE(donations.donation_text, \'\') AS donation_text
            FROM mde_sponsors s
            LEFT JOIN mde_sponsorships ss ON ss.sponsor_id = s.id
            LEFT JOIN mde_orphans o ON o.id = ss.orphan_id
            LEFT JOIN mde_mothers mo ON mo.id = o.mother_id
            LEFT JOIN mde_monthly_sponsorship_entries me ON me.sponsorship_id = ss.id
            LEFT JOIN (
              SELECT sponsorship_id, report_month,
                     GROUP_CONCAT(CONCAT(donation_type, \' — \', FORMAT(amount, 0)) ORDER BY id SEPARATOR \' | \') AS donation_text
              FROM mde_monthly_other_donations
              WHERE sponsorship_id IS NOT NULL
              GROUP BY sponsorship_id, report_month
            ) donations ON donations.sponsorship_id = ss.id AND donations.report_month = me.report_month';
    $params = [];
    if ($query !== '') {
        $like = '%' . $query . '%';
        $sql .= ' WHERE (
            s.sponsor_name LIKE ? OR s.file_number LIKE ? OR
            mo.mother_name LIKE ? OR o.orphan_name LIKE ? OR
            CAST(me.report_month AS CHAR) LIKE ? OR CAST(me.entry_date AS CHAR) LIKE ? OR
            CAST(me.sponsorship_amount AS CHAR) LIKE ? OR CAST(me.services_amount AS CHAR) LIKE ? OR
            me.entered_by LIKE ? OR me.supervisor_name LIKE ? OR me.notes LIKE ? OR
            donations.donation_text LIKE ? OR CAST(s.id AS CHAR) LIKE ? OR
            CAST(ss.id AS CHAR) LIKE ? OR CAST(me.id AS CHAR) LIKE ?
        )';
        $params = array_fill(0, 15, $like);
    }
    $sql .= ' ORDER BY s.sponsor_name, me.created_at DESC, me.report_month DESC,
                       me.entered_by, me.supervisor_name, me.id DESC, mo.mother_name, o.orphan_name LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function mde_manage_render(PDO $pdo, string $query, string $csrf): string
{
    $rows = mde_manage_rows($pdo, $query);
    $groups = [];
    foreach ($rows as $row) {
        $sid = (int) $row['sponsor_id'];
        $groups[$sid]['sponsor'] = $row;
        $groups[$sid]['rows'][] = $row;
    }

    ob_start();
    if ($query !== '' && !$rows) {
        echo '<div class="alert alert-warning mb-0">لم يتم العثور على سجلات مطابقة.</div>';
    } elseif ($query === '') {
        echo '<div class="alert alert-light border mb-0">ابدأ بكتابة اسم أو رقم ملف أو شهر أو مبلغ أو اسم الموظف لعرض السجلات المطابقة.</div>';
    } else {
        echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">';
        echo '<span class="small text-secondary">عدد الصفوف المعروضة: ' . count($rows) . ' — الحد الأقصى 500 صف</span>';
        echo '<span class="small text-secondary">كل صف يمثل إدخالاً شهرياً لعلاقة كفالة محددة</span></div>';
        foreach ($groups as $sid => $group) {
            $sponsor = $group['sponsor'];
            $items = $group['rows'];
            $sponsorForm = 'sponsor-form-' . $sid;
            $rowCount = count($items);
            $messageCounts = [];
            foreach ($items as $messageItem) {
                if (!empty($messageItem['monthly_id'])) {
                    $messageKey = mde_manage_message_key($messageItem);
                    $messageCounts[$messageKey] = ($messageCounts[$messageKey] ?? 0) + 1;
                }
            }
            $seenMessages = [];
            $seenSponsorships = [];
            echo '<section class="manage-group mb-4" data-sponsor-group="' . $sid . '">';
            echo '<form id="' . $sponsorForm . '" method="post" class="manage-save-form">';
            echo '<input type="hidden" name="_csrf" value="' . mde_h($csrf) . '">';
            echo '<input type="hidden" name="action" value="group_update">';
            echo '<input type="hidden" name="sponsor_id" value="' . $sid . '">';
            echo '<input type="hidden" name="q" value="' . mde_h($query) . '">';
            echo '</form>';
            echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">';
            echo '<div class="fw-bold">' . mde_h($sponsor['sponsor_name']) . ($sponsor['sponsor_type'] === 'tree' ? ' <span class="badge text-bg-success">🌳 شجرة</span>' : '') . ' <span class="small text-secondary">#' . $sid . '</span></div>';
            echo '<div class="d-flex gap-2 align-items-center"><span class="small text-secondary">' . $rowCount . ' صف</span>';
            $deleteCounts = mde_manage_sponsor_delete_counts($pdo, (int) $sid);
            $deleteConfirm = 'سيتم حذف الكفيل «' . $sponsor['sponsor_name'] . '» وكل سجلاته التابعة نهائياً:' . "\\n" .
                'علاقات الكفالة: ' . $deleteCounts['relationships'] . "\\n" .
                'الإدخالات الشهرية: ' . $deleteCounts['monthly_entries'] . "\\n" .
                'التبرعات الأخرى: ' . $deleteCounts['donations'] . "\\n" .
                'سجلات المغادرة: ' . $deleteCounts['departures'] . "\\n\\n" .
                'ستبقى سجلات الأمهات والأيتام وهوياتهم وعلاقاتهم بكفلاء آخرين دون حذف. هل أنت متأكد؟';
            echo '<form method="post" class="d-inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . mde_h($deleteConfirm) . '">';
            echo '<input type="hidden" name="_csrf" value="' . mde_h($csrf) . '"><input type="hidden" name="action" value="delete_sponsor"><input type="hidden" name="sponsor_id" value="' . $sid . '"><input type="hidden" name="q" value="' . mde_h($query) . '">';
            echo '<button type="submit" class="btn btn-outline-danger btn-sm">حذف الكفيل وسجلاته</button></form>';
            echo '<button type="button" class="btn btn-outline-primary btn-sm manage-edit-toggle">تعديل هذا الكفيل</button>';
            echo '<button type="submit" form="' . $sponsorForm . '" class="btn btn-success btn-sm manage-save d-none">حفظ التعديلات</button>';
            echo '<button type="button" class="btn btn-outline-secondary btn-sm manage-cancel d-none">إلغاء</button></div></div>';
            echo '<div class="entry-register-wrap manage-table-wrap"><table class="entry-register manage-register">';
            echo '<colgroup><col style="width:2.5%"><col style="width:12.5%"><col style="width:7.5%"><col style="width:8.5%"><col style="width:10.5%"><col style="width:14%"><col style="width:6.5%"><col style="width:6.5%"><col style="width:14%"><col style="width:9%"><col style="width:8.5%"></colgroup>';
            echo '<thead><tr><th class="h-sponsor">#</th><th class="h-sponsor">الكفيل</th><th class="h-sponsor">الملف / النوع</th><th class="h-sponsor">تاريخ الانضمام</th><th class="h-mother">الأم</th><th class="h-orphan">اليتيم</th><th class="h-orphan">الكفالة</th><th class="h-orphan">الخدمات</th><th class="h-orphan">تبرعات أخرى (لليتيم)</th><th class="h-sponsor">بواسطة / المشرف</th><th class="h-sponsor">ملاحظات</th></tr></thead><tbody>';
            $motherCounts = [];
            foreach ($items as $item) {
                $mid = (int) ($item['mother_id'] ?? 0);
                $motherCounts[$mid] = ($motherCounts[$mid] ?? 0) + 1;
            }
            $seenMothers = [];
            $first = true;
            foreach ($items as $index => $item) {
                $mid = (int) ($item['mother_id'] ?? 0);
                $motherForm = 'mother-form-' . $sid . '-' . $mid;
                $orphanForm = 'orphan-form-' . $sid . '-' . (int) ($item['orphan_id'] ?? 0) . '-' . $index;
                $monthlyForm = 'monthly-form-' . $sid . '-' . (int) ($item['monthly_id'] ?? 0) . '-' . $index;
                $messageKey = !empty($item['monthly_id']) ? mde_manage_message_key($item) : '';
                echo '<tr class="orphan-row">';
                if ($first) {
                    echo '<td class="sponsor-span-cell seq-cell" rowspan="' . $rowCount . '"><span class="fw-bold">#' . $sid . '</span></td>';
                    echo '<td class="sponsor-span-cell" rowspan="' . $rowCount . '">';
                    echo '<input class="form-control form-control-sm manage-view" value="' . mde_h($sponsor['sponsor_name']) . '" readonly>';
                    echo '<input class="form-control form-control-sm manage-input d-none" name="sponsor_name" form="' . $sponsorForm . '" maxlength="255" required value="' . mde_h($sponsor['sponsor_name']) . '">';
                    echo '</td>';
                    echo '<td class="sponsor-span-cell" rowspan="' . $rowCount . '">';
                    echo '<div class="manage-view small">' . mde_h($sponsor['file_number'] ?: '—') . '<br>' . ($sponsor['sponsor_type'] === 'tree' ? '🌳 شجرة' : 'فردي') . '</div>';
                    echo '<input class="form-control form-control-sm manage-input d-none mb-1" name="file_number" form="' . $sponsorForm . '" maxlength="100" value="' . mde_h($sponsor['file_number']) . '" placeholder="رقم الملف">';
                    echo '<select class="form-select form-select-sm manage-input d-none" name="sponsor_type" form="' . $sponsorForm . '"><option value="individual"' . ($sponsor['sponsor_type'] === 'individual' ? ' selected' : '') . '>فردي</option><option value="tree"' . ($sponsor['sponsor_type'] === 'tree' ? ' selected' : '') . '>🌳 شجرة</option></select>';
                    echo '</td>';
                    $first = false;
                }
                echo '<td class="sponsor-span-cell"><div class="small fw-semibold">' . mde_h($item['start_date'] ?? '—') . '</div>';
                echo '<div class="small text-secondary">' . ($item['report_month'] ? 'شهر ' . mde_h(substr((string) $item['report_month'], 0, 7)) : 'لا يوجد إدخال شهري') . '</div>';
                if ($item['monthly_id']) {
                    echo '<div class="manage-input d-none mt-1"><input type="date" class="form-control form-control-sm" name="monthly[' . (int) $item['monthly_id'] . '][entry_date]" form="' . $sponsorForm . '" value="' . mde_h($item['entry_date']) . '" required></div>';
                    echo '<form method="post" class="mt-1" onsubmit="return confirm(this.dataset.confirm)" data-confirm="سيُحذف هذا الإدخال الشهري والتبرعات الأخرى المرتبطة باليتيم في الشهر نفسه فقط. ستبقى علاقة الكفالة وبقية الأشهر. هل تريد المتابعة؟">';
                    echo '<input type="hidden" name="_csrf" value="' . mde_h($csrf) . '"><input type="hidden" name="action" value="delete_monthly"><input type="hidden" name="sponsor_id" value="' . $sid . '"><input type="hidden" name="monthly_id" value="' . (int) $item['monthly_id'] . '"><input type="hidden" name="q" value="' . mde_h($query) . '">';
                    echo '<button type="submit" class="btn btn-link btn-sm text-danger p-0">حذف الشهر</button></form>';
                }
                echo '</td>';
                if (!isset($seenMothers[$mid])) {
                    $seenMothers[$mid] = true;
                    echo '<td class="mother-span-cell" rowspan="' . $motherCounts[$mid] . '">';
                    echo '<div class="manage-view">' . mde_h($item['mother_name'] ?? '—') . '</div>';
                    if ($mid > 0) {
                        echo '<input class="form-control form-control-sm manage-input d-none" name="mothers[' . $mid . ']" form="' . $sponsorForm . '" maxlength="255" required value="' . mde_h($item['mother_name']) . '">';
                    }
                    echo '</td>';
                }
                echo '<td class="orphan-cell"><div class="manage-view">' . mde_h($item['orphan_name'] ?? '—') . '</div>';
                if (!empty($item['orphan_id'])) {
                    echo '<input class="form-control form-control-sm manage-input d-none" name="orphans[' . (int) $item['orphan_id'] . ']" form="' . $sponsorForm . '" maxlength="255" required value="' . mde_h($item['orphan_name']) . '">';
                }
                echo '<div class="small text-secondary">علاقة #' . (int) $item['sponsorship_id'] . ' · ' . mde_h($item['sponsorship_status'] ?? '') . '</div>';
                $relationshipId = (int) ($item['sponsorship_id'] ?? 0);
                if ($relationshipId > 0 && !isset($seenSponsorships[$relationshipId])) {
                    $seenSponsorships[$relationshipId] = true;
                    echo '<form method="post" class="mt-1" onsubmit="return confirm(this.dataset.confirm)" data-confirm="سيُحذف سجل علاقة الكفيل باليتيم وكل الإدخالات الشهرية والتبرعات المرتبطة بهذه العلاقة فقط. لن تُحذف هوية اليتيم أو علاقاته بكفلاء آخرين. هل تريد المتابعة؟">';
                    echo '<input type="hidden" name="_csrf" value="' . mde_h($csrf) . '"><input type="hidden" name="action" value="delete_sponsorship"><input type="hidden" name="sponsor_id" value="' . $sid . '"><input type="hidden" name="sponsorship_id" value="' . $relationshipId . '"><input type="hidden" name="q" value="' . mde_h($query) . '">';
                    echo '<button type="submit" class="btn btn-link btn-sm text-danger p-0">حذف العلاقة وسجلها</button></form>';
                }
                echo '</td>';
                foreach (['sponsorship_amount' => 'sponsorship', 'services_amount' => 'services'] as $field => $key) {
                    echo '<td class="text-center">';
                    echo '<div class="manage-view">' . ($item[$field] === null ? '—' : number_format((int) $item[$field])) . '</div>';
                    if ($item['monthly_id']) {
                        echo '<input type="number" min="0" step="1" class="form-control form-control-sm text-center manage-input d-none" name="monthly[' . (int) $item['monthly_id'] . '][' . $field . ']" form="' . $sponsorForm . '" value="' . (int) $item[$field] . '" required>';
                    }
                    echo '</td>';
                }
                echo '<td><div class="manage-view small">' . mde_h($item['donation_text'] ?: '—') . '</div><div class="small text-secondary">عرض فقط</div></td>';
                if ($item['monthly_id'] && !isset($seenMessages[$messageKey])) {
                    $seenMessages[$messageKey] = true;
                    $messageSpan = $messageCounts[$messageKey] ?? 1;
                    echo '<td rowspan="' . $messageSpan . '"><div class="manage-view small">' . mde_h($item['entered_by'] ?: '—') . ' / ' . mde_h($item['supervisor_name'] ?: '—') . '</div>';
                    echo '<div class="manage-input d-none">';
                    echo '<select class="form-select form-select-sm mb-1" name="messages[' . $messageKey . '][entered_by]" form="' . $sponsorForm . '"><option value="">— بواسطة —</option>';
                    foreach ($GLOBALS['mdeEnteredByOptions'] as $person) echo '<option value="' . mde_h($person) . '"' . ($item['entered_by'] === $person ? ' selected' : '') . '>' . mde_h($person) . '</option>';
                    echo '</select><select class="form-select form-select-sm" name="messages[' . $messageKey . '][supervisor_name]" form="' . $sponsorForm . '"><option value="">— المشرف —</option>';
                    foreach ($GLOBALS['mdeSupervisorOptions'] as $person) echo '<option value="' . mde_h($person) . '"' . ($item['supervisor_name'] === $person ? ' selected' : '') . '>' . mde_h($person) . '</option>';
                    echo '</div>';
                    foreach ($items as $messageMember) {
                        if (!empty($messageMember['monthly_id']) && mde_manage_message_key($messageMember) === $messageKey) {
                            echo '<input type="hidden" name="messages[' . $messageKey . '][monthly_ids][]" form="' . $sponsorForm . '" value="' . (int) $messageMember['monthly_id'] . '">';
                        }
                    }
                    echo '</td>';
                } elseif (!$item['monthly_id']) {
                    echo '<td>—</td>';
                }
                echo '<td><div class="manage-view small">' . nl2br(mde_h($item['monthly_notes'] ?? '')) . '</div>';
                if ($item['monthly_id']) {
                    echo '<textarea class="form-control form-control-sm manage-input d-none" rows="2" name="monthly[' . (int) $item['monthly_id'] . '][notes]" form="' . $sponsorForm . '">' . mde_h($item['monthly_notes']) . '</textarea>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '<div class="small text-secondary mt-2">تاريخ الانضمام وحالة العلاقة معروضان كسجل تاريخي. التعديل يشمل بيانات الكفيل والأم واليتيم والإدخال الشهري. التبرعات الأخرى معروضة هنا دون تعديل.</div>';
            echo '</section>';
        }
    }
    return (string) ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        mde_verify_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if (in_array($action, ['delete_monthly', 'delete_sponsorship', 'delete_sponsor'], true)) {
            $sponsorIdForDelete = filter_var($_POST['sponsor_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$sponsorIdForDelete || $sponsorIdForDelete < 1) throw new InvalidArgumentException('معرّف الكفيل غير صحيح.');
            $pdo->beginTransaction();
            if ($action === 'delete_monthly') {
                $monthlyId = filter_var($_POST['monthly_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$monthlyId || $monthlyId < 1) throw new InvalidArgumentException('معرّف الإدخال الشهري غير صحيح.');
                $lock = $pdo->prepare('SELECT me.* FROM mde_monthly_sponsorship_entries me JOIN mde_sponsorships ss ON ss.id = me.sponsorship_id WHERE me.id = ? AND ss.sponsor_id = ? FOR UPDATE');
                $lock->execute([$monthlyId, $sponsorIdForDelete]);
                $beforeMonthly = $lock->fetch();
                if (!$beforeMonthly) throw new InvalidArgumentException('الإدخال الشهري لا يتبع هذا الكفيل.');
                $donationLock = $pdo->prepare('SELECT * FROM mde_monthly_other_donations WHERE sponsorship_id = ? AND report_month = ? FOR UPDATE');
                $donationLock->execute([$beforeMonthly['sponsorship_id'], $beforeMonthly['report_month']]);
                foreach ($donationLock->fetchAll() as $donation) {
                    mde_manage_delete_log($pdo, 'donation', (int) $donation['id'], $donation);
                    $pdo->prepare('DELETE FROM mde_monthly_other_donations WHERE id = ?')->execute([(int) $donation['id']]);
                }
                $pdo->prepare('DELETE FROM mde_monthly_sponsorship_entries WHERE id = ?')->execute([$monthlyId]);
                mde_manage_delete_log($pdo, 'monthly_entry', (int) $monthlyId, $beforeMonthly);
            } elseif ($action === 'delete_sponsorship') {
                $relationshipId = filter_var($_POST['sponsorship_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$relationshipId || $relationshipId < 1) throw new InvalidArgumentException('معرّف علاقة الكفالة غير صحيح.');
                $lock = $pdo->prepare('SELECT ss.* FROM mde_sponsorships ss WHERE ss.id = ? AND ss.sponsor_id = ? FOR UPDATE');
                $lock->execute([$relationshipId, $sponsorIdForDelete]);
                $beforeRelationship = $lock->fetch();
                if (!$beforeRelationship) throw new InvalidArgumentException('علاقة الكفالة لا تتبع هذا الكفيل.');
                $entries = $pdo->prepare('SELECT * FROM mde_monthly_sponsorship_entries WHERE sponsorship_id = ? FOR UPDATE');
                $entries->execute([$relationshipId]);
                $entryRows = $entries->fetchAll();
                $donations = $pdo->prepare('SELECT * FROM mde_monthly_other_donations WHERE sponsorship_id = ? FOR UPDATE');
                $donations->execute([$relationshipId]);
                foreach ($donations->fetchAll() as $donation) {
                    mde_manage_delete_log($pdo, 'donation', (int) $donation['id'], $donation);
                    $pdo->prepare('DELETE FROM mde_monthly_other_donations WHERE id = ?')->execute([(int) $donation['id']]);
                }
                foreach ($entryRows as $entry) mde_manage_delete_log($pdo, 'monthly_entry', (int) $entry['id'], $entry);
                $pdo->prepare('DELETE FROM mde_monthly_sponsorship_entries WHERE sponsorship_id = ?')->execute([$relationshipId]);
                $pdo->prepare('DELETE FROM mde_sponsorships WHERE id = ?')->execute([$relationshipId]);
                mde_manage_delete_log($pdo, 'sponsorship', (int) $relationshipId, $beforeRelationship);
            } else {
                $lock = $pdo->prepare('SELECT * FROM mde_sponsors WHERE id = ? FOR UPDATE');
                $lock->execute([$sponsorIdForDelete]);
                $beforeSponsorDelete = $lock->fetch();
                if (!$beforeSponsorDelete) throw new InvalidArgumentException('الكفيل غير موجود.');

                // Lock and snapshot every dependent row before deleting anything.
                $relationshipStmt = $pdo->prepare('SELECT * FROM mde_sponsorships WHERE sponsor_id = ? FOR UPDATE');
                $relationshipStmt->execute([$sponsorIdForDelete]);
                $relationshipRows = $relationshipStmt->fetchAll();

                $entryStmt = $pdo->prepare('SELECT me.* FROM mde_monthly_sponsorship_entries me JOIN mde_sponsorships ss ON ss.id = me.sponsorship_id WHERE ss.sponsor_id = ? FOR UPDATE');
                $entryStmt->execute([$sponsorIdForDelete]);
                $entryRows = $entryStmt->fetchAll();

                $donationStmt = $pdo->prepare('SELECT d.* FROM mde_monthly_other_donations d WHERE d.sponsor_id = ? OR d.sponsorship_id IN (SELECT ss.id FROM mde_sponsorships ss WHERE ss.sponsor_id = ?) FOR UPDATE');
                $donationStmt->execute([$sponsorIdForDelete, $sponsorIdForDelete]);
                $donationRows = $donationStmt->fetchAll();

                $departureStmt = $pdo->prepare('SELECT * FROM mde_sponsor_departures WHERE sponsor_id = ? FOR UPDATE');
                $departureStmt->execute([$sponsorIdForDelete]);
                $departureRows = $departureStmt->fetchAll();

                foreach ($entryRows as $entry) mde_manage_delete_log($pdo, 'monthly_entry', (int) $entry['id'], $entry);
                foreach ($donationRows as $donation) mde_manage_delete_log($pdo, 'donation', (int) $donation['id'], $donation);
                foreach ($departureRows as $departure) mde_manage_delete_log($pdo, 'departure', (int) $departure['id'], $departure);
                foreach ($relationshipRows as $relationship) mde_manage_delete_log($pdo, 'sponsorship', (int) $relationship['id'], $relationship);
                mde_manage_delete_log($pdo, 'sponsor', (int) $sponsorIdForDelete, $beforeSponsorDelete);

                $pdo->prepare('DELETE FROM mde_monthly_sponsorship_entries WHERE sponsorship_id IN (SELECT id FROM mde_sponsorships WHERE sponsor_id = ?)')->execute([$sponsorIdForDelete]);
                $pdo->prepare('DELETE FROM mde_monthly_other_donations WHERE sponsor_id = ? OR sponsorship_id IN (SELECT id FROM mde_sponsorships WHERE sponsor_id = ?)')->execute([$sponsorIdForDelete, $sponsorIdForDelete]);
                $pdo->prepare('DELETE FROM mde_sponsor_departures WHERE sponsor_id = ?')->execute([$sponsorIdForDelete]);
                $pdo->prepare('DELETE FROM mde_sponsorships WHERE sponsor_id = ?')->execute([$sponsorIdForDelete]);
                $pdo->prepare('DELETE FROM mde_sponsors WHERE id = ?')->execute([$sponsorIdForDelete]);
            }
            $pdo->commit();
            mde_flash('success', 'تم الحذف المحدد مع حفظ لقطات السجلات المحذوفة في سجل المراجعة.');
            mde_redirect('manage', $q !== '' ? ['q' => $q] : []);
        }

        if ($action !== 'group_update') {
            throw new InvalidArgumentException('طلب التعديل غير معروف. أعد تحميل الصفحة وحاول مرة أخرى.');
        }
        $sponsorId = filter_var($_POST['sponsor_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$sponsorId || $sponsorId < 1) {
            throw new InvalidArgumentException('معرّف الكفيل غير صحيح.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, sponsor_name, file_number, sponsor_type, notes FROM mde_sponsors WHERE id = ? FOR UPDATE');
        $stmt->execute([$sponsorId]);
        $beforeSponsor = $stmt->fetch();
        if (!$beforeSponsor) {
            throw new InvalidArgumentException('الكفيل غير موجود.');
        }

        $name = trim((string) ($_POST['sponsor_name'] ?? $beforeSponsor['sponsor_name']));
        $file = trim((string) ($_POST['file_number'] ?? (string) $beforeSponsor['file_number']));
        $type = (string) ($_POST['sponsor_type'] ?? $beforeSponsor['sponsor_type']);
        if ($name === '' || !in_array($type, ['individual', 'tree'], true)) {
            throw new InvalidArgumentException('تحقق من اسم الكفيل ونوعه.');
        }
        $duplicate = $pdo->prepare('SELECT id FROM mde_sponsors WHERE LOWER(TRIM(sponsor_name)) = LOWER(?) AND id <> ? LIMIT 1');
        $duplicate->execute([$name, $sponsorId]);
        if ($duplicate->fetch()) {
            throw new InvalidArgumentException('يوجد كفيل آخر بالاسم نفسه. لم يتم حفظ أي تعديل.');
        }
        $afterSponsor = ['sponsor_name' => $name, 'file_number' => $file ?: null, 'sponsor_type' => $type, 'notes' => $beforeSponsor['notes']];
        $pdo->prepare('UPDATE mde_sponsors SET sponsor_name = ?, file_number = ?, sponsor_type = ? WHERE id = ?')
            ->execute([$name, $file ?: null, $type, $sponsorId]);
        mde_manage_log($pdo, 'sponsor', (int) $sponsorId, $beforeSponsor, $afterSponsor);

        $mothers = $_POST['mothers'] ?? [];
        if (!is_array($mothers)) throw new InvalidArgumentException('بيانات الأمهات غير صالحة.');
        $motherSelect = $pdo->prepare('SELECT mo.id, mo.mother_name FROM mde_mothers mo WHERE mo.id = ? AND EXISTS (SELECT 1 FROM mde_orphans o JOIN mde_sponsorships ss ON ss.orphan_id = o.id WHERE o.mother_id = mo.id AND ss.sponsor_id = ?) FOR UPDATE');
        $motherUpdate = $pdo->prepare('UPDATE mde_mothers SET mother_name = ? WHERE id = ?');
        foreach ($mothers as $id => $rawName) {
            $id = filter_var($id, FILTER_VALIDATE_INT);
            $newName = trim((string) $rawName);
            if (!$id || $newName === '') throw new InvalidArgumentException('اسم الأم مطلوب.');
            $motherSelect->execute([$id, $sponsorId]);
            $before = $motherSelect->fetch();
            if (!$before) throw new InvalidArgumentException('إحدى الأمهات لا تتبع هذا الكفيل.');
            $motherUpdate->execute([$newName, $id]);
            mde_manage_log($pdo, 'mother', (int) $id, $before, ['mother_name' => $newName]);
        }

        $orphans = $_POST['orphans'] ?? [];
        if (!is_array($orphans)) throw new InvalidArgumentException('بيانات الأيتام غير صالحة.');
        $orphanSelect = $pdo->prepare('SELECT o.id, o.orphan_name FROM mde_orphans o JOIN mde_sponsorships ss ON ss.orphan_id = o.id WHERE o.id = ? AND ss.sponsor_id = ? LIMIT 1 FOR UPDATE');
        $orphanUpdate = $pdo->prepare('UPDATE mde_orphans SET orphan_name = ? WHERE id = ?');
        foreach ($orphans as $id => $rawName) {
            $id = filter_var($id, FILTER_VALIDATE_INT);
            $newName = trim((string) $rawName);
            if (!$id || $newName === '') throw new InvalidArgumentException('اسم اليتيم مطلوب.');
            $orphanSelect->execute([$id, $sponsorId]);
            $before = $orphanSelect->fetch();
            if (!$before) throw new InvalidArgumentException('أحد الأيتام لا يتبع هذا الكفيل.');
            $orphanUpdate->execute([$newName, $id]);
            mde_manage_log($pdo, 'orphan', (int) $id, $before, ['orphan_name' => $newName]);
        }

        $monthly = $_POST['monthly'] ?? [];
        if (!is_array($monthly)) throw new InvalidArgumentException('بيانات الإدخالات الشهرية غير صالحة.');
        $monthlySelect = $pdo->prepare('SELECT me.id, me.entry_date, me.sponsorship_amount, me.services_amount, me.entered_by, me.supervisor_name, me.notes FROM mde_monthly_sponsorship_entries me JOIN mde_sponsorships ss ON ss.id = me.sponsorship_id WHERE me.id = ? AND ss.sponsor_id = ? FOR UPDATE');
        $monthlyUpdate = $pdo->prepare('UPDATE mde_monthly_sponsorship_entries SET entry_date=?, sponsorship_amount=?, services_amount=?, notes=? WHERE id=?');
        foreach ($monthly as $id => $values) {
            $id = filter_var($id, FILTER_VALIDATE_INT);
            if (!$id || !is_array($values)) throw new InvalidArgumentException('أحد معرّفات الإدخال الشهري غير صحيح.');
            $monthlySelect->execute([$id, $sponsorId]);
            $before = $monthlySelect->fetch();
            if (!$before) throw new InvalidArgumentException('أحد الإدخالات الشهرية لا يتبع هذا الكفيل.');
            $date = mde_manage_date(trim((string) ($values['entry_date'] ?? $before['entry_date'])), 'تاريخ الإدخال');
            $amount = filter_var($values['sponsorship_amount'] ?? $before['sponsorship_amount'], FILTER_VALIDATE_INT);
            $services = filter_var($values['services_amount'] ?? $before['services_amount'], FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 0 || $services === false || $services < 0) {
                throw new InvalidArgumentException('المبالغ يجب أن تكون أعداداً صحيحة غير سالبة.');
            }
            $notes = trim((string) ($values['notes'] ?? $before['notes']));
            $after = ['entry_date' => $date, 'sponsorship_amount' => $amount, 'services_amount' => $services, 'entered_by' => $before['entered_by'], 'supervisor_name' => $before['supervisor_name'], 'notes' => $notes ?: null];
            $monthlyUpdate->execute([$date, $amount, $services, $notes ?: null, $id]);
            mde_manage_log($pdo, 'monthly_entry', (int) $id, $before, $after);
        }

        $messages = $_POST['messages'] ?? [];
        if (!is_array($messages)) throw new InvalidArgumentException('بيانات بواسطة والمشرف غير صالحة.');
        $messageSelect = $pdo->prepare('SELECT me.id, me.entered_by, me.supervisor_name, me.report_month, me.entry_date, me.created_at AS monthly_created_at, ss.sponsor_id FROM mde_monthly_sponsorship_entries me JOIN mde_sponsorships ss ON ss.id = me.sponsorship_id WHERE me.id = ? AND ss.sponsor_id = ? FOR UPDATE');
        $messageUpdate = $pdo->prepare('UPDATE mde_monthly_sponsorship_entries SET entered_by = ?, supervisor_name = ? WHERE id = ?');
        foreach ($messages as $messageKey => $messageValues) {
            if (!is_array($messageValues) || !preg_match('/^[a-f0-9]{64}$/', (string) $messageKey)) throw new InvalidArgumentException('مجموعة الرسالة غير صالحة.');
            $entered = trim((string) ($messageValues['entered_by'] ?? ''));
            $supervisor = trim((string) ($messageValues['supervisor_name'] ?? ''));
            $ids = $messageValues['monthly_ids'] ?? [];
            if (!is_array($ids) || !$ids) throw new InvalidArgumentException('لم يتم تحديد صفوف الرسالة لتحديث بيانات الموظف والمشرف.');
            if (mb_strlen($entered) > 190 || mb_strlen($supervisor) > 190) throw new InvalidArgumentException('اسم الموظف أو المشرف أطول من الحد المسموح.');
            $uniqueIds = [];
            foreach ($ids as $rawId) {
                $id = filter_var($rawId, FILTER_VALIDATE_INT);
                if (!$id || $id < 1 || isset($uniqueIds[$id])) throw new InvalidArgumentException('معرّف إدخال مكرر أو غير صحيح ضمن الرسالة.');
                $uniqueIds[$id] = true;
                $messageSelect->execute([$id, $sponsorId]);
                $beforeMessageEntry = $messageSelect->fetch();
                if (!$beforeMessageEntry || mde_manage_message_key($beforeMessageEntry) !== $messageKey) throw new InvalidArgumentException('أحد صفوف الرسالة لا يطابق مجموعة الرسالة الحالية. أعد تحميل النتائج.');
                $afterMessageEntry = $beforeMessageEntry;
                $afterMessageEntry['entered_by'] = $entered ?: null;
                $afterMessageEntry['supervisor_name'] = $supervisor ?: null;
                $messageUpdate->execute([$entered ?: null, $supervisor ?: null, $id]);
                mde_manage_log($pdo, 'monthly_entry', (int) $id, $beforeMessageEntry, $afterMessageEntry);
            }
        }

        $pdo->commit();
        mde_flash('success', 'تم حفظ التعديلات وتسجيل التغييرات في سجل المراجعة.');
        mde_redirect('manage', $q !== '' ? ['q' => $q] : []);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception instanceof PDOException
            ? 'تعذر حفظ التعديلات بسبب خطأ في قاعدة البيانات. لم يتم حفظ أي جزء منها.'
            : $exception->getMessage();
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $html = mde_manage_render($pdo, $q, mde_csrf_token());
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}
?>
<style>
.manage-table-wrap{overflow-x:auto}
.manage-register{min-width:1120px}
.manage-register .manage-input{font-size:.75rem}
.manage-register textarea{min-height:58px;resize:vertical}
.manage-register .form-control,.manage-register .form-select{font-size:.74rem;padding:.2rem .25rem;min-width:0}
.manage-group.is-editing{outline:2px solid #7da991;outline-offset:3px;border-radius:.5rem}
.manage-group.is-editing .manage-view{display:none!important}
.manage-group.is-editing .manage-input.d-none{display:block!important}
.manage-group.is-editing .manage-input.d-none select{display:block}
.manage-group.is-editing .manage-input.d-none input,.manage-group.is-editing .manage-input.d-none textarea{display:block}
</style>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
 <h1 class="h3 mb-0">بحث وإدارة البيانات</h1>
 <span class="badge text-bg-secondary">تعديل داخل سجل الكفالة مع حفظ سجل التغييرات</span>
</div>
<p class="text-secondary">ابحث مباشرة أثناء الكتابة باسم الكفيل أو رقم الملف أو الأم أو اليتيم أو الشهر أو المبلغ أو الموظف أو المشرف أو نوع التبرع. تظهر النتائج بتصميم سجل الإدخال نفسه، مع صف مستقل لكل إدخال شهري.</p>
<div class="card p-3 mb-3">
 <label class="form-label" for="manageSearch">بحث مباشر</label>
 <input class="form-control" id="manageSearch" type="search" value="<?= mde_h($q) ?>" placeholder="ابدأ بكتابة أي جزء من البيانات..." autocomplete="off">
 <div class="form-text" id="manageSearchStatus" aria-live="polite">اكتب للبحث تلقائياً دون الضغط على زر أو إعادة تحميل الصفحة.</div>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= mde_h($error) ?></div><?php endif; ?>
<div id="manageResults" aria-live="polite"><?= mde_manage_render($pdo, $q, mde_csrf_token()) ?></div>
<script>
(() => {
 'use strict';
 const input = document.getElementById('manageSearch');
 const results = document.getElementById('manageResults');
 const status = document.getElementById('manageSearchStatus');
 let timer = null;
 let controller = null;
 let editing = false;
 const search = () => {
  const query = input.value.trim();
  if (controller) controller.abort();
  controller = new AbortController();
  status.textContent = 'جارٍ البحث...';
  fetch('index.php?page=manage&ajax=1&q=' + encodeURIComponent(query), {
   headers: {'X-Requested-With':'XMLHttpRequest'},
   signal: controller.signal
  }).then(response => {
   if (!response.ok) throw new Error('تعذر تنفيذ البحث (' + response.status + ').');
   return response.text();
  }).then(html => {
   results.innerHTML = html;
   status.textContent = query ? 'تم تحديث النتائج مباشرة.' : 'اكتب للبحث تلقائياً دون الضغط على زر أو إعادة تحميل الصفحة.';
   editing = false;
  }).catch(error => {
   if (error.name !== 'AbortError') status.textContent = error.message || 'تعذر تحديث النتائج.';
  });
 };
 input.addEventListener('input', () => {
  if (editing) { status.textContent = 'احفظ أو ألغِ التعديل المفتوح قبل تغيير البحث.'; return; }
  clearTimeout(timer);
  timer = setTimeout(search, 180);
 });
 results.addEventListener('click', event => {
  const group = event.target.closest('[data-sponsor-group]');
  if (!group) return;
  if (event.target.closest('.manage-edit-toggle')) {
   if (editing && !group.classList.contains('is-editing')) {
    status.textContent = 'احفظ أو ألغِ التعديل المفتوح أولاً.';
    return;
   }
   group.classList.add('is-editing');
   group.querySelector('.manage-edit-toggle').classList.add('d-none');
   group.querySelector('.manage-save').classList.remove('d-none');
   group.querySelector('.manage-cancel').classList.remove('d-none');
   editing = true;
  }
  if (event.target.closest('.manage-cancel')) {
   search();
  }
 });
 results.addEventListener('submit', event => {
  const form = event.target.closest('.manage-save-form');
  if (!form) return;
  if (!window.confirm('سيتم حفظ كل التعديلات الظاهرة داخل سجل هذا الكفيل، مع تسجيلها في سجل التغييرات. هل تريد المتابعة؟')) {
   event.preventDefault();
  }
 });
})();
</script>
