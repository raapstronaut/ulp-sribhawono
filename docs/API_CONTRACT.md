# API CONTRACT & MUTATION BOUNDARY SPECIFICATION

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Business Flow v2 Baseline (Updated per 4 Oktober 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Dokumen Acuan:** PRD.md · DESIGN_BRIEF.md · TECHNICAL_SPEC.md · DATA_MODEL.md · OFFLINE_SYNC_SPEC.md
**Lingkup Arsitektur:** Flutter Android App · Laravel Web/API · PostgreSQL · SQLite local draft/cache · Laravel Filesystem/S3 · Google Maps
**Lingkup Operasional:** ULP Sribhawono (Single-Tenant)

> [!IMPORTANT]
> **SPECIFICATION / PLANNED CONTRACT:**
> Seluruh endpoint REST API di bawah ini merupakan **TARGET CONTRACT SPECIFICATION** yang dirancang sebagai batas kontrak komunikasi antara Flutter Android dan Laravel Backend. Sebagian besar endpoint (P0, WO, PASILA, Inspeksi, ROW, Special Case, Master API) **BELUM DIIMPLEMENTASIKAN** di codebase (status: `PLANNED` / `PENDING`).
> Pipeline import data sumber dan sinkronisasi topologi saat ini berjalan melalui internal Laravel Artisan CLI commands, bukan melalui endpoint publik.

---

# 1. API Contract Principles

1. Laravel adalah satu-satunya API business boundary untuk Flutter dan Admin Web.
2. Seluruh mutasi server divalidasi ulang di server; validasi client hanya untuk UX.
3. Flutter dapat menyimpan draft offline, tetapi draft belum menjadi data resmi server.
4. Submission harus idempotent menggunakan `Idempotency-Key`/`client_request_id`.
5. Waktu aktivitas/input dan waktu server menerima submission disimpan terpisah.
6. Concurrency kritis menggunakan optimistic concurrency/version.
7. Bulk submit diproses per item; partial success diperbolehkan.
8. Role business: ADMIN, POSKO, HARSUS, INSPEKSI, ROW.

---

# 2. API Surface Overview (Target Specification)

Base proposal: `/api/v1`.

## Auth
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`

## Bootstrap / cache
- `GET /api/v1/mobile/bootstrap`
- `GET /api/v1/mobile/work-feed`
- `GET /api/v1/mobile/reference-data?updated_since=...`

## P0 (Executor: HARSUS)
- `GET /api/v1/p0`
- `GET /api/v1/p0/{id}`
- `POST /api/v1/p0/{id}/accept`
- `POST /api/v1/p0/{id}/start`
- `POST /api/v1/p0/{id}/complete`
- `POST /api/v1/p0/{id}/reschedule-request`
- `POST /api/v1/admin/p0`
- `POST /api/v1/admin/p0/{id}/reschedule-approve`
- `POST /api/v1/admin/p0/{id}/reschedule-reject`

**REMOVED:** `POST /api/v1/p0/{id}/continue-next-shift` — HARSUS is not shift-based.

## Work Order (Executor: POSKO)
- `GET /api/v1/work-orders`
- `GET /api/v1/work-orders/{id}`
- `POST /api/v1/work-orders/{id}/accept`
- `POST /api/v1/work-orders/{id}/start`
- `POST /api/v1/work-orders/{id}/complete`
- `POST /api/v1/work-orders/{id}/reschedule`
- `POST /api/v1/admin/work-orders`

## PASILA (Executor: POSKO)
- `GET /api/v1/pasila/recommendation`
- `POST /api/v1/pasila/sessions`
- `POST /api/v1/pasila/sessions/{id}/actions`
- `POST /api/v1/pasila/actions/{id}/escalate-p0`
- `POST /api/v1/admin/pasila/actions/{id}/validate-location`
- `POST /api/v1/admin/pasila/escalations/{id}/approve`
- `POST /api/v1/admin/pasila/escalations/{id}/reject`
- `POST /api/v1/pasila/sessions/{id}/complete`

**IMPORTANT:** `escalate-p0` creates a **proposal/request** that requires Admin approval. It does **NOT** directly create executable P0. Admin must approve via the approve endpoint before P0 is created for HARSUS.

## Inspections (Creator: INSPEKSI)
- `POST /api/v1/inspections`
- `GET /api/v1/inspections/{id}`
- `POST /api/v1/admin/inspections/{id}/validate-location`
- `POST /api/v1/admin/inspections/{id}/approve-p0`

**RENAMED:** from `/harsus/inspections` to `/inspections`. Authorization owner is **INSPEKSI** role.

## Vegetation Proposals (Creator: INSPEKSI)
- `POST /api/v1/vegetation-proposals`
- `POST /api/v1/admin/vegetation-proposals/{id}/approve`
- `POST /api/v1/admin/vegetation-proposals/{id}/reject`

**Authorization change:** Created by **INSPEKSI**, not ROW. ROW does not have authorization to create vegetation proposals.

## ROW (Executor: ROW)
- `POST /api/v1/row/work`

ROW endpoints are for work submission/execution only (RABAS/PANGKAS/TEBANG). No vegetation proposal creation.

## Special Case
- `GET /api/v1/admin/special-cases`
- `GET /api/v1/admin/special-cases/{id}`
- `POST /api/v1/admin/special-cases/{id}/create-p0`

**NEW:** `create-p0` endpoint documents explicit Admin action for converting a Special Case recommendation into P0. Admin may edit P0 fields before creation. **No automatic P0 creation.** Special Case is a system recommendation that requires Admin decision.

## Documentation
- `GET /api/v1/documentation/{id}`

> Mobile **tidak** memakai generic `/mobile/submissions` atau endpoint bulk server. Draft/Submission Center memanggil endpoint domain canonical (P0/WO/PASILA/Inspeksi/ROW) satu item per request. Tombol **Kirim Semua** hanya mengorkestrasi antrean tersebut dari Flutter.

## Admin correction/master
- `PATCH /api/v1/admin/entities/{type}/{id}`
- CRUD master endpoints under `/api/v1/admin/master/...`

---

# 3. Transport & Naming Conventions

- HTTPS only.
- JSON for normal API payloads.
- Multipart for photo upload where appropriate.
- UUID in URLs/IDs.
- ISO-8601 timestamps with timezone.
- Enum values uppercase snake case.
- Pagination uses `page`, `per_page` or cursor if later required.

---

# 4. Standard Request Metadata

Headers minimum:

```http
Authorization: Bearer <token>
Accept: application/json
X-Client-Version: <app_version>
X-Device-Id: <installation_id>
Idempotency-Key: <uuid>
```

For updates to versioned entity:

```json
{
  "expected_version": 4
}
```

---

# 5. Standard Response Envelope

Success:

```json
{
  "success": true,
  "data": {},
  "meta": {}
}
```

Error:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Data tidak valid",
    "fields": {}
  }
}
```

---

# 6. Standard Error Model

Minimum error codes:
- `UNAUTHENTICATED` — 401
- `FORBIDDEN` — 403
- `NOT_FOUND` — 404
- `VALIDATION_ERROR` — 422
- `CONFLICT` — 409
- `IDEMPOTENCY_REPLAY` — 200/appropriate success replay
- `ACCOUNT_DISABLED` — 403
- `PHOTO_REQUIRED` — 422
- `SERVER_ERROR` — 500

---

# 7. Authentication Contract

## Mobile
Flutter login online ke Laravel. Baseline token API menggunakan Laravel Sanctum atau mekanisme resmi Laravel yang dipilih saat implementasi.

`POST /api/v1/auth/login`

```json
{
  "login": "posko-sribawono",
  "password": "***",
  "device_name": "android-device"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "token": "...",
    "user": {
      "id": "uuid",
      "role": "POSKO",
      "posko_id": "uuid",
      "display_name": "Posko Sribawono"
    }
  }
}
```

Role values: `ADMIN`, `POSKO`, `HARSUS`, `INSPEKSI`, `ROW`.

Login baru membutuhkan internet. Local session dapat dipakai untuk membuka draft offline, tetapi server melakukan revalidation saat request berikutnya.

## Web Admin
Laravel session authentication. Route Admin wajib role ADMIN.

---

# 8. Authorization Matrix

| Capability | ADMIN | POSKO | HARSUS | INSPEKSI | ROW |
|---|---:|---:|---:|---:|---:|
| Admin Web | ✅ | ❌ | ❌ | ❌ | ❌ |
| Master CRUD | ✅ | ❌ | ❌ | ❌ | ❌ |
| Manual P0/WO | ✅ | ❌ | ❌ | ❌ | ❌ |
| Validation/Approval | ✅ | ❌ | ❌ | ❌ | ❌ |
| Correction + Audit | ✅ | ❌ | ❌ | ❌ | ❌ |
| Monitor everything | ✅ | ❌ | ❌ | ❌ | ❌ |
| View P0 (whole ULP) | monitor | ❌ | ✅ | ❌ | ❌ |
| Accept/Start/Complete P0 | ❌ | ❌ | ✅ | ❌ | ❌ |
| Reschedule request P0 | ❌ | ❌ | ✅ | ❌ | ❌ |
| Execute WO | ❌ | ✅ own Posko | ❌ | ❌ | ❌ |
| Execute PASILA | ❌ | ✅ own Posko | ❌ | ❌ | ❌ |
| Incidental vegetation (via PASILA) | ❌ | ✅ | ❌ | ❌ | ❌ |
| Create inspection | ❌ | ❌ | ❌ | ✅ whole ULP | ❌ |
| Unknown asset/location findings | ❌ | ❌ | ❌ | ✅ | ❌ |
| P0 proposals through findings | ❌ | ❌ | ❌ | ✅ | ❌ |
| New vegetation proposals | ❌ | ❌ | ❌ | ✅ | ❌ |
| Execute ROW work | ❌ | ❌ | ❌ | ❌ | ✅ |

---

# 9. P0 Contracts (Executor: HARSUS)

## Create Manual — ADMIN
`POST /api/v1/admin/p0`

P0 executable hanya dibuat untuk asset + Section + Posko yang sudah tervalidasi. Unknown point tetap berada pada source record (Inspection/PASILA/location finding) sampai Admin memetakan/membuat asset, lalu P0 dibuat dan muncul ke HARSUS.

```json
{
  "asset_id": "validated-asset-uuid",
  "issue_type": "KONSTRUKSI",
  "uraian": "Perbaikan konstruksi",
  "kebutuhan_padam": "PERLU_PADAM",
  "kebutuhan_material": "Cross arm + baut",
  "scheduled_date": "2026-09-28"
}
```

**REMOVED:** `scheduled_shift` — HARSUS is not shift-based.

Validation: `asset_id`, derived `section_id`, `posko_id` (as location), `kebutuhan_padam`, dan `kebutuhan_material` wajib siap sebelum P0 executable dibuat. `posko_id` represents location/territory, not executor. Executor = HARSUS.

## Reschedule Request (Date-based)
`POST /api/v1/p0/{id}/reschedule-request`

```json
{
  "target_date": "2026-09-29",
  "reason": "Material belum tersedia",
  "expected_version": 4,
  "input_at": "2026-09-27T15:50:00+07:00"
}
```

**REMOVED:** `target_shift` — HARSUS not shift-based.

Admin approve/reject melalui endpoint Admin. Selama request masih PENDING, P0 tetap `DIKERJAKAN`; jika APPROVED menjadi `RESCHEDULED`, jika REJECTED tetap `DIKERJAKAN`. Saat pekerjaan dilanjutkan, status kembali `DIKERJAKAN`. P0 tetap ID sama.

## Complete
`POST /api/v1/p0/{id}/complete`

Completion by HARSUS includes:
- Petugas selection from Master Petugas (mandatory at completion)
- Photo documentation
- Completion data

Foto minimal sesuai rule wajib tersedia sebelum completion accepted. Completion rejected if required data (including Petugas) is missing.

---

# 10. Work Order Contracts (Executor: POSKO)

MVP `jenis_wo` hanya `PEMASANGAN_APKUK`.

Response detail wajib menyertakan:
- `priority_score`
- `alasan_prioritas`

`POST /api/v1/work-orders/{id}/complete` menerima hasil completion + minimal satu foto dan `apkuk_code` opsional. Jika accepted, server dalam logical transaction yang sama mengubah WO menjadi `SELESAI` dan create/update APKUK `AKTIF` pada asset dengan `tgl_pasang` dari `input_at`.

WO yang belum sempat dikerjakan dapat di-reschedule dengan alasan + target tanggal/shift.

`POST /api/v1/work-orders/{id}/reschedule`
```json
{
  "target_date": "2026-09-28",
  "target_shift": "B",
  "reason": "Prioritas lain mengambil waktu shift",
  "expected_version": 2,
  "input_at": "2026-09-27T15:50:00+07:00"
}
```
Server memperbarui `tanggal/shift` WO menjadi target baru dan menulis `wo_reschedule_history` berisi jadwal asal + target. Carry-over menghitung kuota terlebih dahulu: jika ada 3 WO carry-over ke Shift B, generator hanya membuat 3 WO baru sehingga total target tetap 6/shift/Posko.

---

# 11. PASILA Contracts (Executor: POSKO)

## Recommendation
`GET /api/v1/pasila/recommendation?date=2026-09-27&shift=A`

Response:

```json
{
  "recommended_section_id": "uuid",
  "recommended_asset_id": "uuid-or-null",
  "reason": "Trip tertinggi pada periode prioritas"
}
```

## Create Session
Jika memakai rekomendasi, `is_override=false`.
Jika memilih lokasi lain, `is_override=true` dan `override_reason` wajib.

## Action Tidak Bisa Dieksekusi
Payload menyediakan:
- `alasan_tidak_dieksekusi`
- `kebutuhan_padam`: TANPA_PADAM/PERLU_PADAM
- `kebutuhan_material`
- `escalate_to_p0`

## Escalation to P0
`POST /api/v1/pasila/actions/{id}/escalate-p0`

**IMPORTANT:** This creates a **proposal/request** that requires **Admin approval**. It does **NOT** directly create executable P0.

Flow:
1. POSKO submits escalation with kebutuhan data.
2. Admin reviews via `POST /api/v1/admin/pasila/escalations/{id}/approve` or `reject`.
3. If approved: P0 created with snapshot kebutuhan padam/material. P0 assigned to HARSUS.
4. If asset is unvalidated, Admin must first map asset via location validation endpoint.

---

# 12. Inspection Contracts (Creator: INSPEKSI)

`POST /api/v1/inspections`

**RENAMED:** from `/harsus/inspections` to `/inspections`. Authorization owner is **INSPEKSI** role.

Asset existing:
```json
{
  "asset_id": "uuid",
  "temuan": "...",
  "kondisi_aset": "RUSAK_RINGAN",
  "kebutuhan_padam": "TANPA_PADAM",
  "kebutuhan_material": "TIDAK_ADA",
  "rekomendasi": "...",
  "perlu_p0": true,
  "input_at": "..."
}
```

Unknown point:
```json
{
  "asset_id": null,
  "section_id": "uuid-or-null",
  "latitude": -5.1,
  "longitude": 105.3,
  "temuan": "Titik belum terdaftar",
  "kondisi_aset": "KRITIS",
  "kebutuhan_padam": "PERLU_PADAM",
  "kebutuhan_material": "Cross arm + baut",
  "rekomendasi": "Validasi aset dan tindak lanjut",
  "perlu_p0": true,
  "input_at": "..."
}
```

Admin validation memetakan atau membuat asset master + Section/Posko. Jika `perlu_p0=true`, Admin memvalidasi/menyetujui terlebih dahulu (`POST /api/v1/admin/inspections/{id}/approve-p0`). P0 baru dibuat setelah approval dan membawa snapshot kebutuhan padam/material dari inspeksi. P0 ditugaskan ke HARSUS.

---

# 13. ROW Contracts (Executor: ROW)

`POST /api/v1/row/work` untuk RABAS/PANGKAS/TEBANG.

Tidak membutuhkan field kondisi_sebelum/kondisi_sesudah. Catatan + dokumentasi digunakan.

**REMOVED:** `POST /api/v1/row/vegetation-proposals` — ROW no longer creates vegetation proposals. Vegetation proposals are created by INSPEKSI via `POST /api/v1/vegetation-proposals`.

---

# 14. Admin Correction Contract

Tidak menggunakan `correction_request` workflow pada baseline MVP.

Submitted/completed data hanya dapat dikoreksi ADMIN melalui endpoint Admin yang:
1. memvalidasi entity/field yang boleh dikoreksi;
2. mewajibkan `reason`;
3. menyimpan before/after di audit log;
4. menaikkan version entity bila relevan.

---

# 15. Documentation Metadata Contract

Photo types:
- BEFORE
- AFTER
- TEMUAN
- SURVEY

Metadata minimum:
- entity type/id
- photo type
- captured_at
- submitted_at
- coordinate optional/required sesuai flow
- storage key
- created_by

---

# 16. Photo Upload Contract

Baseline paling sederhana: foto dikirim bersama **endpoint domain canonical** sebagai multipart logical submission; Laravel Filesystem menyimpan ke disk aktif. Endpoint upload foto terpisah tidak menjadi jalur utama mobile MVP.

Development disk: local.  
Production disk: S3 dapat diaktifkan melalui config.

CloudFront/direct pre-signed upload bukan requirement MVP.

---

# 17. Mobile Submission / Draft Queue Contract

Generic `/api/v1/mobile/submissions` dan `/bulk` **tidak digunakan**. Setiap draft mengetahui `domain_endpoint` canonical-nya dan dikirim satu per satu oleh Flutter. Contoh:
- P0 completion → `POST /api/v1/p0/{id}/complete` (HARSUS)
- P0 reschedule → `POST /api/v1/p0/{id}/reschedule-request` (HARSUS)
- WO completion → `POST /api/v1/work-orders/{id}/complete` (POSKO)
- Inspection create → `POST /api/v1/inspections` (INSPEKSI)
- Vegetation proposal → `POST /api/v1/vegetation-proposals` (INSPEKSI)
- ROW work → `POST /api/v1/row/work` (ROW)
- PASILA action → endpoint action/session terkait (POSKO)

Untuk entity yang membutuhkan foto, request menggunakan multipart logical submission:
- part `payload` = JSON domain payload;
- part `photos[]` = file + `photo_type` + `captured_at` + koordinat opsional;
- header `Idempotency-Key`;
- `input_at` di payload = waktu aktivitas di device.

Server baru mengembalikan success setelah payload domain dan foto wajib berhasil dipersist. `submitted_at`/`recorded_at` diisi server. Bila gagal, draft tetap lokal dan retry memakai idempotency key yang sama.

**Kirim Semua** adalah loop/queue client: Flutter mengirim item satu per satu (boleh dibatasi concurrency rendah). Satu item gagal tidak membatalkan item lain; hanya item gagal menjadi `FAILED`.

---

# 18. Bootstrap & Initial Data Contract

`GET /api/v1/mobile/bootstrap`

Mengembalikan:
- user/role/posko context (5 roles);
- shift config (applicable to POSKO);
- work-feed terbaru (role-appropriate: POSKO gets WO/PASILA, HARSUS gets P0, INSPEKSI gets inspections, ROW gets vegetation work);
- cache ringan asset/section/posko/petugas/coordinate;
- version/checkpoint untuk reference data.

Histori lengkap tidak wajib dibawa ke SQLite.

---

# 19. Read Query Contracts

Mobile hanya menerima data sesuai role/scope:
- POSKO: own Posko WO/PASILA.
- HARSUS: P0 across whole ULP.
- INSPEKSI: inspections across whole ULP.
- ROW: ROW work.

Admin dapat query seluruh ULP. Filter master/transaction harus server-authorized.

---

# 20. Admin Web Mutation Contracts

CRUD seluruh master:
- Posko
- Penyulang
- Section
- Asset
- APKUK
- Vegetasi
- Petugas
- User (5 roles)

Plus:
- manual P0/WO
- reschedule decision (P0)
- Inspection location validation (from INSPEKSI)
- Inspection P0 approval (from INSPEKSI findings)
- PASILA unknown-point location validation
- PASILA escalation approval/rejection
- vegetation proposal validation (from INSPEKSI)
- Special Case review → edit → create P0
- direct correction with audit
- config update

---

# 21. Idempotency Contract

`client_request_id`/`Idempotency-Key` wajib unik per intended submission. Laravel menyimpan hasil request yang sudah diproses agar retry network tidak membuat record duplicate.

---

# 22. Concurrency Contract

Entity kritis memakai `version`.

Update:
```sql
UPDATE ...
SET ..., version = version + 1
WHERE id = :id AND version = :expected_version;
```

Jika row count 0 karena version berbeda → `409 CONFLICT`.

Tidak ada last-write-wins otomatis untuk P0/WO kritis.

---

# 23. Business Validation Matrix

- P0 executable → asset + Section + Posko tervalidasi dan snapshot kebutuhan padam/material wajib tersedia. Executor = HARSUS.
- P0 reschedule → target_date + reason wajib, Admin approval wajib.
- PASILA escalation → proposal requiring Admin approval, NOT direct P0 creation.
- Inspection P0 proposal → Admin validation/approval required before P0 creation.
- Special Case → Admin decision required, NOT automatic P0.
- WO MVP → jenis PEMASANGAN_APKUK saja.
- PASILA override → reason wajib.
- PASILA gagal eksekusi → reason wajib, padam/material tersedia.
- Inspection (INSPEKSI) unknown point → coordinate + photo wajib, status `PENDING_ASSET_VALIDATION`, belum membuat P0 executable sebelum Admin validation + approval.
- Vegetation proposal (INSPEKSI) → coordinate + photo wajib.
- ROW → execute only (RABAS/PANGKAS/TEBANG), no proposal creation.
- Submit pekerjaan → minimal photo rule harus terpenuhi; waktu `input_at` dan server `submitted_at` disimpan terpisah.
- P0 completion → Petugas selection mandatory at completion.
- Submitted/completed field record → user lapangan tidak dapat edit langsung.

---

# 24. Server Audit Requirements

Audit event minimal:
- login/security-sensitive event seperlunya;
- status transitions;
- P0 reschedule request + approval/rejection;
- PASILA escalation approval/rejection;
- Inspection P0 approval;
- Admin approve/reject;
- master CRUD;
- Admin correction;
- asset/vegetation validation;
- Special Case review + P0 creation decision;
- config changes.

---

# 25. Security & Boundary Guardrails

- server-side policy/authorization;
- HTTPS;
- rate limit auth/API sesuai kebutuhan;
- file MIME/size validation;
- no secret in Git;
- token secure storage on device;
- password hashing Laravel;
- private repo access controlled.

---

# 26. API Versioning Strategy

Baseline `/api/v1`. Breaking change memakai version baru bila sudah ada client production aktif.

---

# 27. Example End-to-End Flows

## P0 offline response (HARSUS)
Online fetch P0 (HARSUS) → offline edit draft/result + photo → select Petugas at completion → READY_TO_SEND → online Submit → Laravel OCC/validation → PostgreSQL/history → SUBMITTED.

## INSPEKSI unknown point
Offline create draft coordinate+photo (INSPEKSI) → submit online → inspection PENDING_ASSET_VALIDATION → Admin maps/creates asset → Admin approves P0 → P0 created for HARSUS.

## PASILA escalation
POSKO PASILA action cannot be executed → escalation proposal → Admin reviews → approves → P0 created for HARSUS.

## Special Case
System detects >3 trips → notification/recommendation → Admin reviews → edits P0 fields → creates P0 → HARSUS executes.

## Bulk retry
5 item submit → 4 success + 1 failed → hanya failed tetap lokal → user edit/retry → idempotency mencegah duplicate.

---

# 28. Open Questions

- max upload size/compression exact values;
- deployment/storage production provider;
- ML scoring integration contract final;
- retention/rate limit values;
- taxonomy `issue_type` + comparator masalah sama-vs-berbeda sebelum implementasi Special Case production.

Bukan lagi OQ: PowerSync/Supabase JWT/Next.js tidak digunakan; correction dilakukan Admin tanpa `correction_request`; PASILA target selection, shift hours, INSPEKSI replaces HARSUS for inspection, photo enum, login identifier (`username`), draft submission path, 5 roles, HARSUS = Pemeliharaan Khusus, HARSUS executes P0, vegetation proposals by INSPEKSI, Special Case = Admin recommendation, P0 not shift-based sudah dikunci.
