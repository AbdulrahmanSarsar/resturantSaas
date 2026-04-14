<?php
$current_file = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));
$is_restaurants = ($current_dir === 'restaurants');

// Quick stats for sidebar
$total_restaurants  = $pdo->query("SELECT COUNT(*) FROM restaurants")->fetchColumn();
$active_restaurants = $pdo->query("SELECT COUNT(*) FROM restaurants WHERE is_active=1")->fetchColumn();
$today_orders       = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$expiring_soon      = $pdo->query("SELECT COUNT(*) FROM restaurants WHERE subscription_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,700;0,9..144,900;1,9..144,300&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

<style>
/* ============================================================
   ADMIN PANEL — SHARED DESIGN SYSTEM
   Dark sidebar + Light content area
   Primary: #FF6B35 (orange, same as restaurant dashboard)
   ============================================================ */

:root {
    /* Sidebar tokens */
    --sb-bg:     #0F0F0F;
    --sb-bg2:    #181818;
    --sb-line:   rgba(255,255,255,0.07);
    --sb-ink:    #F0EBE3;
    --sb-ink2:   rgba(240,235,227,0.45);
    --sb-ink3:   rgba(240,235,227,0.15);
    --sb-w:      258px;

    /* Content tokens */
    --bg:        #F4F1ED;
    --bg2:       #FFFFFF;
    --bg3:       #EDEAE6;
    --line:      rgba(28,25,23,0.08);
    --ink:       #1C1917;
    --ink2:      rgba(28,25,23,0.5);
    --ink3:      rgba(28,25,23,0.25);
    --shadow:    0 1px 4px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
    --shadow2:   0 2px 8px rgba(0,0,0,0.08), 0 8px 24px rgba(0,0,0,0.06);

    /* Brand */
    --p:         #FF6B35;
    --p-glow:    rgba(255,107,53,0.22);
}

*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }

body {
    font-family: 'Tajawal', sans-serif;
    background: var(--bg);
    min-height: 100vh;
    color: var(--ink);
}

/* Grain texture */
body::before {
    content: '';
    position: fixed; inset: 0; z-index: 0;
    pointer-events: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='200' height='200' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");
    opacity: 1;
}

/* ===== TOPNAV MOBILE ===== */
.topnav {
    display: none;
    position: fixed; top:0; left:0; right:0;
    height: 56px; z-index: 300;
    background: var(--bg2); border-bottom: 1px solid var(--line);
    align-items: center; justify-content: space-between;
    padding: 0 16px;
    box-shadow: var(--shadow);
}
.topnav-brand {
    display: flex; align-items: center; gap: 9px;
}
.topnav-mark {
    width: 30px; height: 30px; border-radius: 9px;
    background: var(--p); display: flex; align-items: center; justify-content: center;
}
.topnav-mark svg { width: 15px; height: 15px; color: #fff; }
.topnav-name {
    font-family: 'Fraunces', serif;
    font-size: 14px; font-weight: 700; color: var(--ink);
}
.topnav-name span { color: var(--p); }
.hamburger {
    width: 38px; height: 38px; background: var(--bg3);
    border: 1px solid var(--line); border-radius: 10px;
    cursor: pointer; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 5px;
}
.hamburger span {
    display: block; width: 18px; height: 1.5px;
    background: var(--ink); border-radius: 2px; transition: all 0.3s;
}
.hamburger.open span:nth-child(1) { transform: translateY(6.5px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-6.5px) rotate(-45deg); }

/* ===== OVERLAY ===== */
.sb-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.5); z-index: 398;
    backdrop-filter: blur(3px);
}
.sb-overlay.active { display: block; }

/* ===== SIDEBAR ===== */
.sidebar {
    position: fixed; top: 0; right: 0;
    width: var(--sb-w); height: 100vh;
    background: var(--sb-bg); z-index: 399;
    display: flex; flex-direction: column;
    border-left: 1px solid var(--sb-line);
    transition: transform 0.35s cubic-bezier(0.4,0,0.2,1);
    overflow: hidden;
}

@media (min-width: 769px) {
    .sidebar { transform: translateX(0) !important; }
    .topnav  { display: none !important; }
    .sb-overlay { display: none !important; }
}
@media (max-width: 768px) {
    .topnav  { display: flex; }
    .sidebar { transform: translateX(100%); }
    .sidebar.open { transform: translateX(0); }
}

/* Sidebar top stripe */
.sidebar::before {
    content: '';
    position: absolute; top: 0; right: 0; left: 0;
    height: 2px; background: var(--p); z-index: 1;
}

/* Header */
.sb-header {
    padding: 26px 18px 18px;
    border-bottom: 1px solid var(--sb-line);
    flex-shrink: 0;
}
.sb-brand {
    display: flex; align-items: center; gap: 11px;
    margin-bottom: 16px;
}
.sb-brand-mark {
    width: 40px; height: 40px; border-radius: 13px;
    background: var(--p); display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 14px var(--p-glow);
}
.sb-brand-mark svg { width: 18px; height: 18px; color: #fff; }
.sb-brand-name {
    font-family: 'Fraunces', serif;
    font-size: 16px; font-weight: 900; color: var(--sb-ink);
}
.sb-brand-name span { color: var(--p); }
.sb-admin-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px;
    background: rgba(255,107,53,0.12);
    border: 1px solid rgba(255,107,53,0.2);
    border-radius: 20px; font-size: 10px; font-weight: 700;
    color: var(--p); letter-spacing: 1px;
}
.sb-admin-badge svg { width: 10px; height: 10px; }

/* Sidebar quick stats */
.sb-stats {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 7px; padding: 14px 16px;
    border-bottom: 1px solid var(--sb-line);
    flex-shrink: 0;
}
.sb-stat {
    background: var(--sb-bg2); border: 1px solid var(--sb-line);
    border-radius: 11px; padding: 9px 10px; text-align: center;
}
.sb-stat-num {
    font-family: 'Fraunces', serif;
    font-size: 18px; font-weight: 900; color: var(--sb-ink); line-height: 1;
}
.sb-stat-lbl {
    font-size: 10px; color: var(--sb-ink2); font-weight: 600; margin-top: 2px;
}

/* Nav */
.sb-nav {
    flex: 1; padding: 12px 10px;
    list-style: none; overflow-y: auto; scrollbar-width: none;
}
.sb-nav::-webkit-scrollbar { display: none; }

.sb-nav li { margin-bottom: 2px; }
.sb-nav li a {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 12px;
    color: var(--sb-ink2); text-decoration: none;
    font-size: 13px; font-weight: 600;
    border-radius: 11px;
    transition: all 0.2s;
    position: relative;
}
.sb-nav li a:hover {
    background: rgba(255,255,255,0.05);
    color: var(--sb-ink);
}
.sb-nav li a.active {
    background: rgba(255,107,53,0.12);
    color: var(--p);
    border: 1px solid rgba(255,107,53,0.15);
}
.sb-nav li a.active .nav-dot {
    background: var(--p);
    box-shadow: 0 0 6px var(--p);
}
.nav-icon-wrap {
    width: 30px; height: 30px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; transition: background 0.2s;
}
.sb-nav li a.active .nav-icon-wrap {
    background: rgba(255,107,53,0.15);
}
.nav-icon-wrap svg { width: 15px; height: 15px; }
.nav-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--sb-ink3); margin-right: auto; flex-shrink: 0;
    transition: all 0.2s;
}
.nav-badge-pill {
    margin-right: auto;
    background: var(--p); color: #fff;
    border-radius: 20px; padding: 2px 8px;
    font-size: 10px; font-weight: 800; flex-shrink: 0;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%,100% { box-shadow: 0 0 0 0 var(--p-glow); }
    50%      { box-shadow: 0 0 0 4px transparent; }
}

/* Footer */
.sb-footer {
    padding: 12px 10px;
    border-top: 1px solid var(--sb-line);
    flex-shrink: 0;
}
.sb-logout {
    display: flex; align-items: center; gap: 9px;
    padding: 10px 12px; border-radius: 11px;
    text-decoration: none; font-size: 13px; font-weight: 600;
    color: var(--sb-ink2); transition: all 0.2s;
    border: 1px solid transparent;
}
.sb-logout svg { width: 15px; height: 15px; flex-shrink: 0; }
.sb-logout:hover {
    background: rgba(239,68,68,0.1);
    border-color: rgba(239,68,68,0.15);
    color: #EF4444;
}
.sb-preview {
    display: flex; align-items: center; gap: 9px;
    padding: 10px 12px; border-radius: 11px;
    text-decoration: none; font-size: 13px; font-weight: 600;
    color: var(--sb-ink2); transition: all 0.2s; margin-bottom: 4px;
    border: 1px solid transparent;
}
.sb-preview svg { width: 15px; height: 15px; flex-shrink: 0; }
.sb-preview:hover {
    background: rgba(255,255,255,0.05);
    color: var(--sb-ink);
}

/* ===== MAIN CONTENT ===== */
.main {
    margin-right: var(--sb-w);
    padding: 28px; min-width: 0;
    position: relative; z-index: 1;
}
@media (max-width: 768px) {
    .main { margin-right: 0; margin-top: 56px; padding: 16px; }
}

/* ===== PAGE HEADER ===== */
.page-title {
    font-family: 'Fraunces', serif;
    font-size: 24px; font-weight: 900; color: var(--ink);
    line-height: 1.1; margin-bottom: 3px;
}
.page-subtitle { font-size: 13px; color: var(--ink2); font-weight: 500; }

/* ===== CARD ===== */
.card {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; overflow: hidden;
    box-shadow: var(--shadow);
    transition: box-shadow 0.2s;
}
.card-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--line);
    display: flex; justify-content: space-between; align-items: center;
}
.card-header h3 {
    font-family: 'Fraunces', serif;
    font-size: 14px; font-weight: 700; color: var(--ink);
}
.card-header a {
    font-size: 12px; color: var(--p); text-decoration: none; font-weight: 700;
    display: flex; align-items: center; gap: 4px;
}
.card-header a:hover { opacity: 0.75; }

/* ===== BUTTONS ===== */
.btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 20px; border-radius: 11px;
    font-size: 13px; font-weight: 700; cursor: pointer;
    border: none; font-family: 'Tajawal', sans-serif;
    text-decoration: none; transition: all 0.2s; flex-shrink: 0;
}
.btn svg { width: 14px; height: 14px; flex-shrink: 0; }
.btn-primary {
    background: var(--p); color: #fff;
    box-shadow: 0 4px 14px var(--p-glow);
}
.btn-primary:hover { opacity: 0.88; transform: translateY(-1px); }
.btn-secondary {
    background: var(--bg3); border: 1px solid var(--line);
    color: var(--ink2);
}
.btn-secondary:hover { background: var(--line); color: var(--ink); }
.btn-success  { background: rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.2); color:#16A34A; }
.btn-success:hover { background:#22C55E; color:#fff; border-color:#22C55E; }
.btn-danger   { background: rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.15); color:#EF4444; }
.btn-danger:hover  { background:#EF4444; color:#fff; border-color:#EF4444; }
.btn-warning  { background: rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.2); color:#D97706; }
.btn-warning:hover { background:#F59E0B; color:#fff; border-color:#F59E0B; }
.btn-sm       { padding: 6px 13px; font-size: 11px; border-radius: 8px; }
.btn-sm svg   { width: 11px; height: 11px; }

/* ===== ALERT ===== */
.alert {
    padding: 12px 16px; border-radius: 12px;
    margin-bottom: 18px; font-size: 13px; font-weight: 600;
    display: flex; align-items: center; gap: 8px;
}
.alert svg { width: 15px; height: 15px; flex-shrink: 0; }
.alert-success { background: rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.18); color:#16A34A; }
.alert-error   { background: rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.18); color:#EF4444; }

/* ===== BADGES ===== */
.badge {
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700; display: inline-block;
    white-space: nowrap;
}
.badge-active    { background:rgba(34,197,94,0.10);  border:1px solid rgba(34,197,94,0.20);  color:#16A34A; }
.badge-inactive  { background:rgba(239,68,68,0.08);  border:1px solid rgba(239,68,68,0.15);  color:#EF4444; }
.badge-basic     { background:rgba(59,130,246,0.10); border:1px solid rgba(59,130,246,0.20); color:#2563EB; }
.badge-advanced  { background:rgba(139,92,246,0.10); border:1px solid rgba(139,92,246,0.20); color:#7C3AED; }
.badge-premium   { background:rgba(245,158,11,0.10); border:1px solid rgba(245,158,11,0.20); color:#D97706; }
.badge-pending   { background:rgba(245,158,11,0.10); border:1px solid rgba(245,158,11,0.20); color:#D97706; }
.badge-confirmed { background:rgba(59,130,246,0.10); border:1px solid rgba(59,130,246,0.20); color:#2563EB; }
.badge-preparing { background:rgba(245,158,11,0.10); border:1px solid rgba(245,158,11,0.20); color:#D97706; }
.badge-ready     { background:rgba(139,92,246,0.10); border:1px solid rgba(139,92,246,0.20); color:#7C3AED; }
.badge-delivered { background:rgba(34,197,94,0.10);  border:1px solid rgba(34,197,94,0.20);  color:#16A34A; }
.badge-cancelled { background:rgba(239,68,68,0.08);  border:1px solid rgba(239,68,68,0.15);  color:#EF4444; }

/* ===== FORM ELEMENTS ===== */
label {
    display: block; font-size: 11px; font-weight: 700;
    color: var(--ink2); letter-spacing: 0.5px; margin-bottom: 7px;
    text-transform: uppercase;
}
input[type=text], input[type=email], input[type=password],
input[type=number], input[type=date], input[type=tel],
select, textarea {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid var(--line); border-radius: 11px;
    font-size: 13px; font-family: 'Tajawal', sans-serif;
    color: var(--ink); background: var(--bg3);
    outline: none; transition: border-color 0.25s, background 0.25s;
}
input:focus, select:focus, textarea:focus {
    border-color: rgba(255,107,53,0.4);
    background: var(--bg2);
    box-shadow: 0 0 0 3px rgba(255,107,53,0.08);
}
textarea { resize: vertical; min-height: 80px; }
.form-group { margin-bottom: 13px; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 500px) { .form-row-2 { grid-template-columns: 1fr; } }

/* File input */
input[type=file] {
    padding: 9px 12px;
    border-style: dashed; cursor: pointer;
}

/* ===== TABLE ===== */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
table { width: 100%; border-collapse: collapse; min-width: 520px; }
th, td {
    padding: 12px 16px; text-align: right;
    border-bottom: 1px solid var(--line); font-size: 13px;
}
th { background: var(--bg3); color: var(--ink2); font-weight: 700; font-size: 11px; white-space: nowrap; text-transform: uppercase; letter-spacing: 0.5px; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: color-mix(in srgb, var(--p) 3%, transparent); }

/* ===== MODAL ===== */
.modal-bg {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.5); z-index: 500;
    backdrop-filter: blur(4px);
    align-items: center; justify-content: center;
    padding: 20px; overflow-y: auto;
}
.modal-bg.open { display: flex; }
.modal {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 20px; padding: 26px; width: 100%; max-width: 500px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.15);
    animation: modalIn 0.3s cubic-bezier(0.34,1.56,0.64,1); margin: auto;
}
@keyframes modalIn {
    from { opacity:0; transform:scale(0.92) translateY(16px); }
    to   { opacity:1; transform:scale(1) translateY(0); }
}
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px;
}
.modal-header h3 {
    font-family: 'Fraunces', serif;
    font-size: 17px; font-weight: 700; color: var(--ink);
}
.modal-close {
    background: var(--bg3); border: 1px solid var(--line);
    width: 32px; height: 32px; border-radius: 50%;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: var(--ink2); transition: all 0.2s;
}
.modal-close:hover { background: rgba(239,68,68,0.1); color: #EF4444; }
.modal-close svg { width: 14px; height: 14px; }
.modal-footer { display: flex; gap: 8px; margin-top: 18px; }

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center; padding: 64px 20px;
    color: var(--ink2);
}
.empty-icon {
    width: 68px; height: 68px; border-radius: 20px;
    background: var(--bg3); border: 1px solid var(--line);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px; color: var(--ink3);
}
.empty-icon svg { width: 28px; height: 28px; }
.empty-state p { font-size: 14px; font-weight: 600; }

/* ===== CARD-IN ANIMATION ===== */
@keyframes cardIn {
    from { opacity:0; transform:translateY(12px); }
    to   { opacity:1; transform:translateY(0); }
}

/* ===== FILTER SELECT ===== */
.filter-select {
    padding: 9px 14px; border: 1.5px solid var(--line);
    border-radius: 11px; font-size: 12px; font-weight: 600;
    font-family: 'Tajawal', sans-serif; color: var(--ink);
    background: var(--bg2); outline: none; cursor: pointer;
    transition: border-color 0.2s;
}
.filter-select:focus { border-color: rgba(255,107,53,0.4); }
</style>

<!-- Topnav Mobile -->
<div class="topnav">
    <div class="topnav-brand">
        <div class="topnav-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>
        <div class="topnav-name">Menu<span>Pro</span> <span style="font-size:10px;color:var(--ink2);font-family:'Tajawal',sans-serif;font-weight:600">أدمن</span></div>
    </div>
    <button class="hamburger" id="hamburger" onclick="toggleSidebar()">
        <span></span><span></span><span></span>
    </button>
</div>

<div class="sb-overlay" id="sbOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">

    <!-- Header -->
    <div class="sb-header">
        <div class="sb-brand">
            <div class="sb-brand-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <div class="sb-brand-name">Menu<span>Pro</span></div>
        </div>
        <div class="sb-admin-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:10px;height:10px"><circle cx="12" cy="8" r="5"/><path d="M3 21v-2a7 7 0 0114 0v2"/></svg>
            SUPER ADMIN
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="sb-stats">
        <div class="sb-stat">
            <div class="sb-stat-num"><?= $total_restaurants ?></div>
            <div class="sb-stat-lbl">مطعم</div>
        </div>
        <div class="sb-stat">
            <div class="sb-stat-num"><?= $today_orders ?></div>
            <div class="sb-stat-lbl">طلب اليوم</div>
        </div>
        <div class="sb-stat">
            <div class="sb-stat-num"><?= $active_restaurants ?></div>
            <div class="sb-stat-lbl">نشط</div>
        </div>
        <div class="sb-stat">
            <div class="sb-stat-num" style="color:<?= $expiring_soon > 0 ? '#F59E0B' : 'var(--sb-ink)' ?>"><?= $expiring_soon ?></div>
            <div class="sb-stat-lbl">ينتهي قريباً</div>
        </div>
    </div>

    <!-- Nav -->
    <ul class="sb-nav">
        <li>
            <a href="<?= BASE_URL ?>/admin/index.php"
               class="<?= $current_file==='index.php' && !$is_restaurants ? 'active' : '' ?>">
                <div class="nav-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
                    </svg>
                </div>
                الرئيسية
                <span class="nav-dot"></span>
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>/admin/restaurants/index.php"
               class="<?= $is_restaurants ? 'active' : '' ?>">
                <div class="nav-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/>
                        <line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>
                    </svg>
                </div>
                المطاعم
                <span class="nav-dot"></span>
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>/admin/orders.php"
               class="<?= $current_file==='orders.php' ? 'active' : '' ?>">
                <div class="nav-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                        <rect x="9" y="3" width="6" height="4" rx="2"/>
                        <line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/>
                    </svg>
                </div>
                الطلبات
                <?php if($today_orders > 0): ?>
                    <span class="nav-badge-pill"><?= $today_orders ?></span>
                <?php else: ?>
                    <span class="nav-dot"></span>
                <?php endif; ?>
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>/admin/subscriptions.php"
               class="<?= $current_file==='subscriptions.php' ? 'active' : '' ?>">
                <div class="nav-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <rect x="1" y="4" width="22" height="16" rx="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                </div>
                الاشتراكات
                <?php if($expiring_soon > 0): ?>
                    <span class="nav-badge-pill"><?= $expiring_soon ?></span>
                <?php else: ?>
                    <span class="nav-dot"></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <!-- Footer -->
    <div class="sb-footer">
        <a href="<?= BASE_URL ?>" class="sb-preview" target="_blank">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
            معاينة الموقع
        </a>
        <a href="<?= BASE_URL ?>/admin/logout.php" class="sb-logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            تسجيل خروج
        </a>
    </div>
</div>

<script>
function toggleSidebar() {
    var s = document.getElementById('sidebar');
    var o = document.getElementById('sbOverlay');
    var h = document.getElementById('hamburger');
    var open = s.classList.contains('open');
    s.classList.toggle('open', !open);
    o.classList.toggle('active', !open);
    h.classList.toggle('open', !open);
    document.body.style.overflow = open ? '' : 'hidden';
}
document.querySelectorAll('.sb-nav a').forEach(function(a) {
    a.addEventListener('click', function() {
        if(window.innerWidth <= 768) toggleSidebar();
    });
});
</script>