<?php
/**
 * _debug_offers.php — Temporary debug helper.
 * افتحه بالمتصفح عشان تشوف وين بالضبط offers.php عم يفشل.
 * احذفه بعد ما نحل المشكلة.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<pre style='font:14px monospace;background:#111;color:#0f0;padding:20px;direction:ltr'>";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Working dir: " . __DIR__ . "\n";
echo "Script: " . __FILE__ . "\n\n";

echo "=== STEP 1: session_start ===\n";
session_start();
echo "session ok. id=" . session_id() . "\n";
echo "restaurant_id in session: " . ($_SESSION['restaurant_id'] ?? 'NOT SET') . "\n\n";

echo "=== STEP 2: load database.php ===\n";
$db_path = __DIR__ . '/../../config/database.php';
echo "path: $db_path\n";
echo "exists: " . (file_exists($db_path) ? 'YES' : 'NO') . "\n";
require_once $db_path;
echo "loaded ok. \$pdo type: " . (isset($pdo) ? get_class($pdo) : 'NOT SET') . "\n";
echo "csrf_field defined: " . (function_exists('csrf_field') ? 'YES' : 'NO') . "\n";
echo "csrf_token defined: " . (function_exists('csrf_token') ? 'YES' : 'NO') . "\n";
echo "csrf_require defined: " . (function_exists('csrf_require') ? 'YES' : 'NO') . "\n\n";

echo "=== STEP 3: load csrf.php manually ===\n";
$csrf_path = __DIR__ . '/../../config/csrf.php';
echo "path: $csrf_path\n";
echo "exists: " . (file_exists($csrf_path) ? 'YES' : 'NO') . "\n";
if (file_exists($csrf_path)) {
    require_once $csrf_path;
    echo "loaded ok\n";
} else {
    echo "SKIPPED — file missing\n";
}
echo "csrf_field defined now: " . (function_exists('csrf_field') ? 'YES' : 'NO') . "\n\n";

echo "=== STEP 4: load plan_guard.php ===\n";
$pg_path = __DIR__ . '/plan_guard.php';
echo "path: $pg_path\n";
echo "exists: " . (file_exists($pg_path) ? 'YES' : 'NO') . "\n";
require_once $pg_path;
echo "loaded ok\n";
echo "plan_required defined: " . (function_exists('plan_required') ? 'YES' : 'NO') . "\n\n";

if (!isset($_SESSION['restaurant_id'])) {
    echo "=== STOP: not logged in. سجّل دخول كمطعم ثم افتح هذه الصفحة ثانية. ===\n";
    exit;
}
$rid = $_SESSION['restaurant_id'];

echo "=== STEP 5: query offers_v2 ===\n";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM offers_v2 WHERE restaurant_id=?");
    $stmt->execute([$rid]);
    echo "offers_v2 count for restaurant $rid: " . $stmt->fetchColumn() . "\n";
} catch (\Throwable $e) {
    echo "ERROR querying offers_v2: " . $e->getMessage() . "\n";
}

echo "\n=== STEP 6: query offer_items_v2 ===\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM offer_items_v2");
    echo "offer_items_v2 total: " . $stmt->fetchColumn() . "\n";
} catch (\Throwable $e) {
    echo "ERROR querying offer_items_v2: " . $e->getMessage() . "\n";
}

echo "\n=== STEP 7: query dishes_v2 with is_available ===\n";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dishes_v2 WHERE restaurant_id=? AND is_available=1");
    $stmt->execute([$rid]);
    echo "available dishes_v2: " . $stmt->fetchColumn() . "\n";
} catch (\Throwable $e) {
    echo "ERROR querying dishes_v2: " . $e->getMessage() . "\n";
}

echo "\n=== STEP 8: full offers query (same as offers.php) ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT o.*,
            GROUP_CONCAT(
                JSON_OBJECT('id',oi.id,'dish_id',oi.dish_id,'quantity',oi.quantity,'role',oi.role,'dish_name',d.name)
                ORDER BY oi.id
            ) as items_json
        FROM offers_v2 o
        LEFT JOIN offer_items_v2 oi ON oi.offer_id = o.id
        LEFT JOIN dishes_v2 d ON d.id = oi.dish_id
        WHERE o.restaurant_id=?
        GROUP BY o.id
        ORDER BY o.sort_order, o.id DESC
    ");
    $stmt->execute([$rid]);
    $rows = $stmt->fetchAll();
    echo "rows fetched: " . count($rows) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
}

echo "\n=== STEP 9: load sidebar.php ===\n";
try {
    ob_start();
    require_once __DIR__ . '/sidebar.php';
    ob_end_clean();
    echo "sidebar loaded ok\n";
} catch (\Throwable $e) {
    ob_end_clean();
    echo "ERROR loading sidebar: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== ALL CHECKS PASSED — لو وصلنا هون فالملفات والاستعلامات شغالة. المشكلة ممكن تكون بـ OPCache. ===\n";
echo "</pre>";
