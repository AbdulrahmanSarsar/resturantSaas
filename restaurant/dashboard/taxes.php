<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/csrf.php';
require_once '../../src/Helpers/PriceHelper.php';
require_once 'plan_guard.php';
if(!isset($_SESSION['restaurant_id'])) { header('Location: ../login.php'); exit; }
plan_required('advanced'); // إدارة الضرائب من ميزات الباقة المتقدمة
$rid     = $_SESSION['restaurant_id'];
$success = '';
$error   = '';

// ===== الفروع + الفرع النشط =====
$active_branches = [];
$bstmt = $pdo->prepare("SELECT id, name FROM branches WHERE restaurant_id = ? AND is_active = 1 ORDER BY id ASC");
$bstmt->execute([$rid]);
$active_branches = $bstmt->fetchAll();

$active_branch_id   = $_SESSION['active_branch_id'] ?? null;
$active_branch_name = '';
if ($active_branch_id) {
    foreach ($active_branches as $b) {
        if ($b['id'] == $active_branch_id) { $active_branch_name = $b['name']; break; }
    }
}

// ===== Currency =====
$cur        = load_branch_currency($pdo, $active_branch_id);
$cur_symbol = $cur['symbol'];
$cur_dec    = $cur['decimals'];
$cur_pfx    = $cur['prefix'];

// Helper: تحقق إن الفرع تبع هالمطعم
$validate_branch = function($branch_id) use ($pdo, $rid) {
    if (!$branch_id) return null;
    $chk = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND restaurant_id = ? AND is_active = 1");
    $chk->execute([$branch_id, $rid]);
    return $chk->fetch() ? (int)$branch_id : null;
};

// Helper: تحقق إن الضريبة تبع فرع تبع هالمطعم (قبل UPDATE/DELETE)
$tax_belongs_to_restaurant = function($tax_id) use ($pdo, $rid) {
    $chk = $pdo->prepare("
        SELECT bt.id FROM branch_taxes bt
        JOIN branches b ON b.id = bt.branch_id
        WHERE bt.id = ? AND b.restaurant_id = ?
    ");
    $chk->execute([$tax_id, $rid]);
    return (bool)$chk->fetch();
};

// ===== POST HANDLER =====
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_require();

    if($_POST['action'] === 'add') {
        $branch_id = $validate_branch(intval($_POST['branch_id'] ?? ($active_branch_id ?? 0)));
        $name    = trim($_POST['name']    ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        $type    = in_array($_POST['type']??'', ['percentage','fixed']) ? $_POST['type'] : 'percentage';
        $value   = floatval($_POST['value'] ?? 0);
        $sort    = intval($_POST['sort_order'] ?? 0);
        if(!$branch_id) { $error = 'الرجاء اختيار فرع صحيح.'; }
        elseif($name && $value >= 0) {
            $pdo->prepare("INSERT INTO branch_taxes (branch_id,name,name_en,type,value,sort_order,is_active) VALUES (?,?,?,?,?,?,1)")
                ->execute([$branch_id, $name, $name_en, $type, $value, $sort]);
            $success = 'تمت إضافة الضريبة بنجاح!';
        } else { $error = 'يرجى تعبئة الاسم والقيمة.'; }
    }

    if($_POST['action'] === 'edit') {
        $tid     = intval($_POST['tax_id']);
        $name    = trim($_POST['name']    ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        $type    = in_array($_POST['type']??'', ['percentage','fixed']) ? $_POST['type'] : 'percentage';
        $value   = floatval($_POST['value'] ?? 0);
        $sort    = intval($_POST['sort_order'] ?? 0);
        $active  = isset($_POST['is_active']) ? 1 : 0;
        if($tax_belongs_to_restaurant($tid)) {
            $pdo->prepare("UPDATE branch_taxes SET name=?,name_en=?,type=?,value=?,sort_order=?,is_active=? WHERE id=?")
                ->execute([$name, $name_en, $type, $value, $sort, $active, $tid]);
            $success = 'تم تعديل الضريبة!';
        } else { $error = 'الضريبة غير موجودة'; }
    }

    if($_POST['action'] === 'delete') {
        $tid = intval($_POST['tax_id']);
        if($tax_belongs_to_restaurant($tid)) {
            $pdo->prepare("DELETE FROM branch_taxes WHERE id=?")->execute([$tid]);
            $success = 'تم حذف الضريبة!';
        }
    }

    if($_POST['action'] === 'toggle') {
        $tid = intval($_POST['tax_id']);
        if($tax_belongs_to_restaurant($tid)) {
            $pdo->prepare("UPDATE branch_taxes SET is_active = NOT is_active WHERE id=?")->execute([$tid]);
        }
        if(isset($_POST['ajax'])) { echo json_encode(['success'=>true]); exit; }
    }

    // Redirect بعد أي action لمنع إعادة إرسال الـ form
    $msg = urlencode($success ?: $error);
    header("Location: taxes.php" . ($msg ? "?msg=" . $msg : ""));
    exit;
}

// ===== جلب الضرائب =====
// إذا في فرع نشط → ضرائبه فقط
// إذا ما في → كل الضرائب للمطعم (مع عمود branch_name)
if ($active_branch_id) {
    $taxes = $pdo->prepare("
        SELECT bt.*, b.name AS branch_name
        FROM branch_taxes bt
        JOIN branches b ON b.id = bt.branch_id
        WHERE bt.branch_id = ?
        ORDER BY bt.sort_order, bt.id
    ");
    $taxes->execute([$active_branch_id]);
} else {
    $taxes = $pdo->prepare("
        SELECT bt.*, b.name AS branch_name
        FROM branch_taxes bt
        JOIN branches b ON b.id = bt.branch_id
        WHERE b.restaurant_id = ?
        ORDER BY b.id, bt.sort_order, bt.id
    ");
    $taxes->execute([$rid]);
}
$taxes = $taxes->fetchAll();

// قراءة رسائل الـ redirect
if(isset($_GET['msg']) && !$success && !$error) {
    $decoded = urldecode($_GET['msg']);
    if(str_starts_with($decoded, 'تم') || str_starts_with($decoded, '✅')) $success = $decoded;
    else $error = $decoded;
}

require_once 'sidebar.php';
?>
<style>
/* ===== TAXES PAGE ===== */
.taxes-toolbar { display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;gap:12px;flex-wrap:wrap; }
.taxes-grid    { display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px; }

/* Tax Card */
.tax-card {
    background:var(--bg2); border:1px solid var(--line);
    border-radius:18px; padding:18px; position:relative;
    transition:transform .2s,border-color .3s,background .3s;
    animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;
}
.tax-card:hover { transform:translateY(-2px); }
.tax-card.inactive { opacity:.55; }

.tax-card-head { display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:14px; }
.tax-name  { font-family:'Fraunces',serif;font-size:16px;font-weight:700;color:var(--ink); }
.tax-name-en { font-size:11px;color:var(--ink2);font-style:italic;margin-top:2px; }

.tax-badge {
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 10px;border-radius:20px;
    font-size:12px;font-weight:800;white-space:nowrap;
}
.tax-badge.pct { background:rgba(99,102,241,.12);color:#818CF8;border:1px solid rgba(99,102,241,.2); }
.tax-badge.fix { background:rgba(34,197,94,.1);color:#22C55E;border:1px solid rgba(34,197,94,.2); }

.tax-value {
    font-family:'Fraunces',serif;
    font-size:32px;font-weight:900;color:var(--p);
    letter-spacing:-1px;margin-bottom:4px;
}
.tax-value-unit { font-size:16px;font-weight:600;color:var(--ink2); }

.tax-status {
    display:inline-flex;align-items:center;gap:5px;
    padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;
}
.s-active   { background:rgba(34,197,94,.12);color:#22C55E;border:1px solid rgba(34,197,94,.2); }
.s-inactive { background:rgba(239,68,68,.1);color:#EF4444;border:1px solid rgba(239,68,68,.2); }
.s-active .dot   { width:5px;height:5px;border-radius:50%;background:#22C55E;box-shadow:0 0 5px #22C55E;animation:badgePulse 2s infinite; }
.s-inactive .dot { width:5px;height:5px;border-radius:50%;background:#EF4444; }

.tax-meta { font-size:12px;color:var(--ink2);margin-top:8px;font-weight:600; }

.tax-actions { display:flex;gap:7px;margin-top:14px; }
.ta-btn {
    flex:1;padding:9px 8px;border-radius:11px;border:1px solid var(--line);
    font-size:12px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;
    transition:all .2s;background:var(--bg3);color:var(--ink2);
    display:flex;align-items:center;justify-content:center;gap:5px;
}
.ta-btn svg { width:13px;height:13px; }
.ta-edit:hover   { background:color-mix(in srgb,var(--p) 12%,transparent);border-color:color-mix(in srgb,var(--p) 22%,transparent);color:var(--p); }
.ta-toggle:hover { background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.2);color:#22C55E; }
.ta-delete:hover { background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:#EF4444; }

/* Add Inline */
.add-inline {
    background:var(--bg2);border:1.5px dashed color-mix(in srgb,var(--p) 35%,transparent);
    border-radius:18px;padding:20px;margin-bottom:20px;display:none;
    animation:cardIn .3s cubic-bezier(.22,1,.36,1) both;
}
.add-inline.open { display:block; }
.add-inline-title { font-family:'Fraunces',serif;font-size:14px;font-weight:700;color:var(--p);margin-bottom:14px;display:flex;align-items:center;gap:7px; }

/* Summary Card */
.tax-summary {
    background:var(--bg2);border:1px solid var(--line);border-radius:18px;
    padding:18px;margin-bottom:20px;
}
.tax-summary-title { font-size:12px;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px; }
.tax-sim-row { display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px; }
.tax-sim-row:last-child { border-bottom:none; }
.tax-sim-label { color:var(--ink2);font-weight:600; }
.tax-sim-value { color:var(--ink);font-weight:700;font-family:'Fraunces',serif; }
.tax-sim-total { color:var(--p);font-size:16px;font-weight:900; }
.sim-input-wrap { display:flex;align-items:center;gap:10px;margin-bottom:14px; }
.sim-input { flex:1;padding:10px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;color:var(--ink);font-size:14px;font-family:'Tajawal',sans-serif;font-weight:600;outline:none; }
.sim-input:focus { border-color:var(--p); }

.empty-taxes { text-align:center;padding:60px 20px;background:var(--bg2);border:1px solid var(--line);border-radius:18px; }
.empty-taxes-icon { width:72px;height:72px;border-radius:22px;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 14px; }

/* type toggle */
.type-toggle { display:flex;gap:8px;margin-top:4px; }
.type-btn {
    flex:1;padding:9px;border-radius:11px;border:1.5px solid var(--line);
    background:var(--bg3);color:var(--ink2);font-size:12px;font-weight:700;
    cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;text-align:center;
}
.type-btn.active { background:var(--p);color:#fff;border-color:transparent; }
</style>

<div class="main">

<div class="taxes-toolbar">
    <div>
        <div class="page-title">
            الضرائب والرسوم
            <?php if($active_branch_name): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:rgba(255,107,53,0.12);color:var(--p);border-radius:20px;font-size:12px;font-weight:700;margin-inline-start:8px;vertical-align:middle;">
                📍 <?= htmlspecialchars($active_branch_name) ?>
            </span>
            <?php elseif(!empty($active_branches)): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:var(--bg3);color:var(--ink2);border-radius:20px;font-size:12px;font-weight:700;margin-inline-start:8px;vertical-align:middle;">
                🌐 كل الفروع
            </span>
            <?php endif; ?>
        </div>
        <div class="page-subtitle"><?= count($taxes) ?> ضريبة/رسم مسجل</div>
    </div>
    <?php if(empty($active_branches)): ?>
        <a href="branches.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            أضف فرع أولاً
        </a>
    <?php else: ?>
        <button class="btn btn-primary" onclick="document.getElementById('addCard').classList.toggle('open')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            إضافة ضريبة
        </button>
    <?php endif; ?>
</div>

<?php if($success): ?>
<div class="alert alert-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg><?= $success ?></div>
<?php elseif($error): ?>
<div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<!-- ===== ADD FORM ===== -->
<div class="add-inline" id="addCard">
    <div class="add-inline-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:15px;height:15px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        ضريبة / رسم جديد
    </div>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="type" id="addTypeVal" value="percentage">
        <div class="form-row-2">
            <div class="form-group" style="margin:0">
                <label>الفرع *</label>
                <select name="branch_id" required style="width:100%;padding:11px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;color:var(--ink);font-size:13px;font-family:'Tajawal',sans-serif;outline:none;">
                    <?php foreach($active_branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $active_branch_id == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label>الاسم (عربي) *</label>
                <input type="text" name="name" placeholder="مثال: ضريبة القيمة المضافة" required>
            </div>
            <div class="form-group" style="margin:0">
                <label>الاسم (English)</label>
                <input type="text" name="name_en" placeholder="e.g. VAT">
            </div>
            <div class="form-group" style="margin:0">
                <label>نوع الضريبة</label>
                <div class="type-toggle">
                    <div class="type-btn active" id="addBtnPct" onclick="setAddType('percentage')">
                        ٪ نسبة مئوية
                    </div>
                    <div class="type-btn" id="addBtnFix" onclick="setAddType('fixed')">
                        <?= $cur_symbol ?> مبلغ ثابت
                    </div>
                </div>
            </div>
            <div class="form-group" style="margin:0">
                <label id="addValueLabel">القيمة (٪)</label>
                <input type="number" name="value" id="addValueInput" step="0.01" min="0" max="100" placeholder="مثال: 10" required>
            </div>
            <div class="form-group" style="margin:0">
                <label>الترتيب</label>
                <input type="number" name="sort_order" value="0" min="0">
            </div>
            <div style="display:flex;align-items:flex-end;">
                <button type="submit" class="btn btn-primary" style="width:100%;padding:11px 18px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/></svg>
                    حفظ
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ===== SIMULATOR ===== -->
<?php if(!empty($taxes)): ?>
<div class="tax-summary" id="simulator">
    <div class="tax-summary-title">🧮 محاكاة حساب الضريبة</div>
    <div class="sim-input-wrap">
        <input type="number" class="sim-input" id="simAmount" placeholder="أدخل مبلغ الطلب..." step="0.01" min="0" oninput="runSim()">
        <button class="btn btn-secondary" onclick="document.getElementById('simAmount').value='';runSim()">مسح</button>
    </div>
    <div id="simResults"></div>
</div>
<?php endif; ?>

<!-- ===== GRID ===== -->
<?php if(empty($taxes)): ?>
<div class="empty-taxes">
    <div class="empty-taxes-icon">﹪</div>
    <p style="font-size:14px;font-weight:600;color:var(--ink2);">لا توجد ضرائب بعد</p>
    <p style="font-size:12px;color:var(--ink2);margin-top:5px;">أضف ضريبة القيمة المضافة أو رسوم الخدمة</p>
</div>
<?php else: ?>
<div class="taxes-grid">
    <?php foreach($taxes as $i => $tax): ?>
    <div class="tax-card <?= !$tax['is_active'] ? 'inactive' : '' ?>" style="animation-delay:<?= $i*0.05 ?>s">
        <div class="tax-card-head">
            <div>
                <div class="tax-name"><?= htmlspecialchars($tax['name']) ?></div>
                <?php if($tax['name_en']): ?>
                <div class="tax-name-en"><?= htmlspecialchars($tax['name_en']) ?></div>
                <?php endif; ?>
            </div>
            <span class="tax-badge <?= $tax['type']==='percentage'?'pct':'fix' ?>">
                <?= $tax['type']==='percentage' ? '٪ نسبة' : htmlspecialchars($cur_symbol) . ' ثابت' ?>
            </span>
        </div>

        <div class="tax-value">
            <?= number_format($tax['value'], $tax['value'] == floor($tax['value']) ? 0 : 2) ?>
            <span class="tax-value-unit"><?= $tax['type']==='percentage' ? '%' : htmlspecialchars($cur_symbol) ?></span>
        </div>

        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span class="tax-status <?= $tax['is_active'] ? 's-active' : 's-inactive' ?>">
                <span class="dot"></span>
                <?= $tax['is_active'] ? 'مفعّل' : 'معطّل' ?>
            </span>
            <?php if(!$active_branch_id && !empty($tax['branch_name'])): ?>
            <span style="font-size:11px;color:var(--p);background:rgba(255,107,53,0.1);padding:3px 8px;border-radius:10px;font-weight:700;">
                📍 <?= htmlspecialchars($tax['branch_name']) ?>
            </span>
            <?php endif; ?>
            <span style="font-size:11px;color:var(--ink2);font-weight:600;">ترتيب: <?= $tax['sort_order'] ?></span>
        </div>

        <div class="tax-actions">
            <button class="ta-btn ta-edit" onclick="openEditTax(<?= htmlspecialchars(json_encode($tax)) ?>)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                تعديل
            </button>
            <form method="POST" style="flex:1;display:flex;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="tax_id" value="<?= $tax['id'] ?>">
                <button type="submit" class="ta-btn ta-toggle" style="flex:1;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><?= $tax['is_active'] ? '<line x1="8" y1="12" x2="16" y2="12"/>' : '<polyline points="16 12 12 8 8 12"/><line x1="12" y1="16" x2="12" y2="8"/>' ?></svg>
                    <?= $tax['is_active'] ? 'تعطيل' : 'تفعيل' ?>
                </button>
            </form>
            <button class="ta-btn ta-delete" onclick="confirmDeleteTax(<?= $tax['id'] ?>, '<?= htmlspecialchars($tax['name'], ENT_QUOTES) ?>')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
                حذف
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- ===== EDIT MODAL ===== -->
<div class="modal-bg" id="editTaxModal">
    <div class="modal">
        <div class="modal-header">
            <h3>تعديل الضريبة</h3>
            <button class="modal-close" onclick="document.getElementById('editTaxModal').classList.remove('open')">✕</button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tax_id" id="editTaxId">
            <input type="hidden" name="type" id="editTypeVal" value="percentage">

            <div class="form-group">
                <label>الاسم (عربي) *</label>
                <input type="text" name="name" id="editTaxName" required>
            </div>
            <div class="form-group">
                <label>الاسم (English)</label>
                <input type="text" name="name_en" id="editTaxNameEn" placeholder="e.g. VAT">
            </div>
            <div class="form-group">
                <label>نوع الضريبة</label>
                <div class="type-toggle">
                    <div class="type-btn" id="editBtnPct" onclick="setEditType('percentage')">٪ نسبة مئوية</div>
                    <div class="type-btn" id="editBtnFix" onclick="setEditType('fixed')"><?= $cur_symbol ?> مبلغ ثابت</div>
                </div>
            </div>
            <div class="form-group">
                <label id="editValueLabel">القيمة (٪)</label>
                <input type="number" name="value" id="editTaxValue" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label>الترتيب</label>
                <input type="number" name="sort_order" id="editTaxSort" min="0" value="0">
            </div>
            <div class="form-group">
                <label class="check-wrap">
                    <input type="checkbox" name="is_active" id="editTaxActive" value="1" checked>
                    <span class="check-label">الضريبة مفعّلة</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" style="flex:1" onclick="document.getElementById('editTaxModal').classList.remove('open')">إلغاء</button>
                <button type="submit" class="btn btn-primary" style="flex:2">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== DELETE MODAL ===== -->
<div class="modal-bg" id="deleteTaxModal">
    <div class="modal">
        <div class="modal-header">
            <h3>تأكيد الحذف</h3>
            <button class="modal-close" onclick="document.getElementById('deleteTaxModal').classList.remove('open')">✕</button>
        </div>
        <p style="color:var(--ink2);font-size:13px;margin-bottom:20px;line-height:1.8;">
            سيتم حذف ضريبة "<strong id="deleteTaxName" style="color:var(--ink)"></strong>". هذا الإجراء لا يمكن التراجع عنه.
        </p>
        <form method="POST" style="display:flex;gap:9px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="tax_id" id="deleteTaxId">
            <button type="button" class="btn btn-secondary" style="flex:1" onclick="document.getElementById('deleteTaxModal').classList.remove('open')">إلغاء</button>
            <button type="submit" class="btn btn-danger" style="flex:1">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
                حذف
            </button>
        </form>
    </div>
</div>

<script>
// ===== ADD TYPE TOGGLE =====
function setAddType(type) {
    document.getElementById('addTypeVal').value = type;
    document.getElementById('addBtnPct').classList.toggle('active', type==='percentage');
    document.getElementById('addBtnFix').classList.toggle('active', type==='fixed');
    document.getElementById('addValueLabel').textContent = type==='percentage' ? 'القيمة (٪)' : `القيمة (${CUR_SYMBOL})`;
    const inp = document.getElementById('addValueInput');
    inp.max = type==='percentage' ? 100 : '';
    inp.placeholder = type==='percentage' ? 'مثال: 10' : 'مثال: 2.50';
}

// ===== EDIT =====
function openEditTax(tax) {
    document.getElementById('editTaxId').value     = tax.id;
    document.getElementById('editTaxName').value   = tax.name;
    document.getElementById('editTaxNameEn').value = tax.name_en || '';
    document.getElementById('editTaxValue').value  = tax.value;
    document.getElementById('editTaxSort').value   = tax.sort_order;
    document.getElementById('editTaxActive').checked = tax.is_active == 1;
    setEditType(tax.type);
    document.getElementById('editTaxModal').classList.add('open');
}

function setEditType(type) {
    document.getElementById('editTypeVal').value = type;
    document.getElementById('editBtnPct').classList.toggle('active', type==='percentage');
    document.getElementById('editBtnFix').classList.toggle('active', type==='fixed');
    document.getElementById('editValueLabel').textContent = type==='percentage' ? 'القيمة (٪)' : `القيمة (${CUR_SYMBOL})`;
}

// ===== DELETE =====
function confirmDeleteTax(id, name) {
    document.getElementById('deleteTaxId').value     = id;
    document.getElementById('deleteTaxName').textContent = name;
    document.getElementById('deleteTaxModal').classList.add('open');
}

// ===== SIMULATOR =====
const TAXES = <?= json_encode(array_filter($taxes, fn($t) => $t['is_active']), JSON_UNESCAPED_UNICODE) ?>;

const CUR_SYMBOL = <?= json_encode($cur_symbol) ?>;

function runSim() {
    const amount = parseFloat(document.getElementById('simAmount').value) || 0;
    const container = document.getElementById('simResults');
    if(amount <= 0) { container.innerHTML=''; return; }

    let html = `<div class="tax-sim-row">
        <span class="tax-sim-label">مجموع الأصناف</span>
        <span class="tax-sim-value">${CUR_SYMBOL}${amount.toFixed(2)}</span>
    </div>`;

    let totalTax = 0;
    const activeTaxes = Object.values(TAXES);
    activeTaxes.forEach(tax => {
        const taxAmt = tax.type === 'percentage'
            ? (amount * tax.value / 100)
            : parseFloat(tax.value);
        totalTax += taxAmt;
        html += `<div class="tax-sim-row">
            <span class="tax-sim-label">${tax.name}${tax.type==='percentage'?` (${tax.value}%)`:` (ثابت ${CUR_SYMBOL}${parseFloat(tax.value).toFixed(2)})`}</span>
            <span class="tax-sim-value" style="color:#818CF8;">+${CUR_SYMBOL}${taxAmt.toFixed(2)}</span>
        </div>`;
    });

    const grand = amount + totalTax;
    html += `<div class="tax-sim-row" style="margin-top:4px;">
        <span class="tax-sim-label" style="font-weight:800;color:var(--ink);">المجموع الكلي</span>
        <span class="tax-sim-value tax-sim-total">${CUR_SYMBOL}${grand.toFixed(2)}</span>
    </div>`;

    container.innerHTML = html;
}

// ===== MODALS CLOSE =====
document.querySelectorAll('.modal-bg').forEach(bg => {
    bg.addEventListener('click', e => { if(e.target===bg) bg.classList.remove('open'); });
});
</script>
</body>
</html>