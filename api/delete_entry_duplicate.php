<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    mde_verify_csrf();

    $entryId = filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT);
    $sponsorId = filter_var($_POST['sponsor_id'] ?? null, FILTER_VALIDATE_INT);
    $entryDate = trim((string) ($_POST['entry_date'] ?? ''));

    if (!$entryId || $entryId < 1 || !$sponsorId || $sponsorId < 1 || $entryDate === '') {
        http_response_code(422);
        echo json_encode(['error' => 'بيانات الحذف غير مكتملة أو غير صحيحة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $reportMonth = mde_month_start($entryDate);
    $pdo->beginTransaction();

    $lock = $pdo->prepare(
        'SELECT me.*, rel.sponsor_id, sp.sponsor_name
         FROM mde_monthly_sponsorship_entries AS me
         INNER JOIN mde_sponsorships AS rel ON rel.id = me.sponsorship_id
         INNER JOIN mde_sponsors AS sp ON sp.id = rel.sponsor_id
         WHERE me.id = ?
         FOR UPDATE'
    );
    $lock->execute([$entryId]);
    $entry = $lock->fetch();

    if (!$entry || (int) $entry['sponsor_id'] !== $sponsorId || $entry['report_month'] !== $reportMonth) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'تغير هذا السجل أو لم يعد يطابق الكفيل والشهر المحددين. حدّث التحذير وحاول مرة أخرى.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $auditEntry = $pdo->prepare(
        "INSERT INTO mde_change_log (entity_type, entity_id, action, before_data, after_data)
         VALUES ('monthly_entry', ?, 'delete', ?, 'null')"
    );
    $auditEntry->execute([
        $entryId,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);

    $deleteEntry = $pdo->prepare('DELETE FROM mde_monthly_sponsorship_entries WHERE id = ?');
    $deleteEntry->execute([$entryId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'entry_id' => $entryId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => 'تعذر حذف الإدخال المحدد. لم يتم حذف أي سجل.'], JSON_UNESCAPED_UNICODE);
}
