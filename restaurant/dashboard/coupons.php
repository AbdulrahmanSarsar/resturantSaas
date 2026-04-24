<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/csrf.php';
require_once 'plan_guard.php';
if(!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}
plan_required('premium');

$rid = $_SESSION['restaurant_id'];
$active_branch_id = $_SESSION['active_branch_id'] ?? null;
$success = ''; $error = '';

// تحقق إن branch_id column موجود (graceful fallback في حال ما تم الـ migration بعد)
$has_branch_col = false;
try {
    $col_check = $pdo->query("SHOW COLUMNS FROM coupons LIKE 'branch_id'");
    $has_branch_col = $col_check && $col_check->rowCount() > 0;
} catch(Exception $e) {}

// جلب الفروع النشطة
$active_branches = [];
$bstmt = $pdo->prepare("SELECT id, name FROM branches WHERE restaurant_id=? AND is_active=1 ORDER BY name");
$bstmt->execute([$rid]);
$active_branches = $bstmt->fetchAll();

// اسم الفرع النشط
$active_branch_name = null;
if ($active_branch_id) {
    foreach ($active_branches as $b) {
        if ($b['id'] == $active_branch_id) { $active_branch_name = $b['name']; break; }
    }
}

// تحقق إن الفرع تابع للمطعم (منع tampering)
$validate_branch = function($branch_id) use ($pdo, $rid) {
    if (!$branch_id) return null;
    $chk = $pdo->prepare("SELECT id FROM branches WHERE id=? AND restaurant_id=?");
    $chk->execute([$branch_id, $rid]);
    return $chk->fetch() ? (int)$branch_id : null;
};

// Add coupon
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_require();

    if($_POST['action'] === 'add') {
        $code           = strtoupper(trim($_POST['code']));
        $discount_type  = $_POST['discount_type'];
        $discount_value = floatval($_POST['discount_value']);
        $min_order      = floatval($_POST['min_order'] ?? 0);
        $max_uses       = intval($_POST['max_uses'] ?? 0);
        $expires_at     = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $coup_branch    = $has_branch_col ? $validate_branch(intval($_POST['branch_id'] ?? 0)) : null;

        if(!$code || !$discount_value) {
            $error = 'الرجاء إدخال الكود والخصم';
        } else {
            $check = $pdo->prepare("SELECT id FROM coupons WHERE restaurant_id=? AND code=?");
            $check->execute([$rid, $code]);
            if($check->fetch()) {
                $error = 'هذا الكود موجود مسبقاً';
            } else {
                if ($has_branch_col) {
                    $pdo->prepare("INSERT INTO coupons
                        (restaurant_id, branch_id, code, discount_type, discount_value, min_order, max_uses, expires_at, is_active)
                        VALUES (?,?,?,?,?,?,?,?,1)")
                        ->execute([$rid, $coup_branch, $code, $discount_type, $discount_value, $min_order, $max_uses ?: null, $expires_at]);
                } else {
                    $pdo->prepare("INSERT INTO coupons
                        (restaurant_id, code, discount_type, discount_value, min_order, max_uses, expires_at, is_active)
                        VALUES (?,?,?,?,?,?,?,1)")
                        ->execute([$rid, $code, $discount_type, $discount_value, $min_order, $max_uses ?: null, $expires_at]);
                }
                $success = 'تم إضافة الكوبون بنجاح';
            }
        }
    }

    if($_POST['action'] === 'toggle') {
        $cid = intval($_POST['coupon_id']);
        $pdo->prepare("UPDATE coupons SET is_active = !is_active WHERE id=? AND restaurant_id=?")->execute([$cid, $rid]);
    }

    if($_POST['action'] === 'delete') {
        $cid = intval($_POST['coupon_id']);
        $pdo->prepare("DELETE FROM coupons WHERE id=? AND restaurant_id=?")->execute([$cid, $rid]);
        $success = 'تم حذف الكوبون';
    }
}

// فلترة القائمة حسب الفرع النشط:
//  - active_branch_id = NULL → كل كوبونات المطعم
//  - active_branch_id محدد → كوبونات الفرع + الكوبونات العامة (branch_id IS NULL)
if ($has_branch_col && $active_branch_id) {
    $coupons = $pdo->prepare("
        SELECT c.*, b.name AS branch_name
        FROM coupons c
        LEFT JOIN branches b ON b.id = c.branch_id
        WHERE c.restaurant_id = ? AND (c.branch_id IS NULL OR c.branch_id = ?)
        ORDER BY c.created_at DESC
    ");
    $coupons->execute([$rid, $active_branch_id]);
} elseif ($has_branch_col) {
    $coupons = $pdo->prepare("
        SELECT c.*, b.name AS branch_name
        FROM coupons c
        LEFT JOIN branches b ON b.id = c.branch_id
        WHERE c.restaurant_id = ?
        ORDER BY c.created_at DESC
    ");
    $coupons->execute([$rid]);
} else {
    $coupons = $pdo->prepare("SELECT * FROM coupons WHERE restaurant_id=? ORDER BY created_at DESC");
    $coupons->execute([$rid]);
}
$coupons = $coupons->fetchAll();

// Stats
$total_coupons   = count($coupons);
$active_coupons  = array_sum(array_column($coupons, 'is_active'));
$total_uses      = array_sum(array_column($coupons, 'used_count'));

require_once 'sidebar.php';
?>
<style>
/* ===== STAT PILLS ===== */
.coup-pills {
    display: grid; grid-template-columns: repeat(3,1fr);
    gap: 12px; margin-bottom: 20px;
}
@media (max-width: 500px) { .coup-pills { grid-template-columns: 1fr; } }

.coup-pill {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 16px; padding: 16px 18px;
    display: flex; align-items: center; gap: 13px;
    transition: background 0.3s, border-color 0.3s;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.coup-pill:nth-child(1){animation-delay:.04s}
.coup-pill:nth-child(2){animation-delay:.08s}
.coup-pill:nth-child(3){animation-delay:.12s}

.coup-pill-icon {
    width: 40px; height: 40px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.coup-pill-icon svg { width: 18px; height: 18px; }
.coup-pill-num {
    font-family: 'Fraunces', serif;
    font-size: 24px; font-weight: 900; color: var(--ink);
    line-height: 1; transition: color 0.3s;
}
.coup-pill-lbl { font-size: 11px; color: var(--ink2); font-weight: 600; margin-top: 2px; }

/* ===== ADD FORM CARD ===== */
.add-card {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 20px; margin-bottom: 20px;
    overflow: hidden;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) .15s both;
    transition: background 0.3s, border-color 0.3s;
}
.add-card-head {
    padding: 13px 20px;
    border-bottom: 1px solid var(--line);
    display: flex; align-items: center; justify-content: space-between;
    cursor: pointer; user-select: none;
    transition: border-color 0.3s;
}
.add-card-head-left {
    display: flex; align-items: center; gap: 9px;
}
.add-head-icon {
    width: 30px; height: 30px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.add-head-icon svg { width: 14px; height: 14px; }
.add-head-title {
    font-family: 'Fraunces', serif;
    font-size: 14px; font-weight: 700; color: var(--ink);
    transition: color 0.3s;
}
.add-chevron {
    width: 18px; height: 18px; color: var(--ink2);
    transition: transform 0.3s;
}
.add-card.open .add-chevron { transform: rotate(180deg); }

.add-card-body {
    padding: 18px 20px;
    display: none;
}
.add-card.open .add-card-body { display: block; }

.add-grid {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    gap: 14px; margin-bottom: 14px;
}
@media (max-width: 700px) { .add-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 450px) { .add-grid { grid-template-columns: 1fr; } }

.add-grid .full { grid-column: 1 / -1; }

.code-input-wrapper { position: relative; }
.code-gen-btn {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    padding: 5px 10px; background: var(--bg3); border: 1px solid var(--line);
    border-radius: 8px; font-size: 11px; font-weight: 700; cursor: pointer;
    color: var(--p); font-family: 'Tajawal', sans-serif;
    transition: background 0.2s;
}
.code-gen-btn:hover { background: color-mix(in srgb, var(--p) 10%, transparent); }

/* ===== COUPONS LIST ===== */
.coupons-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 14px;
}

.coupon-card {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; overflow: hidden;
    transition: transform 0.2s, background 0.3s, border-color 0.3s;
    animation: cardIn 0.35s cubic-bezier(0.22,1,0.36,1) both;
    position: relative;
}
.coupon-card:hover { transform: translateY(-3px); }
.coupon-card.inactive { opacity: 0.55; }

/* Ticket notches */
.coupon-card::before,
.coupon-card::after {
    content: '';
    position: absolute; top: 50%; width: 14px; height: 14px;
    border-radius: 50%; background: var(--bg);
    transform: translateY(-50%); z-index: 2;
    transition: background 0.3s;
}
.coupon-card::before { right: -7px; }
.coupon-card::after  { left: -7px; }

/* Top colored strip */
.coup-strip {
    height: 5px; background: var(--p);
    box-shadow: 0 2px 8px color-mix(in srgb, var(--p) 40%, transparent);
}

.coup-body { padding: 16px 18px; }

/* Code pill */
.coup-code-row {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 10px;
}
.coup-code {
    font-family: 'Fraunces', serif; font-size: 18px; font-weight: 900;
    color: var(--p); letter-spacing: 2px;
    background: color-mix(in srgb, var(--p) 10%, transparent);
    padding: 5px 13px; border-radius: 9px;
    border: 1px dashed color-mix(in srgb, var(--p) 30%, transparent);
    cursor: pointer; transition: background 0.2s;
}
.coup-code:hover { background: color-mix(in srgb, var(--p) 18%, transparent); }
.coup-copy-icon {
    width: 16px; height: 16px; color: var(--ink2);
    opacity: 0; transition: opacity 0.2s;
}
.coup-code:hover .coup-copy-icon { opacity: 1; }

.coup-status-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.coup-status-dot.active   { background: #22C55E; box-shadow: 0 0 6px #22C55E; animation: pulse 1.8s infinite; }
.coup-status-dot.inactive { background: var(--ink3); }

/* Discount badge */
.coup-discount {
    font-family: 'Fraunces', serif; font-size: 28px; font-weight: 900;
    color: var(--ink); margin-bottom: 6px; line-height: 1;
    transition: color 0.3s;
}
.coup-discount span { font-size: 14px; font-weight: 600; color: var(--ink2); }

/* Details chips */
.coup-details {
    display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;
}
.coup-chip {
    font-size: 11px; font-weight: 600; color: var(--ink2);
    background: var(--bg3); border: 1px solid var(--line);
    padding: 4px 10px; border-radius: 20px;
    display: flex; align-items: center; gap: 4px;
    transition: background 0.3s, border-color 0.3s;
}
.coup-chip svg { width: 11px; height: 11px; }
.coup-chip.expired { color: #EF4444; border-color: rgba(239,68,68,0.25); background: rgba(239,68,68,0.08); }

/* Divider */
.coup-divider {
    height: 1px; background: var(--line);
    margin: 0 -18px 12px; transition: background 0.3s;
    /* Dashed style */
    background-image: repeating-linear-gradient(90deg, var(--line) 0, var(--line) 6px, transparent 6px, transparent 12px);
    background-color: transparent;
}

/* Actions */
.coup-actions { display: flex; gap: 8px; }
.coup-toggle-btn {
    flex: 1; padding: 9px; border-radius: 10px;
    border: 1.5px solid var(--line); font-size: 12px; font-weight: 700;
    cursor: pointer; font-family: 'Tajawal', sans-serif;
    background: var(--bg3); color: var(--ink2);
    transition: all 0.2s;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.coup-toggle-btn svg { width: 12px; height: 12px; }
.coup-toggle-btn.is-active {
    background: rgba(239,68,68,0.08); border-color: rgba(239,68,68,0.25);
    color: #EF4444;
}
.coup-toggle-btn:not(.is-active) {
    background: rgba(34,197,94,0.08); border-color: rgba(34,197,94,0.25);
    color: #22C55E;
}

.coup-delete-btn {
    width: 36px; height: 36px; border-radius: 10px;
    border: 1.5px solid rgba(239,68,68,0.2);
    background: rgba(239,68,68,0.06);
    color: #EF4444; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s;
}
.coup-delete-btn svg { width: 14px; height: 14px; }
.coup-delete-btn:hover { background: #EF4444; color: #fff; border-color: #EF4444; }

/* ===== EMPTY ===== */
.coup-empty {
    text-align: center; padding: 70px 20px;
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; color: var(--ink2);
    transition: background 0.3s, border-color 0.3s;
}
.coup-empty-icon {
    width: 72px; height: 72px; border-radius: 22px;
    background: var(--bg3); border: 1px solid var(--line);
    display: flex; align-items: center; justify-content: center;
    font-size: 34px; margin: 0 auto 16px;
}
.coup-empty p { font-size: 14px; font-weight: 600; }

/* ===== SECTION HEAD ===== */
.section-head {
    display: flex; align-items: center; gap: 8px; margin-bottom: 14px;
}
.section-head h3 {
    font-family: 'Fraunces', serif;
    font-size: 15px; font-weight: 700; color: var(--ink); transition: color 0.3s;
}
.section-head::after { content:''; flex:1; height:1px; background: var(--line); }
</style>

<div class="main">

    <div style="margin-bottom:20px;">
        <div class="page-title">
            الكوبونات
            <?php if($active_branch_name): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p);padding:4px 11px;border-radius:20px;font-size:12px;font-weight:800;margin-right:8px;vertical-align:middle;">
                📍 <?= htmlspecialchars($active_branch_name) ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="page-subtitle">
            <?= $active_branch_name
                ? 'كوبونات فرع ' . htmlspecialchars($active_branch_name) . ' + الكوبونات العامة'
                : 'أكواد خصم لزبائنك' ?>
        </div>
    </div>

    <?php if(!$has_branch_col): ?>
    <div class="alert alert-error" style="margin-bottom:16px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div>
            <strong>Migration مطلوب:</strong> لتفعيل الكوبونات per branch، نفّذ:
            <code style="background:var(--bg3);padding:2px 6px;border-radius:4px;">ALTER TABLE coupons ADD COLUMN branch_id INT DEFAULT NULL AFTER restaurant_id;</code>
        </div>
    </div>
    <?php endif; ?>

    <?php if($success): ?>
        <div class="alert alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $success ?>
        </div>
    <?php endif; ?>
    <?php if($error): ?>
        <div class="alert alert-error">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= $error ?>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="coup-pills">
        <div class="coup-pill">
            <div class="coup-pill-icon" style="background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            </div>
            <div>
                <div class="coup-pill-num"><?= $total_coupons ?></div>
                <div class="coup-pill-lbl">إجمالي الكوبونات</div>
            </div>
        </div>
        <div class="coup-pill">
            <div class="coup-pill-icon" style="background:rgba(34,197,94,0.12);color:#22C55E">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <div class="coup-pill-num"><?= $active_coupons ?></div>
                <div class="coup-pill-lbl">كوبونات نشطة</div>
            </div>
        </div>
        <div class="coup-pill">
            <div class="coup-pill-icon" style="background:rgba(245,158,11,0.12);color:#F59E0B">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div>
                <div class="coup-pill-num"><?= $total_uses ?></div>
                <div class="coup-pill-lbl">مجموع الاستخدامات</div>
            </div>
        </div>
    </div>

    <!-- Add Form -->
    <div class="add-card" id="addCard">
        <div class="add-card-head" onclick="toggleAddCard()">
            <div class="add-card-head-left">
                <div class="add-head-icon" style="background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <span class="add-head-title">إضافة كوبون جديد</span>
            </div>
            <svg class="add-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="add-card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="add-grid">
                    <div class="form-group">
                        <label>كود الخصم *</label>
                        <div class="code-input-wrapper">
                            <input type="text" name="code" id="codeInput"
                                   placeholder="مثال: SAVE20"
                                   style="text-transform:uppercase;padding-left:70px"
                                   required>
                            <button type="button" class="code-gen-btn" onclick="generateCode()">توليد</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>نوع الخصم *</label>
                        <select name="discount_type" id="discountType" onchange="updatePlaceholder()" class="filter-select" style="width:100%">
                            <option value="percent">نسبة مئوية %</option>
                            <option value="fixed">مبلغ ثابت $</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>قيمة الخصم *</label>
                        <input type="number" name="discount_value" id="discountValue"
                               placeholder="مثال: 20" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>الحد الأدنى للطلب</label>
                        <input type="number" name="min_order" placeholder="0 = بدون حد" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label>عدد مرات الاستخدام</label>
                        <input type="number" name="max_uses" placeholder="0 = غير محدود" min="0">
                    </div>
                    <div class="form-group">
                        <label>تاريخ الانتهاء</label>
                        <input type="date" name="expires_at">
                    </div>
                    <?php if($has_branch_col): ?>
                    <div class="form-group full">
                        <label>الفرع (اختياري)</label>
                        <select name="branch_id" class="filter-select" style="width:100%">
                            <option value="">🌐 كل الفروع (كوبون عام)</option>
                            <?php foreach($active_branches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= ($active_branch_id == $b['id']) ? 'selected' : '' ?>>
                                📍 <?= htmlspecialchars($b['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="display:block;margin-top:5px;font-size:11px;color:var(--ink2);">
                            اتركه على "كل الفروع" ليعمل الكوبون في أي فرع
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:14px;height:14px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    إضافة الكوبون
                </button>
            </form>
        </div>
    </div>

    <!-- Coupons List -->
    <div class="section-head"><h3>كل الكوبونات</h3></div>

    <?php if(empty($coupons)): ?>
    <div class="coup-empty">
        <div class="coup-empty-icon">🎟️</div>
        <p>ما في كوبونات بعد — أضف أول كوبون خصم لزبائنك!</p>
    </div>
    <?php else: ?>
    <div class="coupons-grid">
        <?php foreach($coupons as $idx => $c):
            $is_active  = $c['is_active'];
            $is_expired = $c['expires_at'] && strtotime($c['expires_at']) < time();
            $maxed_out  = $c['max_uses'] && $c['used_count'] >= $c['max_uses'];
            $effective  = $is_active && !$is_expired && !$maxed_out;
        ?>
        <div class="coupon-card <?= !$effective ? 'inactive' : '' ?>" style="animation-delay:<?= min($idx*0.05,0.3) ?>s">
            <div class="coup-strip"></div>
            <div class="coup-body">

                <!-- Code + status -->
                <div class="coup-code-row">
                    <span class="coup-code" onclick="copyCouponCode('<?= $c['code'] ?>')"
                          title="انقر للنسخ">
                        <?= htmlspecialchars($c['code']) ?>
                    </span>
                    <span class="coup-status-dot <?= $effective ? 'active' : 'inactive' ?>"></span>
                </div>

                <!-- Discount amount -->
                <div class="coup-discount">
                    <?php if($c['discount_type'] === 'percent'): ?>
                        <?= $c['discount_value'] ?>%
                        <span>خصم</span>
                    <?php else: ?>
                        <?= $c['discount_value'] ?> $
                        <span>خصم</span>
                    <?php endif; ?>
                </div>

                <!-- Detail chips -->
                <div class="coup-details">
                    <?php if($has_branch_col): ?>
                        <?php if(!empty($c['branch_name'])): ?>
                        <span class="coup-chip" style="color:var(--p);border-color:color-mix(in srgb,var(--p) 30%,transparent);background:color-mix(in srgb,var(--p) 8%,transparent);">
                            📍 <?= htmlspecialchars($c['branch_name']) ?>
                        </span>
                        <?php else: ?>
                        <span class="coup-chip" style="color:#22C55E;border-color:rgba(34,197,94,0.25);background:rgba(34,197,94,0.08);">
                            🌐 كل الفروع
                        </span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if($c['min_order'] > 0): ?>
                    <span class="coup-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
                        حد أدنى <?= $c['min_order'] ?> $
                    </span>
                    <?php endif; ?>

                    <span class="coup-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        <?= $c['used_count'] ?> /
                        <?= $c['max_uses'] ?: '∞' ?>
                    </span>

                    <?php if($c['expires_at']): ?>
                    <span class="coup-chip <?= $is_expired ? 'expired' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?= $is_expired ? 'انتهى ' : '' ?><?= date('d/m/Y', strtotime($c['expires_at'])) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <div class="coup-divider"></div>

                <!-- Actions -->
                <div class="coup-actions">
                    <form method="POST" style="flex:1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="coupon_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="coup-toggle-btn <?= $is_active ? 'is-active' : '' ?>" style="width:100%">
                            <?php if($is_active): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                تعطيل
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                                تفعيل
                            <?php endif; ?>
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('حذف هذا الكوبون؟')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="coupon_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="coup-delete-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </form>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<div class="toast" id="globalToast"></div>

<script>
// Toggle add form
function toggleAddCard() {
    document.getElementById('addCard').classList.toggle('open');
}

// Generate random code
function generateCode() {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    var code  = '';
    for(var i = 0; i < 6; i++) code += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('codeInput').value = code;
}

// Copy coupon code
function copyCouponCode(code) {
    navigator.clipboard.writeText(code).then(function() {
        var t = document.getElementById('globalToast');
        t.textContent = '✓ تم نسخ الكود: ' + code;
        t.classList.add('show');
        setTimeout(function() { t.classList.remove('show'); }, 2500);
    });
}

// Auto-open form if there was an error
<?php if($error): ?>
document.getElementById('addCard').classList.add('open');
<?php endif; ?>

// Input uppercase
document.getElementById('codeInput').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});
</script>

</body>
</html>