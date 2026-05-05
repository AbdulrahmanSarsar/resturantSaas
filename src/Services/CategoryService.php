<?php
namespace MenuPro\Services;

use PDO;

class CategoryService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getAllForRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, COUNT(d.id) as dish_count
            FROM categories_v2 c
            LEFT JOIN dishes_v2 d ON d.category_id = c.id AND d.restaurant_id = c.restaurant_id
            WHERE c.restaurant_id = ?
            GROUP BY c.id
            ORDER BY c.sort_order, c.id
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll();
    }

    public function getActiveForRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM categories_v2
            WHERE restaurant_id = ? AND is_active = 1
            ORDER BY sort_order
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll();
    }

    public function create(int $restaurantId, string $name, string $nameEn = '', int $sortOrder = 0): int
    {
        $name = trim($name);
        if (!$name) {
            throw new \InvalidArgumentException('اسم التصنيف مطلوب');
        }

        $this->db->prepare("
            INSERT INTO categories_v2 (restaurant_id, name, name_en, sort_order) VALUES (?, ?, ?, ?)
        ")->execute([$restaurantId, $name, $nameEn, $sortOrder]);

        return intval($this->db->lastInsertId());
    }

    public function update(int $id, int $restaurantId, string $name, string $nameEn = '', int $sortOrder = 0, int $isActive = 1): bool
    {
        $this->db->prepare("
            UPDATE categories_v2 SET name = ?, name_en = ?, sort_order = ?, is_active = ?
            WHERE id = ? AND restaurant_id = ?
        ")->execute([trim($name), $nameEn, $sortOrder, $isActive, $id, $restaurantId]);

        return true;
    }

    public function delete(int $id, int $restaurantId): bool
    {
        $this->db->prepare("DELETE FROM categories_v2 WHERE id = ? AND restaurant_id = ?")
            ->execute([$id, $restaurantId]);

        return true;
    }
}
