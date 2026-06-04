-- ============================================================
-- Phase 11: اضافه کردن price_sell و price_buy به stuff_price_list
-- آتنا زیست درمان
-- تاریخ: ۱۴۰۵/۰۳/۱۴
-- ============================================================
-- مشکل: fin_invoice_sell.php و fin_invoice_buy.php به این ستون‌ها
--        نیاز دارند اما در schema تعریف نشده بودند.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `stuff_price_list`
  ADD COLUMN IF NOT EXISTS `price_sell`     BIGINT NOT NULL DEFAULT 0 COMMENT 'قیمت فروش رسمی',
  ADD COLUMN IF NOT EXISTS `price_buy`      BIGINT NOT NULL DEFAULT 0 COMMENT 'قیمت خرید / بهای تمام‌شده',
  ADD COLUMN IF NOT EXISTS `price_sell_imed`    BIGINT NOT NULL DEFAULT 0 COMMENT 'قیمت فروش آیمد',
  ADD COLUMN IF NOT EXISTS `price_sell_faradis` BIGINT NOT NULL DEFAULT 0 COMMENT 'قیمت فروش فرادیس',
  ADD COLUMN IF NOT EXISTS `price_sell_dermazon` BIGINT NOT NULL DEFAULT 0 COMMENT 'قیمت فروش درمازون';

-- sync مقادیر قدیمی به ستون‌های جدید
UPDATE `stuff_price_list` SET
  `price_sell`          = IF(`price_sell` = 0 AND `price` > 0, `price`, `price_sell`),
  `price_sell_imed`     = IF(`price_sell_imed` = 0 AND `price_imed` > 0, `price_imed`, `price_sell_imed`),
  `price_sell_faradis`  = IF(`price_sell_faradis` = 0 AND `price_faradis` > 0, `price_faradis`, `price_sell_faradis`),
  `price_sell_dermazon` = IF(`price_sell_dermazon` = 0 AND `price_dermazon` > 0, `price_dermazon`, `price_sell_dermazon`);

-- اضافه کردن price_sell به stuffs هم (fallback برای COALESCE)
ALTER TABLE `stuffs`
  ADD COLUMN IF NOT EXISTS `price_sell` BIGINT NOT NULL DEFAULT 0 COMMENT 'قیمت فروش پیش‌فرض',
  ADD COLUMN IF NOT EXISTS `price_buy`  BIGINT NOT NULL DEFAULT 0 COMMENT 'قیمت خرید پیش‌فرض',
  ADD COLUMN IF NOT EXISTS `unit`       VARCHAR(30) DEFAULT NULL COMMENT 'واحد اندازه‌گیری';
