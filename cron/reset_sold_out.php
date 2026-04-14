<?php
require_once '../config/database.php';
$pdo->exec("UPDATE dishes SET sold_out = 0");

// إشعار للمطاعم اللي عندها أطباق كانت نافذة
$stmt = $pdo->query("SELECT DISTINCT restaurant_id FROM dishes WHERE sold_out = 0");
// (الإشعار رح نضيفه لاحقاً مع باقي ميزات الإشعارات)

echo "Done: " . date('Y-m-d H:i:s');