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

Tabel di bawah mencampur rute yang **sudah berjalan** (ditandai **Dibangun**) dan proposal yang belum ada (**Belum dibangun**). Rincian payload, respons, galat, dan urutan sinkronisasi offline untuk aplikasi ada di **`MOBILE_API_GUIDE.md`**, yang mengikuti kode dan dijaga `MobileApiContractTest`. Bila tabel ini dan panduan berbeda, panduan yang benar.

# 2. Bootstrap & master

| Method/path | Roles | Catatan |
|---|---|---|
| POST /api/v1/mobile/auth/login | Mobile | **Dibangun.** Login akun tim (`username`, `password`, `device_name`) → token Sanctum + akun. Akun HARSUS/INSPEKSI/ROW juga dapat login; aplikasi POSKO wajib memeriksa role |
| POST /api/v1/mobile/auth/logout | Mobile | **Dibangun.** Mencabut token; outbox di HP tetap |
| GET /api/v1/mobile/me | Mobile | **Dibangun.** Akun dan tim |
| GET /api/v1/mobile/bootstrap | Mobile | **Dibangun.** Tim, petugas aktif, kode dropdown per kategori (`reference_codes`), jenis kendala, shift, shift berjalan + tanggal operasional, penyulang |
| GET /api/v1/mobile/work-feed | POSKO/HARSUS/ROW | **Dibangun.** `scope=active` (bawaan) atau `history`, 50 per halaman; hanya WO tim/Posko sendiri |
| GET /api/v1/mobile/work-feed/{id} | Pemilik tugas | **Belum dibangun** — detail memakai `GET /api/v1/mobile/work-orders/{id}` |
| GET /api/v1/reference-codes?category= | Auth | **Belum dibangun** — kode dropdown ada di `bootstrap.reference_codes` dan `pasila/{id}.finding_codes` |

# 3. Work Order generik

| Method/path | Actors | Efek |
|---|---|---|
| GET /api/v1/mobile/work-orders/{id} | Tim pemilik | **Dibangun.** Detail + laporan, reschedule, limpahan, kendala; WO di luar cakupan → 404 |
| POST /api/v1/mobile/work-orders/{id}/start | Tim executor | **Dibangun.** Idempoten berdasarkan status; status tidak sah → 409 |
| POST /api/v1/mobile/work-orders/{id}/reports | Tim executor | **Dibangun** (menggantikan rencana `/complete`). Multipart: jenis laporan, petugas, foto BEFORE/AFTER, GPS, waktu; APKUK jumlah fisik terpasang; `client_request_id` idempoten |
| POST /api/v1/mobile/work-orders/{id}/reschedules | Tim executor | **Dibangun** (rencana `/reschedule`). **reason_code + note wajib + tanggal/shift tujuan pilihan petugas** → menunggu ACC Admin |
| POST /api/v1/mobile/work-orders/{id}/issues, /escalations | Tim executor | **Dibangun.** Kendala; limpahan ke tim lain menunggu ACC (P0 tidak dapat dilimpahkan) |
| GET /api/v1/work-orders, POST /api/v1/admin/work-orders, /correct | ADMIN | **Belum dibangun** sebagai API; Admin memakai Web Admin |

WO type:
- POSKO = PEMASANGAN_APKUK (sistem 6 target/shift/Posko, Admin boleh tambah).
- HARSUS = PEKERJAAN_KHUSUS (Admin manual wajib asset_id, atau child escalation ACC).
- ROW = RABAS/PANGKAS/TEBANG (auto aktif dari risiko vegetasi atau manual Admin).

# 4. PASILA dan P0 POSKO

| Method/path | Actors | Efek |
|---|---|---|
| GET /api/v1/mobile/pasila | POSKO (Posko timnya saja) | **Dibangun B3.2.** Sesi dibuat server awal shift; ringkasan sesi, Section, `patrol_status`, jumlah temuan. Sejak B3.4: `status` DITUTUP dan blok `closure` (null bila belum ditutup) |
| GET /api/v1/mobile/pasila/{id} | POSKO pemilik sesi | **Dibangun B3.2.** Section terverifikasi milik Posko (pilihan Section temuan), kode temuan aktif, daftar temuan, laporan nihil. Sejak B3.5 sesi Posko lain dan sesi yang tidak ada → 404 dengan pesan sama |
| POST /api/v1/mobile/pasila/{id}/findings | POSKO pemilik sesi | **Dibangun B3.2.** Multipart, **satu temuan per request**: `client_request_id`, `finding_code` (reference_codes PASILA_FINDING), `description`, `section` opsional (default Section sesi; wajib milik Posko), `keypoint_id`/`asset_label` opsional, `latitude`/`longitude` wajib (bukan 0,0), `gps_accuracy_m`, `occurred_at` (di dalam jendela shift sesi), `personnel_ids[]` roster tim, `photos[]` 1–10. 201 + `meta.idempotent_replay`; 403 bukan POSKO; 404 sesi Posko lain; 422 validasi |
| POST /api/v1/mobile/pasila/{id}/nil-report | POSKO pemilik sesi | **Dibangun B3.2.** Laporan nihil eksplisit: `client_request_id`, `note` (area/rute, ≥10 karakter), GPS, `occurred_at`, `personnel_ids[]`. 409 bila sesi sudah memiliki temuan atau laporan nihil. Temuan yang masuk kemudian menggantikan laporan nihil (riwayat tetap) |
| POST /api/v1/mobile/pasila/{id}/close | POSKO pemilik sesi | **Dibangun B3.4** (menggantikan rencana `/complete`). Menutup sesi: `client_request_id` (wajib), `occurred_at` (≥ awal shift sesi, ≤ jam server + 10 menit; boleh setelah shift berakhir, ditandai), `personnel_ids[]` roster tim, `note`/`latitude`/`longitude`/`gps_accuracy_m` opsional. Syarat: server sudah menerima minimal satu temuan atau laporan nihil aktif (daftar kosong ≠ nihil). **Tidak** menunggu target P0 maupun ACC reschedule APKUK; keduanya dikembalikan sebagai `meta.warnings` (`P0_TARGET_BELUM_TERPENUHI`, `APKUK_RESCHEDULE_MENUNGGU_ACC`, `APKUK_BELUM_SELESAI`; selalu objek, `{}` bila kosong sejak B3.5) dan kewajibannya tetap berjalan. 201 + ringkasan sesi (blok `closure`: `outcome` ADA_TEMUAN/NIHIL, waktu, `flags`) + `meta.idempotent_replay`; 409 sudah ditutup / belum ada laporan patroli; 403 bukan POSKO; 404 sesi Posko lain; 422 validasi atau kunci sama dengan isi berbeda |
| POST /api/v1/admin/pasila | ADMIN | **Belum dibangun** sebagai API. Web Admin: tombol "Buka sesi shift berjalan" (terkunci flag pembuka) |
| GET /api/v1/mobile/p0 | POSKO (Posko timnya saja) | **Dibangun B3.3.** Hanya P0 yang sudah menjadi pekerjaan (WO P0): kode P0, sesi PASILA, tahap, sumber, Section, WO. Usulan menunggu ACC/ditolak tidak tampil. Non-POSKO → 403 |
| GET /api/v1/mobile/work-feed, POST /api/v1/mobile/work-orders/{id}/start\|reports\|reschedules\|issues | POSKO pemilik | **Dipakai ulang untuk P0.** WO P0 (`work_type` = `P0`, blok `p0` berisi sesi PASILA) muncul di feed Posko; selesai = laporan `PEKERJAAN_SELESAI` (BEFORE+AFTER, GPS, petugas) → verifikasi Admin → SELESAI. Limpahan P0 ke tim lain ditolak (422) |
| POST /admin/pasila/sesi/{id}/p0 (Web Admin) | ADMIN | **Dibangun B3.3.** P0 manual dari sesi PASILA: sumber tercatat (temuan PASILA atau temuan inspeksi tervalidasi milik Posko), uraian, jadwal shift, opsional pengganti usulan yang ditolak |
| POST /admin/p0/{id}/acc (Web Admin) | ADMIN | **Dibangun B3.3.** ACC usulan SYSTEM → WO P0; sumber dan kepemilikan Section diperiksa ulang; ACC ulang → konflik |
| POST /admin/p0/{id}/tolak (Web Admin) | ADMIN | **Dibangun B3.3.** Tolak usulan SYSTEM dengan alasan → kewajiban P0 manual pengganti dalam sesi yang sama |

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
