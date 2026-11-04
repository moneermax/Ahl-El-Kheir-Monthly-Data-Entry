<?php
declare(strict_types=1);

// Buffer the page shell so POST handlers can still issue clean redirects.
ob_start();
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
        .page-wrap{max-width:1200px}.entry-page-wrap{width:100%;max-width:none;padding-left:1rem;padding-right:1rem}.card{border:0;box-shadow:0 3px 18px #172b3a0d}
        .required:after{content:' *';color:#c0392b}
        .mde-person-dropdown .dropdown-menu{max-height:16rem;overflow-y:auto;text-align:right}
        .mde-person-dropdown .dropdown-item{text-align:right}
        .entry-register-wrap{border:1px solid #c5d2ca;border-radius:.5rem;background:#fff}
        .entry-register{width:100%;table-layout:fixed;margin:0;font-size:.78rem;border-collapse:collapse}
        .entry-register th,.entry-register td{border:1px solid #d5dfd9}
        .entry-register th{text-align:center;vertical-align:middle;font-weight:700;padding:.4rem .2rem;font-size:.74rem;color:#203a34}
        .entry-register th.h-sponsor{background:#dcebe2}.entry-register th.h-mother{background:#dbe6f1}.entry-register th.h-orphan{background:#eceff1}
        .entry-register td{padding:.25rem;vertical-align:top;background:#fff}
        .entry-register .sponsor-span-cell{background:#f2f8f4}
        .entry-register .mother-span-cell{background:#edf3f9}
        .entry-register .seq-cell{text-align:center;vertical-align:middle}
        .entry-register .orphan-cell,.entry-register .orphan-row td{vertical-align:middle}
        .entry-register .family-block td{border-top:2px solid #9db3a6}
        .entry-register .orphan-row:hover td:not(.sponsor-span-cell):not(.mother-span-cell){background:#fafcfb}
        .entry-register .form-control,.entry-register .form-select{font-size:.78rem;min-width:0;width:100%;padding:.2rem .3rem;min-height:28px}
        .entry-register .date-input{padding-inline:.15rem;font-size:.72rem}
        .entry-register .orphan-amount,.entry-register .orphan-services{text-align:center}
        .entry-register input[type=number]::-webkit-outer-spin-button,.entry-register input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
        .entry-register input[type=number]{-moz-appearance:textfield}
        .entry-register .btn{white-space:nowrap;padding:.15rem .4rem;font-size:.74rem;line-height:1.3}
        .entry-register .orphan-cell .btn{flex:0 0 auto}
        .entry-register .notes-input{resize:vertical;min-height:62px}
        .entry-register .mother-actions{display:flex;gap:.25rem}.entry-register .mother-actions .add-orphan{flex:1}
        .entry-register .donations{display:flex;flex-direction:column;gap:.25rem;margin-bottom:.25rem}
        .entry-register .donation-row{display:flex;gap:.2rem;align-items:center}
        .entry-register .donation-type{flex:1 1 0}.entry-register .donation-amount{flex:0 0 38%}.entry-register .donation-row .btn{flex:0 0 auto}
        .entry-register .mde-person-dropdown .form-select{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-height:28px;font-size:.76rem}
        .totals-bar{position:sticky;bottom:0;z-index:20;display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;background:#f1f7f3;border:1px solid #b9d3c4;border-radius:.5rem;padding:.6rem .9rem;box-shadow:0 -4px 14px #172b3a14}
        .totals-bar .form-label{font-size:.72rem;color:#52645b;margin-bottom:.15rem}
        .totals-field{width:170px}.totals-actual{min-width:190px}
        .totals-status{flex:1 1 220px;font-size:.8rem;color:#2f7a52}.totals-status.is-bad{color:#c0392b;font-weight:700}
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
<main class="<?= $page === 'entry' ? 'container-fluid entry-page-wrap' : 'container page-wrap' ?> pb-5">
 <?php if ($flash): ?>
  <div class="alert alert-<?= mde_h($flash['type']) ?>"><?= mde_h($flash['message']) ?></div>
 <?php endif; ?>
 <?php require $pages[$page]['file']; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/person-dropdown.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/person-dropdown.js') ?>"></script>
</body>
</html>
