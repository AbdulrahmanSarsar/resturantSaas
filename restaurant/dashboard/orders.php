<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/csrf.php';
require_once 'plan_guard.php';
if(!isset($_SESSION['restaurant_id'])) { header('Location: ../login.php'); exit; }
plan_required('advanced'); // نظام الطلبات متاح للمتقدمة + الاحترافية

$rid = $_SESSION['restaurant_id'];
$rest_data = $pdo->prepare("SELECT currency_symbol, currency_decimals FROM restaurants WHERE id=?");
$rest_data->execute([$rid]);
$rest_row = $rest_data->fetch();
$cur_symbol   = $rest_row['currency_symbol']   ?? '$';
$cur_decimals = intval($rest_row['currency_decimals'] ?? 2);
$cur_prefix   = in_array($cur_symbol, ['$', '€', '₺']);
if(!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $symbol . ' ' . $formatted;
    }
}

// ===== Branch Filter =====
$active_branch_id = $_SESSION['active_branch_id'] ?? null;

// POST: تحديث حالة الطلب
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    csrf_require();
    // whitelist الحالات المسموحة — نمنع قيم غير متوقعة
    $new_status = $_POST['status'] ?? '';
    if (!in_array($new_status, ['pending','confirmed','preparing','ready','delivered','cancelled'], true)) {
        if(isset($_POST['ajax'])) { echo json_encode(['success'=>false,'error'=>'bad_status']); exit; }
        http_response_code(400); exit;
    }
    $pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=? AND restaurant_id=?")
        ->execute([$new_status, (int)$_POST['order_id'], $rid]);
    try {
        $pdo->prepare("INSERT INTO order_status_log (order_id,status,changed_at) VALUES (?,?,NOW())")
            ->execute([(int)$_POST['order_id'], $new_status]);
    } catch(Exception $e) {}
    if(isset($_POST['ajax'])) { echo json_encode(['success'=>true]); exit; }
}

$filter    = $_GET['status'] ?? 'all';
$date_mode = $_GET['date']   ?? 'today';

// بناء الـ WHERE مع branch filter
$where  = 'WHERE restaurant_id = ?';
$params = [$rid];
if($active_branch_id) { $where .= ' AND branch_id = ?'; $params[] = $active_branch_id; }
if($date_mode !== 'all') { $where .= ' AND DATE(created_at) = CURDATE()'; }
if($filter !== 'all')    { $where .= ' AND status = ?'; $params[] = $filter; }

// LIMIT للحماية من تحميل آلاف الطلبات على صفحة واحدة — ما في pagination بعد
$orders = $pdo->prepare("SELECT * FROM orders $where ORDER BY created_at DESC LIMIT 300");
$orders->execute($params);
$orders = $orders->fetchAll();

// Batch-load order_items لكل الطلبات المعروضة — بدل N+1
$items_by_order = [];
if (!empty($orders)) {
    $oid_list = array_column($orders, 'id');
    $ph = implode(',', array_fill(0, count($oid_list), '?'));
    $bst = $pdo->prepare("SELECT * FROM order_items WHERE order_id IN ($ph) ORDER BY id");
    $bst->execute($oid_list);
    foreach ($bst->fetchAll() as $row) {
        $items_by_order[$row['order_id']][] = $row;
    }
}

// إحصائيات — مع branch filter
$stats_where  = 'WHERE restaurant_id=?';
$stats_params = [$rid];
if($active_branch_id) { $stats_where .= ' AND branch_id = ?'; $stats_params[] = $active_branch_id; }

$stats = $pdo->prepare("
    SELECT COUNT(*) as total,
        SUM(status='pending') as pending,
        SUM(status='preparing') as preparing,
        SUM(status='ready') as ready,
        SUM(status='delivered' AND DATE(created_at)=CURDATE()) as delivered_today,
        COALESCE(SUM(CASE WHEN status='delivered' AND DATE(created_at)=CURDATE() THEN total_price END),0) as revenue_today
    FROM orders $stats_where
");
$stats->execute($stats_params);
$stats = $stats->fetch();

// اسم الفرع النشط للعرض
$branch_name = '';
if($active_branch_id) {
    $bn = $pdo->prepare("SELECT name FROM branches WHERE id=?");
    $bn->execute([$active_branch_id]);
    $branch_name = $bn->fetchColumn() ?: '';
}

require_once 'sidebar.php';
?>
<style>
/* ===== BRANCH BADGE ===== */
.branch-active-badge {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;
    background: color-mix(in srgb, var(--p) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--p) 25%, transparent);
    color: var(--p); margin-bottom: 14px;
}
.branch-active-badge svg { width: 12px; height: 12px; }

/* ===== STAT PILLS ===== */
.stat-pills{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;}
.stat-pill{
    display:flex;align-items:center;gap:10px;
    background:var(--bg2);border:1.5px solid var(--line);
    border-radius:16px;padding:12px 16px;
    text-decoration:none;transition:all .2s;
    flex:1;min-width:110px;cursor:pointer;
}
.stat-pill:hover{border-color:var(--p);transform:translateY(-2px);}
.stat-pill.active{background:color-mix(in srgb,var(--p) 8%,var(--bg2));border-color:var(--p);box-shadow:0 4px 14px color-mix(in srgb,var(--p) 18%,transparent);}
.pill-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.pill-num{font-family:'Fraunces',serif;font-size:20px;font-weight:900;color:var(--ink);letter-spacing:-.3px;line-height:1;}
.pill-label{font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px;}

/* ===== TOOLBAR ===== */
.toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.date-tabs{display:flex;gap:6px;}
.date-tab{padding:7px 14px;border-radius:20px;border:1.5px solid var(--line);background:var(--bg2);font-size:12px;font-weight:700;color:var(--ink2);text-decoration:none;transition:all .2s;font-family:'Tajawal',sans-serif;}
.date-tab:hover{border-color:var(--p);color:var(--p);}
.date-tab.active{background:var(--p);border-color:transparent;color:#fff;}
.live-indicator{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink2);font-weight:600;}
.live-dot{width:7px;height:7px;background:#22C55E;border-radius:50%;animation:liveBlink 1.5s infinite;}
@keyframes liveBlink{0%,100%{opacity:1}50%{opacity:.25}}
.sound-btn{padding:6px 12px;background:var(--bg3);border:1px solid var(--line);border-radius:20px;font-size:11px;font-weight:700;color:var(--ink2);cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;}
.sound-btn:hover{border-color:var(--p);color:var(--p);}

/* ===== ORDER CARDS ===== */
.orders-list{display:flex;flex-direction:column;gap:12px;}
.order-card{
    background:var(--bg2);border:1px solid var(--line);
    border-radius:18px;overflow:hidden;
    animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;
    transition:box-shadow .2s;
}
.order-card:hover{box-shadow:var(--shadow2);}
.order-card.status-pending  {border-right:3px solid #F59E0B;}
.order-card.status-confirmed{border-right:3px solid #3B82F6;}
.order-card.status-preparing{border-right:3px solid #8B5CF6;}
.order-card.status-ready    {border-right:3px solid #22C55E;}
.order-card.status-delivered{border-right:3px solid var(--ink3);}
.order-card.status-cancelled{border-right:3px solid #EF4444;opacity:.6;}
.order-card.new-flash{animation:newFlash .6s ease 3,cardIn .4s both;}
@keyframes newFlash{0%,100%{box-shadow:none}50%{box-shadow:0 0 0 8px rgba(255,107,53,.18)}}

/* ORDER HEAD */
.order-head{display:flex;align-items:center;gap:10px;padding:12px 16px;flex-wrap:wrap;}
.order-num{font-family:'Fraunces',serif;font-size:16px;font-weight:900;color:var(--ink);}
.order-table-chip{background:rgba(255,107,53,.1);border:1px solid rgba(255,107,53,.2);color:var(--p);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;}
.order-customer{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--ink2);font-weight:600;}
.order-customer svg{width:12px;height:12px;}
.order-head-right{margin-right:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.order-time{font-size:11px;color:var(--ink2);font-weight:600;}
.order-total{font-family:'Fraunces',serif;font-size:15px;font-weight:900;color:var(--ink);}
.status-badge{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:800;}
.badge-pending  {background:rgba(245,158,11,.12);color:#F59E0B;}
.badge-confirmed{background:rgba(59,130,246,.12);color:#3B82F6;}
.badge-preparing{background:rgba(139,92,246,.12);color:#8B5CF6;}
.badge-ready    {background:rgba(34,197,94,.12);color:#22C55E;}
.badge-delivered{background:var(--bg3);color:var(--ink2);}
.badge-cancelled{background:rgba(239,68,68,.1);color:#EF4444;}

/* ORDER ITEMS */
.order-items{padding:0 16px;}
.order-item{display:flex;align-items:flex-start;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--line);}
.order-item:last-child{border-bottom:none;}
.order-item-main{flex:1;min-width:0;}
.order-item-name{font-size:13px;font-weight:700;color:var(--ink);margin-bottom:3px;}
.order-item-opts{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:3px;}
.order-item-opt{background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.2);color:#A78BFA;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;}
.order-item-notes{font-size:11px;color:#F59E0B;font-weight:600;}
.order-item-qty{font-family:'Fraunces',serif;font-size:14px;font-weight:900;color:var(--p);white-space:nowrap;margin-top:2px;}

/* ORDER FOOTER */
.order-footer{padding:10px 16px;border-top:1px solid var(--line);background:var(--bg3);display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.order-notes{padding:8px 16px;background:rgba(245,158,11,.06);border-top:1px solid rgba(245,158,11,.12);font-size:12px;color:#F59E0B;display:flex;gap:6px;align-items:flex-start;}
.order-notes svg{width:13px;height:13px;flex-shrink:0;margin-top:1px;}

/* STATUS BUTTONS */
.status-btns{display:flex;gap:6px;flex-wrap:wrap;}
.status-btn{padding:8px 14px;border-radius:10px;border:none;font-size:12px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;white-space:nowrap;display:flex;align-items:center;gap:5px;}
.status-btn svg{width:12px;height:12px;}
.status-btn:active{transform:scale(.96);}
.btn-confirm{background:rgba(59,130,246,.12);color:#3B82F6;border:1px solid rgba(59,130,246,.2);}
.btn-confirm:hover{background:#3B82F6;color:#fff;border-color:transparent;}
.btn-prepare{background:rgba(139,92,246,.12);color:#8B5CF6;border:1px solid rgba(139,92,246,.2);}
.btn-prepare:hover{background:#8B5CF6;color:#fff;border-color:transparent;}
.btn-ready{background:rgba(34,197,94,.12);color:#22C55E;border:1px solid rgba(34,197,94,.2);}
.btn-ready:hover{background:#22C55E;color:#fff;border-color:transparent;}
.btn-deliver{background:var(--bg3);color:var(--ink2);border:1px solid var(--line);}
.btn-deliver:hover{background:var(--ink);color:var(--bg);border-color:transparent;}
.btn-cancel{background:rgba(239,68,68,.08);color:#EF4444;border:1px solid rgba(239,68,68,.18);}
.btn-cancel:hover{background:#EF4444;color:#fff;border-color:transparent;}
.status-btn.loading{opacity:.6;pointer-events:none;}

/* EMPTY */
.orders-empty{text-align:center;padding:64px 20px;background:var(--bg2);border:1px solid var(--line);border-radius:18px;}
.orders-empty-icon{width:64px;height:64px;border-radius:20px;background:var(--bg3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;}
.orders-empty p{font-size:14px;color:var(--ink2);font-weight:600;}
</style>

<div class="main">

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
    <div>
        <div class="page-title">الطلبات</div>
        <div class="page-subtitle">إدارة طلبات مطعمك بالوقت الحقيقي</div>
    </div>
</div>

<?php if($active_branch_id && $branch_name): ?>
<div class="branch-active-badge">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
    📍 <?= htmlspecialchars($branch_name) ?>
</div>
<?php endif; ?>

<!-- Stat Pills -->
<div class="stat-pills">
    <a href="orders.php?status=all&date=<?= $date_mode ?>" class="stat-pill <?= $filter==='all'?'active':'' ?>">
        <div class="pill-icon" style="background:color-mix(in srgb,var(--p) 12%,transparent);">📋</div>
        <div><div class="pill-num"><?= $stats['total'] ?></div><div class="pill-label">الكل</div></div>
    </a>
    <a href="orders.php?status=pending&date=<?= $date_mode ?>" class="stat-pill <?= $filter==='pending'?'active':'' ?>">
        <div class="pill-icon" style="background:rgba(245,158,11,.12);">⏳</div>
        <div><div class="pill-num"><?= $stats['pending'] ?></div><div class="pill-label">معلق</div></div>
    </a>
    <a href="orders.php?status=preparing&date=<?= $date_mode ?>" class="stat-pill <?= $filter==='preparing'?'active':'' ?>">
        <div class="pill-icon" style="background:rgba(139,92,246,.12);">👨‍🍳</div>
        <div><div class="pill-num"><?= $stats['preparing'] ?></div><div class="pill-label">بالتحضير</div></div>
    </a>
    <a href="orders.php?status=ready&date=<?= $date_mode ?>" class="stat-pill <?= $filter==='ready'?'active':'' ?>">
        <div class="pill-icon" style="background:rgba(34,197,94,.12);">🍽️</div>
        <div><div class="pill-num"><?= $stats['ready'] ?></div><div class="pill-label">جاهز</div></div>
    </a>
    <a href="orders.php?status=delivered&date=today" class="stat-pill <?= $filter==='delivered'?'active':'' ?>">
        <div class="pill-icon" style="background:rgba(34,197,94,.12);">💰</div>
        <div><div class="pill-num"><?= fmt_price($stats['revenue_today'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div><div class="pill-label">إيرادات اليوم</div></div>
    </a>
</div>

<!-- Toolbar -->
<div class="toolbar">
    <div class="date-tabs">
        <a href="orders.php?status=<?= $filter ?>" class="date-tab <?= $date_mode==='today'?'active':'' ?>">اليوم</a>
        <a href="orders.php?status=<?= $filter ?>&date=all" class="date-tab <?= $date_mode==='all'?'active':'' ?>">كل الطلبات</a>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <div class="live-indicator"><div class="live-dot"></div> يتحدث تلقائياً</div>
        <button class="sound-btn" onclick="testSound()">🔔 اختبار الصوت</button>
    </div>
</div>

<!-- Orders -->
<div class="orders-list" id="ordersList">
<?php if(empty($orders)): ?>
    <div class="orders-empty">
        <div class="orders-empty-icon">📋</div>
        <p>ما في طلبات <?= $filter!=='all'?'بهاد الفلتر':'بعد' ?></p>
    </div>
<?php else:
    $status_next = [
        'pending'   => [['confirmed','تأكيد','btn-confirm'],['cancelled','إلغاء','btn-cancel']],
        'confirmed' => [['preparing','بالتحضير','btn-prepare'],['cancelled','إلغاء','btn-cancel']],
        'preparing' => [['ready','جاهز','btn-ready']],
        'ready'     => [['delivered','تم التسليم','btn-deliver']],
        'delivered' => [], 'cancelled' => [],
    ];
    $status_labels = ['pending'=>'معلق','confirmed'=>'مؤكد','preparing'=>'بالتحضير','ready'=>'جاهز','delivered'=>'تم التسليم','cancelled'=>'ملغي'];
    $status_icons  = ['pending'=>'⏳','confirmed'=>'✓','preparing'=>'⚡','ready'=>'🍽️','delivered'=>'✅','cancelled'=>'❌'];

    foreach($orders as $idx => $o):
        $items = $items_by_order[$o['id']] ?? [];
        $order_taxes = [];
        if(!empty($o['tax_details'])) {
            $order_taxes = json_decode($o['tax_details'], true) ?: [];
        }
?>
    <div class="order-card status-<?= $o['status'] ?>" id="order-<?= $o['id'] ?>" style="animation-delay:<?= min($idx*.04,.3) ?>s">

        <!-- HEADER -->
        <div class="order-head">
            <div class="order-num">#<?= $o['branch_order_number'] ?? $o['restaurant_order_number'] ?? $o['id'] ?></div>
            <div class="order-table-chip">🪑 طاولة <?= htmlspecialchars($o['table_number']) ?></div>
            <?php if($o['customer_name']): ?>
            <div class="order-customer">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?= htmlspecialchars($o['customer_name']) ?>
            </div>
            <?php endif; ?>
            <div class="order-head-right">
                <span class="order-time"><?= date('H:i', strtotime($o['created_at'])) ?></span>
                <span class="order-total"><?= fmt_price($o['total_price'], $cur_symbol, $cur_decimals, $cur_prefix) ?></span>
                <span class="status-badge badge-<?= $o['status'] ?>">
                    <?= $status_icons[$o['status']] ?> <?= $status_labels[$o['status']] ?>
                </span>
            </div>
        </div>

        <!-- ITEMS -->
        <div class="order-items">
            <?php foreach($items as $item):
                $opts = !empty($item['options']) ? json_decode($item['options'],true)??[] : [];
            ?>
            <div class="order-item">
                <div class="order-item-main">
                    <div class="order-item-name">
                        <?php if(empty($item['dish_id'])): ?>
                        <span style="font-size:9px;font-weight:800;background:rgba(255,107,53,.12);color:var(--p);border:1px solid rgba(255,107,53,.22);padding:1px 6px;border-radius:8px;margin-left:5px;">🎁 عرض</span>
                        <?php endif; ?>
                        <?= htmlspecialchars($item['dish_name']) ?>
                    </div>
                    <?php if(!empty($opts)): ?>
                    <div class="order-item-opts">
                        <?php foreach($opts as $opt): ?>
                        <span class="order-item-opt"><?= htmlspecialchars($opt['name']??'') ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if($item['notes']): ?>
                    <div class="order-item-notes">📝 <?= htmlspecialchars($item['notes']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="order-item-qty">×<?= $item['quantity'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- NOTES -->
        <?php if($o['customer_notes']): ?>
        <div class="order-notes">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            <?= htmlspecialchars($o['customer_notes']) ?>
        </div>
        <?php endif; ?>

        <!-- FOOTER -->
        <div class="order-footer">
            <div class="status-btns">
                <?php foreach($status_next[$o['status']] ?? [] as [$next_status, $label, $btn_class]): ?>
                <button class="status-btn <?= $btn_class ?>" onclick="updateStatus(<?= $o['id'] ?>,'<?= $next_status ?>',this)">
                    <?= $label ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php if(!empty($order_taxes)): ?>
            <div style="margin-right:auto;font-size:11px;color:var(--ink2);font-weight:600;">
                <?php foreach($order_taxes as $tax): ?>
                <span><?= htmlspecialchars($tax['name']) ?>: <?= fmt_price($tax['amount']??0,$cur_symbol,$cur_decimals,$cur_prefix) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
<?php endforeach; endif; ?>
</div>

</div>

<script>
function updateStatus(orderId, status, btn) {
    btn.disabled = true;
    btn.classList.add('loading');
    const orig = btn.textContent;
    btn.textContent = '...';

    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: `order_id=${orderId}&status=${status}&ajax=1`
    })
    .then(r => r.json())
    .then(d => {
        if(d.success) {
            const card = document.getElementById('order-' + orderId);
            if(card) {
                const activeFilter = <?= json_encode($filter) ?>;
                // إذا الفلتر الحالي ما بيطابق الحالة الجديدة، أخفي الكارد
                if (activeFilter !== 'all' && activeFilter !== status) {
                    card.style.transition = 'opacity .3s, transform .3s';
                    card.style.opacity = '0';
                    card.style.transform = 'translateX(-10px)';
                    setTimeout(() => { card.remove(); }, 300);
                } else {
                    // حدث الكارد inline: class + badge + أزرار
                    updateCardInline(card, status);
                }
            }
        } else {
            btn.disabled = false;
            btn.classList.remove('loading');
            btn.textContent = orig;
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.textContent = orig;
    });
}

const STATUS_META = <?= json_encode([
    'labels' => $status_labels ?? ['pending'=>'معلق','confirmed'=>'مؤكد','preparing'=>'بالتحضير','ready'=>'جاهز','delivered'=>'تم التسليم','cancelled'=>'ملغي'],
    'icons'  => $status_icons  ?? ['pending'=>'⏳','confirmed'=>'✓','preparing'=>'⚡','ready'=>'🍽️','delivered'=>'✅','cancelled'=>'❌'],
    'next'   => $status_next   ?? [],
]) ?>;

function updateCardInline(card, newStatus) {
    // 1) Class على الكارد (للـ color stripe)
    card.className = card.className.replace(/status-\w+/g, '') + ' status-' + newStatus;
    card.classList.add('order-card');

    // 2) Badge
    const badge = card.querySelector('.status-badge');
    if (badge) {
        badge.className = 'status-badge badge-' + newStatus;
        const icon  = STATUS_META.icons[newStatus]  || '';
        const label = STATUS_META.labels[newStatus] || newStatus;
        badge.innerHTML = icon + ' ' + label;
    }

    // 3) أزرار الأكشن
    const btnsBox = card.querySelector('.status-btns');
    if (btnsBox) {
        const orderId = card.id.replace('order-', '');
        const nextList = STATUS_META.next[newStatus] || [];
        btnsBox.innerHTML = nextList.map(([ns, lbl, cls]) =>
            `<button class="status-btn ${cls}" onclick="updateStatus(${orderId},'${ns}',this)">${lbl}</button>`
        ).join('');
    }
}

// Sound
let audioCtx = null, userInteracted = false;
['click','touchstart'].forEach(ev =>
    document.addEventListener(ev, () => {
        userInteracted = true;
        if(!audioCtx) { try { audioCtx = new(window.AudioContext||window.webkitAudioContext)(); } catch(e){} }
    }, {passive: true, once: true})
);
function _beep() {
    if(!audioCtx) return;
    [[523,0,.15],[659,.1,.15],[784,.2,.3]].forEach(([f,s,e]) => {
        const o=audioCtx.createOscillator(), g=audioCtx.createGain();
        o.connect(g); g.connect(audioCtx.destination);
        o.frequency.value=f; o.type='sine';
        g.gain.setValueAtTime(0.4,audioCtx.currentTime+s);
        g.gain.exponentialRampToValueAtTime(0.001,audioCtx.currentTime+e);
        o.start(audioCtx.currentTime+s); o.stop(audioCtx.currentTime+e);
    });
}
function playSound() {
    if(!userInteracted || !audioCtx) return;
    if(audioCtx.state==='suspended') audioCtx.resume().then(_beep); else _beep();
}
function testSound() { userInteracted=true; if(!audioCtx){audioCtx=new(window.AudioContext||window.webkitAudioContext)();} playSound(); }

// Polling
let lastId = <?= !empty($orders) ? max(array_column($orders,'id')) : 0 ?>;
let isReloading = false;
setInterval(() => {
    if(isReloading) return;
    fetch(`api/new_orders.php?last_id=${lastId}`)
    .then(r => r.json())
    .then(data => {
        if(data.new_orders && data.new_orders.length > 0) {
            const newest = Math.max(...data.new_orders.map(o => parseInt(o.id)));
            if(newest > lastId) {
                lastId = newest;
                playSound();
                isReloading = true;
                setTimeout(() => location.reload(), 800);
            }
        }
    })
    .catch(() => {});
}, 10000);
</script>