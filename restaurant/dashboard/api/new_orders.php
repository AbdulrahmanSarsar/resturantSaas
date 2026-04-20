<?php
/**
 * restaurant/dashboard/api/new_orders.php
 * Polling endpoint للطلبات الجديدة + الجاهزة + المعلقة.
 *
 * يدعم:
 *   - صاحب المطعم (restaurant_id) — يفلتر بـ active_branch_id من الـ session
 *   - الموظف (staff_rest_id)      — يفلتر بـ staff_branch_id من الـ session
 *
 * إذا الفرع غير محدد، يرجع كل طلبات المطعم (legacy behavior).
 */
session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// تحديد المطعم + الفرع
$rid       = null;
$branch_id = null;

if (isset($_SESSION['restaurant_id'])) {
    $rid       = $_SESSION['restaurant_id'];
    $branch_id = $_SESSION['active_branch_id'] ?? null;
} elseif (isset($_SESSION['staff_rest_id'])) {
    $rid       = $_SESSION['staff_rest_id'];
    $branch_id = $_SESSION['staff_branch_id'] ?? null;
} else {
    echo json_encode(['new_orders' => [], 'pending_count' => 0, 'new_ready' => []]);
    exit;
}

$last_id = intval($_GET['last_id'] ?? 0);

// Branch filter helper
$bf = $branch_id ? 'AND branch_id = ?' : '';
$bp = $branch_id ? [$branch_id]        : [];

// طلبات جديدة بعد last_id — أي حالة
$stmt = $pdo->prepare("
    SELECT id, table_number, status, created_at
    FROM orders
    WHERE restaurant_id = ? $bf AND id > ?
    ORDER BY id DESC
    LIMIT 10
");
$stmt->execute(array_merge([$rid], $bp, [$last_id]));
$new_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// عدد المعلقة
$pending = $pdo->prepare("
    SELECT COUNT(*) FROM orders
    WHERE restaurant_id = ? $bf AND status = 'pending'
");
$pending->execute(array_merge([$rid], $bp));
$pending_count = intval($pending->fetchColumn());

// طلبات جاهزة جديدة (للنادل)
$ready = $pdo->prepare("
    SELECT id, table_number FROM orders
    WHERE restaurant_id = ? $bf AND status = 'ready' AND id > ?
    ORDER BY id DESC LIMIT 5
");
$ready->execute(array_merge([$rid], $bp, [$last_id]));
$new_ready = $ready->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'new_orders'       => $new_orders,
    'new_ready'        => $new_ready,
    'pending_count'    => $pending_count,
    'last_id_received' => $last_id, // للـ debugging
    'branch_filter'    => $branch_id, // للـ debugging
]);
