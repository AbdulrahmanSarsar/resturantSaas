<?php
session_start();
require_once '../../config/database.php';
require_once 'plan_guard.php';

if(!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}

$rid = $_SESSION['restaurant_id'];
$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
$stmt->execute([$rid]);
$restaurant = $stmt->fetch();

$success = '';
$error   = '';

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    $name            = trim($_POST['name']            ?? '');
    $phone           = trim($_POST['phone']           ?? '');
    $address         = trim($_POST['address']         ?? '');
    $welcome_message    = trim($_POST['welcome_message']    ?? '');
    $welcome_message_en = trim($_POST['welcome_message_en'] ?? '');
    $instagram       = trim($_POST['instagram']       ?? '');
    $facebook        = trim($_POST['facebook']        ?? '');
    $whatsapp        = trim($_POST['whatsapp']        ?? '');
    $twitter         = trim($_POST['twitter']         ?? '');
    $tiktok          = trim($_POST['tiktok']          ?? '');

    // رفع شعار
    $logo = $restaurant['logo'];
    if(!empty($_FILES['logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if(in_array($ext, ['jpg','jpeg','png','webp'])) {
            if($logo) @unlink('../../assets/uploads/' . $logo);
            $logo = 'logos/' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], '../../assets/uploads/' . $logo);
        }
    }

    // تغيير كلمة السر
    if(!empty($_POST['new_password'])) {
        if(!password_verify($_POST['current_password'], $restaurant['password'])) {
            $error = 'كلمة السر الحالية غلط!';
        } else {
            $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE restaurants SET password = ? WHERE id = ?")->execute([$hashed, $rid]);
        }
    }

    if(!$error) {
        // شام كاش
        $shamcash_enabled = isset($_POST['shamcash_enabled']) ? 1 : 0;
        $shamcash_number  = trim($_POST['shamcash_number'] ?? '');
        $shamcash_qr      = $restaurant['shamcash_qr'] ?? '';
        if(!empty($_FILES['shamcash_qr']['name']) && $_FILES['shamcash_qr']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['shamcash_qr']['name'], PATHINFO_EXTENSION));
            if(in_array($ext, ['jpg','jpeg','png','webp'])) {
                $qr_dir = rtrim($_SERVER['DOCUMENT_ROOT'],'/') . '/assets/uploads/shamcash/';
                if(!is_dir($qr_dir)) mkdir($qr_dir, 0755, true);
                if($shamcash_qr && file_exists($qr_dir.$shamcash_qr)) @unlink($qr_dir.$shamcash_qr);
                $shamcash_qr = uniqid('sc_',true).'.'.$ext;
                move_uploaded_file($_FILES['shamcash_qr']['tmp_name'], $qr_dir.$shamcash_qr);
            }
        }

        $currency_code      = trim($_POST['currency_code']      ?? 'USD');
        $currency_symbol    = trim($_POST['currency_symbol']    ?? '$');
        $currency_symbol_en = trim($_POST['currency_symbol_en'] ?? '$');
        $currency_decimals  = intval($_POST['currency_decimals'] ?? 2);

        $pdo->prepare("UPDATE restaurants SET
            name=?, phone=?, address=?, logo=?,
            welcome_message=?, welcome_message_en=?,
            instagram=?, facebook=?, whatsapp=?, twitter=?, tiktok=?,
            shamcash_enabled=?, shamcash_number=?, shamcash_qr=?,
            currency_code=?, currency_symbol=?, currency_symbol_en=?, currency_decimals=?
            WHERE id=?")
            ->execute([
                $name, $phone, $address, $logo,
                $welcome_message, $welcome_message_en,
                $instagram, $facebook, $whatsapp, $twitter, $tiktok,
                $shamcash_enabled, $shamcash_number, $shamcash_qr,
                $currency_code, $currency_symbol, $currency_symbol_en, $currency_decimals,
                $rid
            ]);

        $_SESSION['restaurant_name'] = $name;
        $success = 'تم حفظ التعديلات بنجاح!';

        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
        $stmt->execute([$rid]);
        $restaurant = $stmt->fetch();
    }
}


// ===== إدارة الموظفين =====
// جلب الفروع النشطة (مشترك بين الـ form والـ modal)
$active_branches = [];
if(plan_has_feature('staff')) {
    $bstmt = $pdo->prepare("SELECT id, name FROM branches WHERE restaurant_id=? AND is_active=1 ORDER BY name");
    $bstmt->execute([$rid]);
    $active_branches = $bstmt->fetchAll();
}

if(plan_has_feature('staff') && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_action'])) {
    // تحقق إن الفرع تابع فعلاً لهذا المطعم (منع tampering)
    $validate_branch = function($branch_id) use ($pdo, $rid) {
        if (!$branch_id) return null;
        $chk = $pdo->prepare("SELECT id FROM branches WHERE id=? AND restaurant_id=?");
        $chk->execute([$branch_id, $rid]);
        return $chk->fetch() ? (int)$branch_id : null;
    };

    if($_POST['staff_action'] === 'add_staff') {
        $sname     = trim($_POST['staff_name']);
        $susername = trim($_POST['staff_username']);
        $srole     = $_POST['staff_role'];
        $spass     = $_POST['staff_password'];
        $sbranch   = $validate_branch(intval($_POST['staff_branch_id'] ?? 0));

        if(!$sbranch) {
            $error = 'الرجاء اختيار الفرع';
        } elseif($sname && $susername && $spass && in_array($srole,['waiter','kitchen','cashier'])) {
            $hashed = password_hash($spass, PASSWORD_DEFAULT);
            try {
                $pdo->prepare("INSERT INTO restaurant_staff (restaurant_id,branch_id,name,username,password,role) VALUES (?,?,?,?,?,?)")
                    ->execute([$rid,$sbranch,$sname,$susername,$hashed,$srole]);
                $success = 'تم إضافة الموظف بنجاح!';
            } catch(Exception $e) {
                $error = 'اسم المستخدم موجود مسبقاً';
            }
        }
    }
    if($_POST['staff_action'] === 'edit_staff') {
        $sid     = intval($_POST['staff_id']);
        $sname   = trim($_POST['staff_name'] ?? '');
        $srole   = $_POST['staff_role'] ?? '';
        $sbranch = $validate_branch(intval($_POST['staff_branch_id'] ?? 0));

        if(!$sbranch) {
            $error = 'الرجاء اختيار الفرع';
        } elseif($sname && in_array($srole,['waiter','kitchen','cashier'])) {
            $pdo->prepare("UPDATE restaurant_staff SET name=?, role=?, branch_id=? WHERE id=? AND restaurant_id=?")
                ->execute([$sname, $srole, $sbranch, $sid, $rid]);
            $success = 'تم تعديل بيانات الموظف!';
        } else {
            $error = 'البيانات ناقصة أو غير صحيحة';
        }
    }
    if($_POST['staff_action'] === 'toggle_staff') {
        $pdo->prepare("UPDATE restaurant_staff SET is_active=NOT is_active WHERE id=? AND restaurant_id=?")
            ->execute([intval($_POST['staff_id']),$rid]);
    }
    if($_POST['staff_action'] === 'delete_staff') {
        $pdo->prepare("DELETE FROM restaurant_staff WHERE id=? AND restaurant_id=?")
            ->execute([intval($_POST['staff_id']),$rid]);
    }
    // Redirect بعد أي staff action لمنع إعادة إرسال الـ form
    $msg = urlencode($success ?: $error);
    header("Location: profile.php" . ($msg ? "?msg=$msg" : ""));
    exit;
}
$staff_list = $pdo->prepare("
    SELECT s.*, b.name AS branch_name
    FROM restaurant_staff s
    LEFT JOIN branches b ON b.id = s.branch_id
    WHERE s.restaurant_id = ?
    ORDER BY s.role, s.name
");
$staff_list->execute([$rid]);
$staff_list = $staff_list->fetchAll();

// قراءة رسائل الـ redirect
if(isset($_GET['msg']) && !$success && !$error) {
    $msg_decoded = urldecode($_GET['msg']);
    if($msg_decoded) $success = $msg_decoded;
}

require_once 'sidebar.php';
?>
<style>
/* ===== LAYOUT ===== */
.profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
@media (max-width: 768px) { .profile-grid { grid-template-columns: 1fr; } }
@media (max-width: 768px) {
    .profile-grid { gap: 10px; }
    .fc-body { padding: 14px; }
    .fc-head { padding: 11px 14px; }
    .main { padding: 12px !important; }
}

.fc        { /* form card */
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 18px; overflow: hidden;
    transition: background 0.3s, border-color 0.3s;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.fc.full   { grid-column: 1 / -1; }
.fc:nth-child(1){animation-delay:.04s}
.fc:nth-child(2){animation-delay:.08s}
.fc:nth-child(3){animation-delay:.12s}
.fc:nth-child(4){animation-delay:.16s}
.fc:nth-child(5){animation-delay:.20s}
.fc:nth-child(6){animation-delay:.24s}

.fc-head {
    padding: 13px 20px;
    border-bottom: 1px solid var(--line);
    display: flex; align-items: center; gap: 8px;
    transition: border-color 0.3s;
}
.fc-head-icon {
    width: 30px; height: 30px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.fc-head-icon svg { width: 14px; height: 14px; }
.fc-title {
    font-family: 'Fraunces', serif;
    font-size: 14px; font-weight: 700; color: var(--ink);
    transition: color 0.3s;
}
.fc-body { padding: 18px 20px; }

/* ===== COLOR PICKER ===== */
.color-row {
    display: flex; align-items: center; gap: 10px;
}
input[type=color] {
    width: 44px; height: 40px;
    border: 1.5px solid var(--line); border-radius: 10px;
    padding: 3px; cursor: pointer; flex-shrink: 0;
    background: var(--bg3);
    transition: border-color 0.3s;
}
.color-preview-bar {
    height: 10px; border-radius: 20px;
    margin-top: 12px;
    transition: background 0.3s;
}
.color-caption {
    font-size: 11px; color: var(--ink2); text-align: center;
    margin-top: 6px; font-weight: 600;
}

/* ===== LOGO PREVIEW ===== */
.logo-preview-row {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px; background: var(--bg3);
    border-radius: 13px; border: 1px solid var(--line);
    margin-bottom: 14px; transition: background 0.3s, border-color 0.3s;
}
.logo-preview-row img {
    width: 48px; height: 48px; border-radius: 11px;
    object-fit: cover; border: 1px solid var(--line);
}
.logo-preview-row span {
    font-size: 12px; color: var(--ink2); font-weight: 600;
}

/* ===== SOCIAL GRID ===== */
.social-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
}
@media (max-width: 640px) { .social-grid { grid-template-columns: 1fr; } }
@media (max-width: 600px) {
  /* currency symbols row */
  .currency-sym-row { flex-direction: column !important; gap: 8px !important; }
  /* staff card */
  .staff-card { flex-wrap: wrap; gap: 8px; }
  .staff-card .staff-meta { flex: 1; min-width: 0; }
  /* form inline row */
  div[style*="display:flex"][style*="gap:10px"] { flex-wrap: wrap; }
  div[style*="display:flex"][style*="gap:10px"] .form-group { min-width: calc(50% - 5px) !important; }
  div[style*="display:flex"][style*="gap:10px"] .form-group:only-child { min-width: 100% !important; }
  /* currency 3-col → stack */
  .fc-body div[style*="display:flex"] { flex-wrap: wrap; }
  .fc-body div[style*="display:flex"] .form-group { min-width: 120px; }
  /* preview text */
  #currencyPreview { font-size: 18px !important; }
  /* tabs */
  .profile-tabs { overflow-x: auto; white-space: nowrap; padding-bottom: 4px; }
  /* staff action buttons */
  .staff-card form { display: flex; gap: 6px; flex-shrink: 0; }
  .staff-card form button { padding: 6px 10px; font-size: 11px; }
  /* numbers fit */
  .rcard-val { font-size: 16px !important; }
  /* main padding */
  .main { padding: 12px 10px 40px !important; }
  /* fc head */
  .fc-head { padding: 12px 14px !important; }
  .fc-body { padding: 12px 14px !important; }
  /* page title */
  .page-title { font-size: 20px !important; }
}
@media (max-width: 400px) {
  .fc-body div[style*="display:flex"] .form-group { min-width: 100%; flex: 1 1 100% !important; }
}
/* staff form 2-col grid */
.staff-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
@media (max-width: 768px) { .staff-form-grid { grid-template-columns:1fr; } }

.social-field label {
    display: flex; align-items: center; gap: 6px;
}

/* ===== SUB ROWS ===== */
.sub-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 11px 0; border-bottom: 1px solid var(--line);
    font-size: 13px; transition: border-color 0.3s;
}
.sub-row:last-child { border-bottom: none; }
.sub-lbl { color: var(--ink2); font-weight: 500; }
.sub-val {
    font-family: 'Fraunces', serif;
    font-size: 14px; font-weight: 700; color: var(--ink);
    transition: color 0.3s;
}
.sub-link {
    color: var(--p); font-weight: 700; font-size: 13px;
    text-decoration: none; display: flex; align-items: center; gap: 5px;
    transition: opacity 0.2s;
}
.sub-link:hover { opacity: 0.7; }
.sub-link svg { width: 13px; height: 13px; }

/* ===== SAVE BUTTON ===== */
.save-btn {
    width: 100%; padding: 14px;
    background: var(--p); color: #fff;
    border: none; border-radius: 14px;
    font-size: 15px; font-weight: 800;
    cursor: pointer; font-family: 'Tajawal', sans-serif;
    transition: opacity 0.2s, transform 0.2s;
    box-shadow: 0 6px 20px color-mix(in srgb, var(--p) 40%, transparent);
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.save-btn svg { width: 16px; height: 16px; }
.save-btn:hover { opacity: 0.88; transform: translateY(-2px); }
.save-btn:active { transform: scale(0.98); }
</style>

<div class="main">

    <div style="margin-bottom:22px;">
        <div class="page-title">إعدادات المطعم</div>
        <div class="page-subtitle">خصّص مطعمك وأضف بياناتك</div>
    </div>

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

    <form method="POST" enctype="multipart/form-data">
    <div class="profile-grid">

        <!-- البيانات الأساسية -->
        <div class="fc">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <span class="fc-title">البيانات الأساسية</span>
            </div>
            <div class="fc-body">
                <div class="form-group">
                    <label>اسم المطعم *</label>
                    <input type="text" name="name" required value="<?= htmlspecialchars($restaurant['name']) ?>">
                </div>
                <div class="form-group">
                    <label>رقم الهاتف</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($restaurant['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>العنوان</label>
                    <textarea name="address"><?= htmlspecialchars($restaurant['address'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>رسالة ترحيب (عربي)</label>
                    <textarea name="welcome_message" placeholder="مثال: أهلاً بكم، نتمنى لكم تجربة شهية!"><?= htmlspecialchars($restaurant['welcome_message'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:5px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:12px;height:12px"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20"/></svg>
                        Welcome Message (English)
                    </label>
                    <textarea name="welcome_message_en" placeholder="e.g. Welcome! We hope you enjoy your meal 🍽️"><?= htmlspecialchars($restaurant['welcome_message_en'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- الشعار -->
        <div class="fc">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:rgba(139,92,246,0.12);color:#8B5CF6">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </div>
                <span class="fc-title">شعار المطعم</span>
            </div>
            <div class="fc-body">
                <?php if($restaurant['logo']): ?>
                <div class="logo-preview-row">
                    <img src="<?= BASE_URL ?>/assets/uploads/<?= $restaurant['logo'] ?>">
                    <span>الشعار الحالي</span>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>رفع شعار جديد</label>
                    <input type="file" name="logo" accept="image/*">
                </div>
            </div>
        </div>

        <!-- وسائل التواصل -->
        <div class="fc full">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:rgba(59,130,246,0.12);color:#3B82F6">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 2H3v16h5v4l4-4h5l4-4V2zm-10 9V7m5 4V7"/></svg>
                </div>
                <span class="fc-title">وسائل التواصل الاجتماعي</span>
            </div>
            <div class="fc-body">
                <div class="social-grid">
                    <div class="form-group social-field">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg>
                            انستغرام
                        </label>
                        <input type="text" name="instagram" value="<?= htmlspecialchars($restaurant['instagram'] ?? '') ?>" placeholder="instagram.com/yourpage">
                    </div>
                    <div class="form-group social-field">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>
                            فيسبوك
                        </label>
                        <input type="text" name="facebook" value="<?= htmlspecialchars($restaurant['facebook'] ?? '') ?>" placeholder="facebook.com/yourpage">
                    </div>
                    <div class="form-group social-field">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                            واتساب
                        </label>
                        <input type="tel" name="whatsapp" value="<?= htmlspecialchars($restaurant['whatsapp'] ?? '') ?>" placeholder="+963xxxxxxxxx">
                    </div>
                    <div class="form-group social-field">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 00-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0020 4.77 5.07 5.07 0 0019.91 1S18.73.65 16 2.48a13.38 13.38 0 00-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 005 4.77a5.44 5.44 0 00-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 009 18.13V22"/></svg>
                            تيك توك
                        </label>
                        <input type="text" name="tiktok" value="<?= htmlspecialchars($restaurant['tiktok'] ?? '') ?>" placeholder="tiktok.com/@yourpage">
                    </div>
                    <div class="form-group social-field">
                        <label>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><path d="M23 3a10.9 10.9 0 01-3.14 1.53 4.48 4.48 0 00-7.86 3v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5a4.5 4.5 0 00-.08-.83A7.72 7.72 0 0023 3z"/></svg>
                            تويتر / X
                        </label>
                        <input type="text" name="twitter" value="<?= htmlspecialchars($restaurant['twitter'] ?? '') ?>" placeholder="twitter.com/yourpage">
                    </div>
                </div>
            </div>
        </div>

        <!-- تغيير كلمة السر -->
        <div class="fc">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:rgba(239,68,68,0.10);color:#EF4444">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                </div>
                <span class="fc-title">تغيير كلمة السر</span>
            </div>
            <div class="fc-body">
                <div class="form-group">
                    <label>كلمة السر الحالية</label>
                    <input type="password" name="current_password" placeholder="أدخل كلمة السر الحالية">
                </div>
                <div class="form-group">
                    <label>كلمة السر الجديدة</label>
                    <input type="password" name="new_password" placeholder="اتركه فارغ إذا ما بدك تغير">
                </div>
            </div>
        </div>

        <!-- معلومات الاشتراك -->
        <div class="fc">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:rgba(245,158,11,0.12);color:#F59E0B">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                </div>
                <span class="fc-title">معلومات الاشتراك</span>
            </div>
            <div class="fc-body">
                <?php
                $plans = ['basic'=>'أساسية','advanced'=>'متقدمة','premium'=>'بريميوم'];
                $expiry    = $restaurant['subscription_expiry'];
                $days_left = $expiry ? ceil((strtotime($expiry) - time()) / 86400) : 0;
                ?>
                <div class="sub-row">
                    <span class="sub-lbl">الباقة</span>
                    <span class="badge badge-<?= $restaurant['subscription_plan'] ?>">
                        <?= $plans[$restaurant['subscription_plan']] ?>
                    </span>
                </div>
                <div class="sub-row">
                    <span class="sub-lbl">تنتهي في</span>
                    <span class="sub-val" style="font-size:13px"><?= $expiry ? date('d/m/Y', strtotime($expiry)) : '—' ?></span>
                </div>
                <div class="sub-row">
                    <span class="sub-lbl">الأيام المتبقية</span>
                    <span class="sub-val" style="color:<?= $days_left<=7?'#EF4444':($days_left<=14?'#F59E0B':'#22C55E') ?>">
                        <?= $days_left > 0 ? $days_left . ' يوم' : 'منتهي' ?>
                    </span>
                </div>
                <div class="sub-row">
                    <span class="sub-lbl">رابط المنيو</span>
                    <a href="<?= BASE_URL ?>/menu/<?= $restaurant['slug'] ?>" target="_blank" class="sub-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        عرض المنيو
                    </a>
                </div>
            </div>
        </div>

        <!-- شام كاش -->
        <div class="fc full">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:rgba(255,107,53,0.12);color:#FF6B35">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                </div>
                <span class="fc-title">شام كاش</span>
            </div>
            <div class="fc-body">
                <div class="form-group">
                    <label class="check-wrap">
                        <input type="checkbox" name="shamcash_enabled" <?= !empty($restaurant['shamcash_enabled'])?'checked':'' ?>>
                        <span>تفعيل خيار الدفع بشام كاش للزبائن</span>
                    </label>
                </div>
                <div class="form-group">
                    <label>رقم حساب شام كاش</label>
                    <input type="text" name="shamcash_number" value="<?= htmlspecialchars($restaurant['shamcash_number']??'') ?>" placeholder="09xxxxxxxx">
                </div>
                <div class="form-group">
                    <label>صورة QR كود شام كاش</label>
                    <?php if(!empty($restaurant['shamcash_qr'])): ?>
                    <div class="logo-preview-row">
                        <img src="<?= BASE_URL ?>/assets/uploads/shamcash/<?= $restaurant['shamcash_qr'] ?>" style="border-radius:8px;">
                        <span>QR الحالي</span>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="shamcash_qr" accept="image/*">
                </div>
            </div>
        </div>

        <!-- العملة -->
        <div class="fc full">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:rgba(34,197,94,0.12);color:#22C55E">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <span class="fc-title">عملة المطعم</span>
            </div>
            <div class="fc-body">
                <?php
                $cur_code = $restaurant['currency_code'] ?? 'USD';
                $currencies = [
                    'USD' => ['symbol'=>'$',    'symbol_en'=>'$',    'name'=>'دولار أمريكي',    'decimals'=>2],
                    'EUR' => ['symbol'=>'€',    'symbol_en'=>'€',    'name'=>'يورو',             'decimals'=>2],
                    'SYP' => ['symbol'=>'ل.س', 'symbol_en'=>'S.P',  'name'=>'ليرة سورية',      'decimals'=>0],
                    'SAR' => ['symbol'=>'ر.س', 'symbol_en'=>'SAR',  'name'=>'ريال سعودي',      'decimals'=>2],
                    'AED' => ['symbol'=>'د.إ', 'symbol_en'=>'AED',  'name'=>'درهم إماراتي',    'decimals'=>2],
                    'KWD' => ['symbol'=>'د.ك', 'symbol_en'=>'KWD',  'name'=>'دينار كويتي',     'decimals'=>3],
                    'BHD' => ['symbol'=>'د.ب', 'symbol_en'=>'BHD',  'name'=>'دينار بحريني',    'decimals'=>3],
                    'JOD' => ['symbol'=>'د.أ', 'symbol_en'=>'JOD',  'name'=>'دينار أردني',     'decimals'=>3],
                    'IQD' => ['symbol'=>'د.ع', 'symbol_en'=>'IQD',  'name'=>'دينار عراقي',     'decimals'=>0],
                    'LBP' => ['symbol'=>'ل.ل', 'symbol_en'=>'L.L',  'name'=>'ليرة لبنانية',   'decimals'=>0],
                    'EGP' => ['symbol'=>'ج.م', 'symbol_en'=>'EGP',  'name'=>'جنيه مصري',       'decimals'=>2],
                    'TRY' => ['symbol'=>'₺',   'symbol_en'=>'TRY',  'name'=>'ليرة تركية',      'decimals'=>2],
                ];
                ?>
                <div class="form-group">
                    <label>اختر العملة</label>
                    <select name="currency_code" id="currencySelect"
                            onchange="updateCurrencyFields(this)"
                            style="width:100%;padding:11px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;color:var(--ink);font-size:13px;font-family:'Tajawal',sans-serif;outline:none;">
                        <?php foreach($currencies as $code => $cur): ?>
                        <option value="<?= $code ?>"
                                data-symbol="<?= htmlspecialchars($cur['symbol']) ?>"
                                data-symbol-en="<?= htmlspecialchars($cur['symbol_en']) ?>"
                                data-decimals="<?= $cur['decimals'] ?>"
                                <?= $cur_code===$code ? 'selected' : '' ?>>
                            <?= $cur['symbol'] ?> / <?= $cur['symbol_en'] ?> — <?= $cur['name'] ?> (<?= $code ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="currency-sym-row" style="display:flex;gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>رمز عربي</label>
                        <input type="text" name="currency_symbol" id="currencySymbol"
                               value="<?= htmlspecialchars($restaurant['currency_symbol'] ?? '$') ?>"
                               style="text-align:center;font-size:16px;font-weight:800;">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>رمز إنجليزي</label>
                        <input type="text" name="currency_symbol_en" id="currencySymbolEn"
                               value="<?= htmlspecialchars($restaurant['currency_symbol_en'] ?? '$') ?>"
                               style="text-align:center;font-size:16px;font-weight:800;">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>خانات عشرية</label>
                        <input type="number" name="currency_decimals" id="currencyDecimals"
                               value="<?= $restaurant['currency_decimals'] ?? 2 ?>"
                               min="0" max="3">
                    </div>
                </div>
                <div style="background:var(--bg3);border-radius:12px;padding:12px 14px;font-size:13px;color:var(--ink2);">
                    معاينة: <strong id="currencyPreview" style="color:var(--p);font-size:16px;"></strong>
                </div>
            </div>
        </div>
        <script>
        function updateCurrencyFields(sel) {
            const opt = sel.options[sel.selectedIndex];
            document.getElementById('currencySymbol').value   = opt.dataset.symbol;
            document.getElementById('currencySymbolEn').value = opt.dataset.symbolEn || opt.dataset.symbol;
            document.getElementById('currencyDecimals').value = opt.dataset.decimals;
            updatePreview();
        }
        function updatePreview() {
            const sym   = document.getElementById('currencySymbol').value;
            const symEn = document.getElementById('currencySymbolEn').value;
            const dec   = parseInt(document.getElementById('currencyDecimals').value) || 0;
            const amount = (1234.5).toFixed(dec);
            const prefix = ['$','€','₺'].includes(sym);
            const arFmt  = prefix ? sym+amount : sym+' '+amount;
            const enFmt  = ['$','€','₺'].includes(symEn) ? symEn+amount : symEn+' '+amount;
            document.getElementById('currencyPreview').textContent = arFmt + ' / ' + enFmt;
        }
        document.getElementById('currencySymbol').addEventListener('input', updatePreview);
        document.getElementById('currencySymbolEn').addEventListener('input', updatePreview);
        document.getElementById('currencyDecimals').addEventListener('input', updatePreview);
        updatePreview();
        </script>

        <!-- Save Button -->
        <div class="fc full" style="background:transparent;border-color:transparent;box-shadow:none;">
            <div class="fc-body" style="padding:0;">
                <button type="submit" class="save-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    حفظ جميع التعديلات
                </button>
            </div>
        </div>

    </div>
    </form>

<?php if(plan_has_feature('staff')): ?>
<!-- ===== إدارة الموظفين ===== -->
<div style="margin-top:24px;">
    <div class="page-title" style="font-size:18px;margin-bottom:4px;">إدارة الموظفين</div>
    <div class="page-subtitle" style="margin-bottom:18px;">أضف نادلين وموظفي مطبخ بحسابات منفصلة</div>

    <!-- قائمة الموظفين -->
    <?php if(!empty($staff_list)): ?>
    <div style="display:flex;flex-direction:column;gap:9px;margin-bottom:18px;">
        <?php
        $role_labels=['waiter'=>'نادل','kitchen'=>'مطبخ','cashier'=>'كاشير'];
        $role_colors=['waiter'=>'rgba(59,130,246,0.12)','kitchen'=>'rgba(245,158,11,0.12)','cashier'=>'rgba(34,197,94,0.12)'];
        $role_text=['waiter'=>'#3B82F6','kitchen'=>'#F59E0B','cashier'=>'#22C55E'];
        foreach($staff_list as $st):
        ?>
        <div class="staff-card" style="background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:13px 16px;display:flex;align-items:center;gap:12px;transition:background 0.3s,border-color 0.3s;">
            <div style="width:38px;height:38px;border-radius:10px;background:<?= $role_colors[$st['role']] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:18px;">
                <?= $st['role']==='waiter'?'🧑‍💼':($st['role']==='cashier'?'🧾':'👨‍🍳') ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:14px;font-weight:700;color:var(--ink);"><?= htmlspecialchars($st['name']) ?></div>
                <div style="font-size:11px;color:var(--ink2);margin-top:2px;line-height:1.7;">
                    <?php if(!empty($st['staff_number'])): ?>
                    <span style="background:var(--bg3);border:1px solid var(--line);padding:1px 7px;border-radius:8px;font-weight:800;color:var(--ink2);margin-left:4px;">#<?= $st['staff_number'] ?></span>
                    <?php endif; ?>
                    @<?= htmlspecialchars($st['username']) ?> · <span style="color:<?= $role_text[$st['role']] ?>;font-weight:700;"><?= $role_labels[$st['role']] ?></span>
                    · <?php if($st['branch_name']): ?>
                        <span style="color:var(--p);font-weight:700;">📍 <?= htmlspecialchars($st['branch_name']) ?></span>
                    <?php else: ?>
                        <span style="color:#EF4444;font-weight:700;">⚠️ فرع غير محدد</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button type="button" onclick="openStaffEditModal(this)"
                        data-id="<?= $st['id'] ?>"
                        data-name="<?= htmlspecialchars($st['name'], ENT_QUOTES) ?>"
                        data-role="<?= $st['role'] ?>"
                        data-branch="<?= $st['branch_id'] ?? '' ?>"
                        style="padding:6px 12px;border-radius:8px;border:1px solid rgba(59,130,246,0.25);font-size:11px;font-weight:700;cursor:pointer;background:rgba(59,130,246,0.08);color:#3B82F6;font-family:'Tajawal',sans-serif;">
                    تعديل
                </button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="staff_action" value="toggle_staff">
                    <input type="hidden" name="staff_id" value="<?= $st['id'] ?>">
                    <button type="submit" style="padding:6px 12px;border-radius:8px;border:1px solid var(--line);font-size:11px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;background:<?= $st['is_active']?'rgba(34,197,94,0.1)':'var(--bg3)' ?>;color:<?= $st['is_active']?'#22C55E':'var(--ink2)' ?>;">
                        <?= $st['is_active']?'نشط':'موقوف' ?>
                    </button>
                </form>
                <form method="POST" onsubmit="return confirm('حذف الموظف؟')" style="display:inline;">
                    <input type="hidden" name="staff_action" value="delete_staff">
                    <input type="hidden" name="staff_id" value="<?= $st['id'] ?>">
                    <button type="submit" style="padding:6px 10px;border-radius:8px;border:1px solid rgba(239,68,68,0.2);font-size:11px;font-weight:700;cursor:pointer;background:rgba(239,68,68,0.08);color:#EF4444;font-family:'Tajawal',sans-serif;">حذف</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- إضافة موظف جديد -->
    <div style="background:var(--bg2);border:1px solid var(--line);border-radius:18px;overflow:hidden;">
        <div class="fc-head">
            <div class="fc-head-icon" style="background:rgba(34,197,94,0.12);color:#22C55E">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            </div>
            <span class="fc-title">إضافة موظف جديد</span>
        </div>
        <div class="fc-body">
            <?php if(empty($active_branches)): ?>
            <div class="alert alert-error" style="margin-bottom:0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                لا يوجد فروع نشطة. الرجاء <a href="branches.php" style="color:#EF4444;font-weight:800;text-decoration:underline;">إضافة فرع</a> أولاً قبل إضافة الموظفين.
            </div>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="staff_action" value="add_staff">
                <div class="staff-form-grid" style="margin-bottom:0;">
                    <div class="form-group">
                        <label>الاسم *</label>
                        <input type="text" name="staff_name" placeholder="اسم الموظف" required>
                    </div>
                    <div class="form-group">
                        <label>اسم المستخدم *</label>
                        <input type="text" name="staff_username" placeholder="للدخول" required>
                    </div>
                    <div class="form-group">
                        <label>الدور *</label>
                        <select name="staff_role" required style="width:100%;padding:11px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;color:var(--ink);font-size:13px;font-family:'Tajawal',sans-serif;outline:none;">
                            <option value="waiter">🧑‍💼 نادل</option>
                            <option value="kitchen">👨‍🍳 مطبخ</option>
                            <option value="cashier">🧾 كاشير</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>الفرع *</label>
                        <select name="staff_branch_id" required style="width:100%;padding:11px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;color:var(--ink);font-size:13px;font-family:'Tajawal',sans-serif;outline:none;">
                            <option value="">-- اختر الفرع --</option>
                            <?php foreach($active_branches as $b): ?>
                            <option value="<?= $b['id'] ?>">📍 <?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>كلمة السر *</label>
                        <input type="password" name="staff_password" placeholder="كلمة سر قوية" required>
                    </div>
                </div>
                <button type="submit" class="save-btn" style="margin-top:4px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                    إضافة الموظف
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== Modal تعديل الموظف ===== -->
<div id="staffEditModal" class="staff-modal-overlay" onclick="if(event.target===this)closeStaffEditModal()">
    <div class="staff-modal">
        <div class="staff-modal-head">
            <span>تعديل بيانات الموظف</span>
            <button type="button" onclick="closeStaffEditModal()" class="staff-modal-close" aria-label="إغلاق">×</button>
        </div>
        <form method="POST" class="staff-modal-body">
            <input type="hidden" name="staff_action" value="edit_staff">
            <input type="hidden" name="staff_id" id="edit_staff_id">

            <div class="form-group" style="margin-bottom:0;">
                <label>الاسم *</label>
                <input type="text" name="staff_name" id="edit_staff_name" required>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>الدور *</label>
                <select name="staff_role" id="edit_staff_role" required style="width:100%;padding:11px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;color:var(--ink);font-size:13px;font-family:'Tajawal',sans-serif;outline:none;">
                    <option value="waiter">🧑‍💼 نادل</option>
                    <option value="kitchen">👨‍🍳 مطبخ</option>
                    <option value="cashier">🧾 كاشير</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>الفرع *</label>
                <select name="staff_branch_id" id="edit_staff_branch" required style="width:100%;padding:11px 14px;background:var(--bg3);border:1.5px solid var(--line);border-radius:12px;color:var(--ink);font-size:13px;font-family:'Tajawal',sans-serif;outline:none;">
                    <option value="">-- اختر الفرع --</option>
                    <?php foreach($active_branches as $b): ?>
                    <option value="<?= $b['id'] ?>">📍 <?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:8px;margin-top:4px;">
                <button type="button" onclick="closeStaffEditModal()" style="flex:1;padding:12px;background:var(--bg3);border:1px solid var(--line);border-radius:12px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;color:var(--ink2);font-size:13px;">إلغاء</button>
                <button type="submit" style="flex:2;padding:12px;background:var(--p);color:#fff;border:none;border-radius:12px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;font-size:13px;box-shadow:0 4px 14px color-mix(in srgb,var(--p) 35%,transparent);">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<style>
.staff-modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 1000;
    align-items: center; justify-content: center;
    padding: 16px;
    animation: fadeInOverlay 0.15s ease;
}
.staff-modal-overlay.open { display: flex; }
.staff-modal {
    background: var(--bg2);
    border: 1px solid var(--line);
    border-radius: 18px;
    width: 100%; max-width: 440px;
    overflow: hidden;
    animation: slideUpModal 0.2s cubic-bezier(0.22,1,0.36,1);
}
.staff-modal-head {
    padding: 14px 18px;
    border-bottom: 1px solid var(--line);
    display: flex; justify-content: space-between; align-items: center;
    font-family: 'Fraunces', serif;
    font-size: 15px; font-weight: 700; color: var(--ink);
}
.staff-modal-close {
    background: transparent; border: none;
    font-size: 22px; cursor: pointer; color: var(--ink2);
    width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    transition: background 0.2s, color 0.2s;
    line-height: 1;
}
.staff-modal-close:hover { background: var(--bg3); color: var(--ink); }
.staff-modal-body {
    padding: 18px;
    display: flex; flex-direction: column; gap: 14px;
}
@keyframes fadeInOverlay { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUpModal {
    from { opacity: 0; transform: translateY(20px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
</style>

<script>
function openStaffEditModal(btn) {
    document.getElementById('edit_staff_id').value     = btn.dataset.id;
    document.getElementById('edit_staff_name').value   = btn.dataset.name;
    document.getElementById('edit_staff_role').value   = btn.dataset.role;
    document.getElementById('edit_staff_branch').value = btn.dataset.branch || '';
    document.getElementById('staffEditModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeStaffEditModal() {
    document.getElementById('staffEditModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('staffEditModal').classList.contains('open')) {
        closeStaffEditModal();
    }
});
</script>

<?php endif; ?>

</div><!-- end .main -->
</body>
</html>