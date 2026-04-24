<?php
/**
 * categories.php — النسخة المرحّلة (v2)
 * 
 * التغييرات:
 *   - bootstrap.php بدل session_start() + database.php
 *   - CategoryService بدل SQL مباشر
 *   - dual write للجداول القديمة والجديدة
 *   - الواجهة: بدون أي تغيير
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../config/csrf.php';

if(!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}

$rid     = $_SESSION['restaurant_id'];
$success = '';
$error   = '';

/** @var \MenuPro\Services\CategoryService $catService */
$catService = service('category');

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_require();

    if($_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        if($name) {
            try {
                $catService->create($rid, $name, trim($_POST['name_en'] ?? ''), intval($_POST['sort_order'] ?? 0));
                $success = 'تم إضافة التصنيف بنجاح!';
            } catch(\Exception $e) {
                $error = $e->getMessage();
            }
        }
    }

    if($_POST['action'] === 'edit') {
        $catService->update(
            intval($_POST['cat_id']),
            $rid,
            trim($_POST['name'] ?? ''),
            trim($_POST['name_en'] ?? ''),
            intval($_POST['sort_order'] ?? 0),
            isset($_POST['is_active']) ? intval($_POST['is_active']) : 0
        );
        $success = 'تم تعديل التصنيف!';
    }

    if($_POST['action'] === 'delete') {
        $catService->delete(intval($_POST['cat_id']), $rid);
        $success = 'تم حذف التصنيف!';
    }

    $msg = urlencode($success ?: $error);
    header("Location: categories.php" . ($msg ? "?msg=$msg" : ""));
    exit;
}

$cats = $catService->getAllForRestaurant($rid);

if(isset($_GET['msg']) && !$success && !$error) { $success = urldecode($_GET['msg']); }
require_once 'sidebar.php';
?>
?>
<style>
/* ===== TOOLBAR ===== */
.cats-toolbar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; gap: 12px; flex-wrap: wrap;
}

/* ===== INLINE ADD CARD ===== */
.add-inline {
    background: var(--bg2);
    border: 1.5px dashed color-mix(in srgb, var(--p) 35%, transparent);
    border-radius: 18px; padding: 20px;
    margin-bottom: 20px;
    display: none;
    animation: cardIn 0.3s cubic-bezier(0.22,1,0.36,1) both;
    transition: background 0.3s;
}
.add-inline.open { display: block; }
.add-inline-title {
    font-family: 'Fraunces', serif;
    font-size: 14px; font-weight: 700;
    color: var(--p); margin-bottom: 14px;
    display: flex; align-items: center; gap: 7px;
}
.add-form-row {
    display: grid;
    grid-template-columns: 1fr 120px auto;
    gap: 10px; align-items: end;
}
@media (max-width: 520px) { .add-form-row { grid-template-columns: 1fr; } }

/* ===== GRID ===== */
.cats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 13px;
}

/* ===== CAT CARD ===== */
.cat-card {
    background: var(--bg2);
    border: 1px solid var(--line);
    border-radius: 18px; padding: 18px;
    position: relative; overflow: hidden;
    transition: transform 0.2s, background 0.3s, border-color 0.3s;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.cat-card:hover { transform: translateY(-3px); }
.cat-card.inactive { opacity: 0.55; }

/* Accent strip */
.cat-card::before {
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 3px;
    background: linear-gradient(to bottom, var(--p), var(--s));
    opacity: 0;
    transition: opacity 0.2s;
    border-radius: 0 18px 18px 0;
}
.cat-card:hover::before { opacity: 0.7; }
.cat-card.inactive::before { background: #888; opacity: 0.3; }

/* Orb */
.cat-card::after {
    content: '';
    position: absolute;
    top: -20px; right: -20px;
    width: 80px; height: 80px;
    background: var(--p);
    border-radius: 50%; opacity: 0.04;
    filter: blur(18px);
    pointer-events: none;
    transition: opacity 0.3s;
}
.cat-card:hover::after { opacity: 0.08; }

.cat-card-top {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 10px;
    margin-bottom: 12px;
}
.cat-name {
    font-family: 'Fraunces', serif;
    font-size: 17px; font-weight: 700;
    color: var(--ink); line-height: 1.3;
    transition: color 0.3s;
}

/* Status badge */
.cat-status {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700; flex-shrink: 0;
}
.cat-status .dot {
    width: 6px; height: 6px; border-radius: 50%;
}
.s-active   { background: rgba(34,197,94,0.12); color: #22C55E; border: 1px solid rgba(34,197,94,0.2); }
.s-active   .dot { background: #22C55E; animation: badgePulse 2s infinite; }
.s-inactive { background: rgba(239,68,68,0.10); color: #EF4444; border: 1px solid rgba(239,68,68,0.2); }
.s-inactive .dot { background: #EF4444; }

.cat-meta {
    display: flex; gap: 12px; margin-bottom: 15px;
    flex-wrap: wrap;
}
.cat-meta-item {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px; color: var(--ink2); font-weight: 600;
}
.cat-meta-item svg { width: 12px; height: 12px; flex-shrink: 0; }

/* Dish mini chips */
.dish-count-bar {
    display: flex; align-items: center; gap: 7px;
    margin-bottom: 14px;
}
.dish-count-num {
    font-family: 'Fraunces', serif;
    font-size: 22px; font-weight: 900;
    color: var(--p); line-height: 1;
}
.dish-count-label { font-size: 11px; color: var(--ink2); font-weight: 600; }

.cat-actions { display: flex; gap: 7px; }
.ca-btn {
    flex: 1; padding: 9px 8px; border-radius: 11px;
    border: 1px solid var(--line); font-size: 12px; font-weight: 700;
    cursor: pointer; font-family: 'Tajawal', sans-serif;
    transition: all 0.2s; background: var(--bg3); color: var(--ink2);
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.ca-btn svg { width: 13px; height: 13px; }
.ca-edit:hover   { background: color-mix(in srgb, var(--p) 12%, transparent); border-color: color-mix(in srgb, var(--p) 22%, transparent); color: var(--p); }
.ca-delete:hover { background: rgba(239,68,68,0.10); border-color: rgba(239,68,68,0.2); color: #EF4444; }

/* ===== TOGGLE SWITCH ===== */
.toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 11px 14px; background: var(--bg3); border-radius: 12px;
    border: 1.5px solid var(--line); cursor: pointer;
    transition: border-color 0.2s, background 0.3s;
}
.toggle-row:hover { border-color: var(--p); }
.toggle-label-text { font-size: 13px; font-weight: 600; color: var(--ink); }

.switch {
    width: 42px; height: 23px;
    background: var(--bg4); border-radius: 20px;
    position: relative; cursor: pointer; border: none;
    transition: background 0.3s; flex-shrink: 0;
}
.switch::after {
    content: '';
    position: absolute;
    width: 17px; height: 17px;
    background: #fff; border-radius: 50%;
    top: 3px; right: 3px;
    transition: transform 0.28s cubic-bezier(0.34,1.56,0.64,1);
    box-shadow: 0 1px 4px rgba(0,0,0,0.25);
}
.switch.on { background: #22C55E; }
.switch.on::after { transform: translateX(-19px); }

/* ===== EMPTY ===== */
.cats-empty {
    text-align: center; padding: 72px 20px;
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; color: var(--ink2);
    transition: background 0.3s, border-color 0.3s;
}
.cats-empty-icon {
    width: 72px; height: 72px; border-radius: 22px;
    background: var(--bg3); border: 1px solid var(--line);
    display: flex; align-items: center; justify-content: center;
    font-size: 34px; margin: 0 auto 16px;
}
.cats-empty p { font-size: 14px; font-weight: 600; }
</style>

<div class="main">

    <!-- Toolbar -->
    <div class="cats-toolbar">
        <div>
            <div class="page-title">التصنيفات</div>
            <div class="page-subtitle"><?= count($cats) ?> تصنيف مسجل</div>
        </div>
        <button class="btn btn-primary" onclick="toggleAddForm()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            إضافة تصنيف
        </button>
    </div>

    <?php if($success): ?>
        <div class="alert alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $success ?>
        </div>
    <?php endif; ?>

    <!-- Inline Add -->
    <div class="add-inline" id="addCard">
        <div class="add-inline-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:15px;height:15px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            تصنيف جديد
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="add-form-row">
                <div class="form-group" style="margin:0">
                    <label>اسم التصنيف *</label>
                    <input type="text" name="name" id="catNameAdd" placeholder="مثال: المشويات" required>
                </div>
                <div class="form-group" style="margin:0;grid-column:1/-1">
                    <label style="display:flex;align-items:center;justify-content:space-between;">
                        <span>الاسم بالإنجليزي</span>
                        <button type="button" onclick="translateCatAdd()" style="padding:4px 12px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:8px;color:#3B82F6;font-size:11px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;">🌐 ترجمة تلقائية</button>
                    </label>
                    <input type="text" name="name_en" id="catNameEnAdd" placeholder="e.g. Grills">
                </div>
                <div class="form-group" style="margin:0">
                    <label>الترتيب</label>
                    <input type="number" name="sort_order" value="0" min="0">
                </div>
                <div>
                    <button type="submit" class="btn btn-primary" style="width:100%;padding:11px 18px;">
                        حفظ
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Grid -->
    <?php if(empty($cats)): ?>
        <div class="cats-empty">
            <div class="cats-empty-icon">📁</div>
            <p>ما في تصنيفات بعد — أضف أول تصنيف!</p>
        </div>
    <?php else: ?>
    <div class="cats-grid">
        <?php foreach($cats as $idx => $cat): ?>
        <div class="cat-card <?= !$cat['is_active'] ? 'inactive' : '' ?>"
             style="animation-delay:<?= min($idx * 0.05, 0.4) ?>s">

            <div class="cat-card-top">
                <div class="cat-name"><?= htmlspecialchars($cat['name']) ?></div>
                <?php if(!empty($cat['name_en'])): ?>
                <div style="font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px;font-style:italic;"><?= htmlspecialchars($cat['name_en']) ?></div>
                <?php endif; ?>
                <span class="cat-status <?= $cat['is_active'] ? 's-active' : 's-inactive' ?>">
                    <span class="dot"></span>
                    <?= $cat['is_active'] ? 'نشط' : 'مخفي' ?>
                </span>
            </div>

            <div class="dish-count-bar">
                <div class="dish-count-num"><?= $cat['dish_count'] ?></div>
                <div class="dish-count-label">طبق في هذا التصنيف</div>
            </div>

            <div class="cat-meta">
                <div class="cat-meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    ترتيب: <?= $cat['sort_order'] ?>
                </div>
            </div>

            <div class="cat-actions">
                <button class="ca-btn ca-edit"
                        onclick="openEdit(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>', <?= $cat['sort_order'] ?>, <?= $cat['is_active'] ?>, '<?= htmlspecialchars($cat['name_en'] ?? '', ENT_QUOTES) ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    تعديل
                </button>
                <button class="ca-btn ca-delete"
                        onclick="confirmDelete(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
                    حذف
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Edit Modal -->
<div class="modal-bg" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <h3>تعديل التصنيف</h3>
            <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('open')">✕</button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="edit">
            <input type="hidden" name="cat_id"   id="editCatId">
            <input type="hidden" name="is_active" id="isActiveInput" value="1">

            <div class="form-group">
                <label>اسم التصنيف *</label>
                <input type="text" name="name" id="editName" required>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label style="display:flex;align-items:center;justify-content:space-between;">
                    <span>الاسم بالإنجليزي</span>
                    <button type="button" onclick="translateCatEdit()" style="padding:4px 12px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:8px;color:#3B82F6;font-size:11px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;">🌐 ترجمة</button>
                </label>
                <input type="text" name="name_en" id="editNameEn" placeholder="e.g. Grills">
            </div>
            <div class="form-group">
                <label>الترتيب</label>
                <input type="number" name="sort_order" id="editSort" min="0">
            </div>
            <div class="form-group">
                <div class="toggle-row" onclick="toggleActive()">
                    <span class="toggle-label-text">التصنيف نشط ومرئي في المنيو</span>
                    <button type="button" class="switch" id="switchBtn"></button>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" style="flex:1"
                        onclick="document.getElementById('editModal').classList.remove('open')">إلغاء</button>
                <button type="submit" class="btn btn-primary" style="flex:2">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal-bg" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <h3>تأكيد الحذف</h3>
            <button class="modal-close" onclick="document.getElementById('deleteModal').classList.remove('open')">✕</button>
        </div>
        <p style="color:var(--ink2);font-size:13px;margin-bottom:20px;line-height:1.8;">
            رح تحذف تصنيف "<strong id="deleteCatName" style="color:var(--ink)"></strong>".<br>
            الأطباق المرتبطة بهاد التصنيف رح تبقى بس بدون تصنيف.
        </p>
        <form method="POST" style="display:flex;gap:9px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="cat_id" id="deleteCatId">
            <button type="button" class="btn btn-secondary" style="flex:1"
                    onclick="document.getElementById('deleteModal').classList.remove('open')">إلغاء</button>
            <button type="submit" class="btn btn-danger" style="flex:1">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
                حذف
            </button>
        </form>
    </div>
</div>

<script>
function toggleAddForm() {
    var el = document.getElementById('addCard');
    el.classList.toggle('open');
}

function openEdit(id, name, sort, active, nameEn) {
    document.getElementById('editCatId').value   = id;
    document.getElementById('editName').value    = name;
    document.getElementById('editNameEn').value  = nameEn || '';
    document.getElementById('editSort').value    = sort;
    var sw  = document.getElementById('switchBtn');
    var inp = document.getElementById('isActiveInput');
    if(active) { sw.classList.add('on'); inp.value = '1'; }
    else        { sw.classList.remove('on'); inp.value = '0'; }
    document.getElementById('editModal').classList.add('open');
}

function toggleActive() {
    var sw  = document.getElementById('switchBtn');
    var inp = document.getElementById('isActiveInput');
    sw.classList.toggle('on');
    inp.value = sw.classList.contains('on') ? '1' : '0';
}

function confirmDelete(id, name) {
    document.getElementById('deleteCatId').value           = id;
    document.getElementById('deleteCatName').textContent   = name;
    document.getElementById('deleteModal').classList.add('open');
}

document.querySelectorAll('.modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) {
        if(e.target === bg) bg.classList.remove('open');
    });
});

// ===== ترجمة الفئات =====
async function translateCatAdd() {
    const name = document.getElementById('catNameAdd')?.value.trim();
    if(!name) return;
    const btn = event.target;
    btn.textContent = '...'; btn.disabled = true;
    try {
        const r = await fetch(`https://api.mymemory.translated.net/get?q=${encodeURIComponent(name)}&langpair=ar|en`);
        const d = await r.json();
        const en = d.responseData?.translatedText || '';
        if(en) document.getElementById('catNameEnAdd').value = en;
        btn.textContent = '✅ تمت';
        setTimeout(()=>{ btn.textContent='🌐 ترجمة تلقائية'; btn.disabled=false; }, 2000);
    } catch(e) { btn.textContent='❌'; btn.disabled=false; }
}

async function translateCatEdit() {
    const name = document.getElementById('editName')?.value.trim();
    if(!name) return;
    const btn = event.target;
    btn.textContent = '...'; btn.disabled = true;
    try {
        const r = await fetch(`https://api.mymemory.translated.net/get?q=${encodeURIComponent(name)}&langpair=ar|en`);
        const d = await r.json();
        const en = d.responseData?.translatedText || '';
        if(en) document.getElementById('editNameEn').value = en;
        btn.textContent = '✅ تمت';
        setTimeout(()=>{ btn.textContent='🌐 ترجمة'; btn.disabled=false; }, 2000);
    } catch(e) { btn.textContent='❌'; btn.disabled=false; }
}
</script>

</body>
</html>