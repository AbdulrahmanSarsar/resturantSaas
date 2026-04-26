<?php
/**
 * sidebar_v2.php — Restaurant Dashboard Shell (v2)
 * إصلاحات:
 *   - .sb-nav: أضفنا min-height: 0 لمنع overflow يطغى على الـ footer
 *   - .sb-footer: flex-shrink: 0 صريح
 *   - Branch switcher يقرأ الفرع النشط من الـ session
 */
$current_page = basename($_SERVER['PHP_SELF']);
$rid          = $_SESSION['restaurant_id'];

if (!function_exists('plan_has_feature')) {
    require_once __DIR__ . '/plan_guard.php';
}

// CSRF helpers متوفرة لكل الصفحات اللي بتحمّل sidebar
if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/../../config/csrf.php';
}

$restaurant_data = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
$restaurant_data->execute([$rid]);
$restaurant_data = $restaurant_data->fetch();

$primary   = '#FF6B35';
$secondary = '#F7C59F';

// ===== جلب الفروع =====
$branches_list = [];
try {
    $br_stmt = $pdo->prepare("SELECT id, name, name_en, slug, is_active FROM branches WHERE restaurant_id = ? ORDER BY id ASC");
    $br_stmt->execute([$rid]);
    $branches_list = $br_stmt->fetchAll();
} catch (Exception $e) { /* الجدول ممكن ما يكون موجود بعد */ }

// ===== معالجة تبديل الفرع (GET param) =====
if (isset($_GET['switch_branch'])) {
    $switch_id = intval($_GET['switch_branch']);
    foreach ($branches_list as $br) {
        if ($br['id'] == $switch_id) {
            $_SESSION['active_branch_id']   = $br['id'];
            $_SESSION['active_branch_name'] = $br['name'];
            $_SESSION['active_branch_slug'] = $br['slug'];
            break;
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ===== الفرع النشط =====
if (empty($_SESSION['active_branch_id']) && !empty($branches_list)) {
    $_SESSION['active_branch_id']   = $branches_list[0]['id'];
    $_SESSION['active_branch_name'] = $branches_list[0]['name'];
    $_SESSION['active_branch_slug'] = $branches_list[0]['slug'];
}
$active_branch_id   = $_SESSION['active_branch_id']   ?? null;
$active_branch_name = $_SESSION['active_branch_name'] ?? 'الفرع الرئيسي';
$active_branch_slug = $_SESSION['active_branch_slug'] ?? '';
$has_multiple       = count($branches_list) > 1;

// ===== إحصائيات — branch-aware =====
if ($active_branch_id) {
    $pending_count = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND branch_id = ? AND status = 'pending'");
    $pending_count->execute([$rid, $active_branch_id]);
} else {
    $pending_count = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status = 'pending'");
    $pending_count->execute([$rid]);
}
$pending_count = $pending_count->fetchColumn();

$plans      = ['basic' => '🥉 أساسية', 'advanced' => '🥈 متقدمة', 'premium' => '⭐ بريميوم'];
$plan_label = $plans[$restaurant_data['subscription_plan']] ?? '—';

// ===== Nav items =====
$nav_items = [
    ['index.php',      'home',      'الرئيسية'],
    ['chain.php',      'chain',     'لوحة السلسلة'],
    ['categories.php', 'grid',      'التصنيفات'],
    ['dishes.php',     'layers',    'الأطباق'],
    ['offers.php',     'gift',      'العروض'],
    ['orders.php',     'list',      'الطلبات',    $pending_count],
    ['qr.php',         'qr',        'رمز QR'],
    ['coupons.php',    'tag',       'الكوبونات'],
    ['ratings.php',    'star',      'التقييمات'],
    ['stats.php',      'chart',     'الإحصائيات'],
    ['reports.php',    'bar-chart', 'التقارير'],
    ['taxes.php',      'percent',   'الضرائب'],
    ['branches.php',   'branch',    'الفروع'],
    ['profile.php',    'settings',  'الإعدادات'],
];

$page_feature_map = [
    'chain.php'    => 'multi_branch',
    'offers.php'   => 'offers',
    'orders.php'   => 'orders',
    'coupons.php'  => 'coupons',
    'ratings.php'  => 'ratings',
    'stats.php'    => 'stats',
    'reports.php'  => 'reports',
    // branches.php مفتوحة للكل (basic/advanced عندهم فرع واحد بس) — القفل جوا الصفحة على إضافة فرع جديد
];
$nav_items = array_map(function ($item) use ($page_feature_map) {
    $feature        = $page_feature_map[$item[0]] ?? null;
    $item['locked'] = $feature ? !plan_has_feature($feature) : false;
    return $item;
}, $nav_items);

$icons_svg = [
    'home'      => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    'chain'     => '<rect x="2" y="3" width="6" height="6" rx="1"/><rect x="16" y="3" width="6" height="6" rx="1"/><rect x="9" y="15" width="6" height="6" rx="1"/><line x1="5" y1="9" x2="5" y2="12"/><line x1="19" y1="9" x2="19" y2="12"/><line x1="5" y1="12" x2="12" y2="18"/><line x1="19" y1="12" x2="12" y2="18"/>',
    'grid'      => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
    'layers'    => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    'list'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    'qr'        => '<rect x="3" y="3" width="5" height="5"/><rect x="16" y="3" width="5" height="5"/><rect x="3" y="16" width="5" height="5"/><path d="M21 16h-3a2 2 0 00-2 2v3"/><line x1="21" y1="21" x2="21" y2="21"/><path d="M14 14h3"/>',
    'tag'       => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
    'star'      => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    'chart'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'gift'      => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>',
    'percent'   => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
    'bar-chart' => '<rect x="3" y="12" width="4" height="9"/><rect x="10" y="7" width="4" height="14"/><rect x="17" y="3" width="4" height="18"/>',
    'branch'    => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= csrf_meta() ?>
    <title>لوحة التحكم — <?= htmlspecialchars($restaurant_data['name']) ?></title>
    <script>(function(){var t=localStorage.getItem('dash_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
    :root{--p:<?= $primary ?>;--s:<?= $secondary ?>;--sw:260px;}
    [data-theme="dark"]{--bg:#0C0C0C;--bg2:#141414;--bg3:#1C1C1C;--bg4:#252525;--ink:#F0EBE3;--ink2:#5A5550;--ink3:#2E2A27;--line:rgba(240,235,227,0.06);--line2:rgba(240,235,227,0.10);--shadow:0 4px 24px rgba(0,0,0,0.5);--shadow2:0 2px 12px rgba(0,0,0,0.4);--bar-bg:rgba(12,12,12,0.96);--sidebar-bg:#0E0E0E;--sidebar-line:rgba(240,235,227,0.06);}
    [data-theme="light"]{--bg:#F4F1ED;--bg2:#FFFFFF;--bg3:#EDEAE6;--bg4:#E2DED9;--ink:#1C1917;--ink2:#78726C;--ink3:#C4BFB9;--line:rgba(28,25,23,0.07);--line2:rgba(28,25,23,0.12);--shadow:0 4px 24px rgba(0,0,0,0.08);--shadow2:0 2px 12px rgba(0,0,0,0.05);--bar-bg:rgba(244,241,237,0.96);--sidebar-bg:#FFFFFF;--sidebar-line:rgba(28,25,23,0.08);}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
    html{scroll-behavior:smooth;}
    body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;transition:background .3s,color .3s;overflow-x:hidden;}
    body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.022'/%3E%3C/svg%3E");pointer-events:none;z-index:9998;}

    /* ================================================================
       SIDEBAR — الإصلاح الرئيسي:
       .sidebar     → overflow: hidden (يمنع أي محتوى يخرج)
       .sb-nav      → flex: 1 + min-height: 0 (يسمح للـ nav بالانكماش)
       .sb-footer   → flex-shrink: 0 (يمنع الـ footer من الاختفاء)
    ================================================================ */
    .sidebar{
        position:fixed;top:0;right:0;
        width:var(--sw);height:100vh;
        background:var(--sidebar-bg);
        border-left:1px solid var(--sidebar-line);
        z-index:400;
        display:flex;flex-direction:column; /* ← الأساس */
        transition:transform .35s cubic-bezier(.4,0,.2,1),background .3s,border-color .3s;
        overflow:hidden; /* ← يمنع overflow خارج الـ sidebar */
    }
    .sidebar::before{content:'';position:absolute;top:0;right:0;width:3px;height:100%;background:linear-gradient(to bottom,var(--p),var(--s),transparent);opacity:.6;}
    @media(max-width:768px){.sidebar{transform:translateX(100%);}.sidebar.open{transform:translateX(0);}}

    /* Header — flex-shrink: 0 يمنعه من الانكماش */
    .sb-header{padding:22px 20px 16px;border-bottom:1px solid var(--sidebar-line);flex-shrink:0;position:relative;}
    .sb-header::before{content:'';position:absolute;top:-30px;left:-30px;width:130px;height:130px;background:var(--p);border-radius:50%;opacity:.06;filter:blur(30px);pointer-events:none;}

    .sb-brand{display:flex;align-items:center;gap:12px;margin-bottom:14px;}
    .sb-avatar{width:44px;height:44px;border-radius:13px;object-fit:cover;border:1.5px solid var(--line2);flex-shrink:0;}
    .sb-avatar-placeholder{width:44px;height:44px;border-radius:13px;background:color-mix(in srgb,var(--p) 15%,transparent);border:1.5px solid color-mix(in srgb,var(--p) 25%,transparent);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
    .sb-brand-text{min-width:0;}
    .sb-name{font-family:'Fraunces',serif;font-size:14px;font-weight:700;color:var(--ink);letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .sb-plan{display:inline-flex;align-items:center;gap:4px;background:color-mix(in srgb,var(--p) 12%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);color:var(--p);padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;margin-top:3px;}

    /* Branch Switcher — flex-shrink: 0 */
    .branch-switcher{padding:10px 20px;border-bottom:1px solid var(--sidebar-line);background:var(--bg3);flex-shrink:0;}
    .branch-switcher-label{font-size:9px;font-weight:800;color:var(--ink2);letter-spacing:1.2px;text-transform:uppercase;margin-bottom:5px;display:flex;align-items:center;gap:4px;}
    .branch-switcher-label svg{width:10px;height:10px;}
    .branch-select{width:100%;padding:7px 10px 7px 26px;background:var(--bg2);border:1px solid var(--line);border-radius:9px;font-size:12px;font-weight:700;font-family:'Tajawal',sans-serif;color:var(--ink);cursor:pointer;outline:none;transition:border-color .2s;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2' stroke-linecap='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:left 8px center;}
    .branch-select:focus{border-color:var(--p);}
    .branch-single{display:flex;align-items:center;gap:6px;padding:7px 10px;background:var(--bg2);border:1px solid var(--line);border-radius:9px;font-size:12px;font-weight:600;color:var(--ink2);}
    .branch-dot{width:6px;height:6px;border-radius:50%;background:#22C55E;flex-shrink:0;}

    .sb-controls{display:flex;align-items:center;justify-content:space-between;padding:8px 20px;border-bottom:1px solid var(--sidebar-line);flex-shrink:0;}
    .sb-date{font-size:11px;color:var(--ink2);font-weight:600;}
    .theme-toggle{width:30px;height:30px;border-radius:9px;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .2s,transform .3s;}
    .theme-toggle:active{transform:rotate(20deg) scale(.9);}
    .theme-toggle svg{width:14px;height:14px;}
    .icon-moon{display:block;}.icon-sun{display:none;}
    [data-theme="light"] .icon-moon{display:none;}[data-theme="light"] .icon-sun{display:block;}

    /* *** الإصلاح الجوهري ***
       min-height: 0 يسمح للـ flexbox بتصغير الـ nav
       بدونه، الـ nav يأخذ كل المساحة ويدفع الـ footer للخارج */
    .sb-nav{
        flex:1;
        min-height:0; /* ← الإصلاح */
        padding:10px 10px 6px;
        list-style:none;
        overflow-y:auto;
        scrollbar-width:thin;
        scrollbar-color:var(--line) transparent;
    }
    .sb-nav::-webkit-scrollbar{width:3px;}
    .sb-nav::-webkit-scrollbar-thumb{background:var(--line);border-radius:3px;}

    .sb-nav-section{font-size:10px;font-weight:800;color:var(--ink2);letter-spacing:1.5px;text-transform:uppercase;padding:8px 10px 5px;margin-top:2px;}
    .sb-nav li a{display:flex;align-items:center;gap:9px;padding:9px 11px;color:var(--ink2);text-decoration:none;font-size:13px;font-weight:600;border-radius:11px;margin-bottom:1px;transition:all .18s;position:relative;white-space:nowrap;}
    .sb-icon{width:30px;height:30px;border-radius:8px;background:var(--bg3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .18s;color:var(--ink2);}
    .sb-icon svg{width:14px;height:14px;}
    .sb-label{flex:1;}
    .sb-nav li a:hover{color:var(--ink);background:var(--bg3);}
    .sb-nav li a:hover .sb-icon{background:color-mix(in srgb,var(--p) 12%,transparent);border-color:color-mix(in srgb,var(--p) 20%,transparent);color:var(--p);}
    .sb-nav li a.active{color:var(--ink);background:color-mix(in srgb,var(--p) 8%,var(--bg3));}
    .sb-nav li a.active .sb-icon{background:var(--p);border-color:transparent;color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--p) 40%,transparent);}
    .sb-badge{background:var(--p);color:#fff;border-radius:20px;padding:2px 7px;font-size:10px;font-weight:800;min-width:20px;text-align:center;}
    .sb-locked{opacity:.5;}

    /* *** الإصلاح الثاني ***
       flex-shrink: 0 يمنع الـ footer من الاختفاء أو الانكماش */
    .sb-footer{
        flex-shrink:0; /* ← الإصلاح */
        padding:10px 10px;
        border-top:1px solid var(--sidebar-line);
        display:flex;
        flex-direction:column;
        gap:5px;
    }
    .sb-footer-btn{display:flex;align-items:center;justify-content:center;gap:7px;padding:10px;border-radius:11px;text-decoration:none;font-size:13px;font-weight:700;transition:all .18s;border:1px solid var(--line);}
    .sb-footer-btn svg{width:14px;height:14px;flex-shrink:0;}
    .btn-preview{background:color-mix(in srgb,var(--p) 10%,transparent);border-color:color-mix(in srgb,var(--p) 20%,transparent);color:var(--p);}
    .btn-preview:hover{background:var(--p);color:#fff;border-color:transparent;}
    .btn-logout{background:var(--bg3);color:var(--ink2);}
    .btn-logout:hover{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:#EF4444;}

    /* Overlay + Topnav */
    .sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);z-index:399;opacity:0;transition:opacity .3s;}
    .sb-overlay.active{display:block;opacity:1;}
    .topnav{display:none;position:fixed;top:0;left:0;right:0;height:56px;background:var(--bar-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid var(--line);z-index:300;align-items:center;justify-content:space-between;padding:0 14px;}
    .topnav-brand{display:flex;align-items:center;gap:9px;}
    .topnav-logo{width:32px;height:32px;border-radius:9px;object-fit:cover;border:1px solid var(--line);}
    .topnav-logo-ph{width:32px;height:32px;border-radius:9px;background:color-mix(in srgb,var(--p) 15%,transparent);border:1px solid color-mix(in srgb,var(--p) 25%,transparent);display:flex;align-items:center;justify-content:center;font-size:16px;}
    .topnav-name{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:var(--ink);}
    .topnav-right{display:flex;align-items:center;gap:8px;}
    .topnav-theme{width:34px;height:34px;border-radius:10px;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);display:flex;align-items:center;justify-content:center;cursor:pointer;}
    .topnav-theme svg{width:14px;height:14px;}
    .hamburger{width:36px;height:36px;border-radius:10px;background:var(--bg3);border:1px solid var(--line);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;}
    .hamburger span{display:block;width:16px;height:1.5px;background:var(--ink2);border-radius:2px;transition:all .3s;}
    .hamburger.open span:nth-child(1){transform:translateY(5.5px) rotate(45deg);}
    .hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
    .hamburger.open span:nth-child(3){transform:translateY(-5.5px) rotate(-45deg);}

    /* Main content */
    .main{margin-right:var(--sw);flex:1;min-width:0;padding:28px;transition:margin .35s;}
    @media(max-width:768px){.topnav{display:flex;}.main{margin-right:0;margin-top:56px;padding:16px;}}

    /* ===== SHARED COMPONENTS ===== */
    .page-head{margin-bottom:24px;}
    .page-title{font-family:'Fraunces',serif;font-size:24px;font-weight:900;color:var(--ink);letter-spacing:-.4px;margin-bottom:3px;}
    .page-subtitle{font-size:13px;color:var(--ink2);font-weight:500;}
    .card{background:var(--bg2);border-radius:18px;border:1px solid var(--line);overflow:hidden;}
    .card-header{padding:14px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;}
    .card-header h3{font-family:'Fraunces',serif;font-size:14px;font-weight:700;color:var(--ink);}
    .card-header a{font-size:12px;color:var(--p);text-decoration:none;font-weight:700;}
    .btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:12px;font-size:13px;font-weight:700;cursor:pointer;border:none;font-family:'Tajawal',sans-serif;text-decoration:none;transition:all .2s;white-space:nowrap;}
    .btn svg{width:15px;height:15px;}
    .btn-primary{background:var(--p);color:#fff;box-shadow:0 4px 14px color-mix(in srgb,var(--p) 35%,transparent);}
    .btn-primary:hover{opacity:.88;transform:translateY(-1px);}
    .btn-secondary{background:var(--bg3);border:1px solid var(--line);color:var(--ink2);}
    .btn-secondary:hover{background:var(--bg4);border-color:var(--line2);color:var(--ink);}
    .btn-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#EF4444;}
    .btn-danger:hover{background:#EF4444;color:#fff;border-color:transparent;}
    .btn-sm{padding:7px 13px;font-size:12px;border-radius:9px;}
    .alert{padding:13px 16px;border-radius:12px;margin-bottom:18px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;border:1px solid transparent;animation:alertIn .3s cubic-bezier(.22,1,.36,1) both;}
    @keyframes alertIn{from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:translateY(0);}}
    .alert svg{width:16px;height:16px;flex-shrink:0;}
    .alert-success{background:rgba(34,197,94,.08);border-color:rgba(34,197,94,.2);color:#22C55E;}
    .alert-error{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.2);color:#EF4444;}
    .alert-warning{background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.2);color:#F59E0B;}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
    .badge-active{background:rgba(34,197,94,.12);color:#22C55E;border:1px solid rgba(34,197,94,.2);}
    .badge-inactive{background:rgba(239,68,68,.12);color:#EF4444;border:1px solid rgba(239,68,68,.2);}
    .badge-pending{background:rgba(245,158,11,.12);color:#F59E0B;border:1px solid rgba(245,158,11,.2);}
    .badge-confirmed{background:rgba(59,130,246,.12);color:#3B82F6;border:1px solid rgba(59,130,246,.2);}
    .badge-preparing{background:rgba(139,92,246,.12);color:#8B5CF6;border:1px solid rgba(139,92,246,.2);}
    .badge-ready{background:rgba(34,197,94,.12);color:#22C55E;border:1px solid rgba(34,197,94,.2);}
    .badge-delivered{background:var(--bg3);color:var(--ink2);border:1px solid var(--line);}
    .badge-cancelled{background:rgba(239,68,68,.12);color:#EF4444;border:1px solid rgba(239,68,68,.2);}
    .badge-basic{background:rgba(59,130,246,.12);color:#3B82F6;border:1px solid rgba(59,130,246,.2);}
    .badge-advanced{background:rgba(139,92,246,.12);color:#8B5CF6;border:1px solid rgba(139,92,246,.2);}
    .badge-premium{background:rgba(245,158,11,.12);color:#F59E0B;border:1px solid rgba(245,158,11,.2);}
    .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
    table{width:100%;border-collapse:collapse;min-width:480px;}
    th{padding:10px 16px;text-align:right;background:var(--bg3);color:var(--ink2);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--line);}
    td{padding:12px 16px;text-align:right;border-bottom:1px solid var(--line);font-size:13px;color:var(--ink);}
    tr:last-child td{border-bottom:none;}
    tbody tr:hover td{background:var(--bg3);}
    .modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);z-index:500;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;}
    .modal-bg.open{display:flex;}
    .modal{background:var(--bg2);border:1px solid var(--line);border-radius:20px;padding:26px;width:100%;max-width:520px;box-shadow:0 24px 64px rgba(0,0,0,.4);animation:modalIn .35s cubic-bezier(.22,1,.36,1) both;margin:auto;}
    @keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(16px);}to{opacity:1;transform:scale(1) translateY(0);}}
    .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
    .modal-header h3{font-family:'Fraunces',serif;font-size:17px;font-weight:700;color:var(--ink);}
    .modal-close{width:32px;height:32px;border-radius:9px;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;}
    .modal-close:hover{background:var(--bg4);color:var(--ink);}
    .modal-footer{display:flex;gap:9px;margin-top:20px;}
    .form-group{margin-bottom:14px;}
    label{display:block;font-size:12px;font-weight:700;color:var(--ink2);margin-bottom:6px;letter-spacing:.3px;}
    input[type=text],input[type=number],input[type=tel],input[type=password],input[type=date],input[type=url],input[type=email],input[type=file],textarea,select{width:100%;padding:10px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;font-size:13px;font-weight:500;font-family:'Tajawal',sans-serif;color:var(--ink);outline:none;transition:border-color .2s,box-shadow .2s,background .3s;}
    input::placeholder,textarea::placeholder{color:var(--ink2);}
    input:focus,textarea:focus,select:focus{border-color:var(--p);box-shadow:0 0 0 3px color-mix(in srgb,var(--p) 12%,transparent);background:var(--bg2);}
    textarea{resize:vertical;min-height:78px;}
    select{cursor:pointer;}
    input[type=file]{padding:9px 14px;border-style:dashed;cursor:pointer;}
    .form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    @media(max-width:480px){.form-row-2{grid-template-columns:1fr;}}
    .filter-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;}
    .filter-tab{padding:7px 15px;border-radius:20px;border:1.5px solid var(--line);background:var(--bg2);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;color:var(--ink2);transition:all .2s;font-family:'Tajawal',sans-serif;}
    .filter-tab:hover{border-color:var(--p);color:var(--p);}
    .filter-tab.active{background:var(--p);border-color:transparent;color:#fff;box-shadow:0 3px 10px color-mix(in srgb,var(--p) 30%,transparent);}
    .empty-state{text-align:center;padding:60px 20px;color:var(--ink2);}
    .empty-state-icon{width:64px;height:64px;border-radius:20px;background:var(--bg3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;}
    .empty-state p{font-size:14px;font-weight:600;color:var(--ink2);}
    .toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(80px);background:var(--bg2);border:1px solid var(--line);color:var(--ink);padding:11px 22px;border-radius:25px;font-size:13px;font-weight:700;z-index:9000;box-shadow:var(--shadow);transition:transform .35s cubic-bezier(.34,1.56,.64,1);white-space:nowrap;}
    .toast.show{transform:translateX(-50%) translateY(0);}
    .section-label{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:12px;}
    .section-label::after{content:'';flex:1;height:1px;background:var(--line);}
    .card-in{animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;}
    @keyframes cardIn{from{opacity:0;transform:translateY(12px) scale(.98);}to{opacity:1;transform:translateY(0) scale(1);}}
    </style>
</head>
<body>

<!-- TOPNAV MOBILE -->
<div class="topnav">
    <div class="topnav-brand">
        <?php if($restaurant_data['logo']): ?>
            <img src="<?= BASE_URL ?>/assets/uploads/<?= $restaurant_data['logo'] ?>" class="topnav-logo" alt="">
        <?php else: ?>
            <div class="topnav-logo-ph">🍽️</div>
        <?php endif; ?>
        <span class="topnav-name"><?= htmlspecialchars($restaurant_data['name']) ?></span>
    </div>
    <div class="topnav-right">
        <button class="topnav-theme" onclick="toggleTheme()">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg class="icon-sun"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        </button>
        <button class="hamburger" id="hamburger" onclick="toggleSidebar()"><span></span><span></span><span></span></button>
    </div>
</div>

<div class="sb-overlay" id="sbOverlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">

    <!-- Header -->
    <div class="sb-header">
        <div class="sb-brand">
            <?php if($restaurant_data['logo']): ?>
                <img src="<?= BASE_URL ?>/assets/uploads/<?= $restaurant_data['logo'] ?>" class="sb-avatar" alt="">
            <?php else: ?>
                <div class="sb-avatar-placeholder">🍽️</div>
            <?php endif; ?>
            <div class="sb-brand-text">
                <div class="sb-name"><?= htmlspecialchars($restaurant_data['name']) ?></div>
                <div class="sb-plan"><?= $plan_label ?></div>
            </div>
        </div>
    </div>

    <!-- Branch Switcher -->
    <?php if (!empty($branches_list)): ?>
    <div class="branch-switcher">
        <div class="branch-switcher-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            الفرع الحالي
        </div>
        <?php if ($has_multiple): ?>
        <select class="branch-select" onchange="switchBranch(this.value)">
            <?php foreach ($branches_list as $br): ?>
            <option value="<?= $br['id'] ?>" <?= $br['id'] == $active_branch_id ? 'selected' : '' ?>>
                <?= htmlspecialchars($br['name']) ?><?= !$br['is_active'] ? ' (معطّل)' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <div class="branch-single">
            <span class="branch-dot"></span>
            <?= htmlspecialchars($active_branch_name) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Date + Theme -->
    <div class="sb-controls">
        <span class="sb-date"><?= date('d/m/Y') ?></span>
        <button class="theme-toggle" onclick="toggleTheme()">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg class="icon-sun"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        </button>
    </div>

    <!-- Nav — الجزء اللي كان يطغى على الـ footer -->
    <ul class="sb-nav">
        <li class="sb-nav-section">القائمة</li>
        <?php foreach ($nav_items as $item):
            [$file, $icon, $label] = $item;
            $badge  = $item[3] ?? 0;
            $locked = $item['locked'] ?? false;
            $active = ($current_page === $file);
            $svg    = $icons_svg[$icon] ?? '';
        ?>
        <li>
            <a href="<?= $file ?>" class="<?= $active ? 'active' : '' ?> <?= $locked ? 'sb-locked' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $svg ?></svg>
                </span>
                <span class="sb-label"><?= $label ?></span>
                <?php if ($badge > 0): ?><span class="sb-badge"><?= $badge ?></span><?php endif; ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- Footer — دايماً ظاهر بالأسفل -->
    <div class="sb-footer">
        <a href="<?= BASE_URL ?>/menu/<?= rawurlencode($restaurant_data['slug']) ?><?= $active_branch_slug ? '/' . rawurlencode($active_branch_slug) : '' ?>"
           target="_blank" class="sb-footer-btn btn-preview">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            معاينة المنيو
        </a>
        <a href="../logout.php" class="sb-footer-btn btn-logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            تسجيل خروج
        </a>
    </div>
</aside>

<script>
function toggleTheme(){
    var h=document.documentElement;
    var n=h.getAttribute('data-theme')==='dark'?'light':'dark';
    h.setAttribute('data-theme',n);
    localStorage.setItem('dash_theme',n);
}
function toggleSidebar(){
    var s=document.getElementById('sidebar'),
        o=document.getElementById('sbOverlay'),
        b=document.getElementById('hamburger'),
        open=s.classList.contains('open');
    s.classList.toggle('open',!open);
    o.classList.toggle('active',!open);
    b.classList.toggle('open',!open);
    document.body.style.overflow=open?'':'hidden';
}
// إغلاق الموبايل عند الضغط على رابط
document.querySelectorAll('.sb-nav a').forEach(function(l){
    l.addEventListener('click',function(){
        if(window.innerWidth<=768) toggleSidebar();
    });
});
// تبديل الفرع
function switchBranch(id){
    var url=new URL(window.location.href);
    url.searchParams.set('switch_branch',id);
    window.location.href=url.toString();
}
// إغلاق المودال بالضغط على الخلفية
document.addEventListener('click',function(e){
    document.querySelectorAll('.modal-bg').forEach(function(bg){
        if(e.target===bg) bg.classList.remove('open');
    });
});
</script>