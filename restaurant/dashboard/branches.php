<?php
/**
 * branches.php — إدارة الفروع (Phase 7)
 *
 * العمليات:
 *   - عرض كل الفروع مع إحصائياتها
 *   - إضافة فرع جديد (POST action=add)
 *   - تعديل فرع (POST action=edit)
 *   - تفعيل/تعطيل فرع (POST action=toggle)
 *   - حذف فرع (POST action=delete) — يمنع حذف الفرع الوحيد
 *   - تبديل الفرع النشط بالـ session (POST action=switch)
 */
require_once __DIR__ . '/../../bootstrap.php';
// plan_guard.php بيتحمّل تلقائياً من sidebar.php

if (!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}

$rid  = $_SESSION['restaurant_id'];
$slug_stmt = $pdo->prepare("SELECT slug FROM restaurants WHERE id = ?");
$slug_stmt->execute([$rid]);
$slug = $slug_stmt->fetchColumn();

// ===== POST HANDLER =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- تبديل الفرع النشط ---
    if ($action === 'switch') {
        $bid = intval($_POST['branch_id']);
        $chk = $pdo->prepare("SELECT id FROM branches WHERE id=? AND restaurant_id=? AND is_active=1");
        $chk->execute([$bid, $rid]);
        if ($chk->fetch()) {
            $_SESSION['active_branch_id'] = $bid;
        }
        header('Location: branches.php');
        exit;
    }

    // --- إضافة فرع ---
    if ($action === 'add') {
        $name    = trim($_POST['name'] ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $welcome = trim($_POST['welcome_message'] ?? '');
        $welcome_en = trim($_POST['welcome_message_en'] ?? '');

        // توليد slug تلقائي — من الإنجليزي أولاً، وإلا رقم عشوائي
        $raw_slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name_en)));
        $raw_slug = trim($raw_slug, '-');
        // لو الاسم الإنجليزي فارغ أو عربي بحت → استخدم branch + timestamp
        if(strlen($raw_slug) < 2) {
            $raw_slug = 'branch-' . date('His');
        }

        // تأكد الـ slug فريد لهاد المطعم
        $base = $raw_slug;
        $i = 1;
        while (true) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE restaurant_id=? AND slug=?");
            $chk->execute([$rid, $raw_slug]);
            if (!$chk->fetchColumn()) break;
            $raw_slug = $base . '-' . $i++;
        }

        if ($name) {
            $pdo->prepare("INSERT INTO branches (restaurant_id, name, name_en, slug, address, phone, welcome_message, welcome_message_en, is_active, created_at)
                VALUES (?,?,?,?,?,?,?,?,1,NOW())")
                ->execute([$rid, $name, $name_en, $raw_slug, $address, $phone, $welcome, $welcome_en]);
        }
        header('Location: branches.php?ok=added');
        exit;
    }

    // --- تعديل فرع ---
    if ($action === 'edit') {
        $bid     = intval($_POST['branch_id']);
        $name    = trim($_POST['name'] ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $welcome = trim($_POST['welcome_message'] ?? '');
        $welcome_en = trim($_POST['welcome_message_en'] ?? '');

        if ($name && $bid) {
            $pdo->prepare("UPDATE branches SET name=?, name_en=?, address=?, phone=?, welcome_message=?, welcome_message_en=? WHERE id=? AND restaurant_id=?")
                ->execute([$name, $name_en, $address, $phone, $welcome, $welcome_en, $bid, $rid]);
        }
        header('Location: branches.php?ok=edited');
        exit;
    }

    // --- تفعيل/تعطيل ---
    if ($action === 'toggle') {
        $bid = intval($_POST['branch_id']);
        // لا تسمح بتعطيل الفرع الوحيد النشط
        $active_count = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE restaurant_id=? AND is_active=1");
        $active_count->execute([$rid]);
        $cnt = $active_count->fetchColumn();

        $cur = $pdo->prepare("SELECT is_active FROM branches WHERE id=? AND restaurant_id=?");
        $cur->execute([$bid, $rid]);
        $cur_active = $cur->fetchColumn();

        if ($cnt <= 1 && $cur_active) {
            header('Location: branches.php?err=last_branch');
            exit;
        }
        $pdo->prepare("UPDATE branches SET is_active = NOT is_active WHERE id=? AND restaurant_id=?")
            ->execute([$bid, $rid]);

        // لو عطّلنا الفرع النشط بالـ session، غيّره لأول فرع نشط
        if ($_SESSION['active_branch_id'] == $bid && $cur_active) {
            $first = $pdo->prepare("SELECT id FROM branches WHERE restaurant_id=? AND is_active=1 LIMIT 1");
            $first->execute([$rid]);
            $_SESSION['active_branch_id'] = $first->fetchColumn();
        }
        header('Location: branches.php?ok=toggled');
        exit;
    }

    // --- حذف ---
    if ($action === 'delete') {
        $bid = intval($_POST['branch_id']);
        $total = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE restaurant_id=?");
        $total->execute([$rid]);
        if ($total->fetchColumn() <= 1) {
            header('Location: branches.php?err=last_branch');
            exit;
        }
        $pdo->prepare("DELETE FROM branches WHERE id=? AND restaurant_id=?")->execute([$bid, $rid]);
        if ($_SESSION['active_branch_id'] == $bid) {
            $first = $pdo->prepare("SELECT id FROM branches WHERE restaurant_id=? LIMIT 1");
            $first->execute([$rid]);
            $_SESSION['active_branch_id'] = $first->fetchColumn();
        }
        header('Location: branches.php?ok=deleted');
        exit;
    }
}

// ===== جلب البيانات =====
// orders.branch_id موجود بعد الترحيل — بنستخدمه مباشرة
$branches_stmt = $pdo->prepare("
    SELECT b.*,
        (SELECT COUNT(*) FROM orders WHERE branch_id = b.id)                                          AS total_orders,
        (SELECT COUNT(*) FROM orders WHERE branch_id = b.id AND DATE(created_at) = CURDATE())         AS today_orders,
        (SELECT COUNT(*) FROM orders WHERE branch_id = b.id AND status = 'pending')                   AS pending_orders,
        (SELECT ROUND(AVG(total_price),0) FROM orders WHERE branch_id = b.id AND DATE(created_at) = CURDATE()) AS avg_today
    FROM branches b
    WHERE b.restaurant_id = ?
    ORDER BY b.id ASC
");
$branches_stmt->execute([$rid]);
$branches = $branches_stmt->fetchAll();

$active_branch_id = $_SESSION['active_branch_id'] ?? ($branches[0]['id'] ?? 0);

// رسائل
$ok_msgs = [
    'added'   => '✅ تم إضافة الفرع بنجاح',
    'edited'  => '✅ تم حفظ التعديلات',
    'toggled' => '✅ تم تغيير حالة الفرع',
    'deleted' => '✅ تم حذف الفرع',
];
$err_msgs = [
    'last_branch' => '⚠️ لا يمكن تعطيل أو حذف الفرع الوحيد',
];
$flash_ok  = isset($_GET['ok'])  ? ($ok_msgs[$_GET['ok']]   ?? '') : '';
$flash_err = isset($_GET['err']) ? ($err_msgs[$_GET['err']] ?? '') : '';

require_once __DIR__ . '/sidebar.php';
?>
<style>
/* ===== BRANCHES PAGE ===== */
.page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; gap: 12px; flex-wrap: wrap;
}
.page-title-wrap { display: flex; flex-direction: column; gap: 3px; }
.page-title {
    font-family: 'Fraunces', serif;
    font-size: 24px; font-weight: 900; color: var(--ink); letter-spacing: -0.5px;
}
.page-sub { font-size: 13px; color: var(--ink2); font-weight: 500; }

/* Flash messages */
.flash {
    display: flex; align-items: center; gap: 10px;
    padding: 13px 18px; border-radius: 14px;
    font-size: 13px; font-weight: 700; margin-bottom: 18px;
    animation: cardIn .4s both;
}
.flash.ok  { background: rgba(34,197,94,.1);  color: #16a34a; border: 1px solid rgba(34,197,94,.2); }
.flash.err { background: rgba(239,68,68,.1);  color: #dc2626; border: 1px solid rgba(239,68,68,.2); }

/* Stats row */
.branches-stats {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px; margin-bottom: 24px;
}
.bstat {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 14px; padding: 16px 18px;
    animation: cardIn .4s both;
}
.bstat-num {
    font-family: 'Fraunces', serif;
    font-size: 26px; font-weight: 900; color: var(--ink); line-height: 1;
}
.bstat-lbl { font-size: 11px; color: var(--ink2); font-weight: 600; margin-top: 4px; }

/* Branch cards */
.branches-grid {
    display: flex; flex-direction: column; gap: 12px; margin-bottom: 28px;
}

.bcard {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; overflow: hidden;
    animation: cardIn .4s cubic-bezier(.22,1,.36,1) both;
    transition: box-shadow .2s;
}
.bcard:hover { box-shadow: var(--shadow2); }

.bcard-head {
    display: flex; align-items: center; gap: 12px;
    padding: 16px 18px; border-bottom: 1px solid var(--line);
    flex-wrap: wrap;
}

.bcard-status-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.bcard-status-dot.active   { background: #22C55E; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }
.bcard-status-dot.inactive { background: var(--ink3); }

.bcard-name-wrap { flex: 1; min-width: 0; }
.bcard-name {
    font-family: 'Fraunces', serif;
    font-size: 16px; font-weight: 700; color: var(--ink);
}
.bcard-name-en { font-size: 12px; color: var(--ink2); font-weight: 500; margin-top: 1px; }

.bcard-badges { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }

.badge-active   { background: rgba(34,197,94,.1);  color: #16a34a; border: 1px solid rgba(34,197,94,.2); }
.badge-inactive { background: rgba(107,114,128,.1);color: #6b7280; border: 1px solid rgba(107,114,128,.2); }
.badge-current  { background: color-mix(in srgb, var(--p) 12%, transparent); color: var(--p); border: 1px solid color-mix(in srgb, var(--p) 25%, transparent); }
.badge {
    font-size: 10px; font-weight: 800; padding: 3px 9px;
    border-radius: 20px; white-space: nowrap;
}

.bcard-body {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    gap: 0; padding: 0;
}
@media (max-width: 500px) {
    .bcard-body { grid-template-columns: 1fr 1fr; }
}
.bcard-stat {
    padding: 14px 18px; border-left: 1px solid var(--line);
    display: flex; flex-direction: column; gap: 3px;
}
.bcard-stat:last-child { border-left: none; }
[dir='ltr'] .bcard-stat { border-left: none; border-right: 1px solid var(--line); }
[dir='ltr'] .bcard-stat:last-child { border-right: none; }

.bcard-stat-num {
    font-family: 'Fraunces', serif;
    font-size: 20px; font-weight: 900; color: var(--ink); line-height: 1;
}
.bcard-stat-lbl { font-size: 10px; color: var(--ink2); font-weight: 600; }
.bcard-stat-pending { color: #F59E0B; }

.bcard-info {
    padding: 12px 18px; border-top: 1px solid var(--line);
    display: flex; gap: 14px; flex-wrap: wrap; align-items: center;
}
.bcard-info-item {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px; color: var(--ink2); font-weight: 500;
}
.bcard-info-item svg { width: 12px; height: 12px; flex-shrink: 0; }

.bcard-footer {
    padding: 12px 18px; border-top: 1px solid var(--line);
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}

/* QR Link */
.qr-link {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 700; color: var(--ink2);
    background: var(--bg3); border: 1px solid var(--line);
    padding: 5px 10px; border-radius: 8px; text-decoration: none;
    transition: all .2s; flex-shrink: 0;
}
.qr-link:hover { border-color: var(--p); color: var(--p); }
.qr-link svg { width: 11px; height: 11px; }

/* URL chip */
.url-chip {
    font-size: 10px; font-weight: 600; color: var(--ink2);
    background: var(--bg3); border: 1px solid var(--line);
    padding: 4px 8px; border-radius: 6px;
    font-family: monospace; max-width: 200px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    flex: 1;
}

/* Buttons */
.btn-switch {
    background: var(--p); color: #fff; border: none;
    padding: 7px 14px; border-radius: 10px;
    font-size: 12px; font-weight: 800; cursor: pointer;
    font-family: 'Tajawal', sans-serif; transition: opacity .2s;
}
.btn-switch:hover { opacity: .85; }

.btn-switch.current-branch {
    background: rgba(34,197,94,.1); color: #16a34a;
    border: 1px solid rgba(34,197,94,.2); cursor: default;
}

.btn-edit {
    background: var(--bg3); border: 1px solid var(--line);
    color: var(--ink); padding: 7px 14px; border-radius: 10px;
    font-size: 12px; font-weight: 700; cursor: pointer;
    font-family: 'Tajawal', sans-serif; transition: all .2s;
}
.btn-edit:hover { border-color: var(--p); color: var(--p); }

.btn-toggle {
    background: transparent; border: 1px solid var(--line);
    color: var(--ink2); padding: 7px 12px; border-radius: 10px;
    font-size: 12px; font-weight: 700; cursor: pointer;
    font-family: 'Tajawal', sans-serif; transition: all .2s;
}
.btn-toggle:hover { border-color: #F59E0B; color: #F59E0B; }

.btn-delete {
    background: transparent; border: none;
    color: var(--ink3); padding: 7px 10px; border-radius: 10px;
    font-size: 12px; cursor: pointer; transition: all .2s;
}
.btn-delete:hover { color: #EF4444; background: rgba(239,68,68,.08); }
.btn-delete svg { width: 14px; height: 14px; }

/* Add Branch Card */
.add-branch-card {
    border: 2px dashed var(--line); border-radius: 18px;
    padding: 28px; text-align: center; cursor: pointer;
    transition: all .25s cubic-bezier(.34,1.2,.64,1);
    background: transparent;
}
.add-branch-card:hover {
    border-color: var(--p);
    background: color-mix(in srgb, var(--p) 4%, transparent);
    transform: translateY(-2px);
}
.add-branch-icon {
    width: 52px; height: 52px; border-radius: 16px;
    background: color-mix(in srgb, var(--p) 10%, transparent);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 12px; color: var(--p);
}
.add-branch-icon svg { width: 22px; height: 22px; }
.add-branch-title {
    font-family: 'Fraunces', serif;
    font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 5px;
}
.add-branch-sub { font-size: 12px; color: var(--ink2); font-weight: 500; }

/* Modal override */
.modal-section-title {
    font-size: 11px; font-weight: 800; color: var(--ink2);
    text-transform: uppercase; letter-spacing: 1px; margin: 16px 0 10px;
}
.modal-section-title:first-child { margin-top: 0; }
</style>

<div class="main">

    <!-- Flash messages -->
    <?php if($flash_ok):  ?><div class="flash ok"><?= $flash_ok ?></div><?php endif; ?>
    <?php if($flash_err): ?><div class="flash err"><?= $flash_err ?></div><?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-title-wrap">
            <div class="page-title">إدارة الفروع</div>
            <div class="page-sub"><?= count($branches) ?> فرع · كل فرع له منيو وQR مستقل</div>
        </div>
        <button class="btn btn-primary" onclick="openAddModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:15px;height:15px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            فرع جديد
        </button>
    </div>

    <!-- Stats -->
    <div class="branches-stats">
        <div class="bstat" style="animation-delay:.04s">
            <div class="bstat-num"><?= count($branches) ?></div>
            <div class="bstat-lbl">إجمالي الفروع</div>
        </div>
        <div class="bstat" style="animation-delay:.08s">
            <div class="bstat-num"><?= count(array_filter($branches, fn($b) => $b['is_active'])) ?></div>
            <div class="bstat-lbl">فروع نشطة</div>
        </div>
        <div class="bstat" style="animation-delay:.12s">
            <div class="bstat-num" style="color:var(--p)"><?= array_sum(array_column($branches, 'today_orders')) ?></div>
            <div class="bstat-lbl">طلبات اليوم</div>
        </div>
        <div class="bstat" style="animation-delay:.16s">
            <div class="bstat-num" style="color:#F59E0B"><?= array_sum(array_column($branches, 'pending_orders')) ?></div>
            <div class="bstat-lbl">طلبات معلقة</div>
        </div>
    </div>

    <!-- Branches List -->
    <div class="branches-grid">
        <?php foreach($branches as $i => $b): ?>
        <?php $is_current = ($b['id'] == $active_branch_id); ?>
        <div class="bcard" style="animation-delay:<?= $i*.06 ?>s">

            <!-- Head -->
            <div class="bcard-head">
                <div class="bcard-status-dot <?= $b['is_active'] ? 'active' : 'inactive' ?>"></div>
                <div class="bcard-name-wrap">
                    <div class="bcard-name"><?= htmlspecialchars($b['name']) ?></div>
                    <?php if($b['name_en']): ?>
                    <div class="bcard-name-en"><?= htmlspecialchars($b['name_en']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="bcard-badges">
                    <?php if($is_current): ?>
                    <span class="badge badge-current">✦ الحالي</span>
                    <?php endif; ?>
                    <span class="badge <?= $b['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                        <?= $b['is_active'] ? 'نشط' : 'معطّل' ?>
                    </span>
                </div>
            </div>

            <!-- Stats -->
            <div class="bcard-body">
                <div class="bcard-stat">
                    <div class="bcard-stat-num"><?= intval($b['today_orders']) ?></div>
                    <div class="bcard-stat-lbl">طلبات اليوم</div>
                </div>
                <div class="bcard-stat">
                    <div class="bcard-stat-num"><?= intval($b['total_orders']) ?></div>
                    <div class="bcard-stat-lbl">إجمالي الطلبات</div>
                </div>
                <div class="bcard-stat">
                    <div class="bcard-stat-num bcard-stat-pending"><?= intval($b['pending_orders']) ?></div>
                    <div class="bcard-stat-lbl">معلقة</div>
                </div>
            </div>

            <!-- Info -->
            <?php if($b['address'] || $b['phone']): ?>
            <div class="bcard-info">
                <?php if($b['address']): ?>
                <div class="bcard-info-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= htmlspecialchars($b['address']) ?>
                </div>
                <?php endif; ?>
                <?php if($b['phone']): ?>
                <div class="bcard-info-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.63A2 2 0 012 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 14.92z"/></svg>
                    <?= htmlspecialchars($b['phone']) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="bcard-footer">
                <!-- Switch branch -->
                <?php if($is_current): ?>
                <button class="btn-switch current-branch" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-left:4px"><polyline points="20 6 9 17 4 12"/></svg>
                    الفرع النشط
                </button>
                <?php else: ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="switch">
                    <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                    <button class="btn-switch" type="submit">تعيين كالنشط</button>
                </form>
                <?php endif; ?>

                <!-- Edit -->
                <button class="btn-edit" onclick="openEditModal(<?= htmlspecialchars(json_encode([
                    'id'              => $b['id'],
                    'name'            => $b['name'],
                    'name_en'         => $b['name_en'] ?? '',
                    'address'         => $b['address'] ?? '',
                    'phone'           => $b['phone'] ?? '',
                    'welcome_message' => $b['welcome_message'] ?? '',
                    'welcome_message_en' => $b['welcome_message_en'] ?? '',
                ]), ENT_QUOTES) ?>)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px;display:inline-block;vertical-align:middle;margin-left:4px"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    تعديل
                </button>

                <!-- URL -->
                <span class="url-chip" title="/menu/<?= $slug ?>/<?= $b['slug'] ?>">/menu/<?= $slug ?>/<?= $b['slug'] ?></span>

                <!-- QR link -->
                <a href="<?= BASE_URL ?>/menu/<?= $slug ?>/<?= $b['slug'] ?>" target="_blank" class="qr-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    معاينة
                </a>

                <!-- Toggle -->
                <form method="POST" style="display:inline" onsubmit="return confirm('<?= $b['is_active'] ? 'تعطيل هذا الفرع؟' : 'تفعيل هذا الفرع؟' ?>')">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                    <button class="btn-toggle" type="submit"><?= $b['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
                </form>

                <!-- Delete -->
                <?php if(count($branches) > 1): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذا الفرع نهائياً؟')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                    <button class="btn-delete" type="submit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Add new branch card -->
        <div class="add-branch-card" onclick="openAddModal()">
            <div class="add-branch-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </div>
            <div class="add-branch-title">إضافة فرع جديد</div>
            <div class="add-branch-sub">كل فرع يحصل على منيو وQR code مستقل</div>
        </div>
    </div>

</div>

<!-- ===== MODAL: إضافة فرع ===== -->
<div class="modal-bg" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h3>فرع جديد</h3>
            <button class="modal-close" onclick="closeModal('addModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-section-title">المعلومات الأساسية</div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>اسم الفرع (عربي) *</label>
                    <input type="text" name="name" required placeholder="الفرع الرئيسي">
                </div>
                <div class="form-group">
                    <label>اسم الفرع (إنجليزي)</label>
                    <input type="text" name="name_en" placeholder="Main Branch">
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>العنوان</label>
                    <input type="text" name="address" placeholder="دمشق، شارع...">
                </div>
                <div class="form-group">
                    <label>رقم الهاتف</label>
                    <input type="text" name="phone" placeholder="+963...">
                </div>
            </div>
            <div class="modal-section-title">رسالة الترحيب (اختياري)</div>
            <div class="form-group">
                <label>الرسالة (عربي)</label>
                <textarea name="welcome_message" rows="2" placeholder="أهلاً بك في فرعنا..."></textarea>
            </div>
            <div class="form-group">
                <label>الرسالة (إنجليزي)</label>
                <textarea name="welcome_message_en" rows="2" placeholder="Welcome to our branch..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary" style="flex:1">إضافة الفرع</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== MODAL: تعديل فرع ===== -->
<div class="modal-bg" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <h3>تعديل الفرع</h3>
            <button class="modal-close" onclick="closeModal('editModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="branch_id" id="editBranchId">
            <div class="modal-section-title">المعلومات الأساسية</div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>اسم الفرع (عربي) *</label>
                    <input type="text" name="name" id="editName" required>
                </div>
                <div class="form-group">
                    <label>اسم الفرع (إنجليزي)</label>
                    <input type="text" name="name_en" id="editNameEn">
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>العنوان</label>
                    <input type="text" name="address" id="editAddress">
                </div>
                <div class="form-group">
                    <label>رقم الهاتف</label>
                    <input type="text" name="phone" id="editPhone">
                </div>
            </div>
            <div class="modal-section-title">رسالة الترحيب</div>
            <div class="form-group">
                <label>الرسالة (عربي)</label>
                <textarea name="welcome_message" id="editWelcome" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label>الرسالة (إنجليزي)</label>
                <textarea name="welcome_message_en" id="editWelcomeEn" rows="2"></textarea>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary" style="flex:1">حفظ التعديلات</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addModal').classList.add('open');
}
function openEditModal(data) {
    document.getElementById('editBranchId').value    = data.id;
    document.getElementById('editName').value        = data.name;
    document.getElementById('editNameEn').value      = data.name_en;
    document.getElementById('editAddress').value     = data.address;
    document.getElementById('editPhone').value       = data.phone;
    document.getElementById('editWelcome').value     = data.welcome_message;
    document.getElementById('editWelcomeEn').value   = data.welcome_message_en;
    document.getElementById('editModal').classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
// إغلاق بالضغط خارج المودال
document.querySelectorAll('.modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) {
        if (e.target === bg) bg.classList.remove('open');
    });
});
// إغلاق بـ Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-bg.open').forEach(function(m) {
            m.classList.remove('open');
        });
    }
});
</script>