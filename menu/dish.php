<?php
/**
 * menu/dish.php — صفحة الطبق (v2 — branch-aware)
 *
 * التغييرات عن النسخة القديمة:
 *   - يقرأ branch_slug من URL (نفس نمط index.php)
 *   - يستخدم dishes_v2 + branch_dish_overrides للسعر/خصم/sold_out
 *   - يستخدم dish_option_groups_v2 + dish_option_values_v2
 *   - التقييمات من order_ratings (جدول مشترك، ما تغيّر)
 *   - كل الروابط تحفظ branch_slug (back btn → index, cart)
 *   - الـ UI/CSS/JS بدون أي تغيير
 */
require_once __DIR__ . '/../bootstrap.php';

$slug        = $_GET['slug']   ?? '';
$branch_slug = $_GET['branch'] ?? '';
$id          = intval($_GET['id'] ?? 0);

if (!$slug || !$id) { http_response_code(404); die('الصفحة غير موجودة'); }

// ===== جلب المطعم =====
$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE slug=? AND is_active=1");
$stmt->execute([$slug]);
$restaurant = $stmt->fetch();
if (!$restaurant) { http_response_code(404); die('المطعم غير موجود'); }

$rid = $restaurant['id'];

// ===== جلب الفرع =====
/** @var \MenuPro\Services\BranchService $branchService */
$branchService = service('branch');
$branch = null;
if ($branch_slug) {
    $branch = $branchService->getBySlug($rid, $branch_slug);
}
if (!$branch) {
    $active = $branchService->getActiveBranches($rid);
    if (empty($active)) { die('لا يوجد فروع نشطة'); }
    $first = $active[0];
    header("Location: " . BASE_URL . "/menu/{$slug}/{$first['slug']}/dish/{$id}");
    exit;
}
$branch_id  = intval($branch['id']);
$branch_url = $branch['slug'];

// ===== العملة =====
$cur_symbol    = $branch['currency_symbol']    ?? $restaurant['currency_symbol']    ?? '$';
$cur_symbol_en = $branch['currency_symbol_en'] ?? $restaurant['currency_symbol_en'] ?? $cur_symbol;
$cur_decimals  = intval($branch['currency_decimals'] ?? $restaurant['currency_decimals'] ?? 2);
$cur_prefix    = in_array($cur_symbol,    ['$', '€', '₺']);
$cur_prefix_en = in_array($cur_symbol_en, ['$', '€', '₺']);

if (!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $symbol . ' ' . $formatted;
    }
}

// ===== جلب الطبق (dishes_v2 + branch_dish_overrides) =====
$dish_stmt = $pdo->prepare("
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
    WHERE d.id = ? AND d.restaurant_id = ? AND d.is_active = 1
      AND COALESCE(bdo.is_available, 1) = 1
");
$dish_stmt->execute([$branch_id, $id, $rid]);
$dish = $dish_stmt->fetch();

if (!$dish) {
    header("Location: " . BASE_URL . "/menu/{$slug}/{$branch_url}");
    exit;
}

// تطبيق override
if ($dish['branch_price'] !== null)            $dish['price']            = $dish['branch_price'];
if ($dish['branch_discount_percent'] !== null) $dish['discount_percent'] = $dish['branch_discount_percent'];
if ($dish['branch_discount_active']  !== null) $dish['discount_active']  = $dish['branch_discount_active'];
if ($dish['branch_sold_out']         !== null) $dish['sold_out']         = $dish['branch_sold_out'];

$is_sold_out = !empty($dish['sold_out']);

// السعر النهائي
$d_pct   = floatval($dish['discount_percent'] ?? 0);
$d_act   = !empty($dish['discount_active']);
$d_final = ($d_act && $d_pct > 0) ? $dish['price'] * (1 - $d_pct / 100) : $dish['price'];

// ===== خيارات الطبق (dish_option_groups_v2) =====
$og_stmt = $pdo->prepare("
    SELECT og.*, GROUP_CONCAT(
        JSON_OBJECT(
            'id',      ov.id,
            'name',    ov.name,
            'name_en', COALESCE(ov.name_en,''),
            'price',   ov.price
        ) ORDER BY ov.sort_order
    ) as values_json
    FROM dish_option_groups_v2 og
    LEFT JOIN dish_option_values_v2 ov ON ov.group_id = og.id
    WHERE og.dish_id = ?
    GROUP BY og.id
    ORDER BY og.sort_order
");
$og_stmt->execute([$id]);
$opt_groups = $og_stmt->fetchAll();
foreach ($opt_groups as &$og) {
    $og['values'] = $og['values_json']
        ? (json_decode('[' . $og['values_json'] . ']', true) ?: [])
        : [];
    unset($og['values_json']);
}
unset($og);
$has_options = !empty($opt_groups);

// ===== التقييمات (order_ratings — جدول مشترك) =====
$ratings_stmt = $pdo->prepare("
    SELECT r.*, DATE_FORMAT(r.created_at,'%d/%m/%Y') as date_fmt,
           o.table_number
    FROM order_ratings r
    JOIN orders o ON o.id = r.order_id
    WHERE r.restaurant_id=? AND r.favorite_dish_id=?
    ORDER BY r.created_at DESC
");
$ratings_stmt->execute([$rid, $id]);
$ratings = $ratings_stmt->fetchAll();

$rs_stmt = $pdo->prepare("
    SELECT ROUND(AVG(r.rating),1) as avg, COUNT(*) as total,
        SUM(r.rating=5) as r5, SUM(r.rating=4) as r4,
        SUM(r.rating=3) as r3, SUM(r.rating=2) as r2, SUM(r.rating=1) as r1
    FROM order_ratings r
    WHERE r.restaurant_id=? AND r.favorite_dish_id=?
");
$rs_stmt->execute([$rid, $id]);
$rating_stats = $rs_stmt->fetch();

// ===== الفئة =====
$cat_stmt = $pdo->prepare("SELECT name, name_en FROM categories_v2 WHERE id=?");
$cat_stmt->execute([$dish['category_id']]);
$cat = $cat_stmt->fetch();

// ===== flags للباقة =====
$can_order = restaurant_has_feature($rid, 'orders');
$can_ar    = restaurant_has_feature($rid, 'ar');

// ===== AR/3D =====
$primary   = '#FF6B35';
$secondary = '#F7C59F';
// AR متاح فقط لو الباقة تدعمه
$has_ar    = $can_ar && !empty($dish['has_model3d']) && !empty($dish['model3d_file']);
$has_usdz  = $can_ar && !empty($dish['model3d_usdz']);
$glb_url   = $has_ar   ? BASE_URL . '/assets/uploads/models3d/' . $dish['model3d_file'] : '';
$usdz_url  = $has_usdz ? BASE_URL . '/assets/uploads/models3d/' . $dish['model3d_usdz'] : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($dish['name']) ?> — <?= htmlspecialchars($restaurant['name']) ?></title>
    <meta name="description" content="<?= htmlspecialchars(mb_substr($dish['description'] ?? '', 0, 150)) ?>">
    <script>
        (function(){
            var t = localStorage.getItem('menu_theme_<?= $slug ?>') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,700;0,9..144,900;1,9..144,400&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <?php if($has_ar): ?>
    <script type="module" src="https://unpkg.com/@google/model-viewer/dist/model-viewer.min.js"></script>
    <?php endif; ?>
    <style>
        :root { --p: <?= $primary ?>; --s: <?= $secondary ?>; }
        [data-theme="dark"]  { --bg:#080808;--bg2:#111111;--bg3:#1a1a1a;--bg4:#222222;--ink:#F5F0E8;--ink2:#6B6560;--ink3:#2E2B28;--line:rgba(245,240,232,0.06);--bar-bg:rgba(8,8,8,0.95); }
        [data-theme="light"] { --bg:#F7F5F2;--bg2:#FFFFFF;--bg3:#EEEAE6;--bg4:#E4E0DC;--ink:#1A1714;--ink2:#7A7570;--ink3:#B8B4B0;--line:rgba(26,23,20,0.08);--bar-bg:rgba(247,245,242,0.96); }
        body,.dish-visual,.review-card,.rating-overview,.bottom-bar,.toast { transition:background .3s,border-color .3s,color .3s; }
        *,*::before,*::after { margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent; }
        body { font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--ink);max-width:480px;margin:0 auto;min-height:100vh;padding-bottom:155px;overflow-x:hidden; }
        body::after { content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");pointer-events:none;z-index:9999; }
        .dish-visual { position:relative;width:100%;height:360px;background:var(--bg2);overflow:hidden; }
        .dish-photo { width:100%;height:100%;object-fit:cover;display:block;transition:transform .6s cubic-bezier(.25,1,.5,1); }
        .dish-photo-placeholder { width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:80px;background:linear-gradient(135deg,var(--bg2),var(--bg3)); }
        .dish-visual-orb { position:absolute;border-radius:50%;filter:blur(60px);pointer-events:none; }
        .dish-visual-orb-1 { width:250px;height:250px;background:var(--p);opacity:.15;top:-80px;right:-60px;animation:orbDrift 10s ease-in-out infinite; }
        .dish-visual-orb-2 { width:180px;height:180px;background:var(--s);opacity:.12;bottom:-60px;left:-40px;animation:orbDrift 8s ease-in-out infinite reverse; }
        @keyframes orbDrift { 0%,100%{transform:translate(0,0) scale(1)} 50%{transform:translate(15px,-15px) scale(1.1)} }
        .dish-visual::after { content:'';position:absolute;bottom:0;left:0;right:0;height:160px;background:linear-gradient(to top,var(--bg) 0%,transparent 100%);pointer-events:none; }
        model-viewer { width:100%;height:360px;background:var(--bg2);display:none;--poster-color:transparent; }
        .back-btn { position:absolute;top:16px;right:16px;width:42px;height:42px;background:#FF6B35;border-radius:50%;display:flex;align-items:center;justify-content:center;text-decoration:none;border:none;z-index:10;transition:opacity .2s;color:#fff;box-shadow:0 3px 12px rgba(255,107,53,.5); }
        .back-btn:active{opacity:.8;} [dir='rtl'] .back-btn svg{transform:scaleX(-1);} [dir='ltr'] .back-btn{right:auto;left:16px;}
        .back-btn svg{width:18px;height:18px;}
        .ar-mode-badge { position:absolute;top:16px;left:16px;display:flex;align-items:center;gap:6px;background:rgba(167,139,250,.15);backdrop-filter:blur(12px);border:1px solid rgba(167,139,250,.3);padding:6px 12px;border-radius:20px;font-size:11px;font-weight:700;color:#C4B5FD;z-index:10;display:none; }
        .ar-mode-badge.show{display:flex;}
        .view-toggle-wrap{padding:12px 16px 0;background:var(--bg);}
        .view-toggle{display:flex;background:var(--bg2);border-radius:14px;padding:4px;gap:4px;border:1px solid var(--line);}
        .view-toggle-btn{flex:1;padding:9px;border-radius:11px;border:none;background:transparent;color:var(--ink2);font-size:12px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s cubic-bezier(.34,1.2,.64,1);display:flex;align-items:center;justify-content:center;gap:5px;}
        .view-toggle-btn svg{width:14px;height:14px;}
        .view-toggle-btn.active{background:var(--bg4);color:var(--ink);box-shadow:0 2px 8px rgba(0,0,0,.3);}
        .ar-btns-row{display:flex;gap:8px;padding:12px 16px;background:var(--bg);}
        .ar-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 10px;border-radius:14px;border:1.5px solid var(--line);background:var(--bg2);color:var(--ink);text-decoration:none;font-size:12px;font-weight:700;font-family:'Tajawal',sans-serif;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;}
        .ar-btn::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--p),var(--s));opacity:0;transition:opacity .25s;}
        .ar-btn:active::before{opacity:.12;} .ar-btn:active{transform:scale(.97);} .ar-btn>*{position:relative;z-index:1;}
        .ar-btn-icon{font-size:18px;} .ar-btn-label{font-size:12px;font-weight:800;} .ar-btn-sub{font-size:9px;font-weight:600;color:var(--ink2);display:block;margin-top:1px;} .ar-btn-text{text-align:center;}
        .content{padding:16px 16px 0;}
        .dish-cat-chip{display:inline-flex;align-items:center;gap:5px;background:color-mix(in srgb,var(--p) 12%,transparent);border:1px solid color-mix(in srgb,var(--p) 25%,transparent);color:var(--p);padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.3px;margin-bottom:12px;}
        .dish-name{font-family:'Fraunces',serif;font-size:28px;font-weight:900;color:var(--ink);line-height:1.15;letter-spacing:-.5px;margin-bottom:10px;}
        .dish-meta-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
        .dish-price{font-family:'Fraunces',serif;font-size:30px;font-weight:900;color:var(--p);letter-spacing:-.5px;}
        .dish-price-old-detail{font-family:'Fraunces',serif;font-size:16px;font-weight:600;color:var(--ink3);text-decoration:line-through;margin-bottom:2px;}
        .discount-badge-detail{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#EF4444,#DC2626);color:#fff;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:900;box-shadow:0 4px 14px rgba(239,68,68,.35);margin-bottom:6px;}
        .dish-rating-pill{display:flex;align-items:center;gap:5px;background:var(--bg2);border:1px solid var(--line);padding:6px 12px;border-radius:20px;font-size:13px;font-weight:700;color:var(--ink);}
        .dish-rating-pill .star{color:#F59E0B;font-size:14px;} .dish-rating-pill .count{font-size:11px;color:var(--ink2);}
        .dish-description{font-size:14px;color:var(--ink2);line-height:1.8;margin-bottom:16px;}
        .dish-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:22px;}
        .dish-tag{background:var(--bg2);border:1px solid var(--line);padding:5px 13px;border-radius:20px;font-size:12px;color:var(--ink2);font-weight:600;}
        .section-divider{height:1px;background:var(--line);margin:20px 0;}
        .section-title{font-family:'Fraunces',serif;font-size:18px;font-weight:700;color:var(--ink);letter-spacing:-.2px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
        .rating-overview{display:flex;gap:18px;align-items:center;margin-bottom:20px;background:var(--bg2);border-radius:18px;border:1px solid var(--line);padding:18px;}
        .rating-big{text-align:center;min-width:60px;}
        .rating-big-num{font-family:'Fraunces',serif;font-size:48px;font-weight:900;color:var(--ink);line-height:1;letter-spacing:-1px;}
        .rating-big-stars{color:#F59E0B;font-size:14px;margin:5px 0 3px;letter-spacing:1px;}
        .rating-big-count{font-size:11px;color:var(--ink2);font-weight:600;}
        .rating-divider{width:1px;height:60px;background:var(--line);flex-shrink:0;}
        .rating-bars{flex:1;}
        .rb-row{display:flex;align-items:center;gap:7px;margin-bottom:5px;}
        .rb-label{font-size:10px;color:var(--ink2);width:14px;text-align:center;font-weight:700;} .rb-star{font-size:10px;color:#F59E0B;}
        .rb-track{flex:1;height:5px;background:var(--bg3);border-radius:5px;overflow:hidden;}
        .rb-fill{height:100%;border-radius:5px;background:linear-gradient(90deg,#F59E0B,var(--p));transition:width .8s cubic-bezier(.25,1,.5,1);}
        .rb-count{font-size:10px;color:var(--ink3);width:18px;text-align:left;font-weight:700;}
        .review-card{background:var(--bg2);border-radius:16px;border:1px solid var(--line);padding:14px 16px;margin-bottom:8px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;}
        @keyframes cardIn{from{opacity:0;transform:translateY(14px) scale(.97);}to{opacity:1;transform:none;}}
        .review-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
        .review-stars{color:#F59E0B;font-size:13px;letter-spacing:1px;} .review-date{font-size:11px;color:var(--ink3);font-weight:600;}
        .review-comment{font-size:13px;color:var(--ink2);line-height:1.65;margin-bottom:8px;}
        .review-table{font-size:11px;color:var(--ink3);font-weight:700;display:flex;align-items:center;gap:4px;}
        .no-ratings{text-align:center;padding:36px 20px;background:var(--bg2);border-radius:18px;border:1px solid var(--line);}
        .no-ratings-icon{width:56px;height:56px;border-radius:18px;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 12px;opacity:.5;}
        .no-ratings p{font-size:13px;color:var(--ink2);font-weight:600;}
        .bottom-bar{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:480px;padding:12px 16px;background:var(--bar-bg);border-top:1px solid var(--line);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);z-index:100;display:flex;gap:10px;align-items:center;}
        .qty-wrap{display:flex;align-items:center;background:var(--bg2);border-radius:14px;border:1px solid var(--line);overflow:hidden;flex-shrink:0;}
        .qty-btn{background:none;border:none;color:var(--p);font-size:22px;font-weight:300;width:40px;height:48px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s;font-family:'Tajawal',sans-serif;line-height:1;}
        .qty-btn:active{background:var(--bg3);}
        .qty-num{font-family:'Fraunces',serif;font-size:16px;font-weight:700;color:var(--ink);min-width:30px;text-align:center;}
        .add-cart-btn{flex:1;height:48px;background:var(--p);color:#fff;border:none;border-radius:14px;font-size:15px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;transition:transform .2s cubic-bezier(.34,1.56,.64,1),background .2s,box-shadow .2s;box-shadow:0 4px 20px color-mix(in srgb,var(--p) 35%,transparent);display:flex;align-items:center;justify-content:center;gap:8px;position:relative;overflow:hidden;}
        .add-cart-btn svg{width:18px;height:18px;flex-shrink:0;}
        .add-cart-btn:active{transform:scale(.97);}
        .add-cart-btn.added{background:#22C55E;box-shadow:0 4px 20px rgba(34,197,94,.35);}
        .add-cart-btn::after{content:'';position:absolute;top:0;left:-100%;width:60%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);transition:left .4s;}
        .add-cart-btn:active::after{left:140%;}
        .opts-container{padding:0 16px;margin-bottom:16px;}
        .opt-group{margin-bottom:14px;}
        .opt-group-title{font-size:13px;font-weight:800;color:var(--ink);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
        .opt-required-badge{font-size:10px;font-weight:700;color:#EF4444;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);padding:2px 7px;border-radius:10px;}
        .opt-optional-badge{font-size:10px;font-weight:700;color:var(--ink2);background:var(--bg3);border:1px solid var(--line);padding:2px 7px;border-radius:10px;}
        .opt-values-list{display:flex;flex-direction:column;gap:7px;}
        .opt-value-item{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;background:var(--bg2);border:1.5px solid var(--line);border-radius:13px;cursor:pointer;transition:all .2s;}
        .opt-value-item:hover{border-color:var(--p);}
        .opt-value-item.selected{border-color:var(--p);background:color-mix(in srgb,var(--p) 7%,transparent);}
        .opt-value-left{display:flex;align-items:center;gap:10px;}
        .opt-radio{width:18px;height:18px;border-radius:50%;border:2px solid var(--line);background:var(--bg3);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s;}
        .opt-radio-dot{width:8px;height:8px;border-radius:50%;background:var(--p);transform:scale(0);transition:transform .2s;}
        .opt-value-item.selected .opt-radio{border-color:var(--p);background:var(--p);}
        .opt-value-item.selected .opt-radio-dot{transform:scale(1);background:#fff;}
        .opt-value-name{font-size:13px;font-weight:600;color:var(--ink);}
        .opt-value-price{font-family:'Fraunces',serif;font-size:13px;font-weight:700;color:var(--p);}
        .opt-value-price.replace-price{color:var(--p);} .opt-value-price.add-price{color:#22C55E;} .opt-value-price.free{color:var(--ink2);font-size:11px;}
        .notes-bar{position:fixed;bottom:72px;left:50%;transform:translateX(-50%);width:100%;max-width:480px;padding:6px 14px;background:var(--bar-bg);border-top:1px solid var(--line);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);z-index:99;}
        .notes-input{width:100%;padding:9px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;color:var(--ink);font-size:13px;font-weight:500;font-family:'Tajawal',sans-serif;resize:none;outline:none;min-height:38px;max-height:80px;overflow-y:hidden;transition:border-color .2s;line-height:1.4;}
        .notes-input:focus{border-color:var(--p);} .notes-input::placeholder{color:var(--ink2);}
        .go-cart-btn{position:fixed;bottom:82px;left:50%;transform:translateX(-50%) translateY(20px);z-index:400;display:flex;align-items:center;gap:8px;padding:12px 22px;background:var(--ink);color:var(--bg);border-radius:50px;font-size:13px;font-weight:800;text-decoration:none;font-family:'Tajawal',sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.35);opacity:0;pointer-events:none;transition:all .4s cubic-bezier(.34,1.56,.64,1);white-space:nowrap;}
        .go-cart-btn.show{opacity:1;pointer-events:all;transform:translateX(-50%) translateY(0);}
        .go-cart-btn svg{width:16px;height:16px;}
        .go-cart-count{background:var(--p);color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;}
        .toast{position:fixed;bottom:80px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--bg3);border:1px solid var(--line);color:var(--ink);padding:10px 20px;border-radius:25px;font-size:13px;font-weight:700;z-index:999;opacity:0;transition:all .35s cubic-bezier(.34,1.56,.64,1);white-space:nowrap;backdrop-filter:blur(16px);max-width:280px;overflow:hidden;text-overflow:ellipsis;}
        .toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
        .theme-toggle{position:fixed;bottom:80px;left:16px;width:42px;height:42px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:200;box-shadow:0 4px 16px rgba(0,0,0,.25);transition:background .2s,transform .3s,border-color .3s;}
        .theme-toggle:active{transform:rotate(25deg) scale(.9);}
        .theme-toggle svg{width:17px;height:17px;}
        .icon-moon{display:block;} .icon-sun{display:none;}
        [data-theme="light"] .icon-moon{display:none;} [data-theme="light"] .icon-sun{display:block;}
        .lang-toggle{position:fixed;bottom:80px;left:66px;width:42px;height:42px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:200;box-shadow:0 4px 16px rgba(0,0,0,.25);font-size:12px;font-weight:800;font-family:'Tajawal',sans-serif;transition:background .2s,transform .2s;}
        .lang-toggle:active{transform:scale(.9);}
        .lang-toggle.en-active{background:var(--p);color:#fff;border-color:transparent;}
        .dish-name-en{font-family:'Fraunces',serif;font-size:28px;font-weight:900;color:var(--ink);line-height:1.15;letter-spacing:-.5px;margin-bottom:10px;display:none;}
        .dish-desc-en{font-size:14px;color:var(--ink2);line-height:1.8;margin-bottom:16px;display:none;}
        body.lang-en .dish-name{display:none;} body.lang-en .dish-name-en{display:block;}
        body.lang-en .dish-description{display:none;} body.lang-en .dish-desc-en{display:block;}
    </style>
</head>
<body>

<!-- VISUAL -->
<div class="dish-visual" id="dishVisual">
    <?php if(!$dish['image']): ?>
    <div class="dish-visual-orb dish-visual-orb-1"></div>
    <div class="dish-visual-orb dish-visual-orb-2"></div>
    <?php endif; ?>

    <?php if($dish['image']): ?>
        <img src="<?= BASE_URL ?>/assets/uploads/dishes/<?= $dish['image'] ?>"
             class="dish-photo" id="dishPhoto" alt="<?= htmlspecialchars($dish['name']) ?>">
    <?php else: ?>
        <div class="dish-photo-placeholder" id="dishPhoto">🍔</div>
    <?php endif; ?>

    <?php if($has_ar): ?>
    <model-viewer id="modelViewer"
        src="<?= $glb_url ?>"
        <?php if($has_usdz): ?>ios-src="<?= $usdz_url ?>"<?php endif; ?>
        ar ar-modes="webxr scene-viewer quick-look"
        ar-scale="auto" camera-controls auto-rotate
        auto-rotate-delay="1000" rotation-per-second="25deg"
        shadow-intensity="1" exposure="1">
        <button slot="ar-button" style="display:none;"></button>
    </model-viewer>
    <?php endif; ?>

    <div class="ar-mode-badge" id="arModeBadge">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
        <span id="arBadgeText">عرض ثلاثي الأبعاد</span>
    </div>

    <!-- Back button → index with branch_slug -->
    <a href="<?= BASE_URL ?>/menu/<?= $slug ?>/<?= $branch_url ?>" class="back-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    </a>
</div>

<?php if($has_ar): ?>
<div class="view-toggle-wrap">
    <div class="view-toggle">
        <button class="view-toggle-btn active" id="btnPhoto" onclick="showPhoto()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            <span id="photoLabel">صورة</span>
        </button>
        <button class="view-toggle-btn" id="btn3D" onclick="show3D()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
            <span id="label3D">ثلاثي الأبعاد</span>
        </button>
    </div>
</div>
<div class="ar-btns-row">
    <?php if($has_usdz): ?>
    <a href="<?= $usdz_url ?>" rel="ar" class="ar-btn" id="iosArBtn">
        <span class="ar-btn-icon">🍎</span>
        <div class="ar-btn-text">
            <span class="ar-btn-label" id="iosLabel">AR على iPhone</span>
            <span class="ar-btn-sub">Quick Look</span>
        </div>
    </a>
    <?php endif; ?>
    <button class="ar-btn" id="androidArBtn" onclick="launchAndroidAR()">
        <span class="ar-btn-icon">🤖</span>
        <div class="ar-btn-text">
            <span class="ar-btn-label" id="androidLabel">AR على Android</span>
            <span class="ar-btn-sub">Scene Viewer</span>
        </div>
    </button>
</div>
<?php endif; ?>

<!-- CONTENT -->
<div class="content">

    <?php if($cat): ?>
    <div class="dish-cat-chip" id="catChip"
         data-name-ar="<?= htmlspecialchars($cat['name']) ?>"
         data-name-en="<?= htmlspecialchars($cat['name_en'] ?? '') ?>">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 2l3 7H6a3 3 0 003 3v9a1 1 0 001 1h4a1 1 0 001-1v-9a3 3 0 003-3h-3l3-7"/></svg>
        <span id="catChipText"><?= htmlspecialchars($cat['name']) ?></span>
    </div>
    <?php endif; ?>

    <div class="dish-name"><?= htmlspecialchars($dish['name']) ?></div>
    <?php if(!empty($dish['name_en'])): ?>
    <div class="dish-name-en"><?= htmlspecialchars($dish['name_en']) ?></div>
    <?php endif; ?>

    <div class="dish-meta-row">
        <div>
            <?php if($d_act && $d_pct > 0): ?>
            <div class="discount-badge-detail" id="discountBadgeDetail"
                 data-ar="🏷️ خصم <?= round($d_pct) ?>%"
                 data-en="🏷️ <?= round($d_pct) ?>% OFF">
                🏷️ خصم <?= round($d_pct) ?>%
            </div>
            <div class="dish-price-old-detail"><?= fmt_price($dish['price'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
            <?php endif; ?>
            <div class="dish-price" id="dishPriceEl"><?= fmt_price($d_final, $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        </div>
        <?php if($rating_stats['total'] > 0): ?>
        <div class="dish-rating-pill">
            <span class="star">★</span>
            <span><?= $rating_stats['avg'] ?></span>
            <span class="count">(<?= $rating_stats['total'] ?>)</span>
        </div>
        <?php endif; ?>
    </div>

    <?php if($dish['description']): ?>
    <div class="dish-description"><?= nl2br(htmlspecialchars($dish['description'])) ?></div>
    <?php endif; ?>
    <?php if(!empty($dish['description_en'])): ?>
    <div class="dish-desc-en"><?= nl2br(htmlspecialchars($dish['description_en'])) ?></div>
    <?php endif; ?>

    <?php if($dish['ingredients'] || !empty($dish['ingredients_en']) || $has_ar): ?>
    <div class="dish-tags" id="ingrTags">
        <?php if($has_ar): ?>
            <span class="dish-tag" style="color:#C4B5FD;border-color:rgba(167,139,250,.3);">
                🥽 <span id="arAvailText">AR متاح</span>
            </span>
        <?php endif; ?>
        <?php if($dish['ingredients']): ?>
            <?php foreach(explode(',', $dish['ingredients']) as $ing): ?>
                <span class="dish-tag dish-ingr-ar"><?= htmlspecialchars(trim($ing)) ?></span>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if(!empty($dish['ingredients_en'])): ?>
            <?php foreach(explode(',', $dish['ingredients_en']) as $ing): ?>
                <span class="dish-tag dish-ingr-en" style="display:none;"><?= htmlspecialchars(trim($ing)) ?></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($dish['recipe']) || !empty($dish['recipe_en'])): ?>
    <div style="margin-bottom:16px;">
        <?php if(!empty($dish['recipe'])): ?>
        <div class="dish-recipe dish-recipe-ar" style="font-size:13px;color:var(--ink2);line-height:1.8;background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:14px;">
            <div style="font-size:11px;font-weight:800;color:var(--ink2);margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;" id="recipeLbl">طريقة التحضير</div>
            <?= nl2br(htmlspecialchars($dish['recipe'])) ?>
        </div>
        <?php endif; ?>
        <?php if(!empty($dish['recipe_en'])): ?>
        <div class="dish-recipe dish-recipe-en" style="display:none;font-size:13px;color:var(--ink2);line-height:1.8;background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:14px;">
            <div style="font-size:11px;font-weight:800;color:var(--ink2);margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;">Preparation</div>
            <?= nl2br(htmlspecialchars($dish['recipe_en'])) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if($has_options): ?>
    <div class="section-divider"></div>
    <div class="opts-container">
        <?php foreach($opt_groups as $og): ?>
        <div class="opt-group" data-gid="<?= $og['id'] ?>"
             data-mode="<?= $og['price_mode'] ?>"
             data-required="<?= $og['is_required'] ?>">
            <div class="opt-group-title">
                <span class="og-title-text"
                      data-name-ar="<?= htmlspecialchars($og['name']) ?>"
                      data-name-en="<?= htmlspecialchars($og['name_en'] ?? $og['name']) ?>">
                    <?= htmlspecialchars($og['name']) ?>
                </span>
                <?php if($og['is_required']): ?>
                <span class="opt-required-badge opt-req-lbl">إلزامي</span>
                <?php else: ?>
                <span class="opt-optional-badge opt-req-lbl">اختياري</span>
                <?php endif; ?>
            </div>
            <div class="opt-values-list">
                <?php foreach($og['values'] as $ov): ?>
                <div class="opt-value-item"
                     data-gid="<?= $og['id'] ?>"
                     data-vid="<?= $ov['id'] ?>"
                     data-price="<?= $ov['price'] ?>"
                     data-mode="<?= $og['price_mode'] ?>"
                     data-name-ar="<?= htmlspecialchars($ov['name']) ?>"
                     data-name-en="<?= htmlspecialchars($ov['name_en'] ?? $ov['name']) ?>"
                     onclick="selectOption(this)">
                    <div class="opt-value-left">
                        <div class="opt-radio"><div class="opt-radio-dot"></div></div>
                        <div class="opt-value-name opt-val-name-text">
                            <?= htmlspecialchars($ov['name']) ?>
                        </div>
                    </div>
                    <div class="opt-value-price <?= $og['price_mode']==='replace' ? 'replace-price' : ($ov['price']>0?'add-price':'free') ?>">
                        <?php if($og['price_mode'] === 'replace'): ?>
                            <?= fmt_price($ov['price'], $cur_symbol, $cur_decimals, $cur_prefix) ?>
                        <?php elseif($ov['price'] > 0): ?>
                            +<?= fmt_price($ov['price'], $cur_symbol, $cur_decimals, $cur_prefix) ?>
                        <?php else: ?>
                            <span class="opt-free-text">مجاني</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- RATINGS -->
    <div class="section-divider"></div>
    <div class="section-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#F59E0B" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
        <span class="sec-title-text">تقييمات الزبائن</span>
    </div>

    <?php if($rating_stats['total'] > 0): ?>
    <div class="rating-overview">
        <div class="rating-big">
            <div class="rating-big-num"><?= $rating_stats['avg'] ?></div>
            <div class="rating-big-stars">
                <?php $avg = round($rating_stats['avg']); for($i=1;$i<=5;$i++) echo $i<=$avg?'★':'☆'; ?>
            </div>
            <div class="rating-big-count"><?= $rating_stats['total'] ?> <span class="rating-count-word">تقييم</span></div>
        </div>
        <div class="rating-divider"></div>
        <div class="rating-bars">
            <?php $counts=[5=>$rating_stats['r5'],4=>$rating_stats['r4'],3=>$rating_stats['r3'],2=>$rating_stats['r2'],1=>$rating_stats['r1']]; ?>
            <?php foreach($counts as $s=>$c): ?>
            <?php $pct=$rating_stats['total']>0?($c/$rating_stats['total'])*100:0; ?>
            <div class="rb-row">
                <span class="rb-label"><?= $s ?></span>
                <span class="rb-star">★</span>
                <div class="rb-track"><div class="rb-fill" style="width:<?= $pct ?>%"></div></div>
                <span class="rb-count"><?= $c ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php foreach($ratings as $i => $rev): ?>
    <div class="review-card <?= $i >= 5 ? 'review-extra' : '' ?>"
         style="animation-delay:<?= $i*.05 ?>s;<?= $i >= 5 ? 'display:none;' : '' ?>">
        <div class="review-header">
            <div class="review-stars">
                <?php for($s=1;$s<=5;$s++) echo $s<=$rev['rating']?'★':'☆'; ?>
            </div>
            <div class="review-date"><?= $rev['date_fmt'] ?></div>
        </div>
        <?php if($rev['comment']): ?>
            <div class="review-comment"><?= htmlspecialchars($rev['comment']) ?></div>
        <?php endif; ?>
        <?php if($rev['table_number']): ?>
            <div class="review-table">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                <span class="review-table-word">طاولة</span> <?= htmlspecialchars($rev['table_number']) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if(count($ratings) > 5): ?>
    <button onclick="showAllReviews(this)"
            style="width:100%;margin-top:4px;padding:12px;background:var(--bg2);border:1.5px solid var(--line);border-radius:14px;font-size:13px;font-weight:700;color:var(--ink2);cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:7px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:14px;height:14px"><polyline points="6 9 12 15 18 9"/></svg>
        <span id="showAllReviewsText">عرض كل التقييمات (<?= count($ratings) ?>)</span>
    </button>
    <?php endif; ?>

    <?php else: ?>
    <div class="no-ratings">
        <div class="no-ratings-icon">★</div>
        <p class="no-rate-text">كن أول من يقيّم هذا الطبق!</p>
    </div>
    <?php endif; ?>

</div>

<!-- NOTES BAR -->
<?php if(!$is_sold_out): ?>
<div class="notes-bar" id="notesBar">
    <textarea class="notes-input" id="dishNotes"
              placeholder="ملاحظات خاصة... (مثال: بدون بصل، إضافة صوص)"
              rows="1"
              oninput="autoResizeNotes(this)"></textarea>
</div>
<?php endif; ?>

<!-- BOTTOM BAR -->
<div class="bottom-bar">
    <?php if($is_sold_out): ?>
    <div style="flex:1;height:48px;background:rgba(245,158,11,.1);border:1.5px solid rgba(245,158,11,.25);border-radius:14px;display:flex;align-items:center;justify-content:center;gap:10px;">
        <span style="font-size:18px;">⚠️</span>
        <span style="font-size:14px;font-weight:800;color:#F59E0B;" id="soldOutText">نفذ هذا الطبق اليوم</span>
    </div>
    <?php elseif($can_order): ?>
    <div class="qty-wrap">
        <button class="qty-btn" onclick="changeQty(-1)">−</button>
        <span class="qty-num" id="qtyNum">1</span>
        <button class="qty-btn" onclick="changeQty(1)">+</button>
    </div>
    <button class="add-cart-btn" id="addCartBtn" onclick="addToCart()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.96-1.61L23 6H6"/></svg>
        <span id="addCartText">أضف للسلة</span>
    </button>
    <?php endif; ?>
</div>

<div class="toast" id="toast"></div>
<button class="lang-toggle" id="langToggle" onclick="toggleLang()">EN</button>

<!-- Go to Cart — رابط محدّث يحمل branch_slug — مخفي للباقات اللي ما تدعم الطلبات -->
<?php if($can_order): ?>
<a href="<?= BASE_URL ?>/menu/<?= $slug ?>/<?= $branch_url ?>/cart" class="go-cart-btn" id="goCartBtn">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.96-1.61L23 6H6"/></svg>
    <span id="goCartText">عرض السلة</span>
    <div class="go-cart-count" id="goCartCount">0</div>
</a>
<?php endif; ?>

<button class="theme-toggle" onclick="toggleTheme()">
    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
    <svg class="icon-sun"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
</button>

<script>
const SLUG         = '<?= $slug ?>';
const BRANCH_SLUG  = '<?= $branch_url ?>';
const CUR_SYMBOL    = '<?= addslashes($cur_symbol) ?>';
const CUR_SYMBOL_EN = '<?= addslashes($cur_symbol_en) ?>';
const CUR_DECIMALS  = <?= $cur_decimals ?>;
const CUR_PREFIX    = <?= $cur_prefix ? 'true' : 'false' ?>;
const CUR_PREFIX_EN = <?= $cur_prefix_en ? 'true' : 'false' ?>;

function fmtPrice(amount) {
    const isEN = currentLang === 'en';
    const sym    = isEN ? CUR_SYMBOL_EN : CUR_SYMBOL;
    const prefix = isEN ? CUR_PREFIX_EN : CUR_PREFIX;
    const n = parseFloat(amount).toFixed(CUR_DECIMALS);
    return prefix ? sym + n : sym + ' ' + n;
}

const STORAGE_KEY = 'cart_' + SLUG;
const BASE_PRICE  = <?= $d_final ?>;
const HAS_OPTIONS = <?= $has_options ? 'true' : 'false' ?>;
const OPT_GROUPS  = <?= json_encode(array_map(fn($og) => [
    'id'          => $og['id'],
    'name'        => $og['name'],
    'name_en'     => $og['name_en'] ?? '',
    'price_mode'  => $og['price_mode'],
    'is_required' => (int)$og['is_required'],
    'values'      => array_map(fn($v) => [
        'id'      => $v['id'],
        'name'    => $v['name'],
        'name_en' => $v['name_en'] ?? '',
        'price'   => (float)$v['price'],
    ], $og['values']),
], $opt_groups), JSON_UNESCAPED_UNICODE) ?>;
const DISH = {
    id      : <?= $dish['id'] ?>,
    name    : '<?= addslashes($dish['name']) ?>',
    name_en : '<?= addslashes($dish['name_en'] ?? '') ?>',
    price   : <?= $d_final ?>,
    image   : '<?= $dish['image'] ?? '' ?>'
};

let qty = 1;
let currentLang = localStorage.getItem('menu_lang_' + SLUG) || 'ar';
let selectedOptions = {};

function showToast(msg) {
    const t = document.getElementById('toast');
    if(!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
}

function changeQty(delta) {
    qty = Math.max(1, qty + delta);
    document.getElementById('qtyNum').textContent = qty;
}

function selectOption(el) {
    const gid   = el.dataset.gid;
    const price = parseFloat(el.dataset.price) || 0;
    document.querySelectorAll(`.opt-value-item[data-gid="${gid}"]`).forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    selectedOptions[gid] = {
        vid:     el.dataset.vid,
        name:    el.dataset.nameAr,
        name_en: el.dataset.nameEn,
        price,
        mode:    el.dataset.mode,
    };
    updatePriceDisplay();
}

function updatePriceDisplay() {
    let price = BASE_PRICE;
    for(const gid in selectedOptions) {
        if(selectedOptions[gid].mode === 'replace') price = selectedOptions[gid].price;
    }
    for(const gid in selectedOptions) {
        if(selectedOptions[gid].mode === 'add') price += selectedOptions[gid].price;
    }
    const el = document.getElementById('dishPriceEl');
    if(el) el.textContent = fmtPrice(price);
}

function validateOptions() {
    for(const og of OPT_GROUPS) {
        if(og.is_required && !selectedOptions[og.id]) {
            const group = document.querySelector(`.opt-group[data-gid="${og.id}"]`);
            if(group) {
                group.style.outline = '2px solid #EF4444';
                group.style.borderRadius = '12px';
                setTimeout(() => group.style.outline = '', 2000);
            }
            showToast('⚠️ ' + (currentLang==='en' ? 'Please select required options' : 'يرجى تحديد الخيارات الإلزامية'));
            return false;
        }
    }
    return true;
}

function getFinalPrice() {
    let price = BASE_PRICE;
    for(const gid in selectedOptions) {
        if(selectedOptions[gid].mode === 'replace') price = selectedOptions[gid].price;
    }
    for(const gid in selectedOptions) {
        if(selectedOptions[gid].mode === 'add') price += selectedOptions[gid].price;
    }
    return price;
}

function addToCart() {
    if(HAS_OPTIONS && !validateOptions()) return;
    const finalPrice = getFinalPrice();
    const opts = Object.values(selectedOptions).map(o => ({
        name:    currentLang==='en' && o.name_en ? o.name_en : o.name,
        name_ar: o.name, name_en: o.name_en,
        price:   o.price, mode: o.mode,
    }));
    const optKey = JSON.stringify(Object.keys(selectedOptions).sort().map(k => selectedOptions[k].vid));
    const notes  = getNotes();
    let cart     = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
    const existIdx = cart.findIndex(i => i.id === DISH.id && i.optKey === optKey && (i.notes||'') === notes);
    if(existIdx >= 0) {
        cart[existIdx].qty += qty;
    } else {
        cart.push({ ...DISH, price: finalPrice, qty, options: opts.length ? opts : null, optKey, notes });
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
    updateGoCartBtn();

    const btn = document.getElementById('addCartBtn');
    if(btn) {
        btn.classList.add('added');
        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>${currentLang==='en'?'Added!':'تمت الإضافة!'}`;
        setTimeout(() => {
            btn.classList.remove('added');
            btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.96-1.61L23 6H6"/></svg><span id="addCartText">${currentLang==='en'?'Add to Cart':'أضف للسلة'}</span>`;
            qty = 1;
            document.getElementById('qtyNum').textContent = 1;
        }, 1800);
    }
    const dName = currentLang==='en' && DISH.name_en ? DISH.name_en : DISH.name;
    showToast('✓ ' + dName + (currentLang==='en' ? ' added to cart' : ' أُضيف للسلة'));
}

function autoResizeNotes(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 80) + 'px';
}

function getNotes() {
    const el = document.getElementById('dishNotes');
    return el ? el.value.trim() : '';
}

function updateGoCartBtn() {
    const cart  = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
    const count = cart.reduce((s,i) => s+i.qty, 0);
    const btn   = document.getElementById('goCartBtn');
    const cnt   = document.getElementById('goCartCount');
    const txt   = document.getElementById('goCartText');
    if(cnt) cnt.textContent = count;
    if(txt) txt.textContent = currentLang==='en' ? 'View Cart' : 'عرض السلة';
    if(btn) btn.classList.toggle('show', count > 0);
}

// AR
<?php if($has_ar): ?>
function launchAndroidAR() {
    const mv = document.getElementById('modelViewer');
    if(mv && mv.canActivateAR) mv.activateAR();
    else showToast(currentLang==='en' ? '⚠️ AR not available on this device' : '⚠️ AR غير متاح على هاد الجهاز');
}
function showPhoto() {
    document.getElementById('modelViewer').style.display = 'none';
    document.getElementById('dishPhoto').style.display   = '';
    document.getElementById('btnPhoto').classList.add('active');
    document.getElementById('btn3D').classList.remove('active');
    document.getElementById('arModeBadge').classList.remove('show');
}
function show3D() {
    document.getElementById('dishPhoto').style.display   = 'none';
    document.getElementById('modelViewer').style.display = 'block';
    document.getElementById('btn3D').classList.add('active');
    document.getElementById('btnPhoto').classList.remove('active');
    document.getElementById('arModeBadge').classList.add('show');
}
window.addEventListener('DOMContentLoaded', () => {
    const isIOS     = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const isAndroid = /Android/.test(navigator.userAgent);
    if(isIOS)          { const b=document.getElementById('androidArBtn'); if(b) b.style.display='none'; }
    else if(isAndroid) { const b=document.getElementById('iosArBtn');     if(b) b.style.display='none'; }
});
<?php endif; ?>

function toggleTheme() {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('menu_theme_' + SLUG, next);
}

function applyLang(lang) {
    currentLang = lang;
    const isEN  = lang === 'en';
    const btn   = document.getElementById('langToggle');
    document.body.classList.toggle('lang-en', isEN);
    document.documentElement.setAttribute('dir',  isEN ? 'ltr' : 'rtl');
    document.documentElement.setAttribute('lang', isEN ? 'en'  : 'ar');
    if(btn) { btn.textContent = isEN ? 'AR' : 'EN'; btn.classList.toggle('en-active', isEN); }
    localStorage.setItem('menu_lang_' + SLUG, lang);

    const set = (id, v) => { const el=document.getElementById(id); if(el) el.textContent=v; };
    set('photoLabel',   isEN ? 'Photo'        : 'صورة');
    set('label3D',      isEN ? '3D View'       : 'ثلاثي الأبعاد');
    set('arBadgeText',  isEN ? '3D Active'     : 'عرض ثلاثي الأبعاد');
    set('iosLabel',     isEN ? 'AR on iPhone'  : 'AR على iPhone');
    set('androidLabel', isEN ? 'AR on Android' : 'AR على Android');
    set('arAvailText',  isEN ? 'AR Available'  : 'AR متاح');
    set('addCartText',  isEN ? 'Add to Cart'   : 'أضف للسلة');
    set('soldOutText',  isEN ? 'Sold Out Today': 'نفذ هذا الطبق اليوم');

    const ni = document.getElementById('dishNotes');
    if(ni) ni.placeholder = isEN ? 'Special notes... (e.g. no onion)' : 'ملاحظات خاصة... (مثال: بدون بصل)';
    const gcT = document.getElementById('goCartText');
    if(gcT) gcT.textContent = isEN ? 'View Cart' : 'عرض السلة';

    document.querySelectorAll('.dish-ingr-ar').forEach(el => el.style.display = isEN ? 'none' : 'inline-block');
    document.querySelectorAll('.dish-ingr-en').forEach(el => el.style.display = isEN ? 'inline-block' : 'none');
    document.querySelectorAll('.dish-recipe-ar').forEach(el => el.style.display = isEN ? 'none' : 'block');
    document.querySelectorAll('.dish-recipe-en').forEach(el => el.style.display = isEN ? 'block' : 'none');
    set('recipeLbl', isEN ? 'Preparation Method' : 'طريقة التحضير');

    const chip = document.getElementById('catChip');
    const chipT = document.getElementById('catChipText');
    if(chip && chipT) chipT.textContent = (isEN && chip.dataset.nameEn) ? chip.dataset.nameEn : chip.dataset.nameAr;

    document.querySelectorAll('.og-title-text').forEach(el =>
        el.textContent = isEN ? (el.dataset.nameEn||el.dataset.nameAr) : el.dataset.nameAr
    );
    document.querySelectorAll('.opt-val-name-text').forEach(el => {
        const item = el.closest('.opt-value-item');
        if(item) el.textContent = isEN ? (item.dataset.nameEn||item.dataset.nameAr) : item.dataset.nameAr;
    });
    document.querySelectorAll('.opt-req-lbl').forEach(el => {
        el.textContent = el.classList.contains('opt-required-badge')
            ? (isEN ? 'Required' : 'إلزامي')
            : (isEN ? 'Optional' : 'اختياري');
    });
    document.querySelectorAll('.opt-free-text').forEach(el => el.textContent = isEN ? 'Free' : 'مجاني');

    const dBadge = document.getElementById('discountBadgeDetail');
    if(dBadge) dBadge.textContent = isEN ? dBadge.dataset.en : dBadge.dataset.ar;
    document.querySelectorAll('.review-table-word').forEach(el => el.textContent = isEN ? 'Table' : 'طاولة');
    document.querySelectorAll('.rating-count-word').forEach(el => el.textContent = isEN ? 'reviews' : 'تقييم');
    document.querySelectorAll('.sec-title-text').forEach(el => el.textContent = isEN ? 'Customer Reviews' : 'تقييمات الزبائن');
    document.querySelectorAll('.no-rate-text').forEach(el => el.textContent = isEN ? 'Be the first to review!' : 'كن أول من يقيّم هذا الطبق!');
    const sarEl = document.getElementById('showAllReviewsText');
    if(sarEl) {
        const total = sarEl.textContent.match(/\d+/)?.[0] || '';
        sarEl.textContent = isEN ? `Show all reviews (${total})` : `عرض كل التقييمات (${total})`;
    }
    updatePriceDisplay();
    updateGoCartBtn();
}

function toggleLang() { applyLang(currentLang === 'ar' ? 'en' : 'ar'); }

function showAllReviews(btn) {
    document.querySelectorAll('.review-extra').forEach(el => {
        el.style.display = 'block';
        el.style.animation = 'cardIn 0.35s cubic-bezier(.22,1,.36,1) both';
    });
    btn.style.display = 'none';
}

updateGoCartBtn();
applyLang(currentLang);
document.addEventListener('DOMContentLoaded', () => {
    applyLang(currentLang);
    updateGoCartBtn();
});
</script>
</body>
</html>