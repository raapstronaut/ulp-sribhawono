# TECHNICAL SPECIFICATION

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Draft  
**Source of Truth:** [PRD.md](file:///d:/PROJECT/ulp-sribhawono/docs/PRD.md) · [DESIGN_BRIEF.md](file:///d:/PROJECT/ulp-sribhawono/docs/DESIGN_BRIEF.md)  
**Scope:** ULP Sribhawono (single-tenant)  
**Platforms:** Android field app · Admin web application

---

# 1. Architecture Overview

```
┌──────────────────────────────────────────────────────────────────────┐
│                        CLIENTS                                       │
│                                                                      │
│  ┌─────────────────────┐          ┌─────────────────────────────┐   │
│  │   Android App        │          │   Admin Web (Next.js)       │   │
│  │   Flutter + SQLite   │          │   Vercel                    │   │
│  │   + PowerSync SDK    │          │   + Supabase JS Client      │   │
│  └─────────┬───────────┘          └──────────────┬──────────────┘   │
│            │                                      │                  │
└────────────┼──────────────────────────────────────┼──────────────────┘
             │                                      │
             ▼                                      ▼
┌──────────────────────────────────────────────────────────────────────┐
│                     SYNC & API LAYER                                 │
│                                                                      │
│  ┌─────────────────────┐          ┌─────────────────────────────┐   │
│  │   PowerSync Service  │◄────────►│   Supabase (PostgREST)      │   │
│  │   (Cloud-hosted)     │          │   + Supabase Auth            │   │
│  └─────────────────────┘          │   + Supabase Realtime        │   │
│                                    └──────────────┬──────────────┘   │
│                                                    │                  │
│  ┌─────────────────────────────────────────────────┘                  │
│  │                                                                    │
│  ▼                                                                    │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │   Supabase Edge Functions (Deno)                                │ │
│  │   - WO generation                                               │ │
│  │   - Special Case detection                                      │ │
│  │   - P0 auto-assignment                                          │ │
│  │   - Photo upload pre-signed URL generation                      │ │
│  │   - Secure PowerSync write handler                              │ │
│  └─────────────────────────────────────────────────────────────────┘ │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
             │
             ▼
┌──────────────────────────────────────────────────────────────────────┐
│                     DATA & STORAGE                                   │
│                                                                      │
│  ┌─────────────────────┐   ┌────────────────┐   ┌────────────────┐ │
│  │  PostgreSQL          │   │  Amazon S3      │   │  Amazon        │ │
│  │  (Supabase-managed)  │   │  Photo storage  │   │  CloudFront    │ │
│  │  + RLS policies      │   │                 │   │  CDN delivery  │ │
│  │  + pg_cron           │   └────────────────┘   └────────────────┘ │
│  │  + DB functions      │                                           │
│  └─────────────────────┘                                            │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
             │
             ▼
┌──────────────────────────────────────────────────────────────────────┐
│                     EXTERNAL SERVICES                                │
│                                                                      │
│  ┌─────────────────────┐   ┌────────────────┐   ┌────────────────┐ │
│  │  Google Maps         │   │  Sentry         │   │  GitHub        │ │
│  │  Platform            │   │  Error monitor  │   │  Source ctrl   │ │
│  └─────────────────────┘   └────────────────┘   └────────────────┘ │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

**Key architectural decisions:**

1. **Single-tenant.** ULP Sribhawono only. No multi-tenancy abstraction.
2. **Offline-first Android.** All field input operations must work without network. PowerSync + SQLite provide bidirectional sync.
3. **Online-primary Admin Web.** Admin web does not require offline capability (per DESIGN_BRIEF §8 — Admin Dashboard).
4. **Photos never in PostgreSQL.** All photos stored on Amazon S3, served via CloudFront. Only metadata/references stored in the database.
5. **Business logic in Edge Functions + PostgreSQL functions.** No separate backend server. Supabase acts as the BaaS layer.
6. **Business IDs preserved.** All existing PLN identifiers (POSKO_ID, SECTION_ID, ID_ASET, etc.) are retained as unique business keys alongside internal UUIDs.
7. **External data schema preserved.** Data gangguan pusat is imported into a raw/staging table that exactly mirrors the source schema.

---

# 2. Technology Selection Rationale

| Layer | Technology | Rationale |
|---|---|---|
| Android App | **Flutter** | Cross-platform framework but deployed Android-only for MVP. Strong offline-first ecosystem via PowerSync SDK. Single codebase for potential future platforms. Rich widget library suited for operational UIs (cards, forms, maps). |
| Admin Web | **Next.js + TypeScript** | Server-side rendering for SEO is irrelevant (internal tool), but Next.js provides excellent DX, static optimization, and mature ecosystem. TypeScript enforces type safety across the admin codebase. (Photo signing logic is centralized in Supabase Edge Functions, not duplicated in Next.js). |
| Primary Database | **PostgreSQL via Supabase** | Supabase provides managed PostgreSQL with built-in Auth, PostgREST auto-generated API, Realtime subscriptions, Row Level Security, `pg_cron` for scheduled jobs, and Edge Functions. Eliminates the need for a custom backend server while providing full SQL power for complex queries (WO generation, Special Case detection, asset history aggregation). |
| Auth | **Supabase Auth + RLS** | JWT-based authentication. RLS policies enforce data access at the database level, meaning even direct PostgREST access is safe. Custom claims in JWT carry `role` and `posko_id`. Login identifier (email vs username abstraction) is configurable — see §7. |
| Offline Sync | **PowerSync + SQLite** | PowerSync is purpose-built for offline-first apps with PostgreSQL backends. It provides bidirectional sync, conflict handling, and a local SQLite database that Flutter can query directly. This satisfies FR-OFF-01 through FR-OFF-07. |
| Photo Storage | **Amazon S3** | Object storage for potentially thousands of field photos. Direct upload from client via pre-signed URLs. No binary data in PostgreSQL. Cost-effective at scale. |
| CDN | **Amazon CloudFront** | Serves photos with low latency. Pairs natively with S3. Signed URLs for access control. |
| Maps | **Google Maps Platform** | Google Maps SDK for Flutter (Android) and Google Maps JavaScript API for web. Provides map rendering, marker clustering, and navigation intent launching. Well-supported in Indonesia. |
| Edge Functions | **Supabase Edge Functions (Deno)** | Serverless functions for business logic that should not run client-side (WO generation, Special Case detection, P0 assignment routing, secure PowerSync write handling). Cold starts are acceptable for batch/scheduled operations. |
| Admin Deployment | **Vercel** | Native Next.js deployment platform. Zero-config, automatic preview deployments per PR. Edge network for fast global delivery. |
| Error Monitoring | **Sentry** | SDKs for both Flutter and Next.js. Captures crashes, unhandled exceptions, performance traces, and breadcrumbs. Provides release tracking. |
| Source Control | **GitHub** | Repository hosting, pull request workflow, CI/CD via GitHub Actions. |

### Technologies explicitly NOT included

| Technology | Reason for exclusion |
|---|---|
| Firebase / Firestore | PowerSync + Supabase provides a more natural fit for relational data with complex queries (JOINs for WO generation, asset history). Firestore's document model would require denormalization that conflicts with the relational data model in the PRD. |
| Custom backend (Node/Go) | Supabase Edge Functions + PostgreSQL functions cover all server-side needs for MVP scope. A custom backend adds operational overhead without proportional benefit at this scale. |
| Redis | Not required for MVP. PostgreSQL handles all query loads for a single-ULP deployment. If caching is needed post-MVP, Supabase's built-in connection pooler (PgBouncer) is sufficient. |
| GraphQL | PostgREST's auto-generated REST API is sufficient. Admin web queries are CRUD-heavy, not graph-heavy. Adding GraphQL would introduce unnecessary complexity. |

---

# 3. Android Architecture

## 3.1 Architecture Pattern

**Clean Architecture with BLoC (Business Logic Component) pattern.**

```
┌─────────────────────────────────────────────────┐
│                 Presentation Layer               │
│   Screens (Widgets) ←→ BLoC / Cubit             │
│   Design System components from DESIGN_BRIEF     │
└──────────────────────┬──────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────┐
│                 Domain Layer                     │
│   Use Cases · Entities · Repository interfaces   │
└──────────────────────┬──────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────┐
│                 Data Layer                       │
│   Repository impls · Data Sources               │
│   ┌──────────────┐  ┌────────────────────────┐  │
│   │ Local Source  │  │ Remote Source           │  │
│   │ (PowerSync   │  │ (Supabase client +     │  │
│   │  SQLite DB)  │  │  S3 upload)            │  │
│   └──────────────┘  └────────────────────────┘  │
└─────────────────────────────────────────────────┘
```

## 3.2 Key Flutter Packages

| Package | Purpose |
|---|---|
| `powersync` | Offline SQLite + sync with Supabase PostgreSQL |
| `supabase_flutter` | Auth, direct API calls where PowerSync is not needed |
| `flutter_bloc` | State management (BLoC pattern) |
| `google_maps_flutter` | Map rendering, markers, clustering |
| `camera` / `image_picker` | Photo capture for documentation |
| `image` / `flutter_image_compress` | Client-side photo compression before upload |
| `connectivity_plus` | Network state detection |
| `get_it` + `injectable` | Dependency injection |
| `go_router` | Declarative routing |
| `sentry_flutter` | Error monitoring |
| `path_provider` | Local file system access for photo queue |
| `geolocator` | GPS location capture |
| `hive` or `shared_preferences` | App-level preferences (not synced data) |
| `intl` | Date/time formatting (WIB) |
| `uuid` | Offline ID generation (UUIDv7) |

## 3.3 App Initialization Flow

```
App Launch
  │
  ├─ 1. Initialize Sentry
  ├─ 2. Initialize PowerSync DB
  ├─ 3. Check stored Supabase Auth session
  │     ├─ Valid → Restore session, read role + posko_id from JWT claims
  │     └─ Invalid/Expired → Navigate to Login
  ├─ 4. Start PowerSync connection (if online)
  ├─ 5. Check pending sync queue → show Sync Center badge if items pending
  ├─ 6. Determine home screen based on role:
  │     ├─ POSKO → Posko Shift Home
  │     ├─ HARSUS → Harsus Home
  │     └─ ROW → ROW Home
  └─ 7. Start background photo upload worker
```

## 3.4 Navigation Structure

Bottom navigation bar with 5 items per DESIGN_BRIEF §6.1:

**Posko:** Home · Pekerjaan · PASILA · Maps · Riwayat  
**Harsus:** Home · Inspeksi · Maps · Riwayat · Profil  
**ROW:** Home · Pekerjaan · Maps · Riwayat · Profil

Each role sees a different bottom nav configuration based on their `role` claim.

## 3.5 Shift Detection

The app determines the current shift based on configurable shift parameters stored in the `app_config` table (synced via PowerSync). Shift boundaries are evaluated client-side using device local time (WIB / UTC+7).

The system supports 3 shifts per day per PRD OQ-12.

> **OPEN QUESTION (from PRD OQ-12):** Jam operasional masing-masing shift dan aturan cutoff/pergantian belum ditentukan. Nilai shift boundaries akan disimpan di `app_config` sehingga dapat diubah tanpa perubahan kode, tetapi nilai default belum dapat ditetapkan sampai ada keputusan bisnis.

---

# 4. Admin Web Architecture

## 4.1 Architecture Pattern

**Next.js App Router + Server Components + Client Components where interactivity is needed.**

```
┌────────────────────────────────────────────────────────────┐
│                    Next.js App Router                       │
│                                                            │
│  ┌────────────────────┐    ┌─────────────────────────────┐│
│  │ Server Components   │    │ Client Components            ││
│  │ - Data fetching     │    │ - Interactive forms           ││
│  │ - Initial rendering │    │ - Real-time subscriptions     ││
│  │                     │    │ - Maps                        ││
│  └────────────────────┘    │ - Data tables with sort/filter││
│                             └─────────────────────────────┘│
│                                                            │
│  ┌────────────────────────────────────────────────────────┐│
│  │ API Routes / Route Handlers                            ││
│  │ - Proxy for specialized operations if required         ││
│  │ - (Photo signing centralized in Supabase Edge Funcs)   ││
│  └────────────────────────────────────────────────────────┘│
│                                                            │
│  ┌────────────────────────────────────────────────────────┐│
│  │ Supabase Client (Server + Browser)                     ││
│  │ - @supabase/ssr for server components                  ││
│  │ - @supabase/supabase-js for client components          ││
│  └────────────────────────────────────────────────────────┘│
│                                                            │
└────────────────────────────────────────────────────────────┘
```

> **Catatan Photo Signing:** Next.js tidak menduplikasi logic pembuatan S3 pre-signed upload URL maupun CloudFront signed URL. Seluruh otorisasi dan penandatanganan URL foto dipusatkan (single source of truth) pada Supabase Edge Functions (`generate-photo-upload-url` dan `get-photo-access-url`).

## 4.2 Key Dependencies

| Package | Purpose |
|---|---|
| `@supabase/ssr` | Server-side Supabase client (cookie-based auth) |
| `@supabase/supabase-js` | Browser-side Supabase client |
| `@tanstack/react-query` | Data fetching, caching, mutations |
| `@tanstack/react-table` | Headless data table (sort, filter, pagination) |
| `@googlemaps/react-wrapper` | Google Maps JavaScript API integration |
| `react-hook-form` + `zod` | Form handling + schema validation |
| `date-fns` | Date manipulation |
| `@sentry/nextjs` | Error monitoring |
| `nuqs` | URL search params state management for filters |

## 4.3 Page Structure

```
/login
/dashboard
/p0
/p0/[id]
/wo
/wo/[id]
/wo/create          (manual WO)
/pasila
/pasila/[id]
/harsus
/harsus/[id]
/row
/row/[id]
/special-case
/special-case/[id]
/maps
/assets
/assets/[id]
/master/posko
/master/section
/master/aset
/master/apkuk
/master/vegetasi
/master/petugas
/settings
```

## 4.4 Real-time Updates

Admin web subscribes to Supabase Realtime channels for:

- New P0 assignments
- Work status changes
- Special Case triggers
- Sync completions from field devices

This provides live dashboard updates without polling.

---

# 5. Backend Architecture

## 5.1 No Custom Backend Server

The backend is composed entirely of:

1. **Supabase PostgREST** — Auto-generated REST API from PostgreSQL schema. All CRUD operations.
2. **Supabase Auth** — Authentication and session management.
3. **Supabase Edge Functions (Deno)** — Custom business logic that cannot be expressed as database functions or should not run client-side. Includes secure PowerSync write handler.
4. **PostgreSQL Functions** — Complex queries, data transformations, and triggers.
5. **pg_cron** — Scheduled background jobs within PostgreSQL.
6. **Supabase Realtime** — WebSocket-based real-time subscriptions for admin web.

## 5.2 Edge Functions Inventory

| Function | Trigger | Purpose |
|---|---|---|
| `powersync-write` | HTTP (PowerSync upload) | Secure, validated write handler for all offline sync writes (see §10.3) |
| `generate-wo` | pg_cron (per shift) | Generate WO per shift per Posko based on priority scoring |
| `detect-special-case` | pg_cron (daily) | Scan gangguan data for >3 trip/penyulang/period, create Special Cases |
| `process-special-case` | Database webhook (on Special Case creation) | Identify affected assets, generate P0s, avoid duplicates |
| `assign-p0` | Database trigger (on P0 insert) | Route P0 to correct Posko based on section mapping |
| `generate-photo-upload-url` | HTTP (client request) | Generate S3 pre-signed PUT URL for photo upload |
| `process-gangguan-import` | HTTP (admin trigger) | Import gangguan data from external source into raw staging table |
| `validate-wo-apkuk` | Database trigger (before WO insert) | Check if asset already has active APKUK, prevent duplicate WO |

## 5.3 PostgreSQL Functions Inventory

| Function | Purpose |
|---|---|
| `fn_get_asset_history(asset_id)` | Aggregate history across P0, WO, PASILA, Harsus, ROW, gangguan, APKUK for a single asset |
| `fn_calculate_wo_priority(asset_id)` | Calculate priority score for WO candidate (proposed factors — see §18.2) |
| `fn_get_pasila_summary(pasila_id)` | Aggregate action counts: total, completed, escalated to P0, ROW incidental |
| `fn_check_duplicate_p0(asset_id)` | Check if active P0 already exists for asset, return warning per EC-06 |
| `fn_get_posko_for_section(section_id)` | Resolve responsible Posko for a given section |
| `fn_shift_current()` | Return current shift number based on app_config shift boundaries and current WIB time |
| `fn_validate_status_transition(entity_type, old_status, new_status)` | Enforce valid status state machine; reject illegal transitions |
| `fn_update_p0_field_data(...)` | RPC for Posko to update only allowed fields on P0 (status, petugas, timestamps, catatan) |
| `fn_update_wo_field_data(...)` | RPC for Posko to update only allowed fields on WO |

---

# 6. PostgreSQL / Supabase Architecture

## 6.1 Schema Design

All tables use `UUID` as internal primary key (UUIDv7 for offline-generated records, UUIDv4 for server-generated records). **All existing PLN business identifiers are preserved as unique business keys** (e.g., `posko_code`, `section_code`, `asset_code`).

### Core Reference Tables

```sql
-- Posko
CREATE TABLE posko (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    posko_code  TEXT NOT NULL UNIQUE,  -- existing PLN ID, e.g. 'PSK001'
    nama        TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'ACTIVE'
        CHECK (status IN ('ACTIVE', 'INACTIVE')),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Section
CREATE TABLE section (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    section_code    TEXT NOT NULL UNIQUE,  -- existing PLN section identifier
    nama            TEXT NOT NULL,
    penyulang       TEXT NOT NULL,
    posko_id        UUID NOT NULL REFERENCES posko(id),
    aset_awal       TEXT,
    aset_akhir      TEXT,
    panjang_section NUMERIC,
    status          TEXT NOT NULL DEFAULT 'ACTIVE'
        CHECK (status IN ('ACTIVE', 'INACTIVE')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Asset
CREATE TABLE asset (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    asset_code      TEXT NOT NULL UNIQUE,  -- existing PLN asset ID (ID_ASET)
    nama            TEXT NOT NULL,
    jenis           TEXT NOT NULL
        CHECK (jenis IN ('TIANG', 'GARDU', 'KEYPOINT', 'LAINNYA')),
    section_id      UUID NOT NULL REFERENCES section(id),
    latitude        DOUBLE PRECISION,
    longitude       DOUBLE PRECISION,
    konstruksi      TEXT,
    status          TEXT NOT NULL DEFAULT 'ACTIVE',
    kondisi_terakhir TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Petugas (for dropdown selection)
CREATE TABLE petugas (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    nama        TEXT NOT NULL,
    posko_id    UUID REFERENCES posko(id),
    tim         TEXT,
    status      TEXT NOT NULL DEFAULT 'ACTIVE'
        CHECK (status IN ('ACTIVE', 'INACTIVE')),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Vegetasi
CREATE TABLE vegetasi (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    vegetasi_code       TEXT UNIQUE,  -- existing PLN ID if available
    asset_terdekat_id   UUID REFERENCES asset(id),
    section_id          UUID NOT NULL REFERENCES section(id),
    latitude            DOUBLE PRECISION,
    longitude           DOUBLE PRECISION,
    jenis               TEXT,
    kondisi             TEXT,
    kategori_pertumbuhan TEXT,
    tingkat_risiko      TEXT,
    tanggal_row_terakhir DATE,
    kondisi_terakhir    TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- APKUK
CREATE TABLE apkuk (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    apkuk_code      TEXT UNIQUE,  -- existing PLN APKUK ID if available
    asset_id        UUID NOT NULL REFERENCES asset(id),
    jenis           TEXT NOT NULL,
    jumlah          INTEGER NOT NULL DEFAULT 1,
    tanggal_pasang  DATE,
    posisi          TEXT,
    status          TEXT NOT NULL DEFAULT 'ACTIVE'
        CHECK (status IN ('ACTIVE', 'INACTIVE', 'RUSAK')),
    kondisi         TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- App Config (shift times, thresholds, WO weights, etc.)
CREATE TABLE app_config (
    key         TEXT PRIMARY KEY,
    value       JSONB NOT NULL,
    description TEXT,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

### Gangguan — Raw / Staging Table

Data gangguan berasal dari sumber gangguan pusat. Struktur sumber **TIDAK BOLEH diubah**. Tabel `gangguan_raw` mempertahankan seluruh kolom sumber secara eksplisit.

```sql
-- Gangguan Raw (preserves external source schema exactly)
CREATE TABLE gangguan_raw (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_gangguan         TEXT NOT NULL UNIQUE,  -- source system ID
    penyulang           TEXT,
    peralatan           TEXT,
    ulp                 TEXT,
    tanggal_kejadian    DATE NOT NULL,
    kode_gangguan       TEXT,
    uraian_penyebab     TEXT,
    penyebab            TEXT,
    lepas               TIMESTAMPTZ,
    masuk               TIMESTAMPTZ,
    beban_amp           NUMERIC,
    rele_kerja          TEXT,
    arus_gangguan_amp   NUMERIC,
    lama_padam_menit    NUMERIC,
    ens_kwh             NUMERIC,
    sifat_gangguan      TEXT,
    id_aset             TEXT,            -- source asset ID (business key)
    section_id_source   TEXT,            -- source section ID (business key)
    posko_id_source     TEXT,            -- source posko ID (business key)
    latitude            DOUBLE PRECISION,
    longitude           DOUBLE PRECISION,
    jenis_hewan         TEXT,
    foto_ref            TEXT,
    sumber_data         TEXT,
    imported_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

> **Catatan Tipe Data (Provisional):** Tipe data PostgreSQL pada tabel `gangguan_raw` di atas masih bersifat **provisional** sampai source spreadsheet / data pusat diprofiling secara mendalam. Seluruh nama kolom dan nilai sumber asli harus dipertahankan secara utuh, namun tipe database final (misalnya format parsing tanggal/jam, numeric precision, atau penanganan anomali string/null pada kolom numerik) harus diverifikasi dan divalidasi terhadap data aktual sebelum script migration dieksekusi.

### Gangguan — Normalized View for Application Use

Sistem menggunakan view/materialized view untuk kebutuhan analitik internal (WO scoring, Special Case detection, asset history) tanpa mengubah data sumber.

```sql
-- Materialized view for application analytics
CREATE MATERIALIZED VIEW gangguan_mv AS
SELECT
    gr.id,
    gr.id_gangguan,
    gr.penyulang,
    gr.tanggal_kejadian,
    gr.penyebab,
    gr.sifat_gangguan,
    gr.lama_padam_menit,
    gr.ens_kwh,
    gr.latitude,
    gr.longitude,
    gr.jenis_hewan,
    -- Resolve internal FKs from business keys
    a.id AS asset_id,
    s.id AS section_id,
    p.id AS posko_id,
    -- Derive trip boolean from sifat_gangguan
    CASE WHEN gr.sifat_gangguan ILIKE '%trip%' THEN true ELSE false END AS is_trip
FROM gangguan_raw gr
LEFT JOIN asset a ON a.asset_code = gr.id_aset
LEFT JOIN section s ON s.section_code = gr.section_id_source
LEFT JOIN posko p ON p.posko_code = gr.posko_id_source;

CREATE UNIQUE INDEX idx_gangguan_mv_id ON gangguan_mv(id);
CREATE INDEX idx_gangguan_mv_penyulang ON gangguan_mv(penyulang, tanggal_kejadian);
CREATE INDEX idx_gangguan_mv_asset ON gangguan_mv(asset_id);

-- Refresh after each import
-- REFRESH MATERIALIZED VIEW CONCURRENTLY gangguan_mv;
```

> **Note:** The `is_trip` derivation logic above is a placeholder. The exact mapping from `sifat_gangguan` values to the boolean `is_trip` flag depends on the source data vocabulary and must be confirmed during migration.

### Transaction Tables

```sql
-- User Account (extends Supabase auth.users)
CREATE TABLE user_profile (
    id          UUID PRIMARY KEY REFERENCES auth.users(id),
    role        TEXT NOT NULL
        CHECK (role IN ('ADMIN', 'POSKO', 'HARSUS', 'ROW')),
    posko_id    UUID REFERENCES posko(id),  -- required when role = POSKO
    display_name TEXT,
    status      TEXT NOT NULL DEFAULT 'ACTIVE'
        CHECK (status IN ('ACTIVE', 'INACTIVE')),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_posko_role CHECK (
        (role = 'POSKO' AND posko_id IS NOT NULL) OR
        (role != 'POSKO')
    )
);

-- P0
CREATE TABLE p0 (
    id                  UUID PRIMARY KEY,  -- client-generated UUIDv7
    asset_id            UUID REFERENCES asset(id),
    section_id          UUID NOT NULL REFERENCES section(id),
    posko_id            UUID REFERENCES posko(id),
    source_type         TEXT NOT NULL
        CHECK (source_type IN ('ADMIN', 'HARSUS', 'PASILA', 'SPECIAL_CASE')),
    source_id           UUID,  -- FK to originating record
    jenis_pekerjaan     TEXT NOT NULL,
    prioritas           INTEGER NOT NULL DEFAULT 1,
    kebutuhan_padam     BOOLEAN DEFAULT false,
    kebutuhan_material  TEXT,
    status              TEXT NOT NULL DEFAULT 'BARU'
        CHECK (status IN ('BARU', 'DITERIMA', 'DIKERJAKAN',
                          'SELESAI', 'RESCHEDULED', 'CANCELLED')),
    parent_p0_id        UUID REFERENCES p0(id),
    alasan_tidak_selesai TEXT,
    catatan             TEXT,
    waktu_dibuat        TIMESTAMPTZ NOT NULL DEFAULT now(),
    waktu_diterima      TIMESTAMPTZ,
    waktu_mulai         TIMESTAMPTZ,
    waktu_selesai       TIMESTAMPTZ,
    created_by          UUID REFERENCES auth.users(id),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    version             INTEGER NOT NULL DEFAULT 1,  -- optimistic concurrency
    _powersync_id       TEXT  -- PowerSync tracking
);

-- P0 Petugas (junction table)
CREATE TABLE p0_petugas (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    p0_id       UUID NOT NULL REFERENCES p0(id) ON DELETE CASCADE,
    petugas_id  UUID NOT NULL REFERENCES petugas(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (p0_id, petugas_id)
);

-- Work Order
CREATE TABLE work_order (
    id                  UUID PRIMARY KEY,
    asset_id            UUID NOT NULL REFERENCES asset(id),
    section_id          UUID NOT NULL REFERENCES section(id),
    posko_id            UUID NOT NULL REFERENCES posko(id),
    tanggal             DATE NOT NULL,
    shift               INTEGER NOT NULL CHECK (shift IN (1, 2, 3)),
    sumber              TEXT NOT NULL
        CHECK (sumber IN ('AUTO', 'ADMIN')),
    jenis_wo            TEXT NOT NULL DEFAULT 'PEMASANGAN_APKUK',
    priority_score      NUMERIC,
    alasan_prioritas    TEXT,
    status              TEXT NOT NULL DEFAULT 'BARU'
        CHECK (status IN ('BARU', 'DITERIMA', 'DIKERJAKAN',
                          'SELESAI', 'RESCHEDULED', 'CANCELLED')),
    waktu_mulai         TIMESTAMPTZ,
    waktu_selesai       TIMESTAMPTZ,
    alasan_reschedule   TEXT,
    catatan             TEXT,
    created_by          UUID REFERENCES auth.users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    version             INTEGER NOT NULL DEFAULT 1,
    _powersync_id       TEXT
);

-- WO Petugas (junction table)
CREATE TABLE wo_petugas (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    wo_id       UUID NOT NULL REFERENCES work_order(id) ON DELETE CASCADE,
    petugas_id  UUID NOT NULL REFERENCES petugas(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (wo_id, petugas_id)
);

-- PASILA Session
CREATE TABLE pasila_session (
    id                  UUID PRIMARY KEY,
    posko_id            UUID NOT NULL REFERENCES posko(id),
    section_id          UUID NOT NULL REFERENCES section(id),
    penyulang_prioritas TEXT,
    tanggal             DATE NOT NULL,
    shift               INTEGER NOT NULL CHECK (shift IN (1, 2, 3)),
    anchor_asset_id     UUID REFERENCES asset(id),
    waktu_mulai         TIMESTAMPTZ,
    waktu_selesai       TIMESTAMPTZ,
    status              TEXT NOT NULL DEFAULT 'AKTIF'
        CHECK (status IN ('AKTIF', 'SELESAI', 'DIBATALKAN')),
    created_by          UUID REFERENCES auth.users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    version             INTEGER NOT NULL DEFAULT 1,
    _powersync_id       TEXT
);

-- PASILA Action
CREATE TABLE pasila_action (
    id                  UUID PRIMARY KEY,
    pasila_id           UUID NOT NULL REFERENCES pasila_session(id),
    asset_id            UUID REFERENCES asset(id),
    latitude            DOUBLE PRECISION,
    longitude           DOUBLE PRECISION,
    jenis_temuan        TEXT NOT NULL,
    tindakan            TEXT,
    selesai_langsung    BOOLEAN NOT NULL DEFAULT false,
    kebutuhan_padam     BOOLEAN DEFAULT false,
    kebutuhan_material  TEXT,
    keterangan          TEXT,
    create_p0           BOOLEAN DEFAULT false,
    p0_id               UUID REFERENCES p0(id),
    is_row_incidental   BOOLEAN DEFAULT false,
    waktu_action        TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    _powersync_id       TEXT
);

-- PASILA Action Petugas (junction table)
CREATE TABLE pasila_action_petugas (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    pasila_action_id UUID NOT NULL REFERENCES pasila_action(id) ON DELETE CASCADE,
    petugas_id      UUID NOT NULL REFERENCES petugas(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (pasila_action_id, petugas_id)
);

-- Harsus Inspection
CREATE TABLE harsus_inspection (
    id                  UUID PRIMARY KEY,
    asset_id            UUID REFERENCES asset(id),
    section_id          UUID NOT NULL REFERENCES section(id),
    tanggal             DATE NOT NULL,
    temuan              TEXT NOT NULL,
    kondisi             TEXT,
    tingkat_risiko      TEXT,
    kebutuhan_padam     BOOLEAN DEFAULT false,
    rekomendasi         TEXT,
    p0_id               UUID REFERENCES p0(id),
    latitude            DOUBLE PRECISION,
    longitude           DOUBLE PRECISION,
    created_by          UUID REFERENCES auth.users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    _powersync_id       TEXT
);

-- ROW Work
CREATE TABLE row_work (
    id                      UUID PRIMARY KEY,
    vegetasi_id             UUID REFERENCES vegetasi(id),
    section_id              UUID NOT NULL REFERENCES section(id),
    tanggal                 DATE NOT NULL,
    jenis_tindakan          TEXT NOT NULL
        CHECK (jenis_tindakan IN ('RABAS', 'PANGKAS', 'TEBANG')),
    sumber                  TEXT NOT NULL
        CHECK (sumber IN ('ROW_TERJADWAL', 'PASILA_INSIDENTAL')),
    posko_id                UUID REFERENCES posko(id),
    status                  TEXT NOT NULL DEFAULT 'BARU'
        CHECK (status IN ('BARU', 'DIKERJAKAN', 'SELESAI')),
    kondisi_sebelum         TEXT,
    kondisi_sesudah         TEXT,
    waktu_mulai             TIMESTAMPTZ,
    waktu_selesai           TIMESTAMPTZ,
    tanggal_row_berikutnya  DATE,
    catatan                 TEXT,
    latitude                DOUBLE PRECISION,
    longitude               DOUBLE PRECISION,
    created_by              UUID REFERENCES auth.users(id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    version                 INTEGER NOT NULL DEFAULT 1,
    _powersync_id           TEXT
);

-- ROW Work Petugas (junction table)
CREATE TABLE row_work_petugas (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    row_work_id UUID NOT NULL REFERENCES row_work(id) ON DELETE CASCADE,
    petugas_id  UUID NOT NULL REFERENCES petugas(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (row_work_id, petugas_id)
);

-- Special Case
CREATE TABLE special_case (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    penyulang       TEXT NOT NULL,
    periode_awal    DATE NOT NULL,
    periode_akhir   DATE NOT NULL,
    jumlah_trip     INTEGER NOT NULL,
    status          TEXT NOT NULL DEFAULT 'BARU'
        CHECK (status IN ('BARU', 'DITINDAKLANJUTI', 'SELESAI')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Special Case Detail (linked assets and generated P0s)
CREATE TABLE special_case_asset (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    special_case_id UUID NOT NULL REFERENCES special_case(id),
    asset_id        UUID NOT NULL REFERENCES asset(id),
    gangguan_count  INTEGER NOT NULL DEFAULT 0,
    p0_id           UUID REFERENCES p0(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Documentation (photos and evidence)
CREATE TABLE documentation (
    id              UUID PRIMARY KEY,  -- client-generated
    source_type     TEXT NOT NULL
        CHECK (source_type IN ('P0', 'WO', 'PASILA_ACTION',
                                'HARSUS', 'ROW')),
    source_id       UUID NOT NULL,
    asset_id        UUID REFERENCES asset(id),
    photo_type      TEXT NOT NULL
        CHECK (photo_type IN ('BEFORE', 'AFTER', 'CONDITION',
                              'EVIDENCE', 'OTHER')),
    s3_key          TEXT,          -- S3 object key
    s3_bucket       TEXT,          -- S3 bucket name
    file_size_bytes BIGINT,
    mime_type       TEXT,
    captured_at     TIMESTAMPTZ NOT NULL,
    latitude        DOUBLE PRECISION,
    longitude       DOUBLE PRECISION,
    petugas_id      UUID REFERENCES petugas(id),
    upload_status   TEXT NOT NULL DEFAULT 'PENDING'
        CHECK (upload_status IN ('PENDING', 'UPLOADED', 'FAILED')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    _powersync_id   TEXT
);

-- Status History (audit trail for status changes)
CREATE TABLE status_history (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    entity_type     TEXT NOT NULL
        CHECK (entity_type IN ('P0', 'WO', 'PASILA', 'HARSUS',
                                'ROW', 'SPECIAL_CASE')),
    entity_id       UUID NOT NULL,
    old_status      TEXT,
    new_status      TEXT NOT NULL,
    changed_by      UUID REFERENCES auth.users(id),
    reason          TEXT,
    changed_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

> **OPEN QUESTION (from PRD OQ-07):** Apakah satu pekerjaan bisa memiliki lebih dari satu petugas? Schema di atas menggunakan junction table (`p0_petugas`, `wo_petugas`, dll.) yang mendukung multiple petugas per pekerjaan. Jika keputusan bisnis menetapkan satu petugas per pekerjaan, junction table tetap dapat digunakan dengan constraint tambahan, atau disederhanakan menjadi single FK.

## 6.2 Key Indexes

```sql
-- Business key lookups
CREATE INDEX idx_posko_code ON posko(posko_code);
CREATE INDEX idx_section_code ON section(section_code);
CREATE INDEX idx_asset_code ON asset(asset_code);

-- Asset lookups
CREATE INDEX idx_asset_section ON asset(section_id);
CREATE INDEX idx_asset_jenis ON asset(jenis);
CREATE INDEX idx_asset_coords ON asset(latitude, longitude);

-- Section → Posko resolution
CREATE INDEX idx_section_posko ON section(posko_id);
CREATE INDEX idx_section_penyulang ON section(penyulang);

-- P0 queries
CREATE INDEX idx_p0_posko_status ON p0(posko_id, status);
CREATE INDEX idx_p0_asset ON p0(asset_id);
CREATE INDEX idx_p0_source ON p0(source_type, source_id);

-- WO queries
CREATE INDEX idx_wo_posko_shift ON work_order(posko_id, tanggal, shift);
CREATE INDEX idx_wo_asset ON work_order(asset_id);
CREATE INDEX idx_wo_status ON work_order(status);

-- Gangguan raw
CREATE INDEX idx_gangguan_raw_penyulang ON gangguan_raw(penyulang, tanggal_kejadian);
CREATE INDEX idx_gangguan_raw_id_aset ON gangguan_raw(id_aset);
CREATE INDEX idx_gangguan_raw_id_gangguan ON gangguan_raw(id_gangguan);

-- APKUK lookups
CREATE INDEX idx_apkuk_asset_status ON apkuk(asset_id, status);

-- Documentation
CREATE INDEX idx_doc_source ON documentation(source_type, source_id);

-- Status history
CREATE INDEX idx_status_history_entity ON status_history(entity_type, entity_id);

-- Vegetasi
CREATE INDEX idx_vegetasi_section ON vegetasi(section_id);
CREATE INDEX idx_vegetasi_row_terakhir ON vegetasi(tanggal_row_terakhir);

-- PASILA
CREATE INDEX idx_pasila_posko_shift ON pasila_session(posko_id, tanggal, shift);
CREATE INDEX idx_pasila_action_session ON pasila_action(pasila_id);

-- Junction tables
CREATE INDEX idx_p0_petugas_p0 ON p0_petugas(p0_id);
CREATE INDEX idx_wo_petugas_wo ON wo_petugas(wo_id);
CREATE INDEX idx_row_petugas_row ON row_work_petugas(row_work_id);
CREATE INDEX idx_pasila_action_petugas ON pasila_action_petugas(pasila_action_id);
```

## 6.3 Key Database Triggers

```sql
-- Auto-populate updated_at on every update
CREATE OR REPLACE FUNCTION fn_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Auto-record status changes in status_history
CREATE OR REPLACE FUNCTION fn_record_status_change()
RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status IS DISTINCT FROM NEW.status THEN
        INSERT INTO status_history (entity_type, entity_id, old_status, new_status, changed_by)
        VALUES (TG_ARGV[0], NEW.id, OLD.status, NEW.status, auth.uid());
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Validate status transitions (state machine enforcement)
CREATE OR REPLACE FUNCTION fn_validate_status_transition()
RETURNS TRIGGER AS $$
DECLARE
    valid_transitions JSONB := '{
        "P0": {
            "BARU": ["DITERIMA", "CANCELLED"],
            "DITERIMA": ["DIKERJAKAN", "CANCELLED"],
            "DIKERJAKAN": ["SELESAI", "RESCHEDULED"],
            "RESCHEDULED": ["BARU", "CANCELLED"],
            "SELESAI": [],
            "CANCELLED": []
        },
        "WO": {
            "BARU": ["DITERIMA", "CANCELLED"],
            "DITERIMA": ["DIKERJAKAN", "CANCELLED"],
            "DIKERJAKAN": ["SELESAI", "RESCHEDULED"],
            "RESCHEDULED": ["BARU", "CANCELLED"],
            "SELESAI": [],
            "CANCELLED": []
        }
    }'::JSONB;
    entity TEXT := TG_ARGV[0];
    allowed JSONB;
BEGIN
    IF OLD.status IS NOT DISTINCT FROM NEW.status THEN
        RETURN NEW;
    END IF;

    allowed := valid_transitions->entity->OLD.status;
    IF allowed IS NULL OR NOT allowed ? NEW.status THEN
        RAISE EXCEPTION 'Invalid status transition: % → % for %',
            OLD.status, NEW.status, entity;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Optimistic concurrency check
CREATE OR REPLACE FUNCTION fn_check_version()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.version != OLD.version + 1 THEN
        RAISE EXCEPTION 'Conflict: record has been modified (expected version %, got %)',
            OLD.version + 1, NEW.version;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Apply triggers
CREATE TRIGGER trg_p0_status_history
    AFTER UPDATE ON p0 FOR EACH ROW
    EXECUTE FUNCTION fn_record_status_change('P0');

CREATE TRIGGER trg_p0_validate_transition
    BEFORE UPDATE ON p0 FOR EACH ROW
    EXECUTE FUNCTION fn_validate_status_transition('P0');

CREATE TRIGGER trg_p0_check_version
    BEFORE UPDATE ON p0 FOR EACH ROW
    EXECUTE FUNCTION fn_check_version();

CREATE TRIGGER trg_wo_status_history
    AFTER UPDATE ON work_order FOR EACH ROW
    EXECUTE FUNCTION fn_record_status_change('WO');

CREATE TRIGGER trg_wo_validate_transition
    BEFORE UPDATE ON work_order FOR EACH ROW
    EXECUTE FUNCTION fn_validate_status_transition('WO');

CREATE TRIGGER trg_wo_check_version
    BEFORE UPDATE ON work_order FOR EACH ROW
    EXECUTE FUNCTION fn_check_version();
```

> **Note:** Status transitions for SELESAI and CANCELLED are terminal. Only Admin can reopen via a separate correction workflow (see OQ-13). The `version` column enables optimistic concurrency — see §11.

---

# 7. Authentication and Authorization

## 7.1 Authentication Flow

```
┌──────────┐     ┌──────────────┐     ┌──────────────┐
│  Client   │────►│ Supabase Auth│────►│  JWT Token   │
│  (Login)  │     │              │     │  + claims:   │
└──────────┘     └──────────────┘     │  - role      │
                                       │  - posko_id  │
                                       └──────────────┘
```

1. User submits credentials to Supabase Auth.
2. Supabase Auth verifies credentials, generates JWT.
3. A PostgreSQL function (`custom_access_token_hook`) populates custom claims in the JWT:
   - `app_role` (ADMIN | POSKO | HARSUS | ROW)
   - `posko_id` (UUID, present only for POSKO role)
4. JWT is stored securely:
   - **Android:** Flutter Supabase SDK handles secure storage.
   - **Web:** HTTP-only cookies via `@supabase/ssr`.
5. All subsequent API calls include the JWT. PostgREST and Edge Functions validate the JWT automatically.

> **OPEN QUESTION (Login identifier):** PRD menyebutkan username/password login. Supabase Auth secara default menggunakan email sebagai identifier. Opsi:
> - Gunakan email sebagai login identifier (e.g., `posko01@ulp-sribhawono.internal`).
> - Gunakan Supabase Auth "phone" provider sebagai abstraksi untuk username (non-standard workaround).
> - Buat custom sign-in via Edge Function yang menerima username dan memetakan ke email internal secara transparan.
>
> Metode login final belum diputuskan. Arsitektur mendukung ketiga opsi. Keputusan ini tidak mempengaruhi arsitektur lain karena setelah login, semua akses menggunakan JWT yang sama.

## 7.2 Custom JWT Claims

```sql
CREATE OR REPLACE FUNCTION public.custom_access_token_hook(event JSONB)
RETURNS JSONB AS $$
DECLARE
    claims JSONB;
    user_role TEXT;
    user_posko_id UUID;
BEGIN
    SELECT role, posko_id INTO user_role, user_posko_id
    FROM public.user_profile
    WHERE id = (event->>'user_id')::UUID;

    claims := event->'claims';
    claims := jsonb_set(claims, '{app_role}', to_jsonb(user_role));

    IF user_posko_id IS NOT NULL THEN
        claims := jsonb_set(claims, '{posko_id}', to_jsonb(user_posko_id::TEXT));
    END IF;

    event := jsonb_set(event, '{claims}', claims);
    RETURN event;
END;
$$ LANGUAGE plpgsql;
```

## 7.3 Account Management

- **Account creation:** Admin-only. There is no self-registration.
- **Password reset:** Admin can trigger password reset for field users.
- **Session duration:** Long-lived refresh tokens for Android (field workers should not need to re-login frequently). Standard session duration for web.

---

# 8. Role Permissions

## 8.1 Permission Matrix

| Resource | ADMIN | POSKO | HARSUS | ROW |
|---|---|---|---|---|
| **Dashboard** | Full view all Posko | Own Posko only | Own inspections | Own work |
| **P0 — View** | All | Own Posko only | Own inspections → P0 | — |
| **P0 — Create** | ✅ Manual | ✅ From PASILA escalation | ✅ From inspection | — |
| **P0 — Execute** | — | ✅ Own Posko | — | — |
| **P0 — Cancel** | ✅ | — | — | — |
| **WO — View** | All | Own Posko only | — | — |
| **WO — Create manual** | ✅ | — | — | — |
| **WO — Execute** | — | ✅ Own Posko | — | — |
| **PASILA — View** | All | Own Posko only | — | — |
| **PASILA — Execute** | — | ✅ Own Posko | — | — |
| **Harsus — View** | All | — | All own | — |
| **Harsus — Create** | — | — | ✅ | — |
| **ROW — View** | All | Read (PASILA insidental) | — | All |
| **ROW — Execute** | — | ✅ Insidental during PASILA | — | ✅ |
| **Special Case — View** | ✅ | — | — | — |
| **Maps** | ✅ All | ✅ Own sections | ✅ (scope TBD) | ✅ (scope TBD) |
| **Master Data** | ✅ Full CRUD | Read (synced) | Read (synced) | Read (synced) |
| **User Management** | ✅ | — | — | — |
| **Correction/Edit completed** | ✅ | — | — | — |

## 8.2 RLS Policy Strategy — Field-Level Write Control

Every table has RLS enabled. Policies are defined based on `app_role` and `posko_id` claims.

**Critical design principle:** Posko must NOT be able to modify assignment fields (posko_id, section_id, source_type, source_id, sumber, jenis_wo), master data references, or other system-controlled fields via direct table UPDATE. Field data entry by Posko is routed through **server-side RPC functions** that only accept and modify allowed fields.

### Read Policies (SELECT)

```sql
ALTER TABLE p0 ENABLE ROW LEVEL SECURITY;

-- Admin can see all P0
CREATE POLICY "admin_select_p0" ON p0 FOR SELECT
    USING ((auth.jwt()->>'app_role') = 'ADMIN');

-- Posko can see only their own P0
CREATE POLICY "posko_select_p0" ON p0 FOR SELECT
    USING (
        (auth.jwt()->>'app_role') = 'POSKO'
        AND posko_id = (auth.jwt()->>'posko_id')::UUID
    );

-- Harsus can see P0 they created
CREATE POLICY "harsus_select_p0" ON p0 FOR SELECT
    USING (
        (auth.jwt()->>'app_role') = 'HARSUS'
        AND source_type = 'HARSUS'
        AND created_by = auth.uid()
    );
```

### Write Policies — Controlled via RPC

Direct UPDATE on transaction tables (p0, work_order, pasila_session, etc.) is **denied for field roles** at the RLS level. Instead, Posko/Harsus/ROW write through dedicated RPC functions.

```sql
-- DENY direct updates by field roles
-- (No UPDATE policy is created for POSKO/HARSUS/ROW on p0, work_order, etc.)
-- Only Admin gets direct UPDATE policy:
CREATE POLICY "admin_update_p0" ON p0 FOR UPDATE
    USING ((auth.jwt()->>'app_role') = 'ADMIN');

-- INSERT policies remain for record creation:
CREATE POLICY "posko_insert_p0" ON p0 FOR INSERT
    WITH CHECK (
        (auth.jwt()->>'app_role') = 'POSKO'
        AND source_type = 'PASILA'
    );

CREATE POLICY "harsus_insert_p0" ON p0 FOR INSERT
    WITH CHECK (
        (auth.jwt()->>'app_role') = 'HARSUS'
        AND source_type = 'HARSUS'
    );
```

### RPC Functions for Field Updates

```sql
-- Posko updates P0: only allowed fields
CREATE OR REPLACE FUNCTION fn_update_p0_field_data(
    p_id UUID,
    p_status TEXT,
    p_catatan TEXT DEFAULT NULL,
    p_alasan_tidak_selesai TEXT DEFAULT NULL,
    p_waktu_mulai TIMESTAMPTZ DEFAULT NULL,
    p_waktu_selesai TIMESTAMPTZ DEFAULT NULL,
    p_version INTEGER DEFAULT NULL
)
RETURNS VOID AS $$
DECLARE
    v_posko_id UUID;
    v_current_version INTEGER;
BEGIN
    -- Verify caller is POSKO and owns this P0
    IF (auth.jwt()->>'app_role') != 'POSKO' THEN
        RAISE EXCEPTION 'Unauthorized: only POSKO can call this function';
    END IF;

    SELECT posko_id, version INTO v_posko_id, v_current_version
    FROM p0 WHERE id = p_id;

    IF v_posko_id != (auth.jwt()->>'posko_id')::UUID THEN
        RAISE EXCEPTION 'Unauthorized: P0 does not belong to this Posko';
    END IF;

    -- Optimistic concurrency check
    IF p_version IS NOT NULL AND p_version != v_current_version + 1 THEN
        RAISE EXCEPTION 'Conflict: record has been modified';
    END IF;

    -- Only update field-editable columns
    UPDATE p0 SET
        status = COALESCE(p_status, status),
        catatan = COALESCE(p_catatan, catatan),
        alasan_tidak_selesai = COALESCE(p_alasan_tidak_selesai, alasan_tidak_selesai),
        waktu_mulai = COALESCE(p_waktu_mulai, waktu_mulai),
        waktu_selesai = COALESCE(p_waktu_selesai, waktu_selesai),
        version = version + 1
    WHERE id = p_id;
    -- Status transition validation is handled by the trigger
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
```

Similar RPC functions exist for `work_order`, `pasila_session`, `pasila_action`, `row_work`, and `harsus_inspection`.

## 8.3 Service Role Access

Edge Functions and pg_cron jobs use the `service_role` key to bypass RLS when performing system-level operations (WO generation, Special Case processing). These keys are never exposed to clients.

---

# 9. Offline-First Architecture

## 9.1 Design Principles

1. **Offline is the default state.** The Android app assumes no network. All field input operations work against local SQLite.
2. **Sync is opportunistic.** When network becomes available, data syncs automatically.
3. **User always knows data state.** Every record shows sync status: `LOCAL → SYNCING → SYNCED → FAILED`.
4. **No data loss.** Data persists in SQLite even if the app is killed before sync.
5. **Idempotent sync.** Re-syncing the same record does not create duplicates (FR-OFF-06).

## 9.2 What Works Offline (Android)

| Operation | Offline? | Notes |
|---|---|---|
| View assigned P0/WO/PASILA | ✅ | From local SQLite cache |
| Start/complete P0 | ✅ | Status changes saved locally |
| Start/complete WO | ✅ | Status changes saved locally |
| Run PASILA session | ✅ | Session + actions saved locally |
| Add PASILA actions | ✅ | Saved locally |
| Escalate to P0 from PASILA | ✅ | P0 created locally, synced later |
| Harsus inspection input | ✅ | Saved locally |
| ROW work input | ✅ | Saved locally |
| Capture photos | ✅ | Saved to device storage, queued for upload |
| View asset details + history | ✅ | From cached/synced data |
| View Maps markers | ✅ | Asset coordinates available from local SQLite |
| View Maps base map | ⚠️ | Base map tiles depend on Google Maps SDK cache; NOT guaranteed offline |
| Navigate to location | ❌ | Requires Google Maps app (offline availability depends on that app) |
| Receive new P0/WO assignments | ❌ | Requires sync |

## 9.3 What Does NOT Need Offline (Admin Web)

The Admin Web is online-primary. Per DESIGN_BRIEF §8 — Admin Dashboard: "Web Admin tidak perlu full offline operation." The web shows a warning when connectivity is lost but does not provide full offline functionality.

---

# 10. PowerSync + SQLite Synchronization Flow

## 10.1 Architecture

```
┌─────────────────┐         ┌──────────────────┐         ┌──────────────┐
│  Flutter App     │         │  PowerSync        │         │  Supabase    │
│  ┌─────────────┐│         │  Service           │         │  PostgreSQL  │
│  │ SQLite DB   ││◄───────►│  (Cloud-hosted)    │◄───────►│              │
│  │ (local)     ││  sync   │                    │  CDC    │              │
│  └─────────────┘│         └──────────────────┘         └──────────────┘
│                  │
│  PowerSync SDK   │
│  - Watches       │
│  - Queries       │
│  - Writes        │
└─────────────────┘
```

## 10.2 Sync Rules (PowerSync)

PowerSync sync rules define which data syncs to which client based on user parameters.

```yaml
# PowerSync sync rules (conceptual)
bucket_definitions:

  # Reference data — synced to all authenticated users
  - name: "global_reference"
    data:
      - SELECT * FROM posko
      - SELECT * FROM section
      - SELECT * FROM app_config
      - SELECT * FROM petugas WHERE status = 'ACTIVE'

  # Posko-scoped data — only data for this Posko
  - name: "posko_data"
    parameters:
      - posko_id: token_parameters.posko_id
    data:
      - SELECT * FROM asset WHERE section_id IN
          (SELECT id FROM section WHERE posko_id = :posko_id)
      - SELECT * FROM p0 WHERE posko_id = :posko_id
      - SELECT * FROM work_order WHERE posko_id = :posko_id
      - SELECT * FROM pasila_session WHERE posko_id = :posko_id
      - SELECT * FROM pasila_action WHERE pasila_id IN
          (SELECT id FROM pasila_session WHERE posko_id = :posko_id)
      - SELECT * FROM apkuk WHERE asset_id IN
          (SELECT id FROM asset WHERE section_id IN
              (SELECT id FROM section WHERE posko_id = :posko_id))
      - SELECT * FROM vegetasi WHERE section_id IN
          (SELECT id FROM section WHERE posko_id = :posko_id)
      - SELECT * FROM gangguan_mv WHERE posko_id = :posko_id
      - SELECT * FROM documentation WHERE source_id IN
          (SELECT id FROM p0 WHERE posko_id = :posko_id
           UNION SELECT id FROM work_order WHERE posko_id = :posko_id)
      # Junction tables for petugas
      - SELECT * FROM p0_petugas WHERE p0_id IN
          (SELECT id FROM p0 WHERE posko_id = :posko_id)
      - SELECT * FROM wo_petugas WHERE wo_id IN
          (SELECT id FROM work_order WHERE posko_id = :posko_id)

  # Harsus data — scope TBD (see OPEN QUESTION)
  - name: "harsus_data"
    parameters:
      - user_id: token_parameters.user_id
    data:
      - SELECT * FROM harsus_inspection WHERE created_by = :user_id
      # Asset and section scope is OPEN QUESTION — see §10.5

  # ROW data — scope TBD (see OPEN QUESTION)
  - name: "row_data"
    parameters:
      - user_id: token_parameters.user_id
    data:
      - SELECT * FROM row_work
      # Vegetasi, asset, and section scope is OPEN QUESTION — see §10.5
```

## 10.3 Write Path (Client → Server) — Secure Handler

PowerSync's upload endpoint is a Supabase Edge Function (`powersync-write`) that implements **explicit resource routing, role validation, and field-level control**. It does NOT accept arbitrary table names or operations from the client.

```typescript
// Edge Function: powersync-write (conceptual structure)
Deno.serve(async (req) => {
    // 1. Authenticate & extract verified custom JWT claims / auth context
    // NOTE: Custom claims (app_role, posko_id) are injected into the JWT claims payload
    // by custom_access_token_hook. Do NOT assume custom claims exist on user.app_metadata.
    const authHeader = req.headers.get('Authorization');
    const token = authHeader?.replace('Bearer ', '');
    if (!token) return unauthorized();

    // Verify JWT cryptographically using Supabase JWT secret / auth context
    const claims = await verifySupabaseJwt(token, Deno.env.get('SUPABASE_JWT_SECRET')!);
    if (!claims || !claims.sub) return unauthorized();

    const userId = claims.sub;
    const role = claims.app_role as string;
    const poskoId = claims.posko_id as string | undefined;

    if (!role) return unauthorized();

    const supabaseAdmin = createClient(
        Deno.env.get('SUPABASE_URL')!,
        Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!
    );

    // 2. Parse PowerSync upload batch
    const { operations } = await req.json();

    for (const op of operations) {
        // 3. Route to explicit handler per resource type
        switch (op.type) {
            case 'p0':
                await handleP0Write(op, role, poskoId, userId, supabaseAdmin);
                break;
            case 'work_order':
                await handleWOWrite(op, role, poskoId, userId, supabaseAdmin);
                break;
            case 'pasila_session':
                await handlePasilaSessionWrite(op, role, poskoId, userId, supabaseAdmin);
                break;
            case 'pasila_action':
                await handlePasilaActionWrite(op, role, poskoId, userId, supabaseAdmin);
                break;
            case 'harsus_inspection':
                await handleHarsusWrite(op, role, userId, supabaseAdmin);
                break;
            case 'row_work':
                await handleRowWorkWrite(op, role, userId, supabaseAdmin);
                break;
            case 'documentation':
                await handleDocWrite(op, role, userId, supabaseAdmin);
                break;
            // Junction tables
            case 'p0_petugas':
            case 'wo_petugas':
            case 'pasila_action_petugas':
            case 'row_work_petugas':
                await handlePetugasAssignment(op, role, poskoId, userId, supabaseAdmin);
                break;
            default:
                // REJECT unknown resource types
                throw new Error(`Unknown resource type: ${op.type}`);
        }
    }

    return new Response(JSON.stringify({ ok: true }));
});

// Each handler validates:
// - Role is allowed to perform this operation
// - Ownership (Posko can only write to own Posko's records)
// - Field restrictions (Posko cannot set posko_id, section_id, source_type, etc.)
// - Status transition validity
// - Version check for optimistic concurrency on updates
// - Business rules (e.g., no duplicate P0 for same asset)
```

**Key security properties:**
- No arbitrary table access — only explicitly handled resource types are accepted.
- No direct `service_role` pass-through of client data — each handler validates and sanitizes.
- Role and ownership validation on every write.
- Status transition enforcement before applying updates.
- Version/concurrency check on record updates.

## 10.4 Read Path (Server → Client)

1. PowerSync Service monitors PostgreSQL WAL (Write-Ahead Log) via logical replication.
2. Changes are detected and pushed to connected clients.
3. If client is offline, changes accumulate and are delivered on reconnection.
4. Client's local SQLite is updated, triggering reactive UI updates via PowerSync watches.

## 10.5 Offline Data Scope per Role

> **OPEN QUESTION:** Berapa banyak data yang harus diunduh untuk Harsus dan ROW?
>
> - **Harsus:** PRD tidak membatasi inspeksi Harsus ke wilayah tertentu. Apakah Harsus perlu data seluruh aset ULP untuk offline, atau dibatasi berdasarkan penugasan/area tertentu? Mengunduh seluruh aset ULP ke setiap perangkat Harsus memiliki implikasi terhadap volume sync dan storage.
> - **ROW:** Apakah ROW bekerja di seluruh wilayah ULP atau dibatasi per area tertentu? Jika seluruh vegetasi ULP harus tersedia offline, volume data perlu diperhitungkan.
> - **Posko:** Scope Posko sudah jelas — data di-scope berdasarkan section yang menjadi tanggung jawab Posko tersebut.
>
> Sync rules harus didesain agar dapat dibatasi berdasarkan parameter (wilayah/penugasan) begitu keputusan dibuat.

---

# 11. Conflict Resolution Strategy

## 11.1 Conflict Scenario

The primary conflict scenario (per EC-14 and OQ-09 in PRD) is: the same record is edited from two devices while both are offline.

## 11.2 Proposed Strategy: Tiered Approach

Conflict resolution menggunakan **strategi bertingkat** berdasarkan risiko field:

### Tier 1 — Status dan Field Kritis: Optimistic Concurrency + Server-Side Validation

Field yang mempengaruhi state pekerjaan (status, waktu_mulai, waktu_selesai) menggunakan:

1. **Version column.** Setiap record memiliki kolom `version INTEGER`. Client mengirim `version + 1` saat update. Jika server melihat version mismatch, update **ditolak** dan client menerima versi terbaru dari server.
2. **Status state machine.** Trigger `fn_validate_status_transition` menolak transisi status yang tidak valid (misalnya, SELESAI → DIKERJAKAN).
3. **Server authoritative.** Jika dua device mengirim status update yang bertentangan, yang pertama sampai ke server diterima, yang kedua ditolak dan harus menerima state terbaru.

### Tier 2 — Field Informatif: Last-Write-Wins (Proposed Fallback)

Field yang bersifat informatif dan low-risk (catatan, keterangan) menggunakan **Last-Write-Wins** berdasarkan `updated_at` sebagai fallback. Ini adalah **engineering proposal**, bukan keputusan bisnis final.

### Tier 3 — Append-Only Records: No Conflict Possible

- `status_history` — insert-only, never updated.
- `documentation` — created once, only `upload_status` is updated.
- `pasila_action` — created during session, not edited after creation.
- Junction tables (`p0_petugas`, dll.) — created, potentially deleted, not updated.

## 11.3 Assumptions That Must NOT Be Made

- ~~Satu Posko hanya menggunakan satu device~~ — **OPEN QUESTION.** Multiple devices per Posko account mungkin terjadi.
- ~~Harsus pasti satu akun per orang~~ — **OPEN QUESTION.** Satu akun Harsus mungkin digunakan oleh beberapa petugas.
- ~~ROW pasti satu akun per orang~~ — **OPEN QUESTION.** Hal yang sama berlaku.

Karena asumsi single-device tidak dapat dibuat, **optimistic concurrency pada field kritis adalah wajib**, bukan optional.

## 11.4 Client-Side Conflict Handling

Ketika server menolak update karena version mismatch:

1. PowerSync SDK menerima rejection.
2. Client menampilkan pesan: **"Data telah diperbarui dari sumber lain. Memuat versi terbaru."**
3. Client memuat versi terbaru dari server (via PowerSync sync).
4. User dapat mereview dan mengulangi tindakan jika diperlukan.

> **OPEN QUESTION (from PRD OQ-09):** Strategi conflict resolution di atas adalah proposal engineering. Keputusan final tentang behavior ketika terjadi konflik (silently merge, reject, atau prompt user) memerlukan persetujuan stakeholder.

---

# 12. Offline ID Generation Strategy

## 12.1 Approach: UUIDv7

All client-created records use **UUIDv7** as the internal primary key.

**Why UUIDv7:**

1. **Globally unique without server coordination.** No need for network access to generate an ID.
2. **Time-ordered.** UUIDv7 encodes a Unix timestamp in the most significant bits, making IDs naturally sortable by creation time. Beneficial for B-tree index performance and debugging.
3. **No sequence collisions.** Unlike auto-increment integers, UUIDs from different offline devices will not collide.
4. **PowerSync compatible.** PowerSync requires stable client-generated IDs for its sync protocol.

**Note:** UUIDv7 is the **internal primary key**. All existing PLN business identifiers (POSKO_ID, SECTION_ID, ID_ASET, ID_GANGGUAN, etc.) are retained as separate unique business key columns. New transaction records (P0, WO, PASILA, etc.) that do not have pre-existing PLN identifiers use UUIDv7 as their sole key.

## 12.2 Implementation

```dart
// Flutter — using `uuid` package
import 'package:uuid/uuid.dart';

const uuid = Uuid();

String generateId() {
  return uuid.v7(); // time-ordered UUID
}
```

## 12.3 Display ID Convention

For human readability in the UI and logs, display IDs use a prefix derived from the entity type:

| Entity | Display Format | Example |
|---|---|---|
| P0 | `P0-{short}` | `P0-7f3a` (last 4 hex of UUID) |
| WO | `WO-{short}` | `WO-b2c1` |
| PASILA | `PAS-{short}` | `PAS-d4e5` |
| Harsus | `HAR-{short}` | `HAR-1a2b` |
| ROW | `ROW-{short}` | `ROW-9f8e` |

The display prefix is computed client-side from the UUID, not stored separately.

---

# 13. Photo Capture / Upload Architecture

## 13.1 Overview

Photos are the highest-volume data in the system. The architecture must handle:

- Capture while offline
- Local storage until sync
- Compressed upload to S3
- Metadata tracking in PostgreSQL
- Delivery via CloudFront

## 13.2 Capture Flow

```
┌──────────────────────────────────────────────────────────────┐
│                    ANDROID DEVICE                             │
│                                                              │
│  1. User taps camera                                         │
│  2. Camera captures photo                                    │
│  3. Photo compressed (configurable — see §13.4)              │
│  4. Saved to app-private storage:                            │
│     /data/data/{app}/files/photos/{uuid}.jpg                 │
│  5. Documentation record created in local SQLite:            │
│     { id, source_type, source_id, photo_type,                │
│       local_path, captured_at, lat, lng,                     │
│       upload_status: 'PENDING' }                             │
│  6. User sees thumbnail + "Tersimpan di perangkat"           │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

## 13.3 Upload Flow (When Online)

```
┌──────────┐     ┌──────────────┐     ┌──────────┐     ┌──────────┐
│  Android  │────►│ Edge Function │────►│  S3      │     │ Update   │
│  upload   │     │ (get pre-    │     │  PUT     │     │ doc      │
│  worker   │     │  signed URL) │     │  upload  │     │ record   │
│           │     └──────────────┘     └──────────┘     │ status   │
│           │            │                    │          │ → UPLOADED│
│           │            ▼                    ▼          └──────────┘
│           │     Returns:             File stored at:
│           │     PUT URL +            s3://{bucket}/{key}
│           │     s3_key
└──────────┘
```

**Step by step:**

1. Background upload worker polls for `documentation` records with `upload_status = 'PENDING'`.
2. For each pending photo, worker calls `generate-photo-upload-url` Edge Function with:
   - `source_type`, `source_id`, `photo_type`, `file_extension`
3. Edge Function validates the caller's auth token and generates:
   - S3 pre-signed PUT URL (expires in 15 minutes)
   - S3 object key following the storage structure (see §15)
4. Worker uploads the JPEG file directly to S3 using the pre-signed PUT URL.
5. On success, worker updates the local `documentation` record:
   - `upload_status = 'UPLOADED'`
   - `s3_key = {returned key}`
   - `s3_bucket = {bucket name}`
6. PowerSync syncs the updated `documentation` record to the server.
7. Local file is retained until confirmed synced, then eligible for cleanup.

## 13.4 Photo Compression — Engineering Defaults

The following are **configurable engineering defaults**, not business requirements:

- **Max dimension:** 1920px on the longest edge.
- **Quality:** 80% JPEG.
- **Estimated size per photo:** 200–500 KB.

These values balance photo legibility for inspection purposes with upload efficiency on slow connections. They can be adjusted based on field testing and stakeholder feedback.

## 13.5 Retry and Error Handling — Engineering Defaults

The following are **configurable engineering defaults**:

- Upload failures set `upload_status = 'FAILED'`.
- Worker retries failed uploads with exponential backoff (initial interval and max interval configurable).
- Automatic retry count is configurable (default: 5 retries per session).
- User can manually retry from Sync Center.
- **Critical:** Data record (P0/WO/PASILA) sync is independent of photo upload. A completed P0 syncs even if its photos haven't uploaded yet. The P0 record will reference photo documentation IDs, and the photos will upload eventually.

---

# 14. Offline Photo Queue

## 14.1 Queue Structure

```
┌─────────────────────────────────────────────────┐
│           LOCAL PHOTO QUEUE                      │
│                                                  │
│  SQLite table: documentation (local)             │
│                                                  │
│  ┌──────────────────────────────────────────┐   │
│  │ id: UUID                                  │   │
│  │ local_path: /files/photos/{uuid}.jpg      │   │
│  │ upload_status: PENDING | UPLOADING |      │   │
│  │                 UPLOADED | FAILED          │   │
│  │ retry_count: 0                            │   │
│  │ last_retry_at: null                       │   │
│  │ file_size_bytes: 350000                   │   │
│  │ captured_at: timestamp                    │   │
│  └──────────────────────────────────────────┘   │
│                                                  │
│  Queue processing:                               │
│  - FIFO order by captured_at                     │
│  - Max concurrent uploads: configurable          │
│  - Paused when offline                           │
│  - Resumes automatically when online             │
│                                                  │
└─────────────────────────────────────────────────┘
```

## 14.2 Storage Management — Engineering Defaults

- Photos are stored in the app's private directory, not external storage.
- The app monitors available storage and shows a warning when storage drops below a configurable threshold (default: 200 MB, per EC-12).
- After a photo is confirmed uploaded AND the `documentation` record is synced to the server, the local file is eligible for cleanup.
- Cleanup retention period is configurable (default: 48 hours after confirmed sync).

## 14.3 Sync Center UI Integration

Per DESIGN_BRIEF §6.11, the Sync Center shows:

- Total photos pending upload
- Total size pending
- Per-item status (record type, timestamp, size, state)
- "Coba Lagi" button for failed uploads

---

# 15. Amazon S3 Storage Structure

## 15.1 Bucket Structure

**Single bucket:** `ulp-sribhawono-photos-{environment}`

```
ulp-sribhawono-photos-production/
├── p0/
│   └── {p0_id}/
│       ├── before_{uuid}.jpg
│       ├── after_{uuid}.jpg
│       └── evidence_{uuid}.jpg
├── wo/
│   └── {wo_id}/
│       ├── before_{uuid}.jpg
│       ├── after_{uuid}.jpg
│       └── apkuk_{uuid}.jpg
├── pasila/
│   └── {action_id}/
│       ├── before_{uuid}.jpg
│       ├── after_{uuid}.jpg
│       └── condition_{uuid}.jpg
├── harsus/
│   └── {inspection_id}/
│       ├── condition_{uuid}.jpg
│       └── finding_{uuid}.jpg
├── row/
│   └── {row_work_id}/
│       ├── before_{uuid}.jpg
│       └── after_{uuid}.jpg
└── master/
    └── asset/
        └── {asset_id}/
            └── photo_{uuid}.jpg
```

**Key format:** `{source_type}/{source_id}/{photo_type}_{uuid}.{ext}`

## 15.2 Bucket Policy

- **No public access.** All access via pre-signed URLs or CloudFront signed URLs.
- **Server-side encryption:** AES-256 (SSE-S3).
- **No automatic deletion.** Photos are retained indefinitely until a retention policy is defined.
- **Versioning:** **Enabled pada Staging dan Production** sebagai proteksi terhadap accidental deletion atau korupsi data (opsional pada Development). Pengaktifan versioning **TIDAK** dijadikan alasan untuk mengizinkan operasi delete dari client; client tetap dilarang keras menghapus file/foto secara langsung.
- **Retention/lifecycle policy:** Tetap **OPEN QUESTION** (lihat catatan di bawah).
- **CORS:** Configured for direct upload from Android app.

> **OPEN QUESTION (Photo Retention Policy):** Belum ada requirement tentang berapa lama foto harus disimpan, apakah foto merupakan bukti legal/compliance, dan apakah foto yang sudah tua dapat dipindahkan ke storage class yang lebih murah (Infrequent Access, Glacier). Lifecycle rules S3 memerlukan retention/access policy yang disetujui stakeholder sebelum diterapkan. Tanpa kebijakan tersebut, foto disimpan pada storage class standar tanpa batas waktu.

## 15.3 Pre-signed URL Generation (Centralized)

Supabase Edge Function adalah **single source of truth** untuk otorisasi dan penandatanganan URL S3/CloudFront. Next.js API Routes tidak menduplikasi logic penandatanganan AWS SDK ini; seluruh client (Android maupun Admin Web) memanggil Edge Function yang sama.

```typescript
// Edge Function: generate-photo-upload-url
import { S3Client, PutObjectCommand } from "@aws-sdk/client-s3";
import { getSignedUrl } from "@aws-sdk/s3-request-presigner";

const s3 = new S3Client({
    region: Deno.env.get("AWS_REGION"),
    credentials: {
        accessKeyId: Deno.env.get("AWS_ACCESS_KEY_ID")!,
        secretAccessKey: Deno.env.get("AWS_SECRET_ACCESS_KEY")!,
    },
});

Deno.serve(async (req) => {
    // Authenticate caller
    const authHeader = req.headers.get('Authorization');
    // ... validate JWT ...

    const { source_type, source_id, photo_type, file_extension } = await req.json();

    // Validate allowed source_type values
    const allowedTypes = ['p0', 'wo', 'pasila', 'harsus', 'row', 'master'];
    if (!allowedTypes.includes(source_type)) {
        return new Response('Invalid source_type', { status: 400 });
    }

    const uuid = crypto.randomUUID();
    const key = `${source_type}/${source_id}/${photo_type}_${uuid}.${file_extension}`;

    const command = new PutObjectCommand({
        Bucket: Deno.env.get("S3_BUCKET")!,
        Key: key,
        ContentType: `image/${file_extension}`,
    });

    const url = await getSignedUrl(s3, command, { expiresIn: 900 }); // 15 min

    return new Response(JSON.stringify({ upload_url: url, s3_key: key }));
});
```

---

# 16. CloudFront Delivery

## 16.1 Distribution Setup

- **Origin:** S3 bucket (`ulp-sribhawono-photos-{env}`)
- **Access:** Origin Access Control (OAC) — CloudFront accesses S3 on behalf of the user.
- **Signed URLs:** All photo URLs served through CloudFront are signed with a short TTL (1 hour for display, 24 hours for download).
- **Caching:** Cache-Control headers set to `max-age=86400, immutable` (photos never change).
- **Price class:** Price Class 200 (covers Asia Pacific).

## 16.2 URL Generation for Display (Centralized)

When the admin web or Android app needs to display or download an authorized photo:

1. Client (Android app atau Admin Web) meminta authorized photo access URL ke centralized Supabase Edge Function (`get-photo-access-url`).
2. Edge Function memverifikasi JWT dan otorisasi akses pengguna, lalu meng-generate CloudFront signed URL (atau S3 pre-signed GET URL).
3. Client memuat atau mengunduh gambar menggunakan signed URL tersebut.
4. Next.js API Routes **tidak menduplikasi logic penandatanganan ini**, melainkan mengandalkan Edge Function terpusat.

For the Android app, photo URLs are generated during sync and cached locally. When offline, the app displays locally stored photos.

---

# 17. Maps Architecture

## 17.1 Android (Flutter)

- **SDK:** `google_maps_flutter` package.
- **Map type:** Standard roadmap.
- **Markers:** Custom markers per asset type and work status, following DESIGN_BRIEF §7 marker variants (Asset normal, P0, WO, PASILA, Harsus finding, Vegetation, Special Case).
- **Clustering:** Marker clustering for zoom levels where individual markers overlap.
- **Data source:** Asset coordinates from local SQLite (available offline as markers/pins).
- **Base map tiles:** Depend on Google Maps SDK's internal tile cache. **Not guaranteed to be available offline.**
- **Navigation:** Tapping "Navigasi" button launches Google Maps app via intent with destination coordinates. Offline navigation depends on the Google Maps app's own offline maps capability; this is outside the scope of this application.

```dart
// Navigation intent
Future<void> navigateToAsset(double lat, double lng) async {
    final url = Uri.parse(
        'google.navigation:q=$lat,$lng&mode=d'
    );
    if (await canLaunchUrl(url)) {
        await launchUrl(url);
    }
}
```

## 17.2 Admin Web

- **API:** Google Maps JavaScript API via `@googlemaps/react-wrapper`.
- **Layout:** Split-screen per DESIGN_BRIEF §9 — Map 60–70% + detail panel 30–40%.
- **Markers:** Same semantic markers as Android, rendered as custom overlay views.
- **Clustering:** `@googlemaps/markerclusterer` library.
- **Filters:** Penyulang, section, Posko, tipe aset, status pekerjaan (per FR-MAP-03).
- **Detail panel:** Clicking a marker opens the right panel with asset info, history, active work.

## 17.3 Offline Maps

> **OPEN QUESTION (from PRD OQ-08):**
>
> Untuk MVP:
> - **Marker/titik aset** tersedia offline karena koordinat disimpan di SQLite lokal.
> - **Base map (peta dasar)** TIDAK dijamin tersedia offline. Google Maps SDK untuk Flutter **tidak menyediakan API untuk mengunduh peta offline secara programatis**. SDK memiliki cache internal untuk tile yang pernah dilihat, tetapi cache ini tidak dapat dikontrol oleh developer.
> - Jika base map offline menjadi requirement, arsitektur perlu menggunakan map provider yang berbeda (misalnya `flutter_map` + OpenStreetMap tile server dengan kemampuan download offline), yang memiliki implikasi development dan licensing tersendiri.
> - Per DESIGN_BRIEF §8 Maps: jika base map gagal dimuat, sistem harus menampilkan **list titik** sebagai fallback, bukan blank screen.

---

# 18. Background Jobs and Automation

## 18.1 Scheduled Jobs (pg_cron)

| Job | Schedule | Description |
|---|---|---|
| `generate_wo_shift_1` | Before shift 1 starts (time TBD) | Generate WO for shift 1 for all active Posko |
| `generate_wo_shift_2` | Before shift 2 starts (time TBD) | Generate WO for shift 2 |
| `generate_wo_shift_3` | Before shift 3 starts (time TBD) | Generate WO for shift 3 |
| `detect_special_case` | Daily (time TBD) | Scan gangguan for Special Case conditions |
| `refresh_gangguan_mv` | After gangguan import | Refresh `gangguan_mv` materialized view |
| `update_vegetasi_status` | Daily (time TBD) | Flag vegetasi that are overdue for ROW work |

> **Note:** Exact cron schedules for WO generation depend on shift times, which are **OPEN QUESTION (OQ-12)**. pg_cron jobs will be configured once shift times are finalized. pg_cron runs inside PostgreSQL; for operations needing external services, the job calls `net.http_post` to invoke a Supabase Edge Function.

## 18.2 WO Generation Logic — PROPOSED

> **OPEN QUESTION (from PRD OQ-01):** The factors, weights, and complete algorithm for WO prioritization are **not yet finalized**. The following is a **proposed framework**, not a final algorithm. All parameters must be confirmed with stakeholders and stored in `app_config` for tuning without code changes.

**Proposed flow** per shift per Posko:

```
FOR each active Posko:
  1. Get all sections assigned to this Posko
  2. Get all assets in those sections
  3. EXCLUDE assets that:
     - Already have an active APKUK (status = 'ACTIVE') → FR-WO-05
     - Already have an active WO (status IN ('BARU', 'DITERIMA', 'DIKERJAKAN'))
     - Already have an active P0
  4. SCORE remaining assets by CANDIDATE FACTORS (weights TBD):
     - Gangguan count (period TBD)
     - Trip count
     - Kondisi terakhir severity
     - Days since last preventive work
     - Special Case involvement
     - Other factors TBD
  5. RANK by score DESC
  6. SELECT top 6
  7. CREATE work_order records with:
     - sumber = 'AUTO'
     - jenis_wo = 'PEMASANGAN_APKUK'
     - alasan_prioritas = human-readable explanation
     - priority_score = calculated score
```

> **OPEN QUESTION (from PRD OQ-02):** Whether the 6 WO target is reduced when there are many P0s on the same shift.

## 18.3 Event-Driven Jobs

| Trigger | Action |
|---|---|
| P0 inserted with `section_id` but no `posko_id` | `assign-p0` function resolves Posko from section. If no active Posko, P0 is flagged for Admin manual assignment (EC-04). |
| PASILA action with `is_row_incidental = true` | Creates corresponding `row_work` record with `sumber = 'PASILA_INSIDENTAL'` |
| PASILA action with `create_p0 = true` | Creates P0 with `source_type = 'PASILA'` and links back to the action |
| Harsus inspection with `p0_id IS NOT NULL` | P0 already created during inspection flow; trigger assigns it to Posko |
| WO completed with `jenis_wo = 'PEMASANGAN_APKUK'` | Creates or updates APKUK record for the asset |

---

# 19. Special Case Processing

## 19.1 Detection Logic

Per FR-SC-02: a penyulang with >3 trips in one period triggers a Special Case.

**Proposed detection query** (period definition is OPEN QUESTION):

```sql
-- Detect Special Cases (conceptual — period logic TBD)
SELECT
    penyulang,
    COUNT(*) as trip_count
FROM gangguan_mv
WHERE is_trip = true
  AND tanggal_kejadian >= :period_start  -- period boundary TBD
  AND tanggal_kejadian <= :period_end
GROUP BY penyulang
HAVING COUNT(*) > 3;
```

> **OPEN QUESTION (from PRD OQ-05):** Apakah menggunakan bulan kalender, rolling 30 hari, atau periode lain? Threshold (>3) juga dapat dikonfigurasi via `app_config`.

## 19.2 Processing Flow

```
Special Case detected
  │
  ├─ 1. Create special_case record
  ├─ 2. Identify all assets in the affected penyulang that had trips
  ├─ 3. For each asset:
  │     ├─ Check if active P0 already exists (FR-SC-04)
  │     │   ├─ Yes → Skip, but increase prioritas on existing P0 (FR-SC-05)
  │     │   └─ No → Create new P0 with source_type = 'SPECIAL_CASE'
  │     └─ Create special_case_asset link record
  ├─ 4. Update special_case status → 'DITINDAKLANJUTI'
  └─ 5. Notify Admin via Realtime channel
```

---

# 20. WO Generation Architecture

Covered in §18.2. Additional details:

## 20.1 Manual WO by Admin

Admin can create WO manually via the admin web:

1. Admin selects an asset.
2. System validates:
   - Asset does not have active APKUK (for APKUK WO type).
   - Asset does not have an active WO.
3. Admin provides jenis_wo and optional catatan.
4. WO is created with `sumber = 'ADMIN'`.
5. WO is assigned to the Posko responsible for the asset's section.

> **OPEN QUESTION (from PRD OQ-03):** Whether manual WO can be for types other than PEMASANGAN_APKUK.

## 20.2 WO Reschedule

If a WO is not completed by end of shift:

1. Posko marks WO as `RESCHEDULED` with `alasan_reschedule`.
2. The original WO record retains its status change in `status_history`.
3. A new WO for the next shift may or may not be auto-generated.

> **OPEN QUESTION (from PRD OQ-02 / EC-15):** Whether rescheduled WOs count against the 6 WO target for the next shift.

---

# 21. Audit Trail / Logging

## 21.1 Status History Table

All status changes on P0, WO, PASILA, Harsus, ROW, and Special Case records are automatically captured in the `status_history` table via database triggers (see §6.3).

Each entry records:
- Entity type and ID
- Old status → New status
- User who made the change (`auth.uid()`)
- Timestamp
- Optional reason

## 21.2 Application-Level Logging

| Layer | Tool | What is logged |
|---|---|---|
| Android | Sentry | Crashes, unhandled exceptions, breadcrumbs for user actions |
| Android | Local log file | Sync operations, photo upload attempts, offline state transitions |
| Admin Web | Sentry | JavaScript errors, performance traces |
| Edge Functions | Supabase logs | Function invocations, errors, execution time |
| PostgreSQL | Supabase Dashboard logs | Slow queries, RLS violations, function errors |

## 21.3 Data Immutability

- `status_history` is insert-only. No updates or deletes.
- `documentation` records are not deleted without explicit retention policy.
- `gangguan_raw` records (imported) are not modified by the application.
- Soft deletes are used where record removal is needed (set `status = 'INACTIVE'` or `status = 'CANCELLED'`).

---

# 22. Security

## 22.1 Authentication Security

- **Password hashing:** bcrypt (Supabase Auth default).
- **JWT tokens:** Short-lived access tokens (1 hour), long-lived refresh tokens (configurable; longer for Android since field workers should not need to re-login frequently).
- **No self-registration.** Accounts created by Admin only.
- **Session invalidation:** Admin can deactivate a user account, which invalidates all active sessions.

## 22.2 Data Security

- **Row Level Security (RLS):** Enforced on every table. Even direct PostgREST access is filtered by role and posko_id.
- **Field-level write control:** Field roles (Posko, Harsus, ROW) cannot directly UPDATE transaction tables. All writes go through validated RPC functions or the secure PowerSync write handler (§10.3).
- **Service role key:** Used only in Edge Functions and server-side code. Never exposed to clients.
- **API key (anon key):** The public Supabase anon key is safe to embed in clients because all data access is gated by RLS.

## 22.3 Photo Security

- **S3 bucket:** No public access. All uploads via pre-signed PUT URLs (15 min expiry).
- **Photo retrieval:** Via CloudFront signed URLs (1 hour expiry) generated centrally by Supabase Edge Functions.
- **CloudFront key pair:** Stored as environment variable in Supabase Edge Functions (centralized single source for signing). Not exposed to clients and not duplicated in Next.js API routes.

## 22.4 Network Security

- **All traffic over HTTPS.** Supabase enforces TLS. CloudFront enforces TLS. S3 pre-signed URLs are HTTPS.
- **Android app:** Certificate pinning is not implemented for MVP (Supabase-managed endpoints change certificates). Rely on system CA trust store.
- **Admin web:** HTTPS enforced via Vercel.

## 22.5 Local Data Security (Android)

- Photos stored in app-private directory (`/data/data/{package}/files/`), not accessible to other apps.
- SQLite database stored in app-private directory.
- No sensitive data logged to system log in production builds.
- Supabase auth tokens stored via Flutter secure storage.

## 22.6 Input Validation

- **Client-side:** Form validation before submission (immediate user feedback).
- **Server-side:** PostgreSQL CHECK constraints, RLS policies, RPC function validation, Edge Function validation, and status transition triggers. Client-side validation is convenience; server-side is authoritative.
- **SQL injection:** Not applicable — Supabase client libraries use parameterized queries.

---

# 23. Backup and Disaster Recovery

## 23.1 PostgreSQL Backup

- **Supabase managed backups:** Daily automatic backups. Retention period depends on Supabase plan tier.
- **Point-in-Time Recovery (PITR):** May require Supabase Pro plan or paid add-on depending on pricing at time of deployment. Availability and cost must be verified during setup.
- **Manual backups:** Admin can trigger a `pg_dump` export via Supabase Dashboard.

## 23.2 S3 Backup

- **S3 durability:** 99.999999999% (11 nines). Data loss is extremely unlikely.
- **Cross-region replication:** Not required for MVP (single-ULP, Indonesia region).
- **Versioning:** **Enabled pada Staging dan Production** (opsional untuk Development) sebagai mekanisme proteksi terhadap accidental deletion atau file corruption. Aturan retention/lifecycle tetap **OPEN QUESTION**.
- **Deletion protection:** Pengaktifan versioning **bukan** alasan untuk mengizinkan client menghapus file. Operasi delete pada S3 tetap dibatasi secara ketat hanya untuk `service_role` / backend admin melalui IAM policy. Tidak ada client (Android maupun Admin Web) yang dapat melakukan delete langsung.

## 23.3 Recovery Scenarios

| Scenario | Recovery |
|---|---|
| Supabase DB corruption | Restore from PITR (if available) or daily backup |
| Accidental data deletion (DB) | Restore from PITR or backup |
| S3 object deletion | Prevention via restricted IAM (no client-side delete). Recovery via S3 Versioning (restore previous object version / remove delete marker on Staging & Production). |
| Android device loss | Data already synced is safe on server. Unsynced data is lost. Mitigation: clear sync status indicators + encourage frequent sync. |
| Edge Function error | Stateless; redeploy fixes. No persistent state in Edge Functions. |

## 23.4 Proposed RPO and RTO Targets

The following are **proposed engineering targets**, not final requirements:

| Metric | Proposed Target | Notes |
|---|---|---|
| **RPO** (Recovery Point Objective) | ≤ 24 hours (daily backup) or near-zero (PITR) | Depends on Supabase plan |
| **RTO** (Recovery Time Objective) | ≤ 2 hours | Restore from backup + redeploy Edge Functions |

Final RPO/RTO targets must be agreed upon with stakeholders based on business criticality assessment and available Supabase plan features.

---

# 24. Development, Staging, Production Environments

## 24.1 Environment Matrix

| Aspect | Development | Staging | Production |
|---|---|---|---|
| **Supabase Project** | Local (Docker) or Supabase dev project | Separate Supabase project | Production Supabase project |
| **Database** | Seeded with test data | Mirror of production schema, sanitized data | Live data |
| **S3 Bucket** | `ulp-sribhawono-photos-dev` | `ulp-sribhawono-photos-staging` | `ulp-sribhawono-photos-production` |
| **CloudFront** | Not required (direct S3 access for dev) | Separate distribution | Production distribution |
| **PowerSync** | Dev instance | Staging instance | Production instance |
| **Admin Web (Vercel)** | localhost:3000 | Preview deployment per PR | Production domain |
| **Android App** | Debug build | Internal test track (Google Play) | Production release (Google Play or direct APK) |
| **Sentry** | Dev environment | Staging environment | Production environment |
| **Google Maps** | Dev API key (restricted) | Staging API key | Production API key (restricted to app bundle) |

## 24.2 Environment Variables

All secrets are managed via:
- **Supabase:** Project settings → Environment variables (for Edge Functions)
- **Vercel:** Project settings → Environment variables (for Next.js)
- **Flutter:** `.env` files via `flutter_dotenv`, excluded from source control
- **GitHub Actions:** Repository secrets

## 24.3 Database Migrations

- Migrations are managed via **Supabase CLI** (`supabase migration`).
- Each migration is a SQL file stored in the repository under `supabase/migrations/`.
- Migrations are applied in order during `supabase db push`.
- All schema changes go through PR review before merging.

---

# 25. Deployment Architecture

## 25.1 Android App

```
Developer → Push to GitHub → GitHub Actions CI:
  1. Run Flutter tests
  2. Run Flutter analyze
  3. Build APK / AAB
  4. Upload to Google Play Internal Test Track (staging)
  5. Promote to Production (manual trigger)
```

- **Signing:** App signing key managed via GitHub Actions secrets.
- **Distribution:** Google Play Store internal track for staging, production track for release. Direct APK distribution also available for field teams if Google Play access is limited.
- **Update strategy:** In-app update prompt when a new version is available.

## 25.2 Admin Web

```
Developer → Push to GitHub → Vercel auto-deploy:
  - PR branch → Preview deployment (unique URL)
  - main branch → Production deployment
```

- **Domain:** Custom domain (e.g., `admin.ulp-sribhawono.app`)
- **SSL:** Automatic via Vercel.

## 25.3 Supabase

- **Edge Functions:** Deployed via `supabase functions deploy` in CI.
- **Database migrations:** Applied via `supabase db push` in CI.
- **Seed data:** Applied via `supabase db seed` for dev/staging.

## 25.4 AWS (S3 + CloudFront)

- **Infrastructure as Code:** AWS CDK or Terraform for S3 bucket, CloudFront distribution, IAM policies.
- **Deployment:** Infrastructure changes via CI/CD pipeline (GitHub Actions).

---

# 26. Monitoring and Observability

## 26.1 Error Monitoring — Sentry

| Platform | Configuration |
|---|---|
| Android (Flutter) | `sentry_flutter` SDK. Capture: crashes, unhandled exceptions, ANRs, HTTP errors. Release tracking with build number. |
| Admin Web (Next.js) | `@sentry/nextjs` SDK. Capture: client-side errors, server-side errors in API routes, performance traces. Source maps uploaded during build. |
| Edge Functions | Manual Sentry SDK initialization in Deno. Capture: function errors, timeout events. |

## 26.2 Application Monitoring

| Metric | Source | Alert Threshold |
|---|---|---|
| Sync failure rate | Custom metric from Edge Function logs | > 5% of sync attempts in 1 hour |
| Photo upload failure rate | `documentation` table query | > 10 failed uploads in 1 hour |
| WO generation failure | pg_cron job logs | Job fails to complete |
| Special Case detection failure | pg_cron job logs | Job fails to complete |
| Edge Function latency | Supabase Dashboard | p95 > 5 seconds |
| Database connection pool | Supabase Dashboard | > 80% utilization |

## 26.3 Uptime Monitoring

- **Supabase:** Built-in health monitoring via Supabase Dashboard.
- **Admin web:** Vercel analytics + uptime monitoring.
- **PowerSync:** PowerSync Dashboard for sync health.

## 26.4 Log Aggregation

For MVP, logs are accessed via:
- **Supabase Dashboard:** Edge Function logs, database logs.
- **Vercel Dashboard:** Next.js function logs.
- **Sentry Dashboard:** Error events and traces.
- **Google Play Console:** Android vitals, crash reports.

A centralized log aggregation system (e.g., Datadog, Grafana Cloud) is not required for MVP but is recommended for v2.

---

# 27. Initial Spreadsheet Data Migration Strategy

## 27.1 Context

Per user condition #8: existing PLN data comes from spreadsheets. These are used as the source for initial import, not as an operational database.

## 27.2 Migration Approach

```
┌─────────────┐     ┌──────────────┐     ┌───────────────┐     ┌──────────┐
│ Spreadsheets │────►│ Validation   │────►│ Transform     │────►│ PostgreSQL│
│ (.xlsx)       │     │ Script       │     │ Script        │     │ Import   │
│               │     │ (TypeScript) │     │ (TypeScript)  │     │ (SQL     │
│               │     │              │     │               │     │  UPSERT) │
└─────────────┘     └──────────────┘     └───────────────┘     └──────────┘
```

## 27.3 Data to Migrate

| Data | Source | Target Table | Business Key Column | Notes |
|---|---|---|---|---|
| Posko list | Spreadsheet | `posko` | `posko_code` | Name, status |
| Section list | Spreadsheet | `section` | `section_code` | Name, penyulang, posko mapping, boundaries |
| Asset inventory | Spreadsheet | `asset` | `asset_code` | Name, type, section, coordinates, construction |
| APKUK records | Spreadsheet | `apkuk` | `apkuk_code` | Asset, type, date, status |
| Vegetasi points | Spreadsheet | `vegetasi` | `vegetasi_code` | Location, type, condition, risk |
| Gangguan history | Spreadsheet + external source | `gangguan_raw` | `id_gangguan` | Full source schema preserved; see §6.1 |
| Petugas list | — | `petugas` | — | **Not yet available** per PRD OQ-07. |

## 27.4 Migration Script Requirements

1. **Idempotent.** Running the script multiple times does not create duplicates. Use `UPSERT` on business key columns.
2. **Business key preservation.** All PLN IDs are imported as-is into the `*_code` columns.
3. **Validation.** Validate data before import:
   - Required fields present.
   - Section references valid Posko (by posko_code).
   - Asset references valid Section (by section_code).
   - Coordinates within reasonable bounds for Lampung region.
   - No duplicate business keys.
4. **Error reporting.** Output a detailed log of:
   - Records successfully imported.
   - Records skipped (with reason).
   - Records that failed validation (with specific errors).
5. **Dry run mode.** Script can be run in validation-only mode without writing to the database.

## 27.5 Migration Tool

A TypeScript script using Supabase client library, executed as a Node.js script:

```
scripts/
├── migrate/
│   ├── index.ts          # Entry point
│   ├── validators.ts     # Data validation functions
│   ├── transformers.ts   # Spreadsheet → DB schema mapping
│   ├── importers/
│   │   ├── posko.ts
│   │   ├── section.ts
│   │   ├── asset.ts
│   │   ├── apkuk.ts
│   │   ├── vegetasi.ts
│   │   └── gangguan.ts   # Imports to gangguan_raw preserving full schema
│   └── data/             # .gitignored — actual spreadsheet files
│       ├── posko.xlsx
│       ├── section.xlsx
│       └── ...
```

## 27.6 Ongoing Gangguan Data Import

Per PRD condition #9, data gangguan berasal dari sumber eksternal pusat. Aplikasi ini tidak menggantikan sumber tersebut. Metode integrasi gangguan **tetap OPEN QUESTION (OQ-14)**:

- **Manual CSV/XLSX import adalah fallback awal** jika akses otomatis langsung ke sumber pusat belum tersedia.
- **Import/integration adapter:** Jika Google Spreadsheet sumber dapat diakses secara aman (misalnya via Google Sheets API dengan service account terautentikasi), sistem menggunakan adapter integrasi untuk menarik data gangguan secara berkala.
- **API Resmi:** Jika di kemudian hari tersedia API resmi dari sistem gangguan pusat PLN, adapter integrasi dapat diganti dengan API client baru tanpa perlu mengubah skema tabel `gangguan_raw` maupun logic analytical view (`gangguan_mv`).
- Proses import (baik fallback CSV/XLSX maupun adapter otomatis) dijalankan melalui Edge Function (`process-gangguan-import`) yang memvalidasi format, melakukan UPSERT ke `gangguan_raw` berdasarkan `id_gangguan`, dan me-refresh materialized view `gangguan_mv`.

> **OPEN QUESTION (from PRD OQ-14):** Metode integrasi data gangguan pusat belum ditetapkan sebagai keputusan final. Pilihan adapter otomatis vs fallback manual CSV/XLSX akan ditentukan berdasarkan ketersediaan izin akses teknis ke data sumber.

---

# 28. Recommended Repository / Monorepo Structure

## 28.1 Monorepo Structure

```
ulp-sribhawono/
│
├── docs/
│   ├── PRD.md
│   ├── DESIGN_BRIEF.md
│   └── TECHNICAL_SPEC.md
│
├── apps/
│   ├── mobile/                    # Flutter Android app
│   │   ├── lib/
│   │   │   ├── core/              # Shared core
│   │   │   │   ├── config/        # Environment config, constants
│   │   │   │   ├── database/      # PowerSync schema, migrations
│   │   │   │   ├── di/            # Dependency injection setup
│   │   │   │   ├── error/         # Error handling, Sentry
│   │   │   │   ├── network/       # Connectivity, Supabase client
│   │   │   │   ├── theme/         # Design tokens from DESIGN_BRIEF
│   │   │   │   └── utils/         # Shared utilities
│   │   │   │
│   │   │   ├── features/          # Feature modules
│   │   │   │   ├── auth/
│   │   │   │   │   ├── data/
│   │   │   │   │   ├── domain/
│   │   │   │   │   └── presentation/
│   │   │   │   ├── p0/
│   │   │   │   ├── work_order/
│   │   │   │   ├── pasila/
│   │   │   │   ├── harsus/
│   │   │   │   ├── row/
│   │   │   │   ├── maps/
│   │   │   │   ├── asset/
│   │   │   │   ├── sync/          # Sync center, photo upload worker
│   │   │   │   ├── documentation/ # Photo capture, evidence
│   │   │   │   └── settings/
│   │   │   │
│   │   │   ├── shared/            # Shared widgets, components
│   │   │   │   ├── widgets/
│   │   │   │   └── layouts/
│   │   │   │
│   │   │   ├── router.dart
│   │   │   └── main.dart
│   │   │
│   │   ├── android/
│   │   ├── test/
│   │   ├── integration_test/
│   │   ├── pubspec.yaml
│   │   └── .env.example
│   │
│   └── web/                       # Next.js Admin Web
│       ├── src/
│       │   ├── app/               # App Router pages
│       │   │   ├── (auth)/
│       │   │   │   └── login/
│       │   │   ├── (dashboard)/
│       │   │   │   ├── layout.tsx
│       │   │   │   ├── page.tsx
│       │   │   │   ├── p0/
│       │   │   │   ├── wo/
│       │   │   │   ├── pasila/
│       │   │   │   ├── harsus/
│       │   │   │   ├── row/
│       │   │   │   ├── special-case/
│       │   │   │   ├── maps/
│       │   │   │   ├── assets/
│       │   │   │   ├── master/
│       │   │   │   └── settings/
│       │   │   └── api/
│       │   │       └── photos/
│       │   │           └── sign/
│       │   │
│       │   ├── components/
│       │   ├── lib/
│       │   ├── hooks/
│       │   ├── types/
│       │   └── styles/
│       │
│       ├── public/
│       ├── package.json
│       ├── next.config.ts
│       ├── tsconfig.json
│       └── .env.example
│
├── supabase/
│   ├── config.toml
│   ├── migrations/
│   │   ├── 00001_initial_schema.sql
│   │   ├── 00002_gangguan_raw.sql
│   │   ├── 00003_junction_tables.sql
│   │   ├── 00004_rls_policies.sql
│   │   ├── 00005_functions.sql
│   │   ├── 00006_triggers.sql
│   │   ├── 00007_rpc_functions.sql
│   │   └── 00008_seed_app_config.sql
│   ├── functions/
│   │   ├── powersync-write/
│   │   │   └── index.ts
│   │   ├── generate-wo/
│   │   │   └── index.ts
│   │   ├── detect-special-case/
│   │   │   └── index.ts
│   │   ├── assign-p0/
│   │   │   └── index.ts
│   │   ├── generate-photo-upload-url/
│   │   │   └── index.ts
│   │   ├── process-gangguan-import/
│   │   │   └── index.ts
│   │   └── _shared/
│   │       ├── supabase.ts
│   │       ├── s3.ts
│   │       └── validators.ts
│   └── seed.sql
│
├── scripts/
│   ├── migrate/
│   │   ├── index.ts
│   │   ├── validators.ts
│   │   ├── transformers.ts
│   │   └── importers/
│   └── setup/
│
├── infrastructure/
│   ├── s3.tf
│   ├── cloudfront.tf
│   └── iam.tf
│
├── .github/
│   └── workflows/
│       ├── mobile-ci.yml
│       ├── web-ci.yml
│       ├── supabase-ci.yml
│       └── migration-check.yml
│
├── .gitignore
├── README.md
└── LICENSE
```

## 28.2 Rationale for Monorepo

- **Shared types/contracts.** Database schema is the single source of truth. Both Flutter and Next.js can reference the same migration files.
- **Atomic PRs.** A feature that touches mobile, web, and database can be reviewed in one PR.
- **Simplified CI.** One repository to configure CI/CD pipelines.
- **Manageable scope.** For a single-ULP product with a small team, the overhead of multiple repos outweighs the benefits.

---

# 29. Technical Risks

| # | Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|---|
| R-01 | **PowerSync sync conflicts** | Data inconsistency between devices | Medium (multi-device per account not ruled out) | Optimistic concurrency + server-side status validation. Version column on critical tables. Monitor conflict rejections. |
| R-02 | **Photo upload backlog on slow connections** | Hundreds of unuploaded photos accumulating | Medium | Configurable compression. Background upload with retry. Sync Center visibility. Storage warnings. |
| R-03 | **WO generation algorithm produces poor recommendations** | Field teams lose trust in the system | Medium | Make algorithm weights configurable in `app_config`. Log recommendation reasons. Allow Admin override. Iterate based on field feedback. |
| R-04 | **Google Maps API quota exceeded** | Maps stop rendering | Low | Set API key restrictions and budget alerts. Provide list-based fallback. |
| R-05 | **Supabase Edge Function cold starts** | Slow response for photo URL signing | Low | Cold starts acceptable for scheduled jobs. Photo URL signing tolerable at 1–2s. |
| R-06 | **Spreadsheet data quality issues during migration** | Incorrect asset data, wrong section mappings | High | Thorough validation script with dry-run mode. Manual review before production import. Business key validation. |
| R-07 | **Device storage exhaustion from cached photos** | App cannot save new photos | Medium | Monitor storage. Auto-cleanup after confirmed sync. Configurable warning threshold. |
| R-08 | **Supabase platform outage** | All backend services unavailable | Low | Android offline-first mitigates field impact. Admin web unavailable. |
| R-09 | **PowerSync service outage** | Sync stops working | Low | Android continues offline. Queue syncs on recovery. |
| R-10 | **JWT token expiration while offline** | User cannot authenticate after extended offline period | Medium | Long refresh token lifetime for Android. Token refresh on reconnection before sync. |
| R-11 | **Gangguan data import format changes** | Import script breaks | Medium | Validate source format before import. Version import script. Clear error messages. Raw staging table preserves source schema. |
| R-12 | **Android device fragmentation** | UI/functionality issues on diverse devices | Medium | Target minimum Android API level (TBD per OQ-17). Test on common field devices. Use Flutter's abstraction layer. |
| R-13 | **Cloud service governance rejection** | PLN policy prohibits use of proposed cloud services | High | See §31 Cloud Governance. Must be resolved before real PLN data is uploaded to third-party cloud services and before production deployment. Development/prototyping with mock/synthetic data may proceed in parallel. |
| R-14 | **Gangguan source schema change** | Raw table structure no longer matches source | Medium | `gangguan_raw` preserves source schema exactly. Schema changes require migration + view refresh. |

---

# 30. Open Questions

These are questions that require business or organizational decisions. They are carried forward from the PRD and supplemented with technical questions discovered during specification.

## Business Open Questions (from PRD)

| # | Source | Question | Technical Implication |
|---|---|---|---|
| OQ-01 | PRD OQ-01 | **Formula prioritas WO:** Faktor apa saja dan bobot masing-masing yang menentukan 6 aset terpilih per shift? | WO generation algorithm weights stored in `app_config`. Initial values must be defined before WO generation can work. All candidate factors in §18.2 are proposals. |
| OQ-02 | PRD OQ-02 | **6 WO ketika banyak P0:** Apakah target 6 WO/shift tetap wajib ketika shift tersebut memiliki banyak P0? | Affects WO generation logic: always generate 6, or reduce based on P0 count. |
| OQ-03 | PRD OQ-03 | **Manual WO jenis lain:** Apakah WO manual Admin hanya PEMASANGAN_APKUK atau boleh jenis preventive lain? | Affects `jenis_wo` enum values and WO form fields in admin web. |
| OQ-04 | PRD OQ-04 | **PASILA target selection:** Ketika tidak ada P0, apakah area PASILA selalu berdasarkan penyulang dengan trip tertinggi? Periode perhitungan trip? | Affects PASILA suggestion algorithm and `app_config` parameters. |
| OQ-05 | PRD OQ-05 | **Special Case period:** Menggunakan bulan kalender, rolling 30 hari, atau periode lain? | Affects `detect_special_case` query. Configurable via `app_config`. |
| OQ-06 | PRD OQ-06 | **ROW scheduling formula:** Bagaimana menentukan `tanggal_row_berikutnya`? Interval berbeda per jenis/kondisi vegetasi? | Needs configurable interval matrix per vegetation type and condition. |
| OQ-07 | PRD OQ-07 | **Petugas data:** Siapa mengelola? Terikat satu Posko? Satu pekerjaan bisa >1 petugas? | Schema uses junction tables supporting multiple petugas. Can be constrained further based on decision. |
| OQ-08 | PRD OQ-08 | **Offline Maps:** Apakah peta dasar perlu tersedia offline? | If required: need different map provider/architecture. Google Maps SDK tidak menyediakan programmatic offline map download. |
| OQ-09 | PRD OQ-09 | **Conflict resolution:** Aturan versi data jika diedit dari dua perangkat offline? | Proposed: tiered approach (optimistic concurrency for status, LWW for informational fields). Requires stakeholder agreement. |
| OQ-10 | PRD OQ-10 | **Harsus titik baru:** Apakah Harsus boleh membuat titik sementara? | If allowed: need `asset` with `status = 'PENDING_VALIDATION'` and Admin validation workflow. |
| OQ-11 | PRD OQ-11 | **Batas dokumentasi:** Pekerjaan mana wajib foto before+after, mana cukup foto hasil? | Configurable in `app_config` as `{work_type → required_photos[]}`. |
| OQ-12 | PRD OQ-12 | **Shift times:** Jam operasional 3 shift dan aturan cutoff? | Stored in `app_config`. Values TBD. pg_cron schedules depend on this. |
| OQ-13 | PRD OQ-13 | **Correction workflow:** Apakah pekerjaan SELESAI bisa diedit Posko, atau hanya via Admin? | Proposed: locked for Posko, Admin can edit. |
| OQ-14 | PRD OQ-14 | **Sumber data gangguan — metode integrasi:** API, CSV import, atau metode lain? | OPEN QUESTION. CSV/XLSX import adalah fallback awal jika akses otomatis ke sumber pusat belum tersedia. Jika Google Spreadsheet sumber dapat diakses secara aman, gunakan import/integration adapter. Jika kemudian tersedia API resmi, adapter dapat diganti tanpa mengubah schema `gangguan_raw`. |

## Technical Open Questions

| # | Question | Technical Implication |
|---|---|---|
| OQ-15 | **PowerSync pricing tier:** What plan is needed for expected device count and sync volume? | Estimate: ~10–20 concurrent devices. Evaluate Free vs Pro. |
| OQ-16 | **Supabase plan tier:** Free, Pro, or Team? | Pro recommended for daily backups and higher limits. PITR availability/cost may require a paid add-on depending on Supabase pricing at deployment time and must be verified during setup. |
| OQ-17 | **Android minimum version:** Oldest Android version on field devices? | Affects API level target, Flutter compatibility, and testing scope. |
| OQ-18 | **App distribution method:** Google Play Store or direct APK? | Affects CI/CD, auto-update mechanism, and signing strategy. |
| OQ-19 | **Login identifier:** Email-based atau username-based login? | Affects Supabase Auth configuration. See §7.1. |
| OQ-20 | **Device policy per account:** Apakah satu akun Posko boleh digunakan di lebih dari satu device bersamaan? Harsus? ROW? | Affects conflict resolution strategy severity, sync data volume, and potential need for device registration. |
| OQ-21 | **Harsus offline data scope:** Apakah Harsus perlu data seluruh aset ULP atau dibatasi per area penugasan? | Affects sync rule design and device storage requirements. |
| OQ-22 | **ROW offline data scope:** Apakah ROW bekerja di seluruh ULP atau per area tertentu? | Same as above for ROW data. |

---

# 31. Cloud Governance

> **OPEN QUESTIONS — Must be resolved before real PLN data is uploaded to third-party cloud services and before production deployment.**  
> *Catatan:* Prototyping dan development awal menggunakan mock / synthetic data diperbolehkan berjalan paralel sementara proses approval tata kelola cloud berlangsung.

| # | Question | Context |
|---|---|---|
| CG-01 | **Approval penggunaan cloud services:** Apakah penggunaan Supabase (hosted di luar Indonesia), Amazon Web Services, Vercel, dan PowerSync telah disetujui oleh kebijakan IT / security PLN untuk menyimpan dan memproses data internal? | Semua data operasional (aset, gangguan, pekerjaan, foto) akan disimpan di layanan cloud pihak ketiga. |
| CG-02 | **Data residency / region:** Apakah ada requirement bahwa data harus disimpan di region tertentu (misalnya Indonesia / Asia Pacific)? | Supabase menawarkan region Singapore (ap-southeast-1). AWS S3 dapat menggunakan ap-southeast-1 atau ap-southeast-3 (Jakarta). Vercel edge network bersifat global. |
| CG-03 | **Data classification:** Apakah data aset jaringan, data gangguan, dan foto lapangan PLN memiliki klasifikasi keamanan tertentu (confidential, internal, public)? | Klasifikasi mempengaruhi encryption requirements, access controls, dan audit requirements. |
| CG-04 | **Data retention policy:** Berapa lama data pekerjaan dan foto harus disimpan? Apakah ada kebijakan retensi atau penghapusan data PLN? | Mempengaruhi S3 lifecycle rules, database archival strategy, dan biaya penyimpanan. |
| CG-05 | **Corporate security requirements:** Apakah ada requirement security tambahan dari PLN seperti VPN, IP whitelisting, penetration testing, security audit, atau compliance standard tertentu? | Dapat mempengaruhi arsitektur jaringan dan pilihan hosting. |
| CG-06 | **Third-party data processing agreement:** Apakah diperlukan Data Processing Agreement (DPA) dengan Supabase, AWS, dan vendor lain? | Standard practice untuk data processing oleh pihak ketiga. |

---

# Appendix A: Glossary

| Term | Definition |
|---|---|
| **ULP** | Unit Layanan Pelanggan — PLN customer service unit |
| **Posko** | Posko / tim wilayah kerja |
| **Harsus** | Tim inspeksi Harsus; kepanjangan resmi TBD |
| **ROW** | Right of Way — vegetation management team |
| **P0** | Penugasan Khusus |
| **WO** | Work Order — planned preventive work task |
| **PASILA** | Patroli Eksekusi Langsung |
| **APKUK** | APKUK; kepanjangan resmi TBD |
| **Section** | Subdivision of a distribution network |
| **Penyulang** | Feeder — distribution feeder line |
| **Trip** | Circuit breaker trip event (fault indicator) |
| **Special Case** | Recurring fault pattern requiring preventive action |
| **RLS** | Row Level Security — PostgreSQL row-level access control |
| **LWW** | Last Write Wins — conflict resolution strategy |
| **CDC** | Change Data Capture — mechanism to detect database changes |
| **PITR** | Point-in-Time Recovery — database backup recovery method |
| **BLoC** | Business Logic Component — Flutter state management pattern |
| **RPC** | Remote Procedure Call — server-side function invoked by client |

---

# Appendix B: Data Flow Diagrams

## B.1 P0 Creation → Completion

```
Source (Admin/Harsus/PASILA/Special Case)
  │
  ▼
Create P0 record (client or Edge Function)
  │
  ├─ assign-p0 trigger:
  │   resolve section → posko_id
  │   ├─ Posko found → assign
  │   └─ No Posko → flag for Admin (EC-04)
  │
  ▼
P0 syncs to Posko device (via PowerSync)
  │
  ▼
Posko: BARU → DITERIMA → DIKERJAKAN
  │
  ├─ Via fn_update_p0_field_data() RPC
  ├─ Version incremented on each update
  ├─ Status transition validated by trigger
  ├─ Capture documentation (photos, petugas, notes)
  │
  ├─ If completed:
  │   └─ DIKERJAKAN → SELESAI
  │       └─ Status history recorded
  │
  └─ If not completed:
      └─ DIKERJAKAN → RESCHEDULED
          ├─ alasan_tidak_selesai required
          └─ May generate follow-up P0 (parent_p0_id link)
```

## B.2 WO Auto-Generation Flow

```
pg_cron trigger (before each shift — time TBD)
  │
  ▼
generate-wo Edge Function
  │
  ├─ For each active Posko:
  │   ├─ Fetch eligible assets (no active APKUK, no active WO/P0)
  │   ├─ Calculate priority_score per asset (algorithm TBD)
  │   ├─ Sort by score DESC
  │   ├─ Select top 6
  │   └─ Insert work_order records
  │
  ▼
WO records sync to Posko device
  │
  ▼
Posko sees new WO in shift home
```

## B.3 Offline → Sync Flow

```
Device goes offline
  │
  ▼
User continues working (writes to local SQLite)
  │
  ├─ Documentation captured → saved to local storage
  ├─ Status changes → saved to local SQLite
  ├─ New records (PASILA actions, etc.) → saved locally
  │
  ▼
Device reconnects
  │
  ├─ PowerSync SDK detects connectivity
  ├─ Upload queue processes:
  │   ├─ Data records → powersync-write Edge Function
  │   │   (validated per resource type, role, ownership, version)
  │   └─ Photos → pre-signed URL → S3 direct upload
  │
  ├─ If version conflict on update:
  │   ├─ Server rejects write
  │   ├─ Client receives current server version
  │   └─ User notified to review
  │
  ├─ Download queue processes:
  │   └─ New/updated records from server → local SQLite
  │
  ▼
UI updates to reflect synced state
  │
  └─ Sync badges: LOCAL → SYNCED
```

## B.4 Gangguan Import Flow (PROPOSED FALLBACK)

> **Catatan Arsitektur:** Diagram berikut merupakan **PROPOSED FALLBACK** menggunakan unggah file manual CSV/XLSX jika akses langsung ke sumber pusat belum tersedia. Jika akses aman ke Google Spreadsheet pusat atau API resmi tersedia, adapter integrasi menggantikan pemicu admin di awal flow tanpa mengubah struktur downstream (`gangguan_raw` dan `gangguan_mv`).

```
[PROPOSED FALLBACK] Admin uploads CSV/XLSX via admin web
  (ATAU: Integration Adapter otomatis membaca Google Sheets / API resmi)
  │
  ▼
process-gangguan-import Edge Function
  │
  ├─ Validate source format (all expected columns present)
  ├─ UPSERT into gangguan_raw (keyed on id_gangguan)
  ├─ Preserve all source columns exactly
  │
  ▼
REFRESH MATERIALIZED VIEW CONCURRENTLY gangguan_mv
  │
  ├─ Resolve asset_id from id_aset (business key → UUID)
  ├─ Resolve section_id from section_id_source
  ├─ Resolve posko_id from posko_id_source
  ├─ Derive is_trip from sifat_gangguan
  │
  ▼
Application queries use gangguan_mv for:
  - WO priority scoring
  - Special Case detection
  - Asset history
```
