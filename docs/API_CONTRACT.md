# API CONTRACT & AUTHORIZATION
## Sistem Pengelolaan Pekerjaan Jaringan Distribusi — ULP Sribhawono

**Version:** v3.0 — 8 Oktober 2026  
**Status:** Target kontrak; tidak boleh dianggap endpoint sudah ada  
**Stack:** Laravel REST/API + Web Admin, Flutter Android, PostgreSQL, SQLite outbox, Leaflet Admin, Google Maps navigasi eksternal

# 1. Aktor, auth, response

- **ADMIN** mengakses Web Admin, mendapat izin monitor/ACC-Tolak/create manual semua pekerjaan, koreksi berdasar audit.
- **POSKO**, **HARSUS**, **INSPEKSI**, **ROW** mengakses Flutter dan API scoped per role/team.
- Account = tim; petugas pelaksana dipilih dari master. Perubahan role/scope, create/update/delete harus dicek di server, jangan percaya dropdown.
- Login device cap dua / logout empat jam **TBD**.
- JSON response sebaiknya menyertakan data, meta, errors berisi kode dan pesan untuk petugas, server timestamp/version. Server authoritative.
- Submission mobile memakai **Idempotency-Key/client_request_id** sama saat retry, occurred_at dari client, received_at server, version guard dan validasi foto/GPS.

Berikut endpoint merupakan **proposal rute v3**, bukan API sudah diimplementasikan. Naming final dapat berubah tanpa mengubah aturan bisnis.

# 2. Bootstrap & master

| Method/path usulan | Roles | Catatan |
|---|---|---|
| POST /api/v1/mobile/auth/login | Mobile | Login akun tim |
| POST /api/v1/mobile/auth/logout | Mobile | Token invalidation; outbox tetap |
| GET /api/v1/mobile/bootstrap | Mobile | Tim, kode dropdown, petugas aktif, shift, pekerjaan cached scope |
| GET /api/v1/mobile/work-feed | POSKO/HARSUS/INSPEKSI/ROW | Hanya tugas role terkait dan scope relevan |
| GET /api/v1/mobile/work-feed/{id} | Pemilik tugas | Aset, lat/lon, tombol external Google Maps pada UI |
| GET /api/v1/reference-codes?category= | Auth | Master kode ter-version, bukan hardcode mobile |

# 3. Work Order generik

| Method/path | Actors | Efek |
|---|---|---|
| GET /api/v1/work-orders | Mobile owner / Admin sesuai auth | Filter team_type, work_type, status, shift, posko |
| GET /api/v1/work-orders/{id} | Scoped | Detail dan route stop |
| POST /api/v1/work-orders/{id}/start | Tim executor | Workflow guarded |
| POST /api/v1/work-orders/{id}/complete | Tim executor | Result + petugas + foto + GPS; APKUK physical quantity |
| POST /api/v1/work-orders/{id}/reschedule | Tim executor | **reason_code + note wajib + target pilihan petugas** |
| POST /api/v1/admin/work-orders | ADMIN | WO manual untuk POSKO/HARSUS/ROW menurut type |
| POST /api/v1/admin/work-orders/{id}/correct | ADMIN | Alasan koreksi wajib, audit old/new |

WO type:
- POSKO = PEMASANGAN_APKUK (sistem 6 target/shift/Posko, Admin boleh tambah).
- HARSUS = PEKERJAAN_KHUSUS (Admin manual wajib asset_id, atau child escalation ACC).
- ROW = RABAS/PANGKAS/TEBANG (auto aktif dari risiko vegetasi atau manual Admin).

# 4. PASILA dan P0 POSKO

| Method/path | Actors | Efek |
|---|---|---|
| GET /api/v1/pasila | POSKO scoped / ADMIN monitor | Session otomatis awal shift |
| GET /api/v1/pasila/{id} | POSKO / ADMIN | Section, shift, daftar temuan, P0 |
| POST /api/v1/pasila/{id}/findings | POSKO | **Satu temuan per record** dengan GPS/foto/kode |
| POST /api/v1/pasila/{id}/complete | POSKO | Validasi hasil/target; WO rescheduled tidak memblokir |
| POST /api/v1/admin/pasila | ADMIN | Manual create sesuai Section/Posko |
| GET /api/v1/p0 | POSKO / ADMIN | P0 harus berelasi PASILA |
| POST /api/v1/admin/p0 | ADMIN | Manual P0 dari PASILA, inherited Section/Posko |
| POST /api/v1/admin/p0/{id}/approve | ADMIN | ACC usulan SYSTEM agar executable |
| POST /api/v1/admin/p0/{id}/reject | ADMIN | Tolak SYSTEM, tandai mandatory manual replacement |
| POST /api/v1/p0/{id}/complete | POSKO pemilik PASILA | Hasil kerja + petugas + bukti |

Jangan ada P0 endpoint execute milik HARSUS. Jangan ada P0 yang tidak terikat PASILA. Replacement Admin diwajibkan sebagai invariant sesudah SYSTEM proposal reject; mekanisme pengawasan tenggat masih TBD.

# 5. Inspeksi, ROW, HARSUS, eskalasi

| Method/path | Actors | Efek |
|---|---|---|
| GET /api/v1/inspections/areas | INSPEKSI | Ranking/rotasi area untuk dikunjungi |
| POST /api/v1/inspections/visits | INSPEKSI | Kunjungan termasuk Tidak Ada Temuan |
| POST /api/v1/inspections/{id}/findings | INSPEKSI | Temuan per titik dengan GPS/foto |
| POST /api/v1/admin/inspection-findings/{id}/approve | ADMIN | ACC + target team rekomendasi sistem |
| POST /api/v1/admin/inspection-findings/{id}/reject | ADMIN | Catat keputusan, jangan masuk canonical |
| GET /api/v1/row/routes | ROW | Paket WO per area/rute harian |
| GET /api/v1/harsus/work-orders | HARSUS | WO PEKERJAAN_KHUSUS termasuk child escalation |
| POST /api/v1/work-escalations | Pelaksana role terkait | Request limpahan, original tetap tercatat |
| POST /api/v1/admin/work-escalations/{id}/approve | ADMIN | Atomic source SELESAI_DITERUSKAN + child WO |
| POST /api/v1/admin/work-escalations/{id}/reject | ADMIN | No child WO, audit keputusan |

Hujan dan kendala akses sementara → reschedule, bukan escalation ke HARSUS.

# 6. Maps ADMIN Web

| Method/path usulan | Auth | Efek |
|---|---|---|
| GET /api/v1/admin/map/feeders | ADMIN | 16 penyulang |
| GET /api/v1/admin/map/layers | ADMIN | Asset/event types + status |
| GET /api/v1/admin/map/features?penyulang_id=&layers=&bbox=&zoom= | ADMIN | **Wajib satu penyulang**, lazy/cluster/page |
| GET /api/v1/admin/map/search?q= | ADMIN | **Global 16 penyulang**, hasil punya penyulang_id untuk pindah dropdown |
| GET /api/v1/admin/assets/{id} | ADMIN | Identitas, status, current photo, specs, relasi |
| GET /api/v1/admin/assets/{id}/history | ADMIN | Timeline append-only dan foto lama |
| GET /api/v1/admin/disturbances/{id} | ADMIN | Titik kejadian + list affected asset |
| GET /api/v1/admin/map/lines?penyulang_id= | ADMIN | Opsional bila GIS tersedia |

Search global UI: pilih result → ganti dropdown Penyulang → fetch layer marker → zoom → sidebar detail. Gangguan marker adalah titik kejadian, bukan koordinat seluruh aset terkait.

# 7. Media, audit, synchronization

Media diunggah dengan metadata captured_at, lat/lon, media type, asset/job/finding link, file checksum. Semua histori foto lama tetap ada; latest valid image ditampilkan sebagai current. Completion default BEFORE+AFTER hingga aturan form khusus diputuskan.

Server idempotent pada retry. API harus mengembalikan konflik workflow 409 dan validasi 422 secara terstruktur, termasuk petunjuk user-facing. Implementasi v1 (Batch B1):
- **Pengiriman ulang:** request dengan `client_request_id` yang sama mengembalikan record semula dengan `meta.idempotent_replay: true`, termasuk bila dua request datang bersamaan.
- **Kunci dipakai ulang:** kunci yang dipakai untuk WO atau tim lain, atau untuk isi yang berbeda, ditolak dengan 422 pada `client_request_id`. Isi mencakup catatan, koordinat, waktu kejadian, petugas, dan SHA-256 foto. Outbox wajib mengirim ulang payload dan file yang sama persis.
- **Waktu kejadian:** `occurred_at` tanpa offset dibaca sebagai WIB dan disimpan sebagai UTC.
- **Mulai pekerjaan:** `start` pada WO yang sudah DIKERJAKAN mengembalikan 200.
- **Bootstrap:** `current_shift` menyertakan `operational_date`, dan `work_date` WO ber-shift adalah tanggal operasional. Hari operasional dimulai 08:00 WIB, sehingga Shift C setelah tengah malam memakai tanggal kemarin (Batch B2). Jika offline, tombol submit mobile menjamin **local persistence**, bukan keberhasilan server. Background worker mengirim otomatis saat memungkinkan menurut kebijakan Android.

Koreksi oleh ADMIN wajib reason; catat old/new/actor/timestamp dan jangan hard-delete history. Role lain dilarang mengubah catatan canonical setelah diterima.

# 8. Implementasi bertahap dan kontrak pengujian

API belum tersedia saat audit; rute Web Admin lama belum auth guard. Langkah aman: implementasi identity/role + server authorization, canonical masters, WO/PASILA/P0 workflows, admin review, ROW, Maps dan photo history, baru mobile online/offline end-to-end. Uji bahwa seluruh endpoint forbidden untuk role yang salah dan duplicate request tidak membuat transaksi ganda.

**TBD:** payload detail P0 dari atasan, kode dropdown, formula prioritas, status reschedule approval, ROW dedupe policy, bentuk GIS, foto khusus per work_type, frekuensi special-case. Jam shift sudah final 28 Okt 2026 (A 08–16, B 16–00, C 00–08 WIB).
