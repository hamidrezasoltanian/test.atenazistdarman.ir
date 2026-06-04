<?php
/*
 * فایل: public_html/admin/fin_reports.php
 * ماژول گزارش‌های مالی — آتنا زیست درمان
 * گزارش‌ها: نمودار فروش | سود و زیان | تراز آزمایشی | دفتر حساب | مطالبات معوق | گزارش چک
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
// بارگذاری PhpSpreadsheet برای خروجی Excel
if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

// بررسی درخواست AJAX
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// بررسی احراز هویت
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'نشست منقضی شده است']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

// ───────────────────────────────────────────────────────────────────
// توابع کمکی
// ───────────────────────────────────────────────────────────────────

/** تبدیل عدد به فرمت فارسی با جداکننده هزار */
function formatMoney(int $amount): string {
    return number_format($amount) . ' تومان';
}

/** گرفتن سال مالی جاری یا مشخص‌شده */
function getFiscalYear(PDO $pdo, ?int $fyId = null): ?array {
    try {
        if ($fyId) {
            $st = $pdo->prepare("SELECT * FROM fiscal_years WHERE id = ? LIMIT 1");
            $st->execute([$fyId]);
        } else {
            $st = $pdo->prepare("SELECT * FROM fiscal_years WHERE is_current = 1 LIMIT 1");
            $st->execute();
        }
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** گرفتن لیست سال‌های مالی */
function getFiscalYears(PDO $pdo): array {
    try {
        $st = $pdo->query("SELECT id, title, is_current FROM fiscal_years ORDER BY id DESC");
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// ───────────────────────────────────────────────────────────────────
// هندلرهای AJAX
// ───────────────────────────────────────────────────────────────────
if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    $action   = $_GET['action'] ?? '';
    $fyId     = (int)($_GET['fiscal_year_id'] ?? 0);

    // ── نمودار فروش ماهانه ─────────────────────────────────────────
    if ($action === 'sales_chart') {
        $result = [
            'labels'      => [],
            'sales'       => [],
            'collected'   => [],
            'outstanding' => [],
            'total_sales' => 0,
            'total_paid'  => 0,
            'invoice_count'=> 0,
        ];
        try {
            $where  = 'type = \'sell\' AND is_deleted = 0';
            $params = [];
            if ($fyId > 0) {
                $where   .= ' AND fiscal_year_id = ?';
                $params[] = $fyId;
            }

            $st = $pdo->prepare(
                "SELECT SUBSTRING(invoice_date, 1, 7) AS month,
                        SUM(total_amount) AS total,
                        SUM(paid_amount)  AS paid,
                        COUNT(id)         AS cnt
                 FROM fin_invoices
                 WHERE $where
                 GROUP BY SUBSTRING(invoice_date, 1, 7)
                 ORDER BY SUBSTRING(invoice_date, 1, 7)"
            );
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $result['labels'][]      = $r['month'];
                $result['sales'][]       = (int)$r['total'];
                $result['collected'][]   = (int)$r['paid'];
                $result['outstanding'][] = (int)$r['total'] - (int)$r['paid'];
                $result['total_sales']  += (int)$r['total'];
                $result['total_paid']   += (int)$r['paid'];
                $result['invoice_count']+= (int)$r['cnt'];
            }
            $result['total_outstanding'] = $result['total_sales'] - $result['total_paid'];
        } catch (Throwable $e) {
            // جدول وجود ندارد یا خطا — داده خالی برمی‌گردد
        }
        echo json_encode(['ok' => true, 'data' => $result]);
        exit;
    }

    // ── سود و زیان ─────────────────────────────────────────────────
    if ($action === 'income_statement') {
        $data = [
            'revenue'        => 0,
            'cost_of_goods'  => 0,
            'gross_profit'   => 0,
            'expenses'       => 0,
            'tax'            => 0,
            'net_profit'     => 0,
            'expense_cats'   => [],
        ];
        try {
            // درآمد فروش خالص
            $params = [];
            $where  = 'type = \'sell\' AND is_deleted = 0';
            if ($fyId > 0) { $where .= ' AND fiscal_year_id = ?'; $params[] = $fyId; }
            $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fin_invoices WHERE $where");
            $st->execute($params);
            $data['revenue'] = (int)$st->fetchColumn();

            // مجموع مالیات از ردیف‌های فاکتور
            try {
                $taxParams = [];
                $taxWhere  = 'i.type = \'sell\' AND i.is_deleted = 0';
                if ($fyId > 0) { $taxWhere .= ' AND i.fiscal_year_id = ?'; $taxParams[] = $fyId; }
                $st2 = $pdo->prepare(
                    "SELECT COALESCE(SUM(it.tax_amt),0)
                     FROM fin_invoice_items it
                     JOIN fin_invoices i ON it.invoice_id = i.id
                     WHERE $taxWhere"
                );
                $st2->execute($taxParams);
                $data['tax'] = (int)$st2->fetchColumn();
            } catch (Throwable $e) {}

            // هزینه‌های عملیاتی از fin_expenses
            try {
                $expParams = [];
                // اگر ستون fiscal_year_id در fin_expenses وجود دارد از آن استفاده می‌شود
                $expCols = [];
                $stCols = $pdo->query("DESCRIBE fin_expenses");
                foreach ($stCols->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    $expCols[] = $col['Field'];
                }

                if (in_array('fiscal_year_id', $expCols) && $fyId > 0) {
                    $expWhere  = 'fiscal_year_id = ?';
                    $expParams = [$fyId];
                } elseif (in_array('created_at', $expCols) && $fyId > 0) {
                    // سعی می‌کنیم از تاریخ سال مالی استفاده کنیم
                    $fy = getFiscalYear($pdo, $fyId);
                    if ($fy) {
                        $expWhere  = 'created_at BETWEEN ? AND ?';
                        $expParams = [$fy['start_date'] ?? '1900-01-01', $fy['end_date'] ?? '2099-12-31'];
                    } else {
                        $expWhere  = '1=1';
                    }
                } else {
                    $expWhere = '1=1';
                }

                $st3 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_expenses WHERE $expWhere");
                $st3->execute($expParams);
                $data['expenses'] = (int)$st3->fetchColumn();

                // دسته‌بندی هزینه‌ها برای نمودار
                if (in_array('category', $expCols)) {
                    $catField = 'category';
                } elseif (in_array('description', $expCols)) {
                    $catField = 'description';
                } else {
                    $catField = NULL;
                }
                if ($catField) {
                    $st4 = $pdo->prepare(
                        "SELECT COALESCE($catField,'سایر') AS cat, COALESCE(SUM(amount),0) AS total
                         FROM fin_expenses WHERE $expWhere
                         GROUP BY $catField ORDER BY total DESC LIMIT 8"
                    );
                    $st4->execute($expParams);
                    $data['expense_cats'] = $st4->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $e) {}

            $data['gross_profit'] = $data['revenue'] - $data['cost_of_goods'];
            $data['net_profit']   = $data['gross_profit'] - $data['expenses'] - $data['tax'];
        } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'data' => $data]);
        exit;
    }

    // ── تراز آزمایشی ───────────────────────────────────────────────
    if ($action === 'trial_balance') {
        $rows = [];
        try {
            $params = [];
            $joinWhere = '';
            if ($fyId > 0) {
                $joinWhere = 'AND d.fiscal_year_id = ?';
                $params[]  = $fyId;
            }
            $st = $pdo->prepare(
                "SELECT a.id, a.code, a.name, a.type,
                        COALESCE(SUM(r.debit),0)              AS total_debit,
                        COALESCE(SUM(r.credit),0)             AS total_credit,
                        COALESCE(SUM(r.debit),0) - COALESCE(SUM(r.credit),0) AS balance
                 FROM fin_chart_of_accounts a
                 LEFT JOIN fin_doc_rows r ON r.account_id = a.id
                 LEFT JOIN fin_docs d ON d.id = r.doc_id $joinWhere
                 GROUP BY a.id
                 ORDER BY a.code"
            );
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ── دفتر حساب (کارت حساب) ──────────────────────────────────────
    if ($action === 'account_ledger') {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $rows      = [];
        if ($accountId > 0) {
            try {
                $params = [$accountId];
                $andFy  = '';
                if ($fyId > 0) { $andFy = 'AND d.fiscal_year_id = ?'; $params[] = $fyId; }
                $st = $pdo->prepare(
                    "SELECT d.doc_number, d.doc_date, d.type,
                            r.debit, r.credit, r.description
                     FROM fin_doc_rows r
                     JOIN fin_docs d ON r.doc_id = d.id
                     WHERE r.account_id = ? $andFy
                     ORDER BY d.doc_date, d.id"
                );
                $st->execute($params);
                $rawRows = $st->fetchAll(PDO::FETCH_ASSOC);

                // محاسبه مانده تجمعی
                $runningBalance = 0;
                foreach ($rawRows as $row) {
                    $runningBalance += (int)$row['debit'] - (int)$row['credit'];
                    $rows[] = [
                        'doc_number'      => $row['doc_number'],
                        'doc_date'        => $row['doc_date'],
                        'type'            => $row['type'],
                        'debit'           => (int)$row['debit'],
                        'credit'          => (int)$row['credit'],
                        'description'     => $row['description'],
                        'running_balance' => $runningBalance,
                    ];
                }
            } catch (Throwable $e) {}
        }
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ── مطالبات معوق (Invoice Aging) ───────────────────────────────
    if ($action === 'invoice_aging') {
        $rows = [];
        try {
            $params = [];
            $andFy  = '';
            if ($fyId > 0) { $andFy = 'AND i.fiscal_year_id = ?'; $params[] = $fyId; }
            $st = $pdo->prepare(
                "SELECT i.invoice_number,
                        COALESCE(p.name, i.customer_name, '') AS customer,
                        i.invoice_date,
                        i.total_amount,
                        i.paid_amount,
                        i.total_amount - i.paid_amount AS outstanding,
                        DATEDIFF(CURDATE(),
                            STR_TO_DATE(
                                CONCAT(REPLACE(SUBSTRING(i.invoice_date,1,7),'/','-'), '-01'),
                                '%Y-%m-%d'
                            )
                        ) AS age_days
                 FROM fin_invoices i
                 LEFT JOIN fin_persons p ON i.person_id = p.id
                 WHERE i.type = 'sell'
                   AND i.paid_amount < i.total_amount
                   AND i.is_deleted = 0
                   $andFy
                 ORDER BY age_days DESC"
            );
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ── گزارش چک ──────────────────────────────────────────────────
    if ($action === 'cheque_report') {
        $type   = $_GET['cheque_type']   ?? 'all';
        $status = $_GET['cheque_status'] ?? '';
        $rows   = [];
        try {
            $params  = [];
            $andType = '';
            $andStat = '';
            if ($type !== 'all' && in_array($type, ['received', 'issued'])) {
                $andType   = 'AND c.type = ?';
                $params[]  = $type;
            }
            if ($status !== '') {
                $andStat   = 'AND c.status = ?';
                $params[]  = $status;
            }

            // بررسی وجود ستون person_name
            $extraName = '';
            try {
                $colSt = $pdo->query("DESCRIBE fin_cheques");
                foreach ($colSt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    if ($col['Field'] === 'person_name') { $extraName = ", c.person_name"; break; }
                }
            } catch (Throwable $e2) {}

            $st = $pdo->prepare(
                "SELECT c.id, c.cheque_number, c.type, c.bank_name,
                        c.amount, c.issue_date, c.due_date, c.status,
                        COALESCE(p.name $extraName) AS pname
                 FROM fin_cheques c
                 LEFT JOIN fin_persons p ON c.person_id = p.id
                 WHERE c.is_deleted = 0
                   $andType
                   $andStat
                 ORDER BY c.due_date"
            );
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ── خروجی CSV ─────────────────────────────────────────────────
    if ($action === 'export_csv') {
        $reportType = $_GET['report_type'] ?? '';
        $fyId       = (int)($_GET['fiscal_year_id'] ?? 0);

        // هدرهای CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="report_' . $reportType . '_' . date('Ymd') . '.csv"');
        // BOM برای نمایش صحیح در اکسل
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');

        try {
            if ($reportType === 'sales') {
                fputcsv($out, ['ماه', 'فروش کل', 'وصولی', 'مانده']);
                $params = [];
                $where  = 'type = \'sell\' AND is_deleted = 0';
                if ($fyId > 0) { $where .= ' AND fiscal_year_id = ?'; $params[] = $fyId; }
                $st = $pdo->prepare(
                    "SELECT SUBSTRING(invoice_date,1,7) AS month, SUM(total_amount) AS total,
                            SUM(paid_amount) AS paid
                     FROM fin_invoices WHERE $where
                     GROUP BY SUBSTRING(invoice_date,1,7) ORDER BY 1"
                );
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    fputcsv($out, [$r['month'], $r['total'], $r['paid'], $r['total']-$r['paid']]);
                }

            } elseif ($reportType === 'trial_balance') {
                fputcsv($out, ['کد', 'نام حساب', 'نوع', 'بدهکار', 'بستانکار', 'مانده']);
                $params    = [];
                $joinWhere = '';
                if ($fyId > 0) { $joinWhere = 'AND d.fiscal_year_id = ?'; $params[] = $fyId; }
                $st = $pdo->prepare(
                    "SELECT a.code, a.name, a.type,
                            COALESCE(SUM(r.debit),0) AS total_debit,
                            COALESCE(SUM(r.credit),0) AS total_credit,
                            COALESCE(SUM(r.debit),0)-COALESCE(SUM(r.credit),0) AS balance
                     FROM fin_chart_of_accounts a
                     LEFT JOIN fin_doc_rows r ON r.account_id=a.id
                     LEFT JOIN fin_docs d ON d.id=r.doc_id $joinWhere
                     GROUP BY a.id ORDER BY a.code"
                );
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    fputcsv($out, [$r['code'], $r['name'], $r['type'], $r['total_debit'], $r['total_credit'], $r['balance']]);
                }

            } elseif ($reportType === 'aging') {
                fputcsv($out, ['شماره فاکتور', 'مشتری', 'تاریخ فاکتور', 'مبلغ کل', 'پرداختی', 'مانده', 'عمر (روز)']);
                $params = [];
                $andFy  = '';
                if ($fyId > 0) { $andFy = 'AND i.fiscal_year_id = ?'; $params[] = $fyId; }
                $st = $pdo->prepare(
                    "SELECT i.invoice_number, COALESCE(p.name,i.customer_name,'') AS customer,
                            i.invoice_date, i.total_amount, i.paid_amount,
                            i.total_amount-i.paid_amount AS outstanding,
                            DATEDIFF(CURDATE(), STR_TO_DATE(CONCAT(REPLACE(SUBSTRING(i.invoice_date,1,7),'/','-'),'-01'),'%Y-%m-%d')) AS age_days
                     FROM fin_invoices i LEFT JOIN fin_persons p ON i.person_id=p.id
                     WHERE i.type='sell' AND i.paid_amount < i.total_amount AND i.is_deleted=0 $andFy
                     ORDER BY age_days DESC"
                );
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    fputcsv($out, [$r['invoice_number'],$r['customer'],$r['invoice_date'],$r['total_amount'],$r['paid_amount'],$r['outstanding'],$r['age_days']]);
                }

            } elseif ($reportType === 'cheques') {
                fputcsv($out, ['شماره چک', 'نوع', 'بانک', 'طرف حساب', 'مبلغ', 'تاریخ صدور', 'تاریخ سررسید', 'وضعیت']);
                $st = $pdo->prepare(
                    "SELECT c.cheque_number, c.type, c.bank_name,
                            COALESCE(p.name,'') AS pname,
                            c.amount, c.issue_date, c.due_date, c.status
                     FROM fin_cheques c LEFT JOIN fin_persons p ON c.person_id=p.id
                     WHERE c.is_deleted=0 ORDER BY c.due_date"
                );
                $st->execute();
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    fputcsv($out, [$r['cheque_number'],$r['type'],$r['bank_name'],$r['pname'],$r['amount'],$r['issue_date'],$r['due_date'],$r['status']]);
                }
            }
        } catch (Throwable $e) {
            fputcsv($out, ['خطا در تهیه گزارش: ' . $e->getMessage()]);
        }
        fclose($out);
        exit;
    }

    // ── خروجی Excel (xlsx) ────────────────────────────────────────
    if ($action === 'export_xlsx') {
        $reportType = $_GET['report_type'] ?? '';
        $fyId       = (int)($_GET['fiscal_year_id'] ?? 0);

        // تابع کمکی: تبدیل شماره ستون (عدد) + شماره سطر به مختصات اکسل مانند A1
        $coord = static function(int $col, int $row): string {
            return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
        };

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // جهت RTL برای محتوای فارسی
        $sheet->setRightToLeft(true);

        try {
            if ($reportType === 'sales') {
                // ── فروش ماهانه ──
                $sheet->setTitle('فروش ماهانه');
                $headers = ['ماه', 'فروش کل', 'وصولی', 'مانده'];
                foreach ($headers as $i => $h) {
                    $sheet->setCellValue($coord($i + 1, 1), $h);
                }
                $sheet->getStyle('A1:D1')->getFont()->setBold(true);

                $params = [];
                $where  = "type = 'sell' AND is_deleted = 0";
                if ($fyId > 0) { $where .= ' AND fiscal_year_id = ?'; $params[] = $fyId; }
                $st = $pdo->prepare(
                    "SELECT SUBSTRING(invoice_date,1,7) AS month, SUM(total_amount) AS total,
                            SUM(paid_amount) AS paid
                     FROM fin_invoices WHERE $where
                     GROUP BY SUBSTRING(invoice_date,1,7) ORDER BY 1"
                );
                $st->execute($params);
                $row = 2;
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $outstanding = (int)$r['total'] - (int)$r['paid'];
                    $sheet->setCellValue($coord(1, $row), $r['month']);
                    $sheet->setCellValue($coord(2, $row), (int)$r['total']);
                    $sheet->setCellValue($coord(3, $row), (int)$r['paid']);
                    $sheet->setCellValue($coord(4, $row), $outstanding);
                    $row++;
                }
                if ($row > 2) {
                    $sheet->getStyle('B2:D' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
                }

            } elseif ($reportType === 'trial_balance') {
                // ── تراز آزمایشی ──
                $sheet->setTitle('تراز آزمایشی');
                $headers = ['کد', 'نام حساب', 'نوع', 'بدهکار', 'بستانکار', 'مانده'];
                foreach ($headers as $i => $h) {
                    $sheet->setCellValue($coord($i + 1, 1), $h);
                }
                $sheet->getStyle('A1:F1')->getFont()->setBold(true);

                $params    = [];
                $joinWhere = '';
                if ($fyId > 0) { $joinWhere = 'AND d.fiscal_year_id = ?'; $params[] = $fyId; }
                $st = $pdo->prepare(
                    "SELECT a.code, a.name, a.type,
                            COALESCE(SUM(r.debit),0) AS total_debit,
                            COALESCE(SUM(r.credit),0) AS total_credit,
                            COALESCE(SUM(r.debit),0)-COALESCE(SUM(r.credit),0) AS balance
                     FROM fin_chart_of_accounts a
                     LEFT JOIN fin_doc_rows r ON r.account_id=a.id
                     LEFT JOIN fin_docs d ON d.id=r.doc_id $joinWhere
                     GROUP BY a.id ORDER BY a.code"
                );
                $st->execute($params);
                $typeMap = ['asset' => 'دارایی', 'liability' => 'بدهی', 'equity' => 'حقوق صاحبان', 'revenue' => 'درآمد', 'expense' => 'هزینه'];
                $row = 2;
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sheet->setCellValue($coord(1, $row), $r['code']);
                    $sheet->setCellValue($coord(2, $row), $r['name']);
                    $sheet->setCellValue($coord(3, $row), $typeMap[$r['type']] ?? $r['type']);
                    $sheet->setCellValue($coord(4, $row), (int)$r['total_debit']);
                    $sheet->setCellValue($coord(5, $row), (int)$r['total_credit']);
                    $sheet->setCellValue($coord(6, $row), (int)$r['balance']);
                    $row++;
                }
                if ($row > 2) {
                    $sheet->getStyle('D2:F' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
                }

            } elseif ($reportType === 'invoice_aging') {
                // ── مطالبات معوق ──
                $sheet->setTitle('مطالبات معوق');
                $headers = ['شماره فاکتور', 'مشتری', 'تاریخ', 'مبلغ کل', 'پرداخت‌شده', 'مانده', 'روز تأخیر'];
                foreach ($headers as $i => $h) {
                    $sheet->setCellValue($coord($i + 1, 1), $h);
                }
                $sheet->getStyle('A1:G1')->getFont()->setBold(true);

                $params = [];
                $andFy  = '';
                if ($fyId > 0) { $andFy = 'AND i.fiscal_year_id = ?'; $params[] = $fyId; }
                $st = $pdo->prepare(
                    "SELECT i.invoice_number, COALESCE(p.name, i.customer_name, '') AS customer,
                            i.invoice_date, i.total_amount, i.paid_amount,
                            i.total_amount - i.paid_amount AS outstanding,
                            DATEDIFF(CURDATE(), STR_TO_DATE(CONCAT(REPLACE(SUBSTRING(i.invoice_date,1,7),'/','-'),'-01'),'%Y-%m-%d')) AS age_days
                     FROM fin_invoices i LEFT JOIN fin_persons p ON i.person_id=p.id
                     WHERE i.type='sell' AND i.paid_amount < i.total_amount AND i.is_deleted=0 $andFy
                     ORDER BY age_days DESC"
                );
                $st->execute($params);
                $row = 2;
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sheet->setCellValue($coord(1, $row), $r['invoice_number']);
                    $sheet->setCellValue($coord(2, $row), $r['customer']);
                    $sheet->setCellValue($coord(3, $row), $r['invoice_date']);
                    $sheet->setCellValue($coord(4, $row), (int)$r['total_amount']);
                    $sheet->setCellValue($coord(5, $row), (int)$r['paid_amount']);
                    $sheet->setCellValue($coord(6, $row), (int)$r['outstanding']);
                    $sheet->setCellValue($coord(7, $row), (int)$r['age_days']);
                    $row++;
                }
                if ($row > 2) {
                    $sheet->getStyle('D2:F' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
                }

            } elseif ($reportType === 'cheque_report') {
                // ── گزارش چک ──
                $sheet->setTitle('گزارش چک');
                $headers = ['شماره چک', 'نوع', 'بانک', 'مبلغ', 'طرف حساب', 'صادر', 'سررسید', 'وضعیت'];
                foreach ($headers as $i => $h) {
                    $sheet->setCellValue($coord($i + 1, 1), $h);
                }
                $sheet->getStyle('A1:H1')->getFont()->setBold(true);

                // بررسی وجود ستون person_name
                $extraName = '';
                try {
                    $colSt = $pdo->query("DESCRIBE fin_cheques");
                    foreach ($colSt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                        if ($col['Field'] === 'person_name') { $extraName = ", c.person_name"; break; }
                    }
                } catch (Throwable $e2) {}

                $st = $pdo->prepare(
                    "SELECT c.cheque_number, c.type, c.bank_name,
                            c.amount, COALESCE(p.name $extraName, '') AS pname,
                            c.issue_date, c.due_date, c.status
                     FROM fin_cheques c LEFT JOIN fin_persons p ON c.person_id=p.id
                     WHERE c.is_deleted=0 ORDER BY c.due_date"
                );
                $st->execute();
                $typeMap   = ['received' => 'دریافتنی', 'issued' => 'پرداختنی'];
                $statusMap = ['pending' => 'در جریان', 'cleared' => 'وصول شده', 'bounced' => 'برگشتی', 'transferred' => 'منتقل‌شده'];
                $row = 2;
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sheet->setCellValue($coord(1, $row), $r['cheque_number']);
                    $sheet->setCellValue($coord(2, $row), $typeMap[$r['type']] ?? $r['type']);
                    $sheet->setCellValue($coord(3, $row), $r['bank_name']);
                    $sheet->setCellValue($coord(4, $row), (int)$r['amount']);
                    $sheet->setCellValue($coord(5, $row), $r['pname']);
                    $sheet->setCellValue($coord(6, $row), $r['issue_date']);
                    $sheet->setCellValue($coord(7, $row), $r['due_date']);
                    $sheet->setCellValue($coord(8, $row), $statusMap[$r['status']] ?? $r['status']);
                    $row++;
                }
                if ($row > 2) {
                    $sheet->getStyle('D2:D' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
                }
            }
        } catch (Throwable $e) {
            $sheet->setCellValue('A1', 'خطا در تهیه گزارش: ' . $e->getMessage());
        }

        // تنظیم عرض ستون‌ها به صورت خودکار
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // پاکسازی بافر و ارسال فایل
        ob_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="report_' . $reportType . '_' . date('Ymd') . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // ── ترازنامه (Balance Sheet) ───────────────────────────────────
    if ($action === 'balance_sheet') {
        $assets      = [];
        $liabilities = [];
        $equity      = [];
        try {
            $params    = [];
            $joinWhere = '';
            if ($fyId > 0) {
                $joinWhere = 'AND d.fiscal_year_id = ?';
                $params[]  = $fyId;
            }
            $st = $pdo->prepare(
                "SELECT a.id, a.code, a.name, a.type,
                        COALESCE(SUM(r.bd),0) AS total_debit,
                        COALESCE(SUM(r.bs),0) AS total_credit,
                        COALESCE(SUM(r.bd),0) - COALESCE(SUM(r.bs),0) AS balance
                 FROM fin_chart_of_accounts a
                 LEFT JOIN fin_doc_rows r ON r.account_id = a.id
                 LEFT JOIN fin_docs d ON d.id = r.doc_id $joinWhere
                 WHERE a.is_deleted = 0
                 GROUP BY a.id
                 ORDER BY a.code"
            );
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $bal = (float)$row['balance'];
                $item = [
                    'id'      => (int)$row['id'],
                    'code'    => $row['code'],
                    'name'    => $row['name'],
                    'type'    => $row['type'],
                    'balance' => 0,
                ];
                if ($row['type'] === 'asset') {
                    if ($bal > 0) {
                        $item['balance'] = (int)$bal;
                        $assets[] = $item;
                    }
                } elseif ($row['type'] === 'liability') {
                    if ($bal < 0) {
                        $item['balance'] = (int)abs($bal);
                        $liabilities[] = $item;
                    }
                } elseif ($row['type'] === 'equity') {
                    if ($bal < 0) {
                        $item['balance'] = (int)abs($bal);
                        $equity[] = $item;
                    }
                }
            }
        } catch (Throwable $e) {}

        $totalAssets = array_sum(array_column($assets, 'balance'));
        $totalLE     = array_sum(array_column($liabilities, 'balance')) + array_sum(array_column($equity, 'balance'));

        echo json_encode([
            'ok'                    => true,
            'assets'                => $assets,
            'liabilities'           => $liabilities,
            'equity'                => $equity,
            'total_assets'          => $totalAssets,
            'total_liabilities_equity' => $totalLE,
        ]);
        exit;
    }

    // ── مقایسه دوره‌ای ─────────────────────────────────────────────
    if ($action === 'period_comparison') {
        $year1  = (int)($_GET['year1']  ?? 0);
        $month1 = (int)($_GET['month1'] ?? 0);
        $year2  = (int)($_GET['year2']  ?? 0);
        $month2 = (int)($_GET['month2'] ?? 0);

        if (!$year1 || !$month1 || !$year2 || !$month2) {
            echo json_encode(['ok' => false, 'msg' => 'پارامترهای دوره ناقص است']);
            exit;
        }

        $calcPeriod = function(PDO $pdo, int $year, int $month): array {
            $data = [
                'revenue'       => 0,
                'paid'          => 0,
                'expenses'      => 0,
                'invoice_count' => 0,
                'new_cheques'   => 0,
            ];
            try {
                // درآمد و وصولی فاکتور فروش
                $st = $pdo->prepare(
                    "SELECT COALESCE(SUM(total_amount),0) AS rev,
                            COALESCE(SUM(paid_amount),0)  AS paid,
                            COUNT(*) AS cnt
                     FROM fin_invoices
                     WHERE type='sell' AND status != 'cancelled' AND is_deleted=0
                       AND YEAR(invoice_date)=? AND MONTH(invoice_date)=?"
                );
                $st->execute([$year, $month]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                $data['revenue']       = (int)$row['rev'];
                $data['paid']          = (int)$row['paid'];
                $data['invoice_count'] = (int)$row['cnt'];
            } catch (Throwable $e) {}

            try {
                // هزینه‌ها
                $st2 = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount),0) AS total
                     FROM fin_expenses
                     WHERE is_deleted=0
                       AND YEAR(expense_date)=? AND MONTH(expense_date)=?"
                );
                $st2->execute([$year, $month]);
                $data['expenses'] = (int)$st2->fetchColumn();
            } catch (Throwable $e) {}

            try {
                // چک‌های دریافتی جدید
                $st3 = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount),0) AS total
                     FROM fin_cheques
                     WHERE type='received' AND is_deleted=0
                       AND YEAR(issue_date)=? AND MONTH(issue_date)=?"
                );
                $st3->execute([$year, $month]);
                $data['new_cheques'] = (int)$st3->fetchColumn();
            } catch (Throwable $e) {}

            return $data;
        };

        $period1 = $calcPeriod($pdo, $year1, $month1);
        $period2 = $calcPeriod($pdo, $year2, $month2);

        echo json_encode([
            'ok'      => true,
            'period1' => $period1,
            'period2' => $period2,
        ]);
        exit;
    }

    // ── گزارش خرید ────────────────────────────────────────────────
    if ($action === 'purchases_report') {
        $result = [
            'labels'         => [],
            'purchases'      => [],
            'paid'           => [],
            'outstanding'    => [],
            'total_purchase' => 0,
            'total_paid'     => 0,
            'top_suppliers'  => [],
            'invoice_count'  => 0,
        ];
        try {
            $where  = "type='buy' AND is_deleted=0";
            $params = [];
            if ($fyId > 0) { $where .= ' AND fiscal_year_id=?'; $params[] = $fyId; }

            $st = $pdo->prepare(
                "SELECT SUBSTRING(invoice_date,1,7) AS month,
                        SUM(total_amount) AS total, SUM(paid_amount) AS paid, COUNT(id) AS cnt
                 FROM fin_invoices WHERE $where
                 GROUP BY SUBSTRING(invoice_date,1,7) ORDER BY 1"
            );
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $result['labels'][]     = $r['month'];
                $result['purchases'][]  = (int)$r['total'];
                $result['paid'][]       = (int)$r['paid'];
                $result['outstanding'][]= (int)$r['total'] - (int)$r['paid'];
                $result['total_purchase'] += (int)$r['total'];
                $result['total_paid']     += (int)$r['paid'];
                $result['invoice_count']  += (int)$r['cnt'];
            }
            $result['total_outstanding'] = $result['total_purchase'] - $result['total_paid'];

            // تامین‌کنندگان برتر
            $st2 = $pdo->prepare(
                "SELECT COALESCE(p.company_name, p.name, i.customer_name, 'نامشخص') AS supplier,
                        SUM(i.total_amount) AS total, COUNT(i.id) AS cnt
                 FROM fin_invoices i LEFT JOIN fin_persons p ON p.id=i.person_id
                 WHERE i.type='buy' AND i.is_deleted=0
                 GROUP BY i.person_id ORDER BY total DESC LIMIT 8"
            );
            $st2->execute();
            $result['top_suppliers'] = $st2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'data' => $result]);
        exit;
    }

    // ── جریان نقدی ────────────────────────────────────────────────
    if ($action === 'cashflow_report') {
        $result = [
            'labels'   => [],
            'inflow'   => [],
            'outflow'  => [],
            'net'      => [],
            'total_in' => 0, 'total_out' => 0, 'net_total' => 0,
        ];
        try {
            $where  = "is_deleted=0";
            $params = [];
            if ($fyId > 0) { $where .= " AND fiscal_year_id=?"; $params[] = $fyId; }

            // وصولی ماهانه (پرداخت‌شده فاکتور فروش)
            $stIn = $pdo->prepare(
                "SELECT SUBSTRING(invoice_date,1,7) AS month, SUM(paid_amount) AS total
                 FROM fin_invoices WHERE type='sell' AND $where
                 GROUP BY 1 ORDER BY 1"
            );
            $stIn->execute($params);
            $inflows = [];
            foreach ($stIn->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $inflows[$r['month']] = (int)$r['total'];
            }

            // پرداختی ماهانه (فاکتور خرید + هزینه)
            $stOut = $pdo->prepare(
                "SELECT SUBSTRING(invoice_date,1,7) AS month, SUM(paid_amount) AS total
                 FROM fin_invoices WHERE type='buy' AND $where
                 GROUP BY 1 ORDER BY 1"
            );
            $stOut->execute($params);
            $outflows = [];
            foreach ($stOut->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $outflows[$r['month']] = (int)$r['total'];
            }

            // هزینه‌ها
            $stExp = $pdo->prepare(
                "SELECT SUBSTRING(expense_date,1,7) AS month, SUM(amount) AS total
                 FROM fin_expenses WHERE is_deleted=0 GROUP BY 1"
            );
            $stExp->execute();
            foreach ($stExp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $outflows[$r['month']] = ($outflows[$r['month']] ?? 0) + (int)$r['total'];
            }

            $months = array_unique(array_merge(array_keys($inflows), array_keys($outflows)));
            sort($months);

            foreach ($months as $m) {
                $in  = $inflows[$m]  ?? 0;
                $out = $outflows[$m] ?? 0;
                $result['labels'][]  = $m;
                $result['inflow'][]  = $in;
                $result['outflow'][] = $out;
                $result['net'][]     = $in - $out;
                $result['total_in']  += $in;
                $result['total_out'] += $out;
            }
            $result['net_total'] = $result['total_in'] - $result['total_out'];
        } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'data' => $result]);
        exit;
    }

    // ── خلاصه KPI ─────────────────────────────────────────────────
    if ($action === 'kpi_summary') {
        $r = ['ok' => true, 'data' => [
            'total_sales'      => 0, 'total_purchases'  => 0,
            'total_collected'  => 0, 'total_outstanding'=> 0,
            'overdue_invoices' => 0, 'pending_cheques'  => 0,
            'total_expenses'   => 0, 'net_profit'       => 0,
        ]];
        try {
            $p = []; $w = "is_deleted=0";
            if ($fyId > 0) { $w .= " AND fiscal_year_id=?"; $p[] = $fyId; }

            $st = $pdo->prepare("SELECT type, SUM(total_amount) t, SUM(paid_amount) pd FROM fin_invoices WHERE $w GROUP BY type");
            $st->execute($p);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['type'] === 'sell') {
                    $r['data']['total_sales']      = (int)$row['t'];
                    $r['data']['total_collected']  = (int)$row['pd'];
                    $r['data']['total_outstanding']= (int)$row['t'] - (int)$row['pd'];
                }
                if ($row['type'] === 'buy') {
                    $r['data']['total_purchases']  = (int)$row['t'];
                }
            }

            $st2 = $pdo->prepare("SELECT COUNT(*) FROM fin_invoices WHERE type='sell' AND status='confirmed' AND paid_amount < total_amount AND due_date < CURDATE() AND is_deleted=0");
            $st2->execute();
            $r['data']['overdue_invoices'] = (int)$st2->fetchColumn();

            $st3 = $pdo->prepare("SELECT COUNT(*) FROM fin_cheques WHERE status='pending' AND due_date <= DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND is_deleted=0");
            $st3->execute();
            $r['data']['pending_cheques']  = (int)$st3->fetchColumn();

            $st4 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_expenses WHERE is_deleted=0");
            $st4->execute();
            $r['data']['total_expenses']   = (int)$st4->fetchColumn();

            $r['data']['net_profit'] = $r['data']['total_sales'] - $r['data']['total_purchases'] - $r['data']['total_expenses'];
        } catch (Throwable $e) {}
        echo json_encode($r);
        exit;
    }

    // ── گزارش بهای تمام‌شده (COGS) ──────────────────────────────────
    if ($action === 'cogs_report') {
        $result = [
            'ok'         => true,
            'labels'     => [],
            'cogs'       => [],
            'revenue'    => [],
            'gross_profit' => [],
            'totals'     => ['total_cogs' => 0, 'total_revenue' => 0, 'gross_profit' => 0, 'gross_margin' => 0],
            'top_products' => [],
        ];
        try {
            $fy = getFiscalYear($pdo, $fyId ?: null);
            $dateCondition = '';
            $params = [];
            if ($fy) {
                $dateCondition = ' AND fi.invoice_date BETWEEN ? AND ?';
                $params = [$fy['start_date'], $fy['end_date']];
            }
            // فروش ماهانه و بهای تمام‌شده
            $sql = "SELECT MONTH(fi.invoice_date) AS m, YEAR(fi.invoice_date) AS y,
                           COALESCE(SUM(fi.total_amount),0) AS revenue,
                           COALESCE(SUM(
                               (SELECT COALESCE(SUM(ii2.quantity * ii2.unit_price),0)
                                FROM fin_invoice_items ii2
                                WHERE ii2.invoice_id = fi.id)
                           ),0) AS cost
                    FROM fin_invoices fi
                    WHERE fi.type='sell' AND fi.status='confirmed' AND fi.is_deleted=0
                          $dateCondition
                    GROUP BY y, m ORDER BY y ASC, m ASC";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $monthNames = ['','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
            foreach ($rows as $row) {
                $jalaliMonth = (int)$row['m'];
                $result['labels'][] = $monthNames[$jalaliMonth] ?? $row['m'];
                $rev  = (int)$row['revenue'];
                $cost = (int)$row['cost'];
                $gp   = $rev - $cost;
                $result['revenue'][]      = $rev;
                $result['cogs'][]         = $cost;
                $result['gross_profit'][] = $gp;
                $result['totals']['total_revenue']  += $rev;
                $result['totals']['total_cogs']     += $cost;
                $result['totals']['gross_profit']   += $gp;
            }
            $rev = $result['totals']['total_revenue'];
            $result['totals']['gross_margin'] = $rev > 0 ? round(($result['totals']['gross_profit'] / $rev) * 100, 1) : 0;
            // ۸ محصول با بیشترین فروش
            $sql2 = "SELECT spl.stuff_name, COALESCE(SUM(ii.quantity),0) AS qty,
                            COALESCE(SUM(ii.quantity * ii.unit_price),0) AS revenue
                     FROM fin_invoice_items ii
                     JOIN fin_invoices fi ON fi.id = ii.invoice_id
                     LEFT JOIN stuff_price_list spl ON spl.id = ii.stuff_id
                     WHERE fi.type='sell' AND fi.status='confirmed' AND fi.is_deleted=0
                           $dateCondition
                     GROUP BY ii.stuff_id ORDER BY revenue DESC LIMIT 8";
            $st2 = $pdo->prepare($sql2);
            $st2->execute($params);
            $result['top_products'] = $st2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $result['ok']  = false;
            $result['msg'] = $e->getMessage();
        }
        echo json_encode($result);
        exit;
    }

    // اکشن ناشناخته
    echo json_encode(['ok' => false, 'msg' => 'اکشن نامعتبر']);
    exit;
}

// ───────────────────────────────────────────────────────────────────
// آماده‌سازی داده‌های صفحه
// ───────────────────────────────────────────────────────────────────
$fiscalYears   = getFiscalYears($pdo);
$currentFyId   = 0;
foreach ($fiscalYears as $fy) {
    if ($fy['is_current']) { $currentFyId = (int)$fy['id']; break; }
}
if (!$currentFyId && !empty($fiscalYears)) {
    $currentFyId = (int)$fiscalYears[0]['id'];
}

// لیست حساب‌ها برای دفتر حساب
$chartAccounts = [];
try {
    $stAcc = $pdo->query("SELECT id, code, name, type FROM fin_chart_of_accounts ORDER BY code");
    $chartAccounts = $stAcc->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ───────────────────────────────────────────────────────────────────
// خروجی HTML
// ───────────────────────────────────────────────────────────────────
$pageTitle = 'گزارش‌های مالی';
$basePath  = '../../';
$extraCss  = '
<link rel="stylesheet" href="../../assets/css/fin_module.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
';
include __DIR__ . '/../../templates/header.php';
?>
<style>
/* ── تنظیمات اختصاصی صفحه گزارش‌ها ── */
.rep-page { direction: rtl; font-family: 'Vazirmatn','Tahoma',sans-serif; background: var(--fin-bg,#f1f5f9); min-height: 100vh; padding: 24px 28px; }

/* ── هدر صفحه ── */
.rep-page-header { background: linear-gradient(135deg,#7c3aed 0%,#4f46e5 100%); border-radius: 16px; padding: 28px 32px; color: #fff; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; box-shadow: 0 8px 32px rgba(124,58,237,.3); }
.rep-page-header h1 { margin: 0; font-size: 1.6rem; font-weight: 700; display: flex; align-items: center; gap: 12px; }
.rep-page-header h1 i { font-size: 1.8rem; }
.rep-header-controls { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.rep-fy-select { background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3); color: #fff; padding: 8px 14px; border-radius: 10px; font-size: .92rem; font-family: inherit; cursor: pointer; outline: none; }
.rep-fy-select option { color: #1e293b; background: #fff; }
.btn-export-main { background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.4); color: #fff; padding: 9px 18px; border-radius: 10px; font-size: .9rem; font-family: inherit; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: background .2s; }
.btn-export-main:hover { background: rgba(255,255,255,.28); }

/* ── تب‌ناوبری ── */
.rep-tabs { display: flex; gap: 4px; background: #fff; padding: 6px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 24px; overflow-x: auto; flex-wrap: nowrap; }
.rep-tab-btn { padding: 10px 18px; border: none; background: transparent; border-radius: 10px; cursor: pointer; font-family: inherit; font-size: .9rem; font-weight: 500; color: #64748b; white-space: nowrap; transition: all .2s; display: flex; align-items: center; gap: 7px; }
.rep-tab-btn:hover { background: #f1f5f9; color: #4f46e5; }
.rep-tab-btn.active { background: #4f46e5; color: #fff; box-shadow: 0 3px 12px rgba(79,70,229,.35); }
.rep-tab-btn i { font-size: 1.05rem; }

/* ── پانل‌های محتوا ── */
.rep-panel { display: none; }
.rep-panel.active { display: block; }

/* ── کارت‌های خلاصه ── */
.rep-summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 16px; margin-bottom: 24px; }
.rep-card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.05); border: 1px solid #e2e8f0; text-align: center; }
.rep-card-icon { font-size: 2rem; margin-bottom: 8px; }
.rep-card-value { font-size: 1.4rem; font-weight: 700; color: #1e293b; }
.rep-card-label { font-size: .82rem; color: #64748b; margin-top: 4px; }
.rep-card.primary .rep-card-value { color: #4f46e5; }
.rep-card.success .rep-card-value { color: #10b981; }
.rep-card.warning .rep-card-value { color: #f59e0b; }
.rep-card.danger  .rep-card-value { color: #ef4444; }

/* ── نمودارها ── */
.rep-charts-row { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px; }
.rep-chart-box { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.05); border: 1px solid #e2e8f0; }
.rep-chart-title { font-size: 1rem; font-weight: 600; color: #1e293b; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.chart-canvas-wrap { position: relative; height: 280px; }
@media (max-width:768px) { .rep-charts-row { grid-template-columns: 1fr; } }

/* ── جداول ── */
.rep-table-wrap { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.05); border: 1px solid #e2e8f0; overflow-x: auto; }
.rep-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.rep-table th { background: #f8fafc; color: #475569; font-weight: 600; padding: 12px 14px; text-align: right; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
.rep-table td { padding: 11px 14px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
.rep-table tr:hover td { background: #fafbfc; }
.rep-table .total-row td { background: #eef2ff; font-weight: 700; color: #1e293b; border-top: 2px solid #c7d2fe; }
.rep-table .debit-balance { color: #2563eb; font-weight: 600; }
.rep-table .credit-balance { color: #059669; font-weight: 600; }
.rep-table .neg-balance { color: #dc2626; font-weight: 600; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .78rem; font-weight: 500; }
.badge-red    { background: #fef2f2; color: #dc2626; }
.badge-amber  { background: #fffbeb; color: #d97706; }
.badge-green  { background: #ecfdf5; color: #059669; }
.badge-blue   { background: #eff6ff; color: #2563eb; }
.badge-gray   { background: #f1f5f9; color: #475569; }

/* ── سود و زیان ── */
.pnl-wrap { background: #fff; border-radius: 14px; padding: 28px 36px; box-shadow: 0 2px 10px rgba(0,0,0,.05); border: 1px solid #e2e8f0; max-width: 680px; }
.pnl-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; font-size: .95rem; }
.pnl-row.indent { padding-right: 24px; }
.pnl-row .pnl-label { color: #475569; }
.pnl-row .pnl-amount { font-weight: 600; color: #1e293b; direction: ltr; }
.pnl-divider { border: none; border-top: 2px dashed #e2e8f0; margin: 8px 0; }
.pnl-divider.solid { border-top: 2px solid #c7d2fe; }
.pnl-total-row { background: #eef2ff; border-radius: 10px; padding: 14px 16px; margin-top: 8px; display: flex; justify-content: space-between; align-items: center; }
.pnl-total-row .pnl-label { font-size: 1.05rem; font-weight: 700; color: #1e293b; }
.pnl-total-row .pnl-amount { font-size: 1.2rem; font-weight: 700; }
.pnl-positive { color: #059669 !important; }
.pnl-negative { color: #dc2626 !important; }
.pnl-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
@media (max-width:768px) { .pnl-grid { grid-template-columns: 1fr; } .pnl-wrap { padding: 20px; } }

/* ── دفتر حساب ── */
.ledger-controls { display: flex; gap: 12px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
.ledger-select { border: 1px solid #e2e8f0; border-radius: 10px; padding: 9px 14px; font-family: inherit; font-size: .9rem; color: #1e293b; outline: none; min-width: 260px; }
.btn-load { background: #4f46e5; color: #fff; border: none; border-radius: 10px; padding: 10px 20px; font-family: inherit; font-size: .9rem; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: background .2s; }
.btn-load:hover { background: #3730a3; }

/* ── خروجی CSV ── */
.btn-csv { background: #059669; color: #fff; border: none; border-radius: 10px; padding: 9px 18px; font-family: inherit; font-size: .88rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background .2s; margin-bottom: 16px; }
.btn-csv:hover { background: #047857; }
/* ── خروجی Excel (xlsx) ── */
.btn-xlsx { background: #1d6f42; color: #fff; border: none; border-radius: 10px; padding: 9px 18px; font-family: inherit; font-size: .88rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background .2s; margin-bottom: 16px; }
.btn-xlsx:hover { background: #155534; }

/* ── فیلتر گزارش چک ── */
.cheque-filters { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
.rep-filter-select { border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 14px; font-family: inherit; font-size: .88rem; color: #1e293b; outline: none; }

/* ── KPI Bar ── */
.kpi-bar { display:flex; gap:12px; flex-wrap:wrap; background:#fff; border-radius:14px; padding:16px 20px; box-shadow:0 2px 10px rgba(0,0,0,.05); border:1px solid #e2e8f0; margin-bottom:20px; opacity:0.4; transition:opacity .4s; }
.kpi-item { display:flex; align-items:center; gap:10px; flex:1; min-width:140px; }
.kpi-icon { width:38px; height:38px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
.kpi-val { font-size:.95rem; font-weight:700; color:#1e293b; white-space:nowrap; }
.kpi-val.green  { color:#059669; }
.kpi-val.red    { color:#dc2626; }
.kpi-val.amber  { color:#d97706; }
.kpi-val.purple { color:#7c3aed; }
.kpi-lbl { font-size:.72rem; color:#94a3b8; margin-top:1px; }
@media(max-width:768px){ .kpi-item{ min-width:calc(50% - 6px); } }

/* ── لودینگ ── */
.rep-loading { text-align: center; padding: 60px 20px; color: #64748b; font-size: .95rem; }
.rep-loading i { font-size: 2rem; display: block; margin-bottom: 12px; animation: spin 1s linear infinite; }
@keyframes spin { from{transform:rotate(0)} to{transform:rotate(360deg)} }

/* ── هشدار تراز ── */
.unbalanced-warn { background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; border-radius: 10px; padding: 12px 16px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }

/* ── کارت‌های پایین چک ── */
.cheque-sum-card { background: #eef2ff; border-radius: 12px; padding: 16px 20px; display: flex; gap: 32px; margin-top: 16px; flex-wrap: wrap; }
.cheque-sum-item { display: flex; flex-direction: column; gap: 4px; }
.cheque-sum-item .cs-label { font-size: .8rem; color: #64748b; }
.cheque-sum-item .cs-val   { font-size: 1.1rem; font-weight: 700; color: #4f46e5; }

/* ── ترازنامه ── */
.bs-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
@media (max-width:768px) { .bs-grid { grid-template-columns: 1fr; } }
.bs-section-title { font-size: 1rem; font-weight: 700; color: #1e293b; padding: 12px 16px; border-radius: 10px 10px 0 0; display: flex; align-items: center; gap: 8px; }
.bs-section-title.asset-title    { background: #eff6ff; color: #2563eb; border-bottom: 2px solid #bfdbfe; }
.bs-section-title.liab-title     { background: #fef3c7; color: #92400e; border-bottom: 2px solid #fde68a; }
.bs-total-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; font-weight: 700; font-size: .95rem; border-top: 2px solid #e2e8f0; margin-top: 4px; }
.bs-total-row.asset-total { color: #2563eb; }
.bs-total-row.liab-total  { color: #92400e; }
.bs-balance-status { display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-radius: 12px; margin-top: 20px; font-size: 1rem; font-weight: 600; }
.bs-balance-status.ok  { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
.bs-balance-status.bad { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }

/* ── مقایسه دوره‌ای ── */
.period-controls { background: #fff; border-radius: 14px; padding: 20px 24px; box-shadow: 0 2px 10px rgba(0,0,0,.05); border: 1px solid #e2e8f0; margin-bottom: 20px; display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap; }
.period-group { display: flex; flex-direction: column; gap: 6px; }
.period-group label { font-size: .82rem; font-weight: 600; color: #475569; }
.period-inputs { display: flex; gap: 8px; }
.period-year-input  { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-family: inherit; font-size: .9rem; width: 90px; outline: none; color: #1e293b; }
.period-month-select { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-family: inherit; font-size: .9rem; outline: none; color: #1e293b; }
.btn-compare { background: #7c3aed; color: #fff; border: none; border-radius: 10px; padding: 10px 22px; font-family: inherit; font-size: .9rem; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: background .2s; }
.btn-compare:hover { background: #5b21b6; }
.cmp-table-wrap { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.05); border: 1px solid #e2e8f0; overflow-x: auto; }
.cmp-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
.cmp-table th { background: #f8fafc; color: #475569; font-weight: 600; padding: 13px 16px; text-align: right; border-bottom: 2px solid #e2e8f0; }
.cmp-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; }
.cmp-table tr:hover td { background: #fafbfc; }
.cmp-table .metric-col { font-weight: 600; color: #1e293b; }
.cmp-positive { color: #059669; font-weight: 700; }
.cmp-negative { color: #dc2626; font-weight: 700; }
.cmp-neutral  { color: #64748b; font-weight: 600; }
</style>

<div class="rep-page fin-page">

    <!-- ── هدر صفحه ── -->
    <div class="rep-page-header">
        <h1><i class="fas fa-chart-line"></i> گزارش‌های مالی</h1>
        <div class="rep-header-controls">
            <select class="rep-fy-select" id="globalFySelect" onchange="onFyChange()">
                <?php foreach ($fiscalYears as $fy): ?>
                    <option value="<?= (int)$fy['id'] ?>"
                        <?= $fy['id'] == $currentFyId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($fy['title']) ?>
                        <?= $fy['is_current'] ? '(جاری)' : '' ?>
                    </option>
                <?php endforeach; ?>
                <?php if (empty($fiscalYears)): ?>
                    <option value="0">همه دوره‌ها</option>
                <?php endif; ?>
            </select>
            <button class="btn-export-main" onclick="exportCurrentTab()">
                <i class="fas fa-file-excel"></i> خروجی Excel
            </button>
        </div>
    </div>

    <!-- ── تب‌ناوبری ── -->
    <div class="rep-tabs" role="tablist">
        <button class="rep-tab-btn active" onclick="switchTab(1)" id="tab-btn-1">
            <i class="fas fa-chart-bar"></i> نمودار فروش
        </button>
        <button class="rep-tab-btn" onclick="switchTab(2)" id="tab-btn-2">
            <i class="fas fa-balance-scale"></i> سود و زیان
        </button>
        <button class="rep-tab-btn" onclick="switchTab(3)" id="tab-btn-3">
            <i class="fas fa-list-ol"></i> تراز آزمایشی
        </button>
        <button class="rep-tab-btn" onclick="switchTab(4)" id="tab-btn-4">
            <i class="fas fa-book"></i> دفتر حساب
        </button>
        <button class="rep-tab-btn" onclick="switchTab(5)" id="tab-btn-5">
            <i class="fas fa-exclamation-circle"></i> مطالبات معوق
        </button>
        <button class="rep-tab-btn" onclick="switchTab(6)" id="tab-btn-6">
            <i class="fas fa-money-check-alt"></i> گزارش چک
        </button>
        <button class="rep-tab-btn" onclick="switchTab(7)" id="tab-btn-7">
            <i class="fas fa-landmark"></i> ترازنامه
        </button>
        <button class="rep-tab-btn" onclick="switchTab(8)" id="tab-btn-8">
            <i class="fas fa-exchange-alt"></i> مقایسه دوره‌ای
        </button>
        <button class="rep-tab-btn" onclick="switchTab(9)" id="tab-btn-9">
            <i class="fas fa-shopping-cart"></i> گزارش خرید
        </button>
        <button class="rep-tab-btn" onclick="switchTab(10)" id="tab-btn-10">
            <i class="fas fa-water"></i> جریان نقدی
        </button>
        <button class="rep-tab-btn" onclick="switchTab(11)" id="tab-btn-11">
            <i class="fas fa-calculator"></i> بهای تمام‌شده
        </button>
    </div>

    <!-- KPI cards ثابت بالای صفحه -->
    <div class="kpi-bar" id="kpiBar">
        <div class="kpi-item">
            <div class="kpi-icon" style="background:#eff6ff">💰</div>
            <div><div class="kpi-val" id="kpiSales">—</div><div class="kpi-lbl">فروش کل</div></div>
        </div>
        <div class="kpi-item">
            <div class="kpi-icon" style="background:#ecfdf5">✅</div>
            <div><div class="kpi-val green" id="kpiCollected">—</div><div class="kpi-lbl">وصول‌شده</div></div>
        </div>
        <div class="kpi-item">
            <div class="kpi-icon" style="background:#fef2f2">⏰</div>
            <div><div class="kpi-val red" id="kpiOutstanding">—</div><div class="kpi-lbl">مانده وصول</div></div>
        </div>
        <div class="kpi-item">
            <div class="kpi-icon" style="background:#fffbeb">⚠️</div>
            <div><div class="kpi-val amber" id="kpiOverdue">—</div><div class="kpi-lbl">فاکتور معوق</div></div>
        </div>
        <div class="kpi-item">
            <div class="kpi-icon" style="background:#f5f3ff">🏦</div>
            <div><div class="kpi-val purple" id="kpiCheques">—</div><div class="kpi-lbl">چک سررسید ۷ روز</div></div>
        </div>
        <div class="kpi-item">
            <div class="kpi-icon" style="background:#f0fdf4">📈</div>
            <div><div class="kpi-val" id="kpiProfit" style="color:#059669">—</div><div class="kpi-lbl">سود خالص تخمینی</div></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         پانل ۱ — نمودار فروش
    ════════════════════════════════════════════════════ -->
    <div class="rep-panel active" id="panel-1">
        <div id="salesLoading" class="rep-loading"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="salesContent" style="display:none">
            <!-- کارت‌های خلاصه -->
            <div class="rep-summary-cards">
                <div class="rep-card primary">
                    <div class="rep-card-icon">💰</div>
                    <div class="rep-card-value" id="sc-total">—</div>
                    <div class="rep-card-label">مجموع فروش</div>
                </div>
                <div class="rep-card success">
                    <div class="rep-card-icon">✅</div>
                    <div class="rep-card-value" id="sc-paid">—</div>
                    <div class="rep-card-label">وصولی</div>
                </div>
                <div class="rep-card warning">
                    <div class="rep-card-icon">⏳</div>
                    <div class="rep-card-value" id="sc-outstanding">—</div>
                    <div class="rep-card-label">مطالبات</div>
                </div>
                <div class="rep-card">
                    <div class="rep-card-icon">📄</div>
                    <div class="rep-card-value" id="sc-count">—</div>
                    <div class="rep-card-label">تعداد فاکتور</div>
                </div>
            </div>
            <!-- نمودارها -->
            <div class="rep-charts-row">
                <div class="rep-chart-box">
                    <div class="rep-chart-title"><i class="fas fa-chart-bar" style="color:#4f46e5"></i> فروش ماهانه</div>
                    <div class="chart-canvas-wrap">
                        <canvas id="barChartSales"></canvas>
                    </div>
                </div>
                <div class="rep-chart-box">
                    <div class="rep-chart-title"><i class="fas fa-chart-pie" style="color:#10b981"></i> نسبت وصولی به مطالبات</div>
                    <div class="chart-canvas-wrap">
                        <canvas id="doughnutChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         پانل ۲ — سود و زیان
    ════════════════════════════════════════════════════ -->
    <div class="rep-panel" id="panel-2">
        <div id="pnlLoading" class="rep-loading"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="pnlContent" style="display:none">
            <div style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn-csv" onclick="exportCsv('sales')">
                    <i class="fas fa-file-csv"></i> خروجی CSV
                </button>
                <button class="btn-xlsx" onclick="exportXlsx('sales')">
                    <i class="fas fa-file-excel"></i> خروجی Excel
                </button>
            </div>
            <div class="pnl-grid">
                <!-- صورت سود و زیان -->
                <div class="pnl-wrap" id="pnlStatement"></div>
                <!-- نمودار هزینه‌ها -->
                <div class="rep-chart-box">
                    <div class="rep-chart-title"><i class="fas fa-chart-pie" style="color:#f59e0b"></i> توزیع هزینه‌ها</div>
                    <div class="chart-canvas-wrap"><canvas id="expenseChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         پانل ۳ — تراز آزمایشی
    ════════════════════════════════════════════════════ -->
    <div class="rep-panel" id="panel-3">
        <div id="tbLoading" class="rep-loading"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="tbContent" style="display:none">
            <div style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn-csv" onclick="exportCsv('trial_balance')">
                    <i class="fas fa-file-csv"></i> خروجی CSV
                </button>
                <button class="btn-xlsx" onclick="exportXlsx('trial_balance')">
                    <i class="fas fa-file-excel"></i> خروجی Excel
                </button>
            </div>
            <div id="tbWarnBox" class="unbalanced-warn" style="display:none">
                <i class="fas fa-exclamation-triangle"></i>
                هشدار: مجموع بدهکار و بستانکار برابر نیستند — تراز نامتعادل است.
            </div>
            <div class="rep-table-wrap">
                <table class="rep-table" id="tbTable">
                    <thead>
                        <tr>
                            <th>کد حساب</th>
                            <th>نام حساب</th>
                            <th>نوع</th>
                            <th>مجموع بدهکار</th>
                            <th>مجموع بستانکار</th>
                            <th>مانده</th>
                        </tr>
                    </thead>
                    <tbody id="tbBody"></tbody>
                    <tfoot id="tbFoot"></tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         پانل ۴ — دفتر حساب
    ════════════════════════════════════════════════════ -->
    <div class="rep-panel" id="panel-4">
        <div class="ledger-controls">
            <select class="ledger-select" id="ledgerAccountSelect">
                <option value="">— انتخاب حساب —</option>
                <?php foreach ($chartAccounts as $acc): ?>
                    <option value="<?= (int)$acc['id'] ?>">
                        <?= htmlspecialchars($acc['code']) ?> — <?= htmlspecialchars($acc['name']) ?>
                    </option>
                <?php endforeach; ?>
                <?php if (empty($chartAccounts)): ?>
                    <option value="" disabled>حسابی تعریف نشده</option>
                <?php endif; ?>
            </select>
            <button class="btn-load" onclick="loadLedger()">
                <i class="fas fa-search"></i> نمایش دفتر
            </button>
            <button class="btn-csv" onclick="exportCsv('ledger')" style="margin:0">
                <i class="fas fa-file-csv"></i> خروجی CSV
            </button>
        </div>
        <div id="ledgerLoading" class="rep-loading" style="display:none"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="ledgerContent">
            <div style="text-align:center;padding:60px 20px;color:#94a3b8;">
                <i class="fas fa-book" style="font-size:3rem;display:block;margin-bottom:12px"></i>
                حساب مورد نظر را انتخاب کنید و روی «نمایش دفتر» کلیک کنید.
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         پانل ۵ — مطالبات معوق
    ════════════════════════════════════════════════════ -->
    <div class="rep-panel" id="panel-5">
        <div id="agingLoading" class="rep-loading"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="agingContent" style="display:none">
            <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div class="rep-summary-cards" style="margin:0;grid-template-columns:repeat(3,auto);gap:12px" id="agingSummary"></div>
                <button class="btn-csv" onclick="exportCsv('aging')" style="margin:0">
                    <i class="fas fa-file-csv"></i> خروجی CSV
                </button>
                <button class="btn-xlsx" onclick="exportXlsx('invoice_aging')" style="margin:0">
                    <i class="fas fa-file-excel"></i> خروجی Excel
                </button>
            </div>
            <div class="rep-table-wrap">
                <table class="rep-table">
                    <thead>
                        <tr>
                            <th>شماره فاکتور</th>
                            <th>مشتری</th>
                            <th>تاریخ فاکتور</th>
                            <th>مبلغ کل</th>
                            <th>پرداختی</th>
                            <th>مانده</th>
                            <th>عمر (روز)</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody id="agingBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         پانل ۶ — گزارش چک
    ════════════════════════════════════════════════════ -->
    <div class="rep-panel" id="panel-6">
        <div id="chequeLoading" class="rep-loading"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="chequeContent" style="display:none">
            <div class="cheque-filters">
                <select class="rep-filter-select" id="chequeTypeFilter" onchange="loadCheques()">
                    <option value="all">همه چک‌ها</option>
                    <option value="received">دریافتنی</option>
                    <option value="issued">پرداختنی</option>
                </select>
                <select class="rep-filter-select" id="chequeStatusFilter" onchange="loadCheques()">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="pending">در جریان</option>
                    <option value="cleared">وصول شده</option>
                    <option value="bounced">برگشتی</option>
                    <option value="transferred">منتقل‌شده</option>
                </select>
                <button class="btn-csv" onclick="exportCsv('cheques')">
                    <i class="fas fa-file-csv"></i> خروجی CSV
                </button>
                <button class="btn-xlsx" onclick="exportXlsx('cheque_report')">
                    <i class="fas fa-file-excel"></i> خروجی Excel
                </button>
            </div>
            <div class="rep-table-wrap">
                <table class="rep-table">
                    <thead>
                        <tr>
                            <th>شماره چک</th>
                            <th>نوع</th>
                            <th>بانک</th>
                            <th>طرف حساب</th>
                            <th>مبلغ (تومان)</th>
                            <th>تاریخ سررسید</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody id="chequeBody"></tbody>
                </table>
            </div>
            <div class="cheque-sum-card" id="chequeSumCard"></div>
        </div>
    </div>


    <!-- ════════════════════════════════════════════════════
         پانل ۷ — ترازنامه
    ════════════════════════════════════════════════════ -->
    <div class="rep-panel" id="panel-7">
        <div id="bsLoading" class="rep-loading"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="bsContent" style="display:none">
            <div id="bsBalanceStatus"></div>
            <div class="bs-grid" id="bsGrid"></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         پانل ۸ — مقایسه دوره‌ای
    ════════════════════════════════════════════════════ -->
    <div class="rep-panel" id="panel-8">
        <div class="period-controls">
            <div class="period-group">
                <label>دوره اول (سال و ماه میلادی)</label>
                <div class="period-inputs">
                    <input type="number" class="period-year-input" id="cmpYear1" placeholder="مثلاً ۲۰۲۴" min="2000" max="2100" value="<?= date('Y') ?>">
                    <select class="period-month-select" id="cmpMonth1">
                        <option value="1">فروردین / ژانویه</option>
                        <option value="2">اردیبهشت / فوریه</option>
                        <option value="3">خرداد / مارس</option>
                        <option value="4">تیر / آوریل</option>
                        <option value="5">مرداد / مه</option>
                        <option value="6">شهریور / ژوئن</option>
                        <option value="7">مهر / جولای</option>
                        <option value="8">آبان / اوت</option>
                        <option value="9">آذر / سپتامبر</option>
                        <option value="10">دی / اکتبر</option>
                        <option value="11">بهمن / نوامبر</option>
                        <option value="12">اسفند / دسامبر</option>
                    </select>
                </div>
            </div>
            <div class="period-group">
                <label>دوره دوم (سال و ماه میلادی)</label>
                <div class="period-inputs">
                    <input type="number" class="period-year-input" id="cmpYear2" placeholder="مثلاً ۲۰۲۴" min="2000" max="2100" value="<?= date('Y') ?>">
                    <select class="period-month-select" id="cmpMonth2">
                        <option value="1">فروردین / ژانویه</option>
                        <option value="2">اردیبهشت / فوریه</option>
                        <option value="3">خرداد / مارس</option>
                        <option value="4">تیر / آوریل</option>
                        <option value="5">مرداد / مه</option>
                        <option value="6">شهریور / ژوئن</option>
                        <option value="7">مهر / جولای</option>
                        <option value="8">آبان / اوت</option>
                        <option value="9">آذر / سپتامبر</option>
                        <option value="10">دی / اکتبر</option>
                        <option value="11">بهمن / نوامبر</option>
                        <option value="12">اسفند / دسامبر</option>
                    </select>
                </div>
            </div>
            <button class="btn-compare" onclick="loadPeriodComparison()">
                <i class="fas fa-chart-line"></i> مقایسه
            </button>
        </div>
        <div id="cmpLoading" class="rep-loading" style="display:none"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="cmpContent">
            <div style="text-align:center;padding:60px 20px;color:#94a3b8;">
                <i class="fas fa-exchange-alt" style="font-size:3rem;display:block;margin-bottom:12px"></i>
                دو دوره را انتخاب کنید و روی «مقایسه» کلیک کنید.
            </div>
        </div>
    </div>

    <!-- ════ تب ۹ — گزارش خرید ════ -->
    <div class="rep-panel" id="panel-9">
        <div id="purchLoading" class="rep-loading"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="purchContent" style="display:none">
            <div class="rep-summary-cards" style="grid-template-columns:repeat(4,1fr)">
                <div class="rep-card danger">
                    <div class="rep-card-icon">🛒</div>
                    <div class="rep-card-value" id="pur-total">—</div>
                    <div class="rep-card-label">کل خرید</div>
                </div>
                <div class="rep-card success">
                    <div class="rep-card-icon">✅</div>
                    <div class="rep-card-value" id="pur-paid">—</div>
                    <div class="rep-card-label">پرداخت‌شده</div>
                </div>
                <div class="rep-card warning">
                    <div class="rep-card-icon">⏳</div>
                    <div class="rep-card-value" id="pur-out">—</div>
                    <div class="rep-card-label">مانده پرداخت</div>
                </div>
                <div class="rep-card">
                    <div class="rep-card-icon">📋</div>
                    <div class="rep-card-value" id="pur-count">—</div>
                    <div class="rep-card-label">تعداد فاکتور</div>
                </div>
            </div>
            <div class="rep-chart-box" style="margin-bottom:20px">
                <div class="rep-chart-title"><i class="fas fa-shopping-cart" style="color:#ef4444"></i> روند خرید ماهانه</div>
                <div class="chart-canvas-wrap"><canvas id="purchBarChart"></canvas></div>
            </div>
            <div class="rep-table-wrap">
                <div class="rep-chart-title" style="margin-bottom:12px"><i class="fas fa-star" style="color:#f59e0b"></i> تامین‌کنندگان برتر</div>
                <table class="rep-table">
                    <thead><tr><th>#</th><th>نام تامین‌کننده</th><th>تعداد فاکتور</th><th>جمع خرید</th></tr></thead>
                    <tbody id="supplierBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ════ تب ۱۱ — بهای تمام‌شده COGS ════ -->
    <div class="rep-panel" id="panel-11">
        <div id="cogsLoading" class="rep-loading"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="cogsContent" style="display:none">
            <div class="rep-summary-cards" style="grid-template-columns:repeat(4,1fr)">
                <div class="rep-card primary">
                    <div class="rep-card-icon">💰</div>
                    <div class="rep-card-value" id="cogs-revenue">—</div>
                    <div class="rep-card-label">درآمد فروش</div>
                </div>
                <div class="rep-card danger">
                    <div class="rep-card-icon">🏭</div>
                    <div class="rep-card-value" id="cogs-cogs">—</div>
                    <div class="rep-card-label">بهای تمام‌شده کالا</div>
                </div>
                <div class="rep-card success">
                    <div class="rep-card-icon">📈</div>
                    <div class="rep-card-value" id="cogs-gp">—</div>
                    <div class="rep-card-label">سود ناخالص</div>
                </div>
                <div class="rep-card warning">
                    <div class="rep-card-icon">%</div>
                    <div class="rep-card-value" id="cogs-margin">—</div>
                    <div class="rep-card-label">حاشیه سود ناخالص</div>
                </div>
            </div>
            <div class="rep-chart-box" style="margin-bottom:20px">
                <div class="rep-chart-title"><i class="fas fa-calculator" style="color:#7c3aed"></i> مقایسه درآمد و بهای تمام‌شده ماهانه</div>
                <div class="chart-canvas-wrap" style="height:300px"><canvas id="cogsChart"></canvas></div>
            </div>
            <div class="rep-table-wrap">
                <div class="rep-table-title">پرفروش‌ترین محصولات</div>
                <table class="rep-table">
                    <thead><tr><th>محصول</th><th>تعداد فروش</th><th>درآمد</th></tr></thead>
                    <tbody id="cogsTopTable"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ════ تب ۱۰ — جریان نقدی ════ -->
    <div class="rep-panel" id="panel-10">
        <div id="cfLoading" class="rep-loading"><i class="fas fa-spinner"></i>در حال بارگذاری...</div>
        <div id="cfContent" style="display:none">
            <div class="rep-summary-cards" style="grid-template-columns:repeat(3,1fr)">
                <div class="rep-card success">
                    <div class="rep-card-icon">💵</div>
                    <div class="rep-card-value" id="cf-in">—</div>
                    <div class="rep-card-label">جمع وصولی (ورودی)</div>
                </div>
                <div class="rep-card danger">
                    <div class="rep-card-icon">💸</div>
                    <div class="rep-card-value" id="cf-out">—</div>
                    <div class="rep-card-label">جمع پرداختی (خروجی)</div>
                </div>
                <div class="rep-card primary">
                    <div class="rep-card-icon">📊</div>
                    <div class="rep-card-value" id="cf-net">—</div>
                    <div class="rep-card-label" id="cf-net-lbl">خالص جریان نقدی</div>
                </div>
            </div>
            <div class="rep-chart-box" style="margin-bottom:20px">
                <div class="rep-chart-title"><i class="fas fa-water" style="color:#6366f1"></i> جریان نقدی ماهانه</div>
                <div class="chart-canvas-wrap" style="height:300px"><canvas id="cfChart"></canvas></div>
            </div>
            <div class="rep-table-wrap">
                <table class="rep-table">
                    <thead><tr><th>ماه</th><th>ورودی</th><th>خروجی</th><th>خالص</th></tr></thead>
                    <tbody id="cfTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /rep-page -->

<script>
/* ══════════════════════════════════════════════════════════════════
   گزارش‌های مالی — اسکریپت‌های رویه‌ای
   ══════════════════════════════════════════════════════════════════ */

// متغیرهای سراسری
let activeTab       = 1;
let barChartInst    = null;   // نمونه نمودار میله‌ای فروش
let doughnutInst    = null;   // نمودار دونات
let expenseChartInst= null;   // نمودار هزینه‌ها
let tabsLoaded      = {};     // کدام تب‌ها بارگذاری شده‌اند

// ── تبدیل عدد به فرمت فارسی ──
function numFa(n) {
    if (n === null || n === undefined || n === '') return '—';
    return new Intl.NumberFormat('fa-IR').format(n);
}
function moneyFa(n) { return numFa(n) + ' تومان'; }

// ── تبدیل نوع سند به فارسی ──
function docTypeFa(t) {
    const m = {sell:'فروش',buy:'خرید',receive:'دریافت',pay:'پرداخت',
               cheque_receive:'چک دریافتی',cheque_pay:'چک پرداختی',
               transfer:'انتقال',manual:'دستی'};
    return m[t] || t;
}

// ── تبدیل وضعیت چک به فارسی + کلاس ──
function chequeStatusFa(s) {
    const m = {pending:['در جریان','badge-blue'],cleared:['وصول شده','badge-green'],
               bounced:['برگشتی','badge-red'],transferred:['منتقل‌شده','badge-gray']};
    return m[s] || [s,'badge-gray'];
}
function chequeTypeFa(t) { return t === 'received' ? 'دریافتنی' : 'پرداختنی'; }

// ── تبدیل نوع حساب به فارسی ──
function accTypeFa(t) {
    const m = {asset:'دارایی',liability:'بدهی',equity:'حقوق صاحبان',revenue:'درآمد',expense:'هزینه'};
    return m[t] || t;
}

// ── گرفتن سال مالی انتخاب‌شده ──
function getFyId() {
    const sel = document.getElementById('globalFySelect');
    return sel ? parseInt(sel.value) || 0 : 0;
}

// ── تغییر سال مالی ──
function onFyChange() {
    tabsLoaded = {};   // پاکسازی کش تب‌ها
    switchTab(activeTab, true);
}

// ── تغییر تب ──
function switchTab(n, forceReload) {
    activeTab = n;
    // پنهان کردن همه پانل‌ها
    document.querySelectorAll('.rep-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.rep-tab-btn').forEach(b => b.classList.remove('active'));
    // فعال‌سازی پانل و دکمه
    const panel = document.getElementById('panel-' + n);
    const btn   = document.getElementById('tab-btn-' + n);
    if (panel) panel.classList.add('active');
    if (btn)   btn.classList.add('active');

    // بارگذاری داده اگر بارگذاری نشده یا reload اجباری
    if (!tabsLoaded[n] || forceReload) {
        tabsLoaded[n] = true;
        const fy = getFyId();
        if      (n === 1) loadSalesChart(fy);
        else if (n === 2) loadIncomeStatement(fy);
        else if (n === 3) loadTrialBalance(fy);
        else if (n === 4) { /* دفتر حساب — منتظر انتخاب کاربر */ }
        else if (n === 5) loadAging(fy);
        else if (n === 6) loadCheques();
        else if (n === 7) loadBalanceSheet(fy);
        else if (n === 8) { /* مقایسه دوره‌ای — منتظر انتخاب کاربر */ }
        else if (n === 9) loadPurchasesReport(fy);
        else if (n === 10) loadCashflow(fy);
        else if (n === 11) loadCogsReport(fy);
    }
}

// ══════════════════════════════════════════════════════════════════
// تب ۱ — نمودار فروش
// ══════════════════════════════════════════════════════════════════
function loadSalesChart(fyId) {
    document.getElementById('salesLoading').style.display = '';
    document.getElementById('salesContent').style.display = 'none';

    fetch('fin_reports.php?action=sales_chart&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        document.getElementById('salesLoading').style.display = 'none';
        document.getElementById('salesContent').style.display = '';

        if (!res.ok) return;
        const d = res.data;

        // کارت‌های خلاصه
        document.getElementById('sc-total').textContent       = moneyFa(d.total_sales);
        document.getElementById('sc-paid').textContent        = moneyFa(d.total_paid);
        document.getElementById('sc-outstanding').textContent = moneyFa(d.total_outstanding);
        document.getElementById('sc-count').textContent       = numFa(d.invoice_count) + ' فاکتور';

        // نمودار میله‌ای
        const barCtx = document.getElementById('barChartSales').getContext('2d');
        if (barChartInst) barChartInst.destroy();
        barChartInst = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: d.labels,
                datasets: [
                    {
                        label: 'فروش',
                        data: d.sales,
                        backgroundColor: 'rgba(79,70,229,.75)',
                        borderRadius: 6,
                        borderSkipped: false,
                    },
                    {
                        label: 'وصولی',
                        data: d.collected,
                        backgroundColor: 'rgba(5,150,105,.7)',
                        borderRadius: 6,
                        borderSkipped: false,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', rtl: true, labels: { font: { family: 'Vazirmatn,Tahoma' } } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + numFa(ctx.parsed.y) + ' تومان'
                        },
                        bodyFont: { family: 'Vazirmatn,Tahoma' },
                        titleFont: { family: 'Vazirmatn,Tahoma' }
                    }
                },
                scales: {
                    x: { ticks: { font: { family: 'Vazirmatn,Tahoma' } } },
                    y: { ticks: { font: { family: 'Vazirmatn,Tahoma' }, callback: v => numFa(v) } }
                }
            }
        });

        // نمودار دونات
        const doCtx = document.getElementById('doughnutChart').getContext('2d');
        if (doughnutInst) doughnutInst.destroy();
        doughnutInst = new Chart(doCtx, {
            type: 'doughnut',
            data: {
                labels: ['وصولی', 'مطالبات باز'],
                datasets: [{
                    data: [d.total_paid, Math.max(0, d.total_outstanding)],
                    backgroundColor: ['rgba(5,150,105,.8)', 'rgba(244,63,94,.7)'],
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', rtl: true, labels: { font: { family: 'Vazirmatn,Tahoma' }, padding: 16 } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + numFa(ctx.parsed) + ' تومان'
                        },
                        bodyFont: { family: 'Vazirmatn,Tahoma' }
                    }
                },
                cutout: '62%'
            }
        });
    })
    .catch(() => {
        document.getElementById('salesLoading').innerHTML = '<span style="color:#ef4444">خطا در بارگذاری</span>';
    });
}

// ══════════════════════════════════════════════════════════════════
// تب ۲ — سود و زیان
// ══════════════════════════════════════════════════════════════════
function loadIncomeStatement(fyId) {
    document.getElementById('pnlLoading').style.display = '';
    document.getElementById('pnlContent').style.display = 'none';

    fetch('fin_reports.php?action=income_statement&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        document.getElementById('pnlLoading').style.display = 'none';
        document.getElementById('pnlContent').style.display = '';
        if (!res.ok) return;
        const d = res.data;

        const netCls = d.net_profit >= 0 ? 'pnl-positive' : 'pnl-negative';
        const netLabel = d.net_profit >= 0 ? 'سود خالص' : 'زیان خالص';

        document.getElementById('pnlStatement').innerHTML = `
            <h3 style="margin:0 0 20px;font-size:1.1rem;color:#1e293b;border-bottom:2px solid #e2e8f0;padding-bottom:12px">
                <i class="fas fa-balance-scale" style="color:#4f46e5;margin-left:8px"></i>صورت سود و زیان
            </h3>
            <div class="pnl-row">
                <span class="pnl-label">درآمد فروش خالص</span>
                <span class="pnl-amount">${moneyFa(d.revenue)}</span>
            </div>
            <div class="pnl-row indent">
                <span class="pnl-label">(–) بهای تمام‌شده کالا</span>
                <span class="pnl-amount" style="color:#ef4444">${moneyFa(d.cost_of_goods)}</span>
            </div>
            <hr class="pnl-divider">
            <div class="pnl-row" style="font-weight:600">
                <span class="pnl-label">سود ناخالص</span>
                <span class="pnl-amount ${d.gross_profit >= 0 ? 'pnl-positive' : 'pnl-negative'}">${moneyFa(d.gross_profit)}</span>
            </div>
            <hr class="pnl-divider">
            <div class="pnl-row indent">
                <span class="pnl-label">(–) هزینه‌های عملیاتی</span>
                <span class="pnl-amount" style="color:#ef4444">${moneyFa(d.expenses)}</span>
            </div>
            <div class="pnl-row indent">
                <span class="pnl-label">(–) مالیات بر ارزش افزوده</span>
                <span class="pnl-amount" style="color:#ef4444">${moneyFa(d.tax)}</span>
            </div>
            <hr class="pnl-divider solid">
            <div class="pnl-total-row">
                <span class="pnl-label">${netLabel}</span>
                <span class="pnl-amount ${netCls}">${moneyFa(Math.abs(d.net_profit))}</span>
            </div>
        `;

        // نمودار هزینه‌ها
        const expCtx = document.getElementById('expenseChart').getContext('2d');
        if (expenseChartInst) expenseChartInst.destroy();

        let expLabels = [], expVals = [];
        if (d.expense_cats && d.expense_cats.length > 0) {
            d.expense_cats.forEach(c => { expLabels.push(c.cat); expVals.push(parseInt(c.total)); });
        } else {
            expLabels = ['هزینه‌های عملیاتی','مالیات'];
            expVals   = [d.expenses, d.tax];
        }

        expenseChartInst = new Chart(expCtx, {
            type: 'bar',
            data: {
                labels: expLabels,
                datasets: [{
                    data: expVals,
                    backgroundColor: ['rgba(245,158,11,.8)','rgba(239,68,68,.7)','rgba(79,70,229,.7)',
                                      'rgba(5,150,105,.7)','rgba(244,63,94,.7)','rgba(99,102,241,.7)',
                                      'rgba(20,184,166,.7)','rgba(168,85,247,.7)'],
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => ' ' + numFa(ctx.parsed.x) + ' تومان' },
                               bodyFont: { family: 'Vazirmatn,Tahoma' } }
                },
                scales: {
                    x: { ticks: { font:{family:'Vazirmatn,Tahoma'}, callback: v => numFa(v) } },
                    y: { ticks: { font:{family:'Vazirmatn,Tahoma'} } }
                }
            }
        });
    })
    .catch(() => {
        document.getElementById('pnlLoading').innerHTML = '<span style="color:#ef4444">خطا در بارگذاری</span>';
    });
}

// ══════════════════════════════════════════════════════════════════
// تب ۳ — تراز آزمایشی
// ══════════════════════════════════════════════════════════════════
function loadTrialBalance(fyId) {
    document.getElementById('tbLoading').style.display = '';
    document.getElementById('tbContent').style.display = 'none';

    fetch('fin_reports.php?action=trial_balance&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        document.getElementById('tbLoading').style.display = 'none';
        document.getElementById('tbContent').style.display = '';
        if (!res.ok) return;

        const rows  = res.data;
        let sumD = 0, sumC = 0;
        let tbody = '';

        rows.forEach(r => {
            const bal     = parseInt(r.balance);
            const debit   = parseInt(r.total_debit);
            const credit  = parseInt(r.total_credit);
            sumD += debit; sumC += credit;

            let balCls = '';
            if      (bal > 0) balCls = 'debit-balance';
            else if (bal < 0) balCls = 'credit-balance';

            tbody += `<tr>
                <td><code>${r.code}</code></td>
                <td>${r.name}</td>
                <td><span class="badge badge-gray">${accTypeFa(r.type)}</span></td>
                <td style="direction:ltr;text-align:left">${numFa(debit)}</td>
                <td style="direction:ltr;text-align:left">${numFa(credit)}</td>
                <td class="${balCls}" style="direction:ltr;text-align:left">${numFa(Math.abs(bal))}</td>
            </tr>`;
        });

        document.getElementById('tbBody').innerHTML = tbody || '<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:32px">رکوردی یافت نشد</td></tr>';

        // ردیف جمع
        document.getElementById('tbFoot').innerHTML = `
            <tr class="total-row">
                <td colspan="3">جمع کل</td>
                <td style="direction:ltr;text-align:left">${numFa(sumD)}</td>
                <td style="direction:ltr;text-align:left">${numFa(sumC)}</td>
                <td style="direction:ltr;text-align:left">${numFa(sumD - sumC)}</td>
            </tr>`;

        // هشدار عدم تراز
        const warnBox = document.getElementById('tbWarnBox');
        warnBox.style.display = (sumD !== sumC && rows.length > 0) ? '' : 'none';
    })
    .catch(() => {
        document.getElementById('tbLoading').innerHTML = '<span style="color:#ef4444">خطا در بارگذاری</span>';
    });
}

// ══════════════════════════════════════════════════════════════════
// تب ۴ — دفتر حساب
// ══════════════════════════════════════════════════════════════════
function loadLedger() {
    const accountId = document.getElementById('ledgerAccountSelect').value;
    if (!accountId) {
        alert('لطفاً یک حساب انتخاب کنید.');
        return;
    }
    const fyId = getFyId();

    document.getElementById('ledgerLoading').style.display = '';
    document.getElementById('ledgerContent').innerHTML = '';

    fetch('fin_reports.php?action=account_ledger&account_id=' + accountId + '&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        document.getElementById('ledgerLoading').style.display = 'none';
        if (!res.ok) return;

        const rows = res.data;
        let tbody   = '';

        rows.forEach(r => {
            const bal    = parseInt(r.running_balance);
            const balCls = bal > 0 ? 'debit-balance' : (bal < 0 ? 'neg-balance' : '');
            tbody += `<tr>
                <td>${r.doc_date}</td>
                <td>${r.doc_number || '—'}</td>
                <td><span class="badge badge-gray">${docTypeFa(r.type)}</span></td>
                <td style="direction:ltr;text-align:left">${parseInt(r.debit) > 0 ? numFa(r.debit) : '—'}</td>
                <td style="direction:ltr;text-align:left">${parseInt(r.credit) > 0 ? numFa(r.credit) : '—'}</td>
                <td class="${balCls}" style="direction:ltr;text-align:left;font-weight:700">${numFa(Math.abs(bal))}</td>
                <td style="font-size:.82rem;color:#64748b">${r.description || ''}</td>
            </tr>`;
        });

        document.getElementById('ledgerContent').innerHTML = `
            <div class="rep-table-wrap">
                <table class="rep-table">
                    <thead>
                        <tr>
                            <th>تاریخ</th>
                            <th>شماره سند</th>
                            <th>نوع</th>
                            <th>بدهکار</th>
                            <th>بستانکار</th>
                            <th>مانده تجمعی</th>
                            <th>شرح</th>
                        </tr>
                    </thead>
                    <tbody>${tbody || '<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:32px">هیچ گردشی یافت نشد</td></tr>'}</tbody>
                </table>
            </div>`;
    })
    .catch(() => {
        document.getElementById('ledgerLoading').style.display = 'none';
        document.getElementById('ledgerContent').innerHTML = '<p style="color:#ef4444;text-align:center">خطا در بارگذاری دفتر حساب.</p>';
    });
}

// ══════════════════════════════════════════════════════════════════
// تب ۵ — مطالبات معوق
// ══════════════════════════════════════════════════════════════════
function loadAging(fyId) {
    document.getElementById('agingLoading').style.display = '';
    document.getElementById('agingContent').style.display = 'none';

    fetch('fin_reports.php?action=invoice_aging&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        document.getElementById('agingLoading').style.display = 'none';
        document.getElementById('agingContent').style.display = '';
        if (!res.ok) return;

        const rows = res.data;
        let tbody = '', totalOut = 0, totalAge = 0;

        rows.forEach(r => {
            const age = parseInt(r.age_days) || 0;
            totalOut += parseInt(r.outstanding);
            totalAge += age;

            let ageCls = '', ageBadge = 'badge-green';
            if      (age > 90) { ageCls = 'badge-red';   ageBadge = 'badge-red';   }
            else if (age > 30) { ageCls = 'badge-amber';  ageBadge = 'badge-amber'; }

            tbody += `<tr>
                <td>${r.invoice_number || '—'}</td>
                <td>${r.customer || '—'}</td>
                <td>${r.invoice_date || '—'}</td>
                <td style="direction:ltr;text-align:left">${numFa(r.total_amount)}</td>
                <td style="direction:ltr;text-align:left">${numFa(r.paid_amount)}</td>
                <td style="direction:ltr;text-align:left;font-weight:600;color:#ef4444">${numFa(r.outstanding)}</td>
                <td><span class="badge ${ageBadge}">${numFa(age)} روز</span></td>
                <td><span class="badge ${ageBadge}">${age > 90 ? 'بحرانی' : age > 30 ? 'در خطر' : 'عادی'}</span></td>
            </tr>`;
        });

        document.getElementById('agingBody').innerHTML = tbody || '<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:32px">مطالبات معوقی یافت نشد</td></tr>';

        const avgAge = rows.length > 0 ? Math.round(totalAge / rows.length) : 0;
        document.getElementById('agingSummary').innerHTML = `
            <div class="rep-card warning" style="padding:14px 18px">
                <div class="rep-card-value" style="font-size:1.1rem">${numFa(rows.length)}</div>
                <div class="rep-card-label">تعداد فاکتور معوق</div>
            </div>
            <div class="rep-card danger" style="padding:14px 18px">
                <div class="rep-card-value" style="font-size:1.1rem">${numFa(totalOut)}</div>
                <div class="rep-card-label">مجموع مانده (تومان)</div>
            </div>
            <div class="rep-card" style="padding:14px 18px">
                <div class="rep-card-value" style="font-size:1.1rem">${numFa(avgAge)} روز</div>
                <div class="rep-card-label">میانگین عمر</div>
            </div>`;
    })
    .catch(() => {
        document.getElementById('agingLoading').innerHTML = '<span style="color:#ef4444">خطا در بارگذاری</span>';
    });
}

// ══════════════════════════════════════════════════════════════════
// تب ۶ — گزارش چک
// ══════════════════════════════════════════════════════════════════
function loadCheques() {
    const ctype  = document.getElementById('chequeTypeFilter').value;
    const status = document.getElementById('chequeStatusFilter').value;

    document.getElementById('chequeLoading').style.display = '';
    document.getElementById('chequeContent').style.display = 'none';

    const url = `fin_reports.php?action=cheque_report&cheque_type=${encodeURIComponent(ctype)}&cheque_status=${encodeURIComponent(status)}`;
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(res => {
        document.getElementById('chequeLoading').style.display = 'none';
        document.getElementById('chequeContent').style.display = '';
        if (!res.ok) return;

        const rows = res.data;
        let tbody = '', sumReceived = 0, sumIssued = 0;

        rows.forEach(r => {
            const [statusLabel, statusBadge] = chequeStatusFa(r.status);
            const typeBadge = r.type === 'received' ? 'badge-green' : 'badge-blue';
            if (r.type === 'received') sumReceived += parseInt(r.amount);
            else sumIssued += parseInt(r.amount);

            tbody += `<tr>
                <td>${r.cheque_number || '—'}</td>
                <td><span class="badge ${typeBadge}">${chequeTypeFa(r.type)}</span></td>
                <td>${r.bank_name || '—'}</td>
                <td>${r.pname || '—'}</td>
                <td style="direction:ltr;text-align:left">${numFa(r.amount)}</td>
                <td>${r.due_date || '—'}</td>
                <td><span class="badge ${statusBadge}">${statusLabel}</span></td>
            </tr>`;
        });

        document.getElementById('chequeBody').innerHTML = tbody || '<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:32px">چکی یافت نشد</td></tr>';

        document.getElementById('chequeSumCard').innerHTML = `
            <div class="cheque-sum-item">
                <span class="cs-label">مجموع چک‌های دریافتی</span>
                <span class="cs-val" style="color:#059669">${moneyFa(sumReceived)}</span>
            </div>
            <div class="cheque-sum-item">
                <span class="cs-label">مجموع چک‌های پرداختنی</span>
                <span class="cs-val" style="color:#ef4444">${moneyFa(sumIssued)}</span>
            </div>
            <div class="cheque-sum-item">
                <span class="cs-label">تعداد کل</span>
                <span class="cs-val">${numFa(rows.length)} چک</span>
            </div>`;
    })
    .catch(() => {
        document.getElementById('chequeLoading').innerHTML = '<span style="color:#ef4444">خطا در بارگذاری</span>';
    });
}

// ══════════════════════════════════════════════════════════════════
// تب ۷ — ترازنامه
// ══════════════════════════════════════════════════════════════════
function loadBalanceSheet(fyId) {
    document.getElementById('bsLoading').style.display = '';
    document.getElementById('bsContent').style.display = 'none';

    fetch('fin_reports.php?action=balance_sheet&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        document.getElementById('bsLoading').style.display = 'none';
        document.getElementById('bsContent').style.display = '';
        if (!res.ok) return;

        // ── ساخت ردیف‌های جدول ──
        function buildBsTable(items, rowCls) {
            if (!items || items.length === 0) {
                return '<tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:20px">موردی یافت نشد</td></tr>';
            }
            return items.map(it => `<tr>
                <td><code style="font-size:.8rem">${it.code}</code></td>
                <td>${it.name}</td>
                <td class="${rowCls}" style="direction:ltr;text-align:left;font-weight:600">${numFa(it.balance)}</td>
            </tr>`).join('');
        }

        const totalA  = res.total_assets;
        const totalLE = res.total_liabilities_equity;
        const isBalanced = (totalA === totalLE);

        // ── ستون دارایی‌ها ──
        const assetHtml = `
            <div class="rep-table-wrap" style="padding:0">
                <div class="bs-section-title asset-title">
                    <i class="fas fa-cubes"></i> دارایی‌ها
                </div>
                <table class="rep-table">
                    <thead><tr>
                        <th>کد</th><th>نام حساب</th><th>مانده (تومان)</th>
                    </tr></thead>
                    <tbody>${buildBsTable(res.assets, 'debit-balance')}</tbody>
                </table>
                <div class="bs-total-row asset-total">
                    <span>جمع دارایی‌ها</span>
                    <span style="direction:ltr">${numFa(totalA)}</span>
                </div>
            </div>`;

        // ── ستون بدهی‌ها + حقوق ──
        const liabRows   = buildBsTable(res.liabilities, 'credit-balance');
        const equityRows = buildBsTable(res.equity, 'credit-balance');
        const liabHtml = `
            <div class="rep-table-wrap" style="padding:0">
                <div class="bs-section-title liab-title">
                    <i class="fas fa-file-invoice-dollar"></i> بدهی‌ها و حقوق صاحبان
                </div>
                <table class="rep-table">
                    <thead><tr>
                        <th>کد</th><th>نام حساب</th><th>مانده (تومان)</th>
                    </tr></thead>
                    <tbody>
                        ${res.liabilities.length > 0 ? '<tr><td colspan="3" style="background:#fffbeb;font-weight:700;padding:8px 14px;font-size:.82rem;color:#92400e">بدهی‌ها</td></tr>' + liabRows : ''}
                        ${res.equity.length > 0 ? '<tr><td colspan="3" style="background:#faf5ff;font-weight:700;padding:8px 14px;font-size:.82rem;color:#6b21a8">حقوق صاحبان سهام</td></tr>' + equityRows : ''}
                    </tbody>
                </table>
                <div class="bs-total-row liab-total">
                    <span>جمع بدهی‌ها + حقوق</span>
                    <span style="direction:ltr">${numFa(totalLE)}</span>
                </div>
            </div>`;

        document.getElementById('bsGrid').innerHTML = assetHtml + liabHtml;

        // ── وضعیت تراز ──
        if (isBalanced) {
            document.getElementById('bsBalanceStatus').innerHTML =
                '<div class="bs-balance-status ok"><i class="fas fa-check-circle"></i> ترازنامه متعادل است ✅</div>';
        } else {
            const diff = Math.abs(totalA - totalLE);
            document.getElementById('bsBalanceStatus').innerHTML =
                `<div class="bs-balance-status bad"><i class="fas fa-exclamation-triangle"></i> عدم تراز ⚠️ — اختلاف: ${numFa(diff)} تومان</div>`;
        }
    })
    .catch(() => {
        document.getElementById('bsLoading').innerHTML = '<span style="color:#ef4444">خطا در بارگذاری</span>';
    });
}

// ══════════════════════════════════════════════════════════════════
// تب ۸ — مقایسه دوره‌ای
// ══════════════════════════════════════════════════════════════════
function loadPeriodComparison() {
    const year1  = parseInt(document.getElementById('cmpYear1').value)  || 0;
    const month1 = parseInt(document.getElementById('cmpMonth1').value) || 0;
    const year2  = parseInt(document.getElementById('cmpYear2').value)  || 0;
    const month2 = parseInt(document.getElementById('cmpMonth2').value) || 0;

    if (!year1 || !year2) {
        alert('لطفاً سال هر دو دوره را وارد کنید.');
        return;
    }

    document.getElementById('cmpLoading').style.display = '';
    document.getElementById('cmpContent').innerHTML = '';

    const url = `fin_reports.php?action=period_comparison&year1=${year1}&month1=${month1}&year2=${year2}&month2=${month2}`;
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(res => {
        document.getElementById('cmpLoading').style.display = 'none';
        if (!res.ok) {
            document.getElementById('cmpContent').innerHTML = `<p style="color:#ef4444;text-align:center">${res.msg || 'خطا'}</p>`;
            return;
        }

        // ── برچسب دوره‌ها ──
        const monthNames = ['','ژانویه','فوریه','مارس','آوریل','مه','ژوئن','جولای','اوت','سپتامبر','اکتبر','نوامبر','دسامبر'];
        const lbl1 = `${monthNames[month1] || month1} ${year1}`;
        const lbl2 = `${monthNames[month2] || month2} ${year2}`;

        const p1 = res.period1;
        const p2 = res.period2;

        // ── تابع نمایش تغییر درصدی ──
        function deltaPct(v1, v2) {
            if (v1 === 0 && v2 === 0) return '<span class="cmp-neutral">—</span>';
            if (v1 === 0) return '<span class="cmp-positive">جدید</span>';
            const pct = ((v2 - v1) / v1 * 100).toFixed(1);
            const cls = pct > 0 ? 'cmp-positive' : (pct < 0 ? 'cmp-negative' : 'cmp-neutral');
            const sign = pct > 0 ? '+' : '';
            return `<span class="${cls}">${sign}${numFa(pct)}٪</span>`;
        }

        // ── معیارها ──
        const metrics = [
            { label: 'فروش کل (تومان)',      k: 'revenue',       money: true },
            { label: 'وصولی (تومان)',         k: 'paid',          money: true },
            { label: 'هزینه‌ها (تومان)',      k: 'expenses',      money: true },
            { label: 'تعداد فاکتور',          k: 'invoice_count', money: false },
            { label: 'چک دریافتی (تومان)',    k: 'new_cheques',   money: true },
        ];

        let tbody = '';
        metrics.forEach(m => {
            const v1 = parseInt(p1[m.k]) || 0;
            const v2 = parseInt(p2[m.k]) || 0;
            const fmt = m.money ? moneyFa : numFa;
            tbody += `<tr>
                <td class="metric-col">${m.label}</td>
                <td style="direction:ltr;text-align:left">${fmt(v1)}</td>
                <td style="direction:ltr;text-align:left">${fmt(v2)}</td>
                <td>${deltaPct(v1, v2)}</td>
            </tr>`;
        });

        document.getElementById('cmpContent').innerHTML = `
            <div class="cmp-table-wrap">
                <table class="cmp-table">
                    <thead><tr>
                        <th>شاخص</th>
                        <th>${lbl1}</th>
                        <th>${lbl2}</th>
                        <th>تغییر (Δ٪)</th>
                    </tr></thead>
                    <tbody>${tbody}</tbody>
                </table>
            </div>`;
    })
    .catch(() => {
        document.getElementById('cmpLoading').style.display = 'none';
        document.getElementById('cmpContent').innerHTML = '<p style="color:#ef4444;text-align:center">خطا در بارگذاری مقایسه.</p>';
    });
}

// ══════════════════════════════════════════════════════════════════
// خروجی CSV
// ══════════════════════════════════════════════════════════════════
function exportCsv(type) {
    const fyId = getFyId();
    window.location.href = `fin_reports.php?action=export_csv&report_type=${encodeURIComponent(type)}&fiscal_year_id=${fyId}`;
}

// ══════════════════════════════════════════════════════════════════
// خروجی Excel (xlsx)
// ══════════════════════════════════════════════════════════════════
function exportXlsx(type) {
    const fyId = getFyId();
    window.location.href = `fin_reports.php?action=export_xlsx&report_type=${encodeURIComponent(type)}&fiscal_year_id=${fyId}`;
}

// خروجی بر اساس تب جاری — دکمه هدر صفحه
function exportCurrentTab() {
    const xlsxMap = {1:'sales',2:'sales',3:'trial_balance',4:'trial_balance',5:'invoice_aging',6:'cheque_report',7:'trial_balance',8:'sales',9:'sales',10:'sales'};
    exportXlsx(xlsxMap[activeTab] || 'sales');
}

// ══════════════════════════════════════════════════════════════════
// تب ۹ — گزارش خرید
// ══════════════════════════════════════════════════════════════════
let purchaseChartInst = null;
function loadPurchasesReport(fyId) {
    document.getElementById('purchLoading').style.display = '';
    document.getElementById('purchContent').style.display = 'none';
    fetch('fin_reports.php?action=purchases_report&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json()).then(res => {
        document.getElementById('purchLoading').style.display = 'none';
        document.getElementById('purchContent').style.display = '';
        if (!res.ok) return;
        const d = res.data;

        document.getElementById('pur-total').textContent   = moneyFa(d.total_purchase);
        document.getElementById('pur-paid').textContent    = moneyFa(d.total_paid);
        document.getElementById('pur-out').textContent     = moneyFa(d.total_outstanding);
        document.getElementById('pur-count').textContent   = numFa(d.invoice_count) + ' فاکتور';

        const ctx = document.getElementById('purchBarChart').getContext('2d');
        if (purchaseChartInst) purchaseChartInst.destroy();
        purchaseChartInst = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: d.labels,
                datasets: [
                    { label: 'خرید کل', data: d.purchases, backgroundColor: 'rgba(239,68,68,.75)', borderRadius: 5 },
                    { label: 'پرداخت‌شده', data: d.paid, backgroundColor: 'rgba(16,185,129,.75)', borderRadius: 5 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } },
                scales: { y: { ticks: { callback: v => numFa(v) } } } }
        });

        let tbody = '';
        (d.top_suppliers || []).forEach((s, i) => {
            tbody += `<tr>
                <td>${i+1}</td>
                <td>${s.supplier || '—'}</td>
                <td>${numFa(s.cnt)} فاکتور</td>
                <td style="direction:ltr;text-align:left;font-weight:600;color:#1e293b">${moneyFa(s.total)}</td>
            </tr>`;
        });
        document.getElementById('supplierBody').innerHTML = tbody || '<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:24px">داده‌ای یافت نشد</td></tr>';
    }).catch(() => {
        document.getElementById('purchLoading').innerHTML = '<span style="color:#ef4444">خطا در بارگذاری</span>';
    });
}

// ══════════════════════════════════════════════════════════════════
// تب ۱۰ — جریان نقدی
// ══════════════════════════════════════════════════════════════════
let cashflowChartInst = null;
function loadCashflow(fyId) {
    document.getElementById('cfLoading').style.display = '';
    document.getElementById('cfContent').style.display = 'none';
    fetch('fin_reports.php?action=cashflow_report&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json()).then(res => {
        document.getElementById('cfLoading').style.display = 'none';
        document.getElementById('cfContent').style.display = '';
        if (!res.ok) return;
        const d = res.data;

        document.getElementById('cf-in').textContent    = moneyFa(d.total_in);
        document.getElementById('cf-out').textContent   = moneyFa(d.total_out);
        const net = d.net_total;
        const cfNetEl = document.getElementById('cf-net');
        cfNetEl.textContent = moneyFa(Math.abs(net));
        cfNetEl.style.color = net >= 0 ? '#059669' : '#dc2626';
        document.getElementById('cf-net-lbl').textContent = net >= 0 ? 'جریان مثبت ✅' : 'جریان منفی ⚠️';

        const ctx = document.getElementById('cfChart').getContext('2d');
        if (cashflowChartInst) cashflowChartInst.destroy();
        cashflowChartInst = new Chart(ctx, {
            type: 'line',
            data: {
                labels: d.labels,
                datasets: [
                    { label: 'ورودی (وصولی)', data: d.inflow,  borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.12)', fill: true, tension: .35, borderWidth: 2 },
                    { label: 'خروجی (پرداختی)', data: d.outflow, borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,.08)', fill: true, tension: .35, borderWidth: 2 },
                    { label: 'خالص جریان', data: d.net, borderColor: '#6366f1', borderDash: [5,3], fill: false, tension: .35, borderWidth: 2, pointRadius: 4 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: { y: { ticks: { callback: v => numFa(v) } } }
            }
        });

        // جدول ماهانه
        let tbody = '';
        (d.labels || []).forEach((m, i) => {
            const inf = d.inflow[i]  || 0;
            const out = d.outflow[i] || 0;
            const net = d.net[i]     || 0;
            tbody += `<tr>
                <td>${m}</td>
                <td style="direction:ltr;text-align:left;color:#059669">${moneyFa(inf)}</td>
                <td style="direction:ltr;text-align:left;color:#ef4444">${moneyFa(out)}</td>
                <td style="direction:ltr;text-align:left;font-weight:700;color:${net>=0?'#059669':'#dc2626'}">${moneyFa(Math.abs(net))}</td>
            </tr>`;
        });
        document.getElementById('cfTableBody').innerHTML = tbody || '<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:24px">داده‌ای یافت نشد</td></tr>';
    }).catch(() => {
        document.getElementById('cfLoading').innerHTML = '<span style="color:#ef4444">خطا در بارگذاری</span>';
    });
}

// ══════════════════════════════════════════════════════════════════
// KPI Bar بالای صفحه
// ══════════════════════════════════════════════════════════════════
function loadKpiBar(fyId) {
    fetch('fin_reports.php?action=kpi_summary&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json()).then(res => {
        if (!res.ok) return;
        const d = res.data;
        document.getElementById('kpiSales').textContent       = moneyFa(d.total_sales);
        document.getElementById('kpiCollected').textContent   = moneyFa(d.total_collected);
        document.getElementById('kpiOutstanding').textContent = moneyFa(d.total_outstanding);
        document.getElementById('kpiOverdue').textContent     = numFa(d.overdue_invoices) + ' فاکتور';
        document.getElementById('kpiCheques').textContent     = numFa(d.pending_cheques) + ' چک';
        const profitEl = document.getElementById('kpiProfit');
        profitEl.textContent = moneyFa(Math.abs(d.net_profit));
        profitEl.style.color = d.net_profit >= 0 ? '#059669' : '#dc2626';
        document.getElementById('kpiBar').style.opacity = '1';
    }).catch(() => {});
}

// ══════════════════════════════════════════════════════════════════
// تب ۱۱ — بهای تمام‌شده COGS
// ══════════════════════════════════════════════════════════════════
let cogsChartInst = null;
function loadCogsReport(fyId) {
    document.getElementById('cogsLoading').style.display = '';
    document.getElementById('cogsContent').style.display = 'none';
    fetch('fin_reports.php?action=cogs_report&fiscal_year_id=' + fyId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        document.getElementById('cogsLoading').style.display = 'none';
        document.getElementById('cogsContent').style.display = '';
        if (!res.ok) return;
        const t = res.totals;
        document.getElementById('cogs-revenue').textContent = moneyFa(t.total_revenue);
        document.getElementById('cogs-cogs').textContent    = moneyFa(t.total_cogs);
        document.getElementById('cogs-gp').textContent      = moneyFa(t.gross_profit);
        document.getElementById('cogs-margin').textContent  = t.gross_margin + '%';
        // نمودار
        if (cogsChartInst) cogsChartInst.destroy();
        const ctx = document.getElementById('cogsChart').getContext('2d');
        cogsChartInst = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: res.labels,
                datasets: [
                    { label: 'درآمد', data: res.revenue, backgroundColor: 'rgba(99,102,241,.7)' },
                    { label: 'بهای تمام‌شده', data: res.cogs, backgroundColor: 'rgba(239,68,68,.6)' },
                    { label: 'سود ناخالص', data: res.gross_profit, backgroundColor: 'rgba(16,185,129,.6)' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
        // جدول محصولات
        const tbody = document.getElementById('cogsTopTable');
        tbody.innerHTML = '';
        (res.top_products || []).forEach(p => {
            tbody.innerHTML += `<tr>
                <td>${p.stuff_name || '—'}</td>
                <td>${numFa(p.qty)}</td>
                <td>${moneyFa(p.revenue)}</td>
            </tr>`;
        });
    })
    .catch(() => {
        document.getElementById('cogsLoading').style.display = 'none';
        document.getElementById('cogsContent').style.display = '';
    });
}

// ══════════════════════════════════════════════════════════════════
// شروع — بارگذاری تب اول
// ══════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function () {
    switchTab(1);
    loadKpiBar(getFyId());
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
