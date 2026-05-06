<?php
/**
 * secrets.example.php — قالب ملف الأسرار
 *
 * 🔴 هالملف قالب فقط — ما فيه بيانات حقيقية.
 *    انسخه إلى config/secrets.php وحطّ بياناتك الفعلية.
 *    config/secrets.php محمي بـ .gitignore — لا يُرفع إلى GitHub أبداً.
 *
 * كيفية الاستخدام:
 *   1. على السيرفر (Hostinger): ارفع secrets.php عبر File Manager
 *   2. محلياً (XAMPP): استخدم config/database.local.php بدلاً منه
 */

// حماية من الوصول المباشر عبر HTTP
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit;
}

// قاعدة البيانات
define('DB_HOST',  'localhost');
define('DB_USER',  'u000000000_your_db_user');   // اسم مستخدم قاعدة البيانات
define('DB_PASS',  'YourStrongPassword123!');     // كلمة المرور
define('DB_NAME',  'u000000000_your_db_name');   // اسم قاعدة البيانات

// الرابط الأساسي للموقع (بدون trailing slash)
define('BASE_URL', 'https://menu-pro.org');
