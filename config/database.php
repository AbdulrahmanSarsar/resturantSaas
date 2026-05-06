<?php
// ===== Auto-detect environment (local dev vs production) =====
// Local: HTTP_HOST = localhost / 127.0.0.1 → استخدم XAMPP defaults + override من .env.local
// Production: HTTP_HOST = menu-pro.org → استخدم Hostinger
$__is_local = (
    PHP_SAPI === 'cli-server' ||
    in_array(($_SERVER['HTTP_HOST'] ?? ''), ['localhost', '127.0.0.1'], true) ||
    str_starts_with(($_SERVER['HTTP_HOST'] ?? ''), 'localhost:') ||
    str_starts_with(($_SERVER['HTTP_HOST'] ?? ''), '127.0.0.1:')
);

// محلي: اعرض الأخطاء بالشاشة. إنتاج: log فقط
if ($__is_local) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}
error_reporting(E_ALL);

// ============================================================
// الأولوية: secrets.php (على السيرفر، ما في git) ← env vars ← local override
// ============================================================

// 1. ملف الأسرار — موجود على السيرفر فقط، محمي بـ .gitignore
$__secrets = __DIR__ . '/secrets.php';
if (is_file($__secrets)) {
    require $__secrets;
}
unset($__secrets);

// 2. Override محلي للمطوّر (XAMPP etc.) — يطغى على secrets.php إذا موجود
$__local_config = __DIR__ . '/database.local.php';
if ($__is_local && is_file($__local_config)) {
    require $__local_config;
}
unset($__local_config, $__is_local);

// 3. Environment variables — يطغى على كل شي (Hostinger hPanel أو Docker)
if (getenv('MENUPRO_DB_HOST') !== false) define('DB_HOST',  getenv('MENUPRO_DB_HOST'));
if (getenv('MENUPRO_DB_USER') !== false) define('DB_USER',  getenv('MENUPRO_DB_USER'));
if (getenv('MENUPRO_DB_PASS') !== false) define('DB_PASS',  getenv('MENUPRO_DB_PASS'));
if (getenv('MENUPRO_DB_NAME') !== false) define('DB_NAME',  getenv('MENUPRO_DB_NAME'));
if (getenv('MENUPRO_BASE_URL')!== false) define('BASE_URL', getenv('MENUPRO_BASE_URL'));

// 4. Fallback — فارغة، لو ما في secrets.php ولا env vars → فشل واضح بدل كلمة سر مكشوفة
if (!defined('DB_HOST'))  define('DB_HOST',  'localhost');
if (!defined('DB_USER'))  define('DB_USER',  '');
if (!defined('DB_PASS'))  define('DB_PASS',  '');
if (!defined('DB_NAME'))  define('DB_NAME',  '');
if (!defined('BASE_URL')) define('BASE_URL', 'https://menu-pro.org');

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

// ===== CSRF helpers: auto-load =====
// يضمن توفر csrf_field/csrf_token/csrf_require/csrf_meta لكل صفحة
// بتحمّل database.php، بدون ما كل صفحة تعمل require يدوي.
// file_exists() للحماية لو الملف ما اترفع للإنتاج بعد.
$__csrf_path = __DIR__ . '/csrf.php';
if (is_file($__csrf_path)) {
    require_once $__csrf_path;
}
unset($__csrf_path);

// ===== Fallback stubs =====
// لو csrf.php مش موجود (مثلاً deploy ناقص)، نعرّف stubs بدل ما الصفحة تموت
// بـ "Call to undefined function csrf_field()". الـ stubs مش آمنة
// لكنها تمنع الـ 500 والصفحة تشتغل لحد ما يتم رفع الملف.
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="_csrf_token" value="'
             . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
if (!function_exists('csrf_meta')) {
    function csrf_meta(): string {
        return '<meta name="csrf-token" content="'
             . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
if (!function_exists('csrf_verify')) {
    function csrf_verify(): bool {
        $submitted = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($submitted === '' || empty($_SESSION['_csrf_token'])) return false;
        return hash_equals($_SESSION['_csrf_token'], (string)$submitted);
    }
}
if (!function_exists('csrf_require')) {
    function csrf_require(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (csrf_verify()) return;
        http_response_code(403);
        $isAjax = !empty($_POST['ajax'])
              || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                  && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>false,'error'=>'csrf_invalid','message'=>'انتهت صلاحية جلستك.']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'CSRF: انتهت صلاحية جلستك.';
        }
        exit;
    }
}
?>