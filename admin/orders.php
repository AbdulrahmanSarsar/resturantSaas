<?php
session_start();
require_once '../config/database.php';
if(!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

$filter_rest   = intval($_GET['restaurant'] ?? 0);
$filter_date   = $_GET['date'] ?? '';
$filter_status = $_GET['status'] ?? 'all';

$where = 'WHERE 1=1'; $params = [];
if($filter_rest)              { $where .= ' AND o.restaurant_id=?'; $params[] = $filter_rest; }
if($filter_date)              { $where .= ' AND DATE(o.created_at)=?'; $params[] = $filter_date; }
if($filter_status !== 'all')  { $where .= ' AND o.status=?'; $params[] = $filter_status; }

$orders = $pdo->prepare("
    SELECT o.*, r.name as restaurant_name
    FROM orders o JOIN restaurants r ON r.id=o.restaurant_id
    $where ORDER BY o.created_at DESC LIMIT 100
");
$orders->execute($params);
$orders = $orders->fetchAll();

$restaurants = $pdo->query("SELECT id, name FROM restaurants ORDER BY name")->fetchAll();

$today_stats = $pdo->query("
    SELECT COUNT(*) as total,
           SUM(status='pending') as pending,
           SUM(status='cancelled') as cancelled,
           COUNT(DISTINCT restaurant_id) as rest_count
    FROM orders WHERE DATE(created_at)=CURDATE()
")->fetch();

require_once 'sidebar.php';
?>
<style>
/* Stats */
.ord-pills {
    display: grid; grid-template-columns: repeat(4,1fr);
    gap: 12px; margin-bottom: 20px;
}
@media (max-width:700px) { .ord-pills { grid-template-columns: repeat(2,1fr); } }

.ord-pill {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 16px; padding: 15px 16px;
    display: flex; align-items: center; gap: 11px;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;
}
.ord-pill:nth-child(1){animation-delay:.04s}
.ord-pill:nth-child(2){animation-delay:.08s}
.ord-pill:nth-child(3){animation-delay:.12s}
.ord-pill:nth-child(4){animation-delay:.16s}

.ord-pill-icon {
    width: 38px; height: 38px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.ord-pill-icon svg { width: 17px; height: 17px; }
.ord-pill-num {
    font-family: 'Fraunces', serif;
    font-size: 22px; font-weight: 900; color: var(--ink); line-height: 1;
}
.ord-pill-lbl { font-size: 11px; color: var(--ink2); font-weight: 600; margin-top: 2px; }

/* Filters */
.filters-wrap {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 16px; padding: 16px 18px;
    margin-bottom: 18px;
    display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;
    animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) .2s both;
}
.filters-wrap .fg { margin: 0; flex: 1; min-width: 130px; }
.filters-wrap .fg label { text-transform: uppercase; font-size: 10px; letter-spacing: 1px; margin-bottom: 6px; }

/* Order cards */
.orders-list { display: flex; flex-direction: column; gap: 8px; }

.ocard {
    background: var(--bg2); border: 1px solid var(--line);
    border-radius: 14px; overflow: hidden;
    display: flex; align-items: center;
    padding: 13px 16px; gap: 12px; flex-wrap: wrap;
    transition: transform 0.2s, box-shadow 0.2s;
    animation: cardIn 0.35s cubic-bezier(0.22,1,0.36,1) both;
    position: relative;
}
.ocard:hover { transform: translateX(-2px); box-shadow: var(--shadow2); }
/* Status strip */
.ocard::before {
    content: ''; position: absolute;
    right: 0; top: 0; bottom: 0; width: 3px; border-radius: 0 14px 14px 0;
}
.ocard.s-pending::before   { background: #F59E0B; }
.ocard.s-confirmed::before { background: #3B82F6; }
.ocard.s-preparing::before { background: #8B5CF6; }
.ocard.s-ready::before     { background: #22C55E; }
.ocard.s-delivered::before { background: var(--ink3); }
.ocard.s-cancelled::before { background: #EF4444; }
.ocard.s-cancelled         { opacity: 0.6; }

.ocard-id {
    font-family: 'Fraunces', serif;
    font-size: 16px; font-weight: 900; color: var(--ink);
    min-width: 42px; flex-shrink: 0;
}
.ocard-rest { flex: 1; min-width: 120px; }
.ocard-rest-name { font-size: 13px; font-weight: 700; color: var(--ink); }
.ocard-rest-sub  { font-size: 11px; color: var(--ink2); font-weight: 500; margin-top: 2px; display: flex; gap: 8px; flex-wrap: wrap; }
.ocard-rest-sub span { display: flex; align-items: center; gap: 4px; }
.ocard-rest-sub svg  { width: 11px; height: 11px; }
.ocard-time { font-size: 11px; color: var(--ink2); font-weight: 600; white-space: nowrap; }
</style>

<div class="main">

    <div style="margin-bottom:20px;">
        <div class="page-title">الطلبات</div>
        <div class="page-subtitle">كل طلبات المنصة</div>
    </div>

    <!-- Pills -->
    <div class="ord-pills">
        <div class="ord-pill">
            <div class="ord-pill-icon" style="background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
            </div>
            <div><div class="ord-pill-num"><?= $today_stats['total'] ?></div><div class="ord-pill-lbl">طلبات اليوم</div></div>
        </div>
        <div class="ord-pill">
            <div class="ord-pill-icon" style="background:rgba(245,158,11,0.12);color:#F59E0B">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div><div class="ord-pill-num"><?= $today_stats['pending'] ?></div><div class="ord-pill-lbl">معلقة</div></div>
        </div>
        <div class="ord-pill">
            <div class="ord-pill-icon" style="background:rgba(239,68,68,0.10);color:#EF4444">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div><div class="ord-pill-num"><?= $today_stats['cancelled'] ?></div><div class="ord-pill-lbl">ملغية</div></div>
        </div>
        <div class="ord-pill">
            <div class="ord-pill-icon" style="background:rgba(59,130,246,0.10);color:#3B82F6">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/></svg>
            </div>
            <div><div class="ord-pill-num"><?= $today_stats['rest_count'] ?></div><div class="ord-pill-lbl">مطاعم نشطة</div></div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="filters-wrap">
        <div class="fg">
            <label>المطعم</label>
            <select name="restaurant" class="filter-select" style="width:100%">
                <option value="0">كل المطاعم</option>
                <?php foreach($restaurants as $rest): ?>
                <option value="<?= $rest['id'] ?>" <?= $filter_rest==$rest['id']?'selected':'' ?>>
                    <?= htmlspecialchars($rest['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>الحالة</label>
            <select name="status" class="filter-select" style="width:100%">
                <option value="all"       <?= $filter_status==='all'?'selected':'' ?>>كل الحالات</option>
                <option value="pending"   <?= $filter_status==='pending'?'selected':'' ?>>معلق</option>
                <option value="confirmed" <?= $filter_status==='confirmed'?'selected':'' ?>>مؤكد</option>
                <option value="preparing" <?= $filter_status==='preparing'?'selected':'' ?>>بالتحضير</option>
                <option value="ready"     <?= $filter_status==='ready'?'selected':'' ?>>جاهز</option>
                <option value="delivered" <?= $filter_status==='delivered'?'selected':'' ?>>تم التسليم</option>
                <option value="cancelled" <?= $filter_status==='cancelled'?'selected':'' ?>>ملغي</option>
            </select>
        </div>
        <div class="fg">
            <label>التاريخ</label>
            <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>">
        </div>
        <button type="submit" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            فلتر
        </button>
        <a href="orders.php" class="btn btn-secondary">مسح</a>
    </form>

    <!-- Orders -->
    <?php if(empty($orders)): ?>
        <div class="empty-state">
            <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg></div>
            <p>ما في طلبات</p>
        </div>
    <?php else: ?>
    <?php
    $status_labels = ['pending'=>'معلق','confirmed'=>'مؤكد','preparing'=>'بالتحضير','ready'=>'جاهز','delivered'=>'تم','cancelled'=>'ملغي'];
    ?>
    <div class="orders-list">
        <?php foreach($orders as $idx => $o): ?>
        <div class="ocard s-<?= $o['status'] ?>" style="animation-delay:<?= min($idx*0.03,0.4) ?>s">
            <div class="ocard-id">#<?= $o['id'] ?></div>
            <div class="ocard-rest">
                <div class="ocard-rest-name"><?= htmlspecialchars($o['restaurant_name']) ?></div>
                <div class="ocard-rest-sub">
                    <span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="9" y1="4" x2="9" y2="20"/></svg>
                        طاولة <?= htmlspecialchars($o['table_number']) ?>
                    </span>
                    <?php if($o['customer_name']): ?>
                    <span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <?= htmlspecialchars($o['customer_name']) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ocard-time"><?= date('d/m H:i', strtotime($o['created_at'])) ?></div>
            <span class="badge badge-<?= $o['status'] ?>"><?= $status_labels[$o['status']] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="text-align:center;color:var(--ink2);font-size:12px;margin-top:14px;font-weight:600;">
        عرض <?= count($orders) ?> طلب
    </div>
    <?php endif; ?>

</div>
</body></html>