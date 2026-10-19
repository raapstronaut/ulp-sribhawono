# ULP Sribhawono — Sistem Operasional Jaringan Distribusi

**Versi kerja aktif: v3 (8 Oktober 2026). Branch utama: `main`.**

Sistem terdiri dari **Web ADMIN** (Laravel + PostgreSQL) dan **Flutter Android** untuk tim **POSKO, HARSUS, INSPEKSI, ROW**. Akun login mewakili tim, sedangkan petugas pelaksana aktual dipilih dari master petugas.

## Dokumentasi aktif (v3)

- [Status dan progres](docs/PROJECT_STATUS.md)
- [Kebutuhan operasional](docs/PRD.md)
- [Desain UI/UX](docs/DESIGN_BRIEF.md)
- [Arsitektur teknis](docs/TECHNICAL_SPEC.md)
- [Rancangan data](docs/DATA_MODEL.md)
- [Kontrak API](docs/API_CONTRACT.md)
- [Offline sync mobile](docs/OFFLINE_SYNC_SPEC.md)
- [Rencana pengerjaan](docs/IMPLEMENTATION_PLAN.md)

`docs/FINAL_AUDIT_REPORT.md` adalah **audit historis**, bukan spesifikasi operasional terbaru.

## Struktur yang dipakai

- `backend/` — Laravel, Web Admin, migrasi dan impor data, fondasi API, tests.
- `mobile/` — Flutter Android untuk tim lapangan; fungsionalitas operasional masih perlu dikembangkan.
- `docs/` — delapan dokumen aktif v3 dan satu laporan audit historis.

## Status

Sudah dibuat: fondasi Laravel/Flutter, source staging/importer, sinkron otomatis gangguan/APKUK/keypoint dari Google Sheets (cron Railway → Neon shared dev), master enam Posko dan 16 Penyulang, pivot Posko–Penyulang, login dan otorisasi Web ADMIN, scaffold Web Admin Dashboard/P0/WO, serta migration fondasi tim/petugas/shift/kode dropdown (**belum dijalankan di Neon shared dev**).

Belum selesai: akun/role tim mobile, master Section/aset canonical, transaksi WO/PASILA/P0/ROW/HARSUS/INSPEKSI, Maps Admin, Flutter form dan offline outbox.

**Business flow v3:** P0 adalah pekerjaan **POSKO dalam PASILA**; HARSUS menangani **WO Pekerjaan Khusus**. Tidak ada lagi ketentuan tetap >3 trip/bulan.

## Aturan pengembangan

Gunakan `main` sebagai satu-satunya acuan terbaru. Buat branch sementara hanya bila benar-benar diperlukan untuk fitur berikutnya. **Jangan reset database**, mengubah migration yang pernah applied, menghapus histori, atau mengarang data operasional.
