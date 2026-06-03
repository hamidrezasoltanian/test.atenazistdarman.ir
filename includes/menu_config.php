<?php
/*
 * فایل: includes/menu_config.php
 * لیست منوهای سایدبار (بروزرسانی شده با اضافه شدن ماژول برنامه‌ریز فروش CRM)
 */

return [
    [
        'title' => 'داشبورد',
        'link' => 'dashboard.php',
        'icon_color' => '#2563eb',
        'perm' => 'dashboard_view',
        'svg' => '<rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect>'
    ],
    [
        'title' => 'کاربران و نقش ها',
        'link' => '#',
        'icon_color' => '#4f46e5',
        'perm' => 'users_view',
        'svg' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'submenu' => [
            ['title' => 'لیست کاربران', 'link' => 'admin/users.php', 'perm' => 'users_list'],
            ['title' => 'مدیریت دپارتمان', 'link' => 'admin/departments.php', 'perm' => 'departments_manage'],
            ['title' => 'پروفایل کاربری', 'link' => 'admin/profile.php', 'perm' => 'users_profile'],
            ['title' => 'نقش جدید', 'link' => 'admin/roles.php', 'perm' => 'roles_create'],
        ]
    ],
    [
        'title' => 'اعلانات',
        'link' => '#',
        'icon_color' => '#f59e0b',
        'perm' => 'announcements_view',
        'svg' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>',
        'submenu' => [
            ['title' => 'لیست اعلانات', 'link' => 'admin/announcements.php', 'perm' => 'announcements_list'],
            ['title' => 'ارسال اعلان جدید', 'link' => 'admin/create_announcement.php', 'perm' => 'announcements_create'],
            ['title' => 'دسته بندی', 'link' => 'admin/announcement_categories.php', 'perm' => 'announcements_cat'],
        ]
    ],
    [
        'title' => 'مشتریان',
        'link' => '#',
        'icon_color' => '#10b981',
        'perm' => 'customers_view',
        'svg' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle>',
        'submenu' => [
            ['title' => 'لیست مشتریان', 'link' => 'admin/customers.php', 'perm' => 'customers_list'],
            ['title' => 'پروفایل مشتری', 'link' => 'admin/customer_profile.php', 'perm' => 'customers_view'],
        ]
    ],
    [
        'title' => 'برنامه‌ریز فروش',
        'link' => '#',
        'icon_color' => '#f97316',
        'perm' => 'crm_view',
        'svg' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>',
        'submenu' => [
            ['title' => 'کارتابل فروش', 'link' => 'admin/crm_dashboard.php', 'perm' => 'crm_dashboard'],
            ['title' => 'مدیریت کانبان', 'link' => 'admin/crm_kanban_manager.php', 'perm' => 'crm_opportunities'],
            ['title' => 'لیست مراکز', 'link' => 'admin/crm_opportunities_kanban.php', 'perm' => 'crm_opportunities'],
            ['title' => 'تقویم فروش', 'link' => 'admin/crm_calendar.php', 'perm' => 'crm_calendar'],
            ['title' => 'افراد', 'link' => 'admin/crm_contacts.php', 'perm' => 'crm_contacts'],
            ['title' => 'چک‌لیست روزانه', 'link' => 'admin/crm_daily_checklist.php', 'perm' => 'crm_checklist'],
            ['title' => 'شاخص‌های کلیدی (KPI)', 'link' => 'admin/crm_kpi.php', 'perm' => 'crm_kpi'],
            ['title' => 'مدیریت استان‌ها', 'link' => 'admin/crm_provinces.php', 'perm' => 'crm_view'],
            ['title' => '📋 برنامه هفتگی', 'link' => 'admin/crm_weekplan.php', 'perm' => 'crm_view'],
            ['title' => '💰 مطالبات', 'link' => 'admin/crm_receivables.php', 'perm' => 'crm_view'],
        ]
    ],
    [
        'title' => 'گفتگو',
        'link' => '#', 
        'icon_color' => '#ec4899',
        'perm' => 'chat_view',
        'svg' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
        'submenu' => [
            ['title' => 'پیام‌رسان', 'link' => 'admin/chat.php', 'perm' => 'chat_view', 'badge' => 'chat_unread'],
            ['title' => 'تنظیمات گفتگو', 'link' => 'admin/chat_settings.php', 'perm' => 'settings_general'],
        ]
    ],
    [
        'title' => 'پروژه ها',
        'link' => '#',
        'icon_color' => '#8b5cf6',
        'perm' => 'projects_view',
        'svg' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>'
    ],
    [
        'title' => 'وظایف ها',
        'link' => '#',
        'icon_color' => '#06b6d4',
        'perm' => 'tasks_view',
        'svg' => '<path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>'
    ],
    [
        'title' => 'دبیرخانه',
        'link' => '#',
        'icon_color' => '#0ea5e9',
        'perm' => 'letters_module_view',
        'svg' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline>',
        'submenu' => [
            ['title' => '📥 کارتابل دریافتی', 'link' => 'admin/cartable_inbox.php', 'perm' => 'letters_inbox', 'badge' => 'inbox'],
            ['title' => '⏳ در دست اقدام', 'link' => 'admin/letters_pending.php', 'perm' => 'letters_pending', 'badge' => 'pending'],
            ['title' => '📝 ایجاد نامه جدید', 'link' => 'admin/letter_create.php', 'perm' => 'letters_create'],
            ['title' => '📤 نامه‌های صادره', 'link' => 'admin/letters_outgoing.php', 'perm' => 'letters_outgoing'],
            ['title' => '📥 نامه‌های وارده', 'link' => 'admin/letters_incoming.php', 'perm' => 'letters_incoming'],
            ['title' => '🏢 نامه‌های داخلی', 'link' => 'admin/letters_internal.php', 'perm' => 'letters_internal'],
            ['title' => '💾 لیست پیش‌نویس‌ها', 'link' => 'admin/letters_drafts.php', 'perm' => 'letters_drafts'],
            ['title' => '✅ تایید شده‌ها (امضا)', 'link' => 'admin/letters_approved.php', 'perm' => 'letters_sign', 'badge' => 'approved'],
            ['title' => '🗄️ آرشیو نامه‌ها', 'link' => 'admin/letters_archive.php', 'perm' => 'letters_archive_view'],
            ['title' => '🗑️ حذف شده‌ها', 'link' => 'admin/letters_trash.php', 'perm' => 'letters_trash_view'],
            ['title' => '⚙️ تنظیمات و قالب‌ها', 'link' => 'admin/letters_settings.php', 'perm' => 'letters_settings'],
        ]
    ],
    [
        'title' => 'یادداشت های من',
        'link' => 'admin/notes.php',
        'icon_color' => '#fbbf24',
        'perm' => 'notes_view',
        'svg' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>'
    ],
    [
        'title' => 'محصولات و انبار',
        'link' => '#',
        'icon_color' => '#ef4444',
        'perm' => 'products_view',
        'svg' => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path>',
        'submenu' => [
            ['title' => 'لیست محصولات',       'link' => 'admin/products_list.php',  'perm' => 'products_list'],
            ['title' => '📦 داشبورد انبار',    'link' => 'admin/inv_dashboard.php',  'perm' => 'inv_view'],
            ['title' => '🏭 مدیریت انبارها',   'link' => 'admin/inv_storerooms.php', 'perm' => 'inv_storerooms'],
            ['title' => '📥 رسید و حواله',     'link' => 'admin/inv_receipts.php',   'perm' => 'inv_receipts'],
            ['title' => '📋 کاردکس کالا',      'link' => 'admin/inv_kardex.php',     'perm' => 'inv_kardex'],
        ]
    ],
    [
        'title' => 'کمپین ها',
        'link' => '#',
        'icon_color' => '#8b5cf6',
        'perm' => 'campaigns_view',
        'svg' => '<circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>',
        'submenu' => [
            ['title' => 'ایجاد تخفیف', 'link' => '#', 'perm' => 'campaigns_discount'],
            ['title' => 'لیست تخفیف ها', 'link' => '#', 'perm' => 'campaigns_list'],
            ['title' => 'مشتریان VIP', 'link' => '#', 'perm' => 'campaigns_vip'],
            ['title' => 'قوانین کمپین', 'link' => '#', 'perm' => 'campaigns_rules'],
        ]
    ],
    [
        'title' => 'فاکتور فروش',
        'link' => '#',
        'icon_color' => '#10b981',
        'perm' => 'invoices_view',
        'svg' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'submenu' => [
            ['title' => 'جدید', 'link' => '#', 'perm' => 'invoices_create'],
            ['title' => 'لیست فاکتور فروش', 'link' => '#', 'perm' => 'invoices_list'],
            ['title' => 'برگشت از فروش', 'link' => '#', 'perm' => 'invoices_return'],
        ]
    ],
    [
        'title' => 'حسابداری',
        'link' => '#',
        'icon_color' => '#059669',
        'perm' => 'accounting_view',
        'svg' => '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
        'submenu' => [
            ['title' => '📊 داشبورد حسابداری', 'link' => 'admin/fin_accounting_dashboard.php', 'perm' => 'accounting_dashboard'],
            ['title' => '🧾 فاکتور فروش',        'link' => 'admin/fin_invoice_sell.php',       'perm' => 'invoices_sell'],
            ['title' => '👥 طرف حساب‌ها',          'link' => 'admin/fin_persons.php',            'perm' => 'fin_persons'],
            ['title' => '📒 پلان حساب‌ها',          'link' => 'admin/fin_accounts.php',           'perm' => 'fin_chart'],
            ['title' => '📄 مدیریت چک‌ها',          'link' => 'admin/fin_cheques.php',            'perm' => 'fin_cheques'],
        ]
    ],
    [
        'title' => 'تنخواه گردان',
        'link' => '#',
        'icon_color' => '#0891b2',
        'perm' => 'fin_module_view',
        'svg' => '<path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"></path><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"></path><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"></path>',
        'submenu' => [
            ['title' => 'داشبورد تنخواه', 'link' => 'admin/fin_dashboard.php', 'perm' => 'fin_dashboard'],
            ['title' => 'تعریف حساب‌ها', 'link' => 'admin/fin_accounts.php', 'perm' => 'fin_accounts'],
            ['title' => 'درخواست شارژ', 'link' => 'admin/fin_charge_requests.php', 'perm' => 'fin_charge_req'],
            ['title' => 'ثبت هزینه', 'link' => 'admin/fin_expenses.php', 'perm' => 'fin_expenses'],
            ['title' => 'ارسال صورت تنخواه', 'link' => 'admin/fin_expense_reports.php', 'perm' => 'fin_expense_reports'],
            ['title' => 'ممیزی صورت تنخواه‌ها', 'link' => 'admin/fin_report_review.php', 'perm' => 'fin_report_review'],
            ['title' => 'دفتر کل تراکنش‌ها', 'link' => 'admin/fin_transactions.php', 'perm' => 'fin_transactions'],
            ['title' => 'سرفصل‌های هزینه', 'link' => 'admin/fin_categories.php', 'perm' => 'fin_categories'],
        ]
    ],
    [
        'title' => 'مرخصی',
        'link' => '#',
        'icon_color' => '#f59e0b',
        'perm' => 'leaves_view',
        'svg' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
        'submenu' => [
            ['title' => 'لیست مرخصی', 'link' => 'admin/leave_requests.php', 'perm' => 'leaves_list'],
            ['title' => 'تایید مدیریت', 'link' => 'admin/leave_manage.php', 'perm' => 'leaves_admin'],
        ]
    ],
    [
        'title' => 'مأموریت',
        'link' => '#',
        'icon_color' => '#3b82f6',
        'perm' => 'missions_view',
        'svg' => '<rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle>',
        'submenu' => [
            ['title' => 'کارتابل مأموریت', 'link' => 'admin/missions.php', 'perm' => 'missions_list'],
            ['title' => 'ثبت مأموریت جدید', 'link' => 'admin/mission_create.php', 'perm' => 'missions_create'],
            ['title' => 'نوع مأموریت', 'link' => 'admin/mission_types.php', 'perm' => 'missions_types_manage'],
            ['title' => 'کدهای تخفیف', 'link' => 'admin/discount_codes.php', 'perm' => 'discount_codes_manage'],
        ]
    ],
    [
        'title' => 'ورود/خروج',
        'link' => '#',
        'icon_color' => '#6366f1',
        'perm' => 'attendance_view',
        'svg' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line>',
        'submenu' => [
            ['title' => 'لیست ورود/خروج', 'link' => 'admin/attendance_requests.php', 'perm' => 'attendance_list'],
            ['title' => 'تایید مدیریت', 'link' => 'admin/attendance_manage.php', 'perm' => 'attendance_admin'],
        ]
    ],
    [
        'title' => 'گزارشات',
        'link' => '#',
        'icon_color' => '#4b5563',
        'perm' => 'reports_view',
        'svg' => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
        'submenu' => [
            ['title' => '💰 گزارش‌های مالی',      'link' => 'admin/fin_reports.php',        'perm' => 'rep_financial'],
            ['title' => '📊 تحلیل CRM',            'link' => 'admin/crm_reports.php',        'perm' => 'rep_crm'],
            ['title' => 'گزارش کاربران',            'link' => 'admin/users_reports.php',      'perm' => 'rep_users'],
            ['title' => 'گزارش مشتریان',            'link' => 'admin/customer_reports.php',   'perm' => 'rep_customers'],
            ['title' => 'گزارش مرخصی',              'link' => 'admin/leave_reports.php',       'perm' => 'rep_leaves'],
            ['title' => 'گزارش ماموریت‌ها',          'link' => 'admin/mission_reports.php',    'perm' => 'rep_missions'],
            ['title' => 'گزارش ریز ماموریت‌ها',      'link' => 'admin/mission_detailed_reports.php', 'perm' => 'rep_missions'],
            ['title' => 'گزارش ورود/خروج',           'link' => 'admin/attendance_reports.php', 'perm' => 'rep_attendance'],
        ]
    ],
    [
        'title' => 'تنظیمات',
        'link' => '#',
        'icon_color' => '#374151',
        'perm' => 'settings_view',
        'svg' => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
        'submenu' => [
            ['title' => 'عمومی - دسترسی ها', 'link' => 'admin/settings.php', 'perm' => 'settings_general'],
            ['title' => 'سال مالی', 'link' => 'admin/fiscal_years.php', 'perm' => 'settings_fiscal'],
        ]
    ],
    [
        'title' => 'لاگ سیستم',
        'link' => 'admin/logs.php',
        'icon_color' => '#9ca3af',
        'perm' => 'logs_view',
        'svg' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>'
    ],
    [
        'title' => 'پشتیبان گیری',
        'link' => 'admin/backup.php',
        'icon_color' => '#dc2626',
        'perm' => 'backup_view',
        'svg' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline>'
    ],
    [
        'title' => '🏢 داشبورد ERP',
        'link' => 'admin/erp_dashboard.php',
        'icon_color' => '#667eea',
        'perm' => 'dashboard_view',
        'svg' => '<rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect>'
    ],
    [
        'title' => '🤖 ایجنت تست',
        'link' => 'admin/testing_agent.php',
        'icon_color' => '#7c3aed',
        'perm' => 'admin',
        'svg' => '<path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"></path>'
    ],
];
?>