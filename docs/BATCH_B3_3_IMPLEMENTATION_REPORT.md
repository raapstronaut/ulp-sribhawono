# Laporan Implementasi Batch B3.3
## Workflow P0 POSKO — ULP Sribhawono

- **Tanggal:** 1 November 2026
- **Branch:** `feat/admin-web-foundation-v1` (PR #10, belum di-merge), basis `6da38d3` (B3.2)
- **Status kode:** di-commit dan di-push ke PR #10 (belum di-merge) setelah final review (§9). Tidak ada merge, deploy, migration production, perubahan Railway, maupun perubahan Google Sheets.
- **Usulan P0 SYSTEM otomatis:** **belum siap.** Klasifikasi kode temuan yang memerlukan P0 dan bobot prioritas resmi belum ada, sehingga tidak ada generator yang membuat usulan. Mesin ACC/tolak sudah berjalan dan diuji dengan usulan simulasi; P0 operasional dibuat lewat jalur manual Admin.

## 1. Aturan bisnis yang diterapkan

| No | Aturan | Penerapan |
|---|---|---|
| 1 | P0 pekerjaan POSKO | WO `team_type` POSKO, `work_type` P0; feed hanya untuk tim POSKO Posko itu; limpahan P0 ke HARSUS/ROW ditolak |
| 2 | Semua P0 terkait sesi PASILA valid | `p0_items.pasila_session_id` wajib |
| 3 | Sumber: temuan PASILA atau masalah sebelumnya yang tercatat dan terbukti | Temuan PASILA milik Posko (sesi itu atau sebelumnya) atau temuan inspeksi yang sudah di-ACC Admin dan belum punya WO tindak lanjut |
| 4 | Section dan Penyulang valid di wilayah tetap Posko | Diambil dari sumber dan divalidasi `SectionPoskoMap` saat dibuat **dan saat ACC** |
| 5 | Usulan SYSTEM wajib ACC sebelum aktif | Usulan disimpan di `p0_items` tanpa WO; WO dibuat hanya saat ACC |
| 6 | Penolakan → P0 manual pengganti dalam PASILA terkait | `replacement_status` WAJIB → TERPENUHI saat P0 manual dengan `replaces_p0_item_id` dibuat di sesi yang sama |
| 7 | Admin boleh membuat P0 manual yang sah | Validasi sumber dan Section setara usulan SYSTEM |
| 8 | Target ≥1 P0 per Posko per shift terpenuhi hanya bila selesai dengan bukti | Dihitung dari WO P0 berstatus SELESAI (laporan BEFORE/AFTER, GPS, petugas, lalu verifikasi Admin) |
| 9 | P0 tidak termasuk kuota 6 WO APKUK | Kuota hanya menghitung `PEMASANGAN_APKUK` |
| 10 | Tanpa kandidat sah, tidak ada pekerjaan palsu | Form manual hanya menawarkan sumber tercatat; kewajiban tampil "belum terpenuhi" |
| 11 | PASILA boleh nihil walau P0 belum terpenuhi | Laporan nihil (B3.2) tidak bergantung P0 |
| 12 | Penutupan PASILA dengan reschedule menunggu ACC | Penutupan sesi belum dibangun (lihat §8) |
| 13 | Rule-based bukan ML | `recommendation.is_machine_learning` selalu `false`; strategi bawaan berstatus belum siap |

## 2. Struktur database

Migration additive dan reversible `2026_11_01_100001_create_p0_items_table` (rollback hanya menghapus `p0_items`). Tabel `work_orders` tidak diubah.

| Kolom `p0_items` | Keterangan |
|---|---|
| `code`, `pasila_session_id`, `sequence` | `P0-{tanggal}-{shift}-{Posko}-NN`; unique (sesi, sequence) |
| `posko_id`, `penyulang_id`, `section_key`, `section_name` | Dari sumber, terverifikasi milik Posko sesi |
| `origin` | `SYSTEM` (usulan) atau `ADMIN` (manual) |
| `source_type`, `pasila_finding_id`, `inspection_finding_id`, `source_snapshot` | Sumber masalah dan salinannya saat P0 dibuat |
| `active_source_key` (unik) | Satu P0 menunggu ACC/berjalan per sumber; dikosongkan saat usulan ditolak |
| `description`, `recommendation` | Uraian pekerjaan; untuk SYSTEM: strategi, versi, `is_machine_learning`, alasan |
| `approval_status` | `MENUNGGU_ACC` / `DISETUJUI` / `DITOLAK` / `TIDAK_PERLU` (manual) |
| `replacement_status`, `replaces_p0_item_id` (unik) | Kewajiban pengganti dan tautan pengganti → usulan yang ditolak |
| `work_order_id` (unik) | WO P0; kosong selama usulan belum di-ACC atau bila ditolak |
| `created_by_user_id`, `reviewed_by_user_id`, `reviewed_at`, `review_note` | Pembuat dan peninjau |

**WO P0** memakai mesin WO yang ada:
- kode `WO-P0-YYYYMMDD-NNNN`;
- target lokasi `PASILA:{temuan}` atau `INSPEKSI:{temuan}`, dengan `active_target_key` yang mencegah dua WO P0 aktif di titik yang sama;
- status log `CREATED` berisi `p0_item_id` dan sesi PASILA.

**Tahap monitoring** dihitung, tidak disimpan: Menunggu ACC; Ditolak menunggu pengganti; Ditolak sudah diganti; Aktif; Selesai dilaporkan menunggu verifikasi; Selesai; WO dibatalkan/diteruskan.

## 3. Alur usulan SYSTEM

1. Strategi (`P0CandidateStrategy`, dipilih `config('pasila.p0_strategy')`) hanya boleh memilih dari sumber sah `P0Service::candidates()`. Strategi bawaan `UnavailableP0CandidateStrategy` menolak dengan alasan "belum siap", sehingga tidak ada usulan otomatis.
2. `proposeSystem()` membuat usulan `MENUNGGU_ACC` tanpa WO. Usulan tidak tampil di aplikasi POSKO.
3. **ACC** (`POST /admin/p0/{id}/acc`):
   - usulan dikunci baris;
   - sumber dan kepemilikan Section diperiksa ulang;
   - jadwal shift dipilih (default shift sesi, atau shift berjalan bila sesi sudah berakhir);
   - WO P0 dibuat, status `DISETUJUI`, tercatat di audit (sebelum/sesudah).
   ACC atau tolak ulang → konflik, tanpa WO kedua.
4. **Tolak** (`POST /admin/p0/{id}/tolak`): alasan wajib (≥10 karakter); status `DITOLAK`, pengganti `WAJIB`, sumber dilepas; usulan tidak pernah menjadi pekerjaan; tercatat di audit.

## 4. Alur P0 manual dan pengganti

1. Di detail sesi PASILA, Admin memilih sumber dari daftar sumber sah yang belum memiliki P0 berjalan, menulis uraian pekerjaan, dan memilih jadwal shift.
2. Bila sesi memiliki usulan yang ditolak dan belum diganti, Admin dapat menandai P0 manual sebagai penggantinya. Pengganti hanya untuk sesi yang sama, dan satu penolakan hanya punya satu pengganti.
3. P0 manual langsung menjadi WO P0 (`source` ADMIN_MANUAL). Kewajiban pengganti menjadi `TERPENUHI`.
4. Tanpa sumber sah, form tidak tersedia dan kewajiban tetap tampil "belum terpenuhi". Kewajiban pengganti yang belum dipenuhi tetap terlihat di monitoring.

**Pelaksanaan POSKO:** WO P0 muncul di `work-feed` tim POSKO Posko itu (blok `p0` berisi sesi PASILA) dan di `GET /api/v1/mobile/p0`. Tim mulai, lalu melapor `PEKERJAAN_SELESAI` (BEFORE+AFTER, GPS, petugas) → `MENUNGGU_VERIFIKASI` → Admin ACC laporan → `SELESAI`. Baru saat itu target terpenuhi. Tim Posko lain dan role lain tidak dapat melihat atau mengerjakannya.

## 5. Web Admin dan API

- **P0 Monitor** (`/admin/p0`): kartu per tahap, filter tahap/Posko, tautan ke sesi PASILA dan WO, serta status strategi otomatis.
- **Detail P0:** asal-usul sumber, rekomendasi (berlabel "bukan ML"), form ACC/tolak, keputusan, dan tautan pengganti.
- **PASILA:** kolom "Kewajiban P0" di Monitor (terpenuhi/belum, jumlah selesai, aktif, menunggu verifikasi, menunggu ACC, menunggu pengganti); panel P0 dan form P0 manual/pengganti di detail sesi.
- **API mobile:** `GET /api/v1/mobile/p0`; pelaksanaan lewat endpoint WO yang ada.

Scaffold lama `App\Services\P0Service` (data kosong statis) dihapus dan diganti `App\Services\Pasila\P0Service`.

## 6. Pengujian

| Uji | Lingkungan | Hasil |
|---|---|---|
| Suite Laravel penuh | SQLite in-memory | **410 lulus / 3.590 assertion** setelah final review (B3.2: 399/3.418), termasuk regresi B1/B2/B3.1/B3.2 |
| `P0WorkflowTest` | SQLite | 11 tes; termasuk final review: pembatalan P0 ditolak di semua jalur, editor tidak dapat memindahkan P0, pengganti tidak memenuhi target. Cakupan awal: usulan bukan pekerjaan → ACC → WO P0 (target lokasi, log, audit) dan ACC/tolak ulang ditolak; tolak → pengganti wajib di sesi yang sama, satu pengganti per penolakan; P0 manual dari temuan sesi sebelumnya dan temuan inspeksi tervalidasi, serta penolakan sumber belum divalidasi/tanpa Section/milik Posko lain/dari sesi lebih baru; perubahan referensi Section diperiksa ulang saat ACC; hanya POSKO pemilik yang mengerjakan, limpahan ditolak, target terpenuhi hanya setelah SELESAI; tidak masuk kuota APKUK; satu P0 berjalan per sumber; tanpa sumber sah tidak ada P0, kewajiban tetap tampil, PASILA tetap boleh nihil; monitor, detail, dan hak akses |
| PostgreSQL | Postgres lokal, schema sementara `b33test_*` (dihapus) | **17/17**: migrate 29/29, constraint unik/FK, `work_orders` tidak diubah, rollback hanya `p0_items`; **ACC ganda bersamaan → satu WO** (proses kedua menunggu ±3,7 dtk lalu konflik); **tolak vs ACC bersamaan → tanpa WO**; **P0 manual bersamaan untuk sumber sama (sesi sama dan lintas sesi) → satu P0**; kode WO unik; tidak masuk kuota APKUK |
| Browser | Edge headless, `php -S :8010`, schema sementara `b33browser_*` (dihapus) | **29/29**: monitor usulan, ACC lewat form, validasi alasan tolak, tolak, kewajiban di PASILA, form P0 pengganti, tahap akhir di monitor, kolom kewajiban PASILA, Audit Log, seluler tanpa scroll horizontal, 0 error console |
| Pint `--test` | file PHP B3.3 | Lulus |

**Temuan selama pengujian:** foreign key `replaces_p0_item_id` ke tabel `p0_items` sendiri gagal di PostgreSQL bila dibuat di dalam `Schema::create`, karena FK dijalankan sebelum primary key; SQLite tidak menunjukkan masalah ini. Diperbaiki dengan menambahkan FK setelah tabel dibuat. Migration ini belum pernah dijalankan di lingkungan bersama.

## 7. Menunggu keputusan atau data resmi

| Hal | Status | Usulan |
|---|---|---|
| Usulan P0 SYSTEM otomatis | Belum siap | Perlu klasifikasi kode temuan PASILA yang memerlukan P0 dan bobot prioritas resmi; cukup ditambahkan sebagai strategi baru tanpa mengubah alur |
| Form P0 detail (TBD-10) | Belum ada | Saat ini uraian pekerjaan + laporan WO generik; field resmi ditambahkan additive |
| Verifikasi Admin atas penyelesaian P0 | Mengikuti aturan WO non-APKUK (sementara, K-2) | Putuskan apakah P0 selesai langsung atau tetap diverifikasi |
| Kewajiban pengganti tanpa sumber sah | Tetap terbuka sampai ada sumber sah | Putuskan apakah Admin boleh menutup kewajiban dengan alasan "tidak ada pekerjaan sah" |
| Pembatalan WO P0 | Pengaman sementara: **semua** WO P0 tidak dapat dibatalkan (§9) | Putuskan aturan batal P0 dan pelepasan sumbernya |
| Sumber P0 lain (gangguan, APKUK, kendala WO) | Belum dipakai | Tambah bila datanya resmi dan terbukti |
| Penutupan PASILA, koreksi temuan keliru, toleransi jam HP | Dependency lanjutan (dari B3.2) | — |

## 8. Risiko

| Risiko | Dampak | Langkah |
|---|---|---|
| P0 bergantung pada temuan PASILA (B3.2) dan referensi Section | Tanpa kode temuan resmi dan Section terverifikasi, tidak ada sumber P0 | Lengkapi Master Data `PASILA_FINDING` dan SECTION_PIC_RAW |
| Jadwal WO P0 untuk sesi yang sudah berakhir | P0 dijadwalkan di shift berikutnya tetapi dihitung untuk sesi asal | Sesuai aturan target per sesi; tampil di monitoring |
| Monitor P0 menampilkan 300 P0 terbaru | Riwayat lama tidak tampil di halaman | Tambah paginasi server bila volume tumbuh |
| Deploy | Production belum memiliki migration Batch A–B3.3 | Urutan A → B1 → B2 → B3.1 → B3.2 → B3.3 dengan backup |

## 9. Final review sebelum commit (1 Nov 2026)

| Hal | Perubahan |
|---|---|
| Pembatalan WO P0 | Pengaman sementara karena kebijakan belum final: `WorkOrder::supportsAdminCancellation()` mengembalikan `false` untuk semua P0 (tombol dan `cancel()`), dan `WorkOrderLifecycleService::transition()` menolak transisi P0 ke DIBATALKAN dari jalur mana pun. Aturan pembatalan APKUK/HARSUS/ROW tidak berubah |
| Ikatan wilayah P0 | Editor WO Admin tidak dapat mengubah Section WO P0 dan hanya dapat menunjuk tim POSKO dari Posko pemilik sesi; jadwal tetap dapat diubah |
| Pengganti vs target | Dipisahkan: "Target P0 shift" hanya dari P0 SELESAI; "Kewajiban pengganti Admin" menghitung usulan ditolak yang belum diganti. Tahap usulan yang sudah diganti berlabel "Ditolak, pengganti sudah dibuat", dengan catatan bahwa target terpenuhi saat P0 pengganti selesai |
| Verifikasi penyelesaian | Tetap memakai verifikasi laporan WO yang ada (`PEKERJAAN_SELESAI` → MENUNGGU_VERIFIKASI → ACC Admin → SELESAI); tidak ada jalur selesai lain |

Regresi setelah final review: Laravel 410/410 (3.590 assertion), PostgreSQL B3.3 17/17, Pint lulus, `git diff --check` bersih.
