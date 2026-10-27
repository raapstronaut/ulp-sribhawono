#!/bin/sh
# Runner cron Railway: sinkronisasi Gangguan, APKUK, lalu Keypoint.
# Ketiga command selalu dijalankan berurutan. Exit 0 hanya bila semuanya
# berhasil; exit 1 bila ada yang gagal (Railway menandai eksekusi Crashed).
# Baris terakhir SYNC_SUMMARY merangkum hasil tiap command beserta exit code-nya.
set -eu
umask 077

CREDENTIALS_FILE=/tmp/ulp-google-credentials.json

: "${GOOGLE_SHEETS_CREDENTIALS_BASE64:?Kredensial Google belum diatur}"
: "${DB_DATABASE:?Database belum diatur}"

if [ "$DB_DATABASE" != "ulp_sribhawono_shared_dev" ]; then
  echo 'SYNC_SUMMARY status=FAILED exit=1 reason="Database tidak sesuai. Sinkronisasi dibatalkan."' >&2
  exit 1
fi

# Dipasang sebelum file kredensial dibuat agar selalu dihapus, termasuk saat dekode gagal.
trap 'rm -f "$CREDENTIALS_FILE"' EXIT

if ! printf '%s' "$GOOGLE_SHEETS_CREDENTIALS_BASE64" | base64 -d > "$CREDENTIALS_FILE"; then
  echo 'SYNC_SUMMARY status=FAILED exit=1 reason="Kredensial Google tidak dapat didekode."' >&2
  exit 1
fi

export GOOGLE_SHEETS_CREDENTIALS_PATH="$CREDENTIALS_FILE"
unset GOOGLE_SHEETS_CREDENTIALS_BASE64

if ! php artisan config:clear > /dev/null; then
  echo 'SYNC_SUMMARY status=FAILED exit=1 reason="php artisan config:clear gagal."' >&2
  exit 1
fi

failed=0
summary=""

run_sync() {
  code=0
  php artisan "$1" || code=$?

  if [ "$code" -eq 0 ]; then
    summary="$summary $1=OK"
  else
    summary="$summary $1=FAILED(exit=$code)"
    failed=1
  fi
}

run_sync gangguan:sync
run_sync apkuk:sync
run_sync keypoint:sync

if [ "$failed" -eq 0 ]; then
  echo "SYNC_SUMMARY status=OK exit=0$summary"
else
  echo "SYNC_SUMMARY status=FAILED exit=1$summary" >&2
fi

exit "$failed"
