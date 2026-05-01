-- ============================================================
-- Migration: Demo Mode (يشتغل على DB الديمو فقط)
-- Date: 2026-04-27 (محدّث 2026-05-01)
--
-- شو يعمل:
--   1. يضيف عمود `is_demo` لـ restaurants (default 0)
--   2. UPDATE flame-bites: is_demo = 1
--      → يفعّل lazy progression على هذا المطعم
--      → بيظهر زي مطعم عادي (نفس الأطباق + الموظفين) لكن الطلبات تتحرك تلقائياً
--
-- ⚠️ هذا الـ migration يُشغّل **فقط** على DB الديمو (u628425673_demo).
--    لا تشغّله على DB الإنتاج (u628425673_menu_database).
--
-- آمن للتشغيل أكثر من مرة (idempotent).
-- ============================================================

-- ===== 1. عمود is_demo =====
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'is_demo'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE restaurants ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER subscription_expiry',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== 2. تفعيل demo على flame-bites =====
-- لو ما لقي flame-bites، الـ UPDATE فقط لا يفعل شيء (آمن).
-- subscription_plan = premium حتى تظهر كل ميزات النظام بالديمو.
UPDATE restaurants SET is_demo = 1, subscription_plan = 'premium' WHERE slug = 'flame-bites';

-- ===== ✓ التحقق =====
-- SELECT id, slug, name, is_demo, subscription_plan FROM restaurants WHERE is_demo = 1;
