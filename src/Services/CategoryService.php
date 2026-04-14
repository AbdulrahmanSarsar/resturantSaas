<?php
/**
 * MenuPro — Category Service
 * 
 * Extracts category CRUD from categories.php.
 * Categories belong to a restaurant (shared across branches).
 * 
 * Currently reads/writes to OLD tables (categories) 
 * AND new tables (categories_v2) via dual-write.
 */

namespace MenuPro\Services;

use PDO;

class CategoryService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all categories for a restaurant with dish count.
     */
    public function getAllForRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, COUNT(d.id) as dish_count
            FROM categories c
            LEFT JOIN dishes d ON d.category_id = c.id
            WHERE c.restaurant_id = ?
            GROUP BY c.id
            ORDER BY c.sort_order, c.id
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll();
    }

    /**
     * Get active categories only (for dropdowns, menu display).
     */
    public function getActiveForRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM categories 
            WHERE restaurant_id = ? AND is_active = 1 
            ORDER BY sort_order
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll();
    }

    /**
     * Add a new category.
     */
    public function create(int $restaurantId, string $name, string $nameEn = '', int $sortOrder = 0): int
    {
        $name = trim($name);
        if (!$name) {
            throw new \InvalidArgumentException('اسم التصنيف مطلوب');
        }

        // Old table
        $this->db->prepare("
            INSERT INTO categories (restaurant_id, name, name_en, sort_order) VALUES (?, ?, ?, ?)
        ")->execute([$restaurantId, $name, $nameEn, $sortOrder]);
        $id = intval($this->db->lastInsertId());

        // Dual write to new table
        try {
            $this->db->prepare("
                INSERT INTO categories_v2 (id, restaurant_id, name, name_en, sort_order) VALUES (?, ?, ?, ?, ?)
            ")->execute([$id, $restaurantId, $name, $nameEn, $sortOrder]);
        } catch (\Exception $e) {
            error_log('CategoryService dual-write create failed: ' . $e->getMessage());
        }

        return $id;
    }

    /**
     * Update a category.
     */
    public function update(int $id, int $restaurantId, string $name, string $nameEn = '', int $sortOrder = 0, int $isActive = 1): bool
    {
        // Old table
        $this->db->prepare("
            UPDATE categories SET name = ?, name_en = ?, sort_order = ?, is_active = ? 
            WHERE id = ? AND restaurant_id = ?
        ")->execute([trim($name), $nameEn, $sortOrder, $isActive, $id, $restaurantId]);

        // Dual write
        try {
            $this->db->prepare("
                UPDATE categories_v2 SET name = ?, name_en = ?, sort_order = ?, is_active = ? 
                WHERE id = ? AND restaurant_id = ?
            ")->execute([trim($name), $nameEn, $sortOrder, $isActive, $id, $restaurantId]);
        } catch (\Exception $e) {
            error_log('CategoryService dual-write update failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Delete a category.
     */
    public function delete(int $id, int $restaurantId): bool
    {
        $this->db->prepare("DELETE FROM categories WHERE id = ? AND restaurant_id = ?")
            ->execute([$id, $restaurantId]);

        try {
            $this->db->prepare("DELETE FROM categories_v2 WHERE id = ? AND restaurant_id = ?")
                ->execute([$id, $restaurantId]);
        } catch (\Exception $e) {
            error_log('CategoryService dual-write delete failed: ' . $e->getMessage());
        }

        return true;
    }
}