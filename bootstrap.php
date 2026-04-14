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
        'basic'    => ['menu'],
        'advanced' => ['menu', 'ar', 'ratings', 'stats'],
        'premium'  => ['menu', 'ar', 'ratings', 'stats', 'orders', 'coupons', 'staff', 'shamcash'],
    ]);
}
if (!defined('PLAN_RANK')) {
    define('PLAN_RANK', ['basic' => 1, 'advanced' => 2, 'premium' => 3]);
}