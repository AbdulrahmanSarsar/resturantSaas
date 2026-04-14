<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('DB_HOST', 'localhost');
define('DB_USER', 'u689381734_menu_database'); 
define('DB_PASS', '3Bood$@r$@r2006');
define('DB_NAME', 'u689381734_menu_database');
define('BASE_URL', 'https://menu.almanarsoft.com');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch(PDOException $e) {
    die("خطأ في الاتصال: " . $e->getMessage());
}
?>