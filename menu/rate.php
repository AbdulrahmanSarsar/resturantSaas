<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$dish_id       = intval($_POST['dish_id']);
$restaurant_id = intval($_POST['restaurant_id']);
$rating        = intval($_POST['rating']);
$comment       = trim($_POST['comment'] ?? '');
$table         = trim($_POST['table'] ?? '');

if(!$dish_id || !$restaurant_id || $rating < 1 || $rating > 5) {
    echo json_encode(['error' => 'invalid data']);
    exit;
}

// تحقق إن الطبق فعلاً تابع لهاد المطعم
$check = $pdo->prepare("SELECT id FROM dishes WHERE id = ? AND restaurant_id = ?");
$check->execute([$dish_id, $restaurant_id]);
if(!$check->fetch()) {
    echo json_encode(['error' => 'not found']);
    exit;
}

$pdo->prepare("INSERT INTO dish_ratings (dish_id, restaurant_id, rating, comment, table_number) VALUES (?,?,?,?,?)")
    ->execute([$dish_id, $restaurant_id, $rating, $comment, $table]);

echo json_encode(['success' => true]);
?>