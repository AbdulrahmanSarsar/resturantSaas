<?php
/**
 * MenuPro SaaS — Application Configuration
 * 
 * Environment detection: checks for .env file first,
 * falls back to constants for Hostinger compatibility.
 * 
 * SECURITY: Never commit credentials. Use .env in production
 * or define constants in a file outside web root.
 */

// ============================================================
// Environment & Error Handling
// ============================================================
$env = getenv('APP_ENV') ?: 'production';

if ($env === 'production') {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/error.log');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// ============================================================
// Database Configuration
// ============================================================
// Try .env first, fall back to constants for backward compatibility
$db_config = [
    'host'    => getenv('DB_HOST')    ?: 'localhost',
    'name'    => getenv('DB_NAME')    ?: 'u689381734_menu_database',
    'user'    => getenv('DB_USER')    ?: 'u689381734_menu_database',
    'pass'    => getenv('DB_PASS')    ?: '3Bood$@r$@r2006',  // NEVER hardcode in repo
    'charset' => 'utf8mb4',
];

// ============================================================
// Application Settings
// ============================================================
define('APP_ENV',      $env);
define('APP_NAME',     'MenuPro');
define('APP_VERSION',  '2.0.0');
define('BASE_URL',     getenv('BASE_URL') ?: 'https://menu.almanarsoft.com');
define('UPLOAD_DIR',   rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/assets/uploads/');

// Security
define('SESSION_LIFETIME', 7200);  // 2 hours
define('CSRF_TOKEN_NAME', '_csrf_token');

// ============================================================
// Timezone
// ============================================================
date_default_timezone_set('Asia/Damascus');

// ============================================================
// Database Connection (Singleton Pattern)
// ============================================================
class Database {
    private static ?PDO $instance = null;
    
    /**
     * Get the singleton PDO instance.
     * Uses persistent connections for Hostinger shared hosting.
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            global $db_config;
            
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $db_config['host'],
                $db_config['name'],
                $db_config['charset']
            );
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,  // Use real prepared statements
                PDO::ATTR_STRINGIFY_FETCHES  => false,   // Keep numeric types
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+03:00'",
            ];
            
            try {
                self::$instance = new PDO($dsn, $db_config['user'], $db_config['pass'], $options);
            } catch (PDOException $e) {
                if (APP_ENV === 'production') {
                    error_log('Database connection failed: ' . $e->getMessage());
                    http_response_code(503);
                    die('Service temporarily unavailable. Please try again later.');
                }
                throw $e;
            }
        }
        
        return self::$instance;
    }
    
    /** Prevent cloning */
    private function __clone() {}
    
    /** Prevent unserialization */
    public function __wakeup() {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}

/**
 * Backward compatibility: expose $pdo globally
 * so existing files keep working during migration.
 */
$pdo = Database::getInstance();