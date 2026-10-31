# Laporan Implementasi Batch B3.4
## Penutupan Sesi PASILA — ULP Sribhawono

- **Tanggal:** 1 November 2026
- **Branch:** `feat/admin-web-foundation-v1` (PR #10, belum di-merge), basis `b5bc884` (B3.3)
- **Status kode:** di-commit dan di-push ke PR #10 (belum di-merge). Tidak ada merge, deploy, migration production, perubahan Railway, perubahan Google Sheets, maupun aktivasi scheduler/generator.

## 1. Aturan bisnis yang diterapkan

| No | Aturan | Penerapan |
|---|---|---|
| 1 | PASILA milik setiap Posko pada setiap shift | Penutupan terikat satu `pasila_sessions` (unik per shift + Posko); hanya akun POSKO dari Posko pemilik sesi yang dapat menutup |
| 2 | Laporan PASILA dapat berisi banyak temuan atau laporan nihil eksplisit | Penutupan mensyaratkan minimal satu temuan **atau** laporan nihil aktif yang sudah diterima server; daftar temuan kosong tanpa laporan nihil ditolak |
| 3 | Boleh ditutup walau target P0 belum terpenuhi | Penutupan tidak memeriksa P0; target tetap dihitung dari P0 SELESAI dan tercatat "belum terpenuhi" di snapshot dan monitor |
| 4 | Boleh ditutup walau reschedule APKUK menunggu ACC | Penutupan tidak memeriksa WO APKUK; jumlah WO belum selesai dan reschedule menunggu ACC dicatat di snapshot, WO tidak diubah |
| 5 | Reschedule ditolak → kewajiban WO tetap ada | Alur penolakan reschedule yang ada tidak berubah: WO kembali ke Ditugaskan walau sesi sudah ditutup (diuji) |
| 6 | Usulan SYSTEM ditolak tetap wajib diganti Admin | Kewajiban pengganti (B3.3) tidak disentuh penutupan; tetap tampil di monitor dan snapshot (`replacement_open`) |
| 7 | Tidak ada temuan, P0, nihil, atau bukti palsu | Penutupan tidak membuat temuan, laporan nihil, maupun P0; hanya mencatat apa yang sudah diterima server |

## 2. Struktur database

Migration additive dan reversible `2026_11_01_200001_create_pasila_closures_table` (rollback hanya menghapus `pasila_closures`). Tabel lain tidak diubah; `pasila_sessions.status` memakai nilai baru `DITUTUP` (kolom string yang sudah ada).

| Kolom | Isi |
|---|---|
| `pasila_session_id` | FK, **unik**: satu penutupan per sesi |
| `outcome` | `ADA_TEMUAN` bila sudah ada temuan saat ditutup, selain itu `NIHIL` |
| `finding_count`, `pasila_nil_report_id` | Bukti patroli saat ditutup (FK laporan nihil bila nihil) |
| `note`, `latitude`, `longitude`, `gps_accuracy_m` | Opsional dari petugas |
| `personnel` | JSON petugas roster yang menutup (id + nama saat itu) |
| `snapshot` | JSON keadaan saat ditutup: laporan patroli, kewajiban P0 (`target`, `completed`, `fulfilled`, `replacement_open`, `stages`), WO APKUK shift (`total`, `selesai`, `belum_selesai`, `reschedule_menunggu_acc`) |
| `occurred_at`, `received_at` | Waktu di HP dan waktu diterima server, terpisah |
| `submitted_by_field_team_id`, `submitted_by_user_id` | Tim dan akun penutup |
| `client_request_id` | Unik, untuk retry offline |

Catatan penutupan tidak pernah ditulis ulang. Perbedaan yang muncul kemudian dihitung saat dibaca (§4), tidak disimpan sebagai kolom.

## 3. Alur PASILA dari pembukaan sampai penutupan

1. **Dibuka (B3.1).** `pasila:open-shift` membuat sesi per Posko di awal shift (pembuka nonaktif secara default). Section dipilih dari Section terverifikasi milik Posko.
2. **Patroli (B3.2).** POSKO mengirim temuan per titik (foto, GPS, petugas) atau satu laporan nihil eksplisit. Temuan yang masuk setelah nihil menggantikan nihil dengan riwayat tetap.
3. **P0 (B3.3).** Usulan SYSTEM menunggu ACC; Admin membuat P0 manual atau pengganti; target terpenuhi hanya oleh P0 SELESAI. Berjalan terpisah dari penutupan.
4. **Ditutup (B3.4).** POSKO mengirim penutupan. Server:
   - memeriksa waktu dan petugas;
   - mengunci baris sesi;
   - menolak bila sudah ditutup atau belum ada bukti patroli;
   - menyimpan penutupan dan snapshot;
   - mengubah status sesi menjadi DITUTUP;
   - mencatat audit `CLOSED`.

   Respons memuat peringatan non-blokir bila target P0 belum terpenuhi atau WO APKUK belum selesai/menunggu ACC reschedule.
5. **Setelah ditutup.**
   - Section sesi tidak dapat diubah Admin.
   - Temuan terlambat yang sah tetap diterima dan ditandai.
   - Laporan nihil baru ditolak.
   - WO APKUK, reschedule, dan P0 tetap berjalan sesuai alurnya masing-masing.

## 4. Kiriman terlambat dan offline

Mengikuti `OFFLINE_SYNC_SPEC.md` §7. Keputusan untuk kasus yang belum diatur: **simpan semua, jangan tulis ulang, tandai.**

| Kasus | Penanganan |
|---|---|
| Penutupan tiba sebelum laporan patroli (urutan outbox salah) | 409 "belum ada laporan patroli", tanpa penutupan. Klien mengirim ulang dengan key sama setelah laporan terkirim |
| Retry penutupan dengan key sama | Mengembalikan penutupan yang sama (`idempotent_replay: true`); isi berbeda dengan key sama → 422 |
| Penutupan kedua dengan key lain | 409 "sudah ditutup pada … WIB" |
| `occurred_at` penutupan setelah shift berakhir | Diterima, ditandai `DITUTUP_SETELAH_SHIFT` |
| `occurred_at` sebelum awal shift atau > 10 menit di depan jam server | 422 |
| Temuan (jendela shift sah) tiba setelah ditutup | Diterima; audit mencatat "Diterima setelah sesi PASILA ditutup"; sesi ditandai `TEMUAN_SETELAH_DITUTUP` |
| Sesi ditutup nihil lalu temuan masuk | Laporan nihil digantikan (riwayat tetap, perilaku B3.2); status patroli terkini menjadi ADA_TEMUAN; penutupan tetap NIHIL dan ditandai `NIHIL_TERBANTAH` |
| Temuan tiba saat penutupan belum commit | Temuan menunggu kunci sesi, lalu tercatat sebagai temuan setelah ditutup (diuji di PostgreSQL) |

## 5. Web Admin dan API

**PASILA Monitor (`/admin/pasila`):**
- kartu baru "Sesi ditutup";
- kolom sesi menampilkan status Dibuka/Ditutup, hasil penutupan, waktu, dan penanda kiriman terlambat;
- kolom terpisah, tidak digabung ke status sesi: laporan patroli terkini, WO APKUK, reschedule menunggu ACC, dan target P0 dengan kewajiban pengganti.

**Detail PASILA:** panel penutupan menampilkan:
- hasil dan waktu (HP dan server);
- akun dan petugas;
- catatan;
- snapshot "Saat ditutup: …" dengan keterangan bahwa status terkini di bawahnya dapat berbeda;
- penanda.

Bila sesi belum ditutup, panel menjelaskan syarat penutupan. Admin tidak menutup sesi atas nama Posko (belum diputuskan, §7).

**API mobile:**

| Method/path | Keterangan |
|---|---|
| `POST /api/v1/mobile/pasila/{id}/close` | Baru. Rincian di `API_CONTRACT.md` §4 |
| `GET /api/v1/mobile/pasila`, `GET /api/v1/mobile/pasila/{id}` | Menambah status `DITUTUP` dan blok `closure` (`outcome`, `occurred_at`, `received_at`, `flags`) |

Kode penolakan:

| Kode | Arti |
|---|---|
| 401 | Tanpa token |
| 403 | Bukan POSKO |
| 404 | Sesi Posko lain |
| 409 | Sudah ditutup, atau belum ada laporan patroli |
| 422 | Validasi atau key sama dengan isi berbeda |

## 6. Pengujian

| Uji | Lingkungan | Hasil |
|---|---|---|
| Suite Laravel penuh | SQLite in-memory | **417 lulus / 3.677 assertion** (B3.3: 410/3.590), termasuk regresi B1–B3.3 |
| `PasilaClosureTest` | SQLite | 7 tes, mencakup: banyak temuan → ADA_TEMUAN dengan snapshot, audit, dan retry idempoten; nihil eksplisit → NIHIL; tanpa laporan ditolak dan validasi waktu; penutupan dengan target P0 belum terpenuhi dan reschedule APKUK menunggu ACC lalu reschedule ditolak (WO kembali ke Ditugaskan, sesi tetap ditutup); temuan offline terlambat setelah nihil ditutup; penutupan setelah shift; Posko lain, role lain, tanpa token ditolak dan Section terkunci |
| PostgreSQL | Postgres lokal, schema sementara `b34test_*` (dihapus) | **11/11**: migrate 30/30, unik per sesi dan `client_request_id`, FK laporan nihil, rollback hanya `pasila_closures`; **dua penutupan bersamaan → satu penutupan** (proses kedua menunggu ±3,5 dtk lalu 409) dan satu audit; retry paralel key sama → replay; **temuan bersamaan dengan penutupan** → keduanya tercatat, penutupan tetap NIHIL dengan penanda, audit temuan mencatat diterima setelah ditutup |
| Browser | Edge headless, `php -S :8010`, schema sementara `b34browser_*` (dihapus) | **18/18**: status ditutup dengan temuan/nihil, penanda kiriman terlambat, status patroli terkini terpisah, target P0 dan reschedule APKUK terpisah, kartu Sesi ditutup, panel detail dan snapshot, seluler tanpa scroll horizontal, 0 error console; data tidak berubah oleh tampilan Admin |
| Pint `--test` | File PHP B3.4 | Lulus |

**Masalah yang ditemukan selama pengujian:** tidak ada cacat produk. Tiga perbaikan ada di sisi data uji:
- ekspektasi waktu UTC keliru;
- nama field alasan tolak reschedule seharusnya `review_note`;
- header Bearer yang terbawa ke request berikutnya di harness tes.

## 7. Menunggu keputusan

| Hal | Perilaku sekarang | Perlu diputuskan |
|---|---|---|
| Sesi yang tidak pernah ditutup | Tetap DIBUKA tanpa batas; tampil "Dibuka" di monitor | Apakah ada penutupan otomatis/penanda "tidak ditutup" setelah shift, dan kapan |
| Batas penutupan terlambat | Diterima kapan pun setelah shift, ditandai | Batas waktu maksimal (mis. 24 jam) untuk HP offline |
| Temuan setelah ditutup nihil | Diterima dan ditandai, tanpa tindakan Admin | Apakah perlu review/ACC Admin |
| Penutupan oleh Admin atas nama Posko | Tidak ada | Bila diperlukan (HP rusak), perlu alasan dan audit khusus |
| Pembukaan ulang sesi | Tidak ada | Kebijakan koreksi penutupan keliru |
| Koreksi temuan keliru | Belum ada (dari B3.2) | Dirancang terpisah; Admin Web saja |
| Toleransi jam HP | 10 menit ke depan, sama dengan temuan | Konfirmasi |
| Pembatalan WO P0 | Semua WO P0 tidak dapat dibatalkan (B3.3) | Aturan batal P0 |

## 8. Risiko

| Risiko | Dampak | Langkah |
|---|---|---|
| Outbox Flutter mengirim penutupan sebelum laporan | 409 berulang | Outbox wajib mengurutkan penutupan setelah laporan sesi yang sama (dependency) |
| Penanda dihitung saat dibaca | 2 query kecil per sesi di monitor (6 Posko) | Cukup untuk skala sekarang; simpan ringkasan bila volume tumbuh |
| Deploy | Production belum memiliki migration Batch A–B3.4 | Urutan A → B1 → B2 → B3.1 → B3.2 → B3.3 → B3.4 dengan backup |
