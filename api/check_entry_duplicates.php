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
    $sponsorId = filter_var($_POST['sponsor_id'] ?? 0, FILTER_VALIDATE_INT);
    $sponsorId = $sponsorId === false ? 0 : max(0, (int) $sponsorId);
    $sponsorName = trim((string) ($_POST['sponsor_name'] ?? ''));
    $entryDate = trim((string) ($_POST['entry_date'] ?? ''));

    if (($sponsorId < 1 && $sponsorName === '') || $entryDate === '') {
        echo json_encode(['entries' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $month = mde_month_start($entryDate);
    $select = 'SELECT me.id AS entry_id, sp.id AS sponsor_id, sp.sponsor_name, me.entry_date,
                      mo.mother_name, o.orphan_name,
                      me.sponsorship_amount, me.services_amount
               FROM mde_monthly_sponsorship_entries AS me
               INNER JOIN mde_sponsorships AS rel ON rel.id = me.sponsorship_id
               INNER JOIN mde_sponsors AS sp ON sp.id = rel.sponsor_id
               INNER JOIN mde_orphans AS o ON o.id = rel.orphan_id
               INNER JOIN mde_mothers AS mo ON mo.id = o.mother_id
               WHERE me.report_month = ? AND ';
    if ($sponsorId > 0) {
        $stmt = $pdo->prepare($select . 'sp.id = ? ORDER BY sp.sponsor_name, me.entry_date, me.id');
        $stmt->execute([$month, $sponsorId]);
    } else {
        $stmt = $pdo->prepare($select . 'LOWER(TRIM(sp.sponsor_name)) = LOWER(TRIM(?)) ORDER BY sp.sponsor_name, me.entry_date, me.id');
        $stmt->execute([$month, $sponsorName]);
    }

    echo json_encode(
        ['entries' => $stmt->fetchAll()],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['error' => 'تعذر فحص الإدخالات الشهرية السابقة.'], JSON_UNESCAPED_UNICODE);
}
