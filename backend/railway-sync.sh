#!/bin/sh
# Runner cron Railway: sinkronisasi Gangguan, APKUK, lalu Keypoint.
# Ketiga command selalu dijalankan berurutan. Exit 0 bila tidak ada yang gagal;
# exit 1 bila ada yang gagal (Railway menandai eksekusi Crashed).
# apkuk:sync yang berhasil tetapi menyisakan antrean rekonsiliasi (laporan hilang dari
# sumber menunggu keputusan Admin) bukan kegagalan: exit 0 dengan status
# SUCCESS_WITH_PENDING_RECONCILIATION agar tetap terlihat di log.
# Baris terakhir SYNC_SUMMARY merangkum hasil tiap command beserta exit code-nya.
set -eu
umask 077

CREDENTIALS_FILE=/tmp/ulp-google-credentials.json
SYNC_OUTPUT=/tmp/ulp-sync-output.txt

: "${GOOGLE_SHEETS_CREDENTIALS_BASE64:?Kredensial Google belum diatur}"
: "${DB_DATABASE:?Database belum diatur}"

if [ "$DB_DATABASE" != "ulp_sribhawono_shared_dev" ]; then
  echo 'SYNC_SUMMARY status=FAILED exit=1 reason="Database tidak sesuai. Sinkronisasi dibatalkan."' >&2
  exit 1
fi

# Dipasang sebelum file kredensial dibuat agar selalu dihapus, termasuk saat dekode gagal.
trap 'rm -f "$CREDENTIALS_FILE" "$SYNC_OUTPUT" "$SYNC_OUTPUT.code"' EXIT

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
pending=0
summary=""

run_sync() {
  rm -f "$SYNC_OUTPUT" "$SYNC_OUTPUT.code"
  # stdout tetap tampil di log sekaligus disimpan untuk membaca status SYNC_RESULT;
  # stderr tidak dialihkan. Exit code php disimpan karena pipa mengambil exit code tee.
  { php artisan "$1" || echo "$?" > "$SYNC_OUTPUT.code"; } | tee "$SYNC_OUTPUT"

  code=0
  if [ -s "$SYNC_OUTPUT.code" ]; then
    code=$(cat "$SYNC_OUTPUT.code")
  fi

  if [ "$code" -ne 0 ]; then
    summary="$summary $1=FAILED(exit=$code)"
    failed=1
  elif grep -q '^SYNC_RESULT .* status=SUCCESS_WITH_PENDING_RECONCILIATION ' "$SYNC_OUTPUT"; then
    summary="$summary $1=SUCCESS_WITH_PENDING_RECONCILIATION"
    pending=1
  else
    summary="$summary $1=OK"
  fi
}

run_sync gangguan:sync
run_sync apkuk:sync
run_sync keypoint:sync

if [ "$failed" -ne 0 ]; then
  echo "SYNC_SUMMARY status=FAILED exit=1$summary" >&2
elif [ "$pending" -ne 0 ]; then
  echo "SYNC_SUMMARY status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0$summary"
else
  echo "SYNC_SUMMARY status=OK exit=0$summary"
fi

exit "$failed"
