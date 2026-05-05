<?php
session_start();
require_once '../config/database.php';
if(!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

// Platform stats — NO restaurant revenue/order details
$stats = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(is_active=1) as active,
        SUM(is_active=0) as inactive,
        SUM(subscription_plan='free') as free,
        SUM(subscription_plan='basic') as basic,
        SUM(subscription_plan='advanced') as advanced,
        SUM(subscription_plan='premium') as premium,
        SUM(subscription_expiry < CURDATE()) as expired,
        SUM(subscription_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) as expiring_soon
    FROM restaurants
")->fetch();

// Platform subscription revenue (what the platform earns — not restaurant sales)
$plan_prices = ['basic' => 80, 'advanced' => 180, 'premium' => 280];
$platform_mrr = ($stats['basic'] * 80) + ($stats['advanced'] * 180) + ($stats['premium'] * 280);

// New restaurants this month
$new_this_month = $pdo->query("SELECT COUNT(*) FROM restaurants WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();

// Total orders across all restaurants (count only — no amounts shown)
$total_orders   = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$today_orders   = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// Recent restaurants
$recent_restaurants = $pdo->query("SELECT * FROM restaurants ORDER BY created_at DESC LIMIT 6")->fetchAll();

// Subscriptions expiring in 7 days
$expiring = $pdo->query("
    SELECT name, email, subscription_plan, subscription_expiry,
           DATEDIFF(subscription_expiry, CURDATE()) as days_left
    FROM restaurants
    WHERE subscription_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY subscription_expiry ASC
    LIMIT 5
")->fetchAll();

require_once 'sidebar.php';
?>
<style>
/* ===== KPI GRID ===== */
.kpi-grid {
    display: grid; grid-template-columns: repeat(4,1fr);
    gap: 12px; margin-bottom: 20px;
}
@media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px) { .kpi-grid { grid-template-columns: 1fr 1fr; gap:10px; } }

.kpi-card {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; padding: 18px;
    transition: transform 0.2s, box-shadow 0.2s;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
    position: relative; overflow: hidden;
}
.kpi-card:nth-child(1){animation-delay:.04s}
.kpi-card:nth-child(2){animation-delay:.08s}
.kpi-card:nth-child(3){animation-delay:.12s}
.kpi-card:nth-child(4){animation-delay:.16s}
.kpi-card:hover { transform: translateY(-3px); box-shadow: var(--shadow2); }

.kpi-icon {
    width: 36px; height: 36px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 14px; flex-shrink: 0;
}
.kpi-icon svg { width: 17px; height: 17px; }
.kpi-num {
    font-family: 'Fraunces', serif;
    font-size: 30px; font-weight: 900; color: var(--ink);
    line-height: 1; margin-bottom: 4px;
    transition: color 0.3s;
}
.kpi-label { font-size: 12px; color: var(--ink2); font-weight: 600; }
.kpi-sub   { font-size: 11px; color: var(--ink3); margin-top: 6px; font-weight: 500; }

/* MRR hero card */
.mrr-card {
    background: var(--p); border: none;
    grid-column: span 2;
    display: flex; align-items: center; justify-content: space-between;
    padding: 20px 24px;
    box-shadow: 0 8px 28px var(--p-glow);
}
@media (max-width: 480px) { .mrr-card { grid-column: span 2; } }
.mrr-left {}
.mrr-label { font-size: 12px; color: rgba(255,255,255,0.75); font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px; text-transform: uppercase; }
.mrr-num {
    font-family: 'Fraunces', serif;
    font-size: 38px; font-weight: 900; color: #fff; line-height: 1;
}
.mrr-sub { font-size: 12px; color: rgba(255,255,255,0.65); margin-top: 5px; font-weight: 600; }
.mrr-icon {
    width: 56px; height: 56px; border-radius: 16px;
    background: rgba(255,255,255,0.15);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.mrr-icon svg { width: 24px; height: 24px; color: #fff; }

/* ===== PLAN BREAKDOWN ===== */
.plan-row {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 18px; border-bottom: 1px solid var(--line);
    animation: cardIn 0.35s cubic-bezier(0.22,1,0.36,1) both;
    transition: background 0.15s;
}
.plan-row:last-child { border-bottom: none; }
.plan-row:hover { background: color-mix(in srgb, var(--p) 3%, transparent); }
.plan-color { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.plan-name  { font-size: 13px; font-weight: 700; color: var(--ink); flex: 1; }
.plan-count {
    font-family: 'Fraunces', serif;
    font-size: 20px; font-weight: 900; color: var(--ink);
}
.plan-price { font-size: 11px; color: var(--ink2); font-weight: 600; text-align: left; min-width: 60px; }
.plan-bar-wrap { width: 80px; height: 6px; background: var(--bg3); border-radius: 6px; overflow: hidden; }
.plan-bar { height: 100%; border-radius: 6px; transition: width 0.6s cubic-bezier(0.22,1,0.36,1); }

/* ===== CONTENT GRID ===== */
.content-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 14px; margin-top: 14px;
}
@media (max-width: 900px) { .content-grid { grid-template-columns: 1fr; } }

/* ===== RESTAURANT ROW ===== */
.rest-row {
    display: flex; align-items: center; gap: 11px;
    padding: 11px 18px; border-bottom: 1px solid var(--line);
    transition: background 0.15s;
}
.rest-row:last-child { border-bottom: none; }
.rest-row:hover { background: color-mix(in srgb, var(--p) 3%, transparent); }
.rest-logo {
    width: 38px; height: 38px; border-radius: 11px;
    flex-shrink: 0; border: 1px solid var(--line);
    background: var(--bg3); overflow: hidden;
    display: flex; align-items: center; justify-content: center;
}
.rest-logo img { width:100%; height:100%; object-fit:cover; }
.rest-logo svg { width: 16px; height: 16px; color: var(--ink3); }
.rest-info  { flex: 1; min-width: 0; }
.rest-name  { font-size: 13px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rest-email { font-size: 11px; color: var(--ink2); font-weight: 500; margin-top: 1px; }

/* ===== EXPIRING ROWS ===== */
.exp-row {
    display: flex; align-items: center; gap: 11px;
    padding: 11px 18px; border-bottom: 1px solid var(--line);
    transition: background 0.15s;
}
.exp-row:last-child { border-bottom: none; }
.exp-row:hover { background: rgba(245,158,11,0.04); }
.exp-days {
    font-family: 'Fraunces', serif;
    font-size: 18px; font-weight: 900;
    min-width: 32px; text-align: center; flex-shrink: 0;
}
.exp-info  { flex: 1; min-width: 0; }
.exp-name  { font-size: 13px; font-weight: 700; color: var(--ink); }
.exp-date  { font-size: 11px; color: var(--ink2); font-weight: 500; margin-top: 1px; }
.exp-action a {
    font-size: 11px; font-weight: 700; color: var(--p);
    text-decoration: none;
}
.exp-action a:hover { opacity: 0.7; }

/* ===== SECTION HEAD ===== */
.section-head {
    display: flex; align-items: center; gap: 8px; margin-bottom: 14px;
}
.section-head h3 {
    font-family: 'Fraunces', serif;
    font-size: 15px; font-weight: 700; color: var(--ink);
}
.section-head::after { content:''; flex:1; height:1px; background:var(--line); }
</style>

<div class="main">

    <div style="margin-bottom:22px; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:12px;">
        <div>
            <div class="page-title">لوحة التحكم</div>
            <div class="page-subtitle">مرحباً <?= htmlspecialchars($_SESSION['admin_name']) ?> — <?= date('d/m/Y') ?></div>
        </div>
        <a href="restaurants/add.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            إضافة مطعم
        </a>
    </div>

    <!-- KPI Row -->
    <div class="kpi-grid">

        <!-- MRR -->
        <div class="kpi-card mrr-card">
            <div class="mrr-left">
                <div class="mrr-label">إيرادات الاشتراكات الشهرية</div>
                <div class="mrr-num">$<?= number_format($platform_mrr) ?></div>
                <div class="mrr-sub"><?= $stats['active'] ?> مطعم نشط × متوسط الاشتراك</div>
            </div>
            <div class="mrr-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <line x1="12" y1="1" x2="12" y2="23"/>
                    <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                </svg>
            </div>
        </div>

        <!-- Total restaurants -->
        <div class="kpi-card">
            <div class="kpi-icon" style="background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/>
                    <line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>
                </svg>
            </div>
            <div class="kpi-num"><?= $stats['total'] ?></div>
            <div class="kpi-label">إجمالي المطاعم</div>
            <div class="kpi-sub">+<?= $new_this_month ?> هذا الشهر</div>
        </div>

        <!-- Active -->
        <div class="kpi-card">
            <div class="kpi-icon" style="background:rgba(34,197,94,0.12);color:#22C55E">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <div class="kpi-num"><?= $stats['active'] ?></div>
            <div class="kpi-label">مطاعم نشطة</div>
            <div class="kpi-sub"><?= $stats['inactive'] ?> موقوف</div>
        </div>

        <!-- Expired -->
        <div class="kpi-card">
            <div class="kpi-icon" style="background:rgba(239,68,68,0.10);color:#EF4444">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <div class="kpi-num" style="<?= $stats['expired'] > 0 ? 'color:#EF4444' : '' ?>"><?= $stats['expired'] ?></div>
            <div class="kpi-label">اشتراك منتهي</div>
            <div class="kpi-sub"><?= $stats['expiring_soon'] ?> ينتهي قريباً</div>
        </div>

        <!-- Orders today -->
        <div class="kpi-card">
            <div class="kpi-icon" style="background:rgba(59,130,246,0.10);color:#3B82F6">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                </svg>
            </div>
            <div class="kpi-num"><?= $today_orders ?></div>
            <div class="kpi-label">طلبات اليوم</div>
            <div class="kpi-sub"><?= number_format($total_orders) ?> إجمالي</div>
        </div>

        <!-- Premium -->
        <div class="kpi-card">
            <div class="kpi-icon" style="background:rgba(245,158,11,0.12);color:#F59E0B">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
            </div>
            <div class="kpi-num"><?= $stats['premium'] ?></div>
            <div class="kpi-label">باقة بريميوم</div>
            <div class="kpi-sub">$280/شهر</div>
        </div>

    </div>

    <!-- Content -->
    <div class="content-grid">

        <!-- Plan breakdown -->
        <div class="card">
            <div class="card-header">
                <h3>توزيع الباقات</h3>
                <a href="subscriptions.php">إدارة ←</a>
            </div>
            <?php
            $plans_data = [
                ['basic',    'أساسية',  $stats['basic'],    80,  '#3B82F6'],
                ['advanced', 'متقدمة',  $stats['advanced'], 180, '#8B5CF6'],
                ['premium',  'بريميوم', $stats['premium'],  280, '#F59E0B'],
            ];
            $max_count = max(1, $stats['total']);
            foreach($plans_data as $d => $plan):
            ?>
            <div class="plan-row" style="animation-delay:<?= $d*0.06 ?>s">
                <div class="plan-color" style="background:<?= $plan[4] ?>"></div>
                <div class="plan-name"><?= $plan[1] ?></div>
                <div class="plan-bar-wrap">
                    <div class="plan-bar" style="width:<?= $max_count>0?($plan[2]/$max_count*100):0 ?>%;background:<?= $plan[4] ?>"></div>
                </div>
                <div class="plan-count"><?= $plan[2] ?></div>
                <div class="plan-price">$<?= $plan[3] ?>/شهر</div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Expiring soon -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <?php if($stats['expiring_soon'] > 0): ?>
                        <span style="color:#F59E0B">⚠</span>
                    <?php endif; ?>
                    ينتهون قريباً
                </h3>
                <a href="subscriptions.php">الكل ←</a>
            </div>
            <?php if(empty($expiring)): ?>
                <div class="empty-state" style="padding:40px 20px">
                    <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>
                    <p>لا توجد اشتراكات تنتهي قريباً</p>
                </div>
            <?php else: ?>
                <?php foreach($expiring as $e): ?>
                <div class="exp-row">
                    <div class="exp-days" style="color:<?= $e['days_left']<=2?'#EF4444':($e['days_left']<=4?'#F97316':'#F59E0B') ?>">
                        <?= $e['days_left'] ?>
                    </div>
                    <div class="exp-info">
                        <div class="exp-name"><?= htmlspecialchars($e['name']) ?></div>
                        <div class="exp-date">ينتهي <?= date('d/m/Y', strtotime($e['subscription_expiry'])) ?></div>
                    </div>
                    <span class="badge badge-<?= $e['subscription_plan'] ?>"><?= $e['subscription_plan'] ?></span>
                    <div class="exp-action" style="margin-right:8px"><a href="subscriptions.php">تجديد</a></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Recent restaurants -->
        <div class="card" style="grid-column:1/-1">
            <div class="card-header">
                <h3>آخر المطاعم المضافة</h3>
                <a href="restaurants/index.php">عرض الكل ←</a>
            </div>
            <?php if(empty($recent_restaurants)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/>
                        </svg>
                    </div>
                    <p>لا توجد مطاعم بعد</p>
                </div>
            <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr));">
                <?php foreach($recent_restaurants as $r): ?>
                <div class="rest-row">
                    <div class="rest-logo">
                        <?php if($r['logo']): ?>
                            <img src="<?= BASE_URL ?>/assets/uploads/<?= $r['logo'] ?>">
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div class="rest-info">
                        <div class="rest-name"><?= htmlspecialchars($r['name']) ?></div>
                        <div class="rest-email"><?= htmlspecialchars($r['email']) ?></div>
                    </div>
                    <span class="badge badge-<?= $r['subscription_plan'] ?>"><?= $r['subscription_plan'] ?></span>
                    <span class="badge <?= $r['is_active'] ? 'badge-active' : 'badge-inactive' ?>" style="margin-right:6px">
                        <?= $r['is_active'] ? 'نشط' : 'موقوف' ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

</body>
</html>