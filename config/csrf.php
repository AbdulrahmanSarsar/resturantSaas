<?php
/**
 * MenuPro — CSRF Helpers (lightweight, no dependencies)
 *
 * Usage:
 *   require_once __DIR__ . '/../config/csrf.php';   // once per page
 *
 *   // In HTML forms:
 *   <form method="POST">
 *     <?= csrf_field() ?>
 *     ...
 *   </form>
 *
 *   // In AJAX calls:
 *   headers: { 'X-CSRF-Token': '<?= csrf_token() ?>' }
 *
 *   // In POST handlers:
 *   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 *       csrf_require();   // kills the request on failure
 *       // ... rest of handler
 *   }
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="'
             . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')
             . '">';
    }
}

if (!function_exists('csrf_meta')) {
    function csrf_meta(): string
    {
        return '<meta name="csrf-token" content="'
             . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')
             . '">';
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * Returns true if the submitted token matches the session token.
     * Accepts POST field "_csrf_token" or header "X-CSRF-Token".
     */
    function csrf_verify(): bool
    {
        $submitted = $_POST['_csrf_token']
                  ?? $_SERVER['HTTP_X_CSRF_TOKEN']
                  ?? '';
        if ($submitted === '' || empty($_SESSION['_csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf_token'], (string)$submitted);
    }
}

if (!function_exists('csrf_require')) {
    /**
     * Aborts the request (403) if the CSRF token is missing/invalid.
     * Returns JSON error for AJAX; plain text otherwise.
     */
    function csrf_require(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (csrf_verify()) return;

        http_response_code(403);
        $isAjax = !empty($_POST['ajax'])
               || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                   && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'csrf_invalid',
                'message' => 'انتهت صلاحية جلستك. يرجى تحديث الصفحة.',
            ]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'CSRF: انتهت صلاحية جلستك. يرجى تحديث الصفحة.';
        }
        exit;
    }
}
