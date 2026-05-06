<?php
/**
 * MenuPro — Application Bootstrap
 * 
 * Include this file at the top of any page (old or new) to get:
 *   1. PSR-4 autoloading for /src/ classes
 *   2. Database connection ($pdo)
 *   3. Auth middleware ($auth)
 *   4. Tenant middleware ($tenant)
 *   5. Backward-compatible session variables
 * 
 * Usage in existing files:
 *   Replace:
 *     session_start();
 *     require_once '../config/database.php';
 *   With:
 *     require_once __DIR__ . '/../bootstrap.php';
 * 
 * Then gradually adopt the new patterns:
 *   $auth->requireRole('kitchen');
 *   $orders = $orderService->getKitchenOrders();
 */

// ============================================================
// Autoloader (PSR-4 style, no Composer needed)
// ============================================================
spl_autoload_register(function (string $class): void {
    $prefix    = 'MenuPro\\';
    $baseDir   = __DIR__ . '/src/';
    $prefixLen = strlen($prefix);

    // Only handle MenuPro\ namespace
    if (strncmp($class, $prefix, $prefixLen) !== 0) {
        return;
    }

    $relativeClass = substr($class, $prefixLen);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// ============================================================
// Configuration
// ============================================================
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/src/Helpers/PriceHelper.php';

// ============================================================
// Core Services Initialization
// ============================================================
use MenuPro\Middleware\AuthMiddleware;
use MenuPro\Middleware\TenantMiddleware;
use MenuPro\Services\OrderService;
use MenuPro\Services\ReportService;
use MenuPro\Services\DishService;
use MenuPro\Services\CategoryService;
use MenuPro\Services\BranchService;
use MenuPro\Services\MenuService;
use MenuPro\Helpers\PriceHelper;
use MenuPro\Helpers\FileUploader;

// Auth middleware (handles session + login/logout + CSRF)
$auth = new AuthMiddleware($pdo);

// Tenant middleware (enforces data isolation)
$tenant = new TenantMiddleware($pdo, $auth);

// ============================================================
// Service Container (simple, no framework needed)
// ============================================================
$services = [
    'order'    => fn() => new OrderService($pdo, $tenant),
    'report'   => fn() => new ReportService($pdo, $tenant),
    'dish'     => fn() => new DishService($pdo),
    'category' => fn() => new CategoryService($pdo),
    'branch'   => fn() => new BranchService($pdo),
    'menu'     => fn() => new MenuService($pdo),
    'uploader' => fn() => new FileUploader(),
];

/**
 * Get a service instance (lazy-loaded).
 * 
 * Usage: $orderService = service('order');
 */
function service(string $name): object
{
    global $services;
    static $instances = [];
    
    if (!isset($instances[$name])) {
        if (!isset($services[$name])) {
            throw new \RuntimeException("Unknown service: {$name}");
        }
        $instances[$name] = ($services[$name])();
    }
    
    return $instances[$name];
}

// ============================================================
// Price Helper (auto-configured from branch settings)
// ============================================================

/**
 * Get a PriceHelper configured for the current branch.
 * Falls back to defaults if no branch context exists.
 */
function priceHelper(): PriceHelper
{
    static $helper = null;
    
    if ($helper === null) {
        global $pdo, $tenant;
        
        $branchId = $tenant->branchId();
        if ($branchId) {
            $stmt = $pdo->prepare("SELECT * FROM branch_settings WHERE branch_id = ?");
            $stmt->execute([$branchId]);
            $settings = $stmt->fetch();
            
            if ($settings) {
                $helper = PriceHelper::fromBranch($settings);
            }
        }
        
        // Fallback
        if ($helper === null) {
            $helper = new PriceHelper('$', '$', 2);
        }
    }
    
    return $helper;
}

// ============================================================
// Backward Compatibility Bridge
// ============================================================

/**
 * These globals and functions exist so that existing un-migrated files
 * continue to work. Remove them as files are migrated.
 */

// Old code expects $pdo to exist — it does (from app.php)
// Old code expects session_start() — AuthMiddleware handles it
// Old code expects $_SESSION['restaurant_id'] etc. — AuthMiddleware populates them

// Global fmt_price function (loaded from PriceHelper.php)
require_once __DIR__ . '/src/Helpers/PriceHelper.php';

// plan_guard.php — DON'T redefine here, let the original file handle it.
// Pages that need plan_guard will require_once it themselves.
// Since they use require_once, it only loads once even if bootstrap also loaded it.
// We just make sure PLAN_FEATURES constant exists for pages that use bootstrap without plan_guard.
if (!defined('PLAN_FEATURES')) {
    define('PLAN_FEATURES', [
        'basic' => [
            'menu', 'branding', 'support_24h',
        ],
        'advanced' => [
            'menu', 'branding', 'support_24h',
            'ar', 'ratings', 'stats', 'reports',
            'branch_compare', 'advanced_fonts', 'priority_support',
            'orders', 'staff', 'coupons', 'offers', // ← انتقلوا من premium
        ],
        'premium' => [
            'menu', 'branding', 'support_24h',
            'ar', 'ratings', 'stats', 'reports',
            'branch_compare', 'advanced_fonts', 'priority_support',
            'orders', 'staff', 'coupons', 'offers',
            // [removed] shamcash — تم إلغاء شام كاش بالكامل
            'multi_branch',
            'direct_support', 'onboarding_training',
        ],
    ]);
}
if (!defined('PLAN_RANK')) {
    define('PLAN_RANK', ['basic' => 1, 'advanced' => 2, 'premium' => 3]);
}
if (!defined('PLAN_PRICES')) {
    define('PLAN_PRICES', ['basic' => 15, 'advanced' => 20, 'premium' => 25]);
}
if (!defined('PLAN_YEARLY_PRICES')) {
    define('PLAN_YEARLY_PRICES', ['basic' => 150, 'advanced' => 200, 'premium' => 250]);
}
if (!defined('PLAN_BRANCH_LIMITS')) {
    define('PLAN_BRANCH_LIMITS', ['basic' => 1, 'advanced' => 1, 'premium' => PHP_INT_MAX]);
}

/**
 * تحقق إذا مطعم معيّن يدعم ميزة (للزبائن + الموظفين — بدون session restaurant_plan).
 */
if (!function_exists('restaurant_has_feature')) {
    function restaurant_has_feature(int $rid, string $feature): bool {
        global $pdo;
        static $cache = [];
        if (!isset($cache[$rid])) {
            try {
                $st = $pdo->prepare("SELECT subscription_plan FROM restaurants WHERE id = ?");
                $st->execute([$rid]);
                $cache[$rid] = $st->fetchColumn() ?: 'basic';
            } catch (Exception $e) { $cache[$rid] = 'basic'; }
        }
        $plan = $cache[$rid];
        return in_array($feature, PLAN_FEATURES[$plan] ?? [], true);
    }
}

/**
 * يطلق رسالة للموظف لو باقة المطعم ما تدعم ميزة الـ staff. أوقف التنفيذ.
 */
if (!function_exists('staff_feature_or_die')) {
    function staff_feature_or_die(int $rid, string $feature, string $page_title = 'الميزة محجوبة'): void {
        if (restaurant_has_feature($rid, $feature)) return;
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . htmlspecialchars($page_title) . '</title>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@500;700;800&display=swap" rel="stylesheet">';
        echo '<style>body{font-family:"Tajawal",sans-serif;background:#0C0C0C;color:#F0EBE3;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}';
        echo '.box{max-width:420px;width:100%;background:#141414;border:1px solid rgba(255,255,255,0.08);border-radius:20px;padding:36px;text-align:center;}';
        echo '.ic{font-size:54px;margin-bottom:14px;}';
        echo 'h1{font-size:20px;margin:0 0 10px;color:#FF6B35;}';
        echo 'p{font-size:14px;line-height:1.7;color:rgba(240,235,227,0.6);margin:0 0 20px;}';
        echo 'a.btn{display:block;padding:12px;background:#FF6B35;color:#fff;text-decoration:none;border-radius:12px;font-weight:800;}';
        echo '</style></head><body><div class="box">';
        echo '<div class="ic">🔒</div>';
        echo '<h1>هذه الصفحة محجوبة</h1>';
        echo '<p>يجب على صاحب المطعم ترقية الباقة إلى <b style="color:#FF6B35;">الاحترافية 👑</b><br>لتعمل شاشات الموظفين (مطبخ / نادل / كاشير).</p>';
        echo '<a class="btn" href="login.php">رجوع لتسجيل الدخول</a>';
        echo '</div></body></html>';
        exit;
    }
}