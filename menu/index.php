<?php
/**
 * menu/index.php — صفحة المنيو (المرحلة 4 + 11)
 * 
 * MVC + branch-aware:
 *   - يقرأ branch_slug من URL
 *   - يستخدم BranchService.getBySlug للفرع
 *   - يقرأ من dishes_v2, categories_v2, dish_option_groups_v2, offers_v2
 *   - branch_dish_overrides للأسعار الخاصة بالفرع
 *   - كل الروابط تحفظ branch_slug
 */
require_once __DIR__ . '/../bootstrap.php';

$table_from_qr = intval($_GET['table'] ?? 0);
$slug          = $_GET['slug'] ?? '';
$branch_slug   = $_GET['branch'] ?? '';

if(!$slug) { http_response_code(404); die('الصفحة غير موجودة'); }

// ===== جلب المطعم =====
$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE slug = ? AND is_active = 1");
$stmt->execute([$slug]);
$restaurant = $stmt->fetch();

if(!$restaurant) { http_response_code(404); die('المطعم غير موجود'); }
if($restaurant['subscription_expiry'] && $restaurant['subscription_expiry'] < date('Y-m-d')) {
    die('انتهى اشتراك هذا المطعم');
}

$rid = $restaurant['id'];

// ===== flags للباقة (menu-only vs full ordering) =====
$can_order = restaurant_has_feature($rid, 'orders'); // basic/advanced = منيو فقط
$can_ar    = restaurant_has_feature($rid, 'ar');
$can_rate  = restaurant_has_feature($rid, 'ratings');

// ===== جلب الفرع (branch-aware) =====
/** @var \MenuPro\Services\BranchService $branchService */
$branchService = service('branch');

$branch = null;
if($branch_slug) {
    $branch = $branchService->getBySlug($rid, $branch_slug);
}

// لو ما في branch بالـ URL أو الـ slug غلط → خذ أول فرع نشط واعمل redirect
if(!$branch) {
    $active = $branchService->getActiveBranches($rid);
    if(empty($active)) {
        die('لا يوجد فروع نشطة لهذا المطعم');
    }
    $first = $active[0];
    $tableQuery = $table_from_qr ? '?table=' . $table_from_qr : '';
    header("Location: " . BASE_URL . "/menu/{$slug}/{$first['slug']}" . $tableQuery);
    exit;
}

$branch_id = intval($branch['id']);

// ===== العملة (من branch_settings لو موجودة، وإلا من المطعم) =====
$cur_symbol    = $branch['currency_symbol']     ?? $restaurant['currency_symbol']    ?? '$';
$cur_symbol_en = $branch['currency_symbol_en']  ?? $restaurant['currency_symbol_en'] ?? $cur_symbol;
$cur_decimals  = intval($branch['currency_decimals'] ?? $restaurant['currency_decimals'] ?? 2);
$cur_prefix    = in_array($cur_symbol,    ['$', '€', '₺']);
$cur_prefix_en = in_array($cur_symbol_en, ['$', '€', '₺']);

if(!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $symbol . ' ' . $formatted;
    }
}

$primary   = '#FF6B35';
$secondary = '#F7C59F';

// ===== الفئات (من categories_v2) =====
$categories = $pdo->prepare("
    SELECT * FROM categories_v2 
    WHERE restaurant_id = ? AND is_active = 1 
    ORDER BY sort_order
");
$categories->execute([$rid]);
$categories = $categories->fetchAll();

// ===== الأطباق (من dishes_v2 + branch_dish_overrides للسعر) =====
// ملاحظة: dishes_v2 ما فيها is_available / discount / sold_out
//         is_active بدل is_available — والباقي كله في branch_dish_overrides
$dishes = $pdo->prepare("
    SELECT 
        d.*,
        bdo.price            AS branch_price,
        bdo.discount_percent AS branch_discount_percent,
        bdo.discount_active  AS branch_discount_active,
        bdo.is_available     AS branch_is_available,
        bdo.sold_out         AS branch_sold_out
    FROM dishes_v2 d
    LEFT JOIN branch_dish_overrides bdo 
        ON bdo.dish_id = d.id AND bdo.branch_id = ?
    WHERE d.restaurant_id = ? 
      AND d.is_active = 1
      AND COALESCE(bdo.is_available, 1) = 1
    ORDER BY d.category_id, d.sort_order
");
$dishes->execute([$branch_id, $rid]);
$all_dishes = $dishes->fetchAll();

// dishes_v2 ما فيها discount/sold_out — كلهن بـ branch_dish_overrides
foreach($all_dishes as &$d) {
    $d['discount_percent'] = $d['branch_discount_percent'] !== null 
        ? floatval($d['branch_discount_percent']) : 0.0;
    $d['discount_active']  = $d['branch_discount_active']  !== null 
        ? intval($d['branch_discount_active'])  : 0;
    $d['sold_out']         = $d['branch_sold_out']         !== null 
        ? intval($d['branch_sold_out'])         : 0;
    if($d['branch_price'] !== null) {
        $d['price'] = $d['branch_price'];
    }
}
unset($d);

// ===== خيارات الأطباق (من dish_option_groups_v2) =====
// ملاحظة: dish_option_groups_v2 ما فيها restaurant_id — نربط عبر dishes_v2
$dishes_options = [];
$og_stmt = $pdo->prepare("
    SELECT og.id, og.dish_id, og.name, og.name_en, 
           og.type, og.price_mode, og.is_required, og.sort_order,
           GROUP_CONCAT(
               JSON_OBJECT('id',ov.id,'name',ov.name,'name_en',COALESCE(ov.name_en,''),'price',ov.price)
               ORDER BY ov.sort_order
           ) as values_json
    FROM dish_option_groups_v2 og
    INNER JOIN dishes_v2 d ON d.id = og.dish_id
    LEFT JOIN dish_option_values_v2 ov ON ov.group_id = og.id
    WHERE d.restaurant_id = ?
    GROUP BY og.id 
    ORDER BY og.sort_order
");
$og_stmt->execute([$rid]);
foreach($og_stmt->fetchAll() as $og) {
    $og['values'] = $og['values_json'] 
        ? (json_decode('[' . $og['values_json'] . ']', true) ?: [])
        : [];
    unset($og['values_json']);
    $dishes_options[$og['dish_id']][] = $og;
}

// تصنيف الأطباق حسب الفئة
$dishes_by_cat = [];
foreach($all_dishes as $dish) {
    $dishes_by_cat[$dish['category_id']][] = $dish;
}

// ===== العروض النشطة (من offers_v2) =====
$offers_stmt = $pdo->prepare("
    SELECT o.*,
        GROUP_CONCAT(
            JSON_OBJECT('dish_id',oi.dish_id,'quantity',oi.quantity,'role',oi.role,
                'dish_name',d.name,'dish_name_en',COALESCE(d.name_en,''),
                'dish_price',d.price,'dish_image',COALESCE(d.image,''))
            ORDER BY oi.id
        ) as items_json
    FROM offers_v2 o
    LEFT JOIN offer_items_v2 oi ON oi.offer_id = o.id
    LEFT JOIN dishes_v2 d ON d.id = oi.dish_id
    WHERE o.restaurant_id = ? AND o.is_active = 1
    GROUP BY o.id
    ORDER BY o.sort_order, o.id
");
$offers_stmt->execute([$rid]);
$active_offers = $offers_stmt->fetchAll();
foreach($active_offers as &$of) {
    $of['items'] = $of['items_json'] ? json_decode('['. $of['items_json'] .']', true) : [];
    unset($of['items_json']);
}
unset($of);

$has_social = $restaurant['instagram'] || $restaurant['facebook'] ||
              $restaurant['whatsapp']  || $restaurant['twitter']  ||
              $restaurant['tiktok'];

// متغيّر للروابط — يحفظ branch_slug
$branch_url = $branch['slug'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?= htmlspecialchars($restaurant['name']) ?> — <?= htmlspecialchars($branch['name']) ?></title>
    <meta name="theme-color" content="#080808">
    <script>
        (function(){
            var t = localStorage.getItem('menu_theme_<?= $slug ?>') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,700;0,9..144,900;1,9..144,300&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <?php if($table_from_qr): ?>
    <script>sessionStorage.setItem('table_<?= $slug ?>', '<?= $table_from_qr ?>');</script>
    <?php endif; ?>
    <style>
        :root { --p: <?= $primary ?>; --s: <?= $secondary ?>; }
        [data-theme="dark"]  { --bg:#080808;--bg2:#111111;--bg3:#1a1a1a;--bg4:#222222;--ink:#F5F0E8;--ink2:#6B6560;--ink3:#2E2B28;--line:rgba(245,240,232,0.06);--bar-bg:rgba(8,8,8,0.94);--hero-fade:rgba(8,8,8,0.92);--img-fade:rgba(17,17,17,0.55);}
        [data-theme="light"] { --bg:#F7F5F2;--bg2:#FFFFFF;--bg3:#EEEAE6;--bg4:#E4E0DC;--ink:#1A1714;--ink2:#7A7570;--ink3:#B8B4B0;--line:rgba(26,23,20,0.08);--bar-bg:rgba(247,245,242,0.94);--hero-fade:rgba(247,245,242,0.88);--img-fade:rgba(255,255,255,0.45);}
        body, .sticky-header, .summary-card, .dish-card, .welcome-strip, .social-bar, .add-toast, .cart-bar-inner { transition: background 0.3s, border-color 0.3s, color 0.3s; }
        .cart-bar-inner { display: flex; align-items: center; background: var(--ink); border-radius: 50px; overflow: hidden; height: 52px; box-shadow: 0 12px 40px rgba(0,0,0,0.7), 0 2px 8px rgba(0,0,0,0.5); min-width: 240px; }
        .cart-bar-text { display: flex; align-items: center; gap: 8px; padding: 0 18px 0 14px; color: var(--bg); font-size: 13px; font-weight: 800; white-space: nowrap; flex: 1; }
        .cart-bar-text svg { width: 17px; height: 17px; }
        .cart-bar-pill { background: var(--p); color: #fff; height: 52px; padding: 0 18px; display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 800; border-radius: 0 50px 50px 0; }
        [dir='ltr'] .cart-bar-pill { border-radius: 50px 0 0 50px; }
        .cart-count-bubble { background: rgba(255,255,255,0.25); width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; }
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { scroll-behavior: smooth; }
        body { font-family: 'Tajawal', sans-serif; background: var(--bg); color: var(--ink); max-width: 480px; margin: 0 auto; min-height: 100vh; overflow-x: hidden; }
        body::after { content: ''; position: fixed; inset: 0; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E"); pointer-events: none; z-index: 9999; }
        .progress-bar { position: fixed; top: 0; left: 0; height: 2px; background: linear-gradient(90deg, var(--p), var(--s)); z-index: 10000; width: 0%; transition: width 0.08s linear; box-shadow: 0 0 10px var(--p); }
        .hero { position: relative; height: 260px; overflow: hidden; background: var(--bg2); }
        .hero-orb { position: absolute; border-radius: 50%; filter: blur(60px); animation: orbDrift 10s ease-in-out infinite; }
        .hero-orb-1 { width: 300px; height: 300px; background: var(--p); opacity: 0.2; top: -100px; right: -60px; animation-duration: 12s; }
        .hero-orb-2 { width: 200px; height: 200px; background: var(--s); opacity: 0.15; bottom: -60px; left: -40px; animation-duration: 9s; animation-direction: reverse; }
        .hero-orb-3 { width: 150px; height: 150px; background: var(--p); opacity: 0.1; top: 50%; left: 40%; animation-duration: 15s; }
        @keyframes orbDrift { 0%,100% { transform: translate(0,0) scale(1); } 33% { transform: translate(20px,-15px) scale(1.08); } 66% { transform: translate(-10px,20px) scale(0.95); } }
        .hero-grid { position: absolute; inset: 0; background-image: linear-gradient(var(--line) 1px, transparent 1px), linear-gradient(90deg, var(--line) 1px, transparent 1px); background-size: 40px 40px; }
        .hero-content { position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: flex-end; padding: 24px 20px; background: linear-gradient(to top, var(--hero-fade) 0%, rgba(0,0,0,0.15) 50%, transparent 100%); }
        .hero-top { position: absolute; top: 20px; right: 20px; left: 20px; display: flex; align-items: flex-start; justify-content: space-between; }
        .restaurant-logo { width: 56px; height: 56px; border-radius: 16px; object-fit: cover; border: 1.5px solid rgba(255,255,255,0.1); box-shadow: 0 8px 24px rgba(0,0,0,0.6); }
        .logo-placeholder { width: 56px; height: 56px; border-radius: 16px; background: linear-gradient(135deg, var(--p), var(--s)); display: flex; align-items: center; justify-content: center; font-size: 26px; box-shadow: 0 8px 24px rgba(0,0,0,0.5); }
        .hero-badges { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
        .open-badge { display: flex; align-items: center; gap: 5px; background: rgba(74,222,128,0.12); border: 1px solid rgba(74,222,128,0.25); padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; color: #4ADE80; backdrop-filter: blur(8px); }
        .open-dot { width: 5px; height: 5px; background: #4ADE80; border-radius: 50%; box-shadow: 0 0 6px #4ADE80; animation: blink 2s infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }
        .table-badge, .branch-badge { display: flex; align-items: center; gap: 5px; background: rgba(245,240,232,0.08); border: 1px solid rgba(245,240,232,0.12); padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; color: var(--ink); backdrop-filter: blur(8px); }
        .branch-badge { background: color-mix(in srgb, var(--p) 15%, transparent); border-color: color-mix(in srgb, var(--p) 30%, transparent); color: var(--p); }
        .hero-bottom { position: relative; z-index: 2; }
        .restaurant-name { font-family: 'Fraunces', serif; font-size: 30px; font-weight: 900; color: var(--ink); line-height: 1.1; letter-spacing: -0.5px; margin-bottom: 7px; }
        .restaurant-meta { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .meta-item { display: flex; align-items: center; gap: 4px; font-size: 12px; color: rgba(245,240,232,0.5); font-weight: 500; }
        .welcome-strip { background: rgba(245,240,232,0.03); border-bottom: 1px solid var(--line); padding: 12px 20px; font-size: 13px; color: var(--ink2); line-height: 1.6; border-right: 2px solid var(--p); display: flex; align-items: flex-start; gap: 8px; }
        .social-bar { display: flex; gap: 6px; padding: 12px 16px; background: var(--bg2); border-bottom: 1px solid var(--line); overflow-x: auto; scrollbar-width: none; }
        .social-bar::-webkit-scrollbar { display: none; }
        .social-btn { display: flex; align-items: center; gap: 5px; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 11px; font-weight: 700; white-space: nowrap; font-family: 'Tajawal', sans-serif; transition: transform 0.15s, filter 0.15s; border: 1px solid rgba(255,255,255,0.06); }
        .social-btn svg { width: 13px; height: 13px; flex-shrink: 0; }
        .social-btn:active { transform: scale(0.9); }
        .social-instagram { background: linear-gradient(135deg,#833ab4,#fd1d1d,#fcb045); color:#fff; }
        .social-facebook  { background: #1877f2; color: #fff; }
        .social-whatsapp  { background: #25d366; color: #fff; }
        .social-tiktok    { background: #111; color: #fff; }
        .social-twitter   { background: #000; color: #fff; }
        .sticky-header { position: sticky; top: 0; z-index: 100; background: var(--bar-bg); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border-bottom: 1px solid var(--line); }
        .search-wrap { padding: 12px 16px 10px; }
        .search-inner { position: relative; }
        .search-input { width: 100%; padding: 11px 40px 11px 14px; background: var(--bg3); border: 1.5px solid var(--line); border-radius: 12px; color: var(--ink); font-size: 14px; font-family: 'Tajawal', sans-serif; font-weight: 500; transition: border-color 0.2s, background 0.2s, box-shadow 0.2s; direction: inherit; outline: none; }
        .search-input::placeholder { color: var(--ink2); }
        .search-input:focus { border-color: var(--p); background: var(--bg4); box-shadow: 0 0 0 3px color-mix(in srgb, var(--p) 12%, transparent); }
        .search-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--ink2); pointer-events: none; }
        .cats-nav { padding: 0 16px 12px; overflow-x: auto; white-space: nowrap; scrollbar-width: none; }
        .cats-nav::-webkit-scrollbar { display: none; }
        .cats-inner { display: inline-flex; gap: 6px; }
        .cat-btn { position: relative; padding: 7px 16px; border-radius: 20px; border: 1.5px solid var(--line); background: transparent; color: var(--ink2); font-size: 12px; font-weight: 700; cursor: pointer; font-family: 'Tajawal', sans-serif; transition: all 0.25s cubic-bezier(0.34,1.3,0.64,1); white-space: nowrap; overflow: hidden; }
        .cat-btn::before { content: ''; position: absolute; inset: 0; background: var(--p); transform: scale(0); transition: transform 0.25s cubic-bezier(0.34,1.3,0.64,1); }
        .cat-btn span { position: relative; z-index: 1; }
        .cat-btn.active { border-color: var(--p); color: #fff; box-shadow: 0 0 18px color-mix(in srgb, var(--p) 28%, transparent); }
        .cat-btn.active::before { transform: scale(1); }
        .cat-btn:not(.active):active { transform: scale(0.92); }
        .menu-content { padding: 20px 16px 130px; }
        .cat-section { margin-bottom: 32px; }
        .cat-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; padding: 0 2px; }
        .cat-label-wrap { display: flex; align-items: baseline; gap: 7px; }
        .cat-label { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 700; color: var(--ink); letter-spacing: -0.3px; }
        .cat-count { font-size: 11px; font-weight: 700; color: var(--ink2); }
        .cat-line { flex: 1; height: 1px; background: linear-gradient(to left, transparent, var(--line) 60%); }
        .dish-card { position: relative; display: block; text-decoration: none; color: inherit; border-radius: 18px; overflow: hidden; margin-bottom: 10px; background: var(--bg2); border: 1px solid var(--line); transition: transform 0.22s cubic-bezier(0.34,1.2,0.64,1), box-shadow 0.22s; animation: cardIn 0.5s cubic-bezier(0.22,1,0.36,1) both; }
        .dish-card:nth-child(1) { animation-delay: 0.03s; }
        .dish-card:nth-child(2) { animation-delay: 0.07s; }
        .dish-card:nth-child(3) { animation-delay: 0.11s; }
        .dish-card:nth-child(4) { animation-delay: 0.15s; }
        .dish-card:nth-child(5) { animation-delay: 0.19s; }
        .dish-card:nth-child(6) { animation-delay: 0.23s; }
        @keyframes cardIn { from { opacity: 0; transform: translateY(22px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .dish-card:active { transform: scale(0.975); box-shadow: 0 8px 30px rgba(0,0,0,0.5); }
        @media (hover:hover) { .dish-card:hover { transform: translateY(-3px); box-shadow: 0 12px 36px rgba(0,0,0,0.4); border-color: rgba(255,255,255,0.11); } }
        .dish-inner { display: flex; min-height: 120px; }
        .dish-img-col { position: relative; width: 120px; min-width: 120px; overflow: hidden; }
        .dish-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s cubic-bezier(0.25,1,0.5,1); display: block; }
        .dish-card:active .dish-img, .dish-card:hover .dish-img { transform: scale(1.07); }
        .dish-img-col::after { content: ''; position: absolute; inset: 0; background: linear-gradient(to left, var(--img-fade) 0%, transparent 45%); }
        .dish-img-placeholder { width: 100%; height: 100%; min-height: 120px; display: flex; align-items: center; justify-content: center; font-size: 38px; background: var(--bg3); }
        .badge-ar { position: absolute; top: 8px; right: 8px; z-index: 2; background: rgba(167,139,250,0.18); backdrop-filter: blur(8px); border: 1px solid rgba(167,139,250,0.3); color: #C4B5FD; padding: 3px 7px; border-radius: 6px; font-size: 9px; font-weight: 800; letter-spacing: 0.5px; }
        .dish-content-col { flex: 1; padding: 14px 14px 12px 16px; display: flex; flex-direction: column; justify-content: space-between; min-width: 0; }
        .dish-name { font-family: 'Fraunces', serif; font-size: 15px; font-weight: 700; color: var(--ink); line-height: 1.25; letter-spacing: -0.2px; margin-bottom: 5px; }
        .dish-desc { font-size: 12px; color: var(--ink2); line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; flex: 1; }
        .dish-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; }
        .dish-price { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 900; color: var(--p); letter-spacing: -0.4px; }
        .dish-price-old { font-family: 'Fraunces', serif; font-size: 12px; font-weight: 600; color: var(--ink3); text-decoration: line-through; }
        .dish-price-wrap { display: flex; flex-direction: column; align-items: flex-start; gap: 1px; }
        .discount-badge-menu { display: inline-flex; align-items: center; gap: 3px; background: #EF4444; color: #fff; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 900; letter-spacing: 0.3px; box-shadow: 0 2px 8px rgba(239,68,68,0.4); }
        .add-btn { position: relative; width: 34px; height: 34px; border-radius: 10px; background: var(--p); border: none; color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: transform 0.2s cubic-bezier(0.34,1.56,0.64,1), background 0.2s, box-shadow 0.2s; box-shadow: 0 4px 14px color-mix(in srgb, var(--p) 35%, transparent); flex-shrink: 0; overflow: hidden; }
        .add-btn svg { width: 16px; height: 16px; transition: transform 0.2s; }
        .add-btn:active { transform: scale(0.8); }
        .add-btn.added { background: #22C55E; box-shadow: 0 4px 14px rgba(34,197,94,0.4); }
        .add-btn.added svg { transform: scale(0); }
        .add-btn-check { position: absolute; opacity: 0; transition: opacity 0.15s; font-size: 16px; }
        .add-btn.added .add-btn-check { opacity: 1; }
        .cart-bar { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%) translateY(120px); z-index: 500; transition: transform 0.5s cubic-bezier(0.34,1.4,0.64,1); border: none; background: none; padding: 0; cursor: pointer; font-family: 'Tajawal', sans-serif; }
        .cart-bar.visible { transform: translateX(-50%) translateY(0); }
        .no-results { display: none; text-align: center; padding: 70px 20px; }
        .no-results-box { width: 72px; height: 72px; border-radius: 20px; background: var(--bg3); display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; font-size: 30px; }
        .no-results p { font-size: 15px; color: var(--ink2); font-weight: 600; }
        .sold-out-card { opacity: 0.72; }
        .dish-opts-badge { position: absolute; bottom: 9px; right: 9px; z-index: 2; background: rgba(139,92,246,.85); backdrop-filter: blur(6px); color: #fff; padding: 3px 9px; border-radius: 20px; font-size: 9px; font-weight: 800; display: flex; align-items: center; gap: 3px; }
        .dish-opts-badge svg { width: 9px; height: 9px; }
        .opts-popup-overlay { position: fixed; inset: 0; z-index: 600; background: rgba(0,0,0,.6); backdrop-filter: blur(6px); display: flex; align-items: flex-end; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .3s; }
        .opts-popup-overlay.open { opacity: 1; pointer-events: all; }
        .opts-popup-sheet { width: 100%; max-width: 480px; background: var(--bg2); border-radius: 28px 28px 0 0; transform: translateY(100%); transition: transform .42s cubic-bezier(.22,1,.36,1); overflow: hidden; max-height: 85vh; overflow-y: auto; }
        .opts-popup-overlay.open .opts-popup-sheet { transform: translateY(0); }
        .opts-popup-handle { width: 36px; height: 4px; border-radius: 4px; background: var(--line); margin: 12px auto 0; }
        .opts-popup-head { padding: 14px 20px; border-bottom: 1px solid var(--line); }
        .opts-popup-dish-name { font-family: 'Fraunces', serif; font-size: 18px; font-weight: 900; color: var(--ink); margin-bottom: 3px; }
        .opts-popup-price { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 900; color: var(--p); }
        .opts-popup-body { padding: 16px 20px; }
        .popup-group { margin-bottom: 18px; }
        .popup-group-title { font-size: 13px; font-weight: 800; color: var(--ink); margin-bottom: 8px; display: flex; align-items: center; gap: 7px; }
        .popup-req-badge { font-size: 10px; font-weight: 700; color: #EF4444; background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.2); padding: 2px 7px; border-radius: 10px; }
        .popup-opt-badge { font-size: 10px; font-weight: 700; color: var(--ink2); background: var(--bg3); border: 1px solid var(--line); padding: 2px 7px; border-radius: 10px; }
        .popup-values { display: flex; flex-direction: column; gap: 7px; }
        .popup-value { display: flex; align-items: center; justify-content: space-between; padding: 11px 14px; background: var(--bg3); border: 1.5px solid var(--line); border-radius: 13px; cursor: pointer; transition: all .2s; }
        .popup-value:hover { border-color: var(--p); }
        .popup-value.selected { border-color: var(--p); background: color-mix(in srgb, var(--p) 7%, transparent); }
        .popup-value-left { display: flex; align-items: center; gap: 10px; }
        .popup-radio { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--line); background: var(--bg2); flex-shrink: 0; transition: all .2s; display: flex; align-items: center; justify-content: center; }
        .popup-radio-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--p); transform: scale(0); transition: transform .2s; }
        .popup-value.selected .popup-radio { border-color: var(--p); background: var(--p); }
        .popup-value.selected .popup-radio-dot { transform: scale(1); background: #fff; }
        .popup-value-name { font-size: 13px; font-weight: 600; color: var(--ink); }
        .popup-value-price { font-family: 'Fraunces', serif; font-size: 13px; font-weight: 700; }
        .popup-value-price.add { color: #22C55E; }
        .popup-value-price.replace { color: var(--p); }
        .popup-value-price.free { color: var(--ink2); font-size: 11px; }
        .opts-popup-footer { padding: 12px 20px 20px; border-top: 1px solid var(--line); position: sticky; bottom: 0; background: var(--bg2); }
        .opts-popup-add-btn { width: 100%; height: 52px; background: var(--p); color: #fff; border: none; border-radius: 16px; font-size: 15px; font-weight: 800; cursor: pointer; font-family: 'Tajawal', sans-serif; display: flex; align-items: center; justify-content: space-between; padding: 0 18px; box-shadow: 0 4px 18px color-mix(in srgb, var(--p) 35%, transparent); transition: opacity .2s; }
        .opts-popup-add-btn:active { opacity: .85; }
        .opts-popup-total { background: rgba(255,255,255,.2); padding: 5px 12px; border-radius: 20px; font-size: 13px; font-weight: 800; }
        .empty-state { text-align: center; padding: 80px 20px; }
        .empty-box { width: 80px; height: 80px; border-radius: 24px; background: var(--bg3); display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; }
        .empty-state p { font-size: 15px; color: var(--ink2); font-weight: 600; }
        .menu-footer { text-align: center; padding: 24px 20px 20px; color: var(--ink3); font-size: 12px; border-top: 1px solid var(--line); margin-top: 8px; font-weight: 600; }
        .footer-brand { display: inline-block; margin-top: 7px; font-size: 13px; font-weight: 800; background: linear-gradient(135deg, var(--p), var(--s)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .add-toast { position: fixed; bottom: 88px; left: 50%; transform: translateX(-50%) translateY(20px); background: var(--bg3); border: 1px solid var(--line); color: var(--ink); padding: 9px 16px; border-radius: 25px; font-size: 13px; font-weight: 700; z-index: 400; opacity: 0; transition: all 0.3s cubic-bezier(0.34,1.56,0.64,1); pointer-events: none; white-space: nowrap; backdrop-filter: blur(12px); max-width: 260px; overflow: hidden; text-overflow: ellipsis; }
        .lang-toggle { position: fixed; bottom: 88px; left: 66px; width: 42px; height: 42px; border-radius: 50%; background: var(--bg3); border: 1px solid var(--line); color: var(--ink2); display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 200; box-shadow: 0 4px 16px rgba(0,0,0,0.25); transition: background 0.2s, transform 0.2s; font-size: 12px; font-weight: 800; font-family: 'Tajawal', sans-serif; }
        .lang-toggle:active { transform: scale(0.9); }
        .lang-toggle.en-active { background: var(--p); color: #fff; border-color: transparent; }
        .dish-name-en { font-family: 'Fraunces', serif; font-size: 13px; font-weight: 400; color: var(--ink2); margin-top: 2px; display: none; font-style: italic; }
        body.lang-en .dish-name { display: none; }
        body.lang-en .dish-name-en { display: block; color: var(--ink); font-style: normal; font-size: 15px; font-weight: 700; }
        body.lang-en .dish-desc { display: none; }
        body.lang-en .dish-desc-en { display: block; }
        body.lang-en .cat-label { display: none; }
        body.lang-en .cat-label-en { display: block; }
        .dish-desc-en { font-size: 12px; color: var(--ink2); line-height: 1.55; display: none; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .cat-label-en { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 700; color: var(--ink); letter-spacing: -0.3px; display: none; }
        .theme-toggle { position: fixed; bottom: 88px; left: 16px; width: 42px; height: 42px; border-radius: 50%; background: var(--bg3); border: 1px solid var(--line); color: var(--ink2); display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 200; box-shadow: 0 4px 16px rgba(0,0,0,0.25); transition: background 0.2s, transform 0.3s, border-color 0.3s; }
        .theme-toggle:active { transform: rotate(25deg) scale(0.9); }
        .theme-toggle svg { width: 17px; height: 17px; }
        .icon-moon { display: block; }
        .icon-sun { display: none; }
        [data-theme="light"] .icon-moon { display: none; }
        [data-theme="light"] .icon-sun { display: block; }
        .offers-section { margin-bottom: 32px; }
        .offers-section-head { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; padding: 0 2px; }
        .offers-section-label { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 700; color: var(--ink); letter-spacing: -0.3px; }
        .offers-section-line { flex: 1; height: 1px; background: linear-gradient(to left, transparent, var(--line) 60%); }
        .offer-card-menu { background: var(--bg2); border: 1px solid var(--line); border-radius: 18px; overflow: hidden; margin-bottom: 10px; animation: cardIn .5s cubic-bezier(.22,1,.36,1) both; transition: transform .22s; }
        .offer-card-menu:active { transform: scale(0.975); }
        .offer-card-inner { display: flex; min-height: 110px; align-items: stretch; }
        .offer-img-col { width: 110px; min-width: 110px; background: linear-gradient(135deg, var(--p), var(--s)); display: flex; align-items: center; justify-content: center; font-size: 42px; position: relative; overflow: hidden; flex-shrink: 0; }
        .offer-img-col img { width:100%;height:100%;object-fit:cover; }
        .offer-type-badge-menu { position: absolute; bottom: 7px; right: 7px; padding: 3px 8px; border-radius: 12px; font-size: 9px; font-weight: 800; backdrop-filter: blur(8px); }
        .badge-bxgy { background: rgba(99,102,241,.88); color:#fff; }
        .badge-combo { background: rgba(245,158,11,.88); color:#fff; }
        .offer-content-col { flex: 1; padding: 12px 14px; display: flex; flex-direction: column; justify-content: space-between; }
        .offer-card-name { font-family: 'Fraunces', serif; font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 4px; }
        .offer-card-desc { font-size:11px;color:var(--ink2);line-height:1.5;margin-bottom:6px; }
        .offer-items-chips { display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px; }
        .offer-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: 700; }
        .offer-chip-buy { background:rgba(59,130,246,.1);color:#3B82F6;border:1px solid rgba(59,130,246,.2); }
        .offer-chip-get { background:rgba(34,197,94,.1);color:#22C55E;border:1px solid rgba(34,197,94,.2); }
        .offer-chip-combo { background:rgba(245,158,11,.1);color:#F59E0B;border:1px solid rgba(245,158,11,.2); }
        .offer-footer-row { display:flex;align-items:center;justify-content:space-between; }
        .offer-combo-price-menu { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 900; color: var(--p); }
        .offer-add-btn { padding: 7px 14px; background: var(--p); color: #fff; border: none; border-radius: 10px; font-size: 12px; font-weight: 800; cursor: pointer; font-family: 'Tajawal', sans-serif; transition: opacity .2s; }
        .offer-add-btn:active { opacity: .8; }
    </style>
</head>
<body>

<div class="progress-bar" id="progressBar"></div>

<div class="hero">
    <div class="hero-orb hero-orb-1"></div>
    <div class="hero-orb hero-orb-2"></div>
    <div class="hero-orb hero-orb-3"></div>
    <div class="hero-grid"></div>

    <div class="hero-top">
        <?php if($restaurant['logo']): ?>
            <img src="<?= BASE_URL ?>/assets/uploads/<?= $restaurant['logo'] ?>" class="restaurant-logo" alt="">
        <?php else: ?>
            <div class="logo-placeholder">🍽️</div>
        <?php endif; ?>

        <div class="hero-badges">
            <div class="open-badge">
                <div class="open-dot"></div>
                <span id="openBadgeText">نقبل الطلبات</span>
            </div>
            <div class="branch-badge">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                <?= htmlspecialchars($branch['name']) ?>
            </div>
            <?php if($table_from_qr): ?>
            <div class="table-badge">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                طاولة <?= $table_from_qr ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="hero-content">
        <div class="hero-bottom">
            <div class="restaurant-name"><?= htmlspecialchars($restaurant['name']) ?></div>
            <div class="restaurant-meta">
                <?php if(!empty($branch['address'])): ?>
                <div class="meta-item">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= htmlspecialchars($branch['address']) ?>
                </div>
                <?php endif; ?>
                <?php if(!empty($all_dishes)): ?>
                <div class="meta-item">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 2l3 7H6a3 3 0 003 3v9a1 1 0 001 1h4a1 1 0 001-1v-9a3 3 0 003-3h-3l3-7"/></svg>
                    <?= count($all_dishes) ?> صنف
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if(!empty($branch['welcome_message'])): ?>
<div class="welcome-strip">
    <div style="font-size:16px;flex-shrink:0;margin-top:1px;">💬</div>
    <div id="welcomeText"
         data-ar="<?= htmlspecialchars($branch['welcome_message']) ?>"
         data-en="<?= htmlspecialchars($branch['welcome_message_en'] ?? $branch['welcome_message']) ?>">
        <?= htmlspecialchars($branch['welcome_message']) ?>
    </div>
</div>
<?php endif; ?>

<?php if($has_social): ?>
<div class="social-bar">
    <?php if($restaurant['instagram']): ?>
        <a href="https://<?= htmlspecialchars($restaurant['instagram']) ?>" target="_blank" class="social-btn social-instagram" data-ar="انستغرام" data-en="Instagram">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
            انستغرام
        </a>
    <?php endif; ?>
    <?php if($restaurant['whatsapp']): ?>
        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $restaurant['whatsapp']) ?>" target="_blank" class="social-btn social-whatsapp" data-ar="واتساب" data-en="WhatsApp">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            واتساب
        </a>
    <?php endif; ?>
    <?php if($restaurant['facebook']): ?>
        <a href="https://<?= htmlspecialchars($restaurant['facebook']) ?>" target="_blank" class="social-btn social-facebook" data-ar="فيسبوك" data-en="Facebook">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            فيسبوك
        </a>
    <?php endif; ?>
    <?php if($restaurant['tiktok']): ?>
        <a href="https://<?= htmlspecialchars($restaurant['tiktok']) ?>" target="_blank" class="social-btn social-tiktok" data-ar="تيك توك" data-en="TikTok">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.69a8.18 8.18 0 004.78 1.52V6.76a4.85 4.85 0 01-1.01-.07z"/></svg>
            تيك توك
        </a>
    <?php endif; ?>
    <?php if($restaurant['twitter']): ?>
        <a href="https://<?= htmlspecialchars($restaurant['twitter']) ?>" target="_blank" class="social-btn social-twitter" data-ar="تويتر" data-en="Twitter">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
            تويتر
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="sticky-header">
    <div class="search-wrap">
        <div class="search-inner">
            <input type="text" class="search-input" id="searchInput" placeholder="ابحث عن طبق...">
            <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </div>
    </div>
    <?php if(!empty($categories)): ?>
    <div class="cats-nav">
        <div class="cats-inner">
            <button class="cat-btn active" id="btnAll" onclick="scrollToSection('all', this)"><span id="allBtnText">الكل</span></button>
            <?php foreach($categories as $cat): ?>
                <?php if(!empty($dishes_by_cat[$cat['id']])): ?>
                <button class="cat-btn" onclick="scrollToSection('cat-<?= $cat['id'] ?>', this)"
                        data-ar="<?= htmlspecialchars($cat['name']) ?>"
                        data-en="<?= htmlspecialchars($cat['name_en'] ?? $cat['name']) ?>">
                    <span><?= htmlspecialchars($cat['name']) ?></span>
                </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="menu-content" id="menuContent">
    <?php if(empty($all_dishes)): ?>
        <div class="empty-state">
            <div class="empty-box">🍽️</div>
            <p>قيد التحضير، تفضل لاحقاً!</p>
        </div>
    <?php else: ?>
        <div class="no-results" id="noResults">
            <div class="no-results-box">🔍</div>
            <p>ما في نتائج لبحثك</p>
        </div>

        <?php if(!empty($active_offers)): ?>
        <div class="offers-section" id="section-offers">
            <div class="offers-section-head">
                <div class="offers-section-label" id="offersSectionLabel">🎁 العروض</div>
                <div class="offers-section-line"></div>
            </div>
            <?php foreach($active_offers as $oi => $offer): ?>
            <div class="offer-card-menu" style="animation-delay:<?= $oi*.06 ?>s">
                <div class="offer-card-inner">
                    <div class="offer-img-col">
                        <?php if($offer['image']): ?>
                        <img src="<?= BASE_URL ?>/assets/uploads/offers/<?= $offer['image'] ?>">
                        <?php else: ?>
                        <?= $offer['type']==='combo' ? '🍱' : '🎁' ?>
                        <?php endif; ?>
                        <span class="offer-type-badge-menu badge-<?= $offer['type'] ?>">
                            <?= $offer['type']==='bxgy' ? 'اشترِ واحصل' : 'كومبو' ?>
                        </span>
                    </div>
                    <div class="offer-content-col">
                        <div>
                            <div class="offer-card-name"
                                 data-name-ar="<?= htmlspecialchars($offer['name']) ?>"
                                 data-name-en="<?= htmlspecialchars($offer['name_en']??$offer['name']) ?>">
                                <?= htmlspecialchars($offer['name']) ?>
                            </div>
                            <?php if($offer['description']): ?>
                            <div class="offer-card-desc"
                                 data-desc-ar="<?= htmlspecialchars($offer['description']) ?>"
                                 data-desc-en="<?= htmlspecialchars($offer['description_en']??$offer['description']) ?>">
                                <?= htmlspecialchars($offer['description']) ?>
                            </div>
                            <?php endif; ?>
                            <div class="offer-items-chips">
                                <?php foreach($offer['items'] as $itm): ?>
                                <span class="offer-chip offer-chip-<?= $itm['role'] ?>">
                                    <?php if($itm['role']==='buy'): ?>اشترِ<?php elseif($itm['role']==='get'): ?>مجاناً<?php endif; ?>
                                    ×<?= $itm['quantity'] ?>
                                    <span class="offer-chip-dish-name"
                                          data-name-ar="<?= htmlspecialchars($itm['dish_name']) ?>"
                                          data-name-en="<?= htmlspecialchars($itm['dish_name_en']??$itm['dish_name']) ?>">
                                        <?= htmlspecialchars($itm['dish_name']) ?>
                                    </span>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="offer-footer-row">
                            <?php if($offer['type']==='combo' && $offer['combo_price']): ?>
                            <div class="offer-combo-price-menu"><?= fmt_price($offer['combo_price'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
                            <?php else: ?>
                            <div></div>
                            <?php endif; ?>
                            <?php if($restaurant['subscription_plan']==='premium'): ?>
                            <button class="offer-add-btn"
                                onclick="addOfferToCart(<?= $offer['id'] ?>, this)"
                                data-offer='<?= htmlspecialchars(json_encode([
                                    'id'          => $offer['id'],
                                    'name'        => $offer['name'],
                                    'name_en'     => $offer['name_en']??'',
                                    'type'        => $offer['type'],
                                    'combo_price' => $offer['combo_price'],
                                    'items'       => $offer['items'],
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
                                + <span class="offer-add-label">أضف</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php foreach($categories as $cat): ?>
            <?php if(empty($dishes_by_cat[$cat['id']])) continue; ?>
            <div class="cat-section" id="cat-<?= $cat['id'] ?>">
                <div class="cat-header">
                    <div class="cat-label-wrap">
                        <div class="cat-label"><?= htmlspecialchars($cat['name']) ?></div>
                        <?php if(!empty($cat['name_en'])): ?>
                        <div class="cat-label-en"><?= htmlspecialchars($cat['name_en']) ?></div>
                        <?php endif; ?>
                        <div class="cat-count"><?= count($dishes_by_cat[$cat['id']]) ?></div>
                    </div>
                    <div class="cat-line"></div>
                </div>

                <?php foreach($dishes_by_cat[$cat['id']] as $dish):
                    $dish_opts = $dishes_options[$dish['id']] ?? [];
                    $dp  = floatval($dish['discount_percent'] ?? 0);
                    $da  = !empty($dish['discount_active']);
                    $fp  = ($da && $dp > 0) ? $dish['price'] * (1 - $dp/100) : $dish['price'];
                ?>
                <a href="<?= BASE_URL ?>/menu/<?= $slug ?>/<?= $branch_url ?>/dish/<?= $dish['id'] ?>"
                   class="dish-card dish-item<?= !empty($dish['sold_out']) ? ' sold-out-card' : '' ?>"
                   <?php if(!empty($dish_opts)): ?>
                   data-options='<?= htmlspecialchars(json_encode($dish_opts, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS), ENT_COMPAT) ?>'
                   data-dish-id="<?= $dish['id'] ?>"
                   data-dish-name="<?= htmlspecialchars($dish['name'], ENT_QUOTES) ?>"
                   data-dish-name-en="<?= htmlspecialchars($dish['name_en'] ?? '', ENT_QUOTES) ?>"
                   data-dish-price="<?= $fp ?>"
                   data-dish-image="<?= htmlspecialchars($dish['image'] ?? '', ENT_QUOTES) ?>"
                   <?php endif; ?>>
                    <div class="dish-inner">
                        <div class="dish-img-col">
                            <?php if($dish['image']): ?>
                                <img src="<?= BASE_URL ?>/assets/uploads/dishes/<?= $dish['image'] ?>"
                                     class="dish-img" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="dish-img-placeholder">🍔</div>
                            <?php endif; ?>
                            <?php if($dish['has_model3d']): ?>
                                <span class="badge-ar">AR</span>
                            <?php endif; ?>
                            <?php if(!empty($dish['sold_out'])): ?>
                                <span class="badge-ar sold-out-badge-text" style="background:rgba(245,158,11,0.88);border-color:rgba(245,158,11,0.4);color:#fff;top:8px;left:8px;right:auto;">نفذ اليوم</span>
                            <?php endif; ?>
                            <?php if(!empty($dish_opts)): ?>
                                <div class="dish-opts-badge">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/></svg>
                                    خيارات
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="dish-content-col">
                            <div>
                                <div class="dish-name"><?= htmlspecialchars($dish['name']) ?></div>
                                <?php if(!empty($dish['name_en'])): ?>
                                <div class="dish-name-en"><?= htmlspecialchars($dish['name_en']) ?></div>
                                <?php endif; ?>
                                <?php if($dish['description']): ?>
                                    <div class="dish-desc"><?= htmlspecialchars($dish['description']) ?></div>
                                <?php endif; ?>
                                <?php if(!empty($dish['description_en'])): ?>
                                    <div class="dish-desc-en"><?= htmlspecialchars($dish['description_en']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="dish-footer">
                                <div class="dish-price-wrap">
                                    <?php if($da && $dp > 0): ?>
                                    <span class="discount-badge-menu"
                                          data-pct="<?= round($dp) ?>"
                                          data-ar="خصم <?= round($dp) ?>%"
                                          data-en="<?= round($dp) ?>% OFF">
                                        🏷️ خصم <?= round($dp) ?>%
                                    </span>
                                    <span class="dish-price-old"><?= fmt_price($dish['price'], $cur_symbol, $cur_decimals, $cur_prefix) ?></span>
                                    <?php endif; ?>
                                    <div class="dish-price"><?= fmt_price($fp, $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
                                </div>
                                <?php if($restaurant['subscription_plan'] === 'premium'): ?>
                                <?php if(empty($dish['sold_out'])): ?>
                                <?php if($can_order): ?>
                                    <?php if(!empty($dish_opts)): ?>
                                    <button class="add-btn" id="add-<?= $dish['id'] ?>"
                                            style="background:var(--p);"
                                            onclick="event.preventDefault(); openOptsFromCard(event, this)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        <span class="add-btn-check">✓</span>
                                    </button>
                                    <?php else: ?>
                                    <button class="add-btn" id="add-<?= $dish['id'] ?>"
                                            onclick="event.preventDefault(); addToCart(<?= $dish['id'] ?>, '<?= htmlspecialchars($dish['name'], ENT_QUOTES) ?>', <?= $fp ?>, this, '<?= htmlspecialchars($dish['name_en'] ?? '', ENT_QUOTES) ?>', '<?= $dish['image'] ?>')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                        <span class="add-btn-check">✓</span>
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="sold-out-small-text" style="font-size:11px;font-weight:800;color:#F59E0B;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);padding:5px 10px;border-radius:10px;white-space:nowrap;">نفذ</span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="menu-footer">
    <?= htmlspecialchars($restaurant['name']) ?> · <?= htmlspecialchars($branch['name']) ?> · جميع الحقوق محفوظة
    <br><span class="footer-brand">Powered by MenuPro</span>
</div>

<div class="opts-popup-overlay" id="optsOverlay" onclick="if(event.target===this)closeOptsPopup()">
    <div class="opts-popup-sheet">
        <div class="opts-popup-handle"></div>
        <div class="opts-popup-head">
            <div class="opts-popup-dish-name" id="popupDishName"></div>
            <div class="opts-popup-price" id="popupPrice"></div>
        </div>
        <div class="opts-popup-body" id="popupBody"></div>
        <div class="opts-popup-footer">
            <button class="opts-popup-add-btn" onclick="confirmAddWithOpts()">
                <span id="popupAddLabel">أضف للسلة</span>
                <span class="opts-popup-total" id="popupTotal">$0.00</span>
            </button>
        </div>
    </div>
</div>

<?php if($can_order): ?>
<button class="cart-bar" id="cartBar"
        onclick="window.location.href='<?= BASE_URL ?>/menu/<?= $slug ?>/<?= $branch_url ?>/cart'">
    <div class="cart-bar-inner">
        <div class="cart-bar-text">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.96-1.61L23 6H6"/></svg>
            <span id="cartBarText">عرض السلة</span>
        </div>
        <div class="cart-bar-pill">
            <div class="cart-count-bubble" id="cartCount">0</div>
            <span id="cartTotal">$0.00</span>
        </div>
    </div>
</button>
<?php endif; ?>

<button class="lang-toggle" id="langToggle" onclick="toggleLang()" title="English / عربي">EN</button>

<button class="theme-toggle" onclick="toggleTheme()" title="تبديل المظهر">
    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
    <svg class="icon-sun"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
</button>

<div class="add-toast" id="addToast"></div>

<script>
const SLUG          = '<?= $slug ?>';
const BRANCH_SLUG   = '<?= $branch_url ?>';
const BRANCH_ID     = <?= $branch_id ?>;
const CUR_SYMBOL    = '<?= addslashes($cur_symbol) ?>';
const CUR_SYMBOL_EN = '<?= addslashes($cur_symbol_en) ?>';
const CUR_DECIMALS  = <?= $cur_decimals ?>;
const CUR_PREFIX    = <?= $cur_prefix ? 'true' : 'false' ?>;
const CUR_PREFIX_EN = <?= $cur_prefix_en ? 'true' : 'false' ?>;

function fmtPrice(amount) {
    const isEN = (typeof currentLang !== 'undefined' && currentLang === 'en') ||
                 document.documentElement.getAttribute('lang') === 'en';
    const sym    = isEN ? CUR_SYMBOL_EN : CUR_SYMBOL;
    const prefix = isEN ? CUR_PREFIX_EN : CUR_PREFIX;
    const n = parseFloat(amount).toFixed(CUR_DECIMALS);
    return prefix ? sym + n : sym + ' ' + n;
}

window.addEventListener('scroll', () => {
    const el = document.documentElement;
    const pct = (el.scrollTop / (el.scrollHeight - el.clientHeight)) * 100;
    const pb = document.getElementById('progressBar');
    if(pb) pb.style.width = pct + '%';
}, { passive: true });

const searchInputEl = document.getElementById('searchInput');
if(searchInputEl) {
    searchInputEl.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        const items = document.querySelectorAll('.dish-item');
        const sections = document.querySelectorAll('.cat-section');
        let found = 0;
        items.forEach(item => {
            const name = item.querySelector('.dish-name')?.textContent.toLowerCase() || '';
            const desc = item.querySelector('.dish-desc')?.textContent.toLowerCase() || '';
            const match = !q || name.includes(q) || desc.includes(q);
            item.style.display = match ? '' : 'none';
            if(match) found++;
        });
        sections.forEach(s => {
            const vis = s.querySelectorAll('.dish-item:not([style*="none"])');
            s.style.display = vis.length > 0 ? 'block' : 'none';
        });
        const nr = document.getElementById('noResults');
        if(nr) nr.style.display = found === 0 && q ? 'block' : 'none';
    });
}

function scrollToSection(id, btn) {
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    if(id === 'all') { window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
    const el = document.getElementById(id);
    if(el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 120, behavior: 'smooth' });
}

let cart = JSON.parse(localStorage.getItem('cart_<?= $slug ?>') || '[]');

function updateCartBar() {
    const total = cart.reduce((s,i) => s + i.price * i.qty, 0);
    const count = cart.reduce((s,i) => s + i.qty, 0);
    const el  = document.getElementById('cartCount');
    const tl  = document.getElementById('cartTotal');
    const bar = document.getElementById('cartBar');
    if(el)  el.textContent  = count;
    if(tl)  tl.textContent  = fmtPrice(total);
    if(bar) bar.classList.toggle('visible', count > 0);
}

let toastTimer;
function showToast(msg) {
    const t = document.getElementById('addToast');
    if(!t) return;
    t.textContent = msg;
    t.style.opacity = '1';
    t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        t.style.opacity = '0';
        t.style.transform = 'translateX(-50%) translateY(20px)';
    }, 2200);
}

function addToCart(id, name, price, btn, nameEn, image) {
    const existing = cart.find(i => i.id === id && !i.options);
    if(existing) {
        existing.qty++;
    } else {
        cart.push({ id, name, name_en: nameEn||'', price, qty: 1, image: image||'', options: null });
    }
    localStorage.setItem('cart_<?= $slug ?>', JSON.stringify(cart));
    updateCartBar();
    if(btn) {
        btn.classList.add('added');
        setTimeout(() => btn.classList.remove('added'), 1100);
    }
    const displayName = (currentLang === 'en' && nameEn) ? nameEn : name;
    showToast('✓ ' + displayName);
}

let popupDish = null;
let popupSelectedOpts = {};

function openOptsFromCard(event, btn) {
    event.preventDefault();
    event.stopPropagation();
    const card = btn.closest('.dish-card');
    if(!card || !card.dataset.options) return;
    try {
        const opts = JSON.parse(card.dataset.options);
        openOptsPopup(
            parseInt(card.dataset.dishId),
            card.dataset.dishName,
            card.dataset.dishNameEn || '',
            parseFloat(card.dataset.dishPrice),
            card.dataset.dishImage || '',
            opts
        );
    } catch(e) {
        console.error('Options parse error:', e, card.dataset.options?.substring(0,100));
    }
}

function openOptsPopup(id, name, nameEn, basePrice, image, optGroups) {
    popupDish = { id, name, name_en: nameEn, price: basePrice, image, optGroups };
    popupSelectedOpts = {};

    const displayName = (currentLang==='en' && nameEn) ? nameEn : name;
    const pn = document.getElementById('popupDishName');
    if(pn) pn.textContent = displayName;

    updatePopupTotal();

    const body = document.getElementById('popupBody');
    if(!body) return;
    body.innerHTML = '';

    optGroups.forEach(og => {
        const div = document.createElement('div');
        div.className = 'popup-group';
        const gname = (currentLang==='en' && og.name_en) ? og.name_en : og.name;
        const reqLabel = og.is_required
            ? `<span class="popup-req-badge">${currentLang==='en'?'Required':'إلزامي'}</span>`
            : `<span class="popup-opt-badge">${currentLang==='en'?'Optional':'اختياري'}</span>`;

        const vals = (og.values||[]).map(v => {
            const vname = (currentLang==='en' && v.name_en) ? v.name_en : v.name;
            const price = parseFloat(v.price) || 0;
            let priceHtml = '';
            if(og.price_mode === 'replace')  priceHtml = `<span class="popup-value-price replace">${fmtPrice(price)}</span>`;
            else if(price > 0)               priceHtml = `<span class="popup-value-price add">+${fmtPrice(price)}</span>`;
            else                             priceHtml = `<span class="popup-value-price free">${currentLang==='en'?'Free':'مجاني'}</span>`;
            return `<div class="popup-value"
                        data-gid="${og.id}" data-vid="${v.id}"
                        data-price="${price}" data-mode="${og.price_mode}"
                        data-name-ar="${v.name}" data-name-en="${v.name_en||v.name}"
                        onclick="selectPopupOpt(this)">
                <div class="popup-value-left">
                    <div class="popup-radio"><div class="popup-radio-dot"></div></div>
                    <div class="popup-value-name">${vname}</div>
                </div>
                ${priceHtml}
            </div>`;
        }).join('');

        div.innerHTML = `<div class="popup-group-title">${gname} ${reqLabel}</div>
                         <div class="popup-values">${vals}</div>`;
        body.appendChild(div);
    });

    const addLbl = document.getElementById('popupAddLabel');
    if(addLbl) addLbl.textContent = currentLang==='en' ? 'Add to Cart' : 'أضف للسلة';

    const overlay = document.getElementById('optsOverlay');
    if(overlay) overlay.classList.add('open');
}

function selectPopupOpt(el) {
    const gid = el.dataset.gid;
    document.querySelectorAll(`.popup-value[data-gid="${gid}"]`).forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    popupSelectedOpts[gid] = {
        vid:     el.dataset.vid,
        name:    el.dataset.nameAr,
        name_en: el.dataset.nameEn,
        price:   parseFloat(el.dataset.price)||0,
        mode:    el.dataset.mode,
    };
    updatePopupTotal();
}

function updatePopupTotal() {
    if(!popupDish) return;
    let price = popupDish.price;
    for(const gid in popupSelectedOpts) {
        const o = popupSelectedOpts[gid];
        if(o.mode === 'replace') price = o.price;
    }
    for(const gid in popupSelectedOpts) {
        const o = popupSelectedOpts[gid];
        if(o.mode === 'add') price += o.price;
    }
    const pp = document.getElementById('popupPrice');
    const pt = document.getElementById('popupTotal');
    if(pp) pp.textContent = fmtPrice(price);
    if(pt) pt.textContent = fmtPrice(price);
}

function confirmAddWithOpts() {
    if(!popupDish) return;
    for(const og of popupDish.optGroups) {
        if(og.is_required && !popupSelectedOpts[og.id]) {
            const gname = (currentLang==='en' && og.name_en) ? og.name_en : og.name;
            showToast('⚠️ ' + (currentLang==='en' ? 'Please select: ' : 'يرجى تحديد: ') + gname);
            document.querySelectorAll(`.popup-value[data-gid="${og.id}"]`).forEach(el => {
                el.style.borderColor = '#EF4444';
                setTimeout(() => el.style.borderColor = '', 2000);
            });
            return;
        }
    }
    let finalPrice = popupDish.price;
    for(const gid in popupSelectedOpts) {
        if(popupSelectedOpts[gid].mode === 'replace') finalPrice = popupSelectedOpts[gid].price;
    }
    for(const gid in popupSelectedOpts) {
        if(popupSelectedOpts[gid].mode === 'add') finalPrice += popupSelectedOpts[gid].price;
    }
    const opts = Object.values(popupSelectedOpts).map(o => ({
        name:    currentLang==='en' ? (o.name_en||o.name) : o.name,
        name_ar: o.name, name_en: o.name_en,
        price: o.price, mode: o.mode,
    }));
    const optKey = JSON.stringify(Object.keys(popupSelectedOpts).sort().map(k => popupSelectedOpts[k].vid));
    const existIdx = cart.findIndex(i => i.id == popupDish.id && i.optKey === optKey);
    if(existIdx >= 0) {
        cart[existIdx].qty++;
    } else {
        cart.push({
            id: popupDish.id, name: popupDish.name, name_en: popupDish.name_en,
            price: finalPrice, image: popupDish.image,
            qty: 1, options: opts, optKey,
        });
    }
    localStorage.setItem('cart_<?= $slug ?>', JSON.stringify(cart));
    updateCartBar();
    closeOptsPopup();
    const displayName = (currentLang==='en' && popupDish.name_en) ? popupDish.name_en : popupDish.name;
    showToast('✓ ' + displayName);
}

function closeOptsPopup() {
    const overlay = document.getElementById('optsOverlay');
    if(overlay) overlay.classList.remove('open');
    popupDish = null;
    popupSelectedOpts = {};
}

function toggleTheme() {
    const html = document.documentElement;
    const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('menu_theme_<?= $slug ?>', next);
}

let currentLang = localStorage.getItem('menu_lang_<?= $slug ?>') || 'ar';

function applyLang(lang) {
    currentLang = lang;
    const btn = document.getElementById('langToggle');
    const isEN = lang === 'en';

    document.body.classList.toggle('lang-en', isEN);
    document.documentElement.setAttribute('dir',  isEN ? 'ltr' : 'rtl');
    document.documentElement.setAttribute('lang', isEN ? 'en'  : 'ar');
    if(btn) {
        btn.textContent = isEN ? 'AR' : 'EN';
        btn.classList.toggle('en-active', isEN);
    }
    localStorage.setItem('menu_lang_<?= $slug ?>', lang);

    const ct = document.getElementById('cartBarText');
    if(ct) ct.textContent = isEN ? 'View Cart' : 'عرض السلة';

    const si = document.getElementById('searchInput');
    if(si) {
        si.placeholder  = isEN ? 'Search dishes...' : 'ابحث عن طبق...';
        si.style.direction  = isEN ? 'ltr' : 'rtl';
        si.style.textAlign  = isEN ? 'left' : 'right';
    }

    const allT = document.getElementById('allBtnText');
    if(allT) allT.textContent = isEN ? 'All' : 'الكل';

    const ob = document.getElementById('openBadgeText');
    if(ob) ob.textContent = isEN ? 'Now Open' : 'نقبل الطلبات';

    document.querySelectorAll('.sold-out-badge-text').forEach(el => el.textContent = isEN ? 'Sold Out' : 'نفذ اليوم');
    document.querySelectorAll('.sold-out-small-text').forEach(el => el.textContent = isEN ? 'Out' : 'نفذ');

    document.querySelectorAll('.dish-opts-badge').forEach(el => {
        const txt = el.childNodes[el.childNodes.length-1];
        if(txt && txt.nodeType === 3) txt.nodeValue = isEN ? ' Options' : ' خيارات';
    });

    document.querySelectorAll('.cat-btn[data-ar]').forEach(b => {
        const sp = b.querySelector('span');
        if(sp) sp.textContent = isEN ? (b.dataset.en || b.dataset.ar) : b.dataset.ar;
    });

    document.querySelectorAll('.social-btn[data-ar]').forEach(b => {
        const svg = b.querySelector('svg');
        b.innerHTML = '';
        if(svg) b.appendChild(svg);
        b.appendChild(document.createTextNode(isEN ? b.dataset.en : b.dataset.ar));
    });

    const wt = document.getElementById('welcomeText');
    if(wt && wt.dataset.ar) wt.textContent = isEN ? (wt.dataset.en || wt.dataset.ar) : wt.dataset.ar;

    const osl = document.getElementById('offersSectionLabel');
    if(osl) osl.textContent = isEN ? '🎁 Offers' : '🎁 العروض';

    document.querySelectorAll('.offer-card-name[data-name-ar]').forEach(el => {
        el.textContent = isEN ? (el.dataset.nameEn||el.dataset.nameAr) : el.dataset.nameAr;
    });
    document.querySelectorAll('.offer-card-desc[data-desc-ar]').forEach(el => {
        el.textContent = isEN ? (el.dataset.descEn||el.dataset.descAr) : el.dataset.descAr;
    });
    document.querySelectorAll('.offer-chip-dish-name[data-name-ar]').forEach(el => {
        el.textContent = isEN ? (el.dataset.nameEn||el.dataset.nameAr) : el.dataset.nameAr;
    });
    document.querySelectorAll('.offer-add-label').forEach(el => {
        el.textContent = isEN ? 'Add' : 'أضف';
    });
    document.querySelectorAll('.offer-type-badge-menu').forEach(el => {
        const type = el.classList.contains('badge-bxgy') ? 'bxgy' : 'combo';
        el.textContent = type==='bxgy' ? (isEN?'Buy & Get':'اشترِ واحصل') : (isEN?'Combo':'كومبو');
    });
    document.querySelectorAll('.discount-badge-menu[data-ar]').forEach(el => {
        el.innerHTML = '🏷️ ' + (isEN ? el.dataset.en : el.dataset.ar);
    });

    document.querySelectorAll('.offer-chip').forEach(el => {
        const cls = el.className;
        if(cls.includes('offer-chip-buy')) {
            const txt = el.childNodes[0];
            if(txt.nodeType===3) txt.nodeValue = isEN ? 'Buy ' : 'اشترِ ';
        } else if(cls.includes('offer-chip-get')) {
            const txt = el.childNodes[0];
            if(txt.nodeType===3) txt.nodeValue = isEN ? 'Free ' : 'مجاناً ';
        }
    });
}

function toggleLang() { applyLang(currentLang === 'ar' ? 'en' : 'ar'); }

function addOfferToCart(offerId, btn) {
    const offerData = JSON.parse(btn.dataset.offer);
    const isEN = currentLang === 'en';
    const name = isEN && offerData.name_en ? offerData.name_en : offerData.name;
    const optKey = 'offer_' + offerId;

    let price = 0;
    if(offerData.type === 'combo' && offerData.combo_price) {
        price = parseFloat(offerData.combo_price);
    } else {
        offerData.items.filter(i => i.role === 'buy').forEach(i => {
            price += parseFloat(i.dish_price||0) * parseInt(i.quantity||1);
        });
    }

    const itemDetails = offerData.items.map(itm => {
        const dname = isEN && itm.dish_name_en ? itm.dish_name_en : itm.dish_name;
        let label = '';
        if(offerData.type === 'bxgy') {
            label = itm.role === 'get' ? '🎁 ' : '';
        }
        return { name: label + '×' + itm.quantity + ' ' + dname };
    });

    const existIdx = cart.findIndex(i => i.optKey === optKey);
    if(existIdx >= 0) {
        cart[existIdx].qty++;
    } else {
        cart.push({
            id: 'offer_' + offerId,
            name: name,
            name_en: offerData.name_en || offerData.name,
            price: price,
            qty: 1,
            image: '',
            options: itemDetails,
            optKey: optKey,
            is_offer: true,
            offer_type: offerData.type,
        });
    }

    localStorage.setItem('cart_<?= $slug ?>', JSON.stringify(cart));
    updateCartBar();
    btn.classList.add('added');
    btn.innerHTML = '✓ <span class="offer-add-label">' + (isEN ? 'Added!' : 'تمت!') + '</span>';
    setTimeout(() => {
        btn.classList.remove('added');
        btn.innerHTML = '+ <span class="offer-add-label">' + (isEN ? 'Add' : 'أضف') + '</span>';
    }, 1400);
    showToast('✓ ' + name);
}

window.addEventListener('pageshow', function(e) {
    if(e.persisted || (window.performance && window.performance.navigation.type === 2)) {
        cart = JSON.parse(localStorage.getItem('cart_<?= $slug ?>') || '[]');
        updateCartBar();
    }
});

updateCartBar();
applyLang(currentLang);
document.addEventListener('DOMContentLoaded', function() {
    applyLang(currentLang);
    updateCartBar();
});
</script>
</body>
</html>