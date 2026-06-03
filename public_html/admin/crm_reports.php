<?php
/*
 * فایل: public_html/admin/crm_reports.php
 * ماژول CRM — تحلیل و گزارش‌گیری
 * شامل: قیف فروش، عملکرد کارشناسان، KPI ماهانه، تحلیل استان‌ها، تایم‌لاین فعالیت
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// بررسی درخواست AJAX
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// بررسی احراز هویت
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'نشست منقضی']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

// ─── تابع کمکی تبدیل تاریخ شمسی به میلادی ──────────────────────────────────
function jDateToGregorian(string $jdate): string {
    // ورودی YYYY/MM/DD شمسی
    $jdate = faToEn(trim($jdate));
    $parts = explode('/', $jdate);
    if (count($parts) !== 3) return '';
    list($jy, $jm, $jd) = [(int)$parts[0], (int)$parts[1], (int)$parts[2]];
    $result = jalali_to_gregorian($jy, $jm, $jd, '-');
    return $result;
}

// ─── پردازش درخواست‌های AJAX ─────────────────────────────────────────────────
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'] ?? '';

    switch ($action) {

        // ═══ داده‌های قیف فروش ═══════════════════════════════════════════════
        case 'funnel_data': {
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo   = $_GET['date_to']   ?? '';

            $params = [];
            $dateCondition = '';

            if ($dateFrom !== '' && $dateTo !== '') {
                $gFrom = jDateToGregorian($dateFrom);
                $gTo   = jDateToGregorian($dateTo);
                if ($gFrom && $gTo) {
                    $dateCondition = " AND o.created_at BETWEEN ? AND ?";
                    $params[] = $gFrom . ' 00:00:00';
                    $params[] = $gTo   . ' 23:59:59';
                }
            }

            try {
                $sql = "SELECT s.id, s.name AS stage_name, s.color,
                               COUNT(o.id) AS opp_count,
                               COALESCE(SUM(o.value), 0) AS total_value
                        FROM crm_board_stages s
                        LEFT JOIN crm_opportunities o
                            ON o.stage_id = s.id AND o.is_deleted = 0 {$dateCondition}
                        GROUP BY s.id, s.name, s.color, s.order_num
                        ORDER BY s.order_num ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // محاسبه جمع کل و مرحله آخر (برنده)
                $totalOpps   = 0;
                $totalValue  = 0;
                $maxCount    = 0;
                foreach ($rows as $r) {
                    $totalOpps  += (int)$r['opp_count'];
                    $totalValue += (float)$r['total_value'];
                    if ((int)$r['opp_count'] > $maxCount) $maxCount = (int)$r['opp_count'];
                }
                $wins = !empty($rows) ? (int)end($rows)['opp_count'] : 0;
                $winRate = $totalOpps > 0 ? round($wins / $totalOpps * 100, 1) : 0;
                $avgDeal = $totalOpps > 0 ? round($totalValue / $totalOpps) : 0;

                echo json_encode([
                    'ok'        => true,
                    'stages'    => $rows,
                    'max_count' => $maxCount,
                    'summary'   => [
                        'total_opps'  => $totalOpps,
                        'total_value' => $totalValue,
                        'win_rate'    => $winRate,
                        'avg_deal'    => $avgDeal,
                    ]
                ]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => 'خطا در بارگذاری قیف فروش', 'err' => $e->getMessage()]);
            }
            exit;
        }

        // ═══ عملکرد کارشناسان ════════════════════════════════════════════════
        case 'expert_performance': {
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo   = $_GET['date_to']   ?? '';

            $callDateCond = '';
            $callParams   = [];
            if ($dateFrom !== '' && $dateTo !== '') {
                $gFrom = jDateToGregorian($dateFrom);
                $gTo   = jDateToGregorian($dateTo);
                if ($gFrom && $gTo) {
                    $callDateCond = " AND c.created_at BETWEEN ? AND ?";
                    $callParams[] = $gFrom . ' 00:00:00';
                    $callParams[] = $gTo   . ' 23:59:59';
                }
            }

            try {
                // زیرکوئری: بیشترین order_num هر board
                $sql = "SELECT u.id, u.first_name, u.last_name,
                               COUNT(DISTINCT c.id) AS call_count,
                               COUNT(DISTINCT o.id) AS opp_count,
                               COALESCE(SUM(o.value), 0) AS total_value,
                               COUNT(DISTINCT CASE WHEN s.order_num = (
                                   SELECT MAX(s2.order_num) FROM crm_board_stages s2
                                   WHERE s2.board_id = o.board_id
                               ) THEN o.id END) AS wins
                        FROM users u
                        LEFT JOIN crm_opportunity_calls c
                            ON c.user_id = u.id {$callDateCond}
                        LEFT JOIN crm_opportunities o
                            ON o.assigned_to = u.id AND o.is_deleted = 0
                        LEFT JOIN crm_board_stages s ON s.id = o.stage_id
                        WHERE u.is_active = 1
                        GROUP BY u.id, u.first_name, u.last_name
                        ORDER BY total_value DESC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($callParams);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // محاسبه نرخ تبدیل
                foreach ($rows as &$r) {
                    $r['conversion_rate'] = $r['opp_count'] > 0
                        ? round((int)$r['wins'] / (int)$r['opp_count'] * 100, 1)
                        : 0;
                }
                unset($r);

                echo json_encode(['ok' => true, 'experts' => $rows]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => 'خطا در بارگذاری عملکرد کارشناسان', 'err' => $e->getMessage()]);
            }
            exit;
        }

        // ═══ تایم‌لاین فعالیت ════════════════════════════════════════════════
        case 'activity_timeline': {
            $userId = (int)($_GET['user_id'] ?? 0);
            $days   = (int)($_GET['days']    ?? 30);
            if (!in_array($days, [7, 30, 90])) $days = 30;

            $params   = [$days];
            $userCond = '';
            if ($userId > 0) {
                $userCond = " AND a.user_id = ?";
                $params[] = $userId;
            }

            try {
                $sql = "SELECT DATE(a.created_at) AS day,
                               a.type,
                               COUNT(*) AS cnt
                        FROM crm_opportunity_activities a
                        WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                              {$userCond}
                        GROUP BY day, a.type
                        ORDER BY day ASC, a.type ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['ok' => true, 'data' => $rows]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => 'خطا در بارگذاری تایم‌لاین', 'err' => $e->getMessage()]);
            }
            exit;
        }

        // ═══ آمار استان‌ها ════════════════════════════════════════════════════
        case 'province_stats': {
            try {
                // بررسی وجود جدول crm_provinces
                $checkStmt = $pdo->query("SHOW TABLES LIKE 'crm_provinces'");
                if ($checkStmt->rowCount() === 0) {
                    echo json_encode(['ok' => true, 'exists' => false, 'data' => []]);
                    exit;
                }

                $sql = "SELECT p.id,
                               COALESCE(p.province_name, p.name) AS province,
                               COUNT(DISTINCT c.id) AS customer_count,
                               COUNT(DISTINCT o.id) AS opp_count,
                               COALESCE(SUM(inv.total_amount), 0) AS sales_total,
                               CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS expert_name
                        FROM crm_provinces p
                        LEFT JOIN customers c
                            ON c.state = COALESCE(p.province_name, p.name)
                        LEFT JOIN crm_opportunities o
                            ON o.is_deleted = 0 AND EXISTS (
                                SELECT 1 FROM customers cc
                                WHERE cc.id = o.assigned_to
                                  AND cc.state = COALESCE(p.province_name, p.name)
                            )
                        LEFT JOIN fin_invoices inv
                            ON inv.opportunity_id = o.id AND inv.is_deleted = 0
                        LEFT JOIN crm_user_provinces cup ON cup.province_id = p.id
                        LEFT JOIN users u ON u.id = cup.user_id
                        GROUP BY p.id
                        ORDER BY customer_count DESC, sales_total DESC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['ok' => true, 'exists' => true, 'data' => $rows]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => 'خطا در بارگذاری آمار استان‌ها', 'err' => $e->getMessage()]);
            }
            exit;
        }

        // ═══ خلاصه KPI ═══════════════════════════════════════════════════════
        case 'kpi_summary': {
            $userId = (int)($_GET['user_id'] ?? 0);
            $month  = faToEn(trim($_GET['month'] ?? '')); // YYYY/MM شمسی

            // تبدیل ماه شمسی به محدوده میلادی
            $gMonthFrom = '';
            $gMonthTo   = '';
            if ($month !== '' && preg_match('/^\d{4}\/\d{2}$/', $month)) {
                $parts = explode('/', $month);
                $jy = (int)$parts[0];
                $jm = (int)$parts[1];
                // روز اول ماه
                $gFrom = jalali_to_gregorian($jy, $jm, 1, '-');
                // روز آخر ماه (۳۱ برای ۶ ماه اول، ۳۰ برای بقیه، اسفند ۲۹/۳۰)
                $jdLast = ($jm <= 6) ? 31 : (($jm <= 11) ? 30 : 29);
                $gTo    = jalali_to_gregorian($jy, $jm, $jdLast, '-');
                $gMonthFrom = $gFrom . ' 00:00:00';
                $gMonthTo   = $gTo   . ' 23:59:59';
            }

            $userCond   = $userId > 0 ? " AND user_id = ?" : "";
            $userParam  = $userId > 0 ? [$userId] : [];

            $result = [
                'calls'     => 0,
                'visits'    => 0,
                'new_opps'  => 0,
                'won_opps'  => 0,
                'revenue'   => 0,
            ];

            try {
                // تعداد تماس‌ها
                if ($gMonthFrom !== '') {
                    $sql = "SELECT COUNT(*) FROM crm_opportunity_calls
                            WHERE created_at BETWEEN ? AND ? {$userCond}";
                    $p = array_merge([$gMonthFrom, $gMonthTo], $userParam);
                } else {
                    $sql = "SELECT COUNT(*) FROM crm_opportunity_calls WHERE 1=1 {$userCond}";
                    $p = $userParam;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($p);
                $result['calls'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}

            try {
                // تعداد ویزیت‌ها (از جدول mission_requests)
                if ($gMonthFrom !== '') {
                    $sql = "SELECT COUNT(*) FROM mission_requests
                            WHERE created_at BETWEEN ? AND ? {$userCond}";
                    $p = array_merge([$gMonthFrom, $gMonthTo], $userParam);
                } else {
                    $sql = "SELECT COUNT(*) FROM mission_requests WHERE 1=1 {$userCond}";
                    $p = $userParam;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($p);
                $result['visits'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}

            try {
                // فرصت‌های جدید
                if ($gMonthFrom !== '') {
                    $sql = "SELECT COUNT(*) FROM crm_opportunities
                            WHERE is_deleted = 0 AND created_at BETWEEN ? AND ?";
                    $p = [$gMonthFrom, $gMonthTo];
                } else {
                    $sql = "SELECT COUNT(*) FROM crm_opportunities WHERE is_deleted = 0";
                    $p = [];
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($p);
                $result['new_opps'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}

            try {
                // فرصت‌های برنده شده (بیشترین order_num)
                if ($gMonthFrom !== '') {
                    $sql = "SELECT COUNT(*) FROM crm_opportunities o
                            JOIN crm_board_stages s ON s.id = o.stage_id
                            WHERE o.is_deleted = 0
                              AND o.updated_at BETWEEN ? AND ?
                              AND s.order_num = (
                                  SELECT MAX(order_num) FROM crm_board_stages
                                  WHERE board_id = o.board_id
                              )";
                    $p = [$gMonthFrom, $gMonthTo];
                } else {
                    $sql = "SELECT COUNT(*) FROM crm_opportunities o
                            JOIN crm_board_stages s ON s.id = o.stage_id
                            WHERE o.is_deleted = 0
                              AND s.order_num = (
                                  SELECT MAX(order_num) FROM crm_board_stages
                                  WHERE board_id = o.board_id
                              )";
                    $p = [];
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($p);
                $result['won_opps'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}

            try {
                // درآمد فروش
                if ($gMonthFrom !== '') {
                    $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM fin_invoices
                            WHERE is_deleted = 0 AND type = 'sell'
                              AND invoice_date BETWEEN ? AND ?";
                    $p = [substr($gMonthFrom, 0, 10), substr($gMonthTo, 0, 10)];
                } else {
                    $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM fin_invoices
                            WHERE is_deleted = 0 AND type = 'sell'";
                    $p = [];
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($p);
                $result['revenue'] = (float)$stmt->fetchColumn();
            } catch (Exception $e) {}

            // اهداف پیش‌فرض ماهانه
            $targets = [
                'calls'    => 40,
                'visits'   => 8,
                'new_opps' => 5,
                'won_opps' => 2,
                'revenue'  => 50000000,
            ];

            echo json_encode(['ok' => true, 'actual' => $result, 'targets' => $targets]);
            exit;
        }

        // ═══ صدور CSV ════════════════════════════════════════════════════════
        case 'export_csv': {
            $report   = $_GET['report']    ?? 'funnel';
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo   = $_GET['date_to']   ?? '';

            // BOM برای پشتیبانی Unicode در Excel
            $bom = "\xEF\xBB\xBF";

            if ($report === 'funnel') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="funnel_report.csv"');
                echo $bom;
                echo "مرحله,تعداد فرصت,مجموع ارزش\n";
                try {
                    $stmt = $pdo->prepare(
                        "SELECT s.name, COUNT(o.id) AS cnt, COALESCE(SUM(o.value),0) AS val
                         FROM crm_board_stages s
                         LEFT JOIN crm_opportunities o ON o.stage_id=s.id AND o.is_deleted=0
                         GROUP BY s.id ORDER BY s.order_num"
                    );
                    $stmt->execute();
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo '"' . $r['name'] . '",' . $r['cnt'] . ',' . $r['val'] . "\n";
                    }
                } catch (Exception $e) {}

            } elseif ($report === 'expert') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="expert_report.csv"');
                echo $bom;
                echo "کارشناس,تماس‌ها,فرصت‌ها,ارزش فرصت,معاملات بسته,نرخ تبدیل\n";
                try {
                    $stmt = $pdo->prepare(
                        "SELECT u.first_name, u.last_name,
                                COUNT(DISTINCT c.id) AS call_count,
                                COUNT(DISTINCT o.id) AS opp_count,
                                COALESCE(SUM(o.value),0) AS total_value,
                                COUNT(DISTINCT CASE WHEN s.order_num=(
                                    SELECT MAX(order_num) FROM crm_board_stages WHERE board_id=o.board_id
                                ) THEN o.id END) AS wins
                         FROM users u
                         LEFT JOIN crm_opportunity_calls c ON c.user_id=u.id
                         LEFT JOIN crm_opportunities o ON o.assigned_to=u.id AND o.is_deleted=0
                         LEFT JOIN crm_board_stages s ON s.id=o.stage_id
                         WHERE u.is_active=1
                         GROUP BY u.id ORDER BY total_value DESC"
                    );
                    $stmt->execute();
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $conv = $r['opp_count'] > 0 ? round($r['wins']/$r['opp_count']*100,1) : 0;
                        echo '"' . $r['first_name'].' '.$r['last_name'] . '",'
                            . $r['call_count'] . ','
                            . $r['opp_count']  . ','
                            . $r['total_value'] . ','
                            . $r['wins'] . ','
                            . $conv . "%\n";
                    }
                } catch (Exception $e) {}

            } elseif ($report === 'province') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="province_report.csv"');
                echo $bom;
                echo "استان,مشتریان,فرصت‌ها,مجموع فروش\n";
                try {
                    $checkStmt = $pdo->query("SHOW TABLES LIKE 'crm_provinces'");
                    if ($checkStmt->rowCount() > 0) {
                        $stmt = $pdo->prepare(
                            "SELECT COALESCE(p.province_name, p.name) AS province,
                                    COUNT(DISTINCT c.id) AS customer_count,
                                    COUNT(DISTINCT o.id) AS opp_count,
                                    COALESCE(SUM(inv.total_amount),0) AS sales_total
                             FROM crm_provinces p
                             LEFT JOIN customers c ON c.state = COALESCE(p.province_name, p.name)
                             LEFT JOIN crm_opportunities o ON o.is_deleted=0 AND EXISTS (
                                 SELECT 1 FROM customers cc
                                 WHERE cc.id=o.assigned_to
                                   AND cc.state = COALESCE(p.province_name, p.name)
                             )
                             LEFT JOIN fin_invoices inv ON inv.opportunity_id=o.id AND inv.is_deleted=0
                             GROUP BY p.id ORDER BY sales_total DESC"
                        );
                        $stmt->execute();
                        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            echo '"' . $r['province'] . '",'
                                . $r['customer_count'] . ','
                                . $r['opp_count'] . ','
                                . $r['sales_total'] . "\n";
                        }
                    }
                } catch (Exception $e) {}
            }
            exit;
        }

        default:
            echo json_encode(['ok' => false, 'msg' => 'درخواست نامعتبر']);
            exit;
    }
}

// ─── آماده‌سازی لیست کاربران برای فیلتر ─────────────────────────────────────
$allUsers = [];
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE is_active=1 ORDER BY first_name");
    $stmt->execute();
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ─── تنظیمات صفحه ────────────────────────────────────────────────────────────
$pageTitle = 'تحلیل و گزارش CRM';
$basePath  = '../../';

// محدوده تاریخ پیش‌فرض (۳۰ روز اخیر) — محاسبه در PHP
$defaultDateTo   = jdate('Y/m/d');
$defaultDateFrom = jdate('Y/m/d', strtotime('-30 days'));

$extraCss = '
<link rel="stylesheet" href="../../assets/css/fin_module.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ─── لایه‌بندی کلی صفحه ─── */
.crm-reports-wrap { padding: 24px; max-width: 1400px; margin: 0 auto; }

/* ─── هدر صفحه ─── */
.crm-reports-header {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    border-radius: 16px;
    padding: 28px 32px;
    color: #fff;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.crm-reports-header .icon-wrap {
    width: 56px; height: 56px;
    background: rgba(255,255,255,.15);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem; flex-shrink: 0;
}
.crm-reports-header h1 { font-size: 1.5rem; font-weight: 800; margin: 0 0 4px; }
.crm-reports-header p  { margin: 0; opacity: .8; font-size: .9rem; }

/* ─── نوار فیلتر ─── */
.filter-bar {
    background: #fff;
    border-radius: 12px;
    padding: 16px 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
    margin-bottom: 24px;
    border: 1px solid #e5e7eb;
}
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-group label { font-size: .78rem; font-weight: 600; color: #6b7280; }
.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 1.5px solid #d1d5db;
    border-radius: 8px;
    font-size: .88rem;
    font-family: Vazirmatn, sans-serif;
    background: #f9fafb;
    color: #111827;
    min-width: 130px;
    transition: border-color .2s;
}
.filter-group input:focus,
.filter-group select:focus { outline: none; border-color: #6366f1; background: #fff; }
.btn-apply {
    padding: 9px 22px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: .88rem;
    font-family: Vazirmatn, sans-serif;
    font-weight: 700;
    cursor: pointer;
    transition: opacity .2s;
}
.btn-apply:hover { opacity: .88; }

/* ─── تب‌ها ─── */
.tabs-bar {
    display: flex;
    gap: 4px;
    background: #f1f5f9;
    border-radius: 12px;
    padding: 5px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.tab-btn {
    flex: 1;
    min-width: 120px;
    padding: 10px 14px;
    border: none;
    background: transparent;
    border-radius: 9px;
    font-family: Vazirmatn, sans-serif;
    font-size: .85rem;
    font-weight: 600;
    color: #6b7280;
    cursor: pointer;
    transition: all .2s;
    white-space: nowrap;
}
.tab-btn.active {
    background: #fff;
    color: #4f46e5;
    box-shadow: 0 2px 8px rgba(0,0,0,.1);
}
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ─── کارت گزارش ─── */
.report-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    margin-bottom: 20px;
    overflow: hidden;
}
.report-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.report-card-header h3 { margin: 0; font-size: 1rem; font-weight: 700; color: #1f2937; }
.report-card-body { padding: 20px; }

/* ─── خلاصه شاخص‌ها ─── */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: #f8faff;
    border: 1px solid #e0e7ff;
    border-radius: 12px;
    padding: 16px;
    text-align: center;
}
.summary-card .label { font-size: .78rem; color: #6b7280; margin-bottom: 6px; }
.summary-card .value { font-size: 1.6rem; font-weight: 800; color: #4f46e5; }
.summary-card .sub   { font-size: .72rem; color: #9ca3af; margin-top: 2px; }

/* ─── قیف فروش CSS ─── */
.funnel-container { margin-bottom: 20px; }
.funnel-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
}
.funnel-row:last-child { border-bottom: none; }
.funnel-stage-name { width: 160px; font-size: .85rem; font-weight: 600; color: #374151; flex-shrink: 0; }
.funnel-count-badge {
    min-width: 42px;
    padding: 4px 10px;
    border-radius: 99px;
    text-align: center;
    font-size: .8rem;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
}
.funnel-bar-wrap { flex: 1; background: #f3f4f6; border-radius: 99px; height: 20px; overflow: hidden; }
.funnel-bar { height: 100%; border-radius: 99px; transition: width .6s ease; }
.funnel-value { font-size: .8rem; color: #6b7280; min-width: 110px; text-align: left; flex-shrink: 0; }

/* ─── جدول عملکرد ─── */
.perf-table { width: 100%; border-collapse: collapse; font-size: .87rem; }
.perf-table th {
    background: #f8fafc;
    padding: 10px 14px;
    text-align: right;
    font-weight: 700;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}
.perf-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #374151;
}
.perf-table tr:last-child td { border-bottom: none; }
.perf-table tr.best-row td { background: #ecfdf5; }
.perf-table .badge-small {
    padding: 3px 8px;
    border-radius: 6px;
    font-size: .78rem;
    font-weight: 700;
}

/* ─── نوار پیشرفت KPI ─── */
.kpi-table { width: 100%; border-collapse: collapse; font-size: .87rem; margin-bottom: 16px; }
.kpi-table th { background: #f8fafc; padding: 10px 14px; text-align: right; font-weight: 700; color: #374151; border-bottom: 2px solid #e5e7eb; }
.kpi-table td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; color: #374151; vertical-align: middle; }
.progress-bar-wrap { background: #f3f4f6; border-radius: 99px; height: 10px; overflow: hidden; }
.progress-bar { height: 100%; border-radius: 99px; transition: width .6s ease; }
.progress-bar.green  { background: #10b981; }
.progress-bar.amber  { background: #f59e0b; }
.progress-bar.red    { background: #ef4444; }
.status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }

/* ─── نشانگر روزهای تایم‌لاین ─── */
.timeline-days-selector {
    display: flex; gap: 8px; margin-bottom: 16px;
}
.day-btn {
    padding: 6px 16px;
    border-radius: 8px;
    border: 1.5px solid #d1d5db;
    background: #fff;
    font-family: Vazirmatn, sans-serif;
    font-size: .83rem;
    font-weight: 600;
    color: #6b7280;
    cursor: pointer;
    transition: all .2s;
}
.day-btn.active { background: #4f46e5; color: #fff; border-color: #4f46e5; }

/* ─── خروجی Excel ─── */
.btn-export {
    padding: 8px 18px;
    background: #10b981;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: Vazirmatn, sans-serif;
    font-size: .83rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: opacity .2s;
    text-decoration: none;
}
.btn-export:hover { opacity: .85; }

/* ─── بارگذاری ─── */
.loading-placeholder {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
    font-size: .9rem;
}
.spinner {
    display: inline-block;
    width: 26px; height: 26px;
    border: 3px solid #e5e7eb;
    border-top-color: #6366f1;
    border-radius: 50%;
    animation: spin .7s linear infinite;
    margin-bottom: 8px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ─── افسانه فعالیت‌ها ─── */
.activity-legend {
    display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;
}
.legend-item {
    display: flex; align-items: center; gap: 6px;
    font-size: .82rem; color: #374151;
}
.legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }

/* ─── بج تعداد تایپ‌ها ─── */
.type-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.type-badge {
    padding: 5px 12px;
    border-radius: 8px;
    font-size: .82rem;
    font-weight: 700;
}

/* ─── پیام خالی ─── */
.empty-msg {
    text-align: center;
    padding: 50px 20px;
    color: #9ca3af;
}
.empty-msg .icon { font-size: 3rem; margin-bottom: 12px; }
.empty-msg h4 { margin: 0 0 6px; color: #6b7280; }

/* ─── چارت‌ها ─── */
.chart-wrap { position: relative; }
.chart-wrap canvas { max-height: 320px; }

@media (max-width: 768px) {
    .crm-reports-header { padding: 18px; }
    .filter-bar { gap: 8px; }
    .tab-btn { font-size: .78rem; padding: 8px 10px; }
    .funnel-stage-name { width: 100px; }
}
</style>
';

include __DIR__ . '/../../templates/header.php';
?>

<div class="crm-reports-wrap">

    <!-- ─── هدر صفحه ─────────────────────────────────────────── -->
    <div class="crm-reports-header">
        <div class="icon-wrap">📊</div>
        <div>
            <h1>تحلیل و گزارش CRM</h1>
            <p>بررسی عملکرد فروش، کارشناسان و شاخص‌های کلیدی</p>
        </div>
    </div>

    <!-- ─── نوار فیلتر ───────────────────────────────────────── -->
    <div class="filter-bar">
        <div class="filter-group">
            <label>از تاریخ</label>
            <input type="text" id="filterDateFrom" value="<?= htmlspecialchars($defaultDateFrom) ?>"
                   placeholder="مثال: ۱۴۰۳/۰۱/۰۱" dir="ltr">
        </div>
        <div class="filter-group">
            <label>تا تاریخ</label>
            <input type="text" id="filterDateTo" value="<?= htmlspecialchars($defaultDateTo) ?>"
                   placeholder="مثال: ۱۴۰۳/۱۲/۲۹" dir="ltr">
        </div>
        <div class="filter-group">
            <label>کارشناس</label>
            <select id="filterUser">
                <option value="0">همه کارشناسان</option>
                <?php foreach ($allUsers as $u): ?>
                    <option value="<?= (int)$u['id'] ?>">
                        <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn-apply" onclick="applyFilters()">🔍 اعمال فیلتر</button>
    </div>

    <!-- ─── تب‌ها ────────────────────────────────────────────── -->
    <div class="tabs-bar">
        <button class="tab-btn active" onclick="switchTab(1)" id="tab-btn-1">📉 قیف فروش</button>
        <button class="tab-btn"        onclick="switchTab(2)" id="tab-btn-2">👥 عملکرد کارشناسان</button>
        <button class="tab-btn"        onclick="switchTab(3)" id="tab-btn-3">📋 KPI ماهانه</button>
        <button class="tab-btn"        onclick="switchTab(4)" id="tab-btn-4">🗺️ تحلیل استان‌ها</button>
        <button class="tab-btn"        onclick="switchTab(5)" id="tab-btn-5">🕒 تایم‌لاین فعالیت</button>
    </div>

    <!-- ═══ تب ۱: قیف فروش ════════════════════════════════════ -->
    <div class="tab-panel active" id="tab-panel-1">
        <!-- خلاصه شاخص‌ها -->
        <div class="summary-grid" id="funnel-summary">
            <div class="summary-card"><div class="label">کل فرصت‌ها</div><div class="value" id="sum-total-opps">—</div></div>
            <div class="summary-card"><div class="label">کل ارزش</div><div class="value" id="sum-total-value">—</div><div class="sub">تومان</div></div>
            <div class="summary-card"><div class="label">نرخ برد</div><div class="value" id="sum-win-rate">—</div><div class="sub">درصد</div></div>
            <div class="summary-card"><div class="label">میانگین هر معامله</div><div class="value" id="sum-avg-deal">—</div><div class="sub">تومان</div></div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 360px; gap: 20px; flex-wrap: wrap;">
            <!-- قیف CSS -->
            <div class="report-card">
                <div class="report-card-header">
                    <h3>توزیع فرصت‌ها در مراحل</h3>
                </div>
                <div class="report-card-body">
                    <div id="funnel-bars" class="funnel-container">
                        <div class="loading-placeholder"><div class="spinner"></div><br>در حال بارگذاری...</div>
                    </div>
                </div>
            </div>
            <!-- نمودار دونات -->
            <div class="report-card">
                <div class="report-card-header"><h3>توزیع ارزش</h3></div>
                <div class="report-card-body">
                    <div class="chart-wrap" style="max-width:320px; margin: 0 auto;">
                        <canvas id="chart-funnel-doughnut"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ تب ۲: عملکرد کارشناسان ══════════════════════════ -->
    <div class="tab-panel" id="tab-panel-2">
        <div class="report-card">
            <div class="report-card-header">
                <h3>جدول عملکرد کارشناسان</h3>
                <button class="btn-export" onclick="exportCsv('expert')">⬇ خروجی Excel</button>
            </div>
            <div class="report-card-body" style="overflow-x:auto;">
                <div id="expert-table-wrap">
                    <div class="loading-placeholder"><div class="spinner"></div><br>در حال بارگذاری...</div>
                </div>
            </div>
        </div>
        <div class="report-card">
            <div class="report-card-header"><h3>مقایسه تماس و فرصت‌ها</h3></div>
            <div class="report-card-body">
                <div class="chart-wrap">
                    <canvas id="chart-expert-bar"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ تب ۳: KPI ماهانه ══════════════════════════════════ -->
    <div class="tab-panel" id="tab-panel-3">
        <div class="filter-bar" style="margin-bottom:20px; background:#f8fafc;">
            <div class="filter-group">
                <label>ماه (YYYY/MM)</label>
                <input type="text" id="kpiMonth" placeholder="مثال: ۱۴۰۳/۰۶" dir="ltr"
                       value="<?= jdate('Y/m') ?>">
            </div>
            <div class="filter-group">
                <label>کارشناس</label>
                <select id="kpiUser">
                    <option value="0">همه</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>">
                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-apply" onclick="loadKPI()">🔍 نمایش</button>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 360px; gap: 20px;">
            <!-- جدول هدف / واقعی -->
            <div class="report-card">
                <div class="report-card-header"><h3>مقایسه هدف و واقعی</h3></div>
                <div class="report-card-body">
                    <div id="kpi-table-wrap">
                        <div class="loading-placeholder"><div class="spinner"></div><br>در حال بارگذاری...</div>
                    </div>
                </div>
            </div>
            <!-- نمودار رادار -->
            <div class="report-card">
                <div class="report-card-header"><h3>نمودار رادار KPI</h3></div>
                <div class="report-card-body">
                    <div class="chart-wrap" style="max-width:320px; margin: 0 auto;">
                        <canvas id="chart-kpi-radar"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ تب ۴: تحلیل استان‌ها ════════════════════════════ -->
    <div class="tab-panel" id="tab-panel-4">
        <div class="report-card">
            <div class="report-card-header">
                <h3>آمار فروش per استان</h3>
                <button class="btn-export" onclick="exportCsv('province')">⬇ خروجی Excel</button>
            </div>
            <div class="report-card-body" style="overflow-x:auto;">
                <div id="province-table-wrap">
                    <div class="loading-placeholder"><div class="spinner"></div><br>در حال بارگذاری...</div>
                </div>
            </div>
        </div>
        <div class="report-card">
            <div class="report-card-header"><h3>۱۰ استان برتر (مجموع فروش)</h3></div>
            <div class="report-card-body">
                <div class="chart-wrap">
                    <canvas id="chart-province-bar"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ تب ۵: تایم‌لاین فعالیت ══════════════════════════ -->
    <div class="tab-panel" id="tab-panel-5">
        <div class="report-card">
            <div class="report-card-header">
                <h3>تایم‌لاین فعالیت‌ها</h3>
                <div class="timeline-days-selector">
                    <button class="day-btn" onclick="setTimelineDays(7,  this)">۷ روز</button>
                    <button class="day-btn active" onclick="setTimelineDays(30, this)">۳۰ روز</button>
                    <button class="day-btn" onclick="setTimelineDays(90, this)">۹۰ روز</button>
                </div>
            </div>
            <div class="report-card-body">
                <div id="timeline-type-badges" class="type-badges"></div>
                <div id="timeline-legend" class="activity-legend"></div>
                <div class="chart-wrap">
                    <canvas id="chart-timeline-bar"></canvas>
                </div>
            </div>
        </div>
    </div>

</div><!-- /crm-reports-wrap -->

<script>
/* =============================================================
   اسکریپت‌های صفحه تحلیل و گزارش CRM
   ============================================================= */

// ─── رجیستری نمودارها (برای destroy قبل از رسم مجدد) ──────────────────────
const CHARTS = {};

// ─── تب فعال ──────────────────────────────────────────────────────────────
let activeTab = 1;

// ─── روزهای تایم‌لاین ─────────────────────────────────────────────────────
let timelineDays = 30;

// ─── تبدیل اعداد فارسی به انگلیسی (JS) ────────────────────────────────────
function faToEnJs(str) {
    return String(str)
        .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
        .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
}

// ─── فرمت تومان ────────────────────────────────────────────────────────────
function formatToman(n) {
    n = Math.round(n);
    if (n >= 1e9) return (n/1e9).toFixed(1) + ' میلیارد';
    if (n >= 1e6) return (n/1e6).toFixed(1) + ' میلیون';
    return n.toLocaleString('fa-IR');
}

// ─── خواندن مقادیر فیلتر ──────────────────────────────────────────────────
function getFilters() {
    return {
        date_from: faToEnJs(document.getElementById('filterDateFrom').value.trim()),
        date_to:   faToEnJs(document.getElementById('filterDateTo').value.trim()),
        user_id:   document.getElementById('filterUser').value
    };
}

// ─── اعمال فیلتر ──────────────────────────────────────────────────────────
function applyFilters() {
    loadTab(activeTab);
}

// ─── تغییر تب ─────────────────────────────────────────────────────────────
function switchTab(n) {
    document.querySelectorAll('.tab-btn').forEach((b, i) => {
        b.classList.toggle('active', i + 1 === n);
    });
    document.querySelectorAll('.tab-panel').forEach((p, i) => {
        p.classList.toggle('active', i + 1 === n);
    });
    activeTab = n;
    loadTab(n);
}

// ─── بارگذاری تب مربوطه ───────────────────────────────────────────────────
function loadTab(n) {
    switch (n) {
        case 1: loadFunnel();     break;
        case 2: loadExpertPerf(); break;
        case 3: loadKPI();        break;
        case 4: loadProvinces();  break;
        case 5: loadTimeline();   break;
    }
}

// ─── بارگذاری قیف فروش ────────────────────────────────────────────────────
function loadFunnel() {
    const f = getFilters();
    const url = `crm_reports.php?action=funnel_data&date_from=${encodeURIComponent(f.date_from)}&date_to=${encodeURIComponent(f.date_to)}`;

    document.getElementById('funnel-bars').innerHTML =
        '<div class="loading-placeholder"><div class="spinner"></div><br>در حال بارگذاری...</div>';

    fetchJSON(url).then(data => {
        if (!data.ok) { showError('funnel-bars', data.msg); return; }

        // ─── خلاصه ─────────────────────────────────────────────────────────
        const s = data.summary;
        document.getElementById('sum-total-opps').textContent  = s.total_opps.toLocaleString('fa-IR');
        document.getElementById('sum-total-value').textContent = formatToman(s.total_value);
        document.getElementById('sum-win-rate').textContent    = s.win_rate + '%';
        document.getElementById('sum-avg-deal').textContent    = formatToman(s.avg_deal);

        // ─── نوارهای قیف ───────────────────────────────────────────────────
        const max = data.max_count || 1;
        let html = '';
        data.stages.forEach(st => {
            const pct   = Math.round((st.opp_count / max) * 100);
            const color = st.color || '#6366f1';
            html += `
            <div class="funnel-row">
                <div class="funnel-stage-name">${escHtml(st.stage_name)}</div>
                <div class="funnel-count-badge" style="background:${escHtml(color)}">${Number(st.opp_count).toLocaleString('fa-IR')}</div>
                <div class="funnel-bar-wrap">
                    <div class="funnel-bar" style="width:${pct}%;background:${escHtml(color)};opacity:.75;"></div>
                </div>
                <div class="funnel-value">${formatToman(st.total_value)} ت</div>
            </div>`;
        });
        document.getElementById('funnel-bars').innerHTML = html || '<div class="empty-msg"><div class="icon">📭</div><h4>داده‌ای یافت نشد</h4></div>';

        // ─── نمودار دونات ──────────────────────────────────────────────────
        destroyChart('funnel-doughnut');
        const labels = data.stages.map(s => s.stage_name);
        const values = data.stages.map(s => parseFloat(s.total_value));
        const colors = data.stages.map(s => s.color || '#6366f1');

        const ctx = document.getElementById('chart-funnel-doughnut');
        if (ctx && values.some(v => v > 0)) {
            CHARTS['funnel-doughnut'] = new Chart(ctx, {
                type: 'doughnut',
                data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2 }] },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { family: 'Vazirmatn' }, boxWidth: 14 } },
                        tooltip: { callbacks: { label: ctx => formatToman(ctx.raw) + ' تومان' } }
                    }
                }
            });
        }
    });
}

// ─── بارگذاری عملکرد کارشناسان ────────────────────────────────────────────
function loadExpertPerf() {
    const f = getFilters();
    const url = `crm_reports.php?action=expert_performance&date_from=${encodeURIComponent(f.date_from)}&date_to=${encodeURIComponent(f.date_to)}`;

    document.getElementById('expert-table-wrap').innerHTML =
        '<div class="loading-placeholder"><div class="spinner"></div><br>در حال بارگذاری...</div>';

    fetchJSON(url).then(data => {
        if (!data.ok) { showError('expert-table-wrap', data.msg); return; }

        const experts = data.experts;
        if (!experts.length) {
            document.getElementById('expert-table-wrap').innerHTML =
                '<div class="empty-msg"><div class="icon">👤</div><h4>داده‌ای یافت نشد</h4></div>';
            return;
        }

        // پیدا کردن بهترین عملکرد (بیشترین ارزش)
        let bestIdx = 0;
        experts.forEach((e, i) => {
            if (parseFloat(e.total_value) > parseFloat(experts[bestIdx].total_value)) bestIdx = i;
        });

        let html = `<table class="perf-table">
            <thead>
                <tr>
                    <th>کارشناس</th>
                    <th>تماس‌ها</th>
                    <th>فرصت‌ها</th>
                    <th>ارزش فرصت</th>
                    <th>معاملات بسته</th>
                    <th>نرخ تبدیل</th>
                </tr>
            </thead>
            <tbody>`;

        experts.forEach((e, i) => {
            const isBest = i === bestIdx;
            const convColor = e.conversion_rate >= 50 ? '#10b981' :
                              e.conversion_rate >= 20 ? '#f59e0b' : '#ef4444';
            html += `<tr class="${isBest ? 'best-row' : ''}">
                <td><strong>${escHtml(e.first_name)} ${escHtml(e.last_name)}</strong>
                    ${isBest ? '<span class="badge-small" style="background:#d1fae5;color:#065f46;margin-right:6px">🏆 برتر</span>' : ''}
                </td>
                <td>${Number(e.call_count).toLocaleString('fa-IR')}</td>
                <td>${Number(e.opp_count).toLocaleString('fa-IR')}</td>
                <td>${formatToman(e.total_value)}</td>
                <td>${Number(e.wins).toLocaleString('fa-IR')}</td>
                <td><span class="badge-small" style="background:${convColor}22;color:${convColor}">${e.conversion_rate}٪</span></td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('expert-table-wrap').innerHTML = html;

        // ─── نمودار میله‌ای ─────────────────────────────────────────────────
        destroyChart('expert-bar');
        const names  = experts.map(e => e.first_name);
        const calls  = experts.map(e => parseInt(e.call_count));
        const opps   = experts.map(e => parseInt(e.opp_count));

        const ctx = document.getElementById('chart-expert-bar');
        if (ctx) {
            CHARTS['expert-bar'] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: names,
                    datasets: [
                        { label: 'تماس‌ها', data: calls, backgroundColor: 'rgba(99,102,241,.7)', borderRadius: 6 },
                        { label: 'فرصت‌ها', data: opps,  backgroundColor: 'rgba(16,185,129,.7)', borderRadius: 6 }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { labels: { font: { family: 'Vazirmatn' } } } },
                    scales: {
                        x: { ticks: { font: { family: 'Vazirmatn' } } },
                        y: { beginAtZero: true, ticks: { font: { family: 'Vazirmatn' } } }
                    }
                }
            });
        }
    });
}

// ─── بارگذاری KPI ماهانه ──────────────────────────────────────────────────
function loadKPI() {
    const month  = faToEnJs(document.getElementById('kpiMonth').value.trim());
    const userId = document.getElementById('kpiUser').value;
    const url = `crm_reports.php?action=kpi_summary&month=${encodeURIComponent(month)}&user_id=${userId}`;

    document.getElementById('kpi-table-wrap').innerHTML =
        '<div class="loading-placeholder"><div class="spinner"></div><br>در حال بارگذاری...</div>';

    fetchJSON(url).then(data => {
        if (!data.ok) { showError('kpi-table-wrap', data.msg); return; }

        const actual  = data.actual;
        const targets = data.targets;

        const kpiDefs = [
            { key: 'calls',    label: 'تماس‌ها',    unit: 'بار' },
            { key: 'visits',   label: 'ویزیت‌ها',   unit: 'بار' },
            { key: 'new_opps', label: 'فرصت جدید',  unit: 'عدد' },
            { key: 'won_opps', label: 'معامله بسته', unit: 'عدد' },
            { key: 'revenue',  label: 'درآمد',       unit: 'تومان' },
        ];

        let html = `<table class="kpi-table">
            <thead><tr>
                <th>شاخص</th>
                <th>هدف</th>
                <th>واقعی</th>
                <th>درصد تحقق</th>
                <th>پیشرفت</th>
                <th>وضعیت</th>
            </tr></thead>
            <tbody>`;

        kpiDefs.forEach(kpi => {
            const a = actual[kpi.key]  || 0;
            const t = targets[kpi.key] || 1;
            const pct = Math.min(Math.round(a / t * 100), 100);
            const cls = pct >= 80 ? 'green' : pct >= 50 ? 'amber' : 'red';
            const statusEmoji = pct >= 80 ? '✅' : pct >= 50 ? '⚠️' : '❌';
            const dispA = kpi.key === 'revenue' ? formatToman(a) : Number(a).toLocaleString('fa-IR');
            const dispT = kpi.key === 'revenue' ? formatToman(t) : Number(t).toLocaleString('fa-IR');

            html += `<tr>
                <td><strong>${kpi.label}</strong></td>
                <td>${dispT} ${kpi.unit}</td>
                <td>${dispA} ${kpi.unit}</td>
                <td>${pct.toLocaleString('fa-IR')}٪</td>
                <td style="min-width:120px">
                    <div class="progress-bar-wrap">
                        <div class="progress-bar ${cls}" style="width:${pct}%"></div>
                    </div>
                </td>
                <td>${statusEmoji}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('kpi-table-wrap').innerHTML = html;

        // ─── نمودار رادار ────────────────────────────────────────────────────
        destroyChart('kpi-radar');
        const radarLabels = kpiDefs.map(k => k.label);
        const radarActual  = kpiDefs.map(k => {
            const a = actual[k.key]  || 0;
            const t = targets[k.key] || 1;
            return Math.min(Math.round(a / t * 100), 150);
        });

        const ctx = document.getElementById('chart-kpi-radar');
        if (ctx) {
            CHARTS['kpi-radar'] = new Chart(ctx, {
                type: 'radar',
                data: {
                    labels: radarLabels,
                    datasets: [
                        {
                            label: 'درصد تحقق',
                            data: radarActual,
                            backgroundColor: 'rgba(99,102,241,.15)',
                            borderColor: '#6366f1',
                            pointBackgroundColor: '#6366f1',
                            pointRadius: 5
                        },
                        {
                            label: 'هدف (۱۰۰٪)',
                            data: [100, 100, 100, 100, 100],
                            backgroundColor: 'rgba(16,185,129,.08)',
                            borderColor: '#10b981',
                            borderDash: [5, 5],
                            pointRadius: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    scales: {
                        r: {
                            min: 0, max: 120,
                            ticks: { font: { family: 'Vazirmatn' }, stepSize: 20 },
                            pointLabels: { font: { family: 'Vazirmatn', size: 12 } }
                        }
                    },
                    plugins: { legend: { labels: { font: { family: 'Vazirmatn' } } } }
                }
            });
        }
    });
}

// ─── بارگذاری تحلیل استان‌ها ──────────────────────────────────────────────
function loadProvinces() {
    const url = 'crm_reports.php?action=province_stats';

    document.getElementById('province-table-wrap').innerHTML =
        '<div class="loading-placeholder"><div class="spinner"></div><br>در حال بارگذاری...</div>';

    fetchJSON(url).then(data => {
        if (!data.ok) { showError('province-table-wrap', data.msg); return; }

        if (!data.exists) {
            document.getElementById('province-table-wrap').innerHTML = `
                <div class="empty-msg">
                    <div class="icon">🗺️</div>
                    <h4>استان‌ها تعریف نشده‌اند</h4>
                    <p>ابتدا از منوی CRM → مدیریت استان‌ها، استان‌ها را تعریف کنید.</p>
                </div>`;
            return;
        }

        const rows = data.data;
        if (!rows.length) {
            document.getElementById('province-table-wrap').innerHTML =
                '<div class="empty-msg"><div class="icon">📭</div><h4>داده‌ای یافت نشد</h4></div>';
            return;
        }

        let html = `<table class="perf-table">
            <thead><tr>
                <th>استان</th>
                <th>مشتریان</th>
                <th>فرصت‌ها</th>
                <th>مجموع فروش (تومان)</th>
                <th>کارشناس</th>
            </tr></thead>
            <tbody>`;

        rows.forEach((r, i) => {
            let rowStyle = '';
            if (i < 3)                   rowStyle = 'background:#f0fdf4;';
            else if (i >= rows.length - 3) rowStyle = 'background:#fff5f5;';

            html += `<tr style="${rowStyle}">
                <td><strong>${escHtml(r.province)}</strong></td>
                <td>${Number(r.customer_count).toLocaleString('fa-IR')}</td>
                <td>${Number(r.opp_count).toLocaleString('fa-IR')}</td>
                <td>${formatToman(r.sales_total)}</td>
                <td>${escHtml(r.expert_name || '—')}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('province-table-wrap').innerHTML = html;

        // ─── نمودار میله‌ای ۱۰ استان برتر ─────────────────────────────────
        destroyChart('province-bar');
        const top10   = rows.slice(0, 10);
        const pLabels = top10.map(r => r.province);
        const pValues = top10.map(r => parseFloat(r.sales_total));

        const ctx = document.getElementById('chart-province-bar');
        if (ctx && pValues.some(v => v > 0)) {
            CHARTS['province-bar'] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: pLabels,
                    datasets: [{
                        label: 'مجموع فروش (تومان)',
                        data: pValues,
                        backgroundColor: 'rgba(99,102,241,.7)',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: ctx => formatToman(ctx.raw) + ' تومان' } }
                    },
                    scales: {
                        x: { ticks: { font: { family: 'Vazirmatn' } } },
                        y: { beginAtZero: true, ticks: { font: { family: 'Vazirmatn' }, callback: v => formatToman(v) } }
                    }
                }
            });
        }
    });
}

// ─── بارگذاری تایم‌لاین فعالیت ────────────────────────────────────────────
function loadTimeline() {
    const userId = getFilters().user_id;
    const url = `crm_reports.php?action=activity_timeline&user_id=${userId}&days=${timelineDays}`;

    fetchJSON(url).then(data => {
        if (!data.ok) { return; }

        const rows = data.data;

        // رنگ هر نوع فعالیت
        const TYPE_CONFIG = {
            'call':         { label: 'تماس',        color: 'rgba(99,102,241,.8)' },
            'visit':        { label: 'ویزیت',        color: 'rgba(16,185,129,.8)' },
            'note':         { label: 'یادداشت',      color: 'rgba(245,158,11,.8)' },
            'stage_change': { label: 'تغییر مرحله',  color: 'rgba(239,68,68,.8)'  },
            'task':         { label: 'وظیفه',        color: 'rgba(168,85,247,.8)' },
        };

        // جمع‌آوری روزها و تایپ‌ها
        const daysSet  = new Set();
        const typesSet = new Set();
        const typeTotals = {};

        rows.forEach(r => {
            daysSet.add(r.day);
            typesSet.add(r.type);
            typeTotals[r.type] = (typeTotals[r.type] || 0) + parseInt(r.cnt);
        });

        const allDays  = [...daysSet].sort();
        const allTypes = [...typesSet];

        // ساخت بج‌های تعداد
        let badgesHtml = '';
        allTypes.forEach(t => {
            const cfg = TYPE_CONFIG[t] || { label: t, color: 'rgba(107,114,128,.8)' };
            badgesHtml += `<span class="type-badge" style="background:${cfg.color.replace('.8',',.15')};color:${cfg.color.replace('rgba(','').replace(',.8)','').split(',').slice(0,3).map((v,i)=>'rgb('+v).join('')}">
                ${cfg.label}: ${(typeTotals[t]||0).toLocaleString('fa-IR')}
            </span>`;
        });
        document.getElementById('timeline-type-badges').innerHTML = badgesHtml;

        // افسانه
        let legendHtml = '';
        allTypes.forEach(t => {
            const cfg = TYPE_CONFIG[t] || { label: t, color: 'rgba(107,114,128,.8)' };
            legendHtml += `<div class="legend-item"><div class="legend-dot" style="background:${cfg.color}"></div>${cfg.label}</div>`;
        });
        document.getElementById('timeline-legend').innerHTML = legendHtml;

        // ساخت datasets
        const datasets = allTypes.map(t => {
            const cfg = TYPE_CONFIG[t] || { label: t, color: 'rgba(107,114,128,.8)' };
            const dataMap = {};
            rows.filter(r => r.type === t).forEach(r => { dataMap[r.day] = parseInt(r.cnt); });
            return {
                label: cfg.label,
                data: allDays.map(d => dataMap[d] || 0),
                backgroundColor: cfg.color,
                borderRadius: 4
            };
        });

        destroyChart('timeline-bar');
        const ctx = document.getElementById('chart-timeline-bar');
        if (ctx) {
            CHARTS['timeline-bar'] = new Chart(ctx, {
                type: 'bar',
                data: { labels: allDays, datasets },
                options: {
                    responsive: true,
                    scales: {
                        x: { stacked: true, ticks: { font: { family: 'Vazirmatn' }, maxRotation: 45 } },
                        y: { stacked: true, beginAtZero: true, ticks: { font: { family: 'Vazirmatn' } } }
                    },
                    plugins: { legend: { labels: { font: { family: 'Vazirmatn' } } } }
                }
            });
        }
    });
}

// ─── تنظیم روزهای تایم‌لاین ───────────────────────────────────────────────
function setTimelineDays(days, btn) {
    timelineDays = days;
    document.querySelectorAll('.day-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    loadTimeline();
}

// ─── صدور CSV ─────────────────────────────────────────────────────────────
function exportCsv(type) {
    const f = getFilters();
    const url = `crm_reports.php?action=export_csv&report=${type}`
              + `&date_from=${encodeURIComponent(f.date_from)}&date_to=${encodeURIComponent(f.date_to)}`;
    window.location.href = url;
}

// ─── destroy قبلی chart ───────────────────────────────────────────────────
function destroyChart(key) {
    if (CHARTS[key]) {
        CHARTS[key].destroy();
        delete CHARTS[key];
    }
}

// ─── دریافت JSON از سرور ─────────────────────────────────────────────────
async function fetchJSON(url) {
    try {
        const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        return await resp.json();
    } catch (e) {
        return { ok: false, msg: 'خطا در ارتباط با سرور' };
    }
}

// ─── نمایش پیام خطا ───────────────────────────────────────────────────────
function showError(containerId, msg) {
    const el = document.getElementById(containerId);
    if (el) el.innerHTML = `<div class="empty-msg"><div class="icon">⚠️</div><h4>${escHtml(msg || 'خطا')}</h4></div>`;
}

// ─── escape HTML برای جلوگیری از XSS ─────────────────────────────────────
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

// ─── بارگذاری اولیه صفحه ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadFunnel();
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
