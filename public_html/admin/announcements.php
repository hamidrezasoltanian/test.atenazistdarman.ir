<?php
/*
 * فایل: public_html/admin/announcements.php
 * توضیحات: نمایش هوشمند تابلو اعلانات به صورت جدولی و نمایش جزئیات در مودال (۲۰ رکورد در هر صفحه)
 */

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        ob_clean(); header('Content-Type: application/json'); echo json_encode(['status' => 'error']); exit;
    }
    header("Location: ../login.php"); exit;
}

$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

$isManagerRole = (in_array($role, ['admin', 'management', 'manager']) || strpos($role, '_manager') !== false);
$canCreate = $isManagerRole;

// --- پردازش درخواست‌های AJAX (حذف/مخفی کردن) ---
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    if ($isAjax) { ob_clean(); header('Content-Type: application/json'); }
    try {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            if ($isAjax) { echo json_encode(['status' => 'error', 'message' => 'نشست نامعتبر است.']); exit; }
            http_response_code(403); exit;
        }
        $id = (int)$_POST['id'];
        
        $stmt = $pdo->prepare("SELECT created_by FROM announcements WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $createdBy = (int)$stmt->fetchColumn();
        
        if ($role === 'admin' || $createdBy === $userId) {
            $pdo->prepare("DELETE FROM announcement_targets WHERE announcement_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM announcements WHERE id=?")->execute([$id]);
        } else {
            $pdo->prepare("INSERT IGNORE INTO user_hidden_announcements (user_id, announcement_id) VALUES (?, ?)")->execute([$userId, $id]);
        }
        
        if ($isAjax) { echo json_encode(['status'=>'success']); exit; }
        else { header("Location: announcements.php?msg=deleted"); exit; }
    } catch (Exception $e) {
        if ($isAjax) { echo json_encode(['status'=>'error', 'message'=>'خطا در پردازش درخواست.']); exit; }
    }
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20; 
$offset = ($page - 1) * $limit;
$search = $_GET['q'] ?? '';

$where = "a.status = 'published'"; 
$params = [];

if ($search) {
    $where .= " AND (a.title LIKE ? OR a.content LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}

// عدم نمایش مخفی شده‌ها
$where .= " AND a.id NOT IN (SELECT announcement_id FROM user_hidden_announcements WHERE user_id = ?)";
$params[] = $userId;

// === منطق نمایش هوشمند اعلانات بر اساس دپارتمان و کاربر ===
if ($role !== 'admin' && $role !== 'management') {
    $deptId = 0;
    try {
        $stmtDept = $pdo->prepare("SELECT department_id FROM users WHERE id = ? LIMIT 1");
        $stmtDept->execute([$userId]);
        $fetchedDept = $stmtDept->fetchColumn();
        if ($fetchedDept !== false && $fetchedDept !== null) {
            $deptId = (int)$fetchedDept;
        }
    } catch (Exception $e) {}

    $where .= " AND (
        ( (a.target_department_id IS NULL OR a.target_department_id = '' OR a.target_department_id = '0') 
          AND NOT EXISTS (SELECT 1 FROM announcement_targets WHERE announcement_id = a.id) 
        )
        OR (a.target_department_id IS NOT NULL AND FIND_IN_SET(?, a.target_department_id) > 0)
        OR EXISTS (SELECT 1 FROM announcement_targets WHERE announcement_id = a.id AND user_id = ?)
        OR a.created_by = ?
    )";
    $params[] = $deptId; // برای FIND_IN_SET
    $params[] = $userId; // برای targets
    $params[] = $userId; // برای created_by
}

$sql = "SELECT a.*, u.first_name, u.last_name, c.title as cat_title, c.color as cat_color,
        CASE WHEN ar.announcement_id IS NULL THEN 0 ELSE 1 END as is_read
        FROM announcements a 
        LEFT JOIN users u ON a.created_by = u.id 
        LEFT JOIN announcement_categories c ON a.category_id = c.id
        LEFT JOIN announcement_reads ar ON ar.announcement_id = a.id AND ar.user_id = ?
        WHERE $where 
        ORDER BY a.priority DESC, a.created_at DESC 
        LIMIT $limit OFFSET $offset";

array_unshift($params, $userId); 

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ثبت بازدید اعلانات
if (!empty($list)) {
    $unreadIds = [];
    foreach ($list as $item) {
        if ($item['is_read'] == 0 && $item['status'] == 'published') {
            $unreadIds[] = $item['id'];
        }
    }
    if (!empty($unreadIds)) {
        $insertValues = [];
        $insertParams = [];
        foreach ($unreadIds as $aid) {
            $insertValues[] = "(?, ?)";
            $insertParams[] = $userId;
            $insertParams[] = $aid;
        }
        if (!empty($insertValues)) {
            $sqlInsert = "INSERT IGNORE INTO announcement_reads (user_id, announcement_id) VALUES " . implode(", ", $insertValues);
            $pdo->prepare($sqlInsert)->execute($insertParams);
        }
    }
}

// محاسبه تعداد صفحات
array_shift($params); // حذف $userId اول که برای is_read اضافه کرده بودیم
$countStmt = $pdo->prepare("SELECT COUNT(DISTINCT a.id) FROM announcements a WHERE $where");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$pageTitle = 'تابلو اعلانات';
$basePath = '../';

$extraCss = '<style>
    .content-wrapper { padding: 25px; max-width: 1300px; margin: 0 auto; }
    .search-box { margin-bottom: 25px !important; }
    
    /* استایل‌های جدول */
    .table-responsive { width: 100%; overflow-x: auto; border-radius: 12px; border: 1px solid #e5e7eb; margin-bottom: 20px; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .styled-table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.9rem; vertical-align: middle; }
    .styled-table tr:hover td { background: #f8fafc; }
    
    .cat-badge { font-size: 0.7rem; padding: 4px 10px; border-radius: 20px; color: white; font-weight: bold; white-space: nowrap; }
    .ann-badge { padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; white-space: nowrap; }
    .priority-normal { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .priority-high { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .priority-critical { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    
    /* استایل‌های مودال */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 90%; max-width: 650px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; max-height: 85vh; }
    .modal-header { padding: 20px 25px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-weight: 900; font-size: 1.1rem; color: #1f2937; margin: 0; display: flex; align-items: center; gap: 10px; line-height:1.5;}
    .close-modal { cursor: pointer; font-size: 1.5rem; color: #6b7280; transition: 0.2s; line-height: 1; }
    .close-modal:hover { color: #ef4444; }
    .modal-body { padding: 25px; overflow-y: auto; color: #334155; }
    .modal-meta { display: flex; gap: 15px; margin-bottom: 15px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 15px; font-size: 0.85rem; color: #64748b; }
    .modal-content-text { line-height: 1.8; font-size: 0.95rem; text-align: justify; margin-bottom: 20px; white-space: pre-wrap; }
    .modal-footer-box { padding: 15px 25px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; gap: 10px; flex-wrap: wrap; }
    
    .attachment-link { display: inline-flex; align-items: center; gap: 6px; background: #eff6ff; padding: 6px 12px; border-radius: 8px; color: #2563eb; text-decoration: none; font-size: 0.85rem; font-weight: bold; transition: 0.2s; border: 1px solid #bfdbfe;}
    .attachment-link:hover { background: #dbeafe; }
    .action-btn { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; font-size: 0.8rem; font-weight: bold; border-radius: 6px; cursor: pointer; border: 1px solid transparent; font-family: inherit;}
    .btn-view { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; } .btn-view:hover { background: #dbeafe; }
    .btn-edit { background: #fef3c7; color: #b45309; border-color: #fde68a; text-decoration:none;} .btn-edit:hover { background: #fde68a; }
    .btn-del { background: #fef2f2; color: #b91c1c; border-color: #fecaca; } .btn-del:hover { background: #fecaca; }
    
    @media(max-width: 768px) { .content-wrapper { padding: 15px; } .modal-meta { flex-direction: column; gap: 5px; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div>
                <span class="page-title">📌 تابلو اعلانات سازمان</span>
            </div>
            <div>
                <?php if($canCreate): ?>
                <a href="announcement_categories.php" class="btn btn-secondary">مدیریت دسته‌ها</a>
                <a href="create_announcement.php" class="btn btn-primary">+ اعلان جدید</a>
                <?php endif; ?>
            </div>
        </div>

        <form method="GET" class="search-box mb-4" style="max-width: 450px; display:flex; gap:10px;">
            <input type="text" name="q" class="form-control" placeholder="جستجو در اطلاعیه‌ها..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-primary" style="padding: 5px 20px;">جستجو</button>
        </form>

        <?php if(empty($list)): ?>
            <div class="text-center text-muted py-5" style="background: #f9fafb; border-radius: 12px; border: 1px dashed #d1d5db;">هیچ اطلاعیه‌ای برای نمایش وجود ندارد.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th style="width: 50px; text-align:center;">#</th>
                            <th>عنوان اعلان</th>
                            <th>دسته بندی</th>
                            <th>فرستنده</th>
                            <th>تاریخ ثبت</th>
                            <th>اولویت</th>
                            <th style="width: 250px; text-align:center;">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowNum = $offset + 1;
                        foreach ($list as $item): 
                            $pClass = 'priority-' . $item['priority'];
                            $pText = ['normal'=>'عادی', 'high'=>'مهم', 'critical'=>'فوری'][$item['priority']];
                            $sDate = jdate('Y/m/d - H:i', strtotime($item['created_at']));
                            $catColor = $item['cat_color'] ?? '#64748b';
                            $catTitle = $item['cat_title'] ?? 'عمومی';
                            
                            $isOwner = ((int)$item['created_by'] === $userId);
                            $canEditItem = ($isManagerRole && $isOwner) || $role === 'admin'; 
                            $canDeleteItem = true; 
                            
                            // آماده سازی عنوان و محتوا برای نمایش امن
                            $safeTitle = htmlspecialchars($item['title']);
                            $safeContent = htmlspecialchars($item['content']);
                            $displayTitle = $safeTitle;
                            
                            if ($item['type'] === 'system') {
                                $displayTitle = preg_replace('/#(\d+)/', '<a href="mission_view.php?id=$1" style="color:#2563eb; text-decoration:none;" dir="ltr">#$1</a>', $safeTitle);
                                $safeContent = preg_replace('/#(\d+)/', '<a href="mission_view.php?id=$1" style="color:#2563eb; text-decoration:none; font-weight:bold;" dir="ltr">#$1</a>', $safeContent);
                            }
                            
                            $senderName = $item['type']=='system' ? 'سیستم' : htmlspecialchars($item['first_name'].' '.$item['last_name']);
                            
                            // داده های مورد نیاز برای مودال
                            $jsData = [
                                'title' => $displayTitle,
                                'cat_title' => $catTitle,
                                'cat_color' => $catColor,
                                'priority_text' => $pText,
                                'priority_class' => $pClass,
                                'sender' => $senderName,
                                'date' => jdate('l d F Y - H:i', strtotime($item['created_at'])),
                                'content' => nl2br($safeContent),
                                'attachment' => $item['attachment'] ? rawurlencode($item['attachment']) : null,
                                'link' => sanitize_url($item['link'])
                            ];
                        ?>
                        <tr id="row-<?php echo $item['id']; ?>">
                            <td style="text-align:center; font-weight:bold; color:#64748b;"><?php echo $rowNum++; ?></td>
                            <td style="font-weight:bold; color:#1e293b;"><?php echo $displayTitle; ?></td>
                            <td><span class="cat-badge" style="background-color: <?php echo $catColor; ?>"><?php echo htmlspecialchars($catTitle); ?></span></td>
                            <td style="color:#475569;"><i class="fas fa-user-circle me-1" style="color:#94a3b8;"></i> <?php echo $senderName; ?></td>
                            <td style="color:#475569; font-size:0.85rem;" dir="ltr"><?php echo $sDate; ?></td>
                            <td><span class="ann-badge <?php echo $pClass; ?>"><?php echo $pText; ?></span></td>
                            <td style="text-align:center;">
                                <button onclick='viewAnnouncement(<?php echo htmlspecialchars(json_encode($jsData), ENT_QUOTES, 'UTF-8'); ?>)' class="action-btn btn-view"><i class="fas fa-eye"></i> مشاهده کامل</button>
                                <?php if($canEditItem): ?>
                                    <a href="create_announcement.php?id=<?php echo $item['id']; ?>" class="action-btn btn-edit"><i class="fas fa-edit"></i> ویرایش</a>
                                <?php endif; ?>
                                <?php if($canDeleteItem): ?>
                                    <button onclick="deleteAnn(<?php echo $item['id']; ?>)" class="action-btn btn-del"><i class="fas fa-trash"></i> <?php echo ($role === 'admin' || $isOwner) ? 'حذف' : 'مخفی'; ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="pagination page-actions" style="margin-top: 25px; display:flex; justify-content:center; gap:5px;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&q=<?php echo htmlspecialchars($search); ?>" class="btn btn-sm <?php echo ($i == $page) ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</main>

<div class="modal-overlay" id="viewAnnModal" onclick="closeAnnModal()">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">
                <span id="modalCat" class="cat-badge"></span>
                <span id="modalTitleText"></span>
            </div>
            <span class="close-modal" onclick="closeAnnModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="modal-meta">
                <span><i class="fas fa-user-circle me-1"></i> ارسال کننده: <strong id="modalSender"></strong></span>
                <span><i class="far fa-calendar-alt me-1"></i> زمان: <strong id="modalDate"></strong></span>
                <span><i class="fas fa-flag me-1"></i> اولویت: <span id="modalPriority" class="ann-badge"></span></span>
            </div>
            <div id="modalContentHtml" class="modal-content-text"></div>
        </div>
        <div class="modal-footer-box" id="modalLinksBox" style="display:none;"></div>
    </div>
</div>

<script>
    function viewAnnouncement(data) {
        // پر کردن اطلاعات مودال
        document.getElementById('modalCat').innerText = data.cat_title;
        document.getElementById('modalCat').style.backgroundColor = data.cat_color;
        document.getElementById('modalTitleText').innerHTML = data.title;
        
        document.getElementById('modalSender').innerText = data.sender;
        document.getElementById('modalDate').innerText = data.date;
        
        const priorityBadge = document.getElementById('modalPriority');
        priorityBadge.className = 'ann-badge ' + data.priority_class;
        priorityBadge.innerText = data.priority_text;
        
        document.getElementById('modalContentHtml').innerHTML = data.content;
        
        // تنظیم لینک‌ها (پیوست و لینک مرتبط)
        const linksBox = document.getElementById('modalLinksBox');
        let linksHtml = '';
        if(data.attachment) {
            linksHtml += `<a href="../uploads/announcements/${data.attachment}" target="_blank" class="attachment-link"><i class="fas fa-download"></i> دانلود فایل پیوست</a>`;
        }
        if(data.link) {
            linksHtml += `<a href="${data.link}" target="_blank" class="attachment-link"><i class="fas fa-external-link-alt"></i> باز کردن لینک مرتبط</a>`;
        }
        
        if (linksHtml !== '') {
            linksBox.innerHTML = linksHtml;
            linksBox.style.display = 'flex';
        } else {
            linksBox.style.display = 'none';
            linksBox.innerHTML = '';
        }
        
        // نمایش مودال
        document.getElementById('viewAnnModal').style.display = 'flex';
    }

    function closeAnnModal() {
        document.getElementById('viewAnnModal').style.display = 'none';
    }

    function deleteAnn(id) {
        if(!confirm('آیا از انجام این عملیات اطمینان دارید؟')) return;
        
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fd.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
        
        fetch('announcements.php', { 
            method: 'POST', 
            body: fd, 
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                const row = document.getElementById('row-' + id);
                if(row) {
                    row.style.display = 'none';
                } else {
                    location.reload();
                }
            } else {
                alert(res.message || 'خطا در عملیات');
            }
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>