<?php
session_start();
require_once '../../config/database.php';
require_once 'plan_guard.php';
if(!isset($_SESSION['restaurant_id'])) { header('Location: ../login.php'); exit; }

$rid = $_SESSION['restaurant_id'];
$rest = $pdo->prepare("SELECT * FROM restaurants WHERE id=?");
$rest->execute([$rid]);
$restaurant = $rest->fetch();
$cur_symbol   = $restaurant['currency_symbol']   ?? '$';
$cur_decimals = intval($restaurant['currency_decimals'] ?? 2);
$cur_prefix   = in_array($cur_symbol, ['$','€','₺']);
if(!function_exists('fmt_price')) {
    function fmt_price($a,$s,$d,$p){ $f=number_format(floatval($a),$d); return $p?$s.$f:$s.' '.$f; }
}

$view = $_GET['view'] ?? 'daily'; // daily | monthly | compare

// ===== إحصائيات اليوم =====
$today = $pdo->prepare("
    SELECT 
        COUNT(*) as orders,
        COALESCE(SUM(total_price),0) as revenue,
        COALESCE(SUM(CASE WHEN payment_method='cash' THEN total_price END),0) as cash,
        COALESCE(SUM(CASE WHEN payment_method='card' THEN total_price END),0) as card,
        COALESCE(SUM(CASE WHEN payment_method='shamcash' THEN total_price END),0) as shamcash,
        COUNT(CASE WHEN payment_method='cash' THEN 1 END) as cash_count,
        COUNT(CASE WHEN payment_method='card' THEN 1 END) as card_count,
        COUNT(CASE WHEN payment_method='shamcash' THEN 1 END) as sham_count,
        SUM(CASE WHEN payment_status='unpaid' AND status='delivered' THEN 1 ELSE 0 END) as unpaid_count,
        COALESCE(SUM(CASE WHEN payment_status='unpaid' AND status='delivered' THEN total_price END),0) as unpaid_revenue
    FROM orders WHERE restaurant_id=? AND DATE(created_at)=CURDATE()
");
$today->execute([$rid]);
$td = $today->fetch();

// ===== آخر 30 يوم (للمقارنة) =====
$daily = $pdo->prepare("
    SELECT 
        DATE(created_at) as day,
        COUNT(*) as orders,
        COALESCE(SUM(total_price),0) as revenue
    FROM orders
    WHERE restaurant_id=? AND payment_status='paid' AND created_at >= DATE_SUB(CURDATE(),INTERVAL 29 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$daily->execute([$rid]);
$daily_data = $daily->fetchAll();

// ===== آخر 12 شهر =====
$monthly = $pdo->prepare("
    SELECT 
        DATE_FORMAT(created_at,'%Y-%m') as month,
        DATE_FORMAT(created_at,'%m/%Y') as month_label,
        COUNT(*) as orders,
        COALESCE(SUM(total_price),0) as revenue,
        COALESCE(SUM(CASE WHEN payment_method='cash' THEN total_price END),0) as cash,
        COALESCE(SUM(CASE WHEN payment_method='card' THEN total_price END),0) as card,
        COALESCE(SUM(CASE WHEN payment_method='shamcash' THEN total_price END),0) as shamcash
    FROM orders
    WHERE restaurant_id=? AND payment_status='paid' AND created_at >= DATE_SUB(CURDATE(),INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at,'%Y-%m')
    ORDER BY month ASC
");
$monthly->execute([$rid]);
$monthly_data = $monthly->fetchAll();

// ===== أفضل الأطباق =====
$top_dishes = $pdo->prepare("
    SELECT oi.dish_name, SUM(oi.quantity) as qty, SUM(oi.dish_price*oi.quantity) as revenue
    FROM order_items oi
    JOIN orders o ON o.id=oi.order_id
    WHERE o.restaurant_id=? AND o.payment_status='paid' AND DATE(o.created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)
    GROUP BY oi.dish_name ORDER BY qty DESC LIMIT 5
");
$top_dishes->execute([$rid]);
$top = $top_dishes->fetchAll();

require_once 'sidebar.php';
?>
<style>
/* ===== REPORT TABS ===== */
.report-tabs{display:flex;gap:8px;margin-bottom:24px;}
.rtab{padding:8px 18px;border-radius:20px;border:1.5px solid var(--line);background:var(--bg2);font-size:13px;font-weight:700;color:var(--ink2);text-decoration:none;transition:all .2s;}
.rtab:hover{border-color:var(--p);color:var(--p);}
.rtab.active{background:var(--p);border-color:transparent;color:#fff;}

/* ===== STAT CARDS ===== */
.report-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px;}
.rcard{background:var(--bg2);border:1px solid var(--line);border-radius:16px;padding:16px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}
.rcard-label{font-size:11px;font-weight:700;color:var(--ink2);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.rcard-val{font-family:'Fraunces',serif;font-size:24px;font-weight:900;color:var(--ink);letter-spacing:-.3px;}
.rcard-val.p{color:var(--p);}
.rcard-val.g{color:#22C55E;}
.rcard-val.b{color:#3B82F6;}
.rcard-val.r{color:#EF4444;}
.rcard-sub{font-size:11px;color:var(--ink2);margin-top:4px;font-weight:600;}

/* ===== CHART ===== */
.chart-card{background:var(--bg2);border:1px solid var(--line);border-radius:18px;padding:20px;margin-bottom:18px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both .1s;}
.chart-title{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:var(--ink);margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;}
.chart-wrap{overflow-x:auto;}
.bar-chart{display:flex;align-items:flex-end;gap:6px;height:160px;min-width:100%;}
.bar-col{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;min-width:28px;}
.bar{width:100%;border-radius:6px 6px 0 0;background:var(--p);transition:height .5s cubic-bezier(.22,1,.36,1);min-height:2px;cursor:pointer;position:relative;}
.bar:hover{filter:brightness(1.2);}
.bar-label{font-size:9px;color:var(--ink2);font-weight:700;text-align:center;white-space:nowrap;}
.bar-val{font-size:9px;font-weight:800;color:var(--ink);text-align:center;}

/* ===== PAY BREAKDOWN ===== */
.pay-breakdown{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.pay-item{flex:1;min-width:110px;background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:14px;text-align:center;}
.pay-item-icon{font-size:24px;margin-bottom:8px;}
.pay-item-label{font-size:11px;color:var(--ink2);font-weight:700;margin-bottom:5px;}
.pay-item-val{font-family:'Fraunces',serif;font-size:16px;font-weight:900;color:var(--ink);}
.pay-item-count{font-size:11px;color:var(--ink2);margin-top:3px;}

/* ===== TOP DISHES ===== */
.top-dish{display:flex;align-items:center;gap:12px;padding:11px 16px;background:var(--bg2);border:1px solid var(--line);border-radius:13px;margin-bottom:8px;}
.top-rank{font-family:'Fraunces',serif;font-size:20px;font-weight:900;color:var(--p);width:30px;flex-shrink:0;}
.top-name{flex:1;font-size:13px;font-weight:700;color:var(--ink);}
.top-qty{font-size:12px;color:var(--ink2);font-weight:700;}
.top-rev{font-family:'Fraunces',serif;font-size:14px;font-weight:900;color:var(--p);}

/* ===== MONTH TABLE ===== */
.month-table{width:100%;border-collapse:collapse;}
.month-table th{padding:10px 14px;text-align:right;font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--line);}
.month-table td{padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;}
.month-table tr:last-child td{border-bottom:none;}
.month-table tr:hover td{background:var(--bg3);}

/* ===== MOBILE ===== */
@media(max-width:600px){
  .report-tabs{flex-wrap:wrap;}
  .rtab{font-size:12px;padding:7px 12px;}
  .report-grid{grid-template-columns:repeat(2,1fr);}
  .rcard-val{font-size:18px;}
  .bar-chart{gap:3px;}
  .bar-label{font-size:8px;}
  /* month table scroll */
  .chart-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .month-table{min-width:480px;}
  .month-table th,.month-table td{padding:9px 10px;font-size:11px;}
  /* week compare */
  .chart-card>div[style*="flex"]{gap:8px !important;}
}
</style>

<div class="main">
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:12px;">
    <div>
        <div class="page-title">التقارير المالية</div>
        <div class="page-subtitle">متابعة الإيرادات وطرق الدفع</div>
    </div>
</div>

<div class="report-tabs">
    <a href="?view=daily"   class="rtab <?= $view==='daily'  ?'active':'' ?>">📅 يومي</a>
    <a href="?view=monthly" class="rtab <?= $view==='monthly'?'active':'' ?>">📆 شهري</a>
    <a href="?view=compare" class="rtab <?= $view==='compare'?'active':'' ?>">📊 مقارنة</a>
</div>

<?php if($view==='daily'): ?>

<!-- ===== TODAY STATS ===== -->
<div class="report-grid">
    <div class="rcard">
        <div class="rcard-label">💰 إيرادات اليوم</div>
        <div class="rcard-val p"><?= fmt_price($td['revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        <div class="rcard-sub"><?= $td['orders'] ?> طلب</div>
    </div>
    <div class="rcard">
        <div class="rcard-label">💵 نقدي</div>
        <div class="rcard-val g"><?= fmt_price($td['cash'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        <div class="rcard-sub"><?= $td['cash_count'] ?> طلب</div>
    </div>
    <div class="rcard">
        <div class="rcard-label">💳 كارت</div>
        <div class="rcard-val b"><?= fmt_price($td['card'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        <div class="rcard-sub"><?= $td['card_count'] ?> طلب</div>
    </div>
    <div class="rcard">
        <div class="rcard-label">📱 شام كاش</div>
        <div class="rcard-val" style="color:#FF6B35"><?= fmt_price($td['shamcash'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        <div class="rcard-sub"><?= $td['sham_count'] ?> طلب</div>
    </div>
    <?php if($td['unpaid_count'] > 0): ?>
    <div class="rcard" style="border-color:rgba(239,68,68,.25);">
        <div class="rcard-label">⚠️ غير مقبوض</div>
        <div class="rcard-val r"><?= fmt_price($td['unpaid_revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        <div class="rcard-sub"><?= $td['unpaid_count'] ?> طلب</div>
    </div>
    <?php endif; ?>
</div>

<!-- ===== 30-DAY CHART ===== -->
<?php if(!empty($daily_data)): ?>
<?php
$max_rev = max(array_column($daily_data, 'revenue')) ?: 1;
$daily_map = [];
foreach($daily_data as $d) $daily_map[$d['day']] = $d;
?>
<div class="chart-card">
    <div class="chart-title">
        إيرادات آخر 30 يوم
        <span style="font-size:11px;color:var(--ink2);font-weight:600;">
            إجمالي: <?= fmt_price(array_sum(array_column($daily_data,'revenue')), $cur_symbol, $cur_decimals, $cur_prefix) ?>
        </span>
    </div>
    <div class="chart-wrap">
        <div class="bar-chart" id="barChart">
            <?php
            for($i=29; $i>=0; $i--) {
                $day = date('Y-m-d', strtotime("-$i days"));
                $d   = $daily_map[$day] ?? null;
                $rev = $d ? floatval($d['revenue']) : 0;
                $h   = $max_rev > 0 ? max(2, round(($rev / $max_rev) * 140)) : 2;
                $lbl = date('d/m', strtotime($day));
                $color = $rev > 0 ? 'var(--p)' : 'var(--bg4)';
                echo "<div class='bar-col'>";
                echo "<div class='bar-val'>".($rev>0 ? fmt_price($rev,$cur_symbol,0,$cur_prefix) : '')."</div>";
                echo "<div class='bar' style='height:{$h}px;background:{$color};' title='{$lbl}: ".fmt_price($rev,$cur_symbol,$cur_decimals,$cur_prefix)."'></div>";
                echo "<div class='bar-label'>{$lbl}</div>";
                echo "</div>";
            }
            ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- TOP DISHES -->
<?php if(!empty($top)): ?>
<div class="chart-card">
    <div class="chart-title">🍽️ أكثر الأطباق طلباً (30 يوم)</div>
    <?php foreach($top as $i => $dish): ?>
    <div class="top-dish">
        <div class="top-rank"><?= ['🥇','🥈','🥉','4','5'][$i] ?></div>
        <div class="top-name"><?= htmlspecialchars($dish['dish_name']) ?></div>
        <div class="top-qty">×<?= $dish['qty'] ?></div>
        <div class="top-rev"><?= fmt_price($dish['revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif($view==='monthly'): ?>

<!-- ===== MONTHLY TABLE ===== -->
<div class="chart-card">
    <div class="chart-title">التقرير الشهري — آخر 12 شهر</div>
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;"><table class="month-table">
        <thead>
            <tr>
                <th>الشهر</th>
                <th>طلبات</th>
                <th>إيرادات</th>
                <th>نقدي</th>
                <th>كارت</th>
                <th>شام كاش</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach(array_reverse($monthly_data) as $m): ?>
        <tr>
            <td><strong><?= $m['month_label'] ?></strong></td>
            <td><?= $m['orders'] ?></td>
            <td><strong style="color:var(--p)"><?= fmt_price($m['revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?></strong></td>
            <td><?= fmt_price($m['cash'], $cur_symbol, $cur_decimals, $cur_prefix) ?></td>
            <td><?= fmt_price($m['card'], $cur_symbol, $cur_decimals, $cur_prefix) ?></td>
            <td><?= fmt_price($m['shamcash'], $cur_symbol, $cur_decimals, $cur_prefix) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php elseif($view==='compare'): ?>

<!-- ===== COMPARE CHART ===== -->
<?php
$weeks = [];
for($w=3; $w>=0; $w--) {
    $start = date('Y-m-d', strtotime("monday -$w week"));
    $end   = date('Y-m-d', strtotime("sunday -$w week"));
    $ws = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) as rev, COUNT(*) as cnt FROM orders WHERE restaurant_id=? AND payment_status='paid' AND DATE(created_at) BETWEEN ? AND ?");
    $ws->execute([$rid, $start, $end]);
    $wr = $ws->fetch();
    $weeks[] = ['label'=>"أسبوع ".($w==0?'هذا':($w==1?'الماضي':"-$w")),'rev'=>$wr['rev'],'cnt'=>$wr['cnt'],'start'=>$start,'end'=>$end];
}
$max_w = max(array_column($weeks,'rev')) ?: 1;
?>
<div class="chart-card">
    <div class="chart-title">مقارنة آخر 4 أسابيع</div>
    <div style="display:flex;gap:14px;align-items:flex-end;height:180px;padding-bottom:8px;">
        <?php foreach($weeks as $wk): ?>
        <?php $h = $max_w > 0 ? max(4, round(($wk['rev']/$max_w)*160)) : 4; ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;">
            <div style="font-size:11px;font-weight:800;color:var(--ink);"><?= fmt_price($wk['rev'],$cur_symbol,0,$cur_prefix) ?></div>
            <div style="width:100%;height:<?= $h ?>px;background:var(--p);border-radius:8px 8px 0 0;opacity:<?= $wk['rev']>0?'1':'.2' ?>;"></div>
            <div style="font-size:10px;color:var(--ink2);font-weight:700;text-align:center;"><?= $wk['label'] ?></div>
            <div style="font-size:10px;color:var(--ink2);"><?= $wk['cnt'] ?> طلب</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- مقارنة هذا الشهر vs الماضي -->
<?php
$this_month = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) as rev, COUNT(*) as cnt FROM orders WHERE restaurant_id=? AND payment_status='paid' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
$this_month->execute([$rid]); $tm = $this_month->fetch();
$last_month = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) as rev, COUNT(*) as cnt FROM orders WHERE restaurant_id=? AND payment_status='paid' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),'%Y-%m')");
$last_month->execute([$rid]); $lm = $last_month->fetch();
$diff_pct = $lm['rev'] > 0 ? round((($tm['rev']-$lm['rev'])/$lm['rev'])*100) : 0;
?>
<div class="report-grid">
    <div class="rcard">
        <div class="rcard-label">📅 هذا الشهر</div>
        <div class="rcard-val p"><?= fmt_price($tm['rev'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        <div class="rcard-sub"><?= $tm['cnt'] ?> طلب</div>
    </div>
    <div class="rcard">
        <div class="rcard-label">📅 الشهر الماضي</div>
        <div class="rcard-val"><?= fmt_price($lm['rev'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        <div class="rcard-sub"><?= $lm['cnt'] ?> طلب</div>
    </div>
    <div class="rcard">
        <div class="rcard-label">📈 نسبة التغيير</div>
        <div class="rcard-val <?= $diff_pct>=0?'g':'r' ?>"><?= ($diff_pct>=0?'+':'').$diff_pct ?>%</div>
        <div class="rcard-sub"><?= $diff_pct>=0?'نمو':'انخفاض' ?></div>
    </div>
</div>

<?php endif; ?>
</div>