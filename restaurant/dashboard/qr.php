<?php
/**
 * qr.php — رموز QR للمطعم والطاولات (Phase 7 — branch-aware)
 *
 * يقرأ الفرع النشط من $_SESSION['active_branch_id']
 * اللي تبدّل من branch switcher بالـ sidebar
 * URL المنيو: /menu/{slug}/{branch-slug}
 */
require_once __DIR__ . '/../../bootstrap.php';
// plan_guard.php بيتحمّل تلقائياً من sidebar_v2.php

$rid = $_SESSION['restaurant_id'];

// ===== بيانات المطعم =====
$rest_stmt = $pdo->prepare("SELECT id, name, slug FROM restaurants WHERE id = ?");
$rest_stmt->execute([$rid]);
$restaurant_data = $rest_stmt->fetch();

// ===== الفروع النشطة =====
$branches_stmt = $pdo->prepare("SELECT id, name, name_en, slug, address FROM branches WHERE restaurant_id = ? AND is_active = 1 ORDER BY id ASC");
$branches_stmt->execute([$rid]);
$branches = $branches_stmt->fetchAll();

// ===== الفرع النشط من الـ session =====
$active_branch_id = $_SESSION['active_branch_id'] ?? null;
$selected_branch  = null;

if ($active_branch_id) {
    foreach ($branches as $b) {
        if ($b['id'] == $active_branch_id) {
            $selected_branch = $b;
            break;
        }
    }
}
// fallback: أول فرع
if (!$selected_branch && !empty($branches)) {
    $selected_branch = $branches[0];
    $active_branch_id = $selected_branch['id'];
}

// ===== إحصائيات الفرع =====
if ($selected_branch) {
    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                             AS total_orders,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END)     AS today_orders,
            COUNT(DISTINCT table_number)                                         AS unique_tables
        FROM orders
        WHERE restaurant_id = ? AND branch_id = ?
    ");
    $stats_stmt->execute([$rid, $active_branch_id]);
    $s = $stats_stmt->fetch();
} else {
    $s = ['total_orders' => 0, 'today_orders' => 0, 'unique_tables' => 0];
}

$total_orders  = intval($s['total_orders']  ?? 0);
$today_orders  = intval($s['today_orders']  ?? 0);
$unique_tables = intval($s['unique_tables'] ?? 0);

require_once __DIR__ . '/sidebar.php';
?>
<style>
/* ===== QR PAGE ===== */
.qr-stat-pills{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;}
@media(max-width:500px){.qr-stat-pills{grid-template-columns:1fr 1fr;}}
.qr-pill{background:var(--bg2);border:1px solid var(--line);border-radius:16px;padding:16px 18px;display:flex;align-items:center;gap:13px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;}
.qr-pill:nth-child(1){animation-delay:.04s} .qr-pill:nth-child(2){animation-delay:.08s} .qr-pill:nth-child(3){animation-delay:.12s}
.qr-pill-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.qr-pill-icon svg{width:18px;height:18px;}
.qr-pill-num{font-family:'Fraunces',serif;font-size:22px;font-weight:900;color:var(--ink);line-height:1;}
.qr-pill-lbl{font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px;}

/* Active branch indicator */
.branch-indicator{display:flex;align-items:center;gap:10px;padding:12px 18px;background:color-mix(in srgb,var(--p) 6%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);border-radius:14px;margin-bottom:18px;animation:cardIn .35s both;}
.branch-indicator-dot{width:8px;height:8px;border-radius:50%;background:var(--p);box-shadow:0 0 0 3px color-mix(in srgb,var(--p) 20%,transparent);flex-shrink:0;}
.branch-indicator-text{font-size:13px;font-weight:700;color:var(--ink);}
.branch-indicator-sub{font-size:11px;color:var(--ink2);font-weight:500;margin-top:1px;}
.branch-indicator-change{margin-right:auto;font-size:11px;color:var(--p);font-weight:700;text-decoration:none;white-space:nowrap;}
.branch-indicator-change:hover{text-decoration:underline;}

.qr-top-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;}
@media(max-width:800px){.qr-top-grid{grid-template-columns:1fr;}}

.qr-main-card{background:var(--bg2);border:1px solid var(--line);border-radius:20px;padding:22px 18px;text-align:center;animation:cardIn .4s cubic-bezier(.22,1,.36,1) .1s both;display:flex;flex-direction:column;align-items:center;min-width:0;width:100%;}
.qr-main-title{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:var(--ink);margin-bottom:4px;}
.qr-main-branch{font-size:12px;color:var(--p);font-weight:800;margin-bottom:4px;display:flex;align-items:center;gap:4px;}
.qr-main-url{font-size:10px;color:var(--ink2);font-weight:500;margin-bottom:16px;word-break:break-all;max-width:260px;font-family:monospace;background:var(--bg3);padding:5px 10px;border-radius:8px;border:1px solid var(--line);}
.qr-box{background:#fff;border-radius:14px;padding:12px;border:1px solid var(--line);display:inline-block;margin-bottom:16px;}
.qr-box img{display:block!important;border-radius:8px;}
.qr-box canvas{display:none!important;}
.qr-btns{display:flex;gap:8px;width:100%;}
.qr-dl-btn{flex:2;padding:11px;background:var(--p);color:#fff;border:none;border-radius:12px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;display:flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 4px 12px color-mix(in srgb,var(--p) 35%,transparent);transition:opacity .2s;}
.qr-dl-btn:hover{opacity:.88;}
.qr-dl-btn svg{width:14px;height:14px;}
.qr-copy-btn{flex:1;padding:11px;background:var(--bg3);border:1px solid var(--line);color:var(--ink2);border-radius:12px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;display:flex;align-items:center;justify-content:center;gap:5px;transition:all .2s;}
.qr-copy-btn:hover{background:color-mix(in srgb,var(--p) 10%,transparent);color:var(--p);}
.qr-copy-btn svg{width:13px;height:13px;}

.howto-card{background:var(--bg2);border:1px solid var(--line);border-radius:20px;padding:22px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) .2s both;}
.howto-title{font-family:'Fraunces',serif;font-size:15px;font-weight:800;color:var(--ink);margin-bottom:14px;}
.howto-steps{display:flex;flex-direction:column;gap:12px;}
.howto-step{display:flex;gap:11px;align-items:flex-start;}
.step-num{flex-shrink:0;width:26px;height:26px;border-radius:50%;background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;}
.step-text-title{font-size:13px;font-weight:800;color:var(--ink);margin-bottom:2px;}
.step-text-sub{font-size:11px;color:var(--ink2);font-weight:500;line-height:1.5;}

.tables-section{background:var(--bg2);border:1px solid var(--line);border-radius:20px;padding:20px;animation:cardIn .4s cubic-bezier(.22,1,.36,1) .3s both;}
.tables-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;}
.tables-header h3{font-family:'Fraunces',serif;font-size:15px;font-weight:800;margin:0;color:var(--ink);}
.tables-controls{display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap;}
.tables-controls label{font-size:12px;color:var(--ink2);font-weight:700;}
.tables-controls input[type="number"]{width:80px;padding:8px 10px;background:var(--bg3);border:1.5px solid var(--line);border-radius:10px;font-size:13px;font-weight:700;color:var(--ink);font-family:'Tajawal',sans-serif;}
.tables-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;}

.tqr-card{background:var(--bg3);border:1px solid var(--line);border-radius:14px;padding:12px;text-align:center;}
.tqr-num{font-family:'Fraunces',serif;font-size:13px;font-weight:800;color:var(--ink);margin-bottom:8px;}
.tqr-box{background:#fff;border-radius:10px;padding:8px;display:inline-block;margin-bottom:8px;}
.tqr-box img{display:block!important;width:100%;height:auto;border-radius:4px;}
.tqr-box canvas{display:none!important;}
.tqr-dl{width:100%;padding:7px 8px;background:var(--p);color:#fff;border:none;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;font-family:'Tajawal',sans-serif;display:flex;align-items:center;justify-content:center;gap:4px;transition:opacity .2s;}
.tqr-dl:hover{opacity:.88;}
.tqr-dl svg{width:11px;height:11px;}

.dl-all-btn{padding:10px 16px;display:inline-flex;align-items:center;gap:7px;background:var(--p);color:#fff;border:none;border-radius:12px;font-size:13px;font-weight:800;cursor:pointer;font-family:'Tajawal',sans-serif;box-shadow:0 4px 14px color-mix(in srgb,var(--p) 35%,transparent);transition:opacity .2s;}
.dl-all-btn:hover{opacity:.88;}
.dl-all-btn svg{width:14px;height:14px;}

/* Toast */
.qr-toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(80px);background:var(--bg2);border:1px solid var(--line);color:var(--ink);padding:11px 22px;border-radius:25px;font-size:13px;font-weight:700;z-index:9000;box-shadow:var(--shadow);transition:transform .35s cubic-bezier(.34,1.56,.64,1);white-space:nowrap;}
.qr-toast.show{transform:translateX(-50%) translateY(0);}

/* No branch state */
.no-branch{text-align:center;padding:60px 20px;}
.no-branch-icon{font-size:56px;margin-bottom:16px;opacity:.7;}
.no-branch p{font-size:14px;color:var(--ink2);font-weight:600;margin-bottom:16px;}
</style>

<div class="main">

    <div class="page-head">
        <div class="page-title">رمز QR</div>
        <div class="page-subtitle">رموز QR لمنيو الفروع والطاولات</div>
    </div>

    <?php if (empty($branches)): ?>
    <!-- لا يوجد فروع -->
    <div class="tables-section">
        <div class="no-branch">
            <div class="no-branch-icon">🏢</div>
            <p>لازم يكون عندك فرع واحد على الأقل لتوليد رموز QR</p>
            <a href="branches.php" class="btn btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:15px;height:15px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                إضافة فرع جديد
            </a>
        </div>
    </div>

    <?php else: ?>

    <!-- ===== Branch Indicator ===== -->
    <div class="branch-indicator">
        <div class="branch-indicator-dot"></div>
        <div>
            <div class="branch-indicator-text">📍 <?= htmlspecialchars($selected_branch['name']) ?></div>
            <?php if($selected_branch['address']): ?>
            <div class="branch-indicator-sub"><?= htmlspecialchars($selected_branch['address']) ?></div>
            <?php endif; ?>
        </div>
        <?php if(count($branches) > 1): ?>
        <a href="javascript:void(0)" onclick="document.querySelector('.branch-select')?.focus()" class="branch-indicator-change">تغيير الفرع ↑</a>
        <?php endif; ?>
    </div>

    <!-- ===== Stats ===== -->
    <div class="qr-stat-pills">
        <div class="qr-pill">
            <div class="qr-pill-icon" style="background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
            </div>
            <div>
                <div class="qr-pill-num"><?= $total_orders ?></div>
                <div class="qr-pill-lbl">إجمالي الطلبات</div>
            </div>
        </div>
        <div class="qr-pill">
            <div class="qr-pill-icon" style="background:rgba(34,197,94,0.12);color:#22C55E">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div>
                <div class="qr-pill-num"><?= $today_orders ?></div>
                <div class="qr-pill-lbl">طلبات اليوم</div>
            </div>
        </div>
        <div class="qr-pill">
            <div class="qr-pill-icon" style="background:rgba(245,158,11,0.12);color:#F59E0B">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="9" y1="4" x2="9" y2="20"/></svg>
            </div>
            <div>
                <div class="qr-pill-num"><?= $unique_tables ?></div>
                <div class="qr-pill-lbl">طاولات نشطة</div>
            </div>
        </div>
    </div>

    <!-- ===== QR رئيسي + كيفية الاستخدام ===== -->
    <div class="qr-top-grid">

        <div class="qr-main-card">
            <div class="qr-main-title">QR المنيو الرئيسي</div>
            <div class="qr-main-branch">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:12px;height:12px"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <?= htmlspecialchars($selected_branch['name']) ?>
            </div>
            <div class="qr-main-url"><?= BASE_URL ?>/menu/<?= $restaurant_data['slug'] ?>/<?= $selected_branch['slug'] ?></div>
            <div class="qr-box" id="qrMain"></div>
            <div class="qr-btns">
                <button class="qr-dl-btn" onclick="downloadMainQR()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    تحميل
                </button>
                <button class="qr-copy-btn" onclick="copyUrl()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    نسخ
                </button>
            </div>
        </div>

        <div class="howto-card">
            <div class="howto-title">كيفية الاستخدام</div>
            <div class="howto-steps">
                <div class="howto-step">
                    <div class="step-num">1</div>
                    <div>
                        <div class="step-text-title">غيّر الفرع من الـ sidebar</div>
                        <div class="step-text-sub">كل فرع له رموز QR خاصة — تأكد إنك عالفرع الصح قبل التحميل</div>
                    </div>
                </div>
                <div class="howto-step">
                    <div class="step-num">2</div>
                    <div>
                        <div class="step-text-title">حمّل QR لكل طاولة</div>
                        <div class="step-text-sub">حدد عدد الطاولات وحمّل كل QR، أو حمّل الكل دفعة واحدة بـ ZIP</div>
                    </div>
                </div>
                <div class="howto-step">
                    <div class="step-num">3</div>
                    <div>
                        <div class="step-text-title">الصقه على الطاولة</div>
                        <div class="step-text-sub">الزبون يمسح → يوصل لمنيو الفرع مع رقم الطاولة تلقائياً</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ===== QR الطاولات ===== -->
    <div class="tables-section">
        <div class="tables-header">
            <h3>QR الطاولات — <?= htmlspecialchars($selected_branch['name']) ?></h3>
            <button class="dl-all-btn" id="dlAllBtn" onclick="downloadAll()" style="display:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                تحميل الكل (ZIP)
            </button>
        </div>
        <div class="tables-controls">
            <label>عدد الطاولات:</label>
            <input type="number" id="tableCount" value="10" min="1" max="100">
            <button class="btn btn-secondary btn-sm" onclick="generateTables()">توليد</button>
        </div>
        <div class="tables-grid" id="tablesGrid"></div>
    </div>

    <?php endif; ?>

</div>

<div class="qr-toast" id="qrToast"></div>

<?php if (!empty($branches)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script>
const BASE_URL    = '<?= BASE_URL ?>';
const SLUG        = '<?= $restaurant_data['slug'] ?>';
const BRANCH_SLUG = '<?= $selected_branch['slug'] ?>';
const BRANCH_NAME = '<?= addslashes($selected_branch['name']) ?>';
const MENU_URL    = BASE_URL + '/menu/' + SLUG + '/' + BRANCH_SLUG;

// ===== توليد QR رئيسي =====
new QRCode(document.getElementById('qrMain'), {
    text: MENU_URL,
    width: 200, height: 200,
    colorDark: '#111111', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.H
});

// ===== توليد QR الطاولات =====
function generateTables() {
    var count = Math.min(parseInt(document.getElementById('tableCount').value) || 10, 100);
    var grid  = document.getElementById('tablesGrid');
    grid.innerHTML = '';
    document.getElementById('dlAllBtn').style.display = 'none';

    for (var i = 1; i <= count; i++) {
        (function(num) {
            var url  = MENU_URL + '?table=' + num;
            var card = document.createElement('div');
            card.className = 'tqr-card';
            card.innerHTML =
                '<div class="tqr-num">طاولة ' + num + '</div>' +
                '<div class="tqr-box"><div id="tqr-' + num + '"></div></div>' +
                '<button class="tqr-dl" onclick="downloadTable(' + num + ')">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">' +
                    '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>' +
                    '<polyline points="7 10 12 15 17 10"/>' +
                    '<line x1="12" y1="15" x2="12" y2="3"/></svg>' +
                    'تحميل' +
                '</button>';
            grid.appendChild(card);

            setTimeout(function() {
                new QRCode(document.getElementById('tqr-' + num), {
                    text: url,
                    width: 120, height: 120,
                    colorDark: '#111111', colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
            }, num * 40);
        })(i);
    }

    // أظهر زر تحميل الكل بعد ما كل الـ QR تتولّد
    setTimeout(function() {
        document.getElementById('dlAllBtn').style.display = 'inline-flex';
    }, count * 40 + 500);
}

// ===== مساعد: رسم QR بـ Canvas =====
function drawQROnCanvas(imgSrc, label, sublabel, width, height) {
    return new Promise(function(resolve) {
        var canvas = document.createElement('canvas');
        canvas.width  = width;
        canvas.height = height;
        var ctx = canvas.getContext('2d');

        // خلفية بيضاء
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, width, height);

        var img = new Image();
        img.onload = function() {
            // QR بالمنتصف
            var qSize = width - 60;
            ctx.drawImage(img, 30, 20, qSize, qSize);

            // اسم الطاولة / الفرع
            ctx.fillStyle = '#111111';
            ctx.textAlign = 'center';
            ctx.font = 'bold 16px Arial, sans-serif';
            ctx.fillText(label, width / 2, qSize + 44);

            if (sublabel) {
                ctx.fillStyle = '#777777';
                ctx.font = '11px Arial, sans-serif';
                ctx.fillText(sublabel, width / 2, qSize + 62);
            }

            resolve(canvas);
        };
        img.onerror = function() { resolve(canvas); };
        img.src = imgSrc;
    });
}

function getQRImgSrc(containerId) {
    var el = document.querySelector('#' + containerId + ' img');
    return el ? el.src : null;
}

// ===== تحميل QR رئيسي =====
async function downloadMainQR() {
    var src = getQRImgSrc('qrMain');
    if (!src) return;
    var canvas = await drawQROnCanvas(src, BRANCH_NAME, MENU_URL.replace('https://',''), 260, 310);
    var a = document.createElement('a');
    a.download = 'menu-' + BRANCH_SLUG + '.png';
    a.href = canvas.toDataURL();
    a.click();
    showToast('✅ تم تحميل QR المنيو');
}

// ===== تحميل QR طاولة =====
async function downloadTable(num) {
    var src = getQRImgSrc('tqr-' + num);
    if (!src) return;
    var canvas = await drawQROnCanvas(src, 'طاولة ' + num, BRANCH_NAME, 240, 290);
    var a = document.createElement('a');
    a.download = 'table-' + num + '-' + BRANCH_SLUG + '.png';
    a.href = canvas.toDataURL();
    a.click();
}

// ===== تحميل كل الطاولات ZIP =====
async function downloadAll() {
    var btn   = document.getElementById('dlAllBtn');
    var count = parseInt(document.getElementById('tableCount').value) || 10;
    btn.style.opacity = '0.5';
    btn.disabled = true;

    var zip = new JSZip();
    for (var i = 1; i <= count; i++) {
        var src = getQRImgSrc('tqr-' + i);
        if (!src) continue;
        var canvas = await drawQROnCanvas(src, 'طاولة ' + i, BRANCH_NAME, 240, 290);
        var blob   = await new Promise(function(res) { canvas.toBlob(res); });
        zip.file('table-' + i + '.png', blob);
    }

    var content = await zip.generateAsync({ type: 'blob' });
    var a = document.createElement('a');
    a.download = 'tables-' + BRANCH_SLUG + '.zip';
    a.href = URL.createObjectURL(content);
    a.click();
    URL.revokeObjectURL(a.href);

    btn.style.opacity = '1';
    btn.disabled = false;
    showToast('✅ تم تحميل ' + count + ' رمز QR');
}

// ===== نسخ الرابط =====
function copyUrl() {
    navigator.clipboard.writeText(MENU_URL).then(function() {
        showToast('✅ تم نسخ رابط المنيو');
    });
}

// ===== Toast =====
var toastTimer;
function showToast(msg) {
    var t = document.getElementById('qrToast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function() { t.classList.remove('show'); }, 2500);
}

// ===== توليد الطاولات عند التحميل =====
document.addEventListener('DOMContentLoaded', generateTables);
</script>
<?php endif; ?>