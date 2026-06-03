<?php
/*
 * فایل: public_html/admin/fin_receive_pay.php
 * توضیحات: ماژول دریافت از مشتری و پرداخت به تامین‌کننده
 * با ثبت خودکار سند حسابداری دوطرفه (ثبت مستقیم بدون وابستگی به accounting.php)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ── احراز هویت ────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'نشست منقضی شده است.']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

$userId  = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// ── بررسی دسترسی ──────────────────────────────────────────────
$hasAccess = false;
$stmtChk   = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData  = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    if ($roleData['name'] === 'admin' || $roleData['is_system'] == 1) {
        $hasAccess = true;
    } else {
        $perms = json_decode($roleData['permissions'], true) ?? [];
        if (in_array('fin_receive_pay', $perms) || in_array('fin_accounting', $perms) || in_array('all', $perms)) {
            $hasAccess = true;
        }
    }
}
$roleName = strtolower(trim($roleData['name'] ?? $rawRole));
$financeRoles = ['admin', 'management', 'manager', 'finance_manager', 'finance_expert', 'accountant'];
if (in_array($roleName, $financeRoles) || in_array($rawRole, ['1', '2', '5', '10', '14'])) {
    $hasAccess = true;
}

if (!$hasAccess) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'دسترسی غیرمجاز']);
        exit;
    }
    die('<div style="text-align:center;padding:60px;font-family:Tahoma;color:#e11d48;font-weight:bold;">⛔ دسترسی غیرمجاز.</div>');
}

// ── سال مالی فعال ──────────────────────────────────────────────
$fiscalYear   = null;
$fiscalYearId = null;
try {
    $fyStmt = $pdo->query("SELECT id FROM fiscal_years WHERE is_current = 1 AND status = 'active' LIMIT 1");
    $fyRow  = $fyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fyRow) {
        // تلاش با ستون head
        $fyStmt2 = $pdo->query("SELECT id FROM fiscal_years WHERE head = 1 LIMIT 1");
        $fyRow   = $fyStmt2->fetch(PDO::FETCH_ASSOC);
    }
    if (!$fyRow) {
        $fyStmt3 = $pdo->query("SELECT id FROM fiscal_years ORDER BY id DESC LIMIT 1");
        $fyRow   = $fyStmt3->fetch(PDO::FETCH_ASSOC);
    }
    $fiscalYearId = $fyRow ? (int)$fyRow['id'] : null;
} catch (Throwable $e) {
    $fiscalYearId = null;
}

// ── تابع کمکی: دریافت شماره سند جدید ──────────────────────────
function nextDocNumber(PDO $pdo): string {
    try {
        $stmt = $pdo->query("SELECT MAX(CAST(doc_number AS UNSIGNED)) FROM fin_docs WHERE doc_number REGEXP '^[0-9]+$'");
        $max  = (int)$stmt->fetchColumn();
        return (string)($max + 1);
    } catch (Throwable $e) {
        return (string)time();
    }
}

// ── تابع: تبدیل تاریخ جلالی به میلادی ────────────────────────
function jalaliToGregorianSafe(string $jDate): ?string {
    $jDate = trim(str_replace(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
                              ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'], $jDate));
    if (!preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $jDate, $m)) return null;
    if (function_exists('jalali_to_gregorian')) {
        $g = jalali_to_gregorian((int)$m[1], (int)$m[2], (int)$m[3]);
        return sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════
// پردازش درخواست‌های AJAX
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = trim($_POST['action'] ?? '');

    // ── لیست اسناد دریافت/پرداخت ──────────────────────────────
    if ($action === 'list') {
        $docType = in_array($_POST['doc_type'] ?? '', ['sell_receive', 'buy_pay']) ? $_POST['doc_type'] : 'sell_receive';
        $page    = max(1, (int)($_POST['page'] ?? 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        try {
            $cntStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM fin_docs WHERE type = ? AND is_deleted = 0"
            );
            $cntStmt->execute([$docType]);
            $total = (int)$cntStmt->fetchColumn();

            $rows = $pdo->prepare(
                "SELECT d.id, d.doc_number, d.doc_date, d.description, d.created_at,
                        COALESCE(p.company_name, p.name, '') AS person_name,
                        COALESCE(SUM(dr.bd), 0) AS total_amount,
                        ba.bank_name,
                        cd.name AS cashdesk_name
                 FROM fin_docs d
                 LEFT JOIN fin_doc_rows dr ON dr.doc_id = d.id AND dr.bd > 0
                 LEFT JOIN fin_persons p ON p.id = dr.person_id
                 LEFT JOIN fin_bank_accounts ba ON ba.id = dr.bank_id
                 LEFT JOIN fin_cashdesks cd ON cd.id = dr.cashdesk_id
                 WHERE d.type = ? AND d.is_deleted = 0
                 GROUP BY d.id
                 ORDER BY d.id DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $rows->execute([$docType]);
            $docs = $rows->fetchAll(PDO::FETCH_ASSOC);

            foreach ($docs as &$doc) {
                $doc['date_jalali']  = $doc['doc_date'] ? jdate('Y/m/d', $doc['doc_date']) : '';
                $doc['amount_fmt']   = number_format((float)$doc['total_amount']);
                $doc['payment_method'] = $doc['bank_name'] ?: ($doc['cashdesk_name'] ?: '—');
            }
            unset($doc);

            echo json_encode(['ok' => true, 'docs' => $docs, 'total' => $total, 'pages' => max(1, ceil($total / $perPage))]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'خطا: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── دریافت فاکتورهای باز یک شخص ───────────────────────────
    if ($action === 'get_person_invoices') {
        $personId  = (int)($_POST['person_id'] ?? 0);
        $invType   = ($_POST['inv_type'] ?? 'sell') === 'buy' ? 'buy' : 'sell';

        if (!$personId) {
            echo json_encode(['ok' => false, 'msg' => 'شناسه طرف حساب معتبر نیست.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, invoice_number, invoice_date, total_amount, paid_amount,
                        (total_amount - paid_amount) AS remaining
                 FROM fin_invoices
                 WHERE person_id = ? AND type = ? AND is_deleted = 0
                   AND paid_amount < total_amount
                 ORDER BY id DESC
                 LIMIT 50"
            );
            $stmt->execute([$personId, $invType]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($invoices as &$inv) {
                $inv['date_jalali']     = $inv['invoice_date'] ? jdate('Y/m/d', $inv['invoice_date']) : '';
                $inv['total_fmt']       = number_format((float)$inv['total_amount']);
                $inv['paid_fmt']        = number_format((float)$inv['paid_amount']);
                $inv['remaining_fmt']   = number_format((float)$inv['remaining']);
                $inv['total_amount']    = (float)$inv['total_amount'];
                $inv['paid_amount']     = (float)$inv['paid_amount'];
                $inv['remaining']       = (float)$inv['remaining'];
            }
            unset($inv);

            echo json_encode(['ok' => true, 'invoices' => $invoices]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'خطا: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── ثبت دریافت از مشتری ────────────────────────────────────
    if ($action === 'save_receive') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['ok' => false, 'msg' => 'توکن CSRF نامعتبر است.']);
            exit;
        }

        $personId     = (int)($_POST['person_id']    ?? 0);
        $invoiceId    = (int)($_POST['invoice_id']   ?? 0);
        $amount       = abs((float)str_replace([',', ' '], '', faToEn($_POST['amount'] ?? '0')));
        $payMethod    = ($_POST['payment_method'] ?? '') === 'cashdesk' ? 'cashdesk' : 'bank';
        $bankId       = (int)($_POST['bank_id']       ?? 0);
        $cashdeskId   = (int)($_POST['cashdesk_id']   ?? 0);
        $docDateJalali = trim($_POST['doc_date']      ?? '');
        $description   = trim($_POST['description']   ?? '');

        // اعتبارسنجی
        if (!$personId || $amount <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'اطلاعات ناقص است. شخص و مبلغ الزامی هستند.']);
            exit;
        }
        if ($payMethod === 'bank' && !$bankId) {
            echo json_encode(['ok' => false, 'msg' => 'حساب بانکی انتخاب نشده است.']);
            exit;
        }
        if ($payMethod === 'cashdesk' && !$cashdeskId) {
            echo json_encode(['ok' => false, 'msg' => 'صندوق انتخاب نشده است.']);
            exit;
        }

        $docDateGreg = $docDateJalali ? jalaliToGregorianSafe($docDateJalali) : null;
        if (!$docDateGreg) $docDateGreg = date('Y-m-d');

        // بررسی مانده فاکتور
        if ($invoiceId) {
            $invCheck = $pdo->prepare("SELECT total_amount, paid_amount FROM fin_invoices WHERE id = ? AND is_deleted = 0");
            $invCheck->execute([$invoiceId]);
            $invRow = $invCheck->fetch(PDO::FETCH_ASSOC);
            if ($invRow) {
                $remaining = (float)$invRow['total_amount'] - (float)$invRow['paid_amount'];
                if ($amount > $remaining + 0.01) {
                    echo json_encode(['ok' => false, 'msg' => 'مبلغ وارد شده (' . number_format($amount) . ') از مانده فاکتور (' . number_format($remaining) . ') بیشتر است.']);
                    exit;
                }
            }
        }

        try {
            $pdo->beginTransaction();

            // یافتن کدهای حساب مورد نیاز
            // حساب دریافتنی (کد ۳)
            $acctReceivable = $pdo->prepare("SELECT id FROM fin_chart_of_accounts WHERE code = '3' LIMIT 1");
            $acctReceivable->execute();
            $receivableId = $acctReceivable->fetchColumn();

            // حساب بانک (کد ۵) یا صندوق (کد ۱۲۱)
            if ($payMethod === 'bank') {
                $acctCash = $pdo->prepare("SELECT id FROM fin_chart_of_accounts WHERE code = '5' LIMIT 1");
                $acctCash->execute();
                $cashAccId = $acctCash->fetchColumn();
            } else {
                $acctCash = $pdo->prepare("SELECT id FROM fin_chart_of_accounts WHERE code = '121' LIMIT 1");
                $acctCash->execute();
                $cashAccId = $acctCash->fetchColumn();
            }

            // شماره سند جدید
            $docNumber = nextDocNumber($pdo);

            if (!$description) {
                $description = 'دریافت از مشتری' . ($invoiceId ? ' — فاکتور #' . $invoiceId : '');
            }

            // درج سند اصلی
            $insDoc = $pdo->prepare(
                "INSERT INTO fin_docs (doc_number, doc_date, type, description, fiscal_year_id, ref_id, ref_type, created_by, is_deleted)
                 VALUES (?, ?, 'sell_receive', ?, ?, ?, 'invoice', ?, 0)"
            );
            $insDoc->execute([$docNumber, $docDateGreg, $description, $fiscalYearId, $invoiceId ?: null, $userId]);
            $docId = (int)$pdo->lastInsertId();

            // ردیف بدهکار: حساب بانک/صندوق
            $insRow1 = $pdo->prepare(
                "INSERT INTO fin_doc_rows (doc_id, account_id, bd, bs, description, person_id, bank_id, cashdesk_id)
                 VALUES (?, ?, ?, 0, ?, NULL, ?, ?)"
            );
            $insRow1->execute([
                $docId,
                $cashAccId ?: 0,
                $amount,
                $description,
                $payMethod === 'bank' ? $bankId : null,
                $payMethod === 'cashdesk' ? $cashdeskId : null
            ]);

            // ردیف بستانکار: حساب دریافتنی
            $insRow2 = $pdo->prepare(
                "INSERT INTO fin_doc_rows (doc_id, account_id, bd, bs, description, person_id, bank_id, cashdesk_id)
                 VALUES (?, ?, 0, ?, ?, ?, NULL, NULL)"
            );
            $insRow2->execute([$docId, $receivableId ?: 0, $amount, $description, $personId]);

            // به‌روزرسانی پرداخت فاکتور
            if ($invoiceId) {
                $updInv = $pdo->prepare(
                    "UPDATE fin_invoices SET paid_amount = paid_amount + ?,
                        status = CASE
                            WHEN (paid_amount + ?) >= total_amount THEN 'paid'
                            WHEN (paid_amount + ?) > 0              THEN 'partial'
                            ELSE status
                        END,
                        updated_at = NOW()
                     WHERE id = ? AND is_deleted = 0"
                );
                $updInv->execute([$amount, $amount, $amount, $invoiceId]);
            }

            // به‌روزرسانی موجودی بانک/صندوق
            if ($payMethod === 'bank' && $bankId) {
                $pdo->prepare("UPDATE fin_bank_accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $bankId]);
            } elseif ($payMethod === 'cashdesk' && $cashdeskId) {
                $pdo->prepare("UPDATE fin_cashdesks SET balance = balance + ? WHERE id = ?")->execute([$amount, $cashdeskId]);
            }

            $pdo->commit();

            // لاگ فعالیت
            if (function_exists('logActivity')) {
                logActivity($userId, 'fin_receive_pay', 'receive', $docId, 'ثبت دریافت — سند #' . $docNumber . ' مبلغ ' . number_format($amount));
            }

            echo json_encode(['ok' => true, 'msg' => 'دریافت با موفقیت ثبت شد. شماره سند: ' . $docNumber, 'doc_id' => $docId, 'doc_number' => $docNumber]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'خطای سیستم: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── ثبت پرداخت به تامین‌کننده ──────────────────────────────
    if ($action === 'save_pay') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['ok' => false, 'msg' => 'توکن CSRF نامعتبر است.']);
            exit;
        }

        $personId      = (int)($_POST['person_id']    ?? 0);
        $invoiceId     = (int)($_POST['invoice_id']   ?? 0);
        $amount        = abs((float)str_replace([',', ' '], '', faToEn($_POST['amount'] ?? '0')));
        $payMethod     = ($_POST['payment_method'] ?? '') === 'cashdesk' ? 'cashdesk' : 'bank';
        $bankId        = (int)($_POST['bank_id']       ?? 0);
        $cashdeskId    = (int)($_POST['cashdesk_id']   ?? 0);
        $docDateJalali  = trim($_POST['doc_date']      ?? '');
        $description    = trim($_POST['description']   ?? '');

        if (!$personId || $amount <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'اطلاعات ناقص است. شخص و مبلغ الزامی هستند.']);
            exit;
        }
        if ($payMethod === 'bank' && !$bankId) {
            echo json_encode(['ok' => false, 'msg' => 'حساب بانکی انتخاب نشده است.']);
            exit;
        }
        if ($payMethod === 'cashdesk' && !$cashdeskId) {
            echo json_encode(['ok' => false, 'msg' => 'صندوق انتخاب نشده است.']);
            exit;
        }

        $docDateGreg = $docDateJalali ? jalaliToGregorianSafe($docDateJalali) : null;
        if (!$docDateGreg) $docDateGreg = date('Y-m-d');

        if ($invoiceId) {
            $invCheck = $pdo->prepare("SELECT total_amount, paid_amount FROM fin_invoices WHERE id = ? AND is_deleted = 0");
            $invCheck->execute([$invoiceId]);
            $invRow = $invCheck->fetch(PDO::FETCH_ASSOC);
            if ($invRow) {
                $remaining = (float)$invRow['total_amount'] - (float)$invRow['paid_amount'];
                if ($amount > $remaining + 0.01) {
                    echo json_encode(['ok' => false, 'msg' => 'مبلغ وارد شده (' . number_format($amount) . ') از مانده فاکتور (' . number_format($remaining) . ') بیشتر است.']);
                    exit;
                }
            }
        }

        try {
            $pdo->beginTransaction();

            // حساب پرداختنی (کد ۸)
            $acctPayable = $pdo->prepare("SELECT id FROM fin_chart_of_accounts WHERE code = '8' LIMIT 1");
            $acctPayable->execute();
            $payableId = $acctPayable->fetchColumn();

            // حساب بانک (کد ۵) یا صندوق (کد ۱۲۱)
            if ($payMethod === 'bank') {
                $acctCash = $pdo->prepare("SELECT id FROM fin_chart_of_accounts WHERE code = '5' LIMIT 1");
                $acctCash->execute();
                $cashAccId = $acctCash->fetchColumn();
            } else {
                $acctCash = $pdo->prepare("SELECT id FROM fin_chart_of_accounts WHERE code = '121' LIMIT 1");
                $acctCash->execute();
                $cashAccId = $acctCash->fetchColumn();
            }

            $docNumber = nextDocNumber($pdo);

            if (!$description) {
                $description = 'پرداخت به تامین‌کننده' . ($invoiceId ? ' — فاکتور #' . $invoiceId : '');
            }

            $insDoc = $pdo->prepare(
                "INSERT INTO fin_docs (doc_number, doc_date, type, description, fiscal_year_id, ref_id, ref_type, created_by, is_deleted)
                 VALUES (?, ?, 'buy_pay', ?, ?, ?, 'invoice', ?, 0)"
            );
            $insDoc->execute([$docNumber, $docDateGreg, $description, $fiscalYearId, $invoiceId ?: null, $userId]);
            $docId = (int)$pdo->lastInsertId();

            // ردیف بدهکار: حساب پرداختنی
            $insRow1 = $pdo->prepare(
                "INSERT INTO fin_doc_rows (doc_id, account_id, bd, bs, description, person_id, bank_id, cashdesk_id)
                 VALUES (?, ?, ?, 0, ?, ?, NULL, NULL)"
            );
            $insRow1->execute([$docId, $payableId ?: 0, $amount, $description, $personId]);

            // ردیف بستانکار: حساب بانک/صندوق
            $insRow2 = $pdo->prepare(
                "INSERT INTO fin_doc_rows (doc_id, account_id, bd, bs, description, person_id, bank_id, cashdesk_id)
                 VALUES (?, ?, 0, ?, ?, NULL, ?, ?)"
            );
            $insRow2->execute([
                $docId,
                $cashAccId ?: 0,
                $amount,
                $description,
                $payMethod === 'bank' ? $bankId : null,
                $payMethod === 'cashdesk' ? $cashdeskId : null
            ]);

            // به‌روزرسانی پرداخت فاکتور
            if ($invoiceId) {
                $updInv = $pdo->prepare(
                    "UPDATE fin_invoices SET paid_amount = paid_amount + ?,
                        status = CASE
                            WHEN (paid_amount + ?) >= total_amount THEN 'paid'
                            WHEN (paid_amount + ?) > 0              THEN 'partial'
                            ELSE status
                        END,
                        updated_at = NOW()
                     WHERE id = ? AND is_deleted = 0"
                );
                $updInv->execute([$amount, $amount, $amount, $invoiceId]);
            }

            // کسر از موجودی بانک/صندوق
            if ($payMethod === 'bank' && $bankId) {
                $pdo->prepare("UPDATE fin_bank_accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $bankId]);
            } elseif ($payMethod === 'cashdesk' && $cashdeskId) {
                $pdo->prepare("UPDATE fin_cashdesks SET balance = balance - ? WHERE id = ?")->execute([$amount, $cashdeskId]);
            }

            $pdo->commit();

            if (function_exists('logActivity')) {
                logActivity($userId, 'fin_receive_pay', 'pay', $docId, 'ثبت پرداخت — سند #' . $docNumber . ' مبلغ ' . number_format($amount));
            }

            echo json_encode(['ok' => true, 'msg' => 'پرداخت با موفقیت ثبت شد. شماره سند: ' . $docNumber, 'doc_id' => $docId, 'doc_number' => $docNumber]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'خطای سیستم: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── حذف سند (soft delete) ──────────────────────────────────
    if ($action === 'delete_doc') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['ok' => false, 'msg' => 'توکن CSRF نامعتبر است.']);
            exit;
        }

        $docId = (int)($_POST['doc_id'] ?? 0);
        if (!$docId) {
            echo json_encode(['ok' => false, 'msg' => 'شناسه سند نامعتبر است.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // دریافت اطلاعات سند برای برگشت موجودی
            $docInfo = $pdo->prepare("SELECT type, ref_id FROM fin_docs WHERE id = ? AND is_deleted = 0");
            $docInfo->execute([$docId]);
            $doc = $docInfo->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'msg' => 'سند یافت نشد یا قبلاً حذف شده است.']);
                exit;
            }

            // مبلغ ردیف بدهکار اول
            $rowInfo = $pdo->prepare("SELECT bd, bank_id, cashdesk_id FROM fin_doc_rows WHERE doc_id = ? AND bd > 0 LIMIT 1");
            $rowInfo->execute([$docId]);
            $row = $rowInfo->fetch(PDO::FETCH_ASSOC);

            // حذف نرم
            $pdo->prepare("UPDATE fin_docs SET is_deleted = 1 WHERE id = ?")->execute([$docId]);

            // برگشت موجودی بانک/صندوق
            if ($row) {
                $amt = (float)$row['bd'];
                if ($doc['type'] === 'sell_receive') {
                    if ($row['bank_id']) {
                        $pdo->prepare("UPDATE fin_bank_accounts SET balance = balance - ? WHERE id = ?")->execute([$amt, $row['bank_id']]);
                    } elseif ($row['cashdesk_id']) {
                        $pdo->prepare("UPDATE fin_cashdesks SET balance = balance - ? WHERE id = ?")->execute([$amt, $row['cashdesk_id']]);
                    }
                    // برگشت پرداخت فاکتور
                    if ($doc['ref_id']) {
                        $updInv = $pdo->prepare(
                            "UPDATE fin_invoices SET paid_amount = GREATEST(0, paid_amount - ?),
                                status = CASE
                                    WHEN GREATEST(0, paid_amount - ?) = 0           THEN 'draft'
                                    WHEN GREATEST(0, paid_amount - ?) < total_amount THEN 'partial'
                                    ELSE status
                                END,
                                updated_at = NOW()
                             WHERE id = ? AND is_deleted = 0"
                        );
                        $updInv->execute([$amt, $amt, $amt, $doc['ref_id']]);
                    }
                } elseif ($doc['type'] === 'buy_pay') {
                    if ($row['bank_id']) {
                        $pdo->prepare("UPDATE fin_bank_accounts SET balance = balance + ? WHERE id = ?")->execute([$amt, $row['bank_id']]);
                    } elseif ($row['cashdesk_id']) {
                        $pdo->prepare("UPDATE fin_cashdesks SET balance = balance + ? WHERE id = ?")->execute([$amt, $row['cashdesk_id']]);
                    }
                    if ($doc['ref_id']) {
                        $updInv = $pdo->prepare(
                            "UPDATE fin_invoices SET paid_amount = GREATEST(0, paid_amount - ?),
                                status = CASE
                                    WHEN GREATEST(0, paid_amount - ?) = 0           THEN 'draft'
                                    WHEN GREATEST(0, paid_amount - ?) < total_amount THEN 'partial'
                                    ELSE status
                                END,
                                updated_at = NOW()
                             WHERE id = ? AND is_deleted = 0"
                        );
                        $updInv->execute([$amt, $amt, $amt, $doc['ref_id']]);
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => 'سند با موفقیت حذف شد.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'خطای سیستم: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── مشاهده جزئیات سند ─────────────────────────────────────
    if ($action === 'view_doc') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        if (!$docId) {
            echo json_encode(['ok' => false, 'msg' => 'شناسه نامعتبر']);
            exit;
        }
        try {
            $docStmt = $pdo->prepare(
                "SELECT d.*, COALESCE(u.fullname, u.username, 'سیستم') AS creator_name
                 FROM fin_docs d
                 LEFT JOIN users u ON u.id = d.created_by
                 WHERE d.id = ? AND d.is_deleted = 0"
            );
            $docStmt->execute([$docId]);
            $docData = $docStmt->fetch(PDO::FETCH_ASSOC);

            if (!$docData) {
                echo json_encode(['ok' => false, 'msg' => 'سند یافت نشد.']);
                exit;
            }

            $rowsStmt = $pdo->prepare(
                "SELECT dr.*,
                        COALESCE(coa.name,'') AS account_name,
                        COALESCE(coa.code,'') AS account_code,
                        COALESCE(p.company_name, p.name,'') AS person_name,
                        COALESCE(ba.bank_name,'') AS bank_name,
                        COALESCE(cd.name,'') AS cashdesk_name
                 FROM fin_doc_rows dr
                 LEFT JOIN fin_chart_of_accounts coa ON coa.id = dr.account_id
                 LEFT JOIN fin_persons p ON p.id = dr.person_id
                 LEFT JOIN fin_bank_accounts ba ON ba.id = dr.bank_id
                 LEFT JOIN fin_cashdesks cd ON cd.id = dr.cashdesk_id
                 WHERE dr.doc_id = ?
                 ORDER BY dr.id"
            );
            $rowsStmt->execute([$docId]);
            $docRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            $docData['date_jalali'] = $docData['doc_date'] ? jdate('Y/m/d', $docData['doc_date']) : '';
            foreach ($docRows as &$r) {
                $r['bd_fmt'] = $r['bd'] > 0 ? number_format((float)$r['bd']) : '—';
                $r['bs_fmt'] = $r['bs'] > 0 ? number_format((float)$r['bs']) : '—';
            }
            unset($r);

            echo json_encode(['ok' => true, 'doc' => $docData, 'rows' => $docRows]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'خطا: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'عملیات نامشخص']);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// داده‌های اولیه صفحه
// ═══════════════════════════════════════════════════════════════

// آمار امروز
$todayGreg = date('Y-m-d');
try {
    $stmtReceive = $pdo->prepare(
        "SELECT COALESCE(SUM(dr.bd),0) FROM fin_docs d
         JOIN fin_doc_rows dr ON dr.doc_id = d.id AND dr.bd > 0
         WHERE d.type = 'sell_receive' AND d.doc_date = ? AND d.is_deleted = 0"
    );
    $stmtReceive->execute([$todayGreg]);
    $todayReceive = (float)$stmtReceive->fetchColumn();

    $stmtPay = $pdo->prepare(
        "SELECT COALESCE(SUM(dr.bs),0) FROM fin_docs d
         JOIN fin_doc_rows dr ON dr.doc_id = d.id AND dr.bs > 0
         WHERE d.type = 'buy_pay' AND d.doc_date = ? AND d.is_deleted = 0"
    );
    $stmtPay->execute([$todayGreg]);
    $todayPay = (float)$stmtPay->fetchColumn();

    $openInvoices = $pdo->query(
        "SELECT COUNT(*) FROM fin_invoices WHERE is_deleted = 0 AND paid_amount < total_amount AND total_amount > 0"
    )->fetchColumn();
} catch (Throwable $e) {
    $todayReceive = 0;
    $todayPay     = 0;
    $openInvoices = 0;
}

// اشخاص
try {
    $customers  = $pdo->query("SELECT id, COALESCE(company_name, name) AS label FROM fin_persons WHERE (type='customer' OR type='both') AND is_deleted=0 ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
    $suppliers  = $pdo->query("SELECT id, COALESCE(company_name, name) AS label FROM fin_persons WHERE (type='supplier' OR type='both') AND is_deleted=0 ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $customers = [];
    $suppliers = [];
}

// بانک‌ها و صندوق‌ها
try {
    $banks     = $pdo->query("SELECT id, bank_name, account_number, balance FROM fin_bank_accounts WHERE is_active=1 ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);
    $cashdesks = $pdo->query("SELECT id, name, balance FROM fin_cashdesks WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $banks     = [];
    $cashdesks = [];
}

$todayJalali = jdate('Y/m/d');
$csrfField   = csrf_field();

$basePath = '../../';

// ─── رندر HTML ────────────────────────────────────────────────
$pageTitle = 'دریافت و پرداخت';
include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="<?= $basePath ?>assets/css/fin_module.css">
<style>
/* ── افزوده‌های اختصاصی این صفحه ── */
.rp-tabs { display:flex; gap:8px; margin-bottom:24px; }
.rp-tab  { flex:1; padding:12px 20px; border-radius:10px; border:2px solid var(--fin-border);
           background:#fff; font-family:Vazirmatn,Tahoma,sans-serif; font-size:1rem; font-weight:600;
           cursor:pointer; transition:var(--fin-transition); color:var(--fin-text-muted); }
.rp-tab.active.receive { border-color:#10b981; background:#ecfdf5; color:#059669; }
.rp-tab.active.pay     { border-color:#ef4444; background:#fef2f2; color:#dc2626; }
.rp-tab:not(.active):hover { border-color:#c7d2fe; background:#f5f3ff; color:#4f46e5; }

.rp-drawer { position:fixed; top:0; left:0; width:100%; height:100%; z-index:9000;
             display:none; align-items:center; justify-content:center; }
.rp-drawer.open { display:flex; }
.rp-drawer-overlay { position:absolute; inset:0; background:rgba(15,23,42,.45); backdrop-filter:blur(3px); }
.rp-drawer-panel   { position:relative; z-index:1; background:#fff; border-radius:20px;
                     box-shadow:0 20px 60px rgba(0,0,0,.25); width:min(640px,96vw);
                     max-height:90vh; overflow-y:auto; padding:28px 28px 24px; direction:rtl; }
.rp-drawer-panel h3 { margin:0 0 20px; font-size:1.15rem; font-weight:700; display:flex; align-items:center; gap:8px; }

.rp-inv-table { width:100%; border-collapse:collapse; font-size:.88rem; }
.rp-inv-table th { background:#f8fafc; padding:8px 10px; text-align:right; color:#64748b; font-weight:600;
                   border-bottom:2px solid #e2e8f0; }
.rp-inv-table td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.rp-inv-table tr.selected td { background:#eff6ff; }
.rp-inv-table tr:hover td   { background:#f8fafc; cursor:pointer; }
.rp-inv-table .remaining-cell { color:#ef4444; font-weight:700; }

.rp-radio-group { display:flex; gap:10px; }
.rp-radio-item  { flex:1; }
.rp-radio-item input[type=radio] { display:none; }
.rp-radio-item label { display:flex; align-items:center; justify-content:center; gap:6px;
                       padding:10px 16px; border:2px solid #e2e8f0; border-radius:10px;
                       cursor:pointer; font-weight:600; font-size:.92rem; transition:var(--fin-transition); }
.rp-radio-item input[type=radio]:checked + label.bank-lbl { border-color:#3b82f6; background:#eff6ff; color:#1d4ed8; }
.rp-radio-item input[type=radio]:checked + label.cash-lbl { border-color:#f59e0b; background:#fffbeb; color:#d97706; }

.rp-doc-list { margin-top:4px; }
.rp-doc-row  { display:grid; grid-template-columns:90px 1fr 120px 120px 100px 90px; gap:8px;
               align-items:center; padding:10px 14px; border-radius:10px;
               background:#fff; border:1px solid #f1f5f9; margin-bottom:6px;
               font-size:.88rem; transition:var(--fin-transition); }
.rp-doc-row:hover { border-color:#c7d2fe; background:#f5f3ff; }
.rp-doc-row .doc-num  { font-weight:700; color:#4f46e5; }
.rp-doc-row .doc-date { color:#64748b; }
.rp-doc-row .doc-person { font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.rp-doc-row .doc-amount { font-weight:700; text-align:left; }
.rp-doc-row.receive .doc-amount { color:#059669; }
.rp-doc-row.pay     .doc-amount { color:#dc2626; }
.rp-doc-row .doc-method { color:#64748b; font-size:.8rem; }
.rp-doc-row .doc-actions { display:flex; gap:6px; justify-content:flex-end; }

.stat-today { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px; }
@media(max-width:640px) { .stat-today { grid-template-columns:1fr; }
  .rp-doc-row { grid-template-columns:1fr 1fr; } }

.empty-list { text-align:center; padding:40px; color:#94a3b8; }
.empty-list .empty-ico { font-size:2.5rem; margin-bottom:8px; }

.modal-doc-detail { direction:rtl; }
.modal-doc-detail .doc-header { background:#f8fafc; border-radius:10px; padding:14px 16px; margin-bottom:16px; }
.modal-doc-detail .doc-header p { margin:4px 0; font-size:.92rem; color:#475569; }
.modal-doc-detail .doc-header p strong { color:#1e293b; }
.doc-rows-table { width:100%; border-collapse:collapse; font-size:.88rem; }
.doc-rows-table th { background:#f1f5f9; padding:8px 10px; text-align:right; color:#64748b; font-weight:600; border-bottom:2px solid #e2e8f0; }
.doc-rows-table td { padding:9px 10px; border-bottom:1px solid #f1f5f9; }
.doc-rows-table .credit { color:#059669; font-weight:700; }
.doc-rows-table .debit  { color:#ef4444; font-weight:700; }

.alert-msg { padding:12px 16px; border-radius:10px; font-size:.92rem; margin-bottom:16px; display:none; }
.alert-msg.success { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
.alert-msg.error   { background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; }
.alert-msg.info    { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }

.pagination { display:flex; gap:6px; justify-content:center; margin-top:16px; flex-wrap:wrap; }
.pagination button { width:34px; height:34px; border-radius:8px; border:1px solid #e2e8f0;
                     background:#fff; cursor:pointer; font-family:Vazirmatn,Tahoma,sans-serif;
                     font-weight:600; transition:var(--fin-transition); }
.pagination button:hover, .pagination button.active { background:#4f46e5; color:#fff; border-color:#4f46e5; }

.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:.88rem; font-weight:600; color:#475569; margin-bottom:6px; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; padding:10px 12px; border:1.5px solid #e2e8f0; border-radius:10px;
    font-family:Vazirmatn,Tahoma,sans-serif; font-size:.93rem; color:#1e293b;
    outline:none; transition:var(--fin-transition); background:#fff; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15); }
.form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media(max-width:500px) { .form-grid-2 { grid-template-columns:1fr; } }
.form-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; padding-top:16px; border-top:1px solid #f1f5f9; }

/* پیچ دیالوگ */
.rp-detail-modal { display:none; position:fixed; inset:0; z-index:9500; align-items:center; justify-content:center; }
.rp-detail-modal.open { display:flex; }
.rp-detail-modal .overlay { position:absolute; inset:0; background:rgba(15,23,42,.45); }
.rp-detail-modal .panel   { position:relative; z-index:1; background:#fff; border-radius:20px;
                             box-shadow:0 20px 60px rgba(0,0,0,.25); width:min(580px,96vw);
                             max-height:90vh; overflow-y:auto; padding:28px; direction:rtl; }
</style>

<div class="main-content fin-page" id="mainContent">

  <!-- عنوان صفحه -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <div>
      <h1 style="margin:0;font-size:1.4rem;font-weight:800;color:var(--fin-text);">💳 دریافت و پرداخت</h1>
      <p style="margin:4px 0 0;color:var(--fin-text-muted);font-size:.9rem;">ثبت دریافت از مشتریان و پرداخت به تامین‌کنندگان</p>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="fin-btn" id="btnNewReceive" onclick="openDrawer('receive')">
        ➕ دریافت جدید
      </button>
      <button class="fin-btn" style="background:#fef2f2;color:#dc2626;border-color:#fca5a5;" id="btnNewPay" onclick="openDrawer('pay')">
        ➕ پرداخت جدید
      </button>
    </div>
  </div>

  <!-- کارت‌های آمار امروز -->
  <div class="stat-today">
    <div class="fin-stat-card green">
      <div class="label">دریافتی‌های امروز</div>
      <div class="value"><?= number_format($todayReceive) ?> <small>ریال</small></div>
      <div class="icon">📥</div>
    </div>
    <div class="fin-stat-card rose">
      <div class="label">پرداختی‌های امروز</div>
      <div class="value"><?= number_format($todayPay) ?> <small>ریال</small></div>
      <div class="icon">📤</div>
    </div>
    <div class="fin-stat-card amber">
      <div class="label">فاکتورهای باز</div>
      <div class="value"><?= number_format($openInvoices) ?> <small>فاکتور</small></div>
      <div class="icon">🧾</div>
    </div>
  </div>

  <!-- تب‌ها -->
  <div class="rp-tabs">
    <button class="rp-tab active receive" id="tabReceive" onclick="switchTab('receive')">
      📥 دریافت از مشتری
    </button>
    <button class="rp-tab pay" id="tabPay" onclick="switchTab('pay')">
      📤 پرداخت به تامین‌کننده
    </button>
  </div>

  <!-- پنل هر تب -->
  <div class="fin-panel" id="panelReceive">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <h3 style="margin:0;font-size:1rem;font-weight:700;color:#059669;">📥 تاریخچه دریافت‌ها</h3>
      <button class="fin-btn" style="background:#ecfdf5;color:#059669;border:1px solid #a7f3d0;font-size:.85rem;"
              onclick="loadList('sell_receive',1)">🔄 بارگذاری مجدد</button>
    </div>
    <div id="listReceive" class="rp-doc-list">
      <div class="empty-list"><div class="empty-ico">📭</div><div>در حال بارگذاری...</div></div>
    </div>
    <div class="pagination" id="paginationReceive"></div>
  </div>

  <div class="fin-panel" id="panelPay" style="display:none;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <h3 style="margin:0;font-size:1rem;font-weight:700;color:#dc2626;">📤 تاریخچه پرداخت‌ها</h3>
      <button class="fin-btn" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;font-size:.85rem;"
              onclick="loadList('buy_pay',1)">🔄 بارگذاری مجدد</button>
    </div>
    <div id="listPay" class="rp-doc-list">
      <div class="empty-list"><div class="empty-ico">📭</div><div>در حال بارگذاری...</div></div>
    </div>
    <div class="pagination" id="paginationPay"></div>
  </div>

</div><!-- /main-content -->

<!-- ══════════════════════════════════════════════════════
     دراور ثبت دریافت
══════════════════════════════════════════════════════ -->
<div class="rp-drawer" id="drawerReceive">
  <div class="rp-drawer-overlay" onclick="closeDrawer('receive')"></div>
  <div class="rp-drawer-panel">
    <h3 style="color:#059669;">📥 ثبت دریافت از مشتری</h3>

    <div class="alert-msg" id="alertReceive"></div>

    <form id="formReceive" onsubmit="return false;">
      <?= $csrfField ?>
      <input type="hidden" name="action" value="save_receive">
      <input type="hidden" name="invoice_id" id="rcvInvoiceId" value="">

      <div class="form-group">
        <label>طرف حساب (مشتری) <span style="color:#ef4444">*</span></label>
        <select name="person_id" id="rcvPersonId" onchange="loadInvoices('receive')" required>
          <option value="">— انتخاب کنید —</option>
          <?php foreach ($customers as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="rcvInvoicesWrap" style="display:none;margin-bottom:16px;">
        <label style="display:block;font-size:.88rem;font-weight:600;color:#475569;margin-bottom:8px;">
          فاکتورهای باز — انتخاب برای تسویه:
        </label>
        <div style="border:1.5px solid #e2e8f0;border-radius:10px;overflow:hidden;">
          <table class="rp-inv-table">
            <thead>
              <tr>
                <th>شماره</th>
                <th>تاریخ</th>
                <th>مبلغ کل</th>
                <th>پرداخت‌شده</th>
                <th style="color:#ef4444;">مانده</th>
              </tr>
            </thead>
            <tbody id="rcvInvoicesBody">
              <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:14px;">لطفاً مشتری را انتخاب کنید.</td></tr>
            </tbody>
          </table>
        </div>
        <p style="font-size:.8rem;color:#94a3b8;margin:6px 0 0;">روی هر ردیف کلیک کنید تا انتخاب شود و مبلغ خودکار پر شود.</p>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label>مبلغ دریافتی (ریال) <span style="color:#ef4444">*</span></label>
          <input type="text" name="amount" id="rcvAmount" placeholder="۰" required oninput="formatAmountInput(this)">
        </div>
        <div class="form-group">
          <label>تاریخ دریافت <span style="color:#ef4444">*</span></label>
          <input type="text" name="doc_date" id="rcvDate" value="<?= $todayJalali ?>" placeholder="۱۴۰۵/۰۳/۱۳" required>
        </div>
      </div>

      <div class="form-group">
        <label>روش دریافت <span style="color:#ef4444">*</span></label>
        <div class="rp-radio-group">
          <div class="rp-radio-item">
            <input type="radio" name="payment_method" id="rcvMethodBank" value="bank" checked onchange="togglePayMethod('receive')">
            <label for="rcvMethodBank" class="bank-lbl">🏦 واریز بانکی</label>
          </div>
          <div class="rp-radio-item">
            <input type="radio" name="payment_method" id="rcvMethodCash" value="cashdesk" onchange="togglePayMethod('receive')">
            <label for="rcvMethodCash" class="cash-lbl">💰 صندوق نقدی</label>
          </div>
        </div>
      </div>

      <div id="rcvBankWrap" class="form-group">
        <label>حساب بانکی <span style="color:#ef4444">*</span></label>
        <select name="bank_id" id="rcvBankId">
          <option value="">— انتخاب کنید —</option>
          <?php foreach ($banks as $b): ?>
          <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['bank_name']) ?>
            <?= $b['account_number'] ? ' — ' . htmlspecialchars($b['account_number']) : '' ?>
            (موجودی: <?= number_format($b['balance']) ?> ریال)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="rcvCashWrap" class="form-group" style="display:none;">
        <label>صندوق <span style="color:#ef4444">*</span></label>
        <select name="cashdesk_id" id="rcvCashdeskId">
          <option value="">— انتخاب کنید —</option>
          <?php foreach ($cashdesks as $cd): ?>
          <option value="<?= $cd['id'] ?>"><?= htmlspecialchars($cd['name']) ?>
            (موجودی: <?= number_format($cd['balance']) ?> ریال)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>شرح</label>
        <input type="text" name="description" id="rcvDescription" placeholder="توضیح اختیاری...">
      </div>

      <div class="form-actions">
        <button type="button" class="fin-btn" style="background:#f1f5f9;color:#475569;" onclick="closeDrawer('receive')">انصراف</button>
        <button type="submit" class="fin-btn" id="btnSaveReceive" onclick="submitReceive()">✅ ثبت دریافت</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     دراور ثبت پرداخت
══════════════════════════════════════════════════════ -->
<div class="rp-drawer" id="drawerPay">
  <div class="rp-drawer-overlay" onclick="closeDrawer('pay')"></div>
  <div class="rp-drawer-panel">
    <h3 style="color:#dc2626;">📤 ثبت پرداخت به تامین‌کننده</h3>

    <div class="alert-msg" id="alertPay"></div>

    <form id="formPay" onsubmit="return false;">
      <?= $csrfField ?>
      <input type="hidden" name="action" value="save_pay">
      <input type="hidden" name="invoice_id" id="payInvoiceId" value="">

      <div class="form-group">
        <label>طرف حساب (تامین‌کننده) <span style="color:#ef4444">*</span></label>
        <select name="person_id" id="payPersonId" onchange="loadInvoices('pay')" required>
          <option value="">— انتخاب کنید —</option>
          <?php foreach ($suppliers as $s): ?>
          <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="payInvoicesWrap" style="display:none;margin-bottom:16px;">
        <label style="display:block;font-size:.88rem;font-weight:600;color:#475569;margin-bottom:8px;">
          فاکتورهای خرید باز — انتخاب برای تسویه:
        </label>
        <div style="border:1.5px solid #e2e8f0;border-radius:10px;overflow:hidden;">
          <table class="rp-inv-table">
            <thead>
              <tr>
                <th>شماره</th>
                <th>تاریخ</th>
                <th>مبلغ کل</th>
                <th>پرداخت‌شده</th>
                <th style="color:#ef4444;">مانده</th>
              </tr>
            </thead>
            <tbody id="payInvoicesBody">
              <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:14px;">لطفاً تامین‌کننده را انتخاب کنید.</td></tr>
            </tbody>
          </table>
        </div>
        <p style="font-size:.8rem;color:#94a3b8;margin:6px 0 0;">روی هر ردیف کلیک کنید تا انتخاب شود و مبلغ خودکار پر شود.</p>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label>مبلغ پرداختی (ریال) <span style="color:#ef4444">*</span></label>
          <input type="text" name="amount" id="payAmount" placeholder="۰" required oninput="formatAmountInput(this)">
        </div>
        <div class="form-group">
          <label>تاریخ پرداخت <span style="color:#ef4444">*</span></label>
          <input type="text" name="doc_date" id="payDate" value="<?= $todayJalali ?>" placeholder="۱۴۰۵/۰۳/۱۳" required>
        </div>
      </div>

      <div class="form-group">
        <label>روش پرداخت <span style="color:#ef4444">*</span></label>
        <div class="rp-radio-group">
          <div class="rp-radio-item">
            <input type="radio" name="payment_method" id="payMethodBank" value="bank" checked onchange="togglePayMethod('pay')">
            <label for="payMethodBank" class="bank-lbl">🏦 واریز بانکی</label>
          </div>
          <div class="rp-radio-item">
            <input type="radio" name="payment_method" id="payMethodCash" value="cashdesk" onchange="togglePayMethod('pay')">
            <label for="payMethodCash" class="cash-lbl">💰 صندوق نقدی</label>
          </div>
        </div>
      </div>

      <div id="payBankWrap" class="form-group">
        <label>حساب بانکی <span style="color:#ef4444">*</span></label>
        <select name="bank_id" id="payBankId">
          <option value="">— انتخاب کنید —</option>
          <?php foreach ($banks as $b): ?>
          <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['bank_name']) ?>
            <?= $b['account_number'] ? ' — ' . htmlspecialchars($b['account_number']) : '' ?>
            (موجودی: <?= number_format($b['balance']) ?> ریال)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="payCashWrap" class="form-group" style="display:none;">
        <label>صندوق <span style="color:#ef4444">*</span></label>
        <select name="cashdesk_id" id="payCashdeskId">
          <option value="">— انتخاب کنید —</option>
          <?php foreach ($cashdesks as $cd): ?>
          <option value="<?= $cd['id'] ?>"><?= htmlspecialchars($cd['name']) ?>
            (موجودی: <?= number_format($cd['balance']) ?> ریال)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>شرح</label>
        <input type="text" name="description" id="payDescription" placeholder="توضیح اختیاری...">
      </div>

      <div class="form-actions">
        <button type="button" class="fin-btn" style="background:#f1f5f9;color:#475569;" onclick="closeDrawer('pay')">انصراف</button>
        <button type="submit" class="fin-btn" style="background:#fef2f2;color:#dc2626;border-color:#fca5a5;" id="btnSavePay" onclick="submitPay()">✅ ثبت پرداخت</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     مودال جزئیات سند
══════════════════════════════════════════════════════ -->
<div class="rp-detail-modal" id="modalDocDetail">
  <div class="overlay" onclick="closeDetailModal()"></div>
  <div class="panel modal-doc-detail">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <h3 style="margin:0;font-size:1.1rem;font-weight:700;" id="modalDocTitle">جزئیات سند</h3>
      <button onclick="closeDetailModal()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:#64748b;padding:4px 8px;">✕</button>
    </div>
    <div id="modalDocContent">
      <div style="text-align:center;padding:30px;color:#94a3b8;">در حال بارگذاری...</div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     JavaScript
══════════════════════════════════════════════════════ -->
<script>
/* ── متغیرهای سراسری ────────────────────────────────── */
var currentTab    = 'receive';
var currentPageRcv = 1;
var currentPagePay = 1;
var csrfToken      = <?= json_encode(csrf_token()) ?>;

/* ── فرمت‌دهی اعداد فارسی ────────────────────────────── */
function toPersianNum(n) {
    return String(n).replace(/[0-9]/g, function(d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
}
function toEnNum(s) {
    return String(s)
        .replace(/[۰-۹]/g, function(d) { return d.charCodeAt(0) - 1776; })
        .replace(/[٠-٩]/g, function(d) { return d.charCodeAt(0) - 1632; })
        .replace(/,/g, '');
}
function formatAmountInput(el) {
    var raw = toEnNum(el.value).replace(/[^0-9]/g, '');
    if (!raw) { el.value = ''; return; }
    var num = parseInt(raw, 10);
    el.value = num.toLocaleString('fa-IR');
}
function getAmountRaw(id) {
    return parseFloat(toEnNum(document.getElementById(id).value).replace(/,/g, '')) || 0;
}

/* ── سوئیچ تب ────────────────────────────────────────── */
function switchTab(tab) {
    currentTab = tab;
    document.getElementById('panelReceive').style.display = tab === 'receive' ? '' : 'none';
    document.getElementById('panelPay').style.display     = tab === 'pay'     ? '' : 'none';
    document.getElementById('tabReceive').className = 'rp-tab' + (tab === 'receive' ? ' active receive' : '');
    document.getElementById('tabPay').className     = 'rp-tab' + (tab === 'pay'     ? ' active pay'     : '');
    if (tab === 'receive' && currentPageRcv === 1) loadList('sell_receive', 1);
    if (tab === 'pay'     && currentPagePay === 1) loadList('buy_pay',      1);
}

/* ── باز/بسته کردن دراور ──────────────────────────────── */
function openDrawer(type) {
    resetForm(type);
    document.getElementById(type === 'receive' ? 'drawerReceive' : 'drawerPay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDrawer(type) {
    document.getElementById(type === 'receive' ? 'drawerReceive' : 'drawerPay').classList.remove('open');
    document.body.style.overflow = '';
}
function resetForm(type) {
    if (type === 'receive') {
        document.getElementById('formReceive').reset();
        document.getElementById('rcvInvoiceId').value = '';
        document.getElementById('rcvInvoicesWrap').style.display = 'none';
        showAlert('alertReceive', '', '');
        togglePayMethod('receive');
    } else {
        document.getElementById('formPay').reset();
        document.getElementById('payInvoiceId').value = '';
        document.getElementById('payInvoicesWrap').style.display = 'none';
        showAlert('alertPay', '', '');
        togglePayMethod('pay');
    }
}

/* ── نمایش/پنهان بانک یا صندوق ───────────────────────── */
function togglePayMethod(type) {
    if (type === 'receive') {
        var isCash = document.getElementById('rcvMethodCash').checked;
        document.getElementById('rcvBankWrap').style.display  = isCash ? 'none' : '';
        document.getElementById('rcvCashWrap').style.display  = isCash ? '' : 'none';
    } else {
        var isCash = document.getElementById('payMethodCash').checked;
        document.getElementById('payBankWrap').style.display  = isCash ? 'none' : '';
        document.getElementById('payCashWrap').style.display  = isCash ? '' : 'none';
    }
}

/* ── بارگذاری فاکتورهای باز ─────────────────────────── */
function loadInvoices(type) {
    var personId = document.getElementById(type === 'receive' ? 'rcvPersonId' : 'payPersonId').value;
    var invType  = type === 'receive' ? 'sell' : 'buy';
    var wrapId   = type === 'receive' ? 'rcvInvoicesWrap'  : 'payInvoicesWrap';
    var bodyId   = type === 'receive' ? 'rcvInvoicesBody'  : 'payInvoicesBody';

    if (!personId) {
        document.getElementById(wrapId).style.display = 'none';
        return;
    }

    document.getElementById(bodyId).innerHTML = '<tr><td colspan="5" style="text-align:center;padding:14px;color:#94a3b8;">⏳ در حال بارگذاری...</td></tr>';
    document.getElementById(wrapId).style.display = '';

    $.ajax({
        url: window.location.href,
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_person_invoices', person_id: personId, inv_type: invType, csrf_token: csrfToken },
        success: function(res) {
            if (!res.ok) {
                document.getElementById(bodyId).innerHTML = '<tr><td colspan="5" style="text-align:center;color:#ef4444;padding:14px;">' + res.msg + '</td></tr>';
                return;
            }
            if (!res.invoices || res.invoices.length === 0) {
                document.getElementById(bodyId).innerHTML = '<tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:14px;">هیچ فاکتور بازی یافت نشد.</td></tr>';
                return;
            }
            var html = '';
            res.invoices.forEach(function(inv) {
                html += '<tr data-id="' + inv.id + '" data-remaining="' + inv.remaining + '" data-num="' + inv.invoice_number + '" onclick="selectInvoice(this, \'' + type + '\')">'
                      + '<td><strong>' + inv.invoice_number + '</strong></td>'
                      + '<td>' + (inv.date_jalali || '—') + '</td>'
                      + '<td style="text-align:left;">' + inv.total_fmt + '</td>'
                      + '<td style="text-align:left;">' + inv.paid_fmt + '</td>'
                      + '<td class="remaining-cell" style="text-align:left;">' + inv.remaining_fmt + '</td>'
                      + '</tr>';
            });
            document.getElementById(bodyId).innerHTML = html;
        },
        error: function() {
            document.getElementById(bodyId).innerHTML = '<tr><td colspan="5" style="text-align:center;color:#ef4444;padding:14px;">خطا در ارتباط با سرور.</td></tr>';
        }
    });
}

/* ── انتخاب فاکتور ───────────────────────────────────── */
function selectInvoice(row, type) {
    var tbody = row.parentNode;
    Array.from(tbody.querySelectorAll('tr')).forEach(function(r) { r.classList.remove('selected'); });
    row.classList.add('selected');

    var remaining = parseFloat(row.dataset.remaining) || 0;
    var invId     = row.dataset.id;
    var invNum    = row.dataset.num;

    if (type === 'receive') {
        document.getElementById('rcvInvoiceId').value    = invId;
        document.getElementById('rcvAmount').value       = remaining.toLocaleString('fa-IR');
        document.getElementById('rcvDescription').value  = 'دریافت بابت فاکتور ' + invNum;
    } else {
        document.getElementById('payInvoiceId').value    = invId;
        document.getElementById('payAmount').value       = remaining.toLocaleString('fa-IR');
        document.getElementById('payDescription').value  = 'پرداخت بابت فاکتور ' + invNum;
    }
}

/* ── نمایش پیام ──────────────────────────────────────── */
function showAlert(elId, type, msg) {
    var el = document.getElementById(elId);
    el.className = 'alert-msg ' + type;
    el.innerHTML = msg;
    el.style.display = msg ? 'block' : 'none';
    if (msg) { el.scrollIntoView({ behavior:'smooth', block:'nearest' }); }
}

/* ── ارسال فرم دریافت ─────────────────────────────────── */
function submitReceive() {
    var form    = document.getElementById('formReceive');
    var personId = document.getElementById('rcvPersonId').value;
    var amount   = getAmountRaw('rcvAmount');
    var docDate  = document.getElementById('rcvDate').value;

    if (!personId) { showAlert('alertReceive', 'error', '⚠️ طرف حساب را انتخاب کنید.'); return; }
    if (amount <= 0) { showAlert('alertReceive', 'error', '⚠️ مبلغ معتبر وارد کنید.'); return; }
    if (!docDate)   { showAlert('alertReceive', 'error', '⚠️ تاریخ را وارد کنید.'); return; }

    var isCash = document.getElementById('rcvMethodCash').checked;
    if (!isCash && !document.getElementById('rcvBankId').value) {
        showAlert('alertReceive', 'error', '⚠️ حساب بانکی را انتخاب کنید.');
        return;
    }
    if (isCash && !document.getElementById('rcvCashdeskId').value) {
        showAlert('alertReceive', 'error', '⚠️ صندوق را انتخاب کنید.');
        return;
    }

    var btn = document.getElementById('btnSaveReceive');
    btn.disabled = true;
    btn.textContent = '⏳ در حال ثبت...';

    var data = $(form).serialize();
    // مقدار مبلغ را به‌صورت خام ارسال می‌کنیم
    data += '&amount=' + encodeURIComponent(toEnNum(document.getElementById('rcvAmount').value).replace(/,/g, ''));
    data += '&csrf_token=' + encodeURIComponent(csrfToken);

    $.ajax({
        url: window.location.href,
        method: 'POST',
        dataType: 'json',
        data: data,
        success: function(res) {
            btn.disabled = false;
            btn.textContent = '✅ ثبت دریافت';
            if (res.ok) {
                showAlert('alertReceive', 'success', '✅ ' + res.msg);
                setTimeout(function() {
                    closeDrawer('receive');
                    loadList('sell_receive', currentPageRcv);
                    updateTodayStats();
                }, 1500);
            } else {
                showAlert('alertReceive', 'error', '❌ ' + res.msg);
            }
        },
        error: function() {
            btn.disabled = false;
            btn.textContent = '✅ ثبت دریافت';
            showAlert('alertReceive', 'error', '❌ خطا در ارتباط با سرور.');
        }
    });
}

/* ── ارسال فرم پرداخت ─────────────────────────────────── */
function submitPay() {
    var form    = document.getElementById('formPay');
    var personId = document.getElementById('payPersonId').value;
    var amount   = getAmountRaw('payAmount');
    var docDate  = document.getElementById('payDate').value;

    if (!personId) { showAlert('alertPay', 'error', '⚠️ طرف حساب را انتخاب کنید.'); return; }
    if (amount <= 0) { showAlert('alertPay', 'error', '⚠️ مبلغ معتبر وارد کنید.'); return; }
    if (!docDate)   { showAlert('alertPay', 'error', '⚠️ تاریخ را وارد کنید.'); return; }

    var isCash = document.getElementById('payMethodCash').checked;
    if (!isCash && !document.getElementById('payBankId').value) {
        showAlert('alertPay', 'error', '⚠️ حساب بانکی را انتخاب کنید.');
        return;
    }
    if (isCash && !document.getElementById('payCashdeskId').value) {
        showAlert('alertPay', 'error', '⚠️ صندوق را انتخاب کنید.');
        return;
    }

    var btn = document.getElementById('btnSavePay');
    btn.disabled = true;
    btn.textContent = '⏳ در حال ثبت...';

    var data = $(form).serialize();
    data += '&amount=' + encodeURIComponent(toEnNum(document.getElementById('payAmount').value).replace(/,/g, ''));
    data += '&csrf_token=' + encodeURIComponent(csrfToken);

    $.ajax({
        url: window.location.href,
        method: 'POST',
        dataType: 'json',
        data: data,
        success: function(res) {
            btn.disabled = false;
            btn.textContent = '✅ ثبت پرداخت';
            if (res.ok) {
                showAlert('alertPay', 'success', '✅ ' + res.msg);
                setTimeout(function() {
                    closeDrawer('pay');
                    loadList('buy_pay', currentPagePay);
                    updateTodayStats();
                }, 1500);
            } else {
                showAlert('alertPay', 'error', '❌ ' + res.msg);
            }
        },
        error: function() {
            btn.disabled = false;
            btn.textContent = '✅ ثبت پرداخت';
            showAlert('alertPay', 'error', '❌ خطا در ارتباط با سرور.');
        }
    });
}

/* ── بارگذاری لیست اسناد ─────────────────────────────── */
function loadList(docType, page) {
    var isReceive = docType === 'sell_receive';
    if (isReceive) currentPageRcv = page;
    else           currentPagePay = page;

    var listEl  = document.getElementById(isReceive ? 'listReceive' : 'listPay');
    var pagEl   = document.getElementById(isReceive ? 'paginationReceive' : 'paginationPay');

    listEl.innerHTML = '<div class="empty-list"><div class="empty-ico">⏳</div><div>در حال بارگذاری...</div></div>';

    $.ajax({
        url: window.location.href,
        method: 'POST',
        dataType: 'json',
        data: { action: 'list', doc_type: docType, page: page, csrf_token: csrfToken },
        success: function(res) {
            if (!res.ok) {
                listEl.innerHTML = '<div class="empty-list"><div class="empty-ico">⚠️</div><div>' + res.msg + '</div></div>';
                return;
            }
            if (!res.docs || res.docs.length === 0) {
                listEl.innerHTML = '<div class="empty-list"><div class="empty-ico">📭</div><div>هیچ سندی ثبت نشده است.</div></div>';
                pagEl.innerHTML = '';
                return;
            }

            var cls = isReceive ? 'receive' : 'pay';
            var html = '';
            res.docs.forEach(function(d) {
                html += '<div class="rp-doc-row ' + cls + '">'
                      + '<span class="doc-num">سند #' + escHtml(d.doc_number) + '</span>'
                      + '<span class="doc-date">📅 ' + escHtml(d.date_jalali) + '</span>'
                      + '<span class="doc-person">👤 ' + escHtml(d.person_name || '—') + '</span>'
                      + '<span class="doc-amount">' + escHtml(d.amount_fmt) + ' ریال</span>'
                      + '<span class="doc-method">' + escHtml(d.payment_method) + '</span>'
                      + '<span class="doc-actions">'
                      +   '<button class="fin-btn" style="padding:4px 10px;font-size:.8rem;" onclick="viewDoc(' + d.id + ')">👁 مشاهده</button>'
                      +   '<button class="fin-btn" style="padding:4px 10px;font-size:.8rem;background:#fef2f2;color:#dc2626;border-color:#fca5a5;" onclick="deleteDoc(' + d.id + ',\'' + docType + '\',' + page + ')">🗑 حذف</button>'
                      + '</span>'
                      + '</div>';
            });
            listEl.innerHTML = html;

            // صفحه‌بندی
            var pHtml = '';
            for (var i = 1; i <= res.pages; i++) {
                pHtml += '<button class="' + (i === page ? 'active' : '') + '" onclick="loadList(\'' + docType + '\',' + i + ')">' + toPersianNum(i) + '</button>';
            }
            pagEl.innerHTML = pHtml;
        },
        error: function() {
            listEl.innerHTML = '<div class="empty-list"><div class="empty-ico">❌</div><div>خطا در ارتباط با سرور.</div></div>';
        }
    });
}

/* ── مشاهده جزئیات سند ───────────────────────────────── */
function viewDoc(docId) {
    document.getElementById('modalDocContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;">⏳ در حال بارگذاری...</div>';
    document.getElementById('modalDocDetail').classList.add('open');
    document.body.style.overflow = 'hidden';

    $.ajax({
        url: window.location.href,
        method: 'POST',
        dataType: 'json',
        data: { action: 'view_doc', doc_id: docId, csrf_token: csrfToken },
        success: function(res) {
            if (!res.ok) {
                document.getElementById('modalDocContent').innerHTML = '<p style="color:#ef4444;text-align:center;">' + res.msg + '</p>';
                return;
            }
            var d = res.doc;
            var typeLabel = d.type === 'sell_receive' ? 'دریافت از مشتری' : 'پرداخت به تامین‌کننده';
            document.getElementById('modalDocTitle').textContent = 'جزئیات سند — ' + typeLabel;

            var html = '<div class="doc-header">'
                     + '<p><strong>شماره سند:</strong> ' + escHtml(d.doc_number) + '</p>'
                     + '<p><strong>تاریخ:</strong> ' + escHtml(d.date_jalali) + '</p>'
                     + '<p><strong>نوع:</strong> ' + escHtml(typeLabel) + '</p>'
                     + '<p><strong>شرح:</strong> ' + escHtml(d.description || '—') + '</p>'
                     + '<p><strong>ثبت‌کننده:</strong> ' + escHtml(d.creator_name || '—') + '</p>'
                     + '</div>';

            html += '<table class="doc-rows-table"><thead><tr>'
                  + '<th>حساب</th><th>کد</th><th>طرف حساب</th><th>بدهکار</th><th>بستانکار</th><th>شرح</th>'
                  + '</tr></thead><tbody>';

            res.rows.forEach(function(r) {
                var extra = r.bank_name ? ('🏦 ' + escHtml(r.bank_name)) : (r.cashdesk_name ? ('💰 ' + escHtml(r.cashdesk_name)) : '');
                html += '<tr>'
                      + '<td>' + escHtml(r.account_name) + (extra ? '<br><small style="color:#94a3b8;">' + extra + '</small>' : '') + '</td>'
                      + '<td style="color:#64748b;">' + escHtml(r.account_code) + '</td>'
                      + '<td>' + escHtml(r.person_name || '—') + '</td>'
                      + '<td class="debit">'  + (r.bd_fmt !== '—' ? r.bd_fmt + ' ریال' : '—') + '</td>'
                      + '<td class="credit">' + (r.bs_fmt !== '—' ? r.bs_fmt + ' ریال' : '—') + '</td>'
                      + '<td style="color:#64748b;font-size:.83rem;">' + escHtml(r.description || '—') + '</td>'
                      + '</tr>';
            });
            html += '</tbody></table>';

            document.getElementById('modalDocContent').innerHTML = html;
        },
        error: function() {
            document.getElementById('modalDocContent').innerHTML = '<p style="color:#ef4444;text-align:center;">خطا در ارتباط با سرور.</p>';
        }
    });
}
function closeDetailModal() {
    document.getElementById('modalDocDetail').classList.remove('open');
    document.body.style.overflow = '';
}

/* ── حذف سند ─────────────────────────────────────────── */
function deleteDoc(docId, docType, page) {
    if (!confirm('آیا از حذف این سند اطمینان دارید؟\nحذف سند موجودی بانک/صندوق و وضعیت فاکتور را برمی‌گرداند.')) return;

    $.ajax({
        url: window.location.href,
        method: 'POST',
        dataType: 'json',
        data: { action: 'delete_doc', doc_id: docId, csrf_token: csrfToken },
        success: function(res) {
            if (res.ok) {
                loadList(docType, page);
                updateTodayStats();
            } else {
                alert('❌ ' + res.msg);
            }
        },
        error: function() { alert('خطا در ارتباط با سرور.'); }
    });
}

/* ── به‌روزرسانی آمار امروز ──────────────────────────── */
function updateTodayStats() {
    // در صورت نیاز می‌توان یک endpoint اضافه کرد — فعلاً reload سبک
    setTimeout(function() { location.reload(); }, 2000);
}

/* ── امنیت HTML ──────────────────────────────────────── */
function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── راه‌اندازی اولیه ─────────────────────────────────── */
$(function() {
    loadList('sell_receive', 1);

    // میانبر Escape برای بستن دراور
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDrawer('receive');
            closeDrawer('pay');
            closeDetailModal();
        }
    });
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
