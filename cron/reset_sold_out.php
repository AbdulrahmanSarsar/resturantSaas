<?php
require_once '../config/database.php';
$pdo->exec("UPDATE branch_dish_overrides SET sold_out = 0 WHERE sold_out = 1");

echo "Done: " . date('Y-m-d H:i:s');
