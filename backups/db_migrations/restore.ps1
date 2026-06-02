param(
    [string]$DbHost = "127.0.0.1",
    [string]$DbName,
    [string]$DbUser,
    [string]$DbPass = "",
    [string]$InputDir,
    [string]$EncPass = ""
)

if (-not $DbName -or -not $DbUser -or -not $InputDir) {
    Write-Host "استفاده: restore.ps1 -DbHost 127.0.0.1 -DbName DB_NAME -DbUser DB_USER -InputDir backups\\output\\YYYYMMDD_HHMM [-DbPass PASS]"
    exit 1
}

if (-not (Test-Path $InputDir)) {
    Write-Host "مسیر ورودی معتبر نیست."
    exit 1
}

$root = Split-Path -Parent $PSScriptRoot
$plainPass = $EncPass
if (-not $plainPass) {
    $pass = Read-Host "رمز پشتیبان (برای بازیابی)" -AsSecureString
    $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($pass)
    $plainPass = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
}

function Decrypt-File($inFile, $outFile) {
    & openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in $inFile -out $outFile -pass pass:$plainPass
}

# 1) دیتابیس
$dbEnc = Join-Path $InputDir "db.sql.enc"
$dbDump = Join-Path $InputDir "db.sql"
Decrypt-File $dbEnc $dbDump
if ($DbPass -ne "") { $env:MYSQL_PWD = $DbPass }
& mysql --host=$DbHost --user=$DbUser $DbName < $dbDump
if ($DbPass -ne "") { Remove-Item Env:MYSQL_PWD }
Remove-Item $dbDump -Force

# 2) کد
$codeEnc = Join-Path $InputDir "code.tar.gz.enc"
$codeArchive = Join-Path $InputDir "code.tar.gz"
Decrypt-File $codeEnc $codeArchive
Push-Location $root
& tar -xzf $codeArchive
Pop-Location
Remove-Item $codeArchive -Force

# 3) فایل‌های بارگذاری‌شده
$uploadsEnc = Join-Path $InputDir "uploads.tar.gz.enc"
$uploadsArchive = Join-Path $InputDir "uploads.tar.gz"
Decrypt-File $uploadsEnc $uploadsArchive
Push-Location $root
& tar -xzf $uploadsArchive
Pop-Location
Remove-Item $uploadsArchive -Force

Write-Host "بازیابی کامل شد."
