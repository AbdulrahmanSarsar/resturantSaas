-- ============================================================================
-- Comprehensive Data Migration
-- Date:  2026-04-20
-- Scope: offers, taxes, coupons
--
-- ⚠️  IMPORTANT: نفّذ الأقسام بالترتيب + راجع النتائج بين كل قسم.
--
-- Usage:
--   1) Backup الداتا أولاً:
--      mysqldump -u user -p db_name > backup_pre_migration.sql
--   2) نفّذ Section 0 (pre-flight checks) — يعطيك صورة قبل/بعد
--   3) نفّذ Sections 1-4 بالتسلسل
--   4) نفّذ Section 5 (post-flight verification)
--
-- Idempotent: كل الأقسام يمكن تشغيلها أكثر من مرة بدون ضرر.
-- ============================================================================


-- ============================================================================
-- SECTION 0 — Pre-flight Audit (قراءة فقط — ما يعدّل شي)
-- ============================================================================

-- 0.1 — عدد العروض بالجدولين
SELECT 'offers (legacy)' AS source, COUNT(*) AS total FROM offers
UNION ALL
SELECT 'offers_v2'       AS source, COUNT(*) AS total FROM offers_v2;

-- 0.2 — العروض اللي بـ offers بس (مش مرحّلة)
SELECT o.id, o.restaurant_id, o.name, o.is_active, o.created_at
FROM offers o
LEFT JOIN offers_v2 v2 ON v2.restaurant_id = o.restaurant_id AND v2.name = o.name
WHERE v2.id IS NULL;

-- 0.3 — عدد الضرائب
SELECT 'restaurant_taxes' AS source, COUNT(*) AS total FROM restaurant_taxes
UNION ALL
SELECT 'branch_taxes'     AS source, COUNT(*) AS total FROM branch_taxes;

-- 0.4 — الضرائب النشطة اللي ما لها مقابل في branch_taxes
SELECT rt.restaurant_id, rt.id AS tax_id, rt.name, rt.type, rt.value,
       (SELECT COUNT(*) FROM branches b WHERE b.restaurant_id = rt.restaurant_id) AS total_branches,
       (SELECT COUNT(*) FROM branch_taxes bt
        JOIN branches b ON b.id = bt.branch_id
        WHERE b.restaurant_id = rt.restaurant_id AND bt.name = rt.name) AS already_synced
FROM restaurant_taxes rt
WHERE rt.is_active = 1;

-- 0.5 — الموظفين بدون branch_id
SELECT id, restaurant_id, name, username, role, is_active
FROM restaurant_staff
WHERE branch_id IS NULL AND is_active = 1;

-- 0.6 — الكوبونات (قبل ترحيل branch_id)
SELECT COUNT(*) AS total_coupons,
       SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active,
       SUM(CASE WHEN expires_at IS NULL OR expires_at >= CURDATE() THEN 1 ELSE 0 END) AS valid
FROM coupons;


-- ============================================================================
-- SECTION 1 — Coupons: إضافة branch_id
-- ============================================================================

-- 1.1 — إضافة العمود (idempotent — يتجاهل إذا موجود)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'coupons'
      AND COLUMN_NAME = 'branch_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE coupons ADD COLUMN branch_id INT DEFAULT NULL AFTER restaurant_id',
    'SELECT ''coupons.branch_id already exists'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.2 — Index (idempotent)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'coupons'
      AND INDEX_NAME = 'idx_coupons_branch'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE coupons ADD INDEX idx_coupons_branch (restaurant_id, branch_id, is_active)',
    'SELECT ''idx_coupons_branch already exists'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ============================================================================
-- SECTION 2 — Offers: ترحيل offers → offers_v2
-- ============================================================================

-- 2.1 — نسخ العروض المفقودة من offers إلى offers_v2
--       (نستخدم (restaurant_id, name) كـ unique key للتطابق)
INSERT INTO offers_v2
    (restaurant_id, name, name_en, description, description_en,
     type, combo_price, image, is_active, sort_order)
SELECT
    o.restaurant_id, o.name, o.name_en, o.description, o.description_en,
    o.type, o.combo_price, o.image, o.is_active, o.sort_order
FROM offers o
LEFT JOIN offers_v2 v2
    ON v2.restaurant_id = o.restaurant_id AND v2.name = o.name
WHERE v2.id IS NULL;

-- 2.2 — ترحيل offer_items للعروض الجديدة
--       (نربط بالاسم لأن الـ IDs مختلفة بين الجدولين)
INSERT INTO offer_items_v2 (offer_id, dish_id, quantity, role)
SELECT v2.id, oi.dish_id, oi.quantity, oi.role
FROM offer_items oi
JOIN offers o      ON o.id = oi.offer_id
JOIN offers_v2 v2  ON v2.restaurant_id = o.restaurant_id AND v2.name = o.name
LEFT JOIN offer_items_v2 existing
    ON existing.offer_id = v2.id
    AND existing.dish_id = oi.dish_id
    AND existing.role    = oi.role
WHERE existing.id IS NULL;


-- ============================================================================
-- SECTION 3 — Taxes: ترحيل restaurant_taxes → branch_taxes لكل الفروع
-- ============================================================================

-- 3.1 — نسخ كل ضريبة نشطة لكل فرع في المطعم
--       (idempotent عبر LEFT JOIN check)
INSERT INTO branch_taxes
    (branch_id, name, name_en, type, value, is_active, sort_order)
SELECT
    b.id AS branch_id,
    rt.name, rt.name_en, rt.type, rt.value, rt.is_active, rt.sort_order
FROM restaurant_taxes rt
JOIN branches b ON b.restaurant_id = rt.restaurant_id
LEFT JOIN branch_taxes bt
    ON bt.branch_id = b.id AND bt.name = rt.name
WHERE rt.is_active = 1
  AND bt.id IS NULL;


-- ============================================================================
-- SECTION 4 — Staff: تحذير عن الموظفين بدون branch_id
-- ============================================================================

-- ⚠️  هذا ما يعدّل داتا — فقط يعرض القائمة.
--     الحل: ادخل على /restaurant/dashboard/profile.php وحدّد فرع لكل موظف.
--     (لا يمكن الاختيار التلقائي — قد يعطي صلاحيات خاطئة)

SELECT
    s.id AS staff_id, s.username, s.role,
    s.restaurant_id, r.name AS restaurant_name,
    (SELECT GROUP_CONCAT(b.name SEPARATOR ', ')
     FROM branches b
     WHERE b.restaurant_id = s.restaurant_id AND b.is_active = 1) AS available_branches
FROM restaurant_staff s
JOIN restaurants r ON r.id = s.restaurant_id
WHERE s.branch_id IS NULL AND s.is_active = 1
ORDER BY s.restaurant_id, s.id;


-- ============================================================================
-- SECTION 5 — Post-flight Verification
-- ============================================================================

-- 5.1 — تأكد إن كل العروض صارت بـ offers_v2
SELECT 'offers not migrated' AS issue, COUNT(*) AS cnt
FROM offers o
LEFT JOIN offers_v2 v2 ON v2.restaurant_id = o.restaurant_id AND v2.name = o.name
WHERE v2.id IS NULL;

-- 5.2 — تأكد إن كل الضرائب صارت per-branch
SELECT
    rt.restaurant_id,
    rt.name AS tax_name,
    COUNT(DISTINCT b.id) AS total_branches,
    COUNT(DISTINCT bt.id) AS covered_branches,
    COUNT(DISTINCT b.id) - COUNT(DISTINCT bt.id) AS missing
FROM restaurant_taxes rt
JOIN branches b ON b.restaurant_id = rt.restaurant_id
LEFT JOIN branch_taxes bt ON bt.branch_id = b.id AND bt.name = rt.name
WHERE rt.is_active = 1
GROUP BY rt.restaurant_id, rt.name
HAVING missing > 0;

-- 5.3 — التحقق من العمود الجديد في coupons
SHOW COLUMNS FROM coupons LIKE 'branch_id';


-- ============================================================================
-- ROLLBACK (استخدمه لو صار خطأ — كل قسم له rollback منفصل)
-- ============================================================================

-- Rollback Section 1:
--   ALTER TABLE coupons DROP COLUMN branch_id;
--   (الـ index بينحذف تلقائياً)

-- Rollback Section 2: ⚠️ لا يمكن التراجع بدون backup
--   لأن offers_v2 قد يحتوي على داتا أصلية + مرحّلة مختلطة.
--   استرجع من backup pre-migration.

-- Rollback Section 3: ⚠️ نفس الشي — branch_taxes قد يحتوي داتا أصلية.

-- ============================================================================
