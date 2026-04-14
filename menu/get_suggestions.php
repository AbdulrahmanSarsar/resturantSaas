<?php
// menu/get_suggestions.php
// API مستقلة للاقتراحات الذكية
require_once '../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$rid      = intval($_POST['restaurant_id'] ?? 0);
$cart_ids = json_decode($_POST['cart_ids'] ?? '[]', true);
$cart_ids = array_map('intval', is_array($cart_ids) ? $cart_ids : []);

if(!$rid) { echo json_encode(['suggestions'=>[]]); exit; }

$suggestions = [];

// 1. اقتراحات يدوية من dish_suggestions
if(!empty($cart_ids)) {
    $in     = implode(',', array_fill(0, count($cart_ids), '?'));
    $stmt   = $pdo->prepare("
        SELECT DISTINCT ds.suggested_dish_id, d.name, d.name_en, d.price, d.image, d.description, d.description_en
        FROM dish_suggestions ds
        JOIN dishes d ON d.id = ds.suggested_dish_id
        WHERE ds.dish_id IN ($in)
          AND ds.restaurant_id = ?
          AND d.is_available = 1
          AND ds.suggested_dish_id NOT IN ($in)
        ORDER BY ds.sort_order
        LIMIT 2
    ");
    $stmt->execute(array_merge($cart_ids, [$rid], $cart_ids));
    $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 2. Fallback: أكثر الأطباق طلباً اللي مو في السلة
if(empty($suggestions)) {
    $exclude = !empty($cart_ids) ? $cart_ids : [0];
    $in      = implode(',', array_fill(0, count($exclude), '?'));
    $stmt2   = $pdo->prepare("
        SELECT d.id as suggested_dish_id, d.name, d.price, d.image, d.description,
               COUNT(oi.id) as order_count
        FROM dishes d
        LEFT JOIN order_items oi ON oi.dish_id = d.id
        WHERE d.restaurant_id = ?
          AND d.is_available = 1
          AND d.id NOT IN ($in)
        GROUP BY d.id
        ORDER BY order_count DESC, d.sort_order ASC
        LIMIT 2
    ");
    $stmt2->execute(array_merge([$rid], $exclude));
    $suggestions = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode(['suggestions' => $suggestions]);