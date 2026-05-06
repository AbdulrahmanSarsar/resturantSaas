# CLAUDE.md — MenuPro SaaS

> **ملف السياق الرئيسي للمشروع.** Claude Code بيقراه تلقائياً ببداية كل جلسة.
> آخر تحديث: 7 مايو 2026

---

## 👤 عن المستخدم

- **الاسم:** عبدالرحمن
- **اللغة الرئيسية:** عربي (لهجة سورية) — **جاوب بالعربي دائماً إلا لو طلب غير هيك**
- **الدور:** Solo Founder + Full-Stack Developer
- **رقم الواتساب التسويقي:** +963934609138

### أسلوب العمل المفضّل
- **مباشر وعملي** — بدون تكرار الأسئلة اللي تأكدت منها سابقاً
- **لا يعيد شرح السياق** — بيفترض إنك فاهم
- **القرارات المعمارية إله** — Claude يقترح ويتحدى، لكن الكلمة الأخيرة لعبدالرحمن
- يفضّل **comprehensive migration** على incremental (قبل بتعقيد أكبر مقابل صفر ديون تقنية)
- بيرجع للمحادثات القديمة ويكمل منها — ما بيحب يبدأ من الصفر
- لما يشوف مشكلة بالإنتاج بيوقف الفيتشرز ويصلح البَغ أولاً
- يرفض تعقيدات landing page المبالغ فيها (3D scroll, heavy animations) ويفضّل محتوى تسويقي مباشر مع screenshots

---

## 🎯 نظرة عامة على المشروع

**MenuPro** — منصة SaaS متعددة المستأجرين لمنيو رقمي للمطاعم، تستهدف السوق السوري.

### Links
- **Domain:** `menu-pro.org`
- **Demo live:** https://menu-pro.org/menu/flame-bites
- **Dashboard:** https://menu-pro.org/restaurant/dashboard/
- **Admin:** https://menu-pro.org/admin/
- **GitHub:** `AbdulrahmanSarsar/restaurantSaas` (main branch)

### Hosting & Stack
- **Hosting:** Hostinger shared hosting
- **Stack:** PHP 8.3 + MySQL/MariaDB + Vanilla JS
- **Database:** `u689381734_menu_database`
- **Timezone:** +03:00 (Damascus)
- **Charset:** utf8mb4

### الميزات الأساسية
1. **QR Menu** — منيو رقمي عبر QR لكل طاولة/فرع
2. **AR 3D** — عرض الأطباق ثلاثي الأبعاد (`.glb` + `.usdz`) على iPhone + Android
3. **Real-time Ordering** — طلبات مباشرة مع إشعارات المطبخ والنادل
4. **Order Tracking** — تتبع حالة الطلب للزبون بالوقت الفعلي
5. **Invoice System** — فاتورة تفصيلية + دفع (نقدي / شام كاش)
6. **Rating System** — تقييم الطلب + النادل (نجوم)
7. **Multi-role Staff** — waiter / kitchen / cashier (كل دور بصفحته)
8. **Coupons & Offers** — كوبونات خصم + عروض (bxgy / combo)
9. **Analytics** — إحصائيات يومية/شهرية + مقارنة فروع + heatmap
10. **Multi-branch** — مطعم → فروع (كل فرع بإعداداته الخاصة)
11. **Multi-language** — عربي + إنجليزي
12. **Sold Out System** — علامة "نفذ اليوم" + reset تلقائي منتصف الليل

### التسعير (USD شهرياً للسوق السوري)
| الباقة | السعر | الميزات |
|--------|-------|---------|
| **Basic** | $25/شهر | منيو + QR + 30 طبق |
| **Advanced** | $45/شهر | + AR + تقييمات + إحصائيات |
| **Premium** | $70/شهر | + طلبات + كوبونات + موظفين + شام كاش |
| رسوم إعداد | $15 لمرة واحدة | |

---

## 🏗️ المعمارية

### التسلسل الهرمي (3 مستويات)
```
Organization (Chain Owner)
    └── Restaurant (Brand)
            └── Branch (Physical location)
                    └── Staff (waiter/kitchen/cashier)
                    └── Orders
```

**Multi-tenancy:** Shared DB + row-level scoping عبر `TenantMiddleware`.

### نمط الترحيل: Strangler Fig
- جداول `_v2` موجودة بالتوازي مع القديمة
- الترحيل تدريجي — ملف ملف
- **الحالة الحالية:** `orders` هو المصدر الوحيد + `branch_id` column أُضيف عليه
- `orders_v2` **مهجور** — لا تستخدمه

### القرارات المعمارية (ADRs)

| # | القرار | السبب |
|---|-------|-------|
| **ADR-001** | Shared DB + row-level scoping (لا DB-per-tenant) | Hostinger shared لا يدعم إلا DB واحد |
| **ADR-002** | Denormalized `org_id`, `restaurant_id` على orders | Performance — تجنب JOINs للـ analytics |
| **ADR-003** | Dishes على مستوى restaurant + `branch_dish_overrides` | 50 طبق بدل 500 لسلسلة بـ 10 فروع |
| **ADR-004** | Suffix `_v2` للجداول الجديدة | Coexistence أثناء الترحيل |
| **ADR-005** | Vanilla PHP (لا Laravel/Symfony) | Service layer يعطي 80% من الفوائد بـ 20% من التكلفة |

### نمط الـ URLs

| النمط | الوصف |
|-------|-------|
| `/menu/{slug}/{branch-slug}` | صفحة المنيو (branch-aware via QR) |
| `/menu/{slug}/{branch-slug}/dish/{id}` | صفحة طبق |
| `/menu/{slug}/{branch-slug}/cart` | السلة |
| `/menu/{slug}/{branch-slug}/order/{id}` | تتبع الطلب |
| `/menu/{slug}/{branch-slug}/invoice/{id}` | الفاتورة |
| `/menu/{slug}/{branch-slug}/rate/{id}` | التقييم |
| `/menu/{slug}` (legacy) | يعمل redirect لأول فرع نشط |
| `/restaurant/dashboard/` | داشبورد صاحب المطعم |
| `/restaurant/staff/` | صفحات الموظفين |
| `/admin/` | الأدمن الرئيسي |

---

## 📁 هيكل الملفات

```
public_html/
├── bootstrap.php                    ← Autoloader + Service Container + constants
├── config/
│   ├── app.php                     ← Constants only (BASE_URL, etc.)
│   └── database.php                ← $pdo connection
├── .htaccess                        ← UTF-8 URL support (Arabic slugs)
│
├── src/
│   ├── Middleware/
│   │   ├── AuthMiddleware.php      ← session + login/logout + CSRF
│   │   └── TenantMiddleware.php    ← row-level tenant scoping
│   ├── Services/
│   │   ├── OrderService.php        ← create + status transitions + payments
│   │   ├── ReportService.php       ← stats + branch comparison + hourly + staff
│   │   ├── DishService.php
│   │   ├── CategoryService.php
│   │   ├── BranchService.php       ← CRUD + getBySlug (fixed) + settings
│   │   └── MenuService.php         ← customer-facing queries
│   └── Helpers/
│       ├── PriceHelper.php         ← fmt_price()
│       └── FileUploader.php
│
├── menu/                            ← Customer-facing
│   ├── index.php                   ← branch-aware menu page
│   ├── dish.php                    ← single dish + AR + options
│   ├── cart.php                    ← writes orders with branch_id
│   ├── order_track.php             ← real-time tracking
│   ├── invoice.php                 ← invoice + payment
│   ├── rate_order.php              ← rating UI (order + waiter)
│   ├── validate_coupon.php
│   └── payment_callback.php
│
├── restaurant/
│   ├── login.php                   ← unified login (owner + staff)
│   ├── dashboard/
│   │   ├── index.php               ← main dashboard
│   │   ├── dishes.php              ← CRUD + options + offers
│   │   ├── categories.php
│   │   ├── orders.php              ← branch-filtered
│   │   ├── stats.php               ← branch-filtered
│   │   ├── reports.php             ← daily/monthly/compare tabs
│   │   ├── branches.php            ← branch CRUD + slug auto-gen
│   │   ├── branch_settings.php     ← per-branch (currency, shamcash, welcome)
│   │   ├── chain.php               ← chain owner analytics
│   │   ├── qr.php                  ← branch-aware QR generation
│   │   ├── taxes.php
│   │   ├── coupons.php
│   │   ├── offers.php
│   │   ├── ratings.php
│   │   ├── profile.php             ← restaurant + staff management
│   │   ├── plan_guard.php          ← plan feature gating
│   │   ├── sidebar.php             ← with branch switcher
│   │   └── api/
│   │       └── new_orders.php      ← polling endpoint
│   └── staff/
│       ├── login.php
│       ├── kitchen.php             ← branch-scoped
│       ├── waiter.php              ← branch-scoped + sound notifications
│       └── cashier.php             ← branch-scoped + payment recording
│
├── admin/
│   ├── login.php
│   ├── orders.php                  ← cross-restaurant view
│   ├── index.php
│   └── sidebar.php
│
└── assets/
    └── uploads/
        ├── dishes/                 ← images
        ├── models/                 ← .glb files (3D models)
        ├── models-ios/             ← .usdz files (iOS AR)
        ├── offers/
        ├── logos/
        └── shamcash/               ← payment QRs
```

---

## 💾 قاعدة البيانات

### الجداول الرئيسية (Primary — تُستخدم فعلياً)

| الجدول | الوصف | ملاحظات |
|--------|-------|---------|
| `orders` | **المصدر الوحيد للطلبات** | أُضيف `branch_id` column |
| `order_items` | عناصر الطلب | فيه `options` JSON |
| `order_status_log` | سجل حالات الطلب | |
| `order_ratings` | تقييمات الطلبات + النادل | `waiter_rating`, `waiter_id` |
| `dishes_v2` | للقراءة فقط (menu display) | NO discount/sold_out — في branch_dish_overrides |
| `categories_v2` | | |
| `dish_option_groups_v2` + `dish_option_values_v2` | options per dish | type: radio/checkbox |
| `offers_v2` + `offer_items_v2` | عروض bxgy + combo | `combo_price` column |
| `branch_dish_overrides` | سعر/توفر/discount/sold_out لكل فرع | |
| `branches` | الفروع | `id`, `restaurant_id`, `name`, `name_en`, `slug`, `address`, `phone`, `is_active` |
| `branch_settings` | إعدادات الفرع | ⚠️ **عندها `id` مستقل — سبب bug JOINs** |
| `branch_taxes` | ضرائب per branch | **الـ primary** (taxes.php + OrderService) |
| `restaurant_staff` | الموظفين | `branch_id` أُضيف |
| `restaurants` | | `slug`, `currency`, `subscription_plan`, `subscription_expiry` |
| `restaurant_taxes` | | legacy — fallback فقط في OrderService + cart.php |
| `coupons` | | `branch_id` أُضيف (graceful fallback للـ schema القديم) |
| `users` | chain owners + admins | |
| `dish_library` | reference data عام | |
| `social_links` | روابط التواصل per branch | |

### ⚠️ الجداول المهجورة (DO NOT USE)
- `orders_v2` — **مهجور** (foreign key constraints سببت مشاكل)
- `order_items_v2`
- `order_status_log_v2`
- `order_ratings_v2`
- `coupons_v2`
- `subscriptions_v2`

### Schema Snippets المهمة

**orders (relevant columns):**
```sql
id, restaurant_id, restaurant_order_number, table_number,
customer_name, customer_notes,
total_price, discount_amount, tax_amount, tax_details (JSON),
coupon_code, status, payment_status, payment_method, paid_at,
staff_id, cashier_id, created_at, updated_at,
branch_id   -- ← critical for branch filtering
```

**restaurant_staff:**
```sql
id, restaurant_id, branch_id (added), name, username, password,
role ENUM('waiter','kitchen','cashier'), is_active, created_at, staff_number
```

### البيانات الحالية (Flame Bites demo)

**Restaurant:** `restaurant_id = 5`, slug = `flame-bites`

**Branches:**
| id | name | slug |
|----|------|------|
| 5 | Flame Bites - الفرع الرئيسي | `main` |
| 6 | فرع المزة | `فرع-المزة` |

**Staff (is_active=1):**
| id | username | role | branch_id |
|----|----------|------|-----------|
| 1 | omr | waiter | 1 |
| 2 | sad | kitchen | 1 |
| 5 | amar | kitchen | 5 |
| 8 | abd | waiter | 5 |
| 13 | fadi | cashier | 5 |

---

## 🔐 نظام الجلسات (Sessions)

### Restaurant Owner Session
```php
$_SESSION['restaurant_id']
$_SESSION['restaurant_name']
$_SESSION['restaurant_plan']         // basic | advanced | premium
$_SESSION['active_branch_id']        // من sidebar switcher — يحدد الفرع المعروض
```

### Staff Session
```php
$_SESSION['staff_id']
$_SESSION['staff_role']              // waiter | kitchen | cashier
$_SESSION['staff_rest_id']           // restaurant_id
$_SESSION['staff_rest_name']
$_SESSION['staff_branch_id']         // من restaurant_staff.branch_id
```

### Admin Session
```php
$_SESSION['admin_id']
```

### Logout Pattern
```php
// كل الصفحات تستخدم نفس النمط:
if(isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// الرابط:
<a href="?logout=1">خروج</a>
```

---

## 🎨 نظام الباقات (plan_guard.php)

```php
PLAN_FEATURES = [
    'basic'    => ['menu'],
    'advanced' => ['menu', 'ar', 'ratings', 'stats'],
    'premium'  => ['menu', 'ar', 'ratings', 'stats', 'orders', 'coupons', 'staff', 'shamcash'],
];

PLAN_RANK = ['basic' => 1, 'advanced' => 2, 'premium' => 3];
```

### Functions
- `plan_has_feature(string $feature): bool` — يرجع true/false
- `plan_required(string $min_plan): void` — redirect إذا الباقة أقل
- `plan_show_upgrade_page(string $required, string $current): void`

### ⚠️ تحذير مهم
- `bootstrap.php` بيعرّف **الـ CONSTANTS فقط** (`PLAN_FEATURES`, `PLAN_RANK`)
- الـ **FUNCTIONS** موطنها `plan_guard.php` فقط
- الصفحات اللي بدها الفانكشنز لازم تعمل `require_once 'plan_guard.php'`
- **لا تعيد تعريف** الفانكشنز بـ bootstrap — `Cannot redeclare` error

---

## ✅ المراحل المنجزة

### Phase 0 — Foundation
- [x] Migration SQL لـ v2 tables
- [x] `bootstrap.php` + PSR-4 autoloader + service container
- [x] `AuthMiddleware` + `TenantMiddleware` (with CSRF support)
- [x] كل Services: Order/Report/Dish/Category/Branch/Menu
- [x] `PriceHelper` (single source للـ fmt_price)
- [x] `FileUploader`

### Phase 1–5 — Page Migrations
- [x] `cashier.php` (unified login + payment recording)
- [x] `dishes.php` + `categories.php` (via services)
- [x] `cart.php` (branch-aware + writes to orders with branch_id)
- [x] `kitchen.php` + `waiter.php` (branch-scoped)
- [x] Staff unified login (`restaurant/staff/login.php`)

### Phase 6 — Stats & Reports Branch Filter
- [x] `stats.php` (branch-filtered via `active_branch_id`)
- [x] `reports.php` (daily/monthly/compare tabs, branch-filtered)

### Phase 7 — Branch System
- [x] `branches.php` — CRUD + slug auto-generation
- [x] `sidebar.php` — branch switcher dropdown
- [x] `.htaccess` — UTF-8 URL support (`[^/]+` بدل `[a-zA-Z0-9-]+`)
- [x] `qr.php` — branch-aware QR generation
- [x] `branch_settings.php` — عملة + شام كاش + welcome per branch

### Phase 8 — Advanced Analytics
- [x] `chain.php` — chain owner dashboard (KPIs, branch comparison, hourly heatmap, top dishes, sparkline)
- [x] Branch filtering على staff pages عبر `restaurant_staff.branch_id`
- [x] Added `orders.branch_id` column migration
- [x] Added `restaurant_staff.branch_id` column migration

### Phase 11 — Customer-Facing
- [x] `menu/index.php` — branch-aware (reads dishes_v2 + branch_dish_overrides)
- [x] `menu/dish.php` — branch-aware + AR + options
- [x] `menu/cart.php` — writes orders with branch_id
- [x] `menu/order_track.php` — polling + ETA calculation
- [x] `menu/invoice.php` — multi-payment (cash/shamcash)
- [x] `menu/rate_order.php` — order + waiter rating

### Critical Bug Fixes

1. **BranchService.getBySlug** — `SELECT b.*, bs.*` خلى `branch_settings.id` يطغى على `branches.id`
   - **الحل:** `SELECT b.id AS id, b.col1, b.col2, ...` (explicit columns)
   - **تأثير:** طلبات من فرع المزة كانت تنحفظ بـ `branch_id=8` (وهو `branch_settings.id`) بدل 6 الصح

2. **plan_guard redeclare** — bootstrap كان يعرّف functions بدون `function_exists()` check
   - **الحل:** bootstrap يعرّف CONSTANTS فقط؛ functions في plan_guard.php

3. **Missing `?>` before DOCTYPE** — 500 errors عند تجميع PHP files من أجزاء
   - **الحل:** تأكد دائماً من closing `?>` قبل `<!DOCTYPE>`

4. **Arabic slug 404** — `.htaccess` بـ `[a-zA-Z0-9-]+` ما بيدعم العربية
   - **الحل:** `[^/]+` — أي character عدا `/`

5. **orders_v2 abandoned** — foreign key constraints سببت مشاكل
   - **الحل:** استخدام `orders` كمصدر وحيد + إضافة `branch_id` column

6. **Staff logout 404** — HTML كان يرسل `?logout=1` لكن PHP ما بيعالجه بعد الترحيل
   - **الحل:** إضافة handler `if(isset($_GET['logout']))` في كل صفحة staff

7. **Cashier role missing from validation** — `in_array($srole, ['waiter','kitchen'])` كانت بدون cashier
   - **الحل:** `['waiter','kitchen','cashier']`

---

## 🚧 المراحل المتبقية

### Phase 9 — Staff Management (الأولوية التالية)
- [ ] تعديل `profile.php` أو بناء `staff.php` لإضافة dropdown للفرع عند إضافة/تعديل موظف
- [ ] **حرج:** بدون هاد، أي موظف جديد بـ `branch_id = NULL` ويشوف كل الفروع

### Phase 10 — Features Enhancement ✅
- [x] `ratings.php` branch-aware
- [x] `coupons.php` — branch_id + graceful fallback
- [x] `branch_settings.php` fix missing branch_helper
- [x] `offers.php` — migrated to `offers_v2` (كان bug إنتاج)
- [x] `index.php` (dashboard home) — branch-aware + `dishes_v2`/`categories_v2`
- [x] `api/new_orders.php` — branch-aware (owner + staff)
- [x] OrderService + cart.php — tax fallback (`branch_taxes` → `restaurant_taxes`)

### Phase 11 — Per-branch Taxes ✅
- [x] `taxes.php` — per-branch UI (dropdown + branch badge)
- [x] Migration: `restaurant_taxes` → `branch_taxes` لكل فرع
- [x] Validation via `tax_belongs_to_restaurant` (ownership check)

### Phase 12 — Testing & Polish
- [ ] End-to-end testing شامل (طلب كامل من الزبون → مطبخ → نادل → كاشير)
- [ ] اختبار تعدد الفروع بكل السيناريوهات
- [ ] Performance testing
- [ ] Security audit

### Landing Page
- v7 هي النسخة المستقرة الحالية
- **يرفض** الأقسام التفاعلية المعقدة (3D, scroll storytelling, heavy animations)
- يفضّل محتوى تسويقي مباشر + screenshots حقيقية للمنتج + شهادات عملاء

### Marketing & Sales
- تسعير: $25 / $45 / $70 شهرياً + $15 setup fee
- السوق: سوريا (WhatsApp: +963934609138)
- Demo: https://menu.almanarsoft.com/menu/flame-bites

---

## ⚙️ مبادئ العمل (DOs & DON'Ts)

### ✅ افعل
- استخدم Services (`service('order')`, `service('branch')`) بدل `$pdo->prepare()` مباشر
- عند JOIN على `branch_settings`: استخدم `b.id AS id` + صرّح الأعمدة
- استخدم `$_SESSION['active_branch_id']` لفلترة الداشبورد
- استخدم `$_SESSION['staff_branch_id']` لفلترة صفحات الموظفين
- أضف `if (!function_exists('fmt_price'))` للـ helpers العامة
- تأكد من `?>` قبل أي `<!DOCTYPE>` في ملفات مركّبة
- اكتب بالعربي في التواصل مع عبدالرحمن
- كن مباشر وعملي — بدون over-explaining
- اقرأ الكود الحالي قبل التعديل (لا تفترض)
- استخدم `require_once __DIR__ . '/../../bootstrap.php'` للتحميل الموحد

### ❌ لا تفعل
- **لا تستخدم `orders_v2`** — مهجور كلياً
- **لا تعيد سؤال السكيما** إذا تأكد منها سابقاً — بيسبب إحباط
- **لا تستخدم `SELECT b.*, bs.*`** — دائماً صرّح بالأعمدة
- **لا تعرّف functions في `bootstrap.php`** — constants فقط
- **لا تنسى `function_exists()`** check
- **لا تقترح حلول incremental** عندما يطلب comprehensive
- **لا تضيف 3D/scroll storytelling** للـ landing page
- **لا تستخدم dual-write** — `orders` هو المصدر الوحيد
- **لا تستخدم `SELECT *`** مع JOINs — column collisions
- **لا تستخدم regex `[a-zA-Z]`** في `.htaccess` routes — استخدم `[^/]+`

---

## 🧪 أوامر مفيدة (Commands Reference)

```bash
# PHP lint
php -l path/to/file.php

# Find old patterns
grep -rn "orders_v2" --include="*.php"
grep -rn "session_start()" restaurant/

# Find branch-related files
find . -name "*branch*" -type f

# Find helper usage
grep -rn "fmt_price" --include="*.php"

# Git workflow
git status
git diff
git add -p
git commit -m "descriptive message"
git push origin main

# Check for missing bootstrap
grep -L "bootstrap.php" restaurant/dashboard/*.php
```

---

## 🔑 Key Classes & Methods Reference

### BranchService
- `getAllForRestaurant(int $restaurantId): array`
- `getById(int $branchId, int $restaurantId): ?array`
- `getBySlug(int $restaurantId, string $branchSlug): ?array` ⚠️ **uses `b.id AS id`**
- `getActiveBranches(int $restaurantId): array`
- `create(int $restaurantId, array $data): array`
- `update(int $branchId, int $restaurantId, array $data): bool`
- `toggleActive(int $branchId, int $restaurantId): bool`
- `delete(int $branchId, int $restaurantId): array`
- `updateSettings(int $branchId, array $data): bool`
- `generateSlug(string $name, int $restaurantId): string`

### OrderService
- `create(array $data): array` — returns order_id, order_number, total
- `updateStatus(int $orderId, string $newStatus, int $userId): bool`
- `recordPayment(int $orderId, string $method, int $cashierId): bool`
- `getKitchenOrders(): array`
- `getReadyOrders(): array`
- `getUnpaidOrders(?string $dateFilter = 'today'): array`
- `getOrderItems(int $orderId): array`

### ReportService
- `getTodayStats(int $restaurantId, ?int $branchId): array`
- `getDailyRevenue(int $restaurantId, int $days, ?int $branchId): array`
- `getMonthlyRevenue(int $restaurantId, int $months, ?int $branchId): array`
- `getBranchComparison(int $restaurantId, int $days = 30): array`
- `getHourlyDistribution(int $restaurantId, int $days = 30, ?int $branchId = null): array`
- `getStaffPerformance(int $restaurantId, int $days = 30, int $limit = 10): array`
- `getRestaurantTotals(int $restaurantId, int $days = 30): array`
- `getTopDishes(int $restaurantId, int $days, int $limit, ?int $branchId): array`

### MenuService
- `getRestaurantBySlug(string $slug): ?array`
- `getCategories(int $restaurantId): array`
- `getDishesForBranch(int $restaurantId, int $branchId): array`
- `getDishForBranch(int $dishId, int $restaurantId, int $branchId): ?array`
- `getOptionsForRestaurant(int $restaurantId): array`
- `getOptionsForDish(int $dishId): array`

---

## 🌐 Integration Notes

### AR 3D
- Format: `.glb` (Android/Web) + `.usdz` (iOS Quick Look)
- Location: `/assets/uploads/models/` + `/assets/uploads/models-ios/`
- `dishes_v2.has_model3d = 1` للأطباق مع AR
- `dishes_v2.model3d_file` + `dishes_v2.model3d_usdz`
- Uses `<model-viewer>` component with auto-rotate + camera-controls

### ShamCash Payment
- طريقة دفع سورية شائعة
- Toggle per branch (`branch_settings.shamcash_enabled`)
- رقم الحساب + QR image
- Flow: زبون يطلب → يستلم → يقيّم → فاتورة → اختيار دفع → شام كاش/نقدي
- **Postpay** — الدفع بعد استلام الأكل

### WhatsApp
- رقم التسويق: +963934609138
- يستخدم `https://wa.me/963934609138` في landing page

---

## 📊 Database Queries Patterns

### Branch filter pattern (reports)
```php
$active_branch_id = $_SESSION['active_branch_id'] ?? null;
$bf  = $active_branch_id ? "AND branch_id = ?"    : "";
$bfo = $active_branch_id ? "AND o.branch_id = ?"  : "";
$bp  = $active_branch_id ? [$active_branch_id]     : [];

$stmt = $pdo->prepare("
    SELECT ... FROM orders
    WHERE restaurant_id = ? $bf AND ...
");
$stmt->execute(array_merge([$rid], $bp));
```

### Staff branch filter
```php
$staff_branch_id = $_SESSION['staff_branch_id'] ?? null;
$branch_filter = $staff_branch_id ? "AND o.branch_id = ?" : "";
$params = $staff_branch_id ? [$rid, $staff_branch_id] : [$rid];
```

### BranchService.getBySlug — الـ pattern الصحيح
```sql
-- ✅ الطريقة الصحيحة
SELECT
    b.id AS id,
    b.restaurant_id, b.name, b.slug, b.is_active,
    bs.currency_code, bs.currency_symbol, bs.shamcash_enabled
FROM branches b
LEFT JOIN branch_settings bs ON bs.branch_id = b.id
WHERE b.restaurant_id = ? AND b.slug = ? AND b.is_active = 1

-- ❌ الطريقة الخاطئة (bs.id يطغى على b.id)
SELECT b.*, bs.* FROM branches b LEFT JOIN branch_settings bs ...
```

---

## 📝 Special Notes

1. **`branch_settings.id`** عمود مستقل، **مش** FK لـ `branches.id` — هو primary key للإعدادات
2. **ShamCash** طريقة دفع سورية شائعة — يدفع بعد استلام الفاتورة (postpay)
3. **Sound notifications** للنادل تستخدم Web Audio API (beep + 3 notes)
4. **Polling interval** للطلبات الجديدة: 10 ثواني
5. **ETA calculation** في order_track يأخذ بعين الاعتبار queue position + prep_time
6. **Table number via QR**: URL `?table=X` → readonly input
7. **Sold Out Reset**: Cron job (أو manual) ينفذ `UPDATE dishes_v2 SET sold_out=0 WHERE sold_out=1` منتصف الليل
8. **Coupon validation**: `menu/validate_coupon.php` endpoint
9. **Offer types**: `bxgy` (Buy X Get Y) + `combo` (combined price)
10. **Dish options**: 2 modes — `add` (price added) / `replace` (new total price)

---

## 📞 Migration State (18 April 2026)

**Recently resolved:**
- orders.branch_id column added ✅
- BranchService.getBySlug fix deployed ✅
- Order 67 wrong branch_id fixed (UPDATE SET branch_id=6) ✅
- orders.php dashboard now branch-filtered ✅

**Current blocker:** None — system is functional.

**Next session should:**
1. Ask what Abdulrahman wants to tackle next (usually follows priority: staff mgmt → ratings → testing)
2. Before coding, check current state of files being edited
3. Continue comprehensive approach (no incremental patches)

---

*هذا الملف هو الحقيقة الوحيدة (Single Source of Truth) للمشروع. كل جلسة جديدة تبدأ من هنا.*
