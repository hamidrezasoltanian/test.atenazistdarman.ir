<?php
/*
 * letters.php — دبیرخانه یکپارچه
 * تمام نامه‌ها در یک صفحه با تب‌بندی: وارده | صادره | داخلی | پیش‌نویس | آرشیو
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId  = (int)$_SESSION['user_id'];
$isAdmin = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$basePath = '../';
$pageTitle = 'دبیرخانه — نامه‌ها';

// ─── عملیات POST (آرشیو / بازیابی / حذف) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die(json_encode(['ok'=>false,'msg'=>'خطای CSRF']));
    $op  = $_POST['op']  ?? '';
    $lid = (int)($_POST['lid'] ?? 0);

    $tab = $_POST['tab'] ?? 'incoming';
    if ($op === 'archive' && ($isAdmin || $lid)) {
        $pdo->prepare("UPDATE letters SET is_archived=1 WHERE id=?")->execute([$lid]);
    } elseif ($op === 'unarchive' && $isAdmin) {
        $pdo->prepare("UPDATE letters SET is_archived=0 WHERE id=?")->execute([$lid]);
    } elseif ($op === 'trash' && $isAdmin) {
        $pdo->prepare("UPDATE letters SET is_deleted=1 WHERE id=?")->execute([$lid]);
    } elseif ($op === 'restore' && $isAdmin) {
        $pdo->prepare("UPDATE letters SET is_deleted=0 WHERE id=?")->execute([$lid]);
    }
    header("Location: letters.php?tab=$tab");
    exit;
}

// ─── پارامترها ───────────────────────────────────────────────────────
$tab     = in_array($_GET['tab'] ?? '', ['incoming','outgoing','internal','draft','archive','trash']) ? $_GET['tab'] : 'incoming';
$search  = trim($_GET['q'] ?? '');
$dateFrom = trim($_GET['df'] ?? '');
$dateTo   = trim($_GET['dt'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 20;
$offset  = ($page - 1) * $limit;

// تبدیل تاریخ شمسی → میلادی
function faDateToGreg($fa) {
    if (!$fa) return '';
    $p = explode('/', faToEn($fa));
    if (count($p) !== 3) return '';
    $g = jalali_to_gregorian((int)$p[0], (int)$p[1], (int)$p[2]);
    return $g[0].'-'.sprintf('%02d',$g[1]).'-'.sprintf('%02d',$g[2]);
}
$dfGreg = faDateToGreg($dateFrom);
$dtGreg = faDateToGreg($dateTo);

// ─── ساخت WHERE بر اساس تب ──────────────────────────────────────────
function buildWhere($tab, $userId, $isAdmin, $search, $dfGreg, $dtGreg) {
    $where  = '';
    $params = [];

    if ($tab === 'draft') {
        $where .= " WHERE l.status='draft' AND l.is_deleted=0 AND l.created_by=:uid";
        $params[':uid'] = $userId;
    } elseif ($tab === 'archive') {
        $where .= " WHERE l.is_archived=1 AND l.is_deleted=0";
        if (!$isAdmin) {
            $where .= " AND (l.created_by=:uid OR l.id IN (SELECT letter_id FROM letter_receivers WHERE receiver_id=:uid2))";
            $params[':uid']  = $userId;
            $params[':uid2'] = $userId;
        }
    } elseif ($tab === 'trash') {
        $where .= " WHERE l.is_deleted=1";
        if (!$isAdmin) {
            $where .= " AND l.created_by=:uid";
            $params[':uid'] = $userId;
        }
    } else {
        $typeMap = ['incoming'=>'incoming','outgoing'=>'outgoing','internal'=>'internal'];
        $lType = $typeMap[$tab] ?? 'incoming';
        $where .= " WHERE l.type=:ltype AND l.is_archived=0 AND l.is_deleted=0";
        $params[':ltype'] = $lType;
        if (!$isAdmin) {
            $where .= " AND (l.created_by=:uid OR l.id IN (SELECT letter_id FROM letter_receivers WHERE receiver_id=:uid2) OR l.id IN (SELECT DISTINCT letter_id FROM letter_referrals WHERE receiver_id=:uid3))";
            $params[':uid']  = $userId;
            $params[':uid2'] = $userId;
            $params[':uid3'] = $userId;
        }
    }

    if ($search) {
        $where .= " AND (l.subject LIKE :sq OR l.indicator_number LIKE :sq2)";
        $params[':sq']  = "%$search%";
        $params[':sq2'] = "%$search%";
    }
    if ($dfGreg) { $where .= " AND l.created_at >= :dfg"; $params[':dfg'] = $dfGreg.' 00:00:00'; }
    if ($dtGreg) { $where .= " AND l.created_at <= :dtg"; $params[':dtg'] = $dtGreg.' 23:59:59'; }

    return [$where, $params];
}

[$where, $params] = buildWhere($tab, $userId, $isAdmin, $search, $dfGreg, $dtGreg);

// ─── شمارش تب‌ها (برای badge) ────────────────────────────────────────
function tabCount($pdo, $tab, $userId, $isAdmin) {
    [$w, $p] = buildWhere($tab, $userId, $isAdmin, '', '', '');
    $st = $pdo->prepare("SELECT COUNT(*) FROM letters l $w");
    $st->execute($p);
    return (int)$st->fetchColumn();
}
$counts = [];
foreach (['incoming','outgoing','internal','draft','archive','trash'] as $t) {
    $counts[$t] = tabCount($pdo, $t, $userId, $isAdmin);
}

// ─── کوئری داده ──────────────────────────────────────────────────────
$totalItems = (function() use ($pdo, $where, $params) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM letters l $where");
    $st->execute($params);
    return (int)$st->fetchColumn();
})();
$totalPages = max(1, ceil($totalItems / $limit));
$page = min($page, $totalPages);
$offset = ($page-1)*$limit;

$sql = "SELECT l.id, l.type, l.subject, l.indicator_number, l.status,
               l.sender_external_name, l.created_at, l.is_archived,
               CONCAT(u.first_name,' ',u.last_name) AS creator_name,
               (SELECT GROUP_CONCAT(CONCAT(ru.first_name,' ',ru.last_name) SEPARATOR '، ')
                FROM letter_receivers lr JOIN users ru ON lr.receiver_id=ru.id
                WHERE lr.letter_id=l.id AND lr.receiver_type='user') AS receivers_names
        FROM letters l
        LEFT JOIN users u ON u.id=l.created_by
        $where
        ORDER BY l.id DESC
        LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$letters = $st->fetchAll(PDO::FETCH_ASSOC);

// ─── برچسب‌های فارسی ──────────────────────────────────────────────────
$tabLabels = [
    'incoming' => 'وارده',
    'outgoing' => 'صادره',
    'internal' => 'داخلی',
    'draft'    => 'پیش‌نویس',
    'archive'  => 'آرشیو',
    'trash'    => 'حذف‌شده',
];
$tabIcons = [
    'incoming' => '📥','outgoing' => '📤','internal' => '🏢',
    'draft' => '📝','archive' => '🗄️','trash' => '🗑️'
];

$extraCss = '
<link rel="stylesheet" href="../vendor/kamaDatepicker/kamadatepicker.min.css">
<style>
.letters-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:20px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:6px;}
.letters-tab{padding:7px 14px;border-radius:7px;text-decoration:none;color:#64748b;font-size:0.85rem;font-weight:500;display:flex;align-items:center;gap:6px;transition:all .15s;border:1px solid transparent;}
.letters-tab:hover{background:#f1f5f9;color:#1e293b;}
.letters-tab.active{background:#2563eb;color:#fff;border-color:#2563eb;}
.tab-badge{background:rgba(255,255,255,0.3);color:inherit;border-radius:10px;padding:1px 6px;font-size:0.72rem;font-weight:bold;}
.letters-tab:not(.active) .tab-badge{background:#f1f5f9;color:#64748b;}
.letters-tab.active .tab-badge{background:rgba(255,255,255,0.3);color:#fff;}

.filter-bar{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;}
.filter-bar .fi{flex:1;min-width:160px;}
.filter-bar label{display:block;font-size:0.8rem;color:#64748b;margin-bottom:4px;font-weight:500;}
.filter-bar input,.filter-bar select{width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:7px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:0.875rem;background:#fff;}
.filter-bar input:focus,.filter-bar select:focus{border-color:#2563eb;outline:none;}

.l-table{width:100%;border-collapse:collapse;background:#fff;}
.l-table th,.l-table td{padding:11px 13px;text-align:right;border-bottom:1px solid #f1f5f9;font-size:0.86rem;vertical-align:middle;}
.l-table th{background:#f8fafc;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;}
.l-table tr:hover{background:#fafbfc;}
.l-table tbody tr:last-child td{border-bottom:none;}

.lbadge{padding:3px 9px;border-radius:20px;font-size:0.73rem;font-weight:600;display:inline-block;}
.lb-draft{background:#f1f5f9;color:#475569;}
.lb-registered{background:#dcfce7;color:#166534;}
.lb-incoming{background:#eff6ff;color:#2563eb;}
.lb-outgoing{background:#fef3c7;color:#92400e;}
.lb-internal{background:#f5f3ff;color:#5b21b6;}
.lb-archive{background:#f1f5f9;color:#64748b;}
.lb-trash{background:#fee2e2;color:#991b1b;}

.act-btn{padding:5px 10px;border-radius:6px;font-size:0.78rem;font-weight:500;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:3px;transition:all .15s;white-space:nowrap;}
.act-view{background:#eff6ff;color:#2563eb;}
.act-refer{background:#f5f3ff;color:#5b21b6;}
.act-arch{background:#f1f5f9;color:#475569;}
.act-del{background:#fee2e2;color:#991b1b;}
.act-btn:hover{opacity:.85;transform:translateY(-1px);}

.empty-state{text-align:center;padding:50px 20px;color:#94a3b8;}
.empty-state .es-icon{font-size:2.5rem;margin-bottom:12px;}
.empty-state .es-text{font-size:0.9rem;}

@media(max-width:768px){
    .letters-tab{padding:6px 10px;font-size:0.8rem;}
    .filter-bar .fi{min-width:100%;}
    .table-responsive{overflow-x:auto;}
    .l-table{min-width:650px;}
}
</style>';

require_once __DIR__ . '/../../templates/header.php';
?>
<div class="main-content" id="mainContent">
<div class="content-wrapper">

<div class="page-header">
    <div class="page-title">📨 دبیرخانه — نامه‌ها</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="letter_create.php" class="btn btn-primary">✏️ ایجاد نامه جدید</a>
        <a href="cartable_inbox.php" class="btn btn-outline">📥 کارتابل من</a>
    </div>
</div>

<!-- تب‌ها -->
<nav class="letters-tabs">
    <?php foreach ($tabLabels as $t => $label):
        $cnt = $counts[$t];
        $activeClass = ($t === $tab) ? 'active' : '';
        $url = "letters.php?tab=$t" . ($search ? "&q=".urlencode($search) : '');
    ?>
    <a href="<?= $url ?>" class="letters-tab <?= $activeClass ?>">
        <?= $tabIcons[$t] ?> <?= $label ?>
        <span class="tab-badge"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
</nav>

<!-- فیلتر -->
<form method="GET" class="filter-bar">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <div class="fi">
        <label>جستجو (موضوع / اندیکاتور)</label>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="جستجو...">
    </div>
    <div class="fi">
        <label>از تاریخ</label>
        <input type="text" name="df" id="dfInput" value="<?= htmlspecialchars($dateFrom) ?>" placeholder="1403/01/01" autocomplete="off">
    </div>
    <div class="fi">
        <label>تا تاریخ</label>
        <input type="text" name="dt" id="dtInput" value="<?= htmlspecialchars($dateTo) ?>" placeholder="1403/12/29" autocomplete="off">
    </div>
    <div style="flex:0 0 auto;display:flex;gap:6px;">
        <button type="submit" class="btn btn-primary">🔍 فیلتر</button>
        <a href="letters.php?tab=<?= $tab ?>" class="btn btn-outline">پاک</a>
    </div>
</form>

<!-- جدول -->
<div class="table-responsive" style="border-radius:10px;border:1px solid #e2e8f0;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06);">
    <?php if (empty($letters)): ?>
        <div class="empty-state">
            <div class="es-icon"><?= $tabIcons[$tab] ?></div>
            <div class="es-text">نامه‌ای در این بخش وجود ندارد.</div>
        </div>
    <?php else: ?>
    <table class="l-table">
        <thead>
        <tr>
            <th style="width:10%">اندیکاتور</th>
            <th style="width:30%">موضوع</th>
            <?php if (in_array($tab,['outgoing','internal','archive'])): ?>
                <th style="width:16%">ثبت‌کننده</th>
                <th style="width:16%">گیرندگان</th>
            <?php else: ?>
                <th style="width:16%">فرستنده</th>
                <th style="width:16%">گیرندگان</th>
            <?php endif; ?>
            <th style="width:10%">تاریخ</th>
            <th style="width:8%">نوع</th>
            <th style="width:10%;text-align:center">عملیات</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($letters as $l):
            $statusLabel = ($l['status']==='draft') ? '<span class="lbadge lb-draft">پیش‌نویس</span>' : '<span class="lbadge lb-registered">ثبت‌شده</span>';
            $typeLabel   = match($l['type']??'') {
                'incoming' => '<span class="lbadge lb-incoming">وارده</span>',
                'outgoing' => '<span class="lbadge lb-outgoing">صادره</span>',
                'internal' => '<span class="lbadge lb-internal">داخلی</span>',
                default    => ''
            };
            if ($tab==='archive') $typeLabel .= ' <span class="lbadge lb-archive">آرشیو</span>';
            if ($tab==='trash')   $typeLabel .= ' <span class="lbadge lb-trash">حذف</span>';
            $dateFa = function_exists('jdate') ? jdate('Y/m/d', strtotime($l['created_at'])) : substr($l['created_at'],0,10);
        ?>
        <tr>
            <td><?= htmlspecialchars($l['indicator_number'] ?: '—') ?></td>
            <td style="max-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($l['subject']) ?>"><?= htmlspecialchars($l['subject']) ?></td>
            <?php if (in_array($tab,['outgoing','internal'])): ?>
                <td><?= htmlspecialchars($l['creator_name'] ?: '—') ?></td>
            <?php else: ?>
                <td><?= htmlspecialchars($l['sender_external_name'] ?: ($l['creator_name'] ?: '—')) ?></td>
            <?php endif; ?>
            <td style="font-size:0.8rem;color:#64748b;"><?= htmlspecialchars(mb_substr($l['receivers_names']??'—', 0, 30)) ?></td>
            <td style="font-size:0.8rem;color:#64748b;"><?= $dateFa ?></td>
            <td><?= $typeLabel ?></td>
            <td style="text-align:center;">
                <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                    <a href="letter_view.php?id=<?= $l['id'] ?>" class="act-btn act-view" title="مشاهده">👁</a>
                    <?php if ($l['status'] !== 'draft'): ?>
                        <a href="letter_refer.php?id=<?= $l['id'] ?>" class="act-btn act-refer" title="ارجاع">↩</a>
                    <?php endif; ?>
                    <?php if ($l['status'] === 'draft'): ?>
                        <a href="letter_edit.php?id=<?= $l['id'] ?>" class="act-btn act-arch" title="ویرایش">✏️</a>
                    <?php endif; ?>
                    <?php if ($tab !== 'archive' && $tab !== 'trash' && $isAdmin): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('آرشیو شود؟')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="op" value="archive">
                            <input type="hidden" name="lid" value="<?= $l['id'] ?>">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <button type="submit" class="act-btn act-arch" title="آرشیو">📦</button>
                        </form>
                    <?php elseif ($tab === 'archive' && $isAdmin): ?>
                        <form method="POST" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="op" value="unarchive">
                            <input type="hidden" name="lid" value="<?= $l['id'] ?>">
                            <input type="hidden" name="tab" value="archive">
                            <button type="submit" class="act-btn act-view" title="بازیابی از آرشیو">♻️</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($tab !== 'trash' && $isAdmin): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="op" value="trash">
                            <input type="hidden" name="lid" value="<?= $l['id'] ?>">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <button type="submit" class="act-btn act-del" title="حذف">🗑</button>
                        </form>
                    <?php elseif ($tab === 'trash' && $isAdmin): ?>
                        <form method="POST" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="op" value="restore">
                            <input type="hidden" name="lid" value="<?= $l['id'] ?>">
                            <input type="hidden" name="tab" value="trash">
                            <button type="submit" class="act-btn act-view" title="بازیابی">♻️</button>
                        </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- صفحه‌بندی -->
<?php if ($totalPages > 1): ?>
<div class="pagination" style="margin-top:16px;">
    <?php for ($i=1; $i<=$totalPages; $i++):
        $url = "letters.php?tab=$tab&page=$i" . ($search ? "&q=".urlencode($search) : '') . ($dateFrom ? "&df=".urlencode($dateFrom) : '') . ($dateTo ? "&dt=".urlencode($dateTo) : '');
    ?>
        <a href="<?= $url ?>" class="page-link <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

</div><!-- content-wrapper -->
</div><!-- main-content -->

<?php require_once __DIR__ . '/../../templates/sidebar.php'; ?>

<script src="../vendor/kamaDatepicker/kamadatepicker.min.js"></script>
<script>
if(typeof KamaDatepicker !== 'undefined'){
    new KamaDatepicker('#dfInput',{format:'YYYY/MM/DD',direction:'rtl'});
    new KamaDatepicker('#dtInput',{format:'YYYY/MM/DD',direction:'rtl'});
}
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
