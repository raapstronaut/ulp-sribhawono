# MOBILE SUBMISSION & AUTOMATIC SYNCHRONIZATION SPECIFICATION
## ULP Sribhawono — Android Flutter / SQLite

**Version:** v3.0 — 8 Oktober 2026  
**Status:** Target desain; belum terimplementasi di Flutter repo  
**Pengganti:** perilaku lama “offline draft lalu user mengirim manual”

# 1. Aturan yang sudah dikunci

Petugas **mengisi dan menekan SUBMIT di lokasi pekerjaan**, baik online maupun offline. Saat submit, form dan foto **harus lebih dulu tersimpan aman pada perangkat**. Dengan sinyal baik, sistem mengirim segera; dengan sinyal buruk/hilang, submission masuk outbox lalu **diusahakan dikirim otomatis** ketika konektivitas memadai, tanpa petugas mengisi ulang atau sengaja membuka form. Jika gagal dan memerlukan aksi, notifikasi ditampilkan ke perangkat dan tersedia tombol **KIRIM ULANG** untuk data yang sudah disimpan.

Server baru mengakui data sebagai canonical setelah menerima/validasi. Jangan menampilkan pesan “berhasil terkirim” hanya karena data disimpan lokal.

# 2. Cakupan offline

Cache ringan: akun/role, tim, penugasan yang sudah diunduh, petugas aktif, kode dropdown, asset/Section relevan, konfigurasi shift dan metadata navigasi. Jangan mengunduh 16 penyulang beserta seluruh foto/histori ke HP. Tugas baru dari server baru dapat diterima setelah online.

Offline user dapat membuka tugas yang **sudah dicache**, melihat koordinat tersimpan, mengambil foto, merekam GPS jika perangkat memiliki fix, mengisi temuan, dan submit ke local outbox. Google Maps eksternal mungkin memerlukan koneksi peta/rute kecuali aplikasi Google Maps sendiri punya cache.

# 3. Status yang terlihat pengguna

| Status internal | Teks UI | Yang terjadi |
|---|---|---|
| LOCAL_SAVED / QUEUED | Tersimpan di HP — menunggu kirim | Data, foto dan metadata utuh lokal |
| SENDING | Mengirim ke server | Worker sedang mencoba upload |
| ACKNOWLEDGED | Terkirim ke server | Respons sukses + canonical ID direkam |
| RETRYABLE_ERROR | Menunggu jaringan / mencoba lagi | Retry otomatis dengan backoff |
| ACTION_REQUIRED | Gagal — ketuk Kirim Ulang | Payload tetap tersedia, tampil notifikasi perangkat |
| REJECTED_VALIDATION | Perlu perbaikan sesuai pesan | Tidak otomatis drop data; server error dipresentasikan |

Jangan gunakan status SELESAI di server hanya karena lokal QUEUED.

# 4. Alur submit tahan kegagalan

1. Petugas membuka tugas/temuan, pilih petugas aktual dan kode dropdown, isi foto BEFORE+AFTER sesuai aturan sementara, GPS dan waktu otomatis.
2. Validasi minimum lokal berdasarkan **versi kode form** yang tersimpan. Data temuan PASILA ditulis terpisah per item, masing-masing GPS/foto.
3. SQLite transaction menyimpan payload frozen, client_request_id (UUID), dependency links, captured_at, state QUEUED, foto terikat di private application storage; commit dahulu.
4. UI memberi feedback Tersimpan; worker mencoba upload jika koneksi memungkinkan.
5. Server memproses idempotency key; transaksikan record pekerjaan, entitas hasil, metadata file, dan respons canonical ID. File upload besar harus memakai tahap aman/manifest dan checksum agar resume/retry tidak membuat media ganda.
6. Perangkat menerima ACK dan menandai sukses; bila respons hilang, retry key sama sehingga server tidak membuat submission baru.
7. Jika error: network/5xx → retry exponential backoff; 401/403 → reauth/izin; 409 → conflict workflow; 422 → butuh koreksi input. Foto dan payload **tidak boleh dihapus** selama belum berhasil atau selesai penanganan.
8. Tersimpan setelah restart/crash dan ada halaman Antrean Sinkronisasi + item Kirim Ulang tanpa isi ulang.

# 5. Android background constraints

Gunakan mekanisme yang sesuai seperti **WorkManager** dengan network constraint, durable queue, dan callback saat aplikasi aktif/connectivity berubah. Implementasi harus menerima realitas Android Doze/battery optimizations/force-stop: **tidak mungkin menjamin pengiriman langsung pada detik jaringan pulih ketika app ditutup/paksa berhenti**. Target: mencoba otomatis pada kesempatan OS mengizinkan dan menampilkan status yang jujur. Untuk item ACTION_REQUIRED, notifikasi lokal ketika aplikasi dapat menjalankan task; bukan push yang pasti muncul saat app force-stopped.

Rancang test perangkat nyata saat jaringan OFF→ON, task background, reboot, battery saver, ganti perangkat, foto besar.

# 6. Conflict & ordering

- Server authoritative. Kode/approval/shift/WO status dari server dapat berubah saat HP offline.
- Semua write memakai **expected_version**/ETag atau per-domain guard; jika berubah, jangan overwrite senyap.
- Work finding baru mendahului hasil eskalasi/referensi yang membutuhkan ID server; dependency worker harus patuh urutan.
- Gunakan occurred_at dari pengambilan di lapangan dan received_at server terpisah.
- Koreksi histori **ADMIN Web saja**; tim lapangan tidak dapat menghapus laporan canonical.
- Tidak ada PowerSync / full bidirectional replication.

# 7. Offline per role

**POSKO:** cached APKUK WO, PASILA shift, P0 yang sudah ACC, reschedule reason+manual note, temuan terpisah; setiap submit tercatat outbox. PASILA dibentuk server awal shift, sehingga tugas yang belum sempat didownload tidak dapat dibuat-buat lokal tanpa kontrak khusus.

**HARSUS:** cached WO PEKERJAAN_KHUSUS dan target aset. Tidak ada P0 HARSUS.

**INSPEKSI:** daftar area prioritas cached, no-finding reports dan temuan dengan foto/GPS, validasi Admin terjadi setelah server terima.

**ROW:** daftar WO auto/manual dan route stops cached; laporan dan foto per WO.

# 8. Authentication, pemberitahuan, dan retensi

Satu akun per tim (enam Posko + tiga tim ROW/HARSUS/INSPEKSI); dropdown nama petugas dari anggota tim. Kebijakan dua device dan logout setiap empat jam **TBD**: jangan membuang local outbox hanya karena sesi berakhir atau token expired. Setelah autentikasi pulih lanjut kirim dengan key sama.

Tampilkan notifikasi tugas baru/ACC jika perangkat online dan akun login; kegagalan sync ACTION_REQUIRED harus diberi petunjuk membuka halaman outbox. Notifikasi saat OS menahan worker mungkin terlambat.

File lokal sukses hanya dibersihkan setelah ACK server dan kebijakan retensi yang aman; pekerjaan gagal tidak dihapus oleh cleanup.

# 9. Pengujian wajib

- Offline submit, close app, koneksi kembali → antrean berusaha kirim tanpa buka ulang form; catat limit OS.
- Server menerima dua retry dengan key sama → hanya satu transaksi.
- Foto besar upload gagal parsial lalu retry → foto tetap utuh dan tidak ganda.
- Offline shift berubah → konflik diblokir atau ditangani transparan.
- Crash sebelum/selama submit → SQLite konsisten.
- Invalid session setelah masa lama → outbox tetap ada; reauth lalu retry.
- Dua perangkat akun tim submit tugas sama → server mendeteksi duplikat/konflik.
- PASILA multiple findings masing-masing foto/GPS tetap terhubung benar.
