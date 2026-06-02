# CLAUDE.md — نقشه راه پروژه آتنا زیست درمان

> این فایل راهنمای اصلی من (Claude) برای توسعه این پروژه است.
> هر بار که درخواست جدیدی دریافت می‌کنم، این فایل را به‌روز می‌کنم تا همیشه با ترجیحات و جهت‌گیری پروژه همسو باشم.

---

## درباره پروژه

**نام:** آتنا زیست درمان — سیستم یکپارچه مدیریت کسب‌وکار
**هدف نهایی:** یک نرم‌افزار کامل ERP شامل:
- **CRM** — مدیریت مشتریان، فرصت‌های فروش، تماس‌ها، مدیریت استان‌ها، مطالبات
- **حسابداری** — فاکتور، پرداخت/دریافت، حسابداری دوطرفه، ترازنامه، سود و زیان
- **انبارداری** — رسید/حواله انبار، کاردکس، هشدار موجودی
- **HR** — حضور و غیاب، مرخصی، ماموریت
- **اتوماسیون اداری** — مکاتبات، نامه‌ها، اعلانات

---

## سه مخزن موجود (منابع توسعه)

### ۱. `test.atenazistdarman.ir` — پروژه اصلی (PHP procedural)
مسیر: `/home/user/test.atenazistdarman.ir`
پروژه اصلی که همه کدهای جدید اینجا می‌روند.

### ۲. `Sales-Portal` — پورتال فروش (Frontend HTML/JS)
مسیر: `/home/user/Sales-Portal/atena_crm_v3_mtr_3.html`

یک فایل HTML تک‌صفحه‌ای کامل با ۷۸۰۰+ خط کد. شامل:
- **مدیریت استان‌ها** — ۳۰ استان با potential scoring، درصد بیوپسی، تخصیص کارشناس
- **کانبان** — نمایش کانبان برای هر استان/مرکز
- **برنامه هفته** — برنامه‌ریزی هفتگی کارشناسان
- **تقویم شمسی** — نمایش ماهانه/هفتگی رویدادها
- **چک‌لیست روزانه** — وظایف روزانه قابل تنظیم
- **لاگ فعالیت** — تایم‌لاین تماس، ویزیت، فروش، ماموریت
- **KPI** — شاخص‌های عملکرد فروش با اهداف
- **بررسی مدیر** — داشبورد مدیریتی
- **مطالبات (MTR)** — سیستم ردیابی مطالبات با فیلتر تاریخ
- **مدیریت کاربران** — افزودن/ویرایش/جابجایی bulk کارشناسان
- دیتا در localStorage ذخیره می‌شود (باید به DB منتقل شود)
- وضعیت‌های CRM: بدون تماس / تماس اولیه / ملاقات انجام شد / پیشنهاد ارسال شد / قرارداد بسته شد / غیرفعال
- نوع سرنخ: مشتری / لید / فرصت / سرنخ / ندارد / بدون مصرف

### ۳. `hesabix-new` — سیستم حسابداری (Symfony PHP)
مسیر: `/home/user/hesabix-new`

یک سیستم حسابداری کامل و بالغ با Symfony. **منبع اصلی منطق حسابداری و انبارداری** است.

**Entity های کلیدی:**
| Entity | کاربرد |
|--------|---------|
| `HesabdariDoc` / `HesabdariRow` | سند حسابداری دوطرفه |
| `HesabdariTable` | پلان حساب‌ها |
| `Commodity` / `CommodityCat` / `CommodityUnit` | کالا و دسته‌بندی |
| `Storeroom` / `StoreroomItem` / `StoreroomTicket` | انبار، موجودی، حواله/رسید |
| `Person` / `PersonCard` | طرف حساب (مشتری/تامین‌کننده) |
| `BankAccount` / `Cashdesk` / `Cheque` | بانک، صندوق، چک |
| `PriceList` / `PriceListDetail` | لیست قیمت چندسطحی |
| `PreInvoiceDoc` / `PreInvoiceItem` | پیش‌فاکتور |
| `Money` / `WalletTransaction` | کیف پول و تراکنش |
| `PlugHrmDoc` / `PlugHrmDocItem` | HR (حقوق و دستمزد) |
| `PlugGhestaDoc` / `PlugGhestaItem` | اقساط/قسطی |

**Controller های کلیدی:**
| Controller | کاربرد |
|------------|---------|
| `SellController` | فاکتور فروش (ایجاد، ویرایش، چاپ، نمودار) |
| `BuyController` | فاکتور خرید |
| `PreinvoiceController` | پیش‌فاکتور |
| `StoreroomController` | انبار، رسید/حواله، کاردکس |
| `HesabdariDocController` | ثبت اسناد حسابداری دوطرفه |
| `ReportController` | گزارش‌های مالی (سود/زیان، ترازنامه) |
| `BankController` | مدیریت حساب‌های بانکی |
| `CashdeskController` | صندوق |
| `ChequeController` | مدیریت چک |
| `PersonsController` | طرف حساب‌ها |
| `YearController` | سال مالی |

---

## استک فنی

| لایه | فناوری |
|------|--------|
| Backend | PHP (procedural) |
| Database | MySQL/MariaDB + PDO |
| Frontend | HTML5 + CSS3 + jQuery 3.6.0 |
| UI Components | TinyMCE 5، Select2، Sortable.js |
| PDF | TCPDF |
| تاریخ شمسی | Kama Datepicker |
| SMS | Kavenegar API |
| رمزنگاری | AES-256-CBC (پیام‌های چت) |

---

## ساختار پوشه‌ها (پروژه اصلی)

```
/
├── Config/
│   └── config.php              # تنظیمات DB، SMS، کلیدهای رمزنگاری
├── includes/
│   ├── db.php                  # اتصال PDO
│   ├── functions.php           # توابع کمکی (تاریخ، آپلود، ...)
│   ├── auth.php                # احراز هویت
│   └── menu_config.php         # تنظیمات منوی سایدبار
├── templates/
│   ├── header.php
│   ├── footer.php
│   └── sidebar.php
├── public_html/
│   ├── admin/                  # صفحات ادمین (۸۰+ فایل PHP)
│   │   ├── actions/            # هندلرهای عملیات
│   │   └── ajax/               # endpointهای AJAX
│   ├── api/                    # REST APIها
│   ├── assets/                 # CSS، JS، فونت، تصاویر
│   ├── uploads/                # فایل‌های آپلودشده
│   └── dashboard.php           # داشبورد اصلی
├── backups/db_migrations/       # اسکریپت‌های بکاپ
└── vendor/                      # کتابخانه‌های ثالث
```

---

## ماژول‌های موجود (وضعیت فعلی)

### ✅ پیاده‌سازی شده

#### احراز هویت و کاربران
- [x] لاگین با OTP
- [x] مدیریت نقش‌ها (RBAC)
- [x] مدیریت کاربران و دپارتمان‌ها
- [x] پروفایل با آواتار
- [x] لاگ فعالیت سیستم

#### CRM
- [x] بورد کانبان فروش (مراحل قابل تنظیم)
- [x] مدیریت فرصت‌های فروش
- [x] ثبت تماس (ورودی/خروجی)
- [x] مدیریت مخاطبین
- [x] تگ‌بندی مشتریان
- [x] تقویم فروش
- [x] KPI فروش
- [x] تایم‌لاین فعالیت
- [x] لاگ تغییر مراحل با زمان‌سنجی

#### ارتباطات
- [x] چت داخلی با رمزنگاری AES-256
- [x] مدیریت مکالمات (گروهی/خصوصی)
- [x] پین پیام، ویرایش، وضعیت خواندن
- [x] سیستم اعلانات با دسته‌بندی

#### HR
- [x] ثبت حضور و غیاب
- [x] درخواست مرخصی با جریان تأیید
- [x] مدیریت ماموریت با ردیابی هزینه
- [x] گزارش‌های حضور و مرخصی

#### مالی (پایه)
- [x] ردیابی هزینه‌ها
- [x] مدیریت سال مالی
- [x] حساب‌های مالی
- [x] دسته‌بندی هزینه
- [x] دفتر تراکنش‌ها
- [x] سیستم درخواست شارژ

#### اتوماسیون اداری (نامه‌ها)
- [x] ایجاد و ویرایش نامه
- [x] نامه‌های وارده/صادره/داخلی
- [x] جریان ارجاع نامه
- [x] قالب‌های نامه
- [x] آرشیو و سطل آشغال
- [x] امضا و تأیید نامه

#### انبار (پایه)
- [x] لیست کالاها
- [x] قیمت‌گذاری چندسطحی
- [x] ردیابی موجودی (مرکزی، مجازی، اسقاط)
- [x] دسته‌بندی کالا

---

## نقشه راه یکپارچه‌سازی (با اولویت‌بندی)

> منبع هر فیچر مشخص است: [اصلی] [Sales-Portal] [hesabix]

### 🔴 مرحله ۱ — تکمیل CRM با داده Sales-Portal

#### ۱.۱ مدیریت استان‌ها و قلمروبندی (از Sales-Portal)
- [ ] جدول `crm_provinces` — ۳۰ استان با potential و درصد بیوپسی
- [ ] تخصیص کارشناس به استان (جایگزین localStorage)
- [ ] نمایش کانبان per استان
- [ ] bulk reassign کارشناسان بین استان‌ها

#### ۱.۲ سیستم مطالبات (از Sales-Portal)
- [ ] جدول `crm_receivables` — ردیابی مطالبات مشتریان
- [ ] پنل مطالبات با فیلتر تاریخ، کارشناس، استان
- [ ] گزارش مطالبات معوق

#### ۱.۳ تکمیل KPI و گزارش فروش (از Sales-Portal)
- [ ] جداول اهداف KPI per کارشناس per ماه
- [ ] داشبورد KPI با progress bar
- [ ] نمودار تحلیلی فروش (قیف فروش)
- [ ] بررسی مدیر — مقایسه عملکرد کارشناسان

#### ۱.۴ لاگ‌های فعالیت غنی‌تر (از Sales-Portal)
- [ ] لاگ ویزیت (مجزا از تماس تلفنی)
- [ ] لاگ ماموریت در CRM (ارتباط با HR)
- [ ] لاگ فروش — ثبت فروش واقعی در timeline

---

### 🟠 مرحله ۲ — حسابداری کامل (از hesabix)

> منطق کامل از hesabix پیاده‌سازی می‌شود، اما در PHP procedural و با UI موجود

#### ۲.۱ پلان حساب‌ها و سند حسابداری
- [ ] جدول `fin_chart_of_accounts` — پلان حساب‌ها (برگرفته از HesabdariTable)
- [ ] جدول `fin_docs` / `fin_doc_rows` — اسناد حسابداری دوطرفه (برگرفته از HesabdariDoc/Row)
- [ ] ثبت سند دستی
- [ ] مرور دفتر کل

#### ۲.۲ فاکتور فروش و خرید (از SellController/BuyController)
- [ ] جدول `fin_invoices` / `fin_invoice_items`
- [ ] صدور فاکتور فروش با PDF (TCPDF)
- [ ] فاکتور خرید
- [ ] پیش‌فاکتور (از PreinvoiceController)
- [ ] ارتباط فاکتور با فرصت CRM (فاکتور از فرصت)

#### ۲.۳ دریافت و پرداخت (از BankController/CashdeskController)
- [ ] مدیریت حساب‌های بانکی
- [ ] صندوق نقدی
- [ ] ثبت دریافت از مشتری
- [ ] ثبت پرداخت به تامین‌کننده

#### ۲.۴ مدیریت چک (از ChequeController)
- [ ] جدول `fin_cheques`
- [ ] چک دریافتی / پرداختی
- [ ] تقویم سررسید چک

#### ۲.۵ گزارش‌های مالی (از ReportController)
- [ ] گزارش سود و زیان
- [ ] ترازنامه
- [ ] گزارش جریان نقدی
- [ ] کارت حساب هر طرف حساب

---

### 🟡 مرحله ۳ — انبارداری کامل (از hesabix StoreroomController)

> منطق کامل از StoreroomController و موجودیت‌های Storeroom/StoreroomTicket

- [ ] جدول `inv_storerooms` — تعریف انبارها
- [ ] جدول `inv_tickets` / `inv_ticket_items` — رسید و حواله (از StoreroomTicket)
- [ ] سند رسید انبار (خرید → انبار)
- [ ] سند حواله انبار (فروش → کسر از انبار)
- [ ] انتقال بین انبارها
- [ ] گزارش کاردکس (موجودی هر کالا)
- [ ] هشدار موجودی حداقل
- [ ] ارتباط خودکار با فاکتور فروش/خرید

---

### 🟢 مرحله ۴ — گزارش‌دهی پیشرفته

- [ ] داشبورد مدیریتی با نمودارهای تعاملی (Chart.js)
- [ ] گزارش‌های قابل تنظیم
- [ ] صدور Excel
- [ ] گزارش مقایسه‌ای دوره‌ای

### 🔵 مرحله ۵ — بهبودهای فنی

- [ ] بهینه‌سازی کوئری‌های DB + index روی جداول پرکاربرد
- [ ] Caching
- [ ] API یکپارچه REST JSON برای موبایل

---

## قوانین توسعه (که من باید رعایت کنم)

### کد
- همه توضیحات کد به **فارسی** باشد
- از prepared statements برای تمام کوئری‌ها استفاده شود
- تاریخ‌ها همیشه **شمسی** نمایش داده شوند
- فایل‌های آپلودی در مسیر مناسب `public_html/uploads/[module]/` ذخیره شوند
- CSRF token برای تمام فرم‌های POST الزامی است
- منطق hesabix به PHP procedural ترجمه شود (نه Symfony)
- UI کامپوننت‌های Sales-Portal به صفحات PHP تبدیل شوند

### UI/UX
- رابط کاربری کاملاً **راست به چپ (RTL)**
- رنگ‌بندی و آیکون‌ها با سیستم موجود همسو باشد
- تاریخ شمسی در تمام فرم‌ها و گزارش‌ها

### دیتابیس
- نام جداول به انگلیسی با prefix ماژول (`crm_`، `fin_`، `inv_`)
- فیلد `created_at` و `updated_at` در تمام جداول جدید
- Soft delete با فیلد `deleted_at` یا `is_deleted`

---

## جداول دیتابیس (خلاصه)

### هسته
`users` · `roles` · `settings` · `departments`

### CRM (موجود)
`crm_boards` · `crm_board_stages` · `crm_opportunities` · `crm_opportunity_assignees` · `crm_opportunity_calls` · `crm_opportunity_tasks` · `crm_opportunity_notes` · `crm_opportunity_activities` · `crm_opportunity_stage_logs` · `crm_contacts` · `crm_user_provinces`

### CRM (جدید — از Sales-Portal)
`crm_provinces` · `crm_receivables` · `crm_kpi_targets` · `crm_visit_log` · `crm_sales_log`

### ارتباطات
`conversations` · `messages` · `conversation_participants` · `announcements` · `announcement_reads`

### HR
`attendance_requests` · `leave_requests` · `mission_requests` · `mission_items` · `mission_types`

### مالی (موجود)
`fin_expenses` · `fin_accounts` · `fin_categories` · `fin_transactions` · `fin_charge_requests` · `fiscal_years`

### مالی (جدید — از hesabix)
`fin_chart_of_accounts` · `fin_docs` · `fin_doc_rows` · `fin_invoices` · `fin_invoice_items` · `fin_bank_accounts` · `fin_cashdesks` · `fin_cheques` · `fin_persons`

### مکاتبات
`letters` · `letter_referrals` · `letter_templates` · `letter_indicators` · `notes`

### انبار (موجود)
`stuffs` · `stuff_price_list` · `discount_codes`

### انبار (جدید — از hesabix)
`inv_storerooms` · `inv_tickets` · `inv_ticket_items` · `inv_commodity_units` · `inv_price_lists`

### مشتریان
`customers` · `customer_followers` · `customer_tag_links` · `tags`

---

## APIهای فعلی

| مسیر | عملکرد |
|------|--------|
| `/api/chat_api.php` | مدیریت چت (ارسال، ویرایش، خواندن) |
| `/api/notifications_poll.php` | دریافت اعلانات |
| `/api/notifications_mark_read.php` | علامت خواندن |
| `/api/call_comments.php` | نظرات تماس |
| `/api/customer_tags.php` | تگ مشتری |
| `/api/tags.php` | CRUD تگ |
| `/api/users_search.php` | جستجوی کاربر |
| `/api/sync_receiver.php` | همگام‌سازی داده |

---

## ترجیحات و تصمیمات گرفته‌شده

- **زبان رابط:** فارسی (کامل)
- **تاریخ:** شمسی در همه جا
- **PDF:** با TCPDF (موجود در vendor)
- **SMS:** Kavenegar (موجود و تنظیم‌شده)
- **رویکرد توسعه:** تکمیل ماژول‌های موجود قبل از ساخت ماژول جدید
- **اولویت اول:** تکمیل CRM با استان‌ها و مطالبات (از Sales-Portal)
- **اولویت دوم:** حسابداری کامل با منطق hesabix
- **استراتژی یکپارچه‌سازی:** منطق hesabix به PHP procedural ترجمه می‌شود، UI Sales-Portal به PHP تبدیل می‌شود

---

## تاریخچه تغییرات این فایل

| تاریخ | تغییر |
|-------|-------|
| ۱۴۰۵/۰۳/۱۲ | ایجاد اولیه — ایندکس کامل مخزن و تعریف نقشه راه |
| ۱۴۰۵/۰۳/۱۲ | به‌روزرسانی — ایندکس Sales-Portal و hesabix-new، تعریف نقشه یکپارچه‌سازی |
