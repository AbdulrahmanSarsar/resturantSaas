<?php
session_start();
require_once '../config/database.php';
if(!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

$success = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare("UPDATE restaurants SET subscription_plan=?, subscription_expiry=? WHERE id=?")
        ->execute([$_POST['plan'], $_POST['expiry'], $_POST['restaurant_id']]);
    $pdo->prepare("INSERT INTO subscriptions (restaurant_id, plan, price, start_date, end_date, is_active) VALUES (?,?,?,CURDATE(),?,1)")
        ->execute([$_POST['restaurant_id'], $_POST['plan'], $_POST['price'] ?? 0, $_POST['expiry']]);
    $success = 'تم تحديث الاشتراك!';
}

$restaurants = $pdo->query("
    SELECT r.*, DATEDIFF(r.subscription_expiry, CURDATE()) as days_left
    FROM restaurants r ORDER BY days_left ASC
")->fetchAll();

$stats = $pdo->query("
    SELECT
        SUM(subscription_plan='basic') as basic,
        SUM(subscription_plan='advanced') as advanced,
        SUM(subscription_plan='premium') as premium,
        SUM(subscription_expiry < CURDATE()) as expired,
        SUM(subscription_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) as expiring
    FROM restaurants
")->fetch();

require_once 'sidebar.php';
?>
<style>
/* Pills */
.sub-pills {
    display: grid; grid-template-columns: repeat(5,1fr);
    gap: 11px; margin-bottom: 20px;
}
@media (max-width:900px) { .sub-pills { grid-template-columns: repeat(3,1fr); } }
@media (max-width:500px) { .sub-pills { grid-template-columns: repeat(2,1fr); } }

.sub-pill {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 14px; padding: 14px 12px; text-align: center;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
    transition: transform 0.2s;
}
.sub-pill:hover { transform: translateY(-2px); }
.sub-pill:nth-child(1){animation-delay:.04s}
.sub-pill:nth-child(2){animation-delay:.07s}
.sub-pill:nth-child(3){animation-delay:.10s}
.sub-pill:nth-child(4){animation-delay:.13s}
.sub-pill:nth-child(5){animation-delay:.16s}
.sp-num {
    font-family: 'Fraunces', serif;
    font-size: 26px; font-weight: 900; color: var(--ink); line-height: 1; margin-bottom: 3px;
}
.sp-lbl { font-size: 11px; color: var(--ink2); font-weight: 600; }

/* Sub cards */
.sub-card {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 14px; margin-bottom: 9px; overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
    animation: cardIn 0.35s cubic-bezier(0.22,1,0.36,1) both;
    display: flex; align-items: center;
    padding: 13px 16px; gap: 12px; flex-wrap: wrap;
    position: relative;
}
.sub-card:hover { transform: translateX(-2px); box-shadow: var(--shadow2); }
/* Urgency strip */
.sub-card::before {
    content: ''; position: absolute;
    right: 0; top: 0; bottom: 0; width: 3px; border-radius: 0 14px 14px 0;
}
.urg-ok::before      { background: var(--line); }
.urg-warning::before { background: #F59E0B; }
.urg-danger::before  { background: #F97316; }
.urg-expired::before { background: #EF4444; }

.sub-logo {
    width: 40px; height: 40px; border-radius: 11px;
    border: 1px solid var(--line); background: var(--bg3);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden;
}
.sub-logo img { width:100%;height:100%;object-fit:cover; }
.sub-logo svg { width:16px;height:16px;color:var(--ink3); }

.sub-info { flex: 1; min-width: 140px; }
.sub-name { font-size: 13px; font-weight: 700; color: var(--ink); }
.sub-expiry-txt { font-size: 11px; color: var(--ink2); font-weight: 500; margin-top: 2px; }

.days-badge {
    padding: 4px 12px; border-radius: 20px;
    font-size: 12px; font-weight: 700; white-space: nowrap;
}
.days-ok      { background: rgba(34,197,94,0.10);  border:1px solid rgba(34,197,94,0.18);  color:#22C55E; }
.days-warning { background: rgba(245,158,11,0.10); border:1px solid rgba(245,158,11,0.18); color:#F59E0B; }
.days-danger  { background: rgba(249,115,22,0.10); border:1px solid rgba(249,115,22,0.18); color:#F97316; }
.days-expired { background: rgba(239,68,68,0.08);  border:1px solid rgba(239,68,68,0.15);  color:#EF4444; }

.renew-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 10px;
    background: var(--p); color: #fff;
    border: none; font-size: 12px; font-weight: 700;
    cursor: pointer; font-family: 'Tajawal', sans-serif;
    transition: opacity 0.2s, transform 0.2s;
    box-shadow: 0 3px 10px var(--p-glow); flex-shrink: 0;
}
.renew-btn svg { width: 12px; height: 12px; }
.renew-btn:hover { opacity: 0.88; transform: translateY(-1px); }
</style>

<div class="main">

    <div style="margin-bottom:20px;">
        <div class="page-title">الاشتراكات</div>
        <div class="page-subtitle">إدارة اشتراكات المطاعم</div>
    </div>

    <?php if($success): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= $success ?>
    </div>
    <?php endif; ?>

    <!-- Pills -->
    <div class="sub-pills">
        <div class="sub-pill">
            <div class="sp-num" style="color:#3B82F6"><?= $stats['basic'] ?></div>
            <div class="sp-lbl">أساسية</div>
        </div>
        <div class="sub-pill">
            <div class="sp-num" style="color:#8B5CF6"><?= $stats['advanced'] ?></div>
            <div class="sp-lbl">متقدمة</div>
        </div>
        <div class="sub-pill">
            <div class="sp-num" style="color:#F59E0B"><?= $stats['premium'] ?></div>
            <div class="sp-lbl">بريميوم</div>
        </div>
        <div class="sub-pill">
            <div class="sp-num" style="color:#<?= $stats['expiring']>0?'F97316':'ink' ?>"><?= $stats['expiring'] ?></div>
            <div class="sp-lbl">ينتهي قريباً</div>
        </div>
        <div class="sub-pill">
            <div class="sp-num" style="color:<?= $stats['expired']>0?'#EF4444':'var(--ink)' ?>"><?= $stats['expired'] ?></div>
            <div class="sp-lbl">منتهي</div>
        </div>
    </div>

    <!-- Cards -->
    <?php foreach($restaurants as $idx => $r):
        $days = intval($r['days_left']);
        if($days > 14)    { $dc='days-ok';      $dt="$days يوم"; $uc='urg-ok'; }
        elseif($days > 7) { $dc='days-warning'; $dt="$days يوم"; $uc='urg-warning'; }
        elseif($days > 0) { $dc='days-danger';  $dt="$days يوم"; $uc='urg-danger'; }
        else              { $dc='days-expired'; $dt='منتهي';     $uc='urg-expired'; }
    ?>
    <div class="sub-card <?= $uc ?>" style="animation-delay:<?= min($idx*0.03+0.2,0.6) ?>s">
        <div class="sub-logo">
            <?php if($r['logo']): ?>
                <img src="<?= BASE_URL ?>/assets/uploads/<?= $r['logo'] ?>">
            <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/></svg>
            <?php endif; ?>
        </div>
        <div class="sub-info">
            <div class="sub-name"><?= htmlspecialchars($r['name']) ?></div>
            <div class="sub-expiry-txt">
                ينتهي: <?= $r['subscription_expiry'] ? date('d/m/Y', strtotime($r['subscription_expiry'])) : '—' ?>
            </div>
        </div>
        <span class="badge badge-<?= $r['subscription_plan'] ?>"><?= $r['subscription_plan'] ?></span>
        <span class="days-badge <?= $dc ?>"><?= $dt ?></span>
        <button class="renew-btn"
                onclick="openRenew(<?= $r['id'] ?>,'<?= htmlspecialchars($r['name'],ENT_QUOTES) ?>','<?= $r['subscription_plan'] ?>','<?= $r['subscription_expiry'] ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <polyline points="23 4 23 10 17 10"/>
                <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
            </svg>
            تجديد
        </button>
    </div>
    <?php endforeach; ?>
</div>

<!-- Renew Modal -->
<div class="modal-bg" id="renewModal">
    <div class="modal">
        <div class="modal-header">
            <h3>تجديد الاشتراك</h3>
            <button class="modal-close" onclick="document.getElementById('renewModal').classList.remove('open')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <p id="renewRestName" style="font-size:13px;color:var(--ink2);font-weight:600;margin-bottom:18px;"></p>
        <form method="POST">
            <input type="hidden" name="restaurant_id" id="renewId">
            <div class="form-group">
                <label>الباقة</label>
                <select name="plan" id="renewPlan">
                    <option value="basic">أساسية — $80/شهر</option>
                    <option value="advanced">متقدمة — $180/شهر</option>
                    <option value="premium">بريميوم — $280/شهر</option>
                </select>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label>تاريخ الانتهاء الجديد</label>
                    <input type="date" name="expiry" id="renewExpiry" required>
                </div>
                <div class="form-group">
                    <label>السعر المدفوع ($)</label>
                    <input type="number" name="price" step="0.01" placeholder="0.00">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" style="flex:1"
                        onclick="document.getElementById('renewModal').classList.remove('open')">إلغاء</button>
                <button type="submit" class="btn btn-primary" style="flex:2">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    حفظ الاشتراك
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRenew(id, name, plan, expiry) {
    document.getElementById('renewId').value = id;
    document.getElementById('renewRestName').textContent = name;
    document.getElementById('renewPlan').value = plan;
    var base = expiry && new Date(expiry) > new Date() ? new Date(expiry) : new Date();
    base.setMonth(base.getMonth() + 1);
    document.getElementById('renewExpiry').value = base.toISOString().split('T')[0];
    document.getElementById('renewModal').classList.add('open');
}
document.querySelectorAll('.modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) { if(e.target===bg) bg.classList.remove('open'); });
});
</script>
</body></html>