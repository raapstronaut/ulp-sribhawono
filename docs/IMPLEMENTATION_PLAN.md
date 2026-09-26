# IMPLEMENTATION PLAN & EXECUTION ROADMAP

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Proposed Baseline / Implementation Blueprint  
**Dokumen Acuan (Source of Truth):**  
- [PRD.md](./PRD.md)
- [DESIGN_BRIEF.md](./DESIGN_BRIEF.md)
- [TECHNICAL_SPEC.md](./TECHNICAL_SPEC.md)
- [DATA_MODEL.md](./DATA_MODEL.md)
- [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md)
- [API_CONTRACT.md](./API_CONTRACT.md)

**Ruang Lingkup Sistem:** Flutter Android Field App, Next.js Admin Web, Supabase Auth & PostgreSQL, Supabase Edge Functions, PowerSync, Amazon S3  
**Prinsip Arsitektur:** Single-Tenant (ULP Sribhawono), Offline-First Field Work, Strict Server-Authoritative Mutation, Controlled S3 Direct Upload

---

# 1. Implementation Strategy

Dokumen ini menerjemahkan seluruh arsitektur, skema data, batas mutasi, dan spesifikasi offline-first yang telah disetujui menjadi rencana implementasi yang bertahap, konkret, teruji, dan aman. Pengembangan dipandu oleh strategi berikut:

1. **Vertical-Slice Development:**
   - Sistem **TIDAK** dibangun dengan pendekatan horizontal (menyelesaikan seluruh backend database terlebih dahulu baru beralih ke frontend).
   - Setiap kapabilitas fungsional dibangun sebagai satu irisan vertikal utuh: skema database, aturan RLS, backend handler/RPC, sinkronisasi PowerSync, integrasi UI Android/Web, hingga jejak audit.

2. **P0 sebagai First Complete Business Vertical Slice:**
   - Penugasan Khusus (P0) adalah prioritas tertinggi dan entitas operasional pertama yang diselesaikan secara *end-to-end* (Web Admin terbit $\rightarrow$ sinkronisasi ke Posko Android $\rightarrow$ pengerjaan offline $\rightarrow$ sinkronisasi balik $\rightarrow$ verifikasi audit).
   - Keberhasilan P0 membuktikan kestabilan pondasi arsitektur sebelum modul pekerjaan lapangan lainnya dibangun.

3. **Incremental & Testable Delivery:**
   - Setiap fase implementasi menghasilkan artefak yang dapat dijalankan, diobservasi, dan diverifikasi (*demonstrable outcome*).
   - Tidak ada fase yang dianggap selesai tanpa lulus *Exit Gate* dan *Acceptance Criteria* yang terukur.

4. **Offline-First Tested Early (Bukan Pekerjaan Finishing):**
   - Perilaku offline (penyimpanan local SQLite database, antrian mutasi, deteksi konektivitas, idempotency replay, dan resolusi konflik OCC) diuji sejak fase awal vertical slice, bukan ditambahkan di akhir proyek.

5. **Security & Governance Guardrails (Zero Leaks & Mock Data):**
   - Keamanan (RLS, pemisahan kredensial service-role, sanitasi input) diterapkan bersamaan dengan pembuatan entitas.
   - **Data Riil PLN TIDAK BOLEH dikomit ke repositori Git (publik maupun privat) atau diunggah ke cloud pihak ketiga** sebelum persetujuan tata kelola (*cloud governance approval*) resmi diterbitkan. Seluruh fase pengembangan menggunakan data tiruan (*mock / synthetic dataset*). Folder/pattern khusus digunakan untuk mengisolasi berkas data mentah/privat dari tracking Git tanpa perlu mengabaikan secara blanket seluruh berkas `*.csv` atau `*.xlsx` yang dibutuhkan sebagai fixture sintetis.

6. **Out of MVP Scope Guardrail:**
   - Fitur analitik kompetitif seperti **Ranking Posko** secara tegas dikeluarkan dari lingkup MVP dan dialokasikan untuk fase rilis lanjutan (v2).

---

# 2. Dependency Graph

Diagram alur berikut mengilustrasikan ketergantungan logis antar fase implementasi:

```
[Phase 0: Repo & Dev Foundation]
               │
               ▼
[Phase 1: Database, Auth & Security]
               │
               ▼
[Phase 2: Master Data & Staging]
               │
               ▼
[Phase 3: Android SQLite & PowerSync Shell]
               │
               ▼
[Phase 4: P0 End-to-End Vertical Slice] ◄── FIRST COMPLETE BUSINESS SLICE
               │
               ▼
[Phase 5: Documentation Photo & S3 Pipeline]
               │
       ┌───────┴────────────────────────┐
       ▼                                ▼
[Phase 6: Work Order (APKUK)]    [Phase 7: PASILA Patrol]
       │                                │
       ▼                                ▼
[Phase 8: Harsus Inspection]     [Phase 9: ROW / Vegetation]
       │                                │
       └───────┬────────────────────────┘
               │
               ▼
[Phase 10: Maps & Asset History View]
               │
               ▼
[Phase 11: Special Case Feeder Detection]
               │
               ▼
[Phase 12: Offline & Sync Hardening]
               │
               ▼
[Phase 13: Admin Web Operational Dashboard]
               │
               ▼
[Phase 14: Integration, Testing & Security Hardening]
               │
               ▼
[Phase 15: Deployment Readiness & Production Gate]
```

### Ketergantungan Kritis Antar Modul:
1. **Phase 1 & 2 $\rightarrow$ Phase 3 & 4:** Pondasi database, RLS, hierarki seksi/aset, dan bootstrap PowerSync wajib stabil sebelum pengerjaan form transaksi P0 dapat dimulai.
2. **Phase 4 $\rightarrow$ Phase 5:** Pipeline foto dokumentasi diuji di atas tiket P0 yang sudah berfungsi *end-to-end*.
3. **Phase 5 $\rightarrow$ Phase 6, 7, 8, 9:** Seluruh pekerjaan preventif lapangan membutuhkan komponen dokumentasi foto untuk validasi penyelesaian pekerjaan.
4. **Phase 7 $\rightarrow$ Phase 9:** Fitur temuan vegetasi insidental pada patroli PASILA menghasilkan keterikatan (*linkage*) ke modul ROW.
5. **Phase 7 & Phase 8 BERGANTUNG PADA Phase 4 (P0 Foundation):** Eskalasi temuan PASILA dan rekomendasi Harsus memicu pembentukan tiket P0 turunan di sisi server, sehingga membutuhkan ketersediaan dan kestabilan mutasi P0 server-side yang dibangun pada Phase 4.
6. **Phase 14 $\rightarrow$ Phase 15:** Audit keamanan dan uji stres wajib lolos sebelum gerbang deployment produksi dibuka.

---

# 3. Environment Strategy

Sistem memisahkan tahapan lingkungan secara tegas untuk mengisolasi risiko data dan mengendalikan akses kredensial:

| Aspek / Layanan | LOCAL DEVELOPMENT | DEV (Cloud Staging Sandbox) | STAGING (Pre-Production) | PRODUCTION |
|---|---|---|---|---|
| **Tujuan Lingkungan** | Iterasi kode lokal developer | Integrasi CI & pengujian internal | Simulasi operasional & UAT stakeholder | Operasional langsung ULP Sribhawono |
| **Supabase (DB & Auth)** | Supabase CLI lokal via Docker | Supabase Cloud Project (Dev tier) | Supabase Cloud Project (Staging) | Supabase Managed (Prod tier) |
| **PowerSync Service** | PowerSync Local Dev Instance | Cloud PowerSync (Dev instance) | Cloud PowerSync (Staging instance) | Cloud PowerSync (Production instance) |
| **Storage (Amazon S3)** | LocalStack / Dedicated Dev Bucket | Dedicated Dev Bucket (S3) | Dedicated Staging Bucket (S3 Versioning Enabled) | Dedicated Production Bucket (S3 Versioning Enabled) |
| **Web Hosting** | `localhost:3000` (Next.js dev) | Vercel Preview Deployment | Vercel Staging Environment | Vercel Production Environment |
| **Telemetry & Error** | Console output / Local logger | Sentry (Environment: dev) | Sentry (Environment: staging) | Sentry (Environment: production) |
| **Kebijakan Data** | 100% Data Sintetis / Mock | 100% Data Sintetis / Mock | Data Sintetis Realistis / Anonymized | Data Riil PLN (Setelah Governance Approval) |
| **Secrets Management** | `.env.local` (Git-ignored) | GitHub Secrets & Supabase Secrets | GitHub Secrets & Supabase Secrets | Vault / Environment Secrets Terisolasi |

> **PERINGATAN TATA KELOLA DATA:**  
> Penggunaan data riil PLN (spreadsheet gangguan, data pelanggan, foto, atau topologi aset resmi) **DILARANG KERAS** dimasukkan ke repositori Git (baik publik maupun privat) atau akun/layanan cloud pihak ketiga sebelum terbitnya persetujuan tertulis tata kelola data (*Data Governance & Cloud Security Clearance*). Pengabaian berkas tidak dilakukan secara blanket untuk seluruh `*.csv` / `*.xlsx` jika fixture sintetis dibutuhkan oleh repositori, melainkan menggunakan folder/pattern khusus untuk mengisolasi data mentah/privat serta penegakan *secret scanning*. Pengembangan fase 0 hingga 14 wajib mengandalkan data tiruan (*mock / synthetic datasets*).

---

# 4. Repository Structure Proposal

Repositori dirancang dengan struktur monorepo berorientasi kepemilikan komponen yang bersih tanpa fragmentasi berlebihan:

```
/
├── apps/
│   ├── mobile/                     # Flutter Android Application
│   │   ├── lib/
│   │   │   ├── core/               # Konfigurasi, Network, Local Storage, Theme
│   │   │   ├── features/           # Modul fitur: auth, p0, wo, pasila, harsus, row, maps
│   │   │   └── main.dart           # Entrypoint aplikasi
│   │   ├── test/                   # Unit & Widget Tests
│   │   └── pubspec.yaml
│   │
│   └── admin-web/                  # Next.js Web Dashboard
│       ├── src/
│       │   ├── app/                # App Router (Pages, Layouts)
│       │   ├── components/         # UI Components (Data tables, forms, maps, charts)
│       │   ├── lib/                # Supabase SSR client, helpers, validations
│       │   └── types/              # TypeScript interfaces & enums
│       ├── public/
│       └── package.json
│
├── supabase/                       # Backend Source of Truth
│   ├── migrations/                 # PostgreSQL Migration Scripts (Sequential SQL)
│   ├── functions/                  # Supabase Edge Functions (Deno / TypeScript)
│   │   ├── powersync-write/        # Batch write handler
│   │   ├── generate-photo-url/     # Pre-signed S3 URL generator
│   │   ├── photo-complete/         # S3 verification handler
│   │   └── generate-wo/            # Scheduled WO generation job
│   ├── seed/                       # Mock / Synthetic initial datasets
│   └── config.toml                 # Supabase local environment config
│
├── packages/
│   └── shared-types/               # Schema contracts, status enums, constants (opsional)
│
├── docs/                           # Dokumentasi Arsitektur & Spesifikasi
│   ├── PRD.md
│   ├── DESIGN_BRIEF.md
│   ├── TECHNICAL_SPEC.md
│   ├── DATA_MODEL.md
│   ├── OFFLINE_SYNC_SPEC.md
│   ├── API_CONTRACT.md
│   └── IMPLEMENTATION_PLAN.md
│
├── .github/
│   └── workflows/                  # CI/CD Workflows (Lint, Test, Secret Scan)
├── .gitignore                      # Proteksi ketat berkas rahasia, data mentah & build artifacts
└── README.md
```

---

# 5. Phase-by-Phase Implementation Plan

---

## Phase 0 — Repository & Development Foundation

### Objective
Membangun pondasi repositori kerja yang aman, terstandarisasi, memiliki pipeline CI otomatis, serta menjamin tidak ada kebocoran kredensial rahasia atau data riil PLN ke dalam *version control*.

### Dependencies
- Persetujuan dokumen acuan arsitektur ([PRD.md](./PRD.md), [TECHNICAL_SPEC.md](./TECHNICAL_SPEC.md), [DATA_MODEL.md](./DATA_MODEL.md), [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md), [API_CONTRACT.md](./API_CONTRACT.md)).

### Scope
- Konfigurasi root repositori dan struktur direktori monorepo dasar (`apps/mobile`, `apps/admin-web`, `supabase`, `docs`).
- Konfigurasi `.gitignore` komprehensif (mengabaikan `.env*`, berkas keystore Android, kredensial service-role, dan folder khusus data mentah/privat).
- Konfigurasi standar code quality: ESLint & Prettier untuk Next.js, `flutter_lints` untuk Flutter, linter SQL untuk migrasi.
- Setup GitHub Actions baseline: validasi compile/lint pada setiap pull request dan *automated secret scanner* (misal: Gitleaks/TruffleHog).
- Template `.env.example` terpusat untuk konfigurasi environment variable non-sensitif.

### Out of Scope
- Penulisan logika bisnis atau form antarmuka aplikasi.
- Pendaftaran akun cloud production.

### Backend Work
- Inisialisasi konfigurasi Supabase CLI (`supabase init`).

### Android Work
- Inisialisasi struktur Flutter baseline (`flutter create --org id.co.pln.sribhawono apps/mobile`).
- Minimum supported Android API ditentukan setelah inventaris perangkat lapangan; target/compile SDK mengikuti versi stabil yang sesuai pada saat implementasi.

### Admin Web Work
- Inisialisasi Next.js project baseline dengan App Router dan TypeScript (`apps/admin-web`).

### Offline / Sync Work
- Nihil (Fase fondasi).

### Security / Authorization
- Verifikasi pre-commit hooks dan secret scanning untuk mencegah commit file kredensial (*service role keys*, AWS secrets) dan berkas data riil PLN.
- Penataan `.gitignore` dengan pattern khusus untuk mengisolasi data mentah/privat tanpa mengabaikan secara blanket berkas fixture sintetis yang dibutuhkan proyek.

### Test Data
- Nihil.

### Acceptance Criteria
- [ ] Pipeline CI GitHub Actions berjalan sukses untuk pengecekan lint dan build test baseline.
- [ ] Pemeriksaan keamanan membuktikan tidak ada secret, file `.env`, atau data mentah privat PLN yang terlacak git.
- [ ] Emulator/device Flutter Android dan server dev Next.js dapat dijalankan dari repositori bersih.

### Exit Gate
- Repositori bersih disetujui untuk menerima migrasi basis data Phase 1.

### Risks / Open Questions
- **OQ-DATA-GOV:** Risiko pengembang tidak sengaja melakukan commit file data Excel/CSV riil PLN.  
  *Mitigasi:* Gunakan folder khusus data mentah/privat yang diabaikan Git dan aktifkan pemindaian otomatis *secret scanner*.

---

## Phase 1 — Supabase Database, Auth & Security Foundation

### Objective
Membangun fondasi basis data PostgreSQL di Supabase, sistem otentikasi pengguna, manajemen klaim JWT peran, trigger audit aktor, serta konfigurasi aplikasi dinamis.

### Dependencies
- Phase 0 selesai.
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 1, 3, 4.1, 4.2).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 1, 7, 24, 25).

### Scope
- Skema database ekstensi (`pgcrypto`).
- Tabel konfigurasi aplikasi `app_config` dan inisialisasi parameter operasional awal.
- Tabel `user_profile` terikat pada `auth.users` Supabase.
- Konfigurasi custom JWT access token hook untuk menyuntikkan klaim `app_role` (`ADMIN`, `POSKO`, `HARSUS`, `ROW`) dan `posko_id`.
- Pembuatan tabel audit sentral: `status_history` dan `audit_log` beserta trigger otomatisnya.
- Penegakan baseline Row Level Security (RLS) pada tabel profil pengguna dan konfigurasi.

### Out of Scope
- Impor data inventaris fisik jaringan (aset, gardu, tiang).
- UI frontend lengkap.

### Backend Work
- Pembuatan migrasi SQL sekuensial:
  - `00001_initial_extensions.sql`
  - `00002_app_config.sql`
  - `00003_user_profile_and_auth_hooks.sql`
  - `00004_audit_system.sql`
- Implementasi PostgreSQL trigger untuk pemeliharaan audit `created_actor_type` (`USER`, `SYSTEM_JOB`, `EDGE_FUNCTION`) dan `created_by`.

### Android Work
- Setup service wrapper Supabase Auth client di Flutter untuk membaca JWT session dan custom claims.

### Admin Web Work
- Setup Supabase client helper (`@supabase/ssr`) dengan middleware verifikasi sesi JWT dan proteksi route `/admin`.

### Offline / Sync Work
- Nihil.

### Security / Authorization
- Uji kriptografis JWT claim injection:
  - Klaim `app_role` wajib tersedia untuk semua application user (ADMIN, POSKO, HARSUS, ROW).
  - Klaim `posko_id` wajib ada dan bersifat authoritative untuk role **POSKO**.
  - Role **HARSUS** dan **ROW** tidak diasumsikan memiliki klaim `posko_id` sampai model akun dan scope-nya dikonfirmasi. Jika klaim `posko_id` hadir pada token HARSUS/ROW, semantics-nya tidak boleh dijadikan production authorization rule sebelum Open Question terkait diselesaikan.
- Uji RLS: pengguna dengan `app_role = 'POSKO'` tidak dapat membaca profil posko lain atau memodifikasi tabel `app_config`.
- Uji akun nonaktif: verifikasi pengguna dengan `is_active = false` ditolak saat mengakses data.

### Test Data
- Synthetic test fixture: synthetic ADMIN account, synthetic POSKO accounts (`POSKO_01`, `POSKO_02`), synthetic HARSUS account(s), synthetic ROW account(s) (tanpa menyatakan apakah akun HARSUS/ROW mewakili tim atau individu, karena model akun HARSUS/ROW tetap OPEN QUESTION).

### Acceptance Criteria
- [ ] Semua test user menerima klaim `app_role` yang valid sesuai perannya.
- [ ] Test user POSKO menerima klaim `posko_id` yang sesuai dan bersifat authoritative.
- [ ] Test user HARSUS dan ROW tidak diwajibkan memiliki klaim `posko_id` pada baseline ini.
- [ ] Perubahan data memicu pencatatan otomatis ke `audit_log` dan perubahan status mencatat ke `status_history`.
- [ ] Kebijakan RLS memblokir akses data antar posko yang tidak berwenang.

### Exit Gate
- Skema dasar terpasang di database lokal dan dev cloud; otentikasi role terbukti handal.

### Risks / Open Questions
- **OQ-AUTH-ID:** Format identitas login Supabase Auth (Email riil vs Username abstraction).  
  *Mitigasi:* Gunakan format email sintetis terstandarisasi untuk dev (`posko1@sribhawono.local`, `admin@sribhawono.local`).

---

## Phase 2 — Master Data & Initial Import Foundation

### Objective
Membangun struktur data master hierarki jaringan distribusi PLN, tabel pementasan data mentah gangguan, derivasi analitik *Materialized View*, serta pipeline validasi impor data.

### Dependencies
- Phase 1 selesai.
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 2, 4.1, 4.2).

### Scope
- Tabel master terstruktur: `posko`, `penyulang`, `section`, `asset`, `tiang_detail`, `gardu_detail`, `apkuk`, `vegetasi`, dan master `petugas`.
- Penegakan relasi hierarkis: $\text{ASSET} \rightarrow \text{SECTION} \rightarrow \text{POSKO}$.
- Tabel pementasan tanpa modifikasi `gangguan_raw` (tepat 24 kolom sumber eksternal PLN).
- Pembuatan *Materialized View* `gangguan_mv` dengan derivasi analitik provisional `is_trip`.
- Validasi integritas kunci bisnis PLN (`posko_code`, `section_code`, `asset_code`, dll.).

### Out of Scope
- Sinkronisasi data ke perangkat mobile (dilakukan pada Phase 3).
- Impor data gangguan produksi aktual PLN.

### Backend Work
- Migrasi SQL tabel master jaringan dan detail teknis.
- Migrasi tabel `gangguan_raw` (24 kolom text/raw) dan view `gangguan_mv`.
- RPC function / script validasi untuk mendeteksi *orphan asset* (aset tanpa seksi yang valid).

### Android Work
- Nihil.

### Admin Web Work
- Halaman UI awal Admin Web untuk melihat daftar master posko, seksi, dan aset (mode read-only).

### Offline / Sync Work
- Nihil.

### Security / Authorization
- RLS pada tabel master data: hanya peran `ADMIN` yang diizinkan melakukan operasi INSERT/UPDATE/DELETE. Peran lapangan (`POSKO`, `HARSUS`, `ROW`) berstatus read-only.

### Test Data
- Dataset sintetis: 6 Posko ULP Sribhawono terkonfirmasi, 10 Penyulang, 30 Seksi, 500 Titik Aset (Tiang & Gardu), 50 Catatan APKUK, 50 Titik Vegetasi, serta 100 baris mock `gangguan_raw`.

### Acceptance Criteria
- [ ] Database menolak pembuatan aset yang merujuk pada `section_id` yang tidak terdaftar.
- [ ] Refresh `gangguan_mv` berhasil mengekstrak data analitik dari 24 kolom `gangguan_raw`.
- [ ] Admin Web berhasil menampilkan data master aset dengan relasi wilayah 6 Posko yang konsisten.

### Exit Gate
- Seluruh tabel master terpasang dan terisi data mock; hierarki wilayah terverifikasi utuh.

### Risks / Open Questions
- **OQ-GANGGUAN-01:** Derivasi `is_trip` pada `gangguan_mv` berstatus provisional sampai data riil dievaluasi.  
  *Mitigasi:* Gunakan rule `sifat_gangguan ILIKE '%trip%'` pada dataset mock.

---

## Phase 3 — Android Flutter + Local SQLite + PowerSync Foundation

### Objective
Membangun arsitektur dasar aplikasi mobile Android, integrasi local SQLite database, sinkronisasi replikasi PowerSync, serta navigasi aplikasi berbasis peran.

### Dependencies
- Phase 1 dan Phase 2 selesai.
- [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md) (Bagian 1, 2, 4, 10).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 17, 18).

### Scope
- Setup client PowerSync pada Flutter terhubung ke local SQLite database.
- Mekanisme encryption-at-rest pada local SQLite database ditandai sebagai security implementation decision / evaluation item yang dievaluasi sesuai kebutuhan hardening.
- Konfigurasi *sync rules* PowerSync dengan pendekatan berbeda per peran:
  - **POSKO:** sync partition berdasarkan `posko_id` authoritative dari JWT claim dan section scope wilayah kerja posko bersangkutan.
  - **HARSUS / ROW:** gunakan *synthetic minimal test scope* untuk development. Exact production sync partition HARSUS/ROW tetap **OPEN QUESTION** dan tidak boleh dikunci menggunakan asumsi scope posko sebagai production rule.
- Shell UI Android: layar Login, indikator status konektivitas jaringan (Online/Offline/Syncing), dan kerangka navigasi menu sesuai peran pengguna lapangan (`POSKO`, `HARSUS`, `ROW`). Peran `ADMIN` mengelola sistem melalui Next.js Admin Web.
- Manajemen state sinkronisasi lokal dan visualisasi status antrian (*sync queue status*).

### Out of Scope
- Form input pekerjaan transaksi operasional (P0, WO, PASILA, dll.).
- Pengambilan dan kompresi foto kamera.

### Backend Work
- Konfigurasi instance PowerSync dan otentikasi token integrasi Supabase.
- Penulisan aturan replikasi *PowerSync Sync Rules* (partisi data master berdasarkan klaim token pengguna).

### Android Work
- Implementasi layer `PowerSyncDatabase` dan inisialisasi schema local SQLite database.
- Implementasi `ConnectivityService` untuk mendeteksi perubahan jaringan secara reaktif.
- Layar Login dan navigasi utama berbasis `app_role` lapangan (Home Posko, Home Harsus, Home ROW). UI operasional Admin berada pada Next.js Admin Web.

### Admin Web Work
- Nihil.

### Offline / Sync Work
- Pembuktian siklus awal: Login saat online $\rightarrow$ Bootstrap master data terunduh ke SQLite $\rightarrow$ Matikan internet $\rightarrow$ Buka ulang aplikasi $\rightarrow$ Master data tetap terbaca instan dari SQLite.

### Security / Authorization
- Memastikan token JWT dan kredensial sesi tersimpan aman di *Flutter Secure Storage*.
- Memastikan data master yang terunduh ke SQLite lokal Posko hanya mencakup data seksi di wilayah posko bersangkutan.
- Untuk HARSUS dan ROW: gunakan *synthetic minimal test scope* pada fase ini; production data boundary-nya tidak boleh diasumsikan sama dengan scope Posko sebelum Open Question scope HARSUS/ROW diputuskan.

### Test Data
- Kredensial akun sintetis lapangan untuk peran POSKO, HARSUS, dan ROW (tanpa menetapkan model akun tim vs individu).

### Acceptance Criteria
- [ ] Perangkat Android berhasil login online dan menyelesaikan proses bootstrap master data.
- [ ] Saat koneksi internet dimatikan (Airplane Mode), data aset dan seksi tetap dapat dicari dan dibuka dari cache local SQLite database.
- [ ] Indikator status jaringan pada UI aplikasi secara akurat menampilkan status "Offline" dan "Online".

### Exit Gate
- Replikasi data read-only PowerSync berfungsi mulus pada kondisi online dan offline.

### Risks / Open Questions
- **OQ-SYNC-POLICY:** Kebijakan background sync Android via WorkManager.  
  *Mitigasi:* Fokuskan verifikasi sinkronisasi saat aplikasi berada di foreground (*active session*) terlebih dahulu.

---

## Phase 4 — P0 End-to-End Vertical Slice

### Objective
Membuktikan kapabilitas operasional *end-to-end* pertama pada sistem melalui alur **Penugasan Khusus (P0)**: diterbitkan manual oleh Admin Web, disinkronkan ke Posko Android, dieksekusi saat offline, disinkronkan kembali ke server dengan kontrol konkurensi OCC, serta terekam jejak auditnya secara utuh.

### Dependencies
- Phase 1, 2, dan 3 selesai.
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 4.3.1, 4.3.2).
- [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md) (Bagian 3, 5, 6).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 9, 17, 21, 22).

### Scope
- Pembuatan P0 manual di Admin Web: pemilihan aset, penurunan otomatis seksi dan posko, penerbitan `display_code` (format `P0-YYYYMMDD-XXXX`), dan penetapan aktor `created_actor_type = 'USER'`.
- Sinkronisasi P0 aktif ke perangkat Android Posko terkait.
- Eksekusi siklus hidup P0 di Android: `BARU` $\rightarrow$ `DITERIMA` $\rightarrow$ `DIKERJAKAN` $\rightarrow$ `SELESAI` / `TIDAK_SELESAI`.
- Pemilihan multi-personil pelaksana pada dropdown master petugas (tersimpan di junction `p0_petugas`).
- Pengiriman mutasi batch via Supabase Edge Function `powersync-write` menggunakan UUIDv7 client-generated.
- Penegakan Optimistic Concurrency Control (`expected_version`).
- Pencatatan riwayat perubahan status pada tabel `status_history`.

### Out of Scope
- Upload berkas fisik foto ke Amazon S3 (metadata dokumentasi foto disimulasikan).
- Pembangkitan P0 otomatis dari modul lain (PASILA, Harsus, Special Case).

### Backend Work
- Edge Function `powersync-write` menangani operasi mutasi `p0` (UPDATE) dan `p0_petugas` (CREATE) dengan allowlist ketat.
- Endpoint Admin Web `POST /v1/p0/create-manual` via PostgreSQL RPC function.
- Trigger database untuk auto-increment `version` dan pencatatan audit ke `status_history`.

### Android Work
- Tampilan Daftar Tiket P0 di Android (Tab: Berjalan, Selesai).
- Form Eksekusi P0: tombol respon cepat status, input catatan, isian wajib `alasan_tidak_selesai`, dan multi-select dropdown personil regu kerja.
- Local SQLite mutation queue untuk operasi P0 offline.

### Admin Web Work
- Tampilan Manajemen P0 di Admin Web: form pembuatan P0 manual, pemantauan status tiket aktif secara real-time, dan dialog rincian tiket beserta riwayat status auditnya.

### Offline / Sync Work
- Uji eksekusi P0 saat perangkat mobile offline total, dilanjutkan proses *drain queue* otomatis saat konektivitas kembali pulih.
- Uji safe idempotent replay: mutasi berulang dengan `client_operation_id` yang sama tidak menghasilkan record duplikat.

### Security / Authorization
- Validasi otorisasi server: Posko B ditolak oleh server jika mencoba memperbarui status tiket P0 milik Posko A.
- Uji konflik OCC: jika versi tiket di server telah berubah (misal dibatalkan oleh Admin), update Posko ditolak dengan status HTTP 409 (`VERSION_CONFLICT`).

### Test Data
- 5 Tiket P0 sintetis yang ditugaskan ke Posko 01 dan Posko 02.

### Acceptance Criteria
- [ ] Admin berhasil membuat tiket P0 manual di Web Admin; tiket muncul pada daftar Android Posko terkait.
- [ ] Petugas Posko dapat menerima, mengerjakan, dan menyelesaikan P0 saat perangkat offline (Airplane Mode).
- [ ] Saat online kembali, data status dan personil pelaksana tersinkronisasi sempurna ke database server.
- [ ] Web Admin langsung menampilkan status terbaru dan tabel `status_history` mencatat kronologi perubahan secara valid.
- [ ] Percobaan update data usang memicu HTTP 409 `VERSION_CONFLICT` dan mencegah penimpaan sepihak (*blind overwrite*).

### Exit Gate
- Vertical slice P0 berfungsi 100% *end-to-end* baik online maupun offline; arsitektur mutasi terbukti valid.

### Risks / Open Questions
- Risiko: Keterlambatan sinkronisasi antrian saat jaringan edge tidak stabil.  
  *Mitigasi:* Logika transisi status lokal pada SQLite bersifat optimistik sehingga UI tidak terblokir latensi jaringan.

---

## Phase 5 — Documentation Photo + Amazon S3 Pipeline

### Objective
Membangun pipeline penanganan foto bukti fisik pekerjaan lapangan: kompresi di perangkat Android (configurable parameters), penyimpanan metadata offline, perolehan *pre-signed upload URL* Amazon S3, pengunggahan langsung (*direct client-to-S3*), dan verifikasi integritas objek di sisi server.

### Dependencies
- Phase 4 selesai.
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 4.3.12).
- [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md) (Bagian 7).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 15, 16).

### Scope
- Modul kamera aplikasi Android dengan kompresi gambar JPEG yang konfigurasional (*image compression/resolution/quality configurable*). Target ukuran file pasti ditentukan saat pengujian perangkat & jaringan lapangan.
- Pencatatan metadata koordinat GPS dan `taken_at` disimpan sebagai metadata sesuai `DATA_MODEL`. Visual watermark GPS/timestamp hanya diterapkan jika kemudian dikonfirmasi sebagai requirement.
- Penyimpanan file fisik sementara di direktori privat aplikasi (`local_path`) dan pencatatan record `documentation` di SQLite lokal (`upload_status = 'PENDING'`).
- Edge Function `generate-photo-url` (`POST /v1/photo/upload-url`): penerbitan pre-signed PUT URL dengan path kunci deterministik `evidence/{entity_type}/{entity_id}/{documentation_id}.jpg`.
- Pengunggahan berkas fisik via HTTP PUT langsung dari Android ke Amazon S3.
- Edge Function `photo-complete` (`POST /v1/photo/complete`): validasi keberadaan berkas fisik via S3 `HeadObject` dan pembaruan status menjadi `'UPLOADED'`.
- Pembersihan file fisik lokal mengikuti *configurable local retention policy* setelah upload server terkonfirmasi.

### Out of Scope
- Penyimpanan berkas foto sebagai binary/BLOB di PostgreSQL (dilarang mutlak).
- Tampilan galeri foto publik tanpa autentikasi.

### Backend Work
- Setup bucket Amazon S3 dengan konfigurasi Private Access, CORS, dan enkripsi SSE-S3.
- Implementasi Edge Function `generate-photo-url` menggunakan AWS SDK.
- Implementasi Edge Function `photo-complete` dengan pengecekan ukuran berkas dan integritas kunci otoritatif server.

### Android Work
- Integrasi library kamera dan kompresi gambar (*image picker / camera package*).
- Antrian background uploader foto (`PhotoUploadWorker`) yang beroperasi independen dari antrian mutasi data teks.
- Dialog tampilan thumbnail foto lokal saat offline dan preview foto online.

### Admin Web Work
- Komponen pratinjau foto (*photo viewer modal*) di Admin Web yang memuat gambar melalui pre-signed GET URL atau CloudFront berizin.

### Offline / Sync Work
- Uji pemotretan saat offline: metadata masuk antrian sync PowerSync, file fisik masuk antrian upload S3 lokal.
- Uji pemulihan koneksi: verifikasi metadata tersinkronisasi terlebih dahulu sebelum upload file fisik dijalankan.
- Penanganan token pre-signed URL kedaluwarsa: aplikasi meminta URL baru secara transparan jika upload tertunda lama.

### Security / Authorization
- Keamanan Objek S3: Server menolak sembarang `s3_key` dari request client; server memverifikasi hanya path kunci yang diterbitkan secara resmi.
- Kredensial AWS IAM (Access Key & Secret Key) hanya tersimpan pada Supabase Environment Secrets dan **tidak pernah bocor ke client mobile**.

### Test Data
- 20 Gambar sintetis uji coba kompresi dan upload.

### Acceptance Criteria
- [ ] Foto yang diambil di lapangan dikompresi sesuai konfigurasi dan menyimpan metadata koordinat GPS serta `taken_at` sesuai `DATA_MODEL`.
- [ ] Saat offline, foto tersimpan di HP dan tiket pekerjaan dapat ditutup.
- [ ] Saat online, berkas fisik terunggah langsung ke S3 dan status berubah menjadi `UPLOADED`.
- [ ] Pembersihan foto lokal berjalan sesuai *configurable local retention policy* setelah upload terkonfirmasi.
- [ ] Admin Web dapat membuka dan melihat foto bukti pengerjaan pekerjaan lapangan.

### Exit Gate
- Pipeline pengunggahan foto langsung ke S3 teruji stabil pada simulasi koneksi terputus dan bandwidth rendah.

### Risks / Open Questions
- **OQ-URL-EXPIRY:** Masa berlaku pre-signed URL (misal 15 vs 60 menit).  
  *Mitigasi:* Simpan parameter durasi masa aktif pada tabel `app_config` (*configurable*).
- **OQ-PHOTO-01:** Matriks kelengkapan foto wajib per jenis tugas.  
  *Mitigasi:* Simpan aturan validasi kelengkapan foto sebagai parameter konfigurasi dinamis pada `app_config`.

---

## Phase 6 — Work Order (WO) Preventif

### Objective
Membangun siklus kerja pemeliharaan preventif pemasangan APKUK: konsep bisnis target 6 WO per shift per posko, penerbitan manual oleh Admin, eksekusi lapangan oleh Posko, serta pembaruan status siklus hidup master APKUK.

### Dependencies
- Phase 4 dan Phase 5 selesai.
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 4.3.3, 4.3.4, 4.1.7).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 10).

### Scope
- Jenis WO terkonfirmasi: **`PEMASANGAN_APKUK`** murni.
- Target operasional bisnis: 6 WO / shift / Posko. Scheduler tidak dikunci wajib selalu membuat tepat 6 pada seluruh kondisi (misalnya saat terjadi lonjakan P0).
- Scheduled Job / Edge Function `generate-wo`: algoritma seleksi aset target eligible menggunakan deterministic synthetic test fixture atau placeholder scoring configuration yang secara eksplisit NON-PRODUCTION.
- Form penerbitan WO manual ad-hoc di Web Admin.
- Eksekusi WO di Android Posko: penerimaan, pengerjaan, pelaporan selesai/gagal, pemilihan personil pelaksana (`wo_petugas`), dan bukti foto dokumentasi.
- Otomasi pasca penyelesaian WO: pembuatan atau pembaruan record pada tabel `apkuk` menjadi berstatus `status = 'AKTIF'`.

### Out of Scope
- Jenis pekerjaan preventif selain pemasangan APKUK (kecuali kemudian dikonfirmasi via OQ).
- Formula bisnis final pembobotan WO (tetap Open Question).

### Backend Work
- Implementasi Edge Function internal `generate-wo` (dipanggil oleh scheduler dengan konteks aktor `SYSTEM_JOB`).
- Penegakan aturan bisnis:
  1. Aset target berstatus aktif/eligible dan belum memiliki APKUK aktif.
  2. Tolak pembuatan jika aset target sudah memiliki WO aktif berjalan (`DUPLICATE_ACTIVE_WO`).
- Update junction table `wo_petugas` dan pembaruan siklus hidup record `apkuk`.

### Android Work
- Layar Daftar Work Order di Android (Filter: Shift Berjalan, Hari Ini).
- Form Eksekusi WO APKUK dan pratinjau riwayat pemasangan sebelumnya pada aset.

### Admin Web Work
- Dashboard monitoring WO preventif per posko, form/tombol Create Manual WO oleh Admin (mengikuti aturan bisnis pada DATA_MODEL/API_CONTRACT, dengan jenis WO terkonfirmasi saat ini PEMASANGAN_APKUK), dan tabel rekapitulasi progres shift.

### Offline / Sync Work
- Uji eksekusi WO dalam kondisi offline di perangkat Posko, diikuti sinkronisasi massal (*batch drain*) saat online.

### Security / Authorization
- Verifikasi batas wilayah: Posko hanya menerima dan dapat memperbarui WO yang berada pada seksinya.
- Job `generate-wo` terlindungi sebagai endpoint internal privat yang tidak dapat di-trigger sembarang oleh client publik.

### Test Data
- 30 Titik gardu/aset mock tanpa APKUK untuk simulasi pembangkitan otomatis.

### Acceptance Criteria
- [ ] Dalam skenario uji dengan konfigurasi target 6, tidak ada P0 yang mengurangi target, dan tersedia minimal 6 asset eligible, generator menghasilkan 6 WO tanpa duplikasi.
- [ ] Posko dapat menyelesaikan pekerjaan pemasangan APKUK secara offline dengan menyertakan foto dokumentasi.
- [ ] Ketika WO disahkan berstatus `SELESAI`, aset target otomatis tercatat memiliki APKUK aktif pada tabel `apkuk`.

### Exit Gate
- Modul Work Order terintegrasi penuh dari pembangkitan terjadwal hingga penutupan tiket di lapangan.

### Risks / Open Questions
- **OQ-WO-01:** Formula/bobot WO (Open Question).  
  *Mitigasi:* Gunakan deterministic synthetic test fixture / placeholder scoring configuration (NON-PRODUCTION).
- **OQ-WO-02:** Keberlakuan target 6 WO ketika dalam shift terdapat banyak P0 (Open Question).
- **OQ-WO-03:** Jenis manual WO selain `PEMASANGAN_APKUK` (Open Question).

---

## Phase 7 — PASILA (Patroli Eksekusi Langsung)

### Objective
Membangun modul patroli dinamis PASILA: pembukaan dan penutupan sesi patroli per seksi, pencatatan tindakan eksekusi langsung di tempat, pencatatan vegetasi insidental yang terhubung ke histori ROW, serta eskalasi otomatis temuan anomali menjadi tiket P0 turunan di sisi server.

### Dependencies
- Phase 4 (P0 foundation), Phase 5, dan Phase 6 selesai. Modul PASILA bergantung pada Phase 4 karena eskalasi temuan memerlukan pembuatan tiket P0 server-side.
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 4.3.5, 4.3.6, 4.3.7).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 11).

### Scope
- Sesi Patroli: pembukaan sesi (`pasila_session`) wajib menyertakan `section_id` (NOT NULL), pemilihan shift, dan penurunan posko penanggung jawab.
- Pencatatan Tindakan (`pasila_action`):
  - Tindakan Selesai di Tempat: `status = 'SELESAI_DI_TEMPAT'`, `escalated_to_p0 = false`.
  - Pekerjaan Vegetasi Insidental: `is_row_incidental = true` secara otomatis membuat/menautkan record pada tabel `row_work` (`source_action_id = action.id`).
  - Temuan yang Tidak Dapat Dieksekusi: `status = 'ESKALASI_P0'`, `escalated_to_p0 = true` memicu pembuatan tiket P0 turunan secara server-side.
- Penutupan Sesi: transisi atomik state machine `BERJALAN` $\rightarrow$ `SELESAI` (tanpa kolom version).

### Out of Scope
- Patroli PASILA lintas seksi (sesi dikunci wajib terikat pada satu seksi wilayah kerja).
- Tracking rute GPS / breadcrumb data (sistem tidak mencakup route tracking).

### Backend Work
- Handler sinkronisasi `powersync-write` untuk entitas `pasila_session`, `pasila_action`, dan `pasila_action_petugas`.
- Logika server-side: ketika menerima `pasila_action` dengan `escalated_to_p0 = true`, sistem secara otomatis dan idempotent membuat record `p0` (`source_type = 'PASILA'`, `source_ref_id = action.id`) dan mengembalikan `created_p0_id`.
- Logika server-side: ketika `is_row_incidental = true`, sistem membuat record `row_work` terkait secara otomatis.

### Android Work
- Alur UI Sesi PASILA: Layar Buka Sesi $\rightarrow$ Layar Dashboard Sesi Berjalan $\rightarrow$ Form Tambah Tindakan/Temuan $\rightarrow$ Layar Ringkasan & Tutup Sesi.
- Penandaan koordinat GPS otomatis pada setiap pencatatan aksi.

### Admin Web Work
- Monitoring log sesi patroli PASILA: Admin Web menampilkan list sesi PASILA, seksi, action markers / koordinat lokasi tindakan, serta action history.

### Offline / Sync Work
- Seluruh sesi patroli dan multi-aksi tindakan dapat dicatat saat offline. Android Posko **TIDAK MENGIRIM** raw `p0 CREATE`; server yang membentuk P0 saat sinkronisasi tiba.

### Security / Authorization
- Memastikan Posko hanya dapat membuka sesi patroli pada seksi yang menjadi wilayah tanggung jawabnya.
- Sesi yang telah `SELESAI` terkunci dari penambahan tindakan baru di sisi server.

### Test Data
- 3 Sesi patroli mock dengan 10 tindakan (campuran eksekusi langsung, perabasan pohon insidental, dan anomali rusak parah).

### Acceptance Criteria
- [ ] Pembukaan sesi tanpa `section_id` ditolak oleh sistem.
- [ ] Aksi PASILA dengan `is_row_incidental = true` secara otomatis memunculkan catatan pekerjaan pada rekam jejak ROW.
- [ ] Aksi PASILA dengan `escalated_to_p0 = true` berhasil membangkitkan tiket P0 resmi di server tanpa client mengirim payload `p0 CREATE` mentah.
- [ ] Penutupan sesi berhasil mengunci penambahan aksi baru.

### Exit Gate
- Alur patroli PASILA beserta cabang otomatisasinya (ROW & P0) tervalidasi stabil.

### Risks / Open Questions
- **OQ-PASILA-01:** Kriteria pemilihan seksi/penyulang target PASILA dan periode trip yang digunakan saat tidak ada P0 (Open Question).

---

## Phase 8 — Harsus (Tim Inspeksi Harsus)

### Objective
Membangun modul inspeksi teknis intensif oleh Tim Inspeksi Harsus: pencatatan evaluasi visual kondisi aset, klasifikasi kebutuhan pemadaman, dokumentasi foto anomali, serta penerbitan otomatis tiket P0 rekomendasi perbaikan.

### Dependencies
- Phase 4 (P0 foundation) dan Phase 5 selesai. Modul Harsus bergantung pada Phase 4 karena rekomendasi Harsus memicu pembentukan tiket P0 server-side.
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 4.3.8).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 12).

### Scope
- Form input inspeksi Harsus (`harsus_inspection`): pemilihan aset target, evaluasi `kondisi_aset` (`BAIK`, `RUSAK_RINGAN`, `RUSAK_BERAT`, `KRITIS`), uraian temuan teknis visual, dan rekomendasi solusi.
- Validasi mutlak nilai `kebutuhan_padam`: `'TANPA_PADAM'` atau `'PERLU_PADAM'`.
- Rekomendasi tindak lanjut P0: jika `perlu_p0 = true`, backend handler otomatis menerbitkan tiket P0 turunan (`source_type = 'HARSUS'`, `source_ref_id = inspection.id`, `created_actor_type = 'EDGE_FUNCTION'`).
- Hubungan relasi personil inspeksi melalui field `inspected_by`.

### Out of Scope
- Penugasan eksekusi langsung fisik oleh tim Harsus (tim Harsus bertindak sebagai inspektor, bukan pelaksana perbaikan).
- Fitur "Daftar Jadwal Inspeksi" ( scheduling Harsus tidak ada di source of truth).

### Backend Work
- Handler mutasi `harsus_inspection` pada Edge Function `powersync-write`.
- Logika server-side pembuatan P0 otomatis jika `perlu_p0 = true` dengan penurunan posko penanggung jawab dari seksi aset.

### Android Work
- UI baseline khusus peran HARSUS: daftar/akses inspeksi dalam authorized scope, form inspeksi teknis dengan radio button kebutuhan pemadaman, capture foto temuan anomali, serta hasil/history inspeksi.

### Admin Web Work
- Halaman monitoring inspeksi Harsus: filter aset kondisi kritis dan ringkasan rekomendasi penanganan P0.

### Offline / Sync Work
- Uji pengisian inspeksi aset di area blank spot; pengiriman batch data inspeksi beserta foto temuan saat koneksi internet tersedia.

### Security / Authorization
- Akses dibatasi: hanya pengguna dengan `app_role = 'HARSUS'` yang memiliki izin mencatat entitas `harsus_inspection`.

### Test Data
- Synthetic accounts untuk development tanpa menetapkan arti akun (apakah tim atau individu).

### Acceptance Criteria
- [ ] Pengguna role POSKO diblokir jika mencoba mengakses atau membuat form inspeksi Harsus.
- [ ] Inspeksi dengan `perlu_p0 = true` secara konsisten menerbitkan tiket P0 baru berstatus `BARU` untuk Posko wilayah kerja terkait.
- [ ] Nilai `kebutuhan_padam` tersimpan valid dan terbaca jelas pada tiket P0 turunan.

### Exit Gate
- Modul inspeksi Harsus tervalidasi mandiri dan integrasi otomatis ke antrian P0 berfungsi sempurna.

### Risks / Open Questions
- **OQ-ACCOUNT-01:** Model akun HARSUS (representasi tim wilayah vs individu) (Open Question).
- **OQ-HARSUS-SCOPE:** Batas dan cakupan data offline HARSUS (Gunakan synthetic minimal test scope untuk membuktikan mekanisme partitioning; exact production partition rule belum dikunci).
- **OQ-HARSUS-01:** Penanganan pencatatan temuan inspeksi pada titik/aset baru yang belum ada di master data (Open Question).

---

## Phase 9 — ROW / Vegetasi

### Objective
Membangun modul pencatatan pemeliharaan ruang bebas vegetasi (ROW / Vegetasi): eksekusi penebangan/pemangkasan terjadwal oleh Tim ROW maupun insidental oleh Posko, integrasi histori vegetasi, dan pelaporan volume pohon.

### Dependencies
- Phase 4, 5, dan 7 selesai.
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 4.3.9, 4.3.10, 4.1.8).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 13).

### Scope
- Pencatatan pekerjaan ROW (`row_work`): pemilihan seksi kerja, referensi titik vegetasi terdaftar (`vegetasi_id`), jenis pekerjaan (`RABAS`, `PANGKAS`, `TEBANG`), dan tanggal eksekusi.
- Penanganan atribut `volume_pohon`: berstatus nullable/opsional (jika terisi wajib integer > 0).
- Pembaruan riwayat pemeliharaan pada tabel `vegetasi` menggunakan field yang ada di `DATA_MODEL` (`tanggal_row_terakhir`, `next_row_date`, serta kondisi/tanggal kondisi jika relevan). Formula `next_row_date` tetap Open Question.
- Dukungan personil regu kerja melalui junction table `row_work_petugas`.
- Dokumentasi foto bukti pemangkasan mengikuti mandatory photo matrix Open Question.
- Keterkaitan sumber pekerjaan: pekerjaan rutin mandiri (`is_incidental = false`) atau bersumber dari patroli PASILA (`is_incidental = true`, `source_action_id` terisi).

### Out of Scope
- Algoritma prediksi laju pertumbuhan tanaman (*growth rate AI*).
- Fitur pembuatan titik vegetasi baru di lapangan (tetap gated/Open Question sampai hak akses pembuatan titik baru diputuskan).

### Backend Work
- Handler mutasi `row_work` dan `row_work_petugas` pada `powersync-write`.
- Trigger pembaruan kolom `tanggal_row_terakhir` pada tabel master `vegetasi`.

### Android Work
- UI khusus peran ROW: Daftar Titik Rawan Pohon, Form Eksekusi Pekerjaan ROW, dan dialog personil pelaksana.
- Fitur pencatatan ROW insidental dari alur patroli Posko.

### Admin Web Work
- Peta sebaran titik vegetasi dan pemantauan realisasi volume perabasan per seksi/penyulang.

### Offline / Sync Work
- Uji pencatatan pekerjaan ROW di area lapangan tanpa konektivitas; sinkronisasi data beserta foto saat regu kembali ke posko.

### Security / Authorization
- Hak akses: peran `ROW` dapat mencatat pekerjaan vegetasi dalam authorized scope yang berlaku. Exact territory/data scope tetap OPEN QUESTION; peran `POSKO` hanya dapat mencatat pekerjaan insidental yang terikat pada aksi PASILA.

### Test Data
- 20 Titik pohon rawan vegetasi mock di jalur Penyulang Sribhawono.

### Acceptance Criteria
- [ ] Sistem memvalidasi input `jenis_pekerjaan` hanya menerima nilai `'RABAS'`, `'PANGKAS'`, atau `'TEBANG'`.
- [ ] Pekerjaan ROW berhasil memperbarui `tanggal_row_terakhir` pada entitas master `vegetasi`.
- [ ] Hubungan antara temuan PASILA dan rekam jejak pekerjaan ROW tersambung utuh via `source_action_id`.

### Exit Gate
- Modul ROW / Vegetasi tervalidasi fungsional dan terintegrasi dengan data master vegetasi.

### Risks / Open Questions
- **OQ-ROW-01:** Formula kalkulasi dan satuan waktu untuk penjadwalan `next_row_date` (Open Question).
- **OQ-API-04:** Hak pembuatan master titik vegetasi baru oleh pengguna ROW melalui mobile (`create-new-vegetation`). Untuk development: fitur create-new-vegetation tetap disabled/gated, gunakan existing synthetic vegetation master, dan jangan menggunakan workaround memilih titik terdekat atau menyimpan titik baru sebagai catatan seolah itu aturan bisnis. Keputusan final dibutuhkan sebelum workflow ROW production dikunci.
- **OQ-ACCOUNT-01:** Model akun ROW (representasi tim wilayah vs individu) (Open Question).
- **OQ-ROW-SCOPE:** Batas dan cakupan data offline ROW (Gunakan synthetic minimal test scope untuk membuktikan mekanisme partitioning; exact production partition rule belum dikunci).

---

## Phase 10 — Maps & Asset History View

### Objective
Membangun visualisasi spasial peta jaringan distribusi 20 kV berbasis Google Maps di mobile dan web, penandaan marker geografis aset/gangguan/vegetasi, serta tampilan rekam jejak riwayat pemeliharaan aset (*Asset 360 View*).

### Dependencies
- Phase 2, 4, 6, 7, 8, dan 9 selesai.
- [DESIGN_BRIEF.md](./DESIGN_BRIEF.md) (Bagian 7).
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 4.4.2).

### Scope
- Layer Peta Spasial Google Maps: visualisasi titik tiang, gardu distribusi, titik rawan pohon, dan indikator riwayat trip gangguan.
- Filter layer interaktif: filter berdasarkan penyulang, seksi, status APKUK, dan tingkat keparahan anomali.
- Tampilan Detail Aset Komprehensif (Asset 360 View): menyajikan rekam jejak dari seluruh sumber yang didefinisikan dalam `DATA_MODEL`:
  - riwayat gangguan
  - P0
  - WO
  - PASILA action
  - Harsus
  - related ROW
  - APKUK
  - documentation (jika relevan)
- Fitur navigasi eksternal: tombol *Open in Google Maps* dengan koordinat presisi aset untuk memandu petugas menuju lokasi tiang/gardu di lapangan.

### Out of Scope
- Jaminan ketersediaan peta dasar (*tile base map*) Google Maps secara offline total (Google base map NOT guaranteed offline).
- Jaminan seluruh history ULP tersedia offline total (mobile offline hanya menjamin authorized/cached history subset yang disinkronkan ke device).

### Backend Work
- Pembuatan view komprehensif `asset_history_view` menggabungkan riwayat P0, WO, inspeksi Harsus, PASILA action, ROW, APKUK, dan gangguan terkait.

### Android Work
- Integrasi SDK Google Maps Flutter di aplikasi mobile.
- Render marker aset lokal dari tabel SQLite lokal via PowerSync.
- Bottom sheet rincian aset saat marker disentuh dan tombol navigasi rute jalan.

### Admin Web Work
- Peta GIS interaktif skala penuh di dashboard web dengan kemampuan clustering marker dan panel samping riwayat aset.

### Offline / Sync Work
- Uji coba klik marker aset saat offline: data spesifikasi aset dan authorized cached history subset tetap dapat dibaca dari SQLite lokal.

### Security / Authorization
- API Key Google Maps diproteksi dengan pembatasan domain (Admin Web) dan package signature/SHA-1 (Android App).

### Test Data
- 50 Titik koordinat tiang/gardu sintetis di area ULP Sribhawono dengan histori pekerjaan buatan.

### Acceptance Criteria
- [ ] Peta berhasil menampilkan marker aset dengan warna pembeda sesuai status operasional dan anomali.
- [ ] Petugas di lapangan dapat menekan tombol navigasi dan membuka aplikasi Google Maps menuju koordinat tiang.
- [ ] Halaman riwayat aset menampilkan kronologi lengkap dari sumber-sumber yang terdefinisi pada `DATA_MODEL`.

### Exit Gate
- Visualisasi spasial dan riwayat aset berfungsi responsif di Android dan Web Admin.

---

## Phase 11 — Special Case Feeder Anomaly Engine

### Objective
Membangun mesin deteksi otomatis anomali penyulang sering trip (Special Case): kalkulasi ambang batas frekuensi trip (>3 kali dalam rentang evaluasi), penandaan status penyulang anomali, identifikasi kandidat aset terdampak, serta penerbitan terkontrol tiket P0 investigasi.

### Dependencies
- Phase 1, 2, dan 4 selesai.
- [DATA_MODEL.md](./DATA_MODEL.md) (Bagian 4.3.11, 4.3.1).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 9).

### Scope
- Logika kalkulasi trip berbasis data agregasi `gangguan_mv`.
- Parameter dinamis: `periode_awal`, `periode_akhir` (strategi periode = Open Question), dan threshold concept saat ini >3 trip ( configurable).
- Pembuatan record `special_case` yang mengaitkan `penyulang_id`, rentang tanggal evaluasi, dan jumlah akumulasi trip.
- Alur yang dikonfirmasi (*confirmed flow*):
  1. Deteksi penyulang terdampak (*detect affected penyulang*).
  2. Identifikasi / hubungkan kandidat aset terdampak (*identify/link affected asset candidates*) sesuai data yang tersedia.
  3. Setiap P0 tetap mentarget aset spesifik.
  4. Menerbitkan tiket P0 investigasi terarah (`source_type = 'SPECIAL_CASE'`, `source_ref_id = special_case.id`, `created_actor_type = 'SYSTEM_JOB'`).
  5. Pencegahan duplikasi P0: sistem **TIDAK MENCIPTAKAN** duplicate active P0 jika penyulang/aset terkait telah memiliki P0 investigasi aktif.

### Out of Scope
- Integrasi SCADA real-time otomatis (analisis dilakukan berbasis dataset gangguan yang telah dipentaskan di `gangguan_raw`).
- Menetapkan rule baru "aset yang paling sering terindikasi" tanpa acuan source of truth.

### Backend Work
- Scheduled Edge Function / PostgreSQL Stored Procedure `detect-special-cases`.
- Query deteksi frekuensi trip berdasarkan parameter `periode_awal` dan `periode_akhir`.
- Trigger database untuk membuat tiket P0 investigasi terikat pada aset target kandidat.

### Android Work
- Badge visual penanda status "Special Case" pada kartu tiket P0 di aplikasi Posko agar petugas memberikan atensi khusus.

### Admin Web Work
- Halaman Khusus Pemantauan Special Case: grafik tren trip penyulang bermasalah, daftar aset kandidat gangguan pada penyulang tersebut, dan status tiket investigasi.

### Offline / Sync Work
- Tiket P0 bersumber Special Case tersinkronisasi otomatis ke Posko pelaksana melalui mekanisme sync normal.

### Security / Authorization
- Seluruh mutasi Special Case dicatat dengan kredensial sistem dan terikat pada jejak audit `SYSTEM_JOB`.

### Test Data
- Data sintetis 15 catatan gangguan trip pada satu penyulang tertentu dalam rentang 14 hari untuk memicu kondisi Special Case.

### Acceptance Criteria
- [ ] Sistem otomatis mendeteksi penyulang dengan jumlah trip > ambang batas konfigurasi `app_config`.
- [ ] Sistem secara otomatis menerbitkan tiket P0 investigasi dengan penandaan `source_type = 'SPECIAL_CASE'`.
- [ ] Sistem menolak menerbitkan tiket P0 ganda jika tiket investigasi sebelumnya masih berstatus aktif (`BARU`, `DITERIMA`, atau `DIKERJAKAN`).

### Exit Gate
- Mesin deteksi Special Case terbukti akurat dan tidak menghasilkan anomali duplikasi tiket.

### Risks / Open Questions
- **OQ-SPECIALCASE-01:** Strategi dan rentang periode evaluasi Special Case (`periode_awal` s.d. `periode_akhir`) (Open Question).

---

## Phase 12 — Offline & Sync Hardening

### Objective
Melakukan pengujian ketahanan dan pengerasan sistematis (*systematic hardening*) terhadap seluruh perilaku offline-first, sinkronisasi PowerSync, dan pipeline pengunggahan foto di bawah kondisi jaringan ekstrem dan degradasi perangkat.

### Dependencies
- Phase 3 sampai Phase 11 selesai.
- [OFFLINE_SYNC_SPEC.md](./OFFLINE_SYNC_SPEC.md) (Seluruh Dokumen).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 21, 22).

### Scope
- Uji simulasi konektivitas terputus-putus (*intermittent network flaps / high packet loss*).
- Pengujian antrian mutasi offline berukuran besar (>50 mutasi tertahan di SQLite lokal).
- Pengujian *device kill & restart* saat mutasi sedang dalam proses pengiriman jaringan.
- Pengujian integritas urutan dependensi (*dependency ordering*): memastikan record sesi terkirim sebelum record aksi, dan record aksi terkirim sebelum upload foto konfirmasi.
- Pengujian penanganan kondisi saat token/session kedaluwarsa ketika perangkat sedang offline, diikuti penyegaran transparan (*silent token refresh*) saat koneksi pulih.
- Uji skenario multi-perangkat (*concurrent multi-device updates*) untuk memverifikasi ketahanan Optimistic Concurrency Control (OCC) dan mekanisme eskalasi konflik HTTP 409.
- Handling Konflik UX: Client wajib surface conflict dan tidak silently discard local update. Exact merge/retry/escalation UX tetap Open Question. Panel Admin OCC conflict queue hanya dibangun jika OQ resolusi konflik memutuskan bahwa queue tersebut diperlukan.
- Pengujian tekanan media penyimpanan lokal (*storage pressure & low disk space*).

### Out of Scope
- Perubahan arsitektur skema data (hanya pengerasan logika handling dan retry).
- Menjanjikan impossibility of data loss pada seluruh kondisi hardware/OS.

### Backend Work
- Optimasi indexing database untuk mempercepat evaluasi batch `powersync-write`.
- Penyempurnaan pesan kesalahan terstruktur pada endpoint Edge Functions saat terjadi kegagalan parsial batch.

### Android Work
- Implementasi algoritma Exponential Backoff with Jitter pada antrian pengiriman mutasi dan upload foto.
- Komponen *Conflict Resolution Dialog* di Android yang menampilkan penolakan versi server kepada pengguna tanpa menghapus pembaruan lokal secara diam-diam.
- Pembersihan berkas foto lokal mengikuti *configurable local retention policy* setelah status `uploaded_at` terkonfirmasi.

### Admin Web Work
- Panel monitoring antrian konflik data (*OCC conflict queue monitoring*) jika OQ resolusi konflik menetapkan bahwa modul tersebut dibutuhkan.

### Offline / Sync Work
- Uji ketahanan replikasi PowerSync saat server backend disimulasikan mengalami restart atau *maintenance window*.

### Security / Authorization
- Verifikasi bahwa perangkat yang dipaksa offline tidak dapat memanipulasi atribut waktu server atau bypass otorisasi role saat sinkronisasi pulih.

### Test Data
- Skenario pengujian stres: 100 transaksi campuran (P0, WO, PASILA, ROW) yang dibuat dalam mode offline simultan pada 3 perangkat Android berbeda.

### Acceptance Criteria
- [ ] Tidak ada kehilangan transaksi pada test scenarios yang didefinisikan, dan pending operation tetap recoverable setelah app restart/crash.
- [ ] Antrian mutasi offline yang menumpuk berhasil diproses berurutan sesuai dependensi hierarki saat koneksi internet pulih.
- [ ] Konflik OCC (HTTP 409) disajikan ke client secara transparan (*surfaced*) tanpa silent discard atau crash aplikasi.
- [ ] File foto lokal dikelola sesuai *configurable local retention policy* setelah verifikasi S3 berhasil.

### Exit Gate
- Sistem lulus seluruh skenario pengujian ketahanan offline dan batas konkurensi data.

---

## Phase 13 — Admin Web Operational Dashboard

### Objective
Menyelesaikan antarmuka dasbor operasional berbasis web untuk Administrator ULP: pemantauan terpadu seluruh pekerjaan preventif aktif, manajemen permohonan koreksi data, pengelolaan master data ter-allowlist, konfigurasi dinamis aplikasi, serta audit log sistem.

### Dependencies
- Phase 4 sampai Phase 11 selesai.
- [DESIGN_BRIEF.md](./DESIGN_BRIEF.md) (Bagian 8).
- [API_CONTRACT.md](./API_CONTRACT.md) (Bagian 14, 20).

### Scope
- Dashboard Operasional Utama: metrik ringkasan harian (P0 aktif, WO shift berjalan, sesi PASILA terbuka, target APKUK terpasang).
- Modul Manajemen Koreksi Data (`correction_request`): antarmuka bagi Admin untuk meninjau, menyetujui, atau menolak permohonan koreksi dari Posko secara atomik.
- Modul Master Data Manager (Allowlisted Handlers): antarmuka aman untuk menambah/mengubah data aset, batas seksi wilayah, gardu, dan personil petugas lapangan.
- Modul Konfigurasi Aplikasi (`app_config`): pengeditan parameter operasional (ambang batas Special Case, jam shift dinamis, parameter kompresi foto).
- Modul Audit & History Viewer: penelusuran rekam jejak `audit_log` dan `status_history`.

### Out of Scope
- **Fitur Ranking Posko (Dikeluarkan dari MVP sesuai PRD).**

### Backend Work
- RPC function `process_correction_approval` untuk mengeksekusi perubahan data transaksi pasca-persetujuan Admin dengan kenaikan versi record dan pencatatan audit.

### Android Work
- Form pengajuan tiket koreksi data pada pekerjaan yang telah berstatus `SELESAI` di aplikasi mobile Posko.

### Admin Web Work
- Pembangunan UI Next.js + TypeScript responsif mengikuti arahan [DESIGN_BRIEF.md](./DESIGN_BRIEF.md):
  - Layout navigasi Admin (Sidebar, Header).
  - Data table interaktif dengan pagination, sorting, dan filter status.
  - Modal review koreksi data dengan visualisasi perbandingan *diff* data lama vs data baru.

### Offline / Sync Work
- Nihil (Admin Web beroperasi dalam mode online-first).

### Security / Authorization
- Proteksi mutlak: seluruh endpoint admin dan dashboard hanya dapat diakses oleh pengguna dengan klaim `app_role = 'ADMIN'`.
- Sanitasi input ketat pada form master data (mencegah arbitrary table injection).

### Test Data
- 5 Permohonan koreksi data sintetis untuk pengujian alur persetujuan Admin.

### Acceptance Criteria
- [ ] Admin dapat menyetujui permohonan koreksi data dan perubahan langsung tercermin pada transaksi target beserta jejak auditnya.
- [ ] Pengelolaan master data berfungsi melalui handler yang ter-allowlist tanpa proxy SQL sembarang.
- [ ] Parameter pada `app_config` dapat diubah dan langsung memengaruhi perilaku sistem di backend dan mobile.
- [ ] Dasbor operasional menampilkan status pekerjaan lapangan secara real-time.

### Exit Gate
- Seluruh antarmuka Admin Web berfungsi penuh, aman, dan siap digunakan oleh stakeholder manajemen ULP.

---

## Phase 14 — Integration, Testing & Security Hardening

### Objective
Melakukan pengujian komprehensif menyeluruh: pengujian integrasi antar modul, pengujian kepatuhan keamanan dan RLS, verifikasi batasan isolasi data posko, serta pemindaian kerentanan sebelum sistem dipersiapkan untuk rilis.

### Dependencies
- Phase 0 sampai Phase 13 selesai.
- Seluruh dokumen acuan proyek.

### Scope
- **End-to-End Automated Testing:** Uji alur lengkap dari pembuatan tiket di web $\rightarrow$ eksekusi mobile offline $\rightarrow$ upload foto S3 $\rightarrow$ verifikasi dashboard.
- **RLS & Security Audit:** Pengujian penetrasi internal pada layer database:
  - Uji kebocoran data antar posko (Cross-Posko data tampering).
  - Uji bypass klaim JWT dan manipulasi parameter URL.
  - Uji manipulasi objek S3 (*arbitrary key injection*).
- **Stress & Load Testing:** Simulasi beban puncak (lonjakan upload foto dan sinkronisasi batch saat jam pergantian shift).
- **Code Audit & Secret Leakage Check:** Pemindaian repositori final untuk memastikan tidak ada kredensial rahasia, data mentah privat PLN, atau dependensi rentan.

### Out of Scope
- UAT stakeholder akhir (dilakukan pada Phase 15).

### Backend Work
- Penyempurnaan indeks database dan query tuning berdasarkan hasil uji performa.
- Penguncian RLS policies final.

### Android Work
- Pengujian pada varian versi Android sesuai minimum supported Android API yang telah dikonfirmasi dan testing matrix yang ditentukan.
- Optimasi konsumsi baterai dan efisiensi memori background services.

### Admin Web Work
- Uji kompatibilitas browser modern (Chrome, Edge, Firefox, Safari) dan optimasi Core Web Vitals.

### Offline / Sync Work
- Uji integritas sinkronisasi saat database server mengalami *high write contention*.

### Security / Authorization
- Audit menyeluruh hak akses database role: memastikan peran anon dan authenticated tidak memiliki akses modifikasi langsung ke tabel transaksi tanpa melalui RLS/RPC.

### Test Data
- Dataset pengujian beban: 1.000 tiket transaksi operasional dan 500 berkas gambar uji coba.

### Acceptance Criteria
- [ ] Seluruh unit test, widget test, dan integration test lulus dengan tingkat keberhasilan 100%.
- [ ] Uji keamanan membuktikan tidak ada celah pembacaan data lintas posko yang tidak berwenang.
- [ ] Sistem mampu menangani beban pergantian shift serentak tanpa terjadi kendala timeout atau kebocoran memori.

### Exit Gate
- Seluruh metrik pengujian memenuhi standar kualitas dan keamanan proyek; bebas dari temuan kerentanan berisiko tinggi.

---

## Phase 15 — Deployment Readiness & Production Gate

### Objective
Mempersiapkan infrastruktur staging dan produksi, melakukan verifikasi prosedur pemulihan bencana (*Disaster Recovery & Backup*), memastikan perizinan tata kelola data cloud resmi telah terpenuhi, serta mengeksekusi *Go-Live Checklist*.

### Dependencies
- Phase 14 selesai.
- [TECHNICAL_SPEC.md](./TECHNICAL_SPEC.md) (Bagian 7, 8).
- **Persetujuan Tata Kelola Cloud & Data Riil PLN (Gate Mutlak).**

### Scope
- Konfigurasi environment Staging dan Production terpisah secara fisik.
- Verifikasi S3 Versioning pada bucket evidence staging dan produksi.
- Evaluasi kapabilitas backup/restore Supabase plan yang dipilih (uji PITR jika fitur tersedia/diaktifkan; jika tidak tersedia, dokumentasikan dan uji prosedur backup/restore yang disetujui).
- Konfigurasi logging terpusat dan pelaporan error Sentry di lingkungan produksi.
- Rotasi seluruh kredensial dan API keys sebelum peluncuran resmi.
- Verifikasi migrasi data awal produksi (setelah izin tata kelola disahkan).
- Rilis build rilis Android APK / AAB yang ditandatangani dengan production keystore resmi.

### Out of Scope
- Migrasi data riil tanpa izin resmi stakeholder PLN.

### Backend Work
- Penerapan migrasi skema final pada Supabase Production Instance.
- Konfigurasi backup terjadwal dan verifikasi status *readiness* replikasi.

### Android Work
- Pembuatan build release Android bertanda tangan digital resmi (*Signed Release Bundle*).

### Admin Web Work
- Deployment build produksi Next.js pada platform hosting target (Vercel Production) dengan kustom domain PLN jika ditentukan.

### Offline / Sync Work
- Verifikasi performa instance PowerSync produksi terhubung ke database utama.

### Security / Authorization
- **GERBANG TATA KELOLA TINGKAT TINGGI (High-Level Governance Gate):**  
  Sistem **TIDAK DIIZINKAN GO-LIVE** ke lingkungan produksi menggunakan data riil sebelum mendapatkan persetujuan resmi dari otoritas keamanan TI PLN terkait penggunaan infrastruktur cloud pihak ketiga (Supabase, PowerSync, Amazon S3).

### Test Data
- Verifikasi lingkungan staging menggunakan dataset sintetis teranonymisasi penuh.

### Acceptance Criteria
- [ ] Prosedur pemulihan bencana (restore database dan un-delete objek S3 via versioning) berhasil diuji di lingkungan staging.
- [ ] Persetujuan formal tata kelola data cloud telah didokumentasikan dan diarsipkan.
- [ ] Seluruh kredensial produksi telah dirotasi dan diamankan.
- [ ] Aplikasi Android rilis final berhasil terpasang dan beroperasi sempurna pada perangkat tim lapangan PLN ULP Sribhawono.

### Exit Gate
- Sistem secara resmi dinyatakan siap untuk peluncuran operasional (*Go-Live Ready*).

---

# 6. Milestones

Pencapaian proyek dikelompokkan ke dalam milestone utama berbasis verifikasi fungsional (*functional verification milestones*), tanpa mengasumsikan durasi kalender buatan:

| Milestone Code | Nama Milestone | Capaian Kunci yang Terverifikasi |
|---|---|---|
| **M0** | **Foundation Ready** | Repositori terstandarisasi, CI aktif, database foundation, custom JWT claim auth (`ADMIN`, `POSKO`, `HARSUS`, `ROW`), dan penegakan RLS dasar terpasang (Phase 0 & 1). |
| **M1** | **P0 Vertical Slice Proven** | Master data 6 Posko terisi, shell offline mobile siap, tiket P0 manual berhasil diterbitkan di web, diselesaikan offline di mobile, dan tersinkronisasi sempurna dengan audit utuh (Phase 2, 3, & 4). |
| **M2** | **Core Field Workflow Complete** | Pipeline foto S3 aktif, seluruh modul pemeliharaan preventif lapangan (Work Order APKUK, Patroli PASILA, Inspeksi Harsus, dan Pemotongan ROW) selesai dibangun dan saling terhubung (Phase 5, 6, 7, 8, & 9). |
| **M3** | **Intelligence & Dashboard Ready** | Visualisasi spasial Google Maps aktif, riwayat aset terintegrasi, mesin deteksi Special Case berjalan, dan dasbor operasional Admin Web selesai (Phase 10, 11, & 13). |
| **M4** | **Hardened MVP Release Candidate** | Sistem lulus pengujian ketahanan offline ekstrem, audit keamanan tuntas, simulasi disaster recovery sukses, dan seluruh gerbang tata kelola data cloud terpenuhi untuk rilis produksi (Phase 12, 14, & 15). |

---

# 7. Global Test Matrix

Matriks pengujian berikut merangkum cakupan pengujian verifikasi sistem lintas peran, fitur, kondisi jaringan, dan aspek keamanan. Modul P0 menerima cakupan pengujian terdalam sebagai irisan vertikal bisnis pertama:

| Fitur / Modul | Target Peran | Online Flow | Offline Flow | Sync Queue | Authorization & Scope | Concurrency (OCC) | Photo Pipeline | Kedalaman Uji |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|---|
| **P0 (Penugasan Khusus)** | ADMIN, POSKO | Ya | Ya | Ya | Ya (Isolasi Posko) | Ya (Wajib HTTP 409) | Ya (Metadata & Upload) | **Maksimum (Core Vertical Slice)** |
| **Work Order (APKUK)** | ADMIN, POSKO | Ya | Ya | Ya | Ya (Isolasi Posko) | Ya (Wajib HTTP 409) | Ya (Bukti Terpasang) | Tinggi |
| **PASILA (Patroli)** | POSKO | Ya | Ya | Ya | Ya (Batas Seksi) | Ya (State Machine) | Ya (Aksi & Temuan) | Tinggi |
| **Harsus (Inspeksi)** | HARSUS | Ya | Ya | Ya | Ya (Peran Khusus) | — (Append-Only) | Ya (Temuan Rusak) | Tinggi |
| **ROW (Vegetasi)** | ROW, POSKO | Ya | Ya | Ya | Ya (Rutin vs Insidental) | — (Append-Only) | Ya (Bukti Pemangkasan)| Menengah |
| **Master Data Manager** | ADMIN | Ya | — | — | Ya (Admin Only) | Ya (Database Constraint)| — | Menengah |
| **Koreksi Data** | POSKO, ADMIN | Ya | Ya (Antrian) | Ya | Ya (Admin Approval) | Ya (Kenaikan Versi) | — | Tinggi |
| **Maps & Asset History** | ALL ROLES | Ya | Ya (Marker) | — | Ya (Wilayah Posko) | — | — | Menengah |
| **Special Case Engine** | SYSTEM, ADMIN | Ya | — | Ya | Ya (System Actor) | Ya (Anti Duplikasi) | — | Tinggi |

---

# 8. Global Definition of Done (DoD)

Sebuah fitur atau modul **TIDAK DAPAT** dinyatakan selesai (*Done*) hanya karena antarmuka visual (UI) telah tampil di layar. Untuk seluruh fitur yang relevan, status *Done* mensyaratkan pemenuhan checklist ketat berikut:

1. **Database & Schema:**
   - Tabel, relasi foreign key, indeks, dan CHECK constraints terpasang melalui migrasi SQL sekuensial yang dapat di-reproduksi ulang secara otomatis.
2. **Authorization & Security:**
   - Aturan Row Level Security (RLS) terpasang dan diverifikasi; tidak ada celah akses lintas posko atau kebocoran peran.
3. **API & Controlled Mutation:**
   - Mutasi data melalui handler Edge Function / RPC dengan allowlist atribut yang ketat; menolak arbitrary SQL/table injection.
4. **User Interface (Mobile & Web):**
   - UI responsif, ergonomis, bebas glitch visual, dan mengikuti standar pada [DESIGN_BRIEF.md](./DESIGN_BRIEF.md).
5. **Offline & Sync Behavior (Field-Facing):**
   - Transaksi dapat dibuat, diubah, dan disimpan secara persisten di SQLite lokal saat offline tanpa terjadi crash.
   - Sinkronisasi otomatis berjalan mulus saat koneksi internet kembali pulih tanpa duplikasi data (idempotent).
6. **Auditability & Traceability:**
   - Perubahan status tercatat di `status_history`; mutasi sensitif tercatat di `audit_log`; aktor pembuat tercatat benar (`USER` vs `SYSTEM_JOB`).
7. **Error Handling & Feedback:**
   - Penanganan error terstruktur sesuai matriks [API_CONTRACT.md](./API_CONTRACT.md) dengan panduan pesan yang ramah pengguna.
8. **Automated Testing:**
   - Unit test dan integration test untuk skenario normal (*happy path*) dan skenario gagal (*edge cases*) lulus 100%.
9. **Documentation Consistency:**
   - Implementasi terbukti selaras dengan seluruh dokumen acuan tanpa kontradiction istilah atau pemalsuan aturan bisnis.

---

# 9. Open Question Gates

Tabel berikut memetakan keputusan teknis dan bisnis yang masih berstatus terbuka (*unresolved decisions*), dampaknya terhadap fase implementasi, serta strategi kelanjutan pengembangannya:

| Kode Referensi | Deskripsi Keputusan Terbuka (Open Question) | Memblokir Fase? | Dapat Dilanjutkan dengan Placeholder/Mock? | Keputusan Definitif Dibutuhkan Sebelum Titik Mana? |
|---|---|---|---|---|
| **OQ-WO-01** | Formula / pembobotan prioritas Work Order. | Tidak memblokir Phase 6. | **Ya.** Gunakan deterministic synthetic test fixture / placeholder scoring configuration (NON-PRODUCTION). | Sebelum Phase 14 (UAT Pemeliharaan). |
| **OQ-WO-02** | Keberlakuan target 6 WO ketika dalam shift terdapat beban P0 tinggi. | Tidak memblokir Phase 6. | **Ya.** Gunakan skenario uji target 6 saat tidak ada P0 yang mengurangi target dan asset eligible cukup. | Sebelum Phase 14 (UAT Pemeliharaan). |
| **OQ-WO-03** | Jenis pekerjaan manual WO selain `PEMASANGAN_APKUK`. | Tidak memblokir Phase 6. | **Ya.** Batasi development manual WO hanya pada `PEMASANGAN_APKUK`. | Sebelum Phase 13 (Admin Web Finalization). |
| **OQ-PASILA-01** | Kriteria pemilihan target seksi/penyulang PASILA dan periode trip yang digunakan saat tidak ada P0. | Tidak memblokir Phase 7. | **Ya.** Gunakan seleksi seksi manual/mock pada development. | Sebelum Phase 13 (Admin Web Finalization). |
| **OQ-SHIFT-01** | Jam operasional dan waktu cutoff 3 shift operasional. | Tidak memblokir Phase 1–13. | **Ya.** Gunakan konfigurasi jam shift sintetis pada `app_config`. | Sebelum Phase 14 (Integration Testing). |
| **OQ-SPECIALCASE-01** | Strategi dan rentang periode evaluasi Special Case (`periode_awal` s.d. `periode_akhir`). | Tidak memblokir Phase 11. | **Ya.** Gunakan konfigurasi threshold >3 trip dan rentang tanggal configurable (NON-PRODUCTION). | Sebelum Phase 14 (Integration Testing). |
| **OQ-ROW-01** | Formula kalkulasi dan satuan waktu untuk penjadwalan `next_row_date`. | Tidak memblokir Phase 9. | **Ya.** Biarkan field `next_row_date` opsional/mock pada development. | Sebelum Phase 13 (Admin Web Finalization). |
| **OQ-GANGGUAN-01** | Metode integrasi dan pemetaan data gangguan pusat (sifat gangguan, `is_trip`). | Tidak memblokir Phase 2. | **Ya.** Gunakan derivasi provisional pada dataset `gangguan_raw` sintetis. | Sebelum Phase 14 (Integration Testing). |
| **OQ-PHOTO-01** | Matriks kelengkapan foto wajib (kebutuhan foto per jenis tugas). | Tidak memblokir Phase 5. | **Ya.** Simpan aturan kelengkapan foto sebagai parameter dinamis di `app_config`. | Sebelum Phase 14 (Security & Integration Test). |
| **OQ-HARSUS-01** | Penanganan pencatatan temuan inspeksi pada titik/aset baru yang belum ada di master data. | Tidak memblokir Phase 8. | **Ya.** Batasi inspeksi Harsus pada master aset terdaftar dalam authorized scope. | Sebelum Phase 13 (Admin Web Finalization). |
| **OQ-ACCOUNT-01** | Model akun HARSUS dan ROW (representasi tim wilayah vs individu). | Tidak memblokir Phase 1–9. | **Ya.** Gunakan synthetic accounts untuk development tanpa menetapkan arti akun. | Sebelum Phase 12 (Sync Hardening & Multi-Device). |
| **OQ-API-04** | Hak pembuatan master titik vegetasi baru oleh pengguna ROW melalui mobile (`create-new-vegetation`). | Tidak memblokir Phase 9. | **Ya.** Untuk development: fitur create-new-vegetation tetap disabled/gated, gunakan existing synthetic vegetation master, dan jangan menggunakan workaround memilih titik terdekat atau menyimpan titik baru sebagai catatan seolah itu aturan bisnis. | Sebelum workflow ROW production dikunci. |
| **OQ-HARSUS-SCOPE** | Batas dan cakupan data offline untuk akun HARSUS. | Tidak memblokir Phase 8. | **Ya.** Gunakan synthetic minimal test scope untuk membuktikan mekanisme partitioning. Exact production partition rule tidak dikunci sampai scope HARSUS/ROW dikonfirmasi. | Sebelum Phase 12 (Sync Hardening). |
| **OQ-ROW-SCOPE** | Batas dan cakupan data offline untuk akun ROW. | Tidak memblokir Phase 9. | **Ya.** Gunakan synthetic minimal test scope untuk membuktikan mekanisme partitioning. Exact production partition rule tidak dikunci sampai scope HARSUS/ROW dikonfirmasi. | Sebelum Phase 12 (Sync Hardening). |
| **OQ-DEVICE-LIMIT** | Jumlah maksimum perangkat aktif yang diperbolehkan per akun pengguna. | Tidak memblokir Phase 1–12. | **Ya.** Izinkan multi-device login tanpa pembatasan kuota pada development. | Sebelum Phase 14 (Security Hardening). |
| **OQ-ANDROID-MIN** | Minimum supported Android API version (ditentukan setelah inventaris perangkat lapangan). | Tidak memblokir Phase 0–13. | **Ya.** Gunakan versi API stabil yang sesuai untuk kompilasi dan testing awal. | Sebelum Phase 14 (Device Testing Matrix). |
| **OQ-ANDROID-DIST** | Metode distribusi aplikasi Android ke perangkat tim lapangan (MDM, APK direct, Internal App Sharing, dll.). | Tidak memblokir Phase 0–13. | **Ya.** Gunakan build APK manual/direct installation pada development. | Sebelum Phase 15 (Deployment Readiness). |
| **OQ-AUTH-ID** | Identifier login Supabase Auth (Email riil vs Username abstraction). | Tidak memblokir Phase 1–13. | **Ya.** Gunakan format email sintetis terstandarisasi (`user@sribhawono.local`) pada development. | Sebelum Phase 14 (UAT & Staging Readiness). |
| **OQ-DEVICE-ID** | Mekanisme pengikatan identifikasi fisik perangkat hardware (`device_id`). | Tidak memblokir Phase 0–13. | **Ya.** Sertakan field dummy `device_id` pada blok metadata tanpa validasi server. | Sebelum Phase 14 (Security Hardening). |
| **OQ-SYNC-POLICY** | Kebijakan background sync Android (WorkManager interval, triggers & constraints). | Tidak memblokir Phase 3–11. | **Ya.** Prioritaskan sinkronisasi aktif saat aplikasi terbuka (*foreground session sync*). | Sebelum Phase 12 (Offline Hardening). |
| **OQ-CONFLICT-UX** | UX resolusi dan eskalasi konflik OCC (notifikasi mobile client vs perlunya Admin OCC conflict queue). | Tidak memblokir Phase 4–11. | **Ya.** Tampilkan notifikasi konflik di mobile client dan muat ulang versi server (surface conflict tanpa discard lokal). | Sebelum Phase 13 (Admin Web Dashboard). |
| **OQ-PHOTO-LIFECYCLE**| Kebijakan retensi, pembersihan file fisik lokal, dan pengarsipan foto di cloud. | Tidak memblokir Phase 5. | **Ya.** Terapkan configurable local retention policy setelah upload server terkonfirmasi. | Sebelum Phase 12 (Offline Hardening). |
| **OQ-API-VERSION** | Strategi API versioning definitif (URL path `/v1/` vs Header versioning). | Tidak memblokir Phase 1–13. | **Ya.** Gunakan proposed baseline URL path `/v1/` pada seluruh endpoint. | Sebelum Phase 14 (Integration Hardening). |
| **OQ-URL-EXPIRY** | Masa berlaku Pre-signed Upload URL Amazon S3. | Tidak memblokir Phase 5. | **Ya.** Gunakan default durasi yang disimpan secara dinamis pada `app_config`. | Sebelum Phase 12 (Sync Hardening). |
| **OQ-RATE-LIMIT** | Kebijakan kuota dan batasan rate limiting API. | Tidak memblokir Phase 0–11. | **Ya.** Kembangkan API tanpa rate-limit lokal; siapkan konfigurasi batas pada reverse proxy/gateway. | Sebelum Phase 14 (Penetration & Load Testing). |
| **OQ-CLOUD-GOV** | Izin tata kelola penggunaan cloud publik (Supabase, PowerSync, S3) untuk data operasional riil PLN. | **MEMBLOKIR Phase 15 (Go-Live).** | **Ya.** Seluruh pengembangan Phase 0–14 **WAJIB** menggunakan data sintetis/mock. | **WAJIB SEBELUM GO-LIVE PRODUKSI (Phase 15).** |
| **OQ-CLOUD-REGION** | Data residency dan pemilihan cloud region untuk infrastruktur Supabase, PowerSync, dan S3. | Tidak memblokir Phase 0–14. | **Ya.** Gunakan region default dev/staging yang tersedia pada provider. | Sebelum Phase 15 (Deployment Readiness). |
| **OQ-SUPABASE-PLAN** | Supabase Tier/Plan, ketersediaan fitur PITR, serta estimasi biaya operasional. | Tidak memblokir Phase 0–14. | **Ya.** Verifikasi mekanisme backup/restore standar pada plan yang digunakan. | Sebelum Phase 15 (Deployment Readiness). |
| **OQ-POWERSYNC-PLAN**| PowerSync Tier/Plan dan kapasitas koneksi sinkronisasi concurrent devices. | Tidak memblokir Phase 0–14. | **Ya.** Gunakan dev instance PowerSync pada development dan staging. | Sebelum Phase 15 (Deployment Readiness). |

---

# 10. Final Implementation Execution Sequence

Setelah dokumen rencana implementasi ini disetujui, Antigravity akan mengeksekusi pengembangan secara bertahap mengikuti urutan sekuensial berikut:

1. **Langkah 1: Setup Foundation (Phase 0)**
   - Inisialisasi struktur repositori, konfigurasi `.gitignore`, setup linter, proteksi rahasia, dan isolasi file data privat.
2. **Langkah 2: Backend Core & Security (Phase 1)**
   - Eksekusi migrasi Supabase: extensions, `app_config`, `user_profile`, auth hook custom JWT claims (`ADMIN`, `POSKO`, `HARSUS`, `ROW`), dan trigger audit.
3. **Langkah 3: Master Data & Staging Setup (Phase 2)**
   - Eksekusi migrasi master hierarki jaringan (6 Posko terkonfirmasi), staging `gangguan_raw` (24 kolom), dan view `gangguan_mv` dengan mock data sintetis.
4. **Langkah 4: Mobile Shell & PowerSync Replication (Phase 3)**
   - Setup Flutter app (minimum supported Android API ditentukan setelah inventaris perangkat), inisialisasi PowerSync local SQLite database, UI login, dan verifikasi replikasi master data offline.
5. **Langkah 5: P0 End-to-End Vertical Slice (Phase 4)**
   - Pembangunan form web pembuatan P0, Edge Function `powersync-write`, form eksekusi offline Android, junction petugas, dan verifikasi OCC audit.
6. **Langkah 6: Photo Evidence Pipeline (Phase 5)**
   - Integrasi kompresi kamera Android (configurable parameters), Edge Function pre-signed S3 URL, direct upload, dan verifikasi server.
7. **Langkah 7: Work Order Preventif APKUK (Phase 6)**
   - Mesin pembangkitan 6 WO/shift (konfigurasi target 6 dengan OQ beban P0), eksekusi lapangan, deterministic synthetic scoring (NON-PRODUCTION), dan pembaruan siklus hidup master APKUK.
8. **Langkah 8: Patroli Dinamis PASILA (Phase 7)**
   - Sesi patroli per seksi, eksekusi langsung, penautan insidental ROW, dan auto-generate P0 server-side (monitoring Web Admin mencakup session, section, action markers/coordinates, dan action history).
9. **Langkah 9: Tim Inspeksi Harsus (Phase 8)**
   - Form inspeksi teknis visual (UI baseline: akses inspeksi scope, form, history), validasi kebutuhan pemadaman, synthetic accounts, dan auto-generate P0 perbaikan.
10. **Langkah 10: Pemeliharaan Vegetasi ROW / Vegetasi (Phase 9)**
    - Form eksekusi perabasan/penebangan Tim ROW, rekam volume pohon, pembaruan `tanggal_row_terakhir` & `next_row_date`, dan histori vegetasi di area lapangan tanpa konektivitas.
11. **Langkah 11: Spasial Google Maps & Asset History (Phase 10)**
    - Integrasi Google Maps marker layer, filter penyulang/seksi, navigasi koordinat, dan Asset 360 view (sumber history lengkap dari DATA_MODEL, cached subset di mobile offline).
12. **Langkah 12: Anomali Special Case Feeder (Phase 11)**
    - Mesin deteksi trip penyulang anomali berbasis `periode_awal` dan `periode_akhir` (configurable threshold >3 trip), penandaan penyulang, dan tiket investigasi P0 terkontrol.
13. **Langkah 13: Offline & Sync Hardening (Phase 12)**
    - Pengujian ketahanan koneksi flap, queue overflow, device kill, penanganan token kedaluwarsa saat offline, dan surface conflict resolution.
14. **Langkah 14: Dasbor Operasional Web Admin (Phase 13)**
    - Penyelesaian overview operasional, modal review koreksi data, dan allowlisted master manager (Next.js + TypeScript selaras DESIGN_BRIEF).
15. **Langkah 15: Security Audit & Full Integration Testing (Phase 14)**
    - Pengujian RLS penetration, load test pergantian shift, testing matrix Android, dan verifikasi zero secret leaks.
16. **Langkah 16: Production Readiness & Governance Gate (Phase 15)**
    - Verifikasi backup/restore capability Supabase plan (PITR jika tersedia), S3 versioning restore, clearance tata kelola data cloud, dan rilis build produksi.

---

*Dokumen ini menjadi panduan eksekusi teknis resmi. Setiap fase wajib mematuhi batas arsitektur pada dokumen acuan dan dilarang mengubah aturan bisnis yang telah dikunci.*
