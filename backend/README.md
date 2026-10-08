# Backend ULP Sribhawono

Laravel untuk Web **ADMIN**, API mobile, impor data, scheduler, dan PostgreSQL. Acuan utama: `../docs/PROJECT_STATUS.md` dan `../docs/PRD.md` (v3).

## Development

Pastikan PHP/Composer sesuai `composer.json`, PostgreSQL tersedia, dan npm terpasang.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:status
php artisan test
npm install
npm run build
```

Untuk project lama yang sudah memiliki `.env`, **jangan timpa** konfigurasi tersebut; perintah copy hanya untuk instalasi baru. Backup PostgreSQL sebelum menerapkan migration tambahan. Jangan jalankan `migrate:fresh` atau `db:wipe` pada data sungguhan.

## Status

Import/staging dan master Posko/Penyulang sudah ada. Dashboard, P0, dan WO Admin masih kerangka, belum domain transaksi operasional. Route Admin belum mempunyai autentikasi/role guard. Migration untuk tim, petugas, shift, dan kode referensi sudah dibuat tetapi belum dijalankan di database lokal.
