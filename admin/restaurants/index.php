<?php
session_start();
require_once '../../config/database.php';
if(!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }

$success = '';
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if($_POST['action'] === 'toggle') {
        $pdo->prepare("UPDATE restaurants SET is_active = NOT is_active WHERE id=?")->execute([$_POST['id']]);
        $success = 'تم تغيير الحالة!';
    }
    if($_POST['action'] === 'delete') {
        $pdo->prepare("DELETE FROM restaurants WHERE id=?")->execute([$_POST['id']]);
        $success = 'تم حذف المطعم!';
    }
}

$search = trim($_GET['q'] ?? '');
$plan   = $_GET['plan'] ?? 'all';
$status = $_GET['status'] ?? 'all';

$where = 'WHERE 1=1'; $params = [];
if($search) { $where .= ' AND (r.name LIKE ? OR r.email LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if($plan !== 'all')   { $where .= ' AND r.subscription_plan=?'; $params[] = $plan; }
if($status === 'active')   { $where .= ' AND r.is_active=1'; }
if($status === 'inactive') { $where .= ' AND r.is_active=0'; }

$restaurants = $pdo->prepare("
    SELECT r.*,
        COUNT(DISTINCT d.id) as dish_count,
        COUNT(DISTINCT o.id) as order_count,
        DATEDIFF(r.subscription_expiry, CURDATE()) as days_left
    FROM restaurants r
    LEFT JOIN dishes d ON d.restaurant_id=r.id
    LEFT JOIN orders o ON o.restaurant_id=r.id
    $where GROUP BY r.id ORDER BY r.created_at DESC
");
$restaurants->execute($params);
$restaurants = $restaurants->fetchAll();

require_once '../sidebar.php';
?>
<style>
/* Search bar */
.search-wrap {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 14px; padding: 14px 16px;
    margin-bottom: 16px;
    display: flex; gap: 9px; flex-wrap: wrap; align-items: flex-end;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) .1s both;
}
.search-input-wrap {
    flex: 2; min-width: 180px; position: relative;
}
.search-input-wrap svg {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    width: 14px; height: 14px; color: var(--ink3); pointer-events: none;
}
.search-input-wrap input {
    padding: 10px 36px 10px 14px; width: 100%;
}

/* Restaurant cards */
.rcard {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 14px; margin-bottom: 9px; overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
    animation: cardIn 0.35s cubic-bezier(0.22,1,0.36,1) both;
    display: flex; align-items: center; padding: 13px 16px;
    gap: 12px; flex-wrap: wrap; position: relative;
}
.rcard:hover { transform: translateX(-2px); box-shadow: var(--shadow2); }
.rcard::before {
    content: ''; position: absolute;
    right: 0; top: 0; bottom: 0; width: 3px; border-radius: 0 14px 14px 0;
}
.rcard.is-active::before   { background: #22C55E; }
.rcard.is-inactive::before { background: #EF4444; }
.rcard.is-inactive         { opacity: 0.75; }

.r-logo {
    width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
    border: 1px solid var(--line); background: var(--bg3); overflow: hidden;
    display: flex; align-items: center; justify-content: center;
}
.r-logo img { width:100%;height:100%;object-fit:cover; }
.r-logo svg { width:18px;height:18px;color:var(--ink3); }

.r-info { flex: 1; min-width: 160px; }
.r-name { font-size: 14px; font-weight: 700; color: var(--ink); }
.r-email{ font-size: 11px; color: var(--ink2); font-weight: 500; margin-top: 2px; }
.r-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
.r-chip {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 700; color: var(--ink2);
    background: var(--bg3); border: 1px solid var(--line);
    padding: 3px 9px; border-radius: 20px;
}
.r-chip svg { width: 10px; height: 10px; }

.r-actions { display: flex; gap: 7px; flex-wrap: wrap; align-items: center; }

/* Expiry warn */
.exp-chip {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 20px;
}
.exp-warn   { background: rgba(249,115,22,0.10); border:1px solid rgba(249,115,22,0.18); color:#F97316; }
.exp-danger { background: rgba(239,68,68,0.08);  border:1px solid rgba(239,68,68,0.15);  color:#EF4444; }
</style>

<div class="main">

    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
            <div class="page-title">المطاعم</div>
            <div class="page-subtitle"><?= count($restaurants) ?> مطعم</div>
        </div>
        <a href="add.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:13px;height:13px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            إضافة مطعم
        </a>
    </div>

    <?php if($success): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= $success ?>
    </div>
    <?php endif; ?>

    <!-- Search & filter -->
    <form method="GET" class="search-wrap">
        <div class="search-input-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="q" placeholder="بحث باسم أو إيميل..."
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <select name="plan" class="filter-select" onchange="this.form.submit()">
            <option value="all"      <?= $plan==='all'?'selected':'' ?>>كل الباقات</option>
            <option value="basic"    <?= $plan==='basic'?'selected':'' ?>>أساسية</option>
            <option value="advanced" <?= $plan==='advanced'?'selected':'' ?>>متقدمة</option>
            <option value="premium"  <?= $plan==='premium'?'selected':'' ?>>بريميوم</option>
        </select>
        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="all"      <?= $status==='all'?'selected':'' ?>>كل الحالات</option>
            <option value="active"   <?= $status==='active'?'selected':'' ?>>نشط</option>
            <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>موقوف</option>
        </select>
        <button type="submit" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            بحث
        </button>
        <?php if($search || $plan!=='all' || $status!=='all'): ?>
        <a href="index.php" class="btn btn-secondary">مسح</a>
        <?php endif; ?>
    </form>

    <!-- List -->
    <?php if(empty($restaurants)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/>
                    <line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>
                </svg>
            </div>
            <p>ما في مطاعم</p>
        </div>
    <?php else: ?>
        <?php foreach($restaurants as $idx => $r):
            $days = intval($r['days_left']);
        ?>
        <div class="rcard <?= $r['is_active'] ? 'is-active' : 'is-inactive' ?>" style="animation-delay:<?= min($idx*0.03,0.5) ?>s">
            <!-- Logo -->
            <div class="r-logo">
                <?php if($r['logo']): ?>
                    <img src="<?= BASE_URL ?>/assets/uploads/<?= $r['logo'] ?>">
                <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/></svg>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="r-info">
                <div class="r-name"><?= htmlspecialchars($r['name']) ?></div>
                <div class="r-email"><?= htmlspecialchars($r['email']) ?></div>
                <div class="r-meta">
                    <span class="r-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 2h13l5 5v15a1 1 0 01-1 1H3a1 1 0 01-1-1V3a1 1 0 011-1z"/></svg>
                        <?= $r['dish_count'] ?> طبق
                    </span>
                    <span class="r-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
                        <?= $r['order_count'] ?> طلب
                    </span>
                    <span class="r-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?= $r['subscription_expiry'] ? date('d/m/Y', strtotime($r['subscription_expiry'])) : '—' ?>
                    </span>
                    <?php if($days > 0 && $days <= 7): ?>
                        <span class="exp-chip exp-warn">ينتهي خلال <?= $days ?> أيام</span>
                    <?php elseif($days <= 0 && $r['subscription_expiry']): ?>
                        <span class="exp-chip exp-danger">منتهي</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Badges -->
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:7px;">
                <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                    <span class="badge badge-<?= $r['subscription_plan'] ?>"><?= $r['subscription_plan'] ?></span>
                    <span class="badge <?= $r['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                        <?= $r['is_active'] ? 'نشط' : 'موقوف' ?>
                    </span>
                </div>
                <!-- Actions -->
                <div class="r-actions">
                    <a href="<?= BASE_URL ?>/menu/<?= $r['slug'] ?>" target="_blank" class="btn btn-sm btn-secondary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:11px;height:11px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        منيو
                    </a>
                    <a href="edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:11px;height:11px"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        تعديل
                    </a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button type="submit" class="btn btn-sm <?= $r['is_active'] ? 'btn-warning' : 'btn-success' ?>">
                            <?php if($r['is_active']): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:11px;height:11px"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                                إيقاف
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:11px;height:11px"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                تفعيل
                            <?php endif; ?>
                        </button>
                    </form>
                    <button class="btn btn-sm btn-danger"
                            onclick="confirmDelete(<?= $r['id'] ?>,'<?= htmlspecialchars($r['name'],ENT_QUOTES) ?>')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:11px;height:11px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

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
        <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.15);border-radius:12px;padding:14px 16px;margin-bottom:18px;">
            <div style="font-size:13px;font-weight:700;color:#EF4444;margin-bottom:4px;">تحذير — هذا الإجراء لا يمكن التراجع عنه</div>
            <div style="font-size:12px;color:var(--ink2);">
                رح تحذف مطعم <strong id="deleteRestName" style="color:var(--ink)"></strong> مع كل بياناته وأطباقه وطلباته.
            </div>
        </div>
        <form method="POST" class="modal-footer">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteRestId">
            <button type="button" class="btn btn-secondary" style="flex:1"
                    onclick="document.getElementById('deleteModal').classList.remove('open')">إلغاء</button>
            <button type="submit" class="btn btn-danger" style="flex:1">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                حذف نهائي
            </button>
        </form>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    document.getElementById('deleteRestId').value = id;
    document.getElementById('deleteRestName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
document.querySelectorAll('.modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) { if(e.target===bg) bg.classList.remove('open'); });
});
</script>
</body></html>