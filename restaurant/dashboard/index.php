<?php
session_start();
require_once '../../config/database.php';

if(!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}

$rid = $_SESSION['restaurant_id'];

// ===== Stats =====
$total_dishes = $pdo->prepare("SELECT COUNT(*) FROM dishes WHERE restaurant_id = ?");
$total_dishes->execute([$rid]); $total_dishes = $total_dishes->fetchColumn();

$total_cats = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE restaurant_id = ?");
$total_cats->execute([$rid]); $total_cats = $total_cats->fetchColumn();

$today_orders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND DATE(created_at) = CURDATE()");
$today_orders->execute([$rid]); $today_orders = $today_orders->fetchColumn();

$today_revenue = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE restaurant_id = ? AND DATE(created_at) = CURDATE() AND status != 'cancelled'");
$today_revenue->execute([$rid]); $today_revenue = $today_revenue->fetchColumn();

$pending_orders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status = 'pending'");
$pending_orders->execute([$rid]); $pending_orders = $pending_orders->fetchColumn();

$avg_rating = $pdo->prepare("SELECT ROUND(AVG(rating),1) FROM order_ratings WHERE restaurant_id = ?");
$avg_rating->execute([$rid]); $avg_rating = $avg_rating->fetchColumn() ?? '—';

// ===== Last 7 days revenue (for sparkline) =====
$sparkline = $pdo->prepare("
    SELECT DATE(created_at) as d, COALESCE(SUM(total_price),0) as rev
    FROM orders
    WHERE restaurant_id = ? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      AND status != 'cancelled'
    GROUP BY DATE(created_at)
    ORDER BY d ASC
");
$sparkline->execute([$rid]);
$spark_raw = $sparkline->fetchAll(PDO::FETCH_KEY_PAIR);
$spark_data = [];
for($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $spark_data[] = floatval($spark_raw[$day] ?? 0);
}

// ===== Yesterday comparison =====
$yesterday_rev = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE restaurant_id = ? AND DATE(created_at) = DATE_SUB(CURDATE(),INTERVAL 1 DAY) AND status != 'cancelled'");
$yesterday_rev->execute([$rid]); $yesterday_rev = $yesterday_rev->fetchColumn();
$rev_change = $yesterday_rev > 0 ? round((($today_revenue - $yesterday_rev) / $yesterday_rev) * 100, 1) : 0;

// ===== Last orders =====
$last_orders = $pdo->prepare("SELECT * FROM orders WHERE restaurant_id = ? ORDER BY created_at DESC LIMIT 8");
$last_orders->execute([$rid]); $last_orders = $last_orders->fetchAll();

// ===== Top dish today =====
$top_dish = $pdo->prepare("
    SELECT d.name, SUM(oi.quantity) as qty
    FROM order_items oi
    JOIN dishes d ON d.id = oi.dish_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.restaurant_id = ? AND DATE(o.created_at) = CURDATE()
    GROUP BY oi.dish_id ORDER BY qty DESC LIMIT 1
");
$top_dish->execute([$rid]); $top_dish = $top_dish->fetch();

require_once 'sidebar.php';

// ===== Subscription info ===== (بعد sidebar عشان $restaurant_data يكون جاهز)
$expiry    = $restaurant_data['subscription_expiry'] ?? null;
$days_left = $expiry ? ceil((strtotime($expiry) - time()) / 86400) : 0;
?>
<style>
/* ===== KPI GRID ===== */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 22px;
}
@media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px) { .kpi-grid { grid-template-columns: repeat(2,1fr); gap: 10px; } }

.kpi-card {
    background: var(--bg2);
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 18px;
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, background 0.3s, border-color 0.3s;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.kpi-card:nth-child(1) { animation-delay: 0.05s; }
.kpi-card:nth-child(2) { animation-delay: 0.10s; }
.kpi-card:nth-child(3) { animation-delay: 0.15s; }
.kpi-card:nth-child(4) { animation-delay: 0.20s; }
.kpi-card:hover { transform: translateY(-3px); }

.kpi-orb {
    position: absolute;
    top: -25px; left: -25px;
    width: 90px; height: 90px;
    border-radius: 50%;
    opacity: 0.08; filter: blur(20px);
}

.kpi-top {
    display: flex; justify-content: space-between;
    align-items: flex-start; margin-bottom: 12px;
}
.kpi-icon {
    width: 38px; height: 38px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
}
.kpi-icon svg { width: 17px; height: 17px; }

.kpi-change {
    font-size: 11px; font-weight: 700;
    padding: 3px 8px; border-radius: 20px;
    display: flex; align-items: center; gap: 3px;
}
.kpi-change.up   { background: rgba(34,197,94,0.12); color: #22C55E; }
.kpi-change.down { background: rgba(239,68,68,0.12); color: #EF4444; }
.kpi-change.neu  { background: var(--bg3); color: var(--ink2); }

.kpi-val {
    font-family: 'Fraunces', serif;
    font-size: 26px; font-weight: 900;
    color: var(--ink); letter-spacing: -0.5px;
    line-height: 1; margin-bottom: 4px;
    position: relative; z-index: 1;
    transition: color 0.3s;
}
.kpi-label {
    font-size: 12px; color: var(--ink2); font-weight: 600;
    position: relative; z-index: 1;
}

/* Sparkline */
.sparkline-wrap {
    margin-top: 12px;
    height: 32px;
    position: relative;
}
.sparkline-wrap svg { width: 100%; height: 100%; overflow: visible; }

/* ===== CONTENT GRID ===== */
.content-grid {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 16px;
    margin-bottom: 16px;
}
@media (max-width: 960px) { .content-grid { grid-template-columns: 1fr; } }

/* ===== ORDERS TABLE ===== */
.order-row {
    display: flex; align-items: center;
    padding: 12px 18px; gap: 10px;
    border-bottom: 1px solid var(--line);
    transition: background 0.15s, border-color 0.3s;
}
.order-row:last-child { border-bottom: none; }
.order-row:hover { background: var(--bg3); }

.order-id {
    font-family: 'Fraunces', serif;
    font-size: 13px; font-weight: 700;
    color: var(--ink); min-width: 42px;
}
.order-table-tag {
    background: color-mix(in srgb, var(--p) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--p) 18%, transparent);
    color: var(--p);
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
}
.order-name { flex: 1; font-size: 12px; color: var(--ink2); font-weight: 500; }
.order-price {
    font-family: 'Fraunces', serif;
    font-size: 14px; font-weight: 700; color: var(--p);
}
.order-time { font-size: 11px; color: var(--ink2); min-width: 38px; text-align: left; }

/* ===== RIGHT COLUMN ===== */
.right-col { display: flex; flex-direction: column; gap: 14px; }

/* Quick actions */
.quick-actions { padding: 14px; display: flex; flex-direction: column; gap: 7px; }

.quick-btn {
    display: flex; align-items: center; gap: 11px;
    padding: 12px 14px;
    border-radius: 13px;
    text-decoration: none;
    font-size: 13px; font-weight: 700;
    transition: all 0.2s;
    color: var(--ink);
    background: var(--bg3);
    border: 1px solid var(--line);
}
.quick-btn:hover {
    border-color: var(--p);
    background: color-mix(in srgb, var(--p) 6%, var(--bg3));
    color: var(--p);
    transform: translateX(-3px);
}
.quick-btn.primary {
    background: var(--p);
    color: #fff; border-color: transparent;
    box-shadow: 0 4px 14px color-mix(in srgb, var(--p) 35%, transparent);
}
.quick-btn.primary:hover {
    opacity: 0.88; transform: translateY(-2px); color: #fff;
}
.quick-btn-icon {
    width: 30px; height: 30px;
    border-radius: 9px;
    background: rgba(255,255,255,0.15);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.quick-btn-icon svg { width: 14px; height: 14px; }
.quick-btn:not(.primary) .quick-btn-icon {
    background: var(--bg4);
    border: 1px solid var(--line);
}

/* Sub card */
.sub-rows { padding: 6px 0; }
.sub-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 18px;
    border-bottom: 1px solid var(--line);
    font-size: 13px;
    transition: border-color 0.3s;
}
.sub-row:last-child { border-bottom: none; }
.sub-label { color: var(--ink2); font-weight: 500; }
.sub-value { font-weight: 800; color: var(--ink); font-family: 'Fraunces', serif; font-size: 14px; }

/* Expiry warning */
.expiry-warn {
    margin: 0 14px 14px;
    padding: 11px 14px;
    background: rgba(245,158,11,0.08);
    border: 1px solid rgba(245,158,11,0.2);
    border-radius: 12px;
    font-size: 12px; font-weight: 700;
    color: #F59E0B;
    display: flex; align-items: center; gap: 7px;
}
.expiry-warn svg { width: 14px; height: 14px; flex-shrink: 0; }

/* Top dish highlight */
.top-dish-banner {
    background: var(--bg2);
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 16px 18px;
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 16px;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both 0.3s;
    transition: background 0.3s, border-color 0.3s;
}
.top-dish-icon {
    width: 44px; height: 44px;
    border-radius: 13px;
    background: color-mix(in srgb, #F59E0B 12%, transparent);
    border: 1px solid color-mix(in srgb, #F59E0B 22%, transparent);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.top-dish-info { flex: 1; min-width: 0; }
.top-dish-label { font-size: 11px; color: var(--ink2); font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 3px; }
.top-dish-name {
    font-family: 'Fraunces', serif;
    font-size: 15px; font-weight: 700; color: var(--ink);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.top-dish-qty { font-size: 12px; color: var(--p); font-weight: 700; margin-top: 2px; }

/* Pending alert */
.pending-alert {
    background: rgba(245,158,11,0.08);
    border: 1px solid rgba(245,158,11,0.2);
    border-radius: 14px;
    padding: 13px 16px;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 16px;
    text-decoration: none;
    transition: all 0.2s;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both 0.25s;
}
.pending-alert:hover { background: rgba(245,158,11,0.14); transform: translateX(-2px); }
.pending-num {
    font-family: 'Fraunces', serif;
    font-size: 28px; font-weight: 900;
    color: #F59E0B; line-height: 1;
}
.pending-text { flex: 1; }
.pending-title { font-size: 13px; font-weight: 800; color: #F59E0B; margin-bottom: 2px; }
.pending-sub   { font-size: 11px; color: var(--ink2); font-weight: 500; }
.pending-arrow { color: #F59E0B; }
.pending-arrow svg { width: 16px; height: 16px; }
</style>

<div class="main">

    <!-- Page Head -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:12px;">
        <div>
            <div class="page-title">أهلاً، <?= htmlspecialchars($_SESSION['restaurant_name']) ?> 👋</div>
            <div class="page-subtitle">نظرة عامة على مطعمك — <?= date('l، d/m/Y') ?></div>
        </div>
        <a href="orders.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            الطلبات
        </a>
    </div>

    <!-- Pending Alert -->
    <?php if($pending_orders > 0): ?>
    <a href="orders.php?status=pending" class="pending-alert">
        <div class="pending-num"><?= $pending_orders ?></div>
        <div class="pending-text">
            <div class="pending-title">طلب<?= $pending_orders > 1 ? 'ات' : '' ?> بانتظار التأكيد</div>
            <div class="pending-sub">اضغط للمعالجة الآن</div>
        </div>
        <div class="pending-arrow">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        </div>
    </a>
    <?php endif; ?>

    <!-- Top Dish Today -->
    <?php if($top_dish): ?>
    <div class="top-dish-banner">
        <div class="top-dish-icon">🏆</div>
        <div class="top-dish-info">
            <div class="top-dish-label">⭐ الأكثر طلباً اليوم</div>
            <div class="top-dish-name"><?= htmlspecialchars($top_dish['name']) ?></div>
            <div class="top-dish-qty">×<?= $top_dish['qty'] ?> طلب</div>
        </div>
        <a href="stats.php" class="btn btn-secondary btn-sm">التفاصيل</a>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="kpi-grid">

        <!-- إيرادات اليوم -->
        <div class="kpi-card">
            <div class="kpi-orb" style="background:var(--p);"></div>
            <div class="kpi-top">
                <div class="kpi-icon" style="background:color-mix(in srgb,var(--p) 14%,transparent);color:var(--p);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div class="kpi-change <?= $rev_change >= 0 ? 'up' : 'down' ?>">
                    <?= $rev_change >= 0 ? '↑' : '↓' ?> <?= abs($rev_change) ?>%
                </div>
            </div>
            <div class="kpi-val">$<?= number_format($today_revenue, 0) ?></div>
            <div class="kpi-label">إيرادات اليوم</div>
            <div class="sparkline-wrap" id="spark"></div>
        </div>

        <!-- طلبات اليوم -->
        <div class="kpi-card">
            <div class="kpi-orb" style="background:#22C55E;"></div>
            <div class="kpi-top">
                <div class="kpi-icon" style="background:rgba(34,197,94,0.12);color:#22C55E;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/><path d="M9 12h6M9 16h4"/></svg>
                </div>
                <div class="kpi-change neu">اليوم</div>
            </div>
            <div class="kpi-val"><?= $today_orders ?></div>
            <div class="kpi-label">طلبات اليوم</div>
        </div>

        <!-- الأطباق -->
        <div class="kpi-card">
            <div class="kpi-orb" style="background:#8B5CF6;"></div>
            <div class="kpi-top">
                <div class="kpi-icon" style="background:rgba(139,92,246,0.12);color:#8B5CF6;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                </div>
                <div class="kpi-change neu"><?= $total_cats ?> تصنيف</div>
            </div>
            <div class="kpi-val"><?= $total_dishes ?></div>
            <div class="kpi-label">إجمالي الأطباق</div>
        </div>

        <!-- التقييم -->
        <div class="kpi-card">
            <div class="kpi-orb" style="background:#F59E0B;"></div>
            <div class="kpi-top">
                <div class="kpi-icon" style="background:rgba(245,158,11,0.12);color:#F59E0B;">
                    <svg viewBox="0 0 24 24" fill="#F59E0B" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </div>
                <div class="kpi-change neu">/ 5</div>
            </div>
            <div class="kpi-val"><?= $avg_rating ?></div>
            <div class="kpi-label">متوسط التقييم</div>
        </div>

    </div>

    <!-- Content Grid -->
    <div class="content-grid">

        <!-- آخر الطلبات -->
        <div class="card card-in">
            <div class="card-header">
                <h3>آخر الطلبات</h3>
                <a href="orders.php">عرض الكل ←</a>
            </div>
            <?php if(empty($last_orders)): ?>
                <div class="empty-state" style="padding:40px 20px;">
                    <div class="empty-state-icon">📋</div>
                    <p>ما في طلبات بعد</p>
                </div>
            <?php else:
                $status_labels = [
                    'pending'   => 'معلق',
                    'confirmed' => 'مؤكد',
                    'preparing' => 'بالتحضير',
                    'ready'     => 'جاهز',
                    'delivered' => 'تم',
                    'cancelled' => 'ملغي',
                ];
                foreach($last_orders as $o): ?>
                <div class="order-row">
                    <div class="order-id">#<?= $o['id'] ?></div>
                    <div class="order-table-tag">طاولة <?= htmlspecialchars($o['table_number']) ?></div>
                    <?php if($o['customer_name']): ?>
                        <div class="order-name"><?= htmlspecialchars($o['customer_name']) ?></div>
                    <?php else: ?>
                        <div class="order-name" style="flex:1;"></div>
                    <?php endif; ?>
                    <div class="order-price">$<?= number_format($o['total_price'], 2) ?></div>
                    <span class="badge badge-<?= $o['status'] ?>"><?= $status_labels[$o['status']] ?></span>
                    <div class="order-time"><?= date('H:i', strtotime($o['created_at'])) ?></div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Right Column -->
        <div class="right-col">

            <!-- Quick Actions -->
            <div class="card card-in" style="animation-delay:0.1s">
                <div class="card-header"><h3>إجراءات سريعة</h3></div>
                <div class="quick-actions">
                    <a href="dishes.php" class="quick-btn primary">
                        <div class="quick-btn-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </div>
                        إضافة طبق جديد
                    </a>
                    <a href="categories.php" class="quick-btn">
                        <div class="quick-btn-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                        </div>
                        إدارة التصنيفات
                    </a>
                    <a href="qr.php" class="quick-btn">
                        <div class="quick-btn-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="5" height="5"/><rect x="16" y="3" width="5" height="5"/><rect x="3" y="16" width="5" height="5"/></svg>
                        </div>
                        تحميل رمز QR
                    </a>
                    <a href="profile.php" class="quick-btn">
                        <div class="quick-btn-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                        </div>
                        تخصيص المنيو
                    </a>
                </div>
            </div>

            <!-- Subscription -->
            <div class="card card-in" style="animation-delay:0.2s">
                <div class="card-header"><h3>الاشتراك</h3></div>
                <div class="sub-rows">
                    <?php
                    $plans = ['basic'=>'🥉 أساسية','advanced'=>'🥈 متقدمة','premium'=>'⭐ بريميوم'];
                    ?>
                    <div class="sub-row">
                        <span class="sub-label">الباقة</span>
                        <span class="badge badge-<?= $restaurant_data['subscription_plan'] ?>">
                            <?= $plans[$restaurant_data['subscription_plan']] ?>
                        </span>
                    </div>
                    <div class="sub-row">
                        <span class="sub-label">تنتهي في</span>
                        <span class="sub-value" style="font-size:13px;">
                            <?= $expiry ? date('d/m/Y', strtotime($expiry)) : '—' ?>
                        </span>
                    </div>
                    <div class="sub-row">
                        <span class="sub-label">الأيام المتبقية</span>
                        <span class="sub-value" style="color:<?= $days_left <= 7 ? '#EF4444' : ($days_left <= 14 ? '#F59E0B' : '#22C55E') ?>">
                            <?= $days_left > 0 ? $days_left . ' يوم' : 'منتهي' ?>
                        </span>
                    </div>
                </div>
                <?php if($days_left <= 7 && $days_left > 0): ?>
                <div class="expiry-warn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    اشتراكك ينتهي قريباً، تواصل معنا للتجديد
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div>

<script>
// ===== Sparkline =====
(function() {
    const data   = <?= json_encode($spark_data) ?>;
    const wrap   = document.getElementById('spark');
    if(!wrap || !data.length) return;

    const max  = Math.max(...data) || 1;
    const w    = 200, h = 32;
    const pts  = data.map((v, i) => {
        const x = (i / (data.length - 1)) * w;
        const y = h - (v / max) * h;
        return [x, y];
    });

    // Smooth path
    let d = `M ${pts[0][0]} ${pts[0][1]}`;
    for(let i = 1; i < pts.length; i++) {
        const cp1x = (pts[i-1][0] + pts[i][0]) / 2;
        d += ` C ${cp1x} ${pts[i-1][1]}, ${cp1x} ${pts[i][1]}, ${pts[i][0]} ${pts[i][1]}`;
    }

    // Fill path
    const fillD = d + ` L ${pts[pts.length-1][0]} ${h} L ${pts[0][0]} ${h} Z`;

    const primary = getComputedStyle(document.documentElement).getPropertyValue('--p').trim() || '#FF6B35';

    wrap.innerHTML = `
        <svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
            <defs>
                <linearGradient id="sg" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="${primary}" stop-opacity="0.25"/>
                    <stop offset="100%" stop-color="${primary}" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <path d="${fillD}" fill="url(#sg)"/>
            <path d="${d}" fill="none" stroke="${primary}" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
    `;
})();
</script>

</body>
</html>