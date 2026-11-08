# IMPLEMENTATION PLAN & EXECUTION ROADMAP
## Sistem Pengelolaan Pekerjaan Jaringan Distribusi — ULP Sribhawono

**Version:** v3.0 — 8 Oktober 2026  
**Baseline kode aktif:** `main` v3 (berasal dari feat/admin-work-orders-web dan telah mencakup semua commit implementasi terdahulu).  
**Status:** Rencana kerja terbaru; bukan laporan semua fase telah dilaksanakan

# 1. Strategi kerja yang disetujui

**Urutan:** perbaiki delapan dokumen aktif → selaraskan logika data/role/Section/pekerjaan yang independen dari data pusat → kalau terblokir data resmi, **kerjakan UI Web Admin yang dapat dibuat tanpa memalsukan data** → lanjut integrasi segera setelah data tiba.

Data pusat tidak harus lengkap untuk membangun UI, autentikasi/RBAC, migration domain generic, API contracts, unit tests, dan generator yang menggunakan data test. **Jangan mengisi canonical database dengan koordinat/foto/asset palsu untuk terlihat lengkap.**

Sebelum code change: buat branch kecil, jaga main, baca dokumen v3, desain/lock UI Stitch jika digunakan, implementasi Laravel Blade/CSS/JS sesuai source, test manual + automated, commit/push per page. Tidak ada reset PostgreSQL.

# 2. Kondisi awal terverifikasi melalui repository

| Modul | Status awal |
|---|---|
| Laravel foundation | ADA |
| PostgreSQL source staging/import/audit | ADA di repo; jumlah live DB perlu verifikasi |
| Master enam Posko, 16 Penyulang, 26 mapping | ADA |
| Section/asset canonical | BELUM FINAL |
| Web Dashboard Admin | UI SCAFFOLD, angka transaksi kosong |
| Web P0 Monitor + Detail | UI SCAFFOLD, HARSUS v2 salah |
| Web WO Monitor + Detail | UI SCAFFOLD, hanya POSKO dan placeholder |
| Web PASILA, Inspeksi, ROW, HARSUS, approval, Maps | BELUM LENGKAP |
| Role authentication/API | BELUM DIBANGUN SESUAI FINAL |
| Flutter | DEMO SKELETON |
| Tests | 73/274 dilaporkan historis, perlu run ulang |

# 3. Fase 0 — Rebaseline dokumentasi (SEGERA)

Revisi langsung: PRD.md, DESIGN_BRIEF.md, TECHNICAL_SPEC.md, DATA_MODEL.md, OFFLINE_SYNC_SPEC.md, API_CONTRACT.md, IMPLEMENTATION_PLAN.md, PROJECT_STATUS.md. **FINAL_AUDIT_REPORT.md jangan diubah.**

Checklist: P0 POSKO dalam PASILA, HARSUS WO khusus, ROW WO auto, Inspeksi ranking dan temuan, P0 SYSTEM/ADMIN, reschedule, manual Admin, escalation child, status selesai diteruskan, Maps Leaflet global search, GPS/foto, offline auto, role akun tim, kinerja dan kelengkapan data.

# 4. Fase 1 — Audit kode/live database dan kontrak data

- Cek git status lokal/remote branch dan migrations yang applied; backup database sebelum schema changes.
- Konfirmasi angka staging terbaru dari PostgreSQL live, tidak mengandalkan angka laporan lama.
- Audit KEYPOINT FCO/switching, Section/PIC, alias Penyulang, koordinat, ID/duplikat, coverage relasi aset.
- Susun matrix kebutuhan data pusat (asset ID, Section batas, latlon, GIS optional, media/histori, maintenance, gangguan affected-assets, master petugas/kode).
- Tandai missing official fields agar workflow degradable; jangan tebak kepemilikan aset.

**Dapat dikerjakan sekarang tanpa seluruh data pusat:** role model, auth guard, master petugas/ref-code/shift design, data quality validator, ERD, migrasi structural, test fixtures.

# 5. Fase 2 — Database/domain baru bertahap

1. Additive migration identitas tim/petugas/shift, canonical Section/aset + import mapping/provenance.
2. Generic WO + APKUK physical result + reschedule history.
3. PASILA session + per-finding + P0 linked + Admin approval/manual replacement invariant.
4. Inspeksi finding/validation/recommended team; escalation atomic linked child WO.
5. Vegetation risk/cycle (formula pluggable) + ROW auto WO aktif + route clustering.
6. Photo metadata, append-only asset history, audit Admin, gangguan-event/affected-assets relation.
7. API role scoped + idempotent submissions.

**Pengujian:** migration rollback test DB, duplicate shift scheduler, wrong-section P0 blocked, unauthorized role, recount 6 WO, row idempotency, escalation atomic, audit correction.

# 6. Fase 3 — Refactor UI Web yang ada

- Dashboard: ganti KPI P0 HARSUS → P0 POSKO, HARSUS WO khusus, ROW & inspeksi, approval actions.
- P0 Monitor/Detail: scope PASILA + source SYSTEM/ADMIN + ACC/reject/manual replacement.
- WO Monitor/Detail: generic multi-tim, new status termasuk SELESAI_DITERUSKAN, manual create.
- Hilangkan teks dan placeholder “data demo” yang menyesatkan; empty state netral untuk domain belum ada.
- Tambah autentikasi dan role ADMIN pada semua route /admin/*.

# 7. Fase 4 — Halaman Web yang bisa dikerjakan tanpa data pusat lengkap

Kerjakan per halaman bersama desain final:
1. PASILA Monitor/Detail beserta daftar temuan/P0.
2. Inspeksi review dan validation/ACC.
3. ROW + HARSUS work queue, route grouping.
4. Approval & escalation.
5. Maps Leaflet: selector Penyulang wajib, layer toggle, global search, sidebar detail/histori/foto, quick create. **UI bekerja dengan empty state/fixture labeled testing**.
6. Master petugas/kode/konfigurasi (operasional reference code tetap dikelola di DB sesuai keputusan user).
7. History/audit detail.

Frontend bisa diselesaikan walaupun penempatan marker akhir belum bisa dibuktikan; integrasi canonical data dilakukan setelah mapping pusat valid.

# 8. Fase 5 — Flutter Android (empat tim)

Bangun team login, scoped work feed, lightweight form dropdown/GPS/camera, Google Maps external deep link, foto BEFORE+AFTER sementara, PASILA per-finding, manual reschedule reason+note, dan SQLite durable submit/outbox.

Untuk offline: automatic delivery WorkManager/network constraint bila OS mengizinkan; queue/idempotent retry/notifikasi gagal/manual resend. Tes pada HP nyata, termasuk force-stop limitations; jangan janjikan transfer instan tanpa sinyal.

# 9. Fase 6 — Integrasi data pusat dan E2E

Minta data resmi Section/aset/koordinat, affected-asset disturbance, media historis, GIS bila ada. Normalisasi master canonical, verifikasi tempat/asset link di Maps, tautkan foto/perbaikan baru ke timeline; uji penuh WO APKUK → PASILA + P0 → ROW/HARSUS escalation → Admin review → asset history.

# 10. Prioritas dependency dan risiko

| Jika belum ada | Bisa lanjut | Tidak boleh diklaim selesai |
|---|---|---|
| GIS jalur | Leaflet markers, panel UI | Polyline jaringan resmi |
| Foto historis | Galeri baru + placeholder no photo | Foto historis pusat |
| Koordinat sebagian aset | UI, mapping, validasi kualitas | Marker benar untuk aset tanpa koordinat |
| Section master lengkap | Schema, importer, UI, fixtures | Generator lokasi operasional penuh |
| SOP kode pekerjaan | Referensi DB + generic code UI | Daftar pilihan resmi |
| Formula vegetasi | Workflows, scheduler skeleton, data test | Risk engine produksi |
| Frekuensi gangguan threshold | Skor parametrik/testing | Special-case final |
| Model ML tervalidasi (wajib sebelum go-live, keputusan 28 Okt 2026) | Generator rule-based sebagai baseline/fallback, dataset ber-versi, backtest | Sistem siap operasional |

# 11. Definition of Done tiap modul

Bukan hanya halaman terlihat. DoD: role guard, data benar tanpa fake operational row, kontrak API, DB migration/tests, empty/error/offline states, bukti foto/GPS, audit link jika relevan, dan regression test. Dokumentasikan file/perubahan/test dan branch/commit dalam PROJECT_STATUS.

**TBD bisnis:** SOP jenis kerja/temuan dan P0, status/approval reschedule, klasifikasi inspeksi, roster shift jika baru, duplicate WO ROW per vegetasi, aturan login device, final threshold frekuensi, serta ketersediaan data pusat.
