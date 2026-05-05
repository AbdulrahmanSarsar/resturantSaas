<?php
/**
 * MenuPro — Dish Service
 * Source of truth: dishes_v2 (single write, no dual write)
 */

namespace MenuPro\Services;

use PDO;
use MenuPro\Helpers\FileUploader;

class DishService
{
    private PDO $db;
    private FileUploader $uploader;

    public function __construct(PDO $db, ?FileUploader $uploader = null)
    {
        $this->db = $db;
        $this->uploader = $uploader ?? new FileUploader();
    }

    // ============================================================
    // QUERIES
    // ============================================================

    /**
     * Get all dishes for a restaurant, optionally filtered by category.
     * Merges branch_dish_overrides when branchId is provided.
     */
    public function getAllForRestaurant(int $restaurantId, ?int $categoryId = null, ?int $branchId = null): array
    {
        $params = [$restaurantId];
        $joinBranch = '';
        $selectBranch = ',
            0 AS discount_percent,
            0 AS discount_active,
            0 AS sold_out';

        if ($branchId) {
            $joinBranch = "LEFT JOIN branch_dish_overrides bdo ON bdo.dish_id = d.id AND bdo.branch_id = ?";
            $selectBranch = ',
                COALESCE(bdo.discount_percent, 0) AS discount_percent,
                COALESCE(bdo.discount_active,  0) AS discount_active,
                COALESCE(bdo.sold_out,         0) AS sold_out,
                bdo.price            AS branch_price,
                bdo.is_available     AS branch_is_available';
            array_unshift($params, $branchId);
        }

        $catFilter = '';
        if ($categoryId) {
            $catFilter = "AND d.category_id = ?";
            $params[] = $categoryId;
        }

        $stmt = $this->db->prepare("
            SELECT d.*,
                   d.is_active AS is_available,
                   c.name AS cat_name
                   {$selectBranch}
            FROM dishes_v2 d
            LEFT JOIN categories_v2 c ON c.id = d.category_id
            {$joinBranch}
            WHERE d.restaurant_id = ? {$catFilter}
            ORDER BY d.sort_order, d.id DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if ($branchId) {
            foreach ($rows as &$d) {
                if (isset($d['branch_price']) && $d['branch_price'] !== null) {
                    $d['price'] = floatval($d['branch_price']);
                }
                if (isset($d['branch_is_available']) && $d['branch_is_available'] !== null) {
                    $d['is_available'] = intval($d['branch_is_available']);
                }
            }
            unset($d);
        }

        return $rows;
    }

    /**
     * Get all active dishes (for dropdowns, suggestions).
     */
    public function getActiveForRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, price, image FROM dishes_v2
            WHERE restaurant_id = ? AND is_active = 1 ORDER BY name
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll();
    }

    /**
     * Get suggestion map for all dishes.
     */
    public function getSuggestionMap(int $restaurantId): array
    {
        $map = [];
        $stmt = $this->db->prepare("
            SELECT dish_id, suggested_dish_id FROM dish_suggestions
            WHERE restaurant_id = ? ORDER BY sort_order
        ");
        $stmt->execute([$restaurantId]);
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['dish_id']][] = $row['suggested_dish_id'];
        }
        return $map;
    }

    /**
     * Get option groups map for all dishes (dashboard use).
     */
    public function getOptionsMap(int $restaurantId): array
    {
        $map = [];
        $stmt = $this->db->prepare("
            SELECT og.*
            FROM dish_option_groups_v2 og
            JOIN dishes_v2 d ON d.id = og.dish_id AND d.restaurant_id = ?
            ORDER BY og.sort_order
        ");
        $stmt->execute([$restaurantId]);
        foreach ($stmt->fetchAll() as $group) {
            $valStmt = $this->db->prepare("
                SELECT * FROM dish_option_values_v2 WHERE group_id = ? ORDER BY sort_order
            ");
            $valStmt->execute([$group['id']]);
            $group['values'] = $valStmt->fetchAll();
            $map[$group['dish_id']][] = $group;
        }
        return $map;
    }

    /**
     * Get a single dish by ID.
     */
    public function getById(int $id, int $restaurantId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM dishes_v2 WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$id, $restaurantId]);
        return $stmt->fetch() ?: null;
    }

    // ============================================================
    // CREATE
    // ============================================================

    public function create(int $restaurantId, array $data, array $files = [], ?int $branchId = null): array
    {
        $name          = trim($data['name'] ?? '');
        $nameEn        = trim($data['name_en'] ?? '');
        $description   = trim($data['description'] ?? '');
        $descEn        = trim($data['description_en'] ?? '');
        $ingredients   = trim($data['ingredients'] ?? '');
        $ingredientsEn = trim($data['ingredients_en'] ?? '');
        $recipe        = trim($data['recipe'] ?? '');
        $recipeEn      = trim($data['recipe_en'] ?? '');
        $price         = floatval($data['price'] ?? 0);
        $categoryId    = intval($data['category_id'] ?? 0);
        $sortOrder     = intval($data['sort_order'] ?? 0);
        $isActive      = isset($data['is_available']) ? 1 : 0;
        $discountPercent = floatval($data['discount_percent'] ?? 0);
        $discountActive  = isset($data['discount_active']) ? 1 : 0;
        $prepTime      = max(1, intval($data['prep_time'] ?? 15));

        if (!$name) {
            return ['success' => false, 'id' => 0, 'message' => 'اسم الطبق مطلوب'];
        }

        // Handle image upload
        $image = trim($data['old_image'] ?? $data['library_image'] ?? '');
        if (!empty($files['image']['name']) && ($files['image']['error'] ?? 4) === UPLOAD_ERR_OK) {
            $result = $this->uploader->uploadImage($files['image'], 'dishes', $image ?: null);
            if ($result['success']) {
                $image = $result['filename'];
            } elseif ($result['error']) {
                return ['success' => false, 'id' => 0, 'message' => $result['error']];
            }
        }

        // Handle 3D model uploads
        $model3dFile = '';
        $usdzFile    = '';
        $hasModel3d  = 0;

        if (!empty($files['model3d']['name'])) {
            $result = $this->uploader->uploadModel($files['model3d']);
            if ($result['success']) { $model3dFile = $result['filename']; $hasModel3d = 1; }
        }
        if (!empty($files['model3d_usdz']['name'])) {
            $result = $this->uploader->uploadUsdz($files['model3d_usdz']);
            if ($result['success']) { $usdzFile = $result['filename']; }
        }

        $this->db->prepare("
            INSERT INTO dishes_v2 (
                restaurant_id, category_id, name, name_en, description, description_en,
                ingredients, ingredients_en, recipe, recipe_en, price, image, has_model3d,
                model3d_file, model3d_usdz, prep_time, sort_order, is_active
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $restaurantId, $categoryId, $name, $nameEn, $description, $descEn,
            $ingredients, $ingredientsEn, $recipe, $recipeEn, $price, $image, $hasModel3d,
            $model3dFile, $usdzFile, $prepTime, $sortOrder, $isActive
        ]);
        $dishId = intval($this->db->lastInsertId());

        $this->saveSuggestions($dishId, $restaurantId, $data['suggestions'] ?? []);
        $this->syncDiscountToBranches($dishId, $restaurantId, $discountPercent, $discountActive, $branchId);

        return ['success' => true, 'id' => $dishId, 'message' => 'تم إضافة الطبق بنجاح!'];
    }

    // ============================================================
    // UPDATE
    // ============================================================

    public function update(int $dishId, int $restaurantId, array $data, array $files = [], ?int $branchId = null): array
    {
        $name          = trim($data['name'] ?? '');
        $nameEn        = trim($data['name_en'] ?? '');
        $description   = trim($data['description'] ?? '');
        $descEn        = trim($data['description_en'] ?? '');
        $ingredients   = trim($data['ingredients'] ?? '');
        $ingredientsEn = trim($data['ingredients_en'] ?? '');
        $recipe        = trim($data['recipe'] ?? '');
        $recipeEn      = trim($data['recipe_en'] ?? '');
        $price         = floatval($data['price'] ?? 0);
        $categoryId    = intval($data['category_id'] ?? 0);
        $sortOrder     = intval($data['sort_order'] ?? 0);
        $isActive      = isset($data['is_available']) ? 1 : 0;
        $discountPercent = floatval($data['discount_percent'] ?? 0);
        $discountActive  = isset($data['discount_active']) ? 1 : 0;
        $prepTime      = max(1, intval($data['prep_time'] ?? 15));

        // Handle image
        $image = trim($data['old_image'] ?? $data['library_image'] ?? '');
        if (!empty($files['image']['name']) && ($files['image']['error'] ?? 4) === UPLOAD_ERR_OK) {
            $result = $this->uploader->uploadImage($files['image'], 'dishes', $image);
            if ($result['success']) {
                $image = $result['filename'];
            } elseif ($result['error']) {
                return ['success' => false, 'message' => $result['error']];
            }
        }

        // Handle 3D models
        $model3dFile = $data['old_model'] ?? '';
        $usdzFile    = $data['old_usdz'] ?? '';
        $hasModel3d  = 0;

        if (!empty($files['model3d_usdz']['name'])) {
            $result = $this->uploader->uploadUsdz($files['model3d_usdz'], $usdzFile);
            if ($result['success']) $usdzFile = $result['filename'];
        }
        if (!empty($files['model3d']['name'])) {
            $result = $this->uploader->uploadModel($files['model3d'], $model3dFile);
            if ($result['success']) { $model3dFile = $result['filename']; $hasModel3d = 1; }
        } elseif ($model3dFile) {
            $hasModel3d = 1;
        }

        $this->db->prepare("
            UPDATE dishes_v2 SET
                category_id=?, name=?, name_en=?, description=?, description_en=?,
                ingredients=?, ingredients_en=?, recipe=?, recipe_en=?, price=?,
                image=?, has_model3d=?, model3d_file=?, model3d_usdz=?,
                prep_time=?, sort_order=?, is_active=?
            WHERE id=? AND restaurant_id=?
        ")->execute([
            $categoryId, $name, $nameEn, $description, $descEn,
            $ingredients, $ingredientsEn, $recipe, $recipeEn, $price,
            $image, $hasModel3d, $model3dFile, $usdzFile,
            $prepTime, $sortOrder, $isActive,
            $dishId, $restaurantId
        ]);

        $this->saveSuggestions($dishId, $restaurantId, $data['suggestions'] ?? []);
        $this->syncDiscountToBranches($dishId, $restaurantId, $discountPercent, $discountActive, $branchId);

        return ['success' => true, 'message' => 'تم تعديل الطبق!'];
    }

    // ============================================================
    // DELETE
    // ============================================================

    public function delete(int $dishId, int $restaurantId): array
    {
        $dish = $this->getById($dishId, $restaurantId);
        if (!$dish) {
            return ['success' => false, 'message' => 'الطبق غير موجود'];
        }

        if ($dish['image'])       $this->uploader->delete('dishes', $dish['image']);
        if ($dish['model3d_file']) $this->uploader->delete('models3d', $dish['model3d_file']);

        // Delete related data
        $this->db->prepare("DELETE FROM dish_option_values_v2 WHERE group_id IN (SELECT id FROM dish_option_groups_v2 WHERE dish_id=?)")
            ->execute([$dishId]);
        $this->db->prepare("DELETE FROM dish_option_groups_v2 WHERE dish_id=?")
            ->execute([$dishId]);
        $this->db->prepare("DELETE FROM dish_suggestions WHERE dish_id=? OR suggested_dish_id=?")
            ->execute([$dishId, $dishId]);
        $this->db->prepare("DELETE FROM branch_dish_overrides WHERE dish_id=?")
            ->execute([$dishId]);
        $this->db->prepare("DELETE FROM dishes_v2 WHERE id=? AND restaurant_id=?")
            ->execute([$dishId, $restaurantId]);

        return ['success' => true, 'message' => 'تم حذف الطبق!'];
    }

    // ============================================================
    // TOGGLES
    // ============================================================

    public function toggleAvailability(int $dishId, int $restaurantId): bool
    {
        $this->db->prepare("UPDATE dishes_v2 SET is_active = NOT is_active WHERE id=? AND restaurant_id=?")
            ->execute([$dishId, $restaurantId]);
        return true;
    }

    /**
     * Toggle sold_out via branch_dish_overrides.
     * Source of truth is branch_dish_overrides, not the dish table.
     */
    public function toggleSoldOut(int $dishId, int $restaurantId, ?int $branchId = null): bool
    {
        // Verify dish belongs to restaurant
        $check = $this->db->prepare("SELECT id FROM dishes_v2 WHERE id=? AND restaurant_id=?");
        $check->execute([$dishId, $restaurantId]);
        if (!$check->fetchColumn()) return false;

        if ($branchId) {
            // Read current value for this branch
            $cur = $this->db->prepare("SELECT sold_out FROM branch_dish_overrides WHERE dish_id=? AND branch_id=?");
            $cur->execute([$dishId, $branchId]);
            $current = (int)($cur->fetchColumn() ?: 0);
            $this->upsertBranchOverride($branchId, $dishId, ['sold_out' => $current ? 0 : 1]);
        } else {
            $bStmt = $this->db->prepare("SELECT id FROM branches WHERE restaurant_id = ? AND is_active = 1");
            $bStmt->execute([$restaurantId]);
            foreach ($bStmt->fetchAll() as $b) {
                $cur = $this->db->prepare("SELECT sold_out FROM branch_dish_overrides WHERE dish_id=? AND branch_id=?");
                $cur->execute([$dishId, $b['id']]);
                $current = (int)($cur->fetchColumn() ?: 0);
                $this->upsertBranchOverride(intval($b['id']), $dishId, ['sold_out' => $current ? 0 : 1]);
            }
        }

        return true;
    }

    public function resetAllSoldOut(int $restaurantId, ?int $branchId = null): bool
    {
        if ($branchId) {
            $this->db->prepare("
                UPDATE branch_dish_overrides bdo
                JOIN dishes_v2 d ON d.id = bdo.dish_id
                SET bdo.sold_out = 0
                WHERE bdo.branch_id = ? AND d.restaurant_id = ?
            ")->execute([$branchId, $restaurantId]);
        } else {
            $this->db->prepare("
                UPDATE branch_dish_overrides bdo
                JOIN dishes_v2 d ON d.id = bdo.dish_id
                SET bdo.sold_out = 0
                WHERE d.restaurant_id = ?
            ")->execute([$restaurantId]);
        }
        return true;
    }

    public function syncDiscountToBranches(int $dishId, int $restaurantId, float $discountPercent, int $discountActive, ?int $branchId = null): void
    {
        $fields = ['discount_percent' => $discountPercent, 'discount_active' => $discountActive];

        if ($branchId) {
            $this->upsertBranchOverride($branchId, $dishId, $fields);
            return;
        }

        $bStmt = $this->db->prepare("SELECT id FROM branches WHERE restaurant_id = ? AND is_active = 1");
        $bStmt->execute([$restaurantId]);
        foreach ($bStmt->fetchAll() as $b) {
            $this->upsertBranchOverride(intval($b['id']), $dishId, $fields);
        }
    }

    private function upsertBranchOverride(int $branchId, int $dishId, array $fields): void
    {
        if (empty($fields)) return;

        $check = $this->db->prepare("SELECT id FROM branch_dish_overrides WHERE branch_id=? AND dish_id=?");
        $check->execute([$branchId, $dishId]);
        $exists = $check->fetchColumn();

        if ($exists) {
            $setParts = [];
            $params = [];
            foreach ($fields as $col => $val) {
                $setParts[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $params[] = $exists;
            $this->db->prepare("UPDATE branch_dish_overrides SET " . implode(', ', $setParts) . " WHERE id = ?")
                ->execute($params);
        } else {
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            $sql = "INSERT INTO branch_dish_overrides (branch_id, dish_id, " .
                   implode(', ', array_map(fn($c) => "`{$c}`", $cols)) . ") VALUES (?, ?, " .
                   implode(', ', $placeholders) . ")";
            $this->db->prepare($sql)->execute(array_merge([$branchId, $dishId], array_values($fields)));
        }
    }

    // ============================================================
    // SUGGESTIONS
    // ============================================================

    private function saveSuggestions(int $dishId, int $restaurantId, array $suggestionIds): void
    {
        $this->db->prepare("DELETE FROM dish_suggestions WHERE dish_id=? AND restaurant_id=?")
            ->execute([$dishId, $restaurantId]);

        foreach ($suggestionIds as $order => $suggestedId) {
            $suggestedId = intval($suggestedId);
            if ($suggestedId && $suggestedId !== $dishId) {
                $this->db->prepare("
                    INSERT IGNORE INTO dish_suggestions (dish_id, suggested_dish_id, restaurant_id, sort_order)
                    VALUES (?,?,?,?)
                ")->execute([$dishId, $suggestedId, $restaurantId, $order]);
            }
        }
    }

    // ============================================================
    // OPTIONS
    // ============================================================

    public function saveOptions(int $dishId, int $restaurantId, array $groups): bool
    {
        $check = $this->db->prepare("SELECT id FROM dishes_v2 WHERE id=? AND restaurant_id=?");
        $check->execute([$dishId, $restaurantId]);
        if (!$check->fetch()) return false;

        $this->db->prepare("DELETE FROM dish_option_values_v2 WHERE group_id IN (SELECT id FROM dish_option_groups_v2 WHERE dish_id=?)")
            ->execute([$dishId]);
        $this->db->prepare("DELETE FROM dish_option_groups_v2 WHERE dish_id=?")
            ->execute([$dishId]);

        foreach ($groups as $gidx => $group) {
            $gname   = trim($group['name'] ?? '');
            $gnameEn = trim($group['name_en'] ?? '');
            $gmode   = in_array($group['price_mode'] ?? '', ['add', 'replace']) ? $group['price_mode'] : 'add';
            $gtype   = in_array($group['type'] ?? '', ['radio', 'checkbox']) ? $group['type'] : 'radio';
            $greq    = isset($group['is_required']) ? 1 : 0;
            $gsort   = intval($group['sort'] ?? $gidx);

            if (!$gname) continue;

            $this->db->prepare("
                INSERT INTO dish_option_groups_v2 (dish_id, name, name_en, type, price_mode, is_required, sort_order)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$dishId, $gname, $gnameEn, $gtype, $gmode, $greq, $gsort]);

            $groupId = intval($this->db->lastInsertId());

            foreach ($group['values'] ?? [] as $vidx => $val) {
                $vname   = trim($val['name'] ?? '');
                $vnameEn = trim($val['name_en'] ?? '');
                $vprice  = floatval($val['price'] ?? 0);
                $vsort   = intval($val['sort'] ?? $vidx);

                if (!$vname) continue;

                $this->db->prepare("
                    INSERT INTO dish_option_values_v2 (group_id, name, name_en, price, sort_order)
                    VALUES (?,?,?,?,?)
                ")->execute([$groupId, $vname, $vnameEn, $vprice, $vsort]);
            }
        }

        return true;
    }
}
