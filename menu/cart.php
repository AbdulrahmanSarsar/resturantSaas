<?php
/**
 * cart.php — سلة الطلبات (v2 — branch-aware)
 *
 * التغييرات عن النسخة القديمة:
 *   - يقرأ branch_slug من URL
 *   - يجلب branch_id من BranchService
 *   - يكتب بـ orders مع branch_id
 *   - الروابط كلها تحمل branch_slug
 *   - redirect بعد الطلب: /menu/{slug}/{branch-slug}/order/{id}
 */
require_once __DIR__ . '/../bootstrap.php';

$slug        = $_GET['slug']   ?? '';
$branch_slug = $_GET['branch'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE slug=? AND is_active=1");
$stmt->execute([$slug]);
$restaurant = $stmt->fetch();
if(!$restaurant) { http_response_code(404); die('غير موجود'); }

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
    if (!empty($active)) {
        $branch = $active[0];
        $branch_slug = $branch['slug'];
    }
}
// لو ما في فرع مرتبط → null (branch_id صحيح من branches)
$branch_id  = $branch ? intval($branch['id']) : null;
$branch_url = $branch ? $branch['slug'] : '';

// ===== العملة (branch override أو من المطعم) =====
$cur_symbol    = ($branch['currency_symbol']    ?? null) ?: ($restaurant['currency_symbol']    ?? '$');
$cur_symbol_en = ($branch['currency_symbol_en'] ?? null) ?: ($restaurant['currency_symbol_en'] ?? $cur_symbol);
$cur_decimals  = intval(($branch['currency_decimals'] ?? null) ?: ($restaurant['currency_decimals'] ?? 2));
$cur_prefix    = in_array($cur_symbol,    ['$', '€', '₺']);
$cur_prefix_en = in_array($cur_symbol_en, ['$', '€', '₺']);

if(!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $symbol . ' ' . $formatted;
    }
}

$primary  = '#FF6B35';
$qr_table = isset($_GET['table']) ? trim($_GET['table']) : '';

// ===== الضرائب =====
// branch_taxes أولاً (per-branch)، fallback لـ restaurant_taxes
// (نفس منطق OrderService — لازم العرض يطابق الحساب)
$active_taxes = [];
if(!empty($branch['id'])) {
    $taxes_stmt = $pdo->prepare("SELECT * FROM branch_taxes WHERE branch_id=? AND is_active=1 ORDER BY sort_order");
    $taxes_stmt->execute([$branch['id']]);
    $active_taxes = $taxes_stmt->fetchAll();
}
if(empty($active_taxes)) {
    $taxes_stmt = $pdo->prepare("SELECT * FROM restaurant_taxes WHERE restaurant_id=? AND is_active=1 ORDER BY sort_order");
    $taxes_stmt->execute([$rid]);
    $active_taxes = $taxes_stmt->fetchAll();
}

// ===== POST: تسجيل الطلب =====
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['items'])) {
    $items        = json_decode($_POST['items'], true);
    $table        = trim($_POST['table_number']);
    $customer     = trim($_POST['customer_name']);
    $notes        = trim($_POST['notes']);
    $coupon_code  = trim($_POST['coupon_code'] ?? '');
    $discount_amt = floatval($_POST['discount_amount'] ?? 0);
    $post_branch  = trim($_POST['branch_slug'] ?? $branch_url);

    if(empty($items) || !$table) {
        $error = 'بيانات ناقصة';
    } else {
        $total = 0;
        foreach($items as $item) $total += floatval($item['price']) * intval($item['qty']);
        $final_total = max(0, $total - $discount_amt);

        $tax_amount  = 0;
        $tax_details = [];
        foreach($active_taxes as $atax) {
            $tamt = $atax['type']==='percentage' ? ($final_total * $atax['value'] / 100) : floatval($atax['value']);
            $tax_amount += $tamt;
            $tax_details[] = [
                'name'    => $atax['name'],
                'name_en' => $atax['name_en'] ?? '',
                'type'    => $atax['type'],
                'value'   => $atax['value'],
                'amount'  => round($tamt, 2),
            ];
        }
        $grand_total = $final_total + $tax_amount;

        // ===== كتابة الطلب — orders مع branch_id =====
        $pdo->prepare("INSERT INTO orders (restaurant_id,branch_id,table_number,customer_name,customer_notes,total_price,coupon_code,discount_amount,tax_amount,tax_details,status) VALUES (?,?,?,?,?,?,?,?,?,?,'pending')")
            ->execute([$rid,$branch_id,$table,$customer,$notes,$grand_total,$coupon_code?:null,$discount_amt,round($tax_amount,2),json_encode($tax_details)]);
        $order_id = $pdo->lastInsertId();

        foreach($items as $item) {
            $item_options = !empty($item['options']) ? json_encode($item['options']) : null;
            $item_notes   = $item['notes'] ?? '';
            $item_qty     = intval($item['qty'] ?? 1);

            if(!empty($item['is_offer'])) {
                $offer_name    = $item['name'] ?? 'عرض';
                $offer_name_en = $item['name_en'] ?? $offer_name;
                $offer_price   = floatval($item['price'] ?? 0);
                $pdo->prepare("INSERT INTO order_items (order_id,dish_id,dish_name,dish_name_en,dish_price,quantity,notes,options) VALUES (?,NULL,?,?,?,?,?,?)")
                    ->execute([$order_id, $offer_name, $offer_name_en, $offer_price, $item_qty, $item_notes, $item_options]);
                continue;
            }

            // نحاول نجيب الطبق من dishes_v2 أولاً، وإلا من dishes القديم
            $dish_row = null;
            $d_stmt = $pdo->prepare("SELECT * FROM dishes_v2 WHERE id=? AND restaurant_id=?");
            $d_stmt->execute([intval($item['id']), $rid]);
            $dish_row = $d_stmt->fetch();

            if(!$dish_row) {
                // fallback للجدول القديم
                $d_stmt = $pdo->prepare("SELECT * FROM dishes WHERE id=? AND restaurant_id=?");
                $d_stmt->execute([intval($item['id']), $rid]);
                $dish_row = $d_stmt->fetch();
            }

            if(!$dish_row) continue;

            $dp   = floatval($dish_row['discount_percent'] ?? 0);
            $da   = !empty($dish_row['discount_active']);
            $real_price = ($da && $dp > 0) ? $dish_row['price'] * (1 - $dp/100) : $dish_row['price'];
            $item_price = isset($item['price']) ? floatval($item['price']) : $real_price;
            if($item_price > $dish_row['price']) $item_price = $dish_row['price'];

            $pdo->prepare("INSERT INTO order_items (order_id,dish_id,dish_name,dish_name_en,dish_price,quantity,notes,options) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$order_id,$dish_row['id'],$dish_row['name'],$dish_row['name_en']??'',$item_price,$item_qty,$item_notes,$item_options]);
        }

        if($coupon_code) {
            $pdo->prepare("UPDATE coupons SET used_count=used_count+1 WHERE code=? AND restaurant_id=?")
                ->execute([$coupon_code, $rid]);
        }



        // ===== Redirect بعد النجاح — يحمل branch_slug =====
        $redirect_branch = $post_branch ?: $branch_url;
        if($redirect_branch) {
            header("Location: " . BASE_URL . "/menu/{$slug}/{$redirect_branch}/order/{$order_id}");
        } else {
            header("Location: " . BASE_URL . "/menu/{$slug}/order/{$order_id}");
        }
        exit;
    }
}

$taxes_for_js = array_values(array_map(fn($t) => [
    'id'      => $t['id'],
    'name'    => $t['name'],
    'name_en' => $t['name_en'] ?? '',
    'type'    => $t['type'],
    'value'   => (float)$t['value'],
], $active_taxes));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>سلة الطلبات — <?= htmlspecialchars($restaurant['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root{--p:#FF6B35;--s:#F7C59F;}
[data-theme="dark"]{--bg:#080808;--bg2:#111111;--bg3:#1a1a1a;--bg4:#222222;--ink:#F5F0E8;--ink2:#6B6560;--ink3:#3A3530;--line:rgba(245,240,232,0.06);--bar-bg:rgba(8,8,8,0.95);}
[data-theme="light"]{--bg:#F7F5F2;--bg2:#FFFFFF;--bg3:#EEEAE6;--bg4:#E4E0DC;--ink:#1A1714;--ink2:#7A7570;--ink3:#B8B4B0;--line:rgba(26,23,20,0.08);--bar-bg:rgba(247,245,242,0.96);}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--ink);max-width:480px;margin:0 auto;min-height:100vh;transition:background .3s,color .3s;}
body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");pointer-events:none;z-index:9998;}
.header{display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--bar-bg);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:100;backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);}
.back-btn{width:38px;height:38px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);color:var(--ink);display:flex;align-items:center;justify-content:center;text-decoration:none;flex-shrink:0;transition:background .2s;}
.back-btn svg{width:16px;height:16px;} [dir='rtl'] .back-btn svg{transform:scaleX(-1);}
.header-title{font-family:'Fraunces',serif;font-size:18px;font-weight:700;color:var(--ink);flex:1;letter-spacing:-.3px;}
.header-count{background:var(--p);color:#fff;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0;}
.icon-btn{width:38px;height:38px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background .2s,transform .2s;font-size:12px;font-weight:800;font-family:'Tajawal',sans-serif;color:var(--ink2);}
.icon-btn:active{transform:scale(.9);} .icon-btn svg{width:16px;height:16px;}
.icon-btn.en-active{background:var(--p);color:#fff;border-color:transparent;}
.icon-moon{display:block;}.icon-sun{display:none;}
[data-theme="light"] .icon-moon{display:none;}[data-theme="light"] .icon-sun{display:block;}
.content{padding:16px 16px 160px;}
.section-label{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:1.2px;margin:22px 0 12px;}
.section-label::after{content:'';flex:1;height:1px;background:var(--line);}
.cart-item{display:flex;align-items:center;gap:12px;background:var(--bg2);border-radius:16px;padding:12px 14px;margin-bottom:8px;border:1px solid var(--line);animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;transition:background .3s,border-color .3s;}
@keyframes cardIn{from{opacity:0;transform:translateY(12px) scale(.97);}to{opacity:1;transform:none;}}
.item-img{width:54px;height:54px;border-radius:12px;flex-shrink:0;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:24px;overflow:hidden;border:1px solid var(--line);}
.item-img img{width:100%;height:100%;object-fit:cover;display:block;}
.item-info{flex:1;min-width:0;}
.item-name{font-family:'Fraunces',serif;font-size:14px;font-weight:700;color:var(--ink);margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.item-price{font-size:13px;color:var(--p);font-weight:700;}
.item-notes{font-size:11px;color:var(--ink2);font-weight:500;margin:2px 0;padding:3px 7px;background:var(--bg3);border-radius:7px;display:inline-block;}
.item-options{display:flex;flex-wrap:wrap;gap:4px;margin:3px 0;}
.item-opt-chip{font-size:10px;font-weight:700;color:var(--p);background:color-mix(in srgb,var(--p) 10%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);padding:2px 8px;border-radius:10px;}
.tax-summary-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;}
.tax-summary-lbl{color:var(--ink2);font-weight:600;display:flex;align-items:center;gap:5px;}
.tax-type-badge{font-size:9px;font-weight:800;padding:1px 6px;border-radius:10px;background:rgba(99,102,241,.12);color:#818CF8;}
.tax-summary-amt{font-weight:700;color:#818CF8;}
.qty-wrap{display:flex;align-items:center;background:var(--bg3);border-radius:10px;border:1px solid var(--line);overflow:hidden;flex-shrink:0;}
.qty-btn{background:none;border:none;color:var(--p);font-size:18px;width:34px;height:34px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s;font-family:'Tajawal',sans-serif;}
.qty-btn:active{background:var(--bg4);}
.qty-num{font-size:13px;font-weight:800;color:var(--ink);min-width:24px;text-align:center;}
.remove-btn{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.15);color:#EF4444;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;}
.remove-btn svg{width:13px;height:13px;}
.input-field{width:100%;padding:13px 16px;background:var(--bg2);border:1.5px solid var(--line);border-radius:14px;color:var(--ink);font-size:14px;font-family:'Tajawal',sans-serif;font-weight:500;outline:none;margin-bottom:10px;transition:border-color .2s,box-shadow .2s,background .3s;}
.input-field::placeholder{color:var(--ink2);}
.input-field:focus{border-color:var(--p);box-shadow:0 0 0 3px color-mix(in srgb,var(--p) 10%,transparent);}
textarea.input-field{resize:none;height:82px;}
.table-auto-badge{display:none;align-items:center;gap:5px;font-size:11px;color:var(--p);font-weight:700;margin:-6px 0 10px 4px;}
.table-auto-badge svg{width:11px;height:11px;}
.coupon-wrap{display:flex;gap:8px;margin-bottom:8px;}
.coupon-input{flex:1;padding:13px 16px;background:var(--bg2);border:1.5px solid var(--line);border-radius:14px;color:var(--ink);font-size:13px;font-weight:800;font-family:'Tajawal',sans-serif;outline:none;text-transform:uppercase;letter-spacing:2px;transition:border-color .2s,box-shadow .2s,background .3s;}
.coupon-input::placeholder{letter-spacing:0;font-weight:400;color:var(--ink2);}
.coupon-input:focus{border-color:var(--p);box-shadow:0 0 0 3px color-mix(in srgb,var(--p) 10%,transparent);}
.coupon-apply-btn{padding:13px 18px;background:var(--bg3);border:1.5px solid var(--line);border-radius:14px;color:var(--ink);font-size:13px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;white-space:nowrap;transition:all .2s;}
.coupon-apply-btn:active{background:var(--p);border-color:var(--p);color:#fff;}
.coupon-msg{font-size:12px;font-weight:700;padding:10px 14px;border-radius:12px;margin-bottom:4px;display:none;align-items:center;gap:6px;}
.coupon-msg.success{display:flex;background:rgba(34,197,94,.1);color:#22C55E;border:1px solid rgba(34,197,94,.2);}
.coupon-msg.error{display:flex;background:rgba(239,68,68,.1);color:#EF4444;border:1px solid rgba(239,68,68,.2);}
.summary-card{background:var(--bg2);border-radius:18px;border:1px solid var(--line);overflow:hidden;margin-top:6px;}
.summary-row{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid var(--line);font-size:13px;}
.summary-label{color:var(--ink2);font-weight:500;} .summary-value{font-weight:700;color:var(--ink);}
.summary-value.discount{color:#22C55E;}
.summary-total-row{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;}
.total-label{font-family:'Fraunces',serif;font-size:16px;font-weight:700;color:var(--ink);}
.total-amount{font-family:'Fraunces',serif;font-size:26px;font-weight:900;color:var(--p);}
.empty-cart{text-align:center;padding:80px 20px;display:none;}
.empty-icon{width:80px;height:80px;border-radius:24px;background:var(--bg2);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:36px;margin:0 auto 18px;}
.empty-cart p{font-size:15px;color:var(--ink2);font-weight:600;margin-bottom:20px;}
.browse-btn{display:inline-flex;align-items:center;gap:8px;padding:13px 24px;background:var(--p);color:#fff;border-radius:14px;font-weight:800;font-size:14px;text-decoration:none;box-shadow:0 4px 16px color-mix(in srgb,var(--p) 35%,transparent);}
.browse-btn svg{width:16px;height:16px;}
.bottom-bar{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:480px;padding:12px 16px;background:var(--bar-bg);border-top:1px solid var(--line);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);z-index:100;display:none;}
.order-btn{width:100%;height:52px;background:var(--p);color:#fff;border:none;border-radius:16px;font-size:16px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;box-shadow:0 4px 20px color-mix(in srgb,var(--p) 40%,transparent);display:flex;align-items:center;justify-content:space-between;padding:0 16px 0 20px;overflow:hidden;position:relative;}
.order-btn::after{content:'';position:absolute;top:0;left:-60%;width:40%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.12),transparent);animation:shimmer 2.5s infinite;}
@keyframes shimmer{0%{left:-60%}100%{left:120%}}
.order-btn:disabled{opacity:.45;cursor:not-allowed;} .order-btn:disabled::after{display:none;}
.order-btn-label{display:flex;align-items:center;gap:8px;position:relative;z-index:1;}
.order-btn-label svg{width:18px;height:18px;}
.order-btn-total{background:rgba(255,255,255,.2);padding:5px 14px;border-radius:25px;font-size:14px;font-weight:800;position:relative;z-index:1;}
.sugg-overlay{position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.65);backdrop-filter:blur(6px);display:flex;align-items:flex-end;justify-content:center;opacity:0;pointer-events:none;transition:opacity .3s;}
.sugg-overlay.open{opacity:1;pointer-events:all;}
.sugg-sheet{width:100%;max-width:480px;background:var(--bg2);border-radius:28px 28px 0 0;transform:translateY(100%);transition:transform .42s cubic-bezier(.22,1,.36,1);overflow:hidden;}
.sugg-overlay.open .sugg-sheet{transform:translateY(0);}
.sugg-handle{width:36px;height:4px;border-radius:4px;background:var(--line);margin:12px auto 0;}
.sugg-header{display:flex;align-items:center;gap:10px;padding:14px 20px 10px;position:relative;}
.sugg-bolt{width:34px;height:34px;border-radius:10px;background:color-mix(in srgb,var(--p) 15%,transparent);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sugg-bolt svg{width:16px;height:16px;color:var(--p);}
.sugg-title{font-family:'Fraunces',serif;font-size:16px;font-weight:900;color:var(--ink);letter-spacing:-.3px;}
.sugg-subtitle{font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px;}
.sugg-close{width:32px;height:32px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:background .2s;position:absolute;top:14px;left:16px;}
.sugg-close svg{width:13px;height:13px;color:var(--ink2);}
.sugg-cards{display:flex;gap:11px;padding:8px 16px 16px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.sugg-cards::-webkit-scrollbar{display:none;}
.sugg-card{flex-shrink:0;width:calc(50% - 6px);min-width:155px;background:var(--bg3);border:1.5px solid var(--line);border-radius:18px;overflow:hidden;display:flex;flex-direction:column;}
.sugg-card-img{height:110px;background:var(--bg4);display:flex;align-items:center;justify-content:center;font-size:40px;overflow:hidden;flex-shrink:0;}
.sugg-card-img img{width:100%;height:100%;object-fit:cover;}
.sugg-card-body{padding:10px 12px 12px;display:flex;flex-direction:column;flex:1;}
.sugg-card-name{font-family:'Fraunces',serif;font-size:13px;font-weight:700;color:var(--ink);line-height:1.3;margin-bottom:3px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.sugg-card-desc{font-size:10px;color:var(--ink2);font-weight:500;line-height:1.4;margin-bottom:8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;flex-grow:1;}
.sugg-card-price{font-family:'Fraunces',serif;font-size:15px;font-weight:900;color:var(--p);margin-bottom:9px;}
.sugg-add-btn{width:100%;padding:9px 0;background:var(--p);color:#fff;border:none;border-radius:12px;font-size:13px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;display:flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 4px 12px color-mix(in srgb,var(--p) 35%,transparent);}
.sugg-add-btn svg{width:13px;height:13px;}
.sugg-add-btn.added{background:#22C55E;box-shadow:0 4px 12px rgba(34,197,94,.3);}
.sugg-skip{text-align:center;padding:4px 16px 18px;}
.sugg-skip button{background:none;border:none;cursor:pointer;font-size:12px;font-weight:700;color:var(--ink2);font-family:'Tajawal',sans-serif;padding:8px 16px;}
</style>
</head>
<body>

<!-- SUGGESTIONS POPUP -->
<div class="sugg-overlay" id="suggOverlay">
    <div class="sugg-sheet">
        <div class="sugg-handle"></div>
        <div class="sugg-header">
            <div class="sugg-bolt">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <div>
                <div class="sugg-title" id="suggTitle">ممكن يعجبك كمان</div>
                <div class="sugg-subtitle" id="suggSub">زباين طلبوا هالأطباق مع طلبك</div>
            </div>
            <button class="sugg-close" onclick="closeSugg()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="sugg-cards" id="suggCards"></div>
        <div class="sugg-skip">
            <button id="suggSkipBtn" onclick="closeSugg()">لا شكراً، السلة كافية</button>
        </div>
    </div>
</div>

<!-- HEADER — back button يحمل branch_slug -->
<div class="header">
    <a href="<?= BASE_URL ?>/menu/<?= $slug ?>/<?= $branch_url ?>" class="back-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M15 18l-6-6 6-6"/></svg>
    </a>
    <div class="header-title" id="headerTitle">سلة الطلبات</div>
    <div class="header-count" id="headerCount">0</div>
    <button class="icon-btn" id="langBtn" onclick="toggleLang()">EN</button>
    <button class="icon-btn" onclick="toggleTheme()">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
    </button>
</div>

<div class="content">
    <div class="empty-cart" id="emptyState">
        <div class="empty-icon">🛒</div>
        <p id="emptyText">سلتك فارغة!</p>
        <!-- browse btn يحمل branch_slug -->
        <a href="<?= BASE_URL ?>/menu/<?= $slug ?>/<?= $branch_url ?>" class="browse-btn" id="browseBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M3 2l3 7H6a3 3 0 003 3v9a1 1 0 001 1h4a1 1 0 001-1v-9a3 3 0 003-3h-3l3-7"/></svg>
            تصفح المنيو
        </a>
    </div>

    <div id="cartSection">
        <div class="section-label" id="lblItems">الأصناف المختارة</div>
        <div id="cartItemsList"></div>

        <div class="section-label" id="lblDetails">بيانات الطلب</div>
        <input type="text" class="input-field" id="tableNumber" placeholder="رقم الطاولة *">
        <div class="table-auto-badge" id="tableAutoBadge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span id="qrAutoText">تم تحديده تلقائياً من QR Code</span>
        </div>
        <input type="text" class="input-field" id="customerName" placeholder="اسمك (اختياري)">
        <textarea class="input-field" id="orderNotes" placeholder="ملاحظات إضافية..."></textarea>

        <div class="section-label" id="lblCoupon">كوبون الخصم</div>
        <div class="coupon-wrap">
            <input type="text" class="coupon-input" id="couponInput" placeholder="كود الخصم...">
            <button class="coupon-apply-btn" id="applyBtn" onclick="applyCoupon()">تطبيق</button>
        </div>
        <div class="coupon-msg" id="couponMsg"></div>

        <div class="section-label" id="lblSummary">ملخص الطلب</div>
        <div class="summary-card">
            <div class="summary-row">
                <span class="summary-label" id="lblSubtotal">المجموع الفرعي</span>
                <span class="summary-value" id="subtotalVal">$0.00</span>
            </div>
            <div class="summary-row" id="discountRow" style="display:none;">
                <span class="summary-label" id="lblDiscount">خصم الكوبون</span>
                <span class="summary-value discount" id="discountVal">-$0.00</span>
            </div>
            <div id="taxRowsContainer" style="padding:0 4px;"></div>
            <div class="summary-total-row">
                <span class="total-label" id="lblTotal">الإجمالي</span>
                <span class="total-amount" id="totalVal">$0.00</span>
            </div>
        </div>
    </div>
</div>

<div class="bottom-bar" id="bottomBar">
    <button class="order-btn" id="orderBtn" onclick="submitOrder()">
        <span class="order-btn-label" id="orderBtnLabel">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            تأكيد الطلب
        </span>
        <span class="order-btn-total" id="orderBtnTotal">$0.00</span>
    </button>
</div>

<!-- hidden form يحمل branch_slug -->
<form method="POST" id="orderForm" style="display:none;">
    <input type="hidden" name="items"           id="formItems">
    <input type="hidden" name="table_number"    id="formTable">
    <input type="hidden" name="customer_name"   id="formCustomer">
    <input type="hidden" name="notes"           id="formNotes">
    <input type="hidden" name="coupon_code"     id="formCoupon">
    <input type="hidden" name="discount_amount" id="formDiscount">
    <input type="hidden" name="branch_slug"     value="<?= htmlspecialchars($branch_url) ?>">
</form>

<script>
const SLUG         = '<?= $slug ?>';
const BRANCH_SLUG  = '<?= $branch_url ?>';
const CUR_SYMBOL    = '<?= addslashes($cur_symbol) ?>';
const CUR_SYMBOL_EN = '<?= addslashes($cur_symbol_en) ?>';
const CUR_DECIMALS  = <?= $cur_decimals ?>;
const CUR_PREFIX    = <?= $cur_prefix ? 'true' : 'false' ?>;
const CUR_PREFIX_EN = <?= $cur_prefix_en ? 'true' : 'false' ?>;

function fmtPrice(amount) {
    const isEN   = (typeof currentLang !== 'undefined' && currentLang === 'en');
    const sym    = isEN ? CUR_SYMBOL_EN : CUR_SYMBOL;
    const prefix = isEN ? CUR_PREFIX_EN : CUR_PREFIX;
    const n = parseFloat(amount).toFixed(CUR_DECIMALS);
    return prefix ? sym + n : sym + ' ' + n;
}

const RESTAURANT_ID = <?= $rid ?>;
const BASE_URL      = '<?= BASE_URL ?>';
const STORAGE_KEY   = 'cart_' + SLUG;
const TAXES         = <?= json_encode($taxes_for_js, JSON_UNESCAPED_UNICODE) ?>;
const LANG_KEY      = 'menu_lang_' + SLUG;
const THEME_KEY     = 'menu_theme_' + SLUG;
const SUGG_KEY      = 'sugg_shown_' + SLUG;
const QR_TABLE      = '<?= htmlspecialchars($qr_table) ?>';

const TR = {
    ar: {
        cart_title:'سلة الطلبات',cart_empty:'سلتك فارغة!',browse_menu:'تصفح المنيو',
        lbl_items:'الأصناف المختارة',lbl_details:'بيانات الطلب',lbl_coupon:'كوبون الخصم',
        lbl_summary:'ملخص الطلب',lbl_subtotal:'المجموع الفرعي',lbl_discount:'خصم الكوبون',
        lbl_total:'الإجمالي',table_ph:'رقم الطاولة *',table_req:'⚠️ رقم الطاولة مطلوب',
        name_ph:'اسمك (اختياري)',notes_ph:'ملاحظات إضافية...',coupon_ph:'كود الخصم...',
        apply:'تطبيق',confirm_order:'تأكيد الطلب',sending:'جاري إرسال الطلب...',
        qr_auto:'تم تحديده تلقائياً من QR Code',add:'أضف للسلة',added:'تمت الإضافة!',
        no_thanks:'لا شكراً، السلة كافية',you_may_like:'ممكن يعجبك كمان',
        others_ordered:'زباين طلبوا هالأطباق مع طلبك',dir:'rtl',lang:'ar',
    },
    en: {
        cart_title:'Your Cart',cart_empty:'Your cart is empty!',browse_menu:'Browse Menu',
        lbl_items:'Selected Items',lbl_details:'Order Details',lbl_coupon:'Discount Coupon',
        lbl_summary:'Order Summary',lbl_subtotal:'Subtotal',lbl_discount:'Coupon Discount',
        lbl_total:'Total',table_ph:'Table Number *',table_req:'⚠️ Table number is required',
        name_ph:'Your name (optional)',notes_ph:'Additional notes...',coupon_ph:'Coupon code...',
        apply:'Apply',confirm_order:'Place Order',sending:'Sending order...',
        qr_auto:'Auto-detected from QR Code',add:'Add',added:'Added!',
        no_thanks:'No thanks',you_may_like:'You Might Also Like',
        others_ordered:'Customers ordered these with similar orders',dir:'ltr',lang:'en',
    }
};

let currentLang   = localStorage.getItem(LANG_KEY) || 'ar';
let appliedCoupon = null;
let cart          = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');

function t(k) { return TR[currentLang][k] || TR.ar[k] || k; }

(function(){ document.documentElement.setAttribute('data-theme', localStorage.getItem(THEME_KEY) || 'light'); })();
function toggleTheme(){
    const n = document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',n);
    localStorage.setItem(THEME_KEY,n);
}

function applyLang(lang) {
    currentLang = lang;
    localStorage.setItem(LANG_KEY, lang);
    const T = TR[lang];
    document.documentElement.setAttribute('dir', T.dir);
    document.documentElement.setAttribute('lang', T.lang);
    const btn = document.getElementById('langBtn');
    if(btn){ btn.textContent=lang==='en'?'AR':'EN'; btn.classList.toggle('en-active',lang==='en'); }
    setText('headerTitle',  T.cart_title);
    setText('emptyText',    T.cart_empty);
    setText('lblItems',     T.lbl_items);
    setText('lblDetails',   T.lbl_details);
    setText('lblCoupon',    T.lbl_coupon);
    setText('lblSummary',   T.lbl_summary);
    setText('lblSubtotal',  T.lbl_subtotal);
    setText('lblDiscount',  T.lbl_discount);
    setText('lblTotal',     T.lbl_total);
    setText('applyBtn',     T.apply);
    setText('qrAutoText',   T.qr_auto);
    setText('suggTitle',    T.you_may_like);
    setText('suggSub',      T.others_ordered);
    setText('suggSkipBtn',  T.no_thanks);
    setPlaceholder('tableNumber',  T.table_ph);
    setPlaceholder('customerName', T.name_ph);
    setPlaceholder('orderNotes',   T.notes_ph);
    setPlaceholder('couponInput',  T.coupon_ph);
    const lbl = document.getElementById('orderBtnLabel');
    if(lbl) lbl.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>${T.confirm_order}`;
    const bb = document.getElementById('browseBtn');
    if(bb) bb.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M3 2l3 7H6a3 3 0 003 3v9a1 1 0 001 1h4a1 1 0 001-1v-9a3 3 0 003-3h-3l3-7"/></svg>${T.browse_menu}`;
    renderCart();
}

function setText(id, val){ const el=document.getElementById(id); if(el) el.textContent=val; }
function setPlaceholder(id, val){ const el=document.getElementById(id); if(el) el.placeholder=val; }
function toggleLang(){ applyLang(currentLang==='ar'?'en':'ar'); }

function renderCart() {
    const list = document.getElementById('cartItemsList');
    list.innerHTML = '';
    if(cart.length === 0) {
        document.getElementById('emptyState').style.display  = 'block';
        document.getElementById('cartSection').style.display = 'none';
        document.getElementById('bottomBar').style.display   = 'none';
        return;
    }
    document.getElementById('emptyState').style.display  = 'none';
    document.getElementById('cartSection').style.display = 'block';
    document.getElementById('bottomBar').style.display   = 'block';
    cart.forEach((item, idx) => {
        const div = document.createElement('div');
        div.className = 'cart-item';
        div.style.animationDelay = (idx*.05)+'s';
        const displayName = (currentLang==='en' && item.name_en) ? item.name_en : item.name;
        const notesHtml = item.notes ? `<div class="item-notes">📝 ${item.notes}</div>` : '';
        const optsHtml  = item.options && item.options.length > 0
            ? '<div class="item-options">'+item.options.map(o=>`<span class="item-opt-chip">${o.name}</span>`).join('')+'</div>'
            : '';
        div.innerHTML = `
            <div class="item-img">
                ${item.image?`<img src="${BASE_URL}/assets/uploads/dishes/${item.image}" loading="lazy">`:'🍔'}
            </div>
            <div class="item-info">
                <div class="item-name">${displayName}</div>
                ${optsHtml}${notesHtml}
                <div class="item-price">${fmtPrice(parseFloat(item.price||0)*parseInt(item.qty||1))}</div>
            </div>
            <div class="qty-wrap">
                <button class="qty-btn" onclick="changeQty(${idx},-1)">−</button>
                <span class="qty-num">${item.qty}</span>
                <button class="qty-btn" onclick="changeQty(${idx},1)">+</button>
            </div>
            <button class="remove-btn" onclick="removeItem(${idx})">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>`;
        list.appendChild(div);
    });
    updateSummary();
    document.getElementById('headerCount').textContent = cart.reduce((s,i)=>s+i.qty,0);
    if(cart.length) fetchAndShowSuggestions();
}

function loadSavedTable() {
    const inp   = document.getElementById('tableNumber');
    const badge = document.getElementById('tableAutoBadge');
    if(QR_TABLE) {
        inp.value = QR_TABLE; inp.setAttribute('readonly',true);
        inp.style.borderColor = 'var(--p)'; badge.style.display = 'flex';
        sessionStorage.setItem('table_'+SLUG, QR_TABLE);
    } else {
        const saved = sessionStorage.getItem('table_'+SLUG);
        if(saved) {
            inp.value = saved; inp.setAttribute('readonly',true);
            inp.style.borderColor = 'var(--p)'; badge.style.display = 'flex';
        }
    }
}

function changeQty(idx, delta){ cart[idx].qty=Math.max(1,cart[idx].qty+delta); save(); renderCart(); }
function removeItem(idx){ cart.splice(idx,1); if(!cart.length) appliedCoupon=null; save(); renderCart(); }
function save(){ localStorage.setItem(STORAGE_KEY, JSON.stringify(cart)); }
function getSubtotal(){ return cart.reduce((s,i)=>s+(parseFloat(i.price)||0)*(parseInt(i.qty)||0),0); }

function calcTaxes(baseAmount) {
    let total = 0;
    TAXES.forEach(tax => {
        total += tax.type==='percentage' ? (baseAmount*tax.value/100) : tax.value;
    });
    return total;
}

function updateSummary(){
    const sub  = getSubtotal();
    const disc = appliedCoupon ? appliedCoupon.discount : 0;
    const afterDisc = Math.max(0, sub - disc);
    const taxAmt = calcTaxes(afterDisc);
    const tot  = afterDisc + taxAmt;
    document.getElementById('subtotalVal').textContent   = fmtPrice(sub);
    document.getElementById('totalVal').textContent      = fmtPrice(tot);
    document.getElementById('orderBtnTotal').textContent = fmtPrice(tot);
    const dr = document.getElementById('discountRow');
    if(disc>0){ dr.style.display='flex'; document.getElementById('discountVal').textContent='-'+fmtPrice(disc); }
    else dr.style.display='none';
    const taxContainer = document.getElementById('taxRowsContainer');
    if(taxContainer) {
        if(TAXES.length > 0 && afterDisc > 0) {
            taxContainer.innerHTML = TAXES.map(tax => {
                const amt = tax.type==='percentage' ? (afterDisc*tax.value/100) : tax.value;
                const lbl = currentLang==='en' && tax.name_en ? tax.name_en : tax.name;
                const badge = tax.type==='percentage' ? `${tax.value}%` : fmtPrice(parseFloat(tax.value));
                return `<div class="tax-summary-row">
                    <span class="tax-summary-lbl">${lbl} <span class="tax-type-badge">${badge}</span></span>
                    <span class="tax-summary-amt">+${fmtPrice(amt)}</span>
                </div>`;
            }).join('');
        } else {
            taxContainer.innerHTML = '';
        }
    }
}

document.getElementById('couponInput').addEventListener('input', function(){ this.value=this.value.toUpperCase(); });

function applyCoupon(){
    const code = document.getElementById('couponInput').value.trim().toUpperCase();
    const msg  = document.getElementById('couponMsg');
    if(!code){ msg.className='coupon-msg error'; msg.textContent='⚠️ '+(currentLang==='en'?'Enter coupon code first':'أدخل كود الكوبون أولاً'); return; }
    const btn = document.getElementById('applyBtn');
    btn.textContent='...'; btn.disabled=true;
    fetch(`${BASE_URL}/menu/validate_coupon.php`,{
        method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`code=${code}&restaurant_id=${RESTAURANT_ID}&total=${getSubtotal()}`
    })
    .then(r=>r.json())
    .then(data=>{
        btn.textContent=t('apply'); btn.disabled=false;
        if(data.success){ appliedCoupon=data; msg.className='coupon-msg success'; msg.textContent='✅ '+data.message; }
        else{ appliedCoupon=null; msg.className='coupon-msg error'; msg.textContent='❌ '+data.message; }
        updateSummary();
    })
    .catch(()=>{ btn.textContent=t('apply'); btn.disabled=false; });
}

function submitOrder(){
    const tableFromQR = QR_TABLE || sessionStorage.getItem('table_'+SLUG);
    const table = tableFromQR || document.getElementById('tableNumber').value.trim();
    if(!table){
        const inp = document.getElementById('tableNumber');
        inp.focus(); inp.style.borderColor='#EF4444';
        inp.placeholder = t('table_req');
        setTimeout(()=>{ inp.style.borderColor=''; inp.placeholder=t('table_ph'); }, 3000);
        return;
    }
    if(!cart.length) return;
    const btn = document.getElementById('orderBtn');
    btn.disabled=true;
    document.getElementById('orderBtnLabel').innerHTML =
        `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>${t('sending')}`;
    document.getElementById('formItems').value    = JSON.stringify(cart);
    document.getElementById('formTable').value    = table;
    document.getElementById('formCustomer').value = document.getElementById('customerName').value;
    document.getElementById('formNotes').value    = document.getElementById('orderNotes').value;
    document.getElementById('formCoupon').value   = appliedCoupon ? appliedCoupon.coupon_code : '';
    document.getElementById('formDiscount').value = appliedCoupon ? appliedCoupon.discount    : 0;
    localStorage.removeItem(STORAGE_KEY);
    document.getElementById('orderForm').submit();
}

// SUGGESTIONS
function fetchAndShowSuggestions(){
    if(sessionStorage.getItem(SUGG_KEY) || !cart.length) return;
    fetch(`${BASE_URL}/menu/get_suggestions.php`,{
        method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`restaurant_id=${RESTAURANT_ID}&cart_ids=${encodeURIComponent(JSON.stringify(cart.map(i=>i.id)))}`
    })
    .then(r=>r.json())
    .then(data=>{
        if(data.suggestions && data.suggestions.length){
            buildSuggCards(data.suggestions);
            setTimeout(()=>{ document.getElementById('suggOverlay').classList.add('open'); sessionStorage.setItem(SUGG_KEY,'1'); }, 700);
        }
    })
    .catch(()=>{});
}

function buildSuggCards(suggestions){
    const wrap = document.getElementById('suggCards');
    wrap.innerHTML='';
    suggestions.forEach(dish=>{
        const displayName = (currentLang==='en' && dish.name_en) ? dish.name_en : dish.name;
        const displayDesc = (currentLang==='en' && dish.description_en) ? dish.description_en : dish.description;
        const card = document.createElement('div');
        card.className='sugg-card';
        card.innerHTML=`
            <div class="sugg-card-img">${dish.image?`<img src="${BASE_URL}/assets/uploads/dishes/${dish.image}" loading="lazy">`:'🍔'}</div>
            <div class="sugg-card-body">
                <div class="sugg-card-name">${displayName}</div>
                ${displayDesc?`<div class="sugg-card-desc">${displayDesc}</div>`:''}
                <div class="sugg-card-price">${fmtPrice(parseFloat(dish.price))}</div>
                <button class="sugg-add-btn" onclick="addSuggToCart(this,${dish.suggested_dish_id},'${dish.name.replace(/'/g,"\\'")}',${dish.price},'${dish.image||''}','${(dish.name_en||'').replace(/'/g,"\\'")}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    ${t('add')}
                </button>
            </div>`;
        wrap.appendChild(card);
    });
}

function addSuggToCart(btn, id, name, price, image, name_en){
    const ex = cart.find(i=>i.id==id);
    if(ex) ex.qty++; else cart.push({id,name,name_en:name_en||'',price:parseFloat(price),image,qty:1});
    save();
    btn.classList.add('added');
    btn.innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>${t('added')}`;
    btn.disabled=true;
    renderCart();
    const allAdded=document.querySelectorAll('.sugg-add-btn.added').length;
    if(allAdded>=document.querySelectorAll('.sugg-add-btn').length) setTimeout(closeSugg,900);
}

function closeSugg(){ document.getElementById('suggOverlay').classList.remove('open'); }
document.getElementById('suggOverlay').addEventListener('click',function(e){ if(e.target===this) closeSugg(); });

document.addEventListener('DOMContentLoaded', function(){
    loadSavedTable();
    applyLang(currentLang);
    if(cart.length) setTimeout(fetchAndShowSuggestions, 200);
});
</script>
</body>
</html>