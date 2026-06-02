# راهنمای پشتیبان‌گیری و بازیابی

این پوشه شامل اسکریپت‌های پشتیبان‌گیری جداگانه از کد، فایل‌های بارگذاری‌شده و دیتابیس است. بازیابی با رمز عبور انجام می‌شود.

**پیش‌نیازها**
1. `mysqldump` و `mysql` در PATH سیستم
2. `openssl` در PATH سیستم

## پشتیبان‌گیری (ویندوز)
1. اجرای اسکریپت:
```powershell
.\backups\backup.ps1 -DbHost "127.0.0.1" -DbName "DB_NAME" -DbUser "DB_USER"
```
2. رمز را وارد کنید (برای رمزگذاری همه فایل‌ها).
3. خروجی در مسیر `backups\output\YYYYMMDD_HHMM` ذخیره می‌شود:
   - `code.tar.gz.enc` (کد)
   - `uploads.tar.gz.enc` (فایل‌های بارگذاری‌شده)
   - `db.sql.enc` (دیتابیس)

## بازیابی (ویندوز)
1. اجرای اسکریپت:
```powershell
.\backups\restore.ps1 -DbHost "127.0.0.1" -DbName "DB_NAME" -DbUser "DB_USER" -InputDir "backups\output\YYYYMMDD_HHMM"
```
2. رمز را وارد کنید.
3. دیتابیس، کد و فایل‌ها بازیابی می‌شوند.

## زمان‌بندی خودکار روزانه (Task Scheduler)
1. اجرای اسکریپت زمان‌بندی:
```powershell
.\backups\schedule_daily_backup.ps1 -DbName "DB_NAME" -DbUser "DB_USER" -EncPass "BACKUP_PASS" -Time "02:00"
```
2. برای حذف زمان‌بندی:
```powershell
Unregister-ScheduledTask -TaskName "AtenaZistDarmanDailyBackup" -Confirm:$false
```
**نکته امنیتی:** رمز بکاپ در تسک ذخیره می‌شود. اگر می‌خواهید ذخیره نشود، زمان‌بندی را به صورت دستی در Task Scheduler بسازید و رمز را هنگام اجرا وارد کنید.

## نکات مهم امنیتی
1. پوشه `backups\output` را در دسترس عمومی وب قرار ندهید.
2. رمز را در هیچ جایی ذخیره نکنید و به صورت دوره‌ای تغییر دهید.
3. بعد از بازیابی، سطح دسترسی فایل‌ها را بررسی کنید.
4. از پشتیبان‌ها روی یک فضای امن جداگانه نگهداری کنید.
