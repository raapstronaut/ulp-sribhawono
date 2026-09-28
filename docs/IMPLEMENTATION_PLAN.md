# IMPLEMENTATION PLAN & EXECUTION ROADMAP

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Final / Locked Baseline  
**Dokumen Acuan:** PRD.md · DESIGN_BRIEF.md · TECHNICAL_SPEC.md · DATA_MODEL.md · OFFLINE_SYNC_SPEC.md · API_CONTRACT.md  
**Ruang Lingkup:** Flutter Android Field App · Laravel Backend + Admin Web · PostgreSQL · SQLite Draft/Cache · File Storage · Google Maps

---

# 1. Implementation Strategy

Prinsip:
1. Bangun vertical slice sederhana terlebih dahulu.
2. Laravel menjadi satu server application untuk API + Admin Web.
3. Hindari PowerSync, Next.js, Supabase-heavy architecture, realtime, dan CDN pada MVP.
4. SQLite digunakan untuk draft/cache/outbox, bukan database replication.
5. Server tetap authoritative.
6. ML tidak boleh menghambat pembangunan core workflow; sediakan adapter/fallback.
7. Dokumentasi dan migration harus mengikuti keputusan bisnis final.

Ranking Posko tetap **v2**.

---

# 2. Dependency Graph

```text
Phase 0 Repo/Foundation
   ↓
Phase 1 Laravel + PostgreSQL + Auth
   ↓
Phase 2 Master Data
   ↓
Phase 3 Flutter + SQLite Draft Foundation
   ↓
Phase 4 Photo pipeline
   ↓
Phase 5 P0 vertical slice
   ↓
Phase 6 WO
   ↓
Phase 7 PASILA
   ↓
Phase 8 HARSUS
   ↓
Phase 9 ROW
   ↓
Phase 10 Maps/History
   ↓
Phase 11 Special Case
   ↓
Phase 12 Offline Draft/Submission Hardening
   ↓
Phase 13 Admin Operational Dashboard
   ↓
Phase 14 Integration/Security/Test
   ↓
Phase 15 Deployment Readiness
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
├── mobile/                 # Flutter
├── backend/                # Laravel API + Admin Web
├── docs/
├── scripts/
├── data/
│   └── synthetic/
├── .gitignore
└── README.md
```

No Next.js app, no PowerSync config, no Supabase Functions folder.

---

# 5. Phase-by-Phase Implementation Plan

## Phase 0 — Repository & Development Foundation

### Objective
Membuat fondasi project tanpa business feature besar.

### Scope
- init private Git repository;
- folder `mobile`, `backend`, `docs`;
- Laravel project bootstrap;
- Flutter project bootstrap;
- PostgreSQL dev connection;
- `.env.example` + `.gitignore`;
- formatting/lint/test baseline;
- README setup/run instructions;
- copy 7 locked documents as source-of-truth docs.

### Acceptance Criteria
- Laravel dapat run lokal;
- Flutter dapat build/run starter screen;
- PostgreSQL connection tervalidasi;
- tidak ada secret committed;
- CI sederhana opsional jika waktu memungkinkan.

### Exit Gate
Fondasi runnable sebelum migration bisnis dibuat.

---

## Phase 1 — Laravel Database, Auth & Security Foundation

### Objective
Membuat schema core, user/team accounts, login, authorization, audit foundation.

### Scope
- migrations master dasar;
- `users` role ADMIN/POSKO/HARSUS/ROW;
- Laravel Auth/Sanctum baseline;
- Policies/middleware;
- audit log foundation;
- app config shift.

### Acceptance Criteria
- login Flutter online berhasil;
- Admin Web login berhasil;
- non-ADMIN tidak dapat akses Admin Web;
- POSKO scoped ke Posko sendiri.

---

## Phase 2 — Master Data & Initial Import Foundation

### Scope
- Posko
- Penyulang
- Section
- Asset
- APKUK
- Vegetasi
- Petugas
- admin CRUD semua master
- import/profiling source

### Exit Gate
Master minimal cukup untuk menjalankan P0/WO/HARSUS/ROW test.

---

## Phase 3 — Flutter + SQLite Draft/Cache Foundation

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

## Phase 6 — Work Order Preventif

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

## Phase 7 — PASILA

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

## Phase 8 — HARSUS

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

### Scope
- RABAS/PANGKAS/TEBANG;
- photo+catatan;
- ROW incidental history;
- vegetation proposal coordinate+photo;
- Admin approve/reject proposal.

Tidak ada field teks wajib kondisi sebelum/sesudah.

---

## Phase 10 — Maps & Asset History View

### Scope
- online Google Maps;
- markers asset/vegetasi/pekerjaan;
- asset detail/history online;
- navigation intent;
- cached coordinates optional offline.

Full offline basemap out-of-scope MVP.

---

## Phase 11 — Special Case Engine

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
