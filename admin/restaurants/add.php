<?php
session_start();
require_once '../../config/database.php';
if(!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $phone    = trim($_POST['phone']);
    $address  = trim($_POST['address']);
    $plan     = $_POST['subscription_plan'];
    $expiry   = $_POST['subscription_expiry'];
    $slug     = trim($_POST['slug']);

    $check = $pdo->prepare("SELECT id FROM restaurants WHERE email=? OR slug=?");
    $check->execute([$email, $slug]);
    if($check->fetch()) {
        $error = 'الإيميل أو رابط المنيو مستخدم مسبقاً!';
    } else {
        $logo = '';
        if(!empty($_FILES['logo']['name'])) {
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            if(in_array(strtolower($ext), ['jpg','jpeg','png','webp'])) {
                $logo = 'logos/'.uniqid().'.'.$ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], '../../assets/uploads/'.$logo);
            }
        }
        $pdo->prepare("INSERT INTO restaurants (name,email,password,phone,address,logo,slug,subscription_plan,subscription_expiry,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)")
            ->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$phone,$address,$logo,$slug,$plan,$expiry]);
        $new_id = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO subscriptions (restaurant_id,plan,price,start_date,end_date,is_active) VALUES (?,?,?,CURDATE(),?,1)")
            ->execute([$new_id,$plan,$_POST['price']??0,$expiry]);
        header('Location: index.php?success=1'); exit;
    }
}

require_once '../sidebar.php';
?>
<style>
.fc {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; overflow: hidden; max-width: 700px; margin-bottom: 14px;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.fc-header {
    padding: 13px 20px; border-bottom: 1px solid var(--line);
    display: flex; align-items: center; gap: 8px;
    background: color-mix(in srgb, var(--bg3) 60%, transparent);
}
.fc-header h4 {
    font-family: 'Fraunces', serif;
    font-size: 13px; font-weight: 700; color: var(--ink);
    display: flex; align-items: center; gap: 7px; margin: 0;
}
.fc-header h4 svg { width: 14px; height: 14px; color: var(--p); }
.fc-body { padding: 20px; }

.sec-lbl {
    font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; color: var(--ink3);
    margin: 16px 0 10px; display: flex; align-items: center; gap: 8px;
}
.sec-lbl::after { content:''; flex:1; height:1px; background:var(--line); }
.sec-lbl:first-child { margin-top: 0; }

.slug-preview {
    font-size: 11px; color: var(--p); font-weight: 600; margin-top: 5px;
}

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
</style>

<div class="main">

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:22px;flex-wrap:wrap;">
        <a href="index.php" class="btn btn-secondary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><polyline points="15 18 9 12 15 6"/></svg>
            رجوع
        </a>
        <div>
            <div class="page-title">إضافة مطعم</div>
            <div class="page-subtitle">أدخل بيانات المطعم الجديد</div>
        </div>
    </div>

    <?php if($error): ?>
    <div class="alert alert-error" style="max-width:700px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= $error ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">

        <div class="fc">
            <div class="fc-header">
                <h4>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/></svg>
                    بيانات المطعم
                </h4>
            </div>
            <div class="fc-body">

                <div class="sec-lbl">معلومات أساسية</div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>اسم المطعم *</label>
                        <input type="text" name="name" required
                               placeholder="مطعم الشام"
                               oninput="generateSlug(this.value)">
                    </div>
                    <div class="form-group">
                        <label>رابط المنيو *</label>
                        <input type="text" name="slug" id="slugInput" required placeholder="al-sham" oninput="updatePreview()">
                        <div class="slug-preview">menu-pro.org/menu/<span id="slugShow">...</span></div>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>رقم الهاتف</label>
                        <input type="tel" name="phone" placeholder="+963...">
                    </div>
                    <div class="form-group">
                        <label>الشعار</label>
                        <input type="file" name="logo" accept="image/*">
                    </div>
                </div>
                <div class="form-group">
                    <label>العنوان</label>
                    <input type="text" name="address" placeholder="المدينة، الحي...">
                </div>

                <div class="sec-lbl">بيانات الدخول</div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>إيميل المطعم *</label>
                        <input type="email" name="email" required placeholder="restaurant@email.com">
                    </div>
                    <div class="form-group">
                        <label>كلمة السر *</label>
                        <input type="password" name="password" required placeholder="••••••••">
                    </div>
                </div>

                <div class="sec-lbl">الاشتراك</div>
                <div class="form-group">
                    <label>الباقة *</label>
                    <div class="plan-grid">
                        <?php foreach(['basic'=>['🥉','أساسية','$80'],'advanced'=>['🥈','متقدمة','$180'],'premium'=>['⭐','بريميوم','$280']] as $pv=>[$pi,$pn,$pp]): ?>
                        <div>
                            <input type="radio" name="subscription_plan" value="<?= $pv ?>"
                                   id="p_<?= $pv ?>" class="plan-opt"
                                   <?= $pv==='basic'?'checked':'' ?>>
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
                        <label>تاريخ انتهاء الاشتراك *</label>
                        <input type="date" name="subscription_expiry" required value="<?= date('Y-m-d', strtotime('+1 month')) ?>">
                    </div>
                    <div class="form-group">
                        <label>السعر المدفوع ($)</label>
                        <input type="number" name="price" step="0.01" placeholder="0.00">
                    </div>
                </div>

            </div>
        </div>

        <div style="max-width:700px;">
            <button type="submit" class="btn btn-primary" style="width:100%;padding:13px;font-size:15px;border-radius:13px;justify-content:center;box-shadow:0 6px 22px var(--p-glow);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:15px;height:15px"><polyline points="20 6 9 17 4 12"/></svg>
                إنشاء المطعم
            </button>
        </div>
    
    </form>
</div>

<script>
function generateSlug(name) {
    var slug = name.toLowerCase()
        .replace(/\s+/g,'-')
        .replace(/[^a-z0-9-]/g,'')
        .replace(/-+/g,'-')
        .replace(/^-|-$/g,'');
    document.getElementById('slugInput').value = slug;
    document.getElementById('slugShow').textContent = slug || '...';
}
function updatePreview() {
    document.getElementById('slugShow').textContent =
        document.getElementById('slugInput').value || '...';
}
</script>
</body></html>