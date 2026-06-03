<?php
// includes/accounting.php — توابع مرکزی حسابداری دوطرفه (منطق hesabix)
// منبع اصلی: SellController، BuyController، ChequeController در hesabix

// ─────────────────────────────────────────────────────────────────────────────
// کش داخلی برای کدهای حساب — از رفت‌وبرگشت اضافی به DB جلوگیری می‌کند
// ─────────────────────────────────────────────────────────────────────────────
function acc_account_by_code(PDO $pdo, string $code): int
{
    static $cache = [];

    if (isset($cache[$code])) {
        return $cache[$code];
    }

    $stmt = $pdo->prepare('SELECT id FROM fin_chart_of_accounts WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        throw new RuntimeException('کد حساب یافت نشد: ' . $code);
    }

    $cache[$code] = (int)$id;
    return $cache[$code];
}

// ─────────────────────────────────────────────────────────────────────────────
// شماره سند بعدی — صفرپُر شش رقمی (مثلاً 000042)
// ─────────────────────────────────────────────────────────────────────────────
function acc_next_doc_number(PDO $pdo): string
{
    $stmt = $pdo->query('SELECT MAX(CAST(doc_number AS UNSIGNED)) FROM fin_docs');
    $max = (int)$stmt->fetchColumn();
    return str_pad($max + 1, 6, '0', STR_PAD_LEFT);
}

// ─────────────────────────────────────────────────────────────────────────────
// درج سند حسابداری — fin_docs
// پارامترها: type, doc_date, description, fiscal_year_id, created_by,
//            ref_id, ref_type, tax_percent, discount_type, discount_percent, short_link
// ─────────────────────────────────────────────────────────────────────────────
function acc_insert_doc(PDO $pdo, array $params): int
{
    $docNumber = acc_next_doc_number($pdo);

    $stmt = $pdo->prepare('
        INSERT INTO fin_docs
            (doc_number, doc_date, type, tax_percent, description,
             discount_type, discount_percent, fiscal_year_id,
             ref_id, ref_type, short_link, created_by, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');

    $stmt->execute([
        $docNumber,
        $params['doc_date']         ?? '',
        $params['type']             ?? 'manual',
        $params['tax_percent']      ?? 0,
        $params['description']      ?? null,
        $params['discount_type']    ?? 'fixed',
        $params['discount_percent'] ?? 0,
        $params['fiscal_year_id']   ?? null,
        $params['ref_id']           ?? null,
        $params['ref_type']         ?? null,
        $params['short_link']       ?? null,
        $params['created_by'],
    ]);

    return (int)$pdo->lastInsertId();
}

// ─────────────────────────────────────────────────────────────────────────────
// درج ردیف سند — fin_doc_rows
// پارامتر account_code به‌صورت خودکار به account_id تبدیل می‌شود
// پارامترها: account_code, bd, bs, description, person_id, commodity_id,
//            commodity_count, cashdesk_id, bank_id, cheque_id,
//            row_discount, row_tax, referral
// ─────────────────────────────────────────────────────────────────────────────
function acc_insert_row(PDO $pdo, int $doc_id, array $row): void
{
    // تبدیل کد حساب به شناسه
    $account_id = acc_account_by_code($pdo, (string)$row['account_code']);

    $stmt = $pdo->prepare('
        INSERT INTO fin_doc_rows
            (doc_id, account_id, bd, bs, description, referral,
             person_id, commodity_id, commodity_count,
             cashdesk_id, bank_id, cheque_id,
             row_discount, row_tax)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $doc_id,
        $account_id,
        $row['bd']              ?? 0,
        $row['bs']              ?? 0,
        $row['description']     ?? null,
        $row['referral']        ?? null,
        $row['person_id']       ?? null,
        $row['commodity_id']    ?? null,
        $row['commodity_count'] ?? null,
        $row['cashdesk_id']     ?? null,
        $row['bank_id']         ?? null,
        $row['cheque_id']       ?? null,
        $row['row_discount']    ?? 0,
        $row['row_tax']         ?? 0,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// سند فروش دوطرفه — منطق SellController::app_sell_mod
//
// پارامترها:
//   doc_date, description, fiscal_year_id, created_by
//   person_id       — شناسه طرف حساب در fin_persons
//   rows[]          — هر ردیف: [stuff_id, description, qty, amount_without_tax, tax_amount]
//   transfer_cost   — هزینه حمل
//   discount_all    — تخفیف کلی
//   discount_type   — fixed / percent
//   discount_percent
//   tax_percent
//   ref_id          — شناسه فاکتور
//   ref_type        — 'fin_invoice'
//
// منطق ردیف‌ها (مطابق hesabix):
//   هر کالا  → CREDIT حساب '53' (فروش کالا)  = sumWithoutTax + taxAmount
//   حمل      → CREDIT حساب '61' (درآمد حمل)  = transferCost
//   تخفیف    → DEBIT  حساب '104' (تخفیفات فروش) = discountAll
//   طرف حساب → DEBIT  حساب '3'  (حساب دریافتی)  = total با person_id
// ─────────────────────────────────────────────────────────────────────────────
function acc_sell_journal(PDO $pdo, array $params): int
{
    $pdo->beginTransaction();

    try {
        // ساخت سند
        $doc_id = acc_insert_doc($pdo, [
            'type'             => 'sell',
            'doc_date'         => $params['doc_date'],
            'description'      => $params['description'] ?? 'فاکتور فروش',
            'fiscal_year_id'   => $params['fiscal_year_id'],
            'created_by'       => $params['created_by'],
            'ref_id'           => $params['ref_id']           ?? null,
            'ref_type'         => $params['ref_type']         ?? 'fin_invoice',
            'tax_percent'      => $params['tax_percent']      ?? 0,
            'discount_type'    => $params['discount_type']    ?? 'fixed',
            'discount_percent' => $params['discount_percent'] ?? 0,
            'short_link'       => $params['short_link']       ?? null,
        ]);

        $total = 0;

        // ردیف‌های کالا — هر کالا یک CREDIT به حساب فروش (53)
        foreach ($params['rows'] as $item) {
            $lineAmount = (int)$item['amount_without_tax'] + (int)$item['tax_amount'];
            $total += $lineAmount;

            acc_insert_row($pdo, $doc_id, [
                'account_code'   => '53',
                'bd'             => 0,
                'bs'             => $lineAmount,
                'description'    => $item['description']  ?? null,
                'commodity_id'   => $item['stuff_id']     ?? null,
                'commodity_count'=> $item['qty']          ?? null,
                'row_tax'        => $item['tax_amount']   ?? 0,
            ]);
        }

        // ردیف حمل — CREDIT به حساب درآمد حمل (61)
        $transferCost = (int)($params['transfer_cost'] ?? 0);
        if ($transferCost > 0) {
            $total += $transferCost;
            acc_insert_row($pdo, $doc_id, [
                'account_code' => '61',
                'bd'           => 0,
                'bs'           => $transferCost,
                'description'  => 'هزینه حمل',
            ]);
        }

        // ردیف تخفیف — DEBIT به حساب تخفیفات فروش (104)
        $discountAll = (int)($params['discount_all'] ?? 0);
        if ($discountAll > 0) {
            $total -= $discountAll;
            acc_insert_row($pdo, $doc_id, [
                'account_code' => '104',
                'bd'           => $discountAll,
                'bs'           => 0,
                'description'  => 'تخفیف فروش',
            ]);
        }

        // ردیف طرف حساب — DEBIT به حساب دریافتی (3) با person_id
        acc_insert_row($pdo, $doc_id, [
            'account_code' => '3',
            'bd'           => $total,
            'bs'           => 0,
            'person_id'    => $params['person_id'],
            'description'  => 'طرف حساب فروش',
        ]);

        $pdo->commit();
        return $doc_id;

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// سند خرید دوطرفه — منطق BuyController::app_buy_mod
//
// پارامترها مشابه acc_sell_journal
//
// منطق ردیف‌ها (مطابق hesabix):
//   هر کالا  → DEBIT  حساب '120' (موجودی کالا)   = sumWithoutTax + taxAmount
//   حمل      → DEBIT  حساب '90'  (هزینه حمل خرید) = transferCost
//   تخفیف    → CREDIT حساب '51'  (تخفیفات خرید)   = discountAll
//   طرف حساب → CREDIT حساب '8'   (اسناد پرداختنی) = total با person_id
// ─────────────────────────────────────────────────────────────────────────────
function acc_buy_journal(PDO $pdo, array $params): int
{
    $pdo->beginTransaction();

    try {
        $doc_id = acc_insert_doc($pdo, [
            'type'             => 'buy',
            'doc_date'         => $params['doc_date'],
            'description'      => $params['description'] ?? 'فاکتور خرید',
            'fiscal_year_id'   => $params['fiscal_year_id'],
            'created_by'       => $params['created_by'],
            'ref_id'           => $params['ref_id']           ?? null,
            'ref_type'         => $params['ref_type']         ?? 'fin_invoice',
            'tax_percent'      => $params['tax_percent']      ?? 0,
            'discount_type'    => $params['discount_type']    ?? 'fixed',
            'discount_percent' => $params['discount_percent'] ?? 0,
            'short_link'       => $params['short_link']       ?? null,
        ]);

        $total = 0;

        // ردیف‌های کالا — هر کالا یک DEBIT به حساب موجودی کالا (120)
        foreach ($params['rows'] as $item) {
            $lineAmount = (int)$item['amount_without_tax'] + (int)$item['tax_amount'];
            $total += $lineAmount;

            acc_insert_row($pdo, $doc_id, [
                'account_code'   => '120',
                'bd'             => $lineAmount,
                'bs'             => 0,
                'description'    => $item['description']  ?? null,
                'commodity_id'   => $item['stuff_id']     ?? null,
                'commodity_count'=> $item['qty']          ?? null,
                'row_tax'        => $item['tax_amount']   ?? 0,
            ]);
        }

        // ردیف حمل — DEBIT به هزینه حمل خرید (90)
        $transferCost = (int)($params['transfer_cost'] ?? 0);
        if ($transferCost > 0) {
            $total += $transferCost;
            acc_insert_row($pdo, $doc_id, [
                'account_code' => '90',
                'bd'           => $transferCost,
                'bs'           => 0,
                'description'  => 'هزینه حمل خرید',
            ]);
        }

        // ردیف تخفیف — CREDIT به تخفیفات خرید (51)
        $discountAll = (int)($params['discount_all'] ?? 0);
        if ($discountAll > 0) {
            $total -= $discountAll;
            acc_insert_row($pdo, $doc_id, [
                'account_code' => '51',
                'bd'           => 0,
                'bs'           => $discountAll,
                'description'  => 'تخفیف خرید',
            ]);
        }

        // ردیف طرف حساب — CREDIT به اسناد پرداختنی (8) با person_id
        acc_insert_row($pdo, $doc_id, [
            'account_code' => '8',
            'bd'           => 0,
            'bs'           => $total,
            'person_id'    => $params['person_id'],
            'description'  => 'طرف حساب خرید',
        ]);

        $pdo->commit();
        return $doc_id;

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// سند دریافت از مشتری — منطق sell_receive
//
// پارامترها:
//   doc_date, description, fiscal_year_id, created_by
//   person_id      — مشتری
//   amount         — مبلغ دریافتی
//   payment_type   — 'bank' یا 'cashdesk'
//   bank_id        — اگر payment_type == 'bank'
//   cashdesk_id    — اگر payment_type == 'cashdesk'
//   ref_id, ref_type
//
// منطق (مطابق hesabix):
//   DEBIT  حساب '5' (بانک) یا '121' (صندوق) = amount
//   CREDIT حساب '3' (دریافتی) با person_id  = amount
// ─────────────────────────────────────────────────────────────────────────────
function acc_receive_journal(PDO $pdo, array $params): int
{
    $pdo->beginTransaction();

    try {
        $doc_id = acc_insert_doc($pdo, [
            'type'           => 'receipt',
            'doc_date'       => $params['doc_date'],
            'description'    => $params['description'] ?? 'دریافت از مشتری',
            'fiscal_year_id' => $params['fiscal_year_id'],
            'created_by'     => $params['created_by'],
            'ref_id'         => $params['ref_id']   ?? null,
            'ref_type'       => $params['ref_type'] ?? 'fin_invoice',
        ]);

        $amount = (int)$params['amount'];

        // تعیین حساب دریافت: بانک (5) یا صندوق (121)
        $isBank = ($params['payment_type'] ?? 'bank') === 'bank';
        $receiveAccountCode = $isBank ? '5' : '121';

        // DEBIT — بانک یا صندوق
        acc_insert_row($pdo, $doc_id, [
            'account_code' => $receiveAccountCode,
            'bd'           => $amount,
            'bs'           => 0,
            'bank_id'      => $isBank ? ($params['bank_id'] ?? null) : null,
            'cashdesk_id'  => !$isBank ? ($params['cashdesk_id'] ?? null) : null,
            'description'  => $params['description'] ?? 'دریافت وجه',
        ]);

        // CREDIT — حساب دریافتی مشتری (3)
        acc_insert_row($pdo, $doc_id, [
            'account_code' => '3',
            'bd'           => 0,
            'bs'           => $amount,
            'person_id'    => $params['person_id'],
            'description'  => 'تسویه حساب مشتری',
        ]);

        $pdo->commit();
        return $doc_id;

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// سند پرداخت به تامین‌کننده — منطق buy_receive (معکوس دریافت)
//
// پارامترها مشابه acc_receive_journal
//
// منطق (مطابق hesabix):
//   DEBIT  حساب '8' (پرداختنی) با person_id = amount
//   CREDIT حساب '5' (بانک) یا '121' (صندوق) = amount
// ─────────────────────────────────────────────────────────────────────────────
function acc_pay_journal(PDO $pdo, array $params): int
{
    $pdo->beginTransaction();

    try {
        $doc_id = acc_insert_doc($pdo, [
            'type'           => 'payment',
            'doc_date'       => $params['doc_date'],
            'description'    => $params['description'] ?? 'پرداخت به تامین‌کننده',
            'fiscal_year_id' => $params['fiscal_year_id'],
            'created_by'     => $params['created_by'],
            'ref_id'         => $params['ref_id']   ?? null,
            'ref_type'       => $params['ref_type'] ?? 'fin_invoice',
        ]);

        $amount = (int)$params['amount'];

        // تعیین حساب پرداخت: بانک (5) یا صندوق (121)
        $isBank = ($params['payment_type'] ?? 'bank') === 'bank';
        $payAccountCode = $isBank ? '5' : '121';

        // DEBIT — اسناد پرداختنی تامین‌کننده (8)
        acc_insert_row($pdo, $doc_id, [
            'account_code' => '8',
            'bd'           => $amount,
            'bs'           => 0,
            'person_id'    => $params['person_id'],
            'description'  => 'تسویه حساب تامین‌کننده',
        ]);

        // CREDIT — بانک یا صندوق
        acc_insert_row($pdo, $doc_id, [
            'account_code' => $payAccountCode,
            'bd'           => 0,
            'bs'           => $amount,
            'bank_id'      => $isBank ? ($params['bank_id'] ?? null) : null,
            'cashdesk_id'  => !$isBank ? ($params['cashdesk_id'] ?? null) : null,
            'description'  => $params['description'] ?? 'پرداخت وجه',
        ]);

        $pdo->commit();
        return $doc_id;

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// اعتبارسنجی تراز سند — مجموع bd باید برابر مجموع bs باشد
// ─────────────────────────────────────────────────────────────────────────────
function acc_validate_doc(PDO $pdo, int $doc_id): bool
{
    $stmt = $pdo->prepare('
        SELECT
            SUM(bd) AS total_bd,
            SUM(bs) AS total_bs
        FROM fin_doc_rows
        WHERE doc_id = ?
    ');
    $stmt->execute([$doc_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // سند خالی نامعتبر است
    if (!$row || $row['total_bd'] === null) {
        return false;
    }

    return (int)$row['total_bd'] === (int)$row['total_bs'];
}
