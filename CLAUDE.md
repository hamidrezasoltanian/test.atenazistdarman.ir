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

### 🟠 مرحله ۲ — حسابداری کامل (از hesabix) ← مرحله بعدی

> **منبع:** منطق از hesabix ترجمه می‌شود، PHP procedural، UI با سیستم موجود یکپارچه
> **اصل طراحی:** هر فاکتور/تراکنش = یک سند حسابداری (fin_docs) + ردیف‌های دوطرفه (fin_doc_rows)

---

#### ۲.۰ زیرساخت — جداول پایه (Migration اول)

**فایل:** `db_migrations/phase2_accounting.sql`

```sql
-- طرف حساب‌ها (مشتری، تامین‌کننده، کارمند)
fin_persons: id, code, name, company_name, type ENUM(customer,supplier,both),
             national_id, economic_code, tel, mobile, address, state, city,
             opening_balance DECIMAL(20,0) DEFAULT 0,
             created_at, updated_at, is_deleted TINYINT DEFAULT 0

-- پلان حساب‌ها (سرفصل‌ها)
fin_accounts: id, code VARCHAR(20) UNIQUE, name, type ENUM(asset,liability,equity,revenue,expense),
              parent_id INT DEFAULT NULL,  ← کد بالادست
              is_system TINYINT DEFAULT 0, ← حذف‌نشدنی
              created_at, updated_at

-- اسناد حسابداری (هر فاکتور/تراکنش یک doc است)
fin_docs: id, doc_number VARCHAR(30), doc_date DATE,
          type ENUM(sell,buy,receive,pay,cheque_receive,cheque_pay,transfer,manual),
          fiscal_year_id INT, user_id INT,
          description TEXT, total_amount DECIMAL(20,0),
          status ENUM(draft,confirmed,cancelled) DEFAULT 'draft',
          ref_type VARCHAR(50) DEFAULT NULL,   ← 'invoice','mission',...
          ref_id INT DEFAULT NULL,             ← id رکورد مرجع
          created_at, updated_at, is_deleted TINYINT DEFAULT 0

-- ردیف‌های سند (بدهکار/بستانکار)
fin_doc_rows: id, doc_id INT, account_id INT,
              debit DECIMAL(20,0) DEFAULT 0,   ← بدهکار
              credit DECIMAL(20,0) DEFAULT 0,  ← بستانکار
              person_id INT DEFAULT NULL,
              commodity_id INT DEFAULT NULL,   ← ارتباط با کالا
              quantity DECIMAL(12,4) DEFAULT NULL,
              unit_price DECIMAL(20,0) DEFAULT NULL,
              description VARCHAR(255) DEFAULT NULL,
              row_order INT DEFAULT 0

-- فاکتورها (جدول خلاصه برای UI — doc_id لینک اصلی است)
fin_invoices: id, invoice_number VARCHAR(30) UNIQUE,
              invoice_date DATE, due_date DATE,
              type ENUM(sell,buy) DEFAULT 'sell',
              person_id INT,   ← طرف حساب
              doc_id INT,      ← سند حسابداری مرتبط
              opportunity_id INT DEFAULT NULL, ← از CRM
              fiscal_year_id INT, user_id INT,
              subtotal DECIMAL(20,0), discount DECIMAL(20,0) DEFAULT 0,
              tax DECIMAL(20,0) DEFAULT 0,
              total_amount DECIMAL(20,0),
              paid_amount DECIMAL(20,0) DEFAULT 0,
              notes TEXT DEFAULT NULL,
              created_at, updated_at, is_deleted TINYINT DEFAULT 0

-- ردیف‌های فاکتور
fin_invoice_items: id, invoice_id INT, commodity_id INT,
                   quantity DECIMAL(12,4), unit_price DECIMAL(20,0),
                   discount DECIMAL(20,0) DEFAULT 0,
                   tax DECIMAL(20,0) DEFAULT 0,
                   total DECIMAL(20,0),
                   description VARCHAR(255) DEFAULT NULL,
                   row_order INT DEFAULT 0

-- حساب‌های بانکی
fin_bank_accounts: id, bank_name, account_number, sheba_number,
                   account_owner, balance DECIMAL(20,0) DEFAULT 0,
                   is_active TINYINT DEFAULT 1, created_at

-- صندوق نقدی
fin_cashdesks: id, name, balance DECIMAL(20,0) DEFAULT 0,
               user_id INT DEFAULT NULL, is_active TINYINT DEFAULT 1, created_at

-- چک‌ها
fin_cheques: id, type ENUM(received,issued), cheque_number, bank_name,
             amount DECIMAL(20,0), person_id INT,
             issue_date DATE, due_date DATE,
             status ENUM(pending,cleared,bounced,transferred) DEFAULT 'pending',
             doc_id INT DEFAULT NULL,
             bank_account_id INT DEFAULT NULL,
             description TEXT DEFAULT NULL,
             created_at, updated_at, is_deleted TINYINT DEFAULT 0
```

**Seed داده پلان حساب‌ها (پیش‌فرض ایران):**
```
1000 دارایی‌ها          (asset)
  1100 دارایی‌های جاری   (asset)
    1101 صندوق           (asset, is_system)
    1102 بانک            (asset, is_system)
    1103 حساب دریافتنی   (asset, is_system)
    1104 موجودی کالا      (asset, is_system)
2000 بدهی‌ها            (liability)
  2100 بدهی‌های جاری    (liability)
    2101 حساب پرداختنی   (liability, is_system)
    2102 چک پرداختنی     (liability, is_system)
3000 حقوق صاحبان سهام  (equity)
4000 درآمدها            (revenue)
  4001 درآمد فروش       (revenue, is_system)
5000 هزینه‌ها            (expense)
  5001 بهای تمام شده    (expense, is_system)
```

---

#### ۲.۱ مدیریت طرف حساب‌ها

**فایل:** `fin_persons.php`

- لیست مشتریان/تامین‌کنندگان با جستجو و فیلتر
- فرم افزودن/ویرایش (ادغام با جدول `customers` موجود)
- کارت حساب هر طرف (مانده بدهکار/بستانکار)
- **ارتباط با CRM:** هر `customer` یک `fin_person` دارد (یا auto-link)

---

#### ۲.۲ فاکتور فروش ← مهم‌ترین بخش

**فایل:** `fin_invoice_sell.php`
**AJAX:** `admin/ajax/fin_invoice_ajax.php`

**منطق ایجاد فاکتور (از SellController hesabix):**
```
کاربر → فرم فاکتور → ثبت:
1. INSERT fin_invoices (هدر)
2. INSERT fin_invoice_items (ردیف‌ها)
3. INSERT fin_docs (type='sell', ref_type='invoice', ref_id=invoice.id)
4. INSERT fin_doc_rows:
   بدهکار  → حساب دریافتنی (1103)    به مبلغ کل
   بستانکار → درآمد فروش (4001)      به مبلغ خالص
   بستانکار → مالیات پرداختنی       به مبلغ مالیات
5. اگر opportunity_id داشت → update status فرصت CRM
6. log فعالیت
```

**قابلیت‌ها:**
- [ ] جستجوی مشتری (از `fin_persons` یا `customers`)
- [ ] جستجوی کالا (از `stuffs` موجود)
- [ ] افزودن ردیف‌های متعدد (dynamic rows با JS)
- [ ] محاسبه خودکار تخفیف، مالیات ۹٪، جمع کل
- [ ] پیوند به فرصت CRM (ساخت فاکتور از کانبان)
- [ ] **چاپ PDF** با TCPDF (از PrintersController hesabix)
- [ ] ویرایش فاکتور (فقط قبل از تسویه)
- [ ] لیست فاکتورها با فیلتر تاریخ/مشتری/وضعیت

---

#### ۲.۳ فاکتور خرید

**فایل:** `fin_invoice_buy.php`

مشابه فروش، ردیف‌های حسابداری معکوس:
```
بدهکار  → موجودی کالا (1104) یا هزینه
بستانکار → حساب پرداختنی (2101)
```

---

#### ۲.۴ دریافت و پرداخت

**فایل:** `fin_receive_pay.php`

**دریافت از مشتری:**
```
بدهکار  → صندوق/بانک
بستانکار → حساب دریافتنی (1103) — کسر از مانده فاکتور
→ UPDATE fin_invoices SET paid_amount = paid_amount + X
```

**پرداخت به تامین‌کننده:**
```
بدهکار  → حساب پرداختنی (2101)
بستانکار → صندوق/بانک
```

---

#### ۲.۵ مدیریت چک

**فایل:** `fin_cheques.php`

چرخه چک (از ChequeController hesabix):
```
دریافت چک   → status=pending → در جریان وصول
وصول چک      → status=cleared → بستانکار بانک
برگشت چک     → status=bounced → سند برگشتی
انتقال/ظهرنویسی → status=transferred
```

- [ ] تقویم سررسید چک (نمایش ماهانه)
- [ ] هشدار ۳ روز قبل از سررسید

---

#### ۲.۶ گزارش‌های مالی

**فایل:** `fin_reports.php`

| گزارش | منطق |
|-------|------|
| دفتر کل | fin_doc_rows GROUP BY account_id |
| تراز آزمایشی | مانده بدهکار/بستانکار per حساب |
| سود و زیان | درآمدها (4xxx) - هزینه‌ها (5xxx) |
| ترازنامه | دارایی (1xxx) = بدهی (2xxx) + حقوق (3xxx) |
| کارت حساب | fin_doc_rows WHERE person_id = X |
| مانده فاکتورها | fin_invoices WHERE paid_amount < total_amount |

---

#### ترتیب پیاده‌سازی پیشنهادی

```
هفته ۱: Migration جداول + seed پلان حساب‌ها + fin_persons
هفته ۲: فاکتور فروش (فرم + AJAX + ذخیره + سند حسابداری)
هفته ۳: چاپ PDF فاکتور + لیست فاکتورها + لینک به CRM
هفته ۴: فاکتور خرید + دریافت/پرداخت
هفته ۵: مدیریت چک + گزارش‌های مالی پایه
```

---

### 🟡 مرحله ۳ — انبارداری کامل (از hesabix StoreroomController)

> **منبع:** StoreroomController + StoreroomTicket + StoreroomItem
> **اصل:** هر رسید/حواله = یک StoreroomTicket + ردیف‌های کالا + لینک به فاکتور

#### جداول
```sql
inv_storerooms: id, name, code, address, is_active
inv_tickets: id, ticket_number, date, type ENUM(receipt,dispatch,transfer),
             storeroom_id INT, from_storeroom_id INT DEFAULT NULL,
             person_id INT DEFAULT NULL, invoice_id INT DEFAULT NULL,
             doc_id INT DEFAULT NULL, user_id INT,
             description TEXT, status ENUM(draft,confirmed),
             created_at, is_deleted
inv_ticket_items: id, ticket_id, commodity_id, quantity DECIMAL(12,4),
                  unit_price DECIMAL(20,0), description
```

#### قابلیت‌ها
- [ ] تعریف انبارها
- [ ] رسید انبار (خرید → انبار) — auto از فاکتور خرید
- [ ] حواله انبار (فروش → کسر) — auto از فاکتور فروش
- [ ] انتقال بین انبارها
- [ ] کاردکس موجودی per کالا
- [ ] هشدار موجودی حداقل (reorder point از Commodity.orderPoint)
- [ ] ارزش‌گذاری موجودی (FIFO/میانگین)

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
