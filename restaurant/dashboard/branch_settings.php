<?php
/**
 * branch_settings.php — إعدادات الفرع
 * 
 * الإعدادات:
 *   - العملة (رمز، كسور، اتجاه)
 *   - طرق الدفع (ShamCash QR)
 *   - إشعارات WhatsApp
 *   - روابط التواصل الاجتماعي
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once 'plan_guard.php';

if(!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}

$rid = $_SESSION['restaurant_id'];

// الفرع النشط من الـ session (sidebar بيحطه)
$active_branch_id = $_SESSION['active_branch_id'] ?? null;
$branch_id = intval($_GET['branch'] ?? $active_branch_id ?? 0);

// إذا ما في فرع نشط، جيب أول فرع نشط للمطعم
if(!$branch_id) {
    $first_branch = $pdo->prepare("SELECT id FROM branches WHERE restaurant_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1");
    $first_branch->execute([$rid]);
    $fb = $first_branch->fetchColumn();
    if($fb) {
        $branch_id = intval($fb);
        $_SESSION['active_branch_id'] = $branch_id;
    }
}

if(!$branch_id) {
    header('Location: branches.php'); exit;
}

// تحقق إن الفرع تبع هالمطعم
$branch_stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? AND restaurant_id = ?");
$branch_stmt->execute([$branch_id, $rid]);
$branch = $branch_stmt->fetch();

if(!$branch) {
    header('Location: branches.php'); exit;
}

// جلب الإعدادات
$settings_stmt = $pdo->prepare("SELECT * FROM branch_settings WHERE branch_id = ?");
$settings_stmt->execute([$branch_id]);
$settings = $settings_stmt->fetch();

// إذا ما في إعدادات — ننشئ default
if(!$settings) {
    $pdo->prepare("INSERT INTO branch_settings (branch_id) VALUES (?)")->execute([$branch_id]);
    $settings_stmt->execute([$branch_id]);
    $settings = $settings_stmt->fetch();
}

// جلب الروابط الاجتماعية
$social_stmt = $pdo->prepare("SELECT platform, url FROM social_links WHERE branch_id = ?");
$social_stmt->execute([$branch_id]);
$social_links = [];
foreach($social_stmt->fetchAll() as $sl) {
    $social_links[$sl['platform']] = $sl['url'];
}

$success = '';
$error   = '';

// ===== حفظ الإعدادات =====
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_require();

    if($_POST['action'] === 'save_currency') {
        $pdo->prepare("
            UPDATE branch_settings SET
                currency_code = ?,
                currency_symbol = ?,
                currency_symbol_en = ?,
                currency_decimals = ?
            WHERE branch_id = ?
        ")->execute([
            trim($_POST['currency_code']),
            trim($_POST['currency_symbol']),
            trim($_POST['currency_symbol_en']),
            intval($_POST['currency_decimals']),
            $branch_id
        ]);
        $success = 'تم حفظ إعدادات العملة!';
    }

    // [removed] save_payment — قسم شام كاش تم إلغاؤه (الدفع نقدي للكاشير فقط)

    if($_POST['action'] === 'save_notifications') {
        $whatsapp_enabled = isset($_POST['whatsapp_notifications']) ? 1 : 0;
        $pdo->prepare("
            UPDATE branch_settings SET
                whatsapp_notifications = ?,
                whatsapp_notify_number = ?,
                callmebot_apikey = ?,
                welcome_message = ?,
                welcome_message_en = ?
            WHERE branch_id = ?
        ")->execute([
            $whatsapp_enabled,
            trim($_POST['whatsapp_number'] ?? ''),
            trim($_POST['callmebot_key'] ?? ''),
            trim($_POST['welcome_message'] ?? ''),
            trim($_POST['welcome_message_en'] ?? ''),
            $branch_id
        ]);
        $success = 'تم حفظ إعدادات الإشعارات!';
    }

    if($_POST['action'] === 'save_social') {
        $platforms = ['instagram', 'facebook', 'whatsapp', 'twitter', 'tiktok', 'website'];
        // حذف القديمة
        $pdo->prepare("DELETE FROM social_links WHERE branch_id = ?")->execute([$branch_id]);
        // إضافة الجديدة
        foreach($platforms as $platform) {
            $url = trim($_POST["social_$platform"] ?? '');
            if($url) {
                $pdo->prepare("INSERT INTO social_links (branch_id, platform, url) VALUES (?, ?, ?)")
                    ->execute([$branch_id, $platform, $url]);
            }
        }
        $success = 'تم حفظ روابط التواصل!';
    }

    if($success) {
        // إعادة تحميل البيانات
        $settings_stmt->execute([$branch_id]);
        $settings = $settings_stmt->fetch();
        $social_stmt->execute([$branch_id]);
        $social_links = [];
        foreach($social_stmt->fetchAll() as $sl) {
            $social_links[$sl['platform']] = $sl['url'];
        }
    }
}

require_once 'sidebar.php';
?>
<style>
.settings-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--ink2);text-decoration:none;font-size:12px;font-weight:700;margin-bottom:8px;transition:color .2s;}
.back-link:hover{color:var(--p);}
.back-link svg{width:14px;height:14px;}
.branch-badge{display:inline-flex;align-items:center;gap:5px;background:color-mix(in srgb,var(--p) 10%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);color:var(--p);padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;}

.settings-grid{display:grid;gap:18px;}

.fc{background:var(--bg2);border:1px solid var(--line);border-radius:18px;overflow:hidden;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;}
.fc:nth-child(1){animation-delay:.05s}.fc:nth-child(2){animation-delay:.1s}.fc:nth-child(3){animation-delay:.15s}.fc:nth-child(4){animation-delay:.2s}
@keyframes cardIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}
.fc-head{padding:16px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;}
.fc-head-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.fc-head-icon svg{width:17px;height:17px;}
.fc-title{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:var(--ink);}
.fc-body{padding:18px 20px;}
.fc-footer{padding:12px 20px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;}

/* Currency preview */
.currency-preview{background:var(--bg3);border:1px solid var(--line);border-radius:12px;padding:14px;margin-top:12px;text-align:center;}
.currency-preview-label{font-size:11px;color:var(--ink2);font-weight:700;margin-bottom:6px;}
.currency-preview-val{font-family:'Fraunces',serif;font-size:24px;font-weight:900;color:var(--p);}

/* Toggle switch */
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);}
.toggle-row:last-child{border-bottom:none;}
.toggle-info{flex:1;}
.toggle-label{font-size:13px;font-weight:700;color:var(--ink);}
.toggle-sub{font-size:11px;color:var(--ink2);margin-top:2px;}
.switch{position:relative;width:44px;height:24px;flex-shrink:0;}
.switch input{opacity:0;width:0;height:0;}
.slider{position:absolute;inset:0;background:var(--bg4);border-radius:24px;cursor:pointer;transition:.3s;border:1.5px solid var(--line);}
.slider:before{content:'';position:absolute;width:18px;height:18px;border-radius:50%;background:var(--ink2);top:2px;right:2px;transition:.3s;}
.switch input:checked+.slider{background:var(--p);border-color:var(--p);}
.switch input:checked+.slider:before{transform:translateX(-20px);background:#fff;}

/* Social grid */
.social-grid{display:grid;gap:12px;}
.social-row{display:flex;align-items:center;gap:10px;}
.social-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;border:1px solid var(--line);}
.social-row input{flex:1;}
</style>

<div class="main">

    <a href="branches.php" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
        رجوع للفروع
    </a>

    <div class="settings-header">
        <div>
            <div class="page-title">إعدادات الفرع</div>
            <div class="page-subtitle">
                <span class="branch-badge">🏠 <?= htmlspecialchars($branch['name']) ?></span>
            </div>
        </div>
    </div>

    <?php if($success): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <div class="settings-grid">

        <!-- ===== العملة ===== -->
        <form method="POST" class="fc">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_currency">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:rgba(245,158,11,.12);color:#F59E0B">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <span class="fc-title">العملة</span>
            </div>
            <div class="fc-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label>رمز العملة (عربي)</label>
                        <input type="text" name="currency_symbol" value="<?= htmlspecialchars($settings['currency_symbol'] ?? 'ل.س') ?>" placeholder="ل.س">
                    </div>
                    <div class="form-group">
                        <label>رمز العملة (English)</label>
                        <input type="text" name="currency_symbol_en" value="<?= htmlspecialchars($settings['currency_symbol_en'] ?? 'S.P') ?>" placeholder="S.P">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>كود العملة</label>
                        <input type="text" name="currency_code" value="<?= htmlspecialchars($settings['currency_code'] ?? 'SYP') ?>" placeholder="SYP">
                    </div>
                    <div class="form-group">
                        <label>الكسور العشرية</label>
                        <select name="currency_decimals">
                            <option value="0" <?= ($settings['currency_decimals'] ?? 0) == 0 ? 'selected' : '' ?>>0 (بدون كسور)</option>
                            <option value="1" <?= ($settings['currency_decimals'] ?? 0) == 1 ? 'selected' : '' ?>>1</option>
                            <option value="2" <?= ($settings['currency_decimals'] ?? 0) == 2 ? 'selected' : '' ?>>2</option>
                        </select>
                    </div>
                </div>
                <div class="currency-preview">
                    <div class="currency-preview-label">معاينة</div>
                    <div class="currency-preview-val" id="currencyPreview">
                        <?= htmlspecialchars($settings['currency_symbol'] ?? 'ل.س') ?> <?= number_format(25000, $settings['currency_decimals'] ?? 0) ?>
                    </div>
                </div>
            </div>
            <div class="fc-footer">
                <button type="submit" class="btn btn-primary btn-sm">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    حفظ العملة
                </button>
            </div>
        </form>

        <!-- [removed] قسم طرق الدفع — شام كاش تم إلغاؤه. الدفع نقدي للكاشير فقط. -->

        <!-- ===== الإشعارات ===== -->
        <form method="POST" class="fc">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_notifications">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:rgba(34,197,94,.12);color:#22C55E">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                </div>
                <span class="fc-title">الإشعارات</span>
            </div>
            <div class="fc-body">
                <div class="toggle-row">
                    <div class="toggle-info">
                        <div class="toggle-label">إشعارات WhatsApp</div>
                        <div class="toggle-sub">إرسال إشعار عند وصول طلب جديد</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="whatsapp_notifications" <?= ($settings['whatsapp_notifications'] ?? 0) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="form-row-2" style="margin-top:14px;">
                    <div class="form-group">
                        <label>رقم WhatsApp</label>
                        <input type="tel" name="whatsapp_number" value="<?= htmlspecialchars($settings['whatsapp_notify_number'] ?? '') ?>" placeholder="963xxxxxxxxx">
                    </div>
                    <div class="form-group">
                        <label>CallMeBot API Key</label>
                        <input type="text" name="callmebot_key" value="<?= htmlspecialchars($settings['callmebot_apikey'] ?? '') ?>" placeholder="xxxxxx">
                    </div>
                </div>
                <div class="form-group">
                    <label>رسالة ترحيب (عربي)</label>
                    <textarea name="welcome_message" rows="2" placeholder="أهلاً وسهلاً..."><?= htmlspecialchars($settings['welcome_message'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>رسالة ترحيب (English)</label>
                    <textarea name="welcome_message_en" rows="2" placeholder="Welcome..."><?= htmlspecialchars($settings['welcome_message_en'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="fc-footer">
                <button type="submit" class="btn btn-primary btn-sm">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    حفظ الإشعارات
                </button>
            </div>
        </form>

        <!-- ===== روابط التواصل ===== -->
        <form method="POST" class="fc">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_social">
            <div class="fc-head">
                <div class="fc-head-icon" style="background:rgba(139,92,246,.12);color:#8B5CF6">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                </div>
                <span class="fc-title">روابط التواصل</span>
            </div>
            <div class="fc-body">
                <div class="social-grid">
                    <?php
                    $social_platforms = [
                        'instagram' => ['📸', 'Instagram', 'https://instagram.com/...'],
                        'facebook'  => ['📘', 'Facebook',  'https://facebook.com/...'],
                        'whatsapp'  => ['💬', 'WhatsApp',  'https://wa.me/963...'],
                        'twitter'   => ['🐦', 'Twitter/X', 'https://x.com/...'],
                        'tiktok'    => ['🎵', 'TikTok',    'https://tiktok.com/@...'],
                        'website'   => ['🌐', 'الموقع',    'https://...'],
                    ];
                    foreach($social_platforms as $key => [$icon, $label, $placeholder]):
                    ?>
                    <div class="social-row">
                        <div class="social-icon" style="background:var(--bg3)"><?= $icon ?></div>
                        <input type="url" name="social_<?= $key ?>" 
                               value="<?= htmlspecialchars($social_links[$key] ?? '') ?>" 
                               placeholder="<?= $placeholder ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="fc-footer">
                <button type="submit" class="btn btn-primary btn-sm">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    حفظ الروابط
                </button>
            </div>
        </form>

    </div>
</div>

<script>
// معاينة العملة
document.querySelectorAll('[name="currency_symbol"], [name="currency_decimals"]').forEach(el => {
    el.addEventListener('input', updatePreview);
    el.addEventListener('change', updatePreview);
});

function updatePreview() {
    const sym = document.querySelector('[name="currency_symbol"]').value || 'ل.س';
    const dec = parseInt(document.querySelector('[name="currency_decimals"]').value) || 0;
    const num = (25000).toLocaleString('en', {minimumFractionDigits: dec, maximumFractionDigits: dec});
    document.getElementById('currencyPreview').textContent = sym + ' ' + num;
}
</script>
</body>
</html>