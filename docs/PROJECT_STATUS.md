# PROJECT STATUS & COLLABORATION HANDOFF
## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Last Updated:** 4 Oktober 2026
**Document Status:** Living Master Status & Team Entry Point  
**Business Flow Version:** v2 (Final Stakeholder Clarification)
**Current Execution State:** Phase 0 DONE, Phase 1 PARTIAL, Phase 2 PARTIAL (Core Topology Masters DONE, Section Final BLOCKED)  
**Verified Automated Test Suite:** 73 tests passed, 274 assertions

---

# 1. How To Use This Document

Dokumen ini adalah **ENTRY POINT PERTAMA DAN UTAMA** bagi setiap developer baru, tech lead, maupun AI coding assistant yang bekerja pada codebase project *Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono*.

- **Sebelum membaca kode atau mengusulkan perubahan:** Baca dokumen ini secara utuh untuk memahami batasan arsitektur terkunci (*locked baseline*), status implementasi nyata, pekerjaan yang sudah selesai (*completed work*), item yang terblokir (*blocked*), dan daftar tugas yang siap dikerjakan (*available task pool*).
- **Status vs Spesifikasi:** Bedakan secara tegas antara apa yang **SUDAH TERIMPLEMENTASI** (kode nyata, tabel terverifikasi, command aktif, test hijau) dengan apa yang masih berupa **SPESIFIKASI / TARGET DOMAIN**. Jangan menganggap spesifikasi di PRD/API Contract sebagai fitur yang sudah jalan di backend/mobile.
- **Ikuti urutan bacaan** pada Bagian 17 sebelum melompat ke dokumen spesifikasi teknis detail.

---

# 2. Project Summary

Project ini adalah sistem pengelolaan pekerjaan preventive jaringan distribusi tegangan menengah (20 kV) pada unit kerja **ULP Sribhawono** (single-tenant). Sistem dirancang untuk mengintegrasikan seluruh siklus operasional lapangan dari identifikasi temuan/kondisi → penentuan prioritas → penugasan → eksekusi lapangan → dokumentasi foto → histori aset → monitoring dan validasi Admin.

Aplikasi dirancang tangguh untuk kondisi lapangan dengan konektivitas seluler fluktuatif di wilayah Lampung Timur, menggunakan pendekatan *offline draft mode* (bukan *full database replication*).

---

# 3. Locked Architecture

Arsitektur sistem telah dikunci (*locked baseline*) per 27 September 2026 dan **DILARANG DIUBAH/DIREDESIGN** tanpa persetujuan eksplisit stakeholder:

| Layer | Teknologi Terkunci | Peran & Batasan |
|---|---|---|
| **Aplikasi Lapangan** | **Flutter (Android)** | Aplikasi mobile untuk POSKO, HARSUS, INSPEKSI, dan ROW. Mode draft lokal, kamera terintegrasi, GPS, Google Maps online-primary. |
| **Backend & Web Admin** | **Laravel (PHP 8.2+)** | REST API untuk Flutter + Web Admin terpadu untuk ADMIN. Menangani autentikasi, business validation, scheduler, audit trail, dan ingestion pipeline. |
| **Server Database** | **PostgreSQL** | Primary relational database server authoritative. |
| **Mobile Local Storage** | **SQLite** | Cache data ringan, antrean draft lokal (*local draft*), dan outbox pengiriman. **Bukan replika full database server**. |
| **Media Storage** | **Laravel Filesystem** | Penyimpanan foto dokumentasi (lokal disk saat development, S3-compatible saat production). Database hanya menyimpan metadata URL/path. |
| **Peta & Geospasial** | **Google Maps** | Visualisasi marker titik aset/pekerjaan dan navigasi eksternal (online-primary). |

### ⛔ Arsitektur Terlarang (DILARANG DIBANGKITKAN LAGI):
- ❌ **Next.js**
- ❌ **Supabase Auth / Supabase Edge Functions / Supabase Realtime**
- ❌ **PowerSync / Full bidirectional database synchronization**
- ❌ **Offline Full Basemap Replication**

---

# 4. Locked Business Rules

Seluruh business rule berikut bersifat mutlak dan tidak boleh diubah:

1. **Role Pengguna (5 Role):**
   Hanya ada 5 role: `ADMIN`, `POSKO`, `HARSUS`, `INSPEKSI`, dan `ROW`. Akun mewakili tim/unit kerja, sedangkan personil pelaksana aktual dipilih dari master `Petugas`. Account ≠ Petugas.

2. **HARSUS = Pemeliharaan Khusus:**
   HARSUS mengeksekusi **seluruh P0 yang sudah executable**. HARSUS **TIDAK** melakukan inspeksi aset. HARSUS **TIDAK** berbasis shift dan **TIDAK** terikat satu Posko. P0 tidak menggunakan Shift A/B/C untuk eksekusi HARSUS.

3. **INSPEKSI = Inspeksi Aset & Jaringan:**
   INSPEKSI melakukan inspeksi kondisi aset/jaringan, mencatat temuan, mengusulkan P0 (yang memerlukan validasi Admin), dan mengusulkan vegetasi baru (yang memerlukan validasi Admin). INSPEKSI tidak terikat satu Posko.

4. **POSKO Work = WO + PASILA (Bukan P0):**
   POSKO mengeksekusi Work Order (WO) dan PASILA. POSKO **TIDAK** mengeksekusi P0 dan **TIDAK** menerima P0 dalam work feed. POSKO masih dapat melakukan tindakan vegetasi insidental melalui PASILA.

5. **ROW = Eksekusi Vegetasi Saja:**
   ROW mengeksekusi RABAS/PANGKAS/TEBANG. ROW **TIDAK** lagi membuat proposal vegetasi baru (proposal berasal dari INSPEKSI).

6. **P0 Executor = HARSUS:**
   P0 selalu dieksekusi oleh HARSUS. Sumber P0: ADMIN (manual), INSPEKSI (finding + Admin approval), PASILA (escalation + Admin approval), SPECIAL_CASE (rekomendasi + Admin decision). `posko_id` pada P0 = lokasi/wilayah operasional aset, bukan executor.

7. **P0 Petugas di Completion:**
   Petugas aktual dipilih oleh HARSUS saat completion/submission P0, **BUKAN** saat menerima atau memulai P0.

8. **P0 Reschedule Date-Based:**
   P0 yang belum selesai: HARSUS mengajukan reschedule dengan target tanggal + alasan wajib → Admin approve/reject. **TIDAK** ada "Lanjut Shift Berikutnya" untuk P0. P0 tetap menggunakan ID record yang sama.

9. **Special Case = Rekomendasi, Bukan Auto-P0:**
   Kondisi **>3 trip pada satu penyulang dalam satu bulan kalender**. Special Case **TIDAK** otomatis membuat P0. Sistem membuat rekomendasi → Admin mereview/mengedit → Admin memutuskan apakah membuat P0. Jika masalah sama pada aset dengan P0 aktif, satukan konteks ke P0 aktif. Jika masalah berbeda, Admin dapat membuat P0 baru.

10. **PASILA Escalation = Proposal:**
    Jika action PASILA tidak dapat dieksekusi langsung, POSKO dapat mengusulkan escalation ke P0. Escalation adalah **proposal yang memerlukan persetujuan Admin**. Escalation **TIDAK** langsung membuat P0 executable. P0 yang disetujui ditugaskan ke HARSUS.

11. **Target Work Order (WO):**
    Target operasional adalah **6 WO per shift per Posko**. Pada MVP, seluruh WO berfokus pada **PEMASANGAN_APKUK**. Carry-over WO dari shift sebelumnya mengisi kuota terlebih dahulu (total target tetap 6/shift/Posko).

12. **PASILA (Patroli Eksekusi Langsung) Wajib Per Shift:**
    Setiap shift POSKO wajib menjalankan 1 sesi PASILA. Sistem memberikan rekomendasi target berbasis prioritas trip/gangguan; POSKO boleh melakukan override dengan alasan wajib.

13. **Shift Operasional (3 Shift) — Berlaku untuk POSKO:**
    - **Shift A:** 08:00 – 16:00 WIB
    - **Shift B:** 16:00 – 00:00 WIB
    - **Shift C:** 00:00 – 08:00 WIB

14. **Dokumentasi Wajib:**
    Setiap pekerjaan yang disubmit wajib memiliki minimal 1 foto dokumentasi (`BEFORE`, `AFTER`, `TEMUAN`, atau `SURVEY`).

15. **Koreksi Data Pasca-Submit:**
    Petugas lapangan tidak dapat mengedit data yang sudah masuk server. Koreksi dilakukan oleh Admin Web dengan pencatatan audit log otomatis.

---

# 5. Current Implementation State

Status implementasi per 30 September 2026 (kode aktual tidak berubah oleh rebaseline dokumentasi Business Flow v2):

| Komponen / Layer | Status | Keterangan Implementasi Aktual |
|---|---|---|
| **Repository & Tooling Foundation** | `IMPLEMENTED` | Monorepo structure (`backend/`, `mobile/`, `docs/`), Git protection, environment configuration. |
| **Laravel Backend Bootstrap** | `IMPLEMENTED` | Laravel 13.33.0 framework, artisan CLI tooling, PostgreSQL database connection verified. |
| **Flutter Android Bootstrap** | `IMPLEMENTED` | Starter Android project configured. |
| **Data Ingestion & Staging Layer** | `IMPLEMENTED` | 6 tabel staging, Google Sheets API integration, profiling & audit commands. |
| **Core Topology Masters** | `IMPLEMENTED` | Master `poskos`, `penyulangs`, dan pivot Many-to-Many `posko_penyulang` sinkron dan teruji. |
| **Topology Audit Engine** | `IMPLEMENTED` | Command `topology:audit` memvalidasi fill-down in-memory, name matching, coordinate parsing, dan review anomalies. |
| **Automated Testing Suite** | `IMPLEMENTED` | **73 tests passed, 274 assertions** pada test suite aktif. |
| **Auth & RBAC Foundation** | `PARTIAL` | Migration `users` default ada; skema role 5-role (ADMIN/POSKO/HARSUS/INSPEKSI/ROW) dan Sanctum belum difinalkan. |
| **Grand Design UI (Web & Mobile)** | `PARTIAL` | Design brief & screen inventory tersedia; visual grand design menjadi task aktif berikutnya. **Requires v2 revision.** |
| **Section Master Final** | `BLOCKED` | Menunggu file sumber FCO dan Switching ULP Sribhawono yang sudah terfilter. |
| **Operational Asset Master** | `BLOCKED/PARTIAL` | 17,059 aset valid ada di staging; mapping final ke Section & Posko menunggu Section final. |
| **Domain Transactions (P0, WO, PASILA, INSPEKSI, ROW)** | `PENDING` | Spesifikasi API dan Data Model siap (v2); endpoint/controller/service belum dibuat. |
| **Mobile SQLite Draft & Outbox** | `PENDING` | Spesifikasi sinkronisasi draft siap; implementasi database lokal SQLite di Flutter belum dimulai. |
| **Admin Web Dashboard** | `PENDING` | Halaman Web Admin monitoring belum diimplementasikan. |

---

# 6. Implemented Database / Data Sources

### A. Data Ingestion Architecture
Sumber data diproses melalui pipeline multi-tahap:
```text
Google Spreadsheet / Source Files
               ↓
    Laravel Import Commands
               ↓
    PostgreSQL Staging Tables (Raw String)
               ↓
    Audit & Canonicalization Engine
               ↓
    Canonical Operational Masters
```

### B. Tabel Staging Aktif (Database Nyata)
Seluruh kolom mentah disimpan sebagai string untuk menjaga integritas data sumber:
1. `apkuk_source_rows` (2,864 baris aktual dari Google Sheet APKUK)
2. `asset_source_rows` (17,059 baris aset terverifikasi dari snapshot ASSET_RAW)
3. `vegetasi_source_rows` (16,983 baris data pohon dari VEGETASI_RAW)
4. `gangguan_source_rows` (443 baris histori gangguan dari GANGGUAN_RAW)
5. `section_pic_source_rows` (69 baris pemutus/feeder dari SECTION_PIC_RAW)
6. `keypoint_source_rows` (129 baris keypoint jaringan dari KEYPOINT_RAW)

> [!WARNING]
> Source APKUK (2,864 baris) adalah data operasional pemasangan APKUK, **BUKAN MASTER ASET**. Jangan pernah menjadikan source APKUK sebagai master seluruh aset jaringan.

### C. Tabel Master Topologi Inti (Database Nyata)
- **`poskos`**: `id` (UUID PK), `kode_posko` (VARCHAR UNIQUE), `nama_posko` (VARCHAR), `is_active` (BOOLEAN), `timestamps`.
- **`penyulangs`**: `id` (UUID PK), `kode_penyulang` (VARCHAR UNIQUE), `nama_penyulang` (VARCHAR), `gardu_induk` (VARCHAR NULLABLE), `is_active` (BOOLEAN), `timestamps`.
- **`posko_penyulang`**: `posko_id` (UUID FK), `penyulang_id` (UUID FK), `timestamps`, Composite PK (`posko_id`, `penyulang_id`).

---

# 7. Topology Current State

### A. 6 Posko Resmi ULP Sribhawono
1. `SRIBHAWONO`
2. `PUGUNG`
3. `JABUNG`
4. `PASIR SAKTI`
5. `WAY JEPARA`
6. `TRIDATU`

### B. 16 Canonical Penyulang (Feeder)
1. `BAUNG 2`
2. `BEDANA`
3. `CAKALELE`
4. `GAMBYONG`
5. `JAIPONG`
6. `JATILAN`
7. `KECAK`
8. `KUNINGAN 2`
9. `NANAS`
10. `PAYUNG`
11. `REOG`
12. `RONGGENG`
13. `SAMAN`
14. `TANGGAMUS 2` (Canonical alias dari `TANGGAMUS`)
15. `TOR TOR`
16. `ZAPIN`

### C. Karakteristik Relasi Topologi
- **Many-to-Many:** Satu penyulang dapat melintasi lebih dari satu wilayah Posko (saat ini terdaftar **26 relasi Posko ↔ Penyulang** terverifikasi).
- **Asset Ownership:** Relasi Posko-Penyulang **TIDAK SECARA OTOMATIS** menentukan Posko pemilik satu aset fisik. Ownership operasional aset nantinya ditentukan secara definitif melalui **Section tervalidasi**.
- **Section Final Pending:** Master `sections` final belum dibuat karena menunggu kepastian batas proteksi/pemutus dari file FCO dan Switching ULP Sribhawono.

---

# 8. Completed Work

Pekerjaan yang telah diselesaikan dan terverifikasi secara penuh:
- [x] **T01 Foundation:** Setup Git, Laravel 13.33.0, PostgreSQL Dev, Flutter Project, `.env` structure.
- [x] **T02 Source Staging & Import:** Migration 6 tabel staging, Google Sheets API client, commands `apkuk:import-sheet` (dengan opsi `--replace`), `sources:import-sheets`, `sources:profile-sheets`.
- [x] **T03 Source / Topology Audit:** Command `sources:audit-staging`, command `topology:audit` (fill-down parsing, safe string normalization, coordinate extraction, proximity detection).
- [x] **T04 Core Posko/Penyulang Masters:** Migration `poskos`, `penyulangs`, `posko_penyulang`, command `topology:sync-core-masters`, alias mapping `TANGGAMUS` → `TANGGAMUS 2`, safe pivot sync.
- [x] **Automated Test Suite:** 73 automated tests passed, 274 assertions mencakup validasi staging, header resolution, audit logic, dan core topology sync.

---

# 9. Partial Work

Pekerjaan yang sedang berjalan atau sebagian telah memiliki fondasi:
- 🟡 **Auth & Role Foundation:** Migration tabel `users` dasar Laravel ada, tetapi registrasi 5 role (ADMIN/POSKO/HARSUS/INSPEKSI/ROW), Sanctum token issue, dan permission policy belum dibuat.
- 🟡 **Grand Design UI (Web & Mobile):** Screen inventory dan user flows sudah disepakati di Design Brief (updated for Business Flow v2), tetapi perancangan visual antarmuka menyeluruh belum difinalkan. **Requires revision for v2.**
- 🟡 **Keypoint Layer Preparation:** Data mentah keypoint (129 rows) dan matching logic sudah diaudit, siap dijadikan model/candidate layer.

---

# 10. Available Task Pool

Kolaborasi bersifat task-based dan fleksibel. Developer atau AI assistant dapat mengambil task yang berstatus `AVAILABLE`:

```text
[T01 DONE] Foundation
[T02 DONE] Source Staging & Import
[T03 DONE] Source / Topology Audit
[T04 DONE] Core Posko/Penyulang Masters
    ├── [T05 AVAILABLE] Grand Design Web + Mobile — Requires Business Flow v2 revision
    ├── [T06 AVAILABLE] Keypoint Canonical / Candidate Layer
    ├── [T07 AVAILABLE] Auth, Roles & Security Foundation (5 roles)
    │       └── [T08 AVAILABLE/DEP] Petugas & User Master (depends on T07)
    ├── [T09 BLOCKED] Final Section (waiting FCO/Switching Sribhawono)
    └── [T10 BLOCKED/PARTIAL] Operational Asset Master (depends on T09)
```

| Task ID | Nama Task | Status | Prasyarat / Dependensi |
|---|---|---|---|
| **T05** | **Grand Design Web + Mobile** | `AVAILABLE` | Memahami Design Brief (v2) & Screen Inventory; requires Business Flow v2 revision |
| **T06** | **Keypoint Canonical/Candidate Layer** | `AVAILABLE` | T04, Data `keypoint_source_rows` |
| **T07** | **Auth, Role & Security Foundation** | `AVAILABLE` | T01, T04. Now 5 roles: ADMIN/POSKO/HARSUS/INSPEKSI/ROW |
| **T08** | **Petugas & User Master** | `AVAILABLE` | T07. Supports 5-role account model. POSKO scoped to one Posko. HARSUS and INSPEKSI not Posko-scoped. |
| **T09** | **Final Section Master** | `BLOCKED` | Menunggu sumber eksternal FCO/Switching terfilter |
| **T10** | **Operational Asset Master** | `BLOCKED/PARTIAL` | T09 (Section final) |
| **T11** | **P0 Vertical Slice (API + Mobile)** | `PENDING` | T07, T08, T10. Executor: HARSUS. No shift. Date-based reschedule. Petugas at completion. |
| **T12** | **Photo Storage & Ingestion Pipeline** | `PENDING` | T01, T11. Updated ownership for inspections (INSPEKSI). |
| **T13** | **Work Order (WO APKUK) Engine** | `PENDING` | T10, T11. Executor: POSKO. |
| **T14** | **PASILA Engine & Recommendations** | `PENDING` | T10, T11. Executor: POSKO. Escalation = proposal requiring Admin approval → P0 to HARSUS. |
| **T15** | **INSPEKSI Findings & Asset/Vegetation Escalation** | `PENDING` | T10, T11. Inspection by INSPEKSI (not HARSUS). Includes vegetation proposals. |
| **T16** | **ROW Work (Execute Only)** | `PENDING` | T10, T11. RABAS/PANGKAS/TEBANG only. No vegetation proposal. |
| **T17** | **Maps Integration & Asset History** | `PENDING` | T10, T11. Role access updates for 5 roles. |
| **T18** | **Special Case Detection Engine** | `PENDING` | T11, T13. Detection + Admin recommendation → Admin decision → P0 to HARSUS. No auto-P0. |
| **T19** | **Offline Draft Hardening & Outbox** | `PENDING` | T11, T12. Updated queues for 5 roles. |
| **T20** | **Admin Operational Dashboard** | `PENDING` | T07, T11 s/d T18. Updated monitors/approval flows for Business Flow v2. |
| **T21** | **End-to-End Integration & Security Audit** | `PENDING` | T19, T20. |
| **T22** | **Production Deployment Readiness** | `PENDING` | T21. |

---

# 11. Blocked Tasks

- 🔴 **T09 Final Section:** Terblokir karena data pemutus Section pada spreadsheet `SECTION_PIC_RAW` masih memiliki anomali/blank Posko dan belum disinkronkan dengan data filter FCO serta Switching resmi ULP Sribhawono.
- 🔴 **T10 Operational Asset Master:** Sebagian terblokir untuk penetapan Posko penanggung jawab definitif per aset, karena aset harus menginduk ke Section yang valid.

---

# 12. Known Review Items

Hasil audit topologi read-only mengidentifikasi anomali yang **TIDAK BOLEH DI-AUTOFIX / DITEBAK** di kode tanpa konfirmasi resmi:

1. **Blank Posko pada SECTION PIC:**
   - Feeder `KUNINGAN 2` baris pemutus `IR SUTAMI` (kolom Posko PIC kosong di sumber).
   - Feeder `TANGGAMUS 2` baris pemutus `GI KETAPANG P. TANGGAMUS` (kolom Posko PIC kosong di sumber).
2. **Review Topology Equipment:**
   - `LBSM ZAPTOR` (perlu verifikasi nama/tipe vs keypoint).
   - `LBSM KARYA TANI` (perlu verifikasi feeder/koordinat).
   - `REC SIMPANG KAYAT` (perlu konfirmasi feeder canonical).

---

# 13. External Data Being Waited For

1. **File FCO ULP Sribhawono (Terfilter):** File koordinat dan nomor tiang Cut-Out yang hanya mencakup wilayah kerja ULP Sribhawono (bukan data seluruh UID).
2. **File Switching ULP Sribhawono (Terfilter):** File data Recloser/LBS/Pemutus khusus wilayah kerja ULP Sribhawono.

---

# 14. Collaboration Workflow

Kolaborasi dalam project ini mengedepankan fleksibilitas tim:
- **Task-Based, Bukan Kepemilikan Kaku:** Tidak ada pembagian permanen seperti "Developer A hanya Backend" atau "Developer B hanya Mobile". Setiap developer/AI dapat mengambil task terbuka di Task Pool sesuai kesepakatan.
- **Dependency Awareness:** Hormati dependensi antar task. Jangan mengerjakan fitur transaksi sebelum layer auth/master dasar siap.
- **Specification Integrity:** Setiap implementasi harus mematuhi dokumen spesifikasi terkait (`TECHNICAL_SPEC.md`, `DATA_MODEL.md`, `API_CONTRACT.md`, `OFFLINE_SYNC_SPEC.md`) yang sudah diperbarui untuk Business Flow v2.

---

# 15. Git Workflow

Aturan Git branching disesuaikan dengan tingkat risiko perubahan:
- **Perubahan Ringan & Aman:** Pekerjaan dokumentasi, typo fixes, catatan status, dan grand-design docs boleh langsung dikerjakan di branch `main`.
- **Perubahan Berisiko:** Modifikasi kode yang sensitif seperti migration, skema database, auth/security, endpoint API, workflow domain, logika import/sync, atau refactoring besar **wajib** menggunakan feature branch (contoh: `feat/t07-auth-foundation`).
- **Pekerjaan Paralel:** Pekerjaan simultan antar-developer yang berpotensi menimbulkan conflict wajib menggunakan branch terpisah.
- **Kebijakan Branching:** Jangan membuat branch secara otomatis untuk setiap perubahan kecil. Jika branch baru dibuat, jelaskan alasannya kepada tim.
- **No Secrets:** Dilarang keras melakukan commit file `.env`, service account JSON, API keys, atau kredensial database.
- **Verified Testing:** Jalankan test suite sebelum merge untuk memastikan tidak ada regresi.

---

# 16. Rules for AI Assistants

Bagi AI Assistant yang berpartisipasi dalam project ini, patuhi aturan mutlak berikut:
1. **Baca `PROJECT_STATUS.md` terlebih dahulu** sebelum menjawab pertanyaan arsitektur atau menulis kode.
2. **DILARANG mengubah arsitektur locked baseline** (jangan gunakan Supabase, PowerSync, atau Next.js).
3. **DILARANG membuat Section final secara prematur** dari data topologi yang belum lengkap.
4. **DILARANG menyimpulkan kepemilikan Posko aset hanya berdasarkan Feeder** (Penyulang bersifat many-to-many ke Posko).
5. **DILARANG menimpa/mengubah data tabel staging mentah** menjadi format yang diada-adakan.
6. **DILARANG melakukan auto-fix diam-diam** terhadap record review/konflik topologi.
7. **Inspeksi migration dan model aktual** di `backend/database/migrations` dan `backend/app/Models` sebelum mengusulkan perubahan skema.
8. **Periksa `git status`** sebelum dan sesudah melakukan pengeditan.
9. **DILARANG menjalankan migrate/import/commit/push** kecuali ada permintaan eksplisit dari pengguna.
10. **Hormati Business Flow v2:** HARSUS = Pemeliharaan Khusus (executor P0), INSPEKSI = inspeksi aset (role baru), POSKO = WO + PASILA (bukan P0), ROW = eksekusi vegetasi saja, Special Case = rekomendasi Admin (bukan auto-P0).

---

# 17. Recommended Reading Order

Bagi developer atau AI yang baru bergabung, ikuti urutan membaca dokumen berikut:

1. [PROJECT_STATUS.md](./PROJECT_STATUS.md) *(Dokumen ini — Entry point, locked rules, real state)*
2. [PRD.md](./PRD.md) *(Kebutuhan produk, user persona, domain business rules)*
3. [IMPLEMENTATION_PLAN.md](./IMPLEMENTATION_PLAN.md) *(Roadmap fase & status eksekusi)*
4. [TECHNICAL_SPEC.md](./TECHNICAL_SPEC.md) *(Spesifikasi arsitektur teknis Laravel & Flutter)*
5. [DATA_MODEL.md](./DATA_MODEL.md) *(Skema database aktual vs planned domain model)*
6. [DESIGN_BRIEF.md](./DESIGN_BRIEF.md) *(Prinsip desain, screen inventory, user flow)*
7. [API_CONTRACT.md](./API_CONTRACT.md) *(Spesifikasi kontrak REST API target)*
8. [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md) *(Spesifikasi offline draft mode & outbox)*
9. [FINAL_AUDIT_REPORT.md](./FINAL_AUDIT_REPORT.md) *(Historical baseline audit 27 Sept 2026 — DO NOT EDIT)*
