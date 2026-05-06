<?php
/**
 * admin/login.php — النسخة المرحّلة (v2)
 * 
 * التغييرات:
 *   - يقرأ من جدول users بدل admins
 *   - يستخدم AuthMiddleware.attemptWithRoles() مع role='super_admin'
 *   - يضيف CSRF token للفورم
 *   - نفس التصميم والواجهة بالضبط
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/Helpers/RateLimiter.php';

use MenuPro\Helpers\RateLimiter;

// لو مسجل دخول كأدمن → روح للوحة التحكم
if(isset($_SESSION['admin_id'])) {
    header('Location: index.php'); exit;
}

$error = '';
$_ip   = RateLimiter::getIp();

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    // فحص Rate Limit أولاً (Admin أشد: 5 محاولات)
    $rl = RateLimiter::check($pdo, $_ip);
    if($rl['blocked']) {
        $error = "كثّرت المحاولات. انتظر {$rl['minutes']} دقيقة وحاول مرة تانية.";
    } elseif(!$auth->validateCsrf()) {
        $error = 'انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة تانية.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $user = $auth->attemptWithRoles($email, $password, ['super_admin']);

        if($user) {
            RateLimiter::clearForIp($pdo, $_ip);
            header('Location: index.php'); exit;
        } else {
            RateLimiter::recordFailure($pdo, $_ip);
            $error = 'الإيميل أو كلمة السر غلط!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — MenuPro</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,700;0,9..144,900;1,9..144,300&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --p:     #FF6B35;
    --bg:    #0C0C0C;
    --bg2:   #141414;
    --bg3:   #1C1C1C;
    --line:  rgba(255,255,255,0.07);
    --ink:   #F0EBE3;
    --ink2:  rgba(240,235,227,0.45);
    --ink3:  rgba(240,235,227,0.12);
}

body {
    font-family: 'Tajawal', sans-serif;
    min-height: 100vh; background: var(--bg);
    display: flex; align-items: center; justify-content: center;
    padding: 20px; overflow: hidden; color: var(--ink);
}

body::before {
    content: ''; position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='200' height='200' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
    opacity: 0.7;
}

.amb { position: fixed; pointer-events: none; z-index: 0; border-radius: 50%; background: radial-gradient(circle, rgba(255,107,53,0.10) 0%, transparent 70%); }
.amb-1 { width: 400px; height: 400px; top: -100px; right: -100px; animation: drift 14s ease-in-out infinite; }
.amb-2 { width: 300px; height: 300px; bottom: -80px; left: -80px; animation: drift 18s ease-in-out infinite reverse; }
@keyframes drift { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-30px,20px)} }

body::after {
    content: ''; position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background-image: radial-gradient(circle, rgba(255,255,255,0.04) 1px, transparent 1px);
    background-size: 32px 32px;
}

.login-card {
    position: relative; z-index: 1; width: 100%; max-width: 400px;
    background: var(--bg2); border: 1px solid var(--line); border-radius: 24px;
    padding: 36px 32px; box-shadow: 0 24px 64px rgba(0,0,0,0.6);
    animation: popIn 0.5s cubic-bezier(0.22,1,0.36,1) both;
}
.login-card::before { content: ''; position: absolute; top: 0; right: 0; left: 0; height: 2px; background: var(--p); border-radius: 24px 24px 0 0; }
@keyframes popIn { from { opacity:0; transform:translateY(24px) scale(0.97); } to { opacity:1; transform:translateY(0) scale(1); } }

.brand { display: flex; align-items: center; gap: 11px; margin-bottom: 28px; animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) .1s both; }
.brand-mark { width: 42px; height: 42px; border-radius: 13px; background: var(--p); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 16px rgba(255,107,53,0.4); flex-shrink: 0; }
.brand-mark svg { width: 20px; height: 20px; color: #fff; }
.brand-name { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 900; color: var(--ink); line-height: 1; }
.brand-name span { color: var(--p); }
.brand-sub { font-size: 10px; color: var(--ink2); font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 2px; }

.brand-divider { height: 1px; background: var(--line); margin-bottom: 24px; animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) .14s both; }

.login-heading { margin-bottom: 22px; animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) .18s both; }
.login-title { font-family: 'Fraunces', serif; font-size: 26px; font-weight: 900; color: var(--ink); line-height: 1.1; margin-bottom: 5px; }
.login-title em { font-style: italic; font-weight: 300; color: var(--ink2); }
.login-sub { font-size: 13px; color: var(--ink2); font-weight: 500; }

.error-box { display: flex; align-items: center; gap: 9px; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.18); border-radius: 12px; padding: 11px 14px; color: #FCA5A5; font-size: 13px; font-weight: 600; margin-bottom: 18px; animation: shake 0.4s ease, fadeUp 0.3s ease; }
.error-box svg { width: 15px; height: 15px; flex-shrink: 0; }
@keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-5px)} 75%{transform:translateX(5px)} }

.fg { margin-bottom: 14px; animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) both; }
.fg:nth-child(1){animation-delay:.22s} .fg:nth-child(2){animation-delay:.28s}
.fg label { display: block; font-size: 10px; font-weight: 700; color: var(--ink2); letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 8px; }
.input-wrap { position: relative; }
.input-icon { position: absolute; right: 13px; top: 50%; transform: translateY(-50%); color: var(--ink3); pointer-events: none; display: flex; }
.input-icon svg { width: 15px; height: 15px; }

input[type=email], input[type=password], input[type=text] {
    width: 100%; padding: 12px 40px 12px 40px; background: var(--bg3); border: 1.5px solid var(--line);
    border-radius: 13px; color: var(--ink); font-size: 14px; font-family: 'Tajawal', sans-serif;
    outline: none; transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
}
input::placeholder { color: rgba(240,235,227,0.18); }
input:focus { border-color: rgba(255,107,53,0.45); background: var(--bg2); box-shadow: 0 0 0 4px rgba(255,107,53,0.08); }
.eye-btn { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--ink2); padding: 3px; transition: color 0.2s; display: flex; }
.eye-btn svg { width: 15px; height: 15px; }

.submit-btn {
    width: 100%; padding: 13px; background: var(--p); color: #fff; border: none; border-radius: 13px;
    font-size: 15px; font-weight: 800; cursor: pointer; font-family: 'Tajawal', sans-serif; margin-top: 6px;
    transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s; box-shadow: 0 6px 22px rgba(255,107,53,0.42);
    display: flex; align-items: center; justify-content: center; gap: 9px; position: relative; overflow: hidden;
    animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) .34s both;
}
.submit-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
.submit-btn::before { content: ''; position: absolute; top: 0; left: -100%; width: 60%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent); transform: skewX(-20deg); transition: left 0.6s; }
.submit-btn:hover::before { left: 140%; }
.submit-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(255,107,53,0.52); opacity: 0.93; }
.submit-btn:active { transform: translateY(0); }
.submit-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

.security-note { display: flex; align-items: center; gap: 7px; justify-content: center; margin-top: 20px; font-size: 11px; color: var(--ink2); animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) .4s both; }
.security-note svg { width: 12px; height: 12px; flex-shrink: 0; }

@keyframes fadeUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
</style>
</head>
<body>

<div class="amb amb-1"></div>
<div class="amb amb-2"></div>

<div class="login-card">
    <div class="brand">
        <div class="brand-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div class="brand-right">
            <div class="brand-name">Menu<span>Pro</span></div>
            <div class="brand-sub">Admin Panel</div>
        </div>
    </div>

    <div class="brand-divider"></div>

    <div class="login-heading">
        <div class="login-title">مرحباً<br><em>بالأدمن</em></div>
        <div class="login-sub">سجّل دخولك للوحة الإدارة</div>
    </div>

    <?php if($error): ?>
    <div class="error-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="loginForm">
        <?= $auth->csrfField() ?>

        <div class="fg">
            <label>البريد الإلكتروني</label>
            <div class="input-wrap">
                <span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
                <input type="email" name="email" placeholder="admin@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
            </div>
        </div>

        <div class="fg">
            <label>كلمة السر</label>
            <div class="input-wrap">
                <span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg></span>
                <input type="password" name="password" id="passInput" placeholder="••••••••" required>
                <button type="button" class="eye-btn" onclick="togglePass()">
                    <svg id="eyeShow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg id="eyeHide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
            </div>
        </div>

        <button type="submit" class="submit-btn" id="submitBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            دخول للوحة الأدمن
        </button>
    </form>

    <div class="security-note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        اتصال مشفر — وصول محمي للأدمن فقط
    </div>
</div>

<script>
function togglePass() {
    var i = document.getElementById('passInput');
    var s = document.getElementById('eyeShow');
    var h = document.getElementById('eyeHide');
    if(i.type === 'password') { i.type = 'text'; s.style.display = 'none'; h.style.display = 'block'; }
    else { i.type = 'password'; s.style.display = 'block'; h.style.display = 'none'; }
}
document.getElementById('loginForm').addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:16px;height:16px;animation:spin 1s linear infinite"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg> جاري الدخول...';
    btn.disabled = true;
});
</script>
<style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
</body>
</html>