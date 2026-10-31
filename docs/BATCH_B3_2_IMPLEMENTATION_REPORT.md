# Laporan Implementasi Batch B3.2
## Temuan PASILA per Titik dan Laporan Nihil — ULP Sribhawono

- **Tanggal:** 1 November 2026
- **Branch:** `feat/admin-web-foundation-v1` (PR #10, belum di-merge), basis `24af20a` (B3.1)
- **Status kode:** di-commit dan di-push ke PR #10 (belum di-merge). Tidak ada merge, deploy, migration production, perubahan Railway, maupun perubahan Google Sheets. Pembuka sesi PASILA tetap nonaktif secara default.
- **Di luar cakupan:** P0, approval P0, penutupan sesi PASILA (gate WO APKUK), dan aplikasi Flutter.

## 1. Aturan yang diterapkan

| Aturan | Penerapan |
|---|---|
| Satu sesi PASILA milik satu Posko pada satu shift | Temuan selalu merujuk `pasila_session_id`; Posko disalin dari sesi |
| Satu sesi dapat memiliki banyak temuan | `sequence` unik per sesi (1, 2, …), diserialkan kunci baris sesi |
| Setiap temuan adalah catatan sendiri dengan kondisi, GPS, foto, waktu, dan pelapor | Satu request = satu temuan; GPS perangkat wajib; foto 1–10; `occurred_at` per temuan; petugas roster per temuan |
| Section temuan valid dan milik Posko | Default Section sesi, atau Section yang dipilih petugas; divalidasi ulang terhadap `SectionPoskoMap` saat dicatat |
| Tidak ada temuan fiktif | Tidak ada pencatatan temuan oleh Admin; tidak ada GPS pengganti dari aset; koordinat 0,0 ditolak; kode dan Section tidak ditebak |
| Laporan nihil yang jujur | Laporan nihil terpisah dari temuan; daftar temuan kosong berarti "belum ada laporan patroli", bukan nihil |
| Status PASILA, APKUK, dan P0 terpisah | Status laporan patroli dihitung dari temuan/nihil; WO APKUK dan P0 tidak disentuh |

## 2. Struktur database

Migration additive dan reversible `2026_11_01_000001_create_pasila_findings_tables` (rollback hanya menghapus tabel B3.2).

| Tabel | Isi | Batasan |
|---|---|---|
| `pasila_findings` | sesi, `sequence`, Posko, Penyulang, `section_key`/`section_name`, `finding_code`, `description`, `keypoint_id`/`asset_label` opsional, lat/lon (wajib), `gps_accuracy_m`, `coordinate_source` (`PERANGKAT`), `occurred_at`, `received_at`, `status` (`TERCATAT`), `source` (`MOBILE`), tim dan akun pengirim, `client_request_id` | unique (`pasila_session_id`, `sequence`), unique `client_request_id`; FK ke sesi, Posko, Penyulang, keypoint, tim, akun |
| `pasila_finding_media` | foto per temuan: disk, path unik, SHA-256, MIME, ukuran, waktu, GPS | path unik; foto lama tidak ditimpa |
| `pasila_finding_personnel` | petugas roster per temuan + nama saat dicatat | unique (temuan, petugas) |
| `pasila_nil_reports` | sesi (unik), catatan area/rute, GPS, `occurred_at`, `received_at`, `status` (`AKTIF`/`DIGANTIKAN_TEMUAN`), `superseded_by_finding_id`, pengirim, `client_request_id` | satu laporan nihil per sesi |
| `pasila_nil_report_personnel` | petugas roster laporan nihil | unique (laporan, petugas) |

**Kesiapan P0 (B3.3):** temuan memiliki `id` UUID stabil, sehingga P0 kelak cukup menambah FK ke temuan/sesi. Tidak ada kolom P0 yang dibuat sekarang.

**Status laporan patroli** (`PasilaSession::patrolStatus()`, tidak disimpan): `ADA_TEMUAN` bila ada temuan; `NIHIL` bila ada laporan nihil aktif; selain itu `BELUM_ADA_LAPORAN`.

## 3. Alur POSKO → Admin

1. Sesi PASILA dibuka server pada awal shift (B3.1). Aplikasi tidak membuat sesi sendiri.
2. Aplikasi mengambil `GET /api/v1/mobile/pasila` lalu `GET /api/v1/mobile/pasila/{id}`: Section terverifikasi milik Posko, kode temuan aktif, temuan, dan laporan nihil.
3. Setiap titik dikirim sebagai `POST /api/v1/mobile/pasila/{id}/findings` (multipart) dengan `client_request_id` sendiri. Server memvalidasi:
   - akun: tim POSKO pemilik sesi (bukan POSKO → 403; Posko lain → 404);
   - Section: terverifikasi dan milik Posko sesi;
   - kode: aktif di reference_codes `PASILA_FINDING` untuk POSKO;
   - GPS perangkat: wajib, dalam rentang, bukan 0,0;
   - waktu: di dalam jendela shift sesi dan tidak di masa depan (toleransi 10 menit);
   - petugas: anggota aktif roster tim (nama ketik bebas tidak diterima);
   - foto: 1–10, format dan ukuran sesuai `MediaStore`;
   - keypoint (bila diisi): pada Penyulang Section temuan.
4. Bila patroli selesai tanpa temuan, petugas mengirim `POST /api/v1/mobile/pasila/{id}/nil-report` (catatan area, GPS, waktu, petugas).
5. Admin melihat di **PASILA Monitor** kolom "Laporan patroli" (terpisah dari status sesi, WO APKUK, dan P0), dan di **detail Posko**: setiap temuan dengan kode/label, uraian, Section dan Penyulang, aset, GPS (tautan peta), waktu kejadian/diterima, status, petugas, akun, dan foto (route Admin `admin.media.pasila`), serta laporan nihil beserta statusnya.
6. Setiap temuan dan laporan nihil tercatat di Audit Log.

**Pengiriman offline:** kiriman ulang dengan `client_request_id` sama mengembalikan record yang ada (`meta.idempotent_replay`); isi berbeda dengan kunci sama → 422. Kiriman yang tiba setelah shift berakhir diterima bila `occurred_at` di dalam jendela shift; Admin melihat tanda "terkirim setelah shift berakhir". Temuan yang tiba setelah laporan nihil menggantikan laporan nihil (bukti lapangan tidak dibuang), sedangkan laporan nihil untuk sesi yang sudah memiliki temuan ditolak 409.

## 4. Data-ready

- **Kode temuan** tidak ditulis di kode: kategori baru `PASILA_FINDING` di reference_codes, dikelola Admin di Master Data dan otomatis ikut `bootstrap` aplikasi. Bila belum ada kode aktif, kiriman temuan ditolak dengan pesan bahwa Master Data perlu diisi, dan halaman Admin menampilkan kebutuhan ini.
- **Section** memakai `SectionPoskoMap` yang sama dengan B3.1/B2. Perubahan referensi langsung berlaku untuk temuan baru; temuan lama menyimpan Section saat dicatat dan diberi tanda di Admin bila Section-nya tidak lagi terverifikasi milik Posko itu.
- **Aset** hanya dirujuk bila memang ada (keypoint master pada Penyulang yang sama) atau sebagai label teks; tidak ada pencocokan aset yang ditebak.
- Kode dan petugas yang sudah dipakai temuan/laporan nihil tidak dapat dihapus dari Master Data.

## 5. File

**Baru:** migration B3.2; model `PasilaFinding`, `PasilaFindingMedia`, `PasilaFindingPersonnel`, `PasilaNilReport`, `PasilaNilReportPersonnel`; `App\Services\Pasila\PasilaFindingService`; `App\Http\Controllers\Api\V1\Mobile\PasilaController`; `tests/Feature/Operations/PasilaFindingTest.php`; laporan ini.

**Diubah:** `PasilaSession` (relasi temuan/nihil, status laporan patroli), `ReferenceCode` (kategori `PASILA_FINDING`), `AuditLog` (label entitas), `MasterDataService` (pengaman data dipakai), Admin `PasilaController` dan `MediaController`, view PASILA index/detail, `routes/api.php`, `routes/web.php`; dokumen API_CONTRACT, DATA_MODEL, OFFLINE_SYNC_SPEC, PROJECT_STATUS, laporan B3.1 (status push).

## 6. Pengujian

| Uji | Lingkungan | Hasil |
|---|---|---|
| Suite Laravel penuh | SQLite in-memory | **399 lulus / 3.418 assertion** (B3.1: 388/3.246), termasuk regresi B1/B2/B3.1 |
| `PasilaFindingTest` | SQLite | 11 tes / 170 assertion: banyak temuan dengan bukti sendiri (dua Section milik Posko di dua penyulang), retry idempoten tanpa foto ganda, isi berbeda dengan kunci sama ditolak, Posko lain 404 dan role lain 403, Section Posko lain/tidak ditemukan/ambigu ditolak, sesi tanpa Section, validasi GPS/foto/waktu/kode/petugas/keypoint tanpa foto tertinggal, kiriman offline setelah shift, laporan nihil eksplisit vs daftar kosong, nihil ditolak bila ada temuan dan digantikan temuan kemudian, perubahan referensi Section, tampilan Admin (lokasi, foto, waktu, status, pelapor), foto hanya untuk Admin, bootstrap kode, data terpakai tidak dapat dihapus |
| PostgreSQL | Postgres lokal, schema sementara `b32test_*` dan folder media sementara (dihapus) | **15/15**: migrate 28/28, constraint/FK, rollback hanya tabel B3.2; **dua temuan bersamaan → nomor urut 1 dan 2** (proses kedua menunggu ±3,6 dtk); **retry paralel dengan kunci sama → satu temuan, replay, tanpa foto yatim**; nihil vs temuan bersamaan di kedua urutan; unique nomor urut di tingkat database; tanpa WO/P0 |
| Browser | Edge headless, `php -S :8010`, schema sementara `b32browser_*` (dihapus beserta foto uji) | **26/26**: kolom laporan patroli (ada temuan/nihil/belum ada laporan), detail temuan lengkap, foto termuat, tautan peta, laporan nihil, Audit Log, seluler tanpa scroll horizontal, 0 error console |
| Pint `--test` | file PHP B3.2 | Lulus |

## 7. Menunggu data resmi atau keputusan

- Kode temuan PASILA resmi (TBD-16); saat ini kategori kosong di production, sehingga temuan belum dapat dikirim sebelum Admin mengisi Master Data.
- Pengesahan referensi Section → Posko (DT-1); Posko tanpa Section terverifikasi belum dapat mencatat temuan.
- Roster petugas POSKO di production.
- Penutupan sesi PASILA dan aturan pemeriksaan WO APKUK saat penutupan (keputusan K-6 analisis B3).
- Koreksi atau pembatalan temuan oleh Admin (mis. kiriman keliru) belum ada; perlu aturan.
- Toleransi jam perangkat di batas shift (saat ini batas ketat `[mulai, selesai)` + 10 menit untuk masa depan).
- Aplikasi Flutter (outbox, kamera, GPS) belum ada; endpoint siap dipakai.

## 8. Risiko

| Risiko | Dampak | Langkah |
|---|---|---|
| Penyimpanan media Railway tidak persisten bila `MEDIA_DISK` belum S3 | Foto temuan dapat hilang setelah deploy | Tetapkan disk persisten sebelum operasional |
| Laporan nihil yang kemudian digantikan temuan | Pernyataan nihil keliru tetap ada sebagai riwayat | Ditampilkan jelas di Admin; aplikasi sebaiknya mencegah nihil bila outbox masih berisi temuan |
| Jam perangkat salah | Temuan sah ditolak bila `occurred_at` keluar dari jendela shift | Toleransi perlu keputusan; aplikasi memakai waktu jaringan bila tersedia |
| Deploy | Production belum memiliki migration Batch A, B1, B2, B3.1, B3.2 | Urutan A → B1 → B2 → B3.1 → B3.2 dengan backup |
