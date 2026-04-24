-- ============================================================
-- Migration: Per-branch order numbering
-- Date: 2026-04-24
--
-- Problem:
--   restaurant_order_number هو عداد على مستوى المطعم كلو.
--   لما عندك فرعين (مثلاً الرئيسي + المزة)، الأرقام بتتداخل:
--   طلب #1 رئيسي، #2 مزة، #3 رئيسي — مش منطقي للموظفين.
--
-- Solution:
--   إضافة branch_order_number: عداد مستقل لكل فرع.
--   كل فرع يبدأ من #1 ويكمل تسلسلياً.
--   restaurant_order_number يبقى للتوافق الخلفي.
-- ============================================================

-- 1) أضف العمود (idempotent — ما بيعيد الخطأ لو موجود)
SET @dbname = DATABASE();
SET @col_check = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'branch_order_number'
);
SET @sql = IF(@col_check = 0,
  'ALTER TABLE orders ADD COLUMN branch_order_number INT UNSIGNED NULL AFTER restaurant_order_number',
  'SELECT "branch_order_number exists" AS status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Index للأداء (lookup per branch سريع)
SET @idx_check = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_orders_branch_order_num'
);
SET @sql = IF(@idx_check = 0,
  'CREATE INDEX idx_orders_branch_order_num ON orders (branch_id, branch_order_number)',
  'SELECT "index exists" AS status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Backfill — إعطاء كل طلب قديم رقم per-branch
--    نرتب الطلبات القديمة حسب created_at ضمن كل فرع ونرقّمها 1,2,3...
UPDATE orders o
JOIN (
  SELECT id,
         ROW_NUMBER() OVER (PARTITION BY branch_id ORDER BY created_at ASC, id ASC) AS rn
  FROM orders
  WHERE branch_id IS NOT NULL
) t ON t.id = o.id
SET o.branch_order_number = t.rn
WHERE o.branch_order_number IS NULL
  AND o.branch_id IS NOT NULL;

-- التحقق النهائي
SELECT
  branch_id,
  COUNT(*) AS total,
  MIN(branch_order_number) AS min_num,
  MAX(branch_order_number) AS max_num
FROM orders
WHERE branch_id IS NOT NULL
GROUP BY branch_id;
