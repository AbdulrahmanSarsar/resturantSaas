<?php
/**
 * stats.php — v3 (فلترة الفروع)
 * صفحة قراءة فقط — ما بنحتاج dual write
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once 'plan_guard.php';
if(!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}
plan_required('advanced');

$rid    = $_SESSION['restaurant_id'];
$period = $_GET['period'] ?? '30';
$date_from = date('Y-m-d', strtotime("-{$period} days"));

// ===== فلترة الفرع من الجلسة =====
$active_branch_id = $_SESSION['active_branch_id'] ?? null;
$bf  = $active_branch_id ? "AND branch_id = ?"    : "";
$bfo = $active_branch_id ? "AND o.branch_id = ?"  : "";
$bp  = $active_branch_id ? [$active_branch_id]     : [];

// ===== General =====
$general = $pdo->prepare("
    SELECT
        COUNT(*)                                                      AS total_orders,
        COALESCE(SUM(total_price), 0)                                 AS total_revenue,
        COALESCE(AVG(total_price), 0)                                 AS avg_order,
        SUM(status = 'delivered')                                     AS delivered,
        SUM(status = 'cancelled')                                     AS cancelled,
        SUM(status IN ('pending','confirmed','preparing','ready'))    AS active
    FROM orders
    WHERE restaurant_id = ? $bf AND DATE(created_at) >= ?
");
$general->execute(array_merge([$rid], $bp, [$date_from]));
$general = $general->fetch();

// ===== Top Dishes =====
$top_dishes = $pdo->prepare("
    SELECT d.name, d.image, d.price,
        SUM(oi.quantity)                 AS total_qty,
        SUM(oi.quantity * oi.dish_price) AS total_revenue,
        COUNT(DISTINCT oi.order_id)      AS order_count
    FROM order_items oi
    JOIN dishes d ON d.id = oi.dish_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.restaurant_id = ? $bfo AND o.status = 'delivered' AND DATE(o.created_at) >= ?
    GROUP BY oi.dish_id ORDER BY total_qty DESC LIMIT 8
");
$top_dishes->execute(array_merge([$rid], $bp, [$date_from]));
$top_dishes = $top_dishes->fetchAll();

// ===== Low Dishes =====
$low_dishes = $pdo->prepare("
    SELECT d.name, d.image, COALESCE(SUM(oi.quantity), 0) AS total_qty
    FROM dishes d
    LEFT JOIN order_items oi ON oi.dish_id = d.id
    LEFT JOIN orders o ON o.id = oi.order_id
        AND o.status = 'delivered' AND DATE(o.created_at) >= ?
    WHERE d.restaurant_id = ?
    GROUP BY d.id ORDER BY total_qty ASC LIMIT 5
");
$low_dishes->execute([$date_from, $rid]);
$low_dishes = $low_dishes->fetchAll();

// ===== Daily Orders =====
$daily_orders = $pdo->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS orders,
        COALESCE(SUM(total_price), 0) AS revenue
    FROM orders
    WHERE restaurant_id = ? $bf AND DATE(created_at) >= ?
    GROUP BY DATE(created_at) ORDER BY day ASC
");
$daily_orders->execute(array_merge([$rid], $bp, [$date_from]));
$daily_orders = $daily_orders->fetchAll();

// ===== Hourly =====
$hourly = $pdo->prepare("
    SELECT HOUR(created_at) AS hour, COUNT(*) AS count
    FROM orders WHERE restaurant_id = ? $bf AND DATE(created_at) >= ?
    GROUP BY HOUR(created_at) ORDER BY hour ASC
");
$hourly->execute(array_merge([$rid], $bp, [$date_from]));
$hourly_raw = $hourly->fetchAll(PDO::FETCH_KEY_PAIR);
$hourly_data = [];
for($h = 0; $h < 24; $h++) { $hourly_data[$h] = $hourly_raw[$h] ?? 0; }

// ===== Weekday =====
$weekday = $pdo->prepare("
    SELECT DAYOFWEEK(created_at) AS dow, COUNT(*) AS count
    FROM orders WHERE restaurant_id = ? $bf AND DATE(created_at) >= ?
    GROUP BY DAYOFWEEK(created_at) ORDER BY dow ASC
");
$weekday->execute(array_merge([$rid], $bp, [$date_from]));
$weekday_raw    = $weekday->fetchAll(PDO::FETCH_KEY_PAIR);
$weekday_labels = ['', 'الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$weekday_data   = [];
for($d = 1; $d <= 7; $d++) { $weekday_data[] = $weekday_raw[$d] ?? 0; }

// ===== Top Cats =====
$top_cats = $pdo->prepare("
    SELECT c.name, SUM(oi.quantity) AS total_qty
    FROM order_items oi
    JOIN dishes d ON d.id = oi.dish_id
    JOIN categories c ON c.id = d.category_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.restaurant_id = ? $bfo AND o.status = 'delivered' AND DATE(o.created_at) >= ?
    GROUP BY c.id ORDER BY total_qty DESC LIMIT 6
");
$top_cats->execute(array_merge([$rid], $bp, [$date_from]));
$top_cats = $top_cats->fetchAll();

// ===== Rates & Comparison =====
$cancel_rate  = $general['total_orders'] > 0 ? round(($general['cancelled'] / $general['total_orders']) * 100, 1) : 0;
$deliver_rate = $general['total_orders'] > 0 ? round(($general['delivered'] / $general['total_orders']) * 100, 1) : 0;

$prev_from = date('Y-m-d', strtotime("-" . ($period * 2) . " days"));
$prev_to   = date('Y-m-d', strtotime("-{$period} days"));
$prev = $pdo->prepare("
    SELECT COUNT(*) AS total_orders, COALESCE(SUM(total_price),0) AS total_revenue
    FROM orders WHERE restaurant_id = ? $bf AND DATE(created_at) BETWEEN ? AND ?
");
$prev->execute(array_merge([$rid], $bp, [$prev_from, $prev_to]));
$prev = $prev->fetch();

$orders_change  = $prev['total_orders']  > 0 ? round((($general['total_orders']  - $prev['total_orders'])  / $prev['total_orders'])  * 100, 1) : 0;
$revenue_change = $prev['total_revenue'] > 0 ? round((($general['total_revenue'] - $prev['total_revenue']) / $prev['total_revenue']) * 100, 1) : 0;

// ===== Offers Stats =====
$offers_stats = $pdo->prepare("
    SELECT
        COUNT(DISTINCT o.id) as total_orders_with_offers,
        SUM(oi.quantity) as total_offers_sold,
        COALESCE(SUM(oi.dish_price * oi.quantity), 0) as total_offers_revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.restaurant_id = ?
      $bfo
      AND o.status = 'delivered'
      AND oi.dish_id IS NULL
      AND DATE(o.created_at) >= ?
");
$offers_stats->execute(array_merge([$rid], $bp, [$date_from]));
$offers_stats = $offers_stats->fetch();

$top_offers = $pdo->prepare("
    SELECT oi.dish_name,
        SUM(oi.quantity) as total_qty,
        COALESCE(SUM(oi.dish_price * oi.quantity), 0) as total_revenue,
        COUNT(DISTINCT oi.order_id) as order_count
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.restaurant_id = ?
      $bfo
      AND o.status = 'delivered'
      AND oi.dish_id IS NULL
      AND DATE(o.created_at) >= ?
    GROUP BY oi.dish_name
    ORDER BY total_qty DESC
    LIMIT 6
");
$top_offers->execute(array_merge([$rid], $bp, [$date_from]));
$top_offers = $top_offers->fetchAll();

require_once 'sidebar.php';
?>
<style>
/* ===== HEADER ROW ===== */
.stats-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    flex-wrap: wrap; gap: 14px; margin-bottom: 22px;
}

/* ===== PERIOD TABS ===== */
.period-tabs {
    display: flex; gap: 5px;
    background: var(--bg2); border: 1px solid var(--line);
    padding: 5px; border-radius: 14px;
    transition: background 0.3s, border-color 0.3s;
}
.period-tab {
    padding: 7px 16px; border-radius: 10px;
    font-size: 12px; font-weight: 700;
    color: var(--ink2); text-decoration: none;
    transition: all 0.2s; font-family: 'Tajawal', sans-serif;
    border: none; background: none; cursor: pointer;
}
.period-tab:hover { color: var(--p); }
.period-tab.active {
    background: var(--p); color: #fff;
    box-shadow: 0 3px 10px color-mix(in srgb, var(--p) 35%, transparent);
}

/* ===== KPI GRID ===== */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 13px; margin-bottom: 20px;
}
@media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px) { .kpi-grid { gap: 9px; } }

.kpi-card {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; padding: 18px;
    position: relative; overflow: hidden;
    transition: transform 0.2s, background 0.3s, border-color 0.3s;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.kpi-card:hover { transform: translateY(-3px); }
.kpi-card:nth-child(1){animation-delay:.04s}
.kpi-card:nth-child(2){animation-delay:.08s}
.kpi-card:nth-child(3){animation-delay:.12s}
.kpi-card:nth-child(4){animation-delay:.16s}

.kpi-orb {
    position: absolute; top: -25px; left: -25px;
    width: 85px; height: 85px; border-radius: 50%;
    opacity: 0.07; filter: blur(20px); pointer-events: none;
}
.kpi-icon-box {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 12px; position: relative; z-index: 1;
}
.kpi-icon-box svg { width: 17px; height: 17px; }
.kpi-val {
    font-family: 'Fraunces', serif;
    font-size: 26px; font-weight: 900; color: var(--ink);
    letter-spacing: -0.5px; line-height: 1; margin-bottom: 4px;
    position: relative; z-index: 1; transition: color 0.3s;
}
.kpi-lbl { font-size: 11px; color: var(--ink2); font-weight: 600; position: relative; z-index:1; }
.kpi-chg {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 11px; font-weight: 700;
    padding: 3px 8px; border-radius: 20px; margin-top: 6px;
    position: relative; z-index:1;
}
.chg-up   { background: rgba(34,197,94,0.12);  color: #22C55E; }
.chg-down { background: rgba(239,68,68,0.12);  color: #EF4444; }
.chg-neu  { background: var(--bg3); color: var(--ink2); }

/* ===== CHARTS GRID ===== */
.charts-2col {
    display: grid; grid-template-columns: 2fr 1fr;
    gap: 14px; margin-bottom: 14px;
}
@media (max-width: 900px) { .charts-2col { grid-template-columns: 1fr; } }

.chart-card {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; overflow: hidden;
    transition: background 0.3s, border-color 0.3s;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.chart-card-head {
    padding: 14px 18px 10px;
    border-bottom: 1px solid var(--line);
    display: flex; justify-content: space-between; align-items: center;
    transition: border-color 0.3s;
}
.chart-card-head h3 {
    font-family: 'Fraunces', serif;
    font-size: 14px; font-weight: 700; color: var(--ink); transition: color 0.3s;
}
.chart-card-head span { font-size: 11px; color: var(--ink2); font-weight: 600; }
.chart-pad { padding: 16px 18px 20px; }

/* Donut */
.donut-wrap {
    display: flex; align-items: center; justify-content: center;
    gap: 20px; padding: 16px 18px; flex-wrap: wrap;
}
.donut-legend { display: flex; flex-direction: column; gap: 9px; }
.legend-row   { display: flex; align-items: center; gap: 8px; }
.legend-dot   { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.legend-lbl   { font-size: 12px; color: var(--ink2); font-weight: 600; }
.legend-num   {
    font-family: 'Fraunces', serif;
    font-size: 14px; font-weight: 900; color: var(--ink);
    margin-right: auto; padding-right: 6px; transition: color 0.3s;
}

/* ===== TOP DISHES ===== */
.section-head {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 14px; margin-top: 6px;
}
.section-head h3 {
    font-family: 'Fraunces', serif;
    font-size: 15px; font-weight: 700; color: var(--ink); transition: color 0.3s;
}
.section-head::after { content:''; flex:1; height:1px; background: var(--line); }

.top-dishes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 12px; margin-bottom: 20px;
}

.dsc {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 16px; overflow: hidden;
    transition: transform 0.2s, background 0.3s, border-color 0.3s;
    animation: cardIn 0.35s cubic-bezier(0.22,1,0.36,1) both;
    position: relative;
}
.dsc:hover { transform: translateY(-3px); }

.dsc-rank {
    position: absolute; top: 8px; right: 8px; z-index: 2;
    width: 26px; height: 26px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800;
}
.dsc-rank.r1 { background: linear-gradient(135deg, #f6d365, #fda085); color: #fff; }
.dsc-rank.r2 { background: var(--bg3); color: var(--ink2); border: 1px solid var(--line); }
.dsc-rank.r3 { background: var(--bg3); color: var(--ink2); border: 1px solid var(--line); }
.dsc-rank.rn { background: var(--bg3); color: var(--ink2); border: 1px solid var(--line); }

.dsc-img {
    height: 100px; background: var(--bg3);
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; overflow: hidden; transition: background 0.3s;
}
.dsc-img img { width:100%; height:100%; object-fit: cover; }

.dsc-body { padding: 11px 13px 12px; }
.dsc-name {
    font-family: 'Fraunces', serif;
    font-size: 13px; font-weight: 700; color: var(--ink);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 8px; transition: color 0.3s;
}
.dsc-bar-bg {
    background: var(--bg3); border-radius: 10px; height: 5px;
    margin-bottom: 7px; overflow: hidden; transition: background 0.3s;
}
.dsc-bar {
    height: 100%; border-radius: 10px;
    background: var(--p);
    box-shadow: 0 0 6px color-mix(in srgb, var(--p) 40%, transparent);
}
.dsc-meta {
    display: flex; justify-content: space-between; font-size: 11px;
}
.dsc-qty  { color: var(--p); font-weight: 800; }
.dsc-rev  { color: var(--ink2); font-weight: 600; }

/* ===== HEATMAP ===== */
.hours-grid {
    display: grid; grid-template-columns: repeat(12, 1fr);
    gap: 4px;
}
@media (max-width: 600px) { .hours-grid { grid-template-columns: repeat(8, 1fr); } }

.hcell {
    aspect-ratio: 1; border-radius: 7px;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    cursor: default; position: relative;
    transition: transform 0.15s;
}
.hcell:hover { transform: scale(1.12); z-index: 2; }
.hcell-h { font-size: 10px; font-weight: 800; }
.hcell-c { font-size: 9px; margin-top: 1px; }

/* ===== WEEKDAY BARS ===== */
.wd-bars {
    display: flex; align-items: flex-end; gap: 7px;
    height: 90px; padding: 0 6px 8px;
}
.wd-col {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; height: 100%; justify-content: flex-end; gap: 4px;
}
.wd-bar {
    width: 100%; border-radius: 6px 6px 0 0;
    background: var(--p); min-height: 4px; transition: height 0.6s ease;
}
.wd-label { font-size: 10px; color: var(--ink2); font-weight: 700; }
.wd-count { font-size: 10px; color: var(--p); font-weight: 800; }

/* ===== BOTTOM GRID ===== */
.bottom-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 14px; margin-bottom: 14px;
}
@media (max-width: 700px) { .bottom-grid { grid-template-columns: 1fr; } }

/* Cat rows */
.cat-row {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 18px; border-bottom: 1px solid var(--line);
    transition: background 0.15s, border-color 0.3s;
}
.cat-row:last-child { border-bottom: none; }
.cat-row:hover { background: var(--bg3); }
.cat-rname { flex: 1; font-size: 13px; font-weight: 700; color: var(--ink); transition: color 0.3s; }
.cat-rbar-bg {
    width: 90px; background: var(--bg3); border-radius: 10px;
    height: 7px; overflow: hidden; transition: background 0.3s;
}
.cat-rbar { height: 100%; border-radius: 10px; background: var(--p); }
.cat-rqty { font-family: 'Fraunces', serif; font-size: 13px; font-weight: 800; color: var(--p); min-width: 32px; text-align: left; }

/* Low dish rows */
.low-row {
    display: flex; align-items: center; gap: 11px;
    padding: 11px 18px; border-bottom: 1px solid var(--line);
    transition: background 0.15s, border-color 0.3s;
}
.low-row:last-child { border-bottom: none; }
.low-row:hover { background: var(--bg3); }
.low-img {
    width: 36px; height: 36px; border-radius: 10px;
    background: var(--bg3); border: 1px solid var(--line);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0; overflow: hidden;
    transition: background 0.3s, border-color 0.3s;
}
.low-img img { width: 100%; height: 100%; object-fit: cover; }
.low-name { flex: 1; font-size: 13px; font-weight: 700; color: var(--ink); transition: color 0.3s; }
.low-qty  { font-size: 12px; font-weight: 800; color: #EF4444; }
</style>

<div class="main">

    <!-- Header -->
    <div class="stats-header">
        <div>
            <div class="page-title">الإحصائيات</div>
            <div class="page-subtitle">تحليل أداء مطعمك — آخر <?= $period ?> يوم</div>
        </div>
        <div class="period-tabs">
            <?php foreach(['7'=>'7 أيام','30'=>'30 يوم','90'=>'3 أشهر','365'=>'سنة'] as $val=>$label): ?>
            <a href="?period=<?= $val ?>" class="period-tab <?= $period==$val?'active':'' ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-orb" style="background:var(--p)"></div>
            <div class="kpi-icon-box" style="background:color-mix(in srgb,var(--p) 14%,transparent);color:var(--p)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
            </div>
            <div class="kpi-val"><?= number_format($general['total_orders']) ?></div>
            <div class="kpi-lbl">إجمالي الطلبات</div>
            <div class="kpi-chg <?= $orders_change >= 0 ? 'chg-up' : 'chg-down' ?>">
                <?= $orders_change >= 0 ? '↑' : '↓' ?> <?= abs($orders_change) ?>%
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-orb" style="background:#22C55E"></div>
            <div class="kpi-icon-box" style="background:rgba(34,197,94,0.12);color:#22C55E">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            </div>
            <div class="kpi-val">$<?= number_format($general['total_revenue'], 0) ?></div>
            <div class="kpi-lbl">إجمالي الإيرادات</div>
            <div class="kpi-chg <?= $revenue_change >= 0 ? 'chg-up' : 'chg-down' ?>">
                <?= $revenue_change >= 0 ? '↑' : '↓' ?> <?= abs($revenue_change) ?>%
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-orb" style="background:#F59E0B"></div>
            <div class="kpi-icon-box" style="background:rgba(245,158,11,0.12);color:#F59E0B">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            </div>
            <div class="kpi-val">$<?= number_format($general['avg_order'], 1) ?></div>
            <div class="kpi-lbl">متوسط قيمة الطلب</div>
            <div class="kpi-chg chg-neu">━ متوسط</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-orb" style="background:#EF4444"></div>
            <div class="kpi-icon-box" style="background:rgba(239,68,68,0.12);color:#EF4444">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div class="kpi-val"><?= $cancel_rate ?>%</div>
            <div class="kpi-lbl">نسبة الإلغاء</div>
            <div class="kpi-chg <?= $cancel_rate > 10 ? 'chg-down' : 'chg-up' ?>">
                <?= $cancel_rate > 10 ? '↑ مرتفع' : '✓ طبيعي' ?>
            </div>
        </div>
    </div>

    <!-- Daily Chart + Donut -->
    <div class="charts-2col">
        <div class="chart-card" style="animation-delay:0.1s">
            <div class="chart-card-head">
                <h3>الطلبات اليومية</h3>
                <span>آخر <?= $period ?> يوم</span>
            </div>
            <div class="chart-pad">
                <canvas id="dailyChart" height="110"></canvas>
            </div>
        </div>
        <div class="chart-card" style="animation-delay:0.15s">
            <div class="chart-card-head">
                <h3>حالة الطلبات</h3>
            </div>
            <div class="donut-wrap">
                <canvas id="statusChart" width="120" height="120"></canvas>
                <div class="donut-legend">
                    <div class="legend-row">
                        <div class="legend-dot" style="background:#22C55E"></div>
                        <span class="legend-num"><?= $general['delivered'] ?></span>
                        <span class="legend-lbl">مسلّمة</span>
                    </div>
                    <div class="legend-row">
                        <div class="legend-dot" style="background:var(--p)"></div>
                        <span class="legend-num"><?= $general['active'] ?></span>
                        <span class="legend-lbl">نشطة</span>
                    </div>
                    <div class="legend-row">
                        <div class="legend-dot" style="background:#EF4444"></div>
                        <span class="legend-num"><?= $general['cancelled'] ?></span>
                        <span class="legend-lbl">ملغية</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Dishes -->
    <?php if(!empty($top_dishes)): ?>
    <div class="section-head"><h3>🏆 أكثر الأطباق طلباً</h3></div>
    <div class="top-dishes-grid">
        <?php $max_qty = $top_dishes[0]['total_qty'] ?? 1;
        foreach($top_dishes as $i => $dish):
            $rank = $i + 1;
            $pct  = $max_qty > 0 ? ($dish['total_qty'] / $max_qty) * 100 : 0;
            $rc   = $rank===1?'r1':($rank===2?'r2':($rank===3?'r3':'rn'));
        ?>
        <div class="dsc" style="animation-delay:<?= min($i*0.05,0.4) ?>s">
            <div class="dsc-rank <?= $rc ?>"><?= $rank<=3 ? ['🥇','🥈','🥉'][$rank-1] : $rank ?></div>
            <div class="dsc-img">
                <?php if($dish['image']): ?>
                    <img src="<?= BASE_URL ?>/assets/uploads/dishes/<?= $dish['image'] ?>" loading="lazy">
                <?php else: ?>🍔<?php endif; ?>
            </div>
            <div class="dsc-body">
                <div class="dsc-name"><?= htmlspecialchars($dish['name']) ?></div>
                <div class="dsc-bar-bg"><div class="dsc-bar" style="width:<?= $pct ?>%"></div></div>
                <div class="dsc-meta">
                    <span class="dsc-qty">×<?= $dish['total_qty'] ?> طلب</span>
                    <span class="dsc-rev">$<?= number_format($dish['total_revenue'],0) ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Heatmap + Weekday -->
    <div class="bottom-grid" style="margin-top:4px;">
        <div class="chart-card">
            <div class="chart-card-head">
                <h3>أوقات الذروة</h3>
                <span>حسب الساعة</span>
            </div>
            <div class="chart-pad">
                <?php
                $max_hour  = max($hourly_data) ?: 1;
                $peak_hour = array_search(max($hourly_data), $hourly_data);
                ?>
                <div style="font-size:12px;color:var(--ink2);margin-bottom:12px;font-weight:600;">
                    ⚡ ذروة الساعة
                    <strong style="color:var(--p)"><?= $peak_hour ?>:00 – <?= $peak_hour+1 ?>:00</strong>
                </div>
                <div class="hours-grid">
                    <?php foreach($hourly_data as $h => $count):
                        $intensity = $max_hour > 0 ? ($count / $max_hour) : 0;
                        $is_peak   = ($h === $peak_hour);
                        $alpha_val = max(0.06, $intensity * 0.55);
                    ?>
                    <div class="hcell"
                         style="background:<?= $is_peak
                            ? 'var(--p)'
                            : "color-mix(in srgb,var(--p) " . round($alpha_val*100) . "%,transparent)" ?>"
                         title="الساعة <?= $h ?>:00 — <?= $count ?> طلب">
                        <span class="hcell-h" style="color:<?= ($is_peak||$intensity>0.6)?'#fff':'var(--ink2)' ?>"><?= $h ?></span>
                        <?php if($count>0): ?>
                        <span class="hcell-c" style="color:<?= ($is_peak||$intensity>0.6)?'rgba(255,255,255,0.75)':'var(--p)' ?>"><?= $count ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-card-head">
                <h3>أيام الأسبوع</h3>
                <span>توزيع الطلبات</span>
            </div>
            <div class="chart-pad">
                <?php $max_day = max($weekday_data) ?: 1; ?>
                <div class="wd-bars">
                    <?php foreach($weekday_data as $i => $count):
                        $pct    = ($count / $max_day) * 100;
                        $is_max = ($count === max($weekday_data));
                    ?>
                    <div class="wd-col">
                        <div class="wd-count"><?= $count ?: '' ?></div>
                        <div class="wd-bar" style="height:<?= max(4,$pct) ?>%;opacity:<?= $is_max?'1':'0.4' ?>"></div>
                        <div class="wd-label"><?= mb_substr($weekday_labels[$i+1],0,3) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Categories + Low Dishes -->
    <?php if(!empty($top_cats) || !empty($low_dishes)): ?>
    <div class="bottom-grid">
        <?php if(!empty($top_cats)): ?>
        <div class="chart-card">
            <div class="chart-card-head"><h3>أكثر التصنيفات</h3></div>
            <?php $max_cat = $top_cats[0]['total_qty'] ?? 1;
            foreach($top_cats as $cat):
                $pct = $max_cat > 0 ? ($cat['total_qty'] / $max_cat) * 100 : 0; ?>
            <div class="cat-row">
                <span class="cat-rname"><?= htmlspecialchars($cat['name']) ?></span>
                <div class="cat-rbar-bg"><div class="cat-rbar" style="width:<?= $pct ?>%"></div></div>
                <span class="cat-rqty">×<?= $cat['total_qty'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($low_dishes)): ?>
        <div class="chart-card">
            <div class="chart-card-head">
                <h3>أقل الأطباق طلباً</h3>
                <span style="color:#EF4444;font-size:11px;font-weight:700;">تحتاج اهتمام</span>
            </div>
            <?php foreach($low_dishes as $d): ?>
            <div class="low-row">
                <div class="low-img">
                    <?php if($d['image']): ?>
                        <img src="<?= BASE_URL ?>/assets/uploads/dishes/<?= $d['image'] ?>" loading="lazy">
                    <?php else: ?>🍔<?php endif; ?>
                </div>
                <span class="low-name"><?= htmlspecialchars($d['name']) ?></span>
                <span class="low-qty"><?= $d['total_qty']==0 ? 'لم يُطلب' : '×'.$d['total_qty'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>



    <!-- ===== OFFERS SECTION ===== -->
    <?php if(!empty($top_offers) || ($offers_stats['total_offers_sold'] ?? 0) > 0): ?>
    <div class="section-head" style="margin-top:6px;"><h3>🎁 تحليل العروض</h3></div>

    <!-- KPIs -->
    <div class="kpi-grid" style="margin-bottom:14px;">
        <div class="kpi-card" style="animation-delay:.04s">
            <div class="kpi-orb" style="background:var(--p)"></div>
            <div class="kpi-icon-box" style="background:color-mix(in srgb,var(--p) 14%,transparent);color:var(--p)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg>
            </div>
            <div class="kpi-val"><?= number_format($offers_stats['total_offers_sold'] ?? 0) ?></div>
            <div class="kpi-lbl">عروض مباعة</div>
            <div class="kpi-chg chg-up">🎁 كل العروض</div>
        </div>
        <div class="kpi-card" style="animation-delay:.08s">
            <div class="kpi-orb" style="background:#22C55E"></div>
            <div class="kpi-icon-box" style="background:rgba(34,197,94,.12);color:#22C55E">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            </div>
            <div class="kpi-val">$<?= number_format($offers_stats['total_offers_revenue'] ?? 0, 0) ?></div>
            <div class="kpi-lbl">إيرادات العروض</div>
            <div class="kpi-chg chg-up">↑ إيرادات</div>
        </div>
        <div class="kpi-card" style="animation-delay:.12s">
            <div class="kpi-orb" style="background:#8B5CF6"></div>
            <div class="kpi-icon-box" style="background:rgba(139,92,246,.12);color:#8B5CF6">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
            </div>
            <div class="kpi-val"><?= number_format($offers_stats['total_orders_with_offers'] ?? 0) ?></div>
            <div class="kpi-lbl">طلبات فيها عرض</div>
            <div class="kpi-chg chg-neu">━ طلب</div>
        </div>
        <div class="kpi-card" style="animation-delay:.16s">
            <div class="kpi-orb" style="background:#F59E0B"></div>
            <div class="kpi-icon-box" style="background:rgba(245,158,11,.12);color:#F59E0B">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            </div>
            <?php
            $avg_offer = ($offers_stats['total_orders_with_offers'] ?? 0) > 0
                ? $offers_stats['total_offers_revenue'] / $offers_stats['total_orders_with_offers']
                : 0;
            ?>
            <div class="kpi-val">$<?= number_format($avg_offer, 1) ?></div>
            <div class="kpi-lbl">متوسط قيمة العرض</div>
            <div class="kpi-chg chg-neu">━ متوسط</div>
        </div>
    </div>

    <!-- أكثر العروض مبيعاً -->
    <?php if(!empty($top_offers)): ?>
    <div class="chart-card" style="margin-bottom:14px;animation-delay:.2s">
        <div class="chart-card-head">
            <h3>أكثر العروض مبيعاً</h3>
            <span>آخر <?= $period ?> يوم</span>
        </div>
        <?php
        $max_offer_qty = $top_offers[0]['total_qty'] ?? 1;
        foreach($top_offers as $i => $offer):
            $pct = $max_offer_qty > 0 ? ($offer['total_qty'] / $max_offer_qty) * 100 : 0;
        ?>
        <div class="cat-row" style="animation-delay:<?= $i*.05 ?>s">
            <div style="width:32px;height:32px;border-radius:9px;background:color-mix(in srgb,var(--p) 10%,transparent);border:1px solid color-mix(in srgb,var(--p) 18%,transparent);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;">🎁</div>
            <span class="cat-rname"><?= htmlspecialchars($offer['dish_name']) ?></span>
            <div class="cat-rbar-bg"><div class="cat-rbar" style="width:<?= round($pct) ?>%"></div></div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;flex-shrink:0;min-width:60px;">
                <span class="cat-rqty">×<?= $offer['total_qty'] ?></span>
                <span style="font-size:10px;color:var(--ink2);font-weight:600;">$<?= number_format($offer['total_revenue'],0) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<script>
const dailyData = <?= json_encode(array_values(array_map(fn($r) => [
    'day'     => $r['day'],
    'orders'  => intval($r['orders']),
    'revenue' => floatval($r['revenue'])
], $daily_orders))) ?>;

// ===== Theme-aware colors =====
function getCSSVar(v) {
    return getComputedStyle(document.documentElement).getPropertyValue(v).trim();
}
const primary  = getCSSVar('--p') || '#FF6B35';
const ink2     = getCSSVar('--ink2') || '#5A5550';
const line     = getCSSVar('--line') || 'rgba(240,235,227,0.06)';
const bg3      = getCSSVar('--bg3') || '#1C1C1C';

// ===== Daily Chart =====
const dailyCtx = document.getElementById('dailyChart').getContext('2d');
const grad = dailyCtx.createLinearGradient(0, 0, 0, 180);
grad.addColorStop(0, primary + '44');
grad.addColorStop(1, primary + '00');

new Chart(dailyCtx, {
    type: 'line',
    data: {
        labels: dailyData.map(d => {
            const dt = new Date(d.day);
            return (dt.getMonth()+1) + '/' + dt.getDate();
        }),
        datasets: [{
            data: dailyData.map(d => d.orders),
            borderColor: primary,
            backgroundColor: grad,
            borderWidth: 2.5, tension: 0.42, fill: true,
            pointBackgroundColor: primary,
            pointRadius: 3, pointHoverRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false }, tooltip: {
            rtl: true,
            callbacks: { label: ctx => ` ${ctx.parsed.y} طلب` }
        }},
        scales: {
            x: { grid: { display: false }, ticks: { font: { family: 'Tajawal', size: 11 }, color: ink2, maxTicksLimit: 8 } },
            y: { beginAtZero: true, grid: { color: line }, ticks: { font: { family: 'Tajawal', size: 11 }, color: ink2, stepSize: 1 } }
        }
    }
});

// ===== Status Donut =====
new Chart(document.getElementById('statusChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['مسلّمة','نشطة','ملغية'],
        datasets: [{
            data: [<?= $general['delivered'] ?>, <?= $general['active'] ?>, <?= $general['cancelled'] ?>],
            backgroundColor: ['#22C55E', primary, '#EF4444'],
            borderWidth: 0, hoverOffset: 6
        }]
    },
    options: {
        responsive: false, cutout: '74%',
        plugins: { legend: { display: false }, tooltip: {
            callbacks: { label: ctx => ` ${ctx.parsed} طلب` }
        }}
    }
});
</script>

</body>
</html>