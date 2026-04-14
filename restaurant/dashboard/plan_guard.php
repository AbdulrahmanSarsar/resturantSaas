<?php
/**
 * plan_guard.php
 * ضمّنه في أي صفحة dashboard بعد session_start() وقبل أي output
 * مثال: require_once 'plan_guard.php';
 *
 * الاستخدام:
 *   plan_required('advanced');          // يمنع basic
 *   plan_required('premium');           // يمنع basic و advanced
 *   plan_feature_check('orders');       // يرجع true/false
 */

// ===== تعريف الميزات لكل باقة =====
define('PLAN_FEATURES', [
    'basic' => [
        'menu',       // منيو + QR
    ],
    'advanced' => [
        'menu',
        'ar',         // عرض AR
        'ratings',    // تقييمات
        'stats',      // إحصائيات متقدمة
    ],
    'premium' => [
        'menu',
        'ar',
        'ratings',
        'stats',
        'orders',     // نظام الطلبات
        'coupons',    // كوبونات
        'staff',      // أدوار الموظفين
        'shamcash',   // شام كاش
    ],
]);

// ترتيب الباقات
define('PLAN_RANK', ['basic' => 1, 'advanced' => 2, 'premium' => 3]);

/**
 * تحقق إذا الباقة الحالية تدعم الميزة
 */
function plan_has_feature(string $feature): bool {
    $plan = $_SESSION['restaurant_plan'] ?? 'basic';
    return in_array($feature, PLAN_FEATURES[$plan] ?? []);
}

/**
 * إذا الباقة ما تدعم الميزة → أظهر صفحة upgrade وأوقف التنفيذ
 */
function plan_required(string $min_plan): void {
    $current = $_SESSION['restaurant_plan'] ?? 'basic';
    $current_rank = PLAN_RANK[$current] ?? 1;
    $required_rank = PLAN_RANK[$min_plan] ?? 1;

    if ($current_rank < $required_rank) {
        plan_show_upgrade_page($min_plan, $current);
        exit;
    }
}

/**
 * صفحة الـ upgrade
 */
function plan_show_upgrade_page(string $required, string $current): void {
    $plan_names = ['basic' => 'الأساسية', 'advanced' => 'المتقدمة', 'premium' => 'البريميوم'];
    $plan_prices = ['basic' => '80', 'advanced' => '180', 'premium' => '280'];
    $plan_colors = ['basic' => '#6B7280', 'advanced' => '#3B82F6', 'premium' => '#F59E0B'];

    $features_map = [
        'advanced' => ['AR ثلاثي الأبعاد', 'تقييمات الزبائن', 'إحصائيات متقدمة'],
        'premium'  => ['نظام الطلبات الكامل', 'كوبونات الخصم', 'أدوار الموظفين (نادل/مطبخ)', 'الدفع بشام كاش'],
    ];
    $unlock_features = $features_map[$required] ?? [];

    echo '<!DOCTYPE html><html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ترقية الباقة</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:"Tajawal",sans-serif;background:#0C0C0C;color:#F0EBE3;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.box{width:100%;max-width:460px;background:#141414;border:1px solid rgba(255,255,255,0.06);border-radius:24px;overflow:hidden;}
.box-top{background:linear-gradient(135deg,rgba(255,107,53,0.12),rgba(255,107,53,0.04));padding:36px 32px 28px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06);}
.lock-icon{width:64px;height:64px;border-radius:20px;background:rgba(255,107,53,0.12);border:1px solid rgba(255,107,53,0.2);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;}
.box-title{font-family:"Fraunces",serif;font-size:22px;font-weight:900;color:#F0EBE3;margin-bottom:8px;}
.box-sub{font-size:13px;color:rgba(240,235,227,0.5);line-height:1.6;}
.badge-wrap{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px;}
.badge{padding:4px 12px;border-radius:20px;font-size:11px;font-weight:800;}
.badge-current{background:rgba(107,114,128,0.15);color:#9CA3AF;border:1px solid rgba(107,114,128,0.2);}
.badge-arrow{color:rgba(240,235,227,0.3);font-size:14px;}
.badge-required{border:1px solid;font-size:12px;padding:5px 14px;}
.box-body{padding:24px 32px;}
.features-title{font-size:11px;font-weight:800;color:rgba(240,235,227,0.4);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:14px;}
.feature-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,0.04);}
.feature-row:last-child{border-bottom:none;}
.feature-check{width:22px;height:22px;border-radius:7px;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:11px;}
.feature-name{font-size:13px;font-weight:600;color:#F0EBE3;}
.price-row{display:flex;align-items:baseline;gap:4px;margin:20px 0 16px;}
.price-num{font-family:"Fraunces",serif;font-size:36px;font-weight:900;color:#FF6B35;}
.price-period{font-size:13px;color:rgba(240,235,227,0.4);}
.upgrade-btn{display:block;width:100%;padding:14px;background:#FF6B35;color:#fff;border:none;border-radius:14px;font-size:15px;font-weight:800;cursor:pointer;font-family:"Tajawal",sans-serif;text-align:center;text-decoration:none;box-shadow:0 4px 20px rgba(255,107,53,0.4);margin-bottom:10px;}
.back-btn{display:block;width:100%;padding:12px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:14px;font-size:13px;font-weight:700;color:rgba(240,235,227,0.5);cursor:pointer;font-family:"Tajawal",sans-serif;text-align:center;text-decoration:none;}
</style>
</head>
<body>
<div class="box">
<div class="box-top">
<div class="lock-icon">🔒</div>
<div class="box-title">هاي الميزة محجوبة</div>
<div class="box-sub">باقتك الحالية ما تتضمن هاد القسم<br>رقّي باقتك للوصول إليه</div>
<div class="badge-wrap">
<span class="badge badge-current">' . ($plan_names[$current] ?? $current) . '</span>
<span class="badge-arrow">←</span>
<span class="badge badge-required" style="background:rgba(' . ($required === 'premium' ? '245,158,11' : '59,130,246') . ',0.12);color:' . ($plan_colors[$required] ?? '#FF6B35') . ';border-color:' . ($required === 'premium' ? 'rgba(245,158,11,0.25)' : 'rgba(59,130,246,0.25)') . '">' . ($plan_names[$required] ?? $required) . '</span>
</div>
</div>
<div class="box-body">
<div class="features-title">ما ستحصل عليه</div>';
    foreach ($unlock_features as $f) {
        echo '<div class="feature-row"><div class="feature-check">✓</div><div class="feature-name">' . $f . '</div></div>';
    }
    echo '<div class="price-row">
<div class="price-num">$' . ($plan_prices[$required] ?? '?') . '</div>
<div class="price-period">/ شهر</div>
</div>
<a href="mailto:support@almanarsoft.com?subject=طلب ترقية باقة" class="upgrade-btn">تواصل معنا للترقية</a>
<a href="javascript:history.back()" class="back-btn">← رجوع</a>
</div>
</div>
</body></html>';
}