# PRODUCT REQUIREMENTS DOCUMENT
## Sistem Pengelolaan Pekerjaan Jaringan Distribusi — ULP Sribhawono

**Version:** v3.0 — 8 Oktober 2026  
**Status:** Baseline keputusan operasional terbaru; detail bertanda TBD belum final  
**Scope:** Single-tenant ULP Sribhawono, Admin Web + aplikasi Flutter Android  
**Referensi:** DESIGN_BRIEF.md, TECHNICAL_SPEC.md, DATA_MODEL.md, API_CONTRACT.md, OFFLINE_SYNC_SPEC.md, IMPLEMENTATION_PLAN.md, PROJECT_STATUS.md

> Dokumen ini menggantikan business flow v2 di histori Git. Keputusan terbaru pengguna 8 Oktober 2026 mengungguli spesifikasi lama. Jangan menghidupkan kembali P0 oleh HARSUS, batas tetap >3 trip, atau pengiriman offline manual.

# 1. Tujuan produk

Mengintegrasikan data aset, gangguan, APKUK, vegetasi, inspeksi, perencanaan otomatis, penugasan, pelaksanaan, foto, GPS, validasi Admin, dan histori aset. Sistem harus membantu tim menyelesaikan pekerjaan nyata, bukan hanya menampilkan dashboard.

**Pengguna dan platform:**
- **ADMIN:** satu-satunya role yang mengakses Web Admin; monitoring, approval, koreksi, master, Maps, seluruh pekerjaan manual.
- **POSKO:** Android, satu akun tim untuk masing-masing enam Posko; melaksanakan WO APKUK, PASILA, dan P0 yang terhubung.
- **HARSUS:** Android, satu akun tim HARSUS; WO PEKERJAAN_KHUSUS dari Admin atau limpahan yang disetujui.
- **INSPEKSI:** Android, satu akun tim INSPEKSI; mencari, mencatat, dan mengirim temuan untuk validasi.
- **ROW:** Android, satu akun tim ROW; WO RABAS/PANGKAS/TEBANG otomatis atau manual.

Akun login berbeda dari orang: setiap submission memilih satu atau beberapa **petugas aktual** dari master petugas tim terkait. Anggota satu tim Posko tetap, penjadwalan Shift A/B/C dapat berubah. Kebijakan maksimal dua perangkat login dan logout tiap empat jam masih **TBD / perlu persetujuan atasan**, bukan aturan final.

# 2. Operasi POSKO per shift

1. Sistem membuat PASILA otomatis **pada awal setiap Shift A, B, C**, khusus tiap Posko. Section rekomendasi diperoleh dari prioritas gangguan, kondisi aset, histori pekerjaan/kunjungan, dan rotasi; bobot tepat masih TBD.
2. Sistem memberi **target enam WO PEMASANGAN_APKUK per Posko per shift** secara otomatis, dengan lokasi dan urutan rute efisien. Admin boleh menambah WO manual. Enam WO berarti enam *pekerjaan*, bukan enam buah fisik APKUK; hasil wajib mencatat **jumlah APKUK yang dipasang**.
3. Petugas memprioritaskan WO APKUK, kemudian mengerjakan **PASILA beserta minimal satu P0 yang berada pada Section PASILA yang sama**.
4. Jika belum semua enam WO selesai, POSKO **tetap boleh melanjutkan PASILA + P0**, asalkan WO tersisa dijadwalkan ulang melalui form wajib **alasan dropdown + catatan manual** dan tanggal/jadwal tujuan dipilih petugas.
5. Semua tindakan dan temuan PASILA dicatat per item; setiap temuan punya jenis/kode, aset atau koordinat, foto, GPS, waktu, dan petugas sendiri. Catatan bebas opsional kecuali pada kejadian yang membutuhkan alasan tertentu.
6. POSKO minimal mempunyai satu P0 untuk shift-nya. P0 selalu merujuk PASILA yang sama, dengan Section dan Posko diwariskan/terkunci.

**P0 sumber SYSTEM:** sistem mengusulkan aset pada PASILA; Admin wajib ACC sebelum P0 aktif. Bila ditolak, Admin **wajib membuat P0 manual pengganti** dalam PASILA tersebut.  
**P0 sumber ADMIN:** Admin dapat membuat P0 langsung dari PASILA; Section/Posko mengikuti PASILA dan tidak boleh disilangkan. Skema detail P0 masih perlu konfirmasi atasan.

Urutan bisnis enam WO lebih dulu tidak berarti layar PASILA baru dibuat setelah WO selesai: PASILA **sudah dibuat pada awal shift**. Keputusan tentang carry-over mengurangi kuota generator shift berikutnya masih **TBD**; jangan asumsi otomatis.

# 3. Tim HARSUS

HARSUS menerima WO generik berjenis **PEKERJAAN_KHUSUS**, baik dari:
- **WO manual Admin** yang menunjuk **aset jaringan tertentu**, atau
- **eskalasi lintas tim** (POSKO/ROW/dll) setelah ACC Admin.

Kebutuhan pemadaman, peralatan khusus, atau kompetensi teknis dapat memicu eskalasi. **Hujan/cuaca buruk atau akses sementara terhalang bukan alasan otomatis mengalihkan ke HARSUS**; gunakan pending/reschedule. HARSUS bukan pelaksana P0.

# 4. Tim INSPEKSI

Sistem mengarahkan area/Section berdasarkan skor prioritas dan rotasi agar area berisiko tidak terlewat dan area yang sama tidak terus diulang. Tim ditargetkan **minimal satu temuan nyata per hari**, dari jenis apa pun. Hasil kunjungan **Tidak Ada Temuan** harus boleh dicatat secara jujur dan tidak dianggap temuan fiktif; sistem mengarahkan ke area ranking berikutnya.

Setiap temuan punya detail, lokasi/koordinat, foto, petugas, waktu, jenis, dan aset jika diketahui. Temuan INSPEKSI masuk antrean **validasi Admin** sebelum memutakhirkan master aset/menjadi sumber tindakan. Sistem merekomendasikan tim penerima berdasarkan klasifikasi; **Admin ACC/Tolak**. Algoritma rekomendasi dan klasifikasi detail masih TBD.

# 5. Tim ROW

Sistem memeriksa data risiko/siklus pertumbuhan vegetasi, lalu membuat **WO ROW otomatis dan langsung aktif tanpa persetujuan Admin**. Jenis WO: RABAS, PANGKAS, TEBANG. Bisa ada banyak WO per hari. Sistem mengelompokkan titik yang dekat dalam **paket/rute harian**, bergilir menurut Section/area. Admin juga boleh membuat ROW manual. Aturan persis periode/risiko dan penanganan duplikasi yang sama masih TBD; jangan membuat tugas ganda tanpa kendali.

# 6. WO generik, limpahan, dan reschedule

WO generik menyimpan **team_type, work_type, sumber, aset/vegetasi, Section, Penyulang, Posko bila relevan, rute, jadwal, status, petugas, hasil, foto, GPS**.
- POSKO: PEMASANGAN_APKUK.
- HARSUS: PEKERJAAN_KHUSUS.
- ROW: RABAS / PANGKAS / TEBANG.

Admin boleh membuat **WO, PASILA, P0, pekerjaan khusus, dan ROW** secara manual. Pembuatan manual tetap tunduk pada konsistensi lokasi/relasi aset.

Eskalasi lintas tim: sumber tidak dihapus atau dipindah; buat **request escalation**. Setelah Admin ACC, sumber berstatus **SELESAI_DITERUSKAN**, dibuat **child WO** pada tim tujuan, dan tautan ke pekerjaan asal tersimpan. Jika ditolak, tidak dibuat child WO; status penolakan dan audit tersimpan.

Form reschedule dibuat oleh petugas, alasan dropdown + catatan manual **wajib**, serta waktu/jadwal baru pilihan petugas. Kebijakan approval reschedule per jenis pekerjaan masih TBD; jangan mengunci kewajiban ACC tanpa keputusan.

# 7. Form mobile, bukti dan offline

Form harus sesingkat mungkin: tanggal/jam, shift, tim, Penyulang, Section, dan data WO diisi otomatis dari konteks; GPS otomatis perangkat; pilih petugas, jenis pekerjaan/temuan, kode dan kondisi dengan dropdown dari database; catatan umum opsional. Validasi harus menunjukkan error yang mudah dipahami.

**Aturan foto sementara:** foto BEFORE + AFTER wajib untuk penyelesaian pekerjaan; aturan khusus per jenis pekerjaan akan diperinci kemudian. Temuan PASILA/INSPEKSI mempunyai bukti foto dan GPS per temuan. Foto tidak boleh hilang atau tertimpa.

Tombol submit harus langsung menyimpan data tahan-restart ke SQLite/outbox bila jaringan lemah/hilang, lalu mengirim otomatis saat konektivitas dan Android mengizinkan pekerjaan latar. Petugas tidak perlu mengetik ulang. Jika gagal permanen/perlu tindakan, tampilkan notifikasi perangkat dan tombol Kirim Ulang dari payload/foto yang tetap tersimpan. Pengiriman otomatis bersifat **best effort sesuai pembatasan Android**; jangan menjanjikan detik pengiriman pasti saat aplikasi ditutup.

Lokasi WO pada Android mempunyai CTA **Buka Google Maps** yang mengirim koordinat ke aplikasi navigasi. Mobile tidak memerlukan antarmuka Maps Leaflet seluruh ULP.

# 8. Maps dan histori untuk ADMIN Web

- Gunakan **Leaflet/OpenStreetMap** di Web Admin. Admin **wajib memilih 1 dari 16 Penyulang** sebelum memuat layer.
- Checkbox layer antara lain TIANG, GARDU, FCO, LBS/LBSM, RECLOSER, VEGETASI, GANGGUAN, APKUK; dapat ditambah kemudian.
- **Search global mencakup 16 Penyulang**. Saat Admin memilih hasil pencarian dari Penyulang lain, dropdown mengganti ke Penyulang tujuan, layer baru dimuat, lalu kamera peta zoom ke aset terpilih.
- Layer GANGGUAN menunjukkan **titik kejadian gangguan**, dan klik menampilkan kejadian beserta **aset-aset terdampak** yang tersedia.
- Klik aset memunculkan sidebar: identitas, tipe/spesifikasi, status, Penyulang, Section, Posko, koordinat, foto terbaru, riwayat kegiatan, dan galeri semua foto lama.
- Foto utama adalah **foto aset terbaru yang valid menurut waktu dokumentasi**; foto lama tetap tersimpan. Kegiatan pemeliharaan/pergantian/apkuk/temuan mengisi histori aset setelah validasi/pelaksanaan sesuai jenisnya.
- Sidebar aset mendukung **aksi cepat Admin membuat P0/WO/pekerjaan khusus/PASILA** dengan aset terisi otomatis; syarat P0 tetap harus terkait PASILA.
- Jalur GIS/polyline Penyulang opsional saat data GIS tersedia; tidak menghalangi marker MVP. API harus memakai filter Penyulang, layer, viewport/bbox/paging/cluster untuk menghindari overload.

# 9. Histori, keamanan, dan rekomendasi

PostgreSQL adalah source of truth server. Semua koreksi data server hanya oleh ADMIN; **original event, nilai sebelum/sesudah, actor, waktu, alasan koreksi wajib dicatat**. Jangan hapus catatan lampau. Form laporan tim tidak otomatis mengedit riwayat lama.

Sistem menggunakan ranking/rule-based yang dapat dijelaskan dan dituning; model ML bukan prasyarat. **Special Case tidak lagi menggunakan rule keras lebih dari tiga trip per bulan**; perlu metode membaca **frekuensi gangguan** dengan periode, normalisasi, ambang, dan tindak lanjut **TBD**.

# 10. Batasan data dan penerimaan

Enam staging sumber telah ada dalam repo bersama Posko/Penyulang/pivot. **Section final dan asset canonical masih perlu penyelesaian identitas/relasi**. Data KEYPOINT telah diberikan dan mencakup FCO/switching menurut penjelasan stakeholder, tetapi harus diaudit; jangan tandai lagi sebagai “menunggu file FCO terpisah”. Sumber pusat untuk koordinat, histori, foto dan GIS belum tentu lengkap.

**Acceptance minimum:**
- Tidak ada P0 HARSUS, P0 terikat PASILA POSKO, dan tiap shift dapat memenuhi target/tindakan reschedule sesuai aturan.
- WO multi-tim, ROW auto aktif, Admin mampu input manual dan menyetujui escalation/temuan.
- Mobile menyimpan submit beserta foto/GPS aman ketika offline dan mengirim ulang otomatis, dengan pemulihan kegagalan.
- Maps global search beralih Penyulang dan menampilkan histori/foto terkini tanpa menghapus foto lama.
- Hak akses Web hanya ADMIN dan Android hanya empat tim.

**Terbuka/TBD:** kode dan pilihan dropdown, formulir P0 detail, formula prioritas, jam/penugasan shift jika berubah dari jadwal saat ini, aturan approval reschedule, perilaku duplicate ROW, login device limit, SLA sinkronisasi, ketersediaan GIS dan relasi histori pusat.
