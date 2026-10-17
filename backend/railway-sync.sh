#!/bin/sh
set -eu
umask 077

: "${GOOGLE_SHEETS_CREDENTIALS_BASE64:?Kredensial Google belum diatur}"
: "${DB_DATABASE:?Database belum diatur}"

if [ "$DB_DATABASE" != "ulp_sribhawono_shared_dev" ]; then
  echo "Database tidak sesuai. Sinkronisasi dibatalkan."
  exit 1
fi

printf '%s' "$GOOGLE_SHEETS_CREDENTIALS_BASE64" |
  base64 -d > /tmp/ulp-google-credentials.json

export GOOGLE_SHEETS_CREDENTIALS_PATH=/tmp/ulp-google-credentials.json
unset GOOGLE_SHEETS_CREDENTIALS_BASE64

trap 'rm -f /tmp/ulp-google-credentials.json' EXIT

php artisan config:clear

failed=0

php artisan gangguan:sync || failed=1
php artisan apkuk:sync || failed=1
php artisan keypoint:sync || failed=1

exit "$failed"