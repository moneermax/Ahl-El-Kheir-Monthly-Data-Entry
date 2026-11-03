<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$page = (string) ($_GET['page'] ?? 'entry');
$allowed = ['entry', 'manage', 'departures', 'reports'];
if (!in_array($page, $allowed, true)) {
    $page = 'entry';
}
$pages = [
    'entry' => ['title' => 'إدخال الكفالات الجديدة', 'file' => __DIR__ . '/pages/entry.php'],
    'manage' => ['title' => 'بحث وإدارة البيانات', 'file' => __DIR__ . '/pages/manage.php'],
    'departures' => ['title' => 'تسجيل مغادرة كفيل', 'file' => __DIR__ . '/pages/departures.php'],
    'reports' => ['title' => 'التقارير الشهرية', 'file' => __DIR__ . '/pages/reports.php'],
];
$flash = $_SESSION['mde_flash'] ?? null;
unset($_SESSION['mde_flash']);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mde_h($pages[$page]['title']) ?> | أداة البيانات الشهرية</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Cairo',sans-serif;background:#f4f6f8;color:#24313d}
        .navbar{background:#173b35}.navbar-brand,.nav-link{color:#fff!important}
        .nav-link.active{background:#ffffff20;border-radius:.5rem}
        .page-wrap{max-width:1200px}.card{border:0;box-shadow:0 3px 18px #172b3a0d}
        .required:after{content:' *';color:#c0392b}
        .mde-person-dropdown .dropdown-menu{max-height:16rem;overflow-y:auto;text-align:right}
        .mde-person-dropdown .dropdown-item{text-align:right}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark mb-4">
 <div class="container page-wrap">
  <a class="navbar-brand fw-bold" href="index.php">أداة إدخال البيانات والتقارير الشهرية</a>
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mdeNav"><span class="navbar-toggler-icon"></span></button>
  <div class="collapse navbar-collapse" id="mdeNav">
   <div class="navbar-nav me-auto">
    <?php foreach ($pages as $key => $item): ?>
     <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="?page=<?= mde_h($key) ?>"><?= mde_h($item['title']) ?></a>
    <?php endforeach; ?>
   </div>
  </div>
 </div>
</nav>
<main class="container page-wrap pb-5">
 <?php if ($flash): ?>
  <div class="alert alert-<?= mde_h($flash['type']) ?>"><?= mde_h($flash['message']) ?></div>
 <?php endif; ?>
 <?php require $pages[$page]['file']; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/person-dropdown.js"></script>
</body>
</html>
