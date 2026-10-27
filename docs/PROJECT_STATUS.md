# PROJECT STATUS & COLLABORATION HANDOFF
## Sistem Pengelolaan Pekerjaan Jaringan Distribusi — ULP Sribhawono

**Version:** v3.0 — 8 Oktober 2026  
**Scope:** Single-tenant, 5 role, Admin Web + Flutter 4 role  
**Status dokumen:** Source of truth aturan bisnis terbaru setelah klarifikasi 6–8 Oktober 2026  
**Engineering baseline GitHub:** `main` (versi operasional v3; seluruh commit Dashboard/P0/WO, dokumen v3, dan migration tim sudah terkonsolidasi).  
**Pembaruan terakhir:** 19 Oktober 2026 — audit kode lokal `main` (5e616c7), test suite dijalankan ulang, dan `migrate:status` dicek pada Neon shared dev (read-only).

# 1. Baca ini pertama

Dokumen v2 di histori Git sudah superseded; keputusan terbaru v3 mengungguli narasi lama. Rujuk spesifikasi aktif v3: PRD, DESIGN_BRIEF, TECHNICAL_SPEC, DATA_MODEL, API_CONTRACT, OFFLINE_SYNC_SPEC, IMPLEMENTATION_PLAN. Jangan ubah FINAL_AUDIT_REPORT.md karena laporan masa lalu.

**Larangan revived:** HARSUS executor P0, POSKO tanpa P0, P0 tidak terkait PASILA, hard threshold >3 trip per bulan, manual-only offline submit, Google Maps sebagai web map utama, POSKO sebagai satu-satunya pemegang WO, data FCO terpisah masih wajib diminta tanpa audit KEYPOINT.

# 2. 5 role terkunci

| Role | Platform | Akses/pekerjaan |
|---|---|---|
| ADMIN | Web Laravel | Semua monitor, review/ACC-Tolak, manual WO/PASILA/P0/HARSUS/ROW, Maps, koreksi history |
| POSKO | Flutter Android | 6 WO APKUK/shift/Posko, PASILA setiap shift + P0 dalam PASILA, reschedule |
| HARSUS | Flutter Android | WO PEKERJAAN_KHUSUS manual Admin atau limpahan setelah ACC |
| INSPEKSI | Flutter Android | Cari temuan nyata min 1/hari, ranking area + rotasi, validasi Admin |
| ROW | Flutter Android | WO otomatis dan manual RABAS/PANGKAS/TEBANG, cluster rute |

Satu akun tim per Posko (enam), satu akun untuk ROW/HARSUS/INSPEKSI, pilihan petugas aktual terpisah. Tim Posko tetap, shift dapat berubah. Batas 2 device dan logout 4 jam **bukan final**.

# 3. Aturan bisnis yang sudah final

- PASILA dibuat otomatis **awal Shift A/B/C** per Posko. WO APKUK diprioritaskan enam pekerjaan per shift, jumlah APKUK fisik dicatat sendiri.
- Jika WO belum lengkap, POSKO boleh lanjut PASILA+P0 jika sisa WO **direschedule** dengan dropdown alasan + catatan manual wajib dan pilihan petugas.
- Minimal satu P0 tiap shift; P0 **POSKO**, wajib terhubung PASILA dan Section/Posko sama. SYSTEM proposal ACC Admin sebelum aktif; reject → Admin **membuat P0 manual pengganti**; Admin P0 manual dari PASILA.
- PASILA boleh berisi banyak temuan, **masing-masing foto/GPS/detail sendiri**.
- HARSUS punya WO khusus langsung Admin berasset jaringan atau child escalation ACC. Cuaca/reschedule tidak dialihkan ke HARSUS.
- Eskalasi lintas tim membutuhkan ACC Admin, source **SELESAI_DITERUSKAN**, child WO baru dengan provenance sumber.
- INSPEKSI wajib berusaha menemukan >=1 temuan nyata/hari; no-finding tercatat jujur dan berpindah area. Sistem rekomendasi tim tujuan, Admin ACC.
- ROW auto WO **langsung aktif tanpa ACC**, banyak per hari, lokasi dekat dikelompokkan/rute bergilir.
- Admin boleh membuat manual seluruh jenis pekerjaan yang didukung sistem.
- Bukti pekerjaan secara sementara BEFORE+AFTER dan device GPS otomatis; dropdown form, catatan biasa opsional.
- Offline submit lokal tetap sah sebagai *submission queued*, dikirim otomatis saat kondisi mendukung, gagal tersimpan dan dapat kirim ulang tanpa input ulang.
- Maps Web Leaflet pilih Penyulang wajib; global search 16 Penyulang switch otomatis, layer gangguan lokasi kejadian → affected assets, drawer foto terbaru dan history lama, quick create Admin; GIS jalur opsional.
- History tidak dihapus, koreksi hanya Admin dengan old/new/actor/waktu/alasan.
- Special case berbasis **frekuensi gangguan**, bukan fixed >3 trip. Metode/ambang masih TBD.

# 4. Arsitektur yang dipertahankan

Laravel backend/API/Admin Web, PostgreSQL authoritative, Flutter Android, SQLite cache/outbox ringan, Laravel Filesystem local dev/S3-ready prod, Leaflet/OSM Web, Google Maps external mobile navigation, scheduler/queue dan polling. Tidak menggunakan Next.js/Supabase/PowerSync/full offline basemap.

# 5. Implementasi yang terkonfirmasi (audit kode lokal 19 Okt 2026)

| Komponen | Kondisi |
|---|---|
| Laravel repo/backend framework | Ada (PHP 8.4) |
| Staging source tables + artisan import, profiler, audit staging, `topology:audit` | Ada |
| Sinkron Google Sheets → `gangguans` (`gangguan:sync`), `apkuks` (`apkuk:sync`, AppSheet lama), `keypoints` (`keypoint:sync`, SPS BOS) | Ada; dijadwalkan per 15 menit di `routes/console.php` |
| Runner cron Railway (`backend/railway-sync.sh`) ke Neon shared dev | Ada; menolak jalan bila `DB_DATABASE` bukan `ulp_sribhawono_shared_dev` |
| Master poskos/penyulangs/pivot | Ada |
| Tabel fondasi `field_teams`, `field_personnel`, `field_team_shift_assignments`, `reference_codes` | Migration ada; **belum dijalankan di Neon** (Pending); belum ada model/UI |
| Canonical Section/Asset & data master Petugas | Belum dibangun |
| Auth Web ADMIN | Ada: login username, middleware `auth`+`admin` pada `/admin/*`, pengaturan akun, `CreateAdminUser` |
| Auth role mobile (POSKO/HARSUS/INSPEKSI/ROW) | Belum dibangun |
| Shift A/B/C | `ShiftService` tunggal (jam sementara 08–16 / 16–00 / 00–08 WIB), dipakai Dashboard dan WO |
| Dashboard Admin | UI v3, angka master asli, KPI transaksi "Data belum tersedia" |
| P0 Admin Monitor+Detail | UI empty state, belum domain |
| WO APKUK Tahap 1 (branch `feat/workflows-v3-foundation`, 20 Okt) | Migration `2026_10_20_000001_create_work_order_foundations` (work_orders generik + target APKUK, audit log, reschedule, laporan, petugas, media); Admin Web daftar/buat manual/detail/batal; validasi kandidat `apkuks` (laporan terbaru per tiang, `kurang_bh` > 0, status BELUM EKSEKUSI/KURANG, penyulang cocok master); cegah WO aktif ganda per tiang (unique `active_target_key`). Sudah dijalankan di Neon branch `dev-workflows-v3` (`--env=workflows`); belum di shared dev. Laporan/reschedule/foto baru struktur data + status, belum ada API/UI |
| WO HARSUS/ROW | Batch A (28 Okt): WO manual Admin HARSUS (target aset keypoint, wajib) dan ROW (target titik vegetasi staging), cegah WO aktif ganda per titik+jenis (`work_order_location_targets.active_target_key`), laporan PEKERJAAN_SELESAI → verifikasi Admin, child WO limpahan. Generator ROW otomatis dan rute harian belum ada (formula TBD) |
| PASILA/P0 | Masih monitor baca saja: sesi PASILA, P0, dan generator per shift menunggu skema P0 detail dan Section canonical (TBD) |
| Inspeksi, Review & Approval, Special Case, Master Data, User & Akses, Audit, Analitik | Operasional sejak Batch A 28 Okt — lihat baris Batch A dan `docs/BATCH_A_RELEASE.md` |
| Web Admin Foundation v1 / Batch A (branch `feat/admin-web-foundation-v1`; commit WIP lokal `7d0a5d3` + finalisasi 27 Okt belum di-commit) | Navigasi desktop + seluler dari satu sumber (`App\Support\AdminNavigation`) untuk 15 menu. Halaman baca saja: PASILA Monitor/Detail (konteks shift + WO APKUK nyata), Inspeksi (kerangka + roster tim), ROW, HARSUS, Review & Validasi (laporan MENUNGGU_VERIFIKASI + reschedule MENUNGGU_ACC; ACC/Tolak nonaktif), Special Case (frekuensi mentah `gangguans`, tanpa ambang), Master Data (7 tab + detail Posko/Penyulang), Analitik, User & Hak Akses, Audit Log (`work_order_status_logs`). Komponen Blade `x-admin.*`, error state per bagian (`App\Support\DataLoader`), halaman pagination di luar jangkauan dialihkan ke halaman terakhir, filter query string lewat `App\Support\QueryInput` (UUID/tanggal tidak valid dan parameter array diabaikan). Dashboard kini menandai Peta Jaringan tersedia. Pembatalan WO dibatasi ke WO APKUK di server (`WorkOrder::supportsAdminCancellation()`); WO ROW/HARSUS ditolak karena aturan batal (child eskalasi, WO otomatis) belum disahkan. Tanpa migration baru, tanpa route tulis baru |
| Web Admin Batch A operasional (branch `feat/admin-web-foundation-v1`, 28 Okt) | Integrasi `main` (PR #8, #9) lalu modul tulis: WO lifecycle (ubah data/jadwal beralasan, catat laporan + foto BEFORE/AFTER atas nama tim, reschedule, limpahan → child WO, kendala), Review & Validasi (ACC/Tolak laporan, reschedule, limpahan, temuan), Inspeksi (kunjungan termasuk Tidak Ada Temuan, temuan + foto, validasi + WO tindak lanjut), Special Case (kendala + tindak lanjut, WO terlambat), Master Data CRUD (tim, petugas, kode referensi, jadwal shift; Posko/Penyulang/Keypoint tetap master sinkron), User & Akses (akun ADMIN/lapangan, aktif/nonaktif, reset sandi, pengaman Admin terakhir), Audit umum (`audit_logs`), Analitik + ekspor CSV, Dashboard data nyata, halaman APKUK. API mobile v1 (Sanctum) untuk Flutter. Migration additive `2026_10_28_000001`/`000002`. Rincian, rencana migrasi, dan checklist audit: `docs/BATCH_A_RELEASE.md` |
| Flutter mobile domain | Masih skeleton tanpa dependency |
| API mobile + offline sync | Belum dibangun |

`main` sudah di-fast-forward dari v2 ke baseline v3 pada 8 Oktober 2026. Branch fitur lama adalah histori; belum otomatis terhapus dari remote.

**Tes (28 Okt 2026, Batch A operasional):** `php artisan test` → **231 passed / 1864 assertions** (SQLite in-memory). Uji browser Microsoft Edge (Playwright) terhadap server lokal dengan PostgreSQL 18 schema sementara berisi data UJI: **40/40 halaman** (desktop + seluler) dan **18/18 alur** (login, buat WO HARSUS, laporan + foto, ACC/Tolak, reschedule, limpahan, inspeksi, kendala, master data, akun, ekspor CSV, akses ditolak) lulus; schema dihapus setelahnya. `npm run build` sukses, Pint lulus untuk semua file PHP yang berubah.

**Tes (27 Okt 2026, branch `feat/admin-web-foundation-v1`, finalisasi Batch A):** `php artisan test` → **177 passed / 1188 assertions** (SQLite in-memory; baseline sebelum paket 141 / 674). `npm run build` sukses, `php artisan view:cache` mengompilasi semua template, `git diff --check` bersih.

**Smoke test PostgreSQL (27 Okt 2026, read-only):** harness memanggil Kernel HTTP Laravel langsung; setiap request di dalam `BEGIN; SET TRANSACTION READ ONLY; … ROLLBACK`, session/cache driver `array`, Admin hanya objek memori, dan berhenti bila host sama dengan `.env.neon`. Tidak ada migrasi, reset, atau penulisan data.
- Neon branch dev (`.env.workflows`, skema lengkap, data sinkron nyata: 470 gangguan, 3.967 APKUK, 129 keypoint; tabel WO/tim/kode masih kosong): **154 request, 154 OK** — login, otorisasi (tamu → login, akun POSKO → 403), Dashboard (+JSON), Maps (+2 endpoint JSON), P0, WO APKUK index/create, Pengaturan Akun, seluruh modul baru beserta filter, pagination, detail, empty state, navigasi desktop + seluler (15 menu, menu aktif), dan 38 skenario input tidak valid. Angka Special Case/Analitik dicocokkan dengan SQL langsung.
- PostgreSQL lokal (`.env`, kosong, 2 migration tertinggal: `create_work_order_foundations`, `add_source_missing_at_to_apkuks_table`): **116 request — 101 OK, 3 N/A (data kosong), 12 SKEMA, 0 gagal kode**. 47 halaman modul baru tetap HTTP 200 dengan error state; 500 hanya pada halaman lama (Maps data, WO APKUK) dan detail Audit Log karena tabel/kolom memang belum ada. DB lokal perlu `php artisan migrate` (additive) bila ingin dipakai penuh.
- Masalah yang ditemukan dan diperbaiki: filter bukan UUID dan tanggal tidak ada (mis. `2026-13-45`) ditolak PostgreSQL (WO APKUK sampai 500), parameter array (`?status[]=x`) memicu 500 di hampir semua modul, ID di atas batas `bigint` memicu 500, halaman pagination di luar jangkauan tampil kosong menyesatkan, tab Sumber Data 22 → 14 query.
- Belum diverifikasi: tampilan visual di browser (tidak ada alat browser di sesi ini) dan alur dengan transaksi WO nyata di PostgreSQL (tabel WO di Neon dev masih kosong; tercakup test SQLite).

**Tes (20 Okt 2026, branch `feat/workflows-v3-foundation`):** `php artisan test` → **137 passed / 656 assertions** (SQLite in-memory). Jangan memakai `php artisan test --env=...`: opsi itu membuat koneksi `.env` bocor ke PHPUnit; `tests/TestCase.php` kini membatalkan test bila koneksi bukan SQLite `:memory:`.

**Tes (19 Okt 2026):** `php artisan test` → **108 passed / 489 assertions** (SQLite in-memory). Sebelumnya 8 tes gagal karena masih memakai tabel staging lama `gangguan_source_rows`/`keypoint_source_rows`; tes sudah diarahkan ke tabel sinkron `gangguans`/`keypoints`.

# 6. Data snapshot sumber (historis, bukan query live)

- Enam Posko, 16 Penyulang, 26 pivot.
- Laporan historis: sekitar 17.059 aset valid dari staging, 16.983 record vegetasi, 129 keypoints, 69 Section/PIC.
- Sejak 10–15 Oktober, gangguan, APKUK, dan keypoint tidak lagi lewat staging `*_source_rows`, melainkan disinkron langsung ke `gangguans`, `apkuks`, `keypoints`. Jumlah baris live harus dicek dari tabel tersebut.
- Spreadsheet APKUK dan gangguan telah mendapat pembaruan setelah dokumentasi v2: snapshot percakapan terakhir menyebut **2.922 APKUK source rows** dan **451 gangguan**, sedangkan dokumen GitHub v2 masih menyebut 2.864 dan 443. **Jangan menganggap salah satunya jumlah live PostgreSQL tanpa re-check import/status DB.**
- KEYPOINT menurut klarifikasi berisi seluruh data FCO/switching yang diperlukan; verifikasi canonical keypoint/Section tetap wajib. Dua PIC Section dari staging masih kosong, jangan ditebak.

# 7. Apa yang menunggu data atau keputusan

**Butuh pusat/atasan:** kepastian Section/batas, ID dan koordinat aset/cross references, histori maintenance, foto historis, GIS optional, gangguan affected assets, master petugas/kode temuan resmi, formula prioritas & vegetasi, detail form P0, kebijakan sesi perangkat, approval reschedule, aturan carryover, duplicate vegetation WO dan formula frekuensi special-case.

**Butuh keputusan tim:** jam shift A/B/C final; kapan migration `create_field_team_foundations` dijalankan di Neon shared dev; peran data `apkuks` hasil sync (sumber WO atau histori).

**Tidak blocked:** dokumen, RBAC role mobile dan tests, migration additive berdasarkan schema pasti, kode reference/master, API contracts, desain dan UI admin halaman berikutnya, peta dengan empty state/lazy filtering, offline worker design.

# 8. Prioritas pekerjaan berikutnya (keputusan 8 Okt)

1. Sinkronkan **delapan dokumen aktif** sebagai v3 tanpa menyentuh FINAL_AUDIT_REPORT.
2. Audit database runtime lokal + susun ERD/migration domain dan hak akses/role yang tidak bergantung data pusat.
3. Jika Section/canonical assets dan formula belum siap, jangan menunggu diam: refactor Dashboard/P0/WO dan lanjut **UI Web Admin** PASILA, Inspeksi, ROW/HARSUS, Approval, Maps/History secara bertahap.
4. Saat data resmi tersedia, import canonical tervalidasi dan hubungkan UI ke transaksi/backend.
5. Bangun Flutter dengan automatic offline outbox dan tes integrasi end-to-end.

**DoD:** setiap fitur diuji auth+authorization+integritas+foto/GPS/status, tidak ada fake data operasional, log/audit tertaut, setelah selesai update status berikut branch/commit/hasil tes. Jangan klaim rilis tanpa hasil runtime.

# 9. Perubahan manual terhadap repository

Rebaseline v3 semula dibuat pada branch kerja terpisah lalu **diintegrasikan ke `main` dengan fast-forward**. Setelah itu README, halaman starter, dan label P0/WO lama dibersihkan di `main`. Tidak ada reset database, perubahan migration lama, atau penulisan data operasional.

Setelah itu (10–17 Okt): autentikasi Admin, redesign Dashboard v3, sinkron gangguan/APKUK/keypoint, konfigurasi SSL Neon, dan runner Railway. Pada 19 Okt: tes yang tertinggal diperbaiki, logika shift disatukan ke `ShiftService`, dan dokumen ini diperbarui. Tidak ada migration yang dijalankan atau data yang ditulis ke Neon.
