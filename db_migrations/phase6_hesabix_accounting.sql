-- ============================================================
-- Phase 6: یکپارچه‌سازی پلان حساب‌ها با ساختار hesabix
-- آتنا زیست درمان — مرحله ۶
-- ============================================================
-- این migration پلان حساب‌ها را با ساختار اصلی hesabix یکپارچه می‌کند
-- و ستون‌های لازم را به جداول موجود اضافه می‌کند.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- ۱. بازسازی جدول fin_chart_of_accounts با ساختار hesabix
-- ─────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `fin_chart_of_accounts`;

CREATE TABLE `fin_chart_of_accounts` (
  `id`     INT NOT NULL,
  `upper_id` INT DEFAULT NULL COMMENT 'شناسه حساب والد',
  `name`   VARCHAR(255) NOT NULL COMMENT 'نام حساب',
  `type`   VARCHAR(50)  NOT NULL DEFAULT 'calc' COMMENT 'نوع: calc/person/bank/cheque/cashdesk/salary',
  `code`   VARCHAR(50)  NOT NULL COMMENT 'کد حساب (یکتا)',
  `entity` VARCHAR(100) DEFAULT NULL COMMENT 'موجودیت مرتبط (مثلاً Cheque)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_upper` (`upper_id`),
  KEY `idx_type`  (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='پلان حساب‌ها — ساختار hesabix';

-- ─────────────────────────────────────────────────────────────
-- درج ۱۴۲ حساب از hesabdari_table
-- ─────────────────────────────────────────────────────────────

INSERT INTO `fin_chart_of_accounts` (`id`, `upper_id`, `name`, `type`, `code`, `entity`) VALUES
(1,   NULL, 'جدول جساب',                              'calc',     '1',   NULL),
(2,   23,   'دارایی‌های جاری',                         'calc',     '2',   NULL),
(3,   2,    'حساب‌های دریافتی',                        'person',   '3',   NULL),
(4,   2,    'موجودی نقد و بانک',                       'calc',     '4',   NULL),
(5,   4,    'حساب‌های بانکی',                          'bank',     '5',   NULL),
(6,   24,   'بدهی‌های جاری',                           'calc',     '6',   NULL),
(7,   6,    'حساب ها و اسناد پرداختنی',               'calc',     '7',   NULL),
(8,   7,    'اسناد پرداختنی',                          'person',   '8',   NULL),
(9,   7,    'حساب‌های پرداختنی',                       'bank',     '9',   NULL),
(10,  23,   'دارایی های غیر جاری',                    'calc',     '10',  NULL),
(11,  10,   'دارایی های ثابت',                         'calc',     '11',  NULL),
(13,  11,   'زمین',                                    'calc',     '12',  NULL),
(15,  11,   'ساختمان',                                 'calc',     '13',  NULL),
(16,  11,   'وسائل نقلیه',                             'calc',     '14',  NULL),
(17,  11,   'اثاثیه اداری',                            'calc',     '15',  NULL),
(18,  10,   'استهلاک انباشته',                         'calc',     '16',  NULL),
(20,  18,   'استهلاک انباشته ساختمان',                 'calc',     '17',  NULL),
(21,  18,   'استهلاک انباشته وسائل نقلیه',             'calc',     '18',  NULL),
(22,  18,   'استهلاک انباشته اثاثیه اداری',            'calc',     '19',  NULL),
(23,  1,    'دارایی ها',                               'calc',     '20',  NULL),
(24,  1,    'بدهی ها',                                 'calc',     '21',  NULL),
(25,  6,    'سایر حساب های پرداختنی',                 'calc',     '22',  NULL),
(26,  25,   'ذخیره مالیات بر درآمد پرداختنی',          'calc',     '23',  NULL),
(27,  25,   'مالیات بر درآمد پرداختنی',               'calc',     '24',  NULL),
(28,  25,   'مالیات حقوق و دستمزد پرداختنی',          'calc',     '25',  NULL),
(29,  25,   'حق بیمه پرداختنی',                        'calc',     '26',  NULL),
(31,  25,   'حقوق و دستمزد پرداختنی',                 'calc',     '27',  NULL),
(32,  25,   'عیدی و پاداش پرداختنی',                  'calc',     '28',  NULL),
(33,  25,   'سایر هزینه های پرداختنی',                'calc',     '29',  NULL),
(34,  6,    'پیش دریافت ها',                           'calc',     '30',  NULL),
(35,  34,   'پیش دریافت فروش',                         'calc',     '31',  NULL),
(36,  34,   'سایر پیش دریافت ها',                     'calc',     '32',  NULL),
(37,  6,    'مالیات بر ارزش افزوده فروش',             'calc',     '33',  NULL),
(38,  24,   'بدهیهای غیر جاری',                       'calc',     '34',  NULL),
(39,  38,   'حساب ها و اسناد پرداختنی بلندمدت',       'calc',     '35',  NULL),
(40,  39,   'حساب های پرداختنی بلندمدت',              'calc',     '36',  NULL),
(41,  39,   'اسناد پرداختنی بلندمدت',                 'calc',     '37',  NULL),
(44,  38,   'ذخیره مزایای پایان خدمت کارکنان',        'calc',     '38',  NULL),
(45,  38,   'وام پرداختنی',                            'calc',     '39',  NULL),
(46,  1,    'حقوق صاحبان سهام',                        'calc',     '40',  NULL),
(47,  46,   'سرمایه',                                  'calc',     '41',  NULL),
(48,  47,   'سرمایه اولیه',                            'calc',     '42',  NULL),
(49,  47,   'افزایش یا کاهش سرمایه',                  'calc',     '43',  NULL),
(50,  47,   'اندوخته قانونی',                          'calc',     '44',  NULL),
(51,  47,   'برداشت ها',                               'calc',     '45',  NULL),
(52,  47,   'سهم سود و زیان',                          'calc',     '46',  NULL),
(53,  47,   'سود یا زیان انباشته (سنواتی)',            'calc',     '47',  NULL),
(54,  1,    'بهای تمام شده کالای فروخته شده',         'calc',     '48',  NULL),
(55,  54,   'بهای تمام شده کالای فروخته شده',         'calc',     '49',  NULL),
(56,  54,   'برگشت از خرید',                           'calc',     '50',  NULL),
(57,  54,   'تخفیفات نقدی خرید',                       'calc',     '51',  NULL),
(58,  1,    'فروش',                                    'calc',     '52',  NULL),
(59,  58,   'فروش کالا',                               'calc',     '53',  NULL),
(60,  58,   'برگشت از فروش',                           'calc',     '54',  NULL),
(61,  58,   'تخفیفات نقدی فروش',                       'calc',     '55',  NULL),
(64,  1,    'درآمد',                                   'calc',     '56',  NULL),
(66,  64,   'درآمد های عملیاتی',                      'calc',     '57',  NULL),
(67,  66,   'درآمد حاصل از فروش خدمات',               'calc',     '58',  NULL),
(68,  66,   'برگشت از خرید خدمات',                    'calc',     '59',  NULL),
(69,  66,   'درآمد اضافه کالا',                        'calc',     '60',  NULL),
(70,  66,   'درآمد حمل کالا',                          'calc',     '61',  NULL),
(72,  64,   'درآمد های غیر عملیاتی',                  'calc',     '62',  NULL),
(73,  72,   'درآمد حاصل از سرمایه گذاری',             'calc',     '63',  NULL),
(74,  72,   'درآمد سود سپرده ها',                     'calc',     '64',  NULL),
(75,  72,   'سایر درآمد ها',                           'calc',     '65',  NULL),
(76,  72,   'درآمد تسعیر ارز',                         'calc',     '66',  NULL),
(77,  1,    'هزینه ها',                                'calc',     '67',  NULL),
(78,  77,   'هزینه های پرسنلی',                       'calc',     '68',  NULL),
(79,  78,   'هزینه حقوق و دستمزد',                    'calc',     '69',  NULL),
(80,  79,   'حقوق پایه',                               'calc',     '70',  NULL),
(81,  79,   'اضافه کار',                               'calc',     '71',  NULL),
(82,  79,   'حق شیفت و شب کاری',                      'calc',     '72',  NULL),
(83,  79,   'حق نوبت کاری',                            'calc',     '73',  NULL),
(84,  79,   'حق ماموریت',                              'calc',     '74',  NULL),
(85,  79,   'فوق العاده مسکن و خاروبار',               'calc',     '75',  NULL),
(86,  79,   'حق اولاد',                                'calc',     '76',  NULL),
(87,  79,   'عیدی و پاداش',                            'calc',     '77',  NULL),
(88,  79,   'بازخرید سنوات خدمت کارکنان',             'calc',     '78',  NULL),
(89,  79,   'بازخرید مرخصی',                           'calc',     '79',  NULL),
(90,  79,   'بیمه سهم کارفرما',                        'calc',     '80',  NULL),
(91,  79,   'بیمه بیکاری',                             'calc',     '81',  NULL),
(92,  79,   'حقوق مزایای متفرقه',                      'calc',     '82',  NULL),
(93,  78,   'سایر هزینه های کارکنان',                 'calc',     '83',  NULL),
(94,  93,   'سفر و ماموریت',                           'calc',     '84',  NULL),
(95,  93,   'ایاب و ذهاب',                             'calc',     '85',  NULL),
(96,  93,   'سایر هزینه های کارکنان',                 'calc',     '86',  NULL),
(97,  77,   'هزینه های عملیاتی',                      'calc',     '87',  NULL),
(98,  97,   'خرید خدمات',                              'calc',     '88',  NULL),
(99,  97,   'برگشت از فروش خدمات',                    'calc',     '89',  NULL),
(100, 97,   'هزینه حمل کالا',                          'calc',     '90',  NULL),
(101, 97,   'تعمیر و نگهداری اموال و اثاثیه',         'calc',     '91',  NULL),
(102, 97,   'هزینه اجاره محل',                         'calc',     '92',  NULL),
(103, 97,   'هزینه های عمومی',                         'calc',     '93',  NULL),
(104, 97,   'هزینه ملزومات مصرفی',                    'calc',     '94',  NULL),
(105, 97,   'هزینه کسری و ضایعات کالا',               'calc',     '95',  NULL),
(106, 97,   'بیمه دارایی های ثابت',                   'calc',     '96',  NULL),
(107, 77,   'هزینه های استهلاک',                      'calc',     '97',  NULL),
(108, 107,  'هزینه استهلاک ساختمان',                  'calc',     '98',  NULL),
(109, 107,  'هزینه استهلاک وسائل نقلیه',              'calc',     '99',  NULL),
(110, 107,  'هزینه استهلاک اثاثیه',                   'calc',     '100', NULL),
(114, 77,   'هزینه های بازاریابی و توزیع و فروش',    'calc',     '101', NULL),
(115, 114,  'هزینه آگهی و تبلیغات',                   'calc',     '102', NULL),
(116, 114,  'هزینه بازاریابی و پورسانت',              'calc',     '103', NULL),
(117, 114,  'سایر هزینه های توزیع و فروش',           'calc',     '104', NULL),
(118, 77,   'هزینه های غیرعملیاتی',                   'calc',     '105', NULL),
(119, 118,  'هزینه های بانکی',                         'calc',     '106', NULL),
(120, 119,  'سود و کارمزد وامها',                     'calc',     '107', NULL),
(121, 119,  'کارمزد خدمات بانکی',                     'calc',     '108', NULL),
(122, 119,  'جرائم دیرکرد بانکی',                     'calc',     '109', NULL),
(123, 118,  'هزینه تسعیر ارز',                         'calc',     '110', NULL),
(124, 118,  'هزینه مطالبات سوخت شده',                 'calc',     '111', NULL),
(125, 1,    'سایر حساب ها',                            'calc',     '112', NULL),
(126, 125,  'حساب های انتظامی',                        'calc',     '113', NULL),
(127, 126,  'حساب های انتظامی',                        'calc',     '114', NULL),
(128, 126,  'طرف حساب های انتظامی',                   'calc',     '115', NULL),
(129, 125,  'حساب های کنترلی',                         'calc',     '116', NULL),
(130, 129,  'کنترل کسری و اضافه کالا',                'calc',     '117', NULL),
(132, 125,  'حساب خلاصه سود و زیان',                  'calc',     '118', NULL),
(133, 132,  'خلاصه سود و زیان',                        'calc',     '119', NULL),
(137, 2,    'موجودی کالا',                             'calc',     '120', NULL),
(138, 4,    'صندوق',                                   'cashdesk', '121', NULL),
(139, 4,    'تنخواه گردان',                            'salary',   '122', NULL),
(140, 7,    'تنخواه گردان',                            'salary',   '124', NULL),
(141, 7,    'صندوق',                                   'cashdesk', '123', NULL),
(142, 10,   'چک‌های دریافتی',                          'cheque',   '125', 'Cheque');

-- ─────────────────────────────────────────────────────────────
-- ۲. اضافه کردن ستون‌های جدید به fin_docs
-- ─────────────────────────────────────────────────────────────

ALTER TABLE `fin_docs`
  ADD COLUMN IF NOT EXISTS `tax_percent`       DECIMAL(5,2)  NOT NULL DEFAULT 0    COMMENT 'درصد مالیات' AFTER `type`,
  ADD COLUMN IF NOT EXISTS `discount_type`     VARCHAR(20)   NOT NULL DEFAULT 'fixed' COMMENT 'نوع تخفیف: fixed/percent' AFTER `description`,
  ADD COLUMN IF NOT EXISTS `discount_percent`  DECIMAL(10,2) NOT NULL DEFAULT 0    COMMENT 'درصد تخفیف' AFTER `discount_type`,
  ADD COLUMN IF NOT EXISTS `short_link`        VARCHAR(50)   DEFAULT NULL           COMMENT 'لینک کوتاه سند' AFTER `ref_type`;

-- ─────────────────────────────────────────────────────────────
-- ۳. تغییر ستون‌های debit/credit به bd/bs در fin_doc_rows
--    و اضافه کردن ستون‌های جزئیات ردیف
-- ─────────────────────────────────────────────────────────────

-- تغییر نام debit → bd و credit → bs (اگر هنوز تغییر نیافته‌اند)
ALTER TABLE `fin_doc_rows`
  CHANGE COLUMN IF EXISTS `debit`  `bd` DECIMAL(20,0) NOT NULL DEFAULT 0 COMMENT 'بدهکار',
  CHANGE COLUMN IF EXISTS `credit` `bs` DECIMAL(20,0) NOT NULL DEFAULT 0 COMMENT 'بستانکار';

-- اضافه کردن ستون‌های جدید
ALTER TABLE `fin_doc_rows`
  ADD COLUMN IF NOT EXISTS `commodity_id`    INT          DEFAULT NULL COMMENT 'شناسه کالا (stuffs.id)'       AFTER `person_id`,
  ADD COLUMN IF NOT EXISTS `commodity_count` DECIMAL(20,4) DEFAULT NULL COMMENT 'تعداد کالا'                  AFTER `commodity_id`,
  ADD COLUMN IF NOT EXISTS `cashdesk_id`     INT          DEFAULT NULL COMMENT 'شناسه صندوق'                  AFTER `commodity_count`,
  ADD COLUMN IF NOT EXISTS `bank_id`         INT          DEFAULT NULL COMMENT 'شناسه حساب بانکی'             AFTER `cashdesk_id`,
  ADD COLUMN IF NOT EXISTS `cheque_id`       INT          DEFAULT NULL COMMENT 'شناسه چک'                     AFTER `bank_id`,
  ADD COLUMN IF NOT EXISTS `row_discount`    DECIMAL(20,0) NOT NULL DEFAULT 0 COMMENT 'تخفیف ردیف'            AFTER `cheque_id`,
  ADD COLUMN IF NOT EXISTS `row_tax`         DECIMAL(20,0) NOT NULL DEFAULT 0 COMMENT 'مالیات ردیف'           AFTER `row_discount`,
  ADD COLUMN IF NOT EXISTS `referral`        VARCHAR(100) DEFAULT NULL COMMENT 'ارجاع / شرح تکمیلی'           AFTER `description`;

-- ─────────────────────────────────────────────────────────────
-- ۴. اضافه کردن ستون‌های تکمیلی به fin_persons
-- ─────────────────────────────────────────────────────────────

ALTER TABLE `fin_persons`
  ADD COLUMN IF NOT EXISTS `nikename`       VARCHAR(150) NULL COMMENT 'نام مستعار / نام تجاری'   AFTER `name`,
  ADD COLUMN IF NOT EXISTS `codeeghtesadi` VARCHAR(20)  NULL COMMENT 'کد اقتصادی'                AFTER `mobile`,
  ADD COLUMN IF NOT EXISTS `shenasemeli`   VARCHAR(20)  NULL COMMENT 'شناسه ملی / کد ملی'        AFTER `codeeghtesadi`,
  ADD COLUMN IF NOT EXISTS `company`       VARCHAR(200) NULL COMMENT 'نام شرکت'                  AFTER `shenasemeli`;

-- ─────────────────────────────────────────────────────────────
-- ۵. اضافه کردن ستون‌های تکمیلی به fin_cheques
-- ─────────────────────────────────────────────────────────────

ALTER TABLE `fin_cheques`
  ADD COLUMN IF NOT EXISTS `doc_id`           INT          DEFAULT NULL COMMENT 'سند حسابداری مرتبط (fin_docs.id)',
  ADD COLUMN IF NOT EXISTS `pay_date`         VARCHAR(12)  DEFAULT NULL COMMENT 'تاریخ پرداخت شمسی',
  ADD COLUMN IF NOT EXISTS `sayadnum`         VARCHAR(30)  DEFAULT NULL COMMENT 'شماره صیاد',
  ADD COLUMN IF NOT EXISTS `bank_on_cheque`   VARCHAR(100) DEFAULT NULL COMMENT 'نام بانک روی چک';

-- ─────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 1;
