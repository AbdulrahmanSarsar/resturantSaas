<?php
/**
 * restaurant/staff/login.php — النسخة المرحّلة (v2)
 * 
 * التغييرات:
 *   - يقرأ من جدول users بدل restaurant_staff
 *   - يستخدم AuthMiddleware مع roles: waiter, kitchen, cashier
 *   - يضيف CSRF token
 *   - نفس التصميم بالضبط
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../src/Helpers/RateLimiter.php';

use MenuPro\Helpers\RateLimiter;

// لو مسجل دخول → وجّه حسب الدور
if(isset($_SESSION['staff_id'])) {
    $role = $_SESSION['staff_role'];
    if($role === 'kitchen')  { header('Location: kitchen.php'); exit; }
    if($role === 'cashier')  { header('Location: cashier.php'); exit; }
    header('Location: waiter.php'); exit;
}

$error = '';
$_ip   = RateLimiter::getIp();

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    // فحص Rate Limit أولاً
    $rl = RateLimiter::check($pdo, $_ip);
    if($rl['blocked']) {
        $error = "كثّرت المحاولات. انتظر {$rl['minutes']} دقيقة وحاول مرة تانية.";
    } elseif(!$auth->validateCsrf()) {
        $error = 'انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة تانية.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $user = $auth->attemptWithRoles($username, $password, ['waiter', 'kitchen', 'cashier']);

        if($user) {
            RateLimiter::clearForIp($pdo, $_ip);
            $role = $user['role'];
            if($role === 'kitchen')  { header('Location: kitchen.php'); exit; }
            if($role === 'cashier')  { header('Location: cashier.php'); exit; }
            header('Location: waiter.php'); exit;
        } else {
            RateLimiter::recordFailure($pdo, $_ip);
            $error = 'اسم المستخدم أو كلمة السر غلط';
        }
    }
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>دخول الموظفين</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Tajawal',sans-serif;background:#0C0C0C;color:#F0EBE3;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.box{width:100%;max-width:380px;background:#141414;border:1px solid rgba(240,235,227,0.06);border-radius:24px;padding:36px 32px;}
.logo-wrap{text-align:center;margin-bottom:28px;}
.logo-icon{width:64px;height:64px;border-radius:18px;background:rgba(255,107,53,0.12);border:1px solid rgba(255,107,53,0.2);display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 12px;}
.title{font-family:'Fraunces',serif;font-size:22px;font-weight:900;color:#F0EBE3;letter-spacing:-0.3px;}
.sub{font-size:13px;color:#6B6560;margin-top:5px;}
.form-group{margin-bottom:16px;}
label{display:block;font-size:12px;font-weight:700;color:#6B6560;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;}
input{width:100%;padding:13px 16px;background:#1C1C1C;border:1.5px solid rgba(240,235,227,0.08);border-radius:12px;color:#F0EBE3;font-size:14px;font-family:'Tajawal',sans-serif;outline:none;transition:border-color 0.2s;}
input:focus{border-color:#FF6B35;}
.btn{width:100%;padding:14px;background:#FF6B35;color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;margin-top:4px;transition:opacity 0.2s;}
.btn:active{opacity:0.88;}
.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);color:#EF4444;padding:11px 14px;border-radius:12px;font-size:13px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
</style>
</head>
<body>
<div class="box">
    <div class="logo-wrap">
        <div class="logo-icon">👨‍💼</div>
        <div class="title">دخول الموظفين</div>
        <div class="sub">أدخل بيانات حسابك</div>
    </div>
    <?php if($error): ?>
    <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <?= $auth->csrfField() ?>
        <div class="form-group">
            <label>اسم المستخدم</label>
            <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
        </div>
        <div class="form-group">
            <label>كلمة السر</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn">دخول</button>
    </form>
</div>
</body>
</html>