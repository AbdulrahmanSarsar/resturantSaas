<?php
/**
 * cashier.php — Branch-scoped patch
 */
session_start();
require_once '../../config/database.php';
require_once '../../config/csrf.php';

if(!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'cashier') {
    header('Location: login.php'); exit;
}

$rid      = $_SESSION['staff_rest_id'];
$staff_id = $_SESSION['staff_id'];

// branch_id من الـ DB — محمي بـ try/catch
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
    $branch_id = null;
    $_SESSION['staff_branch_id'] = null;
}

// بيانات المطعم (عملة)
$rest = $pdo->prepare("SELECT * FROM restaurants WHERE id=?");
$rest->execute([$rid]);
$restaurant = $rest->fetch();
$cur_symbol    = $restaurant['currency_symbol']    ?? '$';
$cur_symbol_en = $restaurant['currency_symbol_en'] ?? '$';
$cur_decimals  = intval($restaurant['currency_decimals'] ?? 2);
$cur_prefix    = in_array($cur_symbol, ['$','€','₺']);
if(!function_exists('fmt_price')) {
    function fmt_price($a,$s,$d,$p){ $f=number_format(floatval($a),$d); return $p?$s.$f:$s.' '.$f; }
}

// AJAX: تسجيل قبض
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax'])) {
    csrf_require();
    $order_id = intval($_POST['order_id']);
    $method   = in_array($_POST['method'],['cash','card','shamcash']) ? $_POST['method'] : 'cash';
    $chk = $pdo->prepare("SELECT id FROM orders WHERE id=? AND restaurant_id=? AND status='delivered' AND payment_status='unpaid'");
    $chk->execute([$order_id, $rid]);
    if($chk->fetch()) {
        $pdo->prepare("UPDATE orders SET payment_status='paid', payment_method=?, paid_at=NOW(), cashier_id=? WHERE id=?")
            ->execute([$method, $staff_id, $order_id]);
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['error'=>'not_found']);
    }
    exit;
}

// جلب الطلبات المسلّمة غير المقبوضة — branch-scoped
$date_filter = $_GET['date'] ?? 'today';
$where_date  = $date_filter === 'today' ? "AND DATE(o.created_at)=CURDATE()" : '';

if($branch_id) {
    // branch-filtered عبر orders.branch_id مباشرة (بعد ما orders_v2 أُهجر)
    $orders_stmt = $pdo->prepare("
        SELECT o.*, COALESCE(o.branch_order_number, o.restaurant_order_number, o.id) as display_num
        FROM orders o
        WHERE o.restaurant_id=?
          AND o.branch_id=?
          AND o.status='delivered'
          AND o.payment_status='unpaid'
          $where_date
        ORDER BY o.created_at DESC
    ");
    $orders_stmt->execute([$rid, $branch_id]);
} else {
    $orders_stmt = $pdo->prepare("
        SELECT o.*, COALESCE(o.branch_order_number, o.restaurant_order_number, o.id) as display_num
        FROM orders o
        WHERE o.restaurant_id=?
          AND o.status='delivered'
          AND o.payment_status='unpaid'
          $where_date
        ORDER BY o.created_at DESC
    ");
    $orders_stmt->execute([$rid]);
}
$unpaid_orders = $orders_stmt->fetchAll();

// Batch-load order_items للعرض — بدل N+1 في الـ foreach
$items_by_order = [];
if (!empty($unpaid_orders)) {
    $oid_list = array_column($unpaid_orders, 'id');
    $ph = implode(',', array_fill(0, count($oid_list), '?'));
    $bst = $pdo->prepare("SELECT order_id, dish_name, quantity FROM order_items WHERE order_id IN ($ph) ORDER BY id");
    $bst->execute($oid_list);
    foreach ($bst->fetchAll() as $row) {
        $items_by_order[$row['order_id']][] = $row;
    }
}

// ===== إحصائيات اليوم — branch-scoped =====
$stats_branch_sql    = $branch_id ? "AND branch_id = ?" : "";
$stats_branch_params = $branch_id ? [$branch_id]       : [];
$today_stats = $pdo->prepare("
    SELECT
        COUNT(*) as total_orders,
        COALESCE(SUM(total_price),0) as total_revenue,
        SUM(payment_method='cash') as cash_count,
        COALESCE(SUM(CASE WHEN payment_method='cash' THEN total_price END),0) as cash_revenue,
        SUM(payment_method='card') as card_count,
        COALESCE(SUM(CASE WHEN payment_method='card' THEN total_price END),0) as card_revenue,
        SUM(payment_method='shamcash') as sham_count,
        COALESCE(SUM(CASE WHEN payment_method='shamcash' THEN total_price END),0) as sham_revenue
    FROM orders
    WHERE restaurant_id=? $stats_branch_sql AND payment_status='paid' AND DATE(paid_at)=CURDATE()
");
$today_stats->execute(array_merge([$rid], $stats_branch_params));
$stats = $today_stats->fetch();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= csrf_meta() ?>
<title>الكاشير — <?= htmlspecialchars($restaurant['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{--p:#FF6B35;--s:#F7C59F;}
[data-theme="dark"]{--bg:#080808;--bg2:#111111;--bg3:#1a1a1a;--bg4:#222222;--ink:#F5F0E8;--ink2:#6B6560;--ink3:#3A3530;--line:rgba(245,240,232,0.06);}
[data-theme="light"]{--bg:#F7F5F2;--bg2:#FFFFFF;--bg3:#EEEAE6;--bg4:#E4E0DC;--ink:#1A1714;--ink2:#7A7570;--ink3:#B8B4B0;--line:rgba(26,23,20,0.08);}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;}

/* HEADER */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--bg2);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:100;backdrop-filter:blur(20px);}
.topbar-brand{font-family:'Fraunces',serif;font-size:16px;font-weight:700;color:var(--ink);}
.topbar-role{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ink2);font-weight:700;}
.role-badge{background:rgba(245,158,11,.12);color:#F59E0B;border:1px solid rgba(245,158,11,.2);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;}
.topbar-btns{display:flex;gap:8px;}
.icon-btn{width:36px;height:36px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:12px;font-weight:800;font-family:'Tajawal',sans-serif;text-decoration:none;transition:all .2s;}
.icon-btn.logout{background:rgba(239,68,68,.08);color:#EF4444;border-color:rgba(239,68,68,.2);}
.icon-moon{display:block;}.icon-sun{display:none;}
[data-theme="light"] .icon-moon{display:none;}[data-theme="light"] .icon-sun{display:block;}

/* MAIN */
.main{max-width:900px;margin:0 auto;padding:20px;}

/* STATS ROW */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:22px;}
@media(max-width:600px){.stats-row{grid-template-columns:repeat(2,1fr);}}
.stat-card{background:var(--bg2);border:1px solid var(--line);border-radius:16px;padding:14px 16px;}
.stat-label{font-size:11px;font-weight:700;color:var(--ink2);margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.stat-val{font-family:'Fraunces',serif;font-size:22px;font-weight:900;color:var(--ink);letter-spacing:-.3px;}
.stat-val.orange{color:var(--p);}
.stat-val.green{color:#22C55E;}
.stat-val.blue{color:#3B82F6;}
.stat-val.purple{color:#8B5CF6;}

/* DATE TABS */
.date-tabs{display:flex;gap:6px;margin-bottom:18px;}
.date-tab{padding:7px 16px;border-radius:20px;border:1.5px solid var(--line);background:var(--bg2);font-size:12px;font-weight:700;color:var(--ink2);text-decoration:none;font-family:'Tajawal',sans-serif;transition:all .2s;}
.date-tab.active{background:var(--p);border-color:transparent;color:#fff;}

/* SECTION TITLE */
.sec-title{font-family:'Fraunces',serif;font-size:17px;font-weight:700;color:var(--ink);margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.sec-count{background:var(--p);color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;}

/* ORDER CARD */
.order-card{background:var(--bg2);border:1px solid var(--line);border-radius:16px;overflow:hidden;margin-bottom:10px;animation:cardIn .35s cubic-bezier(.22,1,.36,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(10px) scale(.98);}to{opacity:1;transform:none;}}
.order-head{display:flex;align-items:center;gap:10px;padding:13px 16px;background:var(--bg3);border-bottom:1px solid var(--line);}
.order-num{font-family:'Fraunces',serif;font-size:18px;font-weight:900;color:var(--ink);}
.order-table{background:color-mix(in srgb,var(--p) 12%,transparent);border:1px solid color-mix(in srgb,var(--p) 25%,transparent);color:var(--p);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;}
.order-time{font-size:11px;color:var(--ink2);font-weight:600;margin-right:auto;}
.order-total{font-family:'Fraunces',serif;font-size:20px;font-weight:900;color:var(--p);}
.order-body{padding:12px 16px;}
.order-items-preview{font-size:12px;color:var(--ink2);font-weight:600;margin-bottom:12px;line-height:1.7;}
.order-items-preview span{display:inline-flex;align-items:center;gap:4px;background:var(--bg3);border:1px solid var(--line);padding:2px 8px;border-radius:8px;margin:2px 3px 2px 0;}

/* PAY METHODS */
.pay-methods{display:flex;gap:8px;flex-wrap:wrap;}
.pay-btn{flex:1;min-width:80px;padding:11px 8px;border-radius:12px;border:2px solid var(--line);background:var(--bg3);font-size:13px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;color:var(--ink2);transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:4px;}
.pay-btn:hover{transform:translateY(-2px);}
.pay-btn.cash:hover,.pay-btn.cash.selected{border-color:#22C55E;background:rgba(34,197,94,.1);color:#22C55E;}
.pay-btn.card:hover,.pay-btn.card.selected{border-color:#3B82F6;background:rgba(59,130,246,.1);color:#3B82F6;}
.pay-btn.shamcash:hover,.pay-btn.shamcash.selected{border-color:#FF6B35;background:rgba(255,107,53,.1);color:#FF6B35;}
.pay-btn-icon{font-size:20px;}
.confirm-btn{width:100%;margin-top:10px;padding:13px;background:var(--p);color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;display:flex;align-items:center;justify-content:center;gap:8px;transition:opacity .2s;opacity:.5;pointer-events:none;}
.confirm-btn.ready{opacity:1;pointer-events:all;}
.confirm-btn.done{background:#22C55E;}
.confirm-btn svg{width:16px;height:16px;}

/* EMPTY */
.empty-state{text-align:center;padding:60px 20px;background:var(--bg2);border:1px solid var(--line);border-radius:18px;}
.empty-icon{font-size:48px;margin-bottom:14px;}
.empty-state p{font-size:14px;color:var(--ink2);font-weight:600;}

/* TOAST */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:#22C55E;color:#fff;padding:12px 24px;border-radius:25px;font-size:14px;font-weight:700;z-index:999;opacity:0;transition:all .3s cubic-bezier(.34,1.56,.64,1);white-space:nowrap;pointer-events:none;}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-brand">
        💰 <?= htmlspecialchars($restaurant['name']) ?>
    </div>
    <div class="topbar-role">
        <span class="role-badge">🧾 كاشير</span>
        <?= htmlspecialchars($_SESSION['staff_name']) ?>
    </div>
    <div class="topbar-btns">
        <button class="icon-btn" onclick="toggleTheme()">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg>
        </button>
        <a href="logout.php" class="icon-btn logout" title="خروج">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
    </div>
</div>

<div class="main">

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-label">💰 إيرادات اليوم</div>
            <div class="stat-val orange"><?= fmt_price($stats['total_revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">🧾 طلبات مقبوضة</div>
            <div class="stat-val green"><?= $stats['total_orders'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">💵 نقدي</div>
            <div class="stat-val"><?= fmt_price($stats['cash_revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">💳 كارت / شام كاش</div>
            <div class="stat-val blue"><?= fmt_price($stats['card_revenue'] + $stats['sham_revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        </div>
    </div>

    <!-- DATE TABS -->
    <div class="date-tabs">
        <a href="?date=today" class="date-tab <?= $date_filter==='today'?'active':'' ?>">اليوم</a>
        <a href="?date=all" class="date-tab <?= $date_filter==='all'?'active':'' ?>">كل الطلبات</a>
    </div>

    <!-- UNPAID ORDERS -->
    <div class="sec-title">
        طلبات تنتظر القبض
        <div class="sec-count"><?= count($unpaid_orders) ?></div>
    </div>

    <?php if(empty($unpaid_orders)): ?>
    <div class="empty-state">
        <div class="empty-icon">✅</div>
        <p>كل الطلبات مقبوضة!</p>
    </div>
    <?php else: ?>

    <?php foreach($unpaid_orders as $i => $o): ?>
    <?php $items = $items_by_order[$o['id']] ?? []; ?>
    <div class="order-card" id="oc-<?= $o['id'] ?>" style="animation-delay:<?= $i*.04 ?>s">
        <div class="order-head">
            <div class="order-num">#<?= $o['display_num'] ?></div>
            <div class="order-table">🪑 طاولة <?= htmlspecialchars($o['table_number']) ?></div>
            <div class="order-time"><?= date('H:i', strtotime($o['created_at'])) ?></div>
            <div class="order-total"><?= fmt_price($o['total_price'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        </div>
        <div class="order-body">
            <div class="order-items-preview">
                <?php foreach($items as $it): ?>
                <span>×<?= $it['quantity'] ?> <?= htmlspecialchars($it['dish_name']) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="pay-methods">
                <button class="pay-btn cash" onclick="selectMethod(this,'cash',<?= $o['id'] ?>)">
                    <span class="pay-btn-icon">💵</span>
                    نقدي
                </button>
                <button class="pay-btn card" onclick="selectMethod(this,'card',<?= $o['id'] ?>)">
                    <span class="pay-btn-icon">💳</span>
                    كارت
                </button>
                <?php if($restaurant['shamcash_enabled']): ?>
                <button class="pay-btn shamcash" onclick="selectMethod(this,'shamcash',<?= $o['id'] ?>)">
                    <span class="pay-btn-icon">📱</span>
                    شام كاش
                </button>
                <?php endif; ?>
            </div>
            <button class="confirm-btn" id="cb-<?= $o['id'] ?>" onclick="confirmPay(<?= $o['id'] ?>)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                تأكيد القبض
            </button>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<div class="toast" id="toast"></div>

<script>
const selectedMethods = {};

function selectMethod(btn, method, orderId) {
    const card = btn.closest('.order-card');
    card.querySelectorAll('.pay-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedMethods[orderId] = method;
    const cb = document.getElementById('cb-'+orderId);
    if(cb) cb.classList.add('ready');
}

function confirmPay(orderId) {
    const method = selectedMethods[orderId];
    if(!method) return;
    const cb = document.getElementById('cb-'+orderId);
    cb.disabled = true;
    cb.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:16px;height:16px"><circle cx="12" cy="12" r="10"/></svg> جاري...';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded','X-CSRF-Token':csrf},
        body: `ajax=1&order_id=${orderId}&method=${method}&_csrf_token=${encodeURIComponent(csrf)}`
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            const card = document.getElementById('oc-'+orderId);
            cb.classList.add('done');
            cb.innerHTML = '✅ تم القبض!';
            setTimeout(() => {
                card.style.transition = 'all .4s ease';
                card.style.opacity = '0';
                card.style.transform = 'translateX(-20px)';
                setTimeout(() => { card.remove(); updateCount(); }, 400);
            }, 800);
            showToast('✅ تم تسجيل القبض');
            updateStats();
        }
    })
    .catch(() => { cb.disabled=false; cb.classList.remove('done'); });
}

function updateCount() {
    const remaining = document.querySelectorAll('.order-card').length;
    const badge = document.querySelector('.sec-count');
    if(badge) badge.textContent = remaining;
    if(remaining === 0) {
        const title = document.querySelector('.sec-title');
        if(title) title.insertAdjacentHTML('afterend', '<div class="empty-state"><div class="empty-icon">✅</div><p>كل الطلبات مقبوضة!</p></div>');
    }
}

function updateStats() { location.reload(); }

let toastTimer;
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2500);
}

function toggleTheme() {
    const n = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', n);
    localStorage.setItem('cashier_theme', n);
}
(function(){ document.documentElement.setAttribute('data-theme', localStorage.getItem('cashier_theme')||'dark'); })();

// Auto-refresh كل 30 ثانية
setInterval(() => location.reload(), 30000);
</script>
</body>
</html>