# BATCH A — Web Admin Operasional
## ULP Sribhawono · branch `feat/admin-web-foundation-v1` · 28 Oktober 2026

Dokumen ini untuk reviewer PR, operator deploy, dan audit langsung Web Admin. Belum ada yang dijalankan di produksi: tidak ada migration, deploy, atau perubahan data Neon/Railway dari pekerjaan ini.

# 1. Status modul

| Modul | Status | Catatan |
|---|---|---|
| Dashboard | Selesai | KPI, kelompok status, tren 14 hari, ringkasan per tim/POSKO/penyulang, APKUK, gangguan, inspeksi, antrean keputusan, aktivitas terbaru. Filter periode hari ini / 7 / 30 hari. Semua angka dari database |
| Peta Jaringan | Dipertahankan | Maps v1 tidak diubah; APKUK bertanda `source_missing_at` tetap tidak tampil sebagai aktif |
| Work Order | Selesai | Detail generik semua tim, ubah data/jadwal beralasan (status tidak berubah), catat atas nama tim (mulai, laporan + foto), limpahan, kendala, pembatalan, riwayat audit dengan nilai sebelum/sesudah |
| APKUK | Selesai | Laporan aktif + kelayakan WO, histori `source_missing_at`, kebutuhan (bh), progres pemasangan. Tidak menulis ke Google Sheets |
| PASILA / P0 | Terbatas | Monitor konteks shift tetap baca saja. Sesi PASILA, P0, generator per shift menunggu keputusan (lihat §7) |
| Inspeksi | Selesai | Kunjungan (termasuk "Tidak Ada Temuan"), temuan + foto, validasi Admin, WO tindak lanjut HARSUS/ROW |
| ROW | Selesai (manual) | WO RABAS/PANGKAS/TEBANG pada titik vegetasi; generator otomatis dan rute harian belum ada |
| HARSUS | Selesai (manual + limpahan) | WO PEKERJAAN_KHUSUS wajib aset jaringan (keypoint); child WO dari limpahan |
| Review & Validasi | Selesai | ACC/Tolak laporan, reschedule, limpahan; antrean temuan inspeksi |
| Special Case | Selesai | Kendala pelaksanaan + tindak lanjut, reschedule/limpahan menunggu, WO terlambat, frekuensi gangguan (metode ambang tetap TBD) |
| Master Data | Selesai | CRUD tim, petugas, kode referensi, jadwal shift. Posko/Penyulang/Keypoint tetap master sinkron (baca saja) |
| Analitik | Selesai | Rekap per periode, jenis, Posko, penyulang; ketepatan waktu; ekspor CSV |
| User & Hak Akses | Selesai | Akun ADMIN dan akun lapangan terikat tim; aktif/nonaktif; reset sandi; pengaman Admin terakhir |
| Audit Log | Selesai | Audit WO (`work_order_status_logs`) + audit umum (`audit_logs`: master, akun, keputusan) |
| API mobile v1 | Siap untuk integrasi | Belum diuji bersama aplikasi Flutter (aplikasi belum tersedia) |

# 2. Keputusan sementara yang perlu dikonfirmasi

Diambil karena dokumen belum mengatur; mudah diubah bila keputusan berbeda.

1. **Penyelesaian WO HARSUS/ROW** (`PEKERJAAN_SELESAI`) masuk MENUNGGU_VERIFIKASI dan perlu ACC Admin. APKUK tetap sesuai PRD: PEMASANGAN_SELESAI langsung SELESAI; TEMUAN_SUDAH_LENGKAP perlu ACC.
2. **Bukti foto:** penyelesaian wajib BEFORE + AFTER; laporan temuan wajib minimal satu foto. Input Admin atas nama tim boleh tanpa GPS; API wajib GPS.
3. **Pembatalan:** WO ROW/HARSUS manual Admin yang tidak terlibat limpahan dapat dibatalkan. Child/parent limpahan dan WO otomatis sistem tetap tidak dapat dibatalkan.
4. **Ubah jadwal oleh Admin** mengubah tanggal/shift/target tanpa mengubah status (state machine hanya mengenal "Dijadwalkan Ulang" dari pengajuan petugas yang di-ACC).
5. **Target lokasi:** HARSUS memakai `keypoints` (aset sinkron) karena master aset canonical belum ada; ROW memakai `vegetasi_source_rows` (staging). Disimpan sebagai snapshot.
6. **Tolak reschedule** mengembalikan WO ke Dikerjakan bila sudah dimulai, selain itu ke Ditugaskan.
7. **Rekomendasi tim penerima temuan** diisi manual (algoritma rekomendasi TBD).

# 3. Perubahan database (semua additive)

| Migration | Isi |
|---|---|
| `2026_10_28_000001_create_admin_operations_tables` | Kolom `users.field_team_id` (nullable FK); tabel `audit_logs`, `work_order_location_targets`, `work_escalations`, `work_order_issues`, `inspections`, `inspection_personnel`, `inspection_findings`, `inspection_finding_media` |
| `2026_10_28_000002_create_personal_access_tokens_table` | Token Sanctum untuk API mobile |

Tidak ada kolom yang diubah atau dihapus. `down()` hanya menghapus tabel/kolom baru (diuji). Dependensi baru: `laravel/sanctum ^4.3`.

# 4. Rencana migrasi & deploy produksi

**Prasyarat persetujuan:** PR direview dan disetujui; jadwal deploy di luar jam sibuk.

1. **Backup:** buat branch Neon cadangan dari branch production (mis. `backup-before-batch-a`).
2. **Verifikasi target (read-only):** host `.env.neon-migrate` = endpoint production, database `ulp_sribhawono_shared_dev`, role owner, timeline Neon sama dengan koneksi runtime Railway. `php artisan migrate:status --env=neon-migrate` harus menunjukkan **hanya** dua migration di atas yang Pending. Migration PR #11 (`2026_10_28_100001`, `2026_10_28_100002`) sudah Ran di production pada 28 Okt (batch 15–16) dan tidak boleh dijalankan ulang; Batch A tidak bergantung padanya.
3. **Migrasi spesifik (role owner)**, satu per satu, didahului `--pretend`:
   ```
   php artisan migrate --env=neon-migrate --path=database/migrations/2026_10_28_000001_create_admin_operations_tables.php --pretend
   php artisan migrate --env=neon-migrate --path=database/migrations/2026_10_28_000001_create_admin_operations_tables.php --force
   php artisan migrate --env=neon-migrate --path=database/migrations/2026_10_28_000002_create_personal_access_tokens_table.php --force
   ```
   Migration dijalankan **sebelum** deploy kode: kode lama tidak terpengaruh tabel baru.
4. **Verifikasi:** tabel baru ada; role runtime (Railway) punya SELECT/INSERT/UPDATE pada tabel baru (default privileges owner → runtime sudah ada untuk `apkuk_reconciliations`; periksa ulang); jumlah baris `apkuks`, `work_orders` tidak berubah.
5. **Variabel Railway (persetujuan terpisah):**
   - `APP_LOCALE=id` agar pesan validasi berbahasa Indonesia.
   - `MEDIA_DISK` ke penyimpanan **persisten** (disk S3-kompatibel atau volume Railway). Disk `local` di container Railway hilang setiap deploy — **jangan aktifkan upload foto produksi sebelum ini beres.**
6. **Deploy kode** (merge PR → Railway build: `composer install` memasang Sanctum).
7. **Data awal lewat UI (bukan seeder):** kode referensi (alasan reschedule, alasan limpahan, jenis temuan), tim lapangan + petugas, jadwal shift, akun lapangan.
8. **Smoke test produksi (baca dulu):** login, Dashboard (kartu APKUK aktif dan menunggu rekonsiliasi), Work Order, APKUK (tab Menunggu rekonsiliasi), Rekonsiliasi APKUK, Review, Audit Log; cron Railway tetap `SYNC_SUMMARY status=OK` atau `SUCCESS_WITH_PENDING_RECONCILIATION` dengan exit 0.

**Rollback:** kembalikan deploy Railway ke versi sebelumnya; tabel baru boleh tetap ada (tidak dipakai kode lama). `migrate:rollback` hanya bila tabel baru masih kosong, karena `down()` menghapus tabel tersebut.

# 5. API mobile v1 (ringkas)

Basis `/api/v1/mobile`, token `Authorization: Bearer …` (Sanctum, hanya akun lapangan aktif yang timnya aktif; sesi Web Admin tidak berlaku). Respons `{data, meta}`; galat `{message, errors}`: 401 tanpa token, 403 akun/tim tidak berhak, 404 WO di luar cakupan tim, 409 konflik workflow, 422 validasi. Semua kiriman memakai `client_request_id` (pengiriman ulang tidak membuat data ganda).

| Endpoint | Fungsi |
|---|---|
| `POST /auth/login`, `POST /auth/logout`, `GET /me` | Login akun tim (username, password, device_name) |
| `GET /bootstrap` | Tim, petugas aktif, kode dropdown sesuai role, jenis kendala, shift, penyulang |
| `GET /work-feed?scope=active\|history` | WO cakupan tim (POSKO: Posko timnya; HARSUS/ROW: tim sejenis; WO bertim khusus hanya untuk tim itu) + URL Google Maps |
| `GET /work-orders/{id}` | Detail, laporan, reschedule, limpahan, kendala |
| `POST /work-orders/{id}/start` | Mulai dikerjakan |
| `POST /work-orders/{id}/reports` | Laporan + petugas + GPS + `photos_before[]`/`photos_after[]`/`photos_evidence[]` (multipart) |
| `POST /work-orders/{id}/reschedules` | Alasan kode + catatan wajib + tanggal/shift tujuan |
| `POST /work-orders/{id}/escalations` | Permintaan limpahan ke HARSUS/ROW |
| `POST /work-orders/{id}/issues` | Kendala lapangan |
| `GET/POST /inspections` | Kunjungan INSPEKSI dengan temuan + foto per temuan |

Batas dua perangkat dan logout berkala empat jam masih TBD sehingga token belum kedaluwarsa otomatis.

# 6. Checklist audit langsung lewat browser

Login sebagai ADMIN. Untuk setiap menu: halaman terbuka tanpa galat, filter/pencarian/pagination bekerja, detail terbuka, empty state jelas, tampilan seluler (lebar 375 px) tanpa scroll horizontal.

- [ ] **Dashboard:** angka cocok dengan daftar WO; ganti periode; tren 14 hari; tautan akses cepat.
- [ ] **Work Order:** buat WO APKUK dari kandidat layak; detail; ubah jadwal (alasan wajib, riwayat sebelum/sesudah); catat laporan dengan foto BEFORE/AFTER; foto terbuka; batalkan WO dengan alasan.
- [ ] **APKUK:** tab aktif/histori/progres; laporan bertanda tidak muncul di aktif; tautan "Layak — buat WO".
- [ ] **PASILA / P0:** monitor tampil; tidak ada tombol tulis palsu.
- [ ] **Inspeksi:** catat kunjungan tanpa temuan (catatan wajib); catat temuan dengan foto; ACC + WO tindak lanjut ROW; tolak dengan alasan.
- [ ] **ROW / HARSUS:** buat WO (pilih penyulang → pilih titik/aset); WO ganda ditolak; aset beda penyulang ditolak.
- [ ] **Review & Validasi:** ACC/Tolak laporan (tolak wajib alasan); ACC reschedule (jadwal pindah); ACC limpahan (child WO + sumber Selesai Diteruskan); keputusan ganda ditolak.
- [ ] **Special Case:** kendala terbuka → tindak lanjut; WO terlambat.
- [ ] **Master Data:** tambah/ubah tim, petugas, kode, jadwal shift; hapus data yang direferensikan ditolak; Posko/Penyulang baca saja.
- [ ] **Analitik:** filter tim/Posko/penyulang; ekspor CSV terbuka di Excel.
- [ ] **User & Hak Akses:** buat akun lapangan (tim wajib); nonaktifkan; reset sandi; tidak dapat menonaktifkan diri sendiri.
- [ ] **Audit Log:** tab WO dan tab umum; detail sebelum/sesudah; kata sandi tidak pernah tercatat.
- [ ] **Akses:** akun lapangan tidak dapat login Web Admin; URL /admin langsung ditolak.

# 7. Blocker / menunggu keputusan

1. **PASILA & P0:** skema P0 detail, Section canonical, formula prioritas generator per shift (PRD §2, API_CONTRACT §4 TBD).
2. **Generator otomatis:** target 6 WO APKUK/shift dan WO ROW otomatis menunggu formula prioritas/risiko vegetasi dan kebijakan duplikasi.
3. **Penyimpanan foto produksi:** butuh disk persisten (lihat §4.5).
4. **Aplikasi Flutter:** API siap, belum diuji bersama aplikasi.
5. **Kebijakan perangkat/token:** batas dua perangkat, logout 4 jam (TBD).
6. **Master aset canonical:** HARSUS memakai keypoint sampai aset canonical tersedia.

# 8. Membuka Web Admin untuk audit

- **Lokal:** `cd backend && composer install && npm ci && npm run build`, arahkan `.env` ke database lokal yang sudah `php artisan migrate`, buat admin dengan `php artisan admin:create`, lalu `php artisan serve` → `http://127.0.0.1:8000/login`. Isi master dan transaksi lewat UI.
- **Lingkungan bersama:** setelah PR disetujui dan migrasi produksi (§4) dijalankan, buka Web Admin Railway seperti biasa.
