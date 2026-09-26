# API CONTRACT & MUTATION BOUNDARY SPECIFICATION

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Draft / Specification Baseline  
**Dokumen Acuan:** [PRD.md](./PRD.md) · [DESIGN_BRIEF.md](./DESIGN_BRIEF.md) · [TECHNICAL_SPEC.md](./TECHNICAL_SPEC.md) · [DATA_MODEL.md](./DATA_MODEL.md) · [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md)  
**Lingkup Arsitektur:** Flutter Android App, Next.js Admin Web, Supabase Auth, PostgreSQL, Supabase Edge Functions, PowerSync, Amazon S3  
**Lingkup Operasional:** ULP Sribhawono (Single-Tenant)

---

# 1. API Contract Principles

Dokumen ini mendefinisikan batas kontrak komunikasi (*API contract & mutation boundary*) antar komponen sistem. Seluruh interaksi jaringan diatur berdasarkan prinsip arsitektural berikut:

1. **Authenticated Request Boundary (Batas Akses Terotentikasi):**
   - Seluruh pemanggilan API bisnis wajib menyertakan token JWT Supabase Auth yang valid pada header `Authorization: Bearer <token>`, kecuali endpoint publik autentikasi awal provider Supabase Auth (`/auth/v1/token`).
   - Token diverifikasi secara kriptografis di sisi server menggunakan mekanisme dan *signing keys* yang didukung Supabase Auth.
   - Verifikasi wajib memastikan: tanda tangan digital (*signature*) valid, masa berlaku token belum kedaluwarsa (*not expired*), serta klaim issuer dan audience sesuai konfigurasi. Klaim kustom peran aplikasi (`app_role`) dan posko (`posko_id`) hanya dipercaya setelah token berhasil divalidasi penuh oleh server.

2. **Server-Authoritative Validation & Controlled Mutation:**
   - **Aplikasi lapangan Android TIDAK PERNAH melakukan direct database write ke tabel transaksi operasional sebagai mekanisme mutasi bisnis utama.**
   - Seluruh mutasi kritis (pembuatan penugasan, perubahan status pekerjaan, pencatatan eksekusi, persetujuan koreksi) wajib melalui batas server terkontrol: **Supabase Edge Functions** atau **PostgreSQL RPC Functions**.

3. **Role & Wilayah Authorization (RBAC + Scope):**
   - Peran bisnis aplikasi (*business roles*) ditetapkan secara tegas hanya mencakup 4 peran: **`ADMIN`**, **`POSKO`**, **`HARSUS`**, dan **`ROW`**. Kredensial `service_role` merupakan kredensial *privileged* server-side internal dan BUKAN peran bisnis aplikasi.
   - Hak akses pengguna diekstrak dari klaim kustom JWT yang telah divalidasi server (`app_role` dan `posko_id`).
   - Server menolak setiap upaya mutasi data di luar wilayah tanggung jawab posko atau di luar wewenang peran yang sah.

4. **Zero Service Credentials on Client:**
   - Kredensial *Supabase Service Role Key* dan *AWS Secret Access Key* **TIDAK PERNAH dikirim atau disimpan pada perangkat Android maupun browser Admin Web**.
   - Kredensial *service_role* hanya berada pada *Environment Secrets* server-side (Edge Functions dan background cron jobs).

5. **Optimistic Concurrency Control (OCC):**
   - Setiap pembaruan data eksisting pada entitas yang memiliki kolom versi (`p0` dan `work_order`) wajib menyertakan `expected_version`. Server menolak pembaruan jika versi data di server telah berubah (*conflict-safe*), mencegah penimpaan sepihak (*blind overwrite*).

6. **Offline-First Compatibility & Idempotency Boundary:**
   - Identitas record transaksi baru menggunakan **UUIDv7** yang digenerate oleh client sejak offline, bertindak sebagai *authoritative primary key* sekaligus lapisan pertahanan utama pencegahan duplikasi data (*duplicate prevention*).
   - Sistem membedakan secara tegas antara identitas transmisi jaringan (`request_id`) dan identitas mutasi logika stabil (`client_operation_id`).
   - Pengiriman ulang payload mutasi yang sama (*network retry / replay*) dijamin aman tanpa menciptakan record ganda.

7. **Decoupled Data vs Physical Photo Upload:**
   - Metadata foto dicatat terpisah dari berkas fisik gambar.
   - Pengunggahan berkas fisik dilakukan langsung dari client ke Amazon S3 menggunakan *pre-signed PUT URL* berumur pendek yang diterbitkan oleh server.
   - Server tidak mempercayai path atau kunci S3 sembarang dari client; validasi keberadaan berkas diverifikasi pada lokasi kunci otoritatif server sebelum status foto disahkan.

8. **Auditability & Traceability:**
   - Setiap permintaan jaringan dilacak menggunakan korelasi `request_id`, sedangkan mutasi logika dilacak menggunakan `client_operation_id`.
   - Perubahan status pekerjaan dan mutasi data otomatis mencatat jejak audit pada `status_history` dan `audit_log`.

---

# 2. API Surface Overview

Tabel berikut merangkum seluruh antarmuka API dan batasan mutasi pada sistem.

> **Catatan API Versioning:** Prefiks `/v1/` pada contoh endpoint merupakan **PROPOSED BASELINE**. Strategi versioning resmi berstatus **OPEN QUESTION (OQ-API-08)**.

| Operasi / Endpoint | Caller | Business Role / Actor Context | Tujuan Bisnis | Online Wajib? | Idempotent? | Concurrency Control | Saluran Utama |
|---|---|---|---|---|---|---|---|
| **Supabase Auth: Sign In** (`POST /auth/v1/token?grant_type=password`) | Android, Web | Publik (Kredensial) | Autentikasi awal kredensial pengguna | Ya | Tidak | — | Provider Native (Supabase Auth) |
| **Supabase Auth: Refresh Session** (`POST /auth/v1/token?grant_type=refresh_token`) | Android, Web | Authenticated User | Memperbarui JWT access token | Ya | Tidak | — | Provider Native (Supabase Auth) |
| **Supabase Auth: Sign Out** (`POST /auth/v1/logout`) | Android, Web | Authenticated User | Mengakhiri sesi pengguna | Ya | Ya | — | Provider Native (Supabase Auth) |
| **`GET /sync/bootstrap`** | Android | POSKO, HARSUS, ROW | Inisialisasi partisi data referensi awal | Ya | Ya | — | PowerSync + Edge Fn |
| **`POST /sync/powersync-write`** | Android | POSKO, HARSUS, ROW | Batch mutasi transaksi offline | Saat Online | Ya | OCC (`version`) / State Machine | Edge Function |
| **`POST /v1/p0/create-manual`** | Web Admin | ADMIN | Menerbitkan tiket P0 manual oleh Admin | Ya | Ya (UUIDv7) | — | Edge Function / RPC |
| **`PATCH /v1/p0/{id}`** | Android | POSKO | Menerima/mengerjakan/selesai P0 | Diantrekan | Ya | OCC (`version`) | powersync-write / RPC |
| **`generate-wo` (Internal Job)** | Cron Scheduler | Actor: `SYSTEM_JOB` | Pembangkitan otomatis 6 WO preventif/shift | Ya (Internal) | Ya | Unique Constraint | Edge Function (Internal) |
| **`POST /v1/wo/create-manual`** | Web Admin | ADMIN | Menerbitkan WO manual ad-hoc | Ya | Ya (UUIDv7) | Unique Constraint | Edge Function / RPC |
| **`PATCH /v1/wo/{id}`** | Android | POSKO | Mengerjakan & menyelesaikan WO | Diantrekan | Ya | OCC (`version`) | powersync-write / RPC |
| **`POST /v1/pasila/session/open`** | Android | POSKO | Membuka sesi patroli PASILA | Diantrekan | Ya (UUIDv7) | — | powersync-write / RPC |
| **`PATCH /v1/pasila/session/{id}/close`** | Android | POSKO | Menutup sesi patroli PASILA | Diantrekan | Ya | State Machine (`BERJALAN` -> `SELESAI`) | powersync-write / RPC |
| **`POST /v1/pasila/action/record`** | Android | POSKO | Mencatat tindakan / temuan patroli | Diantrekan | Ya (UUIDv7) | — | powersync-write / RPC |
| **`POST /v1/harsus/inspection`** | Android | HARSUS | Mencatat hasil inspeksi kondisi aset | Diantrekan | Ya (UUIDv7) | — | powersync-write / RPC |
| **`POST /v1/row/work/record`** | Android | ROW, POSKO | Mencatat eksekusi pemangkasan vegetasi | Diantrekan | Ya (UUIDv7) | — | powersync-write / RPC |
| **`POST /v1/photo/upload-url`** | Android, Web | ADMIN, POSKO, HARSUS, ROW | Meminta pre-signed upload URL S3 | Ya | Ya | Status Validation | Edge Function |
| **`POST /v1/photo/complete`** | Android, Web | ADMIN, POSKO, HARSUS, ROW | Konfirmasi verifikasi upload objek S3 | Ya | Ya | State Machine | Edge Function |
| **`POST /v1/correction/request`** | Android | POSKO | Mengajukan koreksi data yang telah SELESAI | Diantrekan | Ya (UUIDv7) | — | powersync-write / RPC |
| **`POST /v1/correction/review`** | Web Admin | ADMIN | Menyetujui atau menolak tiket koreksi | Ya | Ya | State Machine | Edge Function / RPC |
| **Allowlisted Admin Master Handlers** (`POST /v1/admin/master/asset`, `/section`, `/gardu`, `/petugas`) | Web Admin | ADMIN | Kelola master aset/seksi/gardu/petugas | Ya | Ya | Database Key | Edge Function / RPC |
| **`POST /v1/admin/app-config`** | Web Admin | ADMIN | Memperbarui parameter konfigurasi aplikasi | Ya | Ya | Key Update | PostgREST / RPC |

*Catatan Penting:* Endpoint `/auth/v1/...` di atas merupakan endpoint native dari Supabase Auth engine, bukan custom business API backend aplikasi. Endpoint internal seperti job pembangkitan WO (`generate-wo`) dieksekusi secara terisolasi via server-side scheduler dengan *privileged service-role credential* dan bukan merupakan endpoint publik yang dapat dipanggil oleh user.

---

# 3. Transport & Naming Conventions

1. **Protokol & Format Payload:**
   - Seluruh komunikasi menggunakan **HTTPS (TLS 1.3 direkomendasikan)**.
   - Format payload request dan response wajib **JSON UTF-8 murni**.
   - Header standar wajib: `Content-Type: application/json`, `Accept: application/json`.

2. **Konvensi Penamaan (Naming Convention):**
   - Penamaan field dalam JSON wajib menggunakan **`snake_case`**, selaras dengan skema [DATA_MODEL.md](./DATA_MODEL.md).
   - Penggunaan singkatan dilarang kecuali istilah operasional resmi PLN: `p0`, `wo`, `pasila`, `harsus`, `row`, `apkuk`, `ulp`. Istilah APKUK ditulis sebagai `apkuk` tanpa mengarang kepanjangan akronim.
   - Istilah tim ditulis konsisten: Tim Inspeksi Harsus (`harsus`) dan Tim ROW / Vegetasi (`row`).

3. **Tipe Data Standar:**
   - **Identifier:** UUID string format standar 36 karakter kanonikal huruf kecil (contoh: `018d9f59-7f3a-7d2a-89b1-e23456789abc`).
   - **Timestamp:** Standar ISO 8601 dengan offset zona waktu eksplisit (contoh: `2026-09-26T10:15:00+07:00` atau format UTC `2026-09-26T03:15:00Z`).
   - **Tanggal:** Standar ISO 8601 Date format `YYYY-MM-DD` (contoh: `2026-09-26`).
   - **Nilai Kosong (Null Handling):** Field opsional yang tidak bernilai dikirim sebagai nilai `null` eksplisit atau diabaikan (*omitted*), bukan string kosong `""`.
   - **Konvensi Enum:** Nilai status dan tipe menggunakan huruf besar (*UPPERCASE*) dengan pemisah *underscore*, selaras dengan CHECK constraint di database (contoh: `DITERIMA`, `PEMASANGAN_APKUK`, `TEBANG`).

---

# 4. Standard Request Metadata

Setiap permintaan mutasi dari client menyertakan blok metadata konseptual untuk keperluan pelacakan, audit, dan pencegahan duplikasi.

```json
{
  "_metadata": {
    "request_id": "c1f7b0a8-3d2e-4b6a-9f1c-8e4a7d2b5c01",
    "client_operation_id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
    "client_created_at": "2026-09-26T10:15:00+07:00",
    "app_version": "1.0.0+12",
    "client_platform": "ANDROID_FIELD",
    "device_id": "PROPOSED_DEVICE_FINGERPRINT"
  }
}
```

### 4.1 Definisi Field Metadata

| Nama Field | Status | Deskripsi |
|---|---|---|
| `request_id` | **Required** | UUIDv4 acak unik untuk **satu upaya transmisi HTTP/jaringan (*correlation identifier*)**. Digunakan pada logging dan distributed tracing. Nilai ini **BOLEH BERBEDA** pada setiap percobaan retry jaringan baru. **JANGAN gunakan `request_id` sebagai idempotency key.** |
| `client_operation_id` | **Required** | UUIDv7 stabil yang mewakili **satu mutasi logika bisnis (*stable logical mutation identifier*)**. Nilai ini **HARUS TETAP SAMA** ketika operasi logika yang sama di-retry akibat jaringan putus atau timeout. Digunakan server untuk mendeteksi pemutaran ulang (*replay*). |
| `client_created_at` | **Required** | Timestamp ISO 8601 saat perangkat client **membentuk objek operasi/permintaan data**, BUKAN waktu kejadian fisik/bisnis di lapangan. |
| `app_version` | **Recommended** | Versi build aplikasi Android untuk verifikasi kompatibilitas kontrak API. |
| `client_platform` | **Required** | Asal platform pemanggil (`ANDROID_FIELD` atau `ADMIN_WEB`). |
| `device_id` | **Proposed / Optional** | Identifier unik perangkat keras untuk audit perangkat. Berstatus usulan teknis (**OPEN QUESTION OQ-API-10**), bukan penentu otentikasi tunggal. |

> **Pemisahan Waktu Metadata vs Waktu Bisnis:**  
> Field `_metadata.client_created_at` hanya mencatat saat data dikemas di aplikasi. Waktu kejadian bisnis atau fisik lapangan sesungguhnya tetap berada pada atribut entitas masing-masing, seperti `tgl_dikerjakan` (mulai kerja fisik), `tgl_selesai` (selesai kerja fisik), `taken_at` (saat rana kamera mengambil foto), dan `tgl_eksekusi` (tanggal tebang/pangkas pohon).

---

# 5. Standard Response Envelope

Seluruh endpoint REST kustom dan Supabase Edge Functions mengembalikan format envelope terpadu:

### 5.1 Format Berhasil (Success Envelope)
```json
{
  "success": true,
  "data": {
    "id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
    "display_code": "P0-20260926-0001",
    "status": "DITERIMA",
    "version": 2
  },
  "error": null,
  "request_id": "c1f7b0a8-3d2e-4b6a-9f1c-8e4a7d2b5c01",
  "server_time": "2026-09-26T10:15:02+07:00"
}
```

### 5.2 Format Diterima / Replay Idempotent (Idempotent Replay Envelope)
Jika client mengirim ulang permintaan mutasi dengan `client_operation_id` atau UUIDv7 record ID yang telah sukses diproses sebelumnya:
```json
{
  "success": true,
  "data": {
    "id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
    "display_code": "P0-20260926-0001",
    "status": "DITERIMA",
    "version": 2,
    "is_idempotent_replay": true
  },
  "error": null,
  "request_id": "d2e8c1b9-4e3f-5a7b-0a2d-9f5b8e3c6d12",
  "server_time": "2026-09-26T10:15:05+07:00"
}
```

### 5.3 Format Kegagalan / Kesalahan (Error Envelope)
```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "VERSION_CONFLICT",
    "message": "Data telah diperbarui oleh pengguna lain. Silakan muat versi terbaru.",
    "details": {
      "expected_version": 1,
      "current_server_version": 2,
      "conflicting_fields": ["status"]
    }
  },
  "request_id": "c1f7b0a8-3d2e-4b6a-9f1c-8e4a7d2b5c01",
  "server_time": "2026-09-26T10:15:02+07:00"
}
```

---

# 6. Standard Error Model

Berikut adalah daftar kode kesalahan standar (*Standard Error Code Registry*) yang diakui dan digunakan secara konsisten oleh seluruh layanan backend:

| HTTP Status | Error Code | Makna / Kondisi Pemicu | Aksi Rekomendasi Client | Auto Retry? | Panduan Pesan untuk Petugas |
|---|---|---|---|---|---|
| **400** | `VALIDATION_ERROR` | Format data tidak valid / field wajib kosong / nilai di luar domain | Tampilkan error pada form field terkait | Tidak | "Mohon lengkapi seluruh isian wajib dengan benar." |
| **401** | `AUTH_REQUIRED` | Token JWT tidak ada, kedaluwarsa, tidak valid, atau akun dinonaktifkan (`details.reason = "ACCOUNT_DISABLED"`) | Lakukan silent refresh; jika akun nonaktif, kunci layar | Ya (1x refresh jika expired) | "Sesi berakhir atau akun dinonaktifkan. Hubungi Admin." |
| **403** | `FORBIDDEN` | Peran pengguna tidak memiliki hak akses / beda posko penugasan | Batalkan aksi; catat log keamanan | Tidak | "Anda tidak memiliki wewenang untuk tugas ini." |
| **404** | `NOT_FOUND` | Record target (aset, seksi, dokumentasi) tidak ditemukan (`details.resource = "asset"` / `"section"` / `"documentation"`) | Periksa sinkronisasi master data | Tidak | "Data referensi tidak ditemukan di sistem." |
| **409** | `VERSION_CONFLICT` | Versi data OCC lokal tidak cocok dengan versi server pada entitas ber-version | Tandai record CONFLICT; muat data server | Tidak | "Data telah diubah dari sumber lain. Memuat versi baru." |
| **409** | `INVALID_STATE_TRANSITION` | Perubahan status melanggar aturan alur kerja state machine | Tolak mutasi; sinkronkan ulang state | Tidak | "Status pekerjaan tidak dapat dialihkan ke tahap ini." |
| **409** | `IDEMPOTENCY_PAYLOAD_MISMATCH` | Record ID atau operation ID sama, namun isi payload mutasi berbeda dengan data yang telah tersimpan | Berhenti dan eskalasi; cegah modifikasi konflik | Tidak | "Permintaan duplikat terdeteksi dengan data berbeda." |
| **422** | `BUSINESS_RULE_VIOLATION` | Melanggar aturan bisnis (e.g. seksi aset tidak cocok dengan seksi penugasan) | Koreksi input data operasional | Tidak | "Data tidak sesuai dengan aturan pemeliharaan PLN." |
| **422** | `DEPENDENCY_MISSING` | Record induk (sesi, transaksi induk) belum ada di database server | Kirim ulang transaksi induk terlebih dahulu | Ya (Batch order) | "Menunggu sinkronisasi data induk." |
| **422** | `DUPLICATE_ACTIVE_WO` | Target aset sudah memiliki WO aktif yang belum selesai | Batalkan pembentukan WO ganda | Tidak | "Aset ini masih memiliki perintah kerja aktif." |
| **429** | `RATE_LIMITED` *(Proposed)* | Melampaui batas kuota request per menit (**OQ-API-06**) | Tunda pengiriman sesuai header `Retry-After` | Ya (Exponential Backoff) | "Lalu lintas data padat. Mencoba kembali sebentar lagi." |
| **500** | `SERVER_ERROR` | Kesalahan internal backend / database exception | Catat Sentry; amankan data di antrian lokal | Ya (Backoff) | "Terjadi kendala server. Data Anda tetap aman di HP." |
| **502** | `PHOTO_UPLOAD_FAILED` | Gagal mengunggah berkas fisik gambar ke Amazon S3 | Jadwalkan upload ulang pada antrian foto | Ya (Backoff) | "Foto belum berhasil terunggah. Akan dicoba kembali." |
| **503** | `TEMPORARY_UNAVAILABLE` | Layanan Supabase / jaringan server sedang pemeliharaan | Tetap dalam mode antrian offline | Ya (Backoff) | "Layanan server sedang sibuk. Mencoba ulang otomatis." |

---

# 7. Authentication Contract

1. **Alur Autentikasi Pengguna (Online Mandatory):**
   - Dilakukan melalui endpoint native Supabase Auth:
     - Sign In: `POST /auth/v1/token?grant_type=password`
     - Refresh: `POST /auth/v1/token?grant_type=refresh_token`
     - Sign Out: `POST /auth/v1/logout`
   - Endpoint di atas merupakan antarmuka bawaan provider Supabase Auth, bukan custom API buatan aplikasi.
   - Format identifier login (apakah email riil, email bayangan internal seperti `posko01@sribhawono.internal`, atau abstraksi username) berstatus **OPEN QUESTION (OQ-API-01)**.
   - Kata sandi mentah hanya dikirim pada request login awal melalui TLS terenkripsi dan **tidak pernah disimpan di database aplikasi atau log telemetri**.

2. **Penerbitan Custom Claims (Server Hook):**
   - Database trigger / auth hook pada Supabase Auth menyuntikkan klaim terverifikasi ke dalam payload JWT:
     - `app_role`: `'ADMIN'` | `'POSKO'` | `'HARSUS'` | `'ROW'`
     - `posko_id`: UUID posko homebase (wajib terisi jika `app_role = 'POSKO'`).
   - Seluruh otorisasi backend membaca klaim ini dari token kriptografis, bukan dari parameter bebas client.

3. **Verifikasi Token Server-Side:**
   - JWT diverifikasi di sisi server menggunakan mekanisme dan *signing keys* yang didukung Supabase Auth.
   - Pengecekan wajib meliputi integritas tanda tangan digital, batas waktu kedaluwarsa token (*expiry*), serta kecocokan peran aplikasi.

4. **Penanganan Akun yang Dinonaktifkan (`is_active = false`):**
   - Endpoint refresh-token native tidak boleh diasumsikan otomatis mengetahui kolom `user_profile.is_active` kecuali diintegrasikan via custom auth hook / banning mechanism.
   - **Kontrak Wajib Backend:** Setiap pemanggilan business API, Edge Function, dan sinkronisasi data wajib memverifikasi bahwa profil aplikasi pengguna berstatus aktif (`user_profile.is_active = true`).
   - Jika akun dinonaktifkan:
     - Server menolak request dengan status `HTTP 401 Unauthorized` dan error payload:
       ```json
       {
         "success": false,
         "error": {
           "code": "AUTH_REQUIRED",
           "message": "Akun Anda telah dinonaktifkan oleh Administrator.",
           "details": { "reason": "ACCOUNT_DISABLED" }
         }
       }
       ```
     - Aplikasi Android langsung mengunci tampilan operasional, menghapus sesi aktif dari *Flutter Secure Storage*, dan mengarahkan pengguna untuk melapor ke Admin ULP.

---

# 8. Authorization Matrix

Peran bisnis resmi aplikasi hanya terdiri dari 4 peran: **`ADMIN`**, **`POSKO`**, **`HARSUS`**, dan **`ROW`**. Operasi otomatis tanpa pengguna manusia dijalankan dengan konteks aktor `SYSTEM_JOB` atau `EDGE_FUNCTION`.

| Operasi Sistem | ADMIN | POSKO | HARSUS | ROW | Aturan Otorisasi Server |
|---|---|---|---|---|---|
| **Melihat Daftar P0** | Semua Posko | Milik Posko Sendiri | Terkait Inspeksinya | — | Filter `posko_id` dari JWT claim |
| **Membuat P0 Manual** | Ya | — | — | — | Wajib `app_role = 'ADMIN'` |
| **Menerima & Mengerjakan P0** | — | Ya (Posko Sendiri) | — | — | Wajib `app_role = 'POSKO'` & tiket milik `posko_id` |
| **Menyelesaikan P0** | — | Ya (Posko Sendiri) | — | — | Wajib `app_role = 'POSKO'` & tiket milik `posko_id`. P0/WO/PASILA/ROW dapat divalidasi terhadap dokumentasi wajib sesuai mandatory photo matrix yang telah dikonfigurasi/dikonfirmasi (**OQ-API-03**) |
| **Membatalkan P0 / WO** | Ya | — | — | — | Hanya Admin yang berhak membatalkan |
| **Melihat Daftar WO** | Semua Posko | Milik Posko Sendiri | — | — | Filter `posko_id` dari JWT claim |
| **Mengeksekusi WO** | — | Ya (Posko Sendiri) | — | — | Wajib `app_role = 'POSKO'` & WO milik `posko_id` |
| **Membuka/Menutup Sesi PASILA**| — | Ya (Posko Sendiri) | — | — | Wajib `posko_id` cocok dengan wilayah kerja seksi |
| **Mencatat Aksi PASILA** | — | Ya (Sesi Aktif) | — | — | Wajib terikat pada sesi yang belum ditutup |
| **Input Inspeksi Harsus** | — | — | Ya | — | Wajib `app_role = 'HARSUS'` |
| **Input Pekerjaan ROW** | — | Ya (Insidental) | — | Ya (Terjadwal) | Posko hanya via aksi PASILA insidental |
| **Meminta Pre-signed Upload URL**| Ya | Ya | Ya | Ya | Terikat pada kepemilikan record dokumentasi |
| **Mengajukan Koreksi Data** | — | Ya (Posko Sendiri) | — | — | Hanya untuk pekerjaan yang telah `SELESAI` |
| **Menyetujui/Menolak Koreksi** | Ya | — | — | — | Wajib `app_role = 'ADMIN'` |
| **Kelola Master Data & Config**| Ya | — | — | — | Wajib `app_role = 'ADMIN'` via allowlisted endpoints |

---

# 9. P0 Contracts (Penugasan Khusus)

Penugasan Khusus (P0) adalah penugasan penanganan anomali jaringan dengan prioritas tertinggi. **P0 wajib memiliki titik aset spesifik (`asset_id NOT NULL`)** dan terikat pada seksi wilayah kerja (`section_id NOT NULL`) untuk routing penugasan ke Posko.

### 9.1 Create P0 Contract
- **Purpose:** Menerbitkan tiket penugasan khusus prioritas tertinggi.
- **Caller:** Next.js Admin Web (Manual), atau Backend Handler (otomatisasi dari Harsus, PASILA, atau Special Case).
- **Endpoint Konseptual:** `POST /v1/p0/create-manual`
- **Authorization:** `ADMIN` (jika manual via web).

**Request Payload (Manual Admin):**
```json
{
  "_metadata": {
    "request_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
    "client_operation_id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
    "client_created_at": "2026-09-26T08:30:00+07:00",
    "client_platform": "ADMIN_WEB"
  },
  "id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
  "source_type": "ADMIN",
  "source_ref_id": null,
  "asset_id": "018d9f51-a1b2-c3d4-e5f6-a7b8c9d0e1f2",
  "section_id": "018d9f50-b2c3-d4e5-f6a7-b8c9d0e1f2a3",
  "uraian": "Perbaikan jumper kendor fasa S berpotensi anjlok PMT",
  "parent_p0_id": null
}
```

**Server-Side Logic & Derivations:**
1. Validasi keberadaan aset (`asset_id`) dan seksi (`section_id`). Jika tidak ada, tolak dengan `404 NOT_FOUND` (`details.resource = "asset"` atau `"section"`).
2. Validasi konsistensi hierarki: pastikan `asset.section_id == section_id`. Jika tidak cocok -> tolak dengan `422 BUSINESS_RULE_VIOLATION`.
3. Turunkan posko penanggung jawab dari seksi: `posko_id = section.posko_id`.
4. Jika `parent_p0_id` disertakan, verifikasi tiket induk ada.
5. Generate `display_code` resmi (format: `P0-YYYYMMDD-XXXX`).
6. **Penetapan Aktor Pembuat:**
   - **Jika dibuat manual Admin:**
     - `created_actor_type = 'USER'`
     - `created_by = auth.uid()`
   - **Jika dibuat otomatis oleh sistem (Harsus, PASILA, Special Case):**
     - `created_actor_type = 'SYSTEM_JOB'` atau `'EDGE_FUNCTION'` sesuai konteks eksekusi
     - `created_by = null`
7. Inisialisasi: `status = 'BARU'`, `version = 1`.

**Response Payload (HTTP 201 Created):**
```json
{
  "success": true,
  "data": {
    "id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
    "display_code": "P0-20260926-0001",
    "posko_id": "018d9f4e-c3d4-e5f6-a7b8-c9d0e1f2a3b4",
    "status": "BARU",
    "version": 1,
    "created_actor_type": "USER",
    "created_by": "018d9f57-b2c3-d4e5-f6a7-b8c9d0e1f2a3",
    "created_at": "2026-09-26T08:30:05+07:00"
  },
  "error": null,
  "request_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "server_time": "2026-09-26T08:30:05+07:00"
}
```

---

### 9.2 Update P0 Execution Contract
- **Purpose:** Memperbarui siklus pengerjaan P0 oleh tim Posko (`BARU` -> `DITERIMA` -> `DIKERJAKAN` -> `SELESAI` / `TIDAK_SELESAI`).
- **Caller:** Android Field App (Role: `POSKO`).
- **Endpoint Konseptual:** `PATCH /v1/p0/{id}` (atau via batch `powersync-write`).
- **Authorization:** `POSKO` dengan `posko_id` yang cocok dengan penugasan tiket.

**Request Payload:**
```json
{
  "_metadata": {
    "request_id": "a8f3b2c1-d4e5-4f6a-8b9c-0d1e2f3a4b5c",
    "client_operation_id": "018d9f59-aa11-7d2a-89b1-e23456789abc",
    "client_created_at": "2026-09-26T11:45:00+07:00",
    "client_platform": "ANDROID_FIELD"
  },
  "expected_version": 2,
  "status": "SELESAI",
  "tgl_dikerjakan": "2026-09-26T10:15:00+07:00",
  "tgl_selesai": "2026-09-26T11:45:00+07:00",
  "alasan_tidak_selesai": null,
  "catatan": "Jumper fasa S telah dipress ulang dan dipasang proteksi isolasi",
  "petugas_ids": [
    "018d9f56-e5f6-a7b8-c9d0-e1f2a3b4c5d6",
    "018d9f56-f6a7-b8c9-d0e1-f2a3b4c5d6e7"
  ]
}
```

**Server-Side Logic & Derivations:**
1. Validasi konkurensi: pastikan `record.version == expected_version`. Jika tidak cocok -> tolak dengan `409 (VERSION_CONFLICT)`.
2. Validasi transisi status: pastikan transisi sesuai state machine (`DITERIMA` -> `DIKERJAKAN` -> `SELESAI` / `TIDAK_SELESAI`).
3. Jika status adalah `TIDAK_SELESAI`, pastikan `alasan_tidak_selesai` terisi teks tidak kosong.
4. Update junction personil pelaksana pada tabel `p0_petugas`.
5. Catat riwayat pada `status_history`.
6. Naikkan versi record: `version = version + 1`.

---

# 10. Work Order Contracts (Preventif Terencana)

### 10.1 Pembangkitan WO Preventif (Scheduled Engine)
- **Purpose:** Membangkitkan target 6 WO preventif pemasangan APKUK per shift per posko.
- **Caller:** Internal Background Job / Supabase Edge Function (`generate-wo`).
- **Actor Context:** `SYSTEM_JOB` (bukan endpoint publik; dipanggil secara internal menggunakan privileged server credentials).
- **Jenis WO Terkonfirmasi:** **`PEMASANGAN_APKUK`** murni.

**Aturan Validasi Bisnis Server:**
1. **Validasi Status Aset:** Aset target terdaftar dan berstatus aktif/eligible sesuai master data aset yang dikonfirmasi pada [DATA_MODEL.md](./DATA_MODEL.md). (Sistem tidak mengunci ke literal status yang belum diverifikasi).
2. **Ketiadaan APKUK Aktif:** Aset target belum memiliki record `apkuk` dengan status `AKTIF`.
3. **Pencegahan Duplikasi WO Aktif:** Sistem menolak membuat WO baru jika aset telah memiliki record `work_order` aktif (`BARU`, `DITERIMA`, `DIKERJAKAN`).
4. Formula pembobotan prioritas bersifat dinamis mengacu pada konfigurasi `app_config` (Formula/scoring WO tetap configurable / OPEN QUESTION sesuai [DATA_MODEL.md](./DATA_MODEL.md)).
5. Record audit diset dengan `created_actor_type = 'SYSTEM_JOB'`, `created_by = null`.

---

### 10.2 Update WO Execution Contract
- **Purpose:** Posko memperbarui pelaksanaan dan menyelesaikan WO pemasangan APKUK.
- **Request Parameters:** Mengikuti pola identik dengan P0 Update (`expected_version`, `status`, `tgl_dikerjakan`, `tgl_selesai`, `alasan_tidak_selesai`, `catatan`, `petugas_ids`).
- **Dampak Penyelesaian WO Sukses:** Ketika WO berstatus `SELESAI`, sistem secara otomatis membuat/memperbarui record pada tabel `apkuk` dengan status `AKTIF` untuk aset bersangkutan.

---

# 11. PASILA Contracts (Patroli Eksekusi Langsung)

### 11.1 Open & Close PASILA Session Contract
- **Open Session (`POST /v1/pasila/session/open`):**
  - Client mengirimkan: `id` (UUIDv7), `section_id` (Wajib NOT NULL), `tanggal`, `shift`, `waktu_mulai`.
  - Server memvalidasi bahwa `section_id` berada di bawah wilayah Posko pemanggil (`section.posko_id == auth.posko_id`).
  - Server menurunkan `posko_id` dan snapshot `penyulang_id` dari relasi `section`.
  - Server menerbitkan nomor display sesi resmi (contoh: `PAS-20260926-1-01`).
- **Close Session (`PATCH /v1/pasila/session/{id}/close`):**
  - Client mengirimkan: `waktu_selesai`, `catatan`, `status = 'SELESAI'`.
  - Validasi penutupan sesi dilakukan secara atomik di sisi server dengan transisi state machine: `BERJALAN` -> `SELESAI`. Sesi tidak menggunakan kolom `version` di database, dan setelah ditutup sesi terkunci dari penambahan aksi baru.

---

### 11.2 Record PASILA Action Contract
- **Purpose:** Mencatat setiap tindakan fisik atau temuan selama sesi patroli berlangsung.
- **Caller:** Android Field App (`POSKO`).

**Request Payload:**
```json
{
  "_metadata": {
    "request_id": "b7c2d1e0-4a5f-6e7d-8c9b-0a1b2c3d4e5f",
    "client_operation_id": "018d9f5d-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
    "client_created_at": "2026-09-26T09:15:00+07:00",
    "client_platform": "ANDROID_FIELD"
  },
  "id": "018d9f5d-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
  "session_id": "018d9f5c-6b7a-8c9d-0e1f-2a3b4c5d6e7f",
  "asset_id": "018d9f51-a1b2-c3d4-e5f6-a7b8c9d0e1f2",
  "action_type": "PERBAIKAN_LANGSUNG",
  "uraian": "Penggantian klem longgar pada travers tiang",
  "status": "SELESAI_DI_TEMPAT",
  "is_row_incidental": false,
  "escalated_to_p0": false
}
```

**Ketentuan Konseptual Cabang Aksi:**
1. **Tindakan Dapat Dieksekusi Langsung:**
   - Client mengirimkan `status = 'SELESAI_DI_TEMPAT'`, `escalated_to_p0 = false`. Selesai langsung di lokasi patroli.
2. **Pekerjaan Vegetasi Insidental oleh Posko:**
   - Client mengirimkan `is_row_incidental = true`.
   - Server-side handler secara otomatis dan idempotent membuat record turunan pada tabel `row_work` (`sumber = 'PASILA_INSIDENTAL'`, `is_incidental = true`, `source_action_id = action.id`) agar masuk dalam rekam jejak histori penanganan vegetasi tanpa duplikasi saat di-retry.
3. **Temuan yang Tidak Dapat Dieksekusi Langsung (Follow-up P0):**
   - Client mengirimkan `status = 'ESKALASI_P0'`, `escalated_to_p0 = true` sesuai skema [DATA_MODEL.md](./DATA_MODEL.md).
   - **Server-side handler secara otomatis:**
     - Memvalidasi data aksi dan sesi patroli.
     - Menyimpan record `pasila_action`.
     - Menerbitkan tiket P0 turunan secara **idempotent** dengan:
       - `source_type = 'PASILA'`
       - `source_ref_id = pasila_action.id`
       - `asset_id = pasila_action.asset_id`
       - `section_id = pasila_session.section_id`
       - `posko_id = pasila_session.posko_id`
       - `created_actor_type = 'EDGE_FUNCTION'` (atau `'SYSTEM_JOB'`)
       - `created_by = null`
     - Mengembalikan `created_p0_id` dan linkage penugasan pada respon server.
   - **ATURAN MUTLAK:** Android POSKO **TIDAK MENGIRIM payload `p0 CREATE` terpisah** untuk kasus ini. Seluruh penciptaan P0 eskalasi PASILA dikelola secara terpusat oleh server handler.

*(Vocabulary status tindakan `pasila_action` telah final sesuai [DATA_MODEL.md](./DATA_MODEL.md): `SELESAI_DI_TEMPAT`, `ESKALASI_P0`, dan `BELUM_SELESAI`. Field `p0_id` pada `pasila_action` tetap server-generated / server-linked ketika `escalated_to_p0 = true`).*

---

# 12. Harsus Contracts (Tim Inspeksi Harsus)

- **Purpose:** Mencatat hasil inspeksi kondisi visual teknis aset oleh Tim Inspeksi Harsus.
- **Caller:** Android Field App (`HARSUS`).
- **Endpoint Konseptual:** `POST /v1/harsus/inspection`

**Request Payload:**
```json
{
  "_metadata": {
    "request_id": "d1e2f3a4-b5c6-7a8b-9c0d-1e2f3a4b5c6d",
    "client_operation_id": "018d9f5e-1a2b-3c4d-5e6f-7a8b9c0d1e2f",
    "client_created_at": "2026-09-26T13:30:00+07:00",
    "client_platform": "ANDROID_FIELD"
  },
  "id": "018d9f5e-1a2b-3c4d-5e6f-7a8b9c0d1e2f",
  "asset_id": "018d9f51-a1b2-c3d4-e5f6-a7b8c9d0e1f2",
  "section_id": "018d9f50-b2c3-d4e5-f6a7-b8c9d0e1f2a3",
  "tanggal": "2026-09-26",
  "kondisi_aset": "RUSAK_BERAT",
  "temuan": "Isolator tumpu flashover dan retak parah",
  "kebutuhan_padam": "PERLU_PADAM",
  "rekomendasi": "Segera ganti isolator tumpu sebelum tembus fasa ke tanah",
  "perlu_p0": true
}
```

**Server-Side Logic:**
- Validasi relasi `asset_id` dan `section_id` via master database.
- Nilai `kebutuhan_padam` wajib divalidasi ketat: `'TANPA_PADAM'` atau `'PERLU_PADAM'`.
- **Penerbitan P0 Terkontrol:**
  - Jika `perlu_p0 == true`, server-side handler secara otomatis membuat tiket `p0` turunan dengan:
    - `source_type = 'HARSUS'`
    - `source_ref_id = inspection.id`
    - `asset_id = inspection.asset_id`
    - `section_id = inspection.section_id`
    - `posko_id = section.posko_id`
    - `status = 'BARU'`
    - `created_actor_type = 'EDGE_FUNCTION'`
    - `created_by = null`
  - **Client Harsus TIDAK MENGIRIM raw `p0 CREATE` bebas**. P0 dibangkitkan secara terpusat oleh server untuk menjamin integritas relasi dan nomor kode unik.

---

# 13. ROW Contracts (Vegetasi)

- **Purpose:** Mencatat realisasi pekerjaan pemotongan, perabasan, atau penebangan pohon di dekat jaringan 20 kV.
- **Caller:** Android Field App (`ROW` untuk terencana, atau `POSKO` untuk insidental).

**Request Payload:**
```json
{
  "_metadata": {
    "request_id": "e2f3a4b5-c6d7-8a9b-0c1d-2e3f4a5b6c7d",
    "client_operation_id": "018d9f5f-2b3c-4d5e-6f7a-8b9c0d1e2f3a",
    "client_created_at": "2026-09-26T14:00:00+07:00",
    "client_platform": "ANDROID_FIELD"
  },
  "id": "018d9f5f-2b3c-4d5e-6f7a-8b9c0d1e2f3a",
  "section_id": "018d9f50-b2c3-d4e5-f6a7-b8c9d0e1f2a3",
  "vegetasi_id": "018d9f55-c4d5-e6f7-a8b9-c0d1e2f3a4b5",
  "jenis_pekerjaan": "PANGKAS",
  "volume_pohon": 4,
  "is_incidental": false,
  "source_action_id": null,
  "status": "SELESAI",
  "tgl_eksekusi": "2026-09-26",
  "catatan": "Dahan pohon sawit mendekati konduktor telah dipangkas aman",
  "petugas_ids": ["018d9f56-e5f6-a7b8-c9d0-e1f2a3b4c5d6"]
}
```

**Ketentuan Bisnis:**
- `jenis_pekerjaan` wajib salah satu dari: `'RABAS'`, `'PANGKAS'`, atau `'TEBANG'`.
- `volume_pohon` berstatus **nullable / opsional** (jika terisi wajib integer > 0).
- Jika pekerjaan berasal dari temuan insidental PASILA, field `source_action_id` diisi ID `pasila_action` terkait.
- Hak tim ROW untuk membuat master titik pohon baru di lapangan berstatus **OPEN QUESTION (OQ-API-04 / OQ-SYNC-09)**.

---

# 14. Correction Request Contracts

Koreksi data pasca-selesai digunakan jika petugas Posko perlu merevisi data pekerjaan yang telah berstatus `SELESAI`.

### 14.1 Submit Correction Request (Posko)
- **Caller:** Android Field App (`POSKO`).
- **Endpoint Konseptual:** `POST /v1/correction/request`
- **Payload:**
```json
{
  "_metadata": {
    "request_id": "7a8b9c0d-1e2f-3a4b-5c6d-7e8f9a0b1c2d",
    "client_operation_id": "018d9f65-5c6d-7e8f-9a0b-1c2d3e4f5a6b",
    "client_created_at": "2026-09-26T15:00:00+07:00",
    "client_platform": "ANDROID_FIELD"
  },
  "id": "018d9f65-5c6d-7e8f-9a0b-1c2d3e4f5a6b",
  "entity_type": "p0",
  "entity_id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
  "alasan_koreksi": "Salah memilih personil pelaksana pada dropdown",
  "perubahan_data": {
    "petugas_ids": [
      "018d9f56-e5f6-a7b8-c9d0-e1f2a3b4c5d6",
      "018d9f56-aaaa-bbbb-cccc-0123456789de"
    ]
  }
}
```

### 14.2 Keamanan Mutasi Koreksi (*Strict Allowlist & Protection*)
- **`perubahan_data` JSONB BUKAN arbitrary column patch.**
- Aturan validasi persetujuan koreksi (*Approval Guardrails*):
  1. `entity_type` wajib allowlisted: `'p0'`, `'work_order'`, `'pasila_action'`, atau `'row_work'`.
  2. Field yang dapat dikoreksi di-allowlist secara ketat per entitas (contoh pada `p0`: `catatan`, `alasan_tidak_selesai`, `petugas_ids`, `tgl_dikerjakan`, `tgl_selesai`).
  3. **Field identitas, penugasan wilayah, dan audit TIDAK BOLEH dikoreksi via JSONB bebas**: `id`, `asset_id`, `section_id`, `posko_id`, `source_type`, `created_by`, `created_at`, `version`.
  4. Ketika Admin menyetujui (`status = 'DISETUJUI'`), PostgreSQL RPC function menerapkan perubahan secara atomik, menaikkan `version` pada entitas ber-version, dan mencatat riwayat lengkap pada `audit_log` (`action = 'UPDATE'`, `table_name`, `record_id`, `old_data`, `new_data`, `actor_type = 'USER'`, `actor_id = admin.uid`, `created_at`).

---

# 15. Documentation Metadata Contract

Metadata dokumentasi dibuat terlebih dahulu di perangkat sebelum berkas fisik foto dikirimkan ke Amazon S3. Sistem membedakan secara tegas representasi lokal pada perangkat dengan payload sinkronisasi ke server:

### 15.1 Representasi Entitas di Local SQLite Android
Pada database lokal perangkat, record dokumentasi menyimpan state lengkap termasuk path internal file:
- `id`: UUIDv7 client-generated (Primary Key)
- `entity_type`: jenis entitas yang didukung sesuai [DATA_MODEL.md](./DATA_MODEL.md) (`'p0'`, `'work_order'`, `'pasila_action'`, `'harsus_inspection'`, `'row_work'`, `'asset'`)
- `entity_id`: referensi ke ID transaksi penugasan atau ID master aset
- `photo_type`: tipe dokumentasi yang didukung sesuai [DATA_MODEL.md](./DATA_MODEL.md) (`'BEFORE'`, `'AFTER'`, `'TEMUAN'`, `'SURVEY'`)
- `latitude`, `longitude`: koordinat geografis pengambilan foto
- `taken_at`: waktu fisik pengambilan foto (rana kamera)
- `upload_status`: diinisialisasi `'PENDING'`
- `s3_key`: bernilai `null`
- `uploaded_at`: bernilai `null`
- `local_path`: path file fisik gambar pada penyimpanan privat perangkat (hanya ada di device, **TIDAK PERNAH dikirim ke server**)

### 15.2 CREATE Payload ke Controlled Server Sync (`powersync-write` / API)
Ketika perangkat online dan melakukan sinkronisasi, client **hanya mengirimkan atribut allowlisted**:
```json
{
  "id": "018d9f61-3c4d-5e6f-7a8b-9c0d1e2f3a4b",
  "entity_type": "work_order",
  "entity_id": "018d9f5b-4d5e-6f7a-8b9c-0d1e2f3a4b5c",
  "photo_type": "AFTER",
  "latitude": -5.342190,
  "longitude": 105.823460,
  "taken_at": "2026-09-26T09:28:10+07:00"
}
```

**Aturan Kontrol Server:**
1. Client **TIDAK MENGONTROL** field siklus hidup upload: `upload_status`, `s3_key`, dan `uploaded_at`. Jika dikirimkan oleh client, server mengabaikan atau menolaknya.
2. Saat server menerima payload CREATE metadata, server menginisialisasi secara mandiri:
   - `upload_status = 'PENDING'`
   - `s3_key = null`
   - `uploaded_at = null`
3. Perubahan nilai `s3_key`, `upload_status`, dan `uploaded_at` hanya terjadi kemudian melalui alur terkontrol server (*photo upload lifecycle*) setelah verifikasi fisik objek S3 berhasil pada endpoint `POST /v1/photo/complete`.

---

# 16. Photo Upload Contract (Amazon S3 Direct)

Alur pengunggahan berkas visual mengadopsi pola aman *Direct Client-to-S3 Upload*:

```
┌──────────────┐          ┌──────────────────────┐          ┌──────────────┐
│ Flutter App  │          │ Supabase Edge Func   │          │  Amazon S3   │
│ (Android)    │          │ (generate-photo-url) │          │  (Storage)   │
└──────┬───────┘          └──────────┬───────────┘          └──────┬───────┘
       │                             │                             │
       │ 1. POST /v1/photo/upload-url│                             │
       │    { documentation_id, ... }│                             │
       ├────────────────────────────►│                             │
       │                             │ 2. Validasi Hak Akses, Key  │
       │                             │    Issuance, Pre-signed URL │
       │ 3. Return Pre-signed PUT    │                             │
       │◄────────────────────────────┤                             │
       │                             │                             │
       │ 4. PUT Binary File (JPEG Stream Langsung ke S3)           │
       ├──────────────────────────────────────────────────────────►│
       │    HTTP 200 OK (ETag, Checksum)                           │
       │◄──────────────────────────────────────────────────────────┤
       │                             │                             │
       │ 5. POST /v1/photo/complete  │                             │
       │    { documentation_id, ... }│                             │
       ├────────────────────────────►│                             │
       │                             │ 6. Lookup Server-Issued Key,│
       │                             │    Verify Object Exists,    │
       │                             │    Set status = 'UPLOADED'  │
       │ 7. Return Success Confirmed │                             │
       │◄────────────────────────────┤                             │
```

### 16.1 Step A: Request Pre-signed Upload URL
- **Endpoint:** `POST /v1/photo/upload-url`
- **Request Payload:**
```json
{
  "_metadata": {
    "request_id": "f3a4b5c6-d7e8-9a0b-1c2d-3e4f5a6b7c8d",
    "client_operation_id": "018d9f61-3c4d-5e6f-7a8b-9c0d1e2f3a4b"
  },
  "documentation_id": "018d9f61-3c4d-5e6f-7a8b-9c0d1e2f3a4b",
  "file_extension": "jpg",
  "content_type": "image/jpeg"
}
```
- **Server Validations:**
  1. Record `documentation_id` ada di database dengan status `upload_status IN ('PENDING', 'FAILED')`.
  2. Peran pemanggil memiliki relasi sah terhadap entitas pemilik foto.
  3. Server menerbitkan path kunci otoritatif: `evidence/{entity_type}/{entity_id}/{documentation_id}.jpg`.
- **Response Payload (HTTP 200 OK):**
```json
{
  "success": true,
  "data": {
    "documentation_id": "018d9f61-3c4d-5e6f-7a8b-9c0d1e2f3a4b",
    "upload_url": "https://<evidence-bucket>.s3.<region>.amazonaws.com/evidence/work_order/018d9f5b/.../018d9f61.jpg?X-Amz-Signature=...",
    "s3_key": "evidence/work_order/018d9f5b-4d5e-6f7a-8b9c-0d1e2f3a4b5c/018d9f61-3c4d-5e6f-7a8b-9c0d1e2f3a4b.jpg",
    "expires_in_seconds": 900
  },
  "error": null,
  "request_id": "f3a4b5c6-d7e8-9a0b-1c2d-3e4f5a6b7c8d",
  "server_time": "2026-09-26T10:00:00+07:00"
}
```
*(Catatan: Nilai `expires_in_seconds: 900` pada respon di atas hanya contoh ilustrasi / example only. Durasi masa aktif URL pre-signed bersifat configurable via `app_config` dan merupakan **OPEN QUESTION OQ-API-05**).*

---

### 16.2 Step B: Upload File Fisik ke S3
- Client mengeksekusi HTTP PUT langsung ke alamat `upload_url` dengan menyertakan binary berkas JPEG. Header: `Content-Type: image/jpeg`.

---

### 16.3 Step C: Konfirmasi Upload Selesai & Validasi Server
- **Endpoint:** `POST /v1/photo/complete`
- **Preferred Request Payload:**
```json
{
  "_metadata": {
    "request_id": "a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d",
    "client_operation_id": "018d9f61-3c4d-5e6f-7a8b-9c0d1e2f3a4b"
  },
  "documentation_id": "018d9f61-3c4d-5e6f-7a8b-9c0d1e2f3a4b",
  "file_size_bytes": 348210,
  "etag": "b10a8db164e0754105b7a99be72e3fe5"
}
```
- **Prinsip Keamanan Objek Server (Photo Complete Security):**
  1. **Server TIDAK MEMPERCAYAI sembarang `s3_key` dari client.**
  2. Server melakukan lookup `s3_key` resmi yang sebelumnya telah diterbitkan dan terikat pada record `documentation_id` di database.
  3. Server melakukan verifikasi langsung ke Amazon S3 (`HeadObject`) pada *exact configured bucket* dan *authoritative key* tersebut untuk memastikan objek benar-benar telah tersimpan dengan ukuran file yang valid.
  4. Jika client mengirim field `s3_key` untuk alasan backward compatibility, server wajib mencocokkannya secara identik dengan server-issued key dan **menolak** jika nilainya berbeda.
  5. Setelah verifikasi lolos, server memperbarui status: `upload_status = 'UPLOADED'`, `s3_key = authoritative_key`, `uploaded_at = now()`.

---

# 17. Sync Mutation Contract (`powersync-write`)

Sinkronisasi offline Android menggunakan endpoint Supabase Edge Function `powersync-write`.

> **ATURAN KEAMANAN MUTLAK:** Endpoint ini **BUKAN generic SQL proxy** dan **MENOLAK arbitrary table name maupun arbitrary column**. Setiap mutasi wajib diarahkan ke handler spesifik yang memiliki daftar atribut yang diizinkan (*strict allowlist*).

```
┌────────────────────────────────────────────────────────┐
│               POWERSYNC-WRITE BATCH PAYLOAD            │
├────────────────────────────────────────────────────────┤
│ - batch_id: UUID                                       │
│ - operations: [                                        │
│     {                                                  │
│       "type": "p0" | "work_order" | "pasila_session"   │
│               | "pasila_action" | "harsus_inspection" │
│               | "row_work" | "documentation"           │
│               | "p0_petugas" | "wo_petugas"            │
│               | "pasila_action_petugas"                │
│               | "row_work_petugas",                    │
│       "op": "CREATE" | "UPDATE",                       │
│       "id": UUIDv7,                                    │
│       "client_operation_id": UUIDv7,                   │
│       "data": { ... allowlisted_fields_only ... }      │
│     }                                                  │
│   ]                                                    │
└────────────────────────────────────────────────────────┘
```

### 17.1 Allowlist Atribut yang Diizinkan per Tipe Operasi

Tabel berikut mengenumerasikan secara eksplisit resource yang diizinkan tanpa penggunaan karakter wildcard (`*`):

| Resource Type | Operasi Client | Atribut yang Diizinkan (Allowlist) | Atribut yang Ditolak / Ditentukan Server |
|---|---|---|---|
| **`p0`** | `UPDATE` | `status`, `tgl_dikerjakan`, `tgl_selesai`, `alasan_tidak_selesai`, `catatan`, `expected_version` | `posko_id`, `section_id`, `asset_id`, `source_type`, `version`, `created_actor_type`, `created_by` |
| **`p0_petugas`** | `CREATE` | `p0_id`, `petugas_id`, `peran` | `created_at` |
| **`work_order`** | `UPDATE` | `status`, `tgl_dikerjakan`, `tgl_selesai`, `alasan_tidak_selesai`, `catatan`, `expected_version` | `posko_id`, `section_id`, `asset_id`, `jenis_wo`, `version` |
| **`wo_petugas`** | `CREATE` | `work_order_id`, `petugas_id`, `peran` | `created_at` |
| **`pasila_session`**| `CREATE` | `id`, `section_id`, `tanggal`, `shift`, `waktu_mulai` | `posko_id`, `penyulang_id`, `display_code`, `created_by` |
| | `UPDATE` | `waktu_selesai`, `catatan`, `status` | `id`, `section_id`, `posko_id`, `expected_version` |
| **`pasila_action`** | `CREATE` | `id`, `session_id`, `asset_id`, `action_type`, `uraian`, `status`, `is_row_incidental`, `escalated_to_p0`, `latitude`, `longitude` | `display_code`, `created_at`, `p0_id` (Server-generated) |
| **`pasila_action_petugas`** | `CREATE` | `action_id`, `petugas_id`, `peran` | `created_at` |
| **`harsus_inspection`**| `CREATE` | `id`, `asset_id`, `section_id`, `tanggal`, `kondisi_aset`, `temuan`, `kebutuhan_padam`, `rekomendasi`, `perlu_p0` | `inspected_by`, `display_code`, `p0_id` (Server-generated) |
| **`row_work`** | `CREATE` | `id`, `section_id`, `vegetasi_id`, `jenis_pekerjaan`, `volume_pohon`, `is_incidental`, `source_action_id`, `status`, `tgl_eksekusi`, `catatan` | `display_code`, `created_by` |
| **`row_work_petugas`** | `CREATE` | `row_work_id`, `petugas_id`, `peran` | `created_at` |
| **`documentation`** | `CREATE` | `id`, `entity_type`, `entity_id`, `photo_type`, `latitude`, `longitude`, `taken_at` | `upload_status`, `s3_key`, `uploaded_at`, `local_path` |

---

# 18. Bootstrap & Initial Data Contract

Saat login pertama berhasil secara online, perangkat Android mengunduh partisi data awal (*initial bootstrap*) melalui kombinasi aturan PowerSync sync rules dan metadata API:

1. **Struktur Scope Data yang Diterima:**
   - **Profil Pengguna:** `user_profile` (`id`, `nama_lengkap`, `role`, `posko_id`).
   - **Konfigurasi Aplikasi:** Subset tabel `app_config` (aturan shift dinamis, parameter kompresi foto, batas trip Special Case).
   - **Master Referensi Global:** Seluruh baris tabel `posko`, `penyulang`, `section`, dan `petugas` aktif.
   - **Partisi Inventaris Wilayah:** Baris tabel `asset`, `tiang_detail`, `gardu_detail`, `apkuk`, dan `vegetasi` yang berada pada seksi di bawah tanggung jawab Posko terkait (`WHERE section.posko_id = :posko_id`).
   - **Antrian Penugasan Berjalan:** P0 dan work_order aktif yang berada dalam *authorized sync scope* pengguna berdasarkan role, Posko, section, atau assignment (status bukan terminal). Untuk peran POSKO, scope dibatasi pada Posko/section wilayah kerjanya. Untuk peran HARSUS dan ROW, detail pembatasan scope penugasan tetap mengikuti **OPEN QUESTION (OQ-API-02 / OFFLINE_SYNC_SPEC.md)** dan tidak mengunduh seluruh data operasional ULP ke perangkat.

2. **Larangan Unduh Data Mentah:**
   - **Tabel `gangguan_raw` (24 kolom log mentah) TIDAK PERNAH disertakan dalam paket bootstrap mobile.** Perangkat mobile hanya menerima ringkasan metrik melalui sinkronisasi selektif jika diperlukan.

---

# 19. Read Query Contracts (Mobile vs Admin Web)

1. **Saluran Baca Aplikasi Mobile (Android):**
   - Seluruh pembacaan data di perangkat Android dilakukan secara lokal langsung ke SQLite perangkat via PowerSync Watchers.
   - Tidak ada HTTP REST GET individual untuk setiap layar aplikasi mobile, menjamin performa UI instan dan bebas latensi.

2. **Saluran Baca Admin Web (Next.js):**
   - Admin Web membaca data melalui Supabase PostgREST Client (`supabase-js` / `@supabase/ssr`) dengan batasan Row Level Security (RLS) berbasis sesi Admin.
   - Query analitik dan agregasi (seperti rekap skor keandalan, pemantauan status Special Case, dan rekap WO) dilayani melalui PostgreSQL Views dan RPC Functions (`gangguan_mv`, `asset_history_view`).

---

# 20. Admin Web Mutation Contracts

Mutasi data yang diinisiasi oleh Administrator melalui Admin Web dilayani melalui endpoint RPC / Edge Functions dengan jejak audit penuh:

1. **`POST /v1/admin/p0/manual`:** Menerbitkan tiket P0 ad-hoc langsung dari dashboard Web.
2. **`POST /v1/admin/wo/manual`:** Menugaskan WO preventif tambahan di luar jadwal otomatis mesin scoring.
3. **`POST /v1/admin/correction/decision`:** Memberikan persetujuan atau penolakan atas permohonan koreksi Posko.
4. **Allowlisted Master Resource Handlers:**
   - Mutasi master data tidak menggunakan arbitrary SQL proxy atau generic resource URL.
   - Server menyediakan handler terisolasi dengan skema validasi ketat per resource:
     - `POST /v1/admin/master/asset` (Kelola master aset distribusi)
     - `POST /v1/admin/master/section` (Kelola batas seksi wilayah)
     - `POST /v1/admin/master/gardu` (Kelola atribut teknis gardu)
     - `POST /v1/admin/master/petugas` (Kelola data personil petugas lapangan)
5. **`POST /v1/admin/app-config`:** Memperbarui parameter operasional (ambang batas trip Special Case, formula scoring WO, durasi pre-signed URL).

---

# 21. Idempotency Contract

Sistem menerapkan arsitektur idempotensi berlapis untuk menjamin keamanan mutasi jaringan:

1. **Idempotensi Pembuatan Record (CREATE):**
   - Lapisan utama pencegahan duplikasi adalah **UUIDv7 yang digenerate oleh client** sebagai Primary Key record.
   - Jika request CREATE diterima ulang dengan ID yang sama:
     - Jika seluruh atribut bisnis identik -> server mengembalikan response sukses `HTTP 200 OK` dengan flag `is_idempotent_replay: true`.
     - Jika ID sama namun isi atribut bisnis berbeda secara material -> server menolak dengan `HTTP 409 (IDEMPOTENCY_PAYLOAD_MISMATCH)`.

2. **Idempotensi Pembaruan Record (UPDATE):**
   - Pembaruan status pada entitas ber-version (`p0` dan `work_order`) wajib menyertakan `expected_version` dan `client_operation_id`.
   - Jika request update terkirim ulang akibat putus koneksi setelah server berhasil memproses mutasi sebelumnya:
     - Server mendeteksi kecocokan `client_operation_id` pada log mutasi terakhir.
     - Server mengembalikan state sukses versi saat ini tanpa menaikkan versi dua kali (*safe replay*).

3. **Idempotensi Foto Visual:**
   - Server-issued object key yang deterministik menjamin pengunggahan ulang berkas fisik foto pada kunci yang sama akan menimpa binary tanpa menghasilkan duplikasi objek atau kebocoran storage Amazon S3.

---

# 22. Concurrency Contract (Optimistic Concurrency Control)

Untuk entitas transaksi operasional yang mendukung pembaruan status dan memiliki kolom version di [DATA_MODEL.md](./DATA_MODEL.md) (yaitu **`p0`** dan **`work_order`**), sistem menerapkan Optimistic Concurrency Control (OCC) dengan kolom integritas konkurensi `version INTEGER DEFAULT 1`.

Entitas transaksi lain (seperti `pasila_session`, `pasila_action`, `harsus_inspection`, dan `row_work`) tidak memiliki kolom `version` di skema [DATA_MODEL.md](./DATA_MODEL.md). Entitas-entitas ini dikelola melalui aturan transisi status state machine atomik (misalnya penutupan sesi PASILA: `BERJALAN` -> `SELESAI`) dan validasi bisnis sesuai [DATA_MODEL.md](./DATA_MODEL.md) serta [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md).

```
┌────────────────────────────────────────────────────────┐
│             OCC CONCURRENCY CONTRACT FLOW              │
│               (Khusus p0 & work_order)                 │
├────────────────────────────────────────────────────────┤
│ Client membaca:                                        │
│   Record { id: "...", version: 3, status: "DIKERJAKAN" }│
│                                                        │
│ Client mengirim payload mutasi:                        │
│   { "id": "...", "expected_version": 3,                │
│     "client_operation_id": "...",                      │
│     "status": "SELESAI", ... }                         │
│                                                        │
│ Server mengeksekusi atomik dalam transaksi:            │
│   UPDATE table_name                                    │
│   SET status = :status,                                │
│       version = version + 1,                           │
│       updated_at = now()                               │
│   WHERE id = :id AND version = :expected_version;      │
│                                                        │
│ Evaluasi baris terpengaruh (Rows Affected):            │
│   - Jika rows_affected == 1 --> Sukses! Return ver: 4   │
│   - Jika rows_affected == 0 --> Konflik! Tolak 409     │
└────────────────────────────────────────────────────────┘
```

---

# 23. Business Validation Matrix

| Operasi | Aturan Validasi Server | Kode Error Standar |
|---|---|---|
| **Pembuatan P0** | Target aset (`asset_id`) wajib terdaftar di master database | `NOT_FOUND` (`details.resource = "asset"`) |
| **Pembuatan P0** | Seksi (`section_id`) wajib cocok dengan `asset.section_id` | `BUSINESS_RULE_VIOLATION` |
| **Pembuatan WO** | Jenis WO yang diizinkan saat ini hanya `PEMASANGAN_APKUK` | `VALIDATION_ERROR` |
| **Pembuatan WO** | Aset target belum memiliki record `apkuk` berstatus `AKTIF` | `BUSINESS_RULE_VIOLATION` |
| **Pembuatan WO** | Tidak boleh ada WO aktif lain untuk aset yang sama | `DUPLICATE_ACTIVE_WO` |
| **Sesi PASILA** | `section_id` pada pembukaan sesi WAJIB NOT NULL | `VALIDATION_ERROR` |
| **Aksi PASILA** | Sesi induk (`session_id`) wajib berstatus `BERJALAN` | `INVALID_STATE_TRANSITION` |
| **Penyelesaian Tugas** | Jika status `TIDAK_SELESAI`, `alasan_tidak_selesai` wajib diisi | `VALIDATION_ERROR` |
| **Inspeksi Harsus** | Nilai `kebutuhan_padam` wajib `'TANPA_PADAM'` atau `'PERLU_PADAM'` | `VALIDATION_ERROR` |
| **Eksekusi ROW** | Nilai `jenis_pekerjaan` wajib `'RABAS'`, `'PANGKAS'`, atau `'TEBANG'` | `VALIDATION_ERROR` |
| **Upload Foto** | Pre-signed URL hanya diterbitkan jika record metadata ada di DB | `NOT_FOUND` (`details.resource = "documentation"`) |

---

# 24. Server Audit Requirements

Setiap operasi mutasi wajib meninggalkan jejak audit permanen yang transparan:

1. **Pencatatan Aktor Mutasi (`created_actor_type` & `created_by`):**
   - `created_actor_type`: Mengidentifikasi jenis aktor pembuat (`'USER'`, `'SYSTEM_JOB'`, atau `'EDGE_FUNCTION'`).
   - `created_by`: Foreign key ke `user_profile.id`.
     - Wajib terisi ID pengguna jika `created_actor_type = 'USER'`.
     - Bernilai `null` jika dibuat secara otomatis oleh sistem (`SYSTEM_JOB` atau `EDGE_FUNCTION`).

2. **Status History Tracking (`status_history`):**
   - Setiap kali terjadi perubahan pada field `status`, trigger database secara otomatis mencatat record baru pada tabel `status_history` dengan field:
     - `entity_type`
     - `entity_id`
     - `status_lama`
     - `status_baru`
     - `alasan`
     - `actor_type` (`'USER'`, `'SYSTEM_JOB'`, atau `'EDGE_FUNCTION'`)
     - `changed_by` (Foreign key ke `user_profile.id`; bernilai `null` jika `actor_type` bukan `'USER'`)
     - `created_at`

3. **Audit Log Perubahan Sensitif (`audit_log`):**
   - Perubahan data melalui persetujuan koreksi atau mutasi master data Admin dicatat pada `audit_log` dengan field:
     - `action` (`'INSERT'`, `'UPDATE'`, `'DELETE'`)
     - `table_name`
     - `record_id`
     - `old_data` (JSONB)
     - `new_data` (JSONB)
     - `actor_type` (`'USER'`, `'SYSTEM_JOB'`, atau `'EDGE_FUNCTION'`)
     - `actor_id` (UUID nullable jika bukan user)
     - `ip_address` (jika tersedia dari header request)
     - `created_at`

---

# 25. Security & Boundary Guardrails

1. **Zero Secret Leakage:**
   - `SUPABASE_SERVICE_ROLE_KEY` dan `AWS_SECRET_ACCESS_KEY` hanya tersimpan di *Environment Secrets* backend Supabase dan tidak pernah dikirim ke client.

2. **RLS & Privileged Server Operations:**
   - **Row Level Security (RLS)** adalah lapisan *defense-in-depth* terutama untuk request dan query yang berjalan dengan konteks **user JWT / user-scoped database connection**.
   - **Operasi Privileged Service Role:** Operasi Edge Functions dan background cron jobs yang berjalan menggunakan *service-role credential* **mem-bypass RLS di PostgreSQL**. Oleh karena itu:
     - Backend handler wajib secara eksplisit memverifikasi token JWT pemanggil jika request berasal dari pengguna.
     - Backend handler wajib secara eksplisit memvalidasi klaim `app_role` dan `posko_id`.
     - Backend handler wajib secara eksplisit memvalidasi batasan kepemilikan data (*ownership/scope*) dan aturan bisnis sebelum mutasi dijalankan.

3. **Restricted Pre-Signed S3 Scope:**
   - URL pre-signed S3 dibatasi secara ketat hanya untuk metode `HTTP PUT`, path objek spesifik yang ditentukan server, ukuran file maksimum, dan masa berlaku terbatas.

4. **Input Sanitization & Injection Prevention:**
   - Seluruh query database dieksekusi menggunakan *Parameterized Statements* untuk mencegah SQL Injection. Endpoint `powersync-write` dan master admin menolak arbitrary table/column proxy.

---

# 26. API Versioning Strategy

- **Proposed Baseline:** Endpoint RESTful kustom dan Supabase Edge Functions dalam dokumen ini menggunakan format awal `POST /v1/{resource}`.
- **Open Question Status:** Strategi versioning resmi (apakah menggunakan path URL `/v1/`, HTTP custom request header `X-API-Version`, atau unversioned route dengan evolusi non-breaking) berstatus **OPEN QUESTION (OQ-API-08)** dan belum dikunci secara permanen.
- **Kompatibilitas Non-Breaking:** Penambahan field baru pada respon JSON selalu bersifat kompatibel ke belakang. Modifikasi struktur payload yang merusak wajib melalui evaluasi versi baru.

---

# 27. Example End-to-End Contract Flows

### Skenario A: Posko Menyelesaikan P0 Offline Lalu Sinkron
**Request (`POST /v1/sync/powersync-write`):**
```json
{
  "_metadata": {
    "request_id": "aa11bb22-cc33-dd44-ee55-ff66aa77bb88",
    "client_operation_id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
    "client_created_at": "2026-09-26T11:45:00+07:00",
    "client_platform": "ANDROID_FIELD"
  },
  "operations": [
    {
      "type": "p0",
      "op": "UPDATE",
      "id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
      "client_operation_id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
      "data": {
        "expected_version": 2,
        "status": "SELESAI",
        "tgl_dikerjakan": "2026-09-26T10:15:00+07:00",
        "tgl_selesai": "2026-09-26T11:45:00+07:00",
        "catatan": "Koneksi jumper kendor telah diperbaiki aman"
      }
    },
    {
      "type": "p0_petugas",
      "op": "CREATE",
      "id": "018d9f5a-1111-7a8b-9c0d-e1f2a3b4c5d6",
      "client_operation_id": "018d9f5a-1111-7a8b-9c0d-e1f2a3b4c5d6",
      "data": {
        "p0_id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
        "petugas_id": "018d9f56-e5f6-a7b8-c9d0-e1f2a3b4c5d6",
        "peran": "PELAKSANA"
      }
    }
  ]
}
```
**Response (HTTP 200 OK):**
```json
{
  "success": true,
  "data": {
    "processed_count": 2,
    "results": [
      { "id": "018d9f59-7f3a-7d2a-89b1-e23456789abc", "status": "SELESAI", "version": 3 },
      { "id": "018d9f5a-1111-7a8b-9c0d-e1f2a3b4c5d6", "status": "CREATED" }
    ]
  },
  "error": null,
  "request_id": "aa11bb22-cc33-dd44-ee55-ff66aa77bb88",
  "server_time": "2026-09-26T11:45:03+07:00"
}
```

---

### Skenario B: Aksi PASILA Menghasilkan Rekomendasi P0 Tindak Lanjut
Dalam skenario ini, tim Posko mencatat temuan anomali yang tidak bisa dieksekusi langsung. **Android Posko HANYA mengirimkan record `pasila_action` dengan indikator `escalated_to_p0 = true` (TIDAK mengirim raw `p0 CREATE`)**. Server membuat tiket P0 turunan secara otomatis.

**Request (`POST /v1/sync/powersync-write`):**
```json
{
  "_metadata": {
    "request_id": "bb22cc33-dd44-ee55-ff66-aa77bb88cc99",
    "client_operation_id": "018d9f5d-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
    "client_created_at": "2026-09-26T09:30:00+07:00",
    "client_platform": "ANDROID_FIELD"
  },
  "operations": [
    {
      "type": "pasila_action",
      "op": "CREATE",
      "id": "018d9f5d-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
      "client_operation_id": "018d9f5d-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
      "data": {
        "session_id": "018d9f5c-6b7a-8c9d-0e1f-2a3b4c5d6e7f",
        "asset_id": "018d9f51-a1b2-c3d4-e5f6-a7b8c9d0e1f2",
        "action_type": "TEMUAN_ANOMALI",
        "uraian": "Cross-arm bengkok parah dan isolator retak temuan patroli",
        "status": "ESKALASI_P0",
        "is_row_incidental": false,
        "escalated_to_p0": true
      }
    }
  ]
}
```

**Response (HTTP 200 OK dengan P0 Linkage):**
```json
{
  "success": true,
  "data": {
    "processed_count": 1,
    "results": [
      {
        "id": "018d9f5d-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
        "status": "CREATED",
        "created_p0_id": "018d9f59-2222-7d2a-89b1-e23456789abc",
        "p0_display_code": "P0-20260926-0004"
      }
    ]
  },
  "error": null,
  "request_id": "bb22cc33-dd44-ee55-ff66-aa77bb88cc99",
  "server_time": "2026-09-26T09:30:04+07:00"
}
```

---

### Skenario C: Deteksi Version Conflict pada P0 (HTTP 409)
**Request Payload dengan Versi Usang:**
```json
{
  "_metadata": {
    "request_id": "cc33dd44-ee55-ff66-aa77-bb88cc99dd00",
    "client_operation_id": "018d9f59-7f3a-7d2a-89b1-e23456789abc"
  },
  "expected_version": 1,
  "status": "SELESAI",
  "catatan": "Pekerjaan selesai"
}
```
**Response Payload (HTTP 409 Conflict):**
```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "VERSION_CONFLICT",
    "message": "Pembaruan ditolak: data telah diubah oleh pengguna lain di server.",
    "details": {
      "record_id": "018d9f59-7f3a-7d2a-89b1-e23456789abc",
      "expected_version": 1,
      "current_version": 2,
      "current_status": "CANCELLED"
    }
  },
  "request_id": "cc33dd44-ee55-ff66-aa77-bb88cc99dd00",
  "server_time": "2026-09-26T12:00:00+07:00"
}
```

---

# 28. Open Questions (API & Integration Decisions)

Keputusan-keputusan teknis dan integrasi berikut belum memiliki acuan definitif pada dokumen produk dan dicatat secara terbuka untuk disepakati sebelum fase implementasi:

| # | Kode Referensi | Pertanyaan Integrasi API | Implikasi pada Kontrak API & Arsitektur |
|---|---|---|---|
| **1** | **OQ-API-01** | **Identifier Login Supabase Auth:** Apakah login lapangan menggunakan format email riil, email bayangan internal (e.g. `posko01@sribhawono.internal`), atau abstraksi *username* via Edge Function? | Menentukan apakah request login menerima field `email` langsung atau menerima `username` yang dipetakan server-side. |
| **2** | **OQ-API-02** | **Model Akun & Penugasan HARSUS & ROW:** Apakah akun Harsus dan ROW mewakili tim per wilayah atau akun per personil individu? | Menentukan apakah JWT kustom menyertakan identifier tim atau personil untuk filter data PowerSync. |
| **3** | **OQ-API-03** | **Matriks Kelengkapan Foto Wajib:** Pekerjaan preventif mana yang wajib Before + After dan mana yang cukup After saja sebelum status pekerjaan diizinkan `SELESAI`? | Menentukan aturan validasi server saat menerima pembaruan status `SELESAI` pada P0, WO, PASILA, dan ROW. |
| **4** | **OQ-API-04** | **Hak Pembuatan Master Vegetasi oleh ROW:** Apakah regu ROW diizinkan membuat master titik pohon baru via mobile atau hanya mencatat eksekusi pada pohon terdaftar? | Menentukan perizinan write handler pada tabel master `vegetasi` dari perangkat mobile. |
| **5** | **OQ-API-05** | **Masa Berlaku Signed URL Amazon S3:** Berapa durasi ideal pre-signed upload URL (misal: 15 menit vs 60 menit) untuk mengantisipasi jaringan lambat saat upload? | Nilai parameter disimpan dalam `app_config` agar dapat disesuaikan tanpa rilis ulang aplikasi. |
| **6** | **OQ-API-06** | **Kebijakan Rate Limiting API (Proposed):** Berapa batas pemanggilan request maksimum per menit untuk endpoint upload foto dan endpoint sinkronisasi? | Mengamankan Edge Functions dari lonjakan trafik tanpa menghambat pengiriman data massal. |
| **7** | **OQ-API-07** | **Mekanisme Eskalasi & Resolusi Konflik OCC ke Admin:** Apakah rekaman konflik versi OCC (HTTP 409) yang tidak terselesaikan otomatis perlu dimunculkan pada antrian investigasi khusus di Web Admin? | Menentukan apakah perlu dibuatkan antarmuka dan endpoint resolusi mediasi data manual di Web Admin. |
| **8** | **OQ-API-08** | **Strategi API Versioning Definitif:** Apakah arsitektur API akan menggunakan URL path versioning (`/v1/`), custom HTTP header (`X-API-Version`), atau unversioned evolution? | Menentukan routing dan backward compatibility policy pada Supabase Edge Functions. |
| **9** | **OQ-API-09** | **Kebijakan Background Sync Android:** Bagaimana kebijakan eksekusi background sync Android (WorkManager interval, charging/unmetered constraints) sebagaimana diidentifikasi pada [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md)? | Menentukan ritme pengiriman batch `powersync-write` dan queue drain saat aplikasi di latar belakang. |
| **10** | **OQ-API-10** | **Kebutuhan Device Identification:** Apakah field `device_id` pada metadata request wajib diikat ke profil perangkat terdaftar untuk audit keamanan? | Menentukan validasi server terhadap header metadata perangkat saat pemanggilan API. |

---

*Dokumen ini menjadi baseline implementasi untuk aturan kontrak API yang telah dikonfirmasi. Item yang ditandai OPEN QUESTION, PROPOSED, atau CONFIGURABLE belum merupakan keputusan final dan harus dikonfirmasi sebelum dikunci dalam implementation.*
