<?php
/**
 * restaurant/dashboard/api/new_orders.php
 */
session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if(isset($_SESSION['restaurant_id'])) {
    $rid = $_SESSION['restaurant_id'];
} elseif(isset($_SESSION['staff_rest_id'])) {
    $rid = $_SESSION['staff_rest_id'];
} else {
    echo json_encode(['new_orders'=>[], 'pending_count'=>0, 'new_ready'=>[]]);
    exit;
}

$last_id = intval($_GET['last_id'] ?? 0);

// طلبات جديدة بعد last_id — أي حالة
$stmt = $pdo->prepare("
    SELECT id, table_number, status, created_at
    FROM orders
    WHERE restaurant_id = ? AND id > ?
    ORDER BY id DESC
    LIMIT 10
");
$stmt->execute([$rid, $last_id]);
$new_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// عدد المعلقة
$pending = $pdo->prepare("
    SELECT COUNT(*) FROM orders
    WHERE restaurant_id=? AND status='pending'
");
$pending->execute([$rid]);
$pending_count = intval($pending->fetchColumn());

// طلبات جاهزة جديدة (للنادل)
$ready = $pdo->prepare("
    SELECT id, table_number FROM orders
    WHERE restaurant_id=? AND status='ready' AND id > ?
    ORDER BY id DESC LIMIT 5
");
$ready->execute([$rid, $last_id]);
$new_ready = $ready->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'new_orders'    => $new_orders,
    'new_ready'     => $new_ready,
    'pending_count' => $pending_count,
    'last_id_received' => $last_id, // للـ debugging
]);