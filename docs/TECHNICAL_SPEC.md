# TECHNICAL SPECIFICATION

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Business Flow v2 Baseline (Updated per 4 Oktober 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Source of Truth:** PRD.md · DESIGN_BRIEF.md · DATA_MODEL.md · API_CONTRACT.md · OFFLINE_SYNC_SPEC.md
**Scope:** ULP Sribhawono (single-tenant)
**Platforms:** Flutter Android field app · Laravel Admin Web/API

---

# 1. Architecture Overview

```text
FIELD USERS
POSKO / HARSUS / INSPEKSI / ROW
        │
        ▼
Flutter Android App
        │
        ├── SQLite local
        │   ├── cache ringan
        │   ├── draft input
        │   ├── pending submission/outbox
        │   └── local photo references
        │
        └── HTTPS REST API saat online
                │
                ▼
             Laravel
        ┌────────┼──────────────┐
        │        │              │
     Auth/API  Business       Scheduler/
               Logic          Queue
        │        │              │
        └────────┴──────┬───────┘
                       ▼
                  PostgreSQL

ADMIN
  │
  ▼
Laravel Web Admin
  │
  └──────────────► PostgreSQL

Photo Storage: Laravel Filesystem → local disk (development) / Amazon S3 (production-ready option)
Maps: Google Maps
```

### Data Ingestion & Master Staging Pipeline:
```text
Google Spreadsheet / Source Files
               ↓
    Laravel Import Commands (Artisan CLI)
               ↓
    Raw / Staging PostgreSQL Tables (All String)
               ↓
    Audit / Validation / Canonicalization Engine
               ↓
    Canonical Operational Masters (poskos, penyulangs, posko_penyulang)
```

Key decisions:
1. Single-tenant ULP Sribhawono.
2. Flutter tetap digunakan untuk Android field app.
3. Laravel menjadi **backend API + Web Admin**.
4. PostgreSQL tetap primary server database.
5. SQLite hanya untuk cache/draft/outbox mobile; **bukan full replica database server**.
6. Data Ingestion & Sync Topologi diimplementasikan sebagai internal Laravel Artisan CLI commands, bukan public API.
7. Tidak menggunakan PowerSync pada baseline baru.
8. Tidak menggunakan Next.js, Supabase Auth, Supabase Edge Functions, atau Supabase Realtime pada baseline baru.
9. Admin Web online-primary.
10. Offline mobile hanya menjamin input/draft/foto lokal; data server baru hanya terlihat setelah device online.
11. Dashboard Admin memakai polling ringan sekitar 15 detik + manual refresh.

---

# 2. Technology Selection Rationale

| Layer | Technology | Rationale |
|---|---|---|
| Android | Flutter + Dart | Tetap sesuai desain mobile lapangan dan dapat menggunakan SQLite/camera/GPS/maps. |
| Backend/API | Laravel | Menyatukan API, auth, business logic, scheduler, validation, audit, dan Admin Web. |
| Admin Web | Laravel Blade/Livewire atau stack Laravel yang setara | Menghindari frontend framework kedua untuk MVP. Implementasi dipilih yang paling sederhana saat development. |
| Server DB | PostgreSQL | Data model sudah PostgreSQL-oriented dan cocok untuk relasi/analitik. |
| Local DB | SQLite | Persistensi draft, cache ringan, outbox, dan metadata foto lokal. |
| Auth | Laravel Auth + token API (Sanctum sebagai baseline) | Satu boundary auth untuk Web Admin dan Flutter. |
| Photo | Laravel Filesystem | Development dapat lokal; production dapat berpindah ke S3 tanpa mengubah domain logic. |
| Maps | Google Maps | Marker, detail titik, dan navigasi. |
| Jobs | Laravel Scheduler + Queue | Generate WO, Special Case detection, dan background processing. |
| Monitoring | Logging Laravel; Sentry opsional | Sentry tidak menjadi blocker MVP. |
| Source control | Private Git repository | Source code/version control. Secret tidak boleh masuk repo. |

### Tidak digunakan pada baseline baru
- Next.js
- PowerSync
- Supabase Auth
- Supabase Edge Functions
- Supabase Realtime
- CloudFront sebagai requirement MVP
- Full offline basemap

---

# 3. Android Architecture

## 3.1 Pattern

Gunakan struktur sederhana dan terpisah:
- Presentation/UI
- Domain/use case
- Repository
- Local data source (SQLite/files)
- Remote data source (Laravel REST API)

BLoC/Cubit dapat digunakan, tetapi jangan membuat abstraction berlebihan sebelum dibutuhkan.

## 3.2 Flutter Packages Baseline

- `dio` atau HTTP client setara — REST API
- `sqflite` / SQLite package yang stabil — local storage
- `flutter_bloc` atau state-management yang dipilih project
- `go_router`
- `connectivity_plus`
- `camera` / `image_picker`
- `path_provider`
- `geolocator`
- `google_maps_flutter`
- `uuid`
- secure storage untuk token/session

## 3.3 App Initialization

1. Buka local SQLite.
2. Muat session/token lokal.
3. Tampilkan shell berdasarkan role tersimpan.
4. Jika online, validasi session ke Laravel.
5. Refresh cache ringan dan pekerjaan terbaru.
6. Tampilkan badge pending draft.
7. Jika offline, tetap izinkan akses ke draft/cache yang sudah tersedia.

## 3.4 Navigation

POSKO: Home · Pekerjaan (WO) · PASILA · Maps · Draft
HARSUS: Home · P0 List · Maps · Riwayat · Draft
INSPEKSI: Home · Inspeksi · Vegetasi Proposal · Maps · Riwayat · Draft
ROW: Home · Pekerjaan · Maps · Riwayat · Draft

## 3.5 Shift Detection

Final default (berlaku untuk POSKO):
- Shift A: 08:00–16:00
- Shift B: 16:00–00:00
- Shift C: 00:00–08:00

HARSUS **TIDAK** berbasis shift. P0 tidak menggunakan shift assignment untuk HARSUS.

Simpan konfigurasi shift di `app_config` agar dapat diubah Admin bila stakeholder mengubah kebijakan.

---

# 4. Admin Web Architecture

Laravel Web Admin hanya untuk role ADMIN pada MVP.

Halaman minimum:
- `/login`
- `/dashboard`
- `/p0`
- `/wo`
- `/pasila`
- `/inspeksi`
- `/row`
- `/special-cases`
- `/maps`
- `/assets`
- `/master/posko`
- `/master/penyulang`
- `/master/section`
- `/master/aset`
- `/master/apkuk`
- `/master/vegetasi`
- `/master/petugas`
- `/users`
- `/audit-log`
- `/settings`

Dashboard melakukan polling ringan sekitar 15 detik. Hindari WebSocket/realtime infrastructure pada MVP kecuali kemudian terbukti dibutuhkan.

---

# 5. Backend Architecture

Laravel adalah server application utama.

Layer arsitektur:
- Routes (API v1 & Web Admin)
- Controllers
- Form Requests / validation
- Policies / authorization
- Services / use cases
- Eloquent models/repositories
- Console Commands & Scheduler (Data ingestion, auditing, sync)
- Audit service

Domain services minimum:
- `P0Service`
- `WorkOrderService`
- `PasilaService`
- `InspeksiService`
- `RowService`
- `SpecialCaseService`
- `SubmissionService`
- `PhotoService`
- `AuditService`

### Internal Console Commands (Implemented per 30 September 2026):
Pipeline import dan sinkronisasi topologi diimplementasikan sebagai artisan console commands internal (**BUKAN PUBLIC API**):
1. `apkuk:import-sheet` (dengan opsi `--replace`) — Ingest data Sheet1 APKUK ke `apkuk_source_rows` via Google Sheets API.
2. `sources:import-sheets` — Ingest multi-source sheets (ASSET_RAW, VEGETASI_RAW, GANGGUAN_RAW, SECTION_PIC_RAW, KEYPOINT_RAW) ke staging tables.
3. `sources:profile-sheets` — Profiling kolom dan struktur header spreadsheet sumber.
4. `sources:audit-staging` — Audit read-only kualitas data staging dan cross-reference vegetasi → aset.
5. `topology:audit` — Audit topologi read-only (fill-down in-memory, name & type normalization, coordinate parsing, feeder coverage, review items).
6. `topology:sync-core-masters` — Sinkronisasi update-safe core master `poskos`, `penyulangs`, dan pivot Many-to-Many `posko_penyulang` dari staging `section_pic_source_rows`.

---

# 6. PostgreSQL Architecture

Schema server final mengikuti `DATA_MODEL.md`. Technical Spec tidak menduplikasi DDL lengkap agar tidak terjadi dua sumber kebenaran.

Prinsip persistence:
- UUID sebagai primary key server.
- Business key PLN dipertahankan.
- no hard delete untuk transaksi operasional.
- status history dan audit log append-oriented.
- P0 executable selalu memiliki asset + Section + Posko tervalidasi. `posko_id` pada P0 adalah lokasi/wilayah operasional, bukan executor. Unknown point tetap pada source INSPEKSI/PASILA sampai Admin validation selesai.
- draft mobile **tidak** menjadi tabel server sampai submit.

---

# 7. Authentication and Authorization

## 7.1 Login

Flutter login melalui Laravel API ketika online. Laravel mengembalikan token API. Web Admin menggunakan Laravel session authentication.

## 7.2 Roles

Business roles:
- ADMIN
- POSKO
- HARSUS
- INSPEKSI
- ROW

Akun dapat mewakili tim/unit, bukan orang individual. Petugas aktual dipilih dari master `petugas`.

POSKO: terhubung ke satu Posko.
HARSUS: tidak terikat satu Posko (seluruh ULP).
INSPEKSI: tidak terikat satu Posko (seluruh ULP).

## 7.3 Offline Session

Jika token/session lokal sudah ada, aplikasi boleh membuka mode draft offline. Begitu online, Laravel memvalidasi kembali status akun. Akun yang sudah dinonaktifkan akan ditolak pada request online; draft lokal tidak dihapus otomatis.

---

# 8. Role Permissions

### ADMIN
Full monitoring, master CRUD, manual P0/WO, validation/approval, reschedule approval, corrections, audit, Special Case review/P0 creation.

### POSKO
Read pekerjaan WO/PASILA Posko sendiri, execute WO/PASILA, pilih petugas, create draft, submit result. **TIDAK** mengeksekusi P0. Incidental vegetation only through PASILA.

### HARSUS
View P0 across whole ULP, accept/start/reschedule-request/complete P0. **TIDAK** melakukan WO, PASILA, inspeksi, atau ROW.

### INSPEKSI
Inspections across whole ULP, unknown asset/location findings, P0 proposals through findings (Admin validates), new vegetation proposals (Admin validates). **TIDAK** mengeksekusi P0, WO, PASILA, atau ROW.

### ROW
Execute vegetation work only (RABAS/PANGKAS/TEBANG). **TIDAK** membuat proposal vegetasi baru. **TIDAK** mengeksekusi P0/WO/PASILA/inspeksi.

Authorization final tetap enforced server-side melalui Laravel Policy/Service validation; client-side hiding bukan security boundary.

---

# 9. Offline Draft Architecture

## 9.1 Principles

1. Offline tidak berarti full database synchronization.
2. Input/foto lapangan dapat disimpan lokal sebagai draft.
3. Draft dapat diedit sampai submit.
4. Data server terbaru memerlukan koneksi.
5. User memilih Kirim atau Kirim Semua saat online.
6. Setiap submission harus idempotent.
7. Input time dan server submit time disimpan terpisah.

## 9.2 Available Offline

- Draft WO response yang sudah dibuka/tersedia lokal (POSKO).
- PASILA draft/action (POSKO).
- P0 cached/available untuk completion/reschedule (HARSUS).
- Inspection draft (INSPEKSI).
- Vegetation proposal draft (INSPEKSI).
- ROW work draft.
- Foto lokal.
- Cache ringan asset/section/posko/petugas/koordinat yang sudah didownload.

Tidak dijamin offline:
- pekerjaan baru dari server;
- histori lengkap;
- dashboard;
- full basemap;
- login baru.

---

# 10. SQLite Draft + Outbox Flow

Local tables minimal:
- `cache_asset`
- `cache_section`
- `cache_posko`
- `cache_petugas`
- `local_draft`
- `local_draft_photo`
- `submission_outbox`

Local submission status:
- DRAFT
- READY_TO_SEND
- SENDING
- SUBMITTED
- FAILED

`local_draft` menyimpan:
- local UUID/idempotency key
- entity type
- server entity id bila merupakan update pekerjaan server
- payload JSON
- input timestamp
- cached server version
- status
- last error
- created/updated local time

---

# 11. Conflict Resolution

Server memakai optimistic concurrency untuk pekerjaan yang dapat berubah dari beberapa device.

Flow:
1. Device menyimpan `server_version` ketika terakhir membaca P0/WO.
2. Submit membawa `expected_version`.
3. Laravel update dengan syarat version masih sama.
4. Jika sudah berubah, response `409 CONFLICT`.
5. Client menandai draft conflict; tidak auto-overwrite.
6. User/Admin memeriksa data terbaru sebelum tindakan selanjutnya.

---

# 12. ID Generation

Draft memakai UUID lokal sebagai `idempotency_key`. Server entity tetap UUID PostgreSQL. Local UUID tidak harus menjadi PK server, tetapi dapat disimpan sebagai request key untuk duplicate prevention.

---

# 13. Photo Capture / Upload

Foto wajib untuk submission pekerjaan sesuai rule.

Flow offline:
1. Capture photo.
2. Simpan file di app-private storage.
3. Simpan metadata ke SQLite draft.
4. Draft tetap dapat disimpan walau validasi submit belum lengkap.

Flow online submit:
1. Validasi foto wajib ada.
2. Draft memanggil **endpoint domain canonical** dengan multipart logical submission (`payload` JSON + `photos[]`).
3. Laravel memvalidasi authorization, idempotency, OCC, payload, dan foto.
4. Laravel Filesystem menyimpan file; transaction domain + metadata dokumentasi diselesaikan.
5. Development: local disk; production: S3 dapat diaktifkan lewat konfigurasi.
6. Server menyimpan `input_at/occurred_at` dari device dan `submitted_at/recorded_at` dari server secara terpisah.
7. Hanya setelah seluruh requirement wajib berhasil, item lokal ditandai SUBMITTED.

Jenis foto: BEFORE, AFTER, TEMUAN, SURVEY.

---

# 14. Submission Queue

UI menyediakan:
- Kirim satu
- Kirim Semua
- Retry Failed

`Kirim Semua` adalah orchestration di Flutter, bukan endpoint bulk generik. Queue memanggil endpoint domain tiap item (concurrency rendah/serial paling sederhana). Jika 10 item dikirim dan 1 gagal, 9 boleh menjadi SUBMITTED dan 1 tetap FAILED.

---

# 15. File Storage

Gunakan Laravel Filesystem sebagai abstraction.

Development:
- local private storage.

Production baseline:
- Amazon S3 atau storage object lain yang disetujui.

Database hanya menyimpan metadata/path/object key, bukan binary image.

---

# 16. CDN

CloudFront **bukan requirement MVP**. Tambahkan hanya bila kebutuhan performa/akses production membenarkan kompleksitasnya.

---

# 17. Maps Architecture

Flutter: Google Maps SDK.  
Admin Web: Google Maps JS integration yang kompatibel dengan Laravel view/component.

Offline:
- koordinat/cache marker ringan boleh tersedia;
- full basemap tidak dijamin;
- navigasi eksternal membutuhkan koneksi/data map yang tersedia.

---

# 18. Background Jobs and Automation

Laravel Scheduler/Queue menangani:
- WO generation per shift;
- Special Case detection;
- optional data import processing;
- retry/background processing yang tidak perlu blocking request.

---

# 19. Special Case Processing

Rule awal: `trip_count > 3` per penyulang per **calendar month**.

Special Case **TIDAK** otomatis membuat P0.

Processing:
1. Agregasi gangguan bulan berjalan.
2. Create/update Special Case sebagai indikasi/rekomendasi.
3. Identifikasi aset kandidat.
4. Sistem membuat notifikasi/rekomendasi untuk Admin.
5. Admin membuka Special Case, mereview, dan dapat mengedit informasi P0 yang direkomendasikan.
6. Admin memutuskan apakah membuat P0. Jika ya, HARSUS mengeksekusi.
7. Untuk aset dengan P0 aktif:
   - masalah sama → append context/evidence ke P0 aktif;
   - masalah berbeda → Admin dapat membuat P0 baru.
8. Special Case juga dapat meningkatkan priority factor untuk WO ranking.

---

# 20. WO Generation Architecture

MVP hanya `PEMASANGAN_APKUK`.

Eligibility minimum:
- asset aktif;
- koordinat/section valid;
- belum memiliki APKUK aktif;
- tidak memiliki WO APKUK aktif yang identik.

Ranking:
- engine menerima `priority_score` + `alasan_prioritas`.
- ML dapat dipasang kemudian.
- sampai model ML siap, deterministic/rule-based fallback boleh digunakan untuk development/testing, tetapi formula production final tetap perlu stakeholder/ML validation.

Target: Top 6 per shift per Posko.

WO yang belum dikerjakan dapat di-reschedule. Carry-over mengisi kuota shift target terlebih dahulu: `new_wo_count = max(0, 6 - active_carry_over_count)`. Saat WO `PEMASANGAN_APKUK` selesai, completion handler create/update APKUK `AKTIF` pada asset dalam logical transaction yang sama agar aset tidak dipilih lagi.

## 20.1 PASILA Runtime Architecture

- Satu sesi PASILA maksimum satu per Posko + tanggal + shift.
- Laravel memberikan rekomendasi Section/aset beserta `recommendation_reason` berdasarkan prioritas gangguan/trip.
- POSKO dapat memakai rekomendasi atau override ke lokasi lain; override wajib `override_reason`.
- Satu session dapat memiliki banyak action.
- Action `TIDAK_DAPAT_DIEKSEKUSI` wajib alasan + kebutuhan padam + kebutuhan material; bila dieskalasi menjadi P0, ini adalah **proposal/request yang memerlukan Admin approval**. Kebutuhan tersebut dicopy sebagai snapshot P0 hanya setelah Admin menyetujui. Escalation **TIDAK** langsung membuat P0 executable.
- Action pada unknown point tidak membuat P0 executable sampai Admin mapping asset + Section/Posko selesai.
- P0 yang disetujui dari escalation PASILA ditugaskan ke HARSUS.

---

# 21. P0 Scheduling and Reschedule

Normal lifecycle:
BARU → DITERIMA → DIKERJAKAN → SELESAI.

HARSUS adalah executor P0. HARSUS **TIDAK** berbasis shift.

**REMOVED:** NEXT_SHIFT / "Lanjut Shift Berikutnya" untuk P0 (karena HARSUS tidak shift-based).
**REMOVED:** `scheduled_shift` requirement pada P0.

Jika belum selesai:

### RESCHEDULE (Date-based)
- target tanggal + alasan wajib;
- selama request `PENDING`, P0 tetap `DIKERJAKAN`;
- Admin APPROVE/REJECT;
- APPROVED → P0 `RESCHEDULED` ke target tanggal;
- REJECTED → P0 tetap `DIKERJAKAN`;
- saat pekerjaan dilanjutkan → `DIKERJAKAN`;
- ID P0 sama.

Semua perubahan dicatat pada `p0_reschedule_history` dan `status_history`.

### P0 execution readiness
P0 yang dikirim ke HARSUS wajib sudah memiliki asset + Section + Posko tervalidasi serta snapshot `kebutuhan_padam` dan `kebutuhan_material`. `posko_id` pada P0 adalah lokasi/wilayah operasional aset, bukan executor. Unknown point tetap berada pada source INSPEKSI/PASILA sampai Admin mapping selesai. Untuk source INSPEKSI/PASILA snapshot dicopy; P0 manual diisi Admin; Special Case yang belum punya kebutuhan eksekusi harus menunggu review sebelum P0 dibuat.

### P0 Petugas Rule
Petugas aktual dipilih oleh HARSUS saat completion/submission P0, **BUKAN** saat menerima, accept, atau memulai P0. Completion ditolak jika data completion wajib (termasuk Petugas) tidak lengkap.

---

# 22. Audit Trail / Logging

- `status_history` untuk status transaction, menyimpan `from_status`, `to_status`, actor, reason, `occurred_at` (waktu lapangan/input), `recorded_at` (waktu server), dan metadata.
- `audit_log` untuk koreksi Admin, master changes, config changes, validation, approval/reject, conflict resolution, dan keputusan Special Case; simpan before/after snapshot.
- Transaksi selesai tidak di-hard-delete.
- Field audit minimal: actor, action, entity, entity_id, before, after, occurred_at, recorded_at, request_id, reason bila relevan.

---

# 23. Security

- HTTPS.
- Password di-hash dengan mekanisme Laravel.
- API token disimpan di secure storage mobile.
- Server-side authorization wajib.
- Validation seluruh request di server.
- Upload file dibatasi MIME/size.
- Secret `.env`, DB password, API keys, access token, private key tidak pernah masuk Git.
- Owner mengizinkan data project masuk private repository; tetap gunakan prinsip minimisasi data dan jangan commit credential/secrets.

---

# 24. Backup and Disaster Recovery

Detail RPO/RTO production masih Open Question. Minimum sebelum production:
- backup PostgreSQL terjadwal;
- backup/object versioning sesuai storage provider;
- restore test;
- documented recovery steps.

---

# 25. Environments

- Development
- Staging (bila waktu memungkinkan/menjelang UAT)
- Production

Environment config melalui `.env`, tidak committed.

---

# 26. Deployment Architecture

Flutter menghasilkan Android build/APK/AAB.

Laravel membutuhkan runtime PHP, web server/platform managed, queue worker bila queue dipakai, scheduler invocation, PostgreSQL connection, dan storage configuration.

Provider deployment tidak dikunci di Technical Spec sebelum kebutuhan lingkungan nyata dipastikan.

---

# 27. Monitoring and Observability

Minimum MVP:
- Laravel structured logs;
- error log API;
- audit log domain;
- Flutter error handling.

Sentry dapat ditambahkan bila waktu/akses memungkinkan, tetapi bukan blocker Phase 0.

---

# 28. Initial Data Migration Strategy

Master awal: Posko, Penyulang, Section, Asset, APKUK, Vegetasi, Petugas bila tersedia. Data gangguan dipertahankan raw/staging sesuai source lalu dibentuk view/derived dataset untuk analitik.

Data transaksi P0/WO/PASILA/Inspeksi/ROW adalah transaksi baru sistem, bukan wajib historical import kecuali stakeholder menyediakan kebutuhan tambahan.

---

# 29. Recommended Repository Structure

```text
ulp-sribhawono/
├── mobile/                 # Flutter
├── backend/                # Laravel API + Admin Web
├── docs/
├── data/
│   ├── synthetic/
│   └── private-input/      # bila owner memang menyimpan data project private; tetap gitignore bila diperlukan
├── scripts/
├── .gitignore
└── README.md
```

Tidak ada folder Next.js, Supabase Functions, atau PowerSync config pada baseline baru.

---

# 30. Technical Risks

1. Draft lokal banyak dan storage foto device penuh.
2. Multi-device conflict pada satu akun.
3. Foto besar / jaringan tidak stabil menyebabkan submit gagal.
4. Integrasi ML terlambat — mitigasi: fallback ranking development.
5. Kualitas master asset/coordinate belum lengkap.
6. P0 lokasi belum tervalidasi dapat membutuhkan assignment manual Admin.
7. Deadline mepet — mitigasi: hindari realtime, CDN, dan custom sync engine kompleks.

---

# 31. Open Questions

- Formula ML/priority score final.
- Provider deployment production.
- S3 wajib atau storage provider lain saat production.
- Retention photo/data.
- Formula next ROW date.
- Integration method data gangguan production.
- Taxonomy `issue_type`/comparator masalah sama-vs-berbeda sebelum implementasi Special Case production.

Yang sudah dikunci tidak boleh dimunculkan lagi sebagai OQ: shift, PowerSync, Next.js, PASILA recommendation behavior, Special Case month window, inspection (now by INSPEKSI), role model (5 roles), WO target 6, WO APKUK-only MVP, correction by Admin, HARSUS = Pemeliharaan Khusus, HARSUS executes P0, INSPEKSI performs inspections, ROW execute-only.

---

# 32. Cloud / Repository Governance

Owner memutuskan repository private dapat berisi data project bila diperlukan. Namun:
- secret dan credential dilarang masuk Git;
- akses repository dibatasi;
- file besar/foto sensitif sebaiknya tidak dijadikan source-control asset bila tidak diperlukan;
- kebijakan organisasi PLN tetap dapat meng-overrule baseline ini jika kemudian ada aturan resmi.

---

# Appendix A: Glossary

- P0 — Penugasan Khusus
- PASILA — Patroli Eksekusi Langsung
- APKUK — kepanjangan resmi belum dikunci di dokumen ini
- HARSUS — Pemeliharaan Khusus
- ROW — istilah operasional ROW; kepanjangan resmi belum dikunci
- INSPEKSI — Role inspeksi aset/jaringan
- Draft — input lokal yang belum menjadi data resmi server
- Submission — pengiriman draft ke Laravel ketika online

---

# Appendix B: Data Flows

## B.1 Offline Draft → Submit

`Form → SQLite Draft → local photo → READY_TO_SEND → HTTPS Laravel → validation → file storage + PostgreSQL → SUBMITTED`

## B.2 P0 Completion (HARSUS)

`Laravel P0 → Flutter online fetch (HARSUS) → execute/draft → select Petugas at completion → submit → Laravel validation/OCC → PostgreSQL/history → SUBMITTED`

## B.3 WO Generation

`Scheduler → eligible asset filter → scoring/ML adapter → Top 6 → WO + alasan_prioritas`

## B.4 Special Case

`Gangguan raw → monthly aggregation → Special Case recommendation → Admin notification → Admin review/edit → Admin decision: create P0 or not → if P0 created, HARSUS executes`
