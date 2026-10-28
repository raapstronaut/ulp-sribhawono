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

## 2. Desain baru

### Alur `apkuk:sync`

1. Eksekusi dicatat di `apkuk_sync_runs` dengan status `RUNNING`. Dry-run tidak dicatat dan tidak menulis apa pun.
2. Sumber dibaca dan divalidasi:
   - header;
   - nilai;
   - kombinasi ID+tiang ganda;
   - minimum 2.800 laporan.

   Bila gagal, status menjadi `FAILED` dan tidak ada data APKUK yang ditulis.
3. Satu transaksi:
   - ambil advisory lock APKUK eksklusif, lalu `LOCK apkuks`;
   - hitung rencana setelah kunci didapat;
   - periksa pengaman snapshot;
   - tulis;
   - perbarui run.

   Pelanggaran pengaman atau galat database membuat seluruh transaksi di-rollback.
4. Status akhir dicatat di `apkuk_sync_runs`, baris `SYNC_RESULT`, dan exit code.

### Klasifikasi laporan

| Kelompok | Perlakuan |
|---|---|
| Baru | Disisipkan |
| Diperbarui (isi berubah) | Diperbarui. Kolom yang dibandingkan: ID, tanggal, petugas, penyulang, section, tiang, naik dari, jumlah, status, koordinat, foto |
| Pindah baris sheet saja | `source_row_number` diperbarui |
| Muncul kembali (sebelumnya `source_missing_at`) | Dipulihkan (`source_missing_at = null`) |
| Tidak berubah | Hanya `last_synced_at` yang diperbarui, maknanya "terakhir terlihat di sumber" |
| Hilang dari sumber | Masuk antrean `MISSING`. **Tidak dihapus dan tidak diberi `source_missing_at`**. Tetap tersimpan, tetapi tidak boleh menjadi target WO baru |
| Identitas baru yang ID AppSheet-nya sama dengan laporan yang hilang | **Ditahan**, tidak disisipkan. Masuk antrean `IDENTITY_CHANGE` dengan laporan lama sebagai kandidat. Pasangan 1:1 dicatat sebagai dugaan; bila ID dipakai beberapa laporan, semua laporan hilang ber-ID sama menjadi kandidat tanpa ditebak |

Laporan baru dan pembaruan tetap ditulis walaupun ada laporan lain di antrean.

### Pengaman snapshot

Default ada di `config/apkuk.php` (env opsional; Railway tidak perlu diubah). Pembanding adalah jumlah laporan snapshot valid terakhir. Sebelum ada riwayat, pembandingnya laporan aktif di `apkuks`.

| Pengaman | Batas default | Untuk produksi saat ini (pembanding 3.799) |
|---|---|---|
| Penurunan jumlah laporan | 5% | 190 |
| Identitas aktif yang baru hilang per eksekusi | min(150, 5% aktif) | 150 |
| Laporan baru + muncul kembali per eksekusi | max(500, 25%) | 950 |
| Laporan berubah isi per eksekusi | max(100, 10% laporan cocok) | 373 |
| Minimum absolut | 2.800 laporan | tetap |

Kejadian 08.47 (78 hilang, 25 berubah) berada di bawah semua batas. Hasilnya `SUCCESS_WITH_PENDING_RECONCILIATION`, bukan kegagalan.

Penghapusan massal yang memang disetujui (seperti 687 laporan pada 27 Oktober) tetap ditolak cron. Operator menjalankannya manual sekali setelah meninjau dry-run:

```
php artisan apkuk:sync --dry-run
php artisan apkuk:sync --max-missing=N
```

Laporan yang hilang dalam eksekusi itu pun hanya masuk antrean. Opsi yang dipakai tercatat di `apkuk_sync_runs.guard`.

### Status cron

| Status | Exit | Arti |
|---|---|---|
| `SUCCESS` | 0 | Snapshot diterapkan, tidak ada antrean |
| `SUCCESS_WITH_PENDING_RECONCILIATION` | 0 | Snapshot diterapkan; ada laporan yang menunggu keputusan. Ini bukan kegagalan teknis |
| `FAILED` | 1 | Sumber gagal dibaca atau tidak valid (`SOURCE_READ`, `SOURCE_VALIDATION`), snapshot mencurigakan (`SNAPSHOT_GUARD`), atau database gagal termasuk lock timeout (`DATABASE`). Tidak ada data APKUK yang ditulis |

Contoh log:

```
SYNC_RESULT command=apkuk:sync status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 duration=0.8s run=12 new=0 updated=25 returned=0 pending=78 pending_new=78 pending_resolved=0 identity_changes=0
SYNC_SUMMARY status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 gangguan:sync=OK apkuk:sync=SUCCESS_WITH_PENDING_RECONCILIATION keypoint:sync=OK
```

`railway-sync.sh` tetap menjalankan ketiga command. Exit code `gangguan:sync` dan `keypoint:sync` tidak berubah. `FAILED` pada command mana pun tetap membuat eksekusi Crashed (exit 1).

### Konkurensi dan idempotensi

- Penulis APKUK memakai advisory lock PostgreSQL eksklusif yang sama, dengan `lock_timeout` 10 detik:
  - `apkuk:sync`;
  - `apkuk:reconcile --apply`;
  - keputusan Admin.

  Cron yang tumpang tindih menunggu, atau gagal bersih dengan pesan "Kunci data APKUK dipegang proses lain"; tidak pernah menulis ganda.
- Pembuatan WO APKUK memakai kunci bersama. WO baru menunggu sync yang sedang menulis, sehingga laporan yang baru masuk antrean tidak lolos sebagai target.
- Antrean memakai `open_key` unik selama `OPEN`. Eksekusi berulang dengan sumber yang sama tidak mengubah isi `apkuks`, tidak membuat antrean ganda, dan hanya menambah `detection_count`.

### Antrean rekonsiliasi (`apkuk_reconciliation_items`)

| Penyelesaian | Oleh | Efek |
|---|---|---|
| `RETURNED` | otomatis (sync) | Identitas muncul kembali di sumber |
| `SUPERSEDED` | otomatis (sync) | Kondisi sumber berubah, misalnya identitas baru hilang lagi atau laporan lama kembali |
| `CONFIRMED_REMOVED` | Admin | `source_missing_at` diisi; baris dan WO tetap utuh |
| `IDENTITY_CONFIRMED` | Admin | Laporan lama mengambil identitas dan isi baru. **ID baris tidak berubah**, jadi semua WO tetap tersambung. Identitas lama tercatat di `resolution_details.previous` |
| `INSERTED_AS_NEW` | Admin | Baris yang ditahan disimpan sebagai laporan baru; laporan lama tetap menunggu keputusan sendiri |
| `RECONCILED` | `apkuk:reconcile --apply` | Ditutup bersama rencana rekonsiliasi yang disetujui |

`legacy_key` tidak pernah diganti secara massal. Penggantian hanya terjadi per laporan, atas konfirmasi Admin, dengan jejak nilai lama.

### Keputusan Admin (autentikasi sungguhan)

- Web Admin → `/admin/apkuk-rekonsiliasi`:
  - daftar antrean dengan referensi WO;
  - riwayat 10 eksekusi sync terakhir;
  - 20 penyelesaian terakhir;
  - keputusan per laporan;
  - konfirmasi massal untuk laporan hilang.
- Konfirmasi massal bersifat semua-atau-tidak-sama-sekali, satu alasan untuk semua, dan setiap item tetap dicatat sendiri.
- Hanya akun ADMIN aktif yang login. Middleware memeriksanya dan layanan memeriksanya sekali lagi.
- Setiap keputusan menyimpan `resolved_by_user_id`, alasan wajib, waktu, dan rincian, misalnya kode WO yang merujuk dan konfirmasi WO aktif.
- Laporan yang dirujuk WO aktif hanya bisa dikonfirmasi bila kotak konfirmasi dicentang. WO tidak pernah diubah.
- Tidak ada penyelesaian lewat CLI dengan nama yang diketik.
- `apkuk:pending [--export=file.csv]` hanya menampilkan dan mengekspor (read-only).
- `apkuk:reconcile` tetap ada untuk penandaan massal dengan rencana yang disetujui. Antrean terkait ikut ditutup otomatis.

Halaman Antrean ditautkan dari catatan data di Peta Jaringan. Tautan sidebar belum ada agar tidak bentrok dengan PR #10 (lihat bagian 4).

### Konsistensi status data

| Status | Definisi | Scope `Apkuk` |
|---|---|---|
| Aktif | Ada di snapshot terakhir | `sourceActive()` |
| Menunggu rekonsiliasi | Tidak ada di snapshot terakhir, belum diputuskan | `awaitingReconciliation()` |
| Histori | `source_missing_at` terisi | `sourceHistory()` |

- **Kandidat WO.** Laporan yang menunggu mendapat alasan `MENUNGGU_REKONSILIASI_SUMBER` sehingga tidak dapat dipilih. Laporan itu tetap ikut menentukan laporan terbaru per tiang, supaya laporan lama di tiang yang sama tidak mendadak layak.
- **Peta.** Laporan yang menunggu tetap tampil dengan tanda "menunggu rekonsiliasi", dan jumlahnya ada di `meta.apkuk_pending_reconciliation`.
- **Sidik `apkuk:reconcile`.** Sidik database mengabaikan `last_synced_at`, sehingga rencana yang disetujui tidak batal hanya karena cron rutin.

## 3. Database

Migration additive `2026_10_28_100001_create_apkuk_sync_runs_and_reconciliation_items`:

- `apkuk_sync_runs`: status, tahap gagal, alasan, snapshot diterima, jumlah laporan, sidik isi dan identitas, `counts`, `guard`, `database_user`, waktu.
- `apkuk_reconciliation_items`: jenis, status, `open_key` unik, `apkuk_id` (FK restrict), identitas, baris sumber yang ditahan, kandidat, run pertama/terakhir/penyelesai, `resolved_by_user_id` (FK users, restrict), `reconciliation_id`, alasan, rincian.

Tabel yang sudah ada tidak diubah. `down()` hanya menghapus dua tabel baru. Tidak ada hard-delete di mana pun.

## 4. Integrasi dengan PR #10 (Web Admin Batch A, masih Open)

Perubahan dibuat supaya tidak bentrok dengan PR #10:

- route berada di blok terpisah setelah route P0;
- `ApkukWorkOrderService` dan `routes/web.php` memakai nama kelas lengkap, bukan menambah baris `use` di blok import yang juga diubah PR #10;
- `ApkukCandidateService`, `MapDataController`, dan halaman peta tidak diubah oleh PR #10.

Setelah keduanya di-merge, tindak lanjut kecil:

1. Tambahkan tautan sidebar "Rekonsiliasi APKUK".
2. Halaman `/admin/apkuk`, `DashboardService`, `AnalyticsController`, dan `MasterDataController` dari PR #10 menghitung aktif sebagai `whereNull('source_missing_at')`; ini termasuk laporan yang menunggu. Ganti dengan scope di atas dan tampilkan jumlah menunggu. "Sinkron terakhir" dapat membaca `apkuk_sync_runs`.
3. Ganti nama kelas lengkap dengan `use` biasa.

Migration Batch A (`2026_10_28_000001/000002`) dan migration ini tidak saling bergantung.

## 5. Rencana migration dan deployment yang aman

Belum ada yang dijalankan di production. Urutan migration sebelum deploy itu wajib: kode baru menolak sync (`FAILED`, tanpa tulis) bila tabel belum ada. Sebaliknya, kode lama tidak terpengaruh oleh tabel baru.

1. **Review dan persetujuan PR.** Buat backup branch Neon baru, misalnya `backup-before-apkuk-resilient-sync`. Backup lama jangan dihapus.
2. **Status migration** (owner role, dari checkout branch ini):

   ```
   php artisan migrate:status --env=neon-migrate
   ```

   Hanya `2026_10_28_100001` yang boleh dijalankan untuk perubahan ini. Migration Batch A yang mungkin juga Pending jangan ikut.
3. **Migration satu file saja:**

   ```
   php artisan migrate --env=neon-migrate --force --path=database/migrations/2026_10_28_100001_create_apkuk_sync_runs_and_reconciliation_items.php
   ```

4. **Hak role runtime** (read-only):

   ```
   has_table_privilege(<runtime>, 'apkuk_sync_runs', 'INSERT')
   ```

   Periksa juga `UPDATE` untuk kedua tabel dan `USAGE` untuk kedua sequence. Pada 28 Okt sudah dicek read-only bahwa default privileges role owner memberi role runtime `arwd` pada tabel dan `rU` pada sequence baru, sehingga tidak perlu GRANT manual.
5. **Dry-run read-only** (role runtime, dari checkout branch ini):

   ```
   php artisan apkuk:sync --dry-run --env=neon
   ```

   Perkiraan pada 10.03 WIB: baru 0, diperbarui 25, pindah baris 2.300, hilang 78, antrean 78, tanpa pelanggaran. Angkanya mengikuti perubahan sumber.
6. **Merge PR.** Railway men-deploy web dan cron. `RAILPACK_SKIP_MIGRATIONS=true`, jadi tidak ada migration otomatis. Tidak ada variabel Railway yang perlu diubah.

**Rollback:** revert merge commit untuk kembali ke kode lama. Tabel baru boleh tetap ada dan aman bagi kode lama. Rollback migration hanya bila benar-benar perlu, karena riwayat run dan antrean ikut hilang:

```
migrate:rollback --path=<file di atas>
```

Dengan kode lama, cron akan kembali gagal pada 78 identitas tersebut.

## 6. Verifikasi production setelah perubahan disetujui

1. **Cron pertama** di log Railway:
   - `apkuk:sync` menghasilkan `SYNC_RESULT ... status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 ... pending=78 pending_new=78`;
   - `SYNC_SUMMARY status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 gangguan:sync=OK ... keypoint:sync=OK`;
   - eksekusi tidak lagi Crashed.
2. **SQL read-only:**
   - `select status, snapshot_accepted, source_report_count, counts from apkuk_sync_runs order by id desc limit 3;`
   - `select kind, status, count(*) from apkuk_reconciliation_items group by 1, 2;` → `MISSING/OPEN = 78`.
   - `select count(*), count(*) filter (where source_missing_at is not null) from apkuks;` → total tetap 4.515 ditambah laporan baru bila ada; bertanda tetap **716**, karena tidak ada penandaan otomatis.
   - `select legacy_key from apkuks group by 1 having count(*) > 1;` → 0 baris.
   - Jumlah `work_orders` dan `work_order_apkuk_targets` tidak berubah.
3. **Cron kedua:** `new=0 updated=0 pending=78 pending_new=0` (idempoten).
4. **Web Admin:**
   - `/admin/apkuk-rekonsiliasi` menampilkan 78 antrean;
   - peta menandai laporan yang menunggu;
   - laporan yang menunggu tidak bisa dipilih untuk WO baru.
5. **Keputusan.** Pemilik data SPS BOS meninjau daftar dari `php artisan apkuk:pending --export=<file di luar repo>`. Admin lalu mengonfirmasi di Web Admin, per laporan atau massal.

   Sesudahnya: `source_missing_at` bertambah 78, antrean `CONFIRMED_REMOVED` tercatat atas akun Admin, dan cron berikutnya `SUCCESS`.

## 7. Pengujian

- **`tests/Feature/Sync/ApkukResilientSyncTest.php`** (baru, 20 tes):
  - penambahan dan pembaruan;
  - penghapusan masuk antrean sementara laporan baru tetap masuk;
  - perubahan nomor tiang (dikonfirmasi sama atau disimpan baru);
  - ID AppSheet dipakai lebih dari sekali;
  - laporan muncul kembali;
  - sheet terpotong, kosong, atau kolom bergeser;
  - ratusan laporan hilang (ditolak, dry-run, opsi manual);
  - perubahan isi massal;
  - cron berulang;
  - database gagal di tengah transaksi (trigger);
  - WO tidak diubah dan laporan yang menunggu tidak bisa dijadikan WO;
  - otorisasi dan audit keputusan;
  - konfirmasi massal;
  - `apkuk:reconcile` menutup antrean;
  - sidik rekonsiliasi stabil;
  - halaman Admin, peta, dan `apkuk:pending`;
  - tabel belum dimigrasi;
  - migration dapat di-rollback.
- **Diperbarui:** `SyncCommandResultTest`, `ApkukReconciliationTest`, `RailwaySyncScriptTest` (status `SUCCESS_WITH_PENDING_RECONCILIATION` dan pembersihan file sementara). Tes `gangguan:sync` dan `keypoint:sync` tidak diubah dan tetap lulus.
- **Uji asap PostgreSQL 18 lokal** di schema sementara (dihapus setelahnya):
  - migration;
  - numeric/decimal tidak terbaca sebagai perubahan;
  - pola 78 hilang + 5 baru + 2 edit + 1 ganti tiang;
  - idempotensi;
  - rencana rekonsiliasi stabil;
  - advisory lock (sync dan pembuatan WO menunggu, lalu timeout bersih);
  - rollback karena trigger;
  - penolakan massal;
  - keputusan Admin;
  - laporan muncul kembali.

  Semua lulus.
