# PROJECT STATUS & COLLABORATION HANDOFF
## Sistem Pengelolaan Pekerjaan Jaringan Distribusi — ULP Sribhawono

**Version:** v3.0 — 8 Oktober 2026  
**Scope:** Single-tenant, 5 role, Admin Web + Flutter 4 role  
**Status dokumen:** Source of truth aturan bisnis terbaru setelah klarifikasi 6–8 Oktober 2026  
**Engineering baseline GitHub:** feat/admin-work-orders-web @ 6d8ec22c9e12bcfd19b838c9301076c1ec38ff5e  
**Verifikasi:** Audit kode melalui GitHub; bukan audit database runtime lokal, bukan tes ulang proyek terbaru

# 1. Baca ini pertama

Dokumen v2 di histori Git sudah superseded; keputusan terbaru v3 mengungguli narasi lama. Rujuk spesifikasi aktif v3: PRD, DESIGN_BRIEF, TECHNICAL_SPEC, DATA_MODEL, API_CONTRACT, OFFLINE_SYNC_SPEC, IMPLEMENTATION_PLAN. Jangan ubah FINAL_AUDIT_REPORT.md karena laporan masa lalu.

**Larangan revived:** HARSUS executor P0, POSKO tanpa P0, P0 tidak terkait PASILA, hard threshold >3 trip per bulan, manual-only offline submit, Google Maps sebagai web map utama, POSKO sebagai satu-satunya pemegang WO, data FCO terpisah masih wajib diminta tanpa audit KEYPOINT.

# 2. 5 role terkunci

| Role | Platform | Akses/pekerjaan |
|---|---|---|
| ADMIN | Web Laravel | Semua monitor, review/ACC-Tolak, manual WO/PASILA/P0/HARSUS/ROW, Maps, koreksi history |
| POSKO | Flutter Android | 6 WO APKUK/shift/Posko, PASILA setiap shift + P0 dalam PASILA, reschedule |
| HARSUS | Flutter Android | WO PEKERJAAN_KHUSUS manual Admin atau limpahan setelah ACC |
| INSPEKSI | Flutter Android | Cari temuan nyata min 1/hari, ranking area + rotasi, validasi Admin |
| ROW | Flutter Android | WO otomatis dan manual RABAS/PANGKAS/TEBANG, cluster rute |

Satu akun tim per Posko (enam), satu akun untuk ROW/HARSUS/INSPEKSI, pilihan petugas aktual terpisah. Tim Posko tetap, shift dapat berubah. Batas 2 device dan logout 4 jam **bukan final**.

# 3. Aturan bisnis yang sudah final

- PASILA dibuat otomatis **awal Shift A/B/C** per Posko. WO APKUK diprioritaskan enam pekerjaan per shift, jumlah APKUK fisik dicatat sendiri.
- Jika WO belum lengkap, POSKO boleh lanjut PASILA+P0 jika sisa WO **direschedule** dengan dropdown alasan + catatan manual wajib dan pilihan petugas.
- Minimal satu P0 tiap shift; P0 **POSKO**, wajib terhubung PASILA dan Section/Posko sama. SYSTEM proposal ACC Admin sebelum aktif; reject → Admin **membuat P0 manual pengganti**; Admin P0 manual dari PASILA.
- PASILA boleh berisi banyak temuan, **masing-masing foto/GPS/detail sendiri**.
- HARSUS punya WO khusus langsung Admin berasset jaringan atau child escalation ACC. Cuaca/reschedule tidak dialihkan ke HARSUS.
- Eskalasi lintas tim membutuhkan ACC Admin, source **SELESAI_DITERUSKAN**, child WO baru dengan provenance sumber.
- INSPEKSI wajib berusaha menemukan >=1 temuan nyata/hari; no-finding tercatat jujur dan berpindah area. Sistem rekomendasi tim tujuan, Admin ACC.
- ROW auto WO **langsung aktif tanpa ACC**, banyak per hari, lokasi dekat dikelompokkan/rute bergilir.
- Admin boleh membuat manual seluruh jenis pekerjaan yang didukung sistem.
- Bukti pekerjaan secara sementara BEFORE+AFTER dan device GPS otomatis; dropdown form, catatan biasa opsional.
- Offline submit lokal tetap sah sebagai *submission queued*, dikirim otomatis saat kondisi mendukung, gagal tersimpan dan dapat kirim ulang tanpa input ulang.
- Maps Web Leaflet pilih Penyulang wajib; global search 16 Penyulang switch otomatis, layer gangguan lokasi kejadian → affected assets, drawer foto terbaru dan history lama, quick create Admin; GIS jalur opsional.
- History tidak dihapus, koreksi hanya Admin dengan old/new/actor/waktu/alasan.
- Special case berbasis **frekuensi gangguan**, bukan fixed >3 trip. Metode/ambang masih TBD.

# 4. Arsitektur yang dipertahankan

Laravel backend/API/Admin Web, PostgreSQL authoritative, Flutter Android, SQLite cache/outbox ringan, Laravel Filesystem local dev/S3-ready prod, Leaflet/OSM Web, Google Maps external mobile navigation, scheduler/queue dan polling. Tidak menggunakan Next.js/Supabase/PowerSync/full offline basemap.

# 5. Implementasi yang terkonfirmasi dari GitHub

| Komponen | Kondisi |
|---|---|
| Laravel repo/backend framework | Ada |
| Enam staging source tables + artisan imports, profiler, audit | Ada |
| Master poskos/penyulangs/pivot | Ada |
| Canonical Section/Asset & master Petugas | Belum dibangun |
| Auth Web ADMIN dan role mobile | Belum lengkap; route admin belum middleware auth/role |
| Dashboard Admin | UI dibangun, sebagian data master asli, transaksi masih nol |
| P0 Admin Monitor+Detail | UI dibangun, belum domain, teks HARSUS perlu refactor |
| WO Admin Monitor+Detail | UI dibangun, belum domain, masih POSKO-focused |
| PASILA/Inspeksi/ROW/HARSUS/approval/Maps full | Belum dibangun |
| Flutter mobile domain | Masih demo Flutter default |
| API mobile + offline sync | Belum dibangun |

Branch main pada commit 5e54446b79d90d1ed396ad57575e24fbba4d937a; branch fitur admin-work-orders-web **3 commit di depan main**, berisi Dashboard, P0, WO. Jangan salah menganggap main memuat UI tersebut. 
Riwayat **73 tests passed / 274 assertions** dicatat pada dokumentasi lama; belum diuji ulang dalam audit repo ini.

# 6. Data snapshot sumber (historis, bukan query live)

- Enam Posko, 16 Penyulang, 26 pivot.
- Laporan historis: sekitar 17.059 aset valid dari staging, 16.983 record vegetasi, 129 keypoints, 69 Section/PIC.
- Spreadsheet APKUK dan gangguan telah mendapat pembaruan setelah dokumentasi v2: snapshot percakapan terakhir menyebut **2.922 APKUK source rows** dan **451 gangguan**, sedangkan dokumen GitHub v2 masih menyebut 2.864 dan 443. **Jangan menganggap salah satunya jumlah live PostgreSQL tanpa re-check import/status DB.**
- KEYPOINT menurut klarifikasi berisi seluruh data FCO/switching yang diperlukan; verifikasi canonical keypoint/Section tetap wajib. Dua PIC Section dari staging masih kosong, jangan ditebak.

# 7. Apa yang menunggu data atau keputusan

**Butuh pusat/atasan:** kepastian Section/batas, ID dan koordinat aset/cross references, histori maintenance, foto historis, GIS optional, gangguan affected assets, master petugas/kode temuan resmi, formula prioritas & vegetasi, detail form P0, kebijakan sesi perangkat, approval reschedule, aturan carryover, duplicate vegetation WO dan formula frekuensi special-case.

**Tidak blocked:** dokumen, RBAC/auth skeleton dan tests, migration additive berdasarkan schema pasti, kode reference/master, API contracts, desain dan UI admin halaman berikutnya, peta dengan empty state/lazy filtering, offline worker design.

# 8. Prioritas pekerjaan berikutnya (keputusan 8 Okt)

1. Sinkronkan **delapan dokumen aktif** sebagai v3 tanpa menyentuh FINAL_AUDIT_REPORT.
2. Audit database runtime lokal + susun ERD/migration domain dan hak akses/role yang tidak bergantung data pusat.
3. Jika Section/canonical assets dan formula belum siap, jangan menunggu diam: refactor Dashboard/P0/WO dan lanjut **UI Web Admin** PASILA, Inspeksi, ROW/HARSUS, Approval, Maps/History secara bertahap.
4. Saat data resmi tersedia, import canonical tervalidasi dan hubungkan UI ke transaksi/backend.
5. Bangun Flutter dengan automatic offline outbox dan tes integrasi end-to-end.

**DoD:** setiap fitur diuji auth+authorization+integritas+foto/GPS/status, tidak ada fake data operasional, log/audit tertaut, setelah selesai update status berikut branch/commit/hasil tes. Jangan klaim rilis tanpa hasil runtime.

# 9. Perubahan manual terhadap repository

Rebaseline v3 di-commit pada **branch kerja terpisah** dari feat/admin-work-orders-web. Tidak ada merge ke main, reset DB, perubahan file migration lama, atau penulisan ke data operasional hanya dari pembaruan dokumen. Lihat riwayat commit branch untuk waktu/sha final.
