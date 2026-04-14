<?php
/**
 * MenuPro — Tenant Middleware
 * 
 * Enforces multi-tenancy at the application layer.
 * Every database query that touches tenant data MUST go through
 * this middleware's scoped methods.
 * 
 * This is the MOST CRITICAL security boundary in the system.
 * A missed tenant scope = data leak between restaurants.
 * 
 * Usage:
 *   $tenant = new TenantMiddleware($pdo, $auth);
 *   $orders = $tenant->scopedQuery(
 *       "SELECT * FROM orders_v2 WHERE {BRANCH_SCOPE} AND status = ?",
 *       ['delivered']
 *   );
 */

namespace MenuPro\Middleware;

use PDO;

class TenantMiddleware
{
    private PDO $db;
    private AuthMiddleware $auth;
    
    // Current scope context
    private ?int $organizationId = null;
    private ?int $restaurantId   = null;
    private ?int $branchId       = null;

    public function __construct(PDO $db, AuthMiddleware $auth)
    {
        $this->db   = $db;
        $this->auth = $auth;
        $this->resolveScope();
    }

    /**
     * Determine the current tenant scope from the authenticated user.
     */
    private function resolveScope(): void
    {
        $user = $this->auth->user();
        if (!$user) return;

        $this->organizationId = $user['organization_id'] ?? null;
        $this->restaurantId   = $user['restaurant_id'] ?? null;
        $this->branchId       = $user['branch_id'] ?? null;
    }

    // ============================================================
    // Scope Getters
    // ============================================================

    public function organizationId(): ?int { return $this->organizationId; }
    public function restaurantId(): ?int   { return $this->restaurantId; }
    public function branchId(): ?int       { return $this->branchId; }

    /**
     * Override the current scope (useful for admin panel context).
     */
    public function setScope(?int $orgId, ?int $restId = null, ?int $branchId = null): void
    {
        // Only super_admin and chain_owner can override scope
        if (!$this->auth->hasRole('super_admin', 'chain_owner', 'restaurant_manager')) {
            throw new \RuntimeException('Insufficient permissions to change tenant scope');
        }

        // Verify the requested scope is within the user's access
        if ($branchId && !$this->auth->canAccessBranch($branchId)) {
            throw new \RuntimeException('Access denied to branch: ' . $branchId);
        }

        $this->organizationId = $orgId;
        $this->restaurantId   = $restId;
        $this->branchId       = $branchId;
    }

    // ============================================================
    // Scoped Queries
    // ============================================================

    /**
     * Execute a query with automatic tenant scoping.
     * 
     * Use placeholders in your SQL:
     *   {BRANCH_SCOPE}  → "branch_id = ?"
     *   {REST_SCOPE}    → "restaurant_id = ?"
     *   {ORG_SCOPE}     → "organization_id = ?"
     *   {BRANCH_IDS}    → "branch_id IN (?,?,?)"
     * 
     * The correct scope is determined by the user's role:
     *   - Branch staff: single branch_id
     *   - Restaurant manager: all branches of their restaurant
     *   - Chain owner: all branches of their organization
     *   - Super admin: no restriction (removes scope clause)
     *
     * @param string $sql    SQL with {SCOPE} placeholders
     * @param array  $params Additional bound parameters
     * @return array Fetched rows
     */
    public function scopedQuery(string $sql, array $params = []): array
    {
        [$processedSql, $scopeParams] = $this->processScope($sql);
        
        $allParams = array_merge($scopeParams, $params);
        $stmt = $this->db->prepare($processedSql);
        $stmt->execute($allParams);
        
        return $stmt->fetchAll();
    }

    /**
     * Execute a scoped query and return a single row.
     */
    public function scopedQueryOne(string $sql, array $params = []): ?array
    {
        [$processedSql, $scopeParams] = $this->processScope($sql);
        
        $allParams = array_merge($scopeParams, $params);
        $stmt = $this->db->prepare($processedSql);
        $stmt->execute($allParams);
        
        return $stmt->fetch() ?: null;
    }

    /**
     * Execute a scoped write (INSERT/UPDATE/DELETE).
     */
    public function scopedExecute(string $sql, array $params = []): int
    {
        [$processedSql, $scopeParams] = $this->processScope($sql);
        
        $allParams = array_merge($scopeParams, $params);
        $stmt = $this->db->prepare($processedSql);
        $stmt->execute($allParams);
        
        return $stmt->rowCount();
    }

    /**
     * Process scope placeholders and generate bound params.
     */
    private function processScope(string $sql): array
    {
        $params = [];
        $role   = $this->auth->user()['role'] ?? null;

        // Super admin: remove scope restrictions
        if ($role === 'super_admin') {
            $sql = str_replace('{BRANCH_SCOPE}', '1=1', $sql);
            $sql = str_replace('{REST_SCOPE}', '1=1', $sql);
            $sql = str_replace('{ORG_SCOPE}', '1=1', $sql);
            $sql = str_replace('{BRANCH_IDS}', '1=1', $sql);
            return [$sql, $params];
        }

        // Branch-level scope
        if (str_contains($sql, '{BRANCH_SCOPE}')) {
            if ($this->branchId) {
                $sql = str_replace('{BRANCH_SCOPE}', 'branch_id = ?', $sql);
                $params[] = $this->branchId;
            } else {
                // Fall back to all accessible branches
                $branchIds = $this->auth->accessibleBranchIds();
                if (empty($branchIds)) {
                    $sql = str_replace('{BRANCH_SCOPE}', '1=0', $sql); // No access
                } else {
                    $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
                    $sql = str_replace('{BRANCH_SCOPE}', "branch_id IN ($placeholders)", $sql);
                    $params = array_merge($params, $branchIds);
                }
            }
        }

        // Branch IDs scope (for aggregate queries)
        if (str_contains($sql, '{BRANCH_IDS}')) {
            $branchIds = $this->auth->accessibleBranchIds();
            if (empty($branchIds)) {
                $sql = str_replace('{BRANCH_IDS}', '1=0', $sql);
            } else {
                $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
                $sql = str_replace('{BRANCH_IDS}', "branch_id IN ($placeholders)", $sql);
                $params = array_merge($params, $branchIds);
            }
        }

        // Restaurant-level scope
        if (str_contains($sql, '{REST_SCOPE}')) {
            if ($this->restaurantId) {
                $sql = str_replace('{REST_SCOPE}', 'restaurant_id = ?', $sql);
                $params[] = $this->restaurantId;
            } else {
                $sql = str_replace('{REST_SCOPE}', '1=0', $sql);
            }
        }

        // Organization-level scope
        if (str_contains($sql, '{ORG_SCOPE}')) {
            if ($this->organizationId) {
                $sql = str_replace('{ORG_SCOPE}', 'organization_id = ?', $sql);
                $params[] = $this->organizationId;
            } else {
                $sql = str_replace('{ORG_SCOPE}', '1=0', $sql);
            }
        }

        return [$sql, $params];
    }

    // ============================================================
    // Convenience: Verify ownership before mutations
    // ============================================================

    /**
     * Verify that an entity belongs to the current tenant.
     * Use before UPDATE/DELETE operations.
     */
    public function verifyOwnership(string $table, int $entityId, string $scopeColumn = 'branch_id'): bool
    {
        $role = $this->auth->user()['role'] ?? null;
        if ($role === 'super_admin') return true;

        $scopeValue = match ($scopeColumn) {
            'branch_id'       => $this->branchId,
            'restaurant_id'   => $this->restaurantId,
            'organization_id' => $this->organizationId,
            default           => null,
        };

        if (!$scopeValue) return false;

        $stmt = $this->db->prepare("SELECT 1 FROM `$table` WHERE id = ? AND `$scopeColumn` = ? LIMIT 1");
        $stmt->execute([$entityId, $scopeValue]);
        
        return (bool) $stmt->fetch();
    }
}