<?php
require_once __DIR__ . '/../bootstrap.php';
$appName = $config['app']['name'];
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$navItems = [
    ['dashboard.php','داشبورد'],
    ['finance_history.php','امور مالی','finance_form.php'],
    ['equipment.php','آماد','equipment_status.php','equipment_return.php'],
    ['orders.php','احکام'],
    ['training.php','آموزش','training_report.php'],
    ['map.php','حوزه استحفاظی'],
    ['personnel.php','فهرست عناصر','personnel_view.php','personnel_file.php'],
];
?>
<!doctype html><html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($appName) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('assets/style.css')) ?>">
</head><body>
<header class="topbar"><div class="brand"><img class="brand-logo" src="<?= e($config['app']['base_url']) ?>/assets/logo.png" alt="نشان سامانه"><div><strong><?= e($appName) ?></strong><small>سازمان رزم سوم فراجا</small></div></div>
<nav class="topnav">
<?php foreach ($navItems as $item): ?>
<?php $isActive = $currentPage === $item[0] || in_array($currentPage, array_slice($item, 2), true); ?>
<a class="nav-btn<?= $isActive ? ' active' : '' ?>" href="<?= e($config['app']['base_url']) ?>/<?= e($item[0]) ?>"><?= e($item[1]) ?></a>
<?php endforeach; ?>
<?php if (can_manage_personnel()): ?><a class="nav-btn nav-accent<?= $currentPage === 'personnel_form.php' ? ' active-form' : '' ?>" href="<?= e($config['app']['base_url']) ?>/personnel_form.php"><span class="nav-accent-label">افزودن عنصر</span><span class="nav-accent-plus" aria-hidden="true">+</span></a><?php endif; ?>
<a class="nav-btn nav-logout" href="<?= e($config['app']['base_url']) ?>/logout.php">خروج</a>
</nav></header>
<main class="container">
<?php
$backMap = [
    'disciplinary_form.php' => ['disciplinary.php', 'پرونده انضباطی'],
    'disciplinary_reports.php' => ['disciplinary.php', 'پرونده انضباطی'],
    'training_report.php' => ['training.php', 'آموزش'],
    'training_applicants.php' => ['training.php', 'آموزش'],
    'equipment_status.php' => ['equipment.php', 'آماد'],
    'equipment_return.php' => ['equipment.php', 'آماد'],
    'personnel_view.php' => ['personnel.php', 'فهرست عناصر'],
    'personnel_form.php' => ['personnel.php', 'فهرست عناصر'],
    'personnel_file.php' => ['personnel.php', 'فهرست عناصر'],
    'personnel_history.php' => ['personnel.php', 'فهرست عناصر'],
    'personnel_history_view.php' => ['personnel_history.php', 'تاریخچه تغییرات'],
    'disciplinary.php' => ['personnel.php', 'فهرست عناصر'],
    'finance_form.php' => ['finance_history.php', 'امور مالی'],
];
$backTarget = $backMap[$currentPage] ?? null;
?>
<?php if ($backTarget && empty($hideBackbar)): ?>
  <div class="global-backbar">
    <a class="global-back-btn" href="<?= e($config['app']['base_url']) ?>/<?= e($backTarget[0]) ?>" aria-label="بازگشت به <?= e($backTarget[1]) ?>">بازگشت</a>
  </div>
<?php endif; ?>
