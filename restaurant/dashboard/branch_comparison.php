<?php
/**
 * branch_comparison.php — مقارنة أداء الفروع
 * 
 * MVC-compliant:
 *   - كل الـ queries عبر ReportService
 *   - صفر SQL مباشر بالصفحة
 *   - الصفحة View فقط
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once 'plan_guard.php';

if(!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}
plan_required('advanced');

$rid = $_SESSION['restaurant_id'];

// ===== Services =====
/** @var \MenuPro\Services\ReportService $reportService */
$reportService = service('report');

// ===== عملة المطعم (للعرض فقط) =====
$rest = $pdo->prepare("SELECT name, currency_symbol, currency_symbol_en, currency_decimals FROM restaurants WHERE id=?");
$rest->execute([$rid]);
$restaurant = $rest->fetch();

$cur_symbol   = $restaurant['currency_symbol']   ?? '$';
$cur_decimals = intval($restaurant['currency_decimals'] ?? 2);
$cur_prefix   = in_array($cur_symbol, ['$','€','₺']);
if(!function_exists('fmt_price')) {
    function fmt_price($a,$s,$d,$p){ $f=number_format(floatval($a),$d); return $p?$s.$f:$s.' '.$f; }
}

// ===== الفترة =====
$period = intval($_GET['period'] ?? 30);
if(!in_array($period, [7, 30, 90])) $period = 30;

// ===== جلب البيانات — كلها عبر ReportService =====
$branches_comp    = $reportService->getBranchComparison($rid, $period);
$hourly_by_branch = $reportService->getHourlyDistributionByBranch($rid, $period);
$staff_perf       = $reportService->getStaffPerformance($rid, $period, 15);
$totals           = $reportService->getRestaurantTotals($rid, $period);

// إجماليات للعرض
$total_all  = floatval($totals['total_revenue']);
$orders_all = intval($totals['total_orders']);
$best_branch = !empty($branches_comp) ? $branches_comp[0] : null;

// أعلى قيمة لتطبيع رسم الساعات
$max_hourly = 0;
foreach($hourly_by_branch as $br) {
    foreach(($br['data'] ?? []) as $v) {
        if($v > $max_hourly) $max_hourly = $v;
    }
}

$role_labels  = ['waiter' => 'نادل', 'kitchen' => 'مطبخ', 'cashier' => 'كاشير'];
$branch_colors = ['#FF6B35', '#3B82F6', '#22C55E', '#8B5CF6', '#EC4899', '#F59E0B'];

require_once 'sidebar.php';
?>
<style>
.bc-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--ink2);text-decoration:none;font-size:12px;font-weight:700;margin-bottom:8px;transition:color .2s;}
.back-link:hover{color:var(--p);}
.back-link svg{width:14px;height:14px;}

.period-tabs{display:flex;gap:5px;background:var(--bg2);border:1px solid var(--line);padding:5px;border-radius:14px;}
.period-tab{padding:7px 16px;border-radius:10px;font-size:12px;font-weight:700;color:var(--ink2);text-decoration:none;transition:all .2s;font-family:'Tajawal',sans-serif;}
.period-tab:hover{color:var(--p);}
.period-tab.active{background:var(--p);color:#fff;box-shadow:0 3px 10px color-mix(in srgb,var(--p) 35%,transparent);}

.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:22px;}
@media(max-width:900px){.kpi-grid{grid-template-columns:repeat(2,1fr);}}

.kpi-card{background:var(--bg2);border:1px solid var(--line);border-radius:18px;padding:18px;position:relative;overflow:hidden;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;}
.kpi-card:nth-child(1){animation-delay:.04s}
.kpi-card:nth-child(2){animation-delay:.08s}
.kpi-card:nth-child(3){animation-delay:.12s}
.kpi-card:nth-child(4){animation-delay:.16s}
@keyframes cardIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}

.kpi-orb{position:absolute;top:-25px;left:-25px;width:85px;height:85px;border-radius:50%;opacity:.07;filter:blur(20px);pointer-events:none;}
.kpi-icon-box{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;}
.kpi-icon-box svg{width:17px;height:17px;}
.kpi-val{font-family:'Fraunces',serif;font-size:26px;font-weight:900;color:var(--ink);letter-spacing:-.5px;line-height:1;margin-bottom:4px;}
.kpi-val.sm{font-size:18px;}
.kpi-label{font-size:11px;color:var(--ink2);font-weight:700;}

.section-card{background:var(--bg2);border:1px solid var(--line);border-radius:18px;padding:20px;margin-bottom:18px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both .1s;}
.section-title{font-family:'Fraunces',serif;font-size:16px;font-weight:800;color:var(--ink);margin-bottom:4px;}
.section-sub{font-size:12px;color:var(--ink2);font-weight:600;margin-bottom:18px;}

.comp-table{width:100%;border-collapse:collapse;min-width:640px;}
.comp-table th{padding:10px 14px;text-align:right;font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--line);}
.comp-table td{padding:14px;border-bottom:1px solid var(--line);font-size:13px;vertical-align:middle;}
.comp-table tr:last-child td{border-bottom:none;}
.comp-table tr:hover td{background:var(--bg3);}
.branch-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-left:8px;vertical-align:middle;}
.branch-name-cell{font-weight:800;color:var(--ink);}
.share-bar{display:flex;align-items:center;gap:8px;min-width:140px;}
.share-track{flex:1;height:6px;background:var(--bg3);border-radius:3px;overflow:hidden;}
.share-fill{height:100%;border-radius:3px;transition:width .8s cubic-bezier(.22,1,.36,1);}
.share-pct{font-size:12px;font-weight:800;color:var(--ink);min-width:38px;text-align:left;}
.money-cell{font-family:'Fraunces',serif;font-weight:900;color:var(--p);}

.hourly-grid{display:flex;flex-direction:column;gap:18px;}
.hourly-row-head{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
.hourly-row-name{font-size:13px;font-weight:800;color:var(--ink);}
.hourly-row-sub{font-size:11px;color:var(--ink2);margin-right:auto;}
.hourly-bars{display:flex;align-items:flex-end;gap:3px;height:80px;padding:8px 0;}
.hourly-bar{flex:1;min-width:8px;border-radius:3px 3px 0 0;transition:all .3s;min-height:2px;position:relative;cursor:pointer;}
.hourly-bar:hover{filter:brightness(1.3);}
.hourly-bar[data-tip]:hover::after{content:attr(data-tip);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:var(--bg4);color:var(--ink);font-size:10px;padding:4px 8px;border-radius:6px;white-space:nowrap;margin-bottom:4px;border:1px solid var(--line);}
.hourly-labels{display:flex;justify-content:space-between;margin-top:4px;font-size:9px;color:var(--ink2);font-weight:700;}

.staff-table{width:100%;border-collapse:collapse;min-width:600px;}
.staff-table th{padding:10px 14px;text-align:right;font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--line);}
.staff-table td{padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;}
.staff-table tr:last-child td{border-bottom:none;}
.role-chip{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:800;border:1px solid;}
.role-waiter  {background:rgba(59,130,246,.12);color:#3B82F6;border-color:rgba(59,130,246,.22);}
.role-kitchen {background:rgba(245,158,11,.12);color:#F59E0B;border-color:rgba(245,158,11,.22);}
.role-cashier {background:rgba(34,197,94,.12);color:#22C55E;border-color:rgba(34,197,94,.22);}

.scroll-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -20px;padding:0 20px;}

.empty-state{text-align:center;padding:60px 20px;color:var(--ink2);}
.empty-state-icon{width:64px;height:64px;border-radius:20px;background:var(--bg3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;}
.empty-state p{font-size:14px;font-weight:600;color:var(--ink2);margin-bottom:16px;}

@media(max-width:600px){
    .period-tabs{flex-wrap:wrap;}
    .period-tab{font-size:11px;padding:6px 12px;}
    .kpi-val{font-size:20px;}
    .kpi-val.sm{font-size:14px;}
}
</style>

<div class="main">

    <a href="reports.php" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
        رجوع للتقارير
    </a>

    <div class="bc-header">
        <div>
            <div class="page-title">مقارنة الفروع</div>
            <div class="page-subtitle">مقارنة أداء فروع المطعم عبر المؤشرات الرئيسية</div>
        </div>
        <div class="period-tabs">
            <a href="?period=7"  class="period-tab <?= $period==7 ?'active':'' ?>">7 أيام</a>
            <a href="?period=30" class="period-tab <?= $period==30?'active':'' ?>">30 يوم</a>
            <a href="?period=90" class="period-tab <?= $period==90?'active':'' ?>">90 يوم</a>
        </div>
    </div>

    <?php if(count($branches_comp) < 2): ?>
    <div class="section-card">
        <div class="empty-state">
            <div class="empty-state-icon">🏠</div>
            <p>لازم يكون عندك فرعين أو أكتر لعرض المقارنة</p>
            <a href="branches.php" class="btn btn-primary btn-sm">إضافة فرع جديد</a>
        </div>
    </div>
    <?php else: ?>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-orb" style="background:var(--p);"></div>
            <div class="kpi-icon-box" style="background:rgba(255,107,53,.12);color:var(--p);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            </div>
            <div class="kpi-val"><?= fmt_price($total_all, $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
            <div class="kpi-label">إجمالي الإيرادات</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-orb" style="background:#3B82F6;"></div>
            <div class="kpi-icon-box" style="background:rgba(59,130,246,.12);color:#3B82F6;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
            </div>
            <div class="kpi-val"><?= number_format($orders_all) ?></div>
            <div class="kpi-label">إجمالي الطلبات</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-orb" style="background:#22C55E;"></div>
            <div class="kpi-icon-box" style="background:rgba(34,197,94,.12);color:#22C55E;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </div>
            <div class="kpi-val sm"><?= htmlspecialchars($best_branch['name'] ?? '—') ?></div>
            <div class="kpi-label">أفضل فرع</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-orb" style="background:#8B5CF6;"></div>
            <div class="kpi-icon-box" style="background:rgba(139,92,246,.12);color:#8B5CF6;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            </div>
            <div class="kpi-val"><?= fmt_price($orders_all > 0 ? $total_all / $orders_all : 0, $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
            <div class="kpi-label">متوسط الطلب</div>
        </div>
    </div>

    <div class="section-card">
        <div class="section-title">جدول المقارنة التفصيلي</div>
        <div class="section-sub">كل المقاييس لكل فرع خلال الفترة المختارة</div>
        <div class="scroll-wrap">
            <table class="comp-table">
                <thead>
                    <tr>
                        <th>الفرع</th>
                        <th>الطلبات</th>
                        <th>الإيرادات</th>
                        <th>متوسط الطلب</th>
                        <th>مسلّمة</th>
                        <th>ملغية</th>
                        <th>الحصة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($branches_comp as $idx => $bc):
                        $share = $total_all > 0 ? round(($bc['total_revenue'] / $total_all) * 100, 1) : 0;
                        $color = $branch_colors[$idx % count($branch_colors)];
                    ?>
                    <tr>
                        <td>
                            <span class="branch-dot" style="background:<?= $color ?>"></span>
                            <span class="branch-name-cell"><?= htmlspecialchars($bc['name']) ?></span>
                            <?php if(!$bc['is_active']): ?>
                            <span class="badge badge-inactive" style="font-size:9px;margin-right:6px;">معطّل</span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($bc['total_orders']) ?></td>
                        <td class="money-cell"><?= fmt_price($bc['total_revenue'], $cur_symbol, $cur_decimals, $cur_prefix) ?></td>
                        <td><?= fmt_price($bc['avg_order'], $cur_symbol, $cur_decimals, $cur_prefix) ?></td>
                        <td style="color:#22C55E;font-weight:800;"><?= $bc['delivered'] ?></td>
                        <td style="color:#EF4444;font-weight:800;"><?= $bc['cancelled'] ?></td>
                        <td>
                            <div class="share-bar">
                                <div class="share-track">
                                    <div class="share-fill" style="width:<?= $share ?>%;background:<?= $color ?>;"></div>
                                </div>
                                <div class="share-pct"><?= $share ?>%</div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if(!empty($hourly_by_branch)): ?>
    <div class="section-card">
        <div class="section-title">توزيع الطلبات بالساعة</div>
        <div class="section-sub">مقارنة ساعات الذروة بين الفروع</div>
        <div class="hourly-grid">
            <?php foreach($hourly_by_branch as $bid => $br):
                $idx = array_search($bid, array_column($branches_comp, 'id'));
                $color = $branch_colors[($idx !== false ? $idx : 0) % count($branch_colors)];
                $total_br = array_sum($br['data'] ?? []);
            ?>
            <div class="hourly-row">
                <div class="hourly-row-head">
                    <span class="branch-dot" style="background:<?= $color ?>"></span>
                    <span class="hourly-row-name"><?= htmlspecialchars($br['name']) ?></span>
                    <span class="hourly-row-sub"><?= $total_br ?> طلب</span>
                </div>
                <div class="hourly-bars">
                    <?php for($h = 0; $h < 24; $h++):
                        $val = $br['data'][$h] ?? 0;
                        $height = $max_hourly > 0 ? ($val / $max_hourly) * 100 : 0;
                    ?>
                    <div class="hourly-bar" 
                         style="height:<?= $height ?>%;background:<?= $color ?>;opacity:<?= $val > 0 ? 0.85 : 0.15 ?>;"
                         data-tip="<?= sprintf('%02d:00 — %d طلب', $h, $val) ?>"></div>
                    <?php endfor; ?>
                </div>
                <div class="hourly-labels">
                    <span>00</span><span>04</span><span>08</span><span>12</span><span>16</span><span>20</span><span>23</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!empty($staff_perf)): ?>
    <div class="section-card">
        <div class="section-title">أداء الموظفين</div>
        <div class="section-sub">أعلى الموظفين أداءً عبر كل الفروع</div>
        <div class="scroll-wrap">
            <table class="staff-table">
                <thead>
                    <tr>
                        <th>الموظف</th>
                        <th>الدور</th>
                        <th>الفرع</th>
                        <th>التسليمات</th>
                        <th>المدفوعات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($staff_perf as $sp): ?>
                    <tr>
                        <td style="font-weight:800;"><?= htmlspecialchars($sp['staff_name']) ?></td>
                        <td>
                            <span class="role-chip role-<?= $sp['role'] ?>">
                                <?= $role_labels[$sp['role']] ?? $sp['role'] ?>
                            </span>
                        </td>
                        <td style="color:var(--ink2);font-weight:600;"><?= htmlspecialchars($sp['branch_name'] ?? '—') ?></td>
                        <td style="font-weight:800;"><?= $sp['deliveries'] ?: '—' ?></td>
                        <td><?= $sp['payments'] ?: '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

</body>
</html>