<?php require __DIR__ . '/../app/bootstrap.php';
if (user()) redirect('dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([trim($_POST['username'] ?? '')]);
    $u = $stmt->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>$u['id'],'username'=>$u['username'],'full_name'=>$u['full_name'],'role'=>$u['role']];
        redirect('dashboard.php');
    }
    $error = 'نام کاربری یا رمز عبور صحیح نیست.';
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود</title><link rel="stylesheet" href="<?= e($config['app']['base_url']) ?>/assets/style.css"></head><body class="login-page">
<div class="login-card"><div class="brand login-brand"><img class="brand-logo login-logo" src="<?= e($config['app']['base_url']) ?>/assets/logo.png" alt="نشان سامانه"><div><strong> سامانه  مدیریت منابع انسانی</strong><small>سازمان رزم سوم فراجا</small></div></div>
<h1>ورود به سامانه</h1><?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>نام کاربری<input name="username" autocomplete="username" required></label><label>رمز عبور<input type="password" name="password" autocomplete="current-password" required></label><button class="btn primary" type="submit">ورود</button></form></div></body></html>
