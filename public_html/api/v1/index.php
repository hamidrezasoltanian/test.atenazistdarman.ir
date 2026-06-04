<?php
/**
 * REST API v1 — آتنا زیست درمان
 * Bearer token authentication, JSON responses
 * Base URL: /api/v1/{resource}/{id?}
 */

// ── بارگذاری پیکربندی ──────────────────────────────────────
$root = dirname(__DIR__, 3);
require_once $root . '/Config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';

// ── هدرها ──────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── توابع کمکی ─────────────────────────────────────────────
function apiResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => $code < 400, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function intParam(string $key, int $default = 1): int {
    $v = $_GET[$key] ?? $default;
    return max(1, (int)$v);
}

// ── احراز هویت با Bearer token ─────────────────────────────
function authenticate(PDO $pdo): array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!$auth && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $auth = $hdrs['Authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        apiError('توکن احراز هویت ارائه نشده است', 401);
    }
    $token = trim($m[1]);
    $stmt = $pdo->prepare(
        'SELECT t.user_id, t.expires_at, u.id, u.name, u.email, u.role_id
         FROM api_tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.is_active = 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) apiError('توکن نامعتبر یا منقضی است', 401);
    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
        apiError('توکن منقضی شده است', 401);
    }
    // آپدیت last_used
    $pdo->prepare('UPDATE api_tokens SET last_used = NOW() WHERE token = ?')->execute([$token]);
    return $row;
}

// ── اتصال دیتابیس ──────────────────────────────────────────
try {
    $config = require $root . '/Config/config.php';
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    apiError('خطا در اتصال به دیتابیس', 503);
}

// ── تجزیه مسیر ─────────────────────────────────────────────
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// /api/v1/{resource}/{id?}/{action?}
$pattern = '#^/api/v1/([a-z_]+)(?:/(\d+))?(?:/([a-z_]+))?#';
if (!preg_match($pattern, $requestUri, $seg)) {
    // ریشه API — اطلاعات کلی
    apiResponse([
        'name'      => 'آتنا زیست درمان REST API',
        'version'   => 'v1',
        'endpoints' => [
            'POST /api/v1/auth/login'        => 'دریافت توکن',
            'GET  /api/v1/crm/opportunities' => 'فرصت‌های فروش',
            'GET  /api/v1/crm/contacts'      => 'مخاطبین',
            'GET  /api/v1/fin/invoices'      => 'فاکتورها',
            'GET  /api/v1/fin/persons'       => 'طرف حساب‌ها',
            'GET  /api/v1/fin/cheques'       => 'چک‌ها',
            'GET  /api/v1/inv/tickets'       => 'حواله/رسید انبار',
            'GET  /api/v1/inv/storerooms'    => 'انبارها',
            'GET  /api/v1/hr/leaves'         => 'درخواست مرخصی',
            'GET  /api/v1/hr/missions'       => 'ماموریت‌ها',
            'GET  /api/v1/dashboard/summary' => 'خلاصه داشبورد',
        ],
    ]);
}

$resource = $seg[1] ?? '';
$id       = isset($seg[2]) ? (int)$seg[2] : null;
$action   = $seg[3] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

// ── endpoint: احراز هویت ──────────────────────────────────
if ($resource === 'auth') {
    if ($action === 'login' && $method === 'POST') {
        $body  = getBody();
        $email = trim($body['email'] ?? '');
        $pass  = trim($body['password'] ?? '');
        if (!$email || !$pass) apiError('ایمیل و رمز عبور الزامی است');

        $stmt = $pdo->prepare('SELECT id, name, email, password, role_id FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pass, $user['password'])) {
            apiError('ایمیل یا رمز عبور اشتباه است', 401);
        }
        $token = bin2hex(random_bytes(32));
        $name  = ($body['device_name'] ?? 'API Client');
        $exp   = date('Y-m-d H:i:s', strtotime('+30 days'));

        // create api_tokens table if missing
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            name VARCHAR(100) NULL,
            last_used DATETIME NULL,
            expires_at DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id), KEY idx_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->prepare('INSERT INTO api_tokens (user_id, token, name, expires_at) VALUES (?,?,?,?)')
            ->execute([$user['id'], $token, $name, $exp]);

        apiResponse([
            'token'      => $token,
            'expires_at' => $exp,
            'user'       => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
        ], 201);
    }

    if ($action === 'logout' && $method === 'POST') {
        $user = authenticate($pdo);
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        preg_match('/Bearer\s+(.+)$/i', $auth, $m);
        $pdo->prepare('UPDATE api_tokens SET is_active = 0 WHERE token = ?')->execute([trim($m[1] ?? '')]);
        apiResponse(['message' => 'با موفقیت خروج شد']);
    }

    if ($action === 'me' && $method === 'GET') {
        $user = authenticate($pdo);
        apiResponse(['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']]);
    }

    apiError('endpoint یافت نشد', 404);
}

// ── احراز هویت برای سایر endpointها ───────────────────────
$authUser = authenticate($pdo);
$userId   = (int)$authUser['user_id'];
$perPage  = min(100, intParam('per_page', 20));
$page     = intParam('page', 1);
$offset   = ($page - 1) * $perPage;

// ══════════════════════════════════════════════════════════
// ── CRM ────────────────────────────────────────────────────
// ══════════════════════════════════════════════════════════
if ($resource === 'crm') {

    // فرصت‌های فروش
    if ($action === 'opportunities' || ($id === null && $resource === 'crm')) {
        // GET /crm/opportunities
        if ($method === 'GET' && $action === 'opportunities') {
            $where  = ['o.is_deleted = 0'];
            $params = [];
            if (!empty($_GET['stage_id'])) { $where[] = 'o.stage_id = ?'; $params[] = (int)$_GET['stage_id']; }
            if (!empty($_GET['assigned_to'])) { $where[] = 'o.assigned_to = ?'; $params[] = (int)$_GET['assigned_to']; }
            if (!empty($_GET['search'])) { $where[] = 'o.title LIKE ?'; $params[] = '%' . $_GET['search'] . '%'; }

            $wSql = implode(' AND ', $where);
            $total = $pdo->prepare("SELECT COUNT(*) FROM crm_opportunities o WHERE $wSql");
            $total->execute($params);
            $count = (int)$total->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT o.id, o.title, o.value, o.stage_id, s.name AS stage_name,
                        o.assigned_to, u.name AS assigned_name,
                        o.created_at, o.updated_at
                 FROM crm_opportunities o
                 LEFT JOIN crm_board_stages s ON s.id = o.stage_id
                 LEFT JOIN users u ON u.id = o.assigned_to
                 WHERE $wSql ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            apiResponse([
                'items'    => $stmt->fetchAll(),
                'total'    => $count,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int)ceil($count / $perPage),
            ]);
        }

        // GET /crm/opportunities/{id}
        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare(
                'SELECT o.*, s.name AS stage_name, u.name AS assigned_name
                 FROM crm_opportunities o
                 LEFT JOIN crm_board_stages s ON s.id = o.stage_id
                 LEFT JOIN users u ON u.id = o.assigned_to
                 WHERE o.id = ? AND o.is_deleted = 0'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) apiError('فرصت یافت نشد', 404);
            apiResponse($row);
        }
    }

    // مخاطبین
    if ($action === 'contacts') {
        if ($method === 'GET') {
            $where  = ['1=1'];
            $params = [];
            if (!empty($_GET['search'])) {
                $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
                $s = '%' . $_GET['search'] . '%';
                $params = array_merge($params, [$s, $s, $s]);
            }
            $wSql  = implode(' AND ', $where);
            $total = $pdo->prepare("SELECT COUNT(*) FROM crm_contacts c WHERE $wSql");
            $total->execute($params);
            $count = (int)$total->fetchColumn();

            $stmt  = $pdo->prepare("SELECT c.* FROM crm_contacts c WHERE $wSql ORDER BY c.id DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute($params);
            apiResponse(['items' => $stmt->fetchAll(), 'total' => $count, 'page' => $page]);
        }
        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare('SELECT * FROM crm_contacts WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) apiError('مخاطب یافت نشد', 404);
            apiResponse($row);
        }
    }

    // مراحل بورد
    if ($action === 'stages' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT s.*, b.name AS board_name FROM crm_board_stages s LEFT JOIN crm_boards b ON b.id = s.board_id ORDER BY s.sort_order');
        $stmt->execute();
        apiResponse($stmt->fetchAll());
    }

    apiError('endpoint یافت نشد', 404);
}

// ══════════════════════════════════════════════════════════
// ── مالی ──────────────────────────────────────────────────
// ══════════════════════════════════════════════════════════
if ($resource === 'fin') {

    // فاکتورها
    if ($action === 'invoices') {
        if ($method === 'GET' && !$id) {
            $where  = ['i.is_deleted = 0'];
            $params = [];
            if (!empty($_GET['type']))   { $where[] = 'i.type = ?'; $params[] = $_GET['type']; }
            if (!empty($_GET['status'])) { $where[] = 'i.status = ?'; $params[] = $_GET['status']; }
            if (!empty($_GET['person_id'])) { $where[] = 'i.person_id = ?'; $params[] = (int)$_GET['person_id']; }
            if (!empty($_GET['from_date'])) { $where[] = 'i.invoice_date >= ?'; $params[] = $_GET['from_date']; }
            if (!empty($_GET['to_date']))   { $where[] = 'i.invoice_date <= ?'; $params[] = $_GET['to_date']; }

            $wSql  = implode(' AND ', $where);
            $count = (int)$pdo->prepare("SELECT COUNT(*) FROM fin_invoices i WHERE $wSql")->execute($params) ? 0 : 0;
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM fin_invoices i WHERE $wSql");
            $cStmt->execute($params);
            $count = (int)$cStmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT i.id, i.invoice_number, i.type, i.invoice_date, i.status,
                        i.total_amount, i.paid_amount, i.person_id,
                        p.name AS person_name
                 FROM fin_invoices i
                 LEFT JOIN fin_persons p ON p.id = i.person_id
                 WHERE $wSql ORDER BY i.id DESC LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            apiResponse(['items' => $stmt->fetchAll(), 'total' => $count, 'page' => $page, 'per_page' => $perPage]);
        }

        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare(
                'SELECT i.*, p.name AS person_name FROM fin_invoices i
                 LEFT JOIN fin_persons p ON p.id = i.person_id
                 WHERE i.id = ? AND i.is_deleted = 0'
            );
            $stmt->execute([$id]);
            $inv = $stmt->fetch();
            if (!$inv) apiError('فاکتور یافت نشد', 404);
            $items = $pdo->prepare('SELECT * FROM fin_invoice_items WHERE invoice_id = ? ORDER BY sort_order');
            $items->execute([$id]);
            $inv['items'] = $items->fetchAll();
            apiResponse($inv);
        }
    }

    // طرف حساب‌ها
    if ($action === 'persons') {
        if ($method === 'GET' && !$id) {
            $where  = ['is_deleted = 0'];
            $params = [];
            if (!empty($_GET['type']))   { $where[] = 'type = ?'; $params[] = $_GET['type']; }
            if (!empty($_GET['search'])) { $where[] = 'name LIKE ?'; $params[] = '%' . $_GET['search'] . '%'; }
            $wSql  = implode(' AND ', $where);
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM fin_persons WHERE $wSql");
            $cStmt->execute($params);
            $count = (int)$cStmt->fetchColumn();
            $stmt  = $pdo->prepare("SELECT * FROM fin_persons WHERE $wSql ORDER BY name LIMIT $perPage OFFSET $offset");
            $stmt->execute($params);
            apiResponse(['items' => $stmt->fetchAll(), 'total' => $count, 'page' => $page]);
        }
        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare('SELECT * FROM fin_persons WHERE id = ? AND is_deleted = 0');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) apiError('طرف حساب یافت نشد', 404);
            apiResponse($row);
        }
    }

    // چک‌ها
    if ($action === 'cheques') {
        if ($method === 'GET' && !$id) {
            $where  = ['is_deleted = 0'];
            $params = [];
            if (!empty($_GET['type']))   { $where[] = 'type = ?'; $params[] = $_GET['type']; }
            if (!empty($_GET['status'])) { $where[] = 'status = ?'; $params[] = $_GET['status']; }
            $wSql  = implode(' AND ', $where);
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM fin_cheques WHERE $wSql");
            $cStmt->execute($params);
            $count = (int)$cStmt->fetchColumn();
            $stmt  = $pdo->prepare("SELECT * FROM fin_cheques WHERE $wSql ORDER BY due_date DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute($params);
            apiResponse(['items' => $stmt->fetchAll(), 'total' => $count, 'page' => $page]);
        }
        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare('SELECT * FROM fin_cheques WHERE id = ? AND is_deleted = 0');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) apiError('چک یافت نشد', 404);
            apiResponse($row);
        }
    }

    // حساب‌های بانکی
    if ($action === 'bank_accounts' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM fin_bank_accounts WHERE is_active = 1 ORDER BY bank_name');
        $stmt->execute();
        apiResponse($stmt->fetchAll());
    }

    apiError('endpoint یافت نشد', 404);
}

// ══════════════════════════════════════════════════════════
// ── انبار ─────────────────────────────────────────────────
// ══════════════════════════════════════════════════════════
if ($resource === 'inv') {

    // حواله/رسید
    if ($action === 'tickets') {
        if ($method === 'GET' && !$id) {
            $where  = ['t.is_deleted = 0'];
            $params = [];
            if (!empty($_GET['type']))         { $where[] = 't.type = ?'; $params[] = $_GET['type']; }
            if (!empty($_GET['storeroom_id'])) { $where[] = 't.storeroom_id = ?'; $params[] = (int)$_GET['storeroom_id']; }
            if (!empty($_GET['from_date']))    { $where[] = 't.ticket_date >= ?'; $params[] = $_GET['from_date']; }
            if (!empty($_GET['to_date']))      { $where[] = 't.ticket_date <= ?'; $params[] = $_GET['to_date']; }
            $wSql  = implode(' AND ', $where);
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM inv_tickets t WHERE $wSql");
            $cStmt->execute($params);
            $count = (int)$cStmt->fetchColumn();
            $stmt  = $pdo->prepare(
                "SELECT t.*, s.name AS storeroom_name, u.name AS created_by_name
                 FROM inv_tickets t
                 LEFT JOIN inv_storerooms s ON s.id = t.storeroom_id
                 LEFT JOIN users u ON u.id = t.created_by
                 WHERE $wSql ORDER BY t.id DESC LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            apiResponse(['items' => $stmt->fetchAll(), 'total' => $count, 'page' => $page]);
        }
        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare('SELECT t.*, s.name AS storeroom_name FROM inv_tickets t LEFT JOIN inv_storerooms s ON s.id = t.storeroom_id WHERE t.id = ? AND t.is_deleted = 0');
            $stmt->execute([$id]);
            $tkt = $stmt->fetch();
            if (!$tkt) apiError('حواله/رسید یافت نشد', 404);
            $items = $pdo->prepare('SELECT ti.*, st.name AS stuff_name FROM inv_ticket_items ti LEFT JOIN stuffs st ON st.id = ti.stuff_id WHERE ti.ticket_id = ?');
            $items->execute([$id]);
            $tkt['items'] = $items->fetchAll();
            apiResponse($tkt);
        }
    }

    // انبارها
    if ($action === 'storerooms' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM inv_storerooms WHERE is_active = 1 ORDER BY name');
        $stmt->execute();
        apiResponse($stmt->fetchAll());
    }

    // موجودی کالا
    if ($action === 'stock' && $method === 'GET') {
        $where  = ['s.is_active = 1'];
        $params = [];
        if (!empty($_GET['search'])) { $where[] = 's.name LIKE ?'; $params[] = '%' . $_GET['search'] . '%'; }
        if (!empty($_GET['low_stock'])) { $where[] = 'spl.total_inventory <= spl.minimum_stock'; }
        $wSql  = implode(' AND ', $where);
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM stuffs s LEFT JOIN stuff_price_list spl ON spl.stuff_id = s.id WHERE $wSql");
        $cStmt->execute($params);
        $count = (int)$cStmt->fetchColumn();
        $stmt  = $pdo->prepare(
            "SELECT s.id, s.name, s.code, spl.total_inventory, spl.central_store,
                    spl.virtual_store, spl.minimum_stock, spl.unit_price
             FROM stuffs s LEFT JOIN stuff_price_list spl ON spl.stuff_id = s.id
             WHERE $wSql ORDER BY s.name LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        apiResponse(['items' => $stmt->fetchAll(), 'total' => $count, 'page' => $page]);
    }

    apiError('endpoint یافت نشد', 404);
}

// ══════════════════════════════════════════════════════════
// ── HR ────────────────────────────────────────────────────
// ══════════════════════════════════════════════════════════
if ($resource === 'hr') {

    // مرخصی‌ها
    if ($action === 'leaves') {
        if ($method === 'GET') {
            $where  = ['1=1'];
            $params = [];
            if (!empty($_GET['status']))  { $where[] = 'status = ?'; $params[] = $_GET['status']; }
            if (!empty($_GET['user_id'])) { $where[] = 'user_id = ?'; $params[] = (int)$_GET['user_id']; }
            $wSql  = implode(' AND ', $where);
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE $wSql");
            $cStmt->execute($params);
            $count = (int)$cStmt->fetchColumn();
            $stmt  = $pdo->prepare(
                "SELECT lr.*, u.name AS user_name FROM leave_requests lr
                 LEFT JOIN users u ON u.id = lr.user_id
                 WHERE $wSql ORDER BY lr.id DESC LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            apiResponse(['items' => $stmt->fetchAll(), 'total' => $count, 'page' => $page]);
        }
    }

    // ماموریت‌ها
    if ($action === 'missions') {
        if ($method === 'GET') {
            $where  = ['1=1'];
            $params = [];
            if (!empty($_GET['status']))  { $where[] = 'm.status = ?'; $params[] = $_GET['status']; }
            if (!empty($_GET['user_id'])) { $where[] = 'm.user_id = ?'; $params[] = (int)$_GET['user_id']; }
            $wSql  = implode(' AND ', $where);
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM mission_requests m WHERE $wSql");
            $cStmt->execute($params);
            $count = (int)$cStmt->fetchColumn();
            $stmt  = $pdo->prepare(
                "SELECT m.*, u.name AS user_name FROM mission_requests m
                 LEFT JOIN users u ON u.id = m.user_id
                 WHERE $wSql ORDER BY m.id DESC LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            apiResponse(['items' => $stmt->fetchAll(), 'total' => $count, 'page' => $page]);
        }
    }

    // حضور و غیاب
    if ($action === 'attendance') {
        if ($method === 'GET') {
            $where  = ['1=1'];
            $params = [];
            if (!empty($_GET['user_id']))   { $where[] = 'user_id = ?'; $params[] = (int)$_GET['user_id']; }
            if (!empty($_GET['from_date'])) { $where[] = 'attendance_date >= ?'; $params[] = $_GET['from_date']; }
            if (!empty($_GET['to_date']))   { $where[] = 'attendance_date <= ?'; $params[] = $_GET['to_date']; }
            $wSql  = implode(' AND ', $where);
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_requests WHERE $wSql");
            $cStmt->execute($params);
            $count = (int)$cStmt->fetchColumn();
            $stmt  = $pdo->prepare(
                "SELECT ar.*, u.name AS user_name FROM attendance_requests ar
                 LEFT JOIN users u ON u.id = ar.user_id
                 WHERE $wSql ORDER BY ar.attendance_date DESC LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            apiResponse(['items' => $stmt->fetchAll(), 'total' => $count, 'page' => $page]);
        }
    }

    apiError('endpoint یافت نشد', 404);
}

// ══════════════════════════════════════════════════════════
// ── داشبورد ───────────────────────────────────────────────
// ══════════════════════════════════════════════════════════
if ($resource === 'dashboard') {
    if ($action === 'summary' && $method === 'GET') {
        $data = [];

        // CRM
        try {
            $data['crm'] = [
                'total_opportunities' => (int)$pdo->query('SELECT COUNT(*) FROM crm_opportunities WHERE is_deleted = 0')->fetchColumn(),
                'open_opportunities'  => (int)$pdo->query('SELECT COUNT(*) FROM crm_opportunities o JOIN crm_board_stages s ON s.id = o.stage_id WHERE o.is_deleted = 0 AND s.name NOT IN ("بسته شد","غیرفعال")')->fetchColumn(),
            ];
        } catch (Exception $e) {
            $data['crm'] = ['error' => $e->getMessage()];
        }

        // مالی
        try {
            $row = $pdo->query(
                "SELECT
                    COUNT(*) AS total_invoices,
                    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_invoices,
                    SUM(total_amount) AS total_revenue,
                    SUM(paid_amount) AS collected_amount
                 FROM fin_invoices WHERE type='sell' AND is_deleted=0"
            )->fetch();
            $data['finance'] = [
                'total_invoices'   => (int)$row['total_invoices'],
                'paid_invoices'    => (int)$row['paid_invoices'],
                'total_revenue'    => (int)$row['total_revenue'],
                'collected_amount' => (int)$row['collected_amount'],
                'pending_cheques'  => (int)$pdo->query("SELECT COUNT(*) FROM fin_cheques WHERE status='pending' AND is_deleted=0")->fetchColumn(),
            ];
        } catch (Exception $e) {
            $data['finance'] = ['error' => $e->getMessage()];
        }

        // انبار
        try {
            $data['inventory'] = [
                'total_products'   => (int)$pdo->query("SELECT COUNT(*) FROM stuffs WHERE is_active=1")->fetchColumn(),
                'low_stock_count'  => (int)$pdo->query("SELECT COUNT(*) FROM stuffs s JOIN stuff_price_list spl ON spl.stuff_id=s.id WHERE s.is_active=1 AND spl.total_inventory > 0 AND spl.total_inventory <= COALESCE(spl.minimum_stock,5)")->fetchColumn(),
            ];
        } catch (Exception $e) {
            $data['inventory'] = ['error' => $e->getMessage()];
        }

        // HR
        try {
            $data['hr'] = [
                'pending_leaves'   => (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn(),
                'pending_missions' => (int)$pdo->query("SELECT COUNT(*) FROM mission_requests WHERE status='pending'")->fetchColumn(),
                'active_users'     => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(),
            ];
        } catch (Exception $e) {
            $data['hr'] = ['error' => $e->getMessage()];
        }

        $data['generated_at'] = date('Y-m-d H:i:s');
        apiResponse($data);
    }
}

// ══════════════════════════════════════════════════════════
// ── قراردادها ─────────────────────────────────────────────
// ══════════════════════════════════════════════════════════
if ($resource === 'contracts') {
    if ($method === 'GET' && !$id) {
        $stmt = $pdo->prepare(
            "SELECT c.id, c.contract_number, c.title,
                    fp.name AS customer_name,
                    c.start_date, c.end_date, c.amount, c.status
             FROM contracts c
             LEFT JOIN fin_persons fp ON fp.id = c.person_id
             WHERE c.is_deleted = 0
             ORDER BY c.id DESC
             LIMIT 100"
        );
        $stmt->execute();
        apiResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    if ($method === 'GET' && $id) {
        $stmt = $pdo->prepare(
            "SELECT c.*, fp.name AS customer_name
             FROM contracts c
             LEFT JOIN fin_persons fp ON fp.id = c.person_id
             WHERE c.id = ? AND c.is_deleted = 0"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) apiError('قرارداد یافت نشد', 404);
        apiResponse(['ok' => true, 'data' => $row]);
    }
    apiError('endpoint یافت نشد', 404);
}

// ══════════════════════════════════════════════════════════
// ── تیکت‌های پشتیبانی ─────────────────────────────────────
// ══════════════════════════════════════════════════════════
if ($resource === 'support-tickets') {
    if ($method === 'GET' && !$id) {
        $stmt = $pdo->prepare(
            "SELECT st.id, st.ticket_number, st.subject,
                    fp.name AS customer_name,
                    st.priority, st.status, st.created_at
             FROM support_tickets st
             LEFT JOIN fin_persons fp ON fp.id = st.person_id
             WHERE st.is_deleted = 0
             ORDER BY st.id DESC
             LIMIT 100"
        );
        $stmt->execute();
        apiResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    if ($method === 'GET' && $id) {
        $stmt = $pdo->prepare(
            "SELECT st.*, fp.name AS customer_name
             FROM support_tickets st
             LEFT JOIN fin_persons fp ON fp.id = st.person_id
             WHERE st.id = ? AND st.is_deleted = 0"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) apiError('تیکت یافت نشد', 404);
        apiResponse(['ok' => true, 'data' => $row]);
    }
    apiError('endpoint یافت نشد', 404);
}

// ══════════════════════════════════════════════════════════
// ── گارانتی / ضمانت ──────────────────────────────────────
// ══════════════════════════════════════════════════════════
if ($resource === 'warranty') {
    if ($method === 'GET' && !$id) {
        $stmt = $pdo->prepare(
            "SELECT w.id, w.serial_number,
                    s.name AS stuff_name,
                    fp.name AS customer_name,
                    w.expiry_date, w.status
             FROM warranty_records w
             LEFT JOIN stuffs s ON s.id = w.stuff_id
             LEFT JOIN fin_persons fp ON fp.id = w.person_id
             WHERE w.is_deleted = 0
             ORDER BY w.id DESC
             LIMIT 100"
        );
        $stmt->execute();
        apiResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    if ($method === 'GET' && $id) {
        $stmt = $pdo->prepare(
            "SELECT w.*, s.name AS stuff_name, fp.name AS customer_name
             FROM warranty_records w
             LEFT JOIN stuffs s ON s.id = w.stuff_id
             LEFT JOIN fin_persons fp ON fp.id = w.person_id
             WHERE w.id = ? AND w.is_deleted = 0"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) apiError('رکورد گارانتی یافت نشد', 404);
        apiResponse(['ok' => true, 'data' => $row]);
    }
    apiError('endpoint یافت نشد', 404);
}

// ══════════════════════════════════════════════════════════
// ── آفرها / پیشنهادهای قیمت ──────────────────────────────
// ══════════════════════════════════════════════════════════
if ($resource === 'quotes') {
    if ($method === 'GET' && !$id) {
        $stmt = $pdo->prepare(
            "SELECT q.id, q.quote_number,
                    fp.name AS customer_name,
                    q.total_amount, q.status, q.quote_date
             FROM quotes q
             LEFT JOIN fin_persons fp ON fp.id = q.person_id
             WHERE q.is_deleted = 0
             ORDER BY q.id DESC
             LIMIT 100"
        );
        $stmt->execute();
        apiResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
    if ($method === 'GET' && $id) {
        $stmt = $pdo->prepare(
            "SELECT q.*, fp.name AS customer_name
             FROM quotes q
             LEFT JOIN fin_persons fp ON fp.id = q.person_id
             WHERE q.id = ? AND q.is_deleted = 0"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) apiError('آفر یافت نشد', 404);
        apiResponse(['ok' => true, 'data' => $row]);
    }
    apiError('endpoint یافت نشد', 404);
}

// ── fallback ───────────────────────────────────────────────
apiError("endpoint '$resource/$action' یافت نشد", 404);
