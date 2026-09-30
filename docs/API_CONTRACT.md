# API CONTRACT & MUTATION BOUNDARY SPECIFICATION

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Final / Locked Baseline — Target Contract Specification (Refreshed per 30 September 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Dokumen Acuan:** PRD.md · DESIGN_BRIEF.md · TECHNICAL_SPEC.md · DATA_MODEL.md · OFFLINE_SYNC_SPEC.md
**Lingkup Arsitektur:** Flutter Android App · Laravel Web/API · PostgreSQL · SQLite local draft/cache · Laravel Filesystem/S3 · Google Maps
**Lingkup Operasional:** ULP Sribhawono (Single-Tenant)

> [!IMPORTANT]
> **SPECIFICATION / PLANNED CONTRACT:**
> Seluruh endpoint REST API di bawah ini merupakan **TARGET CONTRACT SPECIFICATION** yang dirancang sebagai batas kontrak komunikasi antara Flutter Android dan Laravel Backend. Sebagian besar endpoint (P0, WO, PASILA, HARSUS, ROW, Special Case, Master API) **BELUM DIIMPLEMENTASIKAN** di codebase (status: `PLANNED` / `PENDING`).
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
8. Role business hanya ADMIN, POSKO, HARSUS, ROW.

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

## P0
- `GET /api/v1/p0`
- `GET /api/v1/p0/{id}`
- `POST /api/v1/p0/{id}/accept`
- `POST /api/v1/p0/{id}/start`
- `POST /api/v1/p0/{id}/complete`
- `POST /api/v1/p0/{id}/continue-next-shift`
- `POST /api/v1/p0/{id}/reschedule-request`
- `POST /api/v1/admin/p0`
- `POST /api/v1/admin/p0/{id}/reschedule-approve`
- `POST /api/v1/admin/p0/{id}/reschedule-reject`

## Work Order
- `GET /api/v1/work-orders`
- `GET /api/v1/work-orders/{id}`
- `POST /api/v1/work-orders/{id}/accept`
- `POST /api/v1/work-orders/{id}/start`
- `POST /api/v1/work-orders/{id}/complete`
- `POST /api/v1/work-orders/{id}/reschedule`
- `POST /api/v1/admin/work-orders`

## PASILA
- `GET /api/v1/pasila/recommendation`
- `POST /api/v1/pasila/sessions`
- `POST /api/v1/pasila/sessions/{id}/actions`
- `POST /api/v1/pasila/actions/{id}/escalate-p0`
- `POST /api/v1/admin/pasila/actions/{id}/validate-location`
- `POST /api/v1/pasila/sessions/{id}/complete`

## HARSUS
- `POST /api/v1/harsus/inspections`
- `GET /api/v1/harsus/inspections/{id}`
- `POST /api/v1/admin/harsus/{id}/validate-location`

## ROW
- `POST /api/v1/row/work`
- `POST /api/v1/row/vegetation-proposals`
- `POST /api/v1/admin/vegetation-proposals/{id}/approve`
- `POST /api/v1/admin/vegetation-proposals/{id}/reject`

## Special Case
- `GET /api/v1/admin/special-cases`
- `GET /api/v1/admin/special-cases/{id}`

## Documentation
- `GET /api/v1/documentation/{id}`

> Mobile **tidak** memakai generic `/mobile/submissions` atau endpoint bulk server. Draft/Submission Center memanggil endpoint domain canonical (P0/WO/PASILA/HARSUS/ROW) satu item per request. Tombol **Kirim Semua** hanya mengorkestrasi antrean tersebut dari Flutter.

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

Login baru membutuhkan internet. Local session dapat dipakai untuk membuka draft offline, tetapi server melakukan revalidation saat request berikutnya.

## Web Admin
Laravel session authentication. Route Admin wajib role ADMIN.

---

# 8. Authorization Matrix

| Capability | ADMIN | POSKO | HARSUS | ROW |
|---|---:|---:|---:|---:|
| Admin Web | ✅ | ❌ | ❌ | ❌ |
| Master CRUD | ✅ | ❌ | ❌ | ❌ |
| Manual P0/WO | ✅ | ❌ | ❌ | ❌ |
| Execute P0/WO | monitor/correct | ✅ own Posko | ❌ | ❌ |
| PASILA | monitor | ✅ | ❌ | incidental context only |
| HARSUS inspection | monitor/validate | ❌ | ✅ | ❌ |
| ROW work | monitor/validate | incidental via PASILA | ❌ | ✅ |
| Correct submitted data | ✅ + audit | ❌ | ❌ | ❌ |

---

# 9. P0 Contracts

## Create Manual — ADMIN
`POST /api/v1/admin/p0`

P0 executable hanya dibuat untuk asset + Section + Posko yang sudah tervalidasi. Unknown point tetap berada pada source record (HARSUS/PASILA/location finding) sampai Admin memetakan/membuat asset, lalu P0 dibuat dan muncul ke POSKO.

```json
{
  "asset_id": "validated-asset-uuid",
  "issue_type": "KONSTRUKSI",
  "uraian": "Perbaikan konstruksi",
  "kebutuhan_padam": "PERLU_PADAM",
  "kebutuhan_material": "Cross arm + baut",
  "scheduled_date": "2026-09-28",
  "scheduled_shift": "A"
}
```

Validation: `asset_id`, derived `section_id`, `posko_id`, `kebutuhan_padam`, dan `kebutuhan_material` wajib siap sebelum P0 executable dibuat. Untuk unknown point, gunakan workflow source validation terlebih dahulu.

## Continue Next Shift
`POST /api/v1/p0/{id}/continue-next-shift`

```json
{
  "reason": "Hujan deras",
  "expected_version": 3,
  "input_at": "2026-09-27T15:45:00+07:00"
}
```

Server menentukan target shift berikutnya otomatis. Approval = NOT_REQUIRED.

## Custom Reschedule Request
`POST /api/v1/p0/{id}/reschedule-request`

```json
{
  "target_date": "2026-09-29",
  "target_shift": "A",
  "reason": "Material belum tersedia",
  "expected_version": 4,
  "input_at": "2026-09-27T15:50:00+07:00"
}
```

Admin approve/reject melalui endpoint Admin. Selama request CUSTOM masih PENDING, P0 tetap `DIKERJAKAN`; jika APPROVED menjadi `RESCHEDULED`, jika REJECTED tetap `DIKERJAKAN`. Saat target shift dimulai dan user melanjutkan pekerjaan, status kembali `DIKERJAKAN`. P0 tetap ID sama.

## Complete
Foto minimal sesuai rule wajib tersedia sebelum completion accepted.

---

# 10. Work Order Contracts

MVP `jenis_wo` hanya `PEMASANGAN_APKUK`.

Response detail wajib menyertakan:
- `priority_score`
- `alasan_prioritas`

`POST /api/v1/work-orders/{id}/complete` menerima hasil completion + minimal satu foto dan `apkuk_code` opsional. Jika accepted, server dalam logical transaction yang sama mengubah WO menjadi `SELESAI` dan create/update APKUK `AKTIF` pada asset dengan `tgl_pasang` dari `input_at`.

WO yang belum sempat dikerjakan karena P0 dapat di-reschedule dengan alasan + target tanggal/shift.

`POST /api/v1/work-orders/{id}/reschedule`
```json
{
  "target_date": "2026-09-28",
  "target_shift": "B",
  "reason": "P0 prioritas mengambil waktu shift",
  "expected_version": 2,
  "input_at": "2026-09-27T15:50:00+07:00"
}
```
Server memperbarui `tanggal/shift` WO menjadi target baru dan menulis `wo_reschedule_history` berisi jadwal asal + target. Carry-over menghitung kuota terlebih dahulu: jika ada 3 WO carry-over ke Shift B, generator hanya membuat 3 WO baru sehingga total target tetap 6/shift/Posko.

---

# 11. PASILA Contracts

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

Jika action diekskalasi menjadi P0, `kebutuhan_padam` dan `kebutuhan_material` dicopy sebagai **snapshot P0**. Bila action belum memiliki asset tervalidasi, eskalasi menunggu Admin mapping asset terlebih dahulu melalui endpoint validasi lokasi PASILA; setelah mapping selesai, P0 executable dapat dibuat.

---

# 12. HARSUS Contracts

`POST /api/v1/harsus/inspections`

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

Admin validation memetakan atau membuat asset master + Section/Posko. Jika `perlu_p0=true`, P0 baru dibuat setelah validasi selesai dan membawa snapshot kebutuhan padam/material dari inspeksi.

---

# 13. ROW Contracts

`POST /api/v1/row/work` untuk RABAS/PANGKAS/TEBANG.

Tidak membutuhkan field kondisi_sebelum/kondisi_sesudah. Catatan + dokumentasi digunakan.

Vegetation proposal:
`POST /api/v1/row/vegetation-proposals`

Wajib koordinat dan minimal satu foto TEMUAN/SURVEY sesuai UX.

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
- P0 completion → `POST /api/v1/p0/{id}/complete`
- WO completion → `POST /api/v1/work-orders/{id}/complete`
- HARSUS create → `POST /api/v1/harsus/inspections`
- ROW work → `POST /api/v1/row/work`
- PASILA action → endpoint action/session terkait

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
- user/role/posko context;
- shift config;
- work-feed terbaru;
- cache ringan asset/section/posko/petugas/coordinate;
- version/checkpoint untuk reference data.

Histori lengkap tidak wajib dibawa ke SQLite.

---

# 19. Read Query Contracts

Mobile hanya menerima data sesuai role/Posko. Admin dapat query seluruh ULP. Filter master/transaction harus server-authorized.

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
- User

Plus:
- manual P0/WO
- reschedule decision
- HARSUS location validation
- PASILA unknown-point location validation
- vegetation proposal validation
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

- P0 executable → asset + Section + Posko tervalidasi dan snapshot kebutuhan padam/material wajib tersedia.
- NEXT_SHIFT → reason wajib, approval tidak perlu.
- CUSTOM_RESCHEDULE → target_date + target_shift + reason wajib, Admin approval wajib.
- WO MVP → jenis PEMASANGAN_APKUK saja.
- PASILA override → reason wajib.
- PASILA gagal eksekusi → reason wajib, padam/material tersedia.
- HARSUS unknown point → coordinate + photo wajib, status `PENDING_ASSET_VALIDATION`, belum membuat P0 executable sebelum Admin validation.
- ROW proposal → coordinate + photo wajib.
- Submit pekerjaan → minimal photo rule harus terpenuhi; waktu `input_at` dan server `submitted_at` disimpan terpisah.
- Submitted/completed field record → user lapangan tidak dapat edit langsung.

---

# 24. Server Audit Requirements

Audit event minimal:
- login/security-sensitive event seperlunya;
- status transitions;
- P0 next-shift/custom reschedule;
- Admin approve/reject;
- master CRUD;
- Admin correction;
- asset/vegetation validation;
- Special Case merge/new-P0 decision;
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

## P0 offline response
Online fetch P0 → offline edit draft/result + photo → READY_TO_SEND → online Submit → Laravel OCC/validation → PostgreSQL/history → SUBMITTED.

## HARSUS unknown point
Offline create draft coordinate+photo → submit online → inspection PENDING_ASSET_VALIDATION → Admin maps/creates asset → optional P0.

## Bulk retry
5 item submit → 4 success + 1 failed → hanya failed tetap lokal → user edit/retry → idempotency mencegah duplicate.

---

# 28. Open Questions

- max upload size/compression exact values;
- deployment/storage production provider;
- ML scoring integration contract final;
- retention/rate limit values;
- taxonomy `issue_type` + comparator masalah sama-vs-berbeda sebelum implementasi Special Case production.

Bukan lagi OQ: PowerSync/Supabase JWT/Next.js tidak digunakan; correction dilakukan Admin tanpa `correction_request`; PASILA target selection, shift hours, HARSUS unknown point, photo enum, login identifier (`username`), dan draft submission path sudah dikunci.
