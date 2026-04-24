<?php
// Production error handling — log errors, don't echo to users.
// (localhost still shows them via php.ini settings)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (!defined('DB_HOST'))  define('DB_HOST', 'localhost');
if (!defined('DB_USER'))  define('DB_USER', 'u689381734_menu_database');
if (!defined('DB_PASS'))  define('DB_PASS', '3Bood$@r$@r2006');
if (!defined('DB_NAME'))  define('DB_NAME', 'u689381734_menu_database');
if (!defined('BASE_URL')) define('BASE_URL', 'https://menu.almanarsoft.com');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch(PDOException $e) {
    // Log full error, show generic message
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('تعذّر الاتصال بقاعدة البيانات حالياً. حاول بعد قليل.');
}
?>