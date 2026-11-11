<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/reference_options.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    mde_verify_csrf();
    $role = trim((string) ($_POST['role'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    if (!in_array($role, ['operator', 'supervisor'], true)) {
        throw new InvalidArgumentException('نوع القائمة غير صالح.');
    }
    $name = preg_replace('/^ح\\s+/u', '', $name) ?? $name;
    $name = preg_replace('/\\s+/u', ' ', trim($name)) ?? trim($name);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 190 || !preg_match('/^[\\p{Arabic} .\\'-]+$/u', $name)) {
        throw new InvalidArgumentException('أدخل اسماً عربياً صحيحاً لا يتجاوز 190 حرفاً.');
    }
    $normalized = mde_person_option_key($name);
    if ($normalized === '') throw new InvalidArgumentException('الاسم غير صالح.');

    $insert = $pdo->prepare('INSERT IGNORE INTO mde_personnel_options (role, display_name, normalized_name) VALUES (?, ?, ?)');
    $insert->execute([$role, $name, $normalized]);
    $find = $pdo->prepare('SELECT display_name FROM mde_personnel_options WHERE role = ? AND normalized_name = ? LIMIT 1');
    $find->execute([$role, $normalized]);
    $savedName = $find->fetchColumn();
    if (!is_string($savedName)) throw new RuntimeException('تعذر حفظ الاسم.');

    echo json_encode(['success' => true, 'duplicate' => $insert->rowCount() === 0, 'name' => $savedName, 'role' => $role], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('Personnel option save failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'تعذر حفظ الاسم. تأكد من تطبيق ترحيل قاعدة البيانات رقم 11.'], JSON_UNESCAPED_UNICODE);
}
