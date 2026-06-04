<?php
/*
 * فایل: includes/menu_config.php
 * منوی سایدبار — نسخه بازطراحی‌شده
 */

return [

    // ─── مرکز فرماندهی ───
    ['type' => 'separator', 'title' => 'داشبورد'],

    [
        'title' => 'داشبورد ERP',
        'link'  => 'admin/erp_dashboard.php',
        'icon_color' => '#2563eb',
        'perm'  => 'dashboard_view',
        'svg'   => '<rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect>'
    ],
    [
        'title' => 'داشبورد',
        'link'  => 'dashboard.php',
        'icon_color' => '#0ea5e9',
        'perm'  => 'dashboard_view',
        'svg'   => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline>'
    ],

    // ─── CRM و فروش ───
    ['type' => 'separator', 'title' => 'CRM و فروش'],

    [
        'title' => 'برنامه‌ریز فروش',
        'link'  => '#',
        'icon_color' => '#f97316',
        'perm'  => 'crm_view',
        'svg'   => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>',
        'submenu' => [
            ['title' => 'کارتابل فروش',         'link' => 'admin/crm_dashboard.php',           'perm' => 'crm_dashboard'],
            ['title' => 'بورد کانبان',            'link' => 'admin/crm_opportunities_kanban.php','perm' => 'crm_opportunities'],
            ['title' => 'مدیریت کانبان',          'link' => 'admin/crm_kanban_manager.php',      'perm' => 'crm_opportunities'],
            ['title' => 'تقویم فروش',             'link' => 'admin/crm_calendar.php',            'perm' => 'crm_calendar'],
            ['title' => 'افراد / مخاطبین',         'link' => 'admin/crm_contacts.php',            'perm' => 'crm_contacts'],
            ['title' => 'برنامه هفتگی',            'link' => 'admin/crm_weekplan.php',            'perm' => 'crm_view'],
            ['title' => 'مطالبات',                 'link' => 'admin/crm_receivables.php',         'perm' => 'crm_view'],
            ['title' => 'هدف‌گذاری فروش',           'link' => 'admin/crm_targets.php',             'perm' => 'crm_view'],
            ['title' => 'پیگیری خودکار مشتریان',    'link' => 'admin/crm_followup.php',            'perm' => 'crm_view'],
            ['title' => 'چک‌لیست روزانه',          'link' => 'admin/crm_daily_checklist.php',     'perm' => 'crm_checklist'],
            ['title' => 'مدیریت استان‌ها',          'link' => 'admin/crm_provinces.php',           'perm' => 'crm_view'],
            ['title' => 'گزارش‌های CRM',            'link' => 'admin/crm_reports.php',             'perm' => 'crm_kpi'],
        ]
    ],
    [
        'title' => 'مشتریان',
        'link'  => '#',
        'icon_color' => '#10b981',
        'perm'  => 'customers_view',
        'svg'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'submenu' => [
            ['title' => 'لیست مشتریان',  'link' => 'admin/customers.php',        'perm' => 'customers_list'],
            ['title' => 'پروفایل مشتری', 'link' => 'admin/customer_profile.php', 'perm' => 'customers_view'],
            ['title' => 'نمایندگان/توزیع‌کنندگان','link' => 'admin/dealers.php',  'perm' => 'crm_view'],
        ]
    ],

    // ─── تنخواه‌گردان ───
    ['type' => 'separator', 'title' => 'تنخواه‌گردان'],

    [
        'title' => 'تنخواه‌گردان',
        'link'  => 'admin/fin_petty_cash.php',
        'icon_color' => '#0891b2',
        'perm'  => 'fin_petty_cash',
        'svg'   => '<path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"></path><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"></path><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"></path>'
    ],

    // ─── مالی و حسابداری ───
    ['type' => 'separator', 'title' => 'مالی و حسابداری'],

    [
        'title' => 'حسابداری',
        'link'  => '#',
        'icon_color' => '#059669',
        'perm'  => 'accounting_view',
        'svg'   => '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
        'submenu' => [
            ['title' => 'داشبورد حسابداری', 'link' => 'admin/fin_accounting_dashboard.php', 'perm' => 'accounting_dashboard'],
            ['title' => 'فاکتور فروش',       'link' => 'admin/fin_invoice_sell.php',        'perm' => 'invoices_sell'],
            ['title' => 'پیش‌فاکتور',         'link' => 'admin/fin_preinvoice.php',          'perm' => 'invoices_sell'],
            ['title' => 'آفر / قیمت‌نامه',    'link' => 'admin/fin_quote.php',               'perm' => 'invoices_sell'],
            ['title' => 'فاکتور خرید',        'link' => 'admin/fin_invoice_buy.php',         'perm' => 'invoices_buy'],
            ['title' => 'دریافت و پرداخت',   'link' => 'admin/fin_receive_pay.php',          'perm' => 'fin_receive_pay'],
            ['title' => 'تنخواه‌گردان',        'link' => 'admin/fin_petty_cash.php',          'perm' => 'fin_petty_cash'],
            ['title' => 'طرف حساب‌ها',        'link' => 'admin/fin_persons.php',             'perm' => 'fin_persons'],
            ['title' => 'پلان حساب‌ها',        'link' => 'admin/fin_accounts.php',            'perm' => 'fin_chart'],
            ['title' => 'قراردادها',           'link' => 'admin/contracts.php',               'perm' => 'fin_persons'],
            ['title' => 'مدیریت چک‌ها',        'link' => 'admin/fin_cheques.php',             'perm' => 'fin_cheques'],
            ['title' => 'یادآور سررسید چک',   'link' => 'admin/fin_cheque_reminders.php',    'perm' => 'fin_cheques'],
            ['title' => 'سامانه مالیاتی',      'link' => 'admin/fin_tax_integration.php',     'perm' => 'accounting_view'],
        ]
    ],
    [
        'title' => 'گزارش‌های مالی',
        'link'  => '#',
        'icon_color' => '#7c3aed',
        'perm'  => 'reports_view',
        'svg'   => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
        'submenu' => [
            ['title' => 'گزارش‌های مالی',  'link' => 'admin/fin_reports.php',    'perm' => 'rep_financial'],
            ['title' => 'سال مالی',         'link' => 'admin/fiscal_years.php',   'perm' => 'settings_fiscal'],
            ['title' => 'اعلانات SMS خودکار','link' => 'admin/sms_automation.php','perm' => 'settings_general'],
        ]
    ],
    [
        'title' => 'تنخواه (بیس)',
        'link'  => '#',
        'icon_color' => '#0891b2',
        'perm'  => 'fin_module_view',
        'svg'   => '<path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"></path><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"></path><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"></path>',
        'submenu' => [
            ['title' => 'داشبورد تنخواه',   'link' => 'admin/fin_dashboard.php',        'perm' => 'fin_dashboard'],
            ['title' => 'درخواست شارژ',      'link' => 'admin/fin_charge_requests.php',  'perm' => 'fin_charge_req'],
            ['title' => 'ثبت هزینه',         'link' => 'admin/fin_expenses.php',         'perm' => 'fin_expenses'],
            ['title' => 'صورت تنخواه',       'link' => 'admin/fin_expense_reports.php',  'perm' => 'fin_expense_reports'],
            ['title' => 'ممیزی تنخواه',      'link' => 'admin/fin_report_review.php',    'perm' => 'fin_report_review'],
            ['title' => 'دفتر تراکنش‌ها',    'link' => 'admin/fin_transactions.php',     'perm' => 'fin_transactions'],
            ['title' => 'سرفصل هزینه',       'link' => 'admin/fin_categories.php',       'perm' => 'fin_categories'],
        ]
    ],

    // ─── انبار ───
    ['type' => 'separator', 'title' => 'انبار'],

    [
        'title' => 'انبارداری',
        'link'  => '#',
        'icon_color' => '#ef4444',
        'perm'  => 'products_view',
        'svg'   => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path>',
        'submenu' => [
            ['title' => 'لیست محصولات',   'link' => 'admin/products_list.php',  'perm' => 'products_list'],
            ['title' => 'داشبورد انبار',   'link' => 'admin/inv_dashboard.php',  'perm' => 'inv_view'],
            ['title' => 'مدیریت انبارها',  'link' => 'admin/inv_storerooms.php', 'perm' => 'inv_storerooms'],
            ['title' => 'انبار پیشرفته WMS','link' => 'admin/inv_wms.php',       'perm' => 'inv_view'],
            ['title' => 'رسید و حواله',    'link' => 'admin/inv_receipts.php',   'perm' => 'inv_receipts'],
            ['title' => 'کاردکس کالا',     'link' => 'admin/inv_kardex.php',     'perm' => 'inv_kardex'],
        ]
    ],

    // ─── منابع انسانی ───
    ['type' => 'separator', 'title' => 'منابع انسانی'],

    [
        'title' => 'حقوق و دستمزد',
        'link'  => '#',
        'icon_color' => '#7c3aed',
        'perm'  => 'hr_payroll',
        'svg'   => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>',
        'submenu' => [
            ['title' => 'حقوق و دستمزد', 'link' => 'admin/hr_payroll.php', 'perm' => 'hr_payroll'],
        ]
    ],
    [
        'title' => 'مرخصی',
        'link'  => '#',
        'icon_color' => '#f59e0b',
        'perm'  => 'leaves_view',
        'svg'   => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
        'submenu' => [
            ['title' => 'لیست مرخصی',   'link' => 'admin/leave_requests.php', 'perm' => 'leaves_list'],
            ['title' => 'تایید مدیریت',  'link' => 'admin/leave_manage.php',   'perm' => 'leaves_admin'],
        ]
    ],
    [
        'title' => 'مأموریت',
        'link'  => '#',
        'icon_color' => '#3b82f6',
        'perm'  => 'missions_view',
        'svg'   => '<rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle>',
        'submenu' => [
            ['title' => 'کارتابل مأموریت',  'link' => 'admin/missions.php',        'perm' => 'missions_list'],
            ['title' => 'مأموریت جدید',      'link' => 'admin/mission_create.php',  'perm' => 'missions_create'],
            ['title' => 'نوع مأموریت',       'link' => 'admin/mission_types.php',   'perm' => 'missions_types_manage'],
            ['title' => 'کدهای تخفیف',       'link' => 'admin/discount_codes.php',  'perm' => 'discount_codes_manage'],
        ]
    ],
    [
        'title' => 'ورود / خروج',
        'link'  => '#',
        'icon_color' => '#6366f1',
        'perm'  => 'attendance_view',
        'svg'   => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line>',
        'submenu' => [
            ['title' => 'لیست ورود/خروج', 'link' => 'admin/attendance_requests.php', 'perm' => 'attendance_list'],
            ['title' => 'تایید مدیریت',    'link' => 'admin/attendance_manage.php',   'perm' => 'attendance_admin'],
        ]
    ],
    [
        'title' => 'پشتیبانی پس از فروش',
        'link'  => '#',
        'icon_color' => '#0891b2',
        'perm'  => 'crm_view',
        'svg'   => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13 19.79 19.79 0 0 1 1.61 4.38 2 2 0 0 1 3.6 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>',
        'submenu' => [
            ['title' => 'تیکت‌های پشتیبانی', 'link' => 'admin/support_tickets.php', 'perm' => 'crm_view'],
            ['title' => 'مدیریت گارانتی',     'link' => 'admin/warranty.php',         'perm' => 'crm_view'],
        ]
    ],

    // ─── ارتباطات ───
    ['type' => 'separator', 'title' => 'ارتباطات'],

    [
        'title' => 'گفتگو',
        'link'  => '#',
        'icon_color' => '#ec4899',
        'perm'  => 'chat_view',
        'svg'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
        'submenu' => [
            ['title' => 'پیام‌رسان',       'link' => 'admin/chat.php',          'perm' => 'chat_view', 'badge' => 'chat_unread'],
            ['title' => 'تنظیمات گفتگو',   'link' => 'admin/chat_settings.php', 'perm' => 'settings_general'],
        ]
    ],
    [
        'title' => 'دبیرخانه',
        'link'  => '#',
        'icon_color' => '#0ea5e9',
        'perm'  => 'letters_module_view',
        'svg'   => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline>',
        'submenu' => [
            ['title' => 'کارتابل من',       'link' => 'admin/cartable_inbox.php', 'perm' => 'letters_inbox', 'badge' => 'inbox'],
            ['title' => 'نامه‌ها',           'link' => 'admin/letters.php',        'perm' => 'letters_module_view'],
            ['title' => 'ایجاد نامه',        'link' => 'admin/letter_create.php',  'perm' => 'letters_create'],
            ['title' => 'امضا / تایید',      'link' => 'admin/letters_approved.php','perm' => 'letters_sign', 'badge' => 'approved'],
            ['title' => 'تنظیمات',           'link' => 'admin/letters_settings.php','perm' => 'letters_settings'],
        ]
    ],
    [
        'title' => 'اعلانات',
        'link'  => '#',
        'icon_color' => '#f59e0b',
        'perm'  => 'announcements_view',
        'svg'   => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>',
        'submenu' => [
            ['title' => 'لیست اعلانات',   'link' => 'admin/announcements.php',          'perm' => 'announcements_list'],
            ['title' => 'ارسال اعلان',     'link' => 'admin/create_announcement.php',    'perm' => 'announcements_create'],
            ['title' => 'دسته‌بندی',        'link' => 'admin/announcement_categories.php','perm' => 'announcements_cat'],
        ]
    ],
    [
        'title' => 'یادداشت‌های من',
        'link'  => 'admin/notes.php',
        'icon_color' => '#fbbf24',
        'perm'  => 'notes_view',
        'svg'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>'
    ],

    // ─── سیستم ───
    ['type' => 'separator', 'title' => 'سیستم'],

    [
        'title' => 'کاربران و نقش‌ها',
        'link'  => '#',
        'icon_color' => '#4f46e5',
        'perm'  => 'users_view',
        'svg'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'submenu' => [
            ['title' => 'لیست کاربران',    'link' => 'admin/users.php',       'perm' => 'users_list'],
            ['title' => 'مدیریت دپارتمان', 'link' => 'admin/departments.php', 'perm' => 'departments_manage'],
            ['title' => 'پروفایل کاربری',  'link' => 'admin/profile.php',     'perm' => 'users_profile'],
            ['title' => 'نقش‌ها',           'link' => 'admin/roles.php',       'perm' => 'roles_create'],
        ]
    ],
    [
        'title' => 'گزارشات',
        'link'  => '#',
        'icon_color' => '#4b5563',
        'perm'  => 'reports_view',
        'svg'   => '<path d="M21 15a2 2 0 0 1-2 2H5l-4 4V4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"></path>',
        'submenu' => [
            ['title' => 'گزارش کاربران',      'link' => 'admin/users_reports.php',            'perm' => 'rep_users'],
            ['title' => 'گزارش مشتریان',       'link' => 'admin/customer_reports.php',         'perm' => 'rep_customers'],
            ['title' => 'گزارش مرخصی',         'link' => 'admin/leave_reports.php',            'perm' => 'rep_leaves'],
            ['title' => 'گزارش مأموریت‌ها',    'link' => 'admin/mission_reports.php',          'perm' => 'rep_missions'],
            ['title' => 'ریز مأموریت‌ها',       'link' => 'admin/mission_detailed_reports.php', 'perm' => 'rep_missions'],
            ['title' => 'گزارش ورود/خروج',     'link' => 'admin/attendance_reports.php',       'perm' => 'rep_attendance'],
        ]
    ],
    [
        'title' => 'تنظیمات',
        'link'  => '#',
        'icon_color' => '#374151',
        'perm'  => 'settings_view',
        'svg'   => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
        'submenu' => [
            ['title' => 'تنظیمات عمومی', 'link' => 'admin/settings.php',      'perm' => 'settings_general'],
            ['title' => 'سال مالی',       'link' => 'admin/fiscal_years.php',  'perm' => 'settings_fiscal'],
        ]
    ],
    [
        'title' => 'لاگ سیستم',
        'link'  => 'admin/logs.php',
        'icon_color' => '#9ca3af',
        'perm'  => 'logs_view',
        'svg'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>'
    ],
    [
        'title' => 'پشتیبان‌گیری',
        'link'  => 'admin/backup.php',
        'icon_color' => '#dc2626',
        'perm'  => 'backup_view',
        'svg'   => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline>'
    ],
    [
        'title' => 'ایجنت تست',
        'link'  => 'admin/testing_agent.php',
        'icon_color' => '#7c3aed',
        'perm'  => 'admin',
        'svg'   => '<path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"></path>'
    ],
];
