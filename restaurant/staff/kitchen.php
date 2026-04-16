<?php

/**
 * kitchen.php — Branch-scoped (patch)
 *
 * التغييرات:
 *   - نجيب branch_id من restaurant_staff بعد اللوغن
 *   - كل استعلام orders يضيف AND branch_id = ? (لو الموظف مربوط بفرع)
 *   - لو branch_id = NULL → يشوف كل الطلبات (backward compatible)
 */
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'kitchen') {
    header('Location: login.php');
    exit;
}

$rid      = $_SESSION['staff_rest_id'];
$staff_id = $_SESSION['staff_id'];

// ===== جلب branch_id من الـ DB — محمي بـ try/catch =====
$branch_id     = null;
$branch_name   = '';
$branch_cond   = '';
$branch_params = [];
try {
    if (!isset($_SESSION['staff_branch_id'])) {
        $sb = $pdo->prepare("SELECT branch_id FROM restaurant_staff WHERE id = ?");
        $sb->execute([$staff_id]);
        $_SESSION['staff_branch_id'] = $sb->fetchColumn() ?: null;
    }
    $branch_id = $_SESSION['staff_branch_id'];
    if ($branch_id) {
        $bn = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $bn->execute([$branch_id]);
        $branch_name   = $bn->fetchColumn() ?: '';
        $branch_cond   = " AND branch_id = ?";
        $branch_params = [$branch_id];
    }
} catch (Exception $e) {
    $branch_id = null;
    $_SESSION['staff_branch_id'] = null;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// ===== API AJAX polling — branch-scoped =====
if (isset($_GET['ajax']) && $_GET['ajax'] === 'poll') {
    header('Content-Type: application/json');
    $last_id = intval($_GET['last_id'] ?? 0);

    // الاستعلام: نضيف شرط الفرع لو موجود
    // نقرأ من orders مع branch_id
    if ($branch_id) {
        $new = $pdo->prepare("
            SELECT o.id FROM orders o
            WHERE o.restaurant_id=? AND o.branch_id=? AND o.id > ?
              AND o.status IN ('pending','confirmed','preparing')
            LIMIT 5
        ");
        $new->execute([$rid, $branch_id, $last_id]);

        $counts_stmt = $pdo->prepare("
            SELECT status, COUNT(*) as c FROM orders
            WHERE restaurant_id=? AND branch_id=?
              AND status IN ('pending','confirmed','preparing')
            GROUP BY status
        ");
        $counts_stmt->execute([$rid, $branch_id]);
    } else {
        $new = $pdo->prepare("
            SELECT o.id FROM orders o
            WHERE o.restaurant_id=? AND o.id > ?
              AND o.status IN ('pending','confirmed','preparing')
            LIMIT 5
        ");
        $new->execute([$rid, $last_id]);

        $counts_stmt = $pdo->prepare("
            SELECT status, COUNT(*) as c FROM orders
            WHERE restaurant_id=? AND status IN ('pending','confirmed','preparing')
            GROUP BY status
        ");
        $counts_stmt->execute([$rid]);
    }

    $new_orders = $new->fetchAll();
    $status_counts = [];
    foreach ($counts_stmt->fetchAll() as $r) $status_counts[$r['status']] = $r['c'];

    echo json_encode(['new_orders' => $new_orders, 'counts' => $status_counts]);
    exit;
}

// ===== POST handlers — بدون تغيير =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['order_id'])) {
        $allowed = ['confirmed', 'preparing', 'ready', 'cancelled'];
        $status  = $_POST['status'] ?? '';
        if (in_array($status, $allowed)) {
            $pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=? AND restaurant_id=?")
                ->execute([$status, $_POST['order_id'], $rid]);
            try {
                $pdo->prepare("INSERT INTO order_status_log (order_id,status,changed_at) VALUES (?,?,NOW())")
                    ->execute([$_POST['order_id'], $status]);
            } catch (Exception $e) {
            }
        }
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true]);
            exit;
        }
    }
    if (isset($_POST['dish_id']) && isset($_POST['sold_out_action'])) {
        $pdo->prepare("UPDATE dishes SET sold_out=NOT sold_out WHERE id=? AND restaurant_id=?")
            ->execute([$_POST['dish_id'], $rid]);
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true]);
            exit;
        }
    }
}

// ===== جلب الطلبات — orders مع branch_id =====
if (false) { // placeholder
} else {
    // fallback: orders القديم — مع فلتر branch_id لو موجود
    try {
        if ($branch_id) {
            $orders_stmt = $pdo->prepare("
                SELECT *, COALESCE(restaurant_order_number, id) as display_num
                FROM orders
                WHERE restaurant_id=? AND branch_id=? AND status IN ('pending','confirmed','preparing')
                ORDER BY created_at ASC
            ");
            $orders_stmt->execute([$rid, $branch_id]);
        } else {
            $orders_stmt = $pdo->prepare("
                SELECT *, COALESCE(restaurant_order_number, id) as display_num
                FROM orders
                WHERE restaurant_id=? AND status IN ('pending','confirmed','preparing')
                ORDER BY created_at ASC
            ");
            $orders_stmt->execute([$rid]);
        }
    } catch (\Exception $e) {
        // عمود branch_id مش موجود بعد → كل الطلبات
        $orders_stmt = $pdo->prepare("
            SELECT *, COALESCE(restaurant_order_number, id) as display_num
            FROM orders
            WHERE restaurant_id=? AND status IN ('pending','confirmed','preparing')
            ORDER BY created_at ASC
        ");
        $orders_stmt->execute([$rid]);
    }
    $orders = $orders_stmt->fetchAll();

    foreach ($orders as &$o) {
        $items = $pdo->prepare("SELECT dish_id, dish_name, quantity, notes, options FROM order_items WHERE order_id=?");
        $items->execute([$o['id']]);
        $o['items'] = $items->fetchAll();
    }
    unset($o);

    try {
        if ($branch_id) {
            $max_id_stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM orders WHERE restaurant_id=? AND branch_id=?");
            $max_id_stmt->execute([$rid, $branch_id]);
        } else {
            $max_id_stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM orders WHERE restaurant_id=?");
            $max_id_stmt->execute([$rid]);
        }
    } catch (\Exception $e) {
        $max_id_stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM orders WHERE restaurant_id=?");
        $max_id_stmt->execute([$rid]);
    }
}

$last_id = intval($max_id_stmt->fetchColumn());

$dishes = $pdo->prepare("SELECT id, name, sold_out FROM dishes WHERE restaurant_id=? AND is_available=1 ORDER BY sort_order, name");
$dishes->execute([$rid]);
$dishes = $dishes->fetchAll();

$counts = ['pending' => 0, 'confirmed' => 0, 'preparing' => 0];
foreach ($orders as $o) {
    if (isset($counts[$o['status']])) $counts[$o['status']]++;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>مطبخ — <?= htmlspecialchars($_SESSION['staff_rest_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,700;9..144,900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --p: #FF6B35;
        }

        [data-theme="dark"] {
            --bg: #0A0A0A;
            --bg2: #111;
            --bg3: #1A1A1A;
            --bg4: #222;
            --ink: #F0EBE3;
            --ink2: #6B6560;
            --ink3: #2E2B28;
            --line: rgba(255, 255, 255, 0.06);
            --bar: rgba(10, 10, 10, 0.96);
        }

        [data-theme="light"] {
            --bg: #F4F1ED;
            --bg2: #fff;
            --bg3: #EDEAE6;
            --bg4: #E4E0DC;
            --ink: #1C1917;
            --ink2: #78716C;
            --ink3: #C8C4C0;
            --line: rgba(0, 0, 0, 0.07);
            --bar: rgba(244, 241, 237, 0.96);
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--bg);
            color: var(--ink);
            min-height: 100vh;
            transition: background .3s, color .3s;
        }

        /* TOPBAR */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 200;
            background: var(--bar);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--line);
            padding: 0 16px;
            height: 56px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .topbar-stripe {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--p), transparent);
        }

        .role-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.22);
            color: #F59E0B;
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .rest-name {
            font-family: 'Fraunces', serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--ink);
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--bg3);
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .2s, transform .3s;
            color: var(--ink2);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            font-family: 'Tajawal', sans-serif;
        }

        .icon-btn svg {
            width: 15px;
            height: 15px;
        }

        .icon-btn:active {
            transform: scale(0.9);
        }

        .theme-toggle .icon-moon {
            display: block;
        }

        .theme-toggle .icon-sun {
            display: none;
        }

        [data-theme="light"] .theme-toggle .icon-moon {
            display: none;
        }

        [data-theme="light"] .theme-toggle .icon-sun {
            display: block;
        }

        .logout-btn {
            padding: 0 13px;
            border-radius: 10px;
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.15);
            color: #EF4444;
            font-size: 12px;
            width: auto;
        }

        /* TABS */
        .tabs-wrap {
            padding: 12px 16px 0;
            background: var(--bg);
            position: sticky;
            top: 56px;
            z-index: 100;
            border-bottom: 1px solid var(--line);
        }

        .tabs {
            display: flex;
            gap: 6px;
        }

        .tab {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 12px 12px 0 0;
            border: 1px solid transparent;
            border-bottom: none;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
            color: var(--ink2);
            background: transparent;
            transition: all .2s;
            position: relative;
            bottom: -1px;
        }

        .tab.active {
            background: var(--bg2);
            border-color: var(--line);
            color: var(--ink);
        }

        .tab-badge {
            background: var(--p);
            color: #fff;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 800;
        }

        .tab-badge.amber {
            background: #F59E0B;
        }

        /* SECTIONS */
        .section {
            display: none;
            padding: 14px 16px 80px;
        }

        .section.active {
            display: block;
        }

        /* STATUS BAR */
        .status-bar {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-bottom: 16px;
        }

        .sbar-item {
            background: var(--bg2);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px;
            text-align: center;
        }

        .sbar-num {
            font-family: 'Fraunces', serif;
            font-size: 22px;
            font-weight: 900;
            line-height: 1;
        }

        .sbar-lbl {
            font-size: 10px;
            font-weight: 700;
            margin-top: 3px;
            color: var(--ink2);
        }

        .sbar-item.s-pending .sbar-num {
            color: #F59E0B;
        }

        .sbar-item.s-confirmed .sbar-num {
            color: #3B82F6;
        }

        .sbar-item.s-preparing .sbar-num {
            color: #8B5CF6;
        }

        /* ORDER CARDS */
        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 14px;
        }

        @media(max-width:640px) {
            .orders-grid {
                grid-template-columns: 1fr;
            }
        }

        .ocard {
            background: var(--bg2);
            border: 1px solid var(--line);
            border-radius: 18px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: cardIn .35s cubic-bezier(.22, 1, .36, 1) both;
        }

        @keyframes cardIn {
            from {
                opacity: 0;
                transform: translateY(12px) scale(.97);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .ocard.new-flash {
            animation: newFlash .6s ease 3, cardIn .35s both;
        }

        @keyframes newFlash {

            0%,
            100% {
                box-shadow: none;
            }

            50% {
                box-shadow: 0 0 0 8px rgba(255, 107, 53, .2);
            }
        }

        /* Card Header */
        .ocard-head {
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 2px solid var(--line);
            background: var(--bg3);
        }

        .ocard-num {
            font-family: 'Fraunces', serif;
            font-size: 16px;
            font-weight: 900;
            color: var(--ink);
        }

        .table-chip {
            background: rgba(255, 107, 53, .12);
            border: 1px solid rgba(255, 107, 53, .22);
            color: var(--p);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: auto;
            flex-shrink: 0;
        }

        .sd-pending {
            background: #F59E0B;
            animation: pulse 1.5s infinite;
        }

        .sd-confirmed {
            background: #3B82F6;
        }

        .sd-preparing {
            background: #8B5CF6;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .4
            }
        }

        .ocard-elapsed {
            font-size: 11px;
            font-weight: 700;
            color: var(--ink2);
        }

        .ocard-elapsed.warn {
            color: #EF4444;
            font-weight: 900;
        }

        /* Items */
        .ocard-items {
            padding: 10px 14px;
            flex: 1;
        }

        .oitem {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 9px 0;
            gap: 10px;
            border-bottom: 1px solid var(--line);
        }

        .oitem:last-child {
            border-bottom: none;
        }

        .oitem-main {
            flex: 1;
            min-width: 0;
        }

        .oitem-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .oitem-qty {
            background: var(--p);
            color: #fff;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 900;
            flex-shrink: 0;
            margin-top: 2px;
            font-family: 'Fraunces', serif;
        }

        /* Options chips */
        .oitem-opts {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-bottom: 4px;
        }

        .oitem-opt-chip {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: rgba(139, 92, 246, .12);
            border: 1px solid rgba(139, 92, 246, .22);
            color: #A78BFA;
            padding: 2px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
        }

        .oitem-opt-chip::before {
            content: '◆';
            font-size: 8px;
        }

        /* Notes */
        .oitem-note {
            display: flex;
            align-items: center;
            gap: 5px;
            background: rgba(245, 158, 11, .08);
            border: 1px solid rgba(245, 158, 11, .18);
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 11px;
            color: #F59E0B;
            font-weight: 600;
            margin-top: 4px;
        }

        .oitem-note::before {
            content: '📝';
            font-size: 11px;
        }

        /* Customer notes */
        .ocard-note {
            padding: 9px 14px;
            font-size: 12px;
            color: var(--ink2);
            border-top: 1px solid var(--line);
            background: var(--bg3);
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }

        .ocard-note svg {
            width: 12px;
            height: 12px;
            flex-shrink: 0;
            margin-top: 1px;
            color: #F59E0B;
        }

        /* Actions */
        .ocard-actions {
            padding: 10px 14px;
            display: flex;
            gap: 7px;
            border-top: 1px solid var(--line);
            background: var(--bg3);
        }

        .act-btn {
            flex: 1;
            padding: 10px 6px;
            border-radius: 11px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
            transition: all .18s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            border: 1.5px solid transparent;
        }

        .act-btn:active {
            transform: scale(.96);
        }

        .act-confirm {
            background: rgba(59, 130, 246, .1);
            color: #3B82F6;
            border-color: rgba(59, 130, 246, .2);
        }

        .act-confirm:hover {
            background: #3B82F6;
            color: #fff;
            border-color: transparent;
        }

        .act-prepare {
            background: rgba(139, 92, 246, .1);
            color: #8B5CF6;
            border-color: rgba(139, 92, 246, .2);
        }

        .act-prepare:hover {
            background: #8B5CF6;
            color: #fff;
            border-color: transparent;
        }

        .act-ready {
            background: rgba(34, 197, 94, .1);
            color: #22C55E;
            border-color: rgba(34, 197, 94, .2);
        }

        .act-ready:hover {
            background: #22C55E;
            color: #fff;
            border-color: transparent;
        }

        .act-cancel {
            background: rgba(239, 68, 68, .08);
            color: #EF4444;
            border-color: rgba(239, 68, 68, .15);
        }

        .act-cancel:hover {
            background: #EF4444;
            color: #fff;
            border-color: transparent;
        }

        .act-btn svg {
            width: 13px;
            height: 13px;
        }

        /* EMPTY */
        .empty {
            text-align: center;
            padding: 64px 20px;
            color: var(--ink2);
        }

        .empty-icon {
            font-size: 52px;
            margin-bottom: 12px;
            display: block;
        }

        .empty-text {
            font-size: 14px;
            font-weight: 600;
        }

        /* LIVE BAR */
        .live-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            font-size: 11px;
            color: var(--ink2);
            font-weight: 600;
            margin-bottom: 14px;
        }

        .live-dot {
            width: 6px;
            height: 6px;
            background: #22C55E;
            border-radius: 50%;
            animation: blink 1.5s infinite;
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .2
            }
        }

        /* SOLD OUT */
        .dishes-list {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .dcard {
            background: var(--bg2);
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dcard.sold {
            border-color: rgba(245, 158, 11, .3);
            background: rgba(245, 158, 11, .04);
        }

        .dcard-name {
            flex: 1;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
        }

        .dcard.sold .dcard-name {
            color: #F59E0B;
        }

        .sold-badge {
            font-size: 10px;
            font-weight: 800;
            color: #F59E0B;
            background: rgba(245, 158, 11, .1);
            border: 1px solid rgba(245, 158, 11, .2);
            padding: 2px 8px;
            border-radius: 10px;
        }

        .sold-toggle {
            padding: 7px 14px;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
            transition: all .2s;
            border: 1.5px solid;
            flex-shrink: 0;
        }

        .sold-toggle.is-sold {
            background: rgba(34, 197, 94, .1);
            color: #22C55E;
            border-color: rgba(34, 197, 94, .2);
        }

        .sold-toggle.is-sold:hover {
            background: #22C55E;
            color: #fff;
            border-color: transparent;
        }

        .sold-toggle.not-sold {
            background: rgba(245, 158, 11, .1);
            color: #F59E0B;
            border-color: rgba(245, 158, 11, .2);
        }

        .sold-toggle.not-sold:hover {
            background: #F59E0B;
            color: #fff;
            border-color: transparent;
        }

        .section-head {
            font-family: 'Fraunces', serif;
            font-size: 17px;
            font-weight: 900;
            margin-bottom: 4px;
        }

        .section-sub {
            font-size: 12px;
            color: var(--ink2);
            margin-bottom: 14px;
        }

        /* TOAST */
        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: var(--bg2);
            border: 1px solid var(--line);
            color: var(--ink);
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 700;
            z-index: 999;
            opacity: 0;
            transition: all .35s cubic-bezier(.34, 1.56, .64, 1);
            white-space: nowrap;
            backdrop-filter: blur(16px);
            pointer-events: none;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .sound-fab {
            position: fixed;
            bottom: 20px;
            left: 16px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--bg2);
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 300;
            font-size: 18px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, .2);
            transition: transform .2s;
        }

        .sound-fab:active {
            transform: scale(.9);
        }
    </style>
</head>

<body>

    <div class="topbar">
        <div class="topbar-stripe"></div>
        <div class="role-pill">👨‍🍳 مطبخ</div>
        <div class="rest-name"><?= htmlspecialchars($_SESSION['staff_rest_name']) ?></div>
        <div class="topbar-actions">
            <button class="icon-btn theme-toggle" onclick="toggleTheme()">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
                </svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="12" r="5" />
                    <line x1="12" y1="1" x2="12" y2="3" />
                    <line x1="12" y1="21" x2="12" y2="23" />
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
                    <line x1="1" y1="12" x2="3" y2="12" />
                    <line x1="21" y1="12" x2="23" y2="12" />
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
                </svg>
            </button>
            <a href="?logout=1" class="icon-btn logout-btn">خروج</a>
        </div>
    </div>

    <div class="tabs-wrap">
        <div class="tabs">
            <button class="tab active" onclick="switchTab('orders',this)">
                الطلبات
                <?php if (count($orders) > 0): ?>
                    <span class="tab-badge"><?= count($orders) ?></span>
                <?php endif; ?>
            </button>
            <button class="tab" onclick="switchTab('soldout',this)">
                نفذ اليوم
                <?php $sold_count = array_sum(array_column($dishes, 'sold_out'));
                if ($sold_count > 0): ?>
                    <span class="tab-badge amber"><?= $sold_count ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>

    <!-- ORDERS -->
    <div class="section active" id="section-orders">
        <div class="live-bar">
            <div class="live-dot"></div> يتجدد تلقائياً كل 8 ثوانٍ
        </div>

        <div class="status-bar">
            <div class="sbar-item s-pending">
                <div class="sbar-num" id="cnt-pending"><?= $counts['pending'] ?></div>
                <div class="sbar-lbl">معلق</div>
            </div>
            <div class="sbar-item s-confirmed">
                <div class="sbar-num" id="cnt-confirmed"><?= $counts['confirmed'] ?></div>
                <div class="sbar-lbl">مؤكد</div>
            </div>
            <div class="sbar-item s-preparing">
                <div class="sbar-num" id="cnt-preparing"><?= $counts['preparing'] ?></div>
                <div class="sbar-lbl">بالتحضير</div>
            </div>
        </div>

        <?php if (empty($orders)): ?>
            <div class="empty">
                <span class="empty-icon">✅</span>
                <div class="empty-text">ما في طلبات نشطة — استرح شوي!</div>
            </div>
        <?php else: ?>
            <div class="orders-grid" id="ordersGrid">
                <?php
                $next_map = [
                    'pending'   => [
                        ['confirmed', 'تأكيد',      'act-confirm', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>'],
                        ['cancelled', 'إلغاء',       'act-cancel',  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'],
                    ],
                    'confirmed' => [
                        ['preparing', 'بالتحضير',   'act-prepare', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'],
                        ['cancelled', 'إلغاء',       'act-cancel',  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'],
                    ],
                    'preparing' => [
                        ['ready',     'جاهز ✓',     'act-ready',   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>'],
                    ],
                ];
                $status_colors = ['pending' => 'sd-pending', 'confirmed' => 'sd-confirmed', 'preparing' => 'sd-preparing'];
                $status_labels = ['pending' => 'معلق', 'confirmed' => 'مؤكد', 'preparing' => 'بالتحضير'];
                foreach ($orders as $idx => $o):
                ?>
                    <div class="ocard" id="koc-<?= $o['id'] ?>" data-created="<?= $o['created_at'] ?>" style="animation-delay:<?= min($idx * .05, .3) ?>s">

                        <!-- Header -->
                        <div class="ocard-head">
                            <div class="ocard-num">#<?= $o['id'] ?></div>
                            <div class="table-chip">🪑 <?= htmlspecialchars($o['table_number']) ?></div>
                            <?php if ($o['customer_name']): ?>
                                <div style="font-size:11px;color:var(--ink2);font-weight:600;">👤 <?= htmlspecialchars($o['customer_name']) ?></div>
                            <?php endif; ?>
                            <div class="status-dot <?= $status_colors[$o['status']] ?>" style="margin-right:auto;" title="<?= $status_labels[$o['status']] ?>"></div>
                            <div class="ocard-elapsed" id="elapsed-<?= $o['id'] ?>"></div>
                        </div>

                        <!-- Items -->
                        <div class="ocard-items">
                            <?php foreach ($o['items'] as $item): ?>
                                <div class="oitem">
                                    <div class="oitem-main">
                                        <div class="oitem-name">
                                            <?php if (empty($item['dish_id']) || $item['dish_id'] === null): ?>
                                                <span style="font-size:9px;font-weight:800;background:rgba(255,107,53,.15);color:var(--p);border:1px solid rgba(255,107,53,.25);padding:1px 6px;border-radius:8px;margin-left:4px;">🎁 عرض</span>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($item['dish_name']) ?>
                                        </div>

                                        <?php
                                        // خيارات الطبق
                                        $opts = [];
                                        if (!empty($item['options'])) {
                                            $opts = json_decode($item['options'], true) ?? [];
                                        }
                                        if (!empty($opts)): ?>
                                            <div class="oitem-opts">
                                                <?php foreach ($opts as $opt): ?>
                                                    <span class="oitem-opt-chip"><?= htmlspecialchars($opt['name'] ?? '') ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($item['notes'])): ?>
                                            <div class="oitem-note"><?= htmlspecialchars($item['notes']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="oitem-qty">×<?= $item['quantity'] ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Customer notes -->
                        <?php if (!empty($o['customer_notes'])): ?>
                            <div class="ocard-note">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                                    <polyline points="14 2 14 8 20 8" />
                                </svg>
                                ملاحظة الزبون: <?= htmlspecialchars($o['customer_notes']) ?>
                            </div>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div class="ocard-actions">
                            <?php foreach ($next_map[$o['status']] ?? [] as [$ns, $nl, $nc, $ni]): ?>
                                <button class="act-btn <?= $nc ?>" onclick="updateStatus(<?= $o['id'] ?>,'<?= $ns ?>',this)">
                                    <?= $ni ?> <?= $nl ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- SOLD OUT -->
    <div class="section" id="section-soldout">
        <div class="section-head">حالة الأطباق</div>
        <div class="section-sub">اضغط "نفذ" لإيقاف طلبات الطبق مؤقتاً</div>
        <div class="dishes-list">
            <?php foreach ($dishes as $d): ?>
                <div class="dcard <?= $d['sold_out'] ? 'sold' : '' ?>" id="dcard-<?= $d['id'] ?>">
                    <div class="dcard-name"><?= htmlspecialchars($d['name']) ?></div>
                    <?php if ($d['sold_out']): ?><span class="sold-badge">نفذ</span><?php endif; ?>
                    <button class="sold-toggle <?= $d['sold_out'] ? 'is-sold' : 'not-sold' ?>"
                        onclick="toggleSoldOut(<?= $d['id'] ?>,this)">
                        <?= $d['sold_out'] ? 'إعادة توفير' : 'نفذ اليوم' ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <button class="sound-fab" onclick="testSound()" title="اختبار الصوت">🔔</button>
    <div class="toast" id="toast"></div>

    <script>
        const THEME_KEY = 'kitchen_theme';
        (function() {
            document.documentElement.setAttribute('data-theme', localStorage.getItem(THEME_KEY) || 'dark');
        })();

        function toggleTheme() {
            const n = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', n);
            localStorage.setItem(THEME_KEY, n);
        }

        function switchTab(id, btn) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('section-' + id).classList.add('active');
        }

        function showToast(msg) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 2800);
        }

        // Audio
        let audioCtx = null,
            userInteracted = false,
            notifGranted = false;
        ['click', 'touchstart', 'keydown'].forEach(ev =>
            document.addEventListener(ev, () => {
                userInteracted = true;
                if (!audioCtx) {
                    try {
                        audioCtx = new(window.AudioContext || window.webkitAudioContext)();
                    } catch (e) {}
                }
                if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
            }, {
                passive: true
            })
        );
        if ('Notification' in window) {
            if (Notification.permission === 'granted') notifGranted = true;
            else if (Notification.permission !== 'denied') {
                Notification.requestPermission().then(p => {
                    notifGranted = (p === 'granted');
                });
            }
        }

        function _beep() {
            if (!audioCtx) return;
            [
                [523, 0, .15],
                [659, .1, .15],
                [784, .2, .3]
            ].forEach(([f, s, e]) => {
                const o = audioCtx.createOscillator(),
                    g = audioCtx.createGain();
                o.connect(g);
                g.connect(audioCtx.destination);
                o.frequency.value = f;
                o.type = 'sine';
                g.gain.setValueAtTime(0.45, audioCtx.currentTime + s);
                g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + e);
                o.start(audioCtx.currentTime + s);
                o.stop(audioCtx.currentTime + e);
            });
        }

        function playSound() {
            if (!userInteracted) return;
            if (!audioCtx) {
                try {
                    audioCtx = new(window.AudioContext || window.webkitAudioContext)();
                } catch (e) {
                    return;
                }
            }
            if (audioCtx.state === 'suspended') audioCtx.resume().then(_beep);
            else _beep();
        }

        function testSound() {
            userInteracted = true;
            playSound();
            showToast('🔔 الصوت يعمل!');
        }

        function sendNotif(title, body) {
            if (!notifGranted) return;
            try {
                const n = new Notification(title, {
                    body,
                    tag: 'kitchen',
                    renotify: true
                });
                n.onclick = () => {
                    window.focus();
                    n.close();
                };
            } catch (e) {}
        }

        // Elapsed time
        function updateElapsed() {
            document.querySelectorAll('.ocard').forEach(card => {
                const created = card.getAttribute('data-created');
                if (!created) return;
                const elapsed = Math.floor((Date.now() - new Date(created).getTime()) / 60000);
                const el = card.querySelector('.ocard-elapsed');
                if (!el) return;
                el.textContent = elapsed < 1 ? 'الآن' : elapsed + ' د';
                el.classList.toggle('warn', elapsed >= 15);
            });
        }
        updateElapsed();
        setInterval(updateElapsed, 30000);

        // Status Update
        function updateStatus(orderId, status, btn) {
            btn.disabled = true;
            btn.style.opacity = '.5';
            fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `order_id=${orderId}&status=${status}&ajax=1`
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const msgs = {
                            'ready': '🍽️ الطلب #' + orderId + ' جاهز!',
                            'cancelled': '❌ تم إلغاء الطلب #' + orderId,
                            'confirmed': '✅ تم تأكيد الطلب #' + orderId,
                            'preparing': '⚡ الطلب #' + orderId + ' بالتحضير',
                        };
                        showToast(msgs[status] || '✅ تم تحديث الطلب');
                        if (status === 'ready') sendNotif('🍽️ طلب جاهز!', 'طلب #' + orderId + ' جاهز للتسليم');
                        isReloading = true;
                        setTimeout(() => location.reload(), 600);
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                });
        }

        // Sold Out
        function toggleSoldOut(dishId, btn) {
            btn.disabled = true;
            fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `dish_id=${dishId}&sold_out_action=1&ajax=1`
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const card = document.getElementById('dcard-' + dishId);
                        const wasSold = card.classList.contains('sold');
                        card.classList.toggle('sold');
                        const badge = card.querySelector('.sold-badge');
                        if (wasSold) {
                            if (badge) badge.remove();
                        } else {
                            const b = document.createElement('span');
                            b.className = 'sold-badge';
                            b.textContent = 'نفذ';
                            card.insertBefore(b, btn);
                        }
                        btn.className = 'sold-toggle ' + (wasSold ? 'not-sold' : 'is-sold');
                        btn.textContent = wasSold ? 'نفذ اليوم' : 'إعادة توفير';
                        showToast(wasSold ? '✅ الطبق متوفر' : '⚠️ الطبق نفذ');
                    }
                    btn.disabled = false;
                });
        }

        // Polling
        let lastId = <?= $last_id ?>;
        let isReloading = false;
        setInterval(() => {
            if (isReloading) return;
            fetch(`?ajax=poll&last_id=${lastId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.counts) {
                        ['pending', 'confirmed', 'preparing'].forEach(s => {
                            const el = document.getElementById('cnt-' + s);
                            if (el) el.textContent = data.counts[s] || 0;
                        });
                    }
                    if (data.new_orders && data.new_orders.length > 0) {
                        const newest = Math.max(...data.new_orders.map(o => parseInt(o.id)));
                        if (newest > lastId) {
                            lastId = newest;
                            playSound();
                            sendNotif('🛎️ طلب جديد!', 'وصل طلب جديد للمطبخ');
                            showToast('🛎️ طلب جديد وصل!');
                            isReloading = true;
                            setTimeout(() => location.reload(), 800);
                        }
                    }
                })
                .catch(() => {});
        }, 8000);
    </script>
</body>

</html>