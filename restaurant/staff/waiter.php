<?php
/**
 * waiter.php — Branch-scoped patch
 */
session_start();
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../config/csrf.php';

if(!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'waiter') {
    header('Location: login.php'); exit;
}

$rid      = $_SESSION['staff_rest_id'];
$staff_id = $_SESSION['staff_id'];

// تطبيق المنطقة الزمنية الخاصة بالمطعم
if (!function_exists('apply_timezone')) { require_once __DIR__ . '/../../src/Helpers/PriceHelper.php'; }
try { $tz_r = $pdo->prepare("SELECT timezone FROM restaurants WHERE id = ? LIMIT 1"); $tz_r->execute([$rid]); apply_timezone($pdo, $tz_r->fetchColumn() ?: 'Asia/Damascus'); } catch (Exception $e) { apply_timezone($pdo, 'Asia/Damascus'); }

// gate: شاشة النادل ميزة الباقة الاحترافية فقط
staff_feature_or_die($rid, 'staff', 'النادل — محجوب');

// branch_id من الـ DB — محمي بـ try/catch لو العمود ما موجود بعد
$branch_id   = null;
$branch_name = '';
try {
    if(!isset($_SESSION['staff_branch_id'])) {
        $sb = $pdo->prepare("SELECT branch_id FROM restaurant_staff WHERE id = ?");
        $sb->execute([$staff_id]);
        $_SESSION['staff_branch_id'] = $sb->fetchColumn() ?: null;
    }
    $branch_id = $_SESSION['staff_branch_id'];
    if($branch_id) {
        $bn = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $bn->execute([$branch_id]);
        $branch_name = $bn->fetchColumn() ?: '';
    }
} catch(Exception $e) {
    // عمود branch_id مش موجود بعد — شغّل بدون فلتر فرع
    $branch_id = null;
    $_SESSION['staff_branch_id'] = null;
}

if(isset($_GET['logout'])) { session_destroy(); header('Location: login.php'); exit; }

// AJAX polling — branch-scoped
if(isset($_GET['ajax']) && $_GET['ajax'] === 'poll') {
    header('Content-Type: application/json');
    $last_ready_id = intval($_GET['last_ready'] ?? 0);
    $last_new_id   = intval($_GET['last_new']   ?? 0);

    if($branch_id) {
        $ready = $pdo->prepare("SELECT id, table_number FROM orders WHERE restaurant_id=? AND branch_id=? AND status='ready' AND id > ? ORDER BY id DESC LIMIT 5");
        $ready->execute([$rid, $branch_id, $last_ready_id]);
        $new = $pdo->prepare("SELECT id FROM orders WHERE restaurant_id=? AND branch_id=? AND id > ? AND status IN ('ready','preparing','confirmed','pending') LIMIT 3");
        $new->execute([$rid, $branch_id, $last_new_id]);
        $rc = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id=? AND branch_id=? AND status='ready'");
        $rc->execute([$rid, $branch_id]);
    } else {
        $ready = $pdo->prepare("SELECT id, table_number FROM orders WHERE restaurant_id=? AND status='ready' AND id > ? ORDER BY id DESC LIMIT 5");
        $ready->execute([$rid, $last_ready_id]);
        $new = $pdo->prepare("SELECT id FROM orders WHERE restaurant_id=? AND id > ? AND status IN ('ready','preparing','confirmed','pending') LIMIT 3");
        $new->execute([$rid, $last_new_id]);
        $rc = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id=? AND status='ready'");
        $rc->execute([$rid]);
    }

    echo json_encode([
        'new_ready'   => $ready->fetchAll(),
        'new_orders'  => $new->fetchAll(),
        'ready_count' => $rc->fetchColumn(),
    ]);
    exit;
}

// POST — تسليم
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    csrf_require();
    if(($_POST['status'] ?? '') === 'delivered') {
        $pdo->prepare("UPDATE orders SET status='delivered', staff_id=?, updated_at=NOW() WHERE id=? AND restaurant_id=?")
            ->execute([$staff_id, $_POST['order_id'], $rid]);
        try {
            $pdo->prepare("INSERT INTO order_status_log (order_id,status,changed_at) VALUES (?,?,NOW())")
                ->execute([$_POST['order_id'], 'delivered']);
        } catch(Exception $e) {}
    }
    if(isset($_POST['ajax'])) { echo json_encode(['success'=>true]); exit; }
}

// جلب الطلبات — branch-scoped
if($branch_id) {
    $orders_stmt = $pdo->prepare("
        SELECT *, COALESCE(branch_order_number, restaurant_order_number, id) as display_num
        FROM orders
        WHERE restaurant_id=? AND branch_id=?
          AND status IN ('ready','preparing','confirmed','pending')
        ORDER BY FIELD(status,'ready','confirmed','preparing','pending'), created_at ASC
    ");
    $orders_stmt->execute([$rid, $branch_id]);
} else {
    $orders_stmt = $pdo->prepare("
        SELECT *, COALESCE(branch_order_number, restaurant_order_number, id) as display_num
        FROM orders
        WHERE restaurant_id=? AND status IN ('ready','preparing','confirmed','pending')
        ORDER BY FIELD(status,'ready','confirmed','preparing','pending'), created_at ASC
    ");
    $orders_stmt->execute([$rid]);
}
$orders = $orders_stmt->fetchAll();

// Batch-load order_items بدل N+1
$items_by_order = [];
if (!empty($orders)) {
    $order_ids = array_column($orders, 'id');
    $ph = implode(',', array_fill(0, count($order_ids), '?'));
    $items_stmt = $pdo->prepare(
        "SELECT order_id, dish_name, quantity
         FROM order_items
         WHERE order_id IN ($ph) ORDER BY id"
    );
    $items_stmt->execute($order_ids);
    foreach ($items_stmt->fetchAll() as $row) {
        $items_by_order[$row['order_id']][] = $row;
    }
}
foreach ($orders as &$o) {
    $o['items'] = $items_by_order[$o['id']] ?? [];
}
unset($o);

$ready_count   = array_sum(array_map(fn($o)=>$o['status']==='ready'?1:0, $orders));
$last_new_id   = !empty($orders) ? max(array_column($orders,'id')) : 0;
$last_ready    = array_filter($orders, fn($o)=>$o['status']==='ready');
$last_ready_id = !empty($last_ready) ? max(array_column(array_values($last_ready),'id')) : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<?= csrf_meta() ?>
<title>نادل — <?= htmlspecialchars($_SESSION['staff_rest_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
/* ===== THEME ===== */
:root{--p:#FF6B35;}
[data-theme="dark"]{
    --bg:#0A0A0A;--bg2:#111;--bg3:#1A1A1A;--bg4:#222;
    --ink:#F0EBE3;--ink2:#6B6560;--ink3:#2E2B28;
    --line:rgba(255,255,255,0.06);--bar:rgba(10,10,10,0.96);
}
[data-theme="light"]{
    --bg:#F4F1ED;--bg2:#fff;--bg3:#EDEAE6;--bg4:#E4E0DC;
    --ink:#1C1917;--ink2:#78716C;--ink3:#C8C4C0;
    --line:rgba(0,0,0,0.07);--bar:rgba(244,241,237,0.96);
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;transition:background .3s,color .3s;overflow-x:hidden;}

/* ===== TOPBAR ===== */
.topbar{
    position:sticky;top:0;z-index:200;
    background:var(--bar);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-bottom:1px solid var(--line);
    padding:0 16px;height:56px;
    display:flex;align-items:center;gap:12px;
    transition:background .3s,border-color .3s;
}
.topbar-stripe{position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,#3B82F6,transparent);}
.role-pill{
    display:flex;align-items:center;gap:6px;
    background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.22);
    color:#3B82F6;padding:4px 11px;border-radius:20px;
    font-size:11px;font-weight:800;flex-shrink:0;
}
.rest-name{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:var(--ink);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.topbar-right{display:flex;align-items:center;gap:8px;flex-shrink:0;}
.ready-pill{
    display:flex;align-items:center;gap:6px;
    background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.22);
    color:#22C55E;padding:5px 12px;border-radius:20px;
    font-size:12px;font-weight:800;
    animation:readyPulse 2s infinite;
}
.ready-pill svg{width:13px;height:13px;}
@keyframes readyPulse{0%,100%{box-shadow:0 0 0 0 transparent;}50%{box-shadow:0 0 0 6px rgba(34,197,94,.1);}}
.icon-btn{
    width:36px;height:36px;border-radius:50%;
    background:var(--bg3);border:1px solid var(--line);
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;transition:background .2s,transform .3s;color:var(--ink2);
    text-decoration:none;font-size:12px;font-weight:700;font-family:'Tajawal',sans-serif;
}
.icon-btn svg{width:15px;height:15px;}
.icon-btn:active{transform:scale(.9);}
.theme-toggle .icon-moon{display:block;}.theme-toggle .icon-sun{display:none;}
[data-theme="light"] .theme-toggle .icon-moon{display:none;}[data-theme="light"] .theme-toggle .icon-sun{display:block;}
.logout-btn{padding:0 13px;border-radius:10px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.15);color:#EF4444;width:auto;}

/* ===== CONTENT ===== */
.content{padding:14px 16px 80px;}

.section-head{
    display:flex;align-items:center;gap:10px;
    margin-bottom:14px;
}
.section-head h2{font-family:'Fraunces',serif;font-size:18px;font-weight:900;color:var(--ink);}
.section-head::after{content:'';flex:1;height:1px;background:var(--line);}

/* ===== READY BANNER ===== */
.ready-banner{
    background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);
    border-radius:16px;padding:14px 16px;margin-bottom:16px;
    display:flex;align-items:center;gap:12px;
    animation:bannerIn .4s cubic-bezier(.22,1,.36,1) both;
}
@keyframes bannerIn{from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:none;}}
.ready-banner-icon{font-size:28px;flex-shrink:0;}
.ready-banner-text{}
.ready-banner-title{font-size:14px;font-weight:800;color:#22C55E;}
.ready-banner-sub{font-size:12px;color:var(--ink2);margin-top:2px;}

/* ===== ORDER CARDS ===== */
.orders-list{display:flex;flex-direction:column;gap:10px;}

.ocard{
    background:var(--bg2);border:1px solid var(--line);
    border-radius:18px;overflow:hidden;position:relative;
    transition:background .3s,border-color .3s,transform .2s;
    animation:cardIn .35s cubic-bezier(.22,1,.36,1) both;
}
@keyframes cardIn{from{opacity:0;transform:translateY(12px) scale(.97);}to{opacity:1;transform:none;}}

/* Side stripe per status */
.ocard::after{
    content:'';position:absolute;right:0;top:0;bottom:0;width:3px;border-radius:0 18px 18px 0;
}
.ocard.st-ready{border-color:rgba(34,197,94,.25);animation:cardIn .35s cubic-bezier(.22,1,.36,1) both,readyCardPulse 2.5s infinite;}
.ocard.st-ready::after{background:#22C55E;}
.ocard.st-confirmed::after{background:#3B82F6;}
.ocard.st-preparing::after{background:#8B5CF6;}
.ocard.st-pending::after{background:#F59E0B;}
@keyframes readyCardPulse{0%,100%{box-shadow:0 0 0 0 transparent;}50%{box-shadow:0 0 0 5px rgba(34,197,94,.08);}}

.ocard-head{
    padding:12px 16px;display:flex;align-items:center;gap:8px;
    border-bottom:1px solid var(--line);
    transition:border-color .3s;
}
.ocard-num{font-family:'Fraunces',serif;font-size:16px;font-weight:700;color:var(--ink);}
.table-chip{background:rgba(255,107,53,.1);border:1px solid rgba(255,107,53,.2);color:var(--p);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;}
.status-chip{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;}
.sc-ready{background:rgba(34,197,94,.12);color:#22C55E;}
.sc-confirmed{background:rgba(59,130,246,.12);color:#3B82F6;}
.sc-preparing{background:rgba(139,92,246,.12);color:#8B5CF6;}
.sc-pending{background:rgba(245,158,11,.12);color:#F59E0B;}
.ocard-time{font-size:11px;color:var(--ink2);margin-right:auto;}

.ocard-body{padding:11px 16px 13px;display:flex;align-items:center;justify-content:space-between;gap:12px;}
.ocard-info{flex:1;min-width:0;}
.ocard-dishes{font-size:13px;font-weight:600;color:var(--ink);line-height:1.5;margin-bottom:3px;}
.ocard-customer{font-size:12px;color:var(--ink2);}

/* Deliver button */
.deliver-btn{
    padding:11px 18px;background:#22C55E;color:#fff;
    border:none;border-radius:12px;font-size:13px;font-weight:800;
    cursor:pointer;font-family:'Tajawal',sans-serif;
    display:flex;align-items:center;gap:7px;flex-shrink:0;
    transition:transform .2s,opacity .2s;
    box-shadow:0 4px 14px rgba(34,197,94,.35);
}
.deliver-btn svg{width:14px;height:14px;}
.deliver-btn:active{transform:scale(.96);}
.deliver-btn:disabled{opacity:.5;cursor:not-allowed;box-shadow:none;}

/* Waiting label for non-ready */
.waiting-lbl{font-size:12px;color:var(--ink2);font-weight:600;flex-shrink:0;}

/* ===== EMPTY ===== */
.empty{text-align:center;padding:64px 20px;color:var(--ink2);}
.empty span{font-size:52px;display:block;margin-bottom:12px;}
.empty p{font-size:14px;font-weight:600;}

/* ===== LIVE BAR ===== */
.live-bar{display:flex;align-items:center;justify-content:center;gap:7px;font-size:11px;color:var(--ink2);font-weight:600;margin-bottom:14px;}
.live-dot{width:6px;height:6px;background:#22C55E;border-radius:50%;animation:blink 1.5s infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}

/* ===== TOAST ===== */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--bg2);border:1px solid var(--line);color:var(--ink);padding:10px 20px;border-radius:25px;font-size:13px;font-weight:700;z-index:999;opacity:0;transition:all .35s cubic-bezier(.34,1.56,.64,1);white-space:nowrap;pointer-events:none;}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0);}

/* Sound FAB */
.sound-fab{position:fixed;bottom:20px;left:16px;width:44px;height:44px;border-radius:50%;background:var(--bg2);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:300;font-size:18px;box-shadow:0 4px 16px rgba(0,0,0,.2);transition:transform .2s;}
.sound-fab:active{transform:scale(.9);}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-stripe"></div>
    <div class="role-pill">🧑‍💼 نادل</div>
    <div class="rest-name"><?= htmlspecialchars($_SESSION['staff_rest_name']) ?></div>
    <div class="topbar-right">
        <?php if($ready_count > 0): ?>
        <div class="ready-pill" id="readyPill">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/></svg>
            <?= $ready_count ?> جاهز
        </div>
        <?php else: ?>
        <div class="ready-pill" id="readyPill" style="display:none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/></svg>
            <span id="readyCount"><?= $ready_count ?></span> جاهز
        </div>
        <?php endif; ?>
        <button class="icon-btn theme-toggle" onclick="toggleTheme()">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        </button>
        <a href="?logout=1" class="icon-btn logout-btn">خروج</a>
    </div>
</div>

<div class="content">

    <?php if($ready_count > 0): ?>
    <div class="ready-banner">
        <div class="ready-banner-icon">🍽️</div>
        <div class="ready-banner-text">
            <div class="ready-banner-title"><?= $ready_count === 1 ? 'طلب جاهز للتوصيل!' : $ready_count . ' طلبات جاهزة!' ?></div>
            <div class="ready-banner-sub">اضغط "تم التسليم" بعد إيصال الطلب للزبون</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="live-bar"><div class="live-dot"></div> يتجدد تلقائياً</div>

    <?php if(empty($orders)): ?>
    <div class="empty">
        <span>✅</span>
        <p>لا توجد طلبات نشطة الآن</p>
    </div>
    <?php else: ?>
    <div class="section-head"><h2>الطلبات النشطة</h2></div>
    <div class="orders-list" id="ordersList">
        <?php
        $status_labels = ['ready'=>'جاهز','preparing'=>'بالتحضير','confirmed'=>'مؤكد','pending'=>'معلق'];
        foreach($orders as $idx => $o):
        ?>
        <div class="ocard st-<?= $o['status'] ?>" id="oc-<?= $o['id'] ?>" style="animation-delay:<?= min($idx*.05,.3) ?>s">
            <div class="ocard-head">
                <div class="ocard-num">#<?= $o['display_num'] ?? $o['id'] ?></div>
                <div class="table-chip">طاولة <?= htmlspecialchars($o['table_number']) ?></div>
                <div class="status-chip sc-<?= $o['status'] ?>"><?= $status_labels[$o['status']] ?></div>
                <div class="ocard-time"><?= date('H:i', strtotime($o['created_at'])) ?></div>
            </div>
            <div class="ocard-body">
                <div class="ocard-info">
                    <div class="ocard-dishes">
                        <?= implode(' · ', array_map(fn($i) => htmlspecialchars($i['dish_name']).'×'.$i['quantity'], $o['items'])) ?>
                    </div>
                    <?php if($o['customer_name']): ?>
                    <div class="ocard-customer">👤 <?= htmlspecialchars($o['customer_name']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if($o['status'] === 'ready'): ?>
                <button class="deliver-btn" onclick="markDelivered(<?= $o['id'] ?>, this)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    تم التسليم
                </button>
                <?php else: ?>
                <div class="waiting-lbl">⏳ بالانتظار</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<button class="sound-fab" onclick="testSound()">🔔</button>
<div class="toast" id="toast"></div>

<script>
// ===== Theme =====
const THEME_KEY = 'waiter_theme';
(function(){ document.documentElement.setAttribute('data-theme', localStorage.getItem(THEME_KEY)||'dark'); })();
function toggleTheme(){
    const n = document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',n);
    localStorage.setItem(THEME_KEY,n);
}

// ===== Toast =====
function showToast(msg){
    const t=document.getElementById('toast');
    t.textContent=msg; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'),2800);
}

// ===== Audio =====
let audioCtx=null, userInteracted=false, notifGranted=false;

['click','touchstart','keydown'].forEach(ev=>
    document.addEventListener(ev,()=>{
        userInteracted=true;
        if(!audioCtx){try{audioCtx=new(window.AudioContext||window.webkitAudioContext)();}catch(e){}}
        if(audioCtx&&audioCtx.state==='suspended') audioCtx.resume();
        if(sessionStorage.getItem('pendingBeep')){ sessionStorage.removeItem('pendingBeep'); setTimeout(()=>_beep('ready'),100); }
    },{passive:true})
);

if('Notification' in window){
    if(Notification.permission==='granted') notifGranted=true;
    else if(Notification.permission!=='denied'){
        Notification.requestPermission().then(p=>{ notifGranted=(p==='granted'); if(p==='granted') showToast('✅ الإشعارات مفعّلة'); });
    }
}

function _beep(type='ready'){
    if(!audioCtx) return;
    // للنادل: صوت واضح مميز
    const notes = type==='ready'
        ? [[784,0,.2],[987,.15,.2],[1175,.3,.45]]   // Sol Si Re
        : [[880,0,.3],[1100,.2,.6]];
    notes.forEach(([f,s,e])=>{
        const o=audioCtx.createOscillator(),g=audioCtx.createGain();
        o.connect(g); g.connect(audioCtx.destination);
        o.frequency.value=f; o.type='sine';
        g.gain.setValueAtTime(0.45,audioCtx.currentTime+s);
        g.gain.exponentialRampToValueAtTime(0.001,audioCtx.currentTime+e);
        o.start(audioCtx.currentTime+s); o.stop(audioCtx.currentTime+e);
    });
}

function playSound(type='ready'){
    if(!userInteracted){ sessionStorage.setItem('pendingBeep','1'); return; }
    if(!audioCtx){try{audioCtx=new(window.AudioContext||window.webkitAudioContext)();}catch(e){return;}}
    if(audioCtx.state==='suspended') audioCtx.resume().then(()=>_beep(type));
    else _beep(type);
}

function testSound(){ userInteracted=true; playSound('ready'); showToast('🔔 الصوت يعمل!'); }

function sendNotif(title,body){
    if(!notifGranted) return;
    try{ const n=new Notification(title,{body,tag:'waiter-ready',renotify:true,requireInteraction:true}); n.onclick=()=>{window.focus();n.close();}; }catch(e){}
}

// CSRF token (read once)
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ===== Deliver =====
function markDelivered(orderId, btn){
    btn.disabled=true; btn.innerHTML='...';
    fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF_TOKEN},
        body:`order_id=${orderId}&status=delivered&ajax=1&_csrf_token=${encodeURIComponent(CSRF_TOKEN)}`})
    .then(r=>r.json())
    .then(d=>{
        if(d.success){
            const card = document.getElementById('oc-'+orderId);
            card.style.transition='opacity .4s,transform .4s';
            card.style.opacity='0'; card.style.transform='translateX(20px)';
            showToast('✅ طلب #'+orderId+' تم تسليمه');
            setTimeout(()=>card.remove(), 400);
        } else { btn.disabled=false; btn.innerHTML='تم التسليم'; }
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='تم التسليم'; });
}

// ===== Polling =====
let lastReadyId = <?= $last_ready_id ?>;
let lastNewId   = <?= $last_new_id ?>;

setInterval(()=>{
    fetch(`?ajax=poll&last_ready=${lastReadyId}&last_new=${lastNewId}`)
    .then(r=>r.json())
    .then(data=>{
        // طلبات جاهزة جديدة
        if(data.new_ready && data.new_ready.length > 0){
            const newest = Math.max(...data.new_ready.map(o=>o.id));
            if(newest > lastReadyId){
                lastReadyId = newest;
                const tables = data.new_ready.map(o=>'طاولة '+o.table_number).join('، ');
                playSound('ready');
                sendNotif('🍽️ طلب جاهز!', tables + ' — يمكن التسليم');
                showToast('🍽️ طلب جاهز: ' + tables);
                setTimeout(()=>location.reload(), 1000);
            }
        }
        // تحديث عداد الجاهز
        const pill = document.getElementById('readyPill');
        if(pill){
            if(data.ready_count > 0){
                pill.style.display='flex';
                const cnt = pill.querySelector('#readyCount');
                if(cnt) cnt.textContent=data.ready_count;
            } else {
                pill.style.display='none';
            }
        }
    })
    .catch(()=>{});
}, 8000);
</script>
</body>
</html>