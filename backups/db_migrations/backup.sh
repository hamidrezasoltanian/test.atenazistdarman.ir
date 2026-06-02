#!/usr/bin/env bash
set -euo pipefail

DB_HOST="127.0.0.1"
DB_NAME=""
DB_USER=""
DB_PASS=""
ENC_PASS=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --db-host) DB_HOST="$2"; shift 2;;
    --db-name) DB_NAME="$2"; shift 2;;
    --db-user) DB_USER="$2"; shift 2;;
    --db-pass) DB_PASS="$2"; shift 2;;
    --enc-pass) ENC_PASS="$2"; shift 2;;
    *) shift;;
  esac
done

if [[ -z "$DB_NAME" || -z "$DB_USER" || -z "$ENC_PASS" ]]; then
  echo "usage: backup.sh --db-host 127.0.0.1 --db-name DB_NAME --db-user DB_USER --db-pass PASS --enc-pass BACKUP_PASS"
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT_DIR/backups/output/$(date +%Y%m%d_%H%M)"
mkdir -p "$OUT_DIR"

encrypt_file() {
  local in_file="$1"
  local out_file="$2"
  openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -in "$in_file" -out "$out_file" -pass pass:"$ENC_PASS"
  rm -f "$in_file"
}

# 1) دیتابیس
DB_DUMP="$OUT_DIR/db.sql"
MYSQL_PWD="$DB_PASS" mysqldump --host="$DB_HOST" --user="$DB_USER" --databases "$DB_NAME" --single-transaction --routines --triggers > "$DB_DUMP"
encrypt_file "$DB_DUMP" "$DB_DUMP.enc"

# 2) کد (بدون uploads و خروجی بکاپ‌ها)
CODE_ARCHIVE="$OUT_DIR/code.tar.gz"
tar -czf "$CODE_ARCHIVE" \
  --exclude "backups/output" \
  --exclude "uploads" \
  --exclude "logs" \
  -C "$ROOT_DIR" .
encrypt_file "$CODE_ARCHIVE" "$CODE_ARCHIVE.enc"

# 3) فایل‌های بارگذاری‌شده
UPLOADS_ARCHIVE="$OUT_DIR/uploads.tar.gz"
tar -czf "$UPLOADS_ARCHIVE" --exclude "backups/output" -C "$ROOT_DIR" uploads
encrypt_file "$UPLOADS_ARCHIVE" "$UPLOADS_ARCHIVE.enc"

echo "backup completed: $OUT_DIR"
