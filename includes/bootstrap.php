<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Khartoum');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

$configFile = dirname(__DIR__) . '/config/database.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Database configuration is missing. Copy config/database.example.php to config/database.php.');
}
$dbConfig = require $configFile;

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $dbConfig['host'],
    (int) ($dbConfig['port'] ?? 3306),
    $dbConfig['database'],
    $dbConfig['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    error_log('Monthly data-entry database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('تعذر الاتصال بقاعدة بيانات أداة الإدخال. راجع إعدادات الاتصال.');
}

function mde_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mde_csrf_token(): string
{
    if (empty($_SESSION['mde_csrf'])) {
        $_SESSION['mde_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['mde_csrf'];
}

function mde_verify_csrf(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    $known = (string) ($_SESSION['mde_csrf'] ?? '');
    if ($sent === '' || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('انتهت صلاحية النموذج. أعد تحميل الصفحة وحاول مرة أخرى.');
    }
}

function mde_redirect(string $page, array $params = []): never
{
    // The page shell is rendered before page-specific POST handlers run.
    // Discard that buffered shell so the redirect header is sent cleanly.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $query = http_build_query(['page' => $page] + $params);
    header('Location: index.php?' . $query);
    exit;
}

function mde_flash(string $type, string $message): void
{
    $_SESSION['mde_flash'] = ['type' => $type, 'message' => $message];
}

function mde_month_start(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('تاريخ غير صالح.');
    }
    return $parsed->modify('first day of this month')->format('Y-m-d');
}
