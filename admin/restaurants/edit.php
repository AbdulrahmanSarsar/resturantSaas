<?php
session_start();
require_once '../../config/database.php';
if(!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

$id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id=?");
$stmt->execute([$id]);
$r = $stmt->fetch();
if(!$r) { header('Location: index.php'); exit; }

$success = ''; $error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name']);
    $email  = trim($_POST['email']);
    $phone  = trim($_POST['phone']);
    $address= trim($_POST['address']);
    $plan   = $_POST['subscription_plan'];
    $expiry = $_POST['subscription_expiry'];
    $active = isset($_POST['is_active']) ? 1 : 0;

    $logo = $r['logo'];
    if(!empty($_FILES['logo']['name'])) {
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        if(in_array(strtolower($ext), ['jpg','jpeg','png','webp'])) {
            if($logo) @unlink('../../assets/uploads/'.$logo);
            $logo = 'logos/'.uniqid().'.'.$ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], '../../assets/uploads/'.$logo);
        }
    }

    if(!empty($_POST['new_password'])) {
        $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE restaurants SET password=? WHERE id=?")->execute([$hashed, $id]);
        // زامن كلمة السر في users أيضاً
        $pdo->prepare("UPDATE users SET password=? WHERE restaurant_id=? AND role='restaurant_manager'")
            ->execute([$hashed, $id]);
    }

    $pdo->prepare("UPDATE restaurants SET name=?,email=?,phone=?,address=?,logo=?,subscription_plan=?,subscription_expiry=?,is_active=? WHERE id=?")
        ->execute([$name,$email,$phone,$address,$logo,$plan,$expiry,$active,$id]);

    // زامن الاسم والإيميل وحالة النشاط في users
    $pdo->prepare("UPDATE users SET name=?, email=?, is_active=? WHERE restaurant_id=? AND role='restaurant_manager'")
        ->execute([$name, $email, $active, $id]);

    $success = 'تم حفظ التعديلات!';
    $stmt->execute([$id]); $r = $stmt->fetch();
}

// Stats — count only, no revenue
$dish_count  = $pdo->prepare("SELECT COUNT(*) FROM dishes WHERE restaurant_id=?"); $dish_count->execute([$id]);
$dish_count  = $dish_count->fetchColumn();
$order_count = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id=?"); $order_count->execute([$id]);
$order_count = $order_count->fetchColumn();
$cat_count   = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE restaurant_id=?"); $cat_count->execute([$id]);
$cat_count   = $cat_count->fetchColumn();

require_once '../sidebar.php';
?>
<style>
/* Form cards */
.fc {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; overflow: hidden; max-width: 700px; margin-bottom: 14px;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.fc:nth-child(1){animation-delay:.04s}
.fc:nth-child(2){animation-delay:.08s}
.fc:nth-child(3){animation-delay:.12s}

.fc-header {
    padding: 13px 20px; border-bottom: 1px solid var(--line);
    display: flex; justify-content: space-between; align-items: center;
    background: color-mix(in srgb, var(--bg3) 60%, transparent);
}
.fc-header h4 {
    font-family: 'Fraunces', serif;
    font-size: 13px; font-weight: 700; color: var(--ink);
    display: flex; align-items: center; gap: 7px;
}
.fc-header h4 svg { width: 14px; height: 14px; color: var(--p); }
.fc-body { padding: 20px; }

/* Info stats */
.info-pills {
    display: flex; gap: 10px; flex-wrap: wrap;
    max-width: 700px; margin-bottom: 16px;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.info-pill {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 12px; padding: 12px 16px;
    flex: 1; min-width: 100px; text-align: center;
}
.ip-num {
    font-family: 'Fraunces', serif;
    font-size: 22px; font-weight: 900; color: var(--ink); line-height: 1;
}
.ip-lbl { font-size: 11px; color: var(--ink2); font-weight: 600; margin-top: 3px; }

/* Plan selector */
.plan-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 9px; }
@media (max-width:500px) { .plan-grid { grid-template-columns: 1fr; } }

input[type=radio].plan-opt { display: none; }
.plan-lbl {
    display: block; padding: 13px 10px;
    border: 1.5px solid var(--line); border-radius: 13px;
    cursor: pointer; text-align: center; transition: all 0.2s;
    background: var(--bg3);
}
.plan-lbl:hover { border-color: var(--p); }
.plan-lbl-icon  { font-size: 20px; margin-bottom: 5px; }
.plan-lbl-name  { font-size: 12px; font-weight: 800; color: var(--ink); }
.plan-lbl-price { font-size: 10px; color: var(--ink2); margin-top: 2px; }
input[type=radio].plan-opt:checked + .plan-lbl {
    border-color: var(--p);
    background: rgba(255,107,53,0.08);
    box-shadow: 0 4px 12px var(--p-glow);
}

/* Toggle switch */
.toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px;
    background: var(--bg3); border: 1.5px solid var(--line);
    border-radius: 12px;
}
.toggle-row-lbl { font-size: 13px; font-weight: 700; color: var(--ink); }
.sw {
    width: 44px; height: 24px; background: var(--line);
    border-radius: 20px; position: relative; cursor: pointer;
    border: none; transition: background 0.3s; flex-shrink: 0;
}
.sw::after {
    content: ''; position: absolute;
    width: 18px; height: 18px; background: #fff;
    border-radius: 50%; top: 3px; right: 3px;
    transition: transform 0.3s; box-shadow: 0 1px 4px rgba(0,0,0,0.2);
}
.sw.on { background: #22C55E; }
.sw.on::after { transform: translateX(-20px); }

/* Logo preview */
.logo-preview-wrap {
    display: flex; align-items: center; gap: 12px;
    padding: 12px; background: var(--bg3); border: 1px solid var(--line);
    border-radius: 11px; margin-bottom: 10px;
}
.logo-preview-wrap img {
    width: 50px; height: 50px; border-radius: 11px;
    object-fit: cover; border: 1px solid var(--line);
}
.logo-preview-wrap span { font-size: 12px; color: var(--ink2); font-weight: 600; }

/* Section label */
.sec-lbl {
    font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; color: var(--ink3);
    margin: 16px 0 10px; display: flex; align-items: center; gap: 8px;
}
.sec-lbl::after { content:''; flex:1; height:1px; background: var(--line); }
</style>

<div class="main">

    <!-- Back + title -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;">
        <a href="index.php" class="btn btn-secondary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><polyline points="15 18 9 12 15 6"/></svg>
            رجوع
        </a>
        <div style="flex:1;">
            <div class="page-title">تعديل مطعم</div>
            <div class="page-subtitle"><?= htmlspecialchars($r['name']) ?></div>
        </div>
        <a href="<?= BASE_URL ?>/menu/<?= $r['slug'] ?>" target="_blank" class="btn btn-secondary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            عرض المنيو
        </a>
    </div>

    <?php if($success): ?>
    <div class="alert alert-success" style="max-width:700px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= $success ?>
    </div>
    <?php endif; ?>

    <!-- Info pills (count only) -->
    <div class="info-pills">
        <div class="info-pill">
            <div class="ip-num"><?= $dish_count ?></div>
            <div class="ip-lbl">طبق</div>
        </div>
        <div class="info-pill">
            <div class="ip-num"><?= $cat_count ?></div>
            <div class="ip-lbl">تصنيف</div>
        </div>
        <div class="info-pill">
            <div class="ip-num"><?= $order_count ?></div>
            <div class="ip-lbl">طلب</div>
        </div>
        <div class="info-pill">
            <div class="ip-num" style="color:<?= $r['is_active']?'#22C55E':'#EF4444' ?>">
                <?= $r['is_active'] ? 'نشط' : 'موقوف' ?>
            </div>
            <div class="ip-lbl">الحالة</div>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data">

        <!-- Basic info -->
        <div class="fc">
            <div class="fc-header">
                <h4>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/></svg>
                    البيانات الأساسية
                </h4>
                <span class="badge <?= $r['is_active']?'badge-active':'badge-inactive' ?>">
                    <?= $r['is_active']?'نشط':'موقوف' ?>
                </span>
            </div>
            <div class="fc-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label>اسم المطعم</label>
                        <input type="text" name="name" required value="<?= htmlspecialchars($r['name']) ?>">
                    </div>
                    <div class="form-group">
                        <label>رابط المنيو</label>
                        <input type="text" value="<?= $r['slug'] ?>" disabled style="opacity:0.5;cursor:not-allowed;">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>الإيميل</label>
                        <input type="email" name="email" required value="<?= htmlspecialchars($r['email']) ?>">
                    </div>
                    <div class="form-group">
                        <label>رقم الهاتف</label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars($r['phone']??'') ?>" placeholder="+963...">
                    </div>
                </div>
                <div class="form-group">
                    <label>العنوان</label>
                    <input type="text" name="address" value="<?= htmlspecialchars($r['address']??'') ?>">
                </div>

                <div class="sec-lbl">الشعار</div>
                <?php if($r['logo']): ?>
                <div class="logo-preview-wrap">
                    <img src="<?= BASE_URL ?>/assets/uploads/<?= $r['logo'] ?>">
                    <span>الشعار الحالي</span>
                </div>
                <?php endif; ?>
                <div class="form-group" style="margin:0">
                    <input type="file" name="logo" accept="image/*">
                </div>

                <div class="sec-lbl">الحالة</div>
                <div class="toggle-row">
                    <span class="toggle-row-lbl">المطعم نشط؟</span>
                    <button type="button" class="sw <?= $r['is_active']?'on':'' ?>" id="swBtn" onclick="toggleSw()"></button>
                    <input type="hidden" name="is_active" id="swVal" value="<?= $r['is_active'] ?>">
                </div>
            </div>
        </div>

        <!-- Subscription -->
        <div class="fc">
            <div class="fc-header">
                <h4>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    الاشتراك
                </h4>
                <span class="badge badge-<?= $r['subscription_plan'] ?>"><?= $r['subscription_plan'] ?></span>
            </div>
            <div class="fc-body">
                <div class="form-group">
                    <label>الباقة</label>
                    <div class="plan-grid">
                        <?php foreach(['free'=>['🆓','مجانية','$0'],'basic'=>['🥉','أساسية','$15'],'advanced'=>['🥈','متقدمة','$20'],'premium'=>['⭐','بريميوم','$25']] as $pv=>[$pi,$pn,$pp]): ?>
                        <div>
                            <input type="radio" name="subscription_plan" value="<?= $pv ?>"
                                   id="p_<?= $pv ?>" class="plan-opt"
                                   <?= $r['subscription_plan']===$pv?'checked':'' ?>>
                            <label for="p_<?= $pv ?>" class="plan-lbl">
                                <div class="plan-lbl-icon"><?= $pi ?></div>
                                <div class="plan-lbl-name"><?= $pn ?></div>
                                <div class="plan-lbl-price"><?= $pp ?>/شهر</div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>تاريخ انتهاء الاشتراك</label>
                        <input type="date" name="subscription_expiry" value="<?= $r['subscription_expiry'] ?>">
                    </div>
                    <div class="form-group">
                        <label>كلمة سر جديدة (اختياري)</label>
                        <input type="password" name="new_password" placeholder="اتركه فارغاً للإبقاء على القديمة">
                    </div>
                </div>
            </div>
        </div>

        <!-- Save -->
        <div style="max-width:700px;">
            <button type="submit" class="btn btn-primary" style="width:100%;padding:13px;font-size:15px;border-radius:13px;justify-content:center;box-shadow:0 6px 22px var(--p-glow);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:15px;height:15px"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                حفظ التعديلات
            </button>
        </div>

    </form>
</div>

<script>
function toggleSw() {
    var b = document.getElementById('swBtn');
    var v = document.getElementById('swVal');
    b.classList.toggle('on');
    v.value = b.classList.contains('on') ? '1' : '0';
}
</script>
</body></html>