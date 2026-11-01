# Laporan Implementasi Batch B3.5
## Pemantauan PASILA belum ditutup dan audit kontrak API mobile — ULP Sribhawono

- **Tanggal:** 1 November 2026
- **Branch:** `feat/admin-web-foundation-v1` (PR #10, belum di-merge), basis `5199a31` (B3.4)
- **Status kode:** di-commit dan di-push ke PR #10 (belum di-merge).
- **Tidak dilakukan:** merge, deploy Railway, perubahan Neon, perubahan Google Sheets, aktivasi scheduler PASILA, maupun generator P0/APKUK.
- **Cakupan:** backend dan dokumen saja. Folder `mobile/` (aplikasi Flutter, tanggung jawab tim aplikasi) tidak disentuh. Tidak ada migration baru.

## 1. Pemantauan sesi PASILA yang belum ditutup setelah shift berakhir

| Hal | Penerapan |
|---|---|
| Definisi | Sesi berstatus `DIBUKA` yang `shift_instances.ends_at` ≤ sekarang. Sesi shift berjalan dan sesi yang sudah ditutup tidak termasuk |
| Status | Tidak diubah. Tidak ada status baru, penutupan otomatis, job, maupun scheduler. Keterlambatan dihitung saat dibaca (`PasilaSession::scopeUnclosedAfterShift`, `minutesUnclosedAfterShift`, `durationLabel`) |
| PASILA Monitor | Panel baru "Belum ditutup setelah shift berakhir (n)": lintas tanggal, terlama dulu, maks 50 baris dengan total; kolom Posko, kode/shift, jam shift berakhir, lama keterlambatan (merah bila ≥ 1 hari), laporan patroli; mengikuti filter Posko. Baris tabel shift menampilkan status asli "Dibuka" (badge kuning) ditambah "Belum ditutup … setelah shift berakhir" |
| Detail PASILA | Badge status mengikuti keadaan (hijau ditutup, kuning terlambat, biru dibuka). Panel penutupan menjelaskan jam shift berakhir, lama tanpa penutupan, status tetap Dibuka, dan bahwa penutupan dari HP yang masih offline tetap diterima dan ditandai |
| Penutupan terlambat | Tetap diterima (aturan B3.4) dan ditandai `DITUTUP_SETELAH_SHIFT`. Setelah itu sesi keluar dari panel |

Perhitungan memakai `ends_at` UTC. Batasnya: sesi Shift A dihitung terlambat tepat pukul 16.00 WIB, belum pada 15.59 (diuji di PostgreSQL).

## 2. Audit kontrak API mobile

Respons direkam dari server lokal dengan `APP_DEBUG=false`, untuk login, akun, bootstrap, WO APKUK (feed, detail, mulai, laporan, retry, reschedule), PASILA (sesi, temuan, nihil, penutupan), P0, logout, dan semua jenis galat. Hasilnya menjadi `docs/MOBILE_API_GUIDE.md` untuk tim aplikasi. Bentuk respons dikunci oleh `MobileApiContractTest`.

### Ketidaksesuaian yang ditemukan dan perbaikannya

| No | Temuan | Tindakan | Perubahan kontrak |
|---|---|---|---|
| 1 | Sesi PASILA yang tidak ada dibalas `"No query results for model [App\Models\PasilaSession] …"`. Pesan ini membocorkan nama kelas dan berbeda dari sesi Posko lain (`"Sesi PASILA tidak ditemukan untuk Posko tim ini."`), sehingga keberadaan sesi dapat ditebak. Ini tidak sesuai prinsip 404 di controller WO | Controller mobile PASILA mencari sesi di dalam Posko tim (pola `WorkOrderController::scoped`) | Ya: 404 kini identik. Akun non-POSKO mendapat 403 lebih dulu, juga untuk ID yang tidak ada (sebelumnya 404) |
| 2 | `meta.warnings` penutupan berupa objek bila ada peringatan, tetapi `[]` (array JSON) bila kosong. Klien bertipe Map akan gagal parse | Dikirim sebagai objek (`{}` bila kosong) | Ya: `[]` → `{}` |
| 3 | `API_CONTRACT.md` §2–§3 memuat rute usulan yang tidak pernah dibangun (`/work-feed/{id}`, `/work-orders/{id}/complete`, `/reschedule`, `/reference-codes`, API Admin) | Ditandai **Dibangun**/**Belum dibangun** dengan rute aktualnya; panduan menjadi acuan | Dokumen saja |
| 4 | Komentar `routes/api.php` menyebut galat `{ message, errors }`, padahal 401/403/404 hanya `{ message }` | Komentar disesuaikan | Tidak |

### Temuan yang didokumentasikan (tidak diubah)

- Login HARSUS/INSPEKSI/ROW berhasil (API melayani semua tim lapangan). Aplikasi POSKO wajib memeriksa role.
- 401 dan 429 memakai pesan bawaan Laravel berbahasa Inggris. Aplikasi sebaiknya memakai kode status.
- Akun yang dinonaktifkan Admin mendapat 403 dengan `errors.auth`, bukan 401.
- Token Sanctum tidak kedaluwarsa otomatis (kebijakan masih TBD).
- `occurred_at` laporan WO opsional; tanpa itu server memakai waktu terima. Panduan meminta aplikasi selalu mengirimnya.
- Replay idempoten diperiksa sebelum aturan jendela shift, sehingga retry setelah shift berakhir aman.

## 3. Pengujian

| Uji | Lingkungan | Hasil |
|---|---|---|
| Suite Laravel penuh | SQLite in-memory | **426 lulus / 4.097 assertion** (B3.4: 417/3.677), termasuk regresi B1–B3.4 |
| `PasilaUnclosedMonitorTest` (baru) | SQLite | 4 tes: daftar lintas tanggal dan urutan, label durasi, filter Posko, detail, sesi ditutup/shift berjalan tidak ikut; status dan `updated_at` tidak berubah, termasuk setelah pembuka shift berikutnya berjalan; penutupan terlambat dari HP offline diterima lalu keluar dari daftar; panel kosong |
| `MobileApiContractTest` (baru) | SQLite, `app.debug=false` | 5 tes: login/me/logout + envelope galat; bootstrap, feed, detail, mulai, laporan, replay, kunci sama isi beda, 409, reschedule, riwayat, 404 WO; PASILA, temuan, penutupan + `warnings`, P0 dan WO P0; penutupan tanpa peringatan → `{}`; 404 sesi identik + 403 non-POSKO |
| Regresi PASILA/mobile/P0 | SQLite | 87 tes lulus setelah perubahan controller |
| PostgreSQL | Postgres lokal, schema sementara `b35test_*` (dihapus) | **11/11**: scope di PG lintas tanggal, batas 15.59/16.00 WIB, query panel (join + withCount + limit), render monitor/detail, filter Posko, 404 API identik, status tidak berubah |
| Browser | Edge headless, `php -S :8010`, schema sementara `b35browser_*` (dihapus) | **12/12**: panel 3 sesi terlama dulu, sesi ditutup tidak tampil, label baris dan detail, seluler tanpa scroll horizontal, 0 error console |
| Pint `--test`, `git diff --check` | file B3.5 | Lulus, bersih |

## 4. Risiko integrasi untuk aplikasi

| Risiko | Dampak | Langkah |
|---|---|---|
| Batas unggah PHP (`upload_max_filesize` bawaan 2 MB, `post_max_size` 8 MB) lebih kecil dari aturan aplikasi (10 MB per foto, 10 foto). Konfigurasi PHP Railway tidak tercatat di repo | Foto besar ditolak 422, request besar 413 | Aplikasi mengompres foto; tim server memeriksa/menaikkan batas PHP di Railway |
| Aplikasi lupa mengirim `occurred_at` pada laporan WO | Waktu kerja tercatat sebagai waktu terima (salah untuk kiriman offline) | Selalu kirim; dibahas di panduan |
| Penutupan dikirim sebelum laporan patroli (urutan outbox) | 409 berulang | Urutan outbox per sesi (panduan §8) |
| Dua HP satu akun | Temuan ganda dengan kunci berbeda | Kebijakan perangkat masih TBD |
| Batas login 6/menit per IP | Banyak HP di balik satu IP operator (CGNAT) dapat saling membatasi saat login bersamaan | Pantau; ubah batas bila terjadi |
| `APP_DEBUG=true` di server | Galat 403/404/500 memuat trace dan path server | Pastikan `APP_DEBUG=false` di production |
| Monitor menampilkan 50 sesi terlambat terlama | Sesi lebih baru di luar 50 tidak tampil di panel (total tetap benar) | Cukup untuk 6 Posko; tambah paginasi bila perlu |

## 5. Menunggu keputusan

| Hal | Status |
|---|---|
| Batas waktu penutupan terlambat dan tindak lanjut sesi yang tidak pernah ditutup | Hanya dipantau; tanpa penutupan otomatis |
| Penutupan oleh Admin atas nama Posko | Belum ada |
| Kebijakan token (logout 4 jam, 2 perangkat) | TBD |
| `occurred_at` wajib untuk laporan WO mobile | Saat ini opsional; mewajibkannya akan mengubah kontrak |

## 6. File

| File | Perubahan |
|---|---|
| `backend/app/Models/PasilaSession.php` | Scope `unclosedAfterShift`, `minutesUnclosedAfterShift`, `unclosedAfterShiftLabel`, `durationLabel` |
| `backend/app/Http/Controllers/Admin/PasilaController.php` | Data panel sesi belum ditutup (total + 50 terlama, filter Posko) |
| `backend/resources/views/admin/pasila/index.blade.php`, `show.blade.php` | Panel, label baris, badge status, penjelasan di panel penutupan |
| `backend/app/Http/Controllers/Api/V1/Mobile/PasilaController.php` | Lookup sesi di dalam Posko tim (404 identik); `meta.warnings` selalu objek |
| `backend/routes/api.php` | Komentar format respons |
| `backend/tests/Feature/Operations/PasilaUnclosedMonitorTest.php`, `MobileApiContractTest.php` | Tes baru |
| `docs/MOBILE_API_GUIDE.md` | Baru: panduan API untuk tim aplikasi |
| `docs/API_CONTRACT.md`, `OFFLINE_SYNC_SPEC.md`, `PROJECT_STATUS.md` | Penanda dibangun/belum, rujukan panduan, status B3.5 |
