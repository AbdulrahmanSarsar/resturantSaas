<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/csrf.php';
require_once 'plan_guard.php';
if(!isset($_SESSION['restaurant_id'])) { header('Location: ../login.php'); exit; }
plan_required('advanced'); // العروض متاحة للمتقدمة + الاحترافية
$rid     = $_SESSION['restaurant_id'];
$success = '';
$error   = '';

// ===== POST HANDLER =====
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_require();

    if($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
        $name     = trim($_POST['name'] ?? '');
        $name_en  = trim($_POST['name_en'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $desc_en  = trim($_POST['description_en'] ?? '');
        $type     = in_array($_POST['type']??'',['bxgy','combo']) ? $_POST['type'] : 'bxgy';
        $combo_price = $type==='combo' ? floatval($_POST['combo_price']??0) : null;
        $is_active   = isset($_POST['is_active']) ? 1 : 0;
        $sort        = intval($_POST['sort_order'] ?? 0);

        // رفع صورة
        $image = $_POST['old_image'] ?? '';
        if(!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if(in_array($ext, ['jpg','jpeg','png','webp'])) {
                $upload_dir = rtrim($_SERVER['DOCUMENT_ROOT'],'/').'/assets/uploads/offers/';
                if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                if($image && file_exists($upload_dir.$image)) @unlink($upload_dir.$image);
                $image = uniqid('offer_',true).'.'.$ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir.$image);
            }
        }

        if(!$name) { $error = 'اسم العرض مطلوب.'; }
        else {
            if($_POST['action'] === 'add') {
                $pdo->prepare("INSERT INTO offers_v2 (restaurant_id,name,name_en,description,description_en,type,combo_price,image,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$rid,$name,$name_en,$desc,$desc_en,$type,$combo_price,$image,$is_active,$sort]);
                $offer_id = $pdo->lastInsertId();
                $success = 'تمت إضافة العرض!';
            } else {
                $offer_id = intval($_POST['offer_id']);
                $pdo->prepare("UPDATE offers_v2 SET name=?,name_en=?,description=?,description_en=?,type=?,combo_price=?,image=?,is_active=?,sort_order=? WHERE id=? AND restaurant_id=?")
                    ->execute([$name,$name_en,$desc,$desc_en,$type,$combo_price,$image,$is_active,$sort,$offer_id,$rid]);
                // احذف items القديمة
                $pdo->prepare("DELETE FROM offer_items_v2 WHERE offer_id=?")->execute([$offer_id]);
                $success = 'تم تعديل العرض!';
            }

            // احفظ offer_items
            $items = $_POST['offer_items'] ?? [];
            foreach($items as $itm) {
                $dish_id  = intval($itm['dish_id'] ?? 0);
                $qty      = intval($itm['quantity'] ?? 1);
                $role     = in_array($itm['role']??'',['buy','get','combo']) ? $itm['role'] : 'combo';
                if($dish_id) {
                    $pdo->prepare("INSERT INTO offer_items_v2 (offer_id,dish_id,quantity,role) VALUES (?,?,?,?)")
                        ->execute([$offer_id,$dish_id,$qty,$role]);
                }
            }
        }
    }

    if($_POST['action'] === 'delete') {
        $oid = intval($_POST['offer_id']);
        $pdo->prepare("DELETE FROM offer_items_v2 WHERE offer_id=?")->execute([$oid]);
        $pdo->prepare("DELETE FROM offers_v2 WHERE id=? AND restaurant_id=?")->execute([$oid,$rid]);
        $success = 'تم حذف العرض!';
    }

    if($_POST['action'] === 'toggle') {
        $pdo->prepare("UPDATE offers_v2 SET is_active=NOT is_active WHERE id=? AND restaurant_id=?")
            ->execute([intval($_POST['offer_id']),$rid]);
        $success = 'تم تحديث حالة العرض!';
    }

    $msg = urlencode($success ?: $error);
    header("Location: offers.php" . ($msg ? "?msg=$msg" : ""));
    exit;
}

if(isset($_GET['msg']) && !$success && !$error) {
    $decoded = urldecode($_GET['msg']);
    if(str_contains($decoded,'تم')) $success = $decoded;
    else $error = $decoded;
}

// جلب البيانات (من _v2 — متطابق مع menu/index.php)
$offers = $pdo->prepare("
    SELECT o.*,
        GROUP_CONCAT(
            JSON_OBJECT('id',oi.id,'dish_id',oi.dish_id,'quantity',oi.quantity,'role',oi.role,'dish_name',d.name)
            ORDER BY oi.id
        ) as items_json
    FROM offers_v2 o
    LEFT JOIN offer_items_v2 oi ON oi.offer_id = o.id
    LEFT JOIN dishes_v2 d ON d.id = oi.dish_id
    WHERE o.restaurant_id=?
    GROUP BY o.id
    ORDER BY o.sort_order, o.id DESC
");
$offers->execute([$rid]);
$offers = $offers->fetchAll();
foreach($offers as &$o) {
    $o['items'] = $o['items_json'] ? json_decode('['. $o['items_json'] .']',true) : [];
    unset($o['items_json']);
}
unset($o);

$all_dishes = $pdo->prepare("SELECT id,name,price,image FROM dishes_v2 WHERE restaurant_id=? AND is_active=1 ORDER BY name");
$all_dishes->execute([$rid]);
$all_dishes = $all_dishes->fetchAll();

require_once 'sidebar.php';
?>
<style>
.offers-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;gap:12px;flex-wrap:wrap;}
.offers-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;}

/* Offer Card */
.offer-card{
    background:var(--bg2);border:1px solid var(--line);
    border-radius:18px;overflow:hidden;
    display:flex;flex-direction:column;
    animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;
    transition:transform .2s,border-color .3s;
}
.offer-card:hover{transform:translateY(-2px);}
.offer-card.inactive{opacity:.55;}

.offer-img{
    height:140px;background:var(--bg3);
    display:flex;align-items:center;justify-content:center;
    font-size:48px;position:relative;overflow:hidden;
}
.offer-img img{width:100%;height:100%;object-fit:cover;}

.offer-type-badge{
    position:absolute;top:10px;right:10px;
    padding:4px 10px;border-radius:20px;
    font-size:10px;font-weight:800;backdrop-filter:blur(8px);
}
.type-bxgy{background:rgba(99,102,241,.85);color:#fff;}
.type-combo{background:rgba(245,158,11,.88);color:#fff;}

.offer-status-dot{
    position:absolute;top:10px;left:10px;
    width:8px;height:8px;border-radius:50%;
}
.dot-active{background:#22C55E;box-shadow:0 0 6px #22C55E;animation:pulse 2s infinite;}
.dot-inactive{background:#EF4444;}

.offer-body{padding:14px 15px 12px;flex:1;display:flex;flex-direction:column;}
.offer-name{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:var(--ink);margin-bottom:4px;}
.offer-desc{font-size:12px;color:var(--ink2);line-height:1.5;margin-bottom:10px;flex:1;}

.offer-items-row{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px;}
.offer-item-chip{
    display:inline-flex;align-items:center;gap:4px;
    padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;
}
.chip-buy{background:rgba(59,130,246,.1);color:#3B82F6;border:1px solid rgba(59,130,246,.2);}
.chip-get{background:rgba(34,197,94,.1);color:#22C55E;border:1px solid rgba(34,197,94,.2);}
.chip-combo{background:rgba(245,158,11,.1);color:#F59E0B;border:1px solid rgba(245,158,11,.2);}

.offer-combo-price{
    font-family:'Fraunces',serif;font-size:20px;font-weight:900;color:var(--p);
    margin-bottom:8px;
}

.offer-actions{display:flex;gap:6px;margin-top:auto;}
.oa-btn{
    flex:1;padding:8px 6px;border-radius:10px;
    border:1px solid var(--line);font-size:12px;font-weight:700;
    cursor:pointer;font-family:'Tajawal',sans-serif;
    transition:all .2s;background:var(--bg3);color:var(--ink2);
    display:flex;align-items:center;justify-content:center;gap:4px;
}
.oa-btn svg{width:12px;height:12px;}
.oa-edit:hover{background:color-mix(in srgb,var(--p) 12%,transparent);border-color:color-mix(in srgb,var(--p) 22%,transparent);color:var(--p);}
.oa-toggle:hover{background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.2);color:#22C55E;}
.oa-delete:hover{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:#EF4444;}

/* Type Toggle */
.type-toggle{display:flex;gap:8px;margin-top:4px;}
.type-btn{flex:1;padding:9px;border-radius:11px;border:1.5px solid var(--line);background:var(--bg3);color:var(--ink2);font-size:12px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;text-align:center;}
.type-btn.active{background:var(--p);color:#fff;border-color:transparent;}

/* Offer Items Builder */
.offer-items-builder{border:1.5px solid var(--line);border-radius:14px;overflow:hidden;}
.oib-head{display:flex;align-items:center;justify-content:space-between;padding:10px 13px;background:var(--bg3);border-bottom:1px solid var(--line);}
.oib-title{font-size:12px;font-weight:800;color:var(--ink2);text-transform:uppercase;letter-spacing:.5px;}
.oib-rows{padding:10px;display:flex;flex-direction:column;gap:7px;}
.oib-row{display:grid;grid-template-columns:1fr 60px 80px 28px;gap:6px;align-items:center;}
.oib-select{padding:8px 10px;background:var(--bg3);border:1.5px solid var(--line);border-radius:9px;font-size:12px;font-weight:600;color:var(--ink);font-family:'Tajawal',sans-serif;outline:none;width:100%;}
.oib-select:focus{border-color:var(--p);}
.oib-qty{padding:8px;background:var(--bg3);border:1.5px solid var(--line);border-radius:9px;font-size:12px;font-weight:700;color:var(--ink);font-family:'Tajawal',sans-serif;outline:none;width:100%;text-align:center;}
.oib-role{padding:7px 6px;background:var(--bg3);border:1.5px solid var(--line);border-radius:9px;font-size:11px;font-weight:700;color:var(--ink);font-family:'Tajawal',sans-serif;outline:none;cursor:pointer;width:100%;}
.oib-del{width:28px;height:28px;border-radius:8px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.12);color:#EF4444;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;}
.oib-del:hover{background:#EF4444;color:#fff;}
.oib-del svg{width:11px;height:11px;}
.oib-add-row{width:100%;padding:8px;background:transparent;border:1.5px dashed var(--line);border-radius:9px;color:var(--ink2);font-size:12px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:5px;}
.oib-add-row:hover{border-color:var(--p);color:var(--p);}
.oib-add-row svg{width:12px;height:12px;}
.oib-col-headers{display:grid;grid-template-columns:1fr 60px 80px 28px;gap:6px;padding:0 0 4px;font-size:10px;font-weight:700;color:var(--ink2);}
.combo-price-wrap{margin-top:4px;}
</style>

<div class="main">
<div class="offers-toolbar">
    <div>
        <div class="page-title">العروض</div>
        <div class="page-subtitle"><?= count($offers) ?> عرض مسجل</div>
    </div>
    <button class="btn btn-primary" onclick="openAddOffer()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        إضافة عرض
    </button>
</div>

<?php if($success): ?>
<div class="alert alert-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg><?= $success ?></div>
<?php elseif($error): ?>
<div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<?php if(empty($offers)): ?>
<div class="empty-state" style="background:var(--bg2);border:1px solid var(--line);border-radius:18px;">
    <div class="empty-state-icon">🎁</div>
    <p>ما في عروض بعد — أضف أول عرض!</p>
</div>
<?php else: ?>
<div class="offers-grid">
    <?php foreach($offers as $i => $offer): ?>
    <div class="offer-card <?= !$offer['is_active']?'inactive':'' ?>" style="animation-delay:<?= $i*.05 ?>s">
        <div class="offer-img">
            <?php if($offer['image']): ?>
            <img src="<?= BASE_URL ?>/assets/uploads/offers/<?= $offer['image'] ?>">
            <?php else: ?>
            <?= $offer['type']==='bxgy' ? '🎁' : '🍱' ?>
            <?php endif; ?>
            <span class="offer-type-badge type-<?= $offer['type'] ?>">
                <?= $offer['type']==='bxgy' ? 'اشترِ واحصل' : 'كومبو' ?>
            </span>
            <div class="offer-status-dot <?= $offer['is_active']?'dot-active':'dot-inactive' ?>"></div>
        </div>
        <div class="offer-body">
            <div class="offer-name"><?= htmlspecialchars($offer['name']) ?></div>
            <?php if($offer['description']): ?>
            <div class="offer-desc"><?= htmlspecialchars($offer['description']) ?></div>
            <?php endif; ?>

            <?php if($offer['type']==='combo' && $offer['combo_price']): ?>
            <div class="offer-combo-price">$<?= number_format($offer['combo_price'],2) ?></div>
            <?php endif; ?>

            <div class="offer-items-row">
                <?php foreach($offer['items'] as $itm): ?>
                <span class="offer-item-chip chip-<?= $itm['role'] ?>">
                    <?= $itm['role']==='buy'?'اشترِ':($itm['role']==='get'?'احصل على':'') ?>
                    ×<?= $itm['quantity'] ?> <?= htmlspecialchars($itm['dish_name']??'') ?>
                </span>
                <?php endforeach; ?>
            </div>

            <div class="offer-actions">
                <button class="oa-btn oa-edit" onclick='openEditOffer(<?= json_encode($offer) ?>)'>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    تعديل
                </button>
                <form method="POST" style="flex:1;display:flex;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                    <button type="submit" class="oa-btn oa-toggle" style="flex:1;">
                        <?= $offer['is_active']?'تعطيل':'تفعيل' ?>
                    </button>
                </form>
                <button class="oa-btn oa-delete" onclick="confirmDeleteOffer(<?= $offer['id'] ?>,'<?= htmlspecialchars($offer['name'],ENT_QUOTES) ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- ADD/EDIT MODAL -->
<div class="modal-bg" id="offerModal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h3 id="offerModalTitle">إضافة عرض</h3>
            <button class="modal-close" onclick="closeOfferModal()">✕</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="offerAction" value="add">
            <input type="hidden" name="offer_id" id="offerId">
            <input type="hidden" name="old_image" id="offerOldImage">
            <input type="hidden" name="type" id="offerTypeVal" value="bxgy">

            <div class="form-row-2">
                <div class="form-group">
                    <label>اسم العرض (عربي) *</label>
                    <input type="text" name="name" id="offerName" required placeholder="مثال: اشترِ 2 واحصل على 1">
                </div>
                <div class="form-group">
                    <label>Offer Name (English)</label>
                    <input type="text" name="name_en" id="offerNameEn" placeholder="e.g. Buy 2 Get 1 Free">
                </div>
            </div>
            <div class="form-group">
                <label>نوع العرض</label>
                <div class="type-toggle">
                    <div class="type-btn active" id="typeBtnBxgy" onclick="setOfferType('bxgy')">🎁 اشترِ واحصل مجاناً</div>
                    <div class="type-btn" id="typeBtnCombo" onclick="setOfferType('combo')">🍱 كومبو بسعر واحد</div>
                </div>
            </div>
            <div class="form-group combo-price-wrap" id="comboPriceWrap" style="display:none;">
                <label>سعر الكومبو ($)</label>
                <input type="number" name="combo_price" id="comboPriceInput" step="0.01" min="0" placeholder="0.00">
            </div>
            <div class="form-group">
                <label>الوصف (اختياري)</label>
                <textarea name="description" id="offerDesc" placeholder="وصف مختصر للعرض..."></textarea>
            </div>
            <div class="form-group">
                <label>Description (English)</label>
                <input type="text" name="description_en" id="offerDescEn" placeholder="e.g. Perfect combo for two!">
            </div>
            <div class="form-group">
                <label>صورة العرض (اختياري)</label>
                <input type="file" name="image" accept="image/*">
            </div>

            <!-- OFFER ITEMS BUILDER -->
            <div class="form-group">
                <div class="offer-items-builder">
                    <div class="oib-head">
                        <span class="oib-title" id="oibTitle">أطباق العرض</span>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="addOfferItemRow()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:12px;height:12px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            إضافة طبق
                        </button>
                    </div>
                    <div class="oib-rows" id="oibRows">
                        <div class="oib-col-headers">
                            <div>الطبق</div><div>الكمية</div><div id="roleColHeader">الدور</div><div></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="check-wrap">
                    <input type="checkbox" name="is_active" id="offerIsActive" value="1" checked>
                    <span class="check-label">العرض مفعّل</span>
                </label>
            </div>
            <div class="form-row-2">
                <div class="form-group" style="margin:0">
                    <label>الترتيب</label>
                    <input type="number" name="sort_order" id="offerSort" value="0" min="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" style="flex:1" onclick="closeOfferModal()">إلغاء</button>
                <button type="submit" class="btn btn-primary" style="flex:2">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/></svg>
                    حفظ العرض
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-bg" id="deleteOfferModal">
    <div class="modal">
        <div class="modal-header">
            <h3>تأكيد الحذف</h3>
            <button class="modal-close" onclick="document.getElementById('deleteOfferModal').classList.remove('open')">✕</button>
        </div>
        <p style="color:var(--ink2);font-size:13px;margin-bottom:20px;line-height:1.8;">
            رح تحذف عرض "<strong id="deleteOfferName" style="color:var(--ink)"></strong>". هاد الإجراء لا يمكن التراجع عنه.
        </p>
        <form method="POST" style="display:flex;gap:9px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="offer_id" id="deleteOfferId">
            <button type="button" class="btn btn-secondary" style="flex:1" onclick="document.getElementById('deleteOfferModal').classList.remove('open')">إلغاء</button>
            <button type="submit" class="btn btn-danger" style="flex:1">حذف</button>
        </form>
    </div>
</div>

<script>
const ALL_DISHES = <?= json_encode(array_map(fn($d)=>['id'=>$d['id'],'name'=>$d['name'],'price'=>$d['price']],$all_dishes), JSON_UNESCAPED_UNICODE) ?>;
let currentOfferType = 'bxgy';

function setOfferType(type) {
    currentOfferType = type;
    document.getElementById('offerTypeVal').value = type;
    document.getElementById('typeBtnBxgy').classList.toggle('active', type==='bxgy');
    document.getElementById('typeBtnCombo').classList.toggle('active', type==='combo');
    document.getElementById('comboPriceWrap').style.display = type==='combo' ? 'block' : 'none';
    // تحديث header الـ role column
    document.getElementById('roleColHeader').textContent = type==='bxgy' ? 'الدور' : 'النوع';
    // تحديث rows الموجودة
    document.querySelectorAll('.oib-role').forEach(sel => {
        const val = sel.value;
        sel.innerHTML = buildRoleOptions(type, val);
    });
}

function buildRoleOptions(type, selected) {
    if(type === 'bxgy') {
        return `<option value="buy" ${selected==='buy'?'selected':''}>اشترِ</option>
                <option value="get" ${selected==='get'?'selected':''}>مجاناً</option>`;
    } else {
        return `<option value="combo" ${selected==='combo'||!selected?'selected':''}>ضمن الكومبو</option>`;
    }
}

function addOfferItemRow(data) {
    const rows = document.getElementById('oibRows');
    const idx  = rows.querySelectorAll('.oib-row').length;
    const row  = document.createElement('div');
    row.className = 'oib-row';

    const dishOpts = ALL_DISHES.map(d =>
        `<option value="${d.id}" ${data?.dish_id==d.id?'selected':''}>${d.name} — $${parseFloat(d.price).toFixed(2)}</option>`
    ).join('');

    row.innerHTML = `
        <select class="oib-select" name="offer_items[${idx}][dish_id]">${dishOpts}</select>
        <input class="oib-qty" type="number" name="offer_items[${idx}][quantity]" value="${data?.quantity||1}" min="1" max="99">
        <select class="oib-role" name="offer_items[${idx}][role]">${buildRoleOptions(currentOfferType, data?.role)}</select>
        <button type="button" class="oib-del" onclick="this.closest('.oib-row').remove()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>`;
    rows.appendChild(row);
}

function openAddOffer() {
    document.getElementById('offerModalTitle').textContent = 'إضافة عرض';
    document.getElementById('offerAction').value = 'add';
    document.getElementById('offerId').value = '';
    document.getElementById('offerOldImage').value = '';
    document.getElementById('offerName').value = '';
    document.getElementById('offerNameEn').value = '';
    document.getElementById('offerDesc').value = '';
    document.getElementById('offerDescEn').value = '';
    document.getElementById('comboPriceInput').value = '';
    document.getElementById('offerSort').value = '0';
    document.getElementById('offerIsActive').checked = true;
    setOfferType('bxgy');
    // مسح rows
    const rows = document.getElementById('oibRows');
    rows.querySelectorAll('.oib-row').forEach(r => r.remove());
    addOfferItemRow();
    document.getElementById('offerModal').classList.add('open');
}

function openEditOffer(offer) {
    document.getElementById('offerModalTitle').textContent = 'تعديل عرض';
    document.getElementById('offerAction').value = 'edit';
    document.getElementById('offerId').value = offer.id;
    document.getElementById('offerOldImage').value = offer.image || '';
    document.getElementById('offerName').value = offer.name || '';
    document.getElementById('offerNameEn').value = offer.name_en || '';
    document.getElementById('offerDesc').value = offer.description || '';
    document.getElementById('offerDescEn').value = offer.description_en || '';
    document.getElementById('comboPriceInput').value = offer.combo_price || '';
    document.getElementById('offerSort').value = offer.sort_order || 0;
    document.getElementById('offerIsActive').checked = offer.is_active == 1;
    setOfferType(offer.type || 'bxgy');
    // rows
    const rows = document.getElementById('oibRows');
    rows.querySelectorAll('.oib-row').forEach(r => r.remove());
    const items = offer.items || [];
    if(items.length === 0) { addOfferItemRow(); }
    else { items.forEach(itm => addOfferItemRow(itm)); }
    document.getElementById('offerModal').classList.add('open');
}

function closeOfferModal() {
    document.getElementById('offerModal').classList.remove('open');
}

function confirmDeleteOffer(id, name) {
    document.getElementById('deleteOfferId').value = id;
    document.getElementById('deleteOfferName').textContent = name;
    document.getElementById('deleteOfferModal').classList.add('open');
}

document.querySelectorAll('.modal-bg').forEach(bg => {
    bg.addEventListener('click', e => { if(e.target===bg) bg.classList.remove('open'); });
});
</script>
</body>
</html>