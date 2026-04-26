<?php
/**
 * dishes.php — النسخة المرحّلة (v2)
 * 
 * التغييرات:
 *   - bootstrap.php بدل session_start() + database.php
 *   - DishService بدل SQL مباشر (لكل العمليات: add/edit/delete/toggle/options)
 *   - dual write تلقائي
 *   - الواجهة: بدون أي تغيير (1400+ سطر HTML/CSS/JS كما هي)
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once 'plan_guard.php';

if(!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}

$rid     = $_SESSION['restaurant_id'];
$active_branch_id = $_SESSION['active_branch_id'] ?? null;
$success = '';

/** @var \MenuPro\Services\DishService $dishService */
$dishService = service('dish');

/** @var \MenuPro\Services\CategoryService $catService */
$catService = service('category');

// جلب التصنيفات
$cats = $catService->getActiveForRestaurant($rid);

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_require();

    // Server-side gate: لو الباقة ما تدعم AR، اطرح ملفات .glb/.usdz من المُدخل قبل التمرير للـ service
    if (!plan_has_feature('ar')) {
        unset($_FILES['model3d'], $_FILES['model3d_usdz']);
        unset($_POST['old_model'], $_POST['old_usdz']); // يمنع IDs قديمة من الانحفاظ
    }

    if($_POST['action'] === 'add') {
        $result = $dishService->create($rid, $_POST, $_FILES, $active_branch_id);
        $success = $result['message'];
    }

    if($_POST['action'] === 'edit') {
        $result = $dishService->update(intval($_POST['dish_id']), $rid, $_POST, $_FILES, $active_branch_id);
        $success = $result['message'];
    }

    if($_POST['action'] === 'delete') {
        $result = $dishService->delete(intval($_POST['dish_id']), $rid);
        $success = $result['message'];
    }

    if($_POST['action'] === 'toggle') {
        $dishService->toggleAvailability(intval($_POST['dish_id']), $rid);
        if(isset($_POST['ajax'])) { echo json_encode(['success'=>true]); exit; }
    }

    if($_POST['action'] === 'reset_soldout') {
        $dishService->resetAllSoldOut($rid, $active_branch_id);
        $_SESSION['last_soldout_reset'] = date('Y-m-d');
        $success = 'تم إعادة تفعيل جميع الأطباق!';
    }

    if($_POST['action'] === 'toggle_soldout') {
        $dishService->toggleSoldOut(intval($_POST['dish_id']), $rid, $active_branch_id);
        if(isset($_POST['ajax'])) { echo json_encode(['success'=>true]); exit; }
    }

    if($_POST['action'] === 'save_options') {
        $dishService->saveOptions(intval($_POST['dish_id']), $rid, $_POST['opt_groups'] ?? []);
        echo json_encode(['success'=>true]); exit;
    }
}

// فلتر
$filter_cat = intval($_GET['cat'] ?? 0);
$dishes = $dishService->getAllForRestaurant($rid, $filter_cat ?: null, $active_branch_id);

// كل الأطباق للقائمة في modal الاقتراحات
$all_dishes = $dishService->getActiveForRestaurant($rid);

// اقتراحات كل طبق
$sugg_map = $dishService->getSuggestionMap($rid);

// خيارات كل طبق
$options_map = $dishService->getOptionsMap($rid);

require_once 'sidebar.php';
?>
<style>
/* ===== TOOLBAR ===== */
.dishes-toolbar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; gap: 12px; flex-wrap: wrap;
}

/* ===== CAT TABS ===== */
.cat-tabs { display: flex; gap: 7px; flex-wrap: wrap; margin-bottom: 20px; }
.cat-tab {
    padding: 7px 15px; border-radius: 22px;
    border: 1.5px solid var(--line); background: var(--bg2);
    font-size: 12px; font-weight: 700; cursor: pointer;
    text-decoration: none; color: var(--ink2);
    transition: all 0.2s; font-family: 'Tajawal', sans-serif;
}
.cat-tab:hover { border-color: var(--p); color: var(--p); }
.cat-tab.active {
    background: var(--p); border-color: transparent; color: #fff;
    box-shadow: 0 3px 10px color-mix(in srgb, var(--p) 30%, transparent);
}

/* ===== GRID ===== */
.dishes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px,1fr));
    gap: 14px;
}
@media (max-width: 480px) { .dishes-grid { grid-template-columns: 1fr; } }

/* ===== DISH CARD ===== */
.dish-card {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; overflow: hidden;
    transition: transform 0.22s, box-shadow 0.22s;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
    display: flex; flex-direction: column;
}
.dish-card:hover { transform: translateY(-4px); box-shadow: var(--shadow2); }
.dish-card.unavailable { opacity: 0.58; }

.dish-img-wrap {
    position: relative; height: 155px;
    background: var(--bg3); overflow: hidden;
}
.dish-img-wrap img { width:100%;height:100%;object-fit:cover; transition:transform 0.4s; }
.dish-card:hover .dish-img-wrap img { transform: scale(1.05); }
.dish-img-placeholder {
    width:100%;height:100%;
    display:flex;align-items:center;justify-content:center;
    font-size:52px;opacity:0.22;
}
.dish-overlay-badges { position:absolute;top:9px;left:9px;display:flex;gap:5px; }
.dob {
    padding:3px 9px;border-radius:20px;font-size:10px;font-weight:800;
    backdrop-filter:blur(8px);
}
.dob-ar      { background:rgba(139,92,246,0.85);color:#fff; }
.dob-unavail { background:rgba(239,68,68,0.80);color:#fff; }
.dob-soldout { background:rgba(245,158,11,0.88);color:#fff; }
.dish-cat-chip {
    position:absolute;top:9px;right:9px;
    background:rgba(0,0,0,0.48);backdrop-filter:blur(6px);
    color:#fff;padding:3px 9px;border-radius:20px;
    font-size:10px;font-weight:700;
}

/* Suggestion badge on card */
.dish-sugg-badge {
    position:absolute;bottom:9px;left:9px;
    background:rgba(255,107,53,0.88);backdrop-filter:blur(6px);
    color:#fff;padding:3px 10px;border-radius:20px;
    font-size:10px;font-weight:800;display:flex;align-items:center;gap:4px;
}
.dish-sugg-badge svg { width:10px;height:10px; }

.dish-body { padding:14px 15px 12px; display:flex; flex-direction:column; flex:1; }
.dish-top { display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:4px; }
.dish-name {
    font-family:'Fraunces',serif;
    font-size:15px;font-weight:700;color:var(--ink);
    line-height:1.3;flex:1;transition:color 0.3s;
}
.dish-price {
    font-family:'Fraunces',serif;
    font-size:16px;font-weight:900;color:var(--p);flex-shrink:0;
}
.dish-price-old {
    font-family:'Fraunces',serif;
    font-size:12px;font-weight:600;color:var(--ink3);
    text-decoration:line-through;flex-shrink:0;
}
.discount-badge-card {
    display:inline-flex;align-items:center;gap:2px;
    background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.22);
    color:#EF4444;padding:1px 6px;border-radius:8px;
    font-size:10px;font-weight:800;position:absolute;top:9px;left:9px;z-index:2;
    backdrop-filter:blur(6px);
}
.dish-price-wrap { display:flex;flex-direction:column;align-items:flex-end;gap:1px; }
.dish-desc {
    font-size:12px;color:var(--ink2);line-height:1.5;
    margin-bottom:0;font-weight:500;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
    flex:1;
}
.dish-actions { display:flex;gap:6px; margin-top:auto; padding-top:10px; }
.da-btn {
    flex:1;padding:8px 6px;border-radius:10px;
    border:1px solid var(--line);font-size:12px;font-weight:700;
    cursor:pointer;font-family:'Tajawal',sans-serif;
    transition:all 0.2s;background:var(--bg3);color:var(--ink2);
    display:flex;align-items:center;justify-content:center;gap:4px;
}
.da-btn svg { width:12px;height:12px;flex-shrink:0; }
.da-avail   { color:#22C55E;border-color:rgba(34,197,94,0.2);background:rgba(34,197,94,0.07); }
.da-avail:hover   { background:#22C55E;color:#fff;border-color:transparent; }
.da-unavail { color:#EF4444;border-color:rgba(239,68,68,0.2);background:rgba(239,68,68,0.07); }
.da-unavail:hover { background:#EF4444;color:#fff;border-color:transparent; }
.da-edit:hover  { background:color-mix(in srgb,var(--p) 12%,transparent);border-color:color-mix(in srgb,var(--p) 22%,transparent);color:var(--p); }
.da-delete:hover{ background:rgba(239,68,68,0.10);border-color:rgba(239,68,68,0.2);color:#EF4444; }
.da-soldout      { color:var(--ink2); }
.da-soldout:hover{ background:rgba(245,158,11,0.10);border-color:rgba(245,158,11,0.25);color:#F59E0B; }
.da-soldout-active{ background:rgba(245,158,11,0.12);border-color:rgba(245,158,11,0.3);color:#F59E0B; }
.da-soldout-active:hover{ background:#F59E0B;color:#fff;border-color:transparent; }

.dishes-empty {
    text-align:center;padding:72px 20px;
    background:var(--bg2);border:1px solid var(--line);
    border-radius:18px;color:var(--ink2);
}
.dishes-empty-icon {
    width:72px;height:72px;border-radius:22px;
    background:var(--bg3);border:1px solid var(--line);
    display:flex;align-items:center;justify-content:center;
    font-size:34px;margin:0 auto 16px;
}
.dishes-empty p { font-size:14px;font-weight:600; }

/* ===== SUGGESTIONS SECTION IN MODAL ===== */
.sugg-section {
    margin-top: 4px;
    border: 1.5px solid color-mix(in srgb, var(--p) 25%, transparent);
    border-radius: 14px; overflow: hidden;
    background: color-mix(in srgb, var(--p) 4%, transparent);
}
.sugg-section-head {
    display: flex; align-items: center; gap: 8px;
    padding: 11px 14px;
    background: color-mix(in srgb, var(--p) 8%, transparent);
    border-bottom: 1px solid color-mix(in srgb, var(--p) 15%, transparent);
}
.sugg-section-head svg { width: 14px; height: 14px; color: var(--p); flex-shrink: 0; }
.sugg-section-head span {
    font-size: 12px; font-weight: 700; color: var(--p);
    flex: 1;
}
.sugg-section-head small { font-size: 10px; color: var(--ink2); font-weight: 600; }
.sugg-list { padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; }

/* Each suggestion dish row */
.sugg-dish-row {
    display: flex; align-items: center; gap: 9px;
    padding: 8px 10px; border-radius: 10px;
    border: 1.5px solid var(--line); background: var(--bg2);
    cursor: pointer; transition: all 0.2s; position: relative;
}
.sugg-dish-row:hover { border-color: var(--p); background: color-mix(in srgb, var(--p) 5%, transparent); }
.sugg-dish-row.selected {
    border-color: var(--p);
    background: color-mix(in srgb, var(--p) 8%, transparent);
}
.sugg-dish-img {
    width: 34px; height: 34px; border-radius: 9px;
    flex-shrink: 0; overflow: hidden; background: var(--bg3);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; border: 1px solid var(--line);
}
.sugg-dish-img img { width:100%;height:100%;object-fit:cover; }
.sugg-dish-name { flex: 1; font-size: 12px; font-weight: 700; color: var(--ink); }
.sugg-dish-price { font-size: 11px; font-weight: 800; color: var(--p); }
.sugg-check {
    width: 18px; height: 18px; border-radius: 5px; flex-shrink: 0;
    border: 2px solid var(--line); background: var(--bg3);
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s;
}
.sugg-check svg { width: 10px; height: 10px; color: #fff; opacity: 0; transition: opacity 0.2s; }
.sugg-dish-row.selected .sugg-check {
    background: var(--p); border-color: var(--p);
}
.sugg-dish-row.selected .sugg-check svg { opacity: 1; }

/* Hidden checkbox inputs */
.sugg-hidden { display: none; }

.sugg-limit-note {
    font-size: 10px; color: var(--ink2); font-weight: 600;
    text-align: center; padding: 6px 0 4px;
}

/* ===== MODAL FORM EXTRAS ===== */
.check-wrap {
    display:flex;align-items:center;gap:10px;
    padding:11px 14px;background:var(--bg3);
    border-radius:12px;border:1.5px solid var(--line);
    cursor:pointer;transition:border-color 0.2s;
}
.check-wrap:hover { border-color:var(--p); }
.check-wrap input[type=checkbox] { width:17px;height:17px;accent-color:var(--p);cursor:pointer;flex-shrink:0; }
.check-wrap span { font-size:13px;font-weight:600;color:var(--ink); }
.img-preview-wrap { display:none;margin-bottom:9px; }
.img-preview-wrap img { width:74px;height:74px;border-radius:12px;object-fit:cover;border:1.5px solid var(--line); }
.file-hint { font-size:11px;color:var(--ink2);margin-top:5px;font-weight:500; }

/* ===== OPTIONS BUILDER — redesigned ===== */
.opts-section{border:1.5px solid color-mix(in srgb,#22C55E 22%,transparent);border-radius:16px;overflow:hidden;background:color-mix(in srgb,#22C55E 3%,transparent);}
.opts-head{display:flex;align-items:center;gap:10px;padding:13px 16px;background:color-mix(in srgb,#22C55E 7%,transparent);border-bottom:1px solid color-mix(in srgb,#22C55E 15%,transparent);}
.opts-head-icon{width:28px;height:28px;border-radius:8px;background:rgba(34,197,94,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.opts-head-icon svg{width:14px;height:14px;color:#22C55E;}
.opts-head-title{font-size:13px;font-weight:700;color:#22C55E;flex:1;}
.add-group-btn{display:flex;align-items:center;gap:5px;padding:7px 14px;background:#22C55E;border:none;border-radius:10px;color:#fff;font-size:12px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;transition:opacity .2s;}
.add-group-btn:hover{opacity:.85;}
.add-group-btn svg{width:13px;height:13px;}
.opts-body{padding:12px 14px;display:flex;flex-direction:column;gap:12px;}
.opts-empty{text-align:center;padding:20px;color:var(--ink2);font-size:12px;font-weight:600;}

/* Group card */
.opt-group-card{background:var(--bg2);border:1.5px solid var(--line);border-radius:14px;overflow:hidden;transition:border-color .2s;}
.opt-group-card:hover{border-color:color-mix(in srgb,#22C55E 30%,transparent);}

.opt-group-header{display:flex;align-items:center;gap:8px;padding:11px 13px;background:var(--bg3);border-bottom:1px solid var(--line);}
.opt-group-title-badge{font-size:10px;font-weight:800;color:#22C55E;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);padding:3px 9px;border-radius:20px;white-space:nowrap;}
.opt-del-group{width:28px;height:28px;border-radius:8px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.15);color:#EF4444;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s;}
.opt-del-group:hover{background:#EF4444;color:#fff;}
.opt-del-group svg{width:12px;height:12px;}

.opt-group-fields{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 13px;border-bottom:1px solid var(--line);}
.opt-field-wrap{display:flex;flex-direction:column;gap:4px;}
.opt-field-label{font-size:10px;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.5px;}
.opt-field-input{padding:8px 11px;background:var(--bg3);border:1.5px solid var(--line);border-radius:10px;font-size:13px;font-weight:600;color:var(--ink);font-family:'Tajawal',sans-serif;outline:none;width:100%;}
.opt-field-input:focus{border-color:#22C55E;background:var(--bg2);}
.opt-field-select{padding:8px 11px;background:var(--bg3);border:1.5px solid var(--line);border-radius:10px;font-size:12px;font-weight:700;color:var(--ink);font-family:'Tajawal',sans-serif;outline:none;cursor:pointer;width:100%;}
.opt-field-select:focus{border-color:#22C55E;}
.opt-req-wrap{display:flex;align-items:center;gap:8px;padding:10px 13px;font-size:12px;font-weight:600;color:var(--ink2);background:var(--bg3);border-bottom:1px solid var(--line);cursor:pointer;}
.opt-req-wrap input[type=checkbox]{width:16px;height:16px;accent-color:#22C55E;cursor:pointer;}
.opt-req-wrap:hover{background:var(--bg4);}

/* Values */
.opt-values-section{padding:10px 13px 12px;}
.opt-values-title{font-size:10px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;}
.opt-val-rows{display:flex;flex-direction:column;gap:6px;margin-bottom:8px;}
.opt-val-row{display:grid;grid-template-columns:1fr 1fr 70px 28px;gap:6px;align-items:center;}
.opt-val-input{padding:8px 10px;background:var(--bg3);border:1.5px solid var(--line);border-radius:9px;font-size:12px;font-weight:600;color:var(--ink);font-family:'Tajawal',sans-serif;outline:none;width:100%;}
.opt-val-input:focus{border-color:#22C55E;background:var(--bg2);}
.opt-val-price{padding:8px 8px;background:var(--bg3);border:1.5px solid var(--line);border-radius:9px;font-size:12px;font-weight:700;color:var(--p);font-family:'Tajawal',sans-serif;outline:none;width:100%;text-align:center;}
.opt-val-price:focus{border-color:var(--p);background:var(--bg2);}
.opt-del-val{width:28px;height:28px;border-radius:8px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.12);color:#EF4444;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s;}
.opt-del-val:hover{background:#EF4444;color:#fff;}
.opt-del-val svg{width:11px;height:11px;}
.opt-val-headers{display:grid;grid-template-columns:1fr 1fr 70px 28px;gap:6px;margin-bottom:4px;}
.opt-val-header{font-size:10px;font-weight:700;color:var(--ink2);}
.add-val-btn{width:100%;padding:8px;background:transparent;border:1.5px dashed var(--line);border-radius:9px;color:var(--ink2);font-size:12px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:5px;}
.add-val-btn:hover{border-color:#22C55E;color:#22C55E;}
.add-val-btn svg{width:12px;height:12px;}

/* Save btn */
.opts-save-wrap{padding:0 14px 14px;}
.opt-save-btn{width:100%;padding:10px;background:rgba(34,197,94,.1);border:1.5px solid rgba(34,197,94,.25);border-radius:11px;color:#22C55E;font-size:13px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:7px;}
.opt-save-btn:hover{background:#22C55E;color:#fff;border-color:transparent;}
.opt-save-btn svg{width:14px;height:14px;}
.opt-save-btn.saving{opacity:.6;pointer-events:none;}
.opts-empty{text-align:center;padding:16px;color:var(--ink2);font-size:12px;font-weight:600;}

/* ===== TRANSLATION ===== */
.translate-btn {
    display:flex;align-items:center;gap:7px;
    padding:9px 16px;border-radius:11px;
    background:rgba(59,130,246,0.08);border:1.5px solid rgba(59,130,246,0.2);
    color:#3B82F6;font-size:12px;font-weight:800;
    cursor:pointer;font-family:'Tajawal',sans-serif;
    transition:all 0.2s;width:100%;justify-content:center;
    margin-bottom:10px;
}
.translate-btn:hover{background:#3B82F6;color:#fff;border-color:transparent;}
.translate-btn svg{width:14px;height:14px;}
.translate-btn.loading{opacity:0.6;pointer-events:none;}
.en-fields{
    background:rgba(59,130,246,0.04);border:1.5px solid rgba(59,130,246,0.12);
    border-radius:14px;padding:14px;margin-top:4px;
}
.en-fields-head{
    display:flex;align-items:center;gap:6px;
    font-size:11px;font-weight:800;color:#3B82F6;
    margin-bottom:10px;
}
.en-fields-head svg{width:12px;height:12px;}

/* ===== DISH LIBRARY MODAL ===== */
.library-modal-bg {
    position:fixed;inset:0;z-index:1100;
    background:rgba(0,0,0,.7);backdrop-filter:blur(8px);
    display:flex;align-items:center;justify-content:center;
    opacity:0;pointer-events:none;transition:opacity .3s;
    padding:16px;
}
.library-modal-bg.open{opacity:1;pointer-events:all;}
.library-modal {
    background:var(--bg2);border-radius:22px;
    width:100%;max-width:720px;max-height:85vh;
    display:flex;flex-direction:column;overflow:hidden;
    border:1px solid var(--line);
    transform:translateY(20px);transition:transform .3s cubic-bezier(.22,1,.36,1);
}
.library-modal-bg.open .library-modal{transform:translateY(0);}
.library-head{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid var(--line);}
.library-head h3{font-family:'Fraunces',serif;font-size:16px;font-weight:700;color:var(--ink);flex:1;}
.library-search-row{display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--line);}
.library-search-input{flex:1;padding:10px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;font-size:13px;font-family:'Tajawal',sans-serif;color:var(--ink);outline:none;}
.library-search-input:focus{border-color:var(--p);}
.library-search-btn{padding:10px 18px;background:var(--p);color:#fff;border:none;border-radius:12px;font-size:13px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;white-space:nowrap;}
.library-cats{display:flex;gap:6px;padding:10px 16px;overflow-x:auto;scrollbar-width:none;border-bottom:1px solid var(--line);}
.library-cats::-webkit-scrollbar{display:none;}
.lib-cat-btn{padding:5px 13px;border-radius:20px;border:1.5px solid var(--line);background:var(--bg3);font-size:11px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;color:var(--ink2);white-space:nowrap;transition:all .2s;}
.lib-cat-btn.active{background:var(--p);border-color:transparent;color:#fff;}
.library-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;padding:14px 16px;overflow-y:auto;flex:1;}
.lib-photo-card{border-radius:14px;overflow:hidden;border:2px solid transparent;cursor:pointer;transition:all .2s;position:relative;aspect-ratio:4/3;}
.lib-photo-card img{width:100%;height:100%;object-fit:cover;display:block;}
.lib-photo-card:hover{border-color:var(--p);transform:scale(1.02);}
.lib-photo-card.selected{border-color:var(--p);}
.lib-photo-check{position:absolute;top:6px;right:6px;width:22px;height:22px;border-radius:50%;background:var(--p);display:none;align-items:center;justify-content:center;font-size:12px;color:#fff;}
.lib-photo-card.selected .lib-photo-check{display:flex;}
.lib-photo-name{position:absolute;bottom:0;left:0;right:0;padding:6px 8px;background:linear-gradient(to top,rgba(0,0,0,.7),transparent);color:#fff;font-size:10px;font-weight:700;}
.library-footer{padding:12px 16px;border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px;}
.library-loading{display:flex;align-items:center;justify-content:center;padding:40px;color:var(--ink2);font-size:13px;font-weight:600;gap:10px;}
.lib-spinner{width:20px;height:20px;border:2px solid var(--line);border-top-color:var(--p);border-radius:50%;animation:spin .8s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
.lib-select-btn{padding:10px 20px;background:var(--p);color:#fff;border:none;border-radius:12px;font-size:13px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;transition:opacity .2s;}
.lib-select-btn:disabled{opacity:.4;cursor:not-allowed;}
.lib-page-btn{padding:8px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;color:var(--ink2);}
.lib-page-btn:disabled{opacity:.4;cursor:not-allowed;}
.lib-empty{text-align:center;padding:40px;color:var(--ink2);font-size:13px;font-weight:600;}
</style>

<div class="main">

    <div class="dishes-toolbar">
        <div>
            <div class="page-title">الأطباق</div>
            <div class="page-subtitle"><?= count($dishes) ?> طبق مسجل</div>
        </div>
        <button class="btn btn-primary" onclick="openAddModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            إضافة طبق
        </button>
    </div>

    <?php if($success): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= $success ?>
    </div>
    <?php endif; ?>

<?php
// شريط إشعار sold_out reset اليومي — branch-aware
if ($active_branch_id) {
    $sold_out_notice = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM branch_dish_overrides bdo
        JOIN dishes d ON d.id = bdo.dish_id
        WHERE bdo.branch_id = ? AND d.restaurant_id = ? AND bdo.sold_out = 1
    ");
    $sold_out_notice->execute([$active_branch_id, $rid]);
} else {
    $sold_out_notice = $pdo->prepare("SELECT COUNT(*) as cnt FROM dishes WHERE restaurant_id=? AND sold_out=1");
    $sold_out_notice->execute([$rid]);
}
$sold_out_count = $sold_out_notice->fetchColumn();
// تحقق إذا اليوم الحالي != آخر تاريخ reset (نستخدم session)
$today = date('Y-m-d');
$last_reset = $_SESSION['last_soldout_reset'] ?? '';
if($last_reset !== $today && $sold_out_count > 0) {
    // أظهر الإشعار
    $show_reset_notice = true;
}
?>
<?php if(isset($show_reset_notice)): ?>
<div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.25);border-radius:14px;padding:12px 18px;margin-bottom:14px;display:flex;align-items:center;gap:10px;">
    <span style="font-size:18px;">⚠️</span>
    <div style="flex:1;">
        <div style="font-size:13px;font-weight:700;color:#F59E0B;"><?= $sold_out_count ?> طبق مميّز كـ "نفذ اليوم"</div>
        <div style="font-size:11px;color:var(--ink2);margin-top:2px;">راجع قائمة الأطباق وتحقق من التوفر اليومي</div>
    </div>
    <form method="POST" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_soldout">
        <button type="submit" style="padding:7px 14px;background:rgba(245,158,11,0.15);border:1px solid rgba(245,158,11,0.3);border-radius:9px;color:#F59E0B;font-size:12px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;">إعادة تفعيل الكل</button>
    </form>
</div>
<?php endif; ?>

    <!-- Category Filter -->
    <div class="cat-tabs">
        <a href="dishes.php" class="cat-tab <?= !$filter_cat?'active':'' ?>">الكل (<?= count($dishes) ?>)</a>
        <?php foreach($cats as $cat): ?>
        <a href="dishes.php?cat=<?= $cat['id'] ?>" class="cat-tab <?= $filter_cat==$cat['id']?'active':'' ?>">
            <?= htmlspecialchars($cat['name']) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Grid -->
    <?php if(empty($dishes)): ?>
    <div class="dishes-empty">
        <div class="dishes-empty-icon">🍔</div>
        <p>ما في أطباق بعد — أضف أول طبق!</p>
    </div>
    <?php else: ?>
    <div class="dishes-grid">
        <?php foreach($dishes as $idx => $d): ?>
        <div class="dish-card <?= !$d['is_available']?'unavailable':'' ?>" style="animation-delay:<?= min($idx*0.05,0.4) ?>s">
            <div class="dish-img-wrap">
                <?php if($d['image']): ?>
                    <img src="<?= BASE_URL ?>/assets/uploads/dishes/<?= $d['image'] ?>" loading="lazy">
                <?php else: ?>
                    <div class="dish-img-placeholder">🍔</div>
                <?php endif; ?>
                <div class="dish-overlay-badges">
                    <?php if($d['has_model3d']): ?><span class="dob dob-ar">AR 3D</span><?php endif; ?>
                    <?php if(!$d['is_available']): ?><span class="dob dob-unavail">غير متاح</span><?php endif; ?>
                    <?php if(!empty($d['sold_out'])): ?><span class="dob dob-soldout">نفذ اليوم</span><?php endif; ?>
                </div>
                <?php if(!empty($d['discount_active']) && $d['discount_percent'] > 0): ?>
                <div class="discount-badge-card">-<?= round($d['discount_percent']) ?>%</div>
                <?php endif; ?>
                <?php if($d['cat_name']): ?>
                    <div class="dish-cat-chip"><?= htmlspecialchars($d['cat_name']) ?></div>
                <?php endif; ?>
                <?php
                $sugg_count = isset($sugg_map[$d['id']]) ? count($sugg_map[$d['id']]) : 0;
                if($sugg_count > 0):
                ?>
                <div class="dish-sugg-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    <?= $sugg_count ?> اقتراح
                </div>
                <?php endif; ?>
            </div>
            <div class="dish-body">
                <div class="dish-top">
                    <div class="dish-name"><?= htmlspecialchars($d['name']) ?></div>
                    <div class="dish-price-wrap">
                        <?php
                        $disc_p = floatval($d['discount_percent'] ?? 0);
                        $disc_a = !empty($d['discount_active']);
                        $final_p = ($disc_a && $disc_p > 0) ? $d['price'] * (1 - $disc_p/100) : $d['price'];
                        ?>
                        <?php if($disc_a && $disc_p > 0): ?>
                        <div class="dish-price-old">$<?= number_format($d['price'],2) ?></div>
                        <?php endif; ?>
                        <div class="dish-price">$<?= number_format($final_p,2) ?></div>
                    </div>
                </div>
                <?php if($d['description']): ?>
                    <div class="dish-desc"><?= htmlspecialchars($d['description']) ?></div>
                <?php endif; ?>
                <?php if(!empty($d['prep_time'])): ?>
                <div style="display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;color:#F59E0B;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);padding:2px 8px;border-radius:8px;margin-top:4px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:10px;height:10px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?= $d['prep_time'] ?> دقيقة
                </div>
                <?php endif; ?>
                <div class="dish-actions">
                    <form method="POST" style="flex:1.2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action"  value="toggle">
                        <input type="hidden" name="dish_id" value="<?= $d['id'] ?>">
                        <button type="submit" style="width:100%"
                                class="da-btn <?= $d['is_available']?'da-avail':'da-unavail' ?>">
                            <?php if($d['is_available']): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> متاح
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> مخفي
                            <?php endif; ?>
                        </button>
                    </form>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action"  value="toggle_soldout">
                        <input type="hidden" name="dish_id" value="<?= $d['id'] ?>">
                        <button type="submit"
                                class="da-btn <?= !empty($d['sold_out'])?'da-soldout-active':'da-soldout' ?>"
                                title="نفذ اليوم">
                            <?= !empty($d['sold_out'])
                                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>'
                                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                            ?>
                        </button>
                    </form>
                    <button class="da-btn da-edit" onclick='openEditModal(<?= json_encode($d) ?>, <?= json_encode($sugg_map[$d["id"]] ?? []) ?>, <?= json_encode($options_map[$d["id"]] ?? []) ?>)'>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        تعديل
                    </button>
                    <button class="da-btn da-delete"
                            onclick="confirmDelete(<?= $d['id'] ?>,'<?= htmlspecialchars($d['name'],ENT_QUOTES) ?>')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Add/Edit Modal -->
<div class="modal-bg" id="dishModal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h3 id="modalTitle">إضافة طبق</h3>
            <button class="modal-close" onclick="closeModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action"    id="formAction" value="add">
            <input type="hidden" name="dish_id"   id="dishId">
            <input type="hidden" name="old_image" id="oldImage">
            <input type="hidden" name="old_model" id="oldModel">
            <input type="hidden" name="old_usdz"  id="oldUsdz">

            <div class="form-row-2">
                <div class="form-group">
                    <label>اسم الطبق *</label>
                    <input type="text" name="name" id="dishName" required>
                </div>
                <div class="form-group">
                    <label>السعر *</label>
                    <input type="number" name="price" id="dishPrice" step="0.01" min="0" required>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>التصنيف *</label>
                    <select name="category_id" id="dishCat" required>
                        <?php foreach($cats as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>الترتيب</label>
                    <input type="number" name="sort_order" id="dishSort" value="0" min="0">
                </div>
            </div>
            <!-- وقت التحضير -->
            <div class="form-group">
                <div style="background:rgba(245,158,11,0.04);border:1.5px solid rgba(245,158,11,0.18);border-radius:14px;padding:14px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:11px;font-weight:800;color:#F59E0B;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:13px;height:13px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        وقت التحضير
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="number" name="prep_time" id="dishPrepTime"
                               min="1" max="120" value="15"
                               style="flex:1;padding:10px 12px;background:var(--bg3);border:1.5px solid var(--line);border-radius:10px;font-size:16px;font-weight:800;color:#F59E0B;font-family:'Fraunces',sans-serif;outline:none;text-align:center;"
                               oninput="updatePrepPreview()">
                        <div style="font-size:13px;font-weight:700;color:var(--ink2);">دقيقة</div>
                        <div id="prepPreview" style="font-size:11px;font-weight:700;color:var(--ink2);background:var(--bg3);border:1px solid var(--line);padding:6px 12px;border-radius:9px;white-space:nowrap;">
                            ~15 دقيقة
                        </div>
                    </div>
                    <div style="font-size:10px;color:var(--ink2);font-weight:500;margin-top:8px;">
                        ⚡ يُستخدم لحساب الوقت المتوقع للزبون بعد الطلب
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>الوصف</label>
                <textarea name="description" id="dishDesc" placeholder="وصف مختصر للطبق..."></textarea>
            </div>

            <div class="form-group">
                <label>المكونات (مفصولة بفاصلة)</label>
                <input type="text" name="ingredients" id="dishIngredients" placeholder="لحم، خضار، جبنة...">
            </div>
            <div class="form-group">
                <label>طريقة التحضير (اختياري)</label>
                <textarea name="recipe" id="dishRecipe" placeholder="خطوات التحضير..."></textarea>
            </div>

            <!-- حقول الإنجليزي -->
            <div class="en-fields">
                <div class="en-fields-head">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20"/></svg>
                    النص بالإنجليزي (للزبائن الأجانب)
                </div>
                <button type="button" class="translate-btn" id="translateBtn" onclick="autoTranslate()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 8l6 6M4 14l6-6 2-2M2 5h12M7 2h1M22 22l-5-10-5 10M14 18h6"/></svg>
                    ترجمة تلقائية عبر AI
                </button>
                <div class="form-group" style="margin-bottom:10px;">
                    <label>Dish Name (English)</label>
                    <input type="text" name="name_en" id="dishNameEn" placeholder="e.g. Grilled Chicken">
                </div>
                <div class="form-group" style="margin-bottom:10px;">
                    <label>Description (English)</label>
                    <textarea name="description_en" id="dishDescEn" placeholder="e.g. Tender grilled chicken with aromatic spices..."></textarea>
                </div>
                <div class="form-group" style="margin-bottom:10px;">
                    <label>Ingredients (English)</label>
                    <input type="text" name="ingredients_en" id="dishIngredientsEn" placeholder="e.g. Chicken, spices, vegetables...">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Preparation (English)</label>
                    <textarea name="recipe_en" id="dishRecipeEn" placeholder="e.g. Grill the chicken for 15 minutes..."></textarea>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>صورة الطبق</label>
                    <div class="img-preview-wrap" id="currentImgWrap">
                        <img id="currentImg" src="">
                    </div>
                    <input type="file" name="image" id="dishImageFile" accept="image/*">
                    <button type="button" onclick="openLibrary()"
                            style="width:100%;margin-top:8px;padding:9px 14px;background:rgba(59,130,246,0.08);border:1.5px solid rgba(59,130,246,0.2);border-radius:11px;color:#3B82F6;font-size:12px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;display:flex;align-items:center;justify-content:center;gap:7px;transition:all .2s;"
                            onmouseover="this.style.background='#3B82F6';this.style.color='#fff'"
                            onmouseout="this.style.background='rgba(59,130,246,0.08)';this.style.color='#3B82F6'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:13px;height:13px"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        اختر من مكتبة الصور
                    </button>
                    <input type="hidden" name="library_image" id="libraryImageFilename">
                </div>
                <?php if(plan_has_feature('ar')): ?>
                <div class="form-group">
                    <label>AR — Android (.glb)</label>
                    <div id="currentGlbWrap" style="display:none;margin-bottom:6px;padding:7px 11px;background:var(--bg3);border-radius:10px;border:1px solid var(--line);font-size:11px;font-weight:600;color:var(--ink2);display:flex;align-items:center;gap:7px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px;flex-shrink:0;color:#22C55E"><polyline points="20 6 9 17 4 12"/></svg>
                        <span id="currentGlbName">ملف موجود</span>
                    </div>
                    <input type="file" name="model3d" accept=".glb">
                    <p class="file-hint">للأجهزة التي تعمل بنظام Android</p>
                </div>
                <?php endif; ?>
            </div>
            <?php if(plan_has_feature('ar')): ?>
            <div class="form-group">
                <label>AR — iPhone (.usdz)</label>
                <div id="currentUsdzWrap" style="display:none;margin-bottom:6px;padding:7px 11px;background:var(--bg3);border-radius:10px;border:1px solid var(--line);font-size:11px;font-weight:600;color:var(--ink2);display:flex;align-items:center;gap:7px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px;flex-shrink:0;color:#22C55E"><polyline points="20 6 9 17 4 12"/></svg>
                    <span id="currentUsdzName">ملف موجود</span>
                </div>
                <input type="file" name="model3d_usdz" accept=".usdz">
                <p class="file-hint">للأجهزة التي تعمل بنظام iOS</p>
            </div>
            <?php else: ?>
            <div style="background:rgba(59,130,246,0.06);border:1px solid rgba(59,130,246,0.15);border-radius:12px;padding:11px 14px;font-size:12px;color:#3B82F6;display:flex;align-items:center;gap:8px;">
                🥽 ميزة AR متاحة في باقة <strong>المتقدمة</strong> وما فوق
            </div>
            <?php endif; ?>
            <!-- ===== DISCOUNT SECTION ===== -->
            <div class="form-group">
                <div style="background:rgba(239,68,68,0.04);border:1.5px solid rgba(239,68,68,0.15);border-radius:14px;padding:14px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:11px;font-weight:800;color:#EF4444;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:13px;height:13px"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                        الخصم
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                        <div>
                            <label style="font-size:11px;font-weight:700;color:var(--ink2);display:block;margin-bottom:5px;">نسبة الخصم %</label>
                            <input type="number" name="discount_percent" id="discountPercent"
                                   min="0" max="100" step="0.1" value="0"
                                   style="width:100%;padding:10px 12px;background:var(--bg3);border:1.5px solid var(--line);border-radius:10px;font-size:14px;font-weight:700;color:var(--p);font-family:'Tajawal',sans-serif;outline:none;"
                                   oninput="updateDiscountPreview()">
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:700;color:var(--ink2);display:block;margin-bottom:5px;">السعر بعد الخصم</label>
                            <div id="discountPreview" style="padding:10px 12px;background:var(--bg3);border:1.5px solid var(--line);border-radius:10px;font-size:14px;font-weight:700;color:var(--p);font-family:'Fraunces',sans-serif;">
                                —
                            </div>
                        </div>
                    </div>
                    <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--bg3);border-radius:10px;border:1.5px solid var(--line);cursor:pointer;">
                        <input type="checkbox" name="discount_active" id="discountActive" style="width:16px;height:16px;accent-color:#EF4444;cursor:pointer;">
                        <span style="font-size:12px;font-weight:600;color:var(--ink);">تفعيل الخصم وإظهاره للزبائن</span>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="check-wrap">
                    <input type="checkbox" name="is_available" id="isAvailable" checked>
                    <span>الطبق متاح للطلب</span>
                </label>
            </div>

            <!-- ===== SUGGESTIONS SECTION ===== -->
            <div class="form-group">
                <div class="sugg-section">
                    <div class="sugg-section-head">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                        <span>اقتراحات ذكية مع هذا الطبق</span>
                        <small>اختر 1-2 طبق</small>
                    </div>
                    <div class="sugg-list" id="suggList">
                        <!-- تتملأ بـ JS -->
                    </div>
                    <div class="sugg-limit-note" id="suggNote">
                        اختر حتى طبقين — رح يظهروا للزبون وقت يفتح السلة
                    </div>
                    <!-- Hidden inputs تتضاف بـ JS -->
                    <div id="suggHiddenInputs"></div>
                </div>
            </div>


            <!-- ===== OPTIONS BUILDER ===== -->
            <div class="form-group" id="optionsSection" style="display:none;">
                <div class="opts-section">
                    <div class="opts-head">
                        <div class="opts-head-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>
                        </div>
                        <span class="opts-head-title">خيارات الطبق</span>
                        <button type="button" class="add-group-btn" onclick="addOptGroup()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            مجموعة جديدة
                        </button>
                    </div>
                    <div class="opts-body" id="optGroupsContainer">
                        <div class="opts-empty" id="optsEmpty">
                            <div style="font-size:28px;margin-bottom:8px;">🍕</div>
                            ما في خيارات بعد<br>
                            <span style="font-size:11px;">مثال: القياس، الإضافات، النكهة...</span>
                        </div>
                    </div>
                    <div class="opts-save-wrap" id="optsSaveWrap" style="display:none;">
                        <button type="button" class="opt-save-btn" id="optSaveBtn" onclick="saveOptions()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            حفظ الخيارات
                        </button>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" style="flex:1" onclick="closeModal()">إلغاء</button>
                <button type="submit" class="btn btn-primary" style="flex:2">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    حفظ الطبق
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Library Modal -->
<div class="library-modal-bg" id="libraryModal">
    <div class="library-modal">
        <div class="library-head">
            <h3>🖼️ مكتبة صور الأطباق</h3>
            <button onclick="closeLibrary()" style="width:32px;height:32px;border-radius:50%;background:var(--bg3);border:1px solid var(--line);cursor:pointer;display:flex;align-items:center;justify-content:center;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:13px;height:13px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="library-search-row">
            <input type="text" class="library-search-input" id="libSearchInput"
                   placeholder="ابحث عن صورة... مثال: برغر، بيتزا، سلطة">
            <button class="library-search-btn" onclick="libSearch()">بحث</button>
        </div>
        <div class="library-cats" id="libCats">
            <button class="lib-cat-btn active" onclick="libSetCat('all',this)" data-query="">الكل</button>
            <button class="lib-cat-btn" onclick="libSetCat('main',this)" data-query="main dish food">رئيسية</button>
            <button class="lib-cat-btn" onclick="libSetCat('appetizer',this)" data-query="appetizer salad">مقبلات</button>
            <button class="lib-cat-btn" onclick="libSetCat('dessert',this)" data-query="dessert sweet">حلويات</button>
            <button class="lib-cat-btn" onclick="libSetCat('drink',this)" data-query="drink beverage">مشروبات</button>
            <button class="lib-cat-btn" onclick="libSetCat('side',this)" data-query="side dish">مرافقات</button>
        </div>
        <div class="library-grid" id="libGrid">
            <div class="library-loading"><div class="lib-spinner"></div> جاري التحميل...</div>
        </div>
        <div class="library-footer">
            <div style="display:flex;gap:7px;">
                <button class="lib-page-btn" id="libPrevBtn" onclick="libChangePage(-1)" disabled>◄ السابق</button>
                <span id="libPageNum" style="font-size:12px;font-weight:700;color:var(--ink2);padding:8px 4px;">صفحة 1</span>
                <button class="lib-page-btn" id="libNextBtn" onclick="libChangePage(1)">التالي ►</button>
            </div>
            <button class="lib-select-btn" id="libSelectBtn" onclick="libConfirmSelect()" disabled>
                اختر هذه الصورة
            </button>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal-bg" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <h3>تأكيد الحذف</h3>
            <button class="modal-close" onclick="document.getElementById('deleteModal').classList.remove('open')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <p style="color:var(--ink2);font-size:13px;margin-bottom:20px;line-height:1.8;">
            رح تحذف طبق "<strong id="deleteDishName" style="color:var(--ink)"></strong>".<br>
            هاد الإجراء لا يمكن التراجع عنه.
        </p>
        <form method="POST" style="display:flex;gap:9px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="delete">
            <input type="hidden" name="dish_id" id="deleteDishId">
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
// بيانات كل الأطباق للاقتراحات
const ALL_DISHES = <?= json_encode(array_map(fn($d)=>[
    'id'    => $d['id'],
    'name'  => $d['name'],
    'price' => $d['price'],
    'image' => $d['image'],
], $all_dishes)) ?>;

const BASE_URL = '<?= BASE_URL ?>';
const MAX_SUGG = 2; // الحد الأقصى للاقتراحات

let currentDishId  = null;
let selectedSuggIds = new Set();

/* ===== RENDER SUGGESTION LIST ===== */
function renderSuggList(excludeId, preSelected) {
    selectedSuggIds = new Set(preSelected || []);
    const list = document.getElementById('suggList');
    list.innerHTML = '';

    const available = ALL_DISHES.filter(d => d.id != excludeId);
    if(available.length === 0) {
        list.innerHTML = '<div class="sugg-limit-note" style="padding:12px">ما في أطباق أخرى بعد</div>';
        return;
    }

    available.forEach(d => {
        const isSelected = selectedSuggIds.has(d.id);
        const row = document.createElement('div');
        row.className = 'sugg-dish-row' + (isSelected ? ' selected' : '');
        row.dataset.id = d.id;
        row.innerHTML = `
            <div class="sugg-dish-img">
                ${d.image
                    ? `<img src="${BASE_URL}/assets/uploads/dishes/${d.image}" loading="lazy">`
                    : '🍔'}
            </div>
            <div class="sugg-dish-name">${d.name}</div>
            <div class="sugg-dish-price">$${parseFloat(d.price).toFixed(2)}</div>
            <div class="sugg-check">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
        `;
        row.addEventListener('click', () => toggleSuggRow(row, d.id));
        list.appendChild(row);
    });

    updateSuggHiddenInputs();
}

function toggleSuggRow(row, id) {
    if(row.classList.contains('selected')) {
        row.classList.remove('selected');
        selectedSuggIds.delete(id);
    } else {
        if(selectedSuggIds.size >= MAX_SUGG) {
            // فلاش تحذير
            const note = document.getElementById('suggNote');
            note.style.color = 'var(--p)';
            note.textContent = '⚡ الحد الأقصى طبقين للاقتراح';
            setTimeout(() => {
                note.style.color = '';
                note.textContent = 'اختر حتى طبقين — رح يظهروا للزبون وقت يفتح السلة';
            }, 2000);
            return;
        }
        row.classList.add('selected');
        selectedSuggIds.add(id);
    }
    updateSuggHiddenInputs();
}

function updateSuggHiddenInputs() {
    const wrap = document.getElementById('suggHiddenInputs');
    wrap.innerHTML = '';
    let i = 0;
    selectedSuggIds.forEach(id => {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'suggestions[]';
        inp.value = id;
        wrap.appendChild(inp);
        i++;
    });
    // تحديث الـ note
    const note = document.getElementById('suggNote');
    if(selectedSuggIds.size === 0) {
        note.textContent = 'اختر حتى طبقين — رح يظهروا للزبون وقت يفتح السلة';
    } else {
        note.textContent = `✅ ${selectedSuggIds.size} طبق محدد — رح يظهر للزبون وقت يفتح السلة`;
        note.style.color = 'var(--p)';
    }
}

/* ===== MODAL OPEN/CLOSE ===== */
function openAddModal() {
    currentDishId = null;
    document.getElementById('modalTitle').textContent       = 'إضافة طبق';
    document.getElementById('formAction').value             = 'add';
    document.getElementById('dishId').value                 = '';
    document.getElementById('dishName').value               = '';
    document.getElementById('dishPrice').value              = '';
    document.getElementById('dishDesc').value               = '';
    document.getElementById('dishIngredients').value        = '';
    document.getElementById('dishRecipe').value             = '';
    document.getElementById('dishSort').value               = '0';
    document.getElementById('isAvailable').checked          = true;
    document.getElementById('discountPercent').value         = 0;
    document.getElementById('discountActive').checked        = false;
    document.getElementById('dishPrepTime').value            = 15;
    updateDiscountPreview();
    updatePrepPreview();
    document.getElementById('oldImage').value               = '';
    document.getElementById('oldModel').value               = '';
    document.getElementById('oldUsdz').value                = '';
    const gw = document.getElementById('currentGlbWrap');  if(gw)  gw.style.display  = 'none';
    const uw = document.getElementById('currentUsdzWrap'); if(uw)  uw.style.display  = 'none';
    document.getElementById('dishNameEn').value             = '';
    document.getElementById('dishDescEn').value             = '';
    document.getElementById('dishIngredientsEn').value      = '';
    document.getElementById('dishRecipeEn').value           = '';
    document.getElementById('dishIngredientsEn').value      = '';
    document.getElementById('dishRecipeEn').value           = '';
    document.getElementById('currentImgWrap').style.display = 'none';
    renderSuggList(null, []);
    document.getElementById('dishModal').classList.add('open');
}

// openEditModal defined below with options support
function closeModal() {
    document.getElementById('dishModal').classList.remove('open');
}

function confirmDelete(id, name) {
    document.getElementById('deleteDishId').value         = id;
    document.getElementById('deleteDishName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}


// ===== OPTIONS BUILDER =====
let currentDishIdForOpts = null;
let optGroupCounter = 0;

function openEditModal(d, preSelected, dishOptions) {
    currentDishId = d.id;
    currentDishIdForOpts = d.id;
    document.getElementById('modalTitle').textContent       = 'تعديل طبق';
    document.getElementById('formAction').value             = 'edit';
    document.getElementById('dishId').value                 = d.id;
    document.getElementById('dishName').value               = d.name;
    document.getElementById('dishPrice').value              = d.price;
    document.getElementById('dishDesc').value               = d.description  || '';
    document.getElementById('dishIngredients').value        = d.ingredients  || '';
    document.getElementById('dishRecipe').value             = d.recipe       || '';
    document.getElementById('dishSort').value               = d.sort_order   || 0;
    document.getElementById('isAvailable').checked          = d.is_available == 1;
    document.getElementById('discountPercent').value         = d.discount_percent || 0;
    document.getElementById('discountActive').checked        = d.discount_active == 1;
    document.getElementById('dishPrepTime').value            = d.prep_time || 15;
    updateDiscountPreview();
    updatePrepPreview();
    document.getElementById('oldImage').value               = d.image        || '';
    document.getElementById('oldModel').value               = d.model3d_file || '';
    document.getElementById('oldUsdz').value                = d.model3d_usdz  || '';
    // عرض اسم ملف الـ 3D الحالي
    const glbWrap = document.getElementById('currentGlbWrap');
    if(glbWrap) {
        if(d.model3d_file) {
            glbWrap.style.display = 'flex';
            document.getElementById('currentGlbName').textContent = '📦 ' + d.model3d_file;
        } else { glbWrap.style.display = 'none'; }
    }
    const usdzWrap = document.getElementById('currentUsdzWrap');
    if(usdzWrap) {
        if(d.model3d_usdz) {
            usdzWrap.style.display = 'flex';
            document.getElementById('currentUsdzName').textContent = '📦 ' + d.model3d_usdz;
        } else { usdzWrap.style.display = 'none'; }
    }
    document.getElementById('dishCat').value                = d.category_id;
    document.getElementById('dishNameEn').value             = d.name_en          || '';
    document.getElementById('dishDescEn').value             = d.description_en   || '';
    document.getElementById('dishIngredientsEn').value      = d.ingredients_en   || '';
    document.getElementById('dishRecipeEn').value           = d.recipe_en        || '';
    if(d.image) {
        document.getElementById('currentImg').src               = '<?= BASE_URL ?>/assets/uploads/dishes/' + d.image;
        document.getElementById('currentImgWrap').style.display = 'block';
    } else {
        document.getElementById('currentImgWrap').style.display = 'none';
    }
    renderSuggList(d.id, preSelected || []);
    // خيارات الطبق
    document.getElementById('optionsSection').style.display = 'block';
    loadOptions(dishOptions || []);
    document.getElementById('dishModal').classList.add('open');
}

function loadOptions(groups) {
    const container = document.getElementById('optGroupsContainer');
    const saveWrap  = document.getElementById('optsSaveWrap');
    container.innerHTML = '';
    optGroupCounter = 0;
    if(!groups || groups.length === 0) {
        container.innerHTML = `<div class="opts-empty" id="optsEmpty">
            <div style="font-size:28px;margin-bottom:8px;">🍕</div>
            ما في خيارات بعد<br>
            <span style="font-size:11px;">مثال: القياس، الإضافات، النكهة...</span>
        </div>`;
        saveWrap.style.display = 'none';
        return;
    }
    groups.forEach(g => addOptGroup(g));
    saveWrap.style.display = 'block';
}

function addOptGroup(data) {
    const idx = optGroupCounter++;
    const container = document.getElementById('optGroupsContainer');
    const empty     = document.getElementById('optsEmpty');
    if(empty) empty.remove();
    document.getElementById('optsSaveWrap').style.display = 'block';

    const isReplace = data?.price_mode === 'replace';
    const isReq     = data?.is_required == 1;

    const card = document.createElement('div');
    card.className = 'opt-group-card';
    card.dataset.gidx = idx;

    const valRows = (data?.values || []).map((v, vi) => buildValRow(idx, vi, v)).join('');

    card.innerHTML = `
        <!-- هيدر المجموعة -->
        <div class="opt-group-header">
            <span class="opt-group-title-badge">مجموعة ${idx+1}</span>
            <div style="flex:1;"></div>
            <button type="button" class="opt-del-group" title="حذف المجموعة"
                    onclick="this.closest('.opt-group-card').remove(); checkEmpty();">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/>
                </svg>
            </button>
        </div>

        <!-- حقول المجموعة -->
        <div class="opt-group-fields">
            <div class="opt-field-wrap">
                <div class="opt-field-label">اسم المجموعة (عربي) *</div>
                <input class="opt-field-input" type="text"
                       name="opt_groups[${idx}][name]"
                       placeholder="مثال: القياس، الإضافات..."
                       value="${data?.name || ''}">
            </div>
            <div class="opt-field-wrap">
                <div class="opt-field-label">Group Name (English)</div>
                <input class="opt-field-input" type="text"
                       name="opt_groups[${idx}][name_en]"
                       placeholder="e.g. Size, Extras..."
                       value="${data?.name_en || ''}">
            </div>
            <div class="opt-field-wrap">
                <div class="opt-field-label">نوع السعر</div>
                <select class="opt-field-select" name="opt_groups[${idx}][price_mode]">
                    <option value="replace" ${isReplace?'selected':''}>💰 سعر كامل (يبدّل سعر الطبق)</option>
                    <option value="add"     ${!isReplace?'selected':''}>➕ إضافة على السعر الأساسي</option>
                </select>
            </div>
            <div class="opt-field-wrap" style="justify-content:flex-end;">
                <div class="opt-field-label">الإلزامية</div>
                <label class="opt-req-wrap" style="border-radius:10px;border:1.5px solid var(--line);">
                    <input type="checkbox" name="opt_groups[${idx}][is_required]" value="1" ${isReq?'checked':''}>
                    <span>إلزامي — الزبون لازم يختار</span>
                </label>
            </div>
        </div>

        <!-- الخيارات -->
        <div class="opt-values-section">
            <div class="opt-values-title">
                <span>الخيارات المتاحة</span>
            </div>
            <div class="opt-val-headers">
                <div class="opt-val-header">الاسم (عربي)</div>
                <div class="opt-val-header">Name (EN)</div>
                <div class="opt-val-header" style="text-align:center;">السعر $</div>
                <div></div>
            </div>
            <div class="opt-val-rows" id="vals-${idx}">
                ${valRows}
            </div>
            <button type="button" class="add-val-btn" onclick="addOptValue(${idx})">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                إضافة خيار جديد
            </button>
        </div>
    `;
    container.appendChild(card);
}

function buildValRow(gidx, vidx, data) {
    return `<div class="opt-val-row">
        <input class="opt-val-input" type="text"
               name="opt_groups[${gidx}][values][${vidx}][name]"
               placeholder="مثال: كبير" value="${data?.name||''}">
        <input class="opt-val-input" type="text"
               name="opt_groups[${gidx}][values][${vidx}][name_en]"
               placeholder="e.g. Large" value="${data?.name_en||''}">
        <input class="opt-val-price" type="number" step="0.01" min="0"
               name="opt_groups[${gidx}][values][${vidx}][price]"
               placeholder="0.00" value="${parseFloat(data?.price||0).toFixed(2)}">
        <button type="button" class="opt-del-val" title="حذف"
                onclick="this.closest('.opt-val-row').remove()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>`;
}

function addOptValue(gidx) {
    const container = document.getElementById('vals-'+gidx);
    const vidx = container.querySelectorAll('.opt-val-row').length;
    container.insertAdjacentHTML('beforeend', buildValRow(gidx, vidx, null));
    // focus على أول input
    const inputs = container.lastElementChild.querySelectorAll('input');
    if(inputs[0]) inputs[0].focus();
}

function checkEmpty() {
    const container = document.getElementById('optGroupsContainer');
    const saveWrap  = document.getElementById('optsSaveWrap');
    if(container.querySelectorAll('.opt-group-card').length === 0) {
        container.innerHTML = `<div class="opts-empty" id="optsEmpty">
            <div style="font-size:28px;margin-bottom:8px;">🍕</div>
            ما في خيارات بعد<br>
            <span style="font-size:11px;">مثال: القياس، الإضافات، النكهة...</span>
        </div>`;
        saveWrap.style.display = 'none';
    }
}

function saveOptions() {
    if(!currentDishIdForOpts) return;
    const btn = document.getElementById('optSaveBtn');
    btn.classList.add('saving');
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:14px;height:14px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> جاري الحفظ...';

    const groups = [];
    document.querySelectorAll('.opt-group-card').forEach((card, gi) => {
        const nameInput    = card.querySelector('input[name*="[name]"]:not([name*="[values]"]):not([name*="[name_en]"])');
        const nameEnInput  = card.querySelector('input[name*="[name_en]"]:not([name*="[values]"])');
        const modeSelect   = card.querySelector('select[name*="[price_mode]"]');
        const reqCheck     = card.querySelector('input[type=checkbox]');

        const g = {
            name:        nameInput?.value || '',
            name_en:     nameEnInput?.value || '',
            price_mode:  modeSelect?.value || 'add',
            is_required: reqCheck?.checked ? 1 : 0,
            sort:        gi,
            values:      []
        };
        card.querySelectorAll('.opt-val-row').forEach((row, vi) => {
            const inputs = row.querySelectorAll('input');
            g.values.push({
                name:    inputs[0]?.value || '',
                name_en: inputs[1]?.value || '',
                price:   inputs[2]?.value || 0,
                sort:    vi
            });
        });
        if(g.name) groups.push(g);
    });

    const fd = new FormData();
    fd.append('action', 'save_options');
    fd.append('dish_id', currentDishIdForOpts);
    groups.forEach((g, gi) => {
        fd.append(`opt_groups[${gi}][name]`,       g.name);
        fd.append(`opt_groups[${gi}][name_en]`,    g.name_en);
        fd.append(`opt_groups[${gi}][price_mode]`, g.price_mode);
        if(g.is_required) fd.append(`opt_groups[${gi}][is_required]`, '1');
        fd.append(`opt_groups[${gi}][sort]`, gi);
        g.values.forEach((v, vi) => {
            fd.append(`opt_groups[${gi}][values][${vi}][name]`,    v.name);
            fd.append(`opt_groups[${gi}][values][${vi}][name_en]`, v.name_en);
            fd.append(`opt_groups[${gi}][values][${vi}][price]`,   v.price);
            fd.append(`opt_groups[${gi}][values][${vi}][sort]`,    vi);
        });
    });

    fetch('', {
        method: 'POST',
        headers: {'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''},
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        btn.classList.remove('saving');
        if(d.success) {
            btn.style.background = '#22C55E';
            btn.style.color = '#fff';
            btn.style.borderColor = 'transparent';
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:14px;height:14px"><polyline points="20 6 9 17 4 12"/></svg> ✅ تم حفظ الخيارات بنجاح!';
            setTimeout(() => {
                btn.style = '';
                btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:14px;height:14px"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> حفظ الخيارات';
            }, 3000);
        }
    })
    .catch(() => { btn.classList.remove('saving'); });
}


function updatePrepPreview() {
    const val = parseInt(document.getElementById('dishPrepTime')?.value) || 15;
    const preview = document.getElementById('prepPreview');
    if(!preview) return;
    if(val < 60) {
        preview.textContent = '~' + val + ' دقيقة';
    } else {
        const h = Math.floor(val/60), m = val%60;
        preview.textContent = '~' + h + ' ساعة' + (m ? ' و' + m + ' دقيقة' : '');
    }
}

function updateDiscountPreview() {
    const priceEl = document.getElementById('dishPrice');
    const pctEl   = document.getElementById('discountPercent');
    const preview = document.getElementById('discountPreview');
    if(!priceEl || !pctEl || !preview) return;
    const price = parseFloat(priceEl.value) || 0;
    const pct   = parseFloat(pctEl.value)   || 0;
    if(price > 0 && pct > 0) {
        const discounted = price * (1 - pct / 100);
        preview.textContent = '$' + discounted.toFixed(2);
        preview.style.color = '#EF4444';
    } else {
        preview.textContent = '—';
        preview.style.color = 'var(--p)';
    }
}
// تحديث الـ preview عند تغيير السعر
document.addEventListener('DOMContentLoaded', function() {
    const priceInput = document.getElementById('dishPrice');
    if(priceInput) priceInput.addEventListener('input', updateDiscountPreview);
});

document.querySelectorAll('.modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) {
        if(e.target === bg) bg.classList.remove('open');
    });
});

// ===== DISH LIBRARY =====
const BASE_URL_LIB = '<?= BASE_URL ?>';
let libPage = 1;
let libCurrentQuery = 'food dish';
let libSelectedPhoto = null;

function openLibrary() {
    document.getElementById('libraryModal').classList.add('open');
    libPage = 1;
    libCurrentQuery = 'food dish';
    libLoadPhotos();
}

function closeLibrary() {
    document.getElementById('libraryModal').classList.remove('open');
    libSelectedPhoto = null;
}

function libSearch() {
    const q = document.getElementById('libSearchInput').value.trim();
    if(q) libCurrentQuery = q;
    libPage = 1;
    libLoadPhotos();
}

document.addEventListener('DOMContentLoaded', function() {
    const si = document.getElementById('libSearchInput');
    if(si) si.addEventListener('keydown', e => { if(e.key==='Enter') libSearch(); });
});

function libSetCat(cat, btn) {
    document.querySelectorAll('.lib-cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    libCurrentQuery = btn.dataset.query || 'food dish';
    libPage = 1;
    libLoadPhotos();
}

function libChangePage(delta) {
    libPage = Math.max(1, libPage + delta);
    libLoadPhotos();
}

function libLoadPhotos() {
    const grid = document.getElementById('libGrid');
    grid.innerHTML = '<div class="library-loading"><div class="lib-spinner"></div> جاري التحميل...</div>';
    libSelectedPhoto = null;
    document.getElementById('libSelectBtn').disabled = true;
    document.getElementById('libPageNum').textContent = 'صفحة ' + libPage;
    document.getElementById('libPrevBtn').disabled = libPage <= 1;

    fetch(`${BASE_URL_LIB}/restaurant/dashboard/api/dish_library.php?action=photos&q=${encodeURIComponent(libCurrentQuery)}&page=${libPage}`)
    .then(r => r.json())
    .then(data => {
        if(data.error || !data.photos || data.photos.length === 0) {
            grid.innerHTML = '<div class="lib-empty">😕 ما في نتائج — جرب كلمة ثانية</div>';
            document.getElementById('libNextBtn').disabled = true;
            return;
        }
        document.getElementById('libNextBtn').disabled = data.photos.length < 12;
        grid.innerHTML = '';
        data.photos.forEach(photo => {
            const card = document.createElement('div');
            card.className = 'lib-photo-card';
            card.dataset.regular = photo.regular;
            card.dataset.id = photo.id;
            card.innerHTML = `
                <img src="${photo.thumb}" loading="lazy" alt="${photo.alt||''}">
                <div class="lib-photo-check">✓</div>
                <div class="lib-photo-name">${photo.author || ''}</div>
            `;
            card.addEventListener('click', () => libSelectPhoto(card, photo));
            grid.appendChild(card);
        });
    })
    .catch(() => {
        grid.innerHTML = '<div class="lib-empty">❌ فشل الاتصال — تحقق من الإنترنت</div>';
    });
}

function libSelectPhoto(card, photo) {
    document.querySelectorAll('.lib-photo-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    libSelectedPhoto = photo;
    document.getElementById('libSelectBtn').disabled = false;
}

function libConfirmSelect() {
    if(!libSelectedPhoto) return;
    const btn = document.getElementById('libSelectBtn');
    btn.textContent = 'جاري التحميل...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('url', libSelectedPhoto.regular);

    fetch(`${BASE_URL_LIB}/restaurant/dashboard/api/dish_library.php?action=download`, {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(data => {
        if(data.success && data.filename) {
            // أظهر الصورة في الـ preview
            document.getElementById('currentImg').src = `${BASE_URL_LIB}/assets/uploads/dishes/${data.filename}`;
            document.getElementById('currentImgWrap').style.display = 'block';
            // احفظ اسم الملف في hidden input
            document.getElementById('libraryImageFilename').value = data.filename;
            document.getElementById('oldImage').value = data.filename;
            closeLibrary();
            btn.textContent = '✅ تم — اختر هذه الصورة';
        } else {
            btn.textContent = '❌ فشل التحميل';
            btn.disabled = false;
        }
        setTimeout(() => {
            btn.textContent = 'اختر هذه الصورة';
            btn.disabled = false;
        }, 2000);
    })
    .catch(() => {
        btn.textContent = '❌ خطأ في الاتصال';
        btn.disabled = false;
    });
}

// إغلاق المكتبة بالضغط على الخلفية
document.getElementById('libraryModal').addEventListener('click', function(e) {
    if(e.target === this) closeLibrary();
});

// ===== Auto Translate via MyMemory =====
async function autoTranslate() {
    const name = document.getElementById('dishName').value.trim();
    const desc = document.getElementById('dishDesc').value.trim();

    if(!name) {
        alert('أدخل اسم الطبق أولاً');
        return;
    }

    const btn = document.getElementById('translateBtn');
    btn.classList.add('loading');
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> جاري الترجمة...';

    try {
        // ترجمة الاسم
        const nameRes = await fetch(
            `https://api.mymemory.translated.net/get?q=${encodeURIComponent(name)}&langpair=ar|en`
        );
        const nameData = await nameRes.json();
        const nameEn = nameData.responseData?.translatedText || '';

        document.getElementById('dishNameEn').value = nameEn;

        // ترجمة الوصف
        if(desc) {
            const descRes = await fetch(`https://api.mymemory.translated.net/get?q=${encodeURIComponent(desc)}&langpair=ar|en`);
            const descData = await descRes.json();
            document.getElementById('dishDescEn').value = descData.responseData?.translatedText || '';
        }
        // ترجمة المكونات
        const ingr = document.getElementById('dishIngredients').value.trim();
        if(ingr) {
            const ingrRes = await fetch(`https://api.mymemory.translated.net/get?q=${encodeURIComponent(ingr)}&langpair=ar|en`);
            const ingrData = await ingrRes.json();
            document.getElementById('dishIngredientsEn').value = ingrData.responseData?.translatedText || '';
        }
        // ترجمة طريقة التحضير
        const recipe = document.getElementById('dishRecipe').value.trim();
        if(recipe) {
            const recipeRes = await fetch(`https://api.mymemory.translated.net/get?q=${encodeURIComponent(recipe)}&langpair=ar|en`);
            const recipeData = await recipeRes.json();
            document.getElementById('dishRecipeEn').value = recipeData.responseData?.translatedText || '';
        }

        btn.classList.remove('loading');
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg> ✅ تمت الترجمة — راجعها وعدّل لو لزم';
        btn.style.background = 'rgba(34,197,94,0.1)';
        btn.style.borderColor = 'rgba(34,197,94,0.25)';
        btn.style.color = '#22C55E';
        setTimeout(() => {
            btn.style = '';
            btn.classList.remove('loading');
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 8l6 6M4 14l6-6 2-2M2 5h12M7 2h1M22 22l-5-10-5 10M14 18h6"/></svg> ترجمة تلقائية عبر AI';
        }, 3000);

    } catch(e) {
        btn.classList.remove('loading');
        btn.innerHTML = '❌ فشلت الترجمة — حاول مرة ثانية';
        setTimeout(() => {
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 8l6 6M4 14l6-6 2-2M2 5h12M7 2h1M22 22l-5-10-5 10M14 18h6"/></svg> ترجمة تلقائية عبر AI';
        }, 2500);
    }
}
</script>
</body>
</html>