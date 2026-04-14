<?php
require_once '../config/database.php';

$slug     = $_GET['slug'] ?? '';
$order_id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE slug=? AND is_active=1");
$stmt->execute([$slug]);
$restaurant = $stmt->fetch();
if(!$restaurant) { http_response_code(404); die('غير موجود'); }


// ===== العملة =====
$cur_symbol    = $restaurant['currency_symbol']    ?? '$';
$cur_symbol_en = $restaurant['currency_symbol_en'] ?? $restaurant['currency_symbol'] ?? '$';
$cur_decimals  = intval($restaurant['currency_decimals'] ?? 2);
$cur_prefix    = in_array($cur_symbol, ['$', '\u20ac', '\u20ba']);
$cur_prefix_en = in_array($cur_symbol_en, ['$', '\u20ac', '\u20ba']);
if(!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $symbol . ' ' . $formatted;
    }
}


$stmt = $pdo->prepare("SELECT o.* FROM orders o WHERE o.id=? AND o.restaurant_id=?");
$stmt->execute([$order_id, $restaurant['id']]);
$order = $stmt->fetch();
if(!$order) { http_response_code(404); die('الطلب غير موجود'); }
$display_order_num = $order['restaurant_order_number'] ?? $order_id;
// ===== العملة =====
$cur_symbol   = $restaurant['currency_symbol']   ?? '$';
$cur_decimals = intval($restaurant['currency_decimals'] ?? 2);
$cur_prefix   = in_array($cur_symbol, ['$', '€', '₺']);
if(!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $symbol . ' ' . $formatted;
    }
}


// ضرائب الطلب
$order_taxes = [];
if(!empty($order['tax_details'])) {
    $order_taxes = json_decode($order['tax_details'], true) ?: [];
}

$items = $pdo->prepare("SELECT oi.*, COALESCE(oi.dish_name_en, d.name_en, '') as dish_name_en_resolved
    FROM order_items oi
    LEFT JOIN dishes d ON d.id = oi.dish_id
    WHERE oi.order_id=? ORDER BY oi.id");
$items->execute([$order_id]);
$items = $items->fetchAll();

// ===== حساب وقت التحضير المتوقع =====
// 1. وقت هذا الطلب (أطول طبق + دقيقتين لكل وحدة إضافية)
$max_prep = 0;
$total_items_count = 0;
foreach($items as $it) {
    if(empty($it['dish_id'])) continue;
    $dp = $pdo->prepare("SELECT COALESCE(prep_time,15) FROM dishes WHERE id=?");
    $dp->execute([$it['dish_id']]);
    $pt = intval($dp->fetchColumn() ?: 15);
    if($pt > $max_prep) $max_prep = $pt;
    $total_items_count += intval($it['quantity']);
}
$extra = min(($total_items_count - 1) * 2, 10);
$this_order_time = $max_prep > 0 ? $max_prep + $extra : 15;

// 2. الطلبات اللي قبله وهي لسا في الكيو
$queue_stmt = $pdo->prepare("
    SELECT o.id, o.created_at
    FROM orders o
    WHERE o.restaurant_id = ?
      AND o.id != ?
      AND o.status IN ('pending','confirmed','preparing')
      AND o.created_at < ?
    ORDER BY o.created_at ASC
");
$queue_stmt->execute([$restaurant['id'], $order_id, $order['created_at']]);
$queue_orders = $queue_stmt->fetchAll();
$queue_count  = count($queue_orders);

// 3. حساب الوقت المتبقي للطلبات السابقة
$queue_wait = 0;
foreach($queue_orders as $qo) {
    $qi = $pdo->prepare("SELECT dish_id, quantity FROM order_items WHERE order_id=? AND dish_id IS NOT NULL");
    $qi->execute([$qo['id']]);
    $q_items = $qi->fetchAll();
    $q_max = 0; $q_count = 0;
    foreach($q_items as $qi_row) {
        $qd = $pdo->prepare("SELECT COALESCE(prep_time,15) FROM dishes WHERE id=?");
        $qd->execute([$qi_row['dish_id']]);
        $qpt = intval($qd->fetchColumn() ?: 15);
        if($qpt > $q_max) $q_max = $qpt;
        $q_count += intval($qi_row['quantity']);
    }
    $q_extra = min(($q_count - 1) * 2, 10);
    $q_total = $q_max > 0 ? $q_max + $q_extra : 15;
    $q_age   = (time() - strtotime($qo['created_at'])) / 60;
    $q_left  = max(0, $q_total - $q_age);
    $queue_wait += $q_left;
}

// 4. الوقت الإجمالي = وقت الطلبات قبله (max 60) + وقت طلبه
$queue_wait     = min($queue_wait, 60);
$estimated_time = round($this_order_time + $queue_wait);
$order_age_mins = (time() - strtotime($order['created_at'])) / 60;
$time_remaining = max(0, round($estimated_time - $order_age_mins));
$progress_pct   = min(100, round(($order_age_mins / $estimated_time) * 100));

$steps_map = [
    'pending'   => ['label_ar'=>'استلمنا طلبك',  'label_en'=>'Order Received', 'icon'=>'📥', 'step'=>1],
    'confirmed' => ['label_ar'=>'تم التأكيد',     'label_en'=>'Confirmed',      'icon'=>'✅', 'step'=>2],
    'preparing' => ['label_ar'=>'جاري التحضير',   'label_en'=>'Preparing',      'icon'=>'👨‍🍳','step'=>3],
    'ready'     => ['label_ar'=>'طلبك جاهز!',     'label_en'=>'Ready!',         'icon'=>'🍽️','step'=>4],
    'delivered' => ['label_ar'=>'تم التسليم',      'label_en'=>'Delivered',      'icon'=>'✔️','step'=>5],
];
$current  = $steps_map[$order['status']] ?? $steps_map['pending'];
$is_final = in_array($order['status'], ['delivered','cancelled']);

$rated = false;
if($order['status'] === 'delivered') {
    $rchk = $pdo->prepare("SELECT id FROM order_ratings WHERE order_id=?");
    $rchk->execute([$order_id]);
    $rated = (bool)$rchk->fetch();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تتبع طلب #<?= $display_order_num ?></title>
<script>(function(){ var t=localStorage.getItem('menu_theme_<?= $slug ?>')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root{--p:#FF6B35;--s:#F7C59F;}
[data-theme="dark"]{--bg:#080808;--bg2:#111111;--bg3:#1a1a1a;--bg4:#222222;--ink:#F5F0E8;--ink2:#6B6560;--ink3:#3A3530;--line:rgba(245,240,232,0.06);--bar-bg:rgba(8,8,8,0.95);}
[data-theme="light"]{--bg:#F7F5F2;--bg2:#FFFFFF;--bg3:#EEEAE6;--bg4:#E4E0DC;--ink:#1A1714;--ink2:#7A7570;--ink3:#B8B4B0;--line:rgba(26,23,20,0.08);--bar-bg:rgba(247,245,242,0.96);}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--ink);max-width:480px;margin:0 auto;min-height:100vh;padding-bottom:48px;transition:background .3s,color .3s;}
body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");pointer-events:none;z-index:9999;}

/* HERO */
.hero{position:relative;padding:0 0 30px;background:var(--bg2);border-bottom:1px solid var(--line);text-align:center;overflow:hidden;transition:background .3s,border-color .3s;}
.hero-orb{position:absolute;border-radius:50%;filter:blur(55px);pointer-events:none;}
.hero-orb-1{width:260px;height:260px;background:var(--p);opacity:.12;top:-100px;right:-60px;animation:orbDrift 10s ease-in-out infinite;}
.hero-orb-2{width:180px;height:180px;background:var(--s);opacity:.1;bottom:-70px;left:-50px;animation:orbDrift 8s ease-in-out infinite reverse;}
@keyframes orbDrift{0%,100%{transform:translate(0,0) scale(1);}50%{transform:translate(14px,-14px) scale(1.08);}}

.hero-topbar{display:flex;justify-content:space-between;align-items:center;padding:14px 16px 0;position:relative;z-index:2;}
.order-num-badge{display:flex;align-items:center;gap:5px;background:var(--bg3);border:1px solid var(--line);padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;color:var(--ink2);}
.order-num-badge svg{width:12px;height:12px;}
.topbar-btns{display:flex;gap:8px;align-items:center;}
.icon-btn{width:36px;height:36px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .2s,transform .3s;font-size:12px;font-weight:800;font-family:'Tajawal',sans-serif;}
.icon-btn:active{transform:scale(.9);}
.icon-btn svg{width:15px;height:15px;}
.icon-btn.en-active{background:var(--p);color:#fff;border-color:transparent;}
.icon-moon{display:block;}.icon-sun{display:none;}
[data-theme="light"] .icon-moon{display:none;}[data-theme="light"] .icon-sun{display:block;}

/* STATUS ORB */
.status-orb-wrap{position:relative;z-index:2;margin:20px auto 16px;width:96px;height:96px;}
.status-orb{width:96px;height:96px;border-radius:50%;background:linear-gradient(135deg,var(--p),var(--s));display:flex;align-items:center;justify-content:center;font-size:42px;box-shadow:0 8px 32px color-mix(in srgb,var(--p) 40%,transparent);}
.status-orb.pulsing{animation:orbPulse 2s infinite;}
@keyframes orbPulse{0%,100%{box-shadow:0 8px 32px color-mix(in srgb,var(--p) 40%,transparent);}50%{box-shadow:0 8px 48px color-mix(in srgb,var(--p) 55%,transparent),0 0 0 14px color-mix(in srgb,var(--p) 8%,transparent);}}
.status-orb.cancelled{background:linear-gradient(135deg,#EF4444,#DC2626);box-shadow:0 8px 32px rgba(239,68,68,.4);animation:none;}
.status-ring{position:absolute;inset:-6px;border-radius:50%;border:2px solid transparent;border-top-color:var(--p);border-right-color:color-mix(in srgb,var(--p) 40%,transparent);animation:spinRing 2s linear infinite;}
.no-spin .status-ring{display:none;}
@keyframes spinRing{to{transform:rotate(360deg);}}
.status-label{position:relative;z-index:2;font-family:'Fraunces',serif;font-size:24px;font-weight:900;color:var(--ink);letter-spacing:-0.4px;margin-bottom:12px;}
.meta-chips{position:relative;z-index:2;display:flex;justify-content:center;flex-wrap:wrap;gap:7px;padding:0 16px;}
.meta-chip{display:flex;align-items:center;gap:5px;background:var(--bg3);border:1px solid var(--line);padding:5px 13px;border-radius:20px;font-size:12px;font-weight:600;color:var(--ink2);}
.meta-chip svg{width:11px;height:11px;}

/* CONTENT */
.content{padding:16px 16px 24px;}
.section-label{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:1.2px;margin:0 0 12px;}
.section-label::after{content:'';flex:1;height:1px;background:var(--line);}

/* STEPS CARD */
.steps-card{background:var(--bg2);border-radius:18px;border:1px solid var(--line);overflow:hidden;margin-bottom:14px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;transition:background .3s,border-color .3s;}
@keyframes cardIn{from{opacity:0;transform:translateY(14px) scale(.97);}to{opacity:1;transform:none;}}
.steps-inner{padding:18px 20px;}
.step{display:flex;align-items:flex-start;gap:14px;position:relative;padding-bottom:22px;}
.step:last-child{padding-bottom:0;}
.step::before{content:'';position:absolute;right:19px;top:42px;width:2px;height:calc(100% - 22px);background:var(--line);border-radius:2px;}
.step:last-child::before{display:none;}
.step.done::before{background:linear-gradient(to bottom,#22C55E,var(--line));}
.step.active::before{background:linear-gradient(to bottom,var(--p),var(--line));}
.step-circle{width:40px;height:40px;border-radius:50%;background:var(--bg3);border:2px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;z-index:1;transition:all .4s cubic-bezier(.34,1.2,.64,1);}
.step.done .step-circle{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.4);}
.step.active .step-circle{background:var(--p);border-color:transparent;box-shadow:0 4px 16px color-mix(in srgb,var(--p) 45%,transparent);animation:stepBounce 1.8s ease-in-out infinite;}
@keyframes stepBounce{0%,100%{transform:scale(1);}50%{transform:scale(1.1);}}
.step-info{flex:1;padding-top:7px;}
.step-title{font-size:14px;font-weight:700;color:var(--ink2);transition:color .3s;}
.step.done .step-title{color:#22C55E;}
.step.active .step-title{color:var(--ink);font-size:15px;}
.step-now{display:inline-flex;align-items:center;gap:4px;margin-top:4px;font-size:11px;font-weight:700;color:var(--p);}
.step-now-dot{width:6px;height:6px;background:var(--p);border-radius:50%;animation:blink 1.5s infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}

/* REFRESH BAR */
.refresh-bar{display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;font-size:12px;color:var(--ink2);font-weight:600;margin-bottom:14px;}
.refresh-dot{width:7px;height:7px;background:#22C55E;border-radius:50%;animation:blink 1.5s infinite;}

/* CANCELLED */
.cancelled-card{background:var(--bg2);border-radius:18px;border:1px solid rgba(239,68,68,.2);padding:30px 20px;text-align:center;margin-bottom:14px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;}
.cancelled-icon-wrap{width:64px;height:64px;border-radius:20px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;}
.cancelled-title{font-family:'Fraunces',serif;font-size:18px;font-weight:700;color:#EF4444;margin-bottom:6px;}
.cancelled-sub{font-size:13px;color:var(--ink2);font-weight:500;}

/* ITEMS CARD */
.items-card{background:var(--bg2);border-radius:18px;border:1px solid var(--line);overflow:hidden;margin-bottom:14px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both .1s;transition:background .3s,border-color .3s;}
.card-title{padding:12px 18px;border-bottom:1px solid var(--line);font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:1.2px;display:flex;align-items:center;gap:8px;}
.card-title::after{content:'';flex:1;height:1px;background:var(--line);}
.order-item{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid var(--line);gap:10px;transition:border-color .3s;}
.order-item:last-child{border-bottom:none;}
.item-qty-badge{width:26px;height:26px;border-radius:7px;background:color-mix(in srgb,var(--p) 12%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);color:var(--p);font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.item-info{flex:1;min-width:0;}
.item-name{font-family:'Fraunces',serif;font-size:14px;font-weight:700;color:var(--ink);letter-spacing:-0.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.item-unit{font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px;}
.item-price{font-family:'Fraunces',serif;font-size:15px;font-weight:900;color:var(--p);flex-shrink:0;}
.notes-row{padding:12px 18px;border-top:1px solid var(--line);display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--ink2);font-weight:500;}
.notes-row svg{width:14px;height:14px;flex-shrink:0;margin-top:1px;color:var(--ink3);}
.total-row{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-top:1.5px solid var(--line);}
.total-label{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:var(--ink);}
.total-amount{font-family:'Fraunces',serif;font-size:24px;font-weight:900;color:var(--p);}

/* ACTION BUTTONS */
.action-btns{display:flex;flex-direction:column;gap:9px;margin-bottom:14px;}
.btn-primary{display:flex;align-items:center;justify-content:center;gap:8px;padding:15px;background:var(--p);color:#fff;border-radius:16px;font-size:15px;font-weight:800;text-decoration:none;box-shadow:0 4px 18px color-mix(in srgb,var(--p) 35%,transparent);transition:transform .2s;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both .2s;}
.btn-primary:active{transform:scale(.98);}
.btn-primary svg{width:18px;height:18px;}
.btn-secondary{display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;background:var(--bg3);border:1.5px solid var(--line);color:var(--ink2);border-radius:16px;font-size:14px;font-weight:700;text-decoration:none;transition:background .2s,border-color .3s;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both .25s;}
.btn-secondary:active{background:var(--bg4);}
.btn-secondary svg{width:16px;height:16px;}

/* PREP TIME CARD */
.prep-card{background:var(--bg2);border-radius:18px;border:1.5px solid rgba(245,158,11,0.25);overflow:hidden;margin-bottom:14px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both .05s;transition:background .3s,border-color .3s;}
.prep-card-head{display:flex;align-items:center;gap:10px;padding:14px 18px;background:rgba(245,158,11,0.06);border-bottom:1px solid rgba(245,158,11,0.15);}
.prep-icon{width:36px;height:36px;border-radius:10px;background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.2);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.prep-title{font-size:13px;font-weight:800;color:#F59E0B;flex:1;}
.prep-body{padding:16px 18px;}
.prep-time-big{display:flex;align-items:baseline;gap:6px;margin-bottom:12px;}
.prep-time-num{font-family:'Fraunces',serif;font-size:42px;font-weight:900;color:var(--ink);letter-spacing:-1px;line-height:1;}
.prep-time-unit{font-size:14px;font-weight:700;color:var(--ink2);}
.prep-progress-wrap{background:var(--bg3);border-radius:20px;height:8px;overflow:hidden;margin-bottom:8px;}
.prep-progress-fill{height:100%;border-radius:20px;background:linear-gradient(90deg,#F59E0B,#FF6B35);transition:width 1s ease;animation:progressGrow 1s cubic-bezier(.22,1,.36,1) both;}
@keyframes progressGrow{from{width:0%!important;}to{}}
.prep-status-row{display:flex;justify-content:space-between;align-items:center;font-size:11px;font-weight:700;color:var(--ink2);}
.prep-pulsedot{width:6px;height:6px;border-radius:50%;background:#F59E0B;display:inline-block;animation:blink 1.5s infinite;margin-left:4px;}
</style>
</head>
<body>

<!-- HERO -->
<div class="hero">
    <div class="hero-orb hero-orb-1"></div>
    <div class="hero-orb hero-orb-2"></div>
    <div class="hero-topbar">
        <div class="order-num-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span id="orderNumText">طلب #<?= $display_order_num ?></span>
        </div>
        <div class="topbar-btns">
            <button class="icon-btn" id="langBtn" onclick="toggleLang()">EN</button>
            <button class="icon-btn" onclick="toggleTheme()">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </button>
        </div>
    </div>

    <div class="status-orb-wrap <?= $is_final ? 'no-spin' : '' ?>">
        <?php if(!$is_final): ?><div class="status-ring"></div><?php endif; ?>
        <div class="status-orb <?= $order['status']==='cancelled'?'cancelled':($is_final?'':'pulsing') ?>">
            <?= $order['status']==='cancelled' ? '✕' : $current['icon'] ?>
        </div>
    </div>

    <div class="status-label" id="statusLabel">
        <?= $order['status']==='cancelled' ? 'تم إلغاء الطلب' : $current['label_ar'] ?>
    </div>

    <div class="meta-chips">
        <div class="meta-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
            <span id="tableChipText">طاولة</span> <?= htmlspecialchars($order['table_number']) ?>
        </div>
        <?php if($order['customer_name']): ?>
        <div class="meta-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars($order['customer_name']) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- CONTENT -->
<div class="content">

    <?php if($order['status']==='cancelled'): ?>
    <div class="cancelled-card">
        <div class="cancelled-icon-wrap">😔</div>
        <div class="cancelled-title" id="cancelledTitle">تم إلغاء هذا الطلب</div>
        <div class="cancelled-sub" id="cancelledSub">يمكنك تقديم طلب جديد من المنيو</div>
    </div>

    <?php else: ?>
    <div class="section-label" id="lblStage">مرحلة طلبك</div>
    <div class="steps-card">
        <div class="steps-inner">
            <?php
            $steps_list = [
                'pending'   => ['ar'=>'استلمنا طلبك', 'en'=>'Order Received', 'icon'=>'📥'],
                'confirmed' => ['ar'=>'تم التأكيد',    'en'=>'Confirmed',      'icon'=>'✅'],
                'preparing' => ['ar'=>'جاري التحضير',  'en'=>'Preparing',      'icon'=>'👨‍🍳'],
                'ready'     => ['ar'=>'طلبك جاهز!',    'en'=>'Ready!',         'icon'=>'🍽️'],
                'delivered' => ['ar'=>'تم التسليم',     'en'=>'Delivered',      'icon'=>'✔️'],
            ];
            $reached = false;
            foreach($steps_list as $key => $step):
                $is_current = ($key === $order['status']);
                $is_done    = !$reached && !$is_current;
                if($is_current) $reached = true;
                $cls = $is_current ? 'active' : ($is_done ? 'done' : '');
            ?>
            <div class="step <?= $cls ?>" data-ar="<?= $step['ar'] ?>" data-en="<?= $step['en'] ?>">
                <div class="step-circle"><?= $step['icon'] ?></div>
                <div class="step-info">
                    <div class="step-title"><?= $step['ar'] ?></div>
                    <?php if($is_current): ?>
                    <div class="step-now"><div class="step-now-dot"></div><span id="nowText">الآن</span></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if(!$is_final): ?>
    <div class="refresh-bar">
        <div class="refresh-dot"></div>
        <span id="refreshText">يتحدث تلقائياً كل 10 ثوانٍ</span>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if(in_array($order['status'], ['confirmed','preparing','pending']) && $estimated_time > 0): ?>
    <div class="prep-card" id="prepCard">
        <div class="prep-card-head">
            <div class="prep-icon">⏱️</div>
            <div class="prep-title" id="prepTitle">الوقت المتوقع للتحضير</div>
        </div>
        <div class="prep-body">
            <?php if($order['status'] === 'preparing' && $time_remaining > 0): ?>
            <div class="prep-time-big">
                <span class="prep-time-num" id="prepCountdown"><?= $time_remaining ?></span>
                <span class="prep-time-unit" id="prepUnit">دقيقة متبقية</span>
            </div>
            <div class="prep-progress-wrap">
                <div class="prep-progress-fill" id="prepProgress" style="width:<?= $progress_pct ?>%"></div>
            </div>
            <div class="prep-status-row">
                <span><span class="prep-pulsedot"></span><span id="prepNowText">جاري التحضير الآن</span></span>
                <span id="prepTotalText">الإجمالي: <?= $estimated_time ?> دقيقة</span>
            </div>
            <?php elseif($order['status'] === 'preparing' && $time_remaining == 0): ?>
            <div class="prep-time-big">
                <span class="prep-time-num" style="color:#22C55E;">🍽️</span>
                <span class="prep-time-unit" id="prepReadySoon">طلبك على وشك يكون جاهز!</span>
            </div>
            <?php else: ?>
            <div class="prep-time-big">
                <span class="prep-time-num"><?= $estimated_time ?></span>
                <span class="prep-time-unit" id="prepUnit">دقيقة تقريباً</span>
            </div>
            <div style="font-size:11px;color:var(--ink2);font-weight:600;margin-top:4px;" id="prepHint">
                ⚡ يبدأ الحساب من لحظة تأكيد طلبك
            </div>
            <?php if($queue_count > 0): ?>
            <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:var(--ink2);background:var(--bg3);border:1px solid var(--line);padding:7px 12px;border-radius:10px;margin-top:6px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:11px;height:11px;flex-shrink:0;color:#F59E0B"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                <span id="queueText"><?= $queue_count ?> طلب قبلك في الكيو</span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="section-label" id="lblDetails" style="margin-top:4px;">تفاصيل الطلب</div>
    <div class="items-card">
        <div class="card-title" id="lblItems">الأصناف المطلوبة</div>
        <?php foreach($items as $item): ?>
        <div class="order-item">
            <div class="item-qty-badge"><?= $item['quantity'] ?>×</div>
            <div class="item-info">
                <div class="item-name"
                     data-name-ar="<?= htmlspecialchars($item['dish_name']) ?>"
                     data-name-en="<?= htmlspecialchars($item['dish_name_en_resolved'] ?? '') ?>">
                    <?php if(empty($item['dish_id'])): ?>
                    <span style="font-size:9px;font-weight:800;background:rgba(255,107,53,.1);color:var(--p);border:1px solid rgba(255,107,53,.2);padding:1px 5px;border-radius:7px;margin-left:4px;">🎁</span>
                    <?php endif; ?>
                    <?= htmlspecialchars($item['dish_name']) ?>
                </div>
                <div class="item-unit" data-price="<?= number_format($item['dish_price'],2) ?>">
                    <?= fmt_price($item['dish_price'], $cur_symbol, $cur_decimals, $cur_prefix) ?> <span class="unit-label">/ الوحدة</span>
                </div>
                <?php if(!empty($item['options'])): ?>
                <?php $opts = json_decode($item['options'], true) ?? []; ?>
                <?php if(!empty($opts)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;">
                    <?php foreach($opts as $opt): ?>
                    <span style="font-size:10px;font-weight:700;color:var(--p);background:color-mix(in srgb,var(--p) 10%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);padding:2px 7px;border-radius:8px;"><?= htmlspecialchars($opt['name']??'') ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="item-price"><?= fmt_price($item['dish_price']*$item['quantity'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if($order['customer_notes']): ?>
        <div class="notes-row">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
            <?= htmlspecialchars($order['customer_notes']) ?>
        </div>
        <?php endif; ?>
        <?php if(!empty($order_taxes)): ?>
        <?php $before_tax = $order['total_price'] - ($order['tax_amount'] ?? 0); ?>
        <div class="total-row" style="font-size:13px;opacity:.75;">
            <div class="total-label" id="lblSubtotal">المجموع الفرعي</div>
            <div><?= fmt_price($before_tax, $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        </div>
        <?php foreach($order_taxes as $otax): ?>
        <div class="total-row" style="font-size:12px;opacity:.8;">
            <div style="display:flex;align-items:center;gap:5px;color:var(--ink2);">
                <span class="tax-name-disp"
                      data-name-ar="<?= htmlspecialchars($otax['name']) ?>"
                      data-name-en="<?= htmlspecialchars($otax['name_en'] ?? $otax['name']) ?>">
                    <?= htmlspecialchars($otax['name']) ?>
                </span>
                <span style="font-size:9px;font-weight:800;padding:1px 6px;border-radius:10px;background:rgba(99,102,241,.12);color:#818CF8;">
                    <?= $otax['type']==='percentage' ? $otax['value'].'%' : '$'.number_format($otax['value'],2) ?>
                </span>
            </div>
            <div style="color:#818CF8;font-weight:700;">+<?= fmt_price($otax['amount'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        </div>
        <?php endforeach; ?>
        <div style="height:1px;background:var(--line);margin:4px 0;"></div>
        <?php endif; ?>
        <div class="total-row">
            <div class="total-label" id="lblTotal">الإجمالي</div>
            <div class="total-amount"><?= fmt_price($order['total_price'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        </div>
    </div>

    <div class="action-btns">
        <?php if($order['status']==='delivered'): ?>
            <?php if(!$rated): ?>
            <a href="<?= BASE_URL ?>/menu/<?= $slug ?>/rate/<?= $order_id ?>" class="btn-primary" id="btnRate">
                <svg viewBox="0 0 24 24" fill="#fff" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                قيّم تجربتك واحصل على الفاتورة
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/menu/<?= $slug ?>/invoice/<?= $order_id ?>" class="<?= $rated?'btn-primary':'btn-secondary' ?>" id="btnInvoice">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                عرض الفاتورة
            </a>
        <?php else: ?>
            <a href="<?= BASE_URL ?>/menu/<?= $slug ?>" class="btn-primary" id="btnNewOrder">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M3 2l3 7H6a3 3 0 003 3v9a1 1 0 001 1h4a1 1 0 001-1v-9a3 3 0 003-3h-3l3-7"/></svg>
                طلب جديد
            </a>
        <?php endif; ?>
    </div>
</div>

<script>
const CUR_SYMBOL    = '<?= addslashes($cur_symbol) ?>';
const CUR_SYMBOL_EN = '<?= addslashes($cur_symbol_en) ?>';
const CUR_DECIMALS  = <?= $cur_decimals ?>;
const CUR_PREFIX    = <?= $cur_prefix ? 'true' : 'false' ?>;
const CUR_PREFIX_EN = <?= $cur_prefix_en ? 'true' : 'false' ?>;
function fmtPrice(amount) {
    const isEN = (typeof currentLang !== 'undefined' && currentLang === 'en') ||
                 document.documentElement.getAttribute('lang') === 'en';
    const sym    = isEN ? CUR_SYMBOL_EN : CUR_SYMBOL;
    const prefix = isEN ? CUR_PREFIX_EN : CUR_PREFIX;
    const n = parseFloat(amount).toFixed(CUR_DECIMALS);
    return prefix ? sym + n : sym + ' ' + n;
}

const SLUG     = '<?= $slug ?>';
const LANG_KEY = 'menu_lang_' + SLUG;
const STATUS   = '<?= $order['status'] ?>';

const TR = {
    ar: {
        order_num:      'طلب #<?= $display_order_num ?>',
        stage:          'مرحلة طلبك',
        details:        'تفاصيل الطلب',
        items:          'الأصناف المطلوبة',
        total:          'الإجمالي',
        unit:           '/ الوحدة',
        now:            'الآن',
        refresh:        'يتحدث تلقائياً كل 10 ثوانٍ',
        table:          'طاولة',
        cancelled_title:'تم إلغاء هذا الطلب',
        cancelled_sub:  'يمكنك تقديم طلب جديد من المنيو',
        rate_btn:       'قيّم تجربتك واحصل على الفاتورة',
        invoice_btn:    'عرض الفاتورة',
        new_order:      'طلب جديد',
        status_cancelled:'تم إلغاء الطلب',
        status_current: '<?= $current['label_ar'] ?>',
        dir: 'rtl',
    },
    en: {
        order_num:      'Order #<?= $display_order_num ?>',
        stage:          'Order Status',
        details:        'Order Details',
        items:          'Items Ordered',
        total:          'Total',
        unit:           '/ unit',
        now:            'Now',
        refresh:        'Auto-updates every 10 seconds',
        table:          'Table',
        cancelled_title:'Order Cancelled',
        cancelled_sub:  'You can place a new order from the menu',
        rate_btn:       'Rate your experience & get invoice',
        invoice_btn:    'View Invoice',
        new_order:      'New Order',
        status_cancelled:'Order Cancelled',
        status_current: '<?= $current['label_en'] ?>',
        dir: 'ltr',
    }
};

let lang = localStorage.getItem(LANG_KEY) || 'ar';

function applyLang(l) {
    lang = l;
    localStorage.setItem(LANG_KEY, l);
    const T = TR[l];
    document.documentElement.setAttribute('dir', T.dir);
    document.documentElement.setAttribute('lang', l);
    const btn = document.getElementById('langBtn');
    if(btn){ btn.textContent = l==='en'?'AR':'EN'; btn.classList.toggle('en-active', l==='en'); }

    setText('orderNumText', T.order_num);
    setText('lblStage',     T.stage);
    setText('lblDetails',   T.details);
    setText('lblItems',     T.items);
    setText('lblTotal',     T.total);
    setText('nowText',      T.now);
    setText('refreshText',  T.refresh);
    setText('tableChipText',T.table);
    setText('cancelledTitle',T.cancelled_title);
    setText('cancelledSub', T.cancelled_sub);
    setText('statusLabel',  STATUS==='cancelled' ? T.status_cancelled : T.status_current);

    // unit labels
    document.querySelectorAll('.unit-label').forEach(el => el.textContent = T.unit);
    // أسماء الأطباق
    document.querySelectorAll('.item-name[data-name-ar]').forEach(el => {
        el.textContent = (l==='en' && el.dataset.nameEn) ? el.dataset.nameEn : el.dataset.nameAr;
    });
    // ضرائب
    const subtotalLbl = document.getElementById('lblSubtotal');
    if(subtotalLbl) subtotalLbl.textContent = l==='en' ? 'Subtotal' : 'المجموع الفرعي';
    document.querySelectorAll('.tax-name-disp').forEach(el => {
        el.textContent = (l==='en' && el.dataset.nameEn) ? el.dataset.nameEn : el.dataset.nameAr;
    });

    // step titles
    document.querySelectorAll('.step').forEach(el => {
        const title = el.querySelector('.step-title');
        if(title) title.textContent = l==='en' ? el.dataset.en : el.dataset.ar;
    });

    // prep time card
    const ptitle = document.getElementById('prepTitle');
    if(ptitle) ptitle.textContent = l==='en' ? 'Estimated Prep Time' : 'الوقت المتوقع للتحضير';
    const punit = document.getElementById('prepUnit');
    if(punit) punit.textContent = l==='en' ?
        (punit.dataset.countdown ? 'min remaining' : 'min approx') :
        (punit.dataset.countdown ? 'دقيقة متبقية' : 'دقيقة تقريباً');
    const pnow = document.getElementById('prepNowText');
    if(pnow) pnow.textContent = l==='en' ? 'Preparing now' : 'جاري التحضير الآن';
    const ptotal = document.getElementById('prepTotalText');
    if(ptotal) {
        const totalNum = ptotal.textContent.match(/\d+/)?.[0] || '';
        ptotal.textContent = l==='en' ? 'Total: '+totalNum+' min' : 'الإجمالي: '+totalNum+' دقيقة';
    }
    const pready = document.getElementById('prepReadySoon');
    if(pready) pready.textContent = l==='en' ? 'Your order is almost ready!' : 'طلبك على وشك يكون جاهز!';
    const phint = document.getElementById('prepHint');
    if(phint) phint.textContent = l==='en' ? '⚡ Timer starts from order confirmation' : '⚡ يبدأ الحساب من لحظة تأكيد طلبك';
    const qtext = document.getElementById('queueText');
    if(qtext) {
        const qnum = qtext.textContent.match(/\d+/)?.[0] || '';
        qtext.textContent = l==='en' ? qnum+' order(s) ahead of you' : qnum+' طلب قبلك في الكيو';
    }

    // buttons
    updateBtn('btnRate',      T.rate_btn,     'star');
    updateBtn('btnInvoice',   T.invoice_btn,  'file');
    updateBtn('btnNewOrder',  T.new_order,    'menu');
}

function setText(id, val){ const el=document.getElementById(id); if(el) el.textContent=val; }

function updateBtn(id, text, icon) {
    const el = document.getElementById(id);
    if(!el) return;
    const icons = {
        star: '<svg viewBox="0 0 24 24" fill="currentColor" style="width:18px;height:18px"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
        file: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:18px;height:18px"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:18px;height:18px"><path d="M3 2l3 7H6a3 3 0 003 3v9a1 1 0 001 1h4a1 1 0 001-1v-9a3 3 0 003-3h-3l3-7"/></svg>',
    };
    el.innerHTML = (icons[icon]||'') + text;
}

function toggleLang(){ applyLang(lang==='ar'?'en':'ar'); }

function toggleTheme(){
    const n = document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',n);
    localStorage.setItem('menu_theme_'+SLUG, n);
}

applyLang(lang);

<?php if(!$is_final): ?>
setTimeout(() => location.reload(), 10000);

// Countdown لعداد الوقت المتبقي
(function() {
    const countdownEl = document.getElementById('prepCountdown');
    const progressEl  = document.getElementById('prepProgress');
    if(!countdownEl) return;
    let remaining = parseInt(countdownEl.textContent) || 0;
    const total   = <?= $estimated_time ?>;
    let elapsed   = total - remaining;

    setInterval(() => {
        elapsed += 1/60;
        remaining = Math.max(0, total - elapsed);
        countdownEl.textContent = Math.ceil(remaining);
        if(progressEl) {
            const pct = Math.min(100, (elapsed / total) * 100);
            progressEl.style.width = pct + '%';
        }
        if(remaining <= 0) {
            countdownEl.textContent = '🍽️';
            const unitEl = document.getElementById('prepUnit');
            if(unitEl) unitEl.textContent = lang==='en' ? 'Almost ready!' : 'على وشك يكون جاهز!';
        }
    }, 60000); // كل دقيقة
})();
<?php endif; ?>
</script>
</body>
</html>