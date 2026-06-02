param(
    [string]$DbHost = "127.0.0.1",
    [string]$DbName,
    [string]$DbUser,
    [string]$DbPass = "",
    [string]$EncPass = ""
)

if (-not $DbName -or -not $DbUser) {
    Write-Host "استفاده: backup.ps1 -DbHost 127.0.0.1 -DbName DB_NAME -DbUser DB_USER [-DbPass PASS]"
    exit 1
}

$root = Split-Path -Parent $PSScriptRoot
$outDir = Join-Path $PSScriptRoot ("output\" + (Get-Date -Format "yyyyMMdd_HHmm"))
New-Item -ItemType Directory -Path $outDir -Force | Out-Null

$plainPass = $EncPass
if (-not $plainPass) {
    $pass = Read-Host "رمز پشتیبان (برای رمزگذاری)" -AsSecureString
    $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($pass)
    $plainPass = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
}

function Encrypt-File($inFile, $outFile) {
    & openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -in $inFile -out $outFile -pass pass:$plainPass
    if (Test-Path $inFile) { Remove-Item $inFile -Force }
}

# 1) دیتابیس
$dbDump = Join-Path $outDir "db.sql"
$dbArgs = @("--host=$DbHost", "--user=$DbUser", "--databases", $DbName, "--single-transaction", "--routines", "--triggers")
if ($DbPass -ne "") { $env:MYSQL_PWD = $DbPass }
& mysqldump @dbArgs | Out-File -Encoding utf8 $dbDump
if ($DbPass -ne "") { Remove-Item Env:MYSQL_PWD }
Encrypt-File $dbDump ($dbDump + ".enc")

# 2) کد (بدون uploads و خروجی بکاپ‌ها)
$codeArchive = Join-Path $outDir "code.tar.gz"
Push-Location $root
& tar -czf $codeArchive `
    --exclude "backups/output" `
    --exclude "uploads" `
    --exclude "logs" `
    .
Pop-Location
Encrypt-File $codeArchive ($codeArchive + ".enc")

# 3) فایل‌های بارگذاری‌شده
$uploadsArchive = Join-Path $outDir "uploads.tar.gz"
Push-Location $root
& tar -czf $uploadsArchive `
    --exclude "backups/output" `
    uploads
Pop-Location
Encrypt-File $uploadsArchive ($uploadsArchive + ".enc")

Write-Host "پشتیبان‌گیری کامل شد: $outDir"
