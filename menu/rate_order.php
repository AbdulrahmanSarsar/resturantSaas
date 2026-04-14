<?php
require_once '../config/database.php';

$slug     = $_GET['slug']  ?? '';
$order_id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE slug=? AND is_active=1");
$stmt->execute([$slug]);
$restaurant = $stmt->fetch();
if(!$restaurant) die('غير موجود');

$order = $pdo->prepare("SELECT * FROM orders WHERE id=? AND restaurant_id=? AND status='delivered'");
$order->execute([$order_id, $restaurant['id']]);
$order = $order->fetch();
if(!$order) { header("Location: ".BASE_URL."/menu/$slug/order/$order_id"); exit; }

$already = $pdo->prepare("SELECT id FROM order_ratings WHERE order_id=?");
$already->execute([$order_id]);
if($already->fetch()) { header("Location: ".BASE_URL."/menu/$slug/invoice/$order_id"); exit; }

// جلب الأطباق مع دمج نفس الطبق (dish_id) ودمج options
$items_raw = $pdo->prepare("
    SELECT oi.dish_id, oi.dish_name, oi.quantity, oi.options, d.image, d.name_en
    FROM order_items oi
    LEFT JOIN dishes d ON d.id=oi.dish_id
    WHERE oi.order_id=? AND oi.dish_id IS NOT NULL
    ORDER BY oi.id
");
$items_raw->execute([$order_id]);
$items_raw = $items_raw->fetchAll();

// دمج الأطباق بنفس dish_id
$items_map = [];
foreach($items_raw as $row) {
    $key = $row['dish_id'];
    if(!isset($items_map[$key])) {
        $items_map[$key] = [
            'dish_id'   => $row['dish_id'],
            'dish_name' => $row['dish_name'],
            'name_en'   => $row['name_en'] ?? '',
            'image'     => $row['image'],
            'quantity'  => 0,
            'options_list' => [],
        ];
    }
    $items_map[$key]['quantity'] += intval($row['quantity']);
    if(!empty($row['options'])) {
        $opts = json_decode($row['options'], true) ?? [];
        $opt_names = array_map(fn($o) => $o['name'] ?? '', $opts);
        $opt_str = implode(' · ', array_filter($opt_names));
        if($opt_str) $items_map[$key]['options_list'][] = $opt_str;
    }
}
$items = array_values($items_map);

// جلب النادل من الطلب (لو موجود)
$waiter_info = null;
if(!empty($order['staff_id'])) {
    $ws = $pdo->prepare("SELECT id,name FROM restaurant_staff WHERE id=? AND role='waiter'");
    $ws->execute([$order['staff_id']]);
    $waiter_info = $ws->fetch();
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rating'])) {
    $rating        = intval($_POST['rating']);
    $comment       = trim($_POST['comment'] ?? '');
    $favorite_dish = intval($_POST['favorite_dish'] ?? 0) ?: null;
    $waiter_rating = intval($_POST['waiter_rating'] ?? 0) ?: null;
    if($rating >= 1 && $rating <= 5) {
        $chk = $pdo->prepare("SELECT id FROM order_ratings WHERE order_id=?");
        $chk->execute([$order_id]);
        if(!$chk->fetch()) {
            $pdo->prepare("INSERT INTO order_ratings (order_id,restaurant_id,rating,comment,favorite_dish_id,waiter_rating,waiter_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$order_id, $restaurant['id'], $rating, $comment ?: null, $favorite_dish, $waiter_rating, $order['staff_id'] ?: null]);
        }
    }
    header("Location: ".BASE_URL."/menu/$slug/invoice/$order_id"); exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>قيّم تجربتك</title>
<script>(function(){ var t=localStorage.getItem('menu_theme_<?= $slug ?>')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root{--p:#FF6B35;--s:#F7C59F;}
[data-theme="dark"]{--bg:#080808;--bg2:#111111;--bg3:#1a1a1a;--bg4:#222222;--ink:#F5F0E8;--ink2:#6B6560;--ink3:#3A3530;--line:rgba(245,240,232,0.06);--bar-bg:rgba(8,8,8,0.95);}
[data-theme="light"]{--bg:#F7F5F2;--bg2:#FFFFFF;--bg3:#EEEAE6;--bg4:#E4E0DC;--ink:#1A1714;--ink2:#7A7570;--ink3:#B8B4B0;--line:rgba(26,23,20,0.08);--bar-bg:rgba(247,245,242,0.96);}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--ink);max-width:480px;margin:0 auto;min-height:100vh;padding-bottom:100px;transition:background .3s,color .3s;}
body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");pointer-events:none;z-index:9999;}

/* HERO */
.hero{position:relative;padding:0 0 28px;background:var(--bg2);border-bottom:1px solid var(--line);text-align:center;overflow:hidden;transition:background .3s,border-color .3s;}
.hero-orb{position:absolute;border-radius:50%;filter:blur(55px);pointer-events:none;}
.hero-orb-1{width:220px;height:220px;background:var(--p);opacity:.12;top:-90px;right:-50px;animation:orbDrift 10s ease-in-out infinite;}
.hero-orb-2{width:160px;height:160px;background:var(--s);opacity:.1;bottom:-60px;left:-40px;animation:orbDrift 8s ease-in-out infinite reverse;}
@keyframes orbDrift{0%,100%{transform:translate(0,0) scale(1);}50%{transform:translate(12px,-12px) scale(1.08);}}
.hero-topbar{display:flex;justify-content:space-between;align-items:center;padding:14px 16px 0;position:relative;z-index:2;}
.icon-btn{width:36px;height:36px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .2s,transform .3s;font-size:12px;font-weight:800;font-family:'Tajawal',sans-serif;}
.icon-btn:active{transform:scale(.9) rotate(5deg);}
.icon-btn svg{width:15px;height:15px;}
.icon-btn.en-active{background:var(--p);color:#fff;border-color:transparent;}
.icon-moon{display:block;}.icon-sun{display:none;}
[data-theme="light"] .icon-moon{display:none;}[data-theme="light"] .icon-sun{display:block;}

.hero-icon{position:relative;z-index:2;width:80px;height:80px;border-radius:24px;background:color-mix(in srgb,#F59E0B 12%,transparent);border:1px solid color-mix(in srgb,#F59E0B 25%,transparent);display:flex;align-items:center;justify-content:center;font-size:36px;margin:14px auto 16px;animation:iconFloat 3s ease-in-out infinite;}
@keyframes iconFloat{0%,100%{transform:translateY(0);}50%{transform:translateY(-5px);}}
.hero-title{position:relative;z-index:2;font-family:'Fraunces',serif;font-size:24px;font-weight:900;color:var(--ink);letter-spacing:-0.4px;margin-bottom:6px;}
.hero-sub{position:relative;z-index:2;font-size:13px;color:var(--ink2);font-weight:500;}

/* CONTENT */
.content{padding:20px 16px 0;}
.rate-card{background:var(--bg2);border:1px solid var(--line);border-radius:20px;overflow:hidden;margin-bottom:14px;animation:cardIn .45s cubic-bezier(.22,1,.36,1) both;transition:background .3s,border-color .3s;}
@keyframes cardIn{from{opacity:0;transform:translateY(16px) scale(.97);}to{opacity:1;transform:none;}}
.rate-card-head{padding:14px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:1.2px;}
.rate-card-head::after{content:'';flex:1;height:1px;background:var(--line);}
.rate-card-body{padding:24px 20px;}

/* STARS */
.stars-row{display:flex;gap:8px;justify-content:center;margin-bottom:10px;}
.star-btn{font-size:48px;cursor:pointer;color:var(--bg4);background:none;border:none;padding:4px;line-height:1;transition:color .15s,transform .2s cubic-bezier(.34,1.56,.64,1);}
.star-btn:active{transform:scale(.85);}
.star-btn.lit{color:#F59E0B;animation:starPop .25s cubic-bezier(.34,1.56,.64,1) both;}
@keyframes starPop{from{transform:scale(.6);}to{transform:scale(1);}}
.rating-hint{text-align:center;font-size:15px;font-weight:700;height:24px;color:var(--p);margin-bottom:20px;}

/* COMMENT */
.comment-input{width:100%;padding:13px 16px;background:var(--bg3);border:1.5px solid var(--line);border-radius:14px;color:var(--ink);font-size:14px;font-weight:500;font-family:'Tajawal',sans-serif;resize:none;height:90px;outline:none;transition:border-color .2s,box-shadow .2s,background .3s;}
.comment-input::placeholder{color:var(--ink2);}
.comment-input:focus{border-color:var(--p);box-shadow:0 0 0 3px color-mix(in srgb,var(--p) 10%,transparent);}

/* FAV DISHES */
.opt-label{font-size:12px;font-weight:700;color:var(--ink2);margin-bottom:10px;display:flex;align-items:center;gap:5px;}
.fav-grid{display:flex;flex-direction:column;gap:7px;}
.fav-dish-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:13px;border:1.5px solid var(--line);background:var(--bg3);cursor:pointer;transition:all .2s;}
.fav-dish-row:hover{border-color:var(--p);}
.fav-dish-row.selected{border-color:var(--p);background:color-mix(in srgb,var(--p) 8%,transparent);}
.fav-dish-img{width:38px;height:38px;border-radius:10px;flex-shrink:0;overflow:hidden;background:var(--bg4);display:flex;align-items:center;justify-content:center;font-size:18px;border:1px solid var(--line);}
.fav-dish-img img{width:100%;height:100%;object-fit:cover;}
.fav-dish-name{flex:1;font-size:13px;font-weight:700;color:var(--ink);}
.fav-qty{font-size:11px;color:var(--ink2);font-weight:600;}
.fav-check{width:20px;height:20px;border-radius:6px;border:2px solid var(--line);background:var(--bg4);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s;}
.fav-check svg{width:11px;height:11px;color:#fff;opacity:0;transition:opacity .2s;}
.fav-dish-row.selected .fav-check{background:var(--p);border-color:var(--p);}
.fav-dish-row.selected .fav-check svg{opacity:1;}

/* BOTTOM BAR */
.bottom-bar{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:480px;padding:12px 16px;background:var(--bar-bg);border-top:1px solid var(--line);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);z-index:100;display:flex;gap:9px;}
.skip-btn{padding:0 18px;background:var(--bg3);border:1.5px solid var(--line);border-radius:14px;color:var(--ink2);font-size:13px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;text-decoration:none;display:flex;align-items:center;justify-content:center;white-space:nowrap;flex-shrink:0;}
.submit-btn{flex:1;height:50px;background:var(--p);color:#fff;border:none;border-radius:14px;font-size:15px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;box-shadow:0 4px 16px color-mix(in srgb,var(--p) 35%,transparent);display:flex;align-items:center;justify-content:center;gap:8px;position:relative;overflow:hidden;transition:opacity .2s;}
.submit-btn::after{content:'';position:absolute;top:0;left:-60%;width:40%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.12),transparent);animation:shimmer 2.5s infinite;}
@keyframes shimmer{0%{left:-60%}100%{left:120%}}
.submit-btn:disabled{opacity:.45;cursor:not-allowed;}
.submit-btn:disabled::after{display:none;}
.submit-btn svg{width:18px;height:18px;}

/* WAITER RATING */
.waiter-stars-row { display:flex; gap:6px; justify-content:center; }
.waiter-star {
    font-size:36px; cursor:pointer; color:var(--bg4);
    background:none; border:none; padding:3px; line-height:1;
    transition:color .15s, transform .2s cubic-bezier(.34,1.56,.64,1);
}
.waiter-star:active { transform:scale(.85); }
.waiter-star.lit { color:#3B82F6; }
.waiter-card-icon {
    width:48px; height:48px; border-radius:14px;
    background:rgba(59,130,246,.1); border:1px solid rgba(59,130,246,.2);
    display:flex; align-items:center; justify-content:center;
    font-size:22px; margin:0 auto 12px;
}
</style>
</head>
<body>

<div class="hero">
    <div class="hero-orb hero-orb-1"></div>
    <div class="hero-orb hero-orb-2"></div>
    <div class="hero-topbar">
        <button class="icon-btn" id="langBtn" onclick="toggleLang()">EN</button>
        <button class="icon-btn" onclick="toggleTheme()">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        </button>
    </div>
    <div class="hero-icon">⭐</div>
    <div class="hero-title" id="heroTitle">كيف كانت تجربتك؟</div>
    <div class="hero-sub" id="heroSub">تقييمك يساعدنا نتحسن لك في المرة القادمة</div>
</div>

<div class="content">
<form method="POST" id="ratingForm">
    <input type="hidden" name="rating" id="ratingInput" value="0">
    <input type="hidden" name="favorite_dish" id="favDishInput" value="">

    <div class="rate-card">
        <div class="rate-card-head" id="lblOverall">تقييمك العام للطلب</div>
        <div class="rate-card-body">
            <div class="stars-row">
                <?php for($s=1;$s<=5;$s++): ?>
                <button type="button" class="star-btn" data-val="<?= $s ?>" onclick="selectStar(<?= $s ?>)">★</button>
                <?php endfor; ?>
            </div>
            <div class="rating-hint" id="ratingHint"></div>
            <textarea class="comment-input" name="comment" id="commentInput"
                placeholder="أضف تعليقاً على تجربتك... (اختياري)"></textarea>
        </div>
    </div>

    <!-- ===== WAITER RATING ===== -->
    <?php if($waiter_info): ?>
    <div class="rate-card" style="animation-delay:.08s">
        <div class="rate-card-head" id="lblWaiter">تقييم الخدمة</div>
        <div class="rate-card-body">
            <input type="hidden" name="waiter_rating" id="waiterRatingInput" value="0">
            <div class="waiter-card-icon">🧑‍💼</div>
            <div style="text-align:center;font-size:13px;font-weight:700;color:var(--ink);margin-bottom:14px;" id="waiterName">
                <?= htmlspecialchars($waiter_info['name']) ?>
            </div>
            <div class="waiter-stars-row">
                <?php for($s=1;$s<=5;$s++): ?>
                <button type="button" class="waiter-star" data-val="<?= $s ?>" onclick="selectWaiterStar(<?= $s ?>)">★</button>
                <?php endfor; ?>
            </div>
            <div id="waiterHint" style="text-align:center;font-size:13px;font-weight:700;height:22px;color:#3B82F6;margin-top:8px;"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if(count($items) > 1): ?>
    <div class="rate-card" style="animation-delay:.1s">
        <div class="rate-card-head" id="lblFav">أكثر طبق أعجبك</div>
        <div class="rate-card-body" style="padding-top:14px;">
            <div class="opt-label" id="optLabel">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                اختياري
            </div>
            <div class="fav-grid">
                <?php foreach($items as $item): ?>
                <div class="fav-dish-row"
                     onclick="selectFav(this,<?= $item['dish_id'] ?>)"
                     data-name-ar="<?= htmlspecialchars($item['dish_name']) ?>"
                     data-name-en="<?= htmlspecialchars($item['name_en'] ?? '') ?>">
                    <div class="fav-dish-img">
                        <?php if($item['image']): ?>
                        <img src="<?= BASE_URL ?>/assets/uploads/dishes/<?= $item['image'] ?>" loading="lazy">
                        <?php else: ?>🍔<?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div class="fav-dish-name"><?= htmlspecialchars($item['dish_name']) ?></div>
                        <?php if(!empty($item['options_list'])): ?>
                        <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;">
                            <?php foreach(array_unique($item['options_list']) as $opt_str): ?>
                            <span style="font-size:9px;font-weight:700;color:var(--p);background:color-mix(in srgb,var(--p) 10%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);padding:1px 6px;border-radius:6px;"><?= htmlspecialchars($opt_str) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="fav-qty">×<?= $item['quantity'] ?></div>
                    <div class="fav-check">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</form>
</div>

<div class="bottom-bar">
    <a href="<?= BASE_URL ?>/menu/<?= $slug ?>/invoice/<?= $order_id ?>" class="skip-btn" id="skipBtn">تخطي</a>
    <button class="submit-btn" id="submitBtn" onclick="submitRating()" disabled>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
        أرسل التقييم
    </button>
</div>

<script>
const SLUG     = '<?= $slug ?>';
const LANG_KEY = 'menu_lang_' + SLUG;

const TR = {
    ar: {
        title:       'كيف كانت تجربتك؟',
        sub:         'تقييمك يساعدنا نتحسن لك في المرة القادمة',
        overall:     'تقييمك العام للطلب',
        waiter:      'تقييم الخدمة',
        waiter_hints:['','سيئ 😞','مقبول 😐','جيد 👍','ممتاز 😊','رائع! 🌟'],
        fav:         'أكثر طبق أعجبك',
        optional:    'اختياري',
        comment_ph:  'أضف تعليقاً على تجربتك... (اختياري)',
        skip:        'تخطي',
        send:        'أرسل التقييم',
        sending:     'جاري الإرسال...',
        hints:       ['','سيئ 😞','مقبول 😐','جيد 👍','ممتاز 😊','رائع! 🌟'],
        dir:         'rtl',
    },
    en: {
        title:       'How was your experience?',
        sub:         'Your feedback helps us improve',
        overall:     'Overall Rating',
        waiter:      'Service Rating',
        waiter_hints:['','Poor 😞','Fair 😐','Good 👍','Great 😊','Excellent! 🌟'],
        fav:         'Your Favorite Dish',
        optional:    'Optional',
        comment_ph:  'Add a comment about your experience... (optional)',
        skip:        'Skip',
        send:        'Submit Rating',
        sending:     'Submitting...',
        hints:       ['','Bad 😞','Fair 😐','Good 👍','Great 😊','Amazing! 🌟'],
        dir:         'ltr',
    }
};

let lang           = localStorage.getItem(LANG_KEY) || 'ar';
let selectedRating = 0;

function applyLang(l) {
    lang = l;
    localStorage.setItem(LANG_KEY, l);
    const T = TR[l];
    document.documentElement.setAttribute('dir', T.dir);
    document.documentElement.setAttribute('lang', l);

    const btn = document.getElementById('langBtn');
    if(btn){ btn.textContent=l==='en'?'AR':'EN'; btn.classList.toggle('en-active',l==='en'); }

    setText('heroTitle',  T.title);
    setText('heroSub',    T.sub);
    setText('lblOverall', T.overall);
    setText('lblFav',     T.fav);
    setText('skipBtn',    T.skip);

    // opt label — يحتفظ بالـ SVG
    const optLbl = document.getElementById('optLabel');
    if(optLbl){
        const svg = optLbl.querySelector('svg');
        optLbl.innerHTML = '';
        if(svg) optLbl.appendChild(svg);
        optLbl.appendChild(document.createTextNode(' ' + T.optional));
    }

    // comment placeholder
    const ci = document.getElementById('commentInput');
    if(ci) ci.placeholder = T.comment_ph;

    // submit btn
    const sb = document.getElementById('submitBtn');
    if(sb) {
        // نحدث النص دائماً بغض النظر عن disabled
        const svgIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:18px;height:18px"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        if(!sb.textContent.includes(TR.ar.sending) && !sb.textContent.includes(TR.en.sending)) {
            sb.innerHTML = svgIcon + T.send;
        }
    }

    // hint
    if(selectedRating > 0) setText('ratingHint', T.hints[selectedRating]);
    // waiter
    setText('lblWaiter', T.waiter);
    if(selectedWaiterRating > 0) setText('waiterHint', T.waiter_hints[selectedWaiterRating]);

    // أسماء الأطباق في قائمة التقييم
    document.querySelectorAll('.fav-dish-row').forEach(row => {
        const nameEl = row.querySelector('.fav-dish-name');
        if(!nameEl) return;
        const nameEn = row.dataset.nameEn;
        nameEl.textContent = (l==='en' && nameEn) ? nameEn : row.dataset.nameAr;
    });
}

function setText(id, val){ const el=document.getElementById(id); if(el) el.textContent=val; }
function toggleLang(){ applyLang(lang==='ar'?'en':'ar'); }
function toggleTheme(){
    const n=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',n);
    localStorage.setItem('menu_theme_'+SLUG,n);
}

function selectStar(val){
    selectedRating = val;
    document.getElementById('ratingInput').value = val;
    document.querySelectorAll('.star-btn').forEach((s,i)=>{
        s.classList.remove('lit');
        void s.offsetWidth;
        if(i < val) s.classList.add('lit');
    });
    document.getElementById('ratingHint').textContent = TR[lang].hints[val];
    const sb = document.getElementById('submitBtn');
    sb.disabled = false;
    sb.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:18px;height:18px"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>${TR[lang].send}`;
}

let selectedWaiterRating = 0;

function selectWaiterStar(val) {
    selectedWaiterRating = val;
    document.getElementById('waiterRatingInput').value = val;
    document.querySelectorAll('.waiter-star').forEach((s,i) => {
        s.classList.toggle('lit', i < val);
    });
    const hint = document.getElementById('waiterHint');
    if(hint) hint.textContent = TR[lang].waiter_hints[val];
}

function selectFav(row, dishId){
    document.querySelectorAll('.fav-dish-row').forEach(r=>r.classList.remove('selected'));
    row.classList.add('selected');
    document.getElementById('favDishInput').value = dishId;
}

function submitRating(){
    if(selectedRating === 0) return;
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = TR[lang].sending;
    document.getElementById('ratingForm').submit();
}

applyLang(lang);
</script>
</body>
</html>