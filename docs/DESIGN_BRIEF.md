# DESIGN BRIEF

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Platform:** Android App + Web Application  
**Primary field users:** Posko, Harsus, ROW  
**Primary desktop user:** Admin  
**Design priority:** Field operation first, monitoring second  
**Connectivity assumption:** Tidak stabil; input utama wajib tetap dapat dilakukan offline

---

# 1. Design Principles

## Principle 1 — Prioritas Harus Terbaca dalam < 3 Detik

Petugas tidak boleh membuka beberapa halaman hanya untuk mengetahui pekerjaan yang harus dikerjakan terlebih dahulu.

Begitu masuk aplikasi, hierarchy pekerjaan harus langsung terlihat:

**P0 → WO → PASILA**

P0 selalu memiliki treatment visual paling kuat.

Implikasi UI:

- P0 selalu berada di posisi pertama.
- Gunakan label teks + ikon + warna, bukan warna saja.
- Jangan menyembunyikan pekerjaan prioritas di dalam menu.
- Home Posko harus menjawab:  
  **“Sekarang saya harus mengerjakan apa?”**
- Informasi sekunder seperti statistik bulanan tidak boleh mengalahkan pekerjaan aktif.

---

## Principle 2 — Field Action Lebih Penting daripada Dashboard Cantik

Aplikasi lapangan bukan dashboard analitik mini.

Petugas membutuhkan:

- lokasi;
- instruksi;
- status;
- petugas;
- dokumentasi;
- tombol action.

Semua field harus dapat digunakan dengan cepat ketika:

- berdiri;
- berada di luar ruangan;
- jaringan buruk;
- menggunakan satu tangan;
- berpindah lokasi.

Implikasi:

- touch target minimal 48×48 px;
- tombol utama besar;
- form satu kolom pada mobile;
- kurangi input teks bebas;
- gunakan dropdown, radio, segmented control, dan quick action;
- primary action selalu mudah dijangkau ibu jari;
- jangan gunakan tabel horizontal untuk pekerjaan lapangan.

---

## Principle 3 — Offline Bukan Error State

Offline adalah kondisi kerja normal.

User tidak boleh merasa bahwa aplikasi “rusak” ketika internet hilang.

Sistem harus selalu menjelaskan:

**data disimpan di mana, apakah sudah tersimpan, dan apakah sudah tersinkron.**

Status minimum:

- Tersimpan di perangkat
- Menunggu sinkronisasi
- Sedang sinkron
- Tersinkron
- Gagal sinkron

Tidak boleh ada kondisi user menekan “Simpan”, layar kembali, tetapi user tidak tahu datanya hilang atau tidak.

---

# 2. Visual Direction

## Mood

Visual yang dipilih:

**Operational, confident, industrial, clean, high-visibility.**

Bukan:

- startup playful;
- dashboard finansial;
- aplikasi consumer;
- interface futuristik/neon.

Aplikasi harus terasa seperti:

**alat kerja operasional yang serius tetapi tetap modern dan mudah digunakan.**

---

## Visual Character

### Light background

Gunakan background terang karena sebagian besar field use kemungkinan terjadi pada siang hari.

Background utama:

`#F5F7FA`

Surface:

`#FFFFFF`

Dark mode bukan prioritas MVP.

---

## Primary visual

Gunakan kombinasi **deep navy + operational blue + safety yellow**.

Alasannya:

- navy memberikan kesan utilitas/infrastruktur dan cukup kuat untuk dashboard;
- biru cocok untuk navigasi, selected state, link, dan pekerjaan normal;
- kuning digunakan sangat terbatas untuk warning/perhatian;
- merah hanya digunakan untuk kondisi benar-benar prioritas seperti P0 atau error.

Palette ini bukan klaim warna brand resmi PLN. Tujuannya adalah membangun UI utilitas yang jelas dan kontras.

---

## Module Treatment

Jangan membuat setiap modul seperti aplikasi berbeda.

Gunakan satu design system, lalu beri accent semantik:

**P0**  
Merah — urgent/highest priority.

**WO**  
Biru — planned preventive work.

**PASILA**  
Teal — patrol/action.

**Harsus**  
Amber — inspection/finding.

**ROW**  
Hijau — vegetation.

Warna tidak boleh menjadi satu-satunya pembeda. Selalu tampilkan ikon dan label modul.

---

## Hindari

- glassmorphism;
- gradient berlebihan;
- card di dalam card di dalam card;
- shadow tebal;
- dashboard berisi puluhan KPI kecil;
- icon-only navigation tanpa label;
- status hanya berdasarkan warna;
- tombol primary lebih dari satu pada satu section;
- teks abu-abu terlalu pucat;
- peta penuh marker tanpa filter;
- modal bertumpuk;
- action penting hanya muncul ketika hover.

---

# 3. Design Tokens

## 3.1 Color Palette

### Core

| Token | Hex | Penggunaan |
|---|---|---|
| Navy 950 | `#081522` | Header/sidebar sangat gelap |
| Navy 900 | `#0B1F33` | Primary text/navigation |
| Blue 700 | `#1557B0` | Active navigation |
| Blue 600 | `#1769E0` | Primary action |
| Blue 100 | `#DCEAFF` | Selected/light info |
| Background | `#F5F7FA` | App background |
| Surface | `#FFFFFF` | Card/form/modal |
| Border | `#D7DEE7` | Divider/input border |
| Text Primary | `#16202A` | Main text |
| Text Secondary | `#52606D` | Supporting text |
| Disabled | `#98A2AD` | Disabled content |

### Semantic

| Status | Main | Light BG |
|---|---|---|
| Success | `#18794E` | `#E8F5EE` |
| Warning | `#9A6700` | `#FFF4CE` |
| Danger | `#C9362B` | `#FDECEA` |
| Info | `#1769E0` | `#EAF2FF` |
| Offline | `#5F6B76` | `#EEF1F4` |

### Module Accent

| Modul | Warna |
|---|---|
| P0 | `#C9362B` |
| Work Order | `#1769E0` |
| PASILA | `#087F8C` |
| Harsus | `#A15C00` |
| ROW | `#18794E` |

---

# 3.2 Typography

## Font

**Inter**

Alasan:

- angka dan ID mudah dibaca;
- cocok untuk dense information;
- jelas pada ukuran kecil;
- sangat baik untuk Android maupun web;
- memiliki hierarchy yang kuat tanpa terasa dekoratif.

Fallback:

`Inter, Roboto, Arial, sans-serif`

---

## Type Scale

| Style | Size / Line-height | Weight |
|---|---|---|
| Display | 32 / 40 | 700 |
| H1 | 28 / 36 | 700 |
| H2 | 22 / 30 | 700 |
| H3 | 18 / 26 | 600 |
| Body Large | 17 / 26 | 400 |
| Body | 16 / 24 | 400 |
| Body Small | 14 / 20 | 400 |
| Label | 14 / 20 | 600 |
| Caption | 12 / 16 | 500 |
| Metric | 28 / 32 | 700 |

Minimum body pada mobile:

**14 px**, tetapi isi pekerjaan utama menggunakan **16 px**.

---

# 3.3 Spacing Scale

Base unit: **4 px**

`4 / 8 / 12 / 16 / 24 / 32 / 40 / 48 / 64`

Default:

- icon ↔ text: 8 px
- form field gap: 16 px
- card padding: 16 px
- section gap mobile: 24 px
- section gap desktop: 32 px
- page padding mobile: 16 px
- tablet: 24 px
- desktop: 32 px

---

# 3.4 Radius

| Element | Radius |
|---|---|
| Input | 8 px |
| Button | 10 px |
| Card | 12 px |
| Bottom sheet/modal | 16 px |
| Status pill | 999 px |

Jangan menggunakan radius terlalu bulat pada semua elemen karena aplikasi harus terasa operational, bukan consumer lifestyle.

---

# 3.5 Shadow

### Elevation 1

`0 1px 2px rgba(11,31,51,0.08)`

Untuk:

- card;
- toolbar.

### Elevation 2

`0 4px 12px rgba(11,31,51,0.12)`

Untuk:

- modal;
- bottom sheet;
- floating panel.

Tidak ada shadow dekoratif.

---

# 4. Screen Inventory

## Shared

| Screen | Tujuan |
|---|---|
| Splash / Bootstrap | Load account, local database, dan status sync |
| Login | Authentication |
| Notification Center | Pekerjaan baru, P0, sync issue |
| Sync Center | Melihat queue offline/sync |
| Profile | Informasi akun/role |
| Settings | Preferensi dasar |

---

## Posko

| Screen | Tujuan |
|---|---|
| Posko Shift Home | Menampilkan pekerjaan shift dengan prioritas |
| Work List | Semua pekerjaan aktif Posko |
| P0 Detail | Detail dan eksekusi P0 |
| WO Detail | Detail dan eksekusi WO |
| PASILA Start | Memulai PASILA |
| PASILA Session | Menjalankan patroli |
| PASILA Action Form | Input satu temuan/action |
| PASILA Summary | Ringkasan sesi |
| Asset Detail | Detail + histori aset |
| Maps | Lokasi pekerjaan/aset |
| Work History | Riwayat pekerjaan Posko |

---

## Harsus

| Screen | Tujuan |
|---|---|
| Harsus Home | Daftar inspeksi/tugas |
| New Inspection | Input inspeksi |
| Inspection Detail | Detail temuan |
| Create P0 Review | Konfirmasi temuan menjadi P0 |
| Inspection History | Histori inspeksi |
| Maps | Memilih/melihat aset |

---

## ROW

| Screen | Tujuan |
|---|---|
| ROW Home | Daftar pekerjaan vegetasi |
| ROW Job Detail | Detail titik vegetasi |
| ROW Execution | Input pekerjaan |
| Vegetation Detail | Kondisi + histori vegetasi |
| ROW History | Histori pekerjaan |
| Maps | Persebaran vegetasi |

---

## Admin Web

| Screen | Tujuan |
|---|---|
| Admin Dashboard | Overview operasional |
| Work Monitor | Monitoring P0/WO/PASILA |
| P0 Management | List, filter, create manual |
| WO Management | List, recommendation, manual WO |
| PASILA Monitor | Monitoring sesi/action |
| Harsus Monitor | Inspeksi dan temuan |
| ROW Monitor | Monitoring vegetasi |
| Special Case | Gangguan berulang |
| Maps | Monitoring spasial |
| Asset Detail | Profil + histori aset |
| Master Data Hub | Akses seluruh master |
| Posko Management | Posko |
| Section Management | Mapping section |
| Asset Management | Aset |
| APKUK Management | APKUK |
| Vegetation Management | Vegetasi |
| Petugas Management | Dropdown petugas |

Ranking Posko **tidak masuk MVP**.

---

# 5. User Flow

## 5.1 Posko — Mulai Shift

1. Login.
2. Sistem membaca `POSKO_ID`.
3. Local data dan sync queue diperiksa.
4. User masuk `Shift Home`.
5. Sistem menampilkan:
   - P0 aktif;
   - progres WO;
   - PASILA.
6. Jika ada P0:
   - P0 berada paling atas.
7. User membuka pekerjaan.
8. Pilih petugas.
9. Mulai pekerjaan.
10. Dokumentasi.
11. Selesai.
12. Lanjut pekerjaan berikutnya.

---

# 5.2 P0

1. Posko membuka P0.
2. Melihat:
   - titik aset;
   - section;
   - jenis pekerjaan;
   - sumber;
   - alasan;
   - kebutuhan padam/material.
3. Tekan **Navigasi** bila diperlukan.
4. Pilih petugas.
5. Tekan **Mulai Pekerjaan**.
6. Status → `DIKERJAKAN`.
7. Input hasil.
8. Tambah foto/dokumentasi.
9. Jika selesai:
   - tekan **Selesaikan P0**.
10. Jika tidak:
   - pilih **Belum Bisa Diselesaikan**;
   - alasan wajib;
   - sistem mempertahankan/menjadwalkan tindak lanjut.

---

# 5.3 Work Order

1. Posko melihat WO.
2. Sistem menunjukkan alasan titik dipilih.
3. Cek detail aset.
4. Pilih petugas.
5. Navigasi.
6. Mulai WO.
7. Lakukan pemasangan APKUK.
8. Foto before.
9. Input APKUK.
10. Foto after.
11. Selesaikan WO.
12. APKUK masuk histori aset.

---

# 5.4 PASILA

1. Posko membuka PASILA.
2. Lihat:
   - penyulang;
   - section;
   - titik prioritas.
3. Tekan **Mulai PASILA**.
4. Sistem memulai sesi.
5. Petugas patroli.
6. Jika menemukan kondisi:
   - tekan **Tambah Action**.
7. Pilih titik/aset.
8. Pilih jenis temuan.
9. Tentukan:
   - bisa diselesaikan;
   - tidak bisa diselesaikan.
10. Jika bisa:
    - action;
    - foto before/after;
    - simpan.
11. Jika tidak:
    - alasan;
    - kebutuhan padam/material;
    - dokumentasi;
    - **Buat P0**.
12. Ulangi untuk temuan berikutnya.
13. Tekan **Akhiri PASILA**.
14. Sistem tampilkan:
    - total action;
    - selesai;
    - menjadi P0;
    - ROW insidental.

---

# 5.5 Harsus

1. Login.
2. Buka **Inspeksi Baru**.
3. Pilih aset melalui search/Maps.
4. Input kondisi.
5. Input temuan.
6. Foto.
7. Tentukan:
   - perlu padam;
   - tanpa padam.
8. Input rekomendasi.
9. Tentukan perlu tindak lanjut.
10. Jika iya:
    - review P0;
    - submit.
11. Sistem menentukan Posko berdasarkan section.
12. Temuan + P0 masuk histori.

---

# 5.6 ROW

1. ROW melihat daftar pekerjaan vegetasi.
2. Pilih pekerjaan.
3. Lihat:
   - lokasi;
   - vegetasi;
   - kondisi;
   - histori ROW;
   - apakah pernah ditangani Posko.
4. Navigasi.
5. Mulai.
6. Pilih tindakan:
   - rabas;
   - pangkas;
   - tebang.
7. Dokumentasi before.
8. Pekerjaan.
9. Dokumentasi after.
10. Input kondisi terbaru.
11. Selesai.
12. Histori vegetasi diperbarui.

---

# 5.7 Admin Monitoring

1. Login web.
2. Dashboard menunjukkan kondisi hari ini.
3. Admin dapat drill-down:
   - Posko;
   - P0;
   - WO;
   - PASILA;
   - Harsus;
   - ROW;
   - Special Case.
4. Pilih record.
5. Buka detail.
6. Jika perlu:
   - create P0;
   - create manual WO;
   - reassignment;
   - correction.

---

# 5.8 Offline → Sync

1. Connection hilang.
2. Banner:

**Offline — input tetap dapat dilakukan.**

3. User tetap bekerja.
4. Save menghasilkan status:

**Tersimpan di perangkat.**

5. Queue tampil pada Sync Center.
6. Internet kembali.
7. Sistem sync otomatis.
8. Status:

`LOCAL → SYNCING → SYNCED`

9. Jika gagal:
   - `FAILED`;
   - tombol **Coba Lagi**.

---

# 6. Layout per Screen

## 6.1 Posko Shift Home

### Hierarchy

1. Shift header
2. Connectivity/sync state
3. P0 priority
4. WO progress
5. PASILA
6. Quick access Maps/history

### Layout

**Top App Bar**
- Posko
- Shift
- Online/offline badge
- notification

**Priority P0 Block**
- jumlah P0
- P0 pertama
- urgency
- lokasi

**Primary action:**  
`Kerjakan P0`

**WO Progress**
- `4 / 6 selesai`
- progress bar
- next WO

**PASILA Card**
- section
- status
- CTA

**Bottom navigation**
- Home
- Pekerjaan
- PASILA
- Maps
- Riwayat

---

# 6.2 Work List

Sections:

- segmented control:
  - P0
  - WO
  - PASILA
- search
- filter shift/status
- list card

Card hierarchy:

1. type
2. asset
3. section
4. status
5. priority/reason
6. sync indicator

Primary action:

`Buka pekerjaan`

---

# 6.3 P0 Detail

Sections:

1. Priority header
2. Job identity
3. Source P0
4. Asset/location
5. Work instruction
6. Requirement padam/material
7. History/context
8. Petugas
9. Documentation
10. Status timeline

Sticky bottom action:

Before start:

`Mulai Pekerjaan`

During:

`Selesaikan`

Secondary:

`Tidak Bisa Diselesaikan`

---

# 6.4 WO Detail

Struktur sama dengan P0 tetapi header biru.

Tambahan section:

**Kenapa titik ini dipilih?**

Berisi alasan prioritas.

APKUK section:

- status APKUK existing;
- jenis pekerjaan;
- installation result.

Primary:

`Mulai WO`

---

# 6.5 PASILA Session

Top:

- timer/session status
- Posko
- section

Map mini preview.

Summary counters:

- Action
- Selesai
- P0
- ROW

Action list berbentuk timeline.

Floating primary CTA:

`+ Tambah Action`

Bottom sticky:

`Akhiri PASILA`

---

# 6.6 PASILA Action Form

Sections:

1. Pilih aset/titik
2. Temuan
3. Bisa diselesaikan?
4. Action
5. Padam/material
6. Before/after
7. Notes

Jika `Tidak Bisa`:

form berubah dan CTA menjadi:

`Buat P0`

---

# 6.7 Harsus New Inspection

Sections:

1. asset selector
2. location/context
3. kondisi
4. jenis temuan
5. urgency
6. perlu/tanpa padam
7. rekomendasi
8. foto
9. tindak lanjut

Primary:

`Simpan Inspeksi`

Jika tindak lanjut:

`Review & Buat P0`

---

# 6.8 ROW Job Detail

Sections:

1. vegetation identity
2. Maps/location
3. current condition
4. distance/risk
5. last ROW
6. previous actions
7. work instruction
8. petugas

Primary:

`Mulai ROW`

---

# 6.9 Maps Mobile

Full-screen map.

Top:

- search
- filter button

Bottom sheet ketika marker dipilih:

- asset name
- type
- section
- Posko
- condition
- active work

Actions:

`Detail`

`Navigasi`

Filter menggunakan bottom sheet, bukan panel permanen.

---

# 6.10 Asset Detail

Hero summary:

- asset ID
- name/type
- section
- status

Tabs:

- Overview
- Gangguan
- Pekerjaan
- APKUK
- Vegetasi

Primary action tergantung role.

Untuk field user:

`Navigasi`

Admin tidak membutuhkan CTA penugasan permanen di hero; action ditempatkan di action menu.

---

# 6.11 Sync Center

Header:

**Sinkronisasi Data**

Summary:

- synced
- waiting
- failed

Tabs:

- Semua
- Menunggu
- Gagal

Queue item:

- record type
- timestamp
- size/foto
- state

Primary untuk failed:

`Coba Lagi`

---

# 6.12 Admin Dashboard

Desktop-first.

### Top row

- date/filter
- Posko filter
- section filter

### Operational summary

4–6 KPI maksimum:

- P0 aktif
- WO selesai/target
- PASILA berjalan
- Temuan Harsus
- ROW aktif
- Special Case

### Critical queue

P0 overdue / Special Case.

### Posko activity

Table/list per Posko.

### Map preview

Spatial overview.

### Recent activity

Timeline.

Tidak menggunakan 15–20 KPI cards.

---

# 6.13 Admin Work Monitor

Split:

**Left/center:** filter + data table  
**Right drawer:** detail record

Columns:

- type
- ID
- asset
- section
- Posko
- status
- created
- updated

Primary:

`Buat P0`

atau sesuai tab:

`Buat WO Manual`

---

# 6.14 Master Data Screens

Template yang sama:

- title
- search
- filters
- table
- import/add button
- edit drawer

Desktop menggunakan table.

Mobile Admin, jika diperlukan, menggunakan card list.

---

# 7. Component Library

## App Shell

Variants:

- Android field shell
- Admin desktop shell

States:

- online
- offline
- sync issue

---

## Button

Variants:

- Primary
- Secondary
- Tertiary
- Danger
- Icon
- Floating Action

States:

- default
- pressed
- focus
- disabled
- loading

Minimum height:

**48 px**

---

## Job Card

Variants:

- P0
- WO
- PASILA
- Harsus
- ROW

Content:

- module
- ID
- asset
- section
- status
- time
- priority
- sync

States:

- new
- active
- completed
- rescheduled
- offline/local
- error sync

---

## Status Badge

Variants:

- Baru
- Diterima
- Dikerjakan
- Selesai
- Rescheduled
- Cancelled
- Local
- Syncing
- Failed

Tidak hanya warna. Gunakan icon + label.

---

## Priority Banner

Variants:

- P0
- Special Case
- overdue

---

## Connectivity Banner

Variants:

**Offline**
**Syncing**
**Sync failed**

Persistent tapi compact.

---

## Sync Badge

Icon + text:

- Lokal
- Menunggu
- Sinkron
- Gagal

---

## Asset Selector

Variants:

- search
- recent
- Map select
- scan/other future

States:

- selected
- unavailable
- unknown

---

## Petugas Selector

Multi-select dropdown.

States:

- empty
- selected
- inactive member
- validation error

---

## Evidence Capture

Elements:

- camera button
- preview
- delete/retake
- type: Before / After / Condition

States:

- empty
- captured
- local
- uploaded
- failed upload

---

## Section / Posko Chip

Compact informational element.

---

## Timeline

Untuk:

- status pekerjaan;
- histori aset;
- PASILA actions.

---

## Map Marker

Variants:

- Asset normal
- P0
- WO
- PASILA
- Harsus finding
- Vegetation
- Special Case

Selected marker membesar + outline.

---

## Bottom Sheet

Digunakan mobile untuk:

- marker detail;
- filter;
- confirmation;
- action selection.

---

## Data Table

Admin web.

Features:

- sticky header
- sort
- filter
- selectable row
- pagination/virtualization

---

## KPI Tile

Maximum 6 per view.

Variants:

- neutral
- attention
- critical

---

## Form Field

- text
- textarea
- numeric
- select
- date/time
- radio
- segmented
- checkbox

States:

- default
- focus
- filled
- invalid
- disabled
- offline saved

---

## Confirmation Dialog

Digunakan hanya untuk irreversible/high-impact actions:

- cancel;
- close PASILA;
- complete job;
- discard offline draft.

---

## Toast

Hanya untuk feedback pendek.

Contoh:

**Tersimpan di perangkat**

Jangan gunakan toast untuk error yang membutuhkan action.

---

# 8. Screen States

## Posko Home

### Empty
“Tidak ada pekerjaan P0 saat ini.”

WO dan PASILA tetap tampil.

### Loading
Skeleton untuk priority card dan WO.

### Error
Data online gagal → gunakan cache terakhir bila tersedia.

### Success
Home normal.

### Offline
Banner persistent + timestamp:

**Data terakhir diperbarui 10:42**

---

## Job List

### Empty
Tidak ada pekerjaan sesuai filter.

CTA:

`Reset filter`

### Loading
5 skeleton cards.

### Error
Retry + cached data.

### Offline
Cached list + local status badge.

---

## P0 / WO Detail

### Loading
Header + section skeleton.

### Error
Jika cached copy tersedia → tampilkan cache.

### Success
Detail normal.

### Offline
Action tetap aktif.

Label:

**Perubahan akan disinkronkan nanti.**

---

## PASILA

### Empty
Belum ada action.

Prompt:

**Mulai patroli dan tambahkan temuan ketika ditemukan.**

### Loading
Session load.

### Error
Jangan menutup sesi otomatis.

### Success
Summary setelah selesai.

### Offline
Seluruh action tersimpan lokal.

---

## Harsus Inspection

### Empty
Fresh form.

### Loading
Asset context loading.

### Error
Jika aset gagal di-load, gunakan cached asset list.

### Success
Inspeksi tersimpan.

### Offline
Success message harus mengatakan:

**Inspeksi tersimpan di perangkat dan menunggu sinkronisasi.**

Bukan:

**Berhasil dikirim.**

---

## ROW

### Empty
“Tidak ada pekerjaan vegetasi aktif.”

### Loading
Skeleton list.

### Error
Retry/cached.

### Offline
Pekerjaan cached tetap dapat dijalankan.

---

## Maps

### Empty
Tidak ada titik untuk filter tersebut.

### Loading
Map skeleton + progressive markers.

### Error
Jika base map gagal:

tetap tampilkan daftar lokasi/titik.

### Offline
Cached map bila tersedia.

Jika tidak:

tampilkan **list titik**, bukan blank screen.

---

## Admin Dashboard

### Empty
Dashboard menggunakan zero-state explanatory text.

### Loading
Skeleton KPI + table.

### Error
Error per-widget, bukan seluruh dashboard mati.

### Success
Normal.

### Offline
Web Admin tidak perlu full offline operation.

Tampilkan warning bahwa data mungkin tidak terbaru.

---

## Sync Center

### Empty
“Semua data sudah tersinkron.”

### Loading
Checking queue.

### Error
Show affected records.

### Success
“All synced.”

### Offline
Queue tetap dapat dilihat, retry dinonaktifkan sampai koneksi kembali.

---

# 9. Responsive Behaviour

## Mobile — 360–767 px

Primary environment untuk Posko/Harsus/ROW.

Navigation:

**Bottom navigation**

Maximum 5 items.

Forms:

- 1 column;
- sticky bottom CTA;
- 16 px padding.

Cards:

full width.

Tables:

tidak digunakan untuk field operations.

Maps:

full screen + bottom sheet.

---

## Tablet — 768–1199 px

Field use / supervisor use.

Navigation:

bottom nav atau compact side rail tergantung orientation.

Maps:

split map + detail panel memungkinkan.

Forms:

1–2 columns.

PASILA:

map dan action list dapat side-by-side landscape.

---

## Desktop — ≥1200 px

Primary environment Admin.

Navigation:

fixed left sidebar 240–264 px.

Content:

max content width sekitar 1440 px untuk dashboard.

Maps:

split-screen:

**Map 60–70% + detail 30–40%**

Management screens:

tables + side drawer.

Forms:

2-column hanya untuk field yang saling berkaitan.

Jangan membuat form lebih dari 3 column.

---

## Breakpoint Behaviour

Navigation berubah berdasarkan space, bukan sekadar mengecil.

Desktop table tidak boleh dipaksa menyusut menjadi table kecil pada mobile.

Transform:

**Table → Card List**

Sidebar:

**Sidebar → Bottom Nav**

Drawer:

**Right Drawer → Bottom Sheet**

---

# 10. Accessibility

Target minimum:

**WCAG 2.2 AA**

---

## Contrast

Normal text:

minimum **4.5:1**

Large text:

minimum **3:1**

UI component boundary/focus:

minimum **3:1**

Warna status harus tetap dapat dimengerti dalam grayscale.

Contoh:

Jangan hanya:

🔴

Gunakan:

`● P0 — Prioritas Tinggi`

---

## Focus

Web:

Setiap interactive element memiliki visible focus ring.

Token:

`2px solid #1769E0`

offset:

`2px`

Urutan focus harus mengikuti hierarchy visual.

---

## Keyboard Navigation

Admin Web harus 100% usable dengan:

- Tab
- Shift+Tab
- Enter
- Space
- Escape
- Arrow keys untuk menu/select sesuai pattern

Tidak boleh ada action penting yang hanya tersedia melalui mouse hover.

---

## Touch

Minimum touch target:

**48×48 px**

Gap minimum antar touch target utama:

**8 px**

---

## Forms

Setiap field mempunyai:

- visible label;
- optional helper text;
- error message spesifik.

Tidak boleh menggunakan placeholder sebagai satu-satunya label.

Contoh buruk:

`Masukkan alasan`

Contoh benar:

**Alasan pekerjaan tidak dapat diselesaikan**  
`Jelaskan material, padam, atau kendala lainnya.`

---

## Error Messaging

Error harus menjelaskan:

1. apa yang gagal;
2. apakah data aman;
3. tindakan berikutnya.

Contoh:

**Foto belum tersinkron. Data pekerjaan sudah tersimpan di perangkat. Coba lagi saat koneksi tersedia.**

---

## ARIA — Web

Gunakan semantic HTML terlebih dahulu.

Tambahkan ARIA hanya bila native semantic tidak cukup.

Kebutuhan:

- `aria-label` icon-only controls;
- `aria-expanded` expandable filters;
- `aria-live="polite"` untuk sync state;
- `aria-current` navigation aktif;
- `aria-describedby` form error/helper;
- dialog menggunakan proper dialog semantics.

---

## Android Accessibility

Semua control harus memiliki accessibility label untuk TalkBack.

Jangan menggabungkan informasi status hanya dalam icon.

Contoh announcement:

**“P0, prioritas tertinggi, aset T-001, status baru.”**

---

## Maps Accessibility

Maps tidak boleh menjadi satu-satunya cara menemukan pekerjaan.

Semua marker harus memiliki representasi dalam:

**List View**

Sehingga user yang kesulitan menggunakan map tetap dapat:

- mencari aset;
- membuka detail;
- navigasi;
- melakukan pekerjaan.

---

# Final Design Decision

Produk ini menggunakan dua pengalaman yang saling terhubung tetapi tidak identik.

## Android

Dirancang sebagai **field work application**.

Prioritas:

**Tugas → lokasi → action → dokumentasi → sync.**

Bukan dashboard.

## Web

Dirancang sebagai **operational control application**.

Prioritas:

**monitor → filter → inspect → assign → intervene.**

Bukan versi desktop dari aplikasi Android.

Keduanya menggunakan design tokens, terminology, component logic, status, dan visual language yang sama agar user tetap merasa berada dalam satu produk.

## North Star UX

Ketika seorang petugas Posko membuka aplikasi di tengah shift, bahkan dalam kondisi offline, dalam beberapa detik dia harus bisa menjawab tiga pertanyaan:

**Apa yang harus saya kerjakan sekarang?**

**Di mana lokasinya?**

**Apa yang harus saya lakukan dan laporkan setelah selesai?**

Jika UI tidak bisa menjawab ketiga pertanyaan tersebut dengan cepat, desain dianggap gagal.
