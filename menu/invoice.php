<?php
require_once '../config/database.php';

$slug     = $_GET['slug']  ?? '';
$order_id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE slug=? AND is_active=1");
$stmt->execute([$slug]);
$restaurant = $stmt->fetch();
if(!$restaurant) die('غير موجود');


// ===== العملة =====
$cur_symbol    = $restaurant['currency_symbol']    ?? '$';
$cur_symbol_en = $restaurant['currency_symbol_en'] ?? $restaurant['currency_symbol'] ?? '$';
$cur_decimals  = intval($restaurant['currency_decimals'] ?? 2);
$cur_prefix    = in_array($cur_symbol, ['$', '\u20ac', '\u20ba']);
$cur_prefix_en = in_array($cur_symbol_en, ['$', '\u20ac', '\u20ba']);
if(!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $symbol . ' ' . $formatted;
    }
}


$order = $pdo->prepare("SELECT * FROM orders WHERE id=? AND restaurant_id=?");
$order->execute([$order_id, $restaurant['id']]);
$order = $order->fetch();
if(!$order) die('الطلب غير موجود');

// ضرائب الطلب
$order_taxes = [];
if(!empty($order['tax_details'])) {
    $order_taxes = json_decode($order['tax_details'], true) ?: [];
}

$items = $pdo->prepare("SELECT oi.*, COALESCE(oi.dish_name_en, d.name_en, '') as dish_name_en_resolved
    FROM order_items oi
    LEFT JOIN dishes d ON d.id = oi.dish_id
    WHERE oi.order_id=? ORDER BY oi.id");
$items->execute([$order_id]);
$items = $items->fetchAll();

$primary   = '#FF6B35';
$secondary = '#F7C59F';
$shamcash_enabled = !empty($restaurant['shamcash_enabled']);
$shamcash_number  = $restaurant['shamcash_number'] ?? '';
$shamcash_qr      = $restaurant['shamcash_qr'] ?? '';

// ===== العملة =====
$cur_symbol   = $restaurant['currency_symbol']   ?? '$';
$cur_decimals = intval($restaurant['currency_decimals'] ?? 2);
$cur_prefix   = in_array($cur_symbol, ['$', '€', '₺']);
if(!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $symbol . ' ' . $formatted;
    }
}

$subtotal = array_sum(array_map(fn($i) => $i['dish_price'] * $i['quantity'], $items));
$discount = floatval($order['discount_amount'] ?? 0);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $display_order_num = $order['branch_order_number'] ?? $order['restaurant_order_number'] ?? $order_id; ?>
<title>فاتورة #<?= $display_order_num ?> — <?= htmlspecialchars($restaurant['name']) ?></title>
<script>(function(){ var t=localStorage.getItem('menu_theme_<?= $slug ?>')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root{--p:<?= $primary ?>;--s:<?= $secondary ?>;}
[data-theme="dark"]{--bg:#080808;--bg2:#111111;--bg3:#1a1a1a;--bg4:#222222;--ink:#F5F0E8;--ink2:#6B6560;--ink3:#3A3530;--line:rgba(245,240,232,0.06);--bar-bg:rgba(8,8,8,0.95);}
[data-theme="light"]{--bg:#F7F5F2;--bg2:#FFFFFF;--bg3:#EEEAE6;--bg4:#E4E0DC;--ink:#1A1714;--ink2:#7A7570;--ink3:#B8B4B0;--line:rgba(26,23,20,0.08);--bar-bg:rgba(247,245,242,0.96);}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--ink);max-width:480px;margin:0 auto;min-height:100vh;padding-bottom:48px;transition:background .3s,color .3s;}
body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");pointer-events:none;z-index:9999;}

/* HEADER */
.inv-header{position:relative;padding:0 0 28px;overflow:hidden;background:var(--bg2);border-bottom:1px solid var(--line);text-align:center;transition:background .3s,border-color .3s;}
.inv-orb{position:absolute;border-radius:50%;filter:blur(55px);pointer-events:none;}
.inv-orb-1{width:240px;height:240px;background:var(--p);opacity:.12;top:-100px;right:-60px;animation:orbDrift 10s ease-in-out infinite;}
.inv-orb-2{width:160px;height:160px;background:var(--s);opacity:.1;bottom:-60px;left:-40px;animation:orbDrift 8s ease-in-out infinite reverse;}
@keyframes orbDrift{0%,100%{transform:translate(0,0) scale(1);}50%{transform:translate(14px,-14px) scale(1.08);}}
.inv-header-topbar{display:flex;justify-content:flex-end;align-items:center;gap:8px;padding:14px 16px 0;position:relative;z-index:2;}
.icon-btn{width:36px;height:36px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .2s,transform .3s;font-size:12px;font-weight:800;font-family:'Tajawal',sans-serif;}
.icon-btn:active{transform:scale(.9);}
.icon-btn svg{width:15px;height:15px;}
.icon-btn.en-active{background:var(--p);color:#fff;border-color:transparent;}
.icon-moon{display:block;}.icon-sun{display:none;}
[data-theme="light"] .icon-moon{display:none;}[data-theme="light"] .icon-sun{display:block;}
.inv-logo{position:relative;z-index:2;width:72px;height:72px;border-radius:20px;object-fit:cover;border:1.5px solid rgba(255,255,255,.1);box-shadow:0 8px 28px rgba(0,0,0,.4);margin:12px auto 14px;display:block;}
.inv-logo-placeholder{position:relative;z-index:2;width:72px;height:72px;border-radius:20px;background:linear-gradient(135deg,var(--p),var(--s));display:flex;align-items:center;justify-content:center;font-size:32px;box-shadow:0 8px 28px rgba(0,0,0,.3);margin:12px auto 14px;}
.inv-rest-name{position:relative;z-index:2;font-family:'Fraunces',serif;font-size:22px;font-weight:900;color:var(--ink);letter-spacing:-0.4px;margin-bottom:5px;}
.inv-rest-address{position:relative;z-index:2;font-size:12px;color:var(--ink2);font-weight:500;display:flex;align-items:center;justify-content:center;gap:4px;}
.inv-rest-address svg{width:11px;height:11px;flex-shrink:0;}

/* ID STRIP */
.inv-id-strip{margin:14px 16px 0;background:var(--p);border-radius:14px;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 18px color-mix(in srgb,var(--p) 35%,transparent);}
.inv-id-label{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:#fff;letter-spacing:-0.2px;display:flex;align-items:center;gap:7px;}
.inv-id-label svg{width:16px;height:16px;opacity:.85;}
.inv-id-date{font-size:12px;font-weight:600;color:rgba(255,255,255,.75);}

/* CHIPS */
.inv-chips{display:flex;flex-wrap:wrap;gap:7px;padding:14px 16px;}
.inv-chip{display:flex;align-items:center;gap:5px;background:var(--bg3);border:1px solid var(--line);padding:6px 13px;border-radius:20px;font-size:12px;font-weight:600;color:var(--ink2);}
.inv-chip svg{width:12px;height:12px;}
.inv-chip strong{color:var(--ink);font-weight:800;}
.inv-chip-delivered{background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.2);color:#22C55E;}

/* ITEMS CARD */
.inv-card{margin:0 16px;background:var(--bg2);border-radius:18px;border:1px solid var(--line);overflow:hidden;transition:background .3s,border-color .3s;}
.inv-card-title{padding:12px 18px;border-bottom:1px solid var(--line);font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:1.2px;display:flex;align-items:center;gap:8px;}
.inv-card-title::after{content:'';flex:1;height:1px;background:var(--line);}
.inv-item{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid var(--line);gap:12px;animation:fadeUp .35s cubic-bezier(.22,1,.36,1) both;transition:border-color .3s;}
.inv-item:last-of-type{border-bottom:none;}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
.inv-item-qty{width:28px;height:28px;border-radius:8px;background:color-mix(in srgb,var(--p) 12%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);color:var(--p);font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.inv-item-info{flex:1;min-width:0;}
.inv-item-name{font-family:'Fraunces',serif;font-size:14px;font-weight:700;color:var(--ink);letter-spacing:-0.2px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.inv-item-unit{font-size:11px;color:var(--ink2);font-weight:600;}
.inv-item-total{font-family:'Fraunces',serif;font-size:16px;font-weight:900;color:var(--p);letter-spacing:-0.3px;flex-shrink:0;}

/* TOTALS */
.inv-totals{background:var(--bg3);transition:background .3s;}
.inv-total-row{display:flex;justify-content:space-between;align-items:center;padding:11px 18px;font-size:13px;border-bottom:1px solid var(--line);}
.inv-total-row:last-child{border-bottom:none;}
.inv-total-label{color:var(--ink2);font-weight:500;}
.inv-total-value{font-weight:700;color:var(--ink);}
.inv-total-value.discount{color:#22C55E;}
.inv-grand-row{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;background:var(--bg2);border-top:1.5px solid var(--line);}
.inv-grand-label{font-family:'Fraunces',serif;font-size:17px;font-weight:700;color:var(--ink);}
.inv-grand-amount{font-family:'Fraunces',serif;font-size:30px;font-weight:900;color:var(--p);letter-spacing:-0.5px;}

/* THANK YOU */
.inv-thankyou{margin:14px 16px 0;background:var(--bg2);border-radius:18px;border:1px solid var(--line);padding:26px 20px;text-align:center;transition:background .3s,border-color .3s;}
.ty-icon{width:64px;height:64px;border-radius:20px;background:color-mix(in srgb,var(--p) 10%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;}
.ty-title{font-family:'Fraunces',serif;font-size:20px;font-weight:900;color:var(--ink);letter-spacing:-0.3px;margin-bottom:7px;}
.ty-sub{font-size:13px;color:var(--ink2);line-height:1.7;font-weight:500;}

/* PAYMENT */
.pay-card{margin:14px 16px 0;background:var(--bg2);border:1px solid var(--line);border-radius:18px;overflow:hidden;}
.pay-card-title{padding:12px 18px;border-bottom:1px solid var(--line);font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:1.2px;}
.pay-card-body{padding:14px 16px;display:flex;flex-direction:column;gap:9px;}
.pay-opt{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--bg3);border:2px solid var(--line);border-radius:14px;cursor:pointer;font-family:'Tajawal',sans-serif;text-align:right;transition:all .2s;width:100%;}
.pay-opt-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;}
.pay-opt-title{font-size:14px;font-weight:800;color:var(--ink);}
.pay-opt-sub{font-size:11px;color:var(--ink2);margin-top:2px;}
.pay-radio{margin-right:auto;width:20px;height:20px;border-radius:50%;border:2px solid var(--line);background:var(--bg4);transition:all .2s;flex-shrink:0;}
.sham-details{display:none;padding:16px 18px;border-top:1px solid var(--line);background:color-mix(in srgb,#FF6B35 4%,transparent);text-align:center;}
.sham-amount{font-family:'Fraunces',serif;font-size:28px;font-weight:900;color:#FF6B35;margin-bottom:12px;}
.sham-send-lbl{font-size:12px;font-weight:700;color:var(--ink2);margin-bottom:6px;}
.sham-num-row{display:flex;align-items:center;justify-content:space-between;background:var(--bg3);border:1px solid var(--line);border-radius:12px;padding:12px 16px;margin-bottom:10px;text-align:right;}
.sham-num-lbl{font-size:10px;font-weight:700;color:var(--ink2);margin-bottom:3px;}
.sham-num{font-family:'Fraunces',serif;font-size:18px;font-weight:900;color:var(--ink);}
.copy-btn{padding:8px 14px;background:var(--bg4);border:1px solid var(--line);border-radius:9px;font-size:12px;font-weight:700;color:var(--ink2);cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;}
.copy-btn:hover{background:var(--p);color:#fff;border-color:transparent;}
.sham-qr{width:160px;height:160px;border-radius:16px;border:1px solid var(--line);object-fit:cover;}
.sham-scan{font-size:11px;color:var(--ink2);font-weight:600;margin-top:6px;}

/* CASH ONLY */
.cash-only{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:16px;padding:16px 18px;display:flex;align-items:center;gap:12px;margin:14px 16px 0;}
.cash-only-icon{font-size:28px;}
.cash-only-title{font-size:14px;font-weight:800;color:#22C55E;}
.cash-only-sub{font-size:12px;color:var(--ink2);margin-top:2px;}

/* ACTIONS */
.inv-actions{margin:14px 16px 0;display:flex;flex-direction:column;gap:9px;}
.btn-primary{display:flex;align-items:center;justify-content:center;gap:8px;padding:15px;background:var(--p);color:#fff;border-radius:16px;font-size:15px;font-weight:800;text-decoration:none;box-shadow:0 4px 18px color-mix(in srgb,var(--p) 35%,transparent);transition:transform .2s;}
.btn-primary:active{transform:scale(.98);}
.btn-primary svg{width:18px;height:18px;}
.btn-secondary{display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;background:var(--bg3);border:1.5px solid var(--line);color:var(--ink2);border-radius:16px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;transition:background .2s,border-color .3s;}
.btn-secondary:active{background:var(--bg4);}
.btn-secondary svg{width:16px;height:16px;}

/* FOOTER */
.inv-footer{text-align:center;padding:22px 20px 10px;font-size:12px;color:var(--ink3);font-weight:600;}
.inv-footer-brand{display:inline-block;margin-top:5px;font-size:13px;font-weight:800;background:linear-gradient(135deg,var(--p),var(--s));-webkit-background-clip:text;-webkit-text-fill-color:transparent;}

/* PRINT */
@media print{
    body{background:#fff;color:#000;max-width:100%;}
    body::after{display:none;}
    .inv-actions,.inv-thankyou,.icon-btn,.inv-footer,.pay-card,.cash-only{display:none!important;}
    .inv-card,.inv-header,.inv-totals,.inv-grand-row{background:#fff!important;border-color:#eee!important;color:#000!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .inv-id-strip{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>
</head>
<body>

<!-- HEADER -->
<div class="inv-header">
    <div class="inv-orb inv-orb-1"></div>
    <div class="inv-orb inv-orb-2"></div>
    <div class="inv-header-topbar">
        <button class="icon-btn" id="langBtn" onclick="toggleLang()">EN</button>
        <button class="icon-btn" onclick="toggleTheme()">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        </button>
    </div>
    <?php if($restaurant['logo']): ?>
    <img src="<?= BASE_URL ?>/assets/uploads/<?= $restaurant['logo'] ?>" class="inv-logo" alt="">
    <?php else: ?>
    <div class="inv-logo-placeholder">🍽️</div>
    <?php endif; ?>
    <div class="inv-rest-name"><?= htmlspecialchars($restaurant['name']) ?></div>
    <?php if($restaurant['address']): ?>
    <div class="inv-rest-address">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <?= htmlspecialchars($restaurant['address']) ?>
    </div>
    <?php endif; ?>
</div>

<!-- ID STRIP -->
<div class="inv-id-strip">
    <div class="inv-id-label" id="invIdLabel">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        فاتورة #<?= $display_order_num ?>
    </div>
    <div class="inv-id-date"><?= date('d/m/Y · H:i', strtotime($order['created_at'])) ?></div>
</div>

<!-- CHIPS -->
<div class="inv-chips">
    <div class="inv-chip">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
        <span id="tableText">طاولة</span> <strong><?= htmlspecialchars($order['table_number']) ?></strong>
    </div>
    <?php if($order['customer_name']): ?>
    <div class="inv-chip">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <strong><?= htmlspecialchars($order['customer_name']) ?></strong>
    </div>
    <?php endif; ?>
    <div class="inv-chip inv-chip-delivered">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <span id="deliveredText">تم التسليم</span>
    </div>
    <?php if($order['coupon_code']): ?>
    <div class="inv-chip">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
        <span id="couponText">كوبون</span>: <strong><?= htmlspecialchars($order['coupon_code']) ?></strong>
    </div>
    <?php endif; ?>
</div>

<!-- ITEMS -->
<div class="inv-card">
    <div class="inv-card-title" id="lblItems">الأصناف المطلوبة</div>
    <?php foreach($items as $i => $item): ?>
    <div class="inv-item" style="animation-delay:<?= $i*.05 ?>s">
        <div class="inv-item-qty"><?= $item['quantity'] ?>×</div>
        <div class="inv-item-info">
            <div class="inv-item-name"
                 data-name-ar="<?= htmlspecialchars($item['dish_name']) ?>"
                 data-name-en="<?= htmlspecialchars($item['dish_name_en_resolved'] ?? '') ?>">
                <?php if(empty($item['dish_id'])): ?>
                <span style="font-size:9px;font-weight:800;background:rgba(255,107,53,.1);color:var(--p);border:1px solid rgba(255,107,53,.2);padding:1px 6px;border-radius:7px;margin-left:4px;">🎁</span>
                <?php endif; ?>
                <?= htmlspecialchars($item['dish_name']) ?>
            </div>
            <div class="inv-item-unit" data-price="<?= number_format($item['dish_price'],2) ?>">
                $<?= number_format($item['dish_price'],2) ?> <span class="unit-label">/ الوحدة</span>
            </div>
            <?php if(!empty($item['options'])): ?>
            <?php $opts = json_decode($item['options'], true) ?? []; ?>
            <?php if(!empty($opts)): ?>
            <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;">
                <?php foreach($opts as $opt): ?>
                <span style="font-size:10px;font-weight:700;color:var(--p);background:color-mix(in srgb,var(--p) 10%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);padding:2px 7px;border-radius:8px;"><?= htmlspecialchars($opt['name']??'') ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="inv-item-total">$<?= number_format($item['dish_price']*$item['quantity'],2) ?></div>
    </div>
    <?php endforeach; ?>
    <div class="inv-totals">
        <div class="inv-total-row">
            <span class="inv-total-label" id="lblItemsSubtotal" data-ar="المجموع الفرعي" data-en="Subtotal">المجموع الفرعي</span>
            <span class="inv-total-value">$<?= number_format($subtotal,2) ?></span>
        </div>
        <?php if($discount > 0): ?>
        <div class="inv-total-row">
            <span class="inv-total-label" id="lblDiscount">خصم الكوبون</span>
            <span class="inv-total-value discount">-$<?= number_format($discount,2) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php if(!empty($order_taxes)): ?>
    <?php $subtotal_before_tax = $order['total_price'] - ($order['tax_amount'] ?? 0); ?>
    <div style="padding:0 18px 4px;">
        <div class="inv-total-row">
            <span class="inv-total-label" id="lblSubtotal"
                  data-ar="المجموع الفرعي" data-en="Subtotal">المجموع الفرعي</span>
            <span class="inv-total-value">$<?= number_format($subtotal_before_tax,2) ?></span>
        </div>
        <?php foreach($order_taxes as $otax): ?>
        <div class="inv-total-row">
            <span class="inv-total-label" style="display:flex;align-items:center;gap:5px;">
                <span class="tax-name-disp"
                      data-name-ar="<?= htmlspecialchars($otax['name']) ?>"
                      data-name-en="<?= htmlspecialchars($otax['name_en'] ?? $otax['name']) ?>">
                    <?= htmlspecialchars($otax['name']) ?>
                </span>
                <span style="font-size:9px;font-weight:800;padding:1px 6px;border-radius:10px;background:rgba(99,102,241,.12);color:#818CF8;">
                    <?= $otax['type']==='percentage' ? $otax['value'].'%' : '$'.number_format($otax['value'],2) ?>
                </span>
            </span>
            <span class="inv-total-value" style="color:#818CF8;">+$<?= number_format($otax['amount'],2) ?></span>
        </div>
        <?php endforeach; ?>
        <div style="height:1px;background:var(--line);margin:4px 0 8px;"></div>
    </div>
    <?php endif; ?>
    <div class="inv-grand-row">
        <span class="inv-grand-label" id="lblTotal" data-ar="الإجمالي" data-en="Total">الإجمالي</span>
        <span class="inv-grand-amount">$<?= number_format($order['total_price'],2) ?></span>
    </div>
</div>

<!-- THANK YOU -->
<div class="inv-thankyou">
    <div class="ty-icon">🙏</div>
    <div class="ty-title" id="tyTitle">شكراً لزيارتك!</div>
    <div class="ty-sub" id="tySub">نتمنى أن تكون تجربتك ممتازة<br>نراك قريباً في <?= htmlspecialchars($restaurant['name']) ?></div>
</div>

<!-- PAYMENT -->
<?php if($shamcash_enabled && ($shamcash_number || $shamcash_qr)): ?>
<div class="pay-card">
    <div class="pay-card-title" id="lblPayment">طريقة الدفع</div>
    <div class="pay-card-body">
        <button class="pay-opt" id="btnCash" onclick="selectPay('cash')">
            <div class="pay-opt-icon" style="background:rgba(34,197,94,.12);">💵</div>
            <div>
                <div class="pay-opt-title" id="cashTitle">دفع نقدي</div>
                <div class="pay-opt-sub" id="cashSub">ادفع للنادل مباشرة</div>
            </div>
            <div class="pay-radio" id="checkCash"></div>
        </button>
        <button class="pay-opt" id="btnSham" onclick="selectPay('sham')">
            <div class="pay-opt-icon" style="background:rgba(255,107,53,.12);">📱</div>
            <div>
                <div class="pay-opt-title" id="shamTitle">شام كاش</div>
                <div class="pay-opt-sub" id="shamSub">دفع إلكتروني فوري</div>
            </div>
            <div class="pay-radio" id="checkSham"></div>
        </button>
    </div>
    <div class="sham-details" id="shamDetails">
        <div class="sham-send-lbl" id="sendAmountText">ارسل المبلغ لهذا الحساب</div>
        <div class="sham-amount"><?= fmt_price($order['total_price'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div>
        <?php if($shamcash_number): ?>
        <div class="sham-num-row">
            <div>
                <div class="sham-num-lbl" id="accountText">رقم الحساب</div>
                <div class="sham-num"><?= htmlspecialchars($shamcash_number) ?></div>
            </div>
            <button class="copy-btn" id="copyBtn" onclick="copyNumber('<?= htmlspecialchars($shamcash_number) ?>')">نسخ</button>
        </div>
        <?php endif; ?>
        <?php if($shamcash_qr): ?>
        <img src="<?= BASE_URL ?>/assets/uploads/shamcash/<?= htmlspecialchars($shamcash_qr) ?>" class="sham-qr">
        <div class="sham-scan" id="scanText">امسح QR من تطبيق شام كاش</div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="cash-only">
    <div class="cash-only-icon">💵</div>
    <div>
        <div class="cash-only-title" id="cashOnlyTitle">دفع نقدي</div>
        <div class="cash-only-sub" id="cashOnlySub">ادفع المبلغ للنادل مباشرة</div>
    </div>
</div>
<?php endif; ?>

<!-- ACTIONS -->
<div class="inv-actions">
    <a href="<?= BASE_URL ?>/menu/<?= $slug ?>" class="btn-primary" id="btnNewOrder">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M3 2l3 7H6a3 3 0 003 3v9a1 1 0 001 1h4a1 1 0 001-1v-9a3 3 0 003-3h-3l3-7"/></svg>
        طلب جديد
    </a>
    <button class="btn-secondary" id="btnPrint" onclick="window.print()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        طباعة الفاتورة
    </button>
</div>

<!-- FOOTER -->
<div class="inv-footer">
    <?= htmlspecialchars($restaurant['name']) ?> · جميع الحقوق محفوظة
    <br><span class="inv-footer-brand">Powered by MenuPro</span>
</div>

<script>
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

const SLUG     = '<?= $slug ?>';
const LANG_KEY = 'menu_lang_' + SLUG;
const REST_NAME= '<?= addslashes(htmlspecialchars($restaurant['name'])) ?>';

const TR = {
    ar:{
        invoice:      'فاتورة #<?= $display_order_num ?>',
        items:        'الأصناف المطلوبة',
        unit:         '/ الوحدة',
        subtotal:     'المجموع الفرعي',
        discount:     'خصم الكوبون',
        total:        'الإجمالي',
        payment:      'طريقة الدفع',
        cash:         'دفع نقدي',
        cash_sub:     'ادفع للنادل مباشرة',
        cash_only_sub:'ادفع المبلغ للنادل مباشرة',
        sham:         'شام كاش',
        sham_sub:     'دفع إلكتروني فوري',
        send_amount:  'ارسل المبلغ لهذا الحساب',
        account:      'رقم الحساب',
        copy:         'نسخ',
        copied:       '✅ تم',
        scan:         'امسح QR من تطبيق شام كاش',
        thank_you:    'شكراً لزيارتك!',
        thank_sub:    'نتمنى أن تكون تجربتك ممتازة',
        see_you:      'نراك قريباً في',
        new_order:    'طلب جديد',
        print:        'طباعة الفاتورة',
        table:        'طاولة',
        delivered:    'تم التسليم',
        coupon:       'كوبون',
        dir:          'rtl',
    },
    en:{
        invoice:      'Invoice #<?= $display_order_num ?>',
        items:        'Items Ordered',
        unit:         '/ unit',
        subtotal:     'Subtotal',
        discount:     'Coupon Discount',
        total:        'Total',
        payment:      'Payment Method',
        cash:         'Cash Payment',
        cash_sub:     'Pay the waiter directly',
        cash_only_sub:'Pay the amount to the waiter',
        sham:         'ShamCash',
        sham_sub:     'Instant digital payment',
        send_amount:  'Send amount to this account',
        account:      'Account Number',
        copy:         'Copy',
        copied:       '✅ Copied',
        scan:         'Scan QR with ShamCash app',
        thank_you:    'Thank you for visiting!',
        thank_sub:    'We hope you had a great experience',
        see_you:      'See you soon at',
        new_order:    'New Order',
        print:        'Print Invoice',
        table:        'Table',
        delivered:    'Delivered',
        coupon:       'Coupon',
        dir:          'ltr',
    }
};

let lang = localStorage.getItem(LANG_KEY) || 'ar';

function applyLang(l) {
    lang = l;
    localStorage.setItem(LANG_KEY, l);
    const T = TR[l];
    document.documentElement.setAttribute('dir', T.dir);
    document.documentElement.setAttribute('lang', l);
    const btn = document.getElementById('langBtn');
    if(btn){ btn.textContent=l==='en'?'AR':'EN'; btn.classList.toggle('en-active',l==='en'); }

    // ID strip
    const lbl = document.getElementById('invIdLabel');
    if(lbl){ const svg=lbl.querySelector('svg'); lbl.innerHTML=''; if(svg)lbl.appendChild(svg); lbl.appendChild(document.createTextNode(' '+T.invoice)); }

    setText('lblItems',    T.items);
    setText('lblSubtotal', T.subtotal);
    setText('lblDiscount', T.discount);
    setText('lblTotal',    T.total);
    setText('lblPayment',  T.payment);
    setText('cashTitle',   T.cash);
    setText('cashSub',     T.cash_sub);
    setText('cashOnlyTitle',T.cash);
    setText('cashOnlySub', T.cash_only_sub);
    setText('shamTitle',   T.sham);
    setText('shamSub',     T.sham_sub);
    setText('sendAmountText',T.send_amount);
    setText('accountText', T.account);
    setText('scanText',    T.scan);
    setText('tyTitle',     T.thank_you);
    setText('tableText',   T.table);
    setText('deliveredText',T.delivered);
    setText('couponText',  T.coupon);

    // thank sub
    const tySub = document.getElementById('tySub');
    if(tySub) tySub.innerHTML = T.thank_sub + '<br>' + T.see_you + ' ' + REST_NAME;

    // unit labels
    document.querySelectorAll('.unit-label').forEach(el => el.textContent = T.unit);
    // أسماء الأطباق
    document.querySelectorAll('.inv-item-name[data-name-ar]').forEach(el => {
        el.textContent = (l==='en' && el.dataset.nameEn) ? el.dataset.nameEn : el.dataset.nameAr;
    });
    // ضرائب
    const stLbl = document.getElementById('lblSubtotal');
    if(stLbl) stLbl.textContent = l==='en' ? 'Subtotal' : 'المجموع الفرعي';
    const stLbl2 = document.getElementById('lblItemsSubtotal');
    if(stLbl2) stLbl2.textContent = l==='en' ? 'Subtotal' : 'المجموع الفرعي';
    document.querySelectorAll('.tax-name-disp').forEach(el => {
        el.textContent = (l==='en' && el.dataset.nameEn) ? el.dataset.nameEn : el.dataset.nameAr;
    });

    // copy btn
    const copyBtn = document.getElementById('copyBtn');
    if(copyBtn && copyBtn.textContent !== T.copied) copyBtn.textContent = T.copy;

    // buttons
    const btnNew = document.getElementById('btnNewOrder');
    if(btnNew) btnNew.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:18px;height:18px"><path d="M3 2l3 7H6a3 3 0 003 3v9a1 1 0 001 1h4a1 1 0 001-1v-9a3 3 0 003-3h-3l3-7"/></svg>${T.new_order}`;
    const btnPrint = document.getElementById('btnPrint');
    if(btnPrint) btnPrint.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:16px;height:16px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>${T.print}`;
}

function setText(id, val){ const el=document.getElementById(id); if(el) el.textContent=val; }
function toggleLang(){ applyLang(lang==='ar'?'en':'ar'); }
function toggleTheme(){
    const n=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',n);
    localStorage.setItem('menu_theme_'+SLUG,n);
}

function selectPay(type) {
    const c=document.getElementById('btnCash'), s=document.getElementById('btnSham');
    const cc=document.getElementById('checkCash'), cs=document.getElementById('checkSham');
    const sd=document.getElementById('shamDetails');
    if(!c) return;
    if(type==='cash'){
        c.style.borderColor='#22C55E'; c.style.background='rgba(34,197,94,.06)';
        s.style.borderColor='var(--line)'; s.style.background='var(--bg3)';
        cc.style.background='#22C55E'; cc.style.borderColor='#22C55E';
        cs.style.background='var(--bg4)'; cs.style.borderColor='var(--line)';
        if(sd) sd.style.display='none';
    } else {
        s.style.borderColor='#FF6B35'; s.style.background='rgba(255,107,53,.06)';
        c.style.borderColor='var(--line)'; c.style.background='var(--bg3)';
        cs.style.background='#FF6B35'; cs.style.borderColor='#FF6B35';
        cc.style.background='var(--bg4)'; cc.style.borderColor='var(--line)';
        if(sd) sd.style.display='block';
    }
}

function copyNumber(num){
    navigator.clipboard.writeText(num).then(()=>{
        const btn=document.getElementById('copyBtn');
        if(btn){ btn.textContent=TR[lang].copied; setTimeout(()=>btn.textContent=TR[lang].copy,2000); }
    });
}

applyLang(lang);
</script>
</body>
</html>