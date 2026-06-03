<?php
/*
 * فایل: public_html/admin/crm_provinces.php
 * ماژول CRM — مدیریت استان‌ها و مراکز (کانبان per استان)
 * مراکز از جدول customers خوانده می‌شوند، وضعیت CRM از crm_opportunities
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'msg' => 'نشست منقضی']); exit; }
    header('Location: ../login.php'); exit;
}

// ─── ساخت جداول مورد نیاز ────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_provinces` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `province_name` VARCHAR(100) NOT NULL,
        `potential` TINYINT DEFAULT 2 COMMENT '1=بالا 2=متوسط 3=پایین',
        `biopsy_pct` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'درصد سهم بازار بیوپسی',
        `notes` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_province_name` (`province_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // seed داده ۳۰ استان از Sales-Portal
    $provinces30 = [
        ['فارس',1,7.28],['اصفهان',1,7.68],['سیستان و بلوچستان',1,4.16],
        ['مازندران',1,4.93],['آذربایجان شرقی',1,5.86],['لرستان',2,2.64],
        ['بوشهر',2,1.74],['گلستان',2,2.80],['خراسان جنوبی',3,1.15],
        ['چهارمحال و بختیاری',3,1.42],['اردبیل',3,1.91],['خراسان رضوی',1,9.64],
        ['یزد',2,1.71],['قم',2,1.94],['زنجان',2,1.59],['مرکزی',2,2.15],
        ['گیلان',2,3.81],['خراسان شمالی',3,1.29],['ایلام',3,0.87],
        ['خوزستان',1,7.07],['کرمانشاه',1,2.93],['آذربایجان غربی',1,4.90],
        ['کرمان',1,4.75],['البرز',2,4.07],['همدان',2,2.60],['قزوین',2,1.91],
        ['کردستان',2,2.40],['هرمزگان',2,2.66],
        ['کهگیلویه و بویراحمد',3,1.07],['سمنان',3,1.05],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO crm_provinces (province_name, potential, biopsy_pct) VALUES (?,?,?)");
    foreach ($provinces30 as $p) { $ins->execute($p); }
} catch (Throwable $e) {}

// ─── AJAX handlers ────────────────────────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $userId = (int)$_SESSION['user_id'];

    // تخصیص کارشناس به استان
    if ($action === 'assign_expert') {
        $provName = trim($_POST['province_name'] ?? '');
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        if (!$provName) { echo json_encode(['ok'=>false,'msg'=>'نام استان الزامی است']); exit; }
        if ($targetUserId > 0) {
            $pdo->prepare("INSERT INTO crm_user_provinces (user_id, province_name) VALUES (?,?) ON DUPLICATE KEY UPDATE user_id=user_id")
                ->execute([$targetUserId, $provName]);
        } else {
            // حذف تخصیص
            $pdo->prepare("DELETE FROM crm_user_provinces WHERE province_name=?")->execute([$provName]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    // ذخیره تنظیمات استان (potential)
    if ($action === 'save_province') {
        $provName = trim($_POST['province_name'] ?? '');
        $potential = (int)($_POST['potential'] ?? 2);
        if (!$provName) { echo json_encode(['ok'=>false,'msg'=>'نام استان الزامی']); exit; }
        $pdo->prepare("UPDATE crm_provinces SET potential=? WHERE province_name=?")->execute([$potential, $provName]);
        echo json_encode(['ok'=>true]); exit;
    }

    // دریافت مراکز یک استان برای کانبان
    if ($action === 'get_kanban') {
        $provName = trim($_POST['province_name'] ?? '');
        if (!$provName) { echo json_encode(['ok'=>false,'msg'=>'استان الزامی']); exit; }

        // مراکز = مشتریانی که state آنها برابر نام استان است
        $stmt = $pdo->prepare("
            SELECT c.id, c.company_name, c.company_code, c.type_name, c.manager_name, c.mobile,
                   (SELECT o.stage_id FROM crm_opportunities o WHERE o.customer_id = c.id AND o.status='active' ORDER BY o.id DESC LIMIT 1) as stage_id,
                   (SELECT s.name FROM crm_opportunities o JOIN crm_board_stages s ON o.stage_id = s.id WHERE o.customer_id = c.id AND o.status='active' ORDER BY o.id DESC LIMIT 1) as stage_name,
                   (SELECT s.color FROM crm_opportunities o JOIN crm_board_stages s ON o.stage_id = s.id WHERE o.customer_id = c.id AND o.status='active' ORDER BY o.id DESC LIMIT 1) as stage_color,
                   (SELECT COUNT(*) FROM crm_opportunity_calls cc JOIN crm_opportunities oo ON cc.opportunity_id = oo.id WHERE oo.customer_id = c.id) as call_count,
                   (SELECT MAX(cc.call_date) FROM crm_opportunity_calls cc JOIN crm_opportunities oo ON cc.opportunity_id = oo.id WHERE oo.customer_id = c.id) as last_call
            FROM customers c
            WHERE (c.state = ? OR EXISTS (
                SELECT 1 FROM customer_addresses ca WHERE ca.company_num = c.company_num AND ca.state = ?
            ))
            ORDER BY c.company_name ASC
        ");
        $stmt->execute([$provName, $provName]);
        $centers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // دریافت مراحل کانبان موجود
        $stages = $pdo->query("SELECT DISTINCT s.id, s.name, s.color, s.position FROM crm_board_stages s ORDER BY s.position ASC")->fetchAll(PDO::FETCH_ASSOC);
        // افزودن گروه «بدون فرصت»
        array_unshift($stages, ['id' => 0, 'name' => 'بدون فرصت', 'color' => '#94a3b8', 'position' => -1]);

        // گروه‌بندی مراکز بر اساس مرحله
        $grouped = [];
        foreach ($stages as $s) { $grouped[$s['id']] = ['stage' => $s, 'centers' => []]; }
        foreach ($centers as $c) {
            $sid = $c['stage_id'] ?? 0;
            if (!isset($grouped[$sid])) $sid = 0;
            $grouped[$sid]['centers'][] = $c;
        }
        echo json_encode(['ok'=>true, 'data'=>array_values($grouped), 'total'=>count($centers)]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'عملیات نامعتبر']); exit;
}

// ─── بارگذاری داده برای صفحه ──────────────────────────────────
$currentUserId = (int)$_SESSION['user_id'];
$isManager = ($_SESSION['role'] ?? '') === 'admin';

// لیست کارشناسان فروش
$experts = $pdo->query("SELECT id, first_name, last_name, username FROM users WHERE status='active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

// استان‌ها با اطلاعات کارشناس و تعداد مرکز
$provinces = $pdo->query("
    SELECT cp.*,
           cup.user_id as expert_user_id,
           u.first_name as expert_fname, u.last_name as expert_lname,
           (SELECT COUNT(DISTINCT c.id) FROM customers c
            WHERE c.state = cp.province_name
               OR EXISTS (SELECT 1 FROM customer_addresses ca WHERE ca.company_num = c.company_num AND ca.state = cp.province_name)
           ) as center_count
    FROM crm_provinces cp
    LEFT JOIN crm_user_provinces cup ON cup.province_name = cp.province_name
    LEFT JOIN users u ON u.id = cup.user_id
    ORDER BY cp.potential ASC, cp.province_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// خلاصه per کارشناس
$expertSummary = [];
foreach ($provinces as $p) {
    if (!$p['expert_user_id']) continue;
    $eid = $p['expert_user_id'];
    if (!isset($expertSummary[$eid])) {
        $expertSummary[$eid] = ['name'=>trim($p['expert_fname'].' '.$p['expert_lname']), 'provinces'=>0, 'centers'=>0, 'biopsy'=>0];
    }
    $expertSummary[$eid]['provinces']++;
    $expertSummary[$eid]['centers'] += (int)$p['center_count'];
    $expertSummary[$eid]['biopsy'] += (float)$p['biopsy_pct'];
}

$potLabels = [1=>'P1 — بالا', 2=>'P2 — متوسط', 3=>'P3 — پایین'];
$potColors = [1=>'#16a34a', 2=>'#0ea5e9', 3=>'#f59e0b'];
$potBg     = [1=>'#f0fdf4', 2=>'#f0f9ff', 3=>'#fffbeb'];

$pageTitle = 'مدیریت استان‌ها و مراکز';
$basePath = '../../';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<div class="main-content" style="padding:20px;direction:rtl">

  <!-- کارت‌های خلاصه کارشناسان -->
  <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px">
    <?php foreach ($expertSummary as $eid => $es): ?>
    <div style="flex:1;min-width:200px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px">
      <div style="font-weight:700;font-size:14px;color:#1e293b;margin-bottom:8px"><?= htmlspecialchars($es['name']) ?></div>
      <div style="display:flex;gap:12px;font-size:12px;color:#64748b">
        <span>🗺 <?= $es['provinces'] ?> استان</span>
        <span>🏥 <?= $es['centers'] ?> مرکز</span>
        <span>📊 <?= number_format($es['biopsy'],1) ?>٪</span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($expertSummary)): ?>
    <div style="padding:16px;color:#94a3b8;font-size:13px">هنوز کارشناسی به استانی تخصیص داده نشده</div>
    <?php endif; ?>
  </div>

  <!-- جدول استان‌ها -->
  <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden">
    <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between">
      <h2 style="margin:0;font-size:16px;font-weight:700;color:#1e293b">لیست استان‌ها (<?= count($provinces) ?>)</h2>
      <div style="display:flex;gap:8px">
        <input type="text" id="searchInput" placeholder="🔍 جستجوی استان..." oninput="filterProvinces()"
               style="padding:7px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
        <select id="potFilter" onchange="filterProvinces()" style="padding:7px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
          <option value="">همه سطوح</option>
          <option value="1">P1 — بالا</option>
          <option value="2">P2 — متوسط</option>
          <option value="3">P3 — پایین</option>
        </select>
      </div>
    </div>

    <table style="width:100%;border-collapse:collapse" id="provTable">
      <thead>
        <tr style="background:#f8fafc;font-size:12px;color:#64748b">
          <th style="padding:10px 16px;text-align:right;font-weight:600">استان</th>
          <th style="padding:10px;text-align:center;font-weight:600">سطح</th>
          <th style="padding:10px;text-align:center;font-weight:600">سهم بازار</th>
          <th style="padding:10px;text-align:center;font-weight:600">مراکز</th>
          <th style="padding:10px;text-align:center;font-weight:600">کارشناس</th>
          <th style="padding:10px;text-align:center;font-weight:600">عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($provinces as $p):
            $pc = $potColors[$p['potential']] ?? '#94a3b8';
            $pb = $potBg[$p['potential']] ?? '#f8fafc';
        ?>
        <tr class="prov-row" data-pot="<?= $p['potential'] ?>" data-name="<?= htmlspecialchars($p['province_name']) ?>"
            style="border-bottom:1px solid #f1f5f9;transition:background .15s" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
          <td style="padding:12px 16px;font-weight:600;color:#1e293b"><?= htmlspecialchars($p['province_name']) ?></td>
          <td style="padding:12px;text-align:center">
            <span style="font-size:11px;font-weight:700;background:<?= $pb ?>;color:<?= $pc ?>;border:1px solid <?= $pc ?>44;border-radius:5px;padding:2px 8px">
              <?= $potLabels[$p['potential']] ?? '' ?>
            </span>
          </td>
          <td style="padding:12px;text-align:center;font-weight:700;color:#0ea5e9"><?= number_format($p['biopsy_pct'],2) ?>٪</td>
          <td style="padding:12px;text-align:center">
            <a href="#" onclick="showKanban('<?= htmlspecialchars($p['province_name'],ENT_QUOTES) ?>',this);return false"
               style="font-weight:700;color:#1e293b;text-decoration:none;border-bottom:2px solid #0ea5e9">
              <?= (int)$p['center_count'] ?> مرکز
            </a>
          </td>
          <td style="padding:12px;text-align:center">
            <?php if ($p['expert_user_id']): ?>
              <span style="font-size:12px;font-weight:600;color:#16a34a">
                <?= htmlspecialchars($p['expert_fname'].' '.$p['expert_lname']) ?>
              </span>
            <?php else: ?>
              <span style="font-size:11px;color:#f59e0b">— بدون مسئول</span>
            <?php endif; ?>
          </td>
          <td style="padding:12px;text-align:center">
            <?php if ($isManager): ?>
            <button onclick="openAssign('<?= htmlspecialchars($p['province_name'],ENT_QUOTES) ?>',<?= (int)$p['expert_user_id'] ?>)"
                    style="font-size:11px;padding:4px 10px;border:1px solid #0ea5e9;color:#0369a1;background:#f0f9ff;border-radius:5px;cursor:pointer;font-family:inherit">
              تخصیص کارشناس
            </button>
            <?php endif; ?>
            <button onclick="showKanban('<?= htmlspecialchars($p['province_name'],ENT_QUOTES) ?>',null)"
                    style="font-size:11px;padding:4px 10px;border:1px solid #8b5cf6;color:#7c3aed;background:#faf5ff;border-radius:5px;cursor:pointer;font-family:inherit;margin-right:4px">
              📊 کانبان
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- مودال کانبان استان -->
<div id="kanbanModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;overflow-y:auto;padding:20px">
  <div style="max-width:1100px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden">
    <div style="padding:16px 20px;background:#1e293b;display:flex;align-items:center;justify-content:space-between">
      <h3 id="kanbanTitle" style="margin:0;color:#fff;font-size:15px"></h3>
      <button onclick="document.getElementById('kanbanModal').style.display='none'"
              style="background:transparent;border:none;color:#94a3b8;font-size:20px;cursor:pointer;line-height:1">✕</button>
    </div>
    <div id="kanbanBody" style="padding:16px;overflow-x:auto;min-height:300px">
      <div style="text-align:center;padding:40px;color:#94a3b8">در حال بارگذاری...</div>
    </div>
  </div>
</div>

<!-- مودال تخصیص کارشناس -->
<div id="assignModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9001;display:flex;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;width:340px;max-width:95vw">
    <h3 style="margin:0 0 16px;font-size:14px;color:#1e293b">تخصیص کارشناس به استان <strong id="assignProvName"></strong></h3>
    <select id="assignUserId" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;margin-bottom:16px">
      <option value="0">— بدون کارشناس —</option>
      <?php foreach ($experts as $e): ?>
      <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button onclick="document.getElementById('assignModal').style.display='none'"
              style="padding:8px 16px;border:1px solid #e2e8f0;border-radius:7px;cursor:pointer;font-family:inherit;font-size:13px;background:#f8fafc">انصراف</button>
      <button onclick="saveAssign()"
              style="padding:8px 16px;background:#0ea5e9;color:#fff;border:none;border-radius:7px;cursor:pointer;font-family:inherit;font-size:13px;font-weight:600">💾 ذخیره</button>
    </div>
  </div>
</div>

<script>
var _currentAssignProv = '';

function filterProvinces() {
    var q = document.getElementById('searchInput').value.toLowerCase();
    var pot = document.getElementById('potFilter').value;
    document.querySelectorAll('.prov-row').forEach(function(row) {
        var name = row.getAttribute('data-name').toLowerCase();
        var rowPot = row.getAttribute('data-pot');
        var show = (!q || name.indexOf(q) >= 0) && (!pot || rowPot === pot);
        row.style.display = show ? '' : 'none';
    });
}

function openAssign(provName, currentUserId) {
    _currentAssignProv = provName;
    document.getElementById('assignProvName').textContent = provName;
    var sel = document.getElementById('assignUserId');
    sel.value = currentUserId || 0;
    document.getElementById('assignModal').style.display = 'flex';
}

function saveAssign() {
    var uid = document.getElementById('assignUserId').value;
    fetch(location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body: 'action=assign_expert&province_name='+encodeURIComponent(_currentAssignProv)+'&target_user_id='+uid
    }).then(r=>r.json()).then(function(d) {
        if (d.ok) { document.getElementById('assignModal').style.display='none'; location.reload(); }
        else alert(d.msg);
    });
}

function showKanban(provName, btn) {
    document.getElementById('kanbanTitle').textContent = '📊 کانبان مراکز — ' + provName;
    document.getElementById('kanbanModal').style.display = 'block';
    document.getElementById('kanbanBody').innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8">در حال بارگذاری...</div>';

    fetch(location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body: 'action=get_kanban&province_name='+encodeURIComponent(provName)
    }).then(r=>r.json()).then(function(d) {
        if (!d.ok) { document.getElementById('kanbanBody').innerHTML = '<div style="padding:20px;color:#ef4444">'+d.msg+'</div>'; return; }
        if (d.total === 0) {
            document.getElementById('kanbanBody').innerHTML = '<div style="padding:40px;text-align:center;color:#94a3b8">هیچ مرکزی در این استان ثبت نشده</div>';
            return;
        }
        var html = '<div style="display:flex;gap:12px;min-width:max-content">';
        d.data.forEach(function(group) {
            var s = group.stage;
            var color = s.color || '#94a3b8';
            html += '<div style="min-width:220px;max-width:240px;flex-shrink:0">';
            html += '<div style="padding:8px 12px;background:'+color+'22;border-radius:8px 8px 0 0;border-top:3px solid '+color+';display:flex;align-items:center;justify-content:space-between">';
            html += '<span style="font-size:12px;font-weight:700;color:#1e293b">'+esc(s.name||'بدون مرحله')+'</span>';
            html += '<span style="font-size:11px;font-weight:700;background:'+color+'33;color:'+color+';border-radius:10px;padding:1px 8px">'+group.centers.length+'</span>';
            html += '</div><div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:8px;display:flex;flex-direction:column;gap:6px;min-height:80px">';
            group.centers.forEach(function(c) {
                html += '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:7px;padding:10px;font-size:12px">';
                html += '<div style="font-weight:600;color:#1e293b;margin-bottom:4px">'+esc(c.company_name)+'</div>';
                if (c.type_name) html += '<div style="font-size:10px;color:#64748b;margin-bottom:3px">'+esc(c.type_name)+'</div>';
                html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px">';
                if (c.call_count > 0) html += '<span style="font-size:10px;color:#0ea5e9">📞 '+c.call_count+' تماس</span>';
                else html += '<span style="font-size:10px;color:#94a3b8">بدون تماس</span>';
                html += '<a href="customer_profile.php?id='+c.id+'" target="_blank" style="font-size:10px;color:#8b5cf6;text-decoration:none">پروفایل ←</a>';
                html += '</div></div>';
            });
            if (group.centers.length === 0) {
                html += '<div style="text-align:center;padding:20px;color:#cbd5e1;font-size:11px">—</div>';
            }
            html += '</div></div>';
        });
        html += '</div>';
        document.getElementById('kanbanBody').innerHTML = html;
    });
}

function esc(s) {
    return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
