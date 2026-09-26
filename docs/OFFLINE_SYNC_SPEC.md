# OFFLINE SYNCHRONIZATION SPECIFICATION

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Draft / Specification Baseline  
**Dokumen Acuan:** [PRD.md](./PRD.md) · [DESIGN_BRIEF.md](./DESIGN_BRIEF.md) · [TECHNICAL_SPEC.md](./TECHNICAL_SPEC.md) · [DATA_MODEL.md](./DATA_MODEL.md)  
**Platform Target:** Android Field Application (Flutter + SQLite + PowerSync SDK) & PostgreSQL Backend (Supabase + Edge Functions)  
**Lingkup Operasional:** ULP Sribhawono (Single-Tenant)

---

# 1. Offline-First Principles

Pekerjaan lapangan dapat berlangsung di area dengan konektivitas seluler yang tidak stabil, sehingga aplikasi Android membutuhkan pendekatan offline-first dengan prinsip-prinsip arsitektural berikut:

1. **Local-First Write (Penyimpanan Lokal Sebagai Prioritas Pertama):**
   - Setiap interaksi input data lapangan (memulai pekerjaan, mencatat inspeksi, mengambil dokumentasi foto, menyelesaikan tugas) **selalu ditulis langsung ke database lokal SQLite di perangkat**.
   - UI aplikasi merespons secara instan tanpa menunggu respons jaringan (*zero network blocking*). Keberhasilan operasi dinilai dari tersimpannya data di SQLite lokal, bukan konfirmasi server.

2. **Eventual Synchronization (Sinkronisasi Asinkron):**
   - Proses pengiriman data dari SQLite lokal ke PostgreSQL server dilakukan secara asinkron di latar belakang (*background sync*) melalui integrasi PowerSync dan Supabase Edge Functions segera setelah konektivitas terverifikasi.

3. **Server Authoritative Validation (Validasi Akhir di Server):**
   - Meskipun validasi format dilakukan di perangkat (*client-side UI validation*), **server tetap menjadi otoritas tertinggi** dalam memvalidasi keabsahan data, integritas relasi wilayah, aturan transisi status (*state machine*), dan hak akses peran (*role-based authorization*).

4. **Durable Local Persistence & Data-Loss Prevention:**
   - Sistem dirancang untuk meminimalkan risiko kehilangan data melalui pemanfaatan transaksi SQLite lokal, antrian mutasi persisten (*persistent local queue*), mekanisme coba ulang otomatis (*retry logic*), dan prosedur pemulihan kegagalan (*crash recovery*). Data yang tersimpan di SQLite lokal tidak ditimpa secara sepihak saat terjadi konflik sinkronisasi.

5. **Idempotency & Safe Retry (Idempotensi Operasi):**
   - Seluruh mutasi data dirancang aman untuk diulang berkali-kali (*retry-safe*). Pengiriman batch sinkronisasi yang terputus di tengah jalan dan dikirim ulang tidak akan menghasilkan record ganda (*duplicate rows*) atau korupsi relasi.

6. **Decoupled Data vs Photo Sync (Pemisahan Eksekusi Data dan Berkas Foto):**
   - Record transaksi operasional (P0, WO, PASILA, Harsus, ROW) dan metadata dokumentasi disinkronkan secara independen dari proses pengunggahan berkas fisik foto ke Amazon S3. Keterlambatan pengunggahan berkas fisik foto tidak menghambat tersimpannya data tabular pekerjaan di server.

7. **Visible Sync State for Users (Transparansi Status Sinkronisasi):**
   - Petugas lapangan selalu mengetahui status data mereka melalui indikator visual yang jelas, menggunakan terminologi operasional bahasa Indonesia yang mudah dipahami (sesuai panduan Design Brief).

---

# 2. Offline Capability Matrix

Tabel berikut mendefinisikan kemampuan operasional aplikasi Android untuk setiap peran (POSKO, HARSUS, ROW) serta batasannya dibandingkan Web Admin yang beroperasi secara *online-first*.

| Fitur Aplikasi | Role Pengguna | Tersedia Offline? | Akses (R/W) | Kebutuhan Data Lokal (SQLite) | Batasan Operasional Saat Offline |
|---|---|---|---|---|---|
| **Autentikasi & Sesi Login** | POSKO, HARSUS, ROW | Sebagian (Cached) | Read | Profil akun & refresh token tersimpan di Secure Storage | Login perdana wajib online. Jika sesi habis/revoked di server saat offline, input data lokal tetap dimungkinkan sementara waktu. |
| **Daftar & Detail P0** | POSKO | Ya | Read / Write | Tabel `p0`, `p0_petugas`, `asset`, `section` | Terbatas pada tiket P0 yang telah tersinkronisasi sebelum offline. Menerima tiket baru dari Admin/Special Case membutuhkan sync. |
| **Daftar & Detail WO** | POSKO | Ya | Read / Write | Tabel `work_order`, `wo_petugas`, `asset` | WO shift berjalan tersedia lokal. Pembangkitan WO baru shift berikutnya membutuhkan sync online. |
| **Patroli PASILA** | POSKO | Ya | Read / Write | Tabel `pasila_session`, `pasila_action`, `section`, `asset` | Membuka sesi, input aksi, pelaksanaan langsung untuk tindakan yang bisa dieksekusi, pencatatan pekerjaan vegetasi insidental oleh Posko ke `row_work` agar masuk histori ROW, serta pencatatan temuan yang tidak dapat dieksekusi langsung sebagai *noted/follow-up* yang menjadi P0 sesuai business rule dapat berjalan offline. |
| **Inspeksi Harsus** | HARSUS | Ya | Read / Write | Tabel `harsus_inspection`, `asset`, `section` | Form inspeksi dan rekomendasi P0 tersimpan lokal. Rekomendasi P0 baru muncul di Posko setelah Harsus sync ke server. |
| **Pekerjaan ROW** | ROW | Ya | Read / Write | Tabel `row_work`, `row_work_petugas`, `vegetasi`, `section` | Eksekusi tebang/pangkas/rabas pohon tercatat lokal. Penjadwalan ulang otomatis dihitung server pasca-sync. |
| **Detail Aset Kelistrikan** | POSKO, HARSUS, ROW | Ya | Read | Tabel `asset`, `tiang_detail`, `gardu_detail`, `apkuk` | Terbatas pada aset yang tercakup dalam partisi sinkronisasi lokal masing-masing role. |
| **Peta Jaringan (Maps)** | POSKO, HARSUS, ROW | Sebagian (Marker & Koordinat) | Read | Koordinat aset/vegetasi dari tabel SQLite | Koordinat aset dan data marker aplikasi tersedia dari SQLite lokal. Base-map tiles Google Maps **TIDAK dijamin tersedia offline**; konten peta yang pernah terbuka sebelumnya mungkin tersedia tergantung cache internal SDK, namun tidak dapat diandalkan sebagai jaminan peta offline. |
| **Navigasi Rute ke Lokasi** | POSKO, HARSUS, ROW | Tergantung Google Maps App | External Read | — | Membuka aplikasi eksternal Google Maps; ketersediaan rute bergantung pada fitur offline pada aplikasi Google Maps perangkat. |
| **Dropdown Master Petugas** | POSKO, HARSUS, ROW | Ya | Read | Tabel `petugas` | Personil regu kerja dipilih dari master personil lokal aktif. |
| **Dokumentasi Foto** | POSKO, HARSUS, ROW | Ya | Read / Write | Tabel `documentation` + File Storage Lokal | Foto disimpan di storage internal HP, metadata dibuat di SQLite. Upload fisik foto ke Amazon S3 ditangguhkan sampai online. |
| **Pengajuan Koreksi Data** | POSKO | Ya | Write | Tabel `correction_request` | Pengajuan tersimpan di antrian lokal. Admin hanya dapat mereview setelah perangkat terhubung kembali ke jaringan. |
| **Notifikasi Real-Time** | POSKO, HARSUS, ROW | Tidak | — | — | Notifikasi P0 baru dari Admin atau Special Case membutuhkan koneksi data aktif / FCM. |
| **Web Admin Monitoring** | ADMIN | Tidak (Online-First) | Read / Write | — | Web Admin dirancang online-primary; menampilkan banner peringatan koneksi terputus dan menonaktifkan aksi mutasi. |

---

# 3. Local SQLite Data Scope

Untuk mencegah kelebihan beban penyimpanan (*storage bloat*) dan menjaga performa query pada perangkat mobile, **aplikasi Android tidak mengunduh seluruh database ULP ke setiap perangkat**. Data dipartisi ke dalam dua kategori:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        LOCAL SQLITE DATA SCOPE                         │
├───────────────────────────────────┬────────────────────────────────────┤
│ A. REFERENCE DATA (CACHE LOKAL)   │ B. OPERATIONAL DATA (TRANSAKSI)    │
├───────────────────────────────────┼────────────────────────────────────┤
│ - posko (Master 6 Posko ULP)      │ - p0 & p0_petugas                  │
│ - penyulang (Master Feeder 20 kV) │ - work_order & wo_petugas          │
│ - section (Hierarki Wilayah)      │ - pasila_session                   │
│ - asset (Aset ter-filter scope)   │ - pasila_action & action_petugas   │
│ - tiang_detail & gardu_detail     │ - harsus_inspection                │
│ - apkuk (Unit fisik terpasang)    │ - row_work & row_work_petugas      │
│ - vegetasi (Pohon ter-filter)     │ - documentation (Metadata foto)    │
│ - petugas (Master Personil Aktif) │ - correction_request (Draft)       │
│ - app_config (Subset Konfigurasi) │                                    │
└───────────────────────────────────┴────────────────────────────────────┘
```

### 3.1 Aturan Partisi Sinkronisasi (Sync Partitioning Rules)

1. **Role POSKO:**
   - **Reference Scope:** Menerima seluruh master `posko`, `penyulang`, `section`, dan `petugas`.
   - **Asset & Vegetasi Scope:** Dibatasi pada record `asset` dan `vegetasi` yang berada pada seksi-seksi di bawah tanggung jawab Posko terkait (`WHERE section.posko_id = :token_posko_id`).
   - **Operational Scope:** Hanya menerima transaksi `p0`, `work_order`, `pasila_session`, dan `row_work` yang ditugaskan ke Posko tersebut.

2. **Role HARSUS (Proposed Scope — OPEN QUESTION):**
   - **Proposal Baseline:** Untuk memastikan Harsus dapat menginspeksi aset di seksi manapun saat offline, cache lokal diusulkan mencakup seluruh `asset` dan `section` dalam ULP Sribhawono.
   - **Operational Scope:** Mengunduh riwayat `harsus_inspection` yang dibuat oleh akun pengguna tersebut dalam jendela waktu tertentu (misalnya usulan default 30 hari, *configurable*).
   - *Status:* **OPEN QUESTION (OQ-SYNC-01)** mengenai batas kuota ukuran storage lokal jika seluruh aset diunduh.

3. **Role ROW (Proposed Scope — OPEN QUESTION):**
   - **Proposal Baseline:** Tim ROW mengunduh master data `vegetasi` aktif di ULP Sribhawono serta data aset terkait (`asset_terdekat_id`).
   - **Operational Scope:** Mengunduh transaksi `row_work` yang berstatus `DIJADWALKAN` atau dikerjakan dalam periode berjalan.
   - *Status:* **OPEN QUESTION (OQ-SYNC-02)** apakah wilayah kerja ROW dapat dispesifikasikan per penyulang/koridor tertentu.

4. **Pengecualian Data Mentah (Zero Raw Disturbance in Mobile):**
   - Tabel pementasan `gangguan_raw` (24 kolom mentah) **sama sekali tidak disinkronkan ke perangkat Android**.
   - Hanya ringkasan data agregat yang relevan melalui materialized view `gangguan_mv` atau metrik aset yang disertakan dalam sinkronisasi jika diperlukan untuk analisis di lapangan.

---

# 4. Sync State Model

Untuk mengelola siklus hidup data antara SQLite lokal dan PostgreSQL server, setiap record yang berpotensi dimutasi secara lokal memiliki status sinkronisasi yang jelas.

### 4.1 Definisi State Sinkronisasi Lokal

```
   [Record Baru] ────────► PENDING_CREATE ──┐
                                            │
   [Record Diubah] ──────► PENDING_UPDATE ──┤
                                            │
                                    (Koneksi Tersedia)
                                            │
                                            ▼
                                         SYNCING
                                       ┌────┴────┐
                            (Sukses)  │         │  (Error / Mismatch)
                                      ▼         ▼
                                   SYNCED     FAILED / CONFLICT
```

| State Lokal | Deskripsi Operasional | Aksi Sistem | Representasi UI |
|---|---|---|---|
| **`SYNCED`** | Data lokal dan data server berada dalam kondisi identik (*clean state*). | Tidak ada aksi antrian. | Ikon centang hijau / badge netral "Tersinkronisasi". |
| **`PENDING_CREATE`** | Record baru dibuat di perangkat saat offline (UUIDv7 terpasang). | Menunggu koneksi untuk dikirim via upload batch. | Ikon jam kuning "Menunggu Kirim (Baru)". |
| **`PENDING_UPDATE`** | Record eksisting diubah di perangkat saat offline (status/catatan). | Menunggu koneksi untuk dikirim via upload batch. | Ikon pensil oranye "Menunggu Kirim (Perubahan)". |
| **`SYNCING`** | Data sedang dalam proses transmisi ke endpoint Supabase Edge Function. | Mutasi lokal pada record ini dikunci sementara hingga batch selesai. | Ikon putar biru "Menyinkronkan...". |
| **`FAILED`** | Pengiriman data gagal akibat kegagalan jaringan atau server 5xx. | Otomatis dijadwalkan untuk dicoba ulang (*retry* dengan backoff). | Ikon seru merah "Gagal Kirim — Akan Dicoba Otomatis". |
| **`CONFLICT`** | Server menolak mutasi karena konflik versi (OCC) atau pelanggaran state machine. | Sistem menghentikan auto-retry; data lokal diamankan; pengguna/Admin diberi tahu. | Ikon peringatan merah "Konflik Data — Perlu Perhatian". |

### 4.2 Metadata Sinkronisasi di SQLite Lokal

Kolom metadata pelacak berikut dikelola di database lokal SQLite Android.

> **ATURAN ARSITEKTURAL KETAT:** Seluruh kolom metadata lokal ini **HANYA ADA DI SQLITE LOKAL** dan **TIDAK BOLEH DITAMBAHKAN KE SKEMA POSTGRESQL SERVER**.

| Nama Field Lokal | Tipe Data SQLite | Deskripsi / Fungsi |
|---|---|---|
| `_local_sync_status` | `TEXT` | Status sinkronisasi lokal (`SYNCED`, `PENDING_CREATE`, `PENDING_UPDATE`, `SYNCING`, `FAILED`, `CONFLICT`). |
| `_local_updated_at` | `TEXT` (ISO8601) | Timestamp terakhir kali record dimodifikasi di perangkat lokal. |
| `_server_updated_at`| `TEXT` (ISO8601) | Timestamp terakhir kali record diperbarui di server sesuai data sync terakhir. |
| `_retry_count` | `INTEGER` | Jumlah percobaan pengiriman yang telah dilakukan sejak status berubah menjadi `FAILED`. |
| `_last_sync_error` | `TEXT` | Ringkasan pesan kesalahan teknis terakhir yang dikembalikan oleh server. |
| `_powersync_id` | `TEXT` | Identifier pelacak internal PowerSync SDK. |

---

# 5. Record Creation Offline Flow

Alur pembuatan record baru saat perangkat dalam kondisi offline berlaku untuk entitas: `pasila_session`, `pasila_action`, `harsus_inspection`, `row_work`, `documentation`, dan junction table personil (`*_petugas`).

```
┌────────────────────────────────────────────────────────────────────────┐
│                     OFFLINE CREATION WORKFLOW                          │
│                                                                        │
│  1. Client memanggil UUIDv7 generator:                                 │
│     id = uuid.v7()  --> Menghasilkan Primary Key otoritatif           │
│                                                                        │
│  2. Generate Display Code Sementara (Fallback):                        │
│     display_code = "LOCAL-" + id.substring(0, 8)                       │
│                                                                        │
│  3. Simpan ke SQLite lokal dalam 1 DB Transaction:                     │
│     - INSERT ke tabel lokal terkait                                    │
│     - _local_sync_status = 'PENDING_CREATE'                            │
│     - _local_updated_at = waktu lokal saat ini                         │
│                                                                        │
│  4. UI bereaksi seketika (Reactive Watcher update UI):                 │
│     Record muncul di daftar pekerjaan dengan badge "Tersimpan di HP"   │
│                                                                        │
│  ══════════════════ [Jaringan Kembali Aktif] ════════════════════════  │
│                                                                        │
│  5. Background Worker mendeteksi koneksi & membaca antrian PENDING     │
│                                                                        │
│  6. POST payload batch ke Edge Function: `powersync-write`             │
│                                                                        │
│  7. Server Handler memvalidasi & mengeksekusi INSERT PostgreSQL:       │
│     - Memverifikasi JWT claims (role, posko_id)                        │
│     - Menetapkan display_code resmi (e.g. PAS-20260926-1-01)           │
│     - Menyimpan data transaksi ke PostgreSQL                           │
│                                                                        │
│  8. Response OK diterima client:                                       │
│     - SQLite lokal mengupdate _local_sync_status = 'SYNCED'            │
│     - Menimpa display_code dengan kode resmi dari server               │
└────────────────────────────────────────────────────────────────────────┘
```

### 5.1 Authoritative Identifier vs Display Code
- **UUIDv7 adalah Kunci Primer Otoritatif:** Client meng-generate UUIDv7 yang langsung menjadi `id` permanen di database PostgreSQL. Server tidak mengganti ID ini.
- **Display Code Bersifat Sekunder:** Kode ramah manusia (seperti `P0-20260926-0001` atau `PAS-20260926-1-01`) digenerate oleh server saat data pertama kali masuk PostgreSQL untuk memastikan keunikan urutan nomor tanpa risiko tabrakan antar perangkat offline.

---

# 6. Existing Server Record Update Offline Flow

Pembaruan data pada record yang sudah ada sebelumnya (seperti Posko menerima P0, mengubah status menjadi `DIKERJAKAN`, menginput catatan pengerjaan, dan menyelesaikan WO) menggunakan mekanisme **Optimistic Concurrency Control (OCC)** dengan kolom `version INTEGER`.

```
┌─────────────────┐                                  ┌──────────────────┐
│  Android Client │                                  │  PostgreSQL      │
│  (Offline)      │                                  │  (Server)        │
└────────┬────────┘                                  └────────┬─────────┘
         │                                                    │
         │ Record diunduh saat online:                        │
         │ status = 'BARU', version = 1                       │
         ├────────────────────────────────────────────────────┤
         │                                                    │
   [OFFLINE TERJADI]                                          │
         │                                                    │
         │ User klik "Mulai Pekerjaan":                       │
         │ status -> 'DIKERJAKAN'                             │
         │ version -> 1 (tetap mencatat basis versi lama)     │
         │ _local_sync_status -> 'PENDING_UPDATE'             │
         │ Disimpan ke SQLite lokal                           │
         │                                                    │
   [ONLINE KEMBALI]                                           │
         │                                                    │
         │ Kirim Update Payload:                              │
         │ { id: "...", status: "DIKERJAKAN",                 │
         │   expected_version: 1, ... }                       │
         ├───────────────────────────────────────────────────►│
         │                                                    │
         │                                      Evaluasi Trigger / RPC:
         │                                      WHERE id = '...' AND version = 1
         │                                                    │
         │                                      [KASUS A: Cocok]
         │                                      Update berhasil!
         │                                      SET version = version + 1 (menjadi 2)
         │                                      Record status_history tersimpan
         │◄───────────────────────────────────────────────────┤
         │ HTTP 200 OK (New version: 2)                       │
         │ Set _local_sync_status = 'SYNCED'                  │
         │                                                    │
         │                                      [KASUS B: Mismatch]
         │                                      Server mendapati version != 1
         │                                      (Telah diubah sumber lain)
         │◄───────────────────────────────────────────────────┤
         │ HTTP 409 Conflict                                  │
         │ Set _local_sync_status = 'CONFLICT'                │
         │ Tampilkan Peringatan ke Petugas                    │
```

---

# 7. Conflict Resolution Strategy

Sistem ULP Sribhawono menolak penggunaan strategi *Last-Write-Wins (LWW)* secara membabi buta pada seluruh data. Penanganan konflik dibagi ke dalam 4 tier risiko:

```
┌────────────────────────────────────────────────────────────────────────┐
│                     TIERED CONFLICT RESOLUTION                         │
├──────────────────────────┬───────────────────────┬─────────────────────┤
│ KATEGORI TIER            │ CAKUPAN ENTITAS/FIELD │ STRATEGI RESOLUSI   │
├──────────────────────────┼───────────────────────┼─────────────────────┤
│ Tier 1: State & Workflow │ status, waktu_mulai,  │ OCC (Version Check) │
│ Kritis                   │ waktu_selesai, alasan │ + State Machine     │
├──────────────────────────┼───────────────────────┼─────────────────────┤
│ Tier 2: Assignment &     │ posko_id, section_id, │ Server Authoritative│
│ Wilayah Kritis           │ asset_id, jenis_wo    │ (Client Read-Only)  │
├──────────────────────────┼───────────────────────┼─────────────────────┤
│ Tier 3: Field Informatif │ catatan, keterangan   │ Last-Write-Wins     │
│ / Non-Kritis             │ lapangan bebas        │ (Proposal Fallback) │
├──────────────────────────┼───────────────────────┼─────────────────────┤
│ Tier 4: Immutable Audit &│ status_history,       │ Append-Only Event & │
│ Controlled Lifecycle     │ documentation upload  │ Controlled Lifecycle│
└──────────────────────────┴───────────────────────┴─────────────────────┘
```

### 7.1 Penjelasan Detail Tiap Tier

1. **Tier 1 — Status dan Alur Kerja Kritis (P0, WO, PASILA):**
   - Menggunakan validasi ketat: `expected_version` wajib cocok dan transisi status wajib valid menurut *state machine*.
   - Jika terjadi tabrakan pembaruan antara dua perangkat offline yang memegang tiket sama: **perubahan pertama yang sampai di server akan diterima; perubahan kedua ditolak dengan HTTP 409 Conflict**.

2. **Tier 2 — Penugasan dan Integritas Wilayah:**
   - Kolom `posko_id`, `section_id`, `asset_id`, dan `jenis_wo` **bersifat read-only di aplikasi Android**. Posko tidak memiliki wewenang memindahkan penugasan ke posko lain melalui form update mobile. Mutasi wilayah hanya dapat dilakukan Admin melalui Web.

3. **Tier 3 — Field Informatif (Catatan Lapangan):**
   - Jika status pekerjaan sama-sama valid namun terdapat perbedaan teks pada kolom `catatan`, sistem menerapkan *Last-Write-Wins* berdasarkan timestamp `updated_at` terbaru sebagai *engineering proposal fallback*, dengan salinan lama tetap terekam pada audit log.

4. **Tier 4 — Audit Trail & Controlled Documentation Lifecycle:**
   - Record `status_history` dan log audit bersifat **murni append-only** (insert-only, tidak ada operasi update/delete).
   - Record `documentation` **BUKAN murni insert-only**, melainkan mengalami **update terbatas yang terkontrol** pada siklus unggah berkas:
     - Pembaruan `upload_status` (`PENDING` $\rightarrow$ `UPLOADING` $\rightarrow$ `UPLOADED` / `FAILED`).
     - Pengisian `s3_key` dan `uploaded_at` setelah berkas fisik berhasil disimpan di Amazon S3.
     - Pembaruan metadata percobaan ulang (*retry count* dan error terakhir).
   - Konten bukti visual dan data pengambilan foto yang telah final tidak ditimpa sembarangan.

### 7.2 Pengalaman Pengguna (UX) Saat Terjadi Konflik
Jika mutasi ditolak server karena konflik versi:
- Aplikasi **TIDAK PERNAH menghapus data lokal secara diam-diam**.
- Record ditandai dengan badge merah `KONFLIK`.
- Pengguna melihat dialog:
  > **"Peringatan Konflik Sinkronisasi"**  
  > Data pekerjaan ini telah diperbarui oleh pengguna lain di server. Catatan lokal Anda tetap tersimpan di perangkat. Silakan muat versi terbaru atau ajukan tiket koreksi jika ada data yang berbeda.

---

# 8. State Transition Rules During Sync

Server memvalidasi keabsahan transisi status melalui fungsi trigger database (`fn_validate_status_transition`) dan handler Edge Function.

### 8.1 Matriks Transisi Status

| Entitas | Status Awal (Current) | Status Tujuan yang Diizinkan | Keterangan & Aturan Bisnis |
|---|---|---|---|
| **P0** | `BARU` | `DITERIMA`, `CANCELLED` | `CANCELLED` hanya oleh Admin. Posko mengubah ke `DITERIMA`. |
| | `DITERIMA` | `DIKERJAKAN`, `CANCELLED` | Posko menekan tombol "Mulai Pengerjaan". |
| | `DIKERJAKAN` | `SELESAI`, `TIDAK_SELESAI` | Jika `TIDAK_SELESAI`, kolom `alasan_tidak_selesai` WAJIB terisi. |
| | `TIDAK_SELESAI`| *(Terminal / Follow-up TBD)* | Tiket berakhir `TIDAK_SELESAI` dengan alasan tercatat. Kebutuhan P0 lanjutan membawa referensi ke tiket sebelumnya (`parent_p0_id`). Mekanisme pasti pembukaan ulang / tindak lanjut berstatus **PROVISIONAL / OPEN QUESTION**. |
| | `SELESAI` | *(Terminal)* | Status terkunci. Perubahan pasca-selesai wajib via `correction_request`. |
| | `CANCELLED` | *(Terminal)* | Status terkunci final. |
| **WO** | `BARU` | `DITERIMA`, `CANCELLED` | Alur penerimaan pekerjaan preventif. |
| | `DITERIMA` | `DIKERJAKAN`, `CANCELLED` | Masuk tahap pelaksanaan fisik di lapangan. |
| | `DIKERJAKAN` | `SELESAI`, `TIDAK_SELESAI` | Jika `TIDAK_SELESAI`, kolom `alasan_tidak_selesai` WAJIB terisi. |
| | `TIDAK_SELESAI`| *(Terminal / Reschedule TBD)* | Berakhir `TIDAK_SELESAI`. Evaluasi atau penjadwalan ulang untuk shift berikutnya dikelola via logic evaluasi / Admin, tidak otomatis kembali ke `BARU` (**PROVISIONAL / OPEN QUESTION**). |
| | `SELESAI` | *(Terminal)* | Status terkunci. Bukti pemenuhan dokumentasi foto wajib tersimpan. |
| | `CANCELLED` | *(Terminal)* | Dibatalkan Admin. |
| **PASILA Session** | `BERJALAN` | `SELESAI` | Sesi ditutup oleh petugas regu kerja setelah patroli berakhir. |
| | `SELESAI` | *(Terminal)* | Sesi terkunci. Tidak dapat menambahkan `pasila_action` baru ke sesi ini. |
| **ROW Work** | `DIJADWALKAN` | `DIKERJAKAN` | Regu ROW mulai melakukan eksekusi di titik pohon. |
| | `DIKERJAKAN` | `SELESAI`, `TERTUNDA` | `TERTUNDA` jika terkendala izin tanam tumbuh atau cuaca ekstrem. |
| | `TERTUNDA` | *(Follow-up TBD)* | Dijadwalkan kembali untuk koordinat pohon terkait sesuai evaluasi. |
| | `SELESAI` | *(Terminal)* | Selesai dieksekusi; memicu kalkulasi estimasi jadwal berikutnya. |

---

# 9. Photo Offline Architecture & Completion Distinction

Arsitektur foto didesain agar **tidak pernah membebani database relational PostgreSQL dengan binary gambar**, serta membedakan secara tegas antara penyelesaian operasional di lapangan dengan status sinkronisasi cloud penuh.

### 9.1 Perbedaan Dua Kondisi Penyelesaian & Sinkronisasi
1. **Kondisi A: Operational Completion While Offline (Penyelesaian Lapangan):**
   - Pekerjaan (P0, WO, PASILA, ROW) **dapat diselesaikan secara operasional di perangkat saat offline** jika seluruh form data wajib telah terisi dan seluruh dokumentasi foto wajib telah berhasil disimpan di SQLite lokal dan storage berkas privat perangkat.
   - Status pekerjaan lokal beralih menjadi `SELESAI`. Petugas tidak tertahan di lapangan hanya karena ketiadaan koneksi internet.

2. **Kondisi B: Fully Synchronized State (Tersinkronisasi Penuh di Cloud):**
   - Status antarmuka pengguna **JANGAN MENAMPILKAN "Semua Data Tersinkronisasi"** selama masih ada berkas dokumentasi wajib yang berstatus `upload_status = 'PENDING'`, `'UPLOADING'`, atau `'FAILED'`.
   - UI menampilkan status yang akurat: data tabular telah tersimpan di server, namun berkas foto masih dalam antrian pengiriman.
   - Matriks jumlah dan jenis foto wajib (apakah Before + After, After saja, atau Temuan) mengikuti aturan bisnis masing-masing jenis pekerjaan; jika belum diatur eksplisit, berstatus **OPEN QUESTION (OQ-SYNC-08)**.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                              PHOTO OFFLINE PIPELINE                                    │
│                                                                                        │
│   [Kamera Android]                                                                     │
│          │                                                                             │
│          ▼ 1. Ambil Foto & Kompresi Client-Side (Parameter Usulan Configurable)        │
│   [Internal App Storage]                                                               │
│     Path: /data/data/com.pln.sribhawono/files/photos/{uuid}.jpg                        │
│          │                                                                             │
│          ▼ 2. Buat Record Metadata di SQLite Lokal                                      │
│   [SQLite documentation]                                                               │
│     id: UUIDv7                                                                         │
│     upload_status: 'PENDING'                                                           │
│     local_path: '/.../photos/{uuid}.jpg' (Khusus SQLite lokal)                         │
│     s3_key: null, uploaded_at: null                                                    │
│          │                                                                             │
│  ════════╪══════════════════════ [KONEKSI INTERNET AKTIF] ═══════════════════════════  │
│          │                                                                             │
│          ▼ 3. Step A: Sinkronisasi Metadata via PowerSync                              │
│   [PostgreSQL documentation]                                                           │
│     Record metadata tersimpan di server dengan upload_status = 'PENDING'               │
│          │                                                                             │
│          ▼ 4. Step B: Request Pre-signed Upload URL                                    │
│   [Edge Function: generate-photo-upload-url]                                           │
│     Input: { documentation_id, entity_type, photo_type }                               │
│     Output: { put_url, s3_key } (Masa aktif URL berstatus configurable)               │
│          │                                                                             │
│          ▼ 5. Step C: Upload File Fisik Langsung ke Amazon S3                          │
│   [Amazon S3 Storage] (PUT Request multipart / binary streaming)                       │
│     Bucket: s3://<evidence-bucket>/{s3_key} (Ditentukan pada setup environment)        │
│          │                                                                             │
│          ▼ 6. Step D: Konfirmasi Keberhasilan & Update Status                          │
│   [Update Status Metadata di Server & Lokal]                                           │
│     upload_status = 'UPLOADED'                                                         │
│     uploaded_at = now()                                                                │
│     s3_key = '{s3_key}'                                                                │
│          │                                                                             │
│          ▼ 7. Step E: Pembersihan Storage Lokal (Local Cleanup)                        │
│     File lokal memenuhi syarat dihapus sesuai kebijakan retensi lokal aman             │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

### 9.2 Ketahanan Terhadap Crash & Gangguan Jaringan (Fault Tolerance)
- **Aplikasi Dimatikan / HP Mati Mendadak:** Berkas foto telah aman berada di direktori private aplikasi, dan metadata berstatus `PENDING` di SQLite. Saat aplikasi dibuka kembali, background worker memindai record berstatus `PENDING` atau `UPLOADING` yang terputus dan melanjutkan antrian.
- **Koneksi Terputus Saat Upload Berjalan:** HTTP PUT ke S3 akan gagal atau *timeout*. Worker menangkap kegagalan tersebut, menandai `upload_status = 'FAILED'`, dan menjadwalkan *retry* dengan skema *exponential backoff*.
- **Pembersihan File Lokal (Local File Cleanup Policy):** File fisik di perangkat hanya boleh dihapus jika memenuhi syarat:
  1. File fisik telah berhasil terunggah ke S3 (`HTTP 200 OK` dari S3).
  2. Record `documentation` pada PostgreSQL server telah terverifikasi memiliki status `UPLOADED` dan `s3_key` terisi valid.
  3. Lolos masa retensi keamanan lokal (parameter usulan: e.g. 48 jam pasca-upload, *configurable*).

---

# 10. Sync Ordering / Dependency Graph

Ketika perangkat kembali online dan mengirimkan sekumpulan transaksi yang dibuat saat offline, urutan pengiriman (*dependency ordering*) wajib mematuhi aturan relasi kunci asing (*Foreign Key*) untuk mencegah kegagalan *constraint violation* di PostgreSQL.

```
                  ┌──────────────────────┐
                  │    pasila_session    │
                  └──────────┬───────────┘
                             │
                             ▼
                  ┌──────────────────────┐
                  │    pasila_action     │
                  └─────┬──────────┬─────┘
                        │          │
         ┌──────────────┘          └──────────────┐
         ▼                                        ▼
┌──────────────────────┐               ┌──────────────────────┐
│pasila_action_petugas │               │   p0 (Tindak Lanjut) │
└──────────────────────┘               └──────────┬───────────┘
                                                  │
                                                  ▼
                                       ┌──────────────────────┐
                                       │      p0_petugas      │
                                       └──────────┬───────────┘
                                                  │
                                                  ▼
                                       ┌──────────────────────┐
                                       │    documentation     │
                                       │   (Metadata Foto)    │
                                       └──────────────────────┘
```

### 10.1 Urutan Prioritas Transmisi Data
1. **Level 1 (Sesi Induk / Transaksi Utama):** `pasila_session`, `p0`, `work_order`, `harsus_inspection`, `row_work`.
2. **Level 2 (Tindakan / Item Anak):** `pasila_action` (terikat pada `session_id`).
3. **Level 3 (Junction Relasi Personil):** `p0_petugas`, `wo_petugas`, `pasila_action_petugas`, `row_work_petugas`.
4. **Level 4 (Metadata Dokumentasi Visual):** `documentation` (terikat pada `entity_type` dan `entity_id`).

Edge Function `powersync-write` mengeksekusi operasi dalam batch transaction dengan urutan topologis di atas. Jika terjadi *nested creation* (misalnya aksi PASILA yang mencatat pekerjaan vegetasi insidental ke `row_work` atau temuan yang dicatat sebagai P0 tindak lanjut), sistem mengeksekusi pembuatan record induk terlebih dahulu sebelum mengaitkan record turunan.

---

# 11. Idempotency & Safe Duplicate Prevention

Untuk menjamin keandalan data pada jaringan seluler yang rentan mengalami transmisi ulang (*re-transmission*), sistem menerapkan prinsip idempotensi yang aman dan terpisah:

1. **CREATE Retry (Idempotent Record Creation):**
   - Setiap record baru memiliki kunci primer otoritatif UUIDv7 yang digenerate client.
   - Jika request create record baru terkirim ulang akibat gangguan jaringan (*network replay*), server mendeteksi keberadaan ID tersebut. Jika payload data identik, server mengembalikan status sukses tanpa membuat duplikasi baris dan tanpa mengubah nilai timestamp server yang telah terbentuk.

2. **UPDATE Existing Record (Strict Version & State Check):**
   - **TIDAK MENGGUNAKAN** klausa *blind overwrite* generik (`ON CONFLICT DO UPDATE`).
   - Setiap pembaruan record eksisting **wajib menyertakan `expected_version`** dan melalui validasi *state machine*. Jika versi tidak cocok atau status telah berubah di server, update ditolak dengan konflik, mencegah penimpaan data yang tidak disengaja.

3. **Junction Table Constraints:**
   - Seluruh tabel penghubung personil menerapkan *Composite Primary Key* atau *Unique Constraint* (misalnya `UNIQUE (p0_id, petugas_id)`). Percobaan *insert* berulang personil yang sama pada pekerjaan yang sama secara otomatis bersifat *no-op*.

4. **Pre-signed Upload URL Idempotency:**
   - Path kunci S3 (`s3_key`) dibentuk secara deterministik menggunakan kombinasi ID entitas dan ID dokumentasi:
     $$\text{s3\_key} = \text{evidence/}\{entity\_type\}/\{entity\_id\}/\{documentation\_id\}\text{.jpg}$$
   - Pengunggahan ulang file foto yang sama ke S3 akan menimpa objek pada kunci yang identik (*deterministic key overwrite*), mencegah redundansi kapasitas bucket S3.

---

# 12. Deletion Strategy

Aplikasi lapangan Android beroperasi sesuai dengan **auditability requirement sistem ini**:

1. **Larangan Keras Hard-Delete Transaksi:**
   - **Tidak ada fitur atau endpoint untuk melakukan penghapusan fisik record transaksi** (`p0`, `work_order`, `pasila_session`, `harsus_inspection`, `row_work`, `documentation`) dari aplikasi mobile.
   - Perangkat Android tidak memiliki izin SQL `DELETE` pada tabel operasional server.

2. **Penanganan Pembatalan / Kegagalan Tugas:**
   - Transaksi yang batal atau gagal dikerjakan dialihkan statusnya menjadi `TIDAK_SELESAI` (oleh Posko dengan kewajiban mengisi `alasan_tidak_selesai`) atau `CANCELLED` (hanya dapat diputuskan oleh Admin).

3. **Penghapusan Master Data (Soft-Deactivation):**
   - Penonaktifan personil, aset, atau wilayah kerja dilakukan melalui flag status `is_active = false` atau `status = 'NON_AKTIF'` di level Admin. Record historis tetap tersimpan utuh.

4. **Pembersihan Cache Lokal Perangkat (Pruning):**
   - Transaksi operasional berstatus `SELESAI` yang telah melampaui batas retensi perangkat lokal (parameter usulan: e.g. 14 hari, *configurable*) dan telah terkonfirmasi tersinkronisasi penuh ke PostgreSQL dapat di-*purge* dari database SQLite lokal Android untuk menjaga performa perangkat.
   - Data transaksi di database PostgreSQL server **tidak di-hard-delete selama operasional aktif**, dan kebijakan retensi/arsip jangka panjang mengikuti kebijakan stakeholder yang disetujui nantinya (**OPEN QUESTION**).

---

# 13. Connectivity Detection & UI State Engine

Aplikasi tidak boleh hanya mengandalkan indikator koneksi sistem operasi Android (Wi-Fi/Cellular on), karena status radio aktif tidak menjamin ketersediaan akses data internet sesungguhnya (*captive portal / blank spot*).

### 13.1 Empat Tingkatan Pemeriksaan Konektivitas (Konseptual)
Secara arsitektural, sistem wajib membedakan empat kondisi:
1. **Network Interface Available:** Antarmuka radio perangkat aktif (Wi-Fi atau Data Seluler).
2. **Backend Reachable:** Jalur jaringan menuju endpoint backend dapat diakses.
3. **Authentication Valid:** Kredensial / token autentikasi pengguna valid dan belum kedaluwarsa/dicabut.
4. **Sync Service Connected:** Saluran sinkronisasi PowerSync terhubung aktif untuk pertukaran data.

> *Catatan Implementasi:* Metode teknis deteksi (seperti DNS ping, endpoint health-check, dan ambang batas latensi ms) merupakan **usulan implementasi teknis yang dapat dikonfigurasi (*proposed / configurable*)**, bukan batasan bisnis yang kaku.

### 13.2 Tiga Mode Status Operasional Aplikasi
1. **`ONLINE`:** Seluruh layer pemeriksaan terpenuhi. Transaksi lokal mengalir lancar ke server, dan pembaruan dari server langsung diterima secara real-time.
2. **`DEGRADED`:** Radio dan internet terdeteksi, namun latensi sangat tinggi, packet loss tinggi, atau koneksi sinkronisasi tersendat. Aplikasi mengutamakan penyimpanan SQLite lokal dan membatasi upload foto guna menghemat bandwidth.
3. **`OFFLINE`:** Tidak ada jaringan radio aktif atau server backend tidak dapat dijangkau. Seluruh operasi dialihkan penuh ke penyimpanan lokal SQLite tanpa memunculkan dialog pop-up yang mengganggu alur kerja pengguna.

---

# 14. Authentication & Session Handling While Offline

Kondisi lapangan mengharuskan petugas dapat membuka aplikasi dan mencatat data meskipun perangkat dinyalakan di area tanpa sinyal sama sekali.

1. **Login Pertama Wajib Online:**
   - Perangkat baru atau aplikasi yang baru di-install wajib melakukan login pertama kali secara online untuk memvalidasi kredensial ke Supabase Auth, mendapatkan JWT token, mengunduh profil peran, dan menjalankan inisialisasi database lokal (*bootstrap*).

2. **Cached Session di Flutter Secure Storage:**
   - Setelah login pertama sukses, *session context* (User ID, Role, Posko ID, Access Token, Refresh Token) dienkripsi dan disimpan di *Flutter Secure Storage* (menggunakan Android Keystore/EncryptedSharedPreferences).
   - Petugas dapat membuka aplikasi dan masuk ke dashboard operasional secara offline tanpa proses login ulang.

3. **Penanganan Token Kedaluwarsa Saat Offline (Token Expiration Grace Period):**
   - Jika access token kedaluwarsa saat perangkat offline:
     - **Aplikasi TIDAK MENGELUARKAN (*force logout*) pengguna.**
     - Petugas tetap diizinkan menginput pekerjaan, mencatat inspeksi, dan mengambil foto secara normal di SQLite lokal.
     - Operasi mutasi ditandai dengan identitas pengguna lokal yang tersimpan.
     - Segera setelah perangkat mendeteksi koneksi online, aplikasi secara otomatis memanggil proses *silent token refresh* menggunakan refresh token sebelum mengeksekusi antrian sinkronisasi ke server.

4. **Penanganan Akun yang Dinonaktifkan (Revoked Account):**
   - Jika Admin menonaktifkan akun seorang petugas saat perangkatnya sedang offline, sistem tidak dapat mengetahuinya seketika.
   - Begitu perangkat terhubung kembali ke internet, request refresh token atau verifikasi JWT di endpoint `powersync-write` akan ditolak (`HTTP 401 Unauthorized / Account Disabled`).
   - Aplikasi akan mengunci akses pengguna, menghentikan sinkronisasi, dan menampilkan pesan: *"Akun Anda telah dinonaktifkan oleh Administrator. Hubungi kantor ULP."* Data pekerjaan lokal yang belum terunggah diamankan di SQLite untuk diekstrak via prosedur darurat jika diperlukan.

---

# 15. App Restart & Crash Recovery

Aplikasi dirancang tangguh terhadap insiden tak terduga di lapangan (baterai habis, panas ekstrem yang memicu shutdown HP, atau *crash* pada sistem operasi).

1. **Ketahanan Transaksi ACID SQLite (WAL Mode):**
   - Database SQLite dikonfigurasi menggunakan mode **Write-Ahead Logging (WAL)**. Mode ini secara signifikan meningkatkan konkurensi pembacaan/penulisan serta meminimalkan risiko kerusakan data (*corruption*) saat perangkat mati mendadak atau aplikasi terhenti paksa.

2. **Pemulihan Pengisian Formulir (Draft Auto-Save):**
   - Form input pekerjaan yang kompleks (misalnya inspeksi detail Harsus atau sesi PASILA) menerapkan mekanisme *auto-save* secara berkala ke penyimpanan lokal. Jika aplikasi mati saat form sedang diisi, data inputan terakhir dipulihkan saat form dibuka kembali.

3. **Resume Sinkronisasi yang Terputus:**
   - Antrian transaksi persisten (*persistent local mutation queue*) dipelihara di storage lokal. Jika aplikasi mati di tengah proses pengiriman batch data, antrian tidak hilang dan akan dilanjutkan dari titik terakhir saat aplikasi aktif kembali.

4. **Resume Unggah Foto (Photo Upload Resumption):**
   - Pada saat inisialisasi aplikasi (*cold start*), worker pengunggah foto memindai record metadata dokumentasi di SQLite lokal yang masih berstatus `PENDING` atau `UPLOADING` yang terputus, mereset status sementara menjadi `PENDING`, dan melanjutkan antrian unggah secara aman.

---

# 16. Sync UI / UX States

Mengacu pada [DESIGN_BRIEF.md](./DESIGN_BRIEF.md) Seksi 6.11, status sinkronisasi dikomunikasikan kepada petugas lapangan dengan bahasa yang manusiawi, bebas dari jargon teknis rumit, serta menggunakan warna indikator standar.

```
┌────────────────────────────────────────────────────────────────────────┐
│                        SYNC STATUS BADGES                              │
├──────────────────────────┬──────────────┬──────────────────────────────┤
│ STATUS TEKNIS            │ BADGE UI     │ PESAN & LABEL PENGGUNA       │
├──────────────────────────┼──────────────┼──────────────────────────────┤
│ Offline Mode             │ Abu-abu      │ "Mode Offline — Sinyal Putus"│
│ Menunggu Sinkronisasi    │ Kuning/Oranye│ "X Data Menunggu Terkirim"   │
│ Sedang Menyinkronkan     │ Biru Putar   │ "Menyinkronkan ke Server..." │
│ Tersinkronisasi Penuh    │ Hijau Centang│ "Semua Data Aman Tersimpan"  │
│ Gagal Sinkronisasi       │ Merah Seru   │ "Gagal Kirim — Coba Otomatis"│
│ Konflik Data             │ Merah Bahaya │ "Perlu Perhatian Petugas"    │
└──────────────────────────┴──────────────┴──────────────────────────────┘
```

> **Aturan Tampilan "Semua Data Aman Tersimpan":** Label tersinkronisasi penuh hanya ditampilkan jika **seluruh transaksi data DAN seluruh berkas foto wajib** telah terkonfirmasi `UPLOADED` di server. Jika data teks sudah terkirim namun foto masih mengantri, badge menampilkan *"X Foto Menunggu Terkirim"*.

### 16.1 Halaman Pusat Sinkronisasi (Sync Center)
Dapat diakses melalui tombol indikator pada App Header atau menu profil:
- **Ringkasan Antrian:** Menampilkan jumlah transaksi data yang belum terkirim dan jumlah foto yang menunggu koneksi.
- **Tombol "Sinkronkan Sekarang":** Memungkinkan pengguna memicu pemeriksaan koneksi dan pengiriman antrian secara manual tanpa harus menunggu interval otomatis.
- **Daftar Log Riwayat Singkat:** Menampilkan waktu terakhir kali sinkronisasi berhasil dilaksanakan (contoh: *"Terakhir sinkron: Hari ini, 14:15 WIB"*).

---

# 17. Role-Based Sync Scope

Partisi sinkronisasi data disesuaikan dengan kebutuhan spesifik masing-masing peran operasional:

### 17.1 Peran POSKO (1 Akun = 1 Posko)
- **Cakupan Data Masuk (Download):**
  - Seluruh master data `posko`, `penyulang`, `section` (sebagai referensi peta dan hierarki).
  - Data `asset`, `apkuk`, dan `vegetasi` yang berlokasi pada seksi di wilayah kerja Posko bersangkutan.
  - Tiket `p0` dan `work_order` yang berstatus aktif atau ditugaskan ke Posko tersebut.
  - Sesi `pasila_session` yang dibuka oleh Posko tersebut dalam jendela waktu aktif.
  - Master data `petugas` aktif untuk kebutuhan dropdown personil.
- **Cakupan Data Keluar (Upload):**
  - Pembaruan status dan pengerjaan P0/WO.
  - Pembuatan sesi dan aksi PASILA.
  - Pengajuan tiket koreksi data (`correction_request`).
  - Metadata dan unggahan foto dokumentasi pekerjaan.

### 17.2 Peran HARSUS (Tim Inspeksi Khusus)
- **Cakupan Data Masuk (Download — Proposed / OPEN QUESTION):**
  - Master data aset dan seksi di ULP Sribhawono (skup wilayah penuh vs per penugasan berstatus **OPEN QUESTION**).
  - Riwayat inspeksi yang dibuat oleh tim Harsus terkait dalam kurun waktu tertentu (usulan configurable: 30 hari).
- **Cakupan Data Keluar (Upload):**
  - Form transaksi `harsus_inspection`.
  - Rekomendasi tiket penugasan khusus (`p0`).
  - Dokumentasi foto anomali dan kondisi fisik aset.

### 17.3 Peran ROW (Regu Perabasan Vegetasi)
- **Cakupan Data Masuk (Download — Proposed / OPEN QUESTION):**
  - Master data titik `vegetasi` dan seksi jaringan distribusi (skup penuh vs koridor terjadwal berstatus **OPEN QUESTION**).
  - Jadwal pekerjaan perabasan pohon yang ditugaskan ke regu ROW.
- **Cakupan Data Keluar (Upload):**
  - Laporan eksekusi pekerjaan `row_work` (RABAS, PANGKAS, TEBANG).
  - Foto dokumentasi pekerjaan sebelum dan sesudah pelaksanaan sesuai aturan.
  - *Catatan Titik Baru:* Apakah regu ROW dapat langsung membuat master titik vegetasi baru di lapangan atau hanya mencatat pekerjaan pada master eksisting / mengajukan temuan pohon baru untuk Admin berstatus **OPEN QUESTION (OQ-SYNC-09)**.

---

# 18. Initial Sync / Bootstrap Flow

Saat seorang petugas pertama kali menginstal aplikasi atau melakukan proses login perdana di kantor posko, sistem menjalankan prosedur inisialisasi data (*bootstrap*) yang terstruktur:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        INITIAL BOOTSTRAP FLOW                          │
│                                                                        │
│   [1. Form Login]                                                      │
│          │ Memasukkan kredensial login (Online)                        │
│          ▼                                                             │
│   [2. Autentikasi Supabase]                                            │
│          │ Verifikasi password & penerbitan JWT custom claims          │
│          ▼                                                             │
│   [3. Persiapan Database SQLite]                                       │
│          │ Inisialisasi skema lokal & registrasi PowerSync SDK         │
│          ▼                                                             │
│   [4. Layar Pemuatan Inisialisasi (Blocking Modal / Screen)]           │
│          │                                                             │
│          ├─► Unduh Referensi Wilayah & Config ... [20%]                │
│          ├─► Unduh Master Personil Petugas ...... [40%]                │
│          ├─► Unduh Inventaris Aset Wilayah ...... [70%]                │
│          └─► Unduh Tiket Penugasan Aktif ........ [100%]               │
│          │                                                             │
│          ▼                                                             │
│   [5. Verifikasi Integritas Lokal]                                     │
│          │ Validasi kesiapan data & indeks lokal                       │
│          ▼                                                             │
│   [6. Dashboard Siap Digunakan]                                        │
│          Badge hijau: "Aplikasi Siap Digunakan Secara Offline"         │
└────────────────────────────────────────────────────────────────────────┘
```

> **ATURAN INTEGRITAS BOOTSTRAP:** Petugas **tidak diizinkan melewati (*skip*) proses inisialisasi awal**. Jika proses terputus sebelum selesai, aplikasi menampilkan opsi pengulangan dan membatasi akses operasional lapangan guna mencegah kegagalan relasi kunci asing (*missing foreign keys*).

---

# 19. Large Dataset Strategy & Storage Governance

Menyimpan data jaringan distribusi pada perangkat bergerak memerlukan strategi pengelolaan memori dan penyimpanan:

1. **Eliminasi Unduhan Data Gangguan Mentah (Raw Logs):**
   - Tabel `gangguan_raw` yang memuat 24 kolom data histori pemadaman dari sistem pusat PLN **tidak pernah diunduh ke SQLite lokal**. Hal ini menghemat megabyte ruang penyimpanan dan memori perangkat.
   - Perangkat hanya menerima atribut keandalan yang telah diagregasikan jika benar-benar diperlukan untuk skoring prioritas.

2. **Paginasi & Jendela Waktu Sinkronisasi (Time-Window Pruning):**
   - Transaksi historis pekerjaan lapangan dibatasi menggunakan jendela waktu (*sliding window* configurable, misalnya usulan 14 hari pasca-selesai). Data lama tidak disimpan di lokal Android, namun tetap dipertahankan pada database server sesuai kebijakan retensi.

3. **Strategi Peta dan Keterbatasan Offline (Google Maps):**
   - Koordinat titik aset, gardu, tiang, dan pohon disimpan dalam format numerik ringan di SQLite dan dapat ditampilkan sebagai marker di aplikasi.
   - **Base-map tiles Google Maps TIDAK dijamin tersedia offline.** Konten peta yang pernah dibuka sebelumnya mungkin dapat tampil tergantung perilaku cache internal SDK dan memori perangkat, namun hal ini tidak dapat diandalkan sebagai jaminan peta offline.
   - Jika kebutuhan peta offline menyeluruh (*full offline basemap*) menjadi persyaratan wajib di masa depan, arsitektur penyedia peta (*map provider/MBTiles*) harus dievaluasi ulang secara terpisah.

---

# 20. Comprehensive Error Handling Matrix

Tabel berikut merinci skenario kegagalan operasional, dampak pada pengguna, perilaku sistem, serta panduan tindakan pemulihan:

| Skenario Kegagalan | Dampak pada Pengguna | Perilaku Sistem Lokal (Android) | Perilaku Sistem Server (PostgreSQL/Edge) | Kebijakan Retry | Tindakan Pengguna / Petugas |
|---|---|---|---|---|---|
| **Koneksi Internet Putus (No Internet)** | Tidak dapat mengirim data ke server atau menerima tiket baru secara real-time. | Beralih ke Mode Offline. Data tetap disimpan ke SQLite dengan status `PENDING`. | Tidak ada aktivitas (menunggu koneksi). | Otomatis mencoba ulang saat sinyal kembali terdeteksi. | Tetap bekerja seperti biasa; data akan terkirim otomatis saat tiba di area bersinyal. |
| **Server Backend Down / 503 Service Unavailable** | Data yang tertahan belum dapat disinkronkan. | Data aman di SQLite lokal; status ditandai `FAILED`. UI menampilkan status sistem sibuk. | Edge Function / Supabase menolak koneksi tanpa mengubah state database. | Retry dengan skema *exponential backoff*. | Tidak ada tindakan khusus; sistem akan mencoba ulang otomatis. |
| **Token Autentikasi Kedaluwarsa Saat Offline** | Tidak ada dampak langsung saat berada di lapangan. | Pengguna tetap dapat menginput data ke SQLite menggunakan sesi ter-cache. | Server menolak request sync sampai token baru diterbitkan. | Otomatis memicu *silent refresh token* saat koneksi internet kembali pulih. | Pengguna hanya diminta login ulang jika refresh token ikut kedaluwarsa/revoked. |
| **Pelanggaran Kunci Relasi (Missing Parent FK)** | Data anak (misal `pasila_action`) berisiko gagal simpan di server jika sesi induk belum ada. | Seluruh hierarki transaksi dikelompokkan dalam satu batch atomik terurut. | Server memproses dalam transaksi tunggal (`BEGIN ... COMMIT`) berdasarkan urutan dependency. | Retry seluruh batch secara bersamaan. | Jika berulang, Sync Center menampilkan opsi "Kirim Ulang Seluruh Data". |
| **Konflik Data Versi (Record Conflict / OCC Mismatch)** | Perubahan status ditolak server karena telah diubah perangkat/Admin lain. | Record lokal ditandai dengan state `CONFLICT`. Data lokal TIDAK dihapus. | Mutasi ditolak; versi server tetap dipertahankan; log audit mencatat insiden. | **TIDAK di-retry otomatis** untuk mencegah penimpaan data sah. | Petugas membuka Sync Center, memeriksa catatan, atau melapor ke Admin. |
| **Kegagalan Upload Foto ke S3 (Network Timeout)** | Foto belum terunggah ke penyimpanan cloud. | Metadata foto tersimpan di SQLite; status foto ditandai `FAILED`. Transaksi data tabular utama tetap dapat tersinkronisasi. | Server belum menerima pembaruan `upload_status = 'UPLOADED'` dan `s3_key`. | Retry otomatis dengan jeda waktu meningkat (*configurable*). | Petugas dapat menekan tombol "Upload Ulang Foto" di Sync Center / detail tugas. |
| **Penyimpanan HP Penuh (Storage Device Full / Warning Threshold)** | Kamera gagal mengambil gambar; database berisiko error penulisan disk. | Sistem mendeteksi sisa ruang penyimpanan; menampilkan banner peringatan; membatasi pengambilan foto baru. | Tidak ada dampak di server. | Tidak ada. | Petugas membersihkan file/aplikasi lain di luar aplikasi operasional PLN. |
| **Berkas Foto Rusak (Corrupted Image File)** | Foto tidak dapat dibuka atau diunggah. | Worker memvalidasi integritas berkas foto sebelum upload; jika corrupt/kosong, tandai `FAILED_CORRUPT`. | Tidak ada dampak di server. | Tidak di-retry otomatis. | Petugas mengambil foto ulang menggunakan kamera aplikasi jika masih berada di lokasi aset. |
| **Pengiriman Ganda Akibat Jaringan Lambat (Duplicate Op)** | Kekhawatiran data tercatat ganda. | Client mengirimkan payload dengan UUIDv7 yang sama persis. | Server memvalidasi keunikan ID; jika identik, mengembalikan respon sukses tanpa menduplikasi data. | Sukses pada percobaan pertama yang tembus. | Tidak ada (sistem menangani secara transparan). |
| **Akun Dicabut / Di-suspend Server Saat Offline** | Petugas tidak lagi memiliki hak akses operasional. | Saat online, respon 401/403 memicu penguncian aplikasi seketika. | Akses ditolak di level JWT verification / RLS policy. | Tidak ada retry. | Petugas diarahkan menghubungi Supervisor / Admin ULP Sribhawono. |

---

# 21. Observability, Logging, & Auditability

Untuk memudahkan diagnosis masalah sinkronisasi di lapangan tanpa melanggar privasi atau keamanan data:

1. **Event Telemetri Minimal:**
   Aplikasi mencatat jejak audit lokal dan mengirimkan metrik telemetri ke server (saat online) untuk kejadian:
   - `sync_bootstrap_completed` (Waktu durasi dan volume data awal).
   - `sync_batch_pushed` (Jumlah record, durasi transmisi, status keberhasilan).
   - `sync_batch_failed` (Kode error HTTP, ringkasan penolakan server).
   - `sync_conflict_detected` (ID record, nama tabel, versi lokal vs versi server).
   - `photo_upload_failed` (Nama berkas, kode error S3, status koneksi).
   - `photo_retry_exhausted` (Foto yang gagal diunggah setelah batas percobaan maksimal).

2. **Prinsip Sanitasi Log (Zero Credential Leakage):**
   - **DILARANG KERAS** mencatat password, refresh token, access token JWT, atau payload sensitif ke dalam berkas log teks di perangkat maupun sistem error tracking.
   - Log hanya mencatat: ID transaksi (UUID), nama entitas, timestamp, tipe koneksi (Wi-Fi/Cellular), dan kode status error teknis.

---

# 22. Verification & Acceptance Test Scenarios

Sebelum rilis ke tahap produksi, fungsionalitas offline dan sinkronisasi wajib lulus uji pada 10 skenario pengujian penerimaan (*acceptance test*) berikut:

1. **Uji Skenario 1 — Siklus Lengkap PASILA Murni Offline:**
   - *Langkah:* Perangkat dialihkan ke Airplane Mode. Petugas membuka sesi PASILA baru, menambahkan 3 aksi temuan/perbaikan, melampirkan foto dokumentasi pada masing-masing aksi, lalu menutup sesi (`SELESAI`). Airplane Mode dimatikan.
   - *Kriteria Kelulusan:* Seluruh sesi, aksi, relasi personil, dan foto tersinkronisasi ke server tanpa error, dengan urutan dependency yang benar dan display code terbit resmi dari server.

2. **Uji Skenario 2 — Eksekusi Tiket P0 Offline:**
   - *Langkah:* Buka aplikasi saat online hingga tiket P0 termuat. Matikan koneksi internet. Ubah status menjadi `DITERIMA`, lalu `DIKERJAKAN`, isi catatan teknis dan foto, lalu ubah status menjadi `SELESAI`. Nyalakan internet.
   - *Kriteria Kelulusan:* Status P0 di server berubah menjadi `SELESAI`, `status_history` mencatat seluruh urutan perubahan dengan timestamp akurat, dan versi bertambah secara valid.

3. **Uji Skenario 3 — Konflik Modifikasi Dua Perangkat:**
   - *Langkah:* Dua perangkat berbeda memuat tiket WO yang sama dalam kondisi online. Kedua perangkat diputus dari internet. Perangkat A menyelesaikan tiket menjadi `SELESAI`. Perangkat B mengubah tiket menjadi `TIDAK_SELESAI`. Hubungkan Perangkat A ke internet (berhasil sync), lalu hubungkan Perangkat B.
   - *Kriteria Kelulusan:* Perangkat B menerima penolakan konflik (HTTP 409). Data di server tetap berstatus `SELESAI` (hasil dari Perangkat A). Perangkat B memunculkan badge `KONFLIK` dan tidak menghapus catatan yang dibuat oleh pengguna Perangkat B.

4. **Uji Skenario 4 — Pengunggahan Foto Masif (*Burst Photo Upload*):**
   - *Langkah:* Ambil sejumlah foto dokumentasi pekerjaan saat kondisi offline. Hubungkan perangkat ke jaringan seluler berkecepatan rendah.
   - *Kriteria Kelulusan:* Metadata teks tersinkronisasi terlebih dahulu; foto diunggah secara berurutan (*queue*) tanpa menyebabkan crash aplikasi; seluruh status foto berubah menjadi `UPLOADED`.

5. **Uji Skenario 5 — Terminasi Aplikasi Paksa Saat Input Data:**
   - *Langkah:* Buka form inspeksi Harsus saat offline, isi sebagian besar data teknis, lalu matikan daya perangkat secara mendadak (*force kill*). Nyalakan kembali perangkat.
   - *Kriteria Kelulusan:* Basis data SQLite lokal tidak mengalami kerusakan (*corrupt*), form memulihkan isian draft terakhir, dan aplikasi dapat melanjutkan operasional normal.

6. **Uji Skenario 6 — Pemutusan Koneksi di Tengah Unggah Foto S3:**
   - *Langkah:* Mulai proses unggah file foto ke S3, putus koneksi internet saat transmisi sedang berlangsung.
   - *Kriteria Kelulusan:* Aplikasi menangkap kegagalan transmisi secara anggun tanpa force-close; status foto berubah menjadi `FAILED`; worker menjadwalkan upload ulang saat koneksi stabil kembali.

7. **Uji Skenario 7 — Masa Offline Panjang Melampaui Masa Aktif JWT:**
   - *Langkah:* Login di perangkat, putus koneksi internet selama waktu yang melampaui masa aktif access token. Lakukan pencatatan transaksi di lapangan. Hubungkan kembali ke internet.
   - *Kriteria Kelulusan:* Aplikasi secara transparan memanggil proses refresh token sebelum sinkronisasi data; seluruh transaksi lokal berhasil terkirim tanpa meminta pengguna memasukkan password kembali.

8. **Uji Skenario 8 — Integritas Relasi Kunci Asing Bertingkat:**
   - *Langkah:* Buat sesi PASILA baru $\rightarrow$ buat aksi $\rightarrow$ catat temuan yang tidak dapat dieksekusi langsung sehingga menjadi P0 tindak lanjut $\rightarrow$ tambahkan petugas P0 $\rightarrow$ lampirkan foto dokumentasi. Seluruhnya dilakukan offline. Hubungkan ke internet.
   - *Kriteria Kelulusan:* Seluruh rangkaian data tersimpan di server dengan relasi kunci asing yang valid sempurna tanpa ada record anak yang ditolak akibat record induk belum terdaftar.

9. **Uji Skenario 9 — Uji Idempotensi Pengiriman Ulang (*Network Replay*):**
   - *Langkah:* Kirim payload batch sinkronisasi, simulasikan kondisi di mana server telah memproses request namun koneksi putus sebelum client menerima respon sukses. Client otomatis mengirim ulang payload yang sama.
   - *Kriteria Kelulusan:* Server tidak membuat duplikasi record data; mengembalikan respon sukses; data di client beralih ke state `SYNCED`.

10. **Uji Skenario 10 — Peringatan Batas Memori Penyimpanan Perangkat:**
    - *Langkah:* Penuhi penyimpanan memori perangkat hingga mendekati batas ambang aman. Coba ambil foto dokumentasi baru.
    - *Kriteria Kelulusan:* Aplikasi menampilkan dialog peringatan ruang penyimpanan hampir habis; mencegah pengambilan gambar yang berpotensi gagal; menyarankan pembersihan berkas lokal yang telah tersinkronisasi.

---

# 23. Open Questions (Sync & Offline Architecture)

Keputusan-keputusan teknis dan operasional berikut belum memiliki landasan definitif dari dokumen bisnis eksisting dan dicatat secara terbuka untuk disepakati bersama stakeholder sebelum tahap implementasi kode:

| # | Kode Referensi | Pertanyaan Arsitektur Offline | Implikasi Teknis pada Sinkronisasi & Aplikasi |
|---|---|---|---|
| **1** | **OQ-SYNC-01** | **Batasan Partisi Data Aset untuk Role HARSUS:** Apakah tim Harsus wajib mengunduh seluruh master aset di wilayah kerja ULP Sribhawono, atau dibatasi per penugasan/seksi tertentu? | Mengunduh seluruh aset ULP menjamin fleksibilitas inspeksi offline, namun meningkatkan durasi inisialisasi awal (*bootstrap*) dan konsumsi ruang penyimpanan SQLite. |
| **2** | **OQ-SYNC-02** | **Batasan Partisi Data Pohon untuk Role ROW:** Apakah regu ROW bekerja di seluruh seksi atau memiliki koridor kerja tetap per shift? | Menentukan apakah filter query sinkronisasi PowerSync untuk tabel `vegetasi` dapat di-scope berdasarkan seksi/posko atau harus mencakup seluruh ULP. |
| **3** | **OQ-SYNC-03** | **Konkurensi Akun Multi-Device per Posko:** Satu akun POSKO mewakili satu Posko. Apakah akun ini akan digunakan secara bersamaan pada lebih dari satu perangkat HP fisik dalam shift yang sama? | Jika multi-device terjadi secara rutin pada satu akun, potensi terjadinya OCC version conflict meningkat secara signifikan dan membutuhkan SOP penugasan fisik per regu. |
| **4** | **OQ-SYNC-04** | **Kebijakan Jaringan untuk Unggah Berkas Foto:** Apakah pengunggahan foto dokumentasi ke Amazon S3 diizinkan menggunakan kuota data seluler secara otomatis, atau disediakan opsi *"Hanya Unggah Melalui Wi-Fi"* di kantor posko? | Mengunggah foto resolusi tinggi via paket data seluler dapat membebani biaya operasional posko, namun opsi *Wi-Fi only* menunda ketersediaan bukti foto di Web Admin. |
| **5** | **OQ-SYNC-05** | **Masa Retensi Berkas Foto di Penyimpanan Lokal HP:** Berapa lama berkas fisik foto dipertahankan di storage internal HP setelah statusnya terkonfirmasi `UPLOADED` di cloud (parameter usulan: 24 jam vs 48 jam vs 7 hari)? | Retensi lebih lama memudahkan petugas meninjau riwayat visual tanpa koneksi internet, namun berisiko memenuhi kapasitas memori HP tipe *entry-level*. |
| **6** | **OQ-SYNC-06** | **Kebijakan Eksekusi Background Sync Android:** Bagaimana kebijakan sinkronisasi latar belakang saat layar mati atau aplikasi di-minimize? | Background work dapat dibatasi atau tertunda oleh Android Doze Mode dan kebijakan battery optimization / manajemen daya OEM perangkat. Kebutuhan permission khusus, Foreground Service, WorkManager constraints, atau exemption harus diverifikasi dan diuji saat implementation/testing. Kebijakan background sync tetap berstatus **OPEN QUESTION**. |
| **7** | **OQ-SYNC-07** | **Eskalasi Resolusi Konflik Tingkat Lanjut:** Jika terjadi perbedaan data kritis antara dua petugas yang tidak dapat diselesaikan via aturan standar, apakah konflik diteruskan ke antrian review Admin Web atau diselesaikan manual di HP? | Menentukan apakah perlu dibuatkan antrian khusus review konflik pada dashboard Admin Web untuk memutuskan versi data yang sah. |
| **8** | **OQ-SYNC-08** | **Matriks Jenis/Jumlah Foto Wajib:** Pekerjaan preventif mana saja yang wajib melampirkan foto Before + After, dan mana yang cukup After saja atau foto kondisi temuan? | Menentukan syarat validasi lokal sebelum status pekerjaan diizinkan beralih menjadi `SELESAI`. |
| **9** | **OQ-SYNC-09** | **Pencatatan Master Pohon Baru oleh Regu ROW:** Apakah regu ROW memiliki hak membuat master titik vegetasi baru secara offline, atau hanya mencatat eksekusi pada master yang sudah ada / mengajukan draft temuan untuk Admin? | Mempengaruhi perizinan sinkronisasi tulis (*upload write rule*) pada tabel master `vegetasi` dari perangkat mobile. |
| **10**| **OQ-SYNC-10** | **Parameter Kompresi & Ambang Jaringan:** Berapa batas resolusi maksimum foto (misal: 1920px), kualitas kompresi JPEG (misal: 80%), masa aktif signed URL (misal: 15 menit), dan ambang latensi mode degraded (misal: 3000ms)? | Parameter disimpan dalam `app_config` agar dapat disesuaikan tanpa rilis ulang aplikasi Android. |

---

*Dokumen ini menjadi baseline implementasi untuk aturan yang telah dikonfirmasi. Item yang ditandai OPEN QUESTION, PROPOSED, atau CONFIGURABLE belum merupakan keputusan final dan harus dikonfirmasi sebelum dikunci dalam implementation.*
