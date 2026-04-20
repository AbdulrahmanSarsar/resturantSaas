<?php
/**
 * MenuPro — Menu Service
 *
 * Centralizes ALL customer-facing menu queries that were scattered
 * across menu/index.php and menu/dish.php.
 *
 * This is the single source of truth for reading menu data
 * (categories, dishes with branch overrides, options, offers).
 *
 * Used by:
 *   - menu/index.php  (full menu page)
 *   - menu/dish.php   (single dish page)
 *
 * All queries use the new _v2 tables and apply branch_dish_overrides
 * for branch-specific pricing/availability/sold_out state.
 */

namespace MenuPro\Services;

use PDO;

class MenuService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ============================================================
    // Restaurant lookup
    // ============================================================

    /**
     * Get restaurant by slug.
     * Returns null if not found or inactive.
     */
    public function getRestaurantBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM restaurants 
            WHERE slug = ? AND is_active = 1
        ");
        $stmt->execute([$slug]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    // ============================================================
    // Categories
    // ============================================================

    /**
     * Get active categories for a restaurant.
     */
    public function getCategories(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM categories_v2
            WHERE restaurant_id = ? AND is_active = 1
            ORDER BY sort_order
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll();
    }

    /**
     * Get a single category by ID.
     */
    public function getCategoryById(int $categoryId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, name_en 
            FROM categories_v2 
            WHERE id = ?
        ");
        $stmt->execute([$categoryId]);
        $c = $stmt->fetch();
        return $c ?: null;
    }

    // ============================================================
    // Dishes (with branch overrides applied)
    // ============================================================

    /**
     * Get all available dishes for a restaurant, with branch overrides applied.
     *
     * Branch overrides are merged in PHP:
     *   - price            ← branch_dish_overrides.price (if set)
     *   - discount_percent ← branch_dish_overrides.discount_percent (else 0)
     *   - discount_active  ← branch_dish_overrides.discount_active  (else 0)
     *   - sold_out         ← branch_dish_overrides.sold_out         (else 0)
     *
     * Dishes hidden at the branch level (bdo.is_available = 0) are excluded.
     */
    public function getDishesForBranch(int $restaurantId, int $branchId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                d.*,
                bdo.price            AS branch_price,
                bdo.discount_percent AS branch_discount_percent,
                bdo.discount_active  AS branch_discount_active,
                bdo.is_available     AS branch_is_available,
                bdo.sold_out         AS branch_sold_out
            FROM dishes_v2 d
            LEFT JOIN branch_dish_overrides bdo
                ON bdo.dish_id = d.id AND bdo.branch_id = ?
            WHERE d.restaurant_id = ?
              AND d.is_active = 1
              AND COALESCE(bdo.is_available, 1) = 1
            ORDER BY d.category_id, d.sort_order
        ");
        $stmt->execute([$branchId, $restaurantId]);
        $dishes = $stmt->fetchAll();

        return $this->applyBranchOverrides($dishes);
    }

    /**
     * Get a single dish by ID with branch overrides applied.
     * Returns null if not found or unavailable.
     */
    public function getDishForBranch(int $dishId, int $restaurantId, int $branchId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                d.*,
                bdo.price            AS branch_price,
                bdo.discount_percent AS branch_discount_percent,
                bdo.discount_active  AS branch_discount_active,
                bdo.is_available     AS branch_is_available,
                bdo.sold_out         AS branch_sold_out
            FROM dishes_v2 d
            LEFT JOIN branch_dish_overrides bdo
                ON bdo.dish_id = d.id AND bdo.branch_id = ?
            WHERE d.id = ?
              AND d.restaurant_id = ?
              AND d.is_active = 1
              AND COALESCE(bdo.is_available, 1) = 1
        ");
        $stmt->execute([$branchId, $dishId, $restaurantId]);
        $dish = $stmt->fetch();

        if (!$dish) return null;

        $merged = $this->applyBranchOverrides([$dish]);
        return $merged[0];
    }

    /**
     * Apply branch_dish_overrides on top of dishes_v2 rows.
     * dishes_v2 does NOT contain discount/sold_out columns —
     * those live exclusively in branch_dish_overrides.
     */
    private function applyBranchOverrides(array $dishes): array
    {
        foreach ($dishes as &$d) {
            // Synthesize fields that don't exist in dishes_v2
            $d['discount_percent'] = $d['branch_discount_percent'] !== null
                ? floatval($d['branch_discount_percent']) : 0.0;
            $d['discount_active']  = $d['branch_discount_active']  !== null
                ? intval($d['branch_discount_active'])  : 0;
            $d['sold_out']         = $d['branch_sold_out']         !== null
                ? intval($d['branch_sold_out'])         : 0;

            // Override price if branch defines its own
            if ($d['branch_price'] !== null) {
                $d['price'] = $d['branch_price'];
            }
        }
        unset($d);
        return $dishes;
    }

    // ============================================================
    // Dish Options
    // ============================================================

    /**
     * Get option groups for ALL dishes of a restaurant, indexed by dish_id.
     * Used by menu/index.php to attach options to dish cards.
     *
     * dish_option_groups_v2 schema:
     *   id, dish_id, name, name_en, type (radio/checkbox),
     *   price_mode (add/replace), is_required, sort_order
     */
    public function getOptionsForRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                og.id, og.dish_id, og.name, og.name_en, 
                og.type, og.price_mode, og.is_required, og.sort_order,
                GROUP_CONCAT(
                    JSON_OBJECT(
                        'id',      ov.id,
                        'name',    ov.name,
                        'name_en', COALESCE(ov.name_en, ''),
                        'price',   ov.price
                    )
                    ORDER BY ov.sort_order
                ) AS values_json
            FROM dish_option_groups_v2 og
            INNER JOIN dishes_v2 d ON d.id = og.dish_id
            LEFT JOIN dish_option_values_v2 ov ON ov.group_id = og.id
            WHERE d.restaurant_id = ?
            GROUP BY og.id
            ORDER BY og.sort_order
        ");
        $stmt->execute([$restaurantId]);

        $result = [];
        foreach ($stmt->fetchAll() as $og) {
            $og['values'] = $og['values_json']
                ? (json_decode('[' . $og['values_json'] . ']', true) ?: [])
                : [];
            unset($og['values_json']);
            $result[$og['dish_id']][] = $og;
        }
        return $result;
    }

    /**
     * Get option groups for a single dish.
     * Used by menu/dish.php.
     */
    public function getOptionsForDish(int $dishId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM dish_option_groups_v2
            WHERE dish_id = ?
            ORDER BY sort_order
        ");
        $stmt->execute([$dishId]);
        $groups = $stmt->fetchAll();

        if (empty($groups)) return [];

        // Fetch values for each group
        $valStmt = $this->db->prepare("
            SELECT * FROM dish_option_values_v2
            WHERE group_id = ?
            ORDER BY sort_order
        ");
        foreach ($groups as &$og) {
            $valStmt->execute([$og['id']]);
            $og['values'] = $valStmt->fetchAll();
        }
        unset($og);

        return $groups;
    }

    // ============================================================
    // Offers
    // ============================================================

    /**
     * Get active offers for a restaurant, with all items joined.
     * Each offer has an 'items' array containing dish details + role + qty.
     *
     * offers_v2 schema:
     *   id, restaurant_id, name, name_en, description, description_en,
     *   type (bxgy/combo), combo_price, image, is_active, sort_order
     */
    public function getActiveOffers(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                o.*,
                GROUP_CONCAT(
                    JSON_OBJECT(
                        'dish_id',      oi.dish_id,
                        'quantity',     oi.quantity,
                        'role',         oi.role,
                        'dish_name',    d.name,
                        'dish_name_en', COALESCE(d.name_en, ''),
                        'dish_price',   d.price,
                        'dish_image',   COALESCE(d.image, '')
                    )
                    ORDER BY oi.id
                ) AS items_json
            FROM offers_v2 o
            LEFT JOIN offer_items_v2 oi ON oi.offer_id = o.id
            LEFT JOIN dishes_v2 d ON d.id = oi.dish_id
            WHERE o.restaurant_id = ? AND o.is_active = 1
            GROUP BY o.id
            ORDER BY o.sort_order, o.id
        ");
        $stmt->execute([$restaurantId]);

        $offers = $stmt->fetchAll();
        foreach ($offers as &$o) {
            $o['items'] = $o['items_json']
                ? (json_decode('[' . $o['items_json'] . ']', true) ?: [])
                : [];
            unset($o['items_json']);
        }
        unset($o);
        return $offers;
    }

    // ============================================================
    // Ratings (used by dish.php)
    // ============================================================

    /**
     * Get ratings where a dish is marked as the favorite.
     * Returns ratings with table_number from the order.
     */
    public function getDishRatings(int $restaurantId, int $dishId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                r.*, 
                DATE_FORMAT(r.created_at, '%d/%m/%Y') AS date_fmt,
                o.table_number
            FROM order_ratings r
            JOIN orders o ON o.id = r.order_id
            WHERE r.restaurant_id = ? AND r.favorite_dish_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$restaurantId, $dishId]);
        return $stmt->fetchAll();
    }

    /**
     * Get aggregated rating stats for a dish (avg, total, breakdown by stars).
     */
    public function getDishRatingStats(int $restaurantId, int $dishId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                ROUND(AVG(r.rating), 1) AS avg,
                COUNT(*)                AS total,
                SUM(r.rating = 5)       AS r5,
                SUM(r.rating = 4)       AS r4,
                SUM(r.rating = 3)       AS r3,
                SUM(r.rating = 2)       AS r2,
                SUM(r.rating = 1)       AS r1
            FROM order_ratings r
            WHERE r.restaurant_id = ? AND r.favorite_dish_id = ?
        ");
        $stmt->execute([$restaurantId, $dishId]);
        $stats = $stmt->fetch();

        // Normalize null values from empty result set
        return [
            'avg'   => $stats['avg']   ?? 0,
            'total' => intval($stats['total'] ?? 0),
            'r5'    => intval($stats['r5']    ?? 0),
            'r4'    => intval($stats['r4']    ?? 0),
            'r3'    => intval($stats['r3']    ?? 0),
            'r2'    => intval($stats['r2']    ?? 0),
            'r1'    => intval($stats['r1']    ?? 0),
        ];
    }

    // ============================================================
    // Helpers — Build menu data structure
    // ============================================================

    /**
     * Group dishes by category_id for easier rendering.
     */
    public function groupDishesByCategory(array $dishes): array
    {
        $grouped = [];
        foreach ($dishes as $d) {
            $grouped[$d['category_id']][] = $d;
        }
        return $grouped;
    }

    /**
     * Build the complete menu data structure for a branch in one call.
     * This is what menu/index.php should use.
     *
     * Returns:
     *   [
     *     'categories'      => array of category rows,
     *     'dishes'          => array of all dish rows (with overrides),
     *     'dishes_by_cat'   => dishes grouped by category_id,
     *     'options_by_dish' => option groups indexed by dish_id,
     *     'offers'          => active offers with items,
     *   ]
     */
    public function getFullMenuForBranch(int $restaurantId, int $branchId): array
    {
        $dishes = $this->getDishesForBranch($restaurantId, $branchId);

        return [
            'categories'      => $this->getCategories($restaurantId),
            'dishes'          => $dishes,
            'dishes_by_cat'   => $this->groupDishesByCategory($dishes),
            'options_by_dish' => $this->getOptionsForRestaurant($restaurantId),
            'offers'          => $this->getActiveOffers($restaurantId),
        ];
    }
}