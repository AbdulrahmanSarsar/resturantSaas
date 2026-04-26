<?php
/**
 * chain.php — لوحة مالك السلسلة (Phase 8)
 *
 * تعرض تحليلات مقارنة عبر كل الفروع:
 *   - إجمالي الإيرادات + الطلبات (كل الفروع)
 *   - مقارنة الفروع (اليوم / 7 أيام / 30 يوم)
 *   - أفضل الأطباق مبيعاً عبر الفروع
 *   - توزيع الطلبات بالساعة
 *   - حالة كل فرع (نشط / معطّل / طلبات معلقة)
 *
 * يقرأ من:
 *   - branches (الفروع)
 *   - orders (المصدر الوحيد — مع branch_id)
 *   - order_items
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/plan_guard.php';

if (!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}
plan_feature_required('multi_branch'); // لوحة السلسلة للباقة الاحترافية فقط

$rid    = $_SESSION['restaurant_id'];
$period = intval($_GET['period'] ?? 30);
if (!in_array($period, [1, 7, 30, 90])) $period = 30;

// ===== بيانات المطعم =====
$rest = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
$rest->execute([$rid]);
$restaurant = $rest->fetch();

$cur_symbol   = $restaurant['currency_symbol']   ?? '$';
$cur_decimals = intval($restaurant['currency_decimals'] ?? 2);
$cur_prefix   = in_array($cur_symbol, ['$', '€', '₺']);

if (!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $f = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $f : $symbol . ' ' . $f;
    }
}

$date_from = date('Y-m-d', strtotime("-{$period} days"));

// ===== الفروع =====
// orders.branch_id موجود بعد الترحيل — بنستخدمه مباشرة
$branches_stmt = $pdo->prepare("
    SELECT b.*,
        (SELECT COUNT(*) FROM orders
            WHERE branch_id = b.id AND DATE(created_at) >= ?)                  AS total_orders,
        (SELECT COALESCE(SUM(total_price),0) FROM orders
            WHERE branch_id = b.id AND DATE(created_at) >= ?
            AND status = 'delivered')                                          AS revenue,
        (SELECT COUNT(*) FROM orders
            WHERE branch_id = b.id AND DATE(created_at) = CURDATE())           AS today_orders,
        (SELECT COUNT(*) FROM orders
            WHERE branch_id = b.id AND status = 'pending')                     AS pending
    FROM branches b
    WHERE b.restaurant_id = ?
    ORDER BY b.id ASC
");
$branches_stmt->execute([$date_from, $date_from, $rid]);
$branches = $branches_stmt->fetchAll();

// ===== إجماليات =====
$total_revenue = array_sum(array_column($branches, 'revenue'));
$total_orders  = array_sum(array_column($branches, 'total_orders'));
$total_today   = array_sum(array_column($branches, 'today_orders'));
$total_pending = array_sum(array_column($branches, 'pending'));
$active_count  = count(array_filter($branches, fn($b) => $b['is_active']));

// ===== أفضل الأطباق =====
$top_dishes = $pdo->prepare("
    SELECT
        oi.dish_name,
        SUM(oi.quantity)                  AS total_qty,
        SUM(oi.quantity * oi.dish_price)  AS total_rev,
        COUNT(DISTINCT oi.order_id)       AS order_count
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.restaurant_id = ?
      AND o.status = 'delivered'
      AND DATE(o.created_at) >= ?
      AND oi.dish_id IS NOT NULL
    GROUP BY oi.dish_name
    ORDER BY total_qty DESC
    LIMIT 8
");
$top_dishes->execute([$rid, $date_from]);
$top_dishes = $top_dishes->fetchAll();

// ===== توزيع الطلبات بالساعة =====
$hourly_stmt = $pdo->prepare("
    SELECT HOUR(created_at) AS hr, COUNT(*) AS cnt
    FROM orders
    WHERE restaurant_id = ? AND DATE(created_at) >= ?
    GROUP BY HOUR(created_at)
");
$hourly_stmt->execute([$rid, $date_from]);
$hourly_raw = $hourly_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$hourly = [];
for ($h = 0; $h < 24; $h++) {
    $hourly[$h] = intval($hourly_raw[$h] ?? 0);
}
$hourly_max = max($hourly) ?: 1;

// ===== آخر 7 أيام (للـ sparkline) =====
$daily_stmt = $pdo->prepare("
    SELECT DATE(created_at) AS day,
           COUNT(*) AS orders,
           COALESCE(SUM(total_price),0) AS revenue
    FROM orders
    WHERE restaurant_id = ? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$daily_stmt->execute([$rid]);
$daily_raw = [];
foreach ($daily_stmt->fetchAll() as $row) {
    $daily_raw[$row['day']] = $row;
}
$daily_data = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $daily_data[] = [
        'day'     => date('d/m', strtotime($day)),
        'orders'  => intval($daily_raw[$day]['orders']  ?? 0),
        'revenue' => floatval($daily_raw[$day]['revenue'] ?? 0),
    ];
}

// أفضل + أسوأ فرع
usort($branches, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
$best_branch  = $branches[0] ?? null;
$worst_branch = count($branches) > 1 ? end($branches) : null;

require_once __DIR__ . '/sidebar.php';
?>
<style>
/* ===== CHAIN DASHBOARD ===== */
.chain-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 24px; gap: 12px; flex-wrap: wrap;
}
.chain-title { font-family: 'Fraunces', serif; font-size: 24px; font-weight: 900; color: var(--ink); letter-spacing: -.4px; }
.chain-sub   { font-size: 13px; color: var(--ink2); font-weight: 500; margin-top: 3px; }

/* Period tabs */
.period-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
.period-tab {
    padding: 7px 14px; border-radius: 20px;
    border: 1.5px solid var(--line); background: var(--bg2);
    font-size: 12px; font-weight: 700; cursor: pointer;
    text-decoration: none; color: var(--ink2);
    transition: all .2s; font-family: 'Tajawal', sans-serif;
}
.period-tab:hover { border-color: var(--p); color: var(--p); }
.period-tab.active { background: var(--p); border-color: transparent; color: #fff; box-shadow: 0 3px 10px color-mix(in srgb,var(--p) 30%,transparent); }

/* KPI grid */
.kpi-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr));
    gap: 12px; margin-bottom: 20px;
}
.kpi-card {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; padding: 18px 20px;
    animation: cardIn .4s cubic-bezier(.22,1,.36,1) both;
    position: relative; overflow: hidden;
}
.kpi-card::before {
    content: ''; position: absolute;
    top: -20px; left: -20px; width: 80px; height: 80px;
    border-radius: 50%; opacity: .06; filter: blur(20px);
}
.kpi-card:nth-child(1)::before { background: var(--p); }
.kpi-card:nth-child(2)::before { background: #22C55E; }
.kpi-card:nth-child(3)::before { background: #3B82F6; }
.kpi-card:nth-child(4)::before { background: #F59E0B; }
.kpi-card:nth-child(1){ animation-delay:.04s }
.kpi-card:nth-child(2){ animation-delay:.08s }
.kpi-card:nth-child(3){ animation-delay:.12s }
.kpi-card:nth-child(4){ animation-delay:.16s }
.kpi-card:nth-child(5){ animation-delay:.20s }

.kpi-icon { width: 38px; height: 38px; border-radius: 11px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
.kpi-icon svg { width: 18px; height: 18px; }
.kpi-num { font-family: 'Fraunces', serif; font-size: 28px; font-weight: 900; color: var(--ink); line-height: 1; letter-spacing: -.5px; margin-bottom: 4px; }
.kpi-lbl { font-size: 12px; color: var(--ink2); font-weight: 600; }
.kpi-sub { font-size: 11px; color: var(--ink3); font-weight: 500; margin-top: 2px; }

/* Section */
.section { margin-bottom: 20px; }
.section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.section-title { font-family: 'Fraunces', serif; font-size: 16px; font-weight: 700; color: var(--ink); }

/* Branch comparison cards */
.branch-grid { display: flex; flex-direction: column; gap: 10px; }
.branch-row {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 16px; padding: 16px 18px;
    display: flex; align-items: center; gap: 14px;
    animation: cardIn .4s cubic-bezier(.22,1,.36,1) both;
    transition: box-shadow .2s;
    flex-wrap: wrap;
}
.branch-row:hover { box-shadow: var(--shadow2); }
.branch-row.best  { border-color: color-mix(in srgb,#22C55E 30%,transparent); background: color-mix(in srgb,#22C55E 4%,var(--bg2)); }
.branch-row.worst { border-color: color-mix(in srgb,#EF4444 20%,transparent); }
.branch-row.inactive { opacity: .55; }

.branch-rank {
    font-family: 'Fraunces', serif;
    font-size: 22px; font-weight: 900; color: var(--ink3);
    width: 30px; text-align: center; flex-shrink: 0;
}
.branch-rank.gold   { color: #F59E0B; }
.branch-rank.silver { color: #9CA3AF; }
.branch-rank.bronze { color: #CD7C3C; }

.branch-info { flex: 1; min-width: 120px; }
.branch-name { font-size: 14px; font-weight: 800; color: var(--ink); margin-bottom: 3px; display: flex; align-items: center; gap: 7px; }
.branch-addr { font-size: 11px; color: var(--ink2); font-weight: 500; }
.branch-badge-best   { font-size: 9px; font-weight: 800; background: rgba(34,197,94,.12); color: #22C55E; border: 1px solid rgba(34,197,94,.2); padding: 2px 7px; border-radius: 10px; }
.branch-badge-inactive { font-size: 9px; font-weight: 800; background: var(--bg3); color: var(--ink2); border: 1px solid var(--line); padding: 2px 7px; border-radius: 10px; }

.branch-stats { display: flex; gap: 18px; flex-wrap: wrap; }
.bstat-item { text-align: center; }
.bstat-num  { font-family: 'Fraunces', serif; font-size: 18px; font-weight: 900; color: var(--ink); line-height: 1; }
.bstat-lbl  { font-size: 10px; color: var(--ink2); font-weight: 600; margin-top: 2px; }
.bstat-num.revenue { color: var(--p); }
.bstat-num.pending { color: #F59E0B; }
.bstat-num.today   { color: #3B82F6; }

/* Revenue bar */
.branch-bar-wrap { width: 100%; max-width: 140px; }
.branch-bar-track { height: 6px; background: var(--bg3); border-radius: 6px; overflow: hidden; margin-bottom: 3px; }
.branch-bar-fill  { height: 100%; border-radius: 6px; background: linear-gradient(90deg, var(--p), var(--s)); transition: width .8s cubic-bezier(.25,1,.5,1); }
.branch-bar-pct   { font-size: 10px; color: var(--ink2); font-weight: 700; }

/* Sparkline */
.sparkline-wrap {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; padding: 18px 20px;
    margin-bottom: 20px; animation: cardIn .4s .2s both;
}
.spark-title { font-family: 'Fraunces', serif; font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 14px; }
.spark-bars  { display: flex; gap: 5px; align-items: flex-end; height: 70px; }
.spark-bar-wrap { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 5px; }
.spark-bar {
    width: 100%; border-radius: 5px 5px 0 0;
    background: linear-gradient(to top, var(--p), var(--s));
    min-height: 4px;
    transition: height .6s cubic-bezier(.25,1,.5,1);
    position: relative; cursor: pointer;
}
.spark-bar:hover { opacity: .85; }
.spark-day { font-size: 9px; color: var(--ink2); font-weight: 600; text-align: center; white-space: nowrap; }
.spark-val { font-size: 9px; color: var(--ink); font-weight: 700; text-align: center; }

/* Top dishes */
.dishes-list { display: flex; flex-direction: column; gap: 8px; }
.dish-row {
    display: flex; align-items: center; gap: 12px;
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 13px; padding: 12px 14px;
    animation: cardIn .4s cubic-bezier(.22,1,.36,1) both;
}
.dish-rank  { font-family: 'Fraunces', serif; font-size: 15px; font-weight: 900; color: var(--ink3); width: 22px; text-align: center; flex-shrink: 0; }
.dish-rank.top3 { color: var(--p); }
.dish-name  { flex: 1; font-size: 13px; font-weight: 700; color: var(--ink); min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dish-qty   { font-family: 'Fraunces', serif; font-size: 15px; font-weight: 900; color: var(--ink); white-space: nowrap; }
.dish-qty-lbl { font-size: 10px; color: var(--ink2); font-weight: 600; }
.dish-rev   { font-size: 12px; font-weight: 700; color: var(--p); white-space: nowrap; }

/* Hourly heatmap */
.hourly-grid {
    display: grid; grid-template-columns: repeat(12, 1fr);
    gap: 4px; margin-top: 8px;
}
@media(max-width:600px){ .hourly-grid { grid-template-columns: repeat(8, 1fr); } }
.hour-cell {
    aspect-ratio: 1; border-radius: 6px;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700; cursor: default;
    transition: transform .15s;
    position: relative;
}
.hour-cell:hover { transform: scale(1.15); z-index: 1; }
.hour-lbl { font-size: 8px; color: inherit; opacity: .7; }
.hour-cnt  { font-size: 10px; font-weight: 900; }

/* Two-col layout */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px; }
@media(max-width:700px){ .two-col { grid-template-columns: 1fr; } }
.col-card { background: var(--bg2); border: 1px solid var(--line); border-radius: 18px; padding: 18px 20px; animation: cardIn .4s .25s both; }
.col-card-title { font-family: 'Fraunces', serif; font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
</style>

<div class="main">

    <!-- Header -->
    <div class="chain-header">
        <div>
            <div class="chain-title">لوحة السلسلة 🏢</div>
            <div class="chain-sub"><?= htmlspecialchars($restaurant['name']) ?> · <?= $active_count ?> فرع نشط · آخر <?= $period ?> يوم</div>
        </div>
        <div class="period-tabs">
            <?php foreach ([1 => 'اليوم', 7 => '7 أيام', 30 => '30 يوم', 90 => '3 أشهر'] as $p => $lbl): ?>
            <a href="?period=<?= $p ?>" class="period-tab <?= $period == $p ? 'active' : '' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon" style="background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            </div>
            <div class="kpi-num"><?= fmt_price($total_revenue, $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
            <div class="kpi-lbl">إجمالي الإيرادات</div>
            <div class="kpi-sub">آخر <?= $period ?> يوم</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:rgba(34,197,94,.12);color:#22C55E">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
            </div>
            <div class="kpi-num"><?= number_format($total_orders) ?></div>
            <div class="kpi-lbl">إجمالي الطلبات</div>
            <div class="kpi-sub"><?= $total_today ?> اليوم</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:rgba(59,130,246,.12);color:#3B82F6">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <div class="kpi-num"><?= $active_count ?></div>
            <div class="kpi-lbl">فروع نشطة</div>
            <div class="kpi-sub">من أصل <?= count($branches) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:rgba(245,158,11,.12);color:#F59E0B">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="kpi-num" style="color:#F59E0B"><?= $total_pending ?></div>
            <div class="kpi-lbl">طلبات معلقة</div>
            <div class="kpi-sub">تحتاج متابعة</div>
        </div>
        <?php if($total_orders > 0): ?>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:rgba(139,92,246,.12);color:#8B5CF6">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div class="kpi-num"><?= fmt_price($total_revenue / $total_orders, $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
            <div class="kpi-lbl">متوسط قيمة الطلب</div>
            <div class="kpi-sub">لكل الفروع</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sparkline آخر 7 أيام -->
    <?php
    $spark_max_rev = max(array_column($daily_data, 'revenue')) ?: 1;
    $spark_max_ord = max(array_column($daily_data, 'orders'))  ?: 1;
    ?>
    <div class="sparkline-wrap">
        <div class="spark-title">الإيرادات — آخر 7 أيام</div>
        <div class="spark-bars">
            <?php foreach ($daily_data as $day): ?>
            <?php $h = max(4, round(($day['revenue'] / $spark_max_rev) * 65)); ?>
            <div class="spark-bar-wrap">
                <div class="spark-val"><?= $day['orders'] ?> طلب</div>
                <div class="spark-bar" style="height:<?= $h ?>px"
                     title="<?= $day['day'] ?>: <?= fmt_price($day['revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?>"></div>
                <div class="spark-day"><?= $day['day'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- مقارنة الفروع -->
    <div class="section">
        <div class="section-head">
            <div class="section-title">مقارنة الفروع</div>
            <?php if($best_branch): ?>
            <span style="font-size:12px;color:#22C55E;font-weight:700;">🏆 الأفضل: <?= htmlspecialchars($best_branch['name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="branch-grid">
            <?php
            $max_rev = max(array_column($branches, 'revenue')) ?: 1;
            $rank_labels = ['gold', 'silver', 'bronze'];
            $rank_nums   = ['🥇', '🥈', '🥉'];
            ?>
            <?php foreach ($branches as $i => $b): ?>
            <?php
            $is_best    = ($i === 0 && $b['revenue'] > 0);
            $is_worst   = ($i === count($branches)-1 && count($branches) > 1 && $b['revenue'] < $max_rev);
            $bar_pct    = $max_rev > 0 ? round(($b['revenue'] / $max_rev) * 100) : 0;
            ?>
            <div class="branch-row <?= $is_best ? 'best' : '' ?> <?= !$b['is_active'] ? 'inactive' : '' ?>"
                 style="animation-delay:<?= $i*.06 ?>s">

                <div class="branch-rank <?= $rank_labels[$i] ?? '' ?>">
                    <?= $rank_nums[$i] ?? ($i + 1) ?>
                </div>

                <div class="branch-info">
                    <div class="branch-name">
                        <?= htmlspecialchars($b['name']) ?>
                        <?php if($is_best && $b['revenue'] > 0): ?>
                        <span class="branch-badge-best">الأفضل</span>
                        <?php endif; ?>
                        <?php if(!$b['is_active']): ?>
                        <span class="branch-badge-inactive">معطّل</span>
                        <?php endif; ?>
                    </div>
                    <?php if($b['address']): ?>
                    <div class="branch-addr">📍 <?= htmlspecialchars($b['address']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="branch-stats">
                    <div class="bstat-item">
                        <div class="bstat-num revenue"><?= fmt_price($b['revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
                        <div class="bstat-lbl">الإيرادات</div>
                    </div>
                    <div class="bstat-item">
                        <div class="bstat-num"><?= $b['total_orders'] ?></div>
                        <div class="bstat-lbl">الطلبات</div>
                    </div>
                    <div class="bstat-item">
                        <div class="bstat-num today"><?= $b['today_orders'] ?></div>
                        <div class="bstat-lbl">اليوم</div>
                    </div>
                    <?php if($b['pending'] > 0): ?>
                    <div class="bstat-item">
                        <div class="bstat-num pending"><?= $b['pending'] ?></div>
                        <div class="bstat-lbl">معلقة ⚠️</div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="branch-bar-wrap">
                    <div class="branch-bar-track">
                        <div class="branch-bar-fill" style="width:<?= $bar_pct ?>%"></div>
                    </div>
                    <div class="branch-bar-pct"><?= $bar_pct ?>% من الإيرادات</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- أفضل الأطباق + توزيع الساعات -->
    <div class="two-col">

        <!-- أفضل الأطباق -->
        <div class="col-card">
            <div class="col-card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:16px;height:16px;color:var(--p)"><path d="M3 2l3 7H6a3 3 0 003 3v9a1 1 0 001 1h4a1 1 0 001-1v-9a3 3 0 003-3h-3l3-7"/></svg>
                أكثر الأطباق مبيعاً
            </div>
            <?php if(empty($top_dishes)): ?>
            <div style="text-align:center;padding:30px;color:var(--ink2);font-size:13px;">لا توجد بيانات</div>
            <?php else: ?>
            <div class="dishes-list">
                <?php foreach ($top_dishes as $di => $dish): ?>
                <div class="dish-row" style="animation-delay:<?= $di*.05 ?>s">
                    <div class="dish-rank <?= $di < 3 ? 'top3' : '' ?>"><?= $di + 1 ?></div>
                    <div class="dish-name" title="<?= htmlspecialchars($dish['dish_name']) ?>">
                        <?= htmlspecialchars($dish['dish_name']) ?>
                    </div>
                    <div style="text-align:center">
                        <div class="dish-qty"><?= $dish['total_qty'] ?></div>
                        <div class="dish-qty-lbl">وحدة</div>
                    </div>
                    <div class="dish-rev"><?= fmt_price($dish['total_rev'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- توزيع الطلبات بالساعة -->
        <div class="col-card">
            <div class="col-card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:16px;height:16px;color:#3B82F6"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                توزيع الطلبات بالساعة
            </div>
            <div class="hourly-grid">
                <?php for ($h = 0; $h < 24; $h++):
                    $cnt     = $hourly[$h];
                    $pct     = $hourly_max > 0 ? ($cnt / $hourly_max) : 0;
                    $opacity = max(0.07, $pct * 0.9);
                    $bg      = "rgba(255,107,53,{$opacity})";
                    $color   = $pct > 0.5 ? '#FF6B35' : 'var(--ink2)';
                ?>
                <div class="hour-cell" style="background:<?= $bg ?>;color:<?= $color ?>" title="<?= $h ?>:00 — <?= $cnt ?> طلب">
                    <div class="hour-lbl"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?></div>
                    <div class="hour-cnt"><?= $cnt ?: '·' ?></div>
                </div>
                <?php endfor; ?>
            </div>
            <div style="margin-top:10px;font-size:11px;color:var(--ink2);font-weight:600;">
                <?php
                $peak_hour = array_search(max($hourly), $hourly);
                if (max($hourly) > 0):
                ?>
                ⚡ أكثر ازدحاماً: <?= str_pad($peak_hour, 2, '0', STR_PAD_LEFT) ?>:00 — <?= $hourly[$peak_hour] ?> طلب
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- روابط سريعة -->
    <div style="background:var(--bg2);border:1px solid var(--line);border-radius:18px;padding:18px 20px;animation:cardIn .4s .35s both;">
        <div style="font-family:'Fraunces',serif;font-size:14px;font-weight:700;color:var(--ink);margin-bottom:14px;">إجراءات سريعة</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="branches.php" class="btn btn-secondary btn-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                إدارة الفروع
            </a>
            <a href="orders.php" class="btn btn-secondary btn-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
                الطلبات
            </a>
            <a href="reports.php" class="btn btn-secondary btn-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><rect x="3" y="12" width="4" height="9"/><rect x="10" y="7" width="4" height="14"/><rect x="17" y="3" width="4" height="18"/></svg>
                التقارير
            </a>
            <a href="qr.php" class="btn btn-secondary btn-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><rect x="3" y="3" width="5" height="5"/><rect x="16" y="3" width="5" height="5"/><rect x="3" y="16" width="5" height="5"/></svg>
                رموز QR
            </a>
            <?php if($total_pending > 0): ?>
            <a href="orders.php?status=pending" class="btn btn-primary btn-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= $total_pending ?> طلب معلق ⚠️
            </a>
            <?php endif; ?>
        </div>
    </div>

</div>