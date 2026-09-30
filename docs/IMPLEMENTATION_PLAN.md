# IMPLEMENTATION PLAN & EXECUTION ROADMAP

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Final / Locked Baseline (Refreshed per 30 September 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Dokumen Acuan:** PRD.md · DESIGN_BRIEF.md · TECHNICAL_SPEC.md · DATA_MODEL.md · OFFLINE_SYNC_SPEC.md · API_CONTRACT.md
**Ruang Lingkup:** Flutter Android Field App · Laravel Backend + Admin Web · PostgreSQL · SQLite Draft/Cache · File Storage · Google Maps
**Verified Automated Tests:** 73 tests passed, 274 assertions

---

# 1. Implementation Strategy

Prinsip Eksekusi:
1. Bangun vertical slice sederhana terlebih dahulu.
2. Laravel menjadi satu server application untuk API + Admin Web.
3. Hindari PowerSync, Next.js, Supabase-heavy architecture, realtime, dan CDN pada MVP.
4. SQLite digunakan untuk draft/cache/outbox, bukan database replication.
5. Server tetap authoritative.
6. ML tidak boleh menghambat pembangunan core workflow; sediakan adapter/fallback.
7. Dokumentasi dan migration harus mengikuti keputusan bisnis final.
8. Kolaborasi bersifat task-based dan fleksibel (lihat [PROJECT_STATUS.md](./PROJECT_STATUS.md)).

Ranking Posko tetap **v2**.

---

# 2. Execution Status & Dependency Graph

```text
Phase 0 Repo/Foundation                       [STATUS: DONE] (T01)
   ↓
Phase 1 Laravel Database, Auth & Security     [STATUS: PARTIAL] (T01, T07)
   ↓
Phase 2 Master Data & Topology Ingestion      [STATUS: PARTIAL]
   ├── Staging Layer & Ingestion              [STATUS: DONE] (T02)
   ├── Source & Topology Audit                [STATUS: DONE] (T03)
   ├── Core Posko/Penyulang Masters           [STATUS: DONE] (T04)
   ├── Grand Design Web + Mobile              [STATUS: AVAILABLE] (T05)
   ├── Keypoint Canonical/Candidate Layer     [STATUS: AVAILABLE] (T06)
   ├── Section Master Final                   [STATUS: BLOCKED - Waiting FCO/Switching] (T09)
   └── Operational Asset Master               [STATUS: BLOCKED/PARTIAL] (T10)
   ↓
Phase 3 Flutter + SQLite Draft Foundation     [STATUS: PENDING] (T19)
   ↓
Phase 4 Photo Pipeline                        [STATUS: PENDING] (T12)
   ↓
Phase 5 P0 Vertical Slice                     [STATUS: PENDING] (T11)
   ↓
Phase 6 Work Order (WO APKUK)                 [STATUS: PENDING] (T13)
   ↓
Phase 7 PASILA Engine                         [STATUS: PENDING] (T14)
   ↓
Phase 8 HARSUS Inspection                     [STATUS: PENDING] (T15)
   ↓
Phase 9 ROW Work & Proposals                  [STATUS: PENDING] (T16)
   ↓
Phase 10 Maps & History                       [STATUS: PENDING] (T17)
   ↓
Phase 11 Special Case                         [STATUS: PENDING] (T18)
   ↓
Phase 12 Offline Draft Hardening              [STATUS: PENDING] (T19)
   ↓
Phase 13 Admin Operational Dashboard          [STATUS: PENDING] (T20)
   ↓
Phase 14 Integration, Security & Testing      [STATUS: PENDING] (T21)
   ↓
Phase 15 Deployment Readiness                 [STATUS: PENDING] (T22)
```

---

# 3. Environment Strategy

## Development
- Laravel local environment
- PostgreSQL local/dev instance
- local filesystem photo disk
- synthetic/mock data where convenient

## Staging
Optional but recommended before production/UAT if time allows.

## Production
- Laravel runtime
- PostgreSQL managed/self-hosted approved environment
- S3 or approved object storage
- HTTPS
- scheduler/queue worker

Secrets always `.env`/secret manager, never Git.

Owner permits project data in private repository; credentials/secrets remain prohibited. Organizational governance can supersede this if formal policy is provided.

---

# 4. Repository Structure Proposal

```text
ulp-sribhawono/
├── mobile/                 # Flutter Android starter app
├── backend/                # Laravel 13.33.0 API + Admin Web
├── docs/                   # Active documentation & specs
├── .gitignore
└── README.md
```

No Next.js app, no PowerSync config, no Supabase Functions folder.

---

# 5. Phase-by-Phase Implementation Plan

## Phase 0 — Repository & Development Foundation
**Status:** `DONE` (Verified) | **Task ID:** `T01`

### Objective
Membuat fondasi project tanpa business feature besar.

### Scope & Verified Delivery:
- [x] init private Git repository;
- [x] folder `mobile`, `backend`, `docs`;
- [x] Laravel 13.33.0 project bootstrap;
- [x] Flutter Android project bootstrap;
- [x] PostgreSQL dev connection verified;
- [x] `.env.example` + `.gitignore` (secrets protected);
- [x] formatting/lint/test baseline;
- [x] README setup/run instructions;
- [x] copy locked documents as source-of-truth docs.

---

## Phase 1 — Laravel Database, Auth & Security Foundation
**Status:** `PARTIAL` | **Task IDs:** `T01` (DONE), `T07` (AVAILABLE), `T08` (AVAILABLE)

### Objective
Membuat schema core, user/team accounts, login, authorization, audit foundation.

### Current Implementation State:
- [x] Database foundation, cache, and jobs migrations implemented.
- [ ] User role model (ADMIN, POSKO, HARSUS, ROW) & Sanctum token API (Task `T07` — AVAILABLE).
- [ ] Policies & middleware authorization.
- [ ] Audit log service foundation.
- [ ] App config shift parameters.

### Acceptance Criteria
- login Flutter online berhasil;
- Admin Web login berhasil;
- non-ADMIN tidak dapat akses Admin Web;
- POSKO scoped ke Posko sendiri.

---

## Phase 2 — Master Data, Source Staging & Topology Preparation
**Status:** `PARTIAL` | **Task IDs:** `T02` (DONE), `T03` (DONE), `T04` (DONE), `T05` (AVAILABLE), `T06` (AVAILABLE), `T09` (BLOCKED), `T10` (BLOCKED/PARTIAL)

### Scope & Delivery State:
- [x] **Source Staging Tables (T02 - DONE):** Migration 6 tabel staging (`apkuk_source_rows`, `asset_source_rows`, `vegetasi_source_rows`, `gangguan_source_rows`, `section_pic_source_rows`, `keypoint_source_rows`).
- [x] **Source Ingestion Pipeline (T02 - DONE):** Google Sheets API integration, artisan commands `apkuk:import-sheet` (dengan opsi `--replace`), `sources:import-sheets`, `sources:profile-sheets`.
- [x] **Source & Topology Auditing (T03 - DONE):** Commands `sources:audit-staging`, `topology:audit` (in-memory fill-down parsing, safe string normalization, coordinate parsing, proximity detection).
- [x] **Core Topology Masters (T04 - DONE):** Master `poskos` (6 Posko resmi), `penyulangs` (16 canonical feeder), Many-to-Many pivot `posko_penyulang` (26 verified relations), command `topology:sync-core-masters`, alias mapping `TANGGAMUS` $\rightarrow$ `TANGGAMUS 2`.
- [x] **Automated Tests (DONE):** 73 tests passed, 274 assertions.
- [ ] **Grand Design UI (T05 - AVAILABLE):** Perancangan visual web & mobile lengkap.
- [ ] **Keypoint Canonical Layer (T06 - AVAILABLE):** Candidate model & canonicalization untuk 129 keypoint.
- [ ] **Section Master Final (T09 - BLOCKED):** Menunggu file eksternal FCO & Switching ULP Sribhawono yang sudah difilter.
- [ ] **Operational Asset Master (T10 - BLOCKED/PARTIAL):** 17,059 aset valid ada di staging; penetapan Posko penanggung jawab definitif per aset menunggu Section final.

---

## Phase 3 — Flutter + SQLite Draft/Cache Foundation
**Status:** `PENDING` | **Task ID:** `T19`

### Objective
Membuat app shell, local persistence, cache ringan, dan Submission Center.

### Scope
- local SQLite schema;
- session secure storage;
- reference bootstrap/cache;
- local draft CRUD;
- local photo reference;
- statuses DRAFT/READY/SENDING/SUBMITTED/FAILED;
- connectivity banner;
- Kirim/Kirim Semua skeleton;
- idempotency key generation.

### Acceptance Criteria
- draft bertahan setelah kill/restart;
- draft dapat diedit;
- cache asset/section/posko/petugas tersedia offline;
- login baru tetap memerlukan internet.

---

## Phase 4 — Documentation Photo Pipeline
**Status:** `PENDING` | **Task ID:** `T12`

### Scope
- camera/photo picker;
- local file persistence;
- metadata BEFORE/AFTER/TEMUAN/SURVEY;
- submit validation minimal one photo;
- multipart domain submission (`payload` + `photos[]`);
- Laravel Filesystem upload;
- local disk development;
- S3 adapter/config preparation.

### Acceptance Criteria
- foto offline bertahan setelah restart;
- submit tanpa foto wajib ditolak;
- upload gagal tidak menghilangkan draft;
- retry tidak duplicate.

---

## Phase 5 — P0 End-to-End Vertical Slice
**Status:** `PENDING` | **Task ID:** `T11`

### Scope
- Admin create P0 untuk asset yang sudah tervalidasi;
- unknown point HARSUS/PASILA masuk validation queue dahulu, bukan langsung sebagai P0 executable;
- snapshot kebutuhan padam/material pada P0;
- POSKO fetch/accept/start;
- offline result draft + photo;
- complete submission via canonical P0 endpoint;
- NEXT_SHIFT;
- CUSTOM_RESCHEDULE request + Admin approve/reject;
- OCC conflict;
- detailed status/reschedule history (`occurred_at` vs `recorded_at`).

### Acceptance Criteria
- P0 ID tetap sama saat reschedule;
- NEXT_SHIFT tidak perlu Admin approval;
- custom reschedule PENDING tidak mengubah status dari DIKERJAKAN; approved → RESCHEDULED, rejected → tetap DIKERJAKAN;
- P0 yang muncul ke POSKO selalu memiliki asset/Section/Posko + snapshot kebutuhan eksekusi;
- two-device stale update menghasilkan conflict;
- input time dan submit time tersimpan terpisah.

---

## Phase 6 — Work Order Preventif (WO APKUK)
**Status:** `PENDING` | **Task ID:** `T13`

### Scope
- jenis MVP hanya PEMASANGAN_APKUK;
- eligibility filter asset;
- target 6/shift/Posko;
- `priority_score` + `alasan_prioritas`;
- manual WO Admin;
- execution mobile;
- reschedule bila tidak sempat akibat prioritas P0;
- carry-over memakai kuota shift target terlebih dahulu (total tetap 6);
- WO completion create/update APKUK aktif pada asset.

### ML strategy
Implementasikan scoring interface/adapter. Fallback development dapat rule-based. Model ML final dapat dipasang tanpa mengubah contract WO.

### Acceptance Criteria
- total WO aktif/carry-over + WO baru pada satu shift/Posko tidak melebihi target 6;
- APKUK aktif/WO aktif duplicate tidak dipilih;
- alasan prioritas terlihat mobile;
- completion WO membuat/memperbarui APKUK aktif sehingga asset tidak terpilih lagi pada generation berikutnya.

---

## Phase 7 — PASILA Engine
**Status:** `PENDING` | **Task ID:** `T14`

### Scope
- recommendation endpoint;
- one session per operational context;
- override target dengan alasan;
- multi-action;
- gagal eksekusi: alasan + padam + material;
- escalation P0;
- unknown-point action → Admin location validation sebelum P0 executable;
- ROW incidental.

### Acceptance Criteria
- PASILA tersedia tiap shift;
- recommendation dapat dipakai atau dioverride;
- override tanpa reason ditolak.

---

## Phase 8 — HARSUS Inspection
**Status:** `PENDING` | **Task ID:** `T15`

### Scope
- inspection existing asset;
- unknown point coordinate+photo;
- condition enum;
- kebutuhan padam;
- kebutuhan material;
- perlu P0;
- Admin validation/mapping asset.

### Acceptance Criteria
- unknown point dapat dibuat offline sebagai draft dan disubmit;
- Admin dapat validate/create/map asset.

---

## Phase 9 — ROW / Vegetasi
**Status:** `PENDING` | **Task ID:** `T16`

### Scope
- RABAS/PANGKAS/TEBANG;
- photo+catatan;
- ROW incidental history;
- vegetation proposal coordinate+photo;
- Admin approve/reject proposal.

Tidak ada field teks wajib kondisi sebelum/sesudah.

---

## Phase 10 — Maps & Asset History View
**Status:** `PENDING` | **Task ID:** `T17`

### Scope
- online Google Maps;
- markers asset/vegetasi/pekerjaan;
- asset detail/history online;
- navigation intent;
- cached coordinates optional offline.

Full offline basemap out-of-scope MVP.

---

## Phase 11 — Special Case Engine
**Status:** `PENDING` | **Task ID:** `T18`

### Rule
`>3 trip / penyulang / calendar month`.

### Scope
- monthly aggregation;
- create/update Special Case;
- identify candidate assets;
- same issue + active P0 → append context/evidence;
- different issue → allow new P0;
- unknown/unready context harus divalidasi hingga kebutuhan eksekusi tersedia sebelum P0 baru ditugaskan;
- expose factor to WO prioritization.

### Acceptance Criteria
- no identical duplicate P0 for same issue;
- different issue can create separate P0.

---

## Phase 12 — Offline Draft & Submission Hardening
**Status:** `PENDING` | **Task ID:** `T19`

### Scope
- bulk partial success;
- retry;
- app crash recovery;
- storage-full handling;
- account disabled while offline;
- two-device conflict;
- input time vs submit time;
- cleanup policy local submitted draft;

### Acceptance Criteria
Pass all acceptance scenarios from OFFLINE_SYNC_SPEC.

---

## Phase 13 — Laravel Admin Operational Dashboard
**Status:** `PENDING` | **Task ID:** `T20`

### Scope
- dashboard KPI;
- P0/WO/PASILA/HARSUS/ROW/Special Case monitor;
- reschedule approval;
- validation queues;
- correction UI + reason + audit;
- all master CRUD;
- app config;
- polling sekitar 15 detik + manual refresh.

### Out of Scope
- Ranking Posko MVP.
- Realtime WebSocket requirement.

---

## Phase 14 — Integration, Testing & Security Hardening
**Status:** `PENDING` | **Task ID:** `T21`

### Scope
- API authorization matrix tests;
- input validation;
- idempotency/concurrency;
- file upload abuse tests;
- end-to-end role flows;
- data quality checks;
- audit checks;
- regression.

---

## Phase 15 — Deployment Readiness & Production Gate
**Status:** `PENDING` | **Task ID:** `T22`

### Scope
- production env config;
- PostgreSQL backup;
- storage config S3/approved provider;
- Laravel scheduler/queue process;
- HTTPS/domain;
- Android release build;
- migration/runbook;
- restore test;
- stakeholder/UAT gate.

---

# 6. Milestones

1. Foundation runnable.
2. Auth + master ready.
3. Mobile draft queue ready.
4. P0 vertical slice complete.
5. Photo pipeline complete.
6. WO/PASILA/HARSUS/ROW complete.
7. Maps + Special Case complete.
8. Offline hardening complete.
9. Admin monitoring complete.
10. Production readiness.

---

# 7. Global Test Matrix

- role/authorization
- master data integrity
- P0 lifecycle/reschedule
- WO eligibility/target
- PASILA recommendation/override
- HARSUS unknown point
- ROW proposal
- Special Case duplicate rule
- draft persistence
- bulk partial success
- idempotency
- OCC conflict
- photo requirement
- timestamps
- audit log

---

# 8. Global Definition of Done

- Core role flows pass.
- No secret in repository.
- Server validates all critical rules.
- Draft offline does not lose data.
- Failed submissions can retry safely.
- Submitted/completed data field-user immutable.
- Admin correction audited.
- Production runbook exists.

---

# 9. Open Question Gates

## Before Phase 6 production scoring
- final ML/scoring model and feature weights.

## Before Phase 9 scheduling automation
- ROW next-date formula.

## Before Phase 11 Special Case production logic
- taxonomy `issue_type` dan comparator konsisten untuk menentukan “masalah sama” vs “masalah berbeda”.

## Before Production
- deployment provider;
- storage provider/bucket;
- backup/retention policy;
- production gangguan ingest method;
- final photo compression/limits.

No gate remains for shift hours, PowerSync, Next.js, full offline sync, PASILA target behavior, Special Case period, HARSUS unknown asset, account model, WO type/target.

---

# 10. Final Execution Sequence

Phase 0 may start only from the **seven locked baseline documents** in this package. Previous Supabase/PowerSync drafts are historical only and must not be used as implementation source-of-truth.
