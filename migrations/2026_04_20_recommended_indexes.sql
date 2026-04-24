-- ============================================================
-- MenuPro — Recommended indexes (Phase 12 performance)
-- Date: 2026-04-20
-- ------------------------------------------------------------
-- Idempotent: كل ADD INDEX محاط بـ prepared-statement check
-- على information_schema.STATISTICS لتجنّب "duplicate key" errors.
--
-- ⚠️ شغّلها على قاعدة البيانات الإنتاج مرة وحدة فقط.
-- ============================================================

DELIMITER //

DROP PROCEDURE IF EXISTS __add_index_if_missing //
CREATE PROCEDURE __add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_def   TEXT
)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    SELECT COUNT(*) INTO v_count
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = p_table
       AND INDEX_NAME   = p_index;
    IF v_count = 0 THEN
        SET @sql := CONCAT('ALTER TABLE `', p_table, '` ADD ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //

DELIMITER ;

-- ============================================================
--   orders — الأكثر استعلاماً؛ كل صفحة تسحب منه
-- ============================================================

-- تغطية Kitchen/Waiter queries: restaurant+branch+status, مرتّبة بـ id
CALL __add_index_if_missing(
    'orders', 'idx_orders_rest_branch_status',
    'INDEX idx_orders_rest_branch_status (restaurant_id, branch_id, status, id)'
);

-- تغطية Dashboard/Stats: restaurant+branch+created_at
CALL __add_index_if_missing(
    'orders', 'idx_orders_rest_branch_created',
    'INDEX idx_orders_rest_branch_created (restaurant_id, branch_id, created_at)'
);

-- تغطية Reports: restaurant+created_at+status
CALL __add_index_if_missing(
    'orders', 'idx_orders_rest_created_status',
    'INDEX idx_orders_rest_created_status (restaurant_id, created_at, status)'
);

-- تغطية Cashier unpaid: restaurant+branch+payment_status
CALL __add_index_if_missing(
    'orders', 'idx_orders_payment_status',
    'INDEX idx_orders_payment_status (restaurant_id, branch_id, status, payment_status)'
);

-- ============================================================
--   order_items — Batch IN() queries بعد fix الـ N+1
-- ============================================================

CALL __add_index_if_missing(
    'order_items', 'idx_order_items_order',
    'INDEX idx_order_items_order (order_id)'
);

CALL __add_index_if_missing(
    'order_items', 'idx_order_items_dish',
    'INDEX idx_order_items_dish (dish_id)'
);

-- ============================================================
--   branch_dish_overrides — UNIQUE لازم للـ ON DUPLICATE KEY UPDATE
-- ============================================================

-- UNIQUE (branch_id, dish_id) — مطلوب للـ UPSERT في DishService
CALL __add_index_if_missing(
    'branch_dish_overrides', 'uniq_bdo_branch_dish',
    'UNIQUE INDEX uniq_bdo_branch_dish (branch_id, dish_id)'
);

CALL __add_index_if_missing(
    'branch_dish_overrides', 'idx_bdo_dish',
    'INDEX idx_bdo_dish (dish_id)'
);

-- ============================================================
--   dishes_v2 — Menu display queries
-- ============================================================

CALL __add_index_if_missing(
    'dishes_v2', 'idx_dishes_v2_rest_active_sort',
    'INDEX idx_dishes_v2_rest_active_sort (restaurant_id, is_active, category_id, sort_order)'
);

-- ============================================================
--   categories_v2
-- ============================================================

CALL __add_index_if_missing(
    'categories_v2', 'idx_cat_v2_rest_active_sort',
    'INDEX idx_cat_v2_rest_active_sort (restaurant_id, is_active, sort_order)'
);

-- ============================================================
--   order_ratings
-- ============================================================

CALL __add_index_if_missing(
    'order_ratings', 'idx_ratings_order',
    'INDEX idx_ratings_order (order_id)'
);

CALL __add_index_if_missing(
    'order_ratings', 'idx_ratings_rest_created',
    'INDEX idx_ratings_rest_created (restaurant_id, created_at)'
);

CALL __add_index_if_missing(
    'order_ratings', 'idx_ratings_waiter',
    'INDEX idx_ratings_waiter (waiter_id, waiter_rating)'
);

-- ============================================================
--   restaurant_staff — login + branch filter
-- ============================================================

CALL __add_index_if_missing(
    'restaurant_staff', 'idx_staff_rest_branch_active',
    'INDEX idx_staff_rest_branch_active (restaurant_id, branch_id, is_active)'
);

CALL __add_index_if_missing(
    'restaurant_staff', 'idx_staff_username',
    'INDEX idx_staff_username (username)'
);

-- ============================================================
--   branches
-- ============================================================

CALL __add_index_if_missing(
    'branches', 'idx_branches_rest_active',
    'INDEX idx_branches_rest_active (restaurant_id, is_active)'
);

CALL __add_index_if_missing(
    'branches', 'idx_branches_rest_slug',
    'INDEX idx_branches_rest_slug (restaurant_id, slug)'
);

-- ============================================================
--   branch_taxes
-- ============================================================

CALL __add_index_if_missing(
    'branch_taxes', 'idx_btax_branch_active',
    'INDEX idx_btax_branch_active (branch_id, is_active, sort_order)'
);

-- ============================================================
--   dish options
-- ============================================================

CALL __add_index_if_missing(
    'dish_option_groups_v2', 'idx_dog_dish_sort',
    'INDEX idx_dog_dish_sort (dish_id, sort_order)'
);

CALL __add_index_if_missing(
    'dish_option_values_v2', 'idx_dov_group_sort',
    'INDEX idx_dov_group_sort (group_id, sort_order)'
);

-- ============================================================
--   coupons
-- ============================================================

CALL __add_index_if_missing(
    'coupons', 'idx_coupons_rest_code',
    'INDEX idx_coupons_rest_code (restaurant_id, code)'
);

CALL __add_index_if_missing(
    'coupons', 'idx_coupons_active_expiry',
    'INDEX idx_coupons_active_expiry (is_active, expires_at)'
);

-- ============================================================
--   restaurants — login lookup
-- ============================================================

CALL __add_index_if_missing(
    'restaurants', 'idx_rest_slug_active',
    'INDEX idx_rest_slug_active (slug, is_active)'
);

-- ============================================================
-- Clean up
-- ============================================================

DROP PROCEDURE IF EXISTS __add_index_if_missing;

-- Done. استعلم من جديد و EXPLAIN على الاستعلامات الأساسية للتأكد.
