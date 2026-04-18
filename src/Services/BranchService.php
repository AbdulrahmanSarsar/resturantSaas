<?php
/**
 * MenuPro — Branch Service
 * 
 * كل عمليات إدارة الفروع + إعداداتهم + الروابط الاجتماعية.
 *
 * [الإصلاح] نصرح بـ b.id AS id في كل JOIN على branch_settings
 * لأن branch_settings.id بيطغى على branches.id بسبب SELECT bs.*
 */

namespace MenuPro\Services;

use PDO;

class BranchService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ============================================================
    // BRANCH CRUD
    // ============================================================

    public function getAllForRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                b.id AS id,
                b.restaurant_id, b.name, b.name_en, b.slug, b.address, b.phone,
                b.is_active, b.created_at,
                bs.currency_code,
                bs.currency_symbol,
                bs.currency_symbol_en,
                bs.currency_decimals,
                bs.welcome_message,
                bs.welcome_message_en,
                bs.shamcash_enabled,
                bs.shamcash_number,
                (SELECT COUNT(*) 
                   FROM restaurant_staff rs 
                   WHERE rs.branch_id = b.id 
                     AND rs.is_active = 1) AS staff_count,
                (SELECT COUNT(*) 
                   FROM orders o 
                   WHERE o.branch_id = b.id 
                     AND DATE(o.created_at) = CURDATE()) AS today_orders,
                (SELECT COALESCE(SUM(o.total_price), 0)
                   FROM orders o
                   WHERE o.branch_id = b.id
                     AND DATE(o.created_at) = CURDATE()
                     AND o.status != 'cancelled') AS today_revenue
            FROM branches b
            LEFT JOIN branch_settings bs ON bs.branch_id = b.id
            WHERE b.restaurant_id = ?
            ORDER BY b.id ASC
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll();
    }

    public function getById(int $branchId, int $restaurantId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                b.id AS id,
                b.restaurant_id, b.name, b.name_en, b.slug, b.address, b.phone,
                b.is_active, b.created_at,
                bs.currency_code, bs.currency_symbol, bs.currency_symbol_en,
                bs.currency_decimals, bs.welcome_message, bs.welcome_message_en,
                bs.shamcash_enabled, bs.shamcash_number
            FROM branches b
            LEFT JOIN branch_settings bs ON bs.branch_id = b.id
            WHERE b.id = ? AND b.restaurant_id = ?
        ");
        $stmt->execute([$branchId, $restaurantId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * جلب فرع عبر slug — للـ menu URLs (/menu/{slug}/{branch-slug}).
     * [الإصلاح الأهم] b.id AS id ليضمن إن $branch['id'] هو branches.id مش branch_settings.id
     */
    public function getBySlug(int $restaurantId, string $branchSlug): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                b.id AS id,
                b.restaurant_id, b.name, b.name_en, b.slug, b.address, b.phone,
                b.is_active, b.created_at,
                bs.currency_code, bs.currency_symbol, bs.currency_symbol_en,
                bs.currency_decimals, bs.welcome_message, bs.welcome_message_en,
                bs.shamcash_enabled, bs.shamcash_number
            FROM branches b
            LEFT JOIN branch_settings bs ON bs.branch_id = b.id
            WHERE b.restaurant_id = ? AND b.slug = ? AND b.is_active = 1
        ");
        $stmt->execute([$restaurantId, $branchSlug]);
        return $stmt->fetch() ?: null;
    }

    public function getActiveBranches(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, name_en, slug, address, phone
            FROM branches
            WHERE restaurant_id = ? AND is_active = 1
            ORDER BY id ASC
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll();
    }

    public function create(int $restaurantId, array $data): array
    {
        $name    = trim($data['name'] ?? '');
        $nameEn  = trim($data['name_en'] ?? '');
        $address = trim($data['address'] ?? '');
        $phone   = trim($data['phone'] ?? '');

        if (!$name) {
            return ['success' => false, 'message' => 'اسم الفرع مطلوب'];
        }

        $slug = $this->generateSlug($name, $restaurantId);

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO branches (restaurant_id, name, name_en, slug, address, phone, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ")->execute([$restaurantId, $name, $nameEn, $slug, $address, $phone]);
            $branchId = intval($this->db->lastInsertId());

            $firstSettings = $this->db->prepare("
                SELECT bs.* FROM branch_settings bs
                JOIN branches b ON b.id = bs.branch_id
                WHERE b.restaurant_id = ? AND bs.branch_id != ?
                LIMIT 1
            ");
            $firstSettings->execute([$restaurantId, $branchId]);
            $existing = $firstSettings->fetch();

            if ($existing) {
                $this->db->prepare("
                    INSERT INTO branch_settings 
                        (branch_id, currency_code, currency_symbol, currency_symbol_en, 
                         currency_decimals, welcome_message, welcome_message_en, 
                         shamcash_enabled, shamcash_number)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $branchId,
                    $existing['currency_code']       ?? 'SYP',
                    $existing['currency_symbol']     ?? 'ل.س',
                    $existing['currency_symbol_en']  ?? 'S.P',
                    $existing['currency_decimals']   ?? 0,
                    $existing['welcome_message']     ?? '',
                    $existing['welcome_message_en']  ?? '',
                    $existing['shamcash_enabled']    ?? 0,
                    $existing['shamcash_number']     ?? '',
                ]);
            } else {
                $this->db->prepare("
                    INSERT INTO branch_settings (branch_id, currency_code, currency_symbol, currency_symbol_en, currency_decimals)
                    VALUES (?, 'SYP', 'ل.س', 'S.P', 0)
                ")->execute([$branchId]);
            }

            $this->db->commit();
            return ['success' => true, 'branch_id' => $branchId, 'slug' => $slug];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function update(int $branchId, int $restaurantId, array $data): bool
    {
        $this->db->prepare("
            UPDATE branches 
            SET name = ?, name_en = ?, address = ?, phone = ?
            WHERE id = ? AND restaurant_id = ?
        ")->execute([
            trim($data['name']    ?? ''),
            trim($data['name_en'] ?? ''),
            trim($data['address'] ?? ''),
            trim($data['phone']   ?? ''),
            $branchId,
            $restaurantId
        ]);

        // Check if branch_settings row exists, create if not
        $chk = $this->db->prepare("SELECT 1 FROM branch_settings WHERE branch_id = ?");
        $chk->execute([$branchId]);
        if (!$chk->fetch()) {
            $this->db->prepare("INSERT INTO branch_settings (branch_id) VALUES (?)")->execute([$branchId]);
        }

        $this->db->prepare("
            UPDATE branch_settings SET
                currency_code = ?, currency_symbol = ?, currency_symbol_en = ?,
                currency_decimals = ?, welcome_message = ?, welcome_message_en = ?,
                shamcash_enabled = ?, shamcash_number = ?
            WHERE branch_id = ?
        ")->execute([
            trim($data['currency_code']      ?? 'SYP'),
            trim($data['currency_symbol']    ?? 'ل.س'),
            trim($data['currency_symbol_en'] ?? 'S.P'),
            intval($data['currency_decimals'] ?? 0),
            trim($data['welcome_message']    ?? ''),
            trim($data['welcome_message_en'] ?? ''),
            isset($data['shamcash_enabled']) ? 1 : 0,
            trim($data['shamcash_number']    ?? ''),
            $branchId
        ]);
        return true;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function generateSlug(string $name, int $restaurantId): string
    {
        $slug = mb_strtolower(trim($name));
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/[^\p{L}\p{N}\-]/u', '', $slug);
        if (!$slug) $slug = 'branch';

        $base = $slug;
        $counter = 1;
        while (true) {
            $check = $this->db->prepare("SELECT 1 FROM branches WHERE restaurant_id = ? AND slug = ?");
            $check->execute([$restaurantId, $slug]);
            if (!$check->fetch()) break;
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }

    public function countForRestaurant(int $restaurantId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM branches WHERE restaurant_id = ?");
        $stmt->execute([$restaurantId]);
        return intval($stmt->fetchColumn());
    }
}