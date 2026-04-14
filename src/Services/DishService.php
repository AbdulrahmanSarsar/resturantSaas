<?php
/**
 * MenuPro — Dish Service
 * 
 * Extracts ALL dish business logic from dishes.php (92KB, 1692 lines).
 * Handles: CRUD, image/3D model uploads, option groups, suggestions,
 * toggle availability, sold_out management.
 * 
 * Dual-writes to old tables (dishes) AND new tables (dishes_v2).
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
     */
    public function getAllForRestaurant(int $restaurantId, ?int $categoryId = null): array
    {
        if ($categoryId) {
            $stmt = $this->db->prepare("
                SELECT d.*, c.name as cat_name 
                FROM dishes d LEFT JOIN categories c ON c.id = d.category_id 
                WHERE d.restaurant_id = ? AND d.category_id = ? 
                ORDER BY d.sort_order, d.id DESC
            ");
            $stmt->execute([$restaurantId, $categoryId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT d.*, c.name as cat_name 
                FROM dishes d LEFT JOIN categories c ON c.id = d.category_id 
                WHERE d.restaurant_id = ? 
                ORDER BY d.sort_order, d.id DESC
            ");
            $stmt->execute([$restaurantId]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Get all active dishes (for dropdown lists, suggestions).
     */
    public function getActiveForRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, price, image FROM dishes 
            WHERE restaurant_id = ? AND is_available = 1 ORDER BY name
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
     * Get option groups map for all dishes.
     */
    public function getOptionsMap(int $restaurantId): array
    {
        $map = [];
        $stmt = $this->db->prepare("
            SELECT * FROM dish_option_groups WHERE restaurant_id = ? ORDER BY sort_order
        ");
        $stmt->execute([$restaurantId]);
        foreach ($stmt->fetchAll() as $group) {
            $valStmt = $this->db->prepare("
                SELECT * FROM dish_option_values WHERE group_id = ? ORDER BY sort_order
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
        $stmt = $this->db->prepare("SELECT * FROM dishes WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$id, $restaurantId]);
        return $stmt->fetch() ?: null;
    }

    // ============================================================
    // CREATE
    // ============================================================

    /**
     * Add a new dish.
     * 
     * @param int   $restaurantId
     * @param array $data  Form data ($_POST)
     * @param array $files Uploaded files ($_FILES)
     * @return array ['success' => bool, 'id' => int, 'message' => string]
     */
    public function create(int $restaurantId, array $data, array $files = []): array
    {
        $name         = trim($data['name'] ?? '');
        $nameEn       = trim($data['name_en'] ?? '');
        $description  = trim($data['description'] ?? '');
        $descEn       = trim($data['description_en'] ?? '');
        $ingredients  = trim($data['ingredients'] ?? '');
        $ingredientsEn = trim($data['ingredients_en'] ?? '');
        $recipe       = trim($data['recipe'] ?? '');
        $recipeEn     = trim($data['recipe_en'] ?? '');
        $price        = floatval($data['price'] ?? 0);
        $categoryId   = intval($data['category_id'] ?? 0);
        $sortOrder    = intval($data['sort_order'] ?? 0);
        $isAvailable  = isset($data['is_available']) ? 1 : 0;
        $discountPercent = floatval($data['discount_percent'] ?? 0);
        $discountActive  = isset($data['discount_active']) ? 1 : 0;
        $prepTime     = max(1, intval($data['prep_time'] ?? 15));

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
            if ($result['success']) {
                $model3dFile = $result['filename'];
                $hasModel3d = 1;
            }
        }
        if (!empty($files['model3d_usdz']['name'])) {
            $result = $this->uploader->uploadUsdz($files['model3d_usdz']);
            if ($result['success']) {
                $usdzFile = $result['filename'];
            }
        }

        // Insert into old table
        $this->db->prepare("
            INSERT INTO dishes (
                restaurant_id, category_id, name, name_en, description, description_en,
                ingredients, ingredients_en, recipe, recipe_en, price, discount_percent,
                discount_active, prep_time, image, has_model3d, model3d_file, model3d_usdz,
                is_available, sort_order
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $restaurantId, $categoryId, $name, $nameEn, $description, $descEn,
            $ingredients, $ingredientsEn, $recipe, $recipeEn, $price, $discountPercent,
            $discountActive, $prepTime, $image, $hasModel3d, $model3dFile, $usdzFile,
            $isAvailable, $sortOrder
        ]);
        $dishId = intval($this->db->lastInsertId());

        // Save suggestions
        $this->saveSuggestions($dishId, $restaurantId, $data['suggestions'] ?? []);

        // Dual write to new table
        try {
            $this->db->prepare("
                INSERT INTO dishes_v2 (
                    id, restaurant_id, category_id, name, name_en, description, description_en,
                    ingredients, ingredients_en, recipe, recipe_en, price, image, has_model3d,
                    model3d_file, model3d_usdz, prep_time, sort_order, is_active
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $dishId, $restaurantId, $categoryId, $name, $nameEn, $description, $descEn,
                $ingredients, $ingredientsEn, $recipe, $recipeEn, $price, $image, $hasModel3d,
                $model3dFile, $usdzFile, $prepTime, $sortOrder, $isAvailable
            ]);
        } catch (\Exception $e) {
            error_log('DishService dual-write create failed: ' . $e->getMessage());
        }

        return ['success' => true, 'id' => $dishId, 'message' => 'تم إضافة الطبق بنجاح!'];
    }

    // ============================================================
    // UPDATE
    // ============================================================

    /**
     * Update an existing dish.
     */
    public function update(int $dishId, int $restaurantId, array $data, array $files = []): array
    {
        $name         = trim($data['name'] ?? '');
        $nameEn       = trim($data['name_en'] ?? '');
        $description  = trim($data['description'] ?? '');
        $descEn       = trim($data['description_en'] ?? '');
        $ingredients  = trim($data['ingredients'] ?? '');
        $ingredientsEn = trim($data['ingredients_en'] ?? '');
        $recipe       = trim($data['recipe'] ?? '');
        $recipeEn     = trim($data['recipe_en'] ?? '');
        $price        = floatval($data['price'] ?? 0);
        $categoryId   = intval($data['category_id'] ?? 0);
        $sortOrder    = intval($data['sort_order'] ?? 0);
        $isAvailable  = isset($data['is_available']) ? 1 : 0;
        $discountPercent = floatval($data['discount_percent'] ?? 0);
        $discountActive  = isset($data['discount_active']) ? 1 : 0;
        $prepTime     = max(1, intval($data['prep_time'] ?? 15));

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
            if ($result['success']) {
                $model3dFile = $result['filename'];
                $hasModel3d = 1;
            }
        } elseif ($model3dFile) {
            $hasModel3d = 1;
        }

        // Update old table
        $this->db->prepare("
            UPDATE dishes SET 
                category_id=?, name=?, name_en=?, description=?, description_en=?,
                ingredients=?, ingredients_en=?, recipe=?, recipe_en=?, price=?,
                discount_percent=?, discount_active=?, prep_time=?, image=?,
                has_model3d=?, model3d_file=?, model3d_usdz=?, is_available=?, sort_order=?
            WHERE id=? AND restaurant_id=?
        ")->execute([
            $categoryId, $name, $nameEn, $description, $descEn,
            $ingredients, $ingredientsEn, $recipe, $recipeEn, $price,
            $discountPercent, $discountActive, $prepTime, $image,
            $hasModel3d, $model3dFile, $usdzFile, $isAvailable, $sortOrder,
            $dishId, $restaurantId
        ]);

        // Save suggestions
        $this->saveSuggestions($dishId, $restaurantId, $data['suggestions'] ?? []);

        // Dual write
        try {
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
                $prepTime, $sortOrder, $isAvailable,
                $dishId, $restaurantId
            ]);
        } catch (\Exception $e) {
            error_log('DishService dual-write update failed: ' . $e->getMessage());
        }

        return ['success' => true, 'message' => 'تم تعديل الطبق!'];
    }

    // ============================================================
    // DELETE
    // ============================================================

    /**
     * Delete a dish and its files.
     */
    public function delete(int $dishId, int $restaurantId): array
    {
        $dish = $this->getById($dishId, $restaurantId);
        if (!$dish) {
            return ['success' => false, 'message' => 'الطبق غير موجود'];
        }

        // Delete files
        if ($dish['image']) $this->uploader->delete('dishes', $dish['image']);
        if ($dish['model3d_file']) $this->uploader->delete('models3d', $dish['model3d_file']);

        // Delete from old table (cascade deletes options and suggestions)
        $this->db->prepare("DELETE FROM dishes WHERE id=? AND restaurant_id=?")
            ->execute([$dishId, $restaurantId]);

        // Dual write
        try {
            $this->db->prepare("DELETE FROM dishes_v2 WHERE id=? AND restaurant_id=?")
                ->execute([$dishId, $restaurantId]);
        } catch (\Exception $e) {
            error_log('DishService dual-write delete failed: ' . $e->getMessage());
        }

        return ['success' => true, 'message' => 'تم حذف الطبق!'];
    }

    // ============================================================
    // TOGGLES
    // ============================================================

    /**
     * Toggle dish availability.
     */
    public function toggleAvailability(int $dishId, int $restaurantId): bool
    {
        $this->db->prepare("UPDATE dishes SET is_available = NOT is_available WHERE id=? AND restaurant_id=?")
            ->execute([$dishId, $restaurantId]);

        try {
            $this->db->prepare("UPDATE dishes_v2 SET is_active = NOT is_active WHERE id=? AND restaurant_id=?")
                ->execute([$dishId, $restaurantId]);
        } catch (\Exception $e) {}

        return true;
    }

    /**
     * Toggle sold_out status.
     */
    public function toggleSoldOut(int $dishId, int $restaurantId): bool
    {
        $this->db->prepare("UPDATE dishes SET sold_out = NOT sold_out WHERE id=? AND restaurant_id=?")
            ->execute([$dishId, $restaurantId]);
        return true;
    }

    /**
     * Reset sold_out for all dishes in a restaurant.
     */
    public function resetAllSoldOut(int $restaurantId): bool
    {
        $this->db->prepare("UPDATE dishes SET sold_out = 0 WHERE restaurant_id=?")
            ->execute([$restaurantId]);
        return true;
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

    /**
     * Save option groups and values for a dish.
     */
    public function saveOptions(int $dishId, int $restaurantId, array $groups): bool
    {
        // Verify dish belongs to restaurant
        $check = $this->db->prepare("SELECT id FROM dishes WHERE id=? AND restaurant_id=?");
        $check->execute([$dishId, $restaurantId]);
        if (!$check->fetch()) return false;

        // Delete existing options
        $this->db->prepare("
            DELETE FROM dish_option_values WHERE group_id IN 
            (SELECT id FROM dish_option_groups WHERE dish_id=? AND restaurant_id=?)
        ")->execute([$dishId, $restaurantId]);

        $this->db->prepare("DELETE FROM dish_option_groups WHERE dish_id=? AND restaurant_id=?")
            ->execute([$dishId, $restaurantId]);

        // Insert new options
        foreach ($groups as $gidx => $group) {
            $gname    = trim($group['name'] ?? '');
            $gnameEn  = trim($group['name_en'] ?? '');
            $gmode    = in_array($group['price_mode'] ?? '', ['add', 'replace']) ? $group['price_mode'] : 'add';
            $greq     = isset($group['is_required']) ? 1 : 0;
            $gsort    = intval($group['sort'] ?? $gidx);

            if (!$gname) continue;

            $this->db->prepare("
                INSERT INTO dish_option_groups (dish_id, restaurant_id, name, name_en, price_mode, is_required, sort_order) 
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$dishId, $restaurantId, $gname, $gnameEn, $gmode, $greq, $gsort]);

            $groupId = intval($this->db->lastInsertId());

            foreach ($group['values'] ?? [] as $vidx => $val) {
                $vname   = trim($val['name'] ?? '');
                $vnameEn = trim($val['name_en'] ?? '');
                $vprice  = floatval($val['price'] ?? 0);
                $vsort   = intval($val['sort'] ?? $vidx);

                if (!$vname) continue;

                $this->db->prepare("
                    INSERT INTO dish_option_values (group_id, name, name_en, price, sort_order) 
                    VALUES (?,?,?,?,?)
                ")->execute([$groupId, $vname, $vnameEn, $vprice, $vsort]);
            }
        }

        return true;
    }
}