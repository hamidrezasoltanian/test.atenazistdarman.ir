<?php
/* سوابق آموزشی + ماتریس شایستگی — ISO 13485 §6.2 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$userId   = $_SESSION['user_id'];
$userRole = strtolower($_SESSION['role'] ?? 'staff');

/* ─── جداول ─── */
$pdo->exec("
CREATE TABLE IF NOT EXISTS hr_training_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    type ENUM('internal','external','ojt','e_learning','regulatory') NOT NULL DEFAULT 'internal',
    description TEXT DEFAULT NULL,
    trainer VARCHAR(100) DEFAULT NULL,
    duration_hours DECIMAL(5,1) DEFAULT NULL,
    is_mandatory TINYINT(1) DEFAULT 0,
    valid_months INT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS hr_training_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED NOT NULL,
    training_date VARCHAR(12) DEFAULT NULL,
    score DECIMAL(5,2) DEFAULT NULL,
    result ENUM('pass','fail','pending') NOT NULL DEFAULT 'pending',
    certificate_number VARCHAR(50) DEFAULT NULL,
    expiry_date VARCHAR(12) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    INDEX idx_user(user_id), INDEX idx_session(session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS hr_competency_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL,
    session_id INT UNSIGNED NOT NULL,
    is_mandatory TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_role_session (role_name, session_id),
    INDEX idx_role(role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* ─── AJAX ─── */
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    csrf_verify();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    /* ذخیره دوره */
    if ($action === 'save_session') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $type  = $_POST['type'] ?? 'internal';
        $trainer = trim($_POST['trainer'] ?? '');
        $hours   = (float)($_POST['duration_hours'] ?? 0);
        $mand    = isset($_POST['is_mandatory']) ? 1 : 0;
        $valid   = (int)($_POST['valid_months'] ?? 0) ?: null;
        $desc    = trim($_POST['description'] ?? '');
        if (!$title) { echo json_encode(['ok'=>false,'msg'=>'عنوان الزامی است']); exit; }
        if ($id) {
            $s = $pdo->prepare("UPDATE hr_training_sessions SET title=?,type=?,trainer=?,duration_hours=?,is_mandatory=?,valid_months=?,description=? WHERE id=? AND is_deleted=0");
            $s->execute([$title,$type,$trainer,$hours,$mand,$valid,$desc,$id]);
        } else {
            $s = $pdo->prepare("INSERT INTO hr_training_sessions (title,type,trainer,duration_hours,is_mandatory,valid_months,description,created_by) VALUES (?,?,?,?,?,?,?,?)");
            $s->execute([$title,$type,$trainer,$hours,$mand,$valid,$desc,$userId]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }

    /* حذف دوره */
    if ($action === 'delete_session') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE hr_training_sessions SET is_deleted=1 WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ذخیره سابقه آموزشی */
    if ($action === 'save_record') {
        $id        = (int)($_POST['id'] ?? 0);
        $traineeId = (int)($_POST['user_id'] ?? 0);
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $date      = trim($_POST['training_date'] ?? '');
        $score     = strlen($_POST['score'] ?? '') ? (float)$_POST['score'] : null;
        $result    = $_POST['result'] ?? 'pending';
        $cert      = trim($_POST['certificate_number'] ?? '');
        $expiry    = trim($_POST['expiry_date'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        if (!$traineeId || !$sessionId) { echo json_encode(['ok'=>false,'msg'=>'کاربر و دوره الزامی است']); exit; }
        if ($id) {
            $s = $pdo->prepare("UPDATE hr_training_records SET user_id=?,session_id=?,training_date=?,score=?,result=?,certificate_number=?,expiry_date=?,notes=?,recorded_by=? WHERE id=? AND is_deleted=0");
            $s->execute([$traineeId,$sessionId,$date,$score,$result,$cert,$expiry,$notes,$userId,$id]);
        } else {
            $s = $pdo->prepare("INSERT INTO hr_training_records (user_id,session_id,training_date,score,result,certificate_number,expiry_date,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $s->execute([$traineeId,$sessionId,$date,$score,$result,$cert,$expiry,$notes,$userId]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }

    /* حذف سابقه */
    if ($action === 'delete_record') {
        $pdo->prepare("UPDATE hr_training_records SET is_deleted=1 WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ذخیره ماتریس شایستگی */
    if ($action === 'save_competency') {
        $role      = trim($_POST['role_name'] ?? '');
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $mand      = isset($_POST['is_mandatory']) ? 1 : 0;
        if (!$role || !$sessionId) { echo json_encode(['ok'=>false,'msg'=>'نقش و دوره الزامی است']); exit; }
        $s = $pdo->prepare("INSERT INTO hr_competency_requirements (role_name,session_id,is_mandatory) VALUES (?,?,?) ON DUPLICATE KEY UPDATE is_mandatory=?");
        $s->execute([$role,$sessionId,$mand,$mand]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* حذف از ماتریس */
    if ($action === 'delete_competency') {
        $pdo->prepare("DELETE FROM hr_competency_requirements WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* گزارش شکاف شایستگی */
    if ($action === 'get_gap_report') {
        $rows = $pdo->query("
            SELECT u.id user_id, u.name user_name, u.role,
                   hts.id session_id, hts.title session_title, hts.is_mandatory,
                   (SELECT MAX(r.id) FROM hr_training_records r
                    WHERE r.user_id=u.id AND r.session_id=hts.id AND r.result='pass' AND r.is_deleted=0) AS record_id,
                   (SELECT r.expiry_date FROM hr_training_records r
                    WHERE r.user_id=u.id AND r.session_id=hts.id AND r.result='pass' AND r.is_deleted=0
                    ORDER BY r.id DESC LIMIT 1) AS expiry_date
            FROM users u
            JOIN hr_competency_requirements cr ON LOWER(cr.role_name)=LOWER(u.role)
            JOIN hr_training_sessions hts ON hts.id=cr.session_id AND hts.is_deleted=0
            WHERE u.is_deleted=0
            ORDER BY u.name, hts.title
        ")->fetchAll(PDO::FETCH_ASSOC);
        $today = jdate('Y/m/d');
        foreach ($rows as &$r) {
            $r['status'] = 'missing';
            if ($r['record_id']) {
                $r['status'] = (!$r['expiry_date'] || $r['expiry_date'] >= $today) ? 'valid' : 'expired';
            }
        }
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'عملیات نامعتبر']); exit;
}

/* ─── داده صفحه ─── */
$sessions = $pdo->query("SELECT * FROM hr_training_sessions WHERE is_deleted=0 ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
$users    = $pdo->query("SELECT id, name, role FROM users WHERE is_deleted=0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$records = $pdo->query("
    SELECT r.*, u.name user_name, u.role user_role, s.title session_title, s.type session_type
    FROM hr_training_records r
    JOIN users u ON u.id=r.user_id
    JOIN hr_training_sessions s ON s.id=r.session_id
    WHERE r.is_deleted=0
    ORDER BY r.training_date DESC, r.id DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$competencies = $pdo->query("
    SELECT cr.*, s.title session_title
    FROM hr_competency_requirements cr
    JOIN hr_training_sessions s ON s.id=cr.session_id AND s.is_deleted=0
    ORDER BY cr.role_name, s.title
")->fetchAll(PDO::FETCH_ASSOC);

/* آمار */
$today = jdate('Y/m/d');
$totalSessions  = count($sessions);
$totalRecords   = count($records);
$expiredRecords = count(array_filter($records, fn($r) => $r['expiry_date'] && $r['expiry_date'] < $today));
$passedThisYear = count(array_filter($records, fn($r) => $r['result']==='pass' && substr($r['training_date']??'',0,4)===substr($today,0,4)));

$typeLabels = ['internal'=>'داخلی','external'=>'خارجی','ojt'=>'حین کار','e_learning'=>'آنلاین','regulatory'=>'قانونی'];
$resultLabels = ['pass'=>'قبول','fail'=>'رد','pending'=>'در جریان'];
$resultColors = ['pass'=>'green','fail'=>'rose','pending'=>'amber'];
$roles = ['admin'=>'مدیریت','management'=>'مدیرعامل','sales'=>'فروش','warehouse'=>'انبار','accounting'=>'حسابداری','hr'=>'HR','quality'=>'کیفیت'];

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="/assets/css/fin_module.css">
<style>
.gap-valid{color:#16a34a;font-weight:600}
.gap-expired{color:#dc2626;font-weight:600}
.gap-missing{color:#9ca3af}
.score-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600}
</style>

<div class="fin-page-wrap" dir="rtl">
<div class="fin-page-header">
  <h1 class="fin-page-title">سوابق آموزشی — ISO 13485 §6.2</h1>
  <div style="display:flex;gap:8px">
    <button class="fin-btn fin-btn-primary" onclick="openDrawer('session')">+ دوره جدید</button>
    <button class="fin-btn fin-btn-secondary" onclick="openDrawer('record')">+ ثبت سابقه</button>
  </div>
</div>

<!-- کارت‌های آمار -->
<div class="fin-stats-row">
  <div class="fin-stat-card blue"><div class="fin-stat-value"><?= $totalSessions ?></div><div class="fin-stat-label">دوره‌های تعریف‌شده</div></div>
  <div class="fin-stat-card green"><div class="fin-stat-value"><?= $passedThisYear ?></div><div class="fin-stat-label">قبولی امسال</div></div>
  <div class="fin-stat-card rose"><div class="fin-stat-value"><?= $expiredRecords ?></div><div class="fin-stat-label">گواهینامه منقضی</div></div>
  <div class="fin-stat-card amber"><div class="fin-stat-value"><?= $totalRecords ?></div><div class="fin-stat-label">کل سوابق</div></div>
</div>

<!-- تب‌ها -->
<div class="fin-panel">
<div class="fin-tabs" id="mainTabs">
  <button class="fin-tab active" data-tab="tab-sessions">دوره‌های آموزشی</button>
  <button class="fin-tab" data-tab="tab-records">سوابق پرسنل</button>
  <button class="fin-tab" data-tab="tab-competency">ماتریس شایستگی</button>
  <button class="fin-tab" data-tab="tab-gap">گزارش شکاف</button>
</div>

<!-- دوره‌ها -->
<div id="tab-sessions" class="fin-tab-content active" style="padding:16px">
<table class="fin-table">
  <thead><tr><th>#</th><th>عنوان دوره</th><th>نوع</th><th>مدرس</th><th>مدت (ساعت)</th><th>اعتبار</th><th>اجباری</th><th>عملیات</th></tr></thead>
  <tbody>
  <?php foreach ($sessions as $s): ?>
  <tr>
    <td><?= $s['id'] ?></td>
    <td><strong><?= htmlspecialchars($s['title']) ?></strong>
      <?php if ($s['description']): ?><br><small style="color:#6b7280"><?= htmlspecialchars(mb_substr($s['description'],0,60)) ?>…</small><?php endif; ?>
    </td>
    <td><span class="fin-badge"><?= $typeLabels[$s['type']] ?? $s['type'] ?></span></td>
    <td><?= htmlspecialchars($s['trainer'] ?: '—') ?></td>
    <td><?= $s['duration_hours'] ? $s['duration_hours'].' ساعت' : '—' ?></td>
    <td><?= $s['valid_months'] ? $s['valid_months'].' ماه' : 'نامحدود' ?></td>
    <td><?= $s['is_mandatory'] ? '<span class="fin-badge" style="background:#fef2f2;color:#dc2626">اجباری</span>' : '<span class="fin-badge">اختیاری</span>' ?></td>
    <td>
      <button class="fin-btn fin-btn-xs fin-btn-secondary" onclick='editSession(<?= json_encode($s) ?>)'>ویرایش</button>
      <button class="fin-btn fin-btn-xs" style="background:#fef2f2;color:#dc2626" onclick="deleteSession(<?= $s['id'] ?>)">حذف</button>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$sessions): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:32px">دوره‌ای ثبت نشده</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<!-- سوابق -->
<div id="tab-records" class="fin-tab-content" style="padding:16px">
<table class="fin-table">
  <thead><tr><th>پرسنل</th><th>نقش</th><th>دوره</th><th>تاریخ</th><th>نمره</th><th>نتیجه</th><th>انقضا</th><th>شماره گواهی</th><th>عملیات</th></tr></thead>
  <tbody>
  <?php foreach ($records as $r):
    $isExpired = $r['expiry_date'] && $r['expiry_date'] < $today;
    $isNear    = !$isExpired && $r['expiry_date'] && $r['expiry_date'] <= jdate('Y/m/d', strtotime('+30 days'));
  ?>
  <tr>
    <td><?= htmlspecialchars($r['user_name']) ?></td>
    <td><span class="fin-badge"><?= htmlspecialchars($r['user_role']) ?></span></td>
    <td><?= htmlspecialchars($r['session_title']) ?></td>
    <td><?= $r['training_date'] ?: '—' ?></td>
    <td><?= $r['score'] !== null ? $r['score'] : '—' ?></td>
    <td><span class="fin-badge fin-badge-<?= $resultColors[$r['result']] ?>"><?= $resultLabels[$r['result']] ?></span></td>
    <td style="<?= $isExpired ? 'color:#dc2626;font-weight:600' : ($isNear ? 'color:#d97706;font-weight:600' : '') ?>">
      <?= $r['expiry_date'] ?: 'نامحدود' ?>
      <?php if ($isExpired): ?> ⚠️<?php elseif ($isNear): ?> ⏰<?php endif; ?>
    </td>
    <td><?= htmlspecialchars($r['certificate_number'] ?: '—') ?></td>
    <td>
      <button class="fin-btn fin-btn-xs fin-btn-secondary" onclick='editRecord(<?= json_encode($r) ?>)'>ویرایش</button>
      <button class="fin-btn fin-btn-xs" style="background:#fef2f2;color:#dc2626" onclick="deleteRecord(<?= $r['id'] ?>)">حذف</button>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$records): ?><tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:32px">سابقه‌ای ثبت نشده</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<!-- ماتریس شایستگی -->
<div id="tab-competency" class="fin-tab-content" style="padding:16px">
<div style="display:flex;justify-content:flex-end;margin-bottom:12px">
  <button class="fin-btn fin-btn-primary" onclick="openDrawer('competency')">+ افزودن به ماتریس</button>
</div>
<table class="fin-table">
  <thead><tr><th>#</th><th>نقش</th><th>دوره موردنیاز</th><th>اجباری</th><th>حذف</th></tr></thead>
  <tbody id="competencyBody">
  <?php foreach ($competencies as $c): ?>
  <tr id="comp-<?= $c['id'] ?>">
    <td><?= $c['id'] ?></td>
    <td><?= htmlspecialchars($roles[$c['role_name']] ?? $c['role_name']) ?></td>
    <td><?= htmlspecialchars($c['session_title']) ?></td>
    <td><?= $c['is_mandatory'] ? '<span class="fin-badge" style="background:#fef2f2;color:#dc2626">اجباری</span>' : '<span class="fin-badge">اختیاری</span>' ?></td>
    <td><button class="fin-btn fin-btn-xs" style="background:#fef2f2;color:#dc2626" onclick="deleteCompetency(<?= $c['id'] ?>)">حذف</button></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$competencies): ?><tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:32px">ماتریس خالی است</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<!-- گزارش شکاف -->
<div id="tab-gap" class="fin-tab-content" style="padding:16px">
<div style="text-align:center;padding:40px 0">
  <button class="fin-btn fin-btn-primary" onclick="loadGapReport()">📊 بارگذاری گزارش شکاف شایستگی</button>
  <p style="color:#6b7280;margin-top:8px;font-size:13px">بررسی می‌کند هر پرسنل چه دوره‌های الزامی را گذرانده یا نگذرانده است</p>
</div>
<div id="gapReport" style="display:none"></div>
</div>

</div><!-- fin-panel -->

<!-- ─── Drawer دوره ─── -->
<div class="fin-drawer-overlay" id="drawerSession">
<div class="fin-drawer">
  <div class="fin-drawer-header"><h3>دوره آموزشی</h3><button onclick="closeDrawer('session')">✕</button></div>
  <div class="fin-drawer-body">
    <form id="formSession" onsubmit="submitSession(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_session">
      <input type="hidden" name="id" id="s_id" value="0">
      <div class="fin-form-group"><label>عنوان دوره *</label><input type="text" name="title" id="s_title" class="fin-input" required></div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>نوع</label>
          <select name="type" id="s_type" class="fin-input">
            <option value="internal">داخلی</option><option value="external">خارجی</option>
            <option value="ojt">حین کار (OJT)</option><option value="e_learning">آنلاین</option>
            <option value="regulatory">قانونی/الزامی</option>
          </select>
        </div>
        <div class="fin-form-group"><label>مدرس</label><input type="text" name="trainer" id="s_trainer" class="fin-input" placeholder="نام مدرس/موسسه"></div>
      </div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>مدت (ساعت)</label><input type="number" name="duration_hours" id="s_hours" class="fin-input" step="0.5" min="0"></div>
        <div class="fin-form-group"><label>اعتبار گواهی (ماه)</label><input type="number" name="valid_months" id="s_valid" class="fin-input" min="0" placeholder="خالی=نامحدود"></div>
      </div>
      <div class="fin-form-group"><label>توضیحات</label><textarea name="description" id="s_desc" class="fin-input" rows="3"></textarea></div>
      <div class="fin-form-group"><label><input type="checkbox" name="is_mandatory" id="s_mand"> اجباری برای همه پرسنل</label></div>
      <div class="fin-drawer-footer"><button type="submit" class="fin-btn fin-btn-primary">ذخیره</button><button type="button" class="fin-btn fin-btn-secondary" onclick="closeDrawer('session')">انصراف</button></div>
    </form>
  </div>
</div>
</div>

<!-- ─── Drawer سابقه ─── -->
<div class="fin-drawer-overlay" id="drawerRecord">
<div class="fin-drawer">
  <div class="fin-drawer-header"><h3>ثبت سابقه آموزشی</h3><button onclick="closeDrawer('record')">✕</button></div>
  <div class="fin-drawer-body">
    <form id="formRecord" onsubmit="submitRecord(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_record">
      <input type="hidden" name="id" id="r_id" value="0">
      <div class="fin-form-row">
        <div class="fin-form-group"><label>پرسنل *</label>
          <select name="user_id" id="r_user" class="fin-input" required>
            <option value="">انتخاب کنید</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= $u['role'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fin-form-group"><label>دوره *</label>
          <select name="session_id" id="r_session" class="fin-input" required>
            <option value="">انتخاب کنید</option>
            <?php foreach ($sessions as $s): ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>تاریخ آموزش</label><input type="text" name="training_date" id="r_date" class="fin-input" placeholder="۱۴۰۳/۰۱/۰۱"></div>
        <div class="fin-form-group"><label>تاریخ انقضا گواهی</label><input type="text" name="expiry_date" id="r_expiry" class="fin-input" placeholder="۱۴۰۵/۰۱/۰۱"></div>
      </div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>نمره (0-100)</label><input type="number" name="score" id="r_score" class="fin-input" min="0" max="100" step="0.01"></div>
        <div class="fin-form-group"><label>نتیجه</label>
          <select name="result" id="r_result" class="fin-input">
            <option value="pending">در جریان</option><option value="pass">قبول</option><option value="fail">رد</option>
          </select>
        </div>
      </div>
      <div class="fin-form-group"><label>شماره گواهینامه</label><input type="text" name="certificate_number" id="r_cert" class="fin-input"></div>
      <div class="fin-form-group"><label>توضیحات</label><textarea name="notes" id="r_notes" class="fin-input" rows="2"></textarea></div>
      <div class="fin-drawer-footer"><button type="submit" class="fin-btn fin-btn-primary">ذخیره</button><button type="button" class="fin-btn fin-btn-secondary" onclick="closeDrawer('record')">انصراف</button></div>
    </form>
  </div>
</div>
</div>

<!-- ─── Drawer ماتریس ─── -->
<div class="fin-drawer-overlay" id="drawerCompetency">
<div class="fin-drawer" style="max-width:480px">
  <div class="fin-drawer-header"><h3>افزودن به ماتریس شایستگی</h3><button onclick="closeDrawer('competency')">✕</button></div>
  <div class="fin-drawer-body">
    <form id="formCompetency" onsubmit="submitCompetency(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_competency">
      <div class="fin-form-group"><label>نقش</label>
        <select name="role_name" class="fin-input" required>
          <option value="">انتخاب کنید</option>
          <?php foreach ($roles as $k => $v): ?>
          <option value="<?= $k ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fin-form-group"><label>دوره موردنیاز</label>
        <select name="session_id" class="fin-input" required>
          <option value="">انتخاب کنید</option>
          <?php foreach ($sessions as $s): ?>
          <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fin-form-group"><label><input type="checkbox" name="is_mandatory" checked> اجباری</label></div>
      <div class="fin-drawer-footer"><button type="submit" class="fin-btn fin-btn-primary">افزودن</button><button type="button" class="fin-btn fin-btn-secondary" onclick="closeDrawer('competency')">انصراف</button></div>
    </form>
  </div>
</div>
</div>

</div><!-- fin-page-wrap -->

<script>
/* تب‌ها */
document.querySelectorAll('.fin-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.fin-tab,.fin-tab-content').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

function openDrawer(type) {
    if (type==='session') { document.getElementById('s_id').value=0; document.getElementById('formSession').reset(); }
    if (type==='record')  { document.getElementById('r_id').value=0;  document.getElementById('formRecord').reset(); }
    document.getElementById('drawer'+type.charAt(0).toUpperCase()+type.slice(1)).classList.add('open');
}
function closeDrawer(type) {
    document.getElementById('drawer'+type.charAt(0).toUpperCase()+type.slice(1)).classList.remove('open');
}

function editSession(s) {
    document.getElementById('s_id').value    = s.id;
    document.getElementById('s_title').value = s.title;
    document.getElementById('s_type').value  = s.type;
    document.getElementById('s_trainer').value = s.trainer || '';
    document.getElementById('s_hours').value   = s.duration_hours || '';
    document.getElementById('s_valid').value   = s.valid_months || '';
    document.getElementById('s_desc').value    = s.description || '';
    document.getElementById('s_mand').checked  = !!parseInt(s.is_mandatory);
    document.getElementById('drawerSession').classList.add('open');
}

function editRecord(r) {
    document.getElementById('r_id').value      = r.id;
    document.getElementById('r_user').value    = r.user_id;
    document.getElementById('r_session').value = r.session_id;
    document.getElementById('r_date').value    = r.training_date || '';
    document.getElementById('r_expiry').value  = r.expiry_date || '';
    document.getElementById('r_score').value   = r.score || '';
    document.getElementById('r_result').value  = r.result;
    document.getElementById('r_cert').value    = r.certificate_number || '';
    document.getElementById('r_notes').value   = r.notes || '';
    document.getElementById('drawerRecord').classList.add('open');
}

function submitSession(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    if (!fd.get('is_mandatory')) fd.append('skip_mand','1');
    $.ajax({url:'', method:'POST', data: Object.fromEntries(fd),
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){location.reload();}else{alert(r.msg);} }
    });
}
function submitRecord(e) {
    e.preventDefault();
    $.ajax({url:'', method:'POST', data: $(e.target).serialize(),
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){location.reload();}else{alert(r.msg);} }
    });
}
function submitCompetency(e) {
    e.preventDefault();
    $.ajax({url:'', method:'POST', data: $(e.target).serialize(),
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){location.reload();}else{alert(r.msg);} }
    });
}
function deleteSession(id) {
    if (!confirm('این دوره حذف شود؟')) return;
    $.post('', {action:'delete_session',id:id,_csrf:$('[name=_csrf]').first().val()},
        r => { if(r.ok) location.reload(); }, 'json');
}
function deleteRecord(id) {
    if (!confirm('این سابقه حذف شود؟')) return;
    $.post('', {action:'delete_record',id:id,_csrf:$('[name=_csrf]').first().val()},
        r => { if(r.ok) location.reload(); }, 'json');
}
function deleteCompetency(id) {
    if (!confirm('از ماتریس حذف شود؟')) return;
    $.ajax({url:'', method:'POST',
        data:{action:'delete_competency',id:id,_csrf:$('[name=_csrf]').first().val()},
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok) document.getElementById('comp-'+id).remove(); }
    });
}

function loadGapReport() {
    $.ajax({url:'?action=get_gap_report', headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => {
            if (!r.ok) return;
            const div = document.getElementById('gapReport');
            if (!r.rows.length) { div.innerHTML='<p style="text-align:center;color:#9ca3af">داده‌ای یافت نشد — ابتدا ماتریس شایستگی را تعریف کنید</p>'; div.style.display='block'; return; }
            let html = '<table class="fin-table"><thead><tr><th>پرسنل</th><th>نقش</th><th>دوره موردنیاز</th><th>وضعیت</th><th>انقضا</th></tr></thead><tbody>';
            r.rows.forEach(row => {
                const cls = row.status==='valid'?'gap-valid':row.status==='expired'?'gap-expired':'gap-missing';
                const lbl = row.status==='valid'?'✅ دارد':row.status==='expired'?'⚠️ منقضی':'❌ ندارد';
                html += `<tr><td>${row.user_name}</td><td>${row.role}</td><td>${row.session_title}</td><td class="${cls}">${lbl}</td><td>${row.expiry_date||'—'}</td></tr>`;
            });
            html += '</tbody></table>';
            div.innerHTML = html;
            div.style.display = 'block';
        }
    });
}
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
