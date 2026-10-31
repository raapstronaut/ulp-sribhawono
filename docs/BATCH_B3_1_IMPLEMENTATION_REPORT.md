# Laporan Implementasi Batch B3.1
## Fondasi Sesi PASILA dan Referensi Section — ULP Sribhawono

- **Tanggal:** 30 Oktober 2026
- **Branch:** `feat/admin-web-foundation-v1` (PR #10, tetap OPEN), basis `3187a12` (B2)
- **Status kode:** commit `24af20a`, di-push ke PR #10 (belum di-merge). Tidak ada merge, deploy, migration production, perubahan Railway, maupun perubahan Google Sheets.
- **Status operasional:** pembuka sesi PASILA **nonaktif secara default** (`PASILA_OPENER_ENABLED=false`) dan scheduler-nya tidak terdaftar. Temuan per titik (B3.2) dan P0 (B3.3) belum dibangun.

## 1. Keputusan pengguna yang diterapkan (30 Okt 2026)

| No | Keputusan | Penerapan |
|---|---|---|
| 1 | Tanggung jawab Section setiap Posko tetap | Kepemilikan hanya dari `SectionPoskoMap`; sesi PASILA menyimpan pilihan shift, bukan kepemilikan |
| 2 | Satu Posko dapat memegang banyak Section di banyak Penyulang | `SectionPoskoMap::sectionsForPosko()`; diuji |
| 3 | Kepemilikan tidak berpindah antar-Posko per shift | Admin hanya dapat memilih Section milik Posko sesi; Section Posko lain ditolak |
| 4 | Yang berubah tiap shift hanya rekomendasi Section | Strategi rotasi memilih di antara Section milik Posko |
| 5 | Rujukan dari data terverifikasi, bukan hardcode/tebakan | Tidak ada daftar Section di kode; data tidak lengkap/ambigu ditahan dengan alasan |
| 6 | Data resmi dapat dimasukkan tanpa mengubah logika | Kontrak `docs/SECTION_REFERENCE_CONTRACT.md`, validator berkas, `sources:import --only` |

## 2. Struktur database

Migration additive `2026_10_30_000001_create_pasila_sessions_table` (rollback hanya menghapus tabel ini).

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | uuid PK | Dirujuk temuan (B3.2) dan P0 (B3.3) kelak |
| `code` | string(60) unik | `PSL-{tanggal operasional}-{shift}-{kode Posko}`, mis. `PSL-20261029-A-PUGUNG` |
| `shift_instance_id` | FK `shift_instances` | Identitas shift B2 (tanggal operasional + kode) |
| `posko_id` | FK `poskos` | |
| `status` | string(24) | Siklus sesi: `DIBUKA` (status pelaksanaan menyusul di B3.2) |
| `section_status` | string(24) | `MENUNGGU_PENENTUAN` / `REKOMENDASI_SISTEM` / `DITETAPKAN_ADMIN` |
| `penyulang_id` | FK `penyulangs`, nullable | Penyulang Section yang dipilih |
| `section_key` | string, nullable | Section ternormalisasi (`ApkukSectionService::normalize`), bukan ID baris staging |
| `section_name` | string, nullable | Nilai tampilan Section |
| `section_hold_reason` | string(60), nullable | `TIDAK_ADA_SECTION_TERVERIFIKASI` bila menunggu |
| `recommendation` | json | Strategi, versi, `is_machine_learning=false`, sumber referensi, jumlah Section dipertimbangkan, Section rekomendasi awal, alasan |
| `section_set_by_user_id`, `section_set_at`, `section_set_reason` | | Penetapan Section oleh Admin |
| `opened_at`, `opened_trigger`, `opened_by_user_id` | | Waktu buka nyata (UTC), `SCHEDULER`/`CLI`/`ADMIN_WEB` |

Indeks: **unique (`shift_instance_id`, `posko_id`)**, unique `code`, index (`posko_id`, `section_key`) untuk rotasi.

Status sesi, penentuan Section, status WO APKUK (dihitung dari `work_orders`), dan kewajiban P0 (belum aktif) tidak dicampur dalam satu kolom.

## 3. Komponen

| Komponen | Isi |
|---|---|
| `SectionPoskoMap` (diperluas) | `fromRows()` (berkas calon data), `entries()` (inventaris Section per Penyulang dengan Posko atau alasan ditahan), `sectionsForPosko()`, `entry()`. `load()` dan `resolve()` B2 tidak berubah perilakunya |
| `PasilaShiftOpener` | Membuka sesi shift berjalan per Posko aktif; idempoten; advisory lock per shift (namespace `720_261_030`, `lock_timeout` 10 dtk); tidak membuka shift lampau; audit `CREATED`; fail-closed lewat flag |
| `PasilaSectionStrategy` + `RotationSectionStrategy` | Kontrak strategi (dipilih `config('pasila.section_strategy')`) dan baseline rotasi: Section milik Posko yang paling lama tidak ditugaskan; seri diurutkan Penyulang lalu Section. Bukan skor risiko, bukan ML, tanpa data gangguan |
| `PasilaSectionService` | Penetapan Section oleh Admin: hanya Section terverifikasi milik Posko sesi, alasan ≥ 10 karakter, shift belum berakhir, `FOR UPDATE`, audit sebelum/sesudah |
| `pasila:open-shift` | Command pembuka (`--trigger=CLI|SCHEDULER`) |
| `pasila:section-references` | Laporan referensi aktif, atau validasi berkas CSV calon data resmi (`--file`) tanpa menulis |
| `sources:import` | `--only=` untuk satu tab (mis. `SECTION_PIC_RAW`), nama tab divalidasi sebelum menghubungi Google. Membaca lewat `GoogleSheetValues` (read-only, dapat ditiru di tes). Pengaman `SECTION_PIC_RAW`: wajib `--replace` bila staging berisi; isi tanpa satu pun Section terverifikasi ditolak; ringkasan Section terverifikasi sebelum/sesudah dan Section yang berganti Posko |
| `routes/console.php` | Entri scheduler tiap 15 menit, **hanya terdaftar bila** `PASILA_OPENER_ENABLED=true` |
| `PasilaController` + view | Monitor dan detail membaca sesi asli; tombol buka sesi shift berjalan; form tetapkan/ubah Section |

## 4. Pembuka sesi

- **Retry dan scheduler terlambat:** setiap eksekusi hanya membuat sesi Posko yang belum ada. Scheduler berjalan tiap 15 menit, sehingga eksekusi yang terlewat tetap membuka sesi selama shift masih berjalan. Keterlambatan tercatat (`opened_at` terhadap awal shift) dan ditampilkan.
- **Paralel:** kunci advisory per shift menyerialkan pembuka; unique (`shift_instance_id`, `posko_id`) menjadi pengaman terakhir. Bila kunci tidak didapat dalam 10 detik, eksekusi ditolak tanpa sesi setengah jadi.
- **Tanpa backfill:** hanya shift yang sedang berjalan yang dibuka. Shift yang terlewat tampil "Tidak ada sesi" dan tidak dibuat mundur.
- **Tanpa Section terverifikasi:** sesi tetap dibuat dengan status menunggu penentuan Section.
- **Tidak menyentuh WO APKUK:** pembuka tidak membaca atau mengubah WO, reschedule, kuota, alokasi, maupun run generator B2.
- **Aktivasi:** `PASILA_OPENER_ENABLED` (default `false`). Railway tidak menjalankan `schedule:run`; aktivasi production adalah keputusan terpisah.

## 5. Web Admin

- **PASILA Monitor:** kolom terpisah untuk sesi (status, kode, waktu buka, keterlambatan), Penyulang · Section (sumber penentuan, alasan ditahan, peringatan bila Section tidak lagi terverifikasi), WO APKUK, reschedule diajukan, dan kewajiban P0 ("Belum aktif"). Panel referensi menampilkan jumlah Section terverifikasi per Posko beserta Penyulangnya, serta daftar referensi yang ditahan dengan alasan.
- **Detail Posko:** panel sesi dengan rekomendasi awal dan alasannya, form tetapkan/ubah Section (hanya Section milik Posko), daftar Section terverifikasi dengan riwayat penugasan, WO APKUK, dan reschedule.
- Keadaan tanpa data ditampilkan jujur: "Belum dibuka", "Tidak ada sesi" untuk shift lampau, "Shift belum dimulai", serta kebutuhan data untuk Posko tanpa Section.

## 6. Rancangan lanjutan (tidak dibuat di B3.1)

**Temuan PASILA (B3.2):** `pasila_findings` (FK `pasila_session_id`, kode temuan, catatan, aset/keypoint opsional, lat/lon/akurasi, asal koordinat perangkat/aset, `occurred_at`, `received_at`, tim/akun pengirim, `client_request_id` unik), `pasila_finding_media` (foto per temuan), dan `pasila_finding_personnel` (petugas per temuan). Satu sesi memiliki banyak temuan; setiap temuan dikirim sebagai satu record.

**P0 (B3.3):** setiap P0 dan usulan P0 SYSTEM wajib memiliki FK `pasila_session_id` dan mewarisi Posko, Penyulang, dan Section dari sesi. Bentuk penyimpanan P0 (WO atau tabel terpisah), sumber usulan, aturan target sah, dan form detail masih keputusan terbuka. Tidak ada kolom P0 maupun P0 otomatis yang dibuat sekarang.

## 7. Pengujian

| Uji | Lingkungan | Hasil |
|---|---|---|
| Suite Laravel penuh | SQLite in-memory | **388 lulus / 3.246 assertion** (sebelum B3.1: 352/2.991), termasuk regresi B1/B2 |
| `SectionReferenceImportTest` | SQLite, Google Sheets ditiru | 12 tes: hanya tab Section dibaca dan diganti; staging ASSET_RAW/VEGETASI_RAW tidak berubah; import ulang tanpa duplikasi; tanpa `--replace` ditolak; header salah/huruf kecil/kolom tambahan/tab kosong/gagal baca/kolom tertukar → referensi lama utuh; tab hanya header tidak menghapus; perubahan langsung dibaca `SectionPoskoMap`, PASILA (sesi lama ditandai, Section Posko lain ditolak, sesi baru memakai referensi baru), dan generator APKUK B2; importer hanya membaca lewat pembaca read-only |
| `PasilaSessionTest` | SQLite | 24 tes: Posko multi-Penyulang, dua Posko di Penyulang yang sama, Section Posko lain/tidak ditemukan/ambigu/tanpa PIC/multi-Penyulang/Penyulang asing ditolak, pembuka idempoten, rotasi, enam batas waktu Shift A/B/C dan tanggal operasional Shift C, keterlambatan dan tanpa backfill, flag nonaktif + scheduler tidak terdaftar, sesi tetap ada dengan WO belum selesai/reschedule diajukan tanpa mengubah WO, sesi tanpa referensi Section, audit, monitoring, hak akses tamu/akun lapangan, command dan validasi berkas |
| PostgreSQL | Postgres lokal, schema sementara `b31test_*` (dihapus) | **30/30**: migrate 27/27, unique/FK/json, rollback hanya `pasila_sessions`, rotasi A→B→C, penetapan Admin + audit, unique menolak sesi ganda, **dua proses bersamaan → 3 sesi (bukan 6), proses kedua menunggu ±3,5 dtk**, kunci dipegang 13 dtk → ditolak `lock_timeout` tanpa sesi setengah jadi, retry berhasil; import `SECTION_PIC_RAW` tiruan (transaksi ganti, staging lain utuh, tanpa duplikasi, isi tanpa Section terverifikasi dan tanpa `--replace` ditolak) |
| Browser | Edge headless, `php -S :8010`, schema sementara `b31browser_*` (dihapus) | **40/40**: buka sesi dari Web Admin, idempoten, form hanya Section milik Posko, penetapan + audit, manipulasi form ke Section Posko lain ditolak server, Posko tanpa Section, Audit Log, shift lampau, seluler tanpa scroll horizontal, 0 error console |
| Pint `--test` | File PHP yang disentuh | Lulus, kecuali `ImportSourceSheets.php`: sisa fixer hanya mengenai dua baris lama di `resolveHeader()` (subset kegagalan versi HEAD); tidak dirapikan agar diff fokus |
| `npm run build` | Vite | Berhasil (aset di-ignore git) |

Temuan selama pengujian dan final review (semua diperbaiki dan dikunci tes):
- `opened_at` tersimpan dalam jam WIB bila pemanggil memberi waktu ber-zona Asia/Jakarta (ditemukan uji PostgreSQL); kini disimpan UTC.
- `sources:import` untuk `SECTION_PIC_RAW` tanpa `--replace` pada staging berisi akan menambahkan baris yang bercampur dengan baris lama menurut nomor baris sumber, sehingga pewarisan PENYULANG salah dan baris tergandakan; kini ditolak.
- Isi tab dengan header benar tetapi kolom tertukar akan menggantikan seluruh referensi dengan referensi yang semuanya ditahan; kini ditolak bila tidak ada satu pun Section terverifikasi.
- Keluaran CLI di lingkungan ini membuang karakter non-ASCII (mis. `→`), sehingga laporan pergantian Posko terbaca ambigu; pesan CLI baru kini hanya ASCII ("dari X ke Y").

## 8. Menunggu data resmi

- Pengesahan isi `SECTION_PIC_RAW` sebagai referensi resmi Section → Posko (DT-1). Saat ini masih staging; cakupan per Posko di production belum diverifikasi ulang pada batch ini.
- Section untuk Posko yang belum memiliki Section terverifikasi.
- Bobot prioritas Section PASILA (TBD-11) untuk menggantikan baseline rotasi.
- Kode temuan PASILA dan form P0 (untuk B3.2/B3.3).

## 9. Risiko dan keputusan terbuka

| Hal | Dampak | Usulan |
|---|---|---|
| Aktivasi pembuka di production | Tanpa aplikasi Flutter, sesi tercatat tetapi tidak dapat dikerjakan | Aktifkan bersama Flutter dan kode resmi; perlu keputusan dan perubahan konfigurasi Railway |
| Satu sesi per Posko per shift dikunci unique | PASILA manual tambahan untuk Section lain pada shift yang sama memerlukan perubahan constraint | Putuskan bila PASILA tambahan memang dibutuhkan |
| Rotasi menghitung penugasan, bukan patroli nyata | Section yang ditugaskan tetapi tidak dipatroli dianggap sudah dirotasi | Setelah B3.2, rotasi dapat memakai temuan/penutupan sesi |
| Section sesi lampau tidak dapat diubah | Koreksi setelah shift berakhir tidak tersedia | Disengaja; koreksi riwayat memerlukan aturan tersendiri |
| Staging `SECTION_PIC_RAW` diimport manual | Data lama tetap dipakai sampai import berikutnya | Prosedur §4 kontrak; import production perlu persetujuan |
| Deploy | Production belum memiliki migration Batch A, B1, B2, B3.1 | Urutan A → B1 → B2 → B3.1 dengan backup |
