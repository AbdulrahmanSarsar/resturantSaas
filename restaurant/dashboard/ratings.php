<?php
session_start();
require_once '../../config/database.php';
require_once 'plan_guard.php';
if(!isset($_SESSION['restaurant_id'])) {
    header('Location: ../login.php'); exit;
}
plan_required('advanced');

$rid = $_SESSION['restaurant_id'];
$active_branch_id = $_SESSION['active_branch_id'] ?? null;

// Branch filter patterns (shared across queries)
$bf_order  = $active_branch_id ? 'AND o.branch_id = ?' : '';
$bf_staff  = $active_branch_id ? 'AND s.branch_id = ?' : '';
$bf_params = $active_branch_id ? [$active_branch_id]    : [];

// اسم الفرع النشط (للعرض في العنوان)
$active_branch_name = null;
if ($active_branch_id) {
    $bstmt = $pdo->prepare("SELECT name FROM branches WHERE id=? AND restaurant_id=?");
    $bstmt->execute([$active_branch_id, $rid]);
    $active_branch_name = $bstmt->fetchColumn() ?: null;
}

$filter_stars = intval($_GET['stars'] ?? 0);
$where  = 'WHERE r.restaurant_id = ? ' . $bf_order;
$params = array_merge([$rid], $bf_params);
if($filter_stars) { $where .= ' AND r.rating = ?'; $params[] = $filter_stars; }

$ratings = $pdo->prepare("
    SELECT r.*, o.table_number, o.customer_name,
           d.name as fav_dish_name, d.image as fav_dish_image
    FROM order_ratings r
    JOIN orders o ON o.id = r.order_id
    LEFT JOIN dishes_v2 d ON d.id = r.favorite_dish_id
    $where
    ORDER BY r.created_at DESC
    LIMIT 500
");
$ratings->execute($params);
$ratings = $ratings->fetchAll();

$stats = $pdo->prepare("
    SELECT COUNT(*) as total, ROUND(AVG(r.rating),1) as avg_rating,
        SUM(r.rating=5) as five_star, SUM(r.rating=4) as four_star,
        SUM(r.rating=3) as three_star, SUM(r.rating=2) as two_star, SUM(r.rating=1) as one_star
    FROM order_ratings r
    JOIN orders o ON o.id = r.order_id
    WHERE r.restaurant_id = ? $bf_order
");
$stats->execute(array_merge([$rid], $bf_params));
$stats = $stats->fetch();

$fav_dishes = $pdo->prepare("
    SELECT d.name, d.image, COUNT(*) as fav_count
    FROM order_ratings r
    JOIN orders o ON o.id = r.order_id
    JOIN dishes_v2 d ON d.id = r.favorite_dish_id
    WHERE r.restaurant_id = ? AND r.favorite_dish_id IS NOT NULL $bf_order
    GROUP BY r.favorite_dish_id
    ORDER BY fav_count DESC LIMIT 5
");
$fav_dishes->execute(array_merge([$rid], $bf_params));
$fav_dishes = $fav_dishes->fetchAll();

// تقييمات النادلين (مفلترة حسب فرع النادل)
$waiter_stats = $pdo->prepare("
    SELECT s.id, s.name,
        COUNT(r.id) as total_ratings,
        ROUND(AVG(r.waiter_rating),1) as avg_rating,
        SUM(r.waiter_rating=5) as five_star, SUM(r.waiter_rating=4) as four_star,
        SUM(r.waiter_rating=3) as three_star, SUM(r.waiter_rating=2) as two_star,
        SUM(r.waiter_rating=1) as one_star
    FROM restaurant_staff s
    LEFT JOIN order_ratings r ON r.waiter_id = s.id AND r.waiter_rating IS NOT NULL
    WHERE s.restaurant_id = ? AND s.role = 'waiter' $bf_staff
    GROUP BY s.id ORDER BY avg_rating DESC
");
$waiter_stats->execute(array_merge([$rid], $bf_params));
$waiter_stats = $waiter_stats->fetchAll();

$waiter_comments = $pdo->prepare("
    SELECT r.waiter_rating, r.comment, r.created_at,
           o.table_number, o.customer_name, s.name as waiter_name
    FROM order_ratings r
    JOIN orders o ON o.id = r.order_id
    JOIN restaurant_staff s ON s.id = r.waiter_id
    WHERE r.restaurant_id = ? AND r.waiter_rating IS NOT NULL AND r.comment IS NOT NULL AND r.comment != '' $bf_order
    ORDER BY r.created_at DESC LIMIT 5
");
$waiter_comments->execute(array_merge([$rid], $bf_params));
$waiter_comments = $waiter_comments->fetchAll();

require_once 'sidebar.php';
?>
<style>
.overview-grid{display:grid;grid-template-columns:240px 1fr;gap:14px;margin-bottom:20px;}
@media(max-width:800px){.overview-grid{grid-template-columns:1fr;}}
.rating-hero{background:var(--p);border-radius:20px;padding:28px;color:#fff;text-align:center;position:relative;overflow:hidden;animation:cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both;box-shadow:0 8px 28px color-mix(in srgb,var(--p) 45%,transparent);}
.rating-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:130px;height:130px;border-radius:50%;background:rgba(255,255,255,0.12);pointer-events:none;}
.rating-hero::after{content:'';position:absolute;bottom:-30px;left:-30px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,0.08);pointer-events:none;}
.hero-num{font-family:'Fraunces',serif;font-size:68px;font-weight:900;line-height:1;margin-bottom:8px;position:relative;z-index:1;}
.hero-stars{font-size:22px;letter-spacing:3px;margin-bottom:10px;position:relative;z-index:1;}
.hero-count{font-size:13px;opacity:0.82;font-weight:600;position:relative;z-index:1;}
.bars-card{background:var(--bg2);border:1px solid var(--line);border-radius:20px;padding:20px 22px;animation:cardIn 0.4s cubic-bezier(0.22,1,0.36,1) .06s both;transition:background 0.3s,border-color 0.3s;}
.bars-card-title{font-family:'Fraunces',serif;font-size:14px;font-weight:700;color:var(--ink);margin-bottom:16px;}
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.bar-row:last-child{margin-bottom:0;}
.bar-stars-lbl{font-size:12px;font-weight:700;color:var(--ink2);min-width:22px;text-align:center;}
.bar-track{flex:1;height:9px;background:var(--bg3);border-radius:10px;overflow:hidden;}
.bar-fill{height:100%;border-radius:10px;background:var(--p);transition:width 0.7s cubic-bezier(0.22,1,0.36,1);}
.bar-count-lbl{font-family:'Fraunces',serif;font-size:12px;font-weight:700;color:var(--ink2);min-width:26px;}

.section-head{display:flex;align-items:center;gap:8px;margin-bottom:14px;margin-top:4px;}
.section-head h3{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:var(--ink);}
.section-head::after{content:'';flex:1;height:1px;background:var(--line);}

.fav-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px;}
.fav-card{background:var(--bg2);border:1px solid var(--line);border-radius:16px;overflow:hidden;transition:transform 0.2s,background 0.3s,border-color 0.3s;animation:cardIn 0.35s cubic-bezier(0.22,1,0.36,1) both;}
.fav-card:hover{transform:translateY(-3px);}
.fav-img{width:100%;height:90px;background:var(--bg3);overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:36px;}
.fav-img img{width:100%;height:100%;object-fit:cover;}
.fav-body{padding:10px 12px;}
.fav-name{font-family:'Fraunces',serif;font-size:13px;font-weight:700;color:var(--ink);margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.fav-count-badge{display:inline-flex;align-items:center;gap:4px;background:color-mix(in srgb,var(--p) 10%,transparent);border:1px solid color-mix(in srgb,var(--p) 20%,transparent);color:var(--p);padding:3px 9px;border-radius:20px;font-size:11px;font-weight:800;}

.filter-select{padding:9px 14px;border:1.5px solid var(--line);border-radius:12px;font-size:12px;font-weight:600;font-family:'Tajawal',sans-serif;color:var(--ink);background:var(--bg2);outline:none;cursor:pointer;transition:border-color 0.2s,background 0.3s;min-width:140px;}
.filter-select:focus{border-color:var(--p);}

.ratings-list{display:flex;flex-direction:column;gap:10px;}
.rcard{background:var(--bg2);border:1px solid var(--line);border-radius:16px;padding:15px 18px;transition:transform 0.2s,background 0.3s,border-color 0.3s;animation:cardIn 0.35s cubic-bezier(0.22,1,0.36,1) both;position:relative;}
.rcard:hover{transform:translateX(-2px);}
.rcard::before{content:'';position:absolute;right:0;top:0;bottom:0;width:3px;border-radius:0 16px 16px 0;}
.rcard.r5::before{background:#22C55E;}.rcard.r4::before{background:#84CC16;}.rcard.r3::before{background:#F59E0B;}.rcard.r2::before{background:#F97316;}.rcard.r1::before{background:#EF4444;}
.rcard-stars{display:flex;gap:2px;margin-bottom:7px;}
.s-full{color:#F59E0B;font-size:17px;}.s-empty{color:var(--ink3);font-size:17px;}
.rcard-comment{font-size:13px;color:var(--ink2);line-height:1.6;background:var(--bg3);padding:9px 13px;border-radius:10px;margin-bottom:9px;border-right:3px solid color-mix(in srgb,var(--p) 40%,transparent);}
.rcard-meta{display:flex;gap:12px;flex-wrap:wrap;font-size:11px;color:var(--ink2);font-weight:600;}
.rcard-meta span{display:flex;align-items:center;gap:4px;}
.rcard-meta svg{width:11px;height:11px;}
.rcard-fav{display:flex;align-items:center;gap:7px;margin-bottom:8px;padding:7px 10px;background:color-mix(in srgb,#F59E0B 8%,transparent);border:1px solid color-mix(in srgb,#F59E0B 20%,transparent);border-radius:10px;}
.rcard-fav-img{width:28px;height:28px;border-radius:7px;overflow:hidden;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.rcard-fav-img img{width:100%;height:100%;object-fit:cover;}
.rcard-fav-text{font-size:12px;font-weight:700;color:#F59E0B;}

.show-all-btn{width:100%;margin-top:10px;padding:12px;background:var(--bg2);border:1.5px solid var(--line);border-radius:14px;font-size:13px;font-weight:700;color:var(--ink2);cursor:pointer;font-family:'Tajawal',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:7px;}
.show-all-btn:hover{border-color:var(--p);color:var(--p);}
.show-all-btn svg{width:14px;height:14px;}

.ratings-empty{text-align:center;padding:72px 20px;background:var(--bg2);border:1px solid var(--line);border-radius:18px;color:var(--ink2);}
.ratings-empty-icon{width:72px;height:72px;border-radius:22px;background:var(--bg3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 16px;}
.ratings-empty p{font-size:14px;font-weight:600;}

/* WAITER */
.waiter-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin-bottom:20px;}
.waiter-card{background:var(--bg2);border:1px solid var(--line);border-radius:18px;padding:18px;transition:transform .2s,background .3s,border-color .3s;animation:cardIn .35s cubic-bezier(.22,1,.36,1) both;}
.waiter-card:hover{transform:translateY(-3px);}
.waiter-card-top{display:flex;align-items:center;gap:12px;margin-bottom:14px;}
.waiter-avatar{width:48px;height:48px;border-radius:14px;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.2);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
.waiter-name{font-family:'Fraunces',serif;font-size:15px;font-weight:700;color:var(--ink);}
.waiter-total{font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px;}
.waiter-score{display:flex;align-items:baseline;gap:5px;margin-bottom:6px;}
.waiter-score-num{font-family:'Fraunces',serif;font-size:32px;font-weight:900;color:#3B82F6;letter-spacing:-.5px;}
.waiter-score-max{font-size:13px;color:var(--ink2);font-weight:600;}
.waiter-stars-disp{font-size:16px;letter-spacing:2px;margin-bottom:10px;}
.waiter-bar-row{display:flex;align-items:center;gap:7px;margin-bottom:5px;}
.waiter-bar-track{flex:1;height:5px;background:var(--bg3);border-radius:10px;overflow:hidden;}
.waiter-bar-fill{height:100%;border-radius:10px;background:#3B82F6;}
.waiter-bar-lbl{font-size:10px;font-weight:700;color:var(--ink2);min-width:18px;}
.waiter-bar-count{font-size:10px;font-weight:700;color:#3B82F6;min-width:18px;text-align:right;}
.wcc{background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:14px 16px;animation:cardIn .35s cubic-bezier(.22,1,.36,1) both;transition:background .3s,border-color .3s;margin-bottom:9px;}
.wcc-header{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.wcc-waiter{font-size:12px;font-weight:800;color:#3B82F6;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);padding:3px 10px;border-radius:20px;}
.wcc-stars{font-size:14px;letter-spacing:1px;color:#3B82F6;}
.wcc-comment{font-size:13px;color:var(--ink2);line-height:1.6;margin-bottom:8px;padding:8px 12px;background:var(--bg3);border-radius:9px;border-right:3px solid rgba(59,130,246,.4);}
.wcc-meta{font-size:11px;color:var(--ink2);font-weight:600;display:flex;gap:10px;flex-wrap:wrap;}
</style>

<div class="main">
    <div style="margin-bottom:20px;">
        <div class="page-title">
            التقييمات
            <?php if($active_branch_name): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:color-mix(in srgb,var(--p) 12%,transparent);color:var(--p);padding:4px 11px;border-radius:20px;font-size:12px;font-weight:800;margin-right:8px;vertical-align:middle;">
                📍 <?= htmlspecialchars($active_branch_name) ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="page-subtitle">
            <?= $active_branch_name ? 'تقييمات فرع ' . htmlspecialchars($active_branch_name) : 'آراء زبائنك عن تجربتهم' ?>
        </div>
    </div>

    <?php if($stats['total'] == 0): ?>
    <div class="ratings-empty">
        <div class="ratings-empty-icon">⭐</div>
        <p>
            <?php if($active_branch_name): ?>
                لا توجد تقييمات لفرع <?= htmlspecialchars($active_branch_name) ?> بعد
            <?php else: ?>
                لا توجد تقييمات بعد — شجع زبائنك على التقييم!
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>

    <!-- Overview -->
    <div class="overview-grid">
        <div class="rating-hero">
            <div class="hero-num"><?= $stats['avg_rating'] ?: '0' ?></div>
            <div class="hero-stars"><?php $avg_r=round($stats['avg_rating']);for($i=1;$i<=5;$i++)echo $i<=$avg_r?'★':'☆'; ?></div>
            <div class="hero-count">من أصل <?= $stats['total'] ?> تقييم</div>
        </div>
        <div class="bars-card">
            <div class="bars-card-title">توزيع التقييمات</div>
            <?php $counts=[5=>$stats['five_star'],4=>$stats['four_star'],3=>$stats['three_star'],2=>$stats['two_star'],1=>$stats['one_star']];
            foreach($counts as $stars=>$count):
                $pct=$stats['total']>0?($count/$stats['total'])*100:0; ?>
            <div class="bar-row">
                <span class="bar-stars-lbl"><?= $stars ?></span>
                <span style="font-size:13px;">⭐</span>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                <span class="bar-count-lbl"><?= $count ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Fav Dishes -->
    <?php if(!empty($fav_dishes)): ?>
    <div class="section-head"><h3>🏆 الأطباق الأكثر تفضيلاً</h3></div>
    <div class="fav-grid">
        <?php foreach($fav_dishes as $idx=>$fd): ?>
        <div class="fav-card" style="animation-delay:<?= $idx*0.05 ?>s">
            <div class="fav-img">
                <?php if($fd['image']): ?><img src="<?= BASE_URL ?>/assets/uploads/dishes/<?= $fd['image'] ?>" loading="lazy"><?php else: ?>🍔<?php endif; ?>
            </div>
            <div class="fav-body">
                <div class="fav-name"><?= htmlspecialchars($fd['name']) ?></div>
                <div class="fav-count-badge">⭐ <?= $fd['fav_count'] ?> مرة</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- All Ratings -->
    <div class="section-head"><h3>💬 كل التقييمات</h3></div>
    <form method="GET" style="display:flex;gap:9px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
        <select name="stars" class="filter-select" onchange="this.form.submit()">
            <option value="0">كل التقييمات</option>
            <?php for($i=5;$i>=1;$i--): ?>
            <option value="<?= $i ?>" <?= $filter_stars==$i?'selected':'' ?>><?= str_repeat('⭐',$i) ?></option>
            <?php endfor; ?>
        </select>
        <?php if($filter_stars): ?>
        <a href="ratings.php" class="btn btn-secondary btn-sm">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:12px;height:12px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            مسح
        </a>
        <?php endif; ?>
    </form>

    <?php if(empty($ratings)): ?>
    <div class="ratings-empty"><div class="ratings-empty-icon">🔍</div><p>لا توجد تقييمات بهذا الفلتر</p></div>
    <?php else: ?>

    <!-- أول 5 ظاهرة -->
    <div class="ratings-list" id="ratingsListVisible">
        <?php foreach($ratings as $idx=>$r): if($idx >= 5) break; ?>
        <div class="rcard r<?= $r['rating'] ?>" style="animation-delay:<?= $idx*0.04 ?>s">
            <div class="rcard-stars">
                <?php for($i=1;$i<=5;$i++): ?><span class="<?= $i<=$r['rating']?'s-full':'s-empty' ?>">★</span><?php endfor; ?>
            </div>
            <?php if($r['fav_dish_name']): ?>
            <div class="rcard-fav">
                <div class="rcard-fav-img">
                    <?php if($r['fav_dish_image']): ?><img src="<?= BASE_URL ?>/assets/uploads/dishes/<?= $r['fav_dish_image'] ?>" loading="lazy"><?php else: ?>🍔<?php endif; ?>
                </div>
                <div class="rcard-fav-text">⭐ أعجبه: <?= htmlspecialchars($r['fav_dish_name']) ?></div>
            </div>
            <?php endif; ?>
            <?php if($r['comment']): ?><div class="rcard-comment"><?= htmlspecialchars($r['comment']) ?></div><?php endif; ?>
            <div class="rcard-meta">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>طلب #<?= $r['order_id'] ?></span>
                <?php if($r['table_number']): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>طاولة <?= htmlspecialchars($r['table_number']) ?></span><?php endif; ?>
                <?php if($r['customer_name']): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?= htmlspecialchars($r['customer_name']) ?></span><?php endif; ?>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- باقي التقييمات مخفية -->
    <?php if(count($ratings) > 5): ?>
    <div class="ratings-list" id="ratingsListHidden" style="display:none;margin-top:10px;gap:10px;">
        <?php foreach($ratings as $idx=>$r): if($idx < 5) continue; ?>
        <div class="rcard r<?= $r['rating'] ?>" style="animation-delay:<?= ($idx-5)*0.04 ?>s">
            <div class="rcard-stars">
                <?php for($i=1;$i<=5;$i++): ?><span class="<?= $i<=$r['rating']?'s-full':'s-empty' ?>">★</span><?php endfor; ?>
            </div>
            <?php if($r['fav_dish_name']): ?>
            <div class="rcard-fav">
                <div class="rcard-fav-img">
                    <?php if($r['fav_dish_image']): ?><img src="<?= BASE_URL ?>/assets/uploads/dishes/<?= $r['fav_dish_image'] ?>" loading="lazy"><?php else: ?>🍔<?php endif; ?>
                </div>
                <div class="rcard-fav-text">⭐ أعجبه: <?= htmlspecialchars($r['fav_dish_name']) ?></div>
            </div>
            <?php endif; ?>
            <?php if($r['comment']): ?><div class="rcard-comment"><?= htmlspecialchars($r['comment']) ?></div><?php endif; ?>
            <div class="rcard-meta">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>طلب #<?= $r['order_id'] ?></span>
                <?php if($r['table_number']): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>طاولة <?= htmlspecialchars($r['table_number']) ?></span><?php endif; ?>
                <?php if($r['customer_name']): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?= htmlspecialchars($r['customer_name']) ?></span><?php endif; ?>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <button class="show-all-btn" id="showAllBtn" onclick="showAllRatings(this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
        عرض كل التقييمات (<?= count($ratings) ?>)
    </button>
    <?php endif; ?>

    <?php endif; ?>

    <!-- Waiter Ratings -->
    <?php if(!empty($waiter_stats)): ?>
    <div style="margin-top:32px;">
        <div class="section-head"><h3>🧑‍💼 تقييمات النادلين</h3></div>
        <div class="waiter-cards-grid">
            <?php foreach($waiter_stats as $wi=>$ws):
                $wavg=floatval($ws['avg_rating']??0);
                $wtotal=intval($ws['total_ratings']);
                $wcounts=[5=>intval($ws['five_star']),4=>intval($ws['four_star']),3=>intval($ws['three_star']),2=>intval($ws['two_star']),1=>intval($ws['one_star'])];
            ?>
            <div class="waiter-card" style="animation-delay:<?= $wi*.06 ?>s">
                <div class="waiter-card-top">
                    <div class="waiter-avatar">🧑‍💼</div>
                    <div>
                        <div class="waiter-name"><?= htmlspecialchars($ws['name']) ?></div>
                        <div class="waiter-total"><?= $wtotal ?> تقييم</div>
                    </div>
                </div>
                <?php if($wtotal > 0): ?>
                <div class="waiter-score">
                    <span class="waiter-score-num"><?= $wavg ?></span>
                    <span class="waiter-score-max">/ 5</span>
                </div>
                <div class="waiter-stars-disp" style="color:#3B82F6;">
                    <?php $ws_r=round($wavg); for($i=1;$i<=5;$i++) echo $i<=$ws_r?'★':'☆'; ?>
                </div>
                <?php foreach($wcounts as $s=>$c):
                    $pct=$wtotal>0?($c/$wtotal)*100:0; ?>
                <div class="waiter-bar-row">
                    <span class="waiter-bar-lbl"><?= $s ?>★</span>
                    <div class="waiter-bar-track"><div class="waiter-bar-fill" style="width:<?= round($pct) ?>%"></div></div>
                    <span class="waiter-bar-count"><?= $c ?></span>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div style="font-size:12px;color:var(--ink2);font-weight:600;">لا يوجد تقييم بعد</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if(!empty($waiter_comments)): ?>
        <div class="section-head"><h3>💬 تعليقات على الخدمة</h3></div>
        <?php foreach($waiter_comments as $ci=>$wc): ?>
        <div class="wcc" style="animation-delay:<?= $ci*.04 ?>s">
            <div class="wcc-header">
                <span class="wcc-waiter">🧑‍💼 <?= htmlspecialchars($wc['waiter_name']) ?></span>
                <span class="wcc-stars"><?php for($i=1;$i<=5;$i++) echo $i<=$wc['waiter_rating']?'★':'☆'; ?></span>
            </div>
            <div class="wcc-comment"><?= htmlspecialchars($wc['comment']) ?></div>
            <div class="wcc-meta">
                <?php if($wc['table_number']): ?><span>🪑 طاولة <?= htmlspecialchars($wc['table_number']) ?></span><?php endif; ?>
                <?php if($wc['customer_name']): ?><span>👤 <?= htmlspecialchars($wc['customer_name']) ?></span><?php endif; ?>
                <span>🕐 <?= date('d/m/Y H:i', strtotime($wc['created_at'])) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
function showAllRatings(btn) {
    const hidden = document.getElementById('ratingsListHidden');
    if(hidden) {
        hidden.style.display = 'flex';
        hidden.style.flexDirection = 'column';
        hidden.querySelectorAll('.rcard').forEach((el, i) => {
            el.style.animation = 'none';
            void el.offsetWidth;
            el.style.animation = 'cardIn 0.35s cubic-bezier(.22,1,.36,1) ' + (i*0.04) + 's both';
        });
    }
    btn.style.display = 'none';
}
</script>
</body>
</html>