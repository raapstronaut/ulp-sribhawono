# DATA MODEL SPECIFICATION

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Business Flow v2 Baseline (Updated per 4 Oktober 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Source of Truth:** PRD.md · DESIGN_BRIEF.md · TECHNICAL_SPEC.md
**Scope:** ULP Sribhawono (Single-Tenant)
**Target RDBMS:** PostgreSQL (Server Authoritative) · SQLite (Mobile Local Draft/Cache)

---

# 1. Data Model Principles

1. **UUID + Business Key** — seluruh entitas master dan transaksi server memakai UUID sebagai Primary Key; kode PLN tetap disimpan sebagai unique/searchable business key.
2. **Hierarki Topologi & Ownership Wilayah:**
   - **Many-to-Many Posko ↔ Penyulang:** Satu penyulang (feeder) dapat melintasi beberapa Posko, dan satu Posko dapat menangani segmen dari beberapa penyulang (dihubungkan via pivot `posko_penyulang`).
   - **Section sebagai Penentu Ownership Aset:** Relasi Posko-Penyulang **TIDAK** otomatis menentukan Posko pemilik suatu aset. Ownership operasional aset fisik ditetapkan melalui **Section tervalidasi**.
   - **Section Final Pending:** Master `sections` final belum dibuat (status `BLOCKED`) karena menunggu kepastian data pemutus/proteksi eksternal yang terfilter (file FCO dan Switching ULP Sribhawono).
3. **Pemisahan Layer Data Nyata:**
   ```text
   RAW / SOURCE (Google Sheets / Snapshot Files)
          ↓
   STAGING (All String Tables: *_source_rows)
          ↓
   CANONICAL MASTERS (poskos, penyulangs, posko_penyulang)
          ↓
   OPERATIONAL TRANSACTIONS & AUDIT (Planned)
   ```
4. **Single-Tenant** — tidak ada kolom `ulp_id` di seluruh tabel inti; sistem dikhususkan untuk ULP Sribhawono.
5. **Offline Draft Bukan Tabel Transaksi Server** — SQLite mobile menyimpan draft/cache/outbox secara lokal sampai disubmit manual oleh user saat online. Skema database server tidak mereplikasi draft lokal.
6. **Photo Metadata Only** — PostgreSQL hanya menyimpan URL/path, jenis foto, geotag, dan hash metadata; binary file disimpan di Laravel Filesystem/S3.
7. **Team Account vs Petugas Lapangan** — tabel `users` mewakili akun login tim/unit kerja (role ADMIN, POSKO, HARSUS, INSPEKSI, ROW). Personil fisik pelaksana dicatat melalui relasi ke master `petugas`.
8. **No Hard Delete untuk Transaksi** — riwayat transaksi, perubahan status, dan audit log bersifat append-only.
9. **Server-Authoritative Validation** — Laravel memvalidasi role, transisi state mesin domain, optimistic locking version, dan idempotency key.

---

# 2. Entity Categories & Status Implementasi

| Kategori | Tabel / Entitas | Status Implementasi |
|---|---|---|
| **Core Topology Masters** | `poskos`, `penyulangs`, `posko_penyulang` | `IMPLEMENTED` (6 Posko, 16 Penyulang, 26 Relasi) |
| **Staging / Raw Sources** | `apkuk_source_rows`, `asset_source_rows`, `vegetasi_source_rows`, `gangguan_source_rows`, `section_pic_source_rows`, `keypoint_source_rows` | `IMPLEMENTED` (Semua string, import & audit teruji) |
| **Framework Tables** | `users`, `cache`, `jobs` | `IMPLEMENTED` (Framework baseline) |
| **Planned Reference Masters** | `sections`, `assets`, `apkuks`, `vegetasis`, `petugas` | `BLOCKED / PARTIAL / PLANNED` |
| **Planned Transactions** | `p0`, `p0_petugas`, `p0_reschedule_history`, `p0_context_events`, `work_orders`, `wo_petugas`, `wo_reschedule_history`, `pasila_sessions`, `pasila_actions`, `inspections`, `row_works`, `vegetasi_proposals`, `special_cases`, `documentations` | `PLANNED` (Target specification) |
| **History & Config** | `status_histories`, `audit_logs`, `app_configs` | `PLANNED` (Target specification) |

---

# 3. Entity Inventory

| Entity / Table | Layer | Purpose | Created By | Status |
|---|---|---|---|---|
| `poskos` | Core Master | 6 Posko resmi ULP Sribhawono | CLI Sync | `IMPLEMENTED` |
| `penyulangs` | Core Master | 16 Canonical feeder | CLI Sync | `IMPLEMENTED` |
| `posko_penyulang` | Core Pivot | Relasi Many-to-Many Posko ↔ Penyulang | CLI Sync | `IMPLEMENTED` |
| `apkuk_source_rows` | Staging | Data mentah Sheet1 APKUK (2,864 rows) | CLI Import | `IMPLEMENTED` |
| `asset_source_rows` | Staging | Data mentah ASSET_RAW (17,059 valid rows) | CLI Import | `IMPLEMENTED` |
| `vegetasi_source_rows` | Staging | Data mentah VEGETASI_RAW (16,983 rows) | CLI Import | `IMPLEMENTED` |
| `gangguan_source_rows` | Staging | Data mentah GANGGUAN_RAW (443 rows) | CLI Import | `IMPLEMENTED` |
| `section_pic_source_rows` | Staging | Data mentah SECTION_PIC_RAW (69 rows) | CLI Import | `IMPLEMENTED` |
| `keypoint_source_rows` | Staging | Data mentah KEYPOINT_RAW (129 rows) | CLI Import | `IMPLEMENTED` |
| `sections` | Domain Master | Batas segmen jaringan & Posko penanggung jawab | Admin/Sync | `BLOCKED` (Waiting FCO/Switching Sribhawono) |
| `assets` | Domain Master | Master operasional tiang/gardu/keypoint | Admin/Sync | `BLOCKED/PARTIAL` (Depends on Section) |
| `apkuks` | Domain Master | Inventaris status APKUK aktif pada aset | System/WO | `PLANNED` |
| `vegetasis` | Domain Master | Master pohon/titik vegetasi tervalidasi | Admin/Sync | `PLANNED` |
| `petugas` | Domain Master | Personil fisik pelaksana pekerjaan | Admin | `PLANNED` |
| `users` | Auth Master | Akun login tim (ADMIN/POSKO/HARSUS/INSPEKSI/ROW) | Admin/Seeder | `PARTIAL` |
| `p0` | Transaction | Pekerjaan prioritas tertinggi — executor: HARSUS | Admin (source context: ADMIN/INSPEKSI/PASILA/SPECIAL_CASE) | `PLANNED` |
| `p0_petugas` | Junction | Petugas pelaksana P0 (selected at completion) | HARSUS | `PLANNED` |
| `p0_reschedule_history` | History | Riwayat reschedule P0 (date-based) | HARSUS/Admin | `PLANNED` |
| `p0_context_events` | Context | Event/evidence tambahan pada P0 aktif | System/Admin | `PLANNED` |
| `work_orders` | Transaction | WO pemasangan APKUK (target 6/shift) | System/Admin | `PLANNED` |
| `wo_petugas` | Junction | Petugas pelaksana WO | POSKO | `PLANNED` |
| `wo_reschedule_history` | History | Riwayat reschedule WO | POSKO/System | `PLANNED` |
| `pasila_sessions` | Transaction | Sesi patroli PASILA per shift | POSKO | `PLANNED` |
| `pasila_actions` | Transaction | Tindakan patroli PASILA | POSKO | `PLANNED` |
| `inspections` | Transaction | Temuan inspeksi kondisi aset/jaringan | INSPEKSI | `PLANNED` |
| `row_works` | Transaction | Pekerjaan rabas/pangkas/tebang | ROW/POSKO(insidental) | `PLANNED` |
| `vegetasi_proposals` | Transaction | Usulan titik vegetasi baru | INSPEKSI | `PLANNED` |
| `special_cases` | Transaction | Indikasi >3 trip/penyulang/bulan kalender (recommendation) | System | `PLANNED` |
| `documentations` | Media | Metadata foto BEFORE/AFTER/TEMUAN/SURVEY | Mobile/Web | `PLANNED` |
| `status_histories` | History | Audit transisi status transaksi | System | `PLANNED` |
| `audit_logs` | History | Audit log koreksi admin & konfigurasi | System | `PLANNED` |
| `app_configs` | Config | Parameter operasional & shift | Admin | `PLANNED` |

---

# 4. Detailed Entity Definitions

## 4.1 Implemented Core Topology Masters (Database Nyata)

### 4.1.1 `poskos`
Tabel master 6 Posko resmi ULP Sribhawono:
```sql
CREATE TABLE poskos (
    id UUID PRIMARY KEY,
    kode_posko VARCHAR(255) UNIQUE NOT NULL,
    nama_posko VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
CREATE INDEX poskos_is_active_index ON poskos (is_active);
```

### 4.1.2 `penyulangs`
Tabel master 16 Canonical Feeder resmi:
```sql
CREATE TABLE penyulangs (
    id UUID PRIMARY KEY,
    kode_penyulang VARCHAR(255) UNIQUE NOT NULL,
    nama_penyulang VARCHAR(255) NOT NULL,
    gardu_induk VARCHAR(255) NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
CREATE INDEX penyulangs_is_active_index ON penyulangs (is_active);
```

### 4.1.3 `posko_penyulang`
Tabel pivot relasi Many-to-Many antara Posko dan Penyulang:
```sql
CREATE TABLE posko_penyulang (
    posko_id UUID NOT NULL REFERENCES poskos(id) ON DELETE CASCADE,
    penyulang_id UUID NOT NULL REFERENCES penyulangs(id) ON DELETE CASCADE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (posko_id, penyulang_id)
);
CREATE INDEX posko_penyulang_posko_id_index ON posko_penyulang (posko_id);
CREATE INDEX posko_penyulang_penyulang_id_index ON posko_penyulang (penyulang_id);
```

---

## 4.2 Implemented Staging / Raw Source Tables (Database Nyata)

Seluruh kolom data sumber disimpan mentah sebagai tipe string untuk memastikan tidak terjadi distorsi/kehilangan informasi sebelum canonicalization:

### 4.2.1 `apkuk_source_rows`
Staging data Sheet1 APKUK (2,864 baris):
- `id` (BIGINT PK AUTO_INCREMENT)
- `source_id` (VARCHAR NULL), `tanggal` (DATE NULL), `petugas_raw` (VARCHAR NULL)
- `penyulang_raw` (VARCHAR NULL), `section_raw` (VARCHAR NULL), `asset_code_raw` (VARCHAR NULL)
- `naik_dari_raw` (VARCHAR NULL), `terpasang_bh` (SMALLINT NULL), `kurang_bh` (SMALLINT NULL)
- `status_raw` (VARCHAR NULL), `koordinat_raw` (VARCHAR NULL)
- `latitude` (DECIMAL(10,7) NULL), `longitude` (DECIMAL(10,7) NULL)
- `foto_raw` (TEXT NULL), `source_row_number` (INT NULL), `imported_at` (TIMESTAMP NULL)
- `created_at`, `updated_at`

### 4.2.2 `asset_source_rows`
Staging data ASSET_RAW (17,059 valid aset):
- `id` (BIGINT PK AUTO_INCREMENT)
- `id_aset_raw` (VARCHAR NULL), `nama_aset_raw` (VARCHAR NULL), `jenis_aset_raw` (VARCHAR NULL)
- `penyulang_raw` (VARCHAR NULL), `latitude_raw` (VARCHAR NULL), `longitude_raw` (VARCHAR NULL)
- `status_raw` (VARCHAR NULL), `source_row_number` (INT NULL), `imported_at` (TIMESTAMP NULL)
- `created_at`, `updated_at`

### 4.2.3 `vegetasi_source_rows`
Staging data VEGETASI_RAW (16,983 baris pohon):
- `id` (BIGINT PK AUTO_INCREMENT)
- `no_raw`, `nama_pohon_raw`, `up3_raw`, `ulp_raw`, `penyulang_raw`, `id_jtm_raw`, `id_tiang_raw`, `nama_tiang_raw`, `jenis_pohon_raw`, `pertumbuhan_pohon_raw`, `jarak_aman_raw`, `jarak_bahaya_raw`, `kondisi_raw`, `section_raw`, `eam_raw`, `latitude_raw`, `longitude_raw`, `jarak_pohon_raw`, `jarak_pohon_saat_ini_raw`, `izin_padam_raw`, `izin_tebang_bawah_raw`, `tanggal_update_raw`, `tanggal_periksa_raw`, `tanggal_perkiraan_bahaya_raw`, `user_pembuat_raw`, `user_update_raw`, `keterangan_raw`, `id_pohon_raw` (semua VARCHAR/TEXT NULL)
- `source_row_number` (INT NULL), `imported_at` (TIMESTAMP NULL), `created_at`, `updated_at`

### 4.2.4 `gangguan_source_rows`
Staging data GANGGUAN_RAW (443 baris gangguan):
- `id` (BIGINT PK AUTO_INCREMENT)
- `id_gangguan_raw`, `penyulang_raw`, `peralatan_raw`, `ulp_raw`, `tanggal_kejadian_raw`, `kode_gangguan_raw`, `uraian_penyebab_raw`, `penyebab_raw`, `lepas_raw`, `masuk_raw`, `beban_amp_raw`, `rele_kerja_raw`, `arus_gangguan_amp_raw`, `lama_padam_menit_raw`, `ens_kwh_raw`, `sifat_gangguan_raw`, `id_aset_raw`, `section_id_raw`, `posko_id_raw`, `latitude_raw`, `longitude_raw`, `jenis_hewan_raw`, `foto_ref_raw`, `sumber_data_raw` (semua VARCHAR/TEXT NULL)
- `source_row_number` (INT NULL), `imported_at` (TIMESTAMP NULL), `created_at`, `updated_at`

### 4.2.5 `section_pic_source_rows`
Staging data SECTION_PIC_RAW (69 baris pemutus/feeder):
- `id` (BIGINT PK AUTO_INCREMENT)
- `penyulang_raw` (VARCHAR NULL — nullable karena visual merge di spreadsheet)
- `pemutus_raw` (VARCHAR NULL)
- `posko_pic_raw` (VARCHAR NULL)
- `source_row_number` (INT NULL), `imported_at` (TIMESTAMP NULL), `created_at`, `updated_at`

### 4.2.6 `keypoint_source_rows`
Staging data KEYPOINT_RAW (129 baris keypoint):
- `id` (BIGINT PK AUTO_INCREMENT)
- `penyulang_raw` (VARCHAR NULL), `jenis_raw` (VARCHAR NULL), `nama_raw` (VARCHAR NULL), `koordinat_raw` (VARCHAR NULL), `note_raw` (TEXT NULL)
- `source_row_number` (INT NULL), `imported_at` (TIMESTAMP NULL), `created_at`, `updated_at`

---

## 4.3 Planned Domain Masters & Reference Schema (Target Lanjutan)

### 4.3.1 `sections` (Status: BLOCKED)
> Menunggu ketersediaan data pemutus FCO dan Switching ULP Sribhawono yang sudah terfilter.
- `id UUID PRIMARY KEY`
- `kode_section VARCHAR UNIQUE NOT NULL`
- `nama_section VARCHAR NOT NULL`
- `penyulang_id UUID REFERENCES penyulangs(id) NOT NULL`
- `posko_id UUID REFERENCES poskos(id) NOT NULL`
- `is_active BOOLEAN NOT NULL DEFAULT true`
- timestamps

### 4.3.2 `assets` (Status: BLOCKED / PARTIAL)
> Master operasional aset fisik. Mapping ke Posko diturunkan melalui Section.
- `id UUID PRIMARY KEY`
- `kode_aset VARCHAR UNIQUE NOT NULL`
- `section_id UUID REFERENCES sections(id) NOT NULL`
- `jenis_aset VARCHAR CHECK (jenis_aset IN ('TIANG','GARDU','KEYPOINT','PEMUTUS'))`
- `nama_aset VARCHAR NOT NULL`
- `latitude DOUBLE PRECISION NULL`
- `longitude DOUBLE PRECISION NULL`
- `status VARCHAR`
- `is_active BOOLEAN NOT NULL DEFAULT true`
- timestamps

### 4.3.3 `apkuks` (Status: PLANNED)
- `id UUID PRIMARY KEY`
- `kode_apkuk VARCHAR UNIQUE`
- `asset_id UUID REFERENCES assets(id) NOT NULL`
- `tgl_pasang TIMESTAMPTZ NULL`
- `status VARCHAR NOT NULL DEFAULT 'AKTIF'` (`AKTIF`, `NON_AKTIF`, `RUSAK`)
- timestamps

### 4.3.4 `vegetasis` (Status: PLANNED)
- `id UUID PRIMARY KEY`
- `kode_vegetasi VARCHAR UNIQUE`
- `section_id UUID REFERENCES sections(id) NOT NULL`
- `asset_terdekat_id UUID REFERENCES assets(id) NULL`
- `latitude DOUBLE PRECISION NULL`, `longitude DOUBLE PRECISION NULL`
- `jenis_pohon VARCHAR`, `jarak_aman DOUBLE PRECISION`, `kondisi VARCHAR`
- `last_row_at TIMESTAMPTZ NULL`, `next_row_date DATE NULL`
- `status VARCHAR NOT NULL DEFAULT 'TERDAFTAR'`
- timestamps

### 4.3.5 `petugas` (Status: PLANNED)
- `id UUID PRIMARY KEY`
- `kode_petugas VARCHAR UNIQUE NULL`
- `nama_petugas VARCHAR NOT NULL`
- `posko_id UUID REFERENCES poskos(id) NULL`
- `is_active BOOLEAN NOT NULL DEFAULT true`
- timestamps

### 4.3.6 `users` (Status: PARTIAL)
- `id UUID PRIMARY KEY`
- `username VARCHAR UNIQUE NOT NULL`
- `email VARCHAR UNIQUE NULL`
- `password VARCHAR NOT NULL`
- `role VARCHAR CHECK (role IN ('ADMIN','POSKO','HARSUS','INSPEKSI','ROW'))`
- `posko_id UUID REFERENCES poskos(id) NULL` — wajib untuk role POSKO
- `nama_lengkap VARCHAR NULL`
- `is_active BOOLEAN NOT NULL DEFAULT true`
- timestamps

Notes:
- POSKO accounts require/link one Posko.
- HARSUS accounts: no required Posko scope (operates across whole ULP).
- INSPEKSI accounts: no required Posko scope (operates across whole ULP).

---

## 4.4 Planned Operational Transaction Entities (Target Lanjutan)

### 4.4.1 `p0`

| Field | Type | Nullable | Notes |
|---|---|---:|---|
| id | UUID PK | NO | server UUID |
| display_code | TEXT UNIQUE | NO | kode manusiawi |
| source_type | TEXT | NO | ADMIN/INSPEKSI/PASILA/SPECIAL_CASE |
| source_id | UUID | YES | referensi sumber |
| asset_id | UUID FK | **NO** | P0 executable selalu pada asset tervalidasi |
| section_id | UUID FK | NO | hasil mapping asset/wilayah |
| posko_id | UUID FK | NO | **lokasi/wilayah operasional aset** (bukan executor) |
| latitude | FLOAT8 | YES | snapshot lokasi opsional |
| longitude | FLOAT8 | YES | snapshot lokasi opsional |
| lokasi_text | TEXT | YES | deskripsi lokasi |
| issue_type | TEXT | YES | digunakan untuk duplicate/Special Case comparison |
| uraian | TEXT | NO | konteks eksekusi |
| kebutuhan_padam | TEXT | NO | TANPA_PADAM/PERLU_PADAM, snapshot saat P0 dibuat |
| kebutuhan_material | TEXT | NO | isi `TIDAK_ADA` jika tidak membutuhkan material |
| status | TEXT | NO | BARU/DITERIMA/DIKERJAKAN/RESCHEDULED/SELESAI/CANCELLED |
| scheduled_date | DATE | YES | target tanggal |
| version | INTEGER | NO | OCC |
| created_at | TIMESTAMPTZ | NO | server create |
| updated_at | TIMESTAMPTZ | NO | server update |
| completed_at | TIMESTAMPTZ | YES | waktu selesai aktual (`occurred_at`) |
| completed_submitted_at | TIMESTAMPTZ | YES | waktu completion diterima server |

**IMPORTANT:** `posko_id` pada P0 merepresentasikan **lokasi/wilayah operasional aset**, BUKAN berarti POSKO yang mengeksekusi P0. Executor P0 = HARSUS.

**REMOVED:** `scheduled_shift` — HARSUS tidak berbasis shift. P0 tidak menggunakan Shift A/B/C.

**Tidak menyimpan `parent_p0_id` untuk reschedule.** Reschedule tetap record P0 yang sama.

**P0 menyimpan snapshot kebutuhan eksekusi.** Untuk source INSPEKSI/PASILA nilai dicopy ketika P0 dibuat; untuk source ADMIN diisi Admin; untuk Special Case harus tersedia/ditinjau oleh Admin sebelum P0 dibuat. Unknown point tetap berada pada source record sampai Admin memetakan/membuat asset + Section/Posko, baru P0 executable dibuat.

**P0 Petugas Rule:** Petugas aktual dipilih oleh HARSUS saat completion/submission P0, BUKAN saat menerima atau memulai P0.

### 4.4.2 `p0_petugas`
- `p0_id`
- `petugas_id`
- composite unique
- Recorded at completion by HARSUS.

### 4.4.3 `p0_reschedule_history`
- `id UUID PK`
- `p0_id UUID FK NOT NULL`
- `reschedule_type TEXT CHECK IN ('CUSTOM')` — only CUSTOM (date-based) reschedule
- `from_date`
- `target_date`
- `reason TEXT NOT NULL`
- `requested_by UUID FK users`
- `approval_status TEXT CHECK IN ('PENDING','APPROVED','REJECTED')`
- `approved_by UUID NULL`
- `approved_at TIMESTAMPTZ NULL`
- `rejection_reason TEXT NULL`
- timestamps

**REMOVED:** `NEXT_SHIFT` reschedule type (HARSUS not shift-based).
**REMOVED:** `from_shift`, `target_shift` columns.

CUSTOM: PENDING → APPROVED/REJECTED; selama PENDING status P0 tetap `DIKERJAKAN`, setelah APPROVED menjadi `RESCHEDULED`, dan setelah REJECTED tetap `DIKERJAKAN`. Saat pekerjaan dilanjutkan, status kembali `DIKERJAKAN`.

### 4.4.4 `p0_context_event`
Untuk menambahkan context/evidence baru ke P0 aktif tanpa membuat P0 duplikat.
- `id UUID PK`
- `p0_id UUID FK`
- `source_type`
- `source_id`
- `event_type`
- `description`
- `created_at`

Dipakai antara lain ketika Special Case menemukan **masalah yang sama** pada aset yang sudah memiliki P0 aktif.

### 4.4.5 `work_order`
- `id UUID PK`
- `display_code TEXT UNIQUE`
- `tanggal DATE`
- `shift TEXT CHECK IN ('A','B','C')`
- `posko_id UUID FK`
- `section_id UUID FK`
- `asset_id UUID FK NOT NULL`
- `jenis_wo TEXT CHECK (jenis_wo='PEMASANGAN_APKUK')` pada MVP
- `source TEXT CHECK IN ('SYSTEM','ADMIN')`
- `priority_score NUMERIC NULL`
- `alasan_prioritas TEXT NOT NULL`
- `status TEXT CHECK IN ('BARU','DITERIMA','DIKERJAKAN','RESCHEDULED','SELESAI','CANCELLED')`
- `reschedule_reason TEXT NULL`
- `version INTEGER NOT NULL DEFAULT 1`
- `completed_at TIMESTAMPTZ NULL` — waktu selesai aktual
- `completed_submitted_at TIMESTAMPTZ NULL` — waktu completion diterima server
- timestamps

**WO completion side effect:** transaksi completion `PEMASANGAN_APKUK` wajib membuat atau memperbarui `apkuk` menjadi `AKTIF` pada `asset_id` terkait, dengan `tgl_pasang = completed_at`; `apkuk_code` boleh diisi bila tersedia. Operasi ini harus berada dalam logical transaction yang sama dengan completion agar generator WO berikutnya tidak memilih aset yang sudah terpasang.

### 4.4.6 `wo_petugas`
- `wo_id`
- `petugas_id`

### 4.4.7 `wo_reschedule_history`
- `id UUID PK`
- `wo_id UUID FK NOT NULL`
- `from_date DATE NOT NULL`
- `from_shift TEXT NOT NULL`
- `target_date DATE NOT NULL`
- `target_shift TEXT NOT NULL`
- `reason TEXT NOT NULL`
- `requested_by UUID FK users`
- `occurred_at TIMESTAMPTZ NOT NULL`
- `recorded_at TIMESTAMPTZ NOT NULL`

Carry-over WO mengisi kuota shift target lebih dulu. Generator hanya membuat `6 - jumlah_carry_over_aktif` WO baru per Posko/shift (minimum 0).

### 4.4.8 `pasila_session`
- `id UUID PK`
- `tanggal DATE`
- `shift A/B/C`
- `posko_id UUID FK`
- `recommended_section_id UUID NULL`
- `recommended_asset_id UUID NULL`
- `recommendation_reason TEXT NULL`
- `selected_section_id UUID FK NOT NULL`
- `selected_asset_id UUID NULL`
- `is_override BOOLEAN DEFAULT false`
- `override_reason TEXT NULL`
- `status TEXT CHECK IN ('BERJALAN','SELESAI')`
- `started_at TIMESTAMPTZ NOT NULL` — waktu sesi dimulai di device
- `submitted_at TIMESTAMPTZ NOT NULL` — waktu sesi diterima server
- timestamps

Jika `is_override=true`, `override_reason` wajib. Tambahkan unique constraint `(posko_id, tanggal, shift)` agar hanya ada satu sesi PASILA per Posko/shift.

### 4.4.9 `pasila_action`
- `id UUID PK`
- `session_id UUID FK`
- `asset_id UUID NULL`
- `latitude`, `longitude` nullable
- `action_type TEXT`
- `uraian TEXT`
- `status TEXT` — `SELESAI_LANGSUNG` / `TIDAK_DAPAT_DIEKSEKUSI`
- `alasan_tidak_dieksekusi TEXT NULL`
- `kebutuhan_padam TEXT NULL CHECK IN ('TANPA_PADAM','PERLU_PADAM')`
- `kebutuhan_material TEXT NULL`
- `is_row_incidental BOOLEAN`
- `escalated_to_p0 BOOLEAN`
- `p0_id UUID NULL`
- `occurred_at TIMESTAMPTZ NOT NULL` — waktu action dicatat/dilakukan di device
- `submitted_at TIMESTAMPTZ NOT NULL` — waktu diterima server
- timestamps

Jika status TIDAK_DAPAT_DIEKSEKUSI, alasan wajib dan kebutuhan padam/material wajib terisi. Jika `asset_id` belum tervalidasi, action tetap tersimpan sebagai temuan tetapi eskalasi P0 menunggu Admin mapping asset terlebih dahulu. Escalation PASILA adalah **proposal/request yang memerlukan Admin approval**; escalation **TIDAK** langsung membuat P0 executable. Saat Admin menyetujui dan P0 dibuat, kebutuhan eksekusi dicopy sebagai snapshot dan P0 ditugaskan ke HARSUS.

### 4.4.10 `pasila_action_petugas`
- `action_id`
- `petugas_id`

### 4.4.11 `inspections` (was: `harsus_inspections`)
> **RENAMED** from `harsus_inspections` to `inspections`. Created by role **INSPEKSI**, not HARSUS.

- `id UUID PK`
- `inspected_by UUID FK users NOT NULL` — INSPEKSI user who created the inspection
- `asset_id UUID NULL`
- `section_id UUID NULL`
- `latitude FLOAT8 NULL`
- `longitude FLOAT8 NULL`
- `temuan TEXT NOT NULL`
- `kondisi_aset TEXT CHECK IN ('BAIK','RUSAK_RINGAN','RUSAK_BERAT','KRITIS')`
- `kebutuhan_padam TEXT CHECK IN ('TANPA_PADAM','PERLU_PADAM')`
- `kebutuhan_material TEXT NOT NULL` — isi `TIDAK_ADA` bila tidak membutuhkan material
- `rekomendasi TEXT`
- `perlu_p0 BOOLEAN`
- `p0_id UUID NULL`
- `validation_status TEXT CHECK IN ('NOT_NEEDED','PENDING_ASSET_VALIDATION','VALIDATED','REJECTED')`
- `occurred_at TIMESTAMPTZ NOT NULL`
- `submitted_at TIMESTAMPTZ NOT NULL`
- timestamps

Jika `asset_id` null, koordinat wajib dan status default `PENDING_ASSET_VALIDATION`. P0 executable belum dibuat sampai Admin memetakan atau membuat asset + Section/Posko. Jika `perlu_p0=true`, Admin memvalidasi/menyetujui terlebih dahulu, baru P0 dibuat dengan snapshot `kebutuhan_padam` dan `kebutuhan_material` dari inspeksi. P0 yang dibuat ditugaskan ke HARSUS.

### 4.4.12 `row_work`
- `id UUID PK`
- `vegetasi_id UUID FK`
- `section_id UUID FK`
- `source TEXT CHECK IN ('ROW','PASILA_INSIDENTAL')`
- `source_action_id UUID NULL`
- `action_type TEXT CHECK IN ('RABAS','PANGKAS','TEBANG')`
- `catatan TEXT NULL`
- `status TEXT`
- `performed_at TIMESTAMPTZ` — waktu pekerjaan aktual/input
- `submitted_at TIMESTAMPTZ NOT NULL` — waktu diterima server
- timestamps

Tidak ada field teks kondisi sebelum/sesudah wajib.

### 4.4.13 `row_work_petugas`
- `row_work_id`
- `petugas_id`

### 4.4.14 `vegetasi_proposal`
- `id UUID PK`
- `submitted_by UUID FK users` — submitted by **INSPEKSI** (not ROW)
- `section_id UUID NULL`
- `asset_terdekat_id UUID NULL`
- `latitude FLOAT8 NOT NULL`
- `longitude FLOAT8 NOT NULL`
- `catatan TEXT`
- `status TEXT CHECK IN ('PENDING','APPROVED','REJECTED')`
- `validated_by UUID NULL`
- `validated_at TIMESTAMPTZ NULL`
- `vegetasi_id UUID NULL`
- `occurred_at TIMESTAMPTZ NOT NULL`
- `submitted_at TIMESTAMPTZ NOT NULL`
- timestamps

**Authorization change:** `vegetasi_proposals` are now created/submitted by **INSPEKSI**, NOT ROW.

### 4.4.15 `special_case`
- `id UUID PK`
- `penyulang_id UUID FK`
- `periode_bulan DATE NOT NULL` — simpan first-day of calendar month
- `jumlah_trip INTEGER NOT NULL`
- `threshold INTEGER NOT NULL DEFAULT 3`
- `status TEXT` — e.g., DETECTED, ADMIN_REVIEWED, P0_CREATED, DISMISSED
- timestamps

Rule trigger: jumlah trip **>3** pada bulan kalender.

**IMPORTANT:** Special Case **TIDAK** otomatis membuat P0. Special Case adalah **system recommendation/indication** yang memerlukan keputusan eksplisit Admin sebelum P0 dibuat. Admin dapat mereview dan mengedit informasi P0 yang direkomendasikan sebelum membuat P0.

### 4.4.16 `special_case_asset`
- `special_case_id`
- `asset_id`
- `issue_type`
- `decision TEXT` — `MERGED_TO_ACTIVE_P0`, `NEW_P0`, `WO_PRIORITY_ONLY`, `DISMISSED`, dll
- `p0_id UUID NULL`

Decision rule: **masalah sama** + P0 aktif → `MERGED_TO_ACTIVE_P0` dan append `p0_context_event`; **masalah berbeda** → `NEW_P0` diperbolehkan setelah Admin decision dan kebutuhan eksekusi tervalidasi. Taxonomy/comparator `issue_type` final adalah gate sebelum implementasi Special Case production. All decisions are explicit Admin actions.

### 4.4.17 `documentation`
- `id UUID PK`
- `entity_type TEXT`
- `entity_id UUID`
- `photo_type TEXT CHECK IN ('BEFORE','AFTER','TEMUAN','SURVEY')`
- `storage_disk TEXT`
- `storage_key TEXT NOT NULL`
- `captured_at TIMESTAMPTZ NOT NULL`
- `submitted_at TIMESTAMPTZ NULL`
- `latitude`, `longitude` nullable
- `created_by UUID`
- timestamps

Semua pekerjaan yang disubmit wajib minimal satu foto sesuai rule per entity.

---

## 4.5 History / Audit

### `status_history`
Append-only status timeline dengan field minimum:
- `id UUID PK`
- `entity_type TEXT NOT NULL`
- `entity_id UUID NOT NULL`
- `from_status TEXT NULL`
- `to_status TEXT NOT NULL`
- `actor_user_id UUID NULL` — null bila system job
- `reason TEXT NULL`
- `occurred_at TIMESTAMPTZ NOT NULL` — waktu aktivitas/input sebenarnya
- `recorded_at TIMESTAMPTZ NOT NULL` — waktu server menerima/mencatat
- `metadata JSONB NULL`

### `audit_log`
Append-only, field minimum:
- `id UUID PK`
- `actor_user_id UUID NULL`
- `action TEXT NOT NULL`
- `entity_type TEXT NOT NULL`
- `entity_id UUID NULL`
- `before_data JSONB NULL`
- `after_data JSONB NULL`
- `reason TEXT NULL`
- `occurred_at TIMESTAMPTZ NOT NULL`
- `recorded_at TIMESTAMPTZ NOT NULL`
- `request_id UUID NULL`

Mencatat Admin correction, master CRUD, validation proposal, reschedule approval/reject, config change, conflict resolution, Special Case decision, dan aksi penting sistem.

Koreksi setelah submit dilakukan langsung oleh ADMIN sesuai permission dan selalu meninggalkan before/after snapshot. Tidak menggunakan workflow `correction_request` sebagai requirement MVP.

---

## 4.6 Configuration

### `app_config`
Key/value JSON atau typed config untuk parameter yang dapat berubah.

Default awal:
```json
{
  "shift_A": {"start":"08:00","end":"16:00"},
  "shift_B": {"start":"16:00","end":"00:00"},
  "shift_C": {"start":"00:00","end":"08:00"},
  "special_case_trip_threshold": 3,
  "special_case_period": "CALENDAR_MONTH",
  "wo_target_per_shift_per_posko": 6
}
```

Note: Shift config applies to POSKO operations. HARSUS is **NOT** shift-based.

---

# 5. Relationships

- **Posko N:M Penyulang** (melalui tabel pivot `posko_penyulang` — 26 relasi terverifikasi pada 6 Posko & 16 Penyulang)
- **Section N:1 Penyulang & Section N:1 Posko** (satu Section berinduk pada 1 Penyulang dan 1 Posko penanggung jawab aktif)
- **Asset N:1 Section** (ownership operasional aset diturunkan melalui Section tervalidasi)
- **Asset 1:N APKUK / History / Work**
- **P0 N:M Petugas** (selected at completion by HARSUS)
- **WO N:M Petugas**
- **PASILA Session 1:N Action**
- **PASILA Action N:M Petugas**
- **Inspection N:1 INSPEKSI User**
- **ROW Work N:M Petugas**
- **Vegetasi Proposal N:1 INSPEKSI User**
- **Special Case N:M Asset**
- **Entity 1:N Documentation**

Unknown-point INSPEKSI/PASILA dapat memiliki relasi asset kosong sampai Admin validation. P0 executable selalu memiliki asset, Section, dan Posko tervalidasi. `posko_id` pada P0 = lokasi/wilayah operasional, bukan executor.

---

# 6. ERD — Logical

```text
POSKO ──< SECTION >── PENYULANG
            │
            └──< ASSET ──< APKUK
                   │
                   ├──< P0 >── PETUGAS (at completion, executor: HARSUS)
                   ├──< WORK_ORDER >── PETUGAS
                   └──< INSPECTION (created by INSPEKSI)

POSKO ──< PASILA_SESSION ──< PASILA_ACTION >── PETUGAS

VEGETASI ──< ROW_WORK >── PETUGAS
INSPEKSI USER ──< VEGETASI_PROPOSAL ──(Admin validate)──> VEGETASI

PENYULANG ──< SPECIAL_CASE ──< SPECIAL_CASE_ASSET

TRANSACTION ──< DOCUMENTATION
TRANSACTION ──< STATUS_HISTORY
ALL CONTROLLED CHANGES ──< AUDIT_LOG
```

---

# 7. Source-to-Target Mapping

Initial import mempertahankan business key PLN. Mapping rinci per spreadsheet dilakukan setelah profiling source final. Jangan mengasumsikan kolom yang tidak tersedia.

---

# 8. Required Initial Data

Minimum sebelum operasi nyata:
- Posko
- Penyulang
- Section
- Asset
- APKUK
- Vegetasi bila tersedia
- Petugas bila tersedia
- Users/tim account (5 roles)
- gangguan source untuk analytics

---

# 9. Data Generated by New System

P0 (executor: HARSUS), WO (executor: POSKO), PASILA (executor: POSKO), Inspections (created by: INSPEKSI), ROW work, proposal vegetasi (created by: INSPEKSI), Special Case (system recommendation), documentation, histories, audit logs.

---

# 10. Data Integrity Rules

1. WO APKUK tidak boleh duplicate pada asset yang memiliki APKUK aktif atau WO APKUK aktif identik.
2. P0 executable wajib memiliki asset + Section + Posko tervalidasi dan snapshot kebutuhan padam/material. `posko_id` = lokasi, executor = HARSUS.
3. Inspection (INSPEKSI) asset-null wajib koordinat dan pending validation; P0 belum dibuat sampai validation selesai dan Admin approves.
4. PASILA override wajib alasan.
5. PASILA action gagal wajib alasan.
6. P0 reschedule membutuhkan Admin approval (target tanggal + alasan).
7. Completed server transaction tidak diedit user lapangan.
8. Admin correction wajib audit log.
9. Photo type hanya BEFORE/AFTER/TEMUAN/SURVEY.
10. Submit pekerjaan wajib memenuhi minimal photo rule.
11. OCC version wajib untuk P0/WO dan entity kritis yang dapat diupdate dari beberapa device.
12. Maksimal satu APKUK berstatus `AKTIF` per asset pada satu waktu (enforce via partial unique index/validation).
13. PASILA session unique per `(posko_id,tanggal,shift)`.
14. Special Case TIDAK otomatis membuat P0; Admin decision required.
15. PASILA escalation TIDAK langsung membuat P0; Admin approval required.
16. Vegetasi proposals created by INSPEKSI only, not ROW.
17. P0 Petugas recorded at completion only, not at accept/start.

---

# 11. Offline Draft and Submission Considerations

Server database tidak melakukan bidirectional sync dengan SQLite.

Mobile menyimpan secara lokal:
- cache_asset/section/posko/petugas;
- draft payload;
- local photo path;
- idempotency key;
- expected server version;
- input timestamp;
- submission status.

Saat online, Laravel menerima submission dan menyimpan `submitted_at` terpisah dari waktu aktivitas/input yang dikirim client.

---

# 12. Index and Query Requirements

Index minimum:
- `p0(status,scheduled_date)` — no shift index for P0 (HARSUS not shift-based)
- `p0(posko_id,status)` — posko_id as location filter
- `work_order(posko_id,tanggal,shift,status)`
- `work_order(asset_id,jenis_wo,status)`
- `pasila_session(posko_id,tanggal,shift)`
- `inspections(validation_status)`
- `inspections(inspected_by)`
- `vegetasi_proposal(status)`
- `vegetasi_proposal(submitted_by)`
- `special_case(penyulang_id,periode_bulan)`
- `documentation(entity_type,entity_id)`
- `status_history(entity_type,entity_id,created_at)`
- `audit_log(entity_type,entity_id,created_at)`

---

# 13. Data Lifecycle

Master menggunakan soft deactivate. Transaction tidak di-hard-delete selama operasional. Retention period jangka panjang tetap menunggu kebijakan stakeholder. Draft lokal dapat dihapus dari device setelah server mengonfirmasi SUBMITTED dan retention lokal minimum terpenuhi.

---

# 14. Open Questions

1. Formula/model ML final untuk `priority_score` WO.
2. Formula `next_row_date`.
3. Retention/archival policy data dan foto.
4. Mapping final source data gangguan.
5. Detail storage production.
6. Taxonomy `issue_type` dan aturan comparator "masalah sama vs berbeda" sebelum Phase Special Case.

Yang sudah final dan bukan lagi OQ: jam shift, month-window Special Case, akun berbasis tim, unknown asset inspection (INSPEKSI), photo enum, correction Admin, offline draft/manual submit, WO APKUK-only, target 6 WO, PASILA recommendation+override, HARSUS = Pemeliharaan Khusus, HARSUS executes P0, INSPEKSI performs inspections, ROW execute-only, vegetation proposals by INSPEKSI, Special Case = Admin recommendation (not auto-P0), P0 not shift-based.
