<?php
/**
 * MenuPro — Order Service
 * 
 * Centralizes ALL order business logic that was scattered across:
 *   cart.php (order creation, tax calculation, coupon handling)
 *   kitchen.php (status updates)
 *   waiter.php (delivery marking)
 *   cashier.php (payment processing)
 * 
 * This is the single source of truth for order operations.
 * No other file should directly modify the orders table.
 */

namespace MenuPro\Services;

use PDO;
use MenuPro\Middleware\TenantMiddleware;

class OrderService
{
    private PDO $db;
    private TenantMiddleware $tenant;

    public function __construct(PDO $db, TenantMiddleware $tenant)
    {
        $this->db     = $db;
        $this->tenant = $tenant;
    }

    // ============================================================
    // Order Creation
    // ============================================================

    /**
     * Create a new order.
     * 
     * @param array $data [
     *   'branch_id'      => int,
     *   'table_number'   => string,
     *   'customer_name'  => string|null,
     *   'customer_notes' => string|null,
     *   'items'          => array of [id, qty, price, notes, options, is_offer, name, name_en],
     *   'coupon_code'    => string|null,
     *   'discount_amount'=> float,
     * ]
     * @return array ['order_id' => int, 'order_number' => int, 'total' => float]
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function create(array $data): array
    {
        // Validate required fields
        $branchId    = intval($data['branch_id'] ?? 0);
        $tableNumber = trim($data['table_number'] ?? '');
        $items       = $data['items'] ?? [];

        if (!$branchId || !$tableNumber || empty($items)) {
            throw new \InvalidArgumentException('Missing required fields: branch_id, table_number, items');
        }

        // Resolve restaurant_id and organization_id from branch
        $branch = $this->db->prepare("
            SELECT b.id, b.restaurant_id, r.organization_id
            FROM branches b
            JOIN restaurants_v2 r ON r.id = b.restaurant_id
            WHERE b.id = ? AND b.is_active = 1
        ");
        $branch->execute([$branchId]);
        $branch = $branch->fetch();

        if (!$branch) {
            throw new \RuntimeException('Branch not found or inactive');
        }

        $restaurantId  = $branch['restaurant_id'];
        $organizationId = $branch['organization_id'];

        // Calculate subtotal
        $subtotal = 0;
        $validatedItems = [];

        foreach ($items as $item) {
            if (!empty($item['is_offer'])) {
                // Offer item — trust the price (validated at offer creation)
                $itemPrice = floatval($item['price'] ?? 0);
                $itemQty   = max(1, intval($item['qty'] ?? 1));
                $subtotal += $itemPrice * $itemQty;

                $validatedItems[] = [
                    'dish_id'      => null,
                    'dish_name'    => $item['name'] ?? 'عرض',
                    'dish_name_en' => $item['name_en'] ?? ($item['name'] ?? 'Offer'),
                    'dish_price'   => $itemPrice,
                    'quantity'     => $itemQty,
                    'notes'        => $item['notes'] ?? '',
                    'options'      => !empty($item['options']) ? json_encode($item['options']) : null,
                ];
                continue;
            }

            // Regular dish — verify from database
            $dishId = intval($item['id'] ?? 0);
            if (!$dishId) continue;

            $dish = $this->db->prepare("
                SELECT d.*, 
                       bdo.price AS branch_price,
                       bdo.discount_percent AS branch_discount,
                       bdo.discount_active AS branch_discount_active,
                       bdo.is_available,
                       bdo.sold_out
                FROM dishes_v2 d
                LEFT JOIN branch_dish_overrides bdo ON bdo.dish_id = d.id AND bdo.branch_id = ?
                WHERE d.id = ? AND d.restaurant_id = ?
            ");
            $dish->execute([$branchId, $dishId, $restaurantId]);
            $dish = $dish->fetch();

            if (!$dish) continue;

            // Check availability
            if (isset($dish['is_available']) && !$dish['is_available']) continue;
            if (isset($dish['sold_out']) && $dish['sold_out']) continue;

            // Calculate the actual price (branch override → restaurant default)
            $basePrice = $dish['branch_price'] ?? $dish['price'];
            
            // Apply discount if active
            $discountPercent = $dish['branch_discount'] ?? $dish['discount_percent'] ?? 0;
            $discountActive  = $dish['branch_discount_active'] ?? $dish['discount_active'] ?? 0;
            
            if ($discountActive && $discountPercent > 0) {
                $basePrice = $basePrice * (1 - $discountPercent / 100);
            }

            // Price protection: client-submitted price can't exceed DB price
            $clientPrice = isset($item['price']) ? floatval($item['price']) : $basePrice;
            $finalPrice  = min($clientPrice, floatval($dish['price'])); // Never exceed original
            $finalPrice  = max($finalPrice, 0); // Never negative

            $itemQty = max(1, intval($item['qty'] ?? 1));
            $subtotal += $finalPrice * $itemQty;

            $validatedItems[] = [
                'dish_id'      => $dish['id'],
                'dish_name'    => $dish['name'],
                'dish_name_en' => $dish['name_en'] ?? '',
                'dish_price'   => round($finalPrice, 2),
                'quantity'     => $itemQty,
                'notes'        => $item['notes'] ?? '',
                'options'      => !empty($item['options']) ? json_encode($item['options']) : null,
            ];
        }

        if (empty($validatedItems)) {
            throw new \InvalidArgumentException('No valid items in order');
        }

        // Apply coupon discount
        $couponCode    = trim($data['coupon_code'] ?? '');
        $discountAmount = floatval($data['discount_amount'] ?? 0);
        $afterDiscount  = max(0, $subtotal - $discountAmount);

        // Calculate taxes — branch_taxes أولاً، fallback لـ restaurant_taxes
        // (للتوافق مع المطاعم اللي ما رحّلت ضرائبها لكل فرع بعد)
        $activeTaxes = [];
        if ($branchId) {
            $taxes = $this->db->prepare("
                SELECT * FROM branch_taxes
                WHERE branch_id = ? AND is_active = 1
                ORDER BY sort_order
            ");
            $taxes->execute([$branchId]);
            $activeTaxes = $taxes->fetchAll();
        }
        if (empty($activeTaxes)) {
            $taxes = $this->db->prepare("
                SELECT * FROM restaurant_taxes
                WHERE restaurant_id = ? AND is_active = 1
                ORDER BY sort_order
            ");
            $taxes->execute([$restaurantId]);
            $activeTaxes = $taxes->fetchAll();
        }

        $taxAmount  = 0;
        $taxDetails = [];
        foreach ($activeTaxes as $tax) {
            $taxAmt = ($tax['type'] === 'percentage')
                ? ($afterDiscount * $tax['value'] / 100)
                : floatval($tax['value']);
            
            $taxAmount += $taxAmt;
            $taxDetails[] = [
                'name'    => $tax['name'],
                'name_en' => $tax['name_en'] ?? '',
                'type'    => $tax['type'],
                'value'   => $tax['value'],
                'amount'  => round($taxAmt, 2),
            ];
        }

        $grandTotal = round($afterDiscount + $taxAmount, 2);

        // ===== Insert order (transaction) =====
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                INSERT INTO orders_v2 (
                    branch_id, restaurant_id, organization_id,
                    table_number, customer_name, customer_notes,
                    subtotal, discount_amount, tax_amount, total_price,
                    tax_details, coupon_code, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $branchId, $restaurantId, $organizationId,
                $tableNumber,
                trim($data['customer_name'] ?? '') ?: null,
                trim($data['customer_notes'] ?? '') ?: null,
                round($subtotal, 2),
                round($discountAmount, 2),
                round($taxAmount, 2),
                $grandTotal,
                json_encode($taxDetails),
                $couponCode ?: null,
            ]);
            $orderId = intval($this->db->lastInsertId());

            // Insert order items
            $itemStmt = $this->db->prepare("
                INSERT INTO order_items_v2 
                    (order_id, dish_id, dish_name, dish_name_en, dish_price, quantity, notes, options)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($validatedItems as $vi) {
                $itemStmt->execute([
                    $orderId,
                    $vi['dish_id'],
                    $vi['dish_name'],
                    $vi['dish_name_en'],
                    $vi['dish_price'],
                    $vi['quantity'],
                    $vi['notes'],
                    $vi['options'],
                ]);
            }

            // Log initial status
            $this->db->prepare("
                INSERT INTO order_status_log_v2 (order_id, status, changed_at)
                VALUES (?, 'pending', NOW())
            ")->execute([$orderId]);

            // Increment coupon usage
            if ($couponCode) {
                $this->db->prepare("
                    UPDATE coupons_v2 SET used_count = used_count + 1 
                    WHERE code = ? AND branch_id = ?
                ")->execute([$couponCode, $branchId]);
            }

            $this->db->commit();

            // Get the generated order number
            $orderNum = $this->db->prepare("SELECT order_number FROM orders_v2 WHERE id = ?");
            $orderNum->execute([$orderId]);
            $orderNumber = $orderNum->fetchColumn();

            return [
                'order_id'     => $orderId,
                'order_number' => $orderNumber,
                'total'        => $grandTotal,
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Failed to create order: ' . $e->getMessage());
        }
    }

    // ============================================================
    // Status Updates
    // ============================================================

    /**
     * Allowed status transitions to prevent invalid state changes.
     */
    private const STATUS_TRANSITIONS = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready'     => ['delivered', 'cancelled'],
        'delivered' => [],  // Terminal state
        'cancelled' => [],  // Terminal state
    ];

    /**
     * Update order status with validation.
     * 
     * @param int    $orderId   Order ID
     * @param string $newStatus Target status
     * @param int|null $userId  User making the change
     * @return bool Success
     * @throws \InvalidArgumentException on invalid transition
     */
    public function updateStatus(int $orderId, string $newStatus, ?int $userId = null): bool
    {
        $order = $this->tenant->scopedQueryOne(
            "SELECT id, status FROM orders_v2 WHERE {BRANCH_SCOPE} AND id = ?",
            [$orderId]
        );

        if (!$order) {
            throw new \InvalidArgumentException('Order not found or access denied');
        }

        $currentStatus = $order['status'];
        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Cannot transition from '{$currentStatus}' to '{$newStatus}'"
            );
        }

        $this->db->prepare("
            UPDATE orders_v2 SET status = ?, updated_at = NOW() WHERE id = ?
        ")->execute([$newStatus, $orderId]);

        $this->db->prepare("
            INSERT INTO order_status_log_v2 (order_id, status, changed_by, changed_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([$orderId, $newStatus, $userId]);

        return true;
    }

    // ============================================================
    // Payment Processing (replaces cashier.php inline logic)
    // ============================================================

    /**
     * Record payment for a delivered order.
     * 
     * @param int    $orderId  Order ID
     * @param string $method   Payment method (cash, card, shamcash)
     * @param int    $cashierId Cashier user ID
     * @return bool Success
     */
    public function recordPayment(int $orderId, string $method, int $cashierId): bool
    {
        $validMethods = ['cash', 'card', 'shamcash'];
        if (!in_array($method, $validMethods, true)) {
            throw new \InvalidArgumentException('Invalid payment method: ' . $method);
        }

        // Verify order is delivered and unpaid, within tenant scope
        $order = $this->tenant->scopedQueryOne(
            "SELECT id FROM orders_v2 
             WHERE {BRANCH_SCOPE} AND id = ? AND status = 'delivered' AND payment_status = 'unpaid'",
            [$orderId]
        );

        if (!$order) {
            return false;
        }

        $this->db->prepare("
            UPDATE orders_v2 
            SET payment_status = 'paid', payment_method = ?, paid_at = NOW(), cashier_id = ?
            WHERE id = ?
        ")->execute([$method, $cashierId, $orderId]);

        return true;
    }

    // ============================================================
    // Queries (replace inline queries in kitchen.php, waiter.php)
    // ============================================================

    /**
     * Get active orders for kitchen display.
     */
    public function getKitchenOrders(): array
    {
        return $this->tenant->scopedQuery("
            SELECT o.*, 
                   COALESCE(o.order_number, o.id) AS display_num
            FROM orders_v2 o
            WHERE {BRANCH_SCOPE} 
              AND o.status IN ('pending', 'confirmed', 'preparing')
            ORDER BY 
                FIELD(o.status, 'pending', 'confirmed', 'preparing'),
                o.created_at ASC
        ");
    }

    /**
     * Get items for an order.
     */
    public function getOrderItems(int $orderId): array
    {
        return $this->db->prepare("
            SELECT * FROM order_items_v2 WHERE order_id = ? ORDER BY id
        ")->execute([$orderId]) 
            ? $this->db->prepare("SELECT * FROM order_items_v2 WHERE order_id = ? ORDER BY id")
                ->execute([$orderId]) 
            : [];
        
        // Cleaner version:
        $stmt = $this->db->prepare("SELECT * FROM order_items_v2 WHERE order_id = ? ORDER BY id");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Get ready orders for waiter display.
     */
    public function getReadyOrders(): array
    {
        return $this->tenant->scopedQuery("
            SELECT o.*, 
                   COALESCE(o.order_number, o.id) AS display_num
            FROM orders_v2 o
            WHERE {BRANCH_SCOPE} AND o.status = 'ready'
            ORDER BY o.created_at ASC
        ");
    }

    /**
     * Get unpaid delivered orders for cashier.
     */
    public function getUnpaidOrders(?string $dateFilter = 'today'): array
    {
        $dateClause = ($dateFilter === 'today') 
            ? "AND DATE(o.created_at) = CURDATE()" 
            : "";

        return $this->tenant->scopedQuery("
            SELECT o.*,
                   COALESCE(o.order_number, o.id) AS display_num
            FROM orders_v2 o
            WHERE {BRANCH_SCOPE}
              AND o.status = 'delivered'
              AND o.payment_status = 'unpaid'
              $dateClause
            ORDER BY o.created_at DESC
        ");
    }

    /**
     * Get order by ID with tenant scope.
     */
    public function getById(int $orderId): ?array
    {
        return $this->tenant->scopedQueryOne(
            "SELECT * FROM orders_v2 WHERE {BRANCH_SCOPE} AND id = ?",
            [$orderId]
        );
    }

    /**
     * Get order by legacy ID (for backward compatibility during migration).
     */
    public function getByLegacyId(int $legacyId): ?array
    {
        return $this->tenant->scopedQueryOne(
            "SELECT * FROM orders_v2 WHERE {BRANCH_SCOPE} AND legacy_order_id = ?",
            [$legacyId]
        );
    }
}