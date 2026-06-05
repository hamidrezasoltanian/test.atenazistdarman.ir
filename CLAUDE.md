# CLAUDE.md — نقشه راه پروژه آتنا زیست درمان

> این فایل راهنمای اصلی Claude برای توسعه این پروژه است.
> هر بار که درخواست جدیدی دریافت می‌شود، این فایل به‌روز می‌شود.

---

## درباره پروژه

**نام:** آتنا زیست درمان — سیستم یکپارچه مدیریت کسب‌وکار (ERP)
**هدف نهایی:** نرم‌افزار کامل ERP شامل CRM، حسابداری دوطرفه، انبارداری، HR، اتوماسیون اداری و REST API موبایل

**وضعیت:** مرحله ۱۸ تکمیل شده — QMS کامل ISO 13485

---

## سه مخزن توسعه

| مخزن | مسیر | نقش |
|------|------|-----|
| `test.atenazistdarman.ir` | `/home/user/test.atenazistdarman.ir` | **پروژه اصلی** — همه کدهای جدید اینجا |
| `Sales-Portal` | `/home/user/Sales-Portal/atena_crm_v3_mtr_3.html` | منبع UI/UX و منطق CRM (7800+ خط HTML) |
| `hesabix-new` | `/home/user/hesabix-new` | منبع منطق حسابداری و انبارداری (Symfony) |

**قانون کلیدی:** منطق hesabix به PHP procedural ترجمه می‌شود — نه Symfony. UI از Sales-Portal الگوبرداری می‌شود.

---

## استک فنی

| لایه | فناوری |
|------|--------|
| Backend | PHP procedural + PDO |
| Database | MySQL/MariaDB |
| Frontend | HTML5 + jQuery 3.6.0 + Chart.js 4.4.0 |
| UI اضافی | TinyMCE 5، Select2، Sortable.js، Kama Datepicker |
| PDF | TCPDF (در vendor/) |
| SMS | IPPanel API (کانفیگ شده) — `sendFreeSms()` در fin_cheque_reminders.php |
| رمزنگاری | AES-256-CBC (پیام‌های چت) |
| تاریخ | شمسی در همه جا — تابع `jdate()` در functions.php |
| REST API | Bearer Token — `/api/v1/index.php` |

---

## ساختار پوشه‌ها

```
/
├── Config/config.php              ← DB، SMS، کلیدهای رمزنگاری
├── includes/
│   ├── db.php                     ← اتصال PDO
│   ├── functions.php              ← توابع کمکی (جلالی، آپلود، ...)
│   ├── auth.php                   ← احراز هویت + requireLogin()
│   └── menu_config.php            ← منوی سایدبار
├── templates/
│   ├── header.php / footer.php / sidebar.php
├── public_html/
│   ├── admin/                     ← 90+ فایل PHP
│   │   ├── actions/               ← POST handlerها
│   │   ├── ajax/                  ← AJAX endpointها
│   │   └── erp_dashboard.php      ← مرکز فرماندهی ERP
│   ├── api/
│   │   └── v1/index.php           ← REST API با Bearer Token
│   ├── customer/
│   │   └── index.php              ← پورتال مشتریان (OTP + تیکت پشتیبانی)
│   ├── assets/css/fin_module.css  ← سیستم طراحی مالی/انبار
│   └── uploads/[module]/          ← فایل‌های آپلودشده
└── db_migrations/                 ← SQLهای migration
    ├── phase2_accounting.sql
    ├── phase3_inventory.sql
    ├── phase5_indexes.sql
    └── phase6_payroll.sql
```

---

## وضعیت کامل ماژول‌ها

### ✅ مرحله ۰ — بیس اولیه (Initial Commit)

#### زیرساخت
- [x] لاگین با OTP + session
- [x] مدیریت نقش‌ها RBAC
- [x] مدیریت کاربران و دپارتمان‌ها
- [x] پروفایل با آواتار
- [x] لاگ فعالیت سیستم (`logs.php`)

#### CRM پایه
- [x] بورد کانبان فروش (`crm_opportunities_kanban.php`) — مراحل قابل تنظیم
- [x] مدیریت فرصت‌های فروش
- [x] ثبت تماس ورودی/خروجی
- [x] مدیریت مخاطبین (`crm_contacts.php`)
- [x] تگ‌بندی مشتریان
- [x] تقویم فروش (`crm_calendar.php`)
- [x] تایم‌لاین فعالیت
- [x] لاگ تغییر مراحل با زمان‌سنجی

#### HR
- [x] ثبت حضور و غیاب (`attendance_requests.php`)
- [x] درخواست مرخصی با جریان تأیید (`leave_requests.php / leave_manage.php`)
- [x] مدیریت ماموریت با ردیابی هزینه (`missions.php / mission_create.php`)
- [x] گزارش‌های حضور و مرخصی

#### مالی پایه
- [x] ردیابی هزینه‌ها (`fin_expenses.php`)
- [x] مدیریت سال مالی (`fiscal_years.php`)
- [x] دسته‌بندی هزینه (`fin_categories.php`)
- [x] دفتر تراکنش‌ها (`fin_transactions.php`)
- [x] درخواست شارژ (`fin_charge_requests.php`)

#### اتوماسیون اداری
- [x] نامه‌های وارده/صادره/داخلی (`letters_incoming/outgoing/internal.php`)
- [x] جریان ارجاع نامه (`letter_refer.php`)
- [x] قالب‌های نامه (`letter_templates.php`)
- [x] آرشیو، سطل آشغال، امضا و تأیید

#### ارتباطات
- [x] چت داخلی با رمزنگاری AES-256 (`chat.php`)
- [x] مکالمات گروهی/خصوصی
- [x] سیستم اعلانات با دسته‌بندی (`announcements.php`)

#### انبار پایه
- [x] لیست کالاها (`products_list.php`)
- [x] قیمت‌گذاری چندسطحی (`stuff_price_list`)
- [x] ردیابی موجودی مرکزی/مجازی/اسقاط

---

### ✅ مرحله ۱ — تکمیل CRM با داده Sales-Portal

**کامیت:** `80a3a2d` | **منبع:** Sales-Portal HTML

- [x] مدیریت ۳۰ استان + potential scoring (`crm_provinces.php`)
- [x] تخصیص کارشناس به استان (`crm_user_provinces`)
- [x] برنامه‌ریزی هفتگی کارشناسان (`crm_weekplan.php`)
- [x] سیستم مطالبات با فیلتر تاریخ/استان (`crm_receivables.php`)

---

### ✅ مرحله ۲ — حسابداری کامل

**کامیت:** `5899e8a` | **منبع:** hesabix SellController/ChequeController

**Migration:** `db_migrations/phase2_accounting.sql` — 9 جدول + seed پلان حساب‌های ایران

#### اصل طراحی دوطرفه:
```
هر فاکتور تأیید‌شده = fin_docs + fin_doc_rows (بدهکار/بستانکار)
فروش:   بدهکار 1103 (حساب دریافتنی) = بستانکار 4001 (درآمد) + بستانکار 2103 (مالیات)
خرید:   بدهکار 1104 (موجودی کالا)  = بستانکار 2101 (حساب پرداختنی)
دریافت: بدهکار 1101/1102 (صندوق/بانک) = بستانکار 1103
```

#### فایل‌های پیاده‌سازی‌شده:
| فایل | توضیح | خط |
|------|-------|-----|
| `fin_accounting_dashboard.php` | داشبورد + مدیریت بانک/صندوق | 903 |
| `fin_invoice_sell.php` | فاکتور فروش + سند دوطرفه | 1803 |
| `fin_persons.php` | طرف حساب‌ها (مشتری/تامین‌کننده) | 778 |
| `fin_cheques.php` | چرخه چک: pending→cleared/bounced/transferred | 1109 |
| `fin_accounts.php` | پلان حساب‌ها (درخت) | 496 |
| `assets/css/fin_module.css` | سیستم طراحی مشترک fin/inv | 430 |
| `testing_agent.php` | ایجنت تست خودکار | ∼350 |

#### تکمیل‌شده (مرحله ۲):
- [x] `fin_invoice_buy.php` — فاکتور خرید با سند دوطرفه
- [x] `fin_receive_pay.php` — دریافت از مشتری / پرداخت به تامین‌کننده
- [x] `fin_invoice_pdf.php` — چاپ PDF فاکتور با TCPDF
- [x] تقویم سررسید چک (نمایش ماهانه)

---

### ✅ مرحله ۳ — انبارداری

**کامیت‌ها:** `9847644`, `b869b79`, `9f626d4` | **منبع:** hesabix StoreroomController

**Migration:** `db_migrations/phase3_inventory.sql` — 3 جدول `inv_*`

| فایل | توضیح | خط |
|------|-------|-----|
| `inv_dashboard.php` | داشبورد + هشدار کم‌موجودی | 468 |
| `inv_storerooms.php` | CRUD انبارها | 647 |
| `inv_receipts.php` | رسید/حواله/انتقال/مرجوعی + آپدیت موجودی | 1075 |
| `inv_kardex.php` | کاردکس per کالا با مانده گردان | ∼300 |

**نکته کلیدی:** تأیید حواله → `stuff_price_list.total_inventory` آپدیت می‌شود

#### تکمیل‌شده (مرحله ۳):
- [x] ارزش‌گذاری موجودی FIFO — در `inv_kardex.php` با دکمه «💲 ارزش‌گذاری FIFO»
- [x] auto-dispatch هنگام تأیید فاکتور فروش — در `fin_invoice_sell.php`

---

### ✅ مرحله ۴ — گزارش‌دهی

**کامیت‌ها:** `ae799b3`, `21d271d`

| فایل | گزارش‌ها | خط |
|------|----------|-----|
| `fin_reports.php` | دفتر کل، تراز آزمایشی، سود/زیان، کارت حساب، aging فاکتور، گزارش چک، CSV | 1394 |
| `crm_reports.php` | قیف فروش، عملکرد کارشناسان، KPI ماهانه، تحلیل استان‌ها، تایم‌لاین | 1530 |

#### تکمیل‌شده (مرحله ۴):
- [x] گزارش مقایسه‌ای دوره‌ای — در `fin_reports.php`
- [x] ترازنامه رسمی (Balance Sheet) — در `fin_reports.php`
- [x] صدور CSV برای همه گزارش‌ها

---

### ✅ مرحله ۵ — ERP یکپارچه + REST API

**کامیت:** `fab0aba`

| فایل | توضیح | خط |
|------|-------|-----|
| `admin/erp_dashboard.php` | مرکز فرماندهی: ساعت زنده، هشدارها، ۶ کارت ماژول، نمودارها، فید فعالیت | 491 |
| `api/v1/index.php` | REST API کامل: auth، crm، fin، inv، hr، dashboard | 592+ |
| `db_migrations/phase5_indexes.sql` | ایندکس‌های performance + جدول api_tokens | ∼80 |

**Endpoints API:**
```
POST /api/v1/auth/login              ← دریافت Bearer Token
GET  /api/v1/crm/opportunities       ← فرصت‌های فروش
GET  /api/v1/fin/invoices            ← فاکتورها
GET  /api/v1/fin/persons             ← طرف حساب‌ها
GET  /api/v1/fin/cheques             ← چک‌ها
GET  /api/v1/inv/tickets             ← رسید/حواله
GET  /api/v1/inv/stock               ← موجودی کالا
GET  /api/v1/hr/leaves               ← مرخصی
GET  /api/v1/dashboard/summary       ← snapshot همه ماژول‌ها
```

---

## ارتباط بین جداول (نقشه کلیدی)

```
customers ──────────────────────── fin_persons
    │                                   │
    │                             person_id
    ▼                                   ▼
crm_opportunities ──opportunity_id──► fin_invoices ──── fin_invoice_items
    │                                   │                      │
    │                              fin_doc_id              stuff_id
    │                                   ▼                      ▼
    │                               fin_docs              stuffs / stuff_price_list
    │                                   │                      ▲
    │                              fin_doc_rows               │
    │                                   │               inv_ticket_items
    │                            account_id                    │
    │                                   ▼               inv_tickets
    │                       fin_chart_of_accounts              │
    │                                              storeroom_id
    │                                                   ▼
    └── HR (mission_requests) ──────────────── inv_storerooms
```

**پیوندهای حیاتی:**
- `fin_invoices.opportunity_id` → `crm_opportunities.id` — فاکتور از CRM
- `fin_invoices.person_id` → `fin_persons.id` — طرف حساب
- `fin_invoice_items.stuff_id` → `stuffs.id` — کالا
- `fin_docs.ref_id + ref_type` → هر سند مرجع خودش دارد
- `inv_ticket_items.stuff_id` → آپدیت `stuff_price_list.total_inventory`
- `fin_cheques.invoice_id` → چک مرتبط با فاکتور
- `support_tickets.person_id` → `fin_persons.id` — تیکت پشتیبانی مشتری
- `contracts.person_id` → `fin_persons.id` — قرارداد مشتری
- `warranty_records.stuff_id` → `stuffs.id` — گارانتی کالا
- `quotes.person_id` → `fin_persons.id` — آفر/پیشنهاد قیمت

---

## جداول دیتابیس (کامل)

### هسته
`users` · `roles` · `settings` · `departments`

### CRM
`crm_boards` · `crm_board_stages` · `crm_opportunities` · `crm_opportunity_assignees` · `crm_opportunity_calls` · `crm_opportunity_tasks` · `crm_opportunity_notes` · `crm_opportunity_activities` · `crm_opportunity_stage_logs` · `crm_contacts` · `crm_user_provinces` · `crm_provinces` · `crm_receivables`

### ارتباطات
`conversations` · `messages` · `conversation_participants` · `announcements` · `announcement_reads` · `user_notifications`

### HR
`attendance_requests` · `leave_requests` · `mission_requests` · `mission_items` · `mission_types`

### مالی پایه (بیس)
`fin_expenses` · `fin_categories` · `fin_transactions` · `fin_charge_requests` · `fiscal_years`

### حسابداری (مرحله ۲)
`fin_chart_of_accounts` · `fin_persons` · `fin_docs` · `fin_doc_rows` · `fin_invoices` · `fin_invoice_items` · `fin_bank_accounts` · `fin_cashdesks` · `fin_cheques`

### انبار (بیس + مرحله ۳)
`stuffs` · `stuff_price_list` · `discount_codes` · `inv_storerooms` · `inv_tickets` · `inv_ticket_items`

### مکاتبات
`letters` · `letter_referrals` · `letter_templates` · `letter_indicators` · `notes`

### مشتریان
`customers` · `customer_followers` · `customer_tag_links` · `tags`

### API (مرحله ۵)
`api_tokens`

### حقوق و دستمزد (مرحله ۶)
`hr_payroll` · `hr_payroll_plans` · `hr_commission_logs` · `fin_cheque_sms_log`

### پورتال مشتریان
`customer_portal_sessions`

### پشتیبانی و قراردادها (مرحله ۸)
`support_tickets` · `contracts` · `warranty_records` · `quotes`

---

## APIهای موجود

### REST API v1 (Bearer Token)
| مسیر | متد | توضیح |
|------|-----|-------|
| `/api/v1/auth/login` | POST | دریافت توکن |
| `/api/v1/auth/logout` | POST | ابطال توکن |
| `/api/v1/crm/opportunities` | GET | فرصت‌ها |
| `/api/v1/crm/contacts` | GET | مخاطبین |
| `/api/v1/fin/invoices` | GET | فاکتورها |
| `/api/v1/fin/persons` | GET | طرف حساب‌ها |
| `/api/v1/fin/cheques` | GET | چک‌ها |
| `/api/v1/inv/tickets` | GET | رسید/حواله |
| `/api/v1/inv/stock` | GET | موجودی |
| `/api/v1/hr/leaves` | GET | مرخصی |
| `/api/v1/dashboard/summary` | GET | خلاصه ERP |
| `/api/v1/contracts` | GET | قراردادها |
| `/api/v1/support-tickets` | GET | تیکت‌های پشتیبانی |
| `/api/v1/warranty` | GET | رکوردهای گارانتی |
| `/api/v1/quotes` | GET | آفرها/پیشنهاد قیمت |

### API های داخلی (jQuery AJAX)
| مسیر | عملکرد |
|------|--------|
| `/api/chat_api.php` | چت (ارسال، ویرایش، خواندن) |
| `/api/notifications_poll.php` | دریافت اعلانات |
| `/api/users_search.php` | جستجوی کاربر |
| هر صفحه admin با `?action=X` | AJAX داخلی همان صفحه |

---

## قوانین توسعه (باید رعایت شوند)

### کد
- تمام توضیحات کد به **فارسی**
- **prepared statements** برای همه کوئری‌ها — بدون استثنا
- تاریخ‌ها همیشه **شمسی** نمایش داده شوند (`jdate()`)
- آپلود فایل‌ها در `public_html/uploads/[module]/`
- **CSRF token** برای تمام فرم‌های POST: `csrf_field()` + `csrf_verify()`
- AJAX detection: `!empty($_SERVER['HTTP_X_REQUESTED_WITH'])` → JSON + exit
- Soft delete با `is_deleted = 1` — نه DELETE فیزیکی

### UI/UX
- رابط کاملاً **RTL** (راست به چپ)
- فونت **Vazirmatn** (بدون کوتیشن در CSS داخل PHP string)
- CSS کلاس‌های مشترک: `fin-panel`, `fin-stat-card`, `fin-drawer`, `fin-table`, `fin-badge`, `fin-btn`, `fin-tabs`
- رنگ کارت‌های stat: `.blue` `.green` `.rose` `.amber` `.purple` `.cyan`

### دیتابیس
- پیشوند جداول: `crm_` / `fin_` / `inv_` / `hr_` / `api_`
- فیلدهای اجباری جدید: `created_at`, `updated_at`, `is_deleted`

---

## ✅ مرحله ۶ — تکمیل‌شده

### ✅ ۶.۱ — تکمیل حلقه مالی
- [x] `fin_invoice_buy.php` — فاکتور خرید + سند دوطرفه + رسید انبار خودکار
- [x] `fin_receive_pay.php` — دریافت/پرداخت + sync پورسانت
- [x] `fin_invoice_pdf.php` — چاپ PDF با TCPDF + RTL

### ✅ ۶.۲ — پیش‌فاکتور
- [x] `fin_preinvoice.php` — بدون سند حسابداری، قابل تبدیل به فاکتور

### ✅ ۶.۳ — حقوق، دستمزد و پورسانت
- [x] `hr_payroll.php` — ۴ تب: حقوق / پورسانت پلکانی / KPI / پلن
- [x] پورسانت: نرخ پایه ۱٪، آستانه ۲ میلیارد، پله ۵۰۰ میلیون / ۰.۱٪
- [x] KPI فصلی: نمره ≥۸۰ → پله ۰.۲٪
- [x] تخفیف اضافی به پزشک/بیمارستان از مبنای پورسانت کسر می‌شود
- [x] `db_migrations/phase6_payroll.sql`

### ✅ ۶.۴ — یکپارچه‌سازی CRM ↔ مالی
- [x] دکمه «فاکتور فروش» روی کارت کانبان
- [x] تب «💰 مالی» در مودال فرصت کانبان
- [x] تب «💰 مالی» و sidebar بدهی در `customer_profile.php`

### ✅ ۶.۵ — تنخواه‌گردان
- [x] `fin_petty_cash.php` — اتصال به سرفصل‌های حسابداری + بانک/صندوق
- [x] سند دوطرفه برای تخصیص، هزینه، شارژ، تسویه

### ✅ ۶.۶ — مطالبات از فاکتور
- [x] `crm_receivables.php` — بازنویسی با JOIN صحیح fin_persons
- [x] نمای خلاصه مشتری + ریز فاکتور + روزهای معوق

### ✅ ۶.۷ — گزارش‌های تکمیلی
- [x] ترازنامه (Balance Sheet) در `fin_reports.php`
- [x] گزارش مقایسه‌ای دوره‌ای در `fin_reports.php`
- [x] ارزش‌گذاری FIFO در `inv_kardex.php`

### ✅ ۶.۸ — یادآور SMS چک
- [x] `fin_cheque_reminders.php` — لیست معوق، ارسال تکی و گروهی
- [x] لاگ ارسال SMS در `fin_cheque_sms_log`
- [x] قالب پیام با متغیر {نام} {مبلغ} {سررسید} {شماره_چک}

---

## ✅ مرحله ۷ — تکمیل‌شده

### ✅ ۷.۱ — QR حضور و غیاب
- [x] ثبت حضور با QR code — اسکن در موبایل
- [x] تولید QR یکتا per کاربر
- [x] لاگ ورود/خروج با timestamp

### ✅ ۷.۲ — صدور Excel
- [x] صدور Excel (`.xlsx`) — پیاده‌سازی شده
- [x] گزارش‌های مالی قابل دانلود به فرمت xlsx

### ✅ ۷.۳ — PWA (Progressive Web App)
- [x] service worker + manifest.json
- [x] قابل نصب روی موبایل از مرورگر
- [x] کش offline برای صفحات اصلی

---

## ✅ مرحله ۸ — تکمیل‌شده

### ✅ ۸.۱ — Endpoints جدید REST API
- [x] `GET /api/v1/contracts` — لیست قراردادها
- [x] `GET /api/v1/support-tickets` — لیست تیکت‌های پشتیبانی
- [x] `GET /api/v1/warranty` — رکوردهای گارانتی
- [x] `GET /api/v1/quotes` — آفرها/پیشنهادهای قیمت
- [x] احراز هویت Bearer Token یکسان با سایر endpointها
- [x] فیلتر `is_deleted=0` و محدودیت ۱۰۰ ردیف

### ✅ ۸.۲ — پورتال مشتریان (تیکت پشتیبانی)
- [x] جدول تیکت‌های پشتیبانی در پورتال مشتری (`customer/index.php`)
- [x] بج وضعیت: open=rose، in_progress=amber، resolved=green، closed=gray
- [x] دکمه «تیکت جدید» با فرم inline
- [x] ثبت خودکار جدول `support_tickets` (CREATE TABLE IF NOT EXISTS)
- [x] شماره‌گذاری: TKT-YYYYMM-XXXX

### ✅ ۸.۳ — ساختار جداول جدید
- [x] `support_tickets` — تیکت‌های پشتیبانی با person_id, status, priority
- [x] `contracts` — قراردادها با person_id, amount, start_date, end_date
- [x] `warranty_records` — گارانتی کالا با stuff_id, serial_number, expiry_date
- [x] `quotes` — آفر/پیشنهاد قیمت با person_id, quote_number, total_amount

### ✅ ۸.۴ — بهبودهای فنی
- [x] Caching نتایج گزارش‌ها
- [x] Full-text search روی فرصت‌ها و طرف حساب‌ها
- [x] بهینه‌سازی کوئری‌های سنگین با index های اضافی

---

---

## ✅ مرحله ۹ — تکمیل‌شده (بررسی و رفع مشکلات دیتابیس)

### ✅ ۹.۱ — رفع مشکلات ساختاری (`phase9_db_fixes.sql`)
- [x] `AUTO_INCREMENT` به `fin_chart_of_accounts.id` اضافه شد (بحرانی)
- [x] `is_deleted` به ۱۱ جدول فاقد soft-delete اضافه شد
- [x] `updated_at` به ۷ جدول فاقد timestamp اضافه شد
- [x] ایندکس روی ۵ ستون FK اضافه شد (crm_followup_log, hr_commissions, attendance_checkins, ...)
- [x] ایندکس‌های عملکردی روی contracts, warranty, support_tickets, fin_quotes

### ✅ ۹.۲ — رفع مشکلات معماری (`phase10_architecture_fixes.sql`)
- [x] یکپارچه‌سازی `customers ↔ fin_persons`: ستون `person_id` + FK به `customers` اضافه شد
- [x] یکپارچه‌سازی تاریخ: `DATE` میلادی → `VARCHAR(12)` شمسی در جداول مرحله ۸
- [x] یکپارچه‌سازی مبلغ: `DECIMAL(20,0)` → `BIGINT` در ۶ جدول مرحله ۸
- [x] ۳۰+ Foreign Key Constraint با `ON DELETE SET NULL / CASCADE` مناسب
- [x] تمام ستون‌های FK از `INT DEFAULT 0` به `INT UNSIGNED DEFAULT NULL` تبدیل شدند

---

## ✅ مرحله ۱۰ — تکمیل‌شده (امنیت + ماژول واردات + قیمت‌گذاری)

### ✅ ۱۰.۱ — رفع ۸ مشکل امنیتی
- [x] `attendance_checkin_manage.php`: guard مدیریت برای `edit_time` و `save_settings`
- [x] `import_dashboard.php`: `csrf_verify()` بدون آرگومان، SQL خام notDone، MIME validation آپلود
- [x] `fin_invoice_sell.php`: SQL خام در بازیابی شماره فاکتور
- [x] `customer/index.php`: `rand()` → `random_int()` برای OTP
- [x] `mission_view.php`: `mission_status_label()` به نسخه role-aware

### ✅ ۱۰.۲ — داشبورد ERP بازطراحی شده
- [x] کارت‌های KPI سفید با indicator روند (↑/↓)
- [x] پنل هشدار: چک‌های سررسید ۱۴ روز + کالاهای کم‌موجودی
- [x] فید فعالیت با timestamp شمسی

### ✅ ۱۰.۳ — ماژول واردات (`import_dashboard.php`)
- [x] ۵ مرحله پیش‌فرض: ثبت سفارش، ترخیص گمرک، حمل‌ونقل، انبارداری، اداره کل تجهیزات
- [x] مراحل کاملاً سفارشی (افزودن/ویرایش/غیرفعال)
- [x] ثبت هزینه با رسید + آپلود اسناد با MIME validation
- [x] منوی مستقل در سایدبار

### ✅ ۱۰.۴ — ماژول قیمت‌گذاری کامل (`phase11_price_sell_buy.sql`)
- [x] ستون‌های `price_sell`, `price_buy`, `price_sell_imed/faradis/dermazon` به `stuff_price_list` اضافه شد
- [x] `products_list.php`: فرم inline ویرایش ۶ سطح قیمت + حداقل موجودی
- [x] `fin_invoice_sell.php`: انتخابگر سطح قیمت (پایه/آیمد/فرادیس/درمازون) — قیمت خودکار پر می‌شود
- [x] `fin_quote.php`: رفع schema (DATE→VARCHAR12، DECIMAL→BIGINT)

---

---

## ✅ مرحله ۱۱ — تکمیل‌شده (Batch/Lot + انقضا + تلگرام بات + IRC)

### ✅ ۱۱.۱ — ردیابی Batch/Lot و تاریخ انقضا (`phase11_batch_expiry.sql`)
- [x] ستون‌های `batch_number VARCHAR(50)`, `expiry_date VARCHAR(12)` به `inv_ticket_items` اضافه شد
- [x] ستون‌های `irc_code VARCHAR(20)`, `product_type ENUM(...)`, `shelf_life_months` به `stuffs` اضافه شد
- [x] جدول `inv_batch_stock` — موجودی per Batch/Lot/انبار

### ✅ ۱۱.۲ — بروزرسانی رابط کاربری انبار
- [x] `inv_receipts.php`: ستون‌های Lot و تاریخ انقضا در فرم رسید/حواله + ذخیره در `inv_batch_stock`
- [x] `inv_dashboard.php`: پنل هشدار کالاهای منقضی (قرمز) و در آستانه انقضا ۳۰ روز (زرد)
- [x] `inv_kardex.php`: ستون‌های batch و انقضا با رنگ‌بندی (قرمز=منقضی، زرد=آستانه)

### ✅ ۱۱.۳ — بلاک فروش کالای منقضی
- [x] `fin_invoice_sell.php`: بررسی Lot منقضی قبل از تأیید فاکتور — بلاک با پیام خطا

### ✅ ۱۱.۴ — تلگرام بات
- [x] `includes/telegram.php`: `telegramSend()`, `telegramDailyReport()`, `telegramExpiryAlert()`, `telegramCheckAlert()`
- [x] `public_html/admin/telegram_settings.php`: صفحه تنظیمات کامل — توکن، Chat ID، ساعت ارسال، تست
- [x] `includes/menu_config.php`: لینک «تنظیمات تلگرام» در منوی تنظیمات

### ✅ ۱۱.۵ — کد IRC و نوع محصول در کالاها
- [x] `products_list.php`: نمایش IRC code در جدول (چیپ آبی)
- [x] `products_list.php`: فرم ویرایش IRC، نوع محصول، عمر مفید (ماه) در مودال جزئیات
- [x] `products_list.php`: action جدید `save_stuff_info` با prepared statement

---

---

## ✅ مرحله ۱۲ — تکمیل‌شده (جریان تأیید چندسطحی)

### ✅ ۱۲.۱ — موتور تأیید
- [x] `db_migrations/phase12_approval.sql` — ۴ جدول: approval_flows, flow_steps, requests, logs
- [x] `includes/approval.php` — توابع: `approvalGetFlow`, `approvalCreate`, `approvalGetStatus`, `approvalCanAct`, `approvalProcess`, `approvalGetPending`
- [x] جریان پیش‌فرض: فاکتور خرید بالای ۱ میلیارد تومان + هزینه بالای ۵۰۰ میلیون → تأیید management

### ✅ ۱۲.۲ — رابط کاربری
- [x] `approval_inbox.php` — کارتابل تأیید: لیست درخواست‌های pending + approve/reject با یادداشت + تاریخچه کامل
- [x] `approval_flows.php` — مدیریت جریان‌ها: CRUD جریان + مراحل
- [x] `sidebar.php`: بج عددی تأییدهای در انتظار روی لینک کارتابل
- [x] `menu_config.php`: کارتابل تأیید در بالای منو + تنظیمات جریان‌ها

### ✅ ۱۲.۳ — یکپارچه‌سازی fin_invoice_buy
- [x] هنگام تأیید فاکتور خرید: اگر مبلغ بالای آستانه باشد → draft ذخیره + approval_request ایجاد + پیام "در انتظار تأیید"
- [x] اگر قبلاً approved شده باشد → مستقیم confirm
- [x] هنگام تأیید در approval_inbox → status فاکتور به confirmed تغییر می‌کند
- [x] اطلاع‌رسانی تلگرام به درخواست‌کننده پس از تأیید نهایی

### جداول جدید (مرحله ۱۲)
`approval_flows` · `approval_flow_steps` · `approval_requests` · `approval_request_logs`

---

---

## ✅ مرحله ۱۳ — تکمیل‌شده (CAPA + شکایات ISO 13485)

### هدف: آمادگی برای گواهینامه ISO 13485

### ✅ ۱۳.۱ — ماژول شکایات پیشرفته (§8.2.1 / §8.2.6)
- [x] `qms_complaints.php` — ثبت و پیگیری شکایات با audit trail کامل
- [x] دسته‌بندی ISO 13485: منبع (مشتری/داخلی/regulatory)، نوع (کیفیت/ایمنی/عملکرد)، درجه (بحرانی/اصلی/جزئی)
- [x] فلگ `regulatory_reportable` + محاسبه خودکار مهلت ۳۰ روزه MDMA
- [x] شماره‌گذاری: CMP-YYYYMM-XXXX
- [x] دکمه «ایجاد CAPA» مستقیم از روی شکایت

### ✅ ۱۳.۲ — ماژول CAPA (§8.5.2 / §8.5.3)
- [x] `capa.php` — چرخه کامل: باز → بررسی → برنامه اقدام → اجرا → تأیید اثربخشی → بسته
- [x] تفکیک CA (اصلاحی) / PA (پیشگیرانه)
- [x] روش‌های تحلیل ریشه: ۵ چرا، نمودار استخوان ماهی، درخت خطا
- [x] جدول اقدامات تفصیلی با مسئول و سررسید
- [x] لاگ audit trail کامل هر تغییر وضعیت
- [x] هشدار CAPA‌های گذشته از سررسید (رنگ قرمز)

### ✅ ۱۳.۳ — منوی کیفیت ISO 13485
- [x] بخش «کیفیت ISO 13485» در سایدبار
- [x] لینک‌های شکایات و CAPA

### جداول جدید (مرحله ۱۳)
`qms_complaints` · `capa_requests` · `capa_actions` · `capa_status_logs`

### نقشه راه ISO 13485 باقی‌مانده
| فاز | بند | موضوع | اولویت |
|-----|-----|--------|--------|
| ~~۱۴~~ | ~~§8.3~~ | ~~کنترل محصول نامنطبق (NC)~~ | ~~بحرانی~~ ✅ |
| ~~۱۵~~ | ~~§6.2~~ | ~~سوابق آموزشی + ماتریس شایستگی~~ | ~~بحرانی~~ ✅ |
| ~~۱۵~~ | ~~§7.4~~ | ~~ارزیابی تأمین‌کنندگان + AVL~~ | ~~بالا~~ ✅ |
| ~~۱۶~~ | ~~§4.2.4~~ | ~~کنترل مدارک با نسخه‌بندی~~ | ~~بالا~~ ✅ |
| ~~۱۷~~ | ~~§8.2.2~~ | ~~ممیزی داخلی + بازنگری مدیریت~~ | ~~متوسط~~ ✅ |

---

## ✅ مرحله ۱۴ — تکمیل‌شده (کنترل محصول نامنطبق ISO 13485 §8.3)

### ✅ ۱۴.۱ — ماژول NC (Nonconforming Product)
- [x] `nc_records.php` — ثبت، پیگیری و تصمیم‌گیری برای محصولات نامنطبق
- [x] شماره‌گذاری: NC-YYYYMM-XXXX
- [x] منابع کشف: بازرسی ورودی، حین فرآیند، مرجوعی مشتری، یافته ممیزی، بررسی انبار، منقضی
- [x] تصمیم‌گیری (Disposition): قرنطینه، دوباره‌کاری، مرجوع به تأمین‌کننده، اسقاط، استفاده با مجوز، امتیاز
- [x] هنگام قرنطینه: `inv_batch_stock.is_quarantined=1` و `nc_id` بروز می‌شود
- [x] دکمه «ایجاد CAPA» مستقیم از روی رکورد NC
- [x] لاگ audit trail کامل هر تغییر وضعیت (`nc_status_logs`)
- [x] کارت‌های آماری: کل باز، بحرانی، قرنطینه فعال، بسته‌شده

### ✅ ۱۴.۲ — یکپارچه‌سازی با انبار
- [x] `db_migrations/phase14_nc.sql` — جداول `nc_records`, `nc_status_logs`
- [x] `ALTER TABLE inv_batch_stock ADD COLUMN is_quarantined + nc_id`
- [x] فلگ قرنطینه در موجودی batch به‌صورت خودکار از NC تنظیم می‌شود

### ✅ ۱۴.۳ — منوی QMS به‌روزشده
- [x] لینک «محصول نامنطبق §8.3» در زیرمنوی «مدیریت کیفیت (QMS)»

### جداول جدید (مرحله ۱۴)
`nc_records` · `nc_status_logs`

### ستون‌های جدید (مرحله ۱۴)
`inv_batch_stock.is_quarantined` · `inv_batch_stock.nc_id`

---

## ✅ مرحله ۱۵ — تکمیل‌شده (سوابق آموزشی §6.2 + ارزیابی تأمین‌کنندگان §7.4)

### ✅ ۱۵.۱ — سوابق آموزشی و ماتریس شایستگی (§6.2)
- [x] `hr_training.php` — کاتالوگ دوره‌ها، ثبت سوابق پرسنل، ماتریس شایستگی، گزارش شکاف
- [x] تعریف دوره با نوع: داخلی/خارجی/OJT/آنلاین/قانونی
- [x] ثبت سابقه با نمره، نتیجه قبول/رد، شماره گواهینامه، تاریخ انقضا
- [x] هشدار گواهینامه منقضی (قرمز) و در آستانه انقضا ۳۰ روز (زرد)
- [x] ماتریس شایستگی: تعریف دوره‌های الزامی per نقش
- [x] گزارش شکاف: نمایش پرسنلی که دوره الزامی را نگذرانده‌اند

### ✅ ۱۵.۲ — ارزیابی تأمین‌کنندگان و AVL (§7.4)
- [x] `sup_evaluation.php` — فهرست تأمین‌کنندگان با وضعیت AVL + تاریخچه ارزیابی
- [x] شماره‌گذاری: SUP-YYYY-XXXX
- [x] وضعیت AVL: new / approved / conditional / suspended / removed
- [x] ارزیابی ۴ معیار: کیفیت، تحویل، قیمت، خدمات (هرکدام ۰-۱۰۰)
- [x] محاسبه خودکار امتیاز کل + تصمیم AVL: ≥۷۵%=تأییدشده | ۵۰-۷۵%=مشروط | <۵۰%=تعلیق
- [x] به‌روزرسانی خودکار وضعیت AVL تأمین‌کننده پس از هر ارزیابی
- [x] لینک به `fin_persons` برای اتصال مالی

### جداول جدید (مرحله ۱۵)
`hr_training_sessions` · `hr_training_records` · `hr_competency_requirements` · `sup_suppliers` · `sup_evaluations`

---

## ✅ مرحله ۱۶ — تکمیل‌شده (کنترل مدارک ISO 13485 §4.2.4)

### ✅ ۱۶.۱ — سیستم کنترل مدارک
- [x] `doc_control.php` — فهرست مدارک کنترل‌شده با نسخه‌بندی کامل
- [x] شماره‌گذاری: DOC-YYYY-XXXX
- [x] دسته‌بندی: سیستم کیفیت، روش اجرایی، دستورالعمل، فرم، خط‌مشی، مشخصه فنی، الزامات قانونی
- [x] پشتیبانی از مدارک خارجی (استاندارد، آیین‌نامه، ...)
- [x] چرخه تأیید: پیش‌نویس → در بررسی → تأییدشده → منسوخ

### ✅ ۱۶.۲ — نسخه‌بندی مدارک
- [x] ثبت نسخه جدید با آپلود فایل (PDF، Word، Excel، تصویر) با MIME validation
- [x] لاگ کامل تغییر وضعیت هر نسخه (audit trail)
- [x] نسخه‌های قبلی approved هنگام ثبت نسخه جدید به superseded تبدیل می‌شوند
- [x] پیشرفت وضعیت: draft → review → approved با تأیید مسئولان

### ✅ ۱۶.۳ — کنترل توزیع
- [x] ثبت توزیع مدارک per کاربر / دپارتمان
- [x] روش توزیع: الکترونیک / چاپ / ایمیل
- [x] تأیید دریافت (acknowledgment) با تاریخ

### ✅ ۱۶.۴ — هشدار بازنگری
- [x] کارت «نیاز به بازنگری ۳۰ روز» در داشبورد
- [x] تب مدارک با تاریخ بازنگری نزدیک یا گذشته
- [x] رنگ‌بندی هشدار: قرمز=گذشته، زرد=نزدیک

### جداول جدید (مرحله ۱۶)
`doc_documents` · `doc_versions` · `doc_distribution` · `doc_status_logs`

---

## ✅ مرحله ۱۷ — تکمیل‌شده (ممیزی داخلی §8.2.2 + بازنگری مدیریت §5.6)

### ✅ ۱۷.۱ — ممیزی داخلی (§8.2.2)
- [x] `qms_audit.php` — برنامه‌ریزی، اجرا و پیگیری ممیزی‌ها
- [x] شماره‌گذاری: AUD-YYYYMM-XXXX
- [x] انواع ممیزی: داخلی / خارجی / نظارتی / صدور گواهی
- [x] تعریف دامنه، معیار، بندهای ISO مرتبط، سرممیز و تیم
- [x] چرخه وضعیت: برنامه‌ریزی‌شده → در جریان → تکمیل‌شده
- [x] هشدار ممیزی‌های گذشته از برنامه (رنگ قرمز)

### ✅ ۱۷.۲ — یافته‌های ممیزی
- [x] ثبت یافته با نوع: NC اصلی / NC جزئی / مشاهده / فرصت بهبود
- [x] مرجع بند ISO، دپارتمان، شواهد، مسئول پاسخگویی
- [x] ایجاد CAPA خودکار از یافته (CA برای NC، PA برای فرصت)
- [x] پیگیری وضعیت یافته تا بستن
- [x] فهرست کلی یافته‌های باز با هشدار سررسید

### ✅ ۱۷.۳ — بازنگری مدیریت (§5.6)
- [x] شماره‌گذاری: MR-YYYYMM-XXXX
- [x] ورودی‌های §5.6.2: نتایج ممیزی / بازخورد مشتری / عملکرد فرآیند / انطباق محصول / CAPA / تغییرات برنامه‌ریزی / الزامات قانونی
- [x] خروجی‌های §5.6.3: تصمیمات بهبود / نیاز منابع / تغییرات محصول / اقدامات مصوب
- [x] چرخه: پیش‌نویس → تکمیل‌شده

### جداول جدید (مرحله ۱۷)
`qms_audit_plans` · `qms_audit_findings` · `qms_management_reviews`

---

## ✅ مرحله ۱۸ — تکمیل‌شده (واجب‌های QMS — داشبورد + API + PDF + یادآور)

### ✅ ۱۸.۱ — داشبورد QMS
- [x] `erp_dashboard.php`: ردیف KPI جدید QMS — ۴ کارت: NC باز، CAPA باز، مدارک نیاز به بازنگری، گواهینامه در حال انقضا
- [x] نوار هشدار بالا: chips برای CAPA معوق و NC باز
- [x] پنل هشدارها: CAPA‌های معوق با سررسید + NC‌های قرنطینه‌شده

### ✅ ۱۸.۲ — REST API QMS
- [x] `GET /api/v1/qms/nc` — لیست NC records
- [x] `GET /api/v1/qms/capa` — لیست CAPA requests
- [x] `GET /api/v1/qms/audits` — لیست ممیزی‌ها
- [x] `GET /api/v1/qms/summary` — snapshot QMS (همان اعداد داشبورد)

### ✅ ۱۸.۳ — PDF گزارش‌های QMS
- [x] `qms_pdf.php` — تولید PDF با TCPDF برای NC / CAPA / ممیزی
- [x] دکمه PDF در `nc_records.php`، `capa.php`، `qms_audit.php`
- [x] هدر/فوتر با نام شرکت، شماره صفحه، تاریخ چاپ

### ✅ ۱۸.۴ — یادآور خودکار
- [x] `qms_reminders.php` — صفحه یادآور اجرایی (CLI/cron + وب)
- [x] `includes/telegram.php`: تابع `telegramQmsAlert()` — گزارش روزانه QMS
- [x] اعلان داخلی CAPA سررسید ۷ روز آینده → `user_notifications`
- [x] اعلان داخلی گواهینامه انقضا ۳۰ روز آینده → `user_notifications`
- [x] اعلان داخلی مدارک نیاز به بازنگری → `user_notifications`
- [x] جلوگیری از اعلان تکراری (بررسی DATE همان روز)
- [x] لینک «یادآورهای QMS» در منوی QMS (فقط admin)

### کامیت: `eba7d8c`

---

## بهبودهای فنی باقی‌مانده (اختیاری)
- [ ] React Native / Flutter کلاینت از `/api/v1/*`
- [ ] WebSocket برای chat realtime (جایگزین polling)
- [ ] sync خودکار `customers ↔ fin_persons` بر اساس شماره موبایل

---

## تاریخچه تغییرات

| تاریخ | کامیت | تغییر |
|-------|-------|-------|
| ۱۴۰۵/۰۳/۱۲ | `86d58dc` | ایجاد اولیه — بیس کامل CRM+HR+مالی پایه |
| ۱۴۰۵/۰۳/۱۲ | `f004c4a` | CLAUDE.md اولیه + نقشه راه |
| ۱۴۰۵/۰۳/۱۲ | `2ede928` | به‌روزرسانی نقشه یکپارچه‌سازی |
| ۱۴۰۵/۰۳/۱۲ | `80a3a2d` | مرحله ۱: استان‌ها، برنامه هفتگی، مطالبات |
| ۱۴۰۵/۰۳/۱۲ | `5899e8a` | مرحله ۲: حسابداری کامل + ایجنت تست |
| ۱۴۰۵/۰۳/۱۳ | `9847644` | مرحله ۳: انبارداری — migration + داشبورد |
| ۱۴۰۵/۰۳/۱۳ | `b869b79` | مرحله ۳: انبارها |
| ۱۴۰۵/۰۳/۱۳ | `9f626d4` | مرحله ۳: رسید/حواله + کاردکس |
| ۱۴۰۵/۰۳/۱۳ | `ae799b3` | مرحله ۴: گزارش‌های مالی |
| ۱۴۰۵/۰۳/۱۳ | `21d271d` | مرحله ۴: گزارش CRM |
| ۱۴۰۵/۰۳/۱۳ | `fab0aba` | مرحله ۵: داشبورد ERP + REST API v1 + ایندکس‌ها |
| ۱۴۰۵/۰۳/۱۳ | — | مرحله ۶: تکمیل حلقه مالی + حقوق + SMS چک |
| ۱۴۰۵/۰۳/۱۴ | — | مرحله ۷: QR حضور + Excel + PWA |
| ۱۴۰۵/۰۳/۱۴ | — | مرحله ۸: API endpoints جدید + تیکت پشتیبانی پورتال مشتری |
| ۱۴۰۵/۰۳/۱۴ | `2ccea26` | مرحله ۹: رفع ۸ مشکل امنیتی |
| ۱۴۰۵/۰۳/۱۴ | `7198075` | مرحله ۹: رفع مشکلات ساختاری دیتابیس |
| ۱۴۰۵/۰۳/۱۴ | `f9ead57` | مرحله ۱۰: رفع مشکلات معماری دیتابیس |
| ۱۴۰۵/۰۳/۱۴ | `91ea6f3` | مرحله ۱۰: ماژول قیمت‌گذاری کامل |
| ۱۴۰۵/۰۳/۱۵ | — | مرحله ۱۱: Batch/Lot + انقضا + تلگرام بات + IRC code |
| ۱۴۰۵/۰۳/۱۵ | — | مرحله ۱۲: جریان تأیید چندسطحی + کارتابل تأیید |
| ۱۴۰۵/۰۳/۱۵ | — | مرحله ۱۳: CAPA + شکایات ISO 13485 |
| ۱۴۰۵/۰۳/۱۵ | — | مرحله ۱۴: کنترل محصول نامنطبق NC — ISO 13485 §8.3 |
| ۱۴۰۵/۰۳/۱۵ | — | مرحله ۱۵: سوابق آموزشی §6.2 + ارزیابی تأمین‌کنندگان §7.4 |
| ۱۴۰۵/۰۳/۱۵ | — | مرحله ۱۶: کنترل مدارک با نسخه‌بندی — ISO 13485 §4.2.4 |
| ۱۴۰۵/۰۳/۱۵ | — | مرحله ۱۷: ممیزی داخلی §8.2.2 + بازنگری مدیریت §5.6 |
| ۱۴۰۵/۰۳/۱۵ | `eba7d8c` | مرحله ۱۸: داشبورد QMS + REST API + PDF + یادآور خودکار |
