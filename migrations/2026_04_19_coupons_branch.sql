-- ============================================================
-- Migration: coupons.branch_id
-- Date:      2026-04-19
-- Purpose:   إضافة branch_id لجدول coupons ليدعم كوبونات per-branch
-- ============================================================
--
-- السيناريوهات المدعومة بعد الترحيل:
--   - branch_id = NULL  → كوبون عام (صالح لكل الفروع)
--   - branch_id = X     → كوبون خاص بفرع محدد
--
-- الكود في menu/validate_coupon.php + restaurant/dashboard/coupons.php
-- يعمل graceful fallback قبل تنفيذ هذا الترحيل (عبر SHOW COLUMNS check)
-- ============================================================

-- 1) إضافة العمود
ALTER TABLE coupons
    ADD COLUMN branch_id INT DEFAULT NULL AFTER restaurant_id;

-- 2) Index للأداء
ALTER TABLE coupons
    ADD INDEX idx_coupons_branch (restaurant_id, branch_id, is_active);

-- ============================================================
-- تحقق بعد التنفيذ:
--   SHOW COLUMNS FROM coupons LIKE 'branch_id';
--   SELECT id, code, restaurant_id, branch_id FROM coupons LIMIT 5;
-- ============================================================
