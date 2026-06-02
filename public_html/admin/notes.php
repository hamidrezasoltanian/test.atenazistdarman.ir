<?php
/*
 * فایل: public_html/admin/notes.php
 */

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

$dbPath = __DIR__ . '/../../includes/db.php';
$funcPath = __DIR__ . '/../../includes/functions.php';

if (!file_exists($dbPath) || !file_exists($funcPath)) die("خطا: فایل‌های سیستمی یافت نشدند.");
require_once $dbPath;
require_once $funcPath;

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { 
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.']); exit; 
    }
    header("Location: ../login.php"); exit;
}

$userId = $_SESSION['user_id'];

// --- پردازش درخواست‌های AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add' || $action === 'edit') {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $color = $_POST['color'] ?? '#ffffff';
            $id = $_POST['id'] ?? null;

            if (empty($title) && empty($content)) {
                echo json_encode(['status'=>'error', 'message'=>'یادداشت نمی‌تواند خالی باشد.']); exit;
            }

            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content, color) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $title, $content, $color]);
                echo json_encode(['status'=>'success', 'message'=>'یادداشت ایجاد شد.']);
            } else {
                $stmt = $pdo->prepare("UPDATE notes SET title=?, content=?, color=? WHERE id=? AND user_id=?");
                $stmt->execute([$title, $content, $color, $id, $userId]);
                echo json_encode(['status'=>'success', 'message'=>'یادداشت ویرایش شد.']);
            }
        }
        elseif ($action === 'delete') {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM notes WHERE id=? AND user_id=?");
            $stmt->execute([$id, $userId]);
            echo json_encode(['status'=>'success']);
        }
        elseif ($action === 'archive') {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("UPDATE notes SET is_archived = NOT is_archived WHERE id=? AND user_id=?");
            $stmt->execute([$id, $userId]);
            echo json_encode(['status'=>'success']);
        }
        elseif ($action === 'copy') {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("SELECT * FROM notes WHERE id=? AND user_id=?");
            $stmt->execute([$id, $userId]);
            $note = $stmt->fetch();
            if ($note) {
                $stmtIns = $pdo->prepare("INSERT INTO notes (user_id, title, content, color, is_archived) VALUES (?, ?, ?, ?, ?)");
                $stmtIns->execute([$userId, $note['title'] . ' (کپی)', $note['content'], $note['color'], 0]); 
                echo json_encode(['status'=>'success']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['status'=>'error', 'message'=>'خطا: ' . $e->getMessage()]);
    }
    exit;
}

// --- دریافت اطلاعات برای نمایش ---
$filter = $_GET['filter'] ?? 'active'; // active | archived
$search = $_GET['q'] ?? '';

$sql = "SELECT * FROM notes WHERE user_id = ?";
$params = [$userId];

if ($filter === 'archived') {
    $sql .= " AND is_archived = 1";
} else {
    $sql .= " AND is_archived = 0";
}

if (!empty($search)) {
    $sql .= " AND (title LIKE ? OR content LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll();

$pageTitle = 'یادداشت‌های من';
$basePath = '../';
$extraCss = '<link rel="stylesheet" href="../assets/css/notes.css">';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        
        <!-- نوار ابزار -->
        <div class="notes-toolbar">
            <div class="notes-filter">
                <button id="btn-active" class="filter-btn <?php echo $filter!='archived'?'active':''; ?>" onclick="filterNotes('active')">یادداشت‌ها</button>
                <button id="btn-archived" class="filter-btn <?php echo $filter=='archived'?'active':''; ?>" onclick="filterNotes('archived')">آرشیو شده‌ها</button>
            </div>
            
            <div class="search-box" style="flex-grow: 1; max-width: 400px;">
                <input type="text" id="searchInput" class="search-input" placeholder="جستجو در یادداشت‌ها..." value="<?php echo htmlspecialchars($search); ?>">
                <button onclick="loadNotes()" class="btn btn-secondary" style="background:none; border:none;">🔍</button>
            </div>

            <button onclick="openNoteModal('add')" class="btn btn-primary">+ یادداشت جدید</button>
        </div>

        <!-- گرید یادداشت‌ها (قابلیت Sortable به این کلاس اضافه می‌شود) -->
        <div class="notes-grid" id="notesList">
            <?php if (empty($notes)): ?>
                <div style="grid-column: 1/-1; text-align: center; color: #9ca3af; padding: 50px;">
                    هیچ یادداشتی یافت نشد.
                </div>
            <?php else: ?>
                <?php foreach ($notes as $note): 
                    $bgColor = htmlspecialchars($note['color']);
                ?>
                <div class="note-card" style="background-color: <?php echo $bgColor; ?>;" data-id="<?php echo $note['id']; ?>">
                    <div class="note-header"><?php echo htmlspecialchars($note['title']); ?></div>
                    <div class="note-body"><?php echo nl2br(htmlspecialchars($note['content'])); ?></div>
                    <div class="note-date"><?php echo jdate('Y/m/d H:i', strtotime($note['created_at'])); ?></div>
                    
                    <div class="note-actions">
                        <button class="action-btn" title="ویرایش" onclick='openNoteModal("edit", <?php echo json_encode($note); ?>)'>✏️</button>
                        <button class="action-btn archive" title="<?php echo $note['is_archived'] ? 'خروج از آرشیو' : 'آرشیو'; ?>" onclick="noteAction('archive', <?php echo $note['id']; ?>)">
                            <?php echo $note['is_archived'] ? '📤' : '📥'; ?>
                        </button>
                        <button class="action-btn" title="کپی" onclick="noteAction('copy', <?php echo $note['id']; ?>)">📋</button>
                        <button class="action-btn delete" title="حذف" onclick="noteAction('delete', <?php echo $note['id']; ?>)">🗑️</button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<!-- مودال یادداشت -->
<div class="modal-overlay" id="noteModal">
    <div class="modal">
        <div class="modal-header"><h3 id="modalTitle">یادداشت جدید</h3><span class="close-modal" onclick="closeModal()">&times;</span></div>
        <div id="modalMessage"></div>
        
        <form id="noteForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="noteId">
            <input type="hidden" name="color" id="selectedColor" value="#ffffff">

            <div class="form-group">
                <input type="text" name="title" id="noteTitle" class="form-control" placeholder="عنوان یادداشت..." style="font-weight:bold;">
            </div>
            
            <div class="form-group" style="flex-grow: 1; display:flex; flex-direction:column;">
                <textarea name="content" id="noteContent" class="form-control" placeholder="متن یادداشت را اینجا بنویسید..." style="flex-grow:1; resize:none; min-height: 200px;"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">رنگ کارت:</label>
                <div class="color-picker">
                    <div class="color-option selected" style="background:#ffffff;" data-color="#ffffff" onclick="selectColor('#ffffff')"></div>
                    <div class="color-option" style="background:#fecaca;" data-color="#fecaca" onclick="selectColor('#fecaca')"></div>
                    <div class="color-option" style="background:#fed7aa;" data-color="#fed7aa" onclick="selectColor('#fed7aa')"></div>
                    <div class="color-option" style="background:#fef08a;" data-color="#fef08a" onclick="selectColor('#fef08a')"></div>
                    <div class="color-option" style="background:#bbf7d0;" data-color="#bbf7d0" onclick="selectColor('#bbf7d0')"></div>
                    <div class="color-option" style="background:#99f6e4;" data-color="#99f6e4" onclick="selectColor('#99f6e4')"></div>
                    <div class="color-option" style="background:#bfdbfe;" data-color="#bfdbfe" onclick="selectColor('#bfdbfe')"></div>
                    <div class="color-option" style="background:#e9d5ff;" data-color="#e9d5ff" onclick="selectColor('#e9d5ff')"></div>
                    <div class="color-option" style="background:#fbcfe8;" data-color="#fbcfe8" onclick="selectColor('#fbcfe8')"></div>
                </div>
            </div>

            <div style="text-align: left; margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeModal()" class="btn btn-outline">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره یادداشت</button>
            </div>
        </form>
    </div>
</div>

<!-- فراخوانی کتابخانه Sortable -->
<script src="../assets/js/Sortable.min.js"></script>
<script src="../assets/js/notes.js"></script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>