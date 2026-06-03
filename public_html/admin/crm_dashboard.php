<?php
/*
 * فایل: public_html/admin/crm_dashboard.php
 * ماژول CRM — کارتابل فروش (در دست توسعه)
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$basePath = '../../';
$pageTitle = 'کارتابل فروش';
include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>
<style>
body{font-family:Vazirmatn,Tahoma,sans-serif;direction:rtl;}
.coming-soon-wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;gap:1.5rem;}
.coming-soon-icon{font-size:4rem;}
.coming-soon-title{font-size:1.6rem;font-weight:700;color:#374151;}
.coming-soon-desc{color:#6b7280;font-size:1rem;}
</style>
<div id="mainContent" class="main-content">
  <div class="coming-soon-wrap">
    <div class="coming-soon-icon">📊</div>
    <div class="coming-soon-title">کارتابل فروش</div>
    <div class="coming-soon-desc">این ماژول در دست توسعه است و به زودی اضافه می‌شود.</div>
    <a href="<?= $basePath ?>public_html/admin/erp_dashboard.php" class="fin-btn" style="background:#2563eb;color:#fff;padding:.6rem 1.5rem;border-radius:.5rem;text-decoration:none;">بازگشت به داشبورد</a>
  </div>
</div>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
