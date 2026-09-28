# DATA MODEL SPECIFICATION

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Final / Locked Baseline  
**Source of Truth:** PRD.md · DESIGN_BRIEF.md · TECHNICAL_SPEC.md  
**Scope:** ULP Sribhawono (Single-Tenant)  
**Target RDBMS:** PostgreSQL

---

# 1. Data Model Principles

1. **UUID + Business Key** — seluruh entitas server memakai UUID sebagai PK; kode PLN tetap disimpan sebagai unique/searchable business key.
2. **Hierarki Wilayah** — Penyulang ← Section → Posko; Asset → Section. Posko/Penyulang asset diturunkan melalui Section.
3. **Single-Tenant** — tidak ada `ulp_id` di seluruh tabel inti.
4. **Master vs Transaction** — master: Posko, Penyulang, Section, Asset, APKUK, Vegetasi, Petugas, User. Transaction: P0, WO, PASILA, HARSUS, ROW, Special Case, Documentation.
5. **Offline Draft tidak menjadi server transaction** — SQLite mobile menyimpan draft/cache/outbox secara lokal sampai submit berhasil. Server schema tidak perlu mereplikasi seluruh local draft model.
6. **Photo metadata only** — PostgreSQL tidak menyimpan binary foto.
7. **Team account vs petugas** — akun mewakili tim/unit; petugas aktual melalui junction table.
8. **No hard delete untuk transaksi** — histori dan audit dipertahankan.
9. **Server-authoritative validation** — Laravel memvalidasi role, state transition, version, business rules, dan idempotency.
10. **Configuration** — shift dan parameter operasional yang dapat berubah disimpan di `app_config`, tetapi default shift final saat ini A 08–16, B 16–00, C 00–08.

---

# 2. Entity Categories

## A. Master / Reference
- `posko`
- `penyulang`
- `section`
- `asset`
- `apkuk`
- `vegetasi`
- `petugas`
- `users`

## B. External / Raw
- `gangguan_raw`
- `gangguan_mv`

## C. Transactions
- `p0`
- `p0_petugas`
- `p0_reschedule_history`
- `p0_context_event`
- `work_order`
- `wo_petugas`
- `wo_reschedule_history`
- `pasila_session`
- `pasila_action`
- `pasila_action_petugas`
- `harsus_inspection`
- `row_work`
- `row_work_petugas`
- `vegetasi_proposal`
- `special_case`
- `special_case_asset`
- `documentation`

## D. History / Audit
- `status_history`
- `audit_log`
- `asset_history_view`

## E. Configuration
- `app_config`

---

# 3. Entity Inventory

| Entity | Purpose | Created By | Notes |
|---|---|---|---|
| posko | master wilayah | Admin/import | 1 akun POSKO terikat satu Posko |
| penyulang | master feeder | Admin/import | Special Case dihitung per penyulang |
| section | segmentasi wilayah | Admin/import | satu section satu Posko aktif |
| asset | tiang/gardu/keypoint | Admin/import/validation | koordinat boleh belum lengkap saat import |
| apkuk | inventaris APKUK | Admin/import | status aktif mencegah duplicate WO |
| vegetasi | master titik vegetasi tervalidasi | Admin/import | usulan baru masuk `vegetasi_proposal` |
| petugas | orang aktual pelaksana | Admin/import | dipilih pada pekerjaan |
| users | akun login tim/unit | Admin | role hanya ADMIN/POSKO/HARSUS/ROW |
| gangguan_raw | staging source | Admin/import job | struktur source dipertahankan |
| gangguan_mv | view analitik | system | WO/Special Case |
| p0 | pekerjaan prioritas | Admin/HARSUS/PASILA/System | hanya dibuat/ditugaskan setelah asset+wilayah tervalidasi |
| p0_reschedule_history | histori lanjut shift/reschedule | POSKO/Admin/System | append-only |
| p0_context_event | context tambahan P0 aktif | System/Admin | untuk Special Case/temuan tambahan tanpa duplicate P0 |
| work_order | WO APKUK | System/Admin | target 6/shift/Posko |
| wo_reschedule_history | histori jadwal ulang WO | POSKO/System | carry-over mengisi kuota shift target |
| pasila_session | patroli per shift | POSKO | target rekomendasi + override |
| pasila_action | action PASILA | POSKO | dapat eskalasi P0 |
| harsus_inspection | temuan HARSUS | HARSUS | asset nullable jika unknown point |
| row_work | pekerjaan vegetasi | ROW/POSKO | foto+catatan |
| vegetasi_proposal | usulan titik vegetasi | ROW | Admin validate/reject |
| special_case | >3 trip/penyulang/bulan kalender | System | monthly period |
| documentation | metadata foto | Mobile/Web | BEFORE/AFTER/TEMUAN/SURVEY |
| status_history | histori status | System | append-only |
| audit_log | koreksi/master/config | System | append-only |
| app_config | konfigurasi | Admin | JSON/value config |

---

# 4. Detailed Entity Definitions

## 4.1 Master / Reference

### 4.1.1 `posko`
- `id UUID PK`
- `posko_code TEXT UNIQUE NOT NULL`
- `nama_posko TEXT NOT NULL`
- `is_active BOOLEAN NOT NULL DEFAULT true`
- timestamps

### 4.1.2 `penyulang`
- `id UUID PK`
- `kode_penyulang TEXT UNIQUE NOT NULL`
- `nama_penyulang TEXT NOT NULL`
- `gardu_induk TEXT NULL`
- `is_active BOOLEAN NOT NULL DEFAULT true`
- timestamps

### 4.1.3 `section`
- `id UUID PK`
- `section_code TEXT UNIQUE NOT NULL`
- `nama_section TEXT NOT NULL`
- `penyulang_id UUID FK NOT NULL`
- `posko_id UUID FK NOT NULL`
- `is_active BOOLEAN NOT NULL DEFAULT true`
- timestamps

### 4.1.4 `asset`
- `id UUID PK`
- `asset_code TEXT UNIQUE NOT NULL`
- `section_id UUID FK NOT NULL`
- `asset_type TEXT CHECK IN (TIANG,GARDU,KEYPOINT)`
- `nama_aset TEXT NOT NULL`
- `latitude DOUBLE PRECISION NULL`
- `longitude DOUBLE PRECISION NULL`
- `status TEXT`
- `is_active BOOLEAN`
- timestamps

Koordinat boleh NULL saat import awal, tetapi WO/Maps memerlukan koordinat valid bila workflow membutuhkannya.

### 4.1.5 `apkuk`
- `id UUID PK`
- `apkuk_code TEXT UNIQUE`
- `asset_id UUID FK NOT NULL`
- `tgl_pasang DATE/TIMESTAMPTZ`
- `status TEXT` (`AKTIF`, `NON_AKTIF`, dll sesuai data resmi)
- timestamps

### 4.1.6 `vegetasi`
- `id UUID PK`
- `vegetasi_code TEXT UNIQUE`
- `section_id UUID FK NOT NULL`
- `asset_terdekat_id UUID FK NULL`
- `latitude`, `longitude`
- `jenis_pohon`, `jarak`, `tinggi`, `diameter`, `laju_pertumbuhan` nullable sesuai source
- `status`
- `last_row_at`, `next_row_date` nullable
- timestamps

### 4.1.7 `petugas`
- `id UUID PK`
- `petugas_code TEXT UNIQUE NULL`
- `nama_petugas TEXT NOT NULL`
- `team_type TEXT NULL`
- `posko_id UUID NULL`
- `is_active BOOLEAN`
- timestamps

### 4.1.8 `users`
- `id UUID PK`
- `username TEXT UNIQUE NOT NULL`
- `email TEXT UNIQUE NULL`
- `password_hash TEXT NOT NULL`
- `role TEXT CHECK IN ('ADMIN','POSKO','HARSUS','ROW')`
- `posko_id UUID NULL` — wajib untuk role POSKO
- `display_name TEXT`
- `is_active BOOLEAN`
- timestamps

Akun mewakili tim/unit. Jumlah akun mengikuti kebutuhan tim operasional.

---

## 4.2 External / Raw

### 4.2.1 `gangguan_raw`
Staging data gangguan pusat. Kolom sumber dipertahankan sesuai file source final. Jangan memodifikasi nama/meaning source tanpa profiling.

### 4.2.2 `gangguan_mv`
View/materialized view analitik yang minimal mengekspose:
- `id_gangguan`
- tanggal/waktu
- penyulang
- asset mapping bila ditemukan
- section/posko derived
- jenis gangguan
- flag trip
- fields analitik yang diperlukan scoring

Rule sementara `is_trip` mengikuti mapping source yang sudah disepakati pada tahap profiling; formula produksi harus divalidasi pada source nyata.

---

## 4.3 Operational Transaction Entities

### 4.3.1 `p0`

| Field | Type | Nullable | Notes |
|---|---|---:|---|
| id | UUID PK | NO | server UUID |
| display_code | TEXT UNIQUE | NO | kode manusiawi |
| source_type | TEXT | NO | ADMIN/HARSUS/PASILA/SPECIAL_CASE |
| source_id | UUID | YES | referensi sumber |
| asset_id | UUID FK | **NO** | P0 executable selalu pada asset tervalidasi |
| section_id | UUID FK | NO | hasil mapping asset/wilayah |
| posko_id | UUID FK | NO | penerima P0 |
| latitude | FLOAT8 | YES | snapshot lokasi opsional |
| longitude | FLOAT8 | YES | snapshot lokasi opsional |
| lokasi_text | TEXT | YES | deskripsi lokasi |
| issue_type | TEXT | YES | digunakan untuk duplicate/Special Case comparison |
| uraian | TEXT | NO | konteks eksekusi |
| kebutuhan_padam | TEXT | NO | TANPA_PADAM/PERLU_PADAM, snapshot saat P0 dibuat |
| kebutuhan_material | TEXT | NO | isi `TIDAK_ADA` jika tidak membutuhkan material |
| status | TEXT | NO | BARU/DITERIMA/DIKERJAKAN/RESCHEDULED/SELESAI/CANCELLED |
| scheduled_date | DATE | YES | target tanggal |
| scheduled_shift | TEXT | YES | A/B/C |
| version | INTEGER | NO | OCC |
| created_at | TIMESTAMPTZ | NO | server create |
| updated_at | TIMESTAMPTZ | NO | server update |
| completed_at | TIMESTAMPTZ | YES | waktu selesai aktual (`occurred_at`) |
| completed_submitted_at | TIMESTAMPTZ | YES | waktu completion diterima server |

**Tidak menyimpan `parent_p0_id` untuk reschedule.** Reschedule tetap record P0 yang sama.

**P0 menyimpan snapshot kebutuhan eksekusi.** Untuk source HARSUS/PASILA nilai dicopy ketika P0 dibuat; untuk source ADMIN diisi Admin; untuk Special Case harus tersedia/ditinjau sebelum P0 ditugaskan. Unknown point tetap berada pada source record sampai Admin memetakan/membuat asset + Section/Posko, baru P0 executable dibuat.

### 4.3.2 `p0_petugas`
- `p0_id`
- `petugas_id`
- composite unique

### 4.3.3 `p0_reschedule_history`
- `id UUID PK`
- `p0_id UUID FK NOT NULL`
- `reschedule_type TEXT CHECK IN ('NEXT_SHIFT','CUSTOM')`
- `from_date`, `from_shift`
- `target_date`, `target_shift`
- `reason TEXT NOT NULL`
- `requested_by UUID FK users`
- `approval_status TEXT CHECK IN ('NOT_REQUIRED','PENDING','APPROVED','REJECTED')`
- `approved_by UUID NULL`
- `approved_at TIMESTAMPTZ NULL`
- `rejection_reason TEXT NULL`
- timestamps

NEXT_SHIFT menggunakan `NOT_REQUIRED` dan langsung mengubah P0 menjadi `RESCHEDULED`. CUSTOM menggunakan PENDING → APPROVED/REJECTED; selama PENDING status P0 tetap `DIKERJAKAN`, setelah APPROVED menjadi `RESCHEDULED`, dan setelah REJECTED tetap `DIKERJAKAN`. Saat pekerjaan dimulai kembali pada target shift, status kembali `DIKERJAKAN`.

### 4.3.4 `p0_context_event`
Untuk menambahkan context/evidence baru ke P0 aktif tanpa membuat P0 duplikat.
- `id UUID PK`
- `p0_id UUID FK`
- `source_type`
- `source_id`
- `event_type`
- `description`
- `created_at`

Dipakai antara lain ketika Special Case menemukan **masalah yang sama** pada aset yang sudah memiliki P0 aktif.

### 4.3.5 `work_order`
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

### 4.3.6 `wo_petugas`
- `wo_id`
- `petugas_id`

### 4.3.6a `wo_reschedule_history`
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

### 4.3.7 `pasila_session`
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

### 4.3.8 `pasila_action`
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

Jika status TIDAK_DAPAT_DIEKSEKUSI, alasan wajib dan kebutuhan padam/material wajib terisi. Jika `asset_id` belum tervalidasi, action tetap tersimpan sebagai temuan tetapi eskalasi P0 menunggu Admin mapping asset terlebih dahulu. Saat P0 dibuat, kebutuhan eksekusi dicopy sebagai snapshot.

### 4.3.9 `pasila_action_petugas`
- `action_id`
- `petugas_id`

### 4.3.10 `harsus_inspection`
- `id UUID PK`
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

Jika `asset_id` null, koordinat wajib dan status default `PENDING_ASSET_VALIDATION`. P0 executable belum dibuat/ditugaskan sampai Admin memetakan atau membuat asset + Section/Posko. Setelah validasi, P0 dapat dibuat dengan snapshot `kebutuhan_padam` dan `kebutuhan_material` dari inspeksi.

### 4.3.11 `row_work`
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

### 4.3.12 `row_work_petugas`
- `row_work_id`
- `petugas_id`

### 4.3.13 `vegetasi_proposal`
- `id UUID PK`
- `submitted_by UUID FK users`
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

### 4.3.14 `special_case`
- `id UUID PK`
- `penyulang_id UUID FK`
- `periode_bulan DATE NOT NULL` — simpan first-day of calendar month
- `jumlah_trip INTEGER NOT NULL`
- `threshold INTEGER NOT NULL DEFAULT 3`
- `status TEXT`
- timestamps

Rule trigger: jumlah trip **>3** pada bulan kalender.

### 4.3.15 `special_case_asset`
- `special_case_id`
- `asset_id`
- `issue_type`
- `decision TEXT` — `MERGED_TO_ACTIVE_P0`, `NEW_P0`, `WO_PRIORITY_ONLY`, dll
- `p0_id UUID NULL`

Decision rule: **masalah sama** + P0 aktif → `MERGED_TO_ACTIVE_P0` dan append `p0_context_event`; **masalah berbeda** → `NEW_P0` diperbolehkan setelah kebutuhan eksekusi tervalidasi. Taxonomy/comparator `issue_type` final adalah gate sebelum implementasi Special Case production.

### 4.3.16 `documentation`
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

## 4.4 History / Audit

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

## 4.5 Configuration

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

---

# 5. Relationships

- Posko 1:N Section
- Penyulang 1:N Section
- Section 1:N Asset
- Asset 1:N APKUK/history/work
- P0 N:M Petugas
- WO N:M Petugas
- PASILA Session 1:N Action
- PASILA Action N:M Petugas
- ROW Work N:M Petugas
- Special Case N:M Asset
- Entity 1:N Documentation

Unknown-point HARSUS/PASILA dapat memiliki relasi asset kosong sampai Admin validation. P0 executable selalu memiliki asset, Section, dan Posko tervalidasi.

---

# 6. ERD — Logical

```text
POSKO ──< SECTION >── PENYULANG
            │
            └──< ASSET ──< APKUK
                   │
                   ├──< P0 >── PETUGAS
                   ├──< WORK_ORDER >── PETUGAS
                   └──< HARSUS_INSPECTION

POSKO ──< PASILA_SESSION ──< PASILA_ACTION >── PETUGAS

VEGETASI ──< ROW_WORK >── PETUGAS
ROW USER ──< VEGETASI_PROPOSAL ──(Admin validate)──> VEGETASI

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
- Users/tim account
- gangguan source untuk analytics

---

# 9. Data Generated by New System

P0, WO, PASILA, HARSUS, ROW, proposal vegetasi, Special Case, documentation, histories, audit logs.

---

# 10. Data Integrity Rules

1. WO APKUK tidak boleh duplicate pada asset yang memiliki APKUK aktif atau WO APKUK aktif identik.
2. P0 executable wajib memiliki asset + Section + Posko tervalidasi dan snapshot kebutuhan padam/material.
3. HARSUS asset-null wajib koordinat dan pending validation; P0 belum dibuat sampai validation selesai.
4. PASILA override wajib alasan.
5. PASILA action gagal wajib alasan.
6. CUSTOM reschedule P0 membutuhkan Admin approval.
7. NEXT_SHIFT tidak membutuhkan approval.
8. Completed server transaction tidak diedit user lapangan.
9. Admin correction wajib audit log.
10. Photo type hanya BEFORE/AFTER/TEMUAN/SURVEY.
11. Submit pekerjaan wajib memenuhi minimal photo rule.
12. OCC version wajib untuk P0/WO dan entity kritis yang dapat diupdate dari beberapa device.
13. Maksimal satu APKUK berstatus `AKTIF` per asset pada satu waktu (enforce via partial unique index/validation).
14. PASILA session unique per `(posko_id,tanggal,shift)`.

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
- `p0(posko_id,status,scheduled_date,scheduled_shift)`
- `work_order(posko_id,tanggal,shift,status)`
- `work_order(asset_id,jenis_wo,status)`
- `pasila_session(posko_id,tanggal,shift)`
- `harsus_inspection(validation_status)`
- `vegetasi_proposal(status)`
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
6. Taxonomy `issue_type` dan aturan comparator “masalah sama vs berbeda” sebelum Phase Special Case.

Yang sudah final dan bukan lagi OQ: jam shift, month-window Special Case, akun berbasis tim, unknown asset HARSUS, photo enum, correction Admin, offline draft/manual submit, WO APKUK-only, target 6 WO, PASILA recommendation+override.
