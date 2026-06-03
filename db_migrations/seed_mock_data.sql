-- ============================================================
-- دیتای آزمایشی (Mock Data) — آتنا زیست درمان
-- برای پاک کردن: DELETE FROM جداول WHERE is_mock = 1
-- یا اجرای: db_migrations/seed_mock_data_clean.sql
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ─── کاربران تست ────────────────────────────────────────────
-- رمز همه: Admin@1234
INSERT IGNORE INTO `users` (`id`,`username`,`password`,`first_name`,`last_name`,`email`,`mobile`,`role`,`status`,`department_id`) VALUES
(10,'sara_ahmadi','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uRhR9Rjpm','سارا','احمدی','sara@atena.ir','09121000001','user','active',1),
(11,'ali_karimi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uRhR9Rjpm','علی','کریمی','ali@atena.ir','09121000002','user','active',1),
(12,'maryam_hosseini','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uRhR9Rjpm','مریم','حسینی','maryam@atena.ir','09121000003','user','active',1);

-- ─── مشتریان / مراکز ────────────────────────────────────────
INSERT IGNORE INTO `customers` (`id`,`company_name`,`name`,`state`,`mobile`,`is_active`,`is_deleted`) VALUES
(10,'بیمارستان امام خمینی تهران',  'دکتر رضایی',    'تهران',    '02100000001', 1, 0),
(11,'بیمارستان مهر اصفهان',        'دکتر کریمی',    'اصفهان',   '03100000002', 1, 0),
(12,'کلینیک پاتولوژی فارس',        'دکتر نوری',     'فارس',     '07100000003', 1, 0),
(13,'آزمایشگاه مرکزی مشهد',        'دکتر صادقی',    'خراسان رضوی','05100000004',1, 0),
(14,'مرکز پزشکی البرز',            'دکتر محمدی',    'البرز',    '02600000005', 1, 0),
(15,'بیمارستان رازی تبریز',        'دکتر عبادی',    'آذربایجان شرقی','04100000006',1,0),
(16,'کلینیک آسیا شیراز',           'دکتر زارعی',    'فارس',     '07100000007', 1, 0),
(17,'آزمایشگاه نور اهواز',         'دکتر مرادی',    'خوزستان',  '06100000008', 1, 0),
(18,'مرکز تشخیصی کرمان',           'دکتر قاسمی',    'کرمان',    '03400000009', 1, 0),
(19,'بیمارستان گلستان قم',         'دکتر حسن‌زاده', 'قم',       '02500000010', 1, 0);

-- ─── طرف‌حساب‌ها (fin_persons) ──────────────────────────────
INSERT IGNORE INTO `fin_persons` (`id`,`code`,`name`,`nikename`,`type`,`mobile`,`phone`,`email`,`address`,`is_active`,`is_deleted`) VALUES
(10,'P0010','بیمارستان امام خمینی','امام خمینی','customer','09120000001','02100000001','imam@hospital.ir','تهران — خیابان باهنر',1,0),
(11,'P0011','بیمارستان مهر اصفهان','مهر اصفهان','customer','09130000002','03100000002','mehr@hospital.ir','اصفهان — خیابان شریعتی',1,0),
(12,'P0012','شرکت تجهیزات پزشکی آریا','آریا مد','supplier','09140000003','02100000003','aria@medical.ir','تهران — شهرک صنعتی',1,0),
(13,'P0013','داروخانه مرکزی فارس','داروخانه فارس','supplier','09150000004','07100000004','pharma@fars.ir','شیراز — خیابان زند',1,0),
(14,'P0014','کلینیک جامع البرز','کلینیک البرز','both','09160000005','02600000005','alborz@clinic.ir','کرج — بلوار طالقانی',1,0);

-- ─── حساب‌های بانکی ─────────────────────────────────────────
INSERT IGNORE INTO `fin_bank_accounts` (`id`,`bank_name`,`branch_name`,`account_number`,`sheba_number`,`account_owner`,`balance`,`is_active`) VALUES
(1,'بانک ملت','شعبه ولیعصر','1234567890','IR120120000000001234567890','آتنا زیست درمان',50000000,1),
(2,'بانک تجارت','شعبه انقلاب','0987654321','IR380180000000000987654321','آتنا زیست درمان',25000000,1);

-- ─── صندوق نقدی ─────────────────────────────────────────────
INSERT IGNORE INTO `fin_cashdesks` (`id`,`name`,`description`,`balance`,`is_active`) VALUES
(1,'صندوق اصلی','صندوق نقدی دفتر مرکزی',5000000,1);

-- ─── کالاها / محصولات ───────────────────────────────────────
INSERT IGNORE INTO `stuffs` (`id`,`code`,`name`,`unit`,`description`,`is_active`) VALUES
(10,'PRD001','کیت بیوپسی استاندارد','عدد','کیت کامل بیوپسی بافت نرم',1),
(11,'PRD002','محلول فیکساتیو ۱۰٪','لیتر','محلول فرمالین بافر خنثی ۱۰٪',1),
(12,'PRD003','لام شیشه‌ای آزمایشگاهی','بسته','بسته ۵۰ عددی لام میکروسکوپی',1),
(13,'PRD004','رنگ هماتوکسیلین ائوزین','بطری','رنگ HE برای رنگ‌آمیزی بافت',1),
(14,'PRD005','پارافین آزمایشگاهی','کیلوگرم','پارافین مخصوص قالب‌گیری بافت',1),
(15,'PRD006','سرویس تعمیر میکروسکوپ','خدمت','سرویس دوره‌ای و تعمیر میکروسکوپ',1);

INSERT IGNORE INTO `stuff_price_list` (`stuff_id`,`price_sell`,`price_buy`,`total_inventory`) VALUES
(10, 850000,  600000, 120),
(11, 180000,  120000, 500),
(12,  95000,   60000, 800),
(13, 420000,  280000, 200),
(14, 310000,  200000, 350),
(15,1500000, 1000000,   0);

-- ─── فرصت‌های فروش ──────────────────────────────────────────
INSERT IGNORE INTO `crm_opportunities` (`id`,`title`,`customer_id`,`stage_id`,`status`,`amount`,`description`,`created_by`,`created_at`) VALUES
(10,'تامین کیت بیوپسی بیمارستان امام', 10, 3, 'active', 8500000, 'سفارش ۱۰ کیت برای بخش آسیب‌شناسی', 1, NOW()),
(11,'قرارداد سالانه محلول فیکساتیو',   11, 2, 'active', 5400000, 'تامین ۳۰ لیتر در ۶ نوبت',           1, NOW()),
(12,'فروش لام و رنگ به کلینیک البرز',  14, 4, 'active', 2850000, 'نیاز فوری کلینیک',                   1, NOW()),
(13,'سرویس میکروسکوپ آزمایشگاه مشهد', 13, 5, 'active', 1500000, 'سرویس سالانه',                       1, DATE_SUB(NOW(),INTERVAL 7 DAY)),
(14,'تامین پارافین بیمارستان رازی',    15, 1, 'active', 3100000, 'سفارش ۱۰ کیلوگرم',                  1, DATE_SUB(NOW(),INTERVAL 3 DAY));

-- ─── تماس‌های CRM ───────────────────────────────────────────
INSERT IGNORE INTO `crm_opportunity_calls` (`opportunity_id`,`user_id`,`call_date`,`call_type`,`duration`,`result`,`notes`,`created_at`) VALUES
(10, 1, DATE_SUB(CURDATE(),INTERVAL 5 DAY), 'outgoing', 15, 'positive', 'مشتری علاقه‌مند بود، منتظر تایید مدیریت', NOW()),
(10, 1, DATE_SUB(CURDATE(),INTERVAL 2 DAY), 'outgoing', 25, 'positive', 'تایید شد، نیاز به پیشنهاد رسمی دارند',    NOW()),
(11, 1, DATE_SUB(CURDATE(),INTERVAL 4 DAY), 'incoming', 10, 'neutral',  'سوال درباره قیمت و شرایط تحویل',          NOW()),
(12, 1, DATE_SUB(CURDATE(),INTERVAL 1 DAY), 'outgoing', 20, 'positive', 'پیشنهاد فرستاده شد، پیگیری فردا',         NOW());

-- ─── برنامه هفتگی CRM ───────────────────────────────────────
SET @this_week = DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY);
INSERT IGNORE INTO `crm_weekplan` (`week_start`,`user_id`,`customer_id`,`action_type`,`scheduled_date`,`done`,`notes`,`created_at`) VALUES
(@this_week, 1, 10, 'call',  CURDATE(),                             0, 'پیگیری سفارش کیت بیوپسی',       NOW()),
(@this_week, 1, 11, 'call',  DATE_ADD(CURDATE(),INTERVAL 1 DAY),   0, 'ارائه پیشنهاد قرارداد سالانه',   NOW()),
(@this_week, 1, 14, 'visit', DATE_ADD(CURDATE(),INTERVAL 2 DAY),   0, 'بازدید حضوری کلینیک البرز',      NOW()),
(@this_week, 1, 13, 'call',  DATE_SUB(CURDATE(),INTERVAL 1 DAY),   1, 'هماهنگی سرویس میکروسکوپ',       NOW()),
(@this_week, 1, 15, 'call',  DATE_ADD(CURDATE(),INTERVAL 3 DAY),   0, 'پیشنهاد پارافین',                NOW());

-- ─── فاکتور فروش نمونه ──────────────────────────────────────
INSERT IGNORE INTO `fin_invoices` (`id`,`invoice_number`,`type`,`person_id`,`invoice_date`,`subtotal`,`discount`,`tax`,`shipping`,`total_amount`,`paid_amount`,`status`,`notes`,`fiscal_year_id`,`created_by`) VALUES
(10,'INV-1404-001','sell',10,'1404/03/10', 8500000,  500000,  720000, 200000,  8920000, 8920000,'paid',   'تحویل کامل',1,1),
(11,'INV-1404-002','sell',11,'1404/03/12', 5400000,       0,  486000, 150000,  6036000, 3000000,'partial','پرداخت اول انجام شد',1,1),
(12,'INV-1404-003','sell',14,'1404/03/14', 2850000,  150000,  243000,      0,  2943000,       0,'confirmed','در انتظار پرداخت',1,1);

INSERT IGNORE INTO `fin_invoice_items` (`invoice_id`,`stuff_id`,`description`,`unit`,`qty`,`unit_price`,`discount_amt`,`tax_amt`,`total`) VALUES
(10,10,'کیت بیوپسی استاندارد','عدد',10, 850000, 500000, 720000,  8720000),
(11,11,'محلول فیکساتیو ۱۰٪', 'لیتر',30, 180000,      0, 486000,  5886000),
(12,12,'لام شیشه‌ای آزمایشگاهی','بسته',20,95000, 100000, 171000,  2071000),
(12,13,'رنگ HE','بطری',2,420000, 50000,  72000,   872000);

-- ─── درخواست مرخصی ──────────────────────────────────────────
INSERT IGNORE INTO `leave_requests` (`user_id`,`leave_type`,`start_date`,`end_date`,`days`,`reason`,`status`,`created_at`) VALUES
(10,'sick',  DATE_ADD(CURDATE(),INTERVAL 3 DAY),  DATE_ADD(CURDATE(),INTERVAL 4 DAY),  2,'بیماری فصلی','pending', NOW()),
(11,'annual',DATE_ADD(CURDATE(),INTERVAL 7 DAY),  DATE_ADD(CURDATE(),INTERVAL 9 DAY),  3,'مسافرت',      'approved',NOW()),
(12,'annual',DATE_SUB(CURDATE(),INTERVAL 10 DAY), DATE_SUB(CURDATE(),INTERVAL 8 DAY),  3,'استراحت',     'approved',NOW());

-- ─── درخواست‌های حضور ────────────────────────────────────────
INSERT IGNORE INTO `attendance_requests` (`user_id`,`date`,`clock_in`,`clock_out`,`status`,`notes`,`created_at`) VALUES
(10, CURDATE(),               '08:30', '17:15', 'approved', NULL,              NOW()),
(11, CURDATE(),               '09:00', '18:00', 'approved', NULL,              NOW()),
(12, CURDATE(),               '08:00', '16:30', 'approved', NULL,              NOW()),
(10, DATE_SUB(CURDATE(),INTERVAL 1 DAY), '08:45', '17:30', 'approved', NULL,  NOW()),
(11, DATE_SUB(CURDATE(),INTERVAL 1 DAY), '09:15', '18:30', 'approved', NULL,  NOW());

-- ─── ماموریت ────────────────────────────────────────────────
INSERT IGNORE INTO `mission_requests` (`user_id`,`start_date`,`end_date`,`destination`,`purpose`,`status`,`created_at`) VALUES
(10, DATE_ADD(CURDATE(),INTERVAL 5 DAY), DATE_ADD(CURDATE(),INTERVAL 6 DAY), 'اصفهان', 'بازدید از بیمارستان مهر', 'approved', NOW()),
(11, DATE_ADD(CURDATE(),INTERVAL 8 DAY), DATE_ADD(CURDATE(),INTERVAL 8 DAY), 'مشهد',   'پیگیری قرارداد آزمایشگاه', 'pending',  NOW());

-- ─── چک‌های دریافتی ─────────────────────────────────────────
INSERT IGNORE INTO `fin_cheques` (`cheque_number`,`type`,`bank_name`,`amount`,`due_date`,`due_date_j`,`status`,`person_id`,`description`,`created_by`) VALUES
('1234567','received','بانک ملت',      3000000, DATE_ADD(CURDATE(),INTERVAL 15 DAY), '1404/03/28','pending',10,'چک اول بیمارستان امام',1),
('7654321','received','بانک صادرات',   2000000, DATE_ADD(CURDATE(),INTERVAL 30 DAY), '1404/04/12','pending',11,'چک بیمارستان مهر',    1),
('1111111','issued',  'بانک تجارت',    1500000, DATE_ADD(CURDATE(),INTERVAL 45 DAY), '1404/04/27','pending',12,'پرداخت به آریا مد',   1);

SET foreign_key_checks = 1;

-- ────────────────────────────────────────────────────────────
-- برای پاک کردن کامل دیتای آزمایشی، این دستور را اجرا کنید:
-- mysql -u atenazis_user -pStrongPass123! atenazis_db < db_migrations/seed_mock_data_clean.sql
-- ────────────────────────────────────────────────────────────
