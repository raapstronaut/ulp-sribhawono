# Panduan API Mobile POSKO
## ULP Sribhawono — untuk pengembang aplikasi Flutter

- **Versi:** B3.5, 1 November 2026. Basis kode: branch `feat/admin-web-foundation-v1` setelah commit `5199a31` (B3.4).
- **Isi:** endpoint yang benar-benar berjalan di backend. Contoh respons direkam dari server lokal dengan data simulasi (tanggal 29 Okt 2026, Shift A). ID di contoh hanya ilustrasi.
- **Kontrak dijaga tes:** `backend/tests/Feature/Operations/MobileApiContractTest.php`. Bila bentuk respons berubah, tes itu gagal dan panduan ini harus diperbarui.
- Rencana endpoint yang **belum** dibangun ada di §10. Jangan dipakai.

---

## 1. Dasar

| Hal | Nilai |
|---|---|
| Base URL | `{SERVER}/api/v1/mobile` |
| Header | Kirim `Accept: application/json` di setiap request (server sudah membalas JSON untuk `/api/*`, header ini menjaga perilaku tetap konsisten) |
| Autentikasi | `Authorization: Bearer {token}` (Sanctum) untuk semua endpoint kecuali login |
| Body | JSON (`Content-Type: application/json`), kecuali laporan WO dan temuan PASILA: `multipart/form-data` |
| Array di multipart | `personnel_ids[0]`, `personnel_ids[1]` atau `personnel_ids[]`; foto `photos[0]`, `photos_before[0]`, ... |
| Waktu dari server | ISO-8601 UTC, mis. `2026-10-29T01:30:00+00:00` (= 08.30 WIB) |
| Waktu ke server | ISO-8601 **dengan offset**, mis. `2026-10-29T08:30:00+07:00`. Tanpa offset dibaca sebagai WIB |
| ID | Akun: integer. Lainnya (tim, petugas, WO, sesi, temuan): UUID string |
| Batas laju | Login 6 request/menit; endpoint lain 120 request/menit per akun. Lebih → 429 |

Setiap respons sukses berisi `meta.server_time`. Bandingkan dengan jam HP: server menolak `occurred_at` lebih dari **10 menit** di depan jam server.

## 2. Format respons dan galat

Sukses: `{ "data": ..., "meta": { "server_time": ..., ... } }`

Galat selalu memiliki `message` (Bahasa Indonesia, layak ditampilkan), kecuali 401 dan 429 yang memakai pesan bawaan Laravel berbahasa Inggris:

| HTTP | Arti | Body | Tindakan aplikasi |
|---|---|---|---|
| 401 | Token tidak ada, salah, atau sudah logout | `{"message": "Unauthenticated."}` | Hentikan pengiriman, minta login ulang dengan **akun yang sama**. Jangan hapus antrean |
| 403 | Akun bukan tim yang berhak (mis. HARSUS ke PASILA) | `{"message": "Hanya akun tim POSKO yang dapat memakai PASILA."}` | Tampilkan pesan; jangan dicoba ulang |
| 403 + `errors.auth` | Akun atau tim dinonaktifkan Admin | `{"message": "...", "errors": {"auth": ["..."]}}` | Keluar dari akun, simpan antrean, hubungi Admin |
| 404 | Data tidak ada **atau** milik tim/Posko lain (sengaja tidak dibedakan) | `{"message": "WO tidak ditemukan."}` / `{"message": "Sesi PASILA tidak ditemukan untuk Posko tim ini."}` | Tandai gagal, perlu tindakan |
| 409 | Konflik alur kerja: status data di server sudah berubah | `{"message": "...", "errors": {"workflow": ["..."]}}` | Tampilkan pesan, muat ulang data; lihat §8 |
| 413 | Body multipart melebihi `post_max_size` PHP server | `{"message": ...}` (pesan bisa kosong) | Kompres foto; lihat §9 |
| 422 | Validasi isian | `{"message": "...", "errors": {"field": ["pesan"]}}` | Tampilkan pesan per field; data tetap disimpan |
| 429 | Terlalu banyak request | `{"message": "Too Many Attempts."}` + header `Retry-After` | Tunggu sesuai `Retry-After`, lalu coba lagi |
| 5xx | Galat server | `{"message": "Server Error"}` | Coba ulang otomatis (backoff), kunci sama |

Contoh 409 dan 422 yang nyata:

```json
// 409 — WO sudah selesai, tidak dapat dimulai
{"message": "WO berstatus Selesai; tindakan ini tidak dapat dilakukan.",
 "errors": {"workflow": ["WO berstatus Selesai; tindakan ini tidak dapat dilakukan."]}}

// 422 — foto wajib
{"message": "Penyelesaian pekerjaan wajib menyertakan foto BEFORE dan AFTER.",
 "errors": {"photos": ["Penyelesaian pekerjaan wajib menyertakan foto BEFORE dan AFTER."]}}
```

## 3. Login, akun, logout

### `POST /auth/login` (tanpa token)

```json
{"username": "posko_pugung", "password": "••••••", "device_name": "Android POSKO"}
```

200:

```json
{
  "data": {
    "token": "2|CONTOH-TOKEN-BUKAN-ASLI",
    "token_type": "Bearer",
    "account": {
      "id": 2, "username": "posko_pugung", "name": "Tim Pugung A", "role": "POSKO",
      "team": {"id": "01a1960d-eb43-...", "code": "POSKO-PUGUNG-A", "name": "Tim Pugung A", "role": "POSKO",
               "posko_id": "01a1960d-eb40-..."}
    }
  },
  "meta": {"server_time": "2026-10-29T02:00:00+00:00"}
}
```

- Username atau sandi salah → 422 `errors.username` "Username atau kata sandi salah.".
- Akun ADMIN atau akun tidak aktif → 422 `errors.username` "Akun ini tidak aktif atau bukan akun tim lapangan.".
- **Login HARSUS/INSPEKSI/ROW berhasil (200)** karena API melayani semua tim lapangan. Aplikasi POSKO wajib memeriksa `account.role == "POSKO"` dan `account.team.posko_id != null`. Bila tidak, panggil `POST /auth/logout` dengan token itu dan jangan simpan tokennya.
- Simpan token di penyimpanan terenkripsi (Android Keystore). Token **tidak kedaluwarsa otomatis** (kebijakan logout 4 jam/2 perangkat masih TBD). Token berhenti berlaku setelah logout, atau ditolak 403 bila akun dinonaktifkan.

### `GET /me`

200: `data` sama dengan `account` pada login. Pakai saat aplikasi dibuka untuk memastikan akun masih aktif dan role masih POSKO.

### `POST /auth/logout`

200: `{"data": {"logged_out": true}, "meta": {...}}`. Hanya token itu yang dicabut (berikutnya 401); data kiriman di server tidak berubah. Aplikasi **jangan** menghapus antrean kiriman yang belum terkirim.

## 4. Data awal

### `GET /bootstrap`

```json
{
  "data": {
    "team": {"id": "...", "code": "POSKO-PUGUNG-A", "name": "Tim Pugung A", "role": "POSKO",
             "posko": {"id": "...", "name": "PUGUNG"}},
    "personnel": [{"id": "01a1960d-eb45-...", "code": "PGG-01", "name": "Budi Posko"}],
    "reference_codes": {
      "RESCHEDULE_REASON": [{"code": "CUACA", "label": "Cuaca buruk"}],
      "ESCALATION_REASON": [],
      "INSPECTION_FINDING": [],
      "PASILA_FINDING": [{"code": "POHON_DEKAT_JTM", "label": "Pohon dekat JTM"}]
    },
    "issue_types": [{"code": "TIDAK_DAPAT_DILAKSANAKAN", "label": "Pekerjaan tidak dapat dilaksanakan"}, "..."],
    "shifts": [{"code": "A", "name": "Pagi", "time": "08:00–16:00 WIB", "index": "1/3"}, "..."],
    "current_shift": {"code": "A", "name": "Pagi", "time": "08:00–16:00 WIB", "index": "1/3", "operational_date": "2026-10-29"},
    "penyulangs": [{"id": "...", "code": "REOG", "name": "REOG"}]
  },
  "meta": {"server_time": "..."}
}
```

- `personnel`: petugas **aktif** tim. Semua laporan wajib memilih minimal satu petugas dari sini (`personnel_ids`).
- Dropdown alasan reschedule dan kode temuan PASILA diambil dari sini (atau dari `GET /pasila/{id}`), **jangan di-hardcode**. Daftar bisa kosong bila Admin belum mengisi Master Data. Tampilkan sebagai kosong, jangan buat kode sendiri.
- Hari operasional 08.00–08.00 WIB. Shift C (00.00–08.00) memakai tanggal operasional hari sebelumnya.

## 5. Work Order (WO APKUK dan WO P0)

WO P0 juga muncul di work feed (`work_type: "P0"`). Pelaksanaannya memakai endpoint WO yang sama.

### `GET /work-feed` dan `GET /work-feed?scope=history`

- `scope` bawaan `active` (WO berjalan, urut batas waktu); `history` (selesai/diteruskan/dibatalkan, terbaru dulu).
- 50 per halaman: `?page=2`. `meta`: `{scope, page, last_page, total, server_time}`.
- Hanya WO tim POSKO dari Posko tim ini (yang belum ditunjuk ke tim lain).

Satu item (`data[]`):

```json
{
  "id": "01a1960d-ed3d-...", "code": "WO-APKUK-20261029-0001",
  "team_type": "POSKO", "work_type": "PEMASANGAN_APKUK", "source": "ADMIN_MANUAL",
  "status": "DITUGASKAN", "status_label": "Ditugaskan", "is_overdue": false,
  "work_date": "2026-10-29", "shift_code": "A",
  "scheduled_start_at": "2026-10-29T01:00:00+00:00", "due_at": "2026-10-29T09:00:00+00:00",
  "penyulang": {"id": "...", "name": "REOG"}, "posko": {"id": "...", "name": "PUGUNG"},
  "section_name": "LBSM GUNUNG MAS",
  "target": {"label": "F.REOG.700001", "latitude": -5.2, "longitude": 105.7,
             "google_maps_url": "https://www.google.com/maps/search/?api=1&query=-5.2,105.7",
             "apkuk_target_quantity": 3},
  "report_types": ["PEMASANGAN_SELESAI", "TEMUAN_SUDAH_LENGKAP"],
  "notes": null,
  "p0": null
}
```

- Navigasi: buka `target.google_maps_url` dengan aplikasi Google Maps. `latitude`/`longitude` bisa `null` bila data sumber tidak berkoordinat.
- Untuk WO P0, `p0` berisi `{"code", "pasila_session_code", "source_type"}` dan `report_types` = `["PEKERJAAN_SELESAI"]`.

Status WO:

| `status` | Arti | Boleh mulai | Boleh lapor/reschedule |
|---|---|---|---|
| `DITUGASKAN` | Baru ditugaskan | ya | ya |
| `DIJADWALKAN_ULANG` | Reschedule di-ACC Admin, jadwal baru | ya | ya |
| `DIKERJAKAN` | Sudah dimulai | (200, tanpa perubahan) | ya |
| `RESCHEDULE_DIAJUKAN` | Menunggu ACC reschedule | 409 | 409 |
| `MENUNGGU_VERIFIKASI` | Laporan menunggu verifikasi Admin | 409 | 409 |
| `SELESAI` | Selesai | 409 | 409 |
| `SELESAI_DITERUSKAN`, `DIBATALKAN` | Ditutup Admin/limpahan | 409 | 409 |

### `GET /work-orders/{id}`

Isi sama dengan item feed, ditambah riwayat:

```json
"reports": [{"id": "...", "report_type": "PEMASANGAN_SELESAI", "verification_status": "TIDAK_PERLU",
             "verification_note": null, "installed_quantity": 3, "occurred_at": "...", "personnel": ["Budi Posko"]}],
"reschedules": [{"id": "...", "status": "MENUNGGU_ACC", "reason_code": "CUACA",
                 "proposed_work_date": "2026-10-29", "proposed_shift_code": "B", "review_note": null}],
"escalations": [],
"issues": []
```

WO tim lain, WO yang tidak ada, dan ID bukan UUID → 404 `"WO tidak ditemukan."`.

### `POST /work-orders/{id}/start`

Tanpa body. 200 dengan ringkasan WO (`status: "DIKERJAKAN"`). Tidak memakai `client_request_id`. Mengulang start pada WO yang sudah `DIKERJAKAN` aman (200, tanpa log ganda). Status lain → 409. Mulai **tidak wajib** sebelum laporan.

### `POST /work-orders/{id}/reports` (multipart)

| Field | Wajib | Aturan |
|---|---|---|
| `client_request_id` | ya | ≤64 karakter, dibuat sekali per laporan (§8) |
| `report_type` | ya | salah satu dari `report_types` WO |
| `installed_quantity` | untuk `PEMASANGAN_SELESAI` | 1–1000 (jumlah APKUK fisik terpasang) |
| `latitude`, `longitude` | ya | GPS perangkat |
| `gps_accuracy_m` | tidak | meter |
| `occurred_at` | tidak, **kirimkan selalu** | waktu kerja di lapangan; tanpa ini server memakai waktu terima (salah untuk kiriman offline). ≤ jam server + 10 menit |
| `personnel_ids[]` | minimal satu petugas | UUID petugas aktif tim (dari bootstrap) |
| `personnel_names[]` | alternatif | nama manual bila petugas belum ada di roster; minimal satu dari `personnel_ids`/`personnel_names` |
| `notes` | tidak | ≤2000 |
| `photos_before[]`, `photos_after[]` | `PEMASANGAN_SELESAI` dan `PEKERJAAN_SELESAI`: masing-masing ≥1 | maks 10 per kelompok; jpg/jpeg/png/webp/heic |
| `photos_evidence[]` | `TEMUAN_SUDAH_LENGKAP`: ≥1 foto (kelompok mana pun) | maks 10 |

201:

```json
{
  "data": {"report_id": "01a1960d-fc01-...", "verification_status": "TIDAK_PERLU",
           "work_order": {"...ringkasan WO...": "", "status": "SELESAI", "status_label": "Selesai"}},
  "meta": {"server_time": "...", "idempotent_replay": false}
}
```

Hasil per jenis laporan:

| `report_type` | `verification_status` | Status WO setelahnya |
|---|---|---|
| `PEMASANGAN_SELESAI` (APKUK) | `TIDAK_PERLU` | `SELESAI` langsung |
| `TEMUAN_SUDAH_LENGKAP` (APKUK) | `MENUNGGU_VERIFIKASI` | `MENUNGGU_VERIFIKASI` |
| `PEKERJAAN_SELESAI` (P0) | `MENUNGGU_VERIFIKASI` | `MENUNGGU_VERIFIKASI` → `SELESAI` setelah Admin ACC |

### `POST /work-orders/{id}/reschedules` (JSON)

```json
{"client_request_id": "rs-7f3c...", "reason_code": "CUACA", "note": "Hujan deras, jalan licin",
 "proposed_work_date": "2026-10-29", "proposed_shift_code": "B"}
```

- `reason_code` dari `RESCHEDULE_REASON`; `note` wajib ≥5 karakter.
- `proposed_shift_code` (A/B/C) wajib untuk WO bershift. Jadwal tujuan yang sudah lewat ditolak 422.
- 201: `{"data": {"reschedule_id": "...", "status": "MENUNGGU_ACC"}, "meta": {"server_time": "...", "idempotent_replay": false}}`.
- Status WO menjadi `RESCHEDULE_DIAJUKAN`. Admin ACC → `DIJADWALKAN_ULANG` dengan jadwal baru; Admin tolak → kembali `DITUGASKAN` (WO tetap wajib dikerjakan).
- Reschedule tidak mengunci PASILA dan P0.

### Lainnya (opsional untuk POSKO)

- `POST /work-orders/{id}/issues` (JSON): `client_request_id`, `issue_type` (dari `issue_types`), `description` ≥10 karakter → 201 `{issue_id, status}`. Hanya untuk WO aktif.
- `POST /work-orders/{id}/escalations`: limpahan ke HARSUS/ROW butuh ACC Admin. **WO P0 tidak dapat dilimpahkan** (422).

## 6. PASILA

Sesi dibuat **server** di awal tiap shift untuk tiap Posko. Aplikasi tidak pernah membuat sesi. Tanpa sesi untuk shift berjalan, tampilkan bahwa sesi belum dibuat server.

### `GET /pasila`

30 per halaman, terbaru dulu, semua sesi Posko tim (termasuk yang sudah ditutup). `meta`: `{page, last_page, server_time}`.

```json
{
  "id": "01a1960e-5050-...", "code": "PSL-20261029-A-PUGUNG",
  "operational_date": "2026-10-29", "shift_code": "A",
  "starts_at": "2026-10-29T01:00:00+00:00", "ends_at": "2026-10-29T09:00:00+00:00",
  "is_current": true, "status": "DIBUKA",
  "section_status": "REKOMENDASI_SISTEM",
  "section": {"section": "LBSM GUNUNG MAS", "penyulang": "REOG"},
  "patrol_status": "BELUM_ADA_LAPORAN", "finding_count": 0,
  "closure": null
}
```

| Field | Nilai |
|---|---|
| `status` | `DIBUKA`, `DITUTUP` |
| `section_status` | `REKOMENDASI_SISTEM` (rekomendasi rotasi, bukan ML), `DITETAPKAN_ADMIN`, `MENUNGGU_PENENTUAN` (`section` = `null`) |
| `patrol_status` | `BELUM_ADA_LAPORAN`, `ADA_TEMUAN`, `NIHIL`. Daftar temuan kosong **bukan** nihil |
| `closure` | `null`, atau `{outcome: ADA_TEMUAN/NIHIL, occurred_at, received_at, flags[]}` |
| `closure.flags` | `TEMUAN_SETELAH_DITUTUP`, `NIHIL_TERBANTAH`, `DITUTUP_SETELAH_SHIFT` |

Sesi yang `ends_at`-nya sudah lewat tetapi masih `DIBUKA` tetap boleh ditutup. Admin melihatnya di daftar "Belum ditutup setelah shift berakhir"; sistem tidak menutup otomatis.

### `GET /pasila/{id}`

Ringkasan di atas ditambah:

```json
"sections": [{"section": "LBSM GUNUNG MAS", "penyulang": "REOG"}],
"finding_codes": [{"code": "POHON_DEKAT_JTM", "label": "Pohon dekat JTM"}],
"findings": [ "...format seperti respons temuan di bawah..." ],
"nil_report": null
```

- `sections`: semua Section terverifikasi milik Posko (pilihan Section temuan; bisa beberapa penyulang).
- `nil_report`: `{id, status: AKTIF/DIGANTIKAN_TEMUAN, note, latitude, longitude, occurred_at, received_at, superseded_by_finding_id, personnel[]}`.
- Sesi Posko lain atau sesi yang tidak ada → 404 `"Sesi PASILA tidak ditemukan untuk Posko tim ini."` (sama persis). Akun non-POSKO → 403.

### `POST /pasila/{id}/findings` (multipart, satu temuan per request)

| Field | Wajib | Aturan |
|---|---|---|
| `client_request_id` | ya | satu per temuan |
| `finding_code` | ya | dari `finding_codes` |
| `description` | ya | 5–2000 karakter |
| `section` | tidak | default Section sesi; wajib milik Posko. Wajib diisi bila sesi belum punya Section |
| `keypoint_id` | tidak | integer, keypoint di penyulang Section |
| `asset_label` | tidak | ≤255 |
| `latitude`, `longitude` | ya | GPS perangkat; `0,0` ditolak 422 |
| `gps_accuracy_m` | tidak | |
| `occurred_at` | ya | **di dalam jendela shift sesi** `[starts_at, ends_at)`, ≤ jam server + 10 menit |
| `personnel_ids[]` | ya | ≥1 petugas aktif tim (nama manual tidak diterima di PASILA) |
| `photos[]` | ya | 1–10 foto |

201:

```json
{
  "data": {
    "id": "01a1960e-5372-...", "sequence": 1, "finding_code": "POHON_DEKAT_JTM",
    "description": "Dahan bambu menyentuh JTM", "section": "LBSM GUNUNG MAS",
    "penyulang_id": "...", "keypoint_id": null, "asset_label": null,
    "latitude": -5.2101, "longitude": 105.7202, "gps_accuracy_m": 5,
    "occurred_at": "2026-10-29T01:30:00+00:00", "received_at": "2026-10-29T02:00:00+00:00",
    "status": "TERCATAT", "photo_count": 1,
    "personnel": [{"id": "...", "name": "Budi Posko"}]
  },
  "meta": {"server_time": "...", "idempotent_replay": false}
}
```

- Kiriman yang tiba **setelah shift berakhir** tetap diterima bila `occurred_at` di dalam jendela shift.
- Temuan yang tiba setelah sesi **ditutup** tetap diterima dan ditandai `TEMUAN_SETELAH_DITUTUP`.
- Temuan yang masuk setelah laporan nihil menggantikan laporan nihil (`nil_report.status` = `DIGANTIKAN_TEMUAN`).
- Contoh 422 waktu di luar shift: `"Waktu kejadian harus di dalam Shift A sesi ini (29/10/2026 08:00–29/10/2026 16:00 WIB)."`.

### `POST /pasila/{id}/nil-report` (JSON)

Hanya bila patroli **benar-benar** tanpa temuan.

```json
{"client_request_id": "nil-91ab...", "note": "Patroli rute LBSM Gunung Mas sampai ujung, aman",
 "latitude": -5.2, "longitude": 105.7, "gps_accuracy_m": 8,
 "occurred_at": "2026-10-29T08:40:00+07:00", "personnel_ids": ["01a1960d-eb45-..."]}
```

- `note` wajib, minimal 10 karakter (area/rute yang dipatroli). GPS, `occurred_at` (jendela shift), dan `personnel_ids` sama seperti temuan.
- 201: `data` = objek `nil_report` di atas, `meta.idempotent_replay`.
- 409 bila sesi sudah punya temuan (`"Sesi ... sudah memiliki 1 temuan; laporan nihil tidak dapat dibuat."`) atau sudah punya laporan nihil.

### `POST /pasila/{id}/close` (JSON)

```json
{"client_request_id": "cl-5d20...", "occurred_at": "2026-10-29T08:55:00+07:00",
 "personnel_ids": ["01a1960d-eb45-..."], "note": "Patroli selesai",
 "latitude": -5.2, "longitude": 105.7, "gps_accuracy_m": 8}
```

- Wajib: `client_request_id`, `occurred_at` (≥ awal shift sesi, ≤ jam server + 10 menit; **boleh setelah shift berakhir**), `personnel_ids`. `note`, GPS opsional.
- Syarat: server **sudah menerima** minimal satu temuan atau laporan nihil aktif. Bila belum → 409 `"Sesi ... belum dapat ditutup: belum ada laporan patroli yang diterima server (temuan atau laporan nihil). Kirim laporan patroli lebih dulu."`.
- **Tidak** menunggu target P0 maupun ACC reschedule APKUK.

201 (ringkasan sesi + peringatan):

```json
{
  "data": {"...ringkasan sesi...": "", "status": "DITUTUP", "patrol_status": "ADA_TEMUAN", "finding_count": 1,
           "closure": {"outcome": "ADA_TEMUAN", "occurred_at": "2026-10-29T01:55:00+00:00",
                       "received_at": "2026-10-29T02:00:00+00:00", "flags": []}},
  "meta": {
    "server_time": "...", "idempotent_replay": false,
    "warnings": {
      "P0_TARGET_BELUM_TERPENUHI": "Target P0 shift belum terpenuhi; status tetap tercatat belum terpenuhi.",
      "APKUK_RESCHEDULE_MENUNGGU_ACC": "1 WO APKUK menunggu ACC reschedule; kewajiban WO tetap berjalan.",
      "APKUK_BELUM_SELESAI": "1 WO APKUK belum selesai."
    }
  }
}
```

- `meta.warnings` selalu **objek** (kode → pesan). Isinya hanya peringatan yang berlaku; bila tidak ada, `{}`. Ikut dikembalikan saat replay. Tampilkan ke petugas; penutupan tetap sah.
- Penutupan kedua dengan kunci lain → 409 `"Sesi ... sudah ditutup pada 29/10/2026 09:00 WIB."`.

## 7. P0

### `GET /p0`

Hanya P0 yang **sudah menjadi pekerjaan** (punya WO P0) milik Posko tim. Usulan SYSTEM yang menunggu ACC atau ditolak **tidak** dikirim. 30 per halaman, terbaru dulu.

```json
{
  "id": "01a1960e-5396-...", "code": "P0-20261029-A-PUGUNG-01",
  "pasila_session": {"id": "01a1960e-5050-...", "code": "PSL-20261029-A-PUGUNG"},
  "stage": "AKTIF", "source_type": "PASILA_FINDING",
  "section": "LBSM GUNUNG MAS", "penyulang": "REOG",
  "description": "Pangkas dahan bambu di LBSM Gunung Mas",
  "work_order": {"id": "01a1960e-5397-...", "code": "WO-P0-20261029-0001", "status": "DITUGASKAN",
                 "work_date": "2026-10-29", "shift_code": "A"}
}
```

- `stage`: `AKTIF`, `MENUNGGU_VERIFIKASI`, `SELESAI`, `TIDAK_DILANJUTKAN`.
- Pelaksanaan: `GET/POST /work-orders/{work_order.id}/...` (§5) dengan `report_type: "PEKERJAAN_SELESAI"`, foto BEFORE + AFTER, GPS, dan petugas.
- **Target P0 shift terpenuhi hanya bila `work_order.status == "SELESAI"`** (setelah verifikasi Admin). `MENUNGGU_VERIFIKASI` belum dihitung selesai.
- WO P0 tidak dapat dibatalkan oleh aplikasi maupun dilimpahkan.

## 8. Offline dan pengiriman ulang

### Aturan `client_request_id`

1. Buat UUID v4 **sekali** saat petugas menekan Simpan, lalu simpan bersama payload dan file foto di HP.
2. Pakai kunci yang **sama** pada setiap pengiriman ulang: timeout, jaringan putus, 5xx, aplikasi ditutup, setelah login ulang.
3. Jangan mengubah payload maupun foto setelah disimpan. Kunci sama dengan isi berbeda → 422 `errors.client_request_id` ("client_request_id sudah dipakai untuk permintaan dengan isi berbeda (...)"). Bila petugas memperbaiki data, itu kiriman **baru** dengan kunci baru.
4. Replay sukses mengembalikan record yang sama dengan status 201 dan `meta.idempotent_replay: true`. Perlakukan sama dengan sukses.
5. Replay dikenali sebelum aturan waktu diperiksa, jadi retry yang tiba setelah shift berakhir tetap aman.
6. Kunci yang sama dari akun tim lain tidak pernah dianggap replay.

### Urutan kirim

1. FIFO sesuai waktu simpan.
2. **PASILA:** semua temuan dan laporan nihil sebuah sesi dikirim **sebelum** penutupan sesi itu. Bila penutupan tetap tiba lebih dulu, server menolak 409 "belum ada laporan patroli" tanpa membuat apa pun. Kirim laporannya, lalu ulangi penutupan dengan kunci yang sama.
3. **WO:** start sebelum laporan (urutan alami). Server tidak mewajibkan start.
4. Setelah sukses, muat ulang data server (`GET`). Server adalah sumber status resmi.

### Status di HP vs server

- Jangan tampilkan "terkirim/selesai" sebelum server membalas 2xx.
- Hapus foto lokal hanya setelah 2xx (termasuk replay).
- 409/422 bukan galat jaringan. Simpan data dan tampilkan pesan server; jangan buang otomatis.

| Respons | Status antrean | Catatan |
|---|---|---|
| 2xx (termasuk replay) | Terkirim | hapus foto lokal, muat ulang data |
| jaringan, timeout, 5xx, 429 | Coba lagi otomatis | backoff; kunci sama |
| 401 | Tahan antrean | login ulang dengan akun yang sama, lalu lanjut |
| 403 `errors.auth` | Tahan antrean | akun dinonaktifkan; hubungi Admin |
| 403 lain, 404 | Perlu tindakan | data di luar cakupan tim |
| 409 | Perlu tindakan | status server berubah, mis. WO sudah selesai, reschedule menunggu ACC, sesi sudah ditutup, nihil setelah ada temuan |
| 409 penutupan "belum ada laporan patroli" | Tunggu | kirim laporan sesi itu lebih dulu |
| 422 | Perlu perbaikan | tampilkan `errors` per field |

### Kasus yang perlu diketahui

- **Dua HP satu akun:** server hanya mencegah duplikat dengan kunci yang sama. Dua HP dengan kunci berbeda membuat dua temuan. Penutupan dari HP kedua mendapat 409 "sudah ditutup".
- **Jam HP salah:** bandingkan dengan `meta.server_time`; `occurred_at` > jam server + 10 menit ditolak. Peringatkan petugas bila selisih jam lebih dari beberapa menit.
- **Temuan baru setelah shift berakhir:** `occurred_at` harus di dalam jendela shift sesi. Temuan yang terjadi setelah shift berakhir tidak dapat masuk ke sesi itu.

## 9. Foto dan batas unggah

- Server menerima jpg/jpeg/png/webp/heic, maks 10 MB per foto menurut aturan aplikasi.
- **Namun PHP server memiliki batas sendiri.** Bawaan PHP adalah `upload_max_filesize = 2M` dan `post_max_size = 8M` (terverifikasi di lingkungan lokal; konfigurasi Railway belum tercatat di repo).
- Akibatnya, foto > 2 MB ditolak 422 pada field fotonya (mis. `photos.0`, pesan "... gagal diunggah.") dan total request > 8 MB ditolak 413.
- **Rekomendasi aplikasi** sampai batas server dikonfirmasi:
  - kompres foto ke sisi panjang ±1600–2048 px, JPEG kualitas ±80 (biasanya < 1 MB);
  - jaga total satu request < 7 MB.

## 10. Belum dibangun (jangan dipakai)

| Rencana di `API_CONTRACT.md` | Yang dipakai sekarang |
|---|---|
| `GET /api/v1/mobile/work-feed/{id}` | `GET /work-orders/{id}` |
| `POST /api/v1/work-orders/{id}/complete` | `POST /work-orders/{id}/reports` |
| `POST /api/v1/work-orders/{id}/reschedule` | `POST /work-orders/{id}/reschedules` |
| `GET /api/v1/reference-codes?category=` | `GET /bootstrap` (`reference_codes`) dan `GET /pasila/{id}` (`finding_codes`) |
| `POST /api/v1/mobile/pasila/{id}/complete` | `POST /pasila/{id}/close` |
| `POST /api/v1/admin/...` | Tidak ada API Admin; Admin memakai Web |
| Notifikasi push, batas perangkat, logout otomatis | Belum ada (TBD) |

`GET /inspections` dan `POST /inspections` ada, tetapi hanya untuk akun INSPEKSI.

## 11. Perubahan kontrak

| Versi | Perubahan |
|---|---|
| B3.5 | (1) `GET/POST /pasila/{id}...` untuk sesi yang **tidak ada** kini membalas 404 `"Sesi PASILA tidak ditemukan untuk Posko tim ini."`, sama dengan sesi Posko lain. Sebelumnya `"No query results for model [App\\Models\\PasilaSession] ..."`. Akun non-POSKO kini selalu 403 lebih dulu, termasuk untuk ID sesi yang tidak ada (sebelumnya 404). (2) `meta.warnings` pada penutupan selalu objek; tanpa peringatan `{}` (sebelumnya array kosong `[]`). |
| B3.4 | `POST /pasila/{id}/close`; blok `closure` di ringkasan sesi. |
| B3.3 | `GET /p0`; WO P0 di work feed. |
| B3.2 | `GET /pasila`, `GET /pasila/{id}`, temuan, laporan nihil. |
