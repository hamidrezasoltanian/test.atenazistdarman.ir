<?php
// ایجنت واردات داده — آتنا زیست درمان
// پشتیبانی: فاکتور فروش / پیش‌فاکتور / دریافت‌و‌پرداخت / هزینه

$root = dirname(__DIR__, 2);
require_once $root . '/Config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';

requireLogin();
$userId = $_SESSION['user_id'] ?? 0;

// ── پوشه موقت آپلود ───────────────────────────────────────────
$uploadDir = __DIR__ . '/../../public_html/uploads/import_agent/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

// ──────────────────────────────────────────────────────────────
// ── AJAX: آپلود و پارس فایل ───────────────────────────────────
// ──────────────────────────────────────────────────────────────
if ($isAjax && ($_GET['action'] ?? '') === 'upload') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_FILES['file'])) { echo json_encode(['error' => 'فایلی انتخاب نشده']); exit; }

    $file = $_FILES['file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
        echo json_encode(['error' => 'فقط CSV، XLSX و XLS قابل قبول است']); exit;
    }
    $dest = $uploadDir . uniqid('imp_') . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $dest);

    try {
        [$headers, $rows] = parseFile($dest, $ext);
    } catch (Exception $e) {
        @unlink($dest);
        echo json_encode(['error' => 'خطا در پارس فایل: ' . $e->getMessage()]); exit;
    }

    echo json_encode([
        'ok'       => true,
        'file_key' => basename($dest),
        'headers'  => $headers,
        'preview'  => array_slice($rows, 0, 8),
        'total'    => count($rows),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: اجرای واردات ────────────────────────────────────────
if ($isAjax && ($_GET['action'] ?? '') === 'run_import') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify();
    $body    = json_decode(file_get_contents('php://input'), true);
    $fileKey = basename($body['file_key'] ?? '');
    $entity  = $body['entity'] ?? '';
    $mapping = $body['mapping'] ?? [];

    if (!$fileKey || !$entity || !$mapping) {
        echo json_encode(['error' => 'پارامترهای ناقص']); exit;
    }
    $path = $uploadDir . $fileKey;
    if (!file_exists($path)) { echo json_encode(['error' => 'فایل یافت نشد']); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    try {
        [$headers, $rows] = parseFile($path, $ext);
    } catch (Exception $e) {
        echo json_encode(['error' => 'خطا در خواندن فایل: ' . $e->getMessage()]); exit;
    }

    $result = runImport($pdo, $entity, $rows, $mapping, $userId);
    @unlink($path);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: پیش‌نمایش نگاشت ─────────────────────────────────────
if ($isAjax && ($_GET['action'] ?? '') === 'preview_map') {
    header('Content-Type: application/json; charset=utf-8');
    $body    = json_decode(file_get_contents('php://input'), true);
    $fileKey = basename($body['file_key'] ?? '');
    $mapping = $body['mapping'] ?? [];
    $path    = $uploadDir . $fileKey;
    if (!file_exists($path)) { echo json_encode(['error' => 'فایل یافت نشد']); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    [$headers, $rows] = parseFile($path, $ext);
    $preview = [];
    foreach (array_slice($rows, 0, 6) as $row) {
        $mapped = [];
        foreach ($mapping as $field => $col) {
            if ($col !== '' && isset($row[$col])) $mapped[$field] = $row[$col];
        }
        $preview[] = $mapped;
    }
    echo json_encode(['ok' => true, 'preview' => $preview], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──────────────────────────────────────────────────────────────
// ── توابع پارس فایل ──────────────────────────────────────────
// ──────────────────────────────────────────────────────────────
function parseFile(string $path, string $ext): array {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    if ($ext === 'csv') {
        $rows = []; $headers = [];
        if (($fh = fopen($path, 'r')) !== false) {
            $raw = fgets($fh);
            // تشخیص جداکننده
            $sep = (substr_count($raw, "\t") > substr_count($raw, ",")) ? "\t" : ",";
            rewind($fh);
            $first = true;
            while (($data = fgetcsv($fh, 0, $sep)) !== false) {
                if ($first) { $headers = array_map('trim', $data); $first = false; continue; }
                if (count($data) !== count($headers)) continue;
                $row = [];
                foreach ($headers as $i => $h) $row[$h] = trim($data[$i] ?? '');
                $rows[] = $row;
            }
            fclose($fh);
        }
    } else {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet    = $spreadsheet->getActiveSheet();
        $data     = $sheet->toArray(null, true, true, false);
        $headers  = array_map('strval', array_map('trim', $data[0] ?? []));
        $rows     = [];
        foreach (array_slice($data, 1) as $rawRow) {
            $allEmpty = true;
            foreach ($rawRow as $v) if (trim((string)$v) !== '') { $allEmpty = false; break; }
            if ($allEmpty) continue;
            $row = [];
            foreach ($headers as $i => $h) $row[$h] = trim((string)($rawRow[$i] ?? ''));
            $rows[] = $row;
        }
    }
    return [$headers, $rows];
}

// ──────────────────────────────────────────────────────────────
// ── تابع اصلی واردات ─────────────────────────────────────────
// ──────────────────────────────────────────────────────────────
function runImport(PDO $pdo, string $entity, array $rows, array $mapping, int $userId): array {
    $imported = 0; $skipped = 0; $errors = [];

    switch ($entity) {
        case 'invoice_sell': return importInvoiceSell($pdo, $rows, $mapping, $userId);
        case 'preinvoice':   return importPreinvoice($pdo, $rows, $mapping, $userId);
        case 'receive_pay':  return importReceivePay($pdo, $rows, $mapping, $userId);
        case 'expenses':     return importExpenses($pdo, $rows, $mapping, $userId);
        default: return ['error' => 'نوع موجودیت نامعتبر است'];
    }
}

// ── کمکی: یافتن یا ساخت fin_person ───────────────────────────
function findOrCreatePerson(PDO $pdo, string $name, string $phone = '', string $type = 'customer'): ?int {
    $name = trim($name);
    if (!$name) return null;

    // جستجو بر اساس شماره موبایل
    if ($phone) {
        $st = $pdo->prepare("SELECT id FROM fin_persons WHERE mobile=? AND is_deleted=0 LIMIT 1");
        $st->execute([$phone]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    // جستجو بر اساس نام
    $st = $pdo->prepare("SELECT id FROM fin_persons WHERE (name=? OR company_name=?) AND is_deleted=0 LIMIT 1");
    $st->execute([$name, $name]);
    if ($id = $st->fetchColumn()) return (int)$id;

    // ساخت جدید
    $st = $pdo->prepare(
        "INSERT INTO fin_persons (name, company_name, mobile, type, is_deleted, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, NOW(), NOW())"
    );
    $st->execute([$name, $name, $phone, $type]);
    return (int)$pdo->lastInsertId();
}

// ── کمکی: یافتن یا ساخت کالا ────────────────────────────────
function findOrCreateStuff(PDO $pdo, string $name): ?int {
    $name = trim($name);
    if (!$name) return null;

    $st = $pdo->prepare("SELECT id FROM stuffs WHERE stuff_name=? AND is_active=1 LIMIT 1");
    $st->execute([$name]);
    if ($id = $st->fetchColumn()) return (int)$id;

    // ساخت placeholder
    $pdo->prepare("INSERT INTO stuffs (stuff_name, stuff_code, is_active, created_at, updated_at) VALUES (?,?,1,NOW(),NOW())")
        ->execute([$name, 'IMP-' . substr(md5($name), 0, 6)]);
    $stuffId = (int)$pdo->lastInsertId();
    // ردیف قیمت
    $pdo->prepare("INSERT IGNORE INTO stuff_price_list (stuff_id, total_inventory, minimum_stock) VALUES (?,0,0)")
        ->execute([$stuffId]);
    return $stuffId;
}

// ── کمکی: تبدیل مبلغ (رشته → عدد صحیح) ─────────────────────
function parseMoney(string $v): int {
    return (int)preg_replace('/[^0-9\-]/', '', $v);
}

// ── کمکی: تبدیل تاریخ شمسی به فرمت استاندارد YYYY/MM/DD ──────
function parseDate(string $v): string {
    $v = trim($v);
    if (!$v) return jdate('Y/m/d');
    // YYYY/MM/DD یا YYYY-MM-DD
    $v = str_replace(['-', '.'], '/', $v);
    if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $v)) {
        [$y, $m, $d] = explode('/', $v);
        return sprintf('%04d/%02d/%02d', $y, $m, $d);
    }
    return $v ?: jdate('Y/m/d');
}

// ── کمکی: شماره خودکار ───────────────────────────────────────
function nextNumber(PDO $pdo, string $table, string $field, string $prefix): string {
    $ym  = jdate('Ym');
    $st  = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$field} LIKE ?");
    $st->execute([$prefix . '-' . $ym . '-%']);
    $seq = (int)$st->fetchColumn() + 1;
    return $prefix . '-' . $ym . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ──────────────────────────────────────────────────────────────
// ── واردات فاکتور فروش ───────────────────────────────────────
// ──────────────────────────────────────────────────────────────
function importInvoiceSell(PDO $pdo, array $rows, array $mapping, int $userId): array {
    $m = $mapping;
    $groups = []; // گروه‌بندی بر اساس شماره فاکتور

    foreach ($rows as $i => $row) {
        $invNum = isset($m['invoice_number']) && $m['invoice_number'] ? trim($row[$m['invoice_number']] ?? '') : '';
        if (!$invNum) $invNum = 'IMP-' . ($i + 1);
        $groups[$invNum][] = $row;
    }

    $imported = 0; $skipped = 0; $errors = [];

    foreach ($groups as $invNum => $items) {
        $first      = $items[0];
        $personName = $m['customer_name'] ? trim($first[$m['customer_name']] ?? '') : '';
        $personPhone= $m['customer_phone'] ? trim($first[$m['customer_phone']] ?? '') : '';
        $invDate    = parseDate($m['invoice_date'] ? ($first[$m['invoice_date']] ?? '') : '');
        $status     = $m['status'] ? trim($first[$m['status']] ?? 'draft') : 'draft';
        if (!in_array($status, ['draft','confirmed'])) $status = 'draft';

        $personId = null;
        if ($personName || $personPhone) {
            $personId = findOrCreatePerson($pdo, $personName, $personPhone, 'customer');
        }

        // بررسی تکراری
        $chk = $pdo->prepare("SELECT id FROM fin_invoices WHERE invoice_number=? AND is_deleted=0 LIMIT 1");
        $chk->execute([$invNum]);
        if ($chk->fetchColumn()) { $skipped++; continue; }

        try {
            $pdo->beginTransaction();

            // محاسبه جمع
            $subtotal = 0; $taxTotal = 0; $discTotal = 0;
            $lineItems = [];
            foreach ($items as $row) {
                $stuffName = $m['item_name'] ? trim($row[$m['item_name']] ?? '') : 'خدمت';
                $qty       = (float)($m['quantity'] ? ($row[$m['quantity']] ?? 1) : 1);
                $price     = $m['unit_price'] ? parseMoney($row[$m['unit_price']] ?? '0') : 0;
                $disc      = $m['discount']   ? parseMoney($row[$m['discount']] ?? '0') : 0;
                $tax       = $m['tax']        ? parseMoney($row[$m['tax']] ?? '0') : 0;
                $desc      = $m['description'] ? trim($row[$m['description']] ?? '') : '';
                $lineTotal = (int)($qty * $price) - $disc + $tax;
                $subtotal += (int)($qty * $price);
                $discTotal += $disc;
                $taxTotal  += $tax;
                $lineItems[] = compact('stuffName','qty','price','disc','tax','desc','lineTotal');
            }
            $totalAmount = $subtotal - $discTotal + $taxTotal;

            $st = $pdo->prepare(
                "INSERT INTO fin_invoices
                 (invoice_number, type, status, invoice_date, person_id,
                  subtotal, discount, tax, total_amount, paid_amount,
                  created_by, is_deleted, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,0,?,0,NOW(),NOW())"
            );
            $st->execute([$invNum,'sell',$status,$invDate,$personId,$subtotal,$discTotal,$taxTotal,$totalAmount,$userId]);
            $invoiceId = (int)$pdo->lastInsertId();

            foreach ($lineItems as $line) {
                $stuffId = findOrCreateStuff($pdo, $line['stuffName']);
                $pdo->prepare(
                    "INSERT INTO fin_invoice_items
                     (invoice_id, stuff_id, description, quantity, unit_price, discount, tax, total_price, created_at)
                     VALUES (?,?,?,?,?,?,?,?,NOW())"
                )->execute([$invoiceId,$stuffId,$line['desc'],$line['qty'],$line['price'],$line['disc'],$line['tax'],$line['lineTotal']]);
            }

            $pdo->commit();
            $imported++;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "فاکتور {$invNum}: " . $e->getMessage();
        }
    }

    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

// ──────────────────────────────────────────────────────────────
// ── واردات پیش‌فاکتور ─────────────────────────────────────────
// ──────────────────────────────────────────────────────────────
function importPreinvoice(PDO $pdo, array $rows, array $mapping, int $userId): array {
    // بررسی وجود جدول
    try { $pdo->query("SELECT 1 FROM fin_preinvoice LIMIT 1"); }
    catch (Exception $e) {
        return ['error' => 'جدول fin_preinvoice وجود ندارد. لطفاً ابتدا ماژول پیش‌فاکتور را باز کنید.'];
    }

    $m = $mapping;
    $groups = [];
    foreach ($rows as $i => $row) {
        $num = $m['preinvoice_number'] ? trim($row[$m['preinvoice_number']] ?? '') : '';
        if (!$num) $num = 'PRE-' . ($i + 1);
        $groups[$num][] = $row;
    }

    $imported = 0; $skipped = 0; $errors = [];

    foreach ($groups as $num => $items) {
        $first       = $items[0];
        $personName  = $m['customer_name']  ? trim($first[$m['customer_name']] ?? '') : '';
        $personPhone = $m['customer_phone'] ? trim($first[$m['customer_phone']] ?? '') : '';
        $date        = parseDate($m['preinvoice_date'] ? ($first[$m['preinvoice_date']] ?? '') : '');
        $validUntil  = parseDate($m['valid_until'] ? ($first[$m['valid_until']] ?? '') : '');

        $personId = null;
        if ($personName || $personPhone)
            $personId = findOrCreatePerson($pdo, $personName, $personPhone, 'customer');

        $chk = $pdo->prepare("SELECT id FROM fin_preinvoice WHERE preinvoice_number=? AND is_deleted=0 LIMIT 1");
        $chk->execute([$num]);
        if ($chk->fetchColumn()) { $skipped++; continue; }

        try {
            $pdo->beginTransaction();
            $subtotal = 0; $discTotal = 0; $taxTotal = 0; $lineItems = [];

            foreach ($items as $row) {
                $stuffName = $m['item_name']   ? trim($row[$m['item_name']] ?? '') : 'خدمت';
                $qty       = (float)($m['quantity']   ? ($row[$m['quantity']] ?? 1) : 1);
                $price     = $m['unit_price'] ? parseMoney($row[$m['unit_price']] ?? '0') : 0;
                $disc      = $m['discount']   ? parseMoney($row[$m['discount']] ?? '0') : 0;
                $tax       = $m['tax']        ? parseMoney($row[$m['tax']] ?? '0') : 0;
                $desc      = $m['description'] ? trim($row[$m['description']] ?? '') : '';
                $lineTotal = (int)($qty * $price) - $disc + $tax;
                $subtotal += (int)($qty * $price);
                $discTotal += $disc; $taxTotal += $tax;
                $lineItems[] = compact('stuffName','qty','price','disc','tax','desc','lineTotal');
            }
            $total = $subtotal - $discTotal + $taxTotal;

            $pdo->prepare(
                "INSERT INTO fin_preinvoice
                 (preinvoice_number, person_id, preinvoice_date, valid_until,
                  subtotal, discount, tax, total_amount, status,
                  created_by, is_deleted, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,'draft',?,0,NOW(),NOW())"
            )->execute([$num,$personId,$date,$validUntil,$subtotal,$discTotal,$taxTotal,$total,$userId]);
            $preId = (int)$pdo->lastInsertId();

            foreach ($lineItems as $line) {
                $stuffId = findOrCreateStuff($pdo, $line['stuffName']);
                $pdo->prepare(
                    "INSERT INTO fin_preinvoice_items
                     (preinvoice_id, stuff_id, description, quantity, unit_price, discount, tax, total_price, created_at)
                     VALUES (?,?,?,?,?,?,?,?,NOW())"
                )->execute([$preId,$stuffId,$line['desc'],$line['qty'],$line['price'],$line['disc'],$line['tax'],$line['lineTotal']]);
            }

            $pdo->commit();
            $imported++;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "پیش‌فاکتور {$num}: " . $e->getMessage();
        }
    }

    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

// ──────────────────────────────────────────────────────────────
// ── واردات دریافت/پرداخت ─────────────────────────────────────
// ──────────────────────────────────────────────────────────────
function importReceivePay(PDO $pdo, array $rows, array $mapping, int $userId): array {
    $m = $mapping;
    $imported = 0; $skipped = 0; $errors = [];
    $typeMap = [
        'receive' => 'receive', 'دریافت' => 'receive',
        'pay'     => 'pay',     'پرداخت' => 'pay',
    ];
    $methodMap = [
        'cash' => 'cash', 'نقد' => 'cash',
        'bank' => 'bank', 'بانک' => 'bank', 'انتقال' => 'bank',
        'cheque' => 'cheque', 'چک' => 'cheque',
    ];

    foreach ($rows as $i => $row) {
        try {
            $amount      = $m['amount']      ? parseMoney($row[$m['amount']] ?? '0') : 0;
            $date        = parseDate($m['date'] ? ($row[$m['date']] ?? '') : '');
            $personName  = $m['person_name'] ? trim($row[$m['person_name']] ?? '') : '';
            $personPhone = $m['person_phone'] ? trim($row[$m['person_phone']] ?? '') : '';
            $typeRaw     = $m['type']   ? strtolower(trim($row[$m['type']] ?? 'receive')) : 'receive';
            $methodRaw   = $m['method'] ? strtolower(trim($row[$m['method']] ?? 'cash')) : 'cash';
            $desc        = $m['description'] ? trim($row[$m['description']] ?? '') : '';
            $refNo       = $m['reference']   ? trim($row[$m['reference']] ?? '') : '';

            if ($amount <= 0) { $skipped++; continue; }

            $type   = $typeMap[$typeRaw] ?? 'receive';
            $method = $methodMap[$methodRaw] ?? 'cash';
            $personId = null;
            if ($personName || $personPhone)
                $personId = findOrCreatePerson($pdo, $personName, $personPhone, $type === 'receive' ? 'customer' : 'supplier');

            $pdo->prepare(
                "INSERT INTO fin_transactions
                 (type, amount, transaction_date, person_id, payment_method,
                  description, reference_number, created_by, is_deleted, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,0,NOW(),NOW())"
            )->execute([$type,$amount,$date,$personId,$method,$desc,$refNo,$userId]);
            $imported++;
        } catch (Exception $e) {
            $errors[] = "ردیف " . ($i+2) . ": " . $e->getMessage();
        }
    }

    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

// ──────────────────────────────────────────────────────────────
// ── واردات هزینه ─────────────────────────────────────────────
// ──────────────────────────────────────────────────────────────
function importExpenses(PDO $pdo, array $rows, array $mapping, int $userId): array {
    $m = $mapping;
    $imported = 0; $skipped = 0; $errors = [];

    // دسته‌بندی‌های موجود
    $cats = $pdo->query("SELECT id, name FROM fin_categories WHERE is_deleted=0")->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($rows as $i => $row) {
        try {
            $amount  = $m['amount']   ? parseMoney($row[$m['amount']] ?? '0') : 0;
            $date    = parseDate($m['date'] ? ($row[$m['date']] ?? '') : '');
            $desc    = $m['description'] ? trim($row[$m['description']] ?? '') : 'واردات';
            $catName = $m['category']    ? trim($row[$m['category']] ?? '') : '';
            $payTo   = $m['paid_to']     ? trim($row[$m['paid_to']] ?? '') : '';

            if ($amount <= 0) { $skipped++; continue; }

            // یافتن/ساخت دسته‌بندی
            $catId = null;
            if ($catName) {
                $found = array_search($catName, $cats);
                if ($found !== false) {
                    $catId = $found;
                } else {
                    $pdo->prepare("INSERT INTO fin_categories (name, is_deleted, created_at) VALUES (?,0,NOW())")
                        ->execute([$catName]);
                    $catId = (int)$pdo->lastInsertId();
                    $cats[$catId] = $catName;
                }
            }

            $pdo->prepare(
                "INSERT INTO fin_expenses
                 (amount, expense_date, category_id, description, paid_to,
                  created_by, is_deleted, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,0,NOW(),NOW())"
            )->execute([$amount,$date,$catId,$desc,$payTo,$userId]);
            $imported++;
        } catch (Exception $e) {
            $errors[] = "ردیف " . ($i+2) . ": " . $e->getMessage();
        }
    }

    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

// ──────────────────────────────────────────────────────────────
// ── تعریف فیلدهای هر موجودیت ─────────────────────────────────
// ──────────────────────────────────────────────────────────────
$entityFields = [
    'invoice_sell' => [
        'required' => [
            'customer_name'    => 'نام مشتری',
            'invoice_date'     => 'تاریخ فاکتور',
            'item_name'        => 'نام کالا/خدمت',
            'unit_price'       => 'قیمت واحد',
        ],
        'optional' => [
            'invoice_number'   => 'شماره فاکتور',
            'customer_phone'   => 'موبایل مشتری',
            'quantity'         => 'تعداد',
            'discount'         => 'تخفیف',
            'tax'              => 'مالیات',
            'description'      => 'توضیحات ردیف',
            'status'           => 'وضعیت (draft/confirmed)',
        ],
    ],
    'preinvoice' => [
        'required' => [
            'customer_name'      => 'نام مشتری',
            'preinvoice_date'    => 'تاریخ پیش‌فاکتور',
            'item_name'          => 'نام کالا/خدمت',
            'unit_price'         => 'قیمت واحد',
        ],
        'optional' => [
            'preinvoice_number' => 'شماره پیش‌فاکتور',
            'customer_phone'    => 'موبایل مشتری',
            'quantity'          => 'تعداد',
            'discount'          => 'تخفیف',
            'tax'               => 'مالیات',
            'description'       => 'توضیحات ردیف',
            'valid_until'       => 'اعتبار تا',
        ],
    ],
    'receive_pay' => [
        'required' => [
            'amount'       => 'مبلغ',
            'date'         => 'تاریخ',
            'type'         => 'نوع (receive=دریافت / pay=پرداخت)',
        ],
        'optional' => [
            'person_name'  => 'نام طرف حساب',
            'person_phone' => 'موبایل طرف حساب',
            'method'       => 'روش (cash/bank/cheque)',
            'description'  => 'توضیحات',
            'reference'    => 'شماره مرجع/رسید',
        ],
    ],
    'expenses' => [
        'required' => [
            'amount'      => 'مبلغ',
            'date'        => 'تاریخ',
            'description' => 'شرح هزینه',
        ],
        'optional' => [
            'category'    => 'دسته‌بندی',
            'paid_to'     => 'پرداخت به',
        ],
    ],
];

$entityLabels = [
    'invoice_sell' => 'فاکتور فروش',
    'preinvoice'   => 'پیش‌فاکتور',
    'receive_pay'  => 'دریافت / پرداخت',
    'expenses'     => 'هزینه',
];

// ── HTML ──────────────────────────────────────────────────────
$pageTitle = 'ایجنت واردات داده';
ob_start();
require_once $root . '/templates/header.php';
echo ob_get_clean();
require_once $root . '/templates/sidebar.php';
?>

<div class="content-wrapper">
<div style="padding:28px 32px;max-width:960px;">

<!-- عنوان -->
<div style="margin-bottom:28px;">
  <h1 style="margin:0;font-size:1.4rem;font-weight:700;color:#0f172a;">📥 ایجنت واردات داده</h1>
  <p style="margin:6px 0 0;color:#64748b;font-size:.87rem;">
    فایل CSV یا Excel خروجی نرم‌افزار قبلی را آپلود کنید — سیستم به‌صورت هوشمند اطلاعات را وارد می‌کند.
  </p>
</div>

<!-- wizard steps indicator -->
<div id="stepsBar" style="display:flex;gap:0;margin-bottom:32px;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
  <?php $steps = ['۱ انتخاب فایل','۲ نگاشت ستون‌ها','۳ پیش‌نمایش','۴ نتیجه']; ?>
  <?php foreach ($steps as $si => $slbl): ?>
  <div class="wstep" id="wstep<?= $si ?>" style="
      flex:1;padding:13px 8px;text-align:center;font-size:.78rem;font-weight:600;
      background:<?= $si === 0 ? '#1e40af' : '#f8fafc' ?>;
      color:<?= $si === 0 ? '#fff' : '#94a3b8' ?>;
      border-left:<?= $si > 0 ? '1px solid #e2e8f0' : 'none' ?>;">
    <?= $slbl ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── مرحله ۱: آپلود ─────────────────────────────────────── -->
<div id="step0" class="fin-panel" style="margin-bottom:20px;">
  <h3 style="font-size:1rem;font-weight:700;margin:0 0 20px;color:#0f172a;">انتخاب نوع داده و فایل</h3>

  <div style="margin-bottom:18px;">
    <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:8px;">نوع داده:</label>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;">
      <?php foreach ($entityLabels as $ekey => $elbl): ?>
      <label style="cursor:pointer;">
        <input type="radio" name="entity" value="<?= $ekey ?>" style="display:none;" onchange="onEntityChange(this)">
        <div class="entity-card" data-val="<?= $ekey ?>" style="
            border:2px solid #e2e8f0;border-radius:10px;padding:14px 10px;text-align:center;
            font-size:.82rem;font-weight:600;color:#64748b;transition:.15s;cursor:pointer;">
          <?= ['invoice_sell'=>'🧾','preinvoice'=>'📋','receive_pay'=>'💳','expenses'=>'💸'][$ekey] ?>
          <div style="margin-top:6px;"><?= $elbl ?></div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div id="uploadArea" style="display:none;margin-top:16px;">
    <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:8px;">فایل داده (CSV / XLSX / XLS):</label>
    <div id="dropZone" style="
        border:2px dashed #cbd5e1;border-radius:12px;padding:36px 20px;
        text-align:center;cursor:pointer;transition:.2s;background:#f8fafc;"
         onclick="document.getElementById('fileInput').click()"
         ondragover="event.preventDefault();this.style.borderColor='#3b82f6'"
         ondragleave="this.style.borderColor='#cbd5e1'"
         ondrop="handleDrop(event)">
      <div style="font-size:2rem;margin-bottom:8px;">📂</div>
      <div style="color:#64748b;font-size:.85rem;">کلیک کنید یا فایل را اینجا بکشید</div>
      <div style="color:#94a3b8;font-size:.75rem;margin-top:4px;">CSV، XLSX، XLS — حداکثر ۵ مگابایت</div>
      <div id="fileName" style="margin-top:10px;font-weight:600;color:#3b82f6;font-size:.82rem;"></div>
    </div>
    <input type="file" id="fileInput" accept=".csv,.xlsx,.xls" style="display:none" onchange="uploadFile(this)">
    <div id="uploadStatus" style="margin-top:10px;font-size:.82rem;display:none;"></div>
  </div>
</div>

<!-- ── مرحله ۲: نگاشت ─────────────────────────────────────── -->
<div id="step1" class="fin-panel" style="display:none;margin-bottom:20px;">
  <h3 style="font-size:1rem;font-weight:700;margin:0 0 6px;color:#0f172a;">نگاشت ستون‌های فایل</h3>
  <p style="color:#64748b;font-size:.8rem;margin:0 0 20px;">
    برای هر فیلد موردنیاز، ستون متناظر فایل خود را انتخاب کنید.
    فیلدهای با ستاره ⭐ اجباری هستند.
  </p>
  <div id="mappingTable"></div>
  <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
    <button class="fin-btn fin-btn-primary" onclick="previewMapping()">پیش‌نمایش →</button>
    <button class="fin-btn" onclick="goStep(0)">← برگشت</button>
  </div>
</div>

<!-- ── مرحله ۳: پیش‌نمایش ─────────────────────────────────── -->
<div id="step2" class="fin-panel" style="display:none;margin-bottom:20px;">
  <h3 style="font-size:1rem;font-weight:700;margin:0 0 4px;color:#0f172a;">پیش‌نمایش نگاشت</h3>
  <p style="color:#64748b;font-size:.8rem;margin:0 0 16px;"></p>
  <div id="previewTable" style="overflow-x:auto;"></div>
  <div id="importStats" style="margin-top:12px;padding:12px 14px;background:#f0fdf4;border-radius:8px;font-size:.82rem;color:#166534;display:none;"></div>
  <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
    <button class="fin-btn fin-btn-primary" id="importBtn" onclick="runImport()">⬆ شروع واردات</button>
    <button class="fin-btn" onclick="goStep(1)">← برگشت</button>
  </div>
</div>

<!-- ── مرحله ۴: نتیجه ─────────────────────────────────────── -->
<div id="step3" class="fin-panel" style="display:none;margin-bottom:20px;">
  <h3 style="font-size:1rem;font-weight:700;margin:0 0 20px;color:#0f172a;">نتیجه واردات</h3>
  <div id="resultBody"></div>
  <div style="margin-top:20px;">
    <button class="fin-btn fin-btn-primary" onclick="location.reload()">+ واردات جدید</button>
  </div>
</div>

</div>
</div>

<style>
.entity-card.selected { border-color:#3b82f6!important; background:#eff6ff; color:#1d4ed8!important; }
.map-row { display:grid;grid-template-columns:220px 1fr;gap:12px;align-items:center;margin-bottom:10px; }
.map-label { font-size:.82rem;font-weight:600;color:#374151; }
.map-select { padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.82rem;background:#fff;font-family:Vazirmatn,Tahoma;width:100%; }
.fin-table { width:100%;border-collapse:collapse;font-size:.79rem; }
.fin-table th { background:#f1f5f9;padding:8px 10px;text-align:right;font-weight:700;color:#374151;border-bottom:2px solid #e2e8f0; }
.fin-table td { padding:8px 10px;border-bottom:1px solid #f1f5f9;color:#334155; }
</style>

<script>
const entityFields = <?= json_encode($entityFields, JSON_UNESCAPED_UNICODE) ?>;
const CSRF = '<?= htmlspecialchars(csrf_token() ?? '') ?>';
let state = { entity: '', fileKey: '', headers: [], total: 0 };

function onEntityChange(radio) {
  state.entity = radio.value;
  document.querySelectorAll('.entity-card').forEach(c => c.classList.remove('selected'));
  document.querySelector(`.entity-card[data-val="${state.entity}"]`).classList.add('selected');
  document.getElementById('uploadArea').style.display = 'block';
}

function handleDrop(e) {
  e.preventDefault();
  document.getElementById('dropZone').style.borderColor = '#cbd5e1';
  const file = e.dataTransfer.files[0];
  if (file) uploadFileDirect(file);
}

function uploadFile(input) {
  if (input.files[0]) uploadFileDirect(input.files[0]);
}

function uploadFileDirect(file) {
  if (!state.entity) { alert('ابتدا نوع داده را انتخاب کنید'); return; }
  document.getElementById('fileName').textContent = file.name;
  const st = document.getElementById('uploadStatus');
  st.style.display = 'block';
  st.innerHTML = '<span style="color:#3b82f6;">در حال آپلود و پارس...</span>';

  const fd = new FormData();
  fd.append('file', file);
  $.ajax({
    url: '?action=upload',
    method: 'POST',
    data: fd,
    processData: false,
    contentType: false,
    headers: {'X-Requested-With': 'XMLHttpRequest'},
    success: function(d) {
      if (d.error) { st.innerHTML = '<span style="color:#ef4444;">' + d.error + '</span>'; return; }
      state.fileKey = d.file_key;
      state.headers  = d.headers;
      state.total    = d.total;
      st.innerHTML = `<span style="color:#10b981;">✓ ${d.total} ردیف شناسایی شد — ستون‌ها: ${d.headers.length}</span>`;
      buildMappingUI();
      goStep(1);
    },
    error: function() { st.innerHTML = '<span style="color:#ef4444;">خطا در آپلود</span>'; }
  });
}

function buildMappingUI() {
  const ef = entityFields[state.entity] || {};
  const headers = ['(انتخاب نشود)', ...state.headers];
  let html = '';

  const makeSelect = (field, label, req) => {
    let opts = headers.map(h => {
      // auto-detect
      const auto = autoGuess(field, h);
      return `<option value="${h === '(انتخاب نشود)' ? '' : h}" ${auto ? 'selected' : ''}>${h}</option>`;
    }).join('');
    return `<div class="map-row">
      <div class="map-label">${label} ${req ? '<span style="color:#ef4444;">⭐</span>' : ''}</div>
      <select class="map-select" name="${field}">${opts}</select>
    </div>`;
  };

  html += '<div style="font-size:.77rem;font-weight:700;color:#64748b;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">فیلدهای اجباری</div>';
  for (const [f, l] of Object.entries(ef.required || {})) html += makeSelect(f, l, true);
  if (ef.optional && Object.keys(ef.optional).length) {
    html += '<div style="font-size:.77rem;font-weight:700;color:#94a3b8;margin:14px 0 6px;text-transform:uppercase;letter-spacing:.5px;">فیلدهای اختیاری</div>';
    for (const [f, l] of Object.entries(ef.optional || {})) html += makeSelect(f, l, false);
  }

  document.getElementById('mappingTable').innerHTML = html;
}

function autoGuess(field, header) {
  const h = header.toLowerCase().replace(/\s+/g,'_');
  const map = {
    invoice_number:['invoice_number','شماره_فاکتور','inv_no','شماره فاکتور','no_invoice','number'],
    customer_name:['customer','مشتری','نام_مشتری','نام مشتری','client','person'],
    customer_phone:['mobile','phone','موبایل','تلفن','شماره_تماس'],
    invoice_date:['date','تاریخ','invoice_date','تاریخ_فاکتور','تاریخ فاکتور'],
    item_name:['item','product','کالا','نام_کالا','description','شرح'],
    unit_price:['price','unit_price','قیمت','قیمت_واحد','unit'],
    quantity:['qty','quantity','تعداد','مقدار'],
    discount:['discount','تخفیف'],
    tax:['tax','مالیات','vat'],
    amount:['amount','مبلغ','total','جمع'],
    date:['date','تاریخ'],
    type:['type','نوع'],
    method:['method','روش','payment_method'],
    description:['description','شرح','توضیحات'],
    category:['category','دسته','دسته_بندی'],
    paid_to:['paid_to','پرداخت_به','vendor','supplier'],
    preinvoice_number:['preinvoice','pre_invoice','شماره_پیش_فاکتور'],
    preinvoice_date:['date','تاریخ'],
    valid_until:['valid','اعتبار','expiry'],
    reference:['ref','reference','مرجع','رسید'],
    person_name:['person','نام','client','customer'],
    person_phone:['phone','mobile','موبایل'],
  };
  return (map[field] || []).some(k => h.includes(k));
}

function getMapping() {
  const m = {};
  document.querySelectorAll('#mappingTable .map-select').forEach(s => {
    m[s.name] = s.value;
  });
  return m;
}

function previewMapping() {
  const mapping = getMapping();
  const ef = entityFields[state.entity] || {};
  // بررسی فیلدهای اجباری
  for (const f of Object.keys(ef.required || {})) {
    if (!mapping[f]) { alert('فیلد اجباری «' + ef.required[f] + '» نگاشت نشده است.'); return; }
  }

  $.ajax({
    url: '?action=preview_map',
    method: 'POST',
    contentType: 'application/json',
    data: JSON.stringify({file_key: state.fileKey, mapping}),
    headers: {'X-Requested-With':'XMLHttpRequest'},
    success: function(d) {
      if (d.error) { alert(d.error); return; }
      const rows = d.preview || [];
      if (!rows.length) { alert('داده‌ای برای پیش‌نمایش یافت نشد'); return; }

      const allFields = Object.values(mapping).filter(Boolean);
      const fieldNames = Object.entries(mapping).filter(([,v]) => v);
      const ef = entityFields[state.entity] || {};
      const allDefs = {...(ef.required||{}), ...(ef.optional||{})};

      let thead = '<tr>' + fieldNames.map(([f]) => `<th>${allDefs[f]||f}</th>`).join('') + '</tr>';
      let tbody = rows.map(row =>
        '<tr>' + fieldNames.map(([f]) => `<td>${row[f]||''}</td>`).join('') + '</tr>'
      ).join('');
      document.getElementById('previewTable').innerHTML =
        `<table class="fin-table"><thead>${thead}</thead><tbody>${tbody}</tbody></table>`;

      document.getElementById('importStats').style.display = 'block';
      document.getElementById('importStats').textContent =
        `آماده واردات: ${state.total} ردیف از فایل شناسایی شد.`;
      goStep(2);
    },
    error: function() { alert('خطا در پیش‌نمایش'); }
  });
}

function runImport() {
  const btn = document.getElementById('importBtn');
  btn.disabled = true; btn.textContent = 'در حال واردات...';

  const mapping = getMapping();
  $.ajax({
    url: '?action=run_import',
    method: 'POST',
    contentType: 'application/json',
    data: JSON.stringify({
      file_key: state.fileKey,
      entity: state.entity,
      mapping,
      _csrf: CSRF,
    }),
    headers: {'X-Requested-With':'XMLHttpRequest','X-CSRF-Token': CSRF},
    success: function(d) {
      btn.disabled = false; btn.textContent = '⬆ شروع واردات';
      if (d.error) { alert(d.error); return; }
      showResult(d);
      goStep(3);
    },
    error: function() {
      btn.disabled = false; btn.textContent = '⬆ شروع واردات';
      alert('خطا در واردات');
    }
  });
}

function showResult(d) {
  const entityLabel = {invoice_sell:'فاکتور فروش',preinvoice:'پیش‌فاکتور',receive_pay:'دریافت/پرداخت',expenses:'هزینه'}[state.entity] || state.entity;
  let html = `
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;">
      <div style="background:#f0fdf4;border-radius:10px;padding:16px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#15803d;">${d.imported||0}</div>
        <div style="font-size:.8rem;color:#166534;margin-top:4px;">وارد شد</div>
      </div>
      <div style="background:#fffbeb;border-radius:10px;padding:16px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#92400e;">${d.skipped||0}</div>
        <div style="font-size:.8rem;color:#92400e;margin-top:4px;">رد شد (تکراری/ناقص)</div>
      </div>
      <div style="background:#fef2f2;border-radius:10px;padding:16px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#b91c1c;">${(d.errors||[]).length}</div>
        <div style="font-size:.8rem;color:#b91c1c;margin-top:4px;">خطا</div>
      </div>
    </div>`;

  if (d.imported > 0) {
    const links = {
      invoice_sell: '../../admin/fin_invoice_sell.php',
      preinvoice:   '../../admin/fin_preinvoice.php',
      receive_pay:  '../../admin/fin_transactions.php',
      expenses:     '../../admin/fin_expenses.php',
    };
    html += `<div style="margin-bottom:16px;">
      <a href="${links[state.entity]||'#'}" class="fin-btn fin-btn-primary">
        مشاهده ${entityLabel}‌های وارد شده ←
      </a>
    </div>`;
  }

  if (d.errors && d.errors.length) {
    html += `<div style="background:#fef2f2;border-radius:8px;padding:14px;max-height:200px;overflow-y:auto;">
      <div style="font-weight:700;color:#b91c1c;margin-bottom:8px;font-size:.82rem;">جزئیات خطاها:</div>`;
    d.errors.forEach(err => {
      html += `<div style="font-size:.78rem;color:#7f1d1d;padding:3px 0;border-bottom:1px solid #fecaca;">${err}</div>`;
    });
    html += '</div>';
  }

  document.getElementById('resultBody').innerHTML = html;
}

function goStep(n) {
  for (let i = 0; i < 4; i++) {
    document.getElementById('step' + i).style.display = i === n ? '' : 'none';
    const ws = document.getElementById('wstep' + i);
    ws.style.background = i === n ? '#1e40af' : '#f8fafc';
    ws.style.color = i === n ? '#fff' : '#94a3b8';
  }
}

// اعمال رنگ کارت انتخاب‌شده
$(document).on('change', 'input[name="entity"]', function() {});
</script>

<?php require_once $root . '/templates/footer.php'; ?>
