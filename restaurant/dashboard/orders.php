<?php
session_start();
require_once '../../config/database.php';
require_once 'plan_guard.php';
if(!isset($_SESSION['restaurant_id'])) { header('Location: ../login.php'); exit; }
plan_required('premium');

$rid = $_SESSION['restaurant_id'];
$rest_data = $pdo->prepare("SELECT currency_symbol, currency_decimals FROM restaurants WHERE id=?");
$rest_data->execute([$rid]);
$rest_row = $rest_data->fetch();
$cur_symbol   = $rest_row['currency_symbol']   ?? '$';
$cur_decimals = intval($rest_row['currency_decimals'] ?? 2);
$cur_prefix   = in_array($cur_symbol, ['$', '€', '₺']);
if(!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $decimals, $is_prefix) {
        $formatted = number_format(floatval($amount), $decimals);
        return $is_prefix ? $symbol . $formatted : $symbol . ' ' . $formatted;
    }
}


if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    $pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=? AND restaurant_id=?")
        ->execute([$_POST['status'], $_POST['order_id'], $rid]);
    try {
        $pdo->prepare("INSERT INTO order_status_log (order_id,status,changed_at) VALUES (?,?,NOW())")
            ->execute([$_POST['order_id'], $_POST['status']]);
    } catch(Exception $e) {}
    if(isset($_POST['ajax'])) { echo json_encode(['success'=>true]); exit; }
}

$filter    = $_GET['status'] ?? 'all';
$date_mode = $_GET['date']   ?? 'today';

$where  = 'WHERE restaurant_id = ?';
$params = [$rid];
if($date_mode !== 'all') { $where .= ' AND DATE(created_at) = CURDATE()'; }
if($filter !== 'all')    { $where .= ' AND status = ?'; $params[] = $filter; }

$orders = $pdo->prepare("SELECT * FROM orders $where ORDER BY created_at DESC");
$orders->execute($params);
$orders = $orders->fetchAll();

$stats = $pdo->prepare("
    SELECT COUNT(*) as total,
        SUM(status='pending') as pending,
        SUM(status='preparing') as preparing,
        SUM(status='ready') as ready,
        SUM(status='delivered' AND DATE(created_at)=CURDATE()) as delivered_today,
        COALESCE(SUM(CASE WHEN status='delivered' AND DATE(created_at)=CURDATE() THEN total_price END),0) as revenue_today
    FROM orders WHERE restaurant_id=?
");
$stats->execute([$rid]);
$stats = $stats->fetch();

require_once 'sidebar.php';
?>
<style>
/* ===== STAT PILLS ===== */
.stat-pills{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;}
.stat-pill{
    display:flex;align-items:center;gap:10px;
    background:var(--bg2);border:1.5px solid var(--line);
    border-radius:16px;padding:12px 16px;
    text-decoration:none;transition:all .2s;
    flex:1;min-width:110px;cursor:pointer;
}
.stat-pill:hover{border-color:var(--p);transform:translateY(-2px);}
.stat-pill.active{background:color-mix(in srgb,var(--p) 8%,var(--bg2));border-color:var(--p);box-shadow:0 4px 14px color-mix(in srgb,var(--p) 18%,transparent);}
.pill-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.pill-num{font-family:'Fraunces',serif;font-size:20px;font-weight:900;color:var(--ink);letter-spacing:-.3px;line-height:1;}
.pill-label{font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px;}

/* ===== TOOLBAR ===== */
.toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.date-tabs{display:flex;gap:6px;}
.date-tab{padding:7px 14px;border-radius:20px;border:1.5px solid var(--line);background:var(--bg2);font-size:12px;font-weight:700;color:var(--ink2);text-decoration:none;transition:all .2s;font-family:'Tajawal',sans-serif;}
.date-tab:hover{border-color:var(--p);color:var(--p);}
.date-tab.active{background:var(--p);border-color:transparent;color:#fff;}
.live-indicator{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink2);font-weight:600;}
.live-dot{width:7px;height:7px;background:#22C55E;border-radius:50%;animation:liveBlink 1.5s infinite;}
@keyframes liveBlink{0%,100%{opacity:1}50%{opacity:.25}}
.sound-btn{padding:6px 12px;background:var(--bg3);border:1px solid var(--line);border-radius:20px;font-size:11px;font-weight:700;color:var(--ink2);cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;}
.sound-btn:hover{border-color:var(--p);color:var(--p);}

/* ===== ORDER CARDS ===== */
.orders-list{display:flex;flex-direction:column;gap:12px;}

.order-card{
    background:var(--bg2);border:1px solid var(--line);
    border-radius:18px;overflow:hidden;
    animation:cardIn .35s cubic-bezier(.22,1,.36,1) both;
    display:flex;flex-direction:column;
}
@keyframes cardIn{from{opacity:0;transform:translateY(10px) scale(.98);}to{opacity:1;transform:none;}}

/* شريط اللون على اليمين */
.order-card::before{content:'';position:absolute;right:0;top:0;bottom:0;width:3px;border-radius:0 18px 18px 0;}
.order-card{position:relative;}
.order-card.status-pending::before{background:#F59E0B;}
.order-card.status-confirmed::before{background:#3B82F6;}
.order-card.status-preparing::before{background:#8B5CF6;}
.order-card.status-ready::before{background:#22C55E;}
.order-card.status-delivered::before{background:var(--ink3);}
.order-card.status-cancelled::before{background:#EF4444;opacity:.5;}
.order-card.status-cancelled{opacity:.55;}

/* HEADER */
.order-head{
    display:flex;align-items:center;gap:8px;
    padding:13px 18px;flex-wrap:wrap;
    border-bottom:1px solid var(--line);
    background:var(--bg3);
}
.order-num{font-family:'Fraunces',serif;font-size:17px;font-weight:900;color:var(--ink);}
.order-table-chip{background:color-mix(in srgb,var(--p) 10%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);color:var(--p);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;}
.order-customer{font-size:12px;color:var(--ink2);font-weight:500;display:flex;align-items:center;gap:4px;}
.order-customer svg{width:11px;height:11px;}
.order-head-right{display:flex;align-items:center;gap:10px;margin-right:auto;}
.order-time{font-size:11px;color:var(--ink2);font-weight:600;}
.order-total{font-family:'Fraunces',serif;font-size:18px;font-weight:900;color:var(--p);}

/* STATUS BADGE */
.status-badge{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 11px;border-radius:20px;
    font-size:11px;font-weight:800;
}
.badge-pending  {background:rgba(245,158,11,.12);color:#F59E0B;border:1px solid rgba(245,158,11,.2);}
.badge-confirmed{background:rgba(59,130,246,.12);color:#3B82F6;border:1px solid rgba(59,130,246,.2);}
.badge-preparing{background:rgba(139,92,246,.12);color:#8B5CF6;border:1px solid rgba(139,92,246,.2);}
.badge-ready    {background:rgba(34,197,94,.12);color:#22C55E;border:1px solid rgba(34,197,94,.2);}
.badge-delivered{background:var(--bg3);color:var(--ink2);border:1px solid var(--line);}
.badge-cancelled{background:rgba(239,68,68,.08);color:#EF4444;border:1px solid rgba(239,68,68,.15);}

/* ITEMS TABLE */
.order-items{padding:12px 18px;border-bottom:1px solid var(--line);}
.order-item{
    display:flex;align-items:flex-start;justify-content:space-between;
    gap:10px;padding:8px 0;
    border-bottom:1px solid var(--line);
}
.order-item:last-child{border-bottom:none;}
.order-item-main{flex:1;min-width:0;}
.order-item-name{
    font-size:13px;font-weight:700;color:var(--ink);
    margin-bottom:4px;
}
.order-item-opts{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:4px;}
.order-item-opt{
    display:inline-flex;align-items:center;gap:3px;
    background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.2);
    color:#A78BFA;padding:2px 8px;border-radius:8px;
    font-size:10px;font-weight:700;
}
.order-item-opt::before{content:'◆';font-size:8px;}
.order-item-note{
    display:inline-flex;align-items:center;gap:5px;
    background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.18);
    border-radius:8px;padding:3px 8px;
    font-size:11px;color:#F59E0B;font-weight:600;
    margin-top:2px;
}
.order-item-qty{
    font-family:'Fraunces',serif;
    font-size:14px;font-weight:900;
    background:var(--p);color:#fff;
    padding:3px 10px;border-radius:10px;
    flex-shrink:0;margin-top:1px;
    white-space:nowrap;
}

/* FOOTER */
.order-footer{
    display:flex;align-items:center;justify-content:space-between;
    padding:11px 18px;gap:10px;flex-wrap:wrap;
}
.order-notes-wrap{
    display:flex;align-items:flex-start;gap:6px;
    font-size:12px;color:var(--ink2);flex:1;
}
.order-notes-wrap svg{width:12px;height:12px;flex-shrink:0;margin-top:1px;color:#F59E0B;}

/* TAX ROW */
.order-tax-row{
    display:flex;align-items:center;justify-content:space-between;
    padding:6px 18px;background:var(--bg3);
    border-top:1px solid var(--line);
    font-size:12px;
}
.order-tax-items{display:flex;flex-wrap:wrap;gap:6px;}
.order-tax-chip{
    display:inline-flex;align-items:center;gap:4px;
    background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.18);
    color:#818CF8;padding:2px 8px;border-radius:8px;
    font-size:11px;font-weight:700;
}

/* ACTIONS */
.status-btns{display:flex;gap:6px;flex-wrap:wrap;}
.status-btn{
    padding:8px 14px;border-radius:10px;border:none;
    font-size:12px;font-weight:800;cursor:pointer;
    font-family:'Tajawal',sans-serif;transition:all .2s;
    white-space:nowrap;display:flex;align-items:center;gap:5px;
}
.status-btn svg{width:12px;height:12px;}
.status-btn:active{transform:scale(.96);}
.btn-confirm{background:rgba(59,130,246,.12);color:#3B82F6;border:1px solid rgba(59,130,246,.2);}
.btn-confirm:hover{background:#3B82F6;color:#fff;border-color:transparent;}
.btn-prepare{background:rgba(139,92,246,.12);color:#8B5CF6;border:1px solid rgba(139,92,246,.2);}
.btn-prepare:hover{background:#8B5CF6;color:#fff;border-color:transparent;}
.btn-ready{background:rgba(34,197,94,.12);color:#22C55E;border:1px solid rgba(34,197,94,.2);}
.btn-ready:hover{background:#22C55E;color:#fff;border-color:transparent;}
.btn-deliver{background:var(--bg3);color:var(--ink2);border:1px solid var(--line);}
.btn-deliver:hover{background:var(--ink);color:var(--bg);border-color:transparent;}
.btn-cancel{background:rgba(239,68,68,.08);color:#EF4444;border:1px solid rgba(239,68,68,.18);}
.btn-cancel:hover{background:#EF4444;color:#fff;border-color:transparent;}
.status-btn.loading{opacity:.6;pointer-events:none;}

/* EMPTY */
.orders-empty{text-align:center;padding:64px 20px;background:var(--bg2);border:1px solid var(--line);border-radius:18px;}
.orders-empty-icon{width:64px;height:64px;border-radius:20px;background:var(--bg3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;}
.orders-empty p{font-size:14px;color:var(--ink2);font-weight:600;}
</style>

<div class="main">

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:12px;">
    <div>
        <div class="page-title">الطلبات</div>
        <div class="page-subtitle">إدارة طلبات مطعمك بالوقت الحقيقي</div>
    </div>
</div>

<!-- Stat Pills -->
<div class="stat-pills">
    <a href="orders.php?status=all&date=<?= $date_mode ?>" class="stat-pill <?= $filter==='all'?'active':'' ?>">
        <div class="pill-icon" style="background:color-mix(in srgb,var(--p) 12%,transparent);">📋</div>
        <div><div class="pill-num"><?= $stats['total'] ?></div><div class="pill-label">الكل</div></div>
    </a>
    <a href="orders.php?status=pending&date=<?= $date_mode ?>" class="stat-pill <?= $filter==='pending'?'active':'' ?>">
        <div class="pill-icon" style="background:rgba(245,158,11,.12);">⏳</div>
        <div><div class="pill-num"><?= $stats['pending'] ?></div><div class="pill-label">معلق</div></div>
    </a>
    <a href="orders.php?status=preparing&date=<?= $date_mode ?>" class="stat-pill <?= $filter==='preparing'?'active':'' ?>">
        <div class="pill-icon" style="background:rgba(139,92,246,.12);">👨‍🍳</div>
        <div><div class="pill-num"><?= $stats['preparing'] ?></div><div class="pill-label">بالتحضير</div></div>
    </a>
    <a href="orders.php?status=ready&date=<?= $date_mode ?>" class="stat-pill <?= $filter==='ready'?'active':'' ?>">
        <div class="pill-icon" style="background:rgba(34,197,94,.12);">🍽️</div>
        <div><div class="pill-num"><?= $stats['ready'] ?></div><div class="pill-label">جاهز</div></div>
    </a>
    <a href="orders.php?status=delivered&date=today" class="stat-pill <?= $filter==='delivered'?'active':'' ?>">
        <div class="pill-icon" style="background:rgba(34,197,94,.12);">💰</div>
        <div><div class="pill-num"><?= fmt_price($stats['revenue_today'], $cur_symbol, $cur_decimals, $cur_prefix) ?></div><div class="pill-label">إيرادات اليوم</div></div>
    </a>
</div>

<!-- Toolbar -->
<div class="toolbar">
    <div class="date-tabs">
        <a href="orders.php?status=<?= $filter ?>" class="date-tab <?= $date_mode==='today'?'active':'' ?>">اليوم</a>
        <a href="orders.php?status=<?= $filter ?>&date=all" class="date-tab <?= $date_mode==='all'?'active':'' ?>">كل الطلبات</a>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <div class="live-indicator"><div class="live-dot"></div> يتحدث تلقائياً</div>
        <button class="sound-btn" onclick="testSound()">🔔 اختبار الصوت</button>
    </div>
</div>

<!-- Orders -->
<div class="orders-list" id="ordersList">
<?php if(empty($orders)): ?>
    <div class="orders-empty">
        <div class="orders-empty-icon">📋</div>
        <p>ما في طلبات <?= $filter!=='all'?'بهاد الفلتر':'بعد' ?></p>
    </div>
<?php else:
    $status_next = [
        'pending'   => [['confirmed','تأكيد','btn-confirm'],['cancelled','إلغاء','btn-cancel']],
        'confirmed' => [['preparing','بالتحضير','btn-prepare'],['cancelled','إلغاء','btn-cancel']],
        'preparing' => [['ready','جاهز','btn-ready']],
        'ready'     => [['delivered','تم التسليم','btn-deliver']],
        'delivered' => [], 'cancelled' => [],
    ];
    $status_labels = ['pending'=>'معلق','confirmed'=>'مؤكد','preparing'=>'بالتحضير','ready'=>'جاهز','delivered'=>'تم التسليم','cancelled'=>'ملغي'];
    $status_icons  = ['pending'=>'⏳','confirmed'=>'✓','preparing'=>'⚡','ready'=>'🍽️','delivered'=>'✅','cancelled'=>'❌'];

    foreach($orders as $idx => $o):
        $items = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
        $items->execute([$o['id']]);
        $items = $items->fetchAll();

        // ضرائب الطلب
        $order_taxes = [];
        if(!empty($o['tax_details'])) {
            $order_taxes = json_decode($o['tax_details'], true) ?: [];
        }
?>
    <div class="order-card status-<?= $o['status'] ?>" id="order-<?= $o['id'] ?>" style="animation-delay:<?= min($idx*.04,.3) ?>s">

        <!-- HEADER -->
        <div class="order-head">
            <div class="order-num">#<?= $o['restaurant_order_number'] ?? $o['id'] ?></div>
            <div class="order-table-chip">🪑 طاولة <?= htmlspecialchars($o['table_number']) ?></div>
            <?php if($o['customer_name']): ?>
            <div class="order-customer">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?= htmlspecialchars($o['customer_name']) ?>
            </div>
            <?php endif; ?>
            <div class="order-head-right">
                <span class="order-time"><?= date('H:i', strtotime($o['created_at'])) ?></span>
                <span class="order-total"><?= fmt_price($o['total_price'], $cur_symbol, $cur_decimals, $cur_prefix) ?></span>
                <span class="status-badge badge-<?= $o['status'] ?>">
                    <?= $status_icons[$o['status']] ?> <?= $status_labels[$o['status']] ?>
                </span>
            </div>
        </div>

        <!-- ITEMS -->
        <div class="order-items">
            <?php foreach($items as $item):
                $opts = !empty($item['options']) ? json_decode($item['options'],true)??[] : [];
            ?>
            <div class="order-item">
                <div class="order-item-main">
                    <div class="order-item-name">
                        <?php if(empty($item['dish_id'])): ?>
                        <span style="font-size:9px;font-weight:800;background:rgba(255,107,53,.12);color:var(--p);border:1px solid rgba(255,107,53,.22);padding:1px 6px;border-radius:8px;margin-left:5px;">🎁 عرض</span>
                        <?php endif; ?>
                        <?= htmlspecialchars($item['dish_name']) ?>
                    </div>

                    <?php if(!empty($opts)): ?>
                    <div class="order-item-opts">
                        <?php foreach($opts as $opt): ?>
                        <span class="order-item-opt"><?= htmlspecialchars($opt['name']??'') ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if(!empty($item['notes'])): ?>
                    <div class="order-item-note">📝 <?= htmlspecialchars($item['notes']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="order-item-qty">×<?= $item['quantity'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- TAXES -->
        <?php if(!empty($order_taxes)): ?>
        <div class="order-tax-row">
            <div class="order-tax-items">
                <?php foreach($order_taxes as $tax): ?>
                <span class="order-tax-chip">
                    <?= htmlspecialchars($tax['name']) ?>
                    <span style="opacity:.7;"><?= $tax['type']==='percentage'?$tax['value'].'%':'$'.number_format($tax['value'],2) ?></span>
                    → $<?= number_format($tax['amount'],2) ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- FOOTER -->
        <div class="order-footer">
            <div class="order-notes-wrap">
                <?php if($o['customer_notes']): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <?= htmlspecialchars($o['customer_notes']) ?>
                <?php endif; ?>
            </div>
            <div class="status-btns">
                <?php foreach($status_next[$o['status']]??[] as [$ns,$nl,$nc]): ?>
                <button class="status-btn <?= $nc ?>" onclick="updateStatus(<?= $o['id'] ?>,'<?= $ns ?>',this)">
                    <?= $nl ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
<?php endforeach; endif; ?>
</div>
</div>

<script>
function updateStatus(orderId,status,btn){
    btn.classList.add('loading'); btn.disabled=true;
    fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`order_id=${orderId}&status=${status}&ajax=1`})
    .then(r=>r.json())
    .then(d=>{ if(d.success) location.reload(); })
    .catch(()=>{ btn.classList.remove('loading'); btn.disabled=false; });
}

let audioCtx=null, userInteracted=false, notifGranted=false;
['click','touchstart','keydown'].forEach(ev=>
    document.addEventListener(ev,()=>{
        userInteracted=true;
        if(!audioCtx){try{audioCtx=new(window.AudioContext||window.webkitAudioContext)();}catch(e){}}
        if(audioCtx&&audioCtx.state==='suspended') audioCtx.resume();
        if(sessionStorage.getItem('pendingSound')){ sessionStorage.removeItem('pendingSound'); setTimeout(_playBeep,100); }
    },{passive:true})
);
if('Notification' in window){
    if(Notification.permission==='granted') notifGranted=true;
    else if(Notification.permission!=='denied'){
        Notification.requestPermission().then(p=>{ notifGranted=(p==='granted'); });
    }
}
function _playBeep(){
    if(!audioCtx) return;
    [[880,0,.3],[1100,.22,.65]].forEach(([f,s,e])=>{
        const o=audioCtx.createOscillator(),g=audioCtx.createGain();
        o.connect(g); g.connect(audioCtx.destination);
        o.frequency.value=f; o.type='sine';
        g.gain.setValueAtTime(.4,audioCtx.currentTime+s);
        g.gain.exponentialRampToValueAtTime(.001,audioCtx.currentTime+e);
        o.start(audioCtx.currentTime+s); o.stop(audioCtx.currentTime+e);
    });
}
function playSound(){
    if(!userInteracted){sessionStorage.setItem('pendingSound','1');return;}
    if(!audioCtx){try{audioCtx=new(window.AudioContext||window.webkitAudioContext)();}catch(e){return;}}
    if(audioCtx.state==='suspended') audioCtx.resume().then(_playBeep);
    else _playBeep();
}
function sendNotif(title,body){
    if(!notifGranted) return;
    try{ new Notification(title,{body,tag:'order',renotify:true,requireInteraction:true}); }catch(e){}
}
function testSound(){
    userInteracted=true;
    if(!audioCtx){try{audioCtx=new(window.AudioContext||window.webkitAudioContext)();}catch(e){return;}}
    if(audioCtx.state==='suspended') audioCtx.resume().then(_playBeep); else _playBeep();
}

let lastId=parseInt(sessionStorage.getItem('lastOrderId')||'0');
const pageMax=<?= !empty($orders)?$orders[0]['id']:0 ?>;
if(pageMax>lastId){ lastId=pageMax; sessionStorage.setItem('lastOrderId',lastId); }
let isReloading=false;

setInterval(()=>{
    if(isReloading) return;
    fetch(`api/new_orders.php?last_id=${lastId}`)
    .then(r=>r.json())
    .then(data=>{
        if(data.new_orders&&data.new_orders.length>0){
            const newest=Math.max(...data.new_orders.map(o=>parseInt(o.id)));
            if(newest<=lastId) return;
            lastId=newest; sessionStorage.setItem('lastOrderId',lastId);
            sendNotif('🛎️ طلب جديد!',`طاولة ${data.new_orders[0].table_number??''}`);
            playSound();
            isReloading=true;
            setTimeout(()=>location.reload(),800);
        }
        const badge=document.querySelector('.sb-badge');
        if(badge&&data.pending_count!==undefined){
            badge.textContent=data.pending_count;
            badge.style.display=data.pending_count>0?'inline-flex':'none';
        }
    })
    .catch(()=>{});
},8000);
</script>
</body>
</html>