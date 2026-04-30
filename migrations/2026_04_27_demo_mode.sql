-- ============================================================
-- Migration: Demo Mode + Demo Restaurant Seed
-- Date: 2026-04-27
--
-- يضيف:
--   1. عمود is_demo على restaurants — يفعّل auto-progression للطلبات
--   2. مطعم Demo (slug='demo') مع فرع رئيسي + 3 فئات + 6 أطباق + موظفين
--   3. حساب login جاهز (demo@menupro.local / demo123) للمالك
--   4. حسابات staff جاهزة (waiter/kitchen/cashier — كلهن password: demo123)
--
-- آمن للتشغيل أكثر من مرة (idempotent عبر INSERT IGNORE + slug UNIQUE).
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

-- ===== 2. مطعم Demo =====
-- password hash لـ "demo123" — bcrypt cost 10
INSERT IGNORE INTO restaurants (
    name, slug, email, password, phone, logo, address,
    is_active, subscription_plan, is_demo,
    welcome_message, welcome_message_en,
    currency_code, currency_symbol, currency_decimals, currency_symbol_en,
    instagram, facebook, whatsapp
) VALUES (
    'Demo Restaurant', 'demo', 'demo@menupro.local',
    '$2y$10$bdnBJP.HEgebVNiRBPDO1.NUViC5DpL90eRQtTOnyJerEt8GVjita', -- demo123
    '+963000000000', NULL, 'دمشق - Demo Address',
    1, 'premium', 1,
    'مرحباً بك في Demo Restaurant 🎬<br>هذه نسخة تجريبية — كل الطلبات تتحدث تلقائياً',
    'Welcome to Demo Restaurant 🎬<br>This is a demo — orders auto-progress',
    'USD', '$', 2, '$',
    'https://instagram.com/menupro', 'https://facebook.com/menupro', '+963934609138'
);

SET @demo_rid := (SELECT id FROM restaurants WHERE slug = 'demo' LIMIT 1);

-- ===== 2.b mirror إلى restaurants_v2 + organizations =====
-- جداول _v2 (branches/categories_v2/dishes_v2/offers_v2) لها FK لـ restaurants_v2 المهجور.
-- وعمود restaurants_v2.organization_id NOT NULL → نضيف organization مطابق.
-- نشتغل فقط لو الجداول موجودة (idempotent بـ INSERT IGNORE).
SET @v2_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants_v2'
);
SET @org_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations'
);

-- 1. organization (لو الجدول موجود)
SET @sql := IF(@org_exists = 1,
    CONCAT('INSERT IGNORE INTO organizations (id, name, slug, is_active) VALUES (', @demo_rid, ', ''Demo Org'', ''demo-org'', 1)'),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. restaurants_v2 mirror (لو الجدول موجود)
SET @sql := IF(@v2_exists = 1,
    CONCAT('INSERT IGNORE INTO restaurants_v2 (id, organization_id, slug, name, is_active) VALUES (', @demo_rid, ', ', @demo_rid, ', ''demo'', ''Demo Restaurant'', 1)'),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== 3. فرع رئيسي =====
INSERT IGNORE INTO branches (
    restaurant_id, name, name_en, slug, address, phone, is_active
) VALUES (
    @demo_rid, 'الفرع الرئيسي', 'Main Branch', 'main', 'دمشق', '+963000000000', 1
);

SET @demo_bid := (SELECT id FROM branches WHERE restaurant_id = @demo_rid AND slug = 'main' LIMIT 1);

-- branch_settings (لو ما موجود)
INSERT IGNORE INTO branch_settings (branch_id, currency_code, currency_symbol, currency_decimals, currency_symbol_en)
VALUES (@demo_bid, 'USD', '$', 2, '$');

-- ===== 4. فئات =====
INSERT IGNORE INTO categories_v2 (restaurant_id, name, name_en, sort_order, is_active)
VALUES
    (@demo_rid, 'برغر', 'Burgers', 1, 1),
    (@demo_rid, 'بيتزا', 'Pizza', 2, 1),
    (@demo_rid, 'مشروبات', 'Drinks', 3, 1);

SET @cat_burgers := (SELECT id FROM categories_v2 WHERE restaurant_id = @demo_rid AND name = 'برغر' LIMIT 1);
SET @cat_pizza   := (SELECT id FROM categories_v2 WHERE restaurant_id = @demo_rid AND name = 'بيتزا' LIMIT 1);
SET @cat_drinks  := (SELECT id FROM categories_v2 WHERE restaurant_id = @demo_rid AND name = 'مشروبات' LIMIT 1);

-- ===== 5. أطباق =====
-- صور من Unsplash (CDN — لا تحميل محلي)
INSERT IGNORE INTO dishes_v2 (
    restaurant_id, category_id, name, name_en, description, description_en,
    price, image, prep_time, sort_order, is_active
) VALUES
    (@demo_rid, @cat_burgers, 'برغر كلاسيك', 'Classic Burger',
     'لحم بقري طازج · جبنة شيدر · صلصة سرية', 'Fresh beef · cheddar · secret sauce',
     8.50, 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=600&q=80', 12, 1, 1),

    (@demo_rid, @cat_burgers, 'برغر دجاج كرسبي', 'Crispy Chicken Burger',
     'دجاج مقرمش · خس · مايونيز', 'Crispy chicken · lettuce · mayo',
     7.00, 'https://images.unsplash.com/photo-1606755962773-d324e0a13086?w=600&q=80', 10, 2, 1),

    (@demo_rid, @cat_pizza, 'بيتزا مارغريتا', 'Margherita Pizza',
     'موزاريلا · صلصة طماطم · ريحان', 'Mozzarella · tomato · basil',
     12.00, 'https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=600&q=80', 15, 3, 1),

    (@demo_rid, @cat_pizza, 'بيتزا بيبروني', 'Pepperoni Pizza',
     'بيبروني · موزاريلا · صلصة', 'Pepperoni · mozzarella · sauce',
     14.00, 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=600&q=80', 15, 4, 1),

    (@demo_rid, @cat_drinks, 'كولا', 'Cola',
     'بارد · 330مل', 'Cold · 330ml',
     2.00, 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?w=600&q=80', 1, 5, 1),

    (@demo_rid, @cat_drinks, 'عصير برتقال طازج', 'Fresh Orange Juice',
     '100% طبيعي · معصور لحظياً', '100% natural · fresh-squeezed',
     3.50, 'https://images.unsplash.com/photo-1613478223719-2ab802602423?w=600&q=80', 2, 6, 1);

-- ===== 6. موظفين =====
-- كل الـ passwords = 'demo123' بنفس الـ hash
INSERT IGNORE INTO restaurant_staff (restaurant_id, branch_id, name, username, password, role, is_active)
VALUES
    (@demo_rid, @demo_bid, 'نادل تجريبي', 'demo_waiter',
     '$2y$10$bdnBJP.HEgebVNiRBPDO1.NUViC5DpL90eRQtTOnyJerEt8GVjita', 'waiter', 1),
    (@demo_rid, @demo_bid, 'مطبخ تجريبي', 'demo_kitchen',
     '$2y$10$bdnBJP.HEgebVNiRBPDO1.NUViC5DpL90eRQtTOnyJerEt8GVjita', 'kitchen', 1),
    (@demo_rid, @demo_bid, 'كاشير تجريبي', 'demo_cashier',
     '$2y$10$bdnBJP.HEgebVNiRBPDO1.NUViC5DpL90eRQtTOnyJerEt8GVjita', 'cashier', 1);

-- ===== ✓ انتهى =====
-- التحقق:
--   SELECT id, slug, name, is_demo FROM restaurants WHERE slug = 'demo';
--   SELECT * FROM dishes_v2 WHERE restaurant_id = @demo_rid;
