# Panduan pengembangan Backend ULP Sribhawono

Baca terlebih dahulu `../docs/PROJECT_STATUS.md`, `../docs/PRD.md`, dan `../docs/DATA_MODEL.md`. Baseline operasional **v3** lebih baru daripada semua dokumen v2 di histori Git.

- ADMIN memakai Laravel Web, POSKO/HARSUS/INSPEKSI/ROW memakai Android.
- P0 wajib terkait PASILA dan dikerjakan POSKO; HARSUS menerima WO Pekerjaan Khusus.
- Akun login mewakili tim; petugas adalah manusia dan dipilih dari roster.
- Jangan mengasumsikan data aset, Section, GIS, foto, kode dropdown atau daftar petugas sudah lengkap.
- Jangan reset PostgreSQL, mengubah migration yang pernah applied, menghapus histori, atau memasukkan contoh sebagai data operasional nyata.
- Pengembangan Web/Admin wajib dilengkapi auth/role guard sebelum produksi.
- Jangan otomatis memasang atau upgrade tool/framework tanpa alasan pengembangan yang jelas; rujuk `composer.json`.
