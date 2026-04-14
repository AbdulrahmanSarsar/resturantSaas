<?php
// plan_guard يتحمل من داخل sidebar لاحقاً
/**
 * sidebar.php — Restaurant Dashboard Shell
 * Includes: DOCTYPE, <head>, CSS variables, sidebar, topnav, overlay
 * Every page does: require_once 'sidebar.php'; then outputs .main content
 */
$current_page   = basename($_SERVER['PHP_SELF']);
$rid            = $_SESSION['restaurant_id'];

// تحميل نظام الصلاحيات
if(!function_exists('plan_has_feature')) {
    require_once __DIR__ . '/plan_guard.php';
}

$restaurant_data = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
$restaurant_data->execute([$rid]);
$restaurant_data = $restaurant_data->fetch();

$primary   = '#FF6B35';
$secondary = '#F7C59F';

$pending_count = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status = 'pending'");
$pending_count->execute([$rid]);
$pending_count = $pending_count->fetchColumn();

$plans = ['basic' => '🥉 أساسية', 'advanced' => '🥈 متقدمة', 'premium' => '⭐ بريميوم'];
$plan_label = $plans[$restaurant_data['subscription_plan']] ?? '—';

// Nav items
$nav_items = [
    ['index.php',      '/', 'الرئيسية',    'home'],
    ['categories.php', '⊞', 'التصنيفات',   'grid'],
    ['dishes.php',     '◈', 'الأطباق',     'layers'],
    ['offers.php',     '🎁', 'العروض',      'gift'],
    ['orders.php',     '≡', 'الطلبات',     'list',  $pending_count],
    ['qr.php',         '⊡', 'رمز QR',      'qr'],
    ['coupons.php',    '%', 'الكوبونات',   'tag'],
    ['ratings.php',    '★', 'التقييمات',   'star'],
    ['stats.php',      '↗', 'الإحصائيات', 'chart'],
    ['reports.php',    '📊', 'التقارير',   'bar-chart'],
    ['taxes.php',      '﹪', 'الضرائب',    'percent'],
    ['profile.php',    '⚙', 'الإعدادات',  'settings'],
];

// فلترة الروابط حسب الباقة
$nav_items = array_map(function($item) {
    $page_feature_map = [
        'orders.php'  => 'orders',
        'coupons.php' => 'coupons',
        'ratings.php' => 'ratings',
        'stats.php'   => 'stats',
    ];
    $feature = $page_feature_map[$item[0]] ?? null;
    if($feature) {
        // أضف علامة locked إذا الميزة مو متاحة
        $item['locked'] = !plan_has_feature($feature);
    } else {
        $item['locked'] = false;
    }
    return $item;
}, $nav_items);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم — <?= htmlspecialchars($restaurant_data['name']) ?></title>

    <!-- Apply theme before paint to avoid flash -->
    <script>
        (function () {
            var t = localStorage.getItem('dash_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

    <style>
    /* ============================================================
       CSS VARIABLES
    ============================================================ */
    :root {
        --p:  <?= $primary ?>;
        --s:  <?= $secondary ?>;
        --sw: 260px; /* sidebar width */
    }

    [data-theme="dark"] {
        --bg:      #0C0C0C;
        --bg2:     #141414;
        --bg3:     #1C1C1C;
        --bg4:     #252525;
        --ink:     #F0EBE3;
        --ink2:    #5A5550;
        --ink3:    #2E2A27;
        --line:    rgba(240,235,227,0.06);
        --line2:   rgba(240,235,227,0.10);
        --shadow:  0 4px 24px rgba(0,0,0,0.5);
        --shadow2: 0 2px 12px rgba(0,0,0,0.4);
        --bar-bg:  rgba(12,12,12,0.96);
        --sidebar-bg: #0E0E0E;
        --sidebar-line: rgba(240,235,227,0.06);
    }

    [data-theme="light"] {
        --bg:      #F4F1ED;
        --bg2:     #FFFFFF;
        --bg3:     #EDEAE6;
        --bg4:     #E2DED9;
        --ink:     #1C1917;
        --ink2:    #78726C;
        --ink3:    #C4BFB9;
        --line:    rgba(28,25,23,0.07);
        --line2:   rgba(28,25,23,0.12);
        --shadow:  0 4px 24px rgba(0,0,0,0.08);
        --shadow2: 0 2px 12px rgba(0,0,0,0.05);
        --bar-bg:  rgba(244,241,237,0.96);
        --sidebar-bg: #FFFFFF;
        --sidebar-line: rgba(28,25,23,0.08);
    }

    /* ============================================================
       RESET + BASE
    ============================================================ */
    *, *::before, *::after {
        margin: 0; padding: 0;
        box-sizing: border-box;
        -webkit-tap-highlight-color: transparent;
    }

    html { scroll-behavior: smooth; }

    body {
        font-family: 'Tajawal', sans-serif;
        background: var(--bg);
        color: var(--ink);
        min-height: 100vh;
        display: flex;
        transition: background 0.3s, color 0.3s;
        overflow-x: hidden;
    }

    /* Grain overlay */
    body::after {
        content: '';
        position: fixed; inset: 0;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.022'/%3E%3C/svg%3E");
        pointer-events: none;
        z-index: 9998;
    }

    /* ============================================================
       SIDEBAR
    ============================================================ */
    .sidebar {
        position: fixed;
        top: 0; right: 0;
        width: var(--sw);
        height: 100vh;
        background: var(--sidebar-bg);
        border-left: 1px solid var(--sidebar-line);
        z-index: 400;
        display: flex;
        flex-direction: column;
        transition: transform 0.35s cubic-bezier(0.4,0,0.2,1),
                    background 0.3s, border-color 0.3s;
        overflow: hidden;
    }

    /* Accent strip */
    .sidebar::before {
        content: '';
        position: absolute;
        top: 0; right: 0;
        width: 3px;
        height: 100%;
        background: linear-gradient(to bottom, var(--p), var(--s), transparent);
        opacity: 0.6;
    }

    /* Mobile: hidden by default */
    @media (max-width: 768px) {
        .sidebar { transform: translateX(100%); }
        .sidebar.open { transform: translateX(0); }
    }

    /* ---- Sidebar Header ---- */
    .sb-header {
        padding: 28px 20px 20px;
        border-bottom: 1px solid var(--sidebar-line);
        flex-shrink: 0;
        position: relative;
    }

    /* Orb behind avatar */
    .sb-header::before {
        content: '';
        position: absolute;
        top: -30px; left: -30px;
        width: 130px; height: 130px;
        background: var(--p);
        border-radius: 50%;
        opacity: 0.06;
        filter: blur(30px);
        pointer-events: none;
    }

    .sb-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .sb-avatar {
        width: 46px; height: 46px;
        border-radius: 14px;
        object-fit: cover;
        border: 1.5px solid var(--line2);
        flex-shrink: 0;
        transition: border-color 0.3s;
    }
    .sb-avatar-placeholder {
        width: 46px; height: 46px;
        border-radius: 14px;
        background: color-mix(in srgb, var(--p) 15%, transparent);
        border: 1.5px solid color-mix(in srgb, var(--p) 25%, transparent);
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; flex-shrink: 0;
    }

    .sb-brand-text { min-width: 0; }
    .sb-name {
        font-family: 'Fraunces', serif;
        font-size: 15px; font-weight: 700;
        color: var(--ink); letter-spacing: -0.2px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        transition: color 0.3s;
    }
    .sb-plan {
        display: inline-flex; align-items: center;
        gap: 4px;
        background: color-mix(in srgb, var(--p) 12%, transparent);
        border: 1px solid color-mix(in srgb, var(--p) 20%, transparent);
        color: var(--p);
        padding: 3px 9px;
        border-radius: 20px;
        font-size: 11px; font-weight: 700;
        margin-top: 3px;
    }

    /* Theme toggle in header */
    .sb-controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .sb-date {
        font-size: 11px; color: var(--ink2); font-weight: 600;
    }
    .theme-toggle {
        width: 32px; height: 32px;
        border-radius: 10px;
        background: var(--bg3);
        border: 1px solid var(--line);
        color: var(--ink2);
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        transition: background 0.2s, transform 0.3s, border-color 0.3s;
        flex-shrink: 0;
    }
    .theme-toggle:active { transform: rotate(20deg) scale(0.9); }
    .theme-toggle svg { width: 14px; height: 14px; }
    .icon-moon { display: block; }
    .icon-sun  { display: none; }
    [data-theme="light"] .icon-moon { display: none; }
    [data-theme="light"] .icon-sun  { display: block; }

    /* ---- Nav ---- */
    .sb-nav {
        flex: 1;
        padding: 12px 10px;
        list-style: none;
        overflow-y: auto;
        scrollbar-width: none;
    }
    .sb-nav::-webkit-scrollbar { display: none; }

    .sb-nav-section {
        font-size: 10px; font-weight: 800;
        color: var(--ink2); letter-spacing: 1.5px;
        text-transform: uppercase;
        padding: 10px 10px 6px;
        margin-top: 4px;
    }

    .sb-nav li a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        color: var(--ink2);
        text-decoration: none;
        font-size: 13px; font-weight: 600;
        border-radius: 12px;
        margin-bottom: 2px;
        transition: all 0.2s;
        position: relative;
        white-space: nowrap;
    }

    .sb-icon {
        width: 32px; height: 32px;
        border-radius: 9px;
        background: var(--bg3);
        border: 1px solid var(--line);
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; font-weight: 700;
        flex-shrink: 0;
        transition: all 0.2s;
        color: var(--ink2);
        font-family: monospace;
    }

    .sb-nav li a:hover {
        color: var(--ink);
        background: var(--bg3);
    }
    .sb-nav li a:hover .sb-icon {
        background: color-mix(in srgb, var(--p) 12%, transparent);
        border-color: color-mix(in srgb, var(--p) 20%, transparent);
        color: var(--p);
    }

    .sb-nav li a.active {
        color: var(--ink);
        background: color-mix(in srgb, var(--p) 8%, var(--bg3));
    }
    .sb-nav li a.active .sb-icon {
        background: var(--p);
        border-color: transparent;
        color: #fff;
        box-shadow: 0 4px 12px color-mix(in srgb, var(--p) 40%, transparent);
    }

    /* Active indicator dot */
    .sb-nav li a.active::after {
        content: '';
        position: absolute;
        left: 10px; top: 50%;
        transform: translateY(-50%);
        width: 4px; height: 4px;
        border-radius: 50%;
        background: var(--p);
    }

    .sb-label { flex: 1; }

    .sb-badge {
        background: var(--p);
        color: #fff;
        border-radius: 20px;
        padding: 2px 8px;
        font-size: 11px; font-weight: 800;
        min-width: 22px; text-align: center;
        animation: badgePulse 2s infinite;
    }
    @keyframes badgePulse {
        0%,100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--p) 40%, transparent); }
        50%      { box-shadow: 0 0 0 4px transparent; }
    }

    /* ---- Footer ---- */
    .sb-footer {
        padding: 12px 10px;
        border-top: 1px solid var(--sidebar-line);
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
        transition: border-color 0.3s;
    }

    .sb-footer-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 10px;
        border-radius: 12px;
        text-decoration: none;
        font-size: 13px; font-weight: 700;
        transition: all 0.2s;
        border: 1px solid var(--line);
    }
    .sb-footer-btn svg { width: 14px; height: 14px; flex-shrink: 0; }

    .btn-preview {
        background: color-mix(in srgb, var(--p) 10%, transparent);
        border-color: color-mix(in srgb, var(--p) 20%, transparent);
        color: var(--p);
    }
    .btn-preview:hover {
        background: var(--p);
        color: #fff;
        border-color: transparent;
    }
    .btn-logout {
        background: var(--bg3);
        color: var(--ink2);
    }
    .btn-logout:hover {
        background: rgba(239,68,68,0.1);
        border-color: rgba(239,68,68,0.2);
        color: #EF4444;
    }

    /* ============================================================
       OVERLAY
    ============================================================ */
    .sb-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(4px);
        z-index: 399;
        opacity: 0;
        transition: opacity 0.3s;
    }
    .sb-overlay.active { display: block; opacity: 1; }

    /* ============================================================
       TOP NAV (mobile only)
    ============================================================ */
    .topnav {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0;
        height: 56px;
        background: var(--bar-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-bottom: 1px solid var(--line);
        z-index: 300;
        align-items: center;
        justify-content: space-between;
        padding: 0 14px;
        transition: background 0.3s, border-color 0.3s;
    }

    .topnav-brand {
        display: flex; align-items: center; gap: 9px;
    }
    .topnav-logo {
        width: 32px; height: 32px;
        border-radius: 9px;
        object-fit: cover;
        border: 1px solid var(--line);
    }
    .topnav-logo-ph {
        width: 32px; height: 32px;
        border-radius: 9px;
        background: color-mix(in srgb, var(--p) 15%, transparent);
        border: 1px solid color-mix(in srgb, var(--p) 25%, transparent);
        display: flex; align-items: center; justify-content: center;
        font-size: 16px;
    }
    .topnav-name {
        font-family: 'Fraunces', serif;
        font-size: 15px; font-weight: 700;
        color: var(--ink); letter-spacing: -0.2px;
    }

    .topnav-right { display: flex; align-items: center; gap: 8px; }

    .topnav-theme {
        width: 34px; height: 34px;
        border-radius: 10px;
        background: var(--bg3);
        border: 1px solid var(--line);
        color: var(--ink2);
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        transition: background 0.2s, border-color 0.3s;
    }
    .topnav-theme svg { width: 14px; height: 14px; }

    .hamburger {
        width: 36px; height: 36px;
        border-radius: 10px;
        background: var(--bg3);
        border: 1px solid var(--line);
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        gap: 4px; cursor: pointer;
        transition: background 0.2s, border-color 0.3s;
    }
    .hamburger span {
        display: block;
        width: 16px; height: 1.5px;
        background: var(--ink2); border-radius: 2px;
        transition: all 0.3s;
    }
    .hamburger.open span:nth-child(1) { transform: translateY(5.5px) rotate(45deg); }
    .hamburger.open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
    .hamburger.open span:nth-child(3) { transform: translateY(-5.5px) rotate(-45deg); }

    /* ============================================================
       MAIN CONTENT
    ============================================================ */
    .main {
        margin-right: var(--sw);
        flex: 1;
        min-width: 0;
        padding: 28px;
        transition: margin 0.35s;
    }

    @media (max-width: 768px) {
        .topnav  { display: flex; }
        .main    { margin-right: 0; margin-top: 56px; padding: 16px; }
    }

    /* ============================================================
       SHARED COMPONENTS (used across all pages)
    ============================================================ */

    /* Page title */
    .page-head { margin-bottom: 24px; }
    .page-title {
        font-family: 'Fraunces', serif;
        font-size: 24px; font-weight: 900;
        color: var(--ink); letter-spacing: -0.4px;
        margin-bottom: 3px;
        transition: color 0.3s;
    }
    .page-subtitle {
        font-size: 13px; color: var(--ink2); font-weight: 500;
    }

    /* Cards */
    .card {
        background: var(--bg2);
        border-radius: 18px;
        border: 1px solid var(--line);
        overflow: hidden;
        transition: background 0.3s, border-color 0.3s;
    }
    .card-header {
        padding: 14px 20px;
        border-bottom: 1px solid var(--line);
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: border-color 0.3s;
    }
    .card-header h3 {
        font-family: 'Fraunces', serif;
        font-size: 14px; font-weight: 700;
        color: var(--ink); letter-spacing: -0.2px;
    }
    .card-header a {
        font-size: 12px; color: var(--p);
        text-decoration: none; font-weight: 700;
        transition: opacity 0.2s;
    }
    .card-header a:hover { opacity: 0.7; }

    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center; gap: 7px;
        padding: 10px 20px;
        border-radius: 12px;
        font-size: 13px; font-weight: 700;
        cursor: pointer; border: none;
        font-family: 'Tajawal', sans-serif;
        text-decoration: none;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .btn svg { width: 15px; height: 15px; }
    .btn-primary {
        background: var(--p);
        color: #fff;
        box-shadow: 0 4px 14px color-mix(in srgb, var(--p) 35%, transparent);
    }
    .btn-primary:hover { opacity: 0.88; transform: translateY(-1px); }
    .btn-primary:active { transform: scale(0.97); }
    .btn-secondary {
        background: var(--bg3);
        border: 1px solid var(--line);
        color: var(--ink2);
    }
    .btn-secondary:hover { background: var(--bg4); border-color: var(--line2); color: var(--ink); }
    .btn-danger {
        background: rgba(239,68,68,0.1);
        border: 1px solid rgba(239,68,68,0.2);
        color: #EF4444;
    }
    .btn-danger:hover { background: #EF4444; color: #fff; border-color: transparent; }
    .btn-sm { padding: 7px 13px; font-size: 12px; border-radius: 9px; }

    /* Alerts */
    .alert {
        padding: 13px 16px;
        border-radius: 12px;
        margin-bottom: 18px;
        font-size: 13px; font-weight: 600;
        display: flex; align-items: center; gap: 8px;
        border: 1px solid transparent;
        animation: alertIn 0.3s cubic-bezier(0.22,1,0.36,1) both;
    }
    @keyframes alertIn {
        from { opacity:0; transform:translateY(-8px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .alert svg { width: 16px; height: 16px; flex-shrink: 0; }
    .alert-success {
        background: rgba(34,197,94,0.08);
        border-color: rgba(34,197,94,0.2);
        color: #22C55E;
    }
    .alert-error {
        background: rgba(239,68,68,0.08);
        border-color: rgba(239,68,68,0.2);
        color: #EF4444;
    }
    .alert-warning {
        background: rgba(245,158,11,0.08);
        border-color: rgba(245,158,11,0.2);
        color: #F59E0B;
    }

    /* Badges */
    .badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 11px; font-weight: 700;
    }
    .badge-pending   { background: rgba(245,158,11,0.12); color: #F59E0B; border: 1px solid rgba(245,158,11,0.2); }
    .badge-confirmed { background: rgba(59,130,246,0.12); color: #3B82F6; border: 1px solid rgba(59,130,246,0.2); }
    .badge-preparing { background: rgba(139,92,246,0.12); color: #8B5CF6; border: 1px solid rgba(139,92,246,0.2); }
    .badge-ready     { background: rgba(34,197,94,0.12);  color: #22C55E; border: 1px solid rgba(34,197,94,0.2); }
    .badge-delivered { background: var(--bg3); color: var(--ink2); border: 1px solid var(--line); }
    .badge-cancelled { background: rgba(239,68,68,0.12); color: #EF4444; border: 1px solid rgba(239,68,68,0.2); }
    .badge-active    { background: rgba(34,197,94,0.12);  color: #22C55E; border: 1px solid rgba(34,197,94,0.2); }
    .badge-inactive  { background: rgba(239,68,68,0.12); color: #EF4444; border: 1px solid rgba(239,68,68,0.2); }
    .badge-basic     { background: rgba(59,130,246,0.12); color: #3B82F6; border: 1px solid rgba(59,130,246,0.2); }
    .badge-advanced  { background: rgba(139,92,246,0.12); color: #8B5CF6; border: 1px solid rgba(139,92,246,0.2); }
    .badge-premium   { background: rgba(245,158,11,0.12); color: #F59E0B; border: 1px solid rgba(245,158,11,0.2); }

    /* Tables */
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 480px; }
    th {
        padding: 10px 16px;
        text-align: right;
        background: var(--bg3);
        color: var(--ink2);
        font-size: 11px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.8px;
        border-bottom: 1px solid var(--line);
        transition: background 0.3s, border-color 0.3s;
    }
    td {
        padding: 12px 16px;
        text-align: right;
        border-bottom: 1px solid var(--line);
        font-size: 13px; color: var(--ink);
        transition: border-color 0.3s;
    }
    tr:last-child td { border-bottom: none; }
    tbody tr { transition: background 0.15s; }
    tbody tr:hover td { background: var(--bg3); }

    /* Modals */
    .modal-bg {
        display: none;
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(6px);
        z-index: 500;
        align-items: flex-start;
        justify-content: center;
        padding: 20px;
        overflow-y: auto;
    }
    .modal-bg.open { display: flex; }

    .modal {
        background: var(--bg2);
        border: 1px solid var(--line);
        border-radius: 20px;
        padding: 26px;
        width: 100%; max-width: 520px;
        box-shadow: 0 24px 64px rgba(0,0,0,0.4);
        animation: modalIn 0.35s cubic-bezier(0.22,1,0.36,1) both;
        margin: auto;
        transition: background 0.3s, border-color 0.3s;
    }
    @keyframes modalIn {
        from { opacity:0; transform:scale(0.94) translateY(16px); }
        to   { opacity:1; transform:scale(1) translateY(0); }
    }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .modal-header h3 {
        font-family: 'Fraunces', serif;
        font-size: 17px; font-weight: 700;
        color: var(--ink); letter-spacing: -0.2px;
    }
    .modal-close {
        width: 32px; height: 32px;
        border-radius: 9px;
        background: var(--bg3);
        border: 1px solid var(--line);
        color: var(--ink2);
        font-size: 16px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: background 0.2s;
    }
    .modal-close:hover { background: var(--bg4); color: var(--ink); }
    .modal-footer {
        display: flex; gap: 9px; margin-top: 20px;
    }

    /* Form elements */
    .form-group { margin-bottom: 14px; }
    .form-group:last-child { margin-bottom: 0; }

    label {
        display: block;
        font-size: 12px; font-weight: 700;
        color: var(--ink2);
        margin-bottom: 6px;
        letter-spacing: 0.3px;
    }

    input[type=text],
    input[type=number],
    input[type=tel],
    input[type=password],
    input[type=date],
    input[type=url],
    input[type=email],
    input[type=file],
    textarea,
    select {
        width: 100%;
        padding: 10px 14px;
        background: var(--bg3);
        border: 1.5px solid var(--line);
        border-radius: 12px;
        font-size: 13px; font-weight: 500;
        font-family: 'Tajawal', sans-serif;
        color: var(--ink);
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s, background 0.3s;
    }
    input::placeholder, textarea::placeholder { color: var(--ink2); }
    input:focus, textarea:focus, select:focus {
        border-color: var(--p);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--p) 12%, transparent);
        background: var(--bg2);
    }
    textarea { resize: vertical; min-height: 78px; }
    select { cursor: pointer; }

    input[type=file] {
        padding: 9px 14px;
        border-style: dashed;
        cursor: pointer;
    }

    .form-row-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    @media (max-width: 480px) { .form-row-2 { grid-template-columns: 1fr; } }

    /* Filter tabs */
    .filter-tabs {
        display: flex; gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }
    .filter-tab {
        padding: 7px 15px;
        border-radius: 20px;
        border: 1.5px solid var(--line);
        background: var(--bg2);
        font-size: 12px; font-weight: 700;
        cursor: pointer; text-decoration: none;
        color: var(--ink2);
        transition: all 0.2s;
        font-family: 'Tajawal', sans-serif;
    }
    .filter-tab:hover { border-color: var(--p); color: var(--p); }
    .filter-tab.active {
        background: var(--p);
        border-color: transparent;
        color: #fff;
        box-shadow: 0 3px 10px color-mix(in srgb, var(--p) 30%, transparent);
    }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--ink2);
    }
    .empty-state-icon {
        width: 64px; height: 64px;
        border-radius: 20px;
        background: var(--bg3);
        border: 1px solid var(--line);
        display: flex; align-items: center; justify-content: center;
        font-size: 28px;
        margin: 0 auto 14px;
    }
    .empty-state p {
        font-size: 14px; font-weight: 600; color: var(--ink2);
    }

    /* Toast */
    .toast {
        position: fixed;
        bottom: 28px; left: 50%;
        transform: translateX(-50%) translateY(80px);
        background: var(--bg2);
        border: 1px solid var(--line);
        color: var(--ink);
        padding: 11px 22px;
        border-radius: 25px;
        font-size: 13px; font-weight: 700;
        z-index: 9000;
        box-shadow: var(--shadow);
        transition: transform 0.35s cubic-bezier(0.34,1.56,0.64,1);
        white-space: nowrap;
    }
    .toast.show { transform: translateX(-50%) translateY(0); }

    /* Section label */
    .section-label {
        display: flex; align-items: center; gap: 8px;
        font-size: 11px; font-weight: 800;
        color: var(--ink2);
        text-transform: uppercase; letter-spacing: 1.2px;
        margin-bottom: 12px;
    }
    .section-label::after {
        content:''; flex:1; height:1px; background: var(--line);
    }

    /* Card animation */
    .card-in {
        animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
    }
    @keyframes cardIn {
        from { opacity:0; transform:translateY(12px) scale(0.98); }
        to   { opacity:1; transform:translateY(0) scale(1); }
    }
    
.sb-locked { opacity: 0.5; position: relative; }
.sb-locked::after {
    content: '🔒';
    position: absolute;
    left: 14px; top: 50%;
    transform: translateY(-50%);
    font-size: 10px;
}
</style>
</head>
<body>

<!-- ============================================================
     TOP NAV (mobile)
============================================================ -->
<div class="topnav">
    <div class="topnav-brand">
        <?php if ($restaurant_data['logo']): ?>
            <img src="<?= BASE_URL ?>/assets/uploads/<?= $restaurant_data['logo'] ?>" class="topnav-logo">
        <?php else: ?>
            <div class="topnav-logo-ph">🍽️</div>
        <?php endif; ?>
        <span class="topnav-name"><?= htmlspecialchars($restaurant_data['name']) ?></span>
    </div>
    <div class="topnav-right">
        <button class="topnav-theme" onclick="toggleTheme()" title="تبديل المظهر">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg class="icon-sun"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        </button>
        <button class="hamburger" id="hamburger" onclick="toggleSidebar()">
            <span></span><span></span><span></span>
        </button>
    </div>
</div>

<!-- Overlay -->
<div class="sb-overlay" id="sbOverlay" onclick="toggleSidebar()"></div>

<!-- ============================================================
     SIDEBAR
============================================================ -->
<aside class="sidebar" id="sidebar">

    <!-- Header -->
    <div class="sb-header">
        <div class="sb-brand">
            <?php if ($restaurant_data['logo']): ?>
                <img src="<?= BASE_URL ?>/assets/uploads/<?= $restaurant_data['logo'] ?>" class="sb-avatar">
            <?php else: ?>
                <div class="sb-avatar-placeholder">🍽️</div>
            <?php endif; ?>
            <div class="sb-brand-text">
                <div class="sb-name"><?= htmlspecialchars($restaurant_data['name']) ?></div>
                <div class="sb-plan"><?= $plan_label ?></div>
            </div>
        </div>
        <div class="sb-controls">
            <span class="sb-date"><?= date('d/m/Y') ?></span>
            <button class="theme-toggle" onclick="toggleTheme()" title="تبديل المظهر">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                <svg class="icon-sun"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </button>
        </div>
    </div>

    <!-- Nav -->
    <ul class="sb-nav">
        <li class="sb-nav-section">القائمة</li>

        <?php
        $icons_svg = [
            'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'grid'     => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
            'layers'   => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
            'list'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'qr'       => '<rect x="3" y="3" width="5" height="5"/><rect x="16" y="3" width="5" height="5"/><rect x="3" y="16" width="5" height="5"/><path d="M21 16h-3a2 2 0 00-2 2v3"/><line x1="21" y1="21" x2="21" y2="21"/><path d="M14 14h3"/><line x1="14" y1="14" x2="14" y2="14"/>',
            'tag'      => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
            'star'     => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'chart'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'gift'     => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>',
            'percent'  => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
        ];
        foreach ($nav_items as $item):
            [$file, $sym, $label, $icon_key] = $item;
            $badge    = $item[4] ?? 0;
            $locked   = $item['locked'] ?? false;
            $is_active = ($current_page === $file);
            $svg_path = $icons_svg[$icon_key] ?? '';
        ?>
        <li>
            <a href="<?= $file ?>"
               class="<?= $is_active ? 'active' : '' ?> <?= $locked ? 'sb-locked' : '' ?>">
                <span class="sb-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;">
                        <?= $svg_path ?>
                    </svg>
                </span>
                <span class="sb-label"><?= $label ?></span>
                <?php if ($badge > 0): ?>
                    <span class="sb-badge"><?= $badge ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- Footer -->
    <div class="sb-footer">
        <a href="<?= BASE_URL ?>/menu/<?= $restaurant_data['slug'] ?>" target="_blank" class="sb-footer-btn btn-preview">
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
/* ============================================================
   THEME
============================================================ */
function toggleTheme() {
    var html = document.documentElement;
    var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('dash_theme', next);
}

/* ============================================================
   SIDEBAR TOGGLE (mobile)
============================================================ */
function toggleSidebar() {
    var sb      = document.getElementById('sidebar');
    var overlay = document.getElementById('sbOverlay');
    var burger  = document.getElementById('hamburger');
    var isOpen  = sb.classList.contains('open');

    sb.classList.toggle('open', !isOpen);
    overlay.classList.toggle('active', !isOpen);
    burger.classList.toggle('open', !isOpen);
    document.body.style.overflow = isOpen ? '' : 'hidden';
}

// Close on nav link click (mobile)
document.querySelectorAll('.sb-nav a').forEach(function(link) {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 768) toggleSidebar();
    });
});

// Close modals on backdrop click
document.addEventListener('click', function(e) {
    document.querySelectorAll('.modal-bg').forEach(function(bg) {
        if (e.target === bg) bg.classList.remove('open');
    });
});
</script>