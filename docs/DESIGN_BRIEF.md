# DESIGN BRIEF
## Sistem Pengelolaan Pekerjaan Jaringan Distribusi — ULP Sribhawono

**Version:** v3.0 — 8 Oktober 2026  
**Status:** Arahan desain UI/UX; bukan klaim fitur sudah dibangun  
**Acuan:** PRD.md v3.0 dan DATA_MODEL.md v3.0

# 1. Prinsip pengalaman

1. **Pekerjaan harus langsung terlihat.** Mobile memprioritaskan CTA berikutnya, bukan dashboard rumit.
2. **Admin Web dan Mobile bukan pengguna yang sama.** ADMIN saja masuk Web; POSKO, HARSUS, INSPEKSI, ROW memakai Flutter Android.
3. **Minimum ketikan lapangan.** Auto waktu/tim/konteks/GPS; pilihan master menggunakan dropdown; catatan umum opsional, pengecualian reschedule dan koreksi/penolakan beralasan jika diatur.
4. **Kirim artinya sudah disimpan lokal.** Bedakan Tersimpan di Perangkat, Menunggu Sinkronisasi, Terkirim ke Server, dan Perlu Kirim Ulang.
5. **Hindari klaim palsu.** Data belum ada tampil sebagai empty state; jangan mengisi UI dengan laporan/koordinat/foto demo yang dapat disangka data aktual.
6. **Peta bekerja setelah filter.** Admin memilih satu Penyulang lalu layer/bbox dimuat.

# 2. Inventaris Web Admin

- **Dashboard:** KPI per role, status enam Posko per shift, enam WO APKUK/Posko/shift, PASILA dan P0 POSKO, WO khusus HARSUS, WO ROW, temuan INSPEKSI, antrean ACC/Tolak.
- **P0 Monitor & Detail:** rekomendasi SYSTEM menunggu ACC, manual Admin, terikat PASILA/Section/Posko, riwayat, alasan penolakan bila diperkenalkan, pengganti wajib setelah reject SYSTEM.
- **Work Order Monitor & Detail:** satu modul filter team_type (POSKO, HARSUS, ROW), work_type, status, waktu, Penyulang, Section, rute dan keterkaitan child/origin.
- **PASILA Monitor & Detail:** tampilan per shift/Posko/Section, dibuat otomatis awal shift, temuan dalam daftar kartu per titik, P0 terkait, eskalasi, tindakan dan pekerjaan tertunda.
- **Inspeksi:** area rekomendasi/rotasi, hasil tanpa temuan, temuan yang menunggu validasi, rekomendasi tim tujuan sistem dan ACC/Tolak.
- **ROW:** WO harian otomatis dan manual, paket/rute berdasarkan kedekatan, status eksekusi, histori vegetasi.
- **HARSUS:** pekerjaan khusus langsung dari Admin dan limpahan yang telah ACC, aset target dan kebutuhan teknis.
- **Approval Center:** usulan P0 SYSTEM, temuan inspeksi, semua eskalasi lintas tim, informasi dampak dan asal pekerjaan.
- **Maps Leaflet:** pilih Penyulang wajib, layer checkbox, search 16 Penyulang, detail aset/titik gangguan, histori/foto dan action cepat Admin.
- **Master & Audit:** master petugas/tim/role/shift, kode pilihan (diatur lewat DB/deployment), histori koreksi, sumber data/import.

Menu atau tombol menuju fitur yang belum implementasi harus ditandai belum tersedia atau diarahkan ke empty state yang jujur, bukan halaman palsu.

# 3. Alur Maps ADMIN

1. Admin buka Maps; tampil pemilih Penyulang wajib (16 opsi), checkbox layer default sesuai kebutuhan, tanpa memuat seluruh aset ULP.
2. Setelah pilih Penyulang, API mengambil layer aktif untuk Penyulang dan viewport; marker bisa di-cluster/dimuat bertahap.
3. Kotak pencarian **global** menampilkan hasil nama/ID/koordinat dari seluruh Penyulang dan konteks nama Penyulang.
4. Ketika hasil di Penyulang berbeda dipilih: ubah dropdown → tunggu layer dan marker terkait dimuat → zoom/pusatkan/seleksi → buka sidebar.
5. Klik marker aset membuka sidebar bertab **Informasi | Histori | Foto**. Header berisi foto terbaru, ID aset, jenis, status, Penyulang/Section/Posko, koordinat.
6. Histori menggabungkan kejadian pekerjaan, maintenance, APKUK, gangguan terkait dan koreksi tanpa menghilangkan versi sebelumnya.
7. Klik marker **gangguan** menampilkan kejadian pada koordinat kejadian dan daftar aset terdampak; mendukung lebih dari satu aset.
8. Action cepat **Buat P0 / WO / Pekerjaan Khusus / PASILA** hanya untuk Admin; prefill aset dan lokasi. Buat P0 mensyaratkan PASILA relevan.
9. GIS jaringan berupa jalur/polyline menjadi layer opsional, menunggu data otoritatif.

**Perhatian kinerja:** dropdown hanya satu Penyulang aktif, query server filtered, viewport paging, marker clustering, cache dan permintaan dibatalkan saat Penyulang diganti.

# 4. Mobile shared flow

1. Login menggunakan akun tim; pilih petugas aktual saat melaporkan tindakan. Aturan jumlah device dan logout berkala masih TBD.
2. Beranda: tugas paling relevan, jumlah menunggu sinkronisasi, notifikasi ketika akun login, shortcut riwayat.
3. Detail tugas: identitas/kode, jenis, prioritas, aset/Section/Penyulang, koordinat, status, rute, **Buka Google Maps**.
4. Mulai pekerjaan → form kontekstual: tanggal dan waktu perangkat otomatis, tim/shift/Penyulang/Section/aset terisi, GPS otomatis, dropdown petugas dan kode temuan/tindakan, foto wajib, catatan opsional.
5. Jika submit: tulis transaction + foto + outbox **secara atomik lokal** → UI memberi status Tersimpan / Menunggu Sinkronisasi → kirim otomatis saat diperbolehkan konektivitas/Android.
6. Jika pengiriman perlu tindakan: notifikasi lokal, item masih utuh, **Kirim Ulang** tanpa input kembali. Server ACK baru boleh mengubah status menjadi Terkirim.

Android background delivery adalah *best effort* di bawah kebijakan OS, bukan jaminan langsung seketika saat sinyal pulih dan aplikasi dipaksa berhenti.

# 5. Layar per role mobile

## POSKO
**Home Shift A/B/C:** enam WO APKUK dan urutan rute; PASILA yang **sudah dibuat otomatis pada awal shift**; P0 yang terhubung PASILA dan status ACC Admin. Tampilan WO sisa mengharuskan reschedule alasan dropdown + catatan, tetapi **tidak mengunci PASILA+P0**. P0 SYSTEM reject → flag Admin wajib mengganti manual.

**PASILA Detail:** Section, ranking/rekomendasi, peta/koordinat, daftar temuan per titik. Satu temuan = satu detail (jenis/kode, aset/lokasi, GPS, foto, petugas, waktu, catatan). Tindakan/escalation tidak mengganti data temuan lain.

## HARSUS
Beranda **WO Pekerjaan Khusus**, bukan daftar P0. Tiap WO mengarah ke aset jaringan, kebutuhan pemadaman/alat/keahlian jika dicatat, referensi sumber limpahan bila ada.

## INSPEKSI
Urutan area prioritas dengan rotasi, form hasil kunjungan **Tidak Ada Temuan**, tombol Lanjut Area Berikutnya, daftar temuan per titik, status Menunggu Validasi Admin. Target 1 temuan nyata/hari ditampilkan sebagai target, bukan dorongan membuat data fiktif.

## ROW
Daftar paket/rute harian berisi banyak WO berdekatan, filter RABAS/PANGKAS/TEBANG, navigasi Google Maps per titik, bukti pengerjaan sebelum/sesudah.

# 6. Foto, validasi dan kesalahan

**Default sementara:** foto BEFORE + AFTER untuk pekerjaan. Template berbeda per work_type akan disepakati kemudian; tiap temuan punya foto/GPS sendiri. Simpan capture timestamp dan lokasi perangkat; beri petunjuk bila GPS tidak tersedia.

Dropdown dari referensi database (kode temuan/pekerjaan, petugas aktif, status/kondisi, alasan reschedule); jangan hardcode ke Dart. Catatan reschedule **wajib** selain alasan terstruktur; catatan biasa opsional.

Koreksi histori adalah halaman ADMIN dengan alasan wajib, preview sebelum-sesudah dan jejak audit. Jangan pernah menyediakan tombol “hapus permanen” untuk histori.

# 7. Status implementasi UI saat rebaseline

Branch feat/admin-work-orders-web berisi **Dashboard Admin, P0 Monitor+Detail, WO Monitor+Detail**. Beberapa teks masih salah (P0-HARSUS), data transaksi masih empty state, sejumlah tombol nonaktif/tautan placeholder. Refactor dan sambungkan ke model asli secara bertahap; jangan membuang UI yang sudah dibuat. Flutter di repo masih skeleton/demo.

# 8. TBD visual dan kerja lanjut

Detail formulir P0 dan klasifikasi kode dari atasan, foto khusus per pekerjaan, batas sesi perangkat, gaya akhir design system dan prioritas penambahan halaman belum sepenuhnya final. Gunakan komponen reuse (header, menu, filter, detail drawer, status chip, photo uploader, sync status, route card, audit timeline).
