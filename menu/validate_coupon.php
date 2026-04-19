<?php
require_once '../config/database.php';
header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }

$code          = strtoupper(trim($_POST['code'] ?? ''));
$restaurant_id = intval($_POST['restaurant_id'] ?? 0);
$branch_id     = intval($_POST['branch_id'] ?? 0);
$total         = floatval($_POST['total'] ?? 0);

if(!$code || !$restaurant_id) {
    echo json_encode(['success'=>false,'message'=>'بيانات غير صحيحة']);
    exit;
}

// تحقق إن branch_id column موجود (graceful fallback)
$has_branch_col = false;
try {
    $col_check = $pdo->query("SHOW COLUMNS FROM coupons LIKE 'branch_id'");
    $has_branch_col = $col_check && $col_check->rowCount() > 0;
} catch(Exception $e) {}

if ($has_branch_col && $branch_id) {
    // الكوبون صالح إذا: (عام) أو (تابع لهذا الفرع)
    $coupon = $pdo->prepare("
        SELECT * FROM coupons
        WHERE code = ? AND restaurant_id = ? AND is_active = 1
          AND (branch_id IS NULL OR branch_id = ?)
          AND (expires_at IS NULL OR expires_at >= CURDATE())
          AND (max_uses IS NULL OR used_count < max_uses)
    ");
    $coupon->execute([$code, $restaurant_id, $branch_id]);
} else {
    $coupon = $pdo->prepare("
        SELECT * FROM coupons
        WHERE code = ? AND restaurant_id = ? AND is_active = 1
          AND (expires_at IS NULL OR expires_at >= CURDATE())
          AND (max_uses IS NULL OR used_count < max_uses)
    ");
    $coupon->execute([$code, $restaurant_id]);
}
$coupon = $coupon->fetch();

if(!$coupon) {
    echo json_encode(['success'=>false,'message'=>'الكوبون غير صالح أو منتهي']);
    exit;
}

if($total < $coupon['min_order']) {
    echo json_encode([
        'success' => false,
        'message' => 'الحد الأدنى للطلب $'.number_format($coupon['min_order'],2)
    ]);
    exit;
}

// حساب الخصم (بيدعم 'percent' و 'percentage' للتوافق)
if(in_array($coupon['discount_type'], ['percent','percentage'])) {
    $discount = ($total * $coupon['discount_value']) / 100;
    $msg = 'خصم '.rtrim(rtrim(number_format($coupon['discount_value'],2),'0'),'.').'% = $'.number_format($discount,2);
} else {
    $discount = min($coupon['discount_value'], $total);
    $msg = 'خصم $'.number_format($discount,2);
}

echo json_encode([
    'success'     => true,
    'message'     => $msg,
    'discount'    => $discount,
    'coupon_id'   => $coupon['id'],
    'coupon_code' => $coupon['code'],
    'new_total'   => max(0, $total - $discount)
]);
