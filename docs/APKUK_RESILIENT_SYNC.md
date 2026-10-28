# Sinkronisasi APKUK Tahan Perubahan

Branch `fix/apkuk-resilient-sync` (dari `main` 8a30337), 28 Oktober 2026.

Tujuan: cron APKUK tidak lagi gagal hanya karena perubahan normal di SPS BOS (laporan dihapus, diedit, ditambah), tetapi tetap menolak pembacaan parsial, perubahan mencurigakan, duplikasi, dan kehilangan histori.

## 1. Diagnosis kejadian 28 Oktober 2026, 08.47 WIB

**Metode:** pembandingan READ ONLY pukul 09.19 WIB:

- APKUK_RAW dibaca lewat Google Sheets dengan scope read-only;
- Neon production dibaca dengan role runtime dalam transaksi `READ ONLY` yang di-rollback.

Log Railway tidak dapat dibaca dari mesin ini (tidak ada Railway CLI). Baris `SYNC_RESULT ... reason="78 identitas lama di PostgreSQL tidak ditemukan di sumber terbaru."` di log Railway menjadi konfirmasinya. Sumber bisa saja berubah lagi di antara 08.47 dan 09.19.

| Pemeriksaan | Hasil |
|---|---|
| Sync terakhir yang berhasil | 08.30 WIB (`max(last_synced_at)` 01:30:44 UTC); setelah itu tidak ada penulisan |
| Laporan di APKUK_RAW | 3.721; sebelumnya 3.799, turun 78 |
| Validasi sumber | Header benar, 0 kesalahan nilai, 0 kombinasi ID+tiang ganda, lolos minimum 2.800 |
| Identitas aktif di Neon yang tidak ada di sumber | **78** |
| Laporan baru / muncul kembali | 0 / 0 |
| Pasangan "hilang ↔ baru" dengan ID AppSheet sama (indikasi tiang diedit) | 0 |
| Kategori 78 laporan | 69 tiang masih punya laporan lain di sumber; 8 ID dan tiang tidak ada lagi; 1 ID masih dipakai laporan lain (A150) |
| Isi 78 laporan | Semua `kurang_bh = 0` (tidak ada kebutuhan pemasangan), sebagian besar `SELESAI`; tidak ada yang menjadi kandidat WO layak |
| Referensi WO ke 78 laporan | 0 |
| Laporan yang nomor baris sheet-nya bergeser | 2.325, artinya baris dihapus di tengah sheet, bukan terpotong di akhir |

**Kesimpulan:** penyebabnya adalah penghapusan laporan di SPS BOS antara 08.30 dan 08.47 WIB. Sebagian besar yang dihapus adalah laporan lama atau ganda pada tiang yang sudah punya laporan lebih baru.

Bukan penyebabnya:

- sumber yang terpotong;
- duplikasi;
- nomor tiang yang diedit.

Untuk A150, ID ini sebelumnya dipakai dua laporan berbeda tiang. Satu laporan dihapus. Laporan A150 yang tersisa memang laporan lain (tanggal, penyulang, dan tiangnya berbeda), jadi bukan hasil edit.

Ini dugaan kuat, bukan bukti bahwa penghapusannya sah. Delapan laporan yang ID dan tiangnya hilang sama sekali tetap perlu dikonfirmasi pemilik data SPS BOS.

**Dampak:** sejak 08.47 seluruh penulisan APKUK terblokir. Simulasi read-only kode baru terhadap data yang sama menemukan **25 perubahan isi laporan yang sah** (terutama perubahan status) yang tertahan. Akibatnya kandidat WO dan peta membaca data usang.

### Kelemahan desain lama

1. **Semua-atau-tidak-sama-sekali.** Satu identitas yang hilang membatalkan semua penulisan, termasuk laporan baru dan pembaruan. Setiap pembersihan data normal di SPS BOS membuat cron gagal berulang sampai ada yang menjalankan `apkuk:reconcile --apply`.
2. **Persetujuan hanya lewat CLI.** `--approved-by` adalah teks bebas. Kewenangannya hanya berasal dari akses ke kredensial database, bukan akun yang terautentikasi.
3. **Identitas `legacy_key` = sha256(ID AppSheet + nomor tiang ternormalisasi).**
   - Bila nomor tiang diedit, identitas lama hilang dan muncul identitas baru.
   - ID AppSheet bisa dipakai lebih dari satu laporan (A150), jadi ID saja juga tidak stabil.
   - Kode lama tidak membedakan edit tiang dari laporan baru.
4. **`MIN_EXPECTED_ROWS = 2800` bersifat absolut.** Dengan 3.799 laporan, sumber yang terpotong sampai 999 laporan (26%) masih lolos. Selama ini hanya penjaga "identitas hilang" yang menutupi celah ini, sehingga penjaga itu tidak bisa dilonggarkan tanpa pembanding relatif.
5. **Tidak ada pembanding.** Riwayat eksekusi dan snapshot valid terakhir tidak disimpan.
6. **Upsert semua baris setiap 15 menit tanpa pemeriksaan.** Perubahan isi massal yang mencurigakan, misalnya rumus rusak yang mengosongkan status ribuan laporan, akan langsung menimpa data yang baik.

## 2. Aturan bisnis SPS BOS dan kesesuaiannya

SPS BOS adalah sumber **live**. Pemilik data sering menambah, mengedit, memindahkan, dan menghapus laporan. Laporan yang hilang dari SPS BOS punya dua kemungkinan:

1. memang sudah tidak berlaku dan perlu diarsipkan;
2. hanya hilang sementara (sedang diedit atau dipindahkan) dan nanti muncul kembali.

Karena itu, laporan yang hilang **tidak pernah** diarsipkan otomatis, baik berdasarkan jumlah siklus cron maupun lamanya hilang.

| Aturan | Implementasi | Bukti uji |
|---|---|---|
| Perubahan normal tersinkron tanpa menggagalkan cron | Snapshot valid diterapkan. Laporan hilang hanya masuk antrean; status cron `SUCCESS_WITH_PENDING_RECONCILIATION` (exit 0) | `test_deleted_reports_are_queued_not_removed_while_new_reports_still_sync`, uji asap PG (pola 08.47) |
| Laporan hilang tetap pending sampai keputusan sah atau muncul kembali | Antrean `MISSING` tetap `OPEN`. Tidak ada pengarsipan otomatis berbasis siklus atau waktu | `test_pending_report_is_never_archived_automatically_however_long_it_is_missing` (6 siklus, 72 hari) |
| Pending tidak dihitung aktif untuk penugasan, dashboard, kandidat WO, analitik | Definisi tunggal `ApkukSourceState` (aktif / pending / histori). Kandidat WO memberi alasan `MENUNGGU_REKONSILIASI_SUMBER`. Pembuatan WO menunggu sync yang menulis. Peta tidak menghitung pending sebagai aktif dan menampilkannya dengan warna terpisah. Halaman PR #10 perlu patch integrasi kecil (bagian 5) | Tes kandidat, peta, scope, `ApkukWorkOrderService`, tes integrasi pada simulasi merge |
| WO, relasi, dan histori utuh | WO dan target tidak pernah diubah. Konfirmasi identitas mempertahankan ID baris. FK `restrict`; tidak ada hard-delete; model `Apkuk` hanya-baca | `test_work_orders_are_never_modified_...`, `test_pole_change_is_held_...` |
| Muncul kembali dipulihkan otomatis tanpa duplikasi | Dicocokkan lewat `legacy_key`. Antrean pending ditutup `RETURNED`; histori dipulihkan dan ID-nya dicatat di `apkuk_sync_runs.details.returned_apkuk_ids` | `test_reports_that_reappear_...`, tes 72 hari |
| Pengarsipan permanen oleh Admin terautentikasi atas konfirmasi pemilik data, audit lengkap | Web Admin: akun Admin aktif yang login, alasan wajib, **konfirmasi pemilik data wajib** (`owner_confirmation`), per laporan atau massal. CLI `apkuk:reconcile --apply` wajib `--admin` dengan kata sandi interaktif dan tercatat di `approved_by_user_id` | `test_resolution_requires_...`, `test_admin_can_confirm_many_...`, `test_apply_requires_an_authenticated_active_admin` |
| Pengaman sumber tidak lengkap, perubahan massal, galat database | Validasi sumber, minimum 2.800, pengaman relatif terhadap snapshot valid terakhir, transaksi + advisory lock, rollback penuh | Tes terpotong, ratusan hilang, perubahan isi massal, trigger galat DB, lock timeout PG |

### Evaluasi batas pengaman untuk SPS BOS yang sering berubah

Batas yang ada **tetap dipertahankan**. Pertimbangannya:

- **Hanya laporan yang baru hilang yang dihitung.** Laporan yang sudah pending tidak dihitung lagi di eksekusi berikutnya. Penghapusan bertahap sepanjang hari (puluhan per 15 menit) selalu lolos. Kejadian 08.47 (78 laporan dalam sekitar 17 menit, 2% dari 3.799) berada jauh di bawah batas 150 dan penurunan 5% (190).
- **Penolakan tidak merusak data.** Sumber yang hilang sementara dalam jumlah besar (dipindah ke tab lain, IMPORTRANGE belum lengkap) ditolak tanpa penulisan. Begitu sumber pulih, eksekusi berikutnya berjalan normal tanpa antrean palsu.
- **Penghapusan besar yang sah memang langka dan perlu pemeriksaan manusia.** Contohnya 687 laporan pada 27 Oktober. Operator menjalankan `apkuk:sync --dry-run`, lalu satu eksekusi manual `--max-missing=N`. Laporan tetap hanya masuk antrean; pengarsipan tetap keputusan Admin.
- **Pengaman perubahan isi tetap ketat**, max(100, 10%) per eksekusi, karena hanya kegagalan inilah yang benar-benar menimpa data baik (misalnya rumus rusak mengosongkan status). Laju normal: pagi ini 25 perubahan dalam sekitar 1,5 jam.
- **Pengaman pertambahan max(500, 25%)** masih menampung sekitar 45 laporan baru per hari bahkan setelah cron mati sekitar 3 minggu.
- **Yang tidak ditambahkan:**
  - Heuristik "baris hilang di ekor sheet = terpotong" tidak ditambahkan, karena laporan terbaru yang salah input sering langsung dihapus pemiliknya, sehingga heuristik ini akan sering salah menggagalkan cron.
  - Pencocokan identitas berdasarkan tiang saja juga tidak ditambahkan, karena 69 dari 78 laporan yang dihapus pagi ini memang laporan lama pada tiang yang masih punya laporan lain.

Semua batas dapat diubah lewat env (`config/apkuk.php`) tanpa mengubah kode. Tidak ada yang perlu diubah di Railway.

## 3. Desain

### Alur `apkuk:sync`

1. Eksekusi dicatat di `apkuk_sync_runs` dengan status `RUNNING`. Dry-run tidak dicatat dan tidak menulis apa pun.
2. Sumber dibaca dan divalidasi:
   - header;
   - nilai;
   - kombinasi ID+tiang ganda;
   - minimum 2.800 laporan.

   Bila gagal, status menjadi `FAILED` tanpa penulisan APKUK.
3. Satu transaksi:
   - ambil advisory lock APKUK eksklusif, lalu `LOCK apkuks`;
   - hitung rencana setelah kunci didapat;
   - periksa pengaman;
   - tulis;
   - perbarui run.

   Pelanggaran pengaman atau galat database membuat seluruh transaksi di-rollback.
4. Status akhir dicatat di `apkuk_sync_runs`, baris `SYNC_RESULT`, dan exit code.

### Klasifikasi laporan

| Kelompok | Perlakuan |
|---|---|
| Baru | Disisipkan |
| Diperbarui (isi berubah) | Diperbarui |
| Pindah baris sheet saja | `source_row_number` diperbarui |
| Muncul kembali dari histori | Dipulihkan (`source_missing_at = null`). ID tercatat di `apkuk_sync_runs.details` dan log warning |
| Muncul kembali dari pending | Antrean ditutup `RETURNED` |
| Tidak berubah | Hanya `last_synced_at` yang diperbarui ("terakhir terlihat di sumber") |
| Hilang dari sumber | Antrean `MISSING`: **pending**. Tidak dihapus dan tidak diarsipkan; tidak dihitung aktif; tidak bisa menjadi target WO baru |
| Identitas baru yang ID AppSheet-nya sama dengan laporan yang hilang (kemungkinan tiang diedit) | **Ditahan** di antrean `IDENTITY_CHANGE`, tidak disisipkan, tidak ditebak |

### Status sumber (definisi tunggal `App\Services\Apkuk\ApkukSourceState`)

| Status | Definisi | Query builder | Eloquent |
|---|---|---|---|
| Aktif | Ada di snapshot terakhir | `ApkukSourceState::active(DB::table('apkuks'))` | `Apkuk::sourceActive()` |
| Pending | Tidak ada di snapshot terakhir, belum diputuskan | `ApkukSourceState::pending(...)` | `Apkuk::awaitingReconciliation()` |
| Histori | Diarsipkan (`source_missing_at`) | `ApkukSourceState::history(...)` | `Apkuk::sourceHistory()` |

- **Kandidat WO:** pending tidak bisa dipilih, tetapi tetap ikut menentukan laporan terbaru per tiang, supaya laporan lama di tiang yang sama tidak mendadak layak.
- **Peta:** pending tampil merah muda bertanda "menunggu rekonsiliasi". `meta.apkuk_active_total` dan `meta.apkuk_mapped` hanya menghitung yang aktif; pending dihitung terpisah di `meta.apkuk_pending_reconciliation` dan `meta.apkuk_pending_mapped`.

### Status cron

| Status | Exit | Arti |
|---|---|---|
| `SUCCESS` | 0 | Snapshot diterapkan, tanpa antrean |
| `SUCCESS_WITH_PENDING_RECONCILIATION` | 0 | Snapshot diterapkan; ada pending. Bukan kegagalan teknis |
| `FAILED` | 1 | Tahap `SOURCE_READ`, `SOURCE_VALIDATION`, `SNAPSHOT_GUARD`, atau `DATABASE` (termasuk lock timeout). Tidak ada penulisan APKUK |

`railway-sync.sh` menulis `SYNC_SUMMARY status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 ...` bila ada pending. `FAILED` pada command mana pun tetap menghasilkan exit 1. Perilaku `gangguan:sync` dan `keypoint:sync` tidak berubah.

### Pengaman snapshot

Default ada di `config/apkuk.php`. Pembandingnya adalah snapshot valid terakhir.

| Pengaman | Default | Produksi saat ini (pembanding 3.799) |
|---|---|---|
| Penurunan jumlah laporan | 5% | 190 |
| Identitas aktif yang baru hilang per eksekusi | min(150, 5%) | 150 |
| Baru + muncul kembali per eksekusi | max(500, 25%) | 950 |
| Berubah isi per eksekusi | max(100, 10%) | 373 |
| Minimum absolut | 2.800 | tetap |

Opsi manual `--max-missing`, `--max-new`, dan `--max-updated` hanya untuk satu eksekusi, dan tercatat di `apkuk_sync_runs.guard`.

### Konkurensi dan idempotensi

- **Kunci eksklusif bersama.** `apkuk:sync`, `apkuk:reconcile --apply`, dan keputusan Admin memakai advisory lock eksklusif yang sama, dengan `lock_timeout` 10 detik. Bila kunci tidak didapat, prosesnya gagal bersih tanpa menulis ganda.
- **Kunci bersama untuk WO.** Pembuatan WO APKUK memakai kunci bersama, sehingga menunggu sync yang sedang menulis.
- **Tanpa antrean ganda.** `open_key` unik selama `OPEN`. Eksekusi berulang dengan sumber yang sama tidak mengubah isi dan tidak membuat antrean ganda; yang bertambah hanya `detection_count`.

### Keputusan Admin

| Keputusan | Jalur | Wajib | Efek |
|---|---|---|---|
| Arsipkan (`CONFIRMED_REMOVED`) | Web Admin, per laporan atau massal (semua-atau-tidak-sama-sekali, maks. 500) | Admin aktif yang login, alasan, **konfirmasi pemilik data**, centang bila ada WO aktif | `source_missing_at` diisi; WO tidak diubah; pulih otomatis bila muncul kembali |
| Laporan sama, identitas diperbarui (`IDENTITY_CONFIRMED`) | Web Admin | Admin aktif, alasan, pilihan laporan lama dari kandidat | ID baris tetap, sehingga WO tetap tersambung; identitas lama tercatat di `resolution_details.previous` |
| Laporan berbeda (`INSERTED_AS_NEW`) | Web Admin | Admin aktif, alasan | Baris yang ditahan disisipkan; laporan lama tetap pending |
| Arsip massal lewat rencana (`RECONCILED`) | `apkuk:reconcile --apply` | `--admin` dengan **kata sandi yang diminta interaktif** (tidak lewat argumen atau env), `--approved-by` = konfirmasi pemilik data, `--reason`, rencana dan sidik yang cocok | Akun Admin dicatat di `apkuk_reconciliations.approved_by_user_id` dan di setiap item antrean |

Setiap item menyimpan `resolved_by_user_id`, `resolution_reason`, `owner_confirmation`, `resolved_at`, dan `resolution_details` (misalnya kode WO yang merujuk, konfirmasi WO aktif, dan ukuran batch). Item tidak pernah dihapus.

`apkuk:pending [--export=file.csv]` bersifat read-only, untuk ditinjau bersama pemilik data.

## 4. Database

Kedua migration bersifat additive dan belum dijalankan di lingkungan mana pun selain tes lokal.

- **`2026_10_28_100001_create_apkuk_sync_runs_and_reconciliation_items`**
  - `apkuk_sync_runs`: status, tahap gagal, alasan, snapshot diterima, jumlah laporan, sidik isi dan identitas, `counts`, `guard`, `details`, `database_user`, waktu.
  - `apkuk_reconciliation_items`: jenis, status, `open_key` unik, `apkuk_id` (FK restrict), identitas, baris sumber yang ditahan, kandidat, run, `resolved_by_user_id` (FK users restrict), `owner_confirmation`, `reconciliation_id`, alasan, rincian.
- **`2026_10_28_100002_add_approved_by_user_id_to_apkuk_reconciliations`**: menambah kolom nullable `approved_by_user_id` (FK users restrict) pada tabel yang sudah ada. Baris lama tidak diubah.

`down()` hanya menghapus kolom atau tabel baru. Tidak ada hard-delete di mana pun.

## 5. Integrasi dengan PR #10 (Web Admin Batch A)

**Simulasi merge lokal.** Branch sementara dari `origin/feat/admin-web-foundation-v1` di-merge dengan branch ini. Branch itu tidak di-push dan PR #10 tidak diubah.

- **Tanpa konflik git.**
- **Seluruh tes gabungan lulus:** 257/257.

**Penyesuaian yang dibutuhkan setelah keduanya di-merge.** Tanpa penyesuaian ini, halaman PR #10 masih menghitung pending sebagai aktif lewat `whereNull('source_missing_at')`.

| File PR #10 | Penyesuaian |
|---|---|
| `app/Services/DashboardService.php` + `dashboard.blade.php` | `active_reports` memakai `ApkukSourceState::active`; tambah `pending_reports` di hint kartu |
| `app/Http/Controllers/Admin/ApkukController.php` + `apkuk/index.blade.php` | Tab baru "Menunggu rekonsiliasi"; kartu pending (tautan ke antrean); aktif/histori memakai `ApkukSourceState` |
| `app/Http/Controllers/Admin/AnalyticsController.php` | Distribusi status APKUK hanya laporan aktif |
| `app/Http/Controllers/Admin/MasterDataController.php` | Jumlah APKUK per penyulang hanya laporan aktif |
| `app/Support/AdminNavigation.php` + `AdminModuleAccessTest` | Menu "Rekonsiliasi APKUK" (jumlah menu 16 → 17) |
| `routes/web.php`, `app/Services/ApkukWorkOrderService.php` | Nama kelas lengkap diganti `use` (sengaja ditunda agar PR #10 tidak bentrok) |
| `tests/Feature/Operations/ApkukPendingIntegrationTest.php` (baru) | Pending tidak tampil/terhitung aktif di halaman APKUK, dashboard, analitik; tampil di tab menunggu dan antrean |

Patch ini sudah diterapkan dan diuji pada hasil gabungan: **258/258 tes lulus**. Patch disimpan terpisah, tidak di-commit ke PR mana pun, dan diterapkan sebagai tindak lanjut setelah PR #10 dan PR #11 sama-sama masuk `main`.

**Status 28 Okt (setelah PR #11 merge):** penyesuaian di atas sudah diterapkan langsung pada branch PR #10 (`feat/admin-web-foundation-v1`) setelah `main` di-merge ke branch tersebut, ditambah kartu dashboard tersendiri untuk pending, pencatatan keputusan antrean ke Audit Log umum, dan perbaikan skrip layout agar tombol keputusan terkirim. Rinciannya di `docs/WEB_ADMIN_DATA_FIELD_AUDIT.md` bagian 12.

## 6. Rencana migration dan deployment yang aman

Belum ada yang dijalankan di production. Urutan migration sebelum merge itu wajib: kode baru menolak sync (`FAILED`, tanpa tulis) bila tabel belum ada, sedangkan kode lama tidak terpengaruh oleh tabel dan kolom baru.

1. **Review dan persetujuan PR #11.** Buat backup branch Neon baru, misalnya `backup-before-apkuk-resilient-sync`. Backup lama jangan dihapus.
2. **Cek status migration** (owner role, dari checkout branch ini):

   ```
   php artisan migrate:status --env=neon-migrate
   ```

   Migration Batch A yang mungkin Pending jangan ikut dijalankan.
3. **Jalankan hanya dua file ini:**

   ```
   php artisan migrate --env=neon-migrate --force --path=database/migrations/2026_10_28_100001_create_apkuk_sync_runs_and_reconciliation_items.php --path=database/migrations/2026_10_28_100002_add_approved_by_user_id_to_apkuk_reconciliations.php
   ```

4. **Hak role runtime** (read-only): `INSERT` dan `UPDATE` pada kedua tabel baru, `USAGE` pada sequence-nya, dan `UPDATE` pada `apkuk_reconciliations`. Pada 28 Okt sudah dicek read-only bahwa default privileges role owner memberi role runtime `arwd` pada tabel dan `rU` pada sequence baru, sehingga tidak perlu GRANT manual.
5. **Dry-run read-only** (role runtime):

   ```
   php artisan apkuk:sync --dry-run --env=neon
   ```

   Perkiraan pada 10.03 WIB: baru 0, diperbarui 25, pindah baris 2.300, hilang 78, antrean 78, tanpa pelanggaran.
6. **Merge PR #11.** Railway men-deploy web dan cron. `RAILPACK_SKIP_MIGRATIONS=true`, jadi tidak ada migration otomatis. Tidak ada variabel Railway yang perlu diubah.
7. **Bila PR #10 di-merge setelahnya**, terapkan patch integrasi di bagian 5 sebagai PR kecil terpisah.

**Rollback:** revert merge commit. Tabel dan kolom baru aman dibiarkan. Rollback migration hanya bila benar-benar perlu, karena riwayat run dan antrean ikut hilang.

## 7. Verifikasi production setelah perubahan disetujui

1. **Cron pertama:** `apkuk:sync` menghasilkan `SYNC_RESULT ... status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 ... pending=78 pending_new=78`, dan `SYNC_SUMMARY ... exit=0`. Eksekusi tidak lagi Crashed.
2. **SQL read-only:**
   - `select status, snapshot_accepted, source_report_count, counts, details from apkuk_sync_runs order by id desc limit 3;`
   - `select kind, status, count(*) from apkuk_reconciliation_items group by 1, 2;` → `MISSING/OPEN = 78`.
   - `select count(*), count(*) filter (where source_missing_at is not null) from apkuks;` → total 4.515 ditambah laporan baru bila ada; diarsipkan tetap **716**, karena tidak ada pengarsipan otomatis.
   - `select legacy_key from apkuks group by 1 having count(*) > 1;` → 0 baris.
   - Jumlah `work_orders` dan `work_order_apkuk_targets` tidak berubah.
3. **Cron kedua:** `new=0 updated=0 pending=78 pending_new=0` (idempoten).
4. **Web Admin:**
   - `/admin/apkuk-rekonsiliasi` menampilkan 78 pending;
   - peta menampilkan pending merah muda dan tidak menghitungnya aktif;
   - pending tidak bisa dipilih untuk WO baru.
5. **Keputusan arsip.** Pemilik data SPS BOS meninjau ekspor `php artisan apkuk:pending --export=<file di luar repo>`. Bagian 1 (diagnosis) mencatat 8 laporan yang ID dan tiangnya hilang sama sekali; laporan ini perlu dicek khusus. Admin mengarsipkan di Web Admin dengan mengisi konfirmasi pemilik data, per laporan atau massal.

   Sesudahnya: `source_missing_at` bertambah sesuai jumlah yang diarsipkan; `resolved_by_user_id` dan `owner_confirmation` terisi; laporan yang belum dikonfirmasi tetap pending.
6. **Laporan yang kembali.** Bila pemilik data memulihkan laporan di SPS BOS, cron berikutnya menutupnya (`RETURNED`) atau memulihkan histori (`details.returned_apkuk_ids`) tanpa baris ganda.

## 8. Pengujian

- **`php artisan test` di branch ini:** 197 tes lulus.
  - `ApkukResilientSyncTest` (22 tes): tambah, ubah, hapus, ganti tiang, ID ganda, muncul kembali, terpotong/kosong/kolom bergeser, ratusan hilang, perubahan isi massal, cron berulang, galat DB, WO, otorisasi dan audit, konfirmasi pemilik data, massal, reconcile, pending 72 hari tidak diarsipkan, status sumber, halaman Admin/peta/`apkuk:pending`, migration.
  - `ApkukReconciliationTest`: termasuk autentikasi Admin untuk apply (tanpa `--admin`, kata sandi salah, akun tidak ada/nonaktif/bukan ADMIN, sesi tidak interaktif).
  - `SyncCommandResultTest`, `RailwaySyncScriptTest`.
  - Tes `gangguan:sync` dan `keypoint:sync` tidak diubah dan tetap lulus.
- **Uji asap PostgreSQL 18 lokal** di schema sementara (dihapus setelahnya), 26 cek lulus:
  - migration (termasuk FK baru pada tabel lama);
  - idempotensi numerik;
  - pola 08.47;
  - advisory lock dan lock timeout (sync dan pembuatan WO);
  - rollback karena trigger;
  - penolakan massal;
  - keputusan Admin dengan konfirmasi pemilik data;
  - pemulihan dan audit `details`;
  - status sumber `whereNotExists`;
  - reconcile apply oleh Admin terautentikasi dan penolakan akun yang tidak ada.
- **Simulasi merge dengan PR #10:** 257/257 tes; dengan patch integrasi 258/258.
- **Simulasi read-only kode baru terhadap Neon** (28 Okt 10.03 WIB): `SUCCESS_WITH_PENDING_RECONCILIATION`, 78 pending, 25 pembaruan, tanpa pelanggaran pengaman.
