<?php
/**
 * restaurant/login.php — النسخة المرحّلة (v2)
 * 
 * التغييرات:
 *   - يقرأ من جدول users بدل restaurants
 *   - يستخدم AuthMiddleware مع roles: chain_owner, restaurant_manager, branch_manager
 *   - يتحقق من انتهاء الاشتراك عبر subscriptions_v2
 *   - يضيف CSRF token
 *   - نفس التصميم بالضبط
 */
require_once __DIR__ . '/../bootstrap.php';

// لو مسجل دخول → روح للداشبورد
if(isset($_SESSION['restaurant_id'])) {
    header('Location: dashboard/index.php'); exit;
}

$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(!$auth->validateCsrf()) {
        $error = 'انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة تانية.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $user = $auth->attemptWithRoles($email, $password, ['chain_owner', 'restaurant_manager', 'branch_manager']);

        if($user) {
            // تحقق من الاشتراك
            $orgId = $user['organization_id'];
            if($orgId) {
                $subStmt = $pdo->prepare("
                    SELECT end_date FROM subscriptions_v2 
                    WHERE organization_id = ? AND is_active = 1 
                    ORDER BY end_date DESC LIMIT 1
                ");
                $subStmt->execute([$orgId]);
                $sub = $subStmt->fetch();
                
                if($sub && $sub['end_date'] < date('Y-m-d')) {
                    // اشتراك منتهي — سجّل خروج وأظهر خطأ
                    $auth->logout();
                    $error = 'انتهى اشتراكك! تواصل مع الإدارة لتجديده.';
                } else {
                    header('Location: dashboard/index.php'); exit;
                }
            } else {
                header('Location: dashboard/index.php'); exit;
            }
        } else {
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
<title>تسجيل دخول — MenuPro</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,700;0,9..144,900;1,9..144,300&family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root { --p: #FF6B35; --p2: #FF8C5A; --bg: #0C0C0C; --bg2: #141414; --bg3: #1C1C1C; --bg4: #242424; --line: rgba(255,255,255,0.07); --ink: #F0EBE3; --ink2: rgba(240,235,227,0.45); --ink3: rgba(240,235,227,0.15); }
body { font-family: 'Tajawal', sans-serif; min-height: 100vh; background: var(--bg); display: flex; overflow: hidden; color: var(--ink); }
body::before { content: ''; position: fixed; inset: 0; z-index: 0; pointer-events: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='200' height='200' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E"); opacity: 0.6; }
.ambient { position: fixed; pointer-events: none; z-index: 0; }
.ambient-1 { width: 500px; height: 500px; border-radius: 50%; background: radial-gradient(circle, rgba(255,107,53,0.12) 0%, transparent 70%); top: -150px; right: 20%; animation: drift1 12s ease-in-out infinite; }
.ambient-2 { width: 400px; height: 400px; border-radius: 50%; background: radial-gradient(circle, rgba(255,107,53,0.07) 0%, transparent 70%); bottom: -100px; left: 10%; animation: drift2 15s ease-in-out infinite; }
@keyframes drift1 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-40px,30px)} }
@keyframes drift2 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(30px,-40px)} }

.left-panel { flex: 1; display: flex; flex-direction: column; justify-content: space-between; padding: 48px 52px; position: relative; overflow: hidden; border-left: 1px solid var(--line); }
@media (max-width: 900px) { .left-panel { display: none; } }
.vert-deco { position: absolute; left: 28px; top: 50%; transform: translateY(-50%) rotate(180deg); writing-mode: vertical-rl; font-family: 'Fraunces', serif; font-size: 11px; font-weight: 300; font-style: italic; letter-spacing: 3px; color: var(--ink3); white-space: nowrap; }
.brand { display: flex; align-items: center; gap: 14px; animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) both; }
.brand-mark { width: 46px; height: 46px; border-radius: 14px; background: var(--p); display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 20px rgba(255,107,53,0.45); flex-shrink: 0; }
.brand-mark svg { width: 22px; height: 22px; color: #fff; }
.brand-name { font-family: 'Fraunces', serif; font-size: 22px; font-weight: 900; color: var(--ink); }
.brand-name span { color: var(--p); }
.hero-area { flex: 1; display: flex; flex-direction: column; justify-content: center; padding-right: 20px; }
.hero-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: 3px; color: var(--p); text-transform: uppercase; margin-bottom: 18px; animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) .1s both; display: flex; align-items: center; gap: 8px; }
.hero-eyebrow::before { content: ''; width: 28px; height: 2px; background: var(--p); border-radius: 2px; flex-shrink: 0; }
.hero-heading { font-family: 'Fraunces', serif; font-size: clamp(38px, 4.5vw, 62px); font-weight: 900; line-height: 1.08; color: var(--ink); margin-bottom: 22px; animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) .18s both; }
.hero-heading em { font-style: italic; color: var(--p); font-weight: 300; }
.hero-sub { font-size: 15px; color: var(--ink2); line-height: 1.75; max-width: 380px; animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) .26s both; }
.pills { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 32px; animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) .32s both; }
.pill { display: flex; align-items: center; gap: 7px; padding: 8px 14px; background: var(--bg3); border: 1px solid var(--line); border-radius: 40px; font-size: 12px; font-weight: 600; color: var(--ink2); white-space: nowrap; transition: border-color 0.2s, color 0.2s; }
.pill:hover { border-color: rgba(255,107,53,0.3); color: var(--p2); }
.pill-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--p); flex-shrink: 0; }
.stats-row { display: flex; gap: 32px; animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) .38s both; }
.stat-num { font-family: 'Fraunces', serif; font-size: 28px; font-weight: 900; color: var(--ink); line-height: 1; }
.stat-num span { color: var(--p); }
.stat-lbl { font-size: 11px; color: var(--ink2); font-weight: 600; margin-top: 3px; }
.deco-number { position: absolute; bottom: -20px; right: -10px; font-family: 'Fraunces', serif; font-size: 220px; font-weight: 900; color: rgba(255,107,53,0.04); line-height: 1; pointer-events: none; user-select: none; }

.right-panel { width: 440px; flex-shrink: 0; background: var(--bg2); border-right: 1px solid var(--line); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 44px; position: relative; z-index: 1; }
@media (max-width: 900px) { .right-panel { width: 100%; padding: 40px 24px; } }
.right-panel::before { content: ''; position: absolute; top: 0; right: 0; left: 0; height: 3px; background: linear-gradient(90deg, transparent, var(--p), transparent); }
.form-wrap { width: 100%; }
.login-heading { margin-bottom: 36px; animation: fadeUp 0.6s cubic-bezier(0.22,1,0.36,1) .05s both; }
.login-heading-top { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.login-badge { padding: 4px 12px; background: rgba(255,107,53,0.12); border: 1px solid rgba(255,107,53,0.22); border-radius: 20px; font-size: 11px; font-weight: 700; color: var(--p); letter-spacing: 1px; }
.login-title-text { font-family: 'Fraunces', serif; font-size: 30px; font-weight: 900; color: var(--ink); line-height: 1.1; margin-bottom: 7px; }
.login-title-text em { font-style: italic; font-weight: 300; color: var(--ink2); }
.login-sub-text { font-size: 13px; color: var(--ink2); font-weight: 500; }

.error-box { display: flex; align-items: center; gap: 10px; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); border-radius: 13px; padding: 12px 16px; color: #FCA5A5; font-size: 13px; font-weight: 600; margin-bottom: 22px; animation: shake 0.4s ease, fadeUp 0.3s ease; }
.error-box svg { width: 16px; height: 16px; flex-shrink: 0; }
@keyframes shake { 0%,100%{transform:translateX(0)} 20%{transform:translateX(-6px)} 40%{transform:translateX(6px)} 60%{transform:translateX(-4px)} 80%{transform:translateX(4px)} }

.fg { margin-bottom: 16px; animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) both; }
.fg:nth-child(1){animation-delay:.12s} .fg:nth-child(2){animation-delay:.18s}
.fg label { display: block; font-size: 11px; font-weight: 700; color: var(--ink3); letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 9px; }
.input-wrap { position: relative; }
.input-icon { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--ink3); pointer-events: none; display: flex; }
.input-icon svg { width: 16px; height: 16px; }
input[type=email], input[type=password], input[type=text] { width: 100%; padding: 13px 44px 13px 44px; background: var(--bg3); border: 1.5px solid var(--line); border-radius: 14px; color: var(--ink); font-size: 14px; font-family: 'Tajawal', sans-serif; outline: none; transition: border-color 0.25s, background 0.25s, box-shadow 0.25s; }
input::placeholder { color: var(--ink3); }
input:focus { border-color: rgba(255,107,53,0.5); background: var(--bg4); box-shadow: 0 0 0 4px rgba(255,107,53,0.08); }
.pass-eye { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--ink3); padding: 4px; transition: color 0.2s; display: flex; }
.pass-eye:hover { color: var(--ink2); }
.pass-eye svg { width: 16px; height: 16px; }

.submit-btn { width: 100%; padding: 14px; background: var(--p); color: #fff; border: none; border-radius: 14px; font-size: 15px; font-weight: 800; cursor: pointer; font-family: 'Tajawal', sans-serif; margin-top: 8px; transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s; box-shadow: 0 6px 24px rgba(255,107,53,0.4); position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; gap: 9px; animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) .28s both; }
.submit-btn svg { width: 17px; height: 17px; flex-shrink: 0; }
.submit-btn::after { content: ''; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255,255,255,0.12) 0%, transparent 60%); pointer-events: none; }
.submit-btn::before { content: ''; position: absolute; top: 0; left: -100%; width: 60%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent); transform: skewX(-20deg); transition: left 0.6s; }
.submit-btn:hover::before { left: 140%; }
.submit-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 32px rgba(255,107,53,0.5); opacity: 0.95; }
.submit-btn:active { transform: translateY(0); }
.submit-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

.divider { display: flex; align-items: center; gap: 12px; margin: 22px 0 18px; animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) .34s both; }
.divider-line { flex: 1; height: 1px; background: var(--line); }
.divider-text { font-size: 11px; color: var(--ink3); font-weight: 600; }
.back-link { display: flex; align-items: center; justify-content: center; gap: 7px; color: var(--ink3); font-size: 13px; font-weight: 600; text-decoration: none; transition: color 0.2s; animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) .38s both; }
.back-link svg { width: 14px; height: 14px; }
.back-link:hover { color: var(--ink2); }
.login-footer { position: absolute; bottom: 22px; left: 0; right: 0; text-align: center; font-size: 11px; color: var(--ink3); }

@keyframes fadeUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body>

<div class="ambient ambient-1"></div>
<div class="ambient ambient-2"></div>

<div class="left-panel">
    <div class="vert-deco">منصة المنيو الرقمي الذكي</div>
    <div class="brand">
        <div class="brand-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg></div>
        <div class="brand-name">Menu<span>Pro</span></div>
    </div>
    <div class="hero-area">
        <div class="hero-eyebrow">منصة رقمية للمطاعم</div>
        <div class="hero-heading">منيو<br><em>ذكي</em><br>لمطعمك</div>
        <div class="hero-sub">حوّل قائمة طعامك إلى تجربة رقمية غامرة مع QR، عرض AR ثلاثي الأبعاد، وإدارة طلبات في الوقت الحقيقي.</div>
        <div class="pills">
            <div class="pill"><span class="pill-dot"></span>QR Code</div>
            <div class="pill"><span class="pill-dot"></span>عرض AR</div>
            <div class="pill"><span class="pill-dot"></span>طلبات لحظية</div>
            <div class="pill"><span class="pill-dot"></span>إحصائيات</div>
            <div class="pill"><span class="pill-dot"></span>كوبونات</div>
        </div>
    </div>
    <div class="stats-row">
        <div class="stat-item"><div class="stat-num">500<span>+</span></div><div class="stat-lbl">مطعم نشط</div></div>
        <div class="stat-item"><div class="stat-num">3<span>باقات</span></div><div class="stat-lbl">لكل احتياج</div></div>
        <div class="stat-item"><div class="stat-num">24<span>/7</span></div><div class="stat-lbl">دعم فني</div></div>
    </div>
    <div class="deco-number">M</div>
</div>

<div class="right-panel">
    <div class="form-wrap">
        <div class="login-heading">
            <div class="login-heading-top"><span class="login-badge">RESTAURANT</span></div>
            <div class="login-title-text">مرحباً<br><em>بعودتك</em></div>
            <div class="login-sub-text">سجّل دخول لإدارة مطعمك</div>
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
                    <input type="email" name="email" placeholder="restaurant@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                </div>
            </div>

            <div class="fg">
                <label>كلمة السر</label>
                <div class="input-wrap">
                    <span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg></span>
                    <input type="password" name="password" id="passInput" placeholder="••••••••" required>
                    <button type="button" class="pass-eye" onclick="togglePass()">
                        <svg id="eyeShow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg id="eyeHide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="submit-btn" id="submitBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                دخول للوحة التحكم
            </button>
        </form>

        <div class="divider"><div class="divider-line"></div><div class="divider-text">أو</div><div class="divider-line"></div></div>
        <a href="<?= defined('BASE_URL') ? BASE_URL : '/' ?>" class="back-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            العودة للصفحة الرئيسية
        </a>
    </div>
    <div class="login-footer">© <?= date('Y') ?> MenuPro — جميع الحقوق محفوظة</div>
</div>

<script>
function togglePass() {
    var i = document.getElementById('passInput'), s = document.getElementById('eyeShow'), h = document.getElementById('eyeHide');
    if(i.type === 'password') { i.type = 'text'; s.style.display = 'none'; h.style.display = 'block'; }
    else { i.type = 'password'; s.style.display = 'block'; h.style.display = 'none'; }
}
document.getElementById('loginForm').addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:17px;height:17px;animation:spin 1s linear infinite"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg> جاري الدخول...';
    btn.disabled = true;
});
</script>
<style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
</body>
</html>