<?php
/**
 * MenuPro — Authentication & Authorization Middleware
 * 
 * Replaces scattered session_start() + $_SESSION checks
 * across every file with a centralized, secure auth system.
 * 
 * Usage:
 *   $auth = new AuthMiddleware($pdo);
 *   $auth->requireRole('kitchen', 'waiter');  // Allow these roles
 *   $user = $auth->user();                     // Get current user
 */

namespace MenuPro\Middleware;

use PDO;

class AuthMiddleware
{
    private PDO $db;
    private ?array $currentUser = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->initSession();
    }

    // ============================================================
    // Session Management
    // ============================================================

    /**
     * Initialize secure session with proper settings.
     */
    private function initSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 7200;

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);

        session_start();

        // Regenerate session ID periodically to prevent fixation
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - $_SESSION['_created'] > 1800) { // 30 min
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }

        // Session timeout
        if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity'] > $lifetime)) {
            $this->logout();
            return;
        }
        $_SESSION['_last_activity'] = time();

        // Load current user from session
        if (isset($_SESSION['user_id'])) {
            $this->loadUser($_SESSION['user_id']);
        }
    }

    /**
     * Load user from database and cache in memory.
     */
    private function loadUser(int $userId): void
    {
        $stmt = $this->db->prepare("
            SELECT u.*, 
                   b.name AS branch_name,
                   rv.name AS restaurant_name,
                   o.name AS organization_name
            FROM users u
            LEFT JOIN branches b ON b.id = u.branch_id
            LEFT JOIN restaurants rv ON rv.id = u.restaurant_id
            LEFT JOIN organizations o ON o.id = u.organization_id
            WHERE u.id = ? AND u.is_active = 1
        ");
        $stmt->execute([$userId]);
        $this->currentUser = $stmt->fetch() ?: null;

        // If user was deactivated after login, force logout
        if ($this->currentUser === null && isset($_SESSION['user_id'])) {
            $this->logout();
        }
    }

    // ============================================================
    // Authentication
    // ============================================================

    /**
     * Attempt login with email/username + password.
     * Handles both email (admins, owners) and username (staff) login.
     * 
     * @return array|null User record on success, null on failure
     */
    public function attempt(string $identifier, string $password): ?array
    {
        return $this->attemptWithRoles($identifier, $password);
    }

    /**
     * Attempt login restricted to specific roles.
     * Each login page calls this with its allowed roles.
     * 
     * @param string   $identifier  Email or username
     * @param string   $password    Plain text password
     * @param string[] $allowedRoles  If empty, all roles allowed
     * @return array|null User record on success, null on failure
     */
    public function attemptWithRoles(string $identifier, string $password, array $allowedRoles = []): ?array
    {
        // Build role filter
        $roleFilter = '';
        $params = [$identifier, $identifier];
        if (!empty($allowedRoles)) {
            $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));
            $roleFilter = "AND u.role IN ($placeholders)";
            $params = array_merge($params, $allowedRoles);
        }

        // JOIN to get restaurant/org names for legacy session
        $stmt = $this->db->prepare("
            SELECT u.*,
                   rv.name AS restaurant_name,
                   o.name AS organization_name
            FROM users u
            LEFT JOIN restaurants rv ON rv.id = u.restaurant_id
            LEFT JOIN organizations o ON o.id = u.organization_id
            WHERE (u.email = ? OR u.username = ?) AND u.is_active = 1 $roleFilter
            LIMIT 1
        ");
        $stmt->execute($params);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }

        // Successful login
        session_regenerate_id(true);
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['_created']      = time();
        $_SESSION['_last_activity'] = time();

        // Update last login timestamp
        $this->db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        // === BACKWARD COMPATIBILITY LAYER ===
        $this->setLegacySession($user);

        $this->currentUser = $user;
        return $user;
    }

    /**
     * Populate legacy session variables for backward compatibility.
     * Remove these once all pages are migrated.
     * 
     * IMPORTANT: These must match EXACTLY what the old login pages set,
     * because un-migrated pages check these variables.
     */
    private function setLegacySession(array $user): void
    {
        switch ($user['role']) {
            case 'super_admin':
                $_SESSION['admin_id']   = $user['id'];
                $_SESSION['admin_name'] = $user['name'];
                break;

            case 'chain_owner':
            case 'restaurant_manager':
            case 'branch_manager':
                // Old code: $_SESSION['restaurant_id'] = $restaurant['id']
                // Old code: $_SESSION['restaurant_name'] = $restaurant['name'] (the RESTAURANT name, not user name)
                // Old code: $_SESSION['restaurant_plan'] = $restaurant['subscription_plan']
                $restId = $user['branch_id'] ?? $user['restaurant_id'];
                $_SESSION['restaurant_id'] = $restId;
                
                // Get the restaurant/branch name (not the user's name)
                $restName = $user['restaurant_name'] ?? $user['organization_name'] ?? $user['name'];
                $_SESSION['restaurant_name'] = $restName;
                
                $_SESSION['restaurant_plan'] = $this->getActivePlan($user['organization_id']);
                break;

            case 'waiter':
            case 'kitchen':
            case 'cashier':
                // Old code set these in restaurant/staff/login.php:
                // $_SESSION['staff_id'] = $staff['id']
                // $_SESSION['staff_role'] = $staff['role']
                // $_SESSION['staff_name'] = $staff['name']
                // $_SESSION['staff_number'] = $staff['staff_number']
                // $_SESSION['staff_rest_id'] = $staff['restaurant_id']
                // $_SESSION['staff_rest_name'] = $staff['rest_name']
                $_SESSION['staff_id']        = $user['id'];
                $_SESSION['staff_role']      = $user['role'];
                $_SESSION['staff_name']      = $user['name'];
                $_SESSION['staff_number']    = $user['staff_number'] ?? $user['id'];
                $_SESSION['staff_rest_id']   = $user['branch_id'] ?? $user['restaurant_id'];
                $_SESSION['staff_rest_name'] = $user['restaurant_name'] ?? $user['organization_name'] ?? '';
                break;
        }
    }

    /**
     * Get the active subscription plan for an organization.
     *
     * بعد إهمال subscriptions_v2 — نقرأ من restaurants.subscription_plan مباشرة.
     * (restaurant-level plans، organization-wide lookup يبقى أول مطعم نشط)
     */
    private function getActivePlan(?int $orgId): string
    {
        if (!$orgId) return 'basic';

        $stmt = $this->db->prepare("
            SELECT subscription_plan AS plan
            FROM restaurants
            WHERE organization_id = ?
              AND (subscription_expiry IS NULL OR subscription_expiry >= CURDATE())
            ORDER BY subscription_expiry DESC LIMIT 1
        ");
        $stmt->execute([$orgId]);
        $sub = $stmt->fetch();

        return ($sub && $sub['plan']) ? $sub['plan'] : 'basic';
    }

    /**
     * Destroy session and log out.
     */
    public function logout(): void
    {
        $this->currentUser = null;
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    // ============================================================
    // Authorization
    // ============================================================

    /**
     * Check if the user is authenticated.
     */
    public function check(): bool
    {
        return $this->currentUser !== null;
    }

    /**
     * Get current authenticated user.
     */
    public function user(): ?array
    {
        return $this->currentUser;
    }

    /**
     * Get current user's ID.
     */
    public function id(): ?int
    {
        return $this->currentUser['id'] ?? null;
    }

    /**
     * Check if user has one of the given roles.
     */
    public function hasRole(string ...$roles): bool
    {
        if (!$this->currentUser) return false;
        return in_array($this->currentUser['role'], $roles, true);
    }

    /**
     * Require authentication. Redirects to login if not authenticated.
     */
    public function requireAuth(string $redirectTo = '/login.php'): void
    {
        if (!$this->check()) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    /**
     * Require specific role(s). Returns 403 if unauthorized.
     */
    public function requireRole(string ...$roles): void
    {
        $this->requireAuth();

        if (!$this->hasRole(...$roles)) {
            http_response_code(403);
            die('Access denied: insufficient permissions.');
        }
    }

    /**
     * Check if the current user can access data for a given branch.
     * Enforces tenant isolation.
     */
    public function canAccessBranch(int $branchId): bool
    {
        if (!$this->currentUser) return false;

        $role = $this->currentUser['role'];

        // Super admin can access everything
        if ($role === 'super_admin') return true;

        // Chain owner can access any branch in their organization
        if ($role === 'chain_owner') {
            $stmt = $this->db->prepare("
                SELECT 1 FROM branches b
                JOIN restaurants r ON r.id = b.restaurant_id
                WHERE b.id = ? AND r.organization_id = ?
            ");
            $stmt->execute([$branchId, $this->currentUser['organization_id']]);
            return (bool) $stmt->fetch();
        }

        // Restaurant manager can access any branch of their restaurant
        if ($role === 'restaurant_manager') {
            $stmt = $this->db->prepare("
                SELECT 1 FROM branches 
                WHERE id = ? AND restaurant_id = ?
            ");
            $stmt->execute([$branchId, $this->currentUser['restaurant_id']]);
            return (bool) $stmt->fetch();
        }

        // Branch-level users can only access their own branch
        return $this->currentUser['branch_id'] === $branchId;
    }

    /**
     * Get the branch ID(s) the current user can access.
     * Used for scoping queries.
     */
    public function accessibleBranchIds(): array
    {
        if (!$this->currentUser) return [];

        $role = $this->currentUser['role'];

        if ($role === 'super_admin') {
            // Return all branch IDs (admin panel context)
            return $this->db->query("SELECT id FROM branches")->fetchAll(\PDO::FETCH_COLUMN);
        }

        if ($role === 'chain_owner') {
            $stmt = $this->db->prepare("
                SELECT b.id FROM branches b
                JOIN restaurants r ON r.id = b.restaurant_id
                WHERE r.organization_id = ?
            ");
            $stmt->execute([$this->currentUser['organization_id']]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        if ($role === 'restaurant_manager') {
            $stmt = $this->db->prepare("
                SELECT id FROM branches WHERE restaurant_id = ?
            ");
            $stmt->execute([$this->currentUser['restaurant_id']]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        // Branch-scoped users
        return $this->currentUser['branch_id'] ? [$this->currentUser['branch_id']] : [];
    }

    // ============================================================
    // CSRF Protection
    // ============================================================

    /**
     * Generate a CSRF token for forms.
     */
    public function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    /**
     * Output a hidden CSRF input field for forms.
     */
    public function csrfField(): string
    {
        $token = $this->csrfToken();
        $name  = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : '_csrf_token';
        return '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Validate the CSRF token from POST data.
     */
    public function validateCsrf(): bool
    {
        $name  = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : '_csrf_token';
        $token = $_POST[$name] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        
        return isset($_SESSION['_csrf_token']) 
            && hash_equals($_SESSION['_csrf_token'], $token);
    }

    /**
     * Require valid CSRF token for POST requests.
     */
    public function requireCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$this->validateCsrf()) {
            http_response_code(403);
            die('Invalid or expired security token. Please refresh and try again.');
        }
    }
}