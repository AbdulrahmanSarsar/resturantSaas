<?php
/**
 * MenuPro — Branch Service
 * 
 * كل عمليات إدارة الفروع + إعداداتهم + الروابط الاجتماعية.
 * 
 * ملاحظة: staff_count يستخدم restaurant_staff (الجدول القديم) لأنو هو المستخدم فعلياً.
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

    /**
     * جلب كل فروع مطعم مع إحصائيات سريعة.
     */
    public function getAllForRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                b.*,
                bs.currency_code,
                bs.currency_symbol,
                bs.currency_decimals,
                (SELECT COUNT(*) 
                   FROM restaurant_staff rs 
                   WHERE rs.branch_id = b.id 
                     AND rs.is_active = 1) AS staff_count,
                (SELECT COUNT(*) 
                   FROM orders_v2 o 
                   WHERE o.branch_id = b.id 
                     AND DATE(o.created_at) = CURDATE()) AS today_orders,
                (SELECT COALESCE(SUM(o.total_price), 0)
                   FROM orders_v2 o
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

    /**
     * جلب فرع واحد مع إعداداته.
     */
    public function getById(int $branchId, int $restaurantId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT b.*, bs.*
            FROM branches b
            LEFT JOIN branch_settings bs ON bs.branch_id = b.id
            WHERE b.id = ? AND b.restaurant_id = ?
        ");
        $stmt->execute([$branchId, $restaurantId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * جلب فرع عبر slug — للـ menu URLs (/menu/{slug}/{branch-slug}).
     */
    public function getBySlug(int $restaurantId, string $branchSlug): ?array
    {
        $stmt = $this->db->prepare("
            SELECT b.*, bs.*
            FROM branches b
            LEFT JOIN branch_settings bs ON bs.branch_id = b.id
            WHERE b.restaurant_id = ? AND b.slug = ? AND b.is_active = 1
        ");
        $stmt->execute([$restaurantId, $branchSlug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * جلب فقط الفروع النشطة (للقوائم المختصرة).
     */
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

    /**
     * إنشاء فرع جديد — ينسخ الإعدادات والضرائب من أول فرع موجود.
     */
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
            // Create branch
            $this->db->prepare("
                INSERT INTO branches (restaurant_id, name, name_en, slug, address, phone, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ")->execute([$restaurantId, $name, $nameEn, $slug, $address, $phone]);
            $branchId = intval($this->db->lastInsertId());

            // نسخ الإعدادات من أول فرع موجود
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
                    $existing['shamcash_number']     ?? ''
                ]);
            } else {
                $this->db->prepare("INSERT INTO branch_settings (branch_id) VALUES (?)")
                    ->execute([$branchId]);
            }

            // نسخ الضرائب من أول فرع
            try {
                $taxes = $this->db->prepare("
                    SELECT name, name_en, type, value, is_active, sort_order
                    FROM branch_taxes 
                    WHERE branch_id = (
                        SELECT MIN(id) FROM branches 
                        WHERE restaurant_id = ? AND id != ?
                    )
                ");
                $taxes->execute([$restaurantId, $branchId]);
                foreach ($taxes->fetchAll() as $tax) {
                    $this->db->prepare("
                        INSERT INTO branch_taxes 
                            (branch_id, name, name_en, type, value, is_active, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $branchId, 
                        $tax['name'], $tax['name_en'], 
                        $tax['type'], $tax['value'], 
                        $tax['is_active'], $tax['sort_order']
                    ]);
                }
            } catch (\Exception $e) {
                // جدول branch_taxes ممكن يكون فاضي — ما بيسبب مشكلة
            }

            $this->db->commit();
            return ['success' => true, 'id' => $branchId, 'message' => 'تم إنشاء الفرع بنجاح!'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('BranchService::create failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'فشل إنشاء الفرع: ' . $e->getMessage()];
        }
    }

    /**
     * تحديث بيانات فرع.
     */
    public function update(int $branchId, int $restaurantId, array $data): array
    {
        $name    = trim($data['name'] ?? '');
        $nameEn  = trim($data['name_en'] ?? '');
        $address = trim($data['address'] ?? '');
        $phone   = trim($data['phone'] ?? '');

        if (!$name) {
            return ['success' => false, 'message' => 'اسم الفرع مطلوب'];
        }

        $this->db->prepare("
            UPDATE branches 
            SET name = ?, name_en = ?, address = ?, phone = ?
            WHERE id = ? AND restaurant_id = ?
        ")->execute([$name, $nameEn, $address, $phone, $branchId, $restaurantId]);

        return ['success' => true, 'message' => 'تم تحديث الفرع!'];
    }

    /**
     * تفعيل/تعطيل فرع.
     */
    public function toggleActive(int $branchId, int $restaurantId): bool
    {
        $this->db->prepare("
            UPDATE branches 
            SET is_active = NOT is_active 
            WHERE id = ? AND restaurant_id = ?
        ")->execute([$branchId, $restaurantId]);
        return true;
    }

    /**
     * حذف فرع — ممنوع لو هو الفرع الوحيد.
     */
    public function delete(int $branchId, int $restaurantId): array
    {
        $count = $this->db->prepare("SELECT COUNT(*) FROM branches WHERE restaurant_id = ?");
        $count->execute([$restaurantId]);
        if ($count->fetchColumn() <= 1) {
            return ['success' => false, 'message' => 'لا يمكن حذف الفرع الوحيد'];
        }

        $this->db->prepare("DELETE FROM branches WHERE id = ? AND restaurant_id = ?")
            ->execute([$branchId, $restaurantId]);

        return ['success' => true, 'message' => 'تم حذف الفرع!'];
    }

    // ============================================================
    // BRANCH SETTINGS
    // ============================================================

    /**
     * تحديث إعدادات فرع (عملة، دفع، إلخ).
     */
    public function updateSettings(int $branchId, array $data): bool
    {
        $this->db->prepare("
            UPDATE branch_settings SET
                currency_code      = ?,
                currency_symbol    = ?,
                currency_symbol_en = ?,
                currency_decimals  = ?,
                welcome_message    = ?,
                welcome_message_en = ?,
                shamcash_enabled   = ?,
                shamcash_number    = ?
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

    /**
     * توليد slug فريد للفرع داخل المطعم.
     */
    private function generateSlug(string $name, int $restaurantId): string
    {
        // تحويل بسيط للعربي
        $slug = mb_strtolower(trim($name));
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/[^\p{L}\p{N}\-]/u', '', $slug);
        if (!$slug) $slug = 'branch';

        // ضمان التفرد
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

    /**
     * عدد الفروع لمطعم.
     */
    public function countForRestaurant(int $restaurantId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM branches WHERE restaurant_id = ?");
        $stmt->execute([$restaurantId]);
        return intval($stmt->fetchColumn());
    }
}