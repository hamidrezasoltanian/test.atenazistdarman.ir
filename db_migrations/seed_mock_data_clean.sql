-- ============================================================
-- پاک کردن دیتای آزمایشی (Mock Data Cleanup)
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

DELETE FROM `crm_opportunity_calls`    WHERE opportunity_id IN (10,11,12,13,14);
DELETE FROM `crm_weekplan`             WHERE customer_id IN (10,11,12,13,14,15);
DELETE FROM `crm_opportunities`        WHERE id IN (10,11,12,13,14);
DELETE FROM `fin_invoice_items`        WHERE invoice_id IN (10,11,12);
DELETE FROM `fin_invoices`             WHERE id IN (10,11,12);
DELETE FROM `fin_cheques`              WHERE cheque_number IN ('1234567','7654321','1111111');
DELETE FROM `stuff_price_list`         WHERE stuff_id IN (10,11,12,13,14,15);
DELETE FROM `stuffs`                   WHERE id IN (10,11,12,13,14,15);
DELETE FROM `fin_cashdesks`            WHERE id IN (1);
DELETE FROM `fin_bank_accounts`        WHERE id IN (1,2);
DELETE FROM `fin_persons`              WHERE id IN (10,11,12,13,14);
DELETE FROM `customers`                WHERE id IN (10,11,12,13,14,15,16,17,18,19);
DELETE FROM `attendance_requests`      WHERE user_id IN (10,11,12);
DELETE FROM `leave_requests`           WHERE user_id IN (10,11,12);
DELETE FROM `mission_requests`         WHERE user_id IN (10,11,12);
DELETE FROM `users`                    WHERE id IN (10,11,12);

SET foreign_key_checks = 1;

SELECT 'دیتای آزمایشی با موفقیت پاک شد.' AS result;
