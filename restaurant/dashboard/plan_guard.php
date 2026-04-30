<?php
/**
 * plan_guard.php
 * ضمّنه في أي صفحة dashboard بعد session_start() وقبل أي output
 * مثال: require_once 'plan_guard.php';
 *
 * الاستخدام:
 *   plan_required('advanced');          // يمنع basic
 *   plan_required('premium');           // يمنع basic و advanced
 *   plan_has_feature('orders');         // يرجع true/false
 *   plan_max_branches();                // 1 للأساسية/المتقدمة، PHP_INT_MAX للبريميوم
 *
 * الباقات (محدّثة 27 أبريل 2026):
 *   basic    $15/شهر  — منيو فقط: QR + ألوان/شعار + فرع واحد + دعم 24h
 *   advanced $20/شهر  — + AR + تقييمات + إحصائيات + تقارير + ضرائب
 *                       + طلبات لحظي + موظفين + كوبونات + عروض
 *   premium  $25/شهر  — + شام كاش + فروع متعددة + دعم واتساب مباشر + تدريب أونلاين
 */

// ===== تعريف الميزات لكل باقة =====
if (!defined('PLAN_FEATURES')) {
    define('PLAN_FEATURES', [
        'basic' => [
            'menu',          // منيو QR + أطباق + فئات (غير محدودة)
            'branding',      // تخصيص ألوان/شعار
            'support_24h',
        ],
        'advanced' => [
            'menu',
            'branding',
            'support_24h',
            'ar',                // AR ثلاثي الأبعاد
            'ratings',           // تقييمات الزبائن (طلب + نادل)
            'stats',             // إحصائيات لحظية
            'reports',           // تقارير يومية/شهرية
            'branch_compare',    // مقارنة فروع + ساعات الذروة
            'advanced_fonts',    // تخصيص خطوط متقدمة
            'priority_support',
            'orders',            // نظام طلبات لحظي ← انتقلت من premium
            'staff',             // كاشير + مطبخ + نادل ← انتقلت من premium
            'coupons',           // كوبونات ← انتقلت من premium
            'offers',            // عروض BxGy / كومبو ← انتقلت من premium
        ],
        'premium' => [
            'menu',
            'branding',
            'support_24h',
            'ar',
            'ratings',
            'stats',
            'reports',
            'branch_compare',
            'advanced_fonts',
            'priority_support',
            'orders',
            'staff',
            'coupons',
            'offers',
            'shamcash',           // شام كاش (premium-only)
            'multi_branch',       // فروع متعددة (premium-only)
            'direct_support',     // دعم واتساب مباشر (رد خلال ساعة) — بديل account_manager
            'onboarding_training',// تدريب أونلاين للفريق (جلسة Zoom 30 د) — بديل account_manager
        ],
    ]);
}

// ترتيب الباقات (للمقارنة)
if (!defined('PLAN_RANK')) {
    define('PLAN_RANK', ['basic' => 1, 'advanced' => 2, 'premium' => 3]);
}

// أسعار شهرية (USD)
if (!defined('PLAN_PRICES')) {
    define('PLAN_PRICES', ['basic' => 15, 'advanced' => 20, 'premium' => 25]);
}

// أسعار سنوية (USD) — = 10 شهور (شهرين مجاناً)
if (!defined('PLAN_YEARLY_PRICES')) {
    define('PLAN_YEARLY_PRICES', ['basic' => 150, 'advanced' => 200, 'premium' => 250]);
}

// حد عدد الفروع لكل باقة
if (!defined('PLAN_BRANCH_LIMITS')) {
    define('PLAN_BRANCH_LIMITS', ['basic' => 1, 'advanced' => 1, 'premium' => PHP_INT_MAX]);
}

/**
 * يرجع باقة الجلسة الحالية (basic لو مش معرّفة)
 */
function plan_current(): string {
    return $_SESSION['restaurant_plan'] ?? 'basic';
}

/**
 * تحقق إذا الباقة الحالية تدعم الميزة
 */
function plan_has_feature(string $feature): bool {
    $plan = plan_current();
    return in_array($feature, PLAN_FEATURES[$plan] ?? [], true);
}

/**
 * الحد الأقصى لعدد الفروع للباقة الحالية
 */
function plan_max_branches(): int {
    $plan = plan_current();
    return PLAN_BRANCH_LIMITS[$plan] ?? 1;
}

/**
 * إذا الباقة ما تدعم الميزة → أظهر صفحة upgrade وأوقف التنفيذ
 */
function plan_required(string $min_plan): void {
    $current       = plan_current();
    $current_rank  = PLAN_RANK[$current]  ?? 1;
    $required_rank = PLAN_RANK[$min_plan] ?? 1;

    if ($current_rank < $required_rank) {
        plan_show_upgrade_page($min_plan, $current);
        exit;
    }
}

/**
 * نفس الفكرة لكن بناء على feature flag بدل اسم الباقة
 */
function plan_feature_required(string $feature): void {
    if (plan_has_feature($feature)) return;
    // لقي أصغر باقة بتدعم الميزة
    $needed = 'premium';
    foreach (['basic','advanced','premium'] as $p) {
        if (in_array($feature, PLAN_FEATURES[$p] ?? [], true)) {
            $needed = $p; break;
        }
    }
    plan_show_upgrade_page($needed, plan_current());
    exit;
}

/**
 * صفحة الـ upgrade
 */
function plan_show_upgrade_page(string $required, string $current): void {
    $plan_names = [
        'basic'    => '🟠 الأساسية',
        'advanced' => '🔴 المتقدمة',
        'premium'  => '👑 الاحترافية',
    ];
    $plan_prices = PLAN_PRICES;
    $plan_colors = [
        'basic'    => '#F59E0B',
        'advanced' => '#EF4444',
        'premium'  => '#FF6B35',
    ];

    $features_map = [
        'advanced' => [
            'AR ثلاثي الأبعاد للأطباق',
            'تقييمات الزبائن (طلب + نادل)',
            'إحصائيات وتقارير لحظية',
            'مقارنة فروع وساعات الذروة',
            'نظام طلبات لحظي',
            'كاشير + مطبخ + نادل (شاشات منفصلة)',
            'كوبونات وعروض (BxGy / كومبو)',
            'إدارة موظفين بصلاحيات',
            'إدارة الضرائب',
            'تخصيص خطوط متقدمة',
            'دعم أولوية',
        ],
        'premium'  => [
            'دفع شام كاش + نقدي',
            'فروع متعددة (غير محدود)',
            'دعم واتساب مباشر (رد خلال ساعة)',
            'تدريب أونلاين للفريق (جلسة Zoom)',
            'كل ميزات الباقة المتقدمة',
        ],
    ];
    $unlock_features = $features_map[$required] ?? [];
    $price = $plan_prices[$required] ?? '?';
    $accent = $plan_colors[$required] ?? '#FF6B35';

    echo '<!DOCTYPE html><html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ترقية الباقة</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:"Tajawal",sans-serif;background:#0C0C0C;color:#F0EBE3;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.box{width:100%;max-width:460px;background:#141414;border:1px solid rgba(255,255,255,0.06);border-radius:24px;overflow:hidden;}
.box-top{background:linear-gradient(135deg,' . $accent . '22,' . $accent . '08);padding:36px 32px 28px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06);}
.lock-icon{width:64px;height:64px;border-radius:20px;background:' . $accent . '22;border:1px solid ' . $accent . '44;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;}
.box-title{font-family:"Fraunces",serif;font-size:22px;font-weight:900;color:#F0EBE3;margin-bottom:8px;}
.box-sub{font-size:13px;color:rgba(240,235,227,0.5);line-height:1.6;}
.badge-wrap{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px;}
.badge{padding:4px 12px;border-radius:20px;font-size:11px;font-weight:800;}
.badge-current{background:rgba(107,114,128,0.15);color:#9CA3AF;border:1px solid rgba(107,114,128,0.2);}
.badge-arrow{color:rgba(240,235,227,0.3);font-size:14px;}
.badge-required{border:1px solid;font-size:12px;padding:5px 14px;background:' . $accent . '22;color:' . $accent . ';border-color:' . $accent . '44;}
.box-body{padding:24px 32px;}
.features-title{font-size:11px;font-weight:800;color:rgba(240,235,227,0.4);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:14px;}
.feature-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,0.04);}
.feature-row:last-child{border-bottom:none;}
.feature-check{width:22px;height:22px;border-radius:7px;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:11px;color:#22C55E;}
.feature-name{font-size:13px;font-weight:600;color:#F0EBE3;}
.price-row{display:flex;align-items:baseline;gap:4px;margin:20px 0 16px;justify-content:center;}
.price-num{font-family:"Fraunces",serif;font-size:42px;font-weight:900;color:' . $accent . ';}
.price-period{font-size:13px;color:rgba(240,235,227,0.4);}
.upgrade-btn{display:block;width:100%;padding:14px;background:' . $accent . ';color:#fff;border:none;border-radius:14px;font-size:15px;font-weight:800;cursor:pointer;font-family:"Tajawal",sans-serif;text-align:center;text-decoration:none;box-shadow:0 4px 20px ' . $accent . '66;margin-bottom:10px;}
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
<span class="badge badge-required">' . ($plan_names[$required] ?? $required) . '</span>
</div>
</div>
<div class="box-body">
<div class="features-title">ما ستحصل عليه</div>';
    foreach ($unlock_features as $f) {
        echo '<div class="feature-row"><div class="feature-check">✓</div><div class="feature-name">' . $f . '</div></div>';
    }
    echo '<div class="price-row">
<div class="price-num">$' . $price . '</div>
<div class="price-period">/ شهر</div>
</div>
<a href="https://wa.me/963934609138?text=' . rawurlencode('بدي ارقّي باقتي إلى ' . ($plan_names[$required] ?? $required)) . '" class="upgrade-btn">📱 تواصل عبر واتساب للترقية</a>
<a href="javascript:history.back()" class="back-btn">← رجوع</a>
</div>
</div>
</body></html>';
}
