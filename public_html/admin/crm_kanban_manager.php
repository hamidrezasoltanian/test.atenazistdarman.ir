<?php
/*
 * فایل: public_html/admin/crm_kanban_manager.php
 * توضیحات: ماژول مدیریت بردهای کانبان (حذف تداخل توکن CSRF و هماهنگ‌سازی کامل با دیتابیس)
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

// بررسی وجود فایل‌های مورد نیاز
$dbPath = __DIR__ . '/../../includes/db.php';
$functionsPath = __DIR__ . '/../../includes/functions.php';

if (!file_exists($dbPath)) {
    die('فایل db.php یافت نشد. مسیر: ' . $dbPath);
}
if (!file_exists($functionsPath)) {
    die('فایل functions.php یافت نشد. مسیر: ' . $functionsPath);
}

require_once $dbPath;
require_once $functionsPath;

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { 
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است. لطفا مجدداً وارد شوید.']); 
        exit; 
    }
    header("Location: ../login.php"); 
    exit;
}

$userId = (int)$_SESSION['user_id'];

// ==================== ایجاد خودکار جداول ====================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_boards` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(255) NOT NULL,
        `description` text DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_board_stages` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `board_id` int(11) NOT NULL,
        `name` varchar(100) NOT NULL,
        `color_class` varchar(50) DEFAULT 'bg-gray',
        `sort_order` int(11) DEFAULT 0,
        PRIMARY KEY (`id`),
        CONSTRAINT `fk_crm_stage_board_mgr` FOREIGN KEY (`board_id`) REFERENCES `crm_boards` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} catch (Throwable $e) {}

// ==================== توابع مستقل مدیریت دیتابیس ====================
if (!function_exists('getCrmBoards')) {
    function getCrmBoards($pdo) {
        $stmt = $pdo->prepare("SELECT b.*, COUNT(s.id) as stages_count 
                               FROM crm_boards b 
                               LEFT JOIN crm_board_stages s ON b.id = s.board_id 
                               GROUP BY b.id 
                               ORDER BY b.created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getCrmBoardById')) {
    function getCrmBoardById($pdo, $boardId) {
        $stmt = $pdo->prepare("SELECT * FROM crm_boards WHERE id = ?");
        $stmt->execute([$boardId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getCrmStagesByBoard')) {
    function getCrmStagesByBoard($pdo, $boardId) {
        $stmt = $pdo->prepare("SELECT * FROM crm_board_stages WHERE board_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$boardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ==================== رنگ‌های پیش‌فرض ====================
$colorClasses = [
    'bg-red' => '#ef4444',
    'bg-orange' => '#f97316', 
    'bg-amber' => '#f59e0b',
    'bg-yellow' => '#eab308',
    'bg-lime' => '#84cc16',
    'bg-green' => '#22c55e',
    'bg-emerald' => '#10b981',
    'bg-teal' => '#14b8a6',
    'bg-cyan' => '#06b6d4',
    'bg-sky' => '#0ea5e9',
    'bg-blue' => '#3b82f6',
    'bg-indigo' => '#6366f1',
    'bg-violet' => '#8b5cf6',
    'bg-purple' => '#a855f7',
    'bg-fuchsia' => '#d946ef',
    'bg-pink' => '#ec4899',
    'bg-rose' => '#f43f5e',
    'bg-gray' => '#6b7280'
];

// ==================== پردازش درخواست‌های AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    // دریافت لیست بردها
    if ($action === 'get_boards') {
        try {
            $boards = getCrmBoards($pdo);
            echo json_encode(['status' => 'success', 'boards' => $boards]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در دریافت لیست بردها.']);
        }
        exit;
    }

    // دریافت اطلاعات یک برد
    if ($action === 'get_board') {
        $boardId = (int)($_POST['board_id'] ?? 0);
        try {
            $board = getCrmBoardById($pdo, $boardId);
            if ($board) {
                $stages = getCrmStagesByBoard($pdo, $boardId);
                echo json_encode(['status' => 'success', 'board' => $board, 'stages' => $stages]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'برد مورد نظر یافت نشد.']);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در دریافت اطلاعات برد.']);
        }
        exit;
    }

    // ذخیره برد
    if ($action === 'save_board') {
        $boardId = (int)($_POST['board_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $stagesJson = $_POST['stages'] ?? '[]';
        $stagesData = json_decode($stagesJson, true);

        if (empty($name) || !is_array($stagesData) || empty($stagesData)) {
            echo json_encode(['status' => 'error', 'message' => 'وارد کردن نام برد و تعیین مراحل الزامی است.']); 
            exit;
        }

        try {
            $pdo->beginTransaction();
            
            if ($boardId > 0) {
                $stmt = $pdo->prepare("UPDATE crm_boards SET name = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $description, $boardId]);
                
                $existingStageIds = [];
                $sort = 1;
                $updateStmt = $pdo->prepare("UPDATE crm_board_stages SET name = ?, color_class = ?, sort_order = ? WHERE id = ? AND board_id = ?");
                $insertStmt = $pdo->prepare("INSERT INTO crm_board_stages (board_id, name, color_class, sort_order) VALUES (?, ?, ?, ?)");
                
                foreach ($stagesData as $st) {
                    $sName = isset($st['name']) ? trim($st['name']) : '';
                    $sColorClass = $st['color_class'] ?? 'bg-gray';
                    if (!empty($sName)) {
                        if (isset($st['id']) && $st['id'] > 0) {
                            $updateStmt->execute([$sName, $sColorClass, $sort, $st['id'], $boardId]);
                            $existingStageIds[] = $st['id'];
                        } else {
                            $insertStmt->execute([$boardId, $sName, $sColorClass, $sort]);
                            $existingStageIds[] = $pdo->lastInsertId();
                        }
                        $sort++;
                    }
                }
                
                if (!empty($existingStageIds)) {
                    $inQuery = implode(',', array_fill(0, count($existingStageIds), '?'));
                    $deleteStmt = $pdo->prepare("DELETE FROM crm_board_stages WHERE board_id = ? AND id NOT IN ($inQuery)");
                    $params = array_merge([$boardId], $existingStageIds);
                    $deleteStmt->execute($params);
                } else {
                    $pdo->prepare("DELETE FROM crm_board_stages WHERE board_id = ?")->execute([$boardId]);
                }
                if (function_exists('logSystem')) logSystem('CRM', 'update_board', $boardId, "بروزرسانی برد کانبان: $name");
            } else {
                $stmt = $pdo->prepare("INSERT INTO crm_boards (name, description, created_by) VALUES (?, ?, ?)");
                $stmt->execute([$name, $description, $userId]);
                $boardId = $pdo->lastInsertId();
                
                $sort = 1;
                $insertStmt = $pdo->prepare("INSERT INTO crm_board_stages (board_id, name, color_class, sort_order) VALUES (?, ?, ?, ?)");
                foreach ($stagesData as $st) {
                    $sName = isset($st['name']) ? trim($st['name']) : '';
                    $sColorClass = $st['color_class'] ?? 'bg-gray';
                    if (!empty($sName)) {
                        $insertStmt->execute([$boardId, $sName, $sColorClass, $sort]);
                        $sort++;
                    }
                }
                if (function_exists('logSystem')) logSystem('CRM', 'create_board', $boardId, "ایجاد برد کانبان جدید: $name");
            }
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'برد کانبان با موفقیت ذخیره شد.', 'board_id' => $boardId]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => 'خطا در ذخیره‌سازی اطلاعات.']);
        }
        exit;
    }

    // حذف برد
    if ($action === 'delete_board') {
        $boardId = (int)($_POST['board_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM crm_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            if (function_exists('logSystem')) logSystem('CRM', 'delete_board', $boardId, "حذف برد کانبان به شناسه $boardId");
            echo json_encode(['status' => 'success', 'message' => 'برد با موفقیت حذف شد.']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در حذف برد کانبان.']);
        }
        exit;
    }

    // به‌روزرسانی نام مرحله
    if ($action === 'update_stage_name') {
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        
        try {
            $stmt = $pdo->prepare("UPDATE crm_board_stages SET name = ? WHERE id = ?");
            $stmt->execute([$name, $stageId]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در ثبت عنوان مرحله.']);
        }
        exit;
    }
    
    // به‌روزرسانی رنگ مرحله
    if ($action === 'update_stage_color') {
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $colorClass = trim($_POST['color_class'] ?? '');
        
        try {
            $stmt = $pdo->prepare("UPDATE crm_board_stages SET color_class = ? WHERE id = ?");
            $stmt->execute([$colorClass, $stageId]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در ثبت رنگ مرحله.']);
        }
        exit;
    }

    // حذف مرحله
    if ($action === 'delete_stage') {
        $stageId = (int)($_POST['stage_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM crm_board_stages WHERE id = ?");
            $stmt->execute([$stageId]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در حذف مرحله.']);
        }
        exit;
    }

    // افزودن مرحله جدید
    if ($action === 'add_stage') {
        $boardId = (int)($_POST['board_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $colorClass = trim($_POST['color_class'] ?? 'bg-gray');
        
        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'نام مرحله الزامی است.']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM crm_board_stages WHERE board_id = ?");
            $stmt->execute([$boardId]);
            $maxOrder = $stmt->fetch(PDO::FETCH_ASSOC)['max_order'] ?? 0;
            
            $insertStmt = $pdo->prepare("INSERT INTO crm_board_stages (board_id, name, color_class, sort_order) VALUES (?, ?, ?, ?)");
            $insertStmt->execute([$boardId, $name, $colorClass, $maxOrder + 1]);
            echo json_encode(['status' => 'success', 'stage_id' => $pdo->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در افزودن مرحله جدید.']);
        }
        exit;
    }
    
    // جابجایی مراحل
    if ($action === 'reorder_stages') {
        $boardId = (int)($_POST['board_id'] ?? 0);
        $stagesData = json_decode($_POST['stages'] ?? '[]', true);
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE crm_board_stages SET sort_order = ? WHERE id = ? AND board_id = ?");
            foreach ($stagesData as $index => $stage) {
                $stmt->execute([$index, (int)$stage['id'], $boardId]);
            }
            $pdo->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => 'خطا در مرتب‌سازی مراحل.']);
        }
        exit;
    }
}

// ==================== بارگذاری اطلاعات پایه صفحه ====================
try {
    $boards = getCrmBoards($pdo);
} catch (Exception $e) {
    $boards = [];
}

$currentBoardId = isset($_GET['board_id']) ? (int)$_GET['board_id'] : (isset($boards[0]['id']) ? (int)$boards[0]['id'] : 0);
$currentBoard = null;
$stages = [];

if ($currentBoardId > 0) {
    try {
        $currentBoard = getCrmBoardById($pdo, $currentBoardId);
        if ($currentBoard) {
            $stages = getCrmStagesByBoard($pdo, $currentBoardId);
        }
    } catch (Exception $e) {
        $currentBoard = null;
        $stages = [];
    }
}

$pageTitle = 'مدیریت بردهای کانبان';
$basePath = '../';
$extraCss = '
<style>
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    .main-content { background: #f1f5f9; min-height: calc(100vh - 70px); padding-top: 20px; }
    .content-wrapper { padding: 0 20px; }
    .page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px; background: white; padding: 16px 24px; border-radius: 12px; border: 1px solid #e2e8f0; }
    .page-title { font-size: 1.3rem; font-weight: 700; color: #1e293b; }
    .header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .board-selector-card { background: #f8fafc; padding: 4px 8px 4px 4px; border-radius: 8px; display: flex; align-items: center; gap: 8px; border: 1px solid #e2e8f0; }
    .board-select { padding: 8px 16px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem; background: white; cursor: pointer; min-width: 220px; }
    .btn { padding: 8px 18px; border: none; border-radius: 8px; font-weight: 500; font-size: 0.8rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    .btn-primary { background: #2563eb; color: white; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-outline { background: white; color: #475569; border: 1px solid #e2e8f0; }
    .btn-outline:hover { background: #f8fafc; }
    .btn-danger { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
    .btn-danger:hover { background: #fecaca; }
    .kanban-container { overflow-x: auto; padding-bottom: 20px; }
    .kanban-wrapper { display: flex; gap: 20px; min-width: min-content; padding: 4px; }
    .kanban-column { flex: 0 0 340px; background: white; border-radius: 12px; display: flex; flex-direction: column; max-height: calc(100vh - 240px); border: 1px solid #e2e8f0; cursor: grab; }
    .kanban-column:active { cursor: grabbing; }
    .kanban-column.dragging { opacity: 0.5; }
    .kanban-column.drag-over { border: 2px dashed #3b82f6; background: #eff6ff; }
    .kanban-column-header { padding: 14px 16px; background: white; border-radius: 12px 12px 0 0; border-bottom: 3px solid; display: flex; justify-content: space-between; align-items: center; }
    .col-title-wrapper { display: flex; align-items: center; gap: 10px; flex: 1; }
    .col-color { width: 28px; height: 28px; border-radius: 8px; border: 2px solid white; cursor: pointer; }
    .col-title { font-weight: 700; font-size: 0.9rem; background: transparent; border: none; padding: 4px 8px; width: auto; min-width: 100px; }
    .col-title:focus { outline: none; background: #f1f5f9; }
    .col-actions { display: flex; gap: 4px; opacity: 0; transition: opacity 0.2s; }
    .kanban-column:hover .col-actions { opacity: 1; }
    .col-action-btn { background: #f1f5f9; border: none; border-radius: 6px; padding: 4px 8px; cursor: pointer; font-size: 11px; }
    .col-action-btn.delete { color: #dc2626; }
    .kanban-column-body { flex: 1; overflow-y: auto; padding: 12px; background: #fafbfc; border-radius: 0 0 12px 12px; }
    .empty-stage { text-align: center; padding: 30px 20px; color: #94a3b8; font-size: 0.75rem; }
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
    .modal-content { background: white; width: 90%; max-width: 500px; border-radius: 12px; overflow: hidden; }
    .modal-header { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 20px; }
    .modal-footer { padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; }
    .form-group { margin-bottom: 18px; }
    .form-label { font-weight: 600; color: #334155; font-size: 0.8rem; margin-bottom: 6px; display: block; }
    .form-control { width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem; }
    .color-palette { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
    .color-option { width: 40px; height: 40px; border-radius: 10px; cursor: pointer; border: 2px solid transparent; }
    .color-option.selected { border-color: #3b82f6; }
    .empty-state { text-align: center; padding: 50px 20px; background: white; border-radius: 12px; }
    .add-stage-card { background: #f8fafc; border: 2px dashed #cbd5e1; justify-content: center; align-items: center; cursor: pointer; min-width: 200px; }
    .add-stage-card:hover { border-color: #3b82f6; background: #eff6ff; }
    @media (max-width: 768px) {
        .kanban-column { flex: 0 0 280px; }
        .page-header { flex-direction: column; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        
        <div class="page-header">
            <div>
                <span class="page-title">🎯 مدیریت بردهای کانبان</span>
                <p class="text-muted mt-1 mb-0" style="font-size: 0.75rem;">ایجاد و مدیریت ساختار کانبان</p>
            </div>
            
            <div class="header-actions">
                <div class="board-selector-card">
                    <label>📌 انتخاب برد:</label>
                    <select id="boardSelect" class="board-select" onchange="changeBoard(this.value)">
                        <option value="">-- انتخاب برد --</option>
                        <?php foreach($boards as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($b['id'] == $currentBoardId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['name']); ?> (<?php echo $b['stages_count']; ?> مرحله)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button onclick="openBoardModal(0)" class="btn btn-primary">✨ برد جدید</button>
                <?php if($currentBoardId > 0 && $currentBoard): ?>
                <button onclick="editCurrentBoard()" class="btn btn-outline">✏️ ویرایش برد</button>
                <button onclick="deleteCurrentBoard()" class="btn btn-danger">🗑️ حذف برد</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if(empty($boards)): ?>
            <div class="empty-state">
                <div class="empty-state-icon" style="font-size:48px;">🎯</div>
                <div class="empty-state-title">هیچ بردی وجود ندارد</div>
                <div class="empty-state-desc">برای شروع، یک برد کانبان جدید ایجاد کنید.</div>
                <button onclick="openBoardModal(0)" class="btn btn-primary">✨ ایجاد برد جدید</button>
            </div>
        <?php elseif(empty($stages)): ?>
            <div class="empty-state">
                <div class="empty-state-icon" style="font-size:48px;">📋</div>
                <div class="empty-state-title">این برد فاقد مرحله است</div>
                <div class="empty-state-desc">برای برد «<?php echo htmlspecialchars($currentBoard['name']); ?>» هیچ مرحله‌ای تعریف نشده است.</div>
                <button onclick="openAddStageModal()" class="btn btn-primary">➕ افزودن مرحله</button>
            </div>
        <?php else: ?>
            <div class="kanban-container">
                <div class="kanban-wrapper" id="kanbanWrapper">
                    <?php foreach($stages as $stage): 
                        $colorValue = isset($colorClasses[$stage['color_class']]) ? $colorClasses[$stage['color_class']] : '#6366f1';
                    ?>
                        <div class="kanban-column" data-stage-id="<?php echo $stage['id']; ?>" data-stage-order="<?php echo $stage['sort_order']; ?>" draggable="true">
                            <div class="kanban-column-header" style="border-bottom-color: <?php echo $colorValue; ?>;">
                                <div class="col-title-wrapper">
                                    <div class="col-color" style="background: <?php echo $colorValue; ?>;" 
                                         onclick="openColorPicker(<?php echo $stage['id']; ?>, '<?php echo $stage['color_class']; ?>')"></div>
                                    <input type="text" class="col-title" value="<?php echo htmlspecialchars($stage['name']); ?>" 
                                           onblur="updateStageName(<?php echo $stage['id']; ?>, this.value)" 
                                           onkeypress="if(event.key==='Enter') this.blur();">
                                </div>
                                <div class="col-actions">
                                    <button class="col-action-btn" onclick="openAddStageModal()" title="افزودن مرحله">➕</button>
                                    <button class="col-action-btn delete" onclick="deleteStage(<?php echo $stage['id']; ?>)" title="حذف مرحله">🗑️</button>
                                </div>
                            </div>
                            <div class="kanban-column-body">
                                <div class="empty-stage">
                                    <div class="empty-stage-icon" style="font-size:28px;">📌</div>
                                    <div>مرحله «<?php echo htmlspecialchars($stage['name']); ?>»</div>
                                    <div style="font-size: 10px; margin-top: 5px;">برای جابجایی، سربرگ مرحله را بگیرید و بکشید</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="kanban-column add-stage-card" onclick="openAddStageModal()">
                        <div style="text-align: center; padding: 40px 20px;">
                            <div style="font-size: 36px; color: #94a3b8;">➕</div>
                            <div style="font-size: 13px; font-weight: 500; color: #64748b; margin-top: 10px;">افزودن مرحله جدید</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<div id="boardModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h4 id="boardModalTitle">✨ برد جدید</h4>
            <span onclick="closeModal('boardModal')" style="cursor:pointer;">&times;</span>
        </div>
        <form id="boardForm" onsubmit="submitBoardForm(event)">
            <div class="modal-body">
                <input type="hidden" name="action" value="save_board">
                <input type="hidden" name="board_id" id="modalBoardId" value="0">
                <div class="form-group">
                    <label class="form-label">نام برد <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="boardName" class="form-control" required placeholder="مثال: فرآیند فروش">
                </div>
                <div class="form-group">
                    <label class="form-label">توضیحات</label>
                    <textarea name="description" id="boardDesc" class="form-control" rows="2" placeholder="توضیحات اختیاری..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">مراحل (قابل جابجایی)</label>
                    <div id="stagesEditor" style="max-height: 300px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px;"></div>
                    <button type="button" class="btn-outline" style="margin-top: 12px; width: 100%; padding: 8px; border-radius:8px; cursor:pointer;" onclick="addStageRow()">➕ افزودن مرحله جدید</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('boardModal')">انصراف</button>
                <button type="submit" id="btnSubmitBoard" class="btn btn-primary">💾 ذخیره برد</button>
            </div>
        </form>
    </div>
</div>

<div id="addStageModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h4>➕ افزودن مرحله جدید</h4>
            <span onclick="closeModal('addStageModal')" style="cursor:pointer;">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">نام مرحله <span class="text-danger">*</span></label>
                <input type="text" id="newStageName" class="form-control" placeholder="مثال: در انتظار تایید">
            </div>
            <div class="form-group">
                <label class="form-label">رنگ مرحله</label>
                <div class="color-palette" id="newStageColorPalette">
                    <?php foreach($colorClasses as $class => $color): ?>
                        <div class="color-option" style="background: <?php echo $color; ?>;" data-color-class="<?php echo $class; ?>" onclick="selectNewStageColor('<?php echo $class; ?>', this)"></div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" id="newStageColorClass" value="bg-gray">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('addStageModal')">انصراف</button>
            <button type="button" class="btn btn-primary" onclick="submitAddStage()">➕ افزودن مرحله</button>
        </div>
    </div>
</div>

<div id="colorPickerModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h4>انتخاب رنگ مرحله</h4>
            <span onclick="closeModal('colorPickerModal')" style="cursor:pointer;">&times;</span>
        </div>
        <div class="modal-body">
            <div class="color-palette" id="colorPaletteContainer">
                <?php foreach($colorClasses as $class => $color): ?>
                    <div class="color-option" style="background: <?php echo $color; ?>;" data-color-class="<?php echo $class; ?>" onclick="selectStageColor('<?php echo $class; ?>', '<?php echo $color; ?>')"></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('colorPickerModal')">انصراف</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // مقداردهی اولیه متغیرهای رنگ از PHP به جاوااسکریپت (دلیل رفع مشکل دکمه‌ها همینجاست)
    const colorClasses = <?php echo json_encode($colorClasses); ?>;
    
    let currentBoardId = <?php echo $currentBoardId; ?>;
    let currentBoardName = '<?php echo addslashes($currentBoard['name'] ?? ''); ?>';
    let pendingStageId = null;
    let currentStages = [];
    
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'flex';
    }
    
    function showToast(message, isError) {
        var toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = 'position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:' + (isError ? '#ef4444' : '#22c55e') + '; color:white; padding:8px 16px; border-radius:8px; font-size:12px; z-index:10000; font-weight:bold;';
        document.body.appendChild(toast);
        setTimeout(function() { toast.remove(); }, 2500);
    }
    
    function changeBoard(boardId) {
        if (boardId) window.location.href = '?board_id=' + boardId;
    }
    
    function editCurrentBoard() {
        if (currentBoardId > 0) openBoardModal(currentBoardId);
        else showToast('لطفاً ابتدا یک برد را انتخاب کنید', true);
    }
    
    function deleteCurrentBoard() {
        if (currentBoardId > 0 && confirm('آیا از حذف کامل برد "' + currentBoardName + '" مطمئن هستید؟')) {
            $.post('crm_kanban_manager.php', { action: 'delete_board', board_id: currentBoardId }, function(res) {
                if (res.status === 'success') window.location.href = 'crm_kanban_manager.php';
                else showToast('خطا در حذف برد', true);
            });
        }
    }
    
    function updateStageName(stageId, newName) {
        if (!newName.trim()) { showToast('نام مرحله نمی‌تواند خالی باشد', true); return; }
        $.post('crm_kanban_manager.php', { action: 'update_stage_name', stage_id: stageId, name: newName.trim() }, function(res) {
            if (res.status !== 'success') showToast('خطا در ذخیره نام', true);
        });
    }
    
    function openColorPicker(stageId, currentColorClass) {
        pendingStageId = stageId;
        document.querySelectorAll('#colorPaletteContainer .color-option').forEach(opt => {
            if (opt.dataset.colorClass === currentColorClass) opt.classList.add('selected');
            else opt.classList.remove('selected');
        });
        openModal('colorPickerModal');
    }
    
    function selectStageColor(colorClass, colorValue) {
        if (pendingStageId) {
            $.post('crm_kanban_manager.php', { action: 'update_stage_color', stage_id: pendingStageId, color_class: colorClass }, function(res) {
                if (res.status === 'success') {
                    var column = document.querySelector('.kanban-column[data-stage-id="' + pendingStageId + '"]');
                    if (column) {
                        column.querySelector('.kanban-column-header').style.borderBottomColor = colorValue;
                        column.querySelector('.col-color').style.background = colorValue;
                    }
                    showToast('رنگ مرحله تغییر کرد', false);
                } else showToast('خطا در تغییر رنگ', true);
            });
        }
        closeModal('colorPickerModal');
    }
    
    function deleteStage(stageId) {
        if (confirm('آیا از حذف این مرحله مطمئن هستید؟')) {
            $.post('crm_kanban_manager.php', { action: 'delete_stage', stage_id: stageId }, function(res) {
                if (res.status === 'success') location.reload();
                else showToast('خطا در حذف مرحله', true);
            });
        }
    }
    
    function openAddStageModal() {
        document.getElementById('newStageName').value = '';
        document.getElementById('newStageColorClass').value = 'bg-gray';
        document.querySelectorAll('#newStageColorPalette .color-option').forEach(opt => opt.classList.remove('selected'));
        var defaultOpt = document.querySelector('#newStageColorPalette .color-option[data-color-class="bg-gray"]');
        if (defaultOpt) defaultOpt.classList.add('selected');
        openModal('addStageModal');
    }
    
    function selectNewStageColor(colorClass, element) {
        document.querySelectorAll('#newStageColorPalette .color-option').forEach(opt => opt.classList.remove('selected'));
        element.classList.add('selected');
        document.getElementById('newStageColorClass').value = colorClass;
    }
    
    function submitAddStage() {
        var stageName = document.getElementById('newStageName').value.trim();
        var colorClass = document.getElementById('newStageColorClass').value;
        if (!stageName) { showToast('لطفاً نام مرحله را وارد کنید', true); return; }
        $.post('crm_kanban_manager.php', { action: 'add_stage', board_id: currentBoardId, name: stageName, color_class: colorClass }, function(res) {
            if (res.status === 'success') { showToast('مرحله جدید اضافه شد', false); location.reload(); }
            else showToast('خطا در افزودن مرحله', true);
        });
    }
    
    // Drag & Drop برای جابجایی مراحل
    function initDragDrop() {
        var dragSrc = null;
        document.querySelectorAll('.kanban-column:not(.add-stage-card)').forEach(function(col) {
            col.addEventListener('dragstart', function(e) {
                dragSrc = this;
                e.dataTransfer.setData('text/plain', this.dataset.stageId);
                this.style.opacity = '0.5';
            });
            col.addEventListener('dragend', function(e) { this.style.opacity = ''; });
            col.addEventListener('dragover', function(e) { e.preventDefault(); });
            col.addEventListener('drop', function(e) {
                e.preventDefault();
                if (!dragSrc || dragSrc === this) return;
                var wrapper = document.getElementById('kanbanWrapper');
                var allCols = Array.from(wrapper.children).filter(c => !c.classList.contains('add-stage-card'));
                var fromIdx = allCols.indexOf(dragSrc);
                var toIdx = allCols.indexOf(this);
                if (fromIdx < toIdx) this.parentNode.insertBefore(dragSrc, this.nextSibling);
                else this.parentNode.insertBefore(dragSrc, this);
                var newStages = [];
                document.querySelectorAll('.kanban-column:not(.add-stage-card)').forEach(function(col, idx) {
                    newStages.push({ id: parseInt(col.dataset.stageId), order: idx });
                });
                $.post('crm_kanban_manager.php', { action: 'reorder_stages', board_id: currentBoardId, stages: JSON.stringify(newStages) }, function(res) {
                    if (res.status !== 'success') showToast('خطا در ذخیره ترتیب', true);
                });
                dragSrc = null;
            });
        });
    }
    
    // مودال برد
    function resetBoardForm() {
        document.getElementById('boardName').value = '';
        document.getElementById('boardDesc').value = '';
        document.getElementById('modalBoardId').value = '0';
        document.getElementById('boardModalTitle').innerHTML = '✨ برد جدید';
        currentStages = [];
        renderStagesEditor();
    }
    
    function renderStagesEditor() {
        var container = document.getElementById('stagesEditor');
        if (!container) return;
        if (currentStages.length === 0) { container.innerHTML = '<div style="text-align:center; padding:20px; font-weight:bold; color:#64748b;">هیچ مرحله‌ای تعریف نشده است</div>'; return; }
        var html = '';
        for (var i = 0; i < currentStages.length; i++) {
            var stage = currentStages[i];
            var colorValue = colorClasses[stage.color_class] || '#6366f1';
            html += '<div style="display:flex; align-items:center; gap:10px; background:#f8fafc; padding:8px; border-radius:8px; margin-bottom:6px;">' +
                '<span style="cursor:move; color:#94a3b8;">⋮⋮</span>' +
                '<input type="hidden" class="stage-id" value="' + (stage.id || 0) + '">' +
                '<input type="text" value="' + escapeHtml(stage.name) + '" placeholder="نام مرحله" style="flex:1; padding:6px; border:1px solid #e2e8f0; border-radius:6px;" onchange="currentStages[' + i + '].name=this.value">' +
                '<div style="width:32px; height:32px; border-radius:8px; background:' + colorValue + '; cursor:pointer;" onclick="openColorPickerEditor(' + i + ')"></div>' +
                '<input type="hidden" class="stage-color-class" value="' + (stage.color_class || 'bg-gray') + '">' +
                '<button type="button" style="background:#fee2e2; color:#dc2626; border:none; border-radius:6px; padding:4px 8px; cursor:pointer;" onclick="removeStageFromArray(' + i + ')">🗑️</button>' +
                '</div>';
        }
        container.innerHTML = html;
    }
    
    function openColorPickerEditor(index) {
        var currentColorClass = currentStages[index].color_class || 'bg-gray';
        var tempDiv = document.createElement('div');
        tempDiv.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10001; display:flex; align-items:center; justify-content:center;';
        var paletteHtml = '<div style="background:white; border-radius:12px; padding:20px; max-width:380px;"><h4>انتخاب رنگ</h4><div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">';
        for (var cls in colorClasses) {
            paletteHtml += '<div style="width:36px; height:36px; border-radius:10px; background:' + colorClasses[cls] + '; cursor:pointer; border:2px solid ' + (currentColorClass === cls ? '#3b82f6' : 'transparent') + ';" onclick="selectColorEditor(' + index + ', \'' + cls + '\'); document.body.removeChild(this.parentElement.parentElement.parentElement);"></div>';
        }
        paletteHtml += '</div><button style="margin-top:15px; padding:8px 20px; border:none; border-radius:8px; background:#e2e8f0; cursor:pointer; font-weight:bold;" onclick="document.body.removeChild(this.parentElement.parentElement)">انصراف</button></div>';
        tempDiv.innerHTML = paletteHtml;
        document.body.appendChild(tempDiv);
    }
    
    function selectColorEditor(index, colorClass) {
        currentStages[index].color_class = colorClass;
        renderStagesEditor();
    }
    
    function removeStageFromArray(index) {
        currentStages.splice(index, 1);
        renderStagesEditor();
    }
    
    function addStageRow() {
        currentStages.push({ id: 0, name: 'مرحله جدید', color_class: 'bg-gray' });
        renderStagesEditor();
    }
    
    function openBoardModal(boardId) {
        if (boardId > 0) {
            var btn = document.getElementById('btnSubmitBoard');
            btn.disabled = true;
            btn.innerHTML = '⏳ بارگذاری...';
            openModal('boardModal');
            document.getElementById('stagesEditor').innerHTML = '<div style="text-align:center; padding:20px; font-weight:bold; color:#64748b;">⏳ در حال بارگذاری مراحل...</div>';
            $.post('crm_kanban_manager.php', { action: 'get_board', board_id: boardId }, function(res) {
                if (res.status === 'success') {
                    document.getElementById('boardName').value = res.board.name;
                    document.getElementById('boardDesc').value = res.board.description || '';
                    document.getElementById('modalBoardId').value = res.board.id;
                    document.getElementById('boardModalTitle').innerHTML = '✏️ ویرایش برد';
                    currentStages = [];
                    if (res.stages && res.stages.length) {
                        res.stages.forEach(function(s) { currentStages.push({ id: s.id, name: s.name, color_class: s.color_class }); });
                    }
                    renderStagesEditor();
                    btn.disabled = false;
                    btn.innerHTML = '💾 ذخیره تغییرات';
                } else { alert('خطا در بارگذاری اطلاعات'); closeModal('boardModal'); }
            });
        } else {
            resetBoardForm();
            currentStages = [
                { id: 0, name: '📋 سرنخ', color_class: 'bg-amber' },
                { id: 0, name: '📞 تماس اولیه', color_class: 'bg-sky' },
                { id: 0, name: '🤝 ملاقات', color_class: 'bg-violet' },
                { id: 0, name: '📄 پیشنهاد', color_class: 'bg-cyan' },
                { id: 0, name: '💬 مذاکره', color_class: 'bg-orange' },
                { id: 0, name: '✅ قرارداد', color_class: 'bg-green' }
            ];
            renderStagesEditor();
            openModal('boardModal');
        }
    }
    
    function submitBoardForm(e) {
        e.preventDefault();
        var boardId = document.getElementById('modalBoardId').value;
        var name = document.getElementById('boardName').value.trim();
        var description = document.getElementById('boardDesc').value;
        if (!name) { showToast('لطفاً نام برد را وارد کنید', true); return; }
        if (currentStages.length === 0) { showToast('حداقل یک مرحله باید تعریف شود', true); return; }
        var stages = [];
        for (var i = 0; i < currentStages.length; i++) {
            if (!currentStages[i].name.trim()) { showToast('مرحله ' + (i+1) + ' بدون نام است', true); return; }
            stages.push({ id: currentStages[i].id || 0, name: currentStages[i].name.trim(), color_class: currentStages[i].color_class || 'bg-gray' });
        }
        var formData = new FormData(document.getElementById('boardForm'));
        formData.append('stages', JSON.stringify(stages));
        var btn = document.getElementById('btnSubmitBoard');
        btn.disabled = true; btn.innerHTML = '⏳ در حال ذخیره...';
        fetch('crm_kanban_manager.php', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                showToast(boardId != 0 ? 'برد ویرایش شد' : 'برد ایجاد شد', false);
                setTimeout(function() { window.location.href = '?board_id=' + data.board_id; }, 800);
            } else { showToast(data.message || 'خطا در ذخیره سازی', true); btn.disabled = false; btn.innerHTML = boardId != 0 ? '💾 ذخیره تغییرات' : '💾 ذخیره برد'; }
        })
        .catch(function() { showToast('خطا در ارتباط با سرور', true); btn.disabled = false; btn.innerHTML = boardId != 0 ? '💾 ذخیره تغییرات' : '💾 ذخیره برد'; });
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m]; });
    }
    
    document.addEventListener('DOMContentLoaded', function() { initDragDrop(); });
    document.querySelectorAll('.modal-overlay').forEach(function(modal) {
        modal.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>