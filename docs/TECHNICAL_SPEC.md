# TECHNICAL SPECIFICATION
## Sistem Pengelolaan Pekerjaan Jaringan Distribusi — ULP Sribhawono

**Version:** v3.0 — 8 Oktober 2026  
**Status:** Target arsitektur dan perilaku; bukan klaim semua komponen tersedia  
**Kontrak:** PRD.md, DATA_MODEL.md, API_CONTRACT.md, OFFLINE_SYNC_SPEC.md

# 1. Arsitektur tetap

- **Flutter Android:** hanya POSKO, HARSUS, INSPEKSI, ROW.
- **Laravel + Blade/CSS/JS Web:** hanya ADMIN; API REST, authorization, scheduler, queue, validasi, audit, import. Refactor halaman yang sudah ada.
- **PostgreSQL:** authoritative server database untuk master dan transaksi canonical.
- **SQLite + file storage Android:** cache kerja ringan, snapshot dropdown/penugasan, immutable local submissions, outbox; **bukan full replication**.
- **Laravel Filesystem:** file foto lokal saat dev, object storage S3-compatible saat prod; PostgreSQL menyimpan metadata/path/hash.
- **Maps ADMIN:** Leaflet + OSM tiles untuk peta dan layer. **Google Maps eksternal Android** untuk navigasi petugas melalui koordinat; bukan Leaflet untuk seluruh mobile.
- **Scheduler/Queue:** shift generation, prioritization, ROW WO, upload processing, notification retries, cleanup terkendali.
- **No legacy Next.js/Supabase/PowerSync**, tidak wajib websocket/realtime. Polling atau refresh berskala wajar.
- **ML (keputusan 28 Okt 2026):** model prioritas/pemilihan WO otomatis wajib tervalidasi dan terintegrasi pada generator sebelum go-live. Aturan keras (pending APKUK, WO aktif ganda, kelayakan) tetap di luar model, dan rule-based menjadi fallback. Lihat `OPERATIONAL_SPEC_GAP_AUDIT.md` §10.

# 2. Status kode yang terverifikasi melalui GitHub

Baseline aktif **`main` v3**. Sebelumnya UI berasal dari branch feat/admin-work-orders-web @ 6d8ec22c; riwayat tersebut kini tercakup di `main`.
- Implemented file/migration: 6 staging source tables; poskos, penyulangs, posko_penyulang; importer, profiling, audit, topology sync.
- Admin Web scaffold: Dashboard, P0 Monitor/Detail, WO Monitor/Detail; service transaksi masih empty state, beberapa tampilan menyebut P0 HARSUS dan demo.
- Belum ditemukan domain canonical untuk WO/PASILA/P0/Inspeksi/ROW; belum ada auth-role lengkap maupun API mobile. route Admin yang ada belum auth/ADMIN-gated.
- Flutter mobile/lib/main.dart masih Flutter Demo. **Tidak boleh mengklaim fitur mobile selesai.**
- Angka 73 tes/274 assertion adalah **hasil historis yang dilaporkan**, bukan tes ulang branch baru di runtime.

# 3. Domain, source dan identity

Pertahankan staging sebagai snapshot data mentah: apkuk_source_rows, asset_source_rows, vegetasi_source_rows, gangguan_source_rows, section_pic_source_rows, keypoint_source_rows. Pertahankan core Posko/Penyulang/pivot. Tambahkan canonical masters secara migration bertahap, jangan ubah migration lama yang sudah applied.

Satu penyulang dapat terkait beberapa Posko; jangan menurunkan kepemilikan aset dari pivot secara langsung. **Section/cabang/asset harus mendapat relasi eksplisit yang tervalidasi.** KEYPOINT sudah memuat data FCO/switching menurut informasi stakeholder, tetapi identitas dan kelengkapannya tetap diaudit. Jika koordinat/relasi hilang, tandai status verifikasi; **jangan mengisi lokasi palsu**.

Setiap aset canonical punya ID stabil, jenis, kode, parent Section, Penyulang, Posko tanggung jawab (jika sudah disahkan), koordinat saat tersedia, current status, atribut tipe-specific. Gangguan adalah kejadian; satu event dapat memengaruhi banyak aset, sehingga relasi ke aset menggunakan mapping junction bila diperlukan.

# 4. Security & identity

Hanya role: ADMIN / POSKO / HARSUS / INSPEKSI / ROW. Account = tim login; petugas = manusia aktual yang dipilih dari anggota aktif. 6 akun Posko (per Posko), masing-masing 1 akun ROW/HARSUS/INSPEKSI, akun ADMIN Web terpisah. Shift assignment adalah data periodik karena tim dapat berpindah Shift A/B/C, personelnya tetap.

- Route /admin/* harus memakai autentikasi server dan policy/middleware role ADMIN; jangan publikasikan dashboard tanpa guard.
- Endpoint mobile memakai token/sesi yang aman dan role scope; tidak boleh memakai ADMIN Web.
- Penyulang/Section/Posko scope ditegakkan di server, bukan hanya filter UI.
- Hard device cap 2 dan auto-logout 4 jam **TBD**, belum boleh di-hardcode.
- Audit perubahan Admin, ACC/reject, koreksi, escalation dan sinkronisasi; sanitasi upload, rate limiting, input validation dan retensi sesuai kebijakan unit.

# 5. Shift generation & business services

Shift A 08:00–16:00, B 16:00–00:00, C 00:00–08:00 **FINAL (dikonfirmasi 28 Okt 2026)**; tiga shift berurutan 24 jam tanpa jeda. Gunakan Asia/Jakarta dan identitas shift-instance (tanggal kerja + kode + Posko) agar aman pada pergantian tengah malam.

**Shift scheduler (idempotent):** saat awal tiap shift per Posko ciptakan PASILA dengan rekomendasi Section dan enam tugas WO APKUK sistem yang didukung data, tanpa membuat duplikasi pada rerun; Admin bisa membuat manual. PASILA tidak menunggu enam WO beres untuk dibuat. Route WO berdasarkan titik/Section dan jarak; kecocokan GPS dan rute jalan perlu dibedakan.

**WO APKUK:** enam WO adalah target per shift/Posko, bukan jumlah unit APKUK; result mencatat physical_apkuk_quantity. Sisa WO boleh direschedule dengan target dipilih petugas + alasan dropdown + catatan wajib; POSKO boleh lanjut PASILA+P0. Algoritma kuota carry-over shift berikutnya TBD.

**PASILA/P0:** temuan PASILA per item berisi koordinat/foto/asset-link. P0 hanya POSKO dan selalu merujuk PASILA/Section/Posko yang sama; SYSTEM proposal perlu Admin ACC; jika Admin reject, sistem menandai kebutuhan manual replacement dalam PASILA; Admin P0 dibuat manual dengan relasi inherited. Aturan minimum 1 P0/shift dinilai dari realisasi sesuai definisi final.

**INSPEKSI:** rekomendasi Section berdasarkan risiko dan rotasi. Laporan no-finding tetap disimpan dan dorong next area; actual finding target >=1/hari. Admin review; sistem menyarankan tim tujuan dengan kode klasifikasi, ACC mengaktifkan downstream action/WO bila sesuai.

**ROW:** penilaian vegetasi periodik membuat WO RABAS/PANGKAS/TEBANG **aktif segera tanpa ACC**, dikelompokkan route/cluster berdekatan dan area bergilir. Bisa banyak WO sehari. Generator wajib idempotent; kebijakan beberapa WO di titik sama dan risk scoring TBD.

**HARSUS:** WO PEKERJAAN_KHUSUS dari Admin (referensi aset wajib) atau child escalation setelah ACC; bukan executor P0.

**Escalation:** request + approval Admin + transaction membuat child WO dan status sumber SELESAI_DITERUSKAN secara atomik, semua immutable link/status log terjaga. Cuaca hanya reschedule/pending.

**Special Case:** tidak ada konstanta business rule >3 trip/bulan; rancang pipeline frekuensi gangguan yang parameterisasi/formulanya TBD, tanpa otomatis menghasilkan P0 di luar PASILA.

# 6. Maps Web architecture

Server endpoint memilih penyulang wajib dan jenis layer serta bbox/zoom/page. Jangan mengembalikan 17k+ aset seluruh ULP setiap request. Cache/cluster sesuai zoom; dukung jenis TIANG, GARDU, FCO, LBSM, REC, APKUK, VEGETASI dan event GANGGUAN. Layer gangguan merender koordinat kejadian dan mereferensikan aset terdampak.

Search endpoint adalah **global lintas 16 penyulang** meskipun map menampilkan satu penyulang. Hasil harus mengandung asset ID, penyulang ID, koordinat, label. UI **switch dropdown → load markers → zoom → drawer**.

Drawer meminta detail canonical asset + latest photo berdasarkan capture/verification time + asset history events dan galeri lama. Admin quick-create actions harus melakukan validasi server; P0 tetap harus mendapat PASILA yang kompatibel. GeoJSON/KML/polyline feeder opsional bila tersedia.

# 7. File evidence & audit

Media punya type BEFORE/AFTER/FINDING/SURVEY bila dibutuhkan, captured_at, location & accuracy, content hash, upload status, source work/finding, storage key. Pada hasil kerja default provisional butuh BEFORE + AFTER. Setiap temuan PASILA punya foto/GPS sendiri. Jangan mengganti path foto lama ketika foto baru datang; gunakan latest valid photo sebagai tampilan.

Asset history append-only event + audit_logs untuk koreksi, dengan actor, old/new, changed_at dan reason wajib. Timestamp **occurred_at** (kejadian lapangan) dan **received_at** (diterima server) terpisah, penting untuk offline.

# 8. Offline architecture

Submit perangkat selalu membuat **local durable submission** plus referenced photo files lebih dulu, lalu outbox otomatis mencoba upload jika ada jaringan. Retry/backoff, OS background scheduling dan network constraints diperlukan; Android tidak menjamin real-time ketika force-stop/dioptimasi baterai. Status: QUEUED, SENDING, ACKNOWLEDGED, RETRYABLE_ERROR, ACTION_REQUIRED. Server idempotency key unik dan cek duplikasi agar retry tak menggandakan pekerjaan. Jika gagal butuh tindakan, notify perangkat + tombol manual resend tanpa re-entry.

# 9. Testing & deployment

Tulis test per rule: duplicate shift job, P0 scoped PASILA, reject SYSTEM menuntut replacement, WO reschedule tidak mengunci PASILA, ROW auto active & idempotent, escalation atomic, history append only, search global feeder switch, auth Admin/role scoping, offline repeated submit. Migration baru dibuktikan pada **database testing** lebih dulu, snapshot/backup untuk database nyata. Simpan README setup dan test evidence dalam PROJECT_STATUS, jangan klaim tes lulus sebelum dieksekusi.

# 10. Data gaps / non-blocking work

**Butuh sumber/validasi:** Section ownership, asset coordinates/ID, photo historis, maintenance, gangguan affected asset, GIS route, master petugas, kode finding/action, frekuensi threshold. **Bisa jalan sekarang:** dokumen, auth/RBAC design, migrasi master independen, workflow schema, test domain tanpa data nyata, UI Web dengan empty states, peta dengan API contract dan test fixture **secara jelas berlabel fixture (tidak untuk operasional)**.

Catat data provenance, confidence dan missing-field audit; jangan menjadikan staging raw sebagai master operasional hanya untuk mengisi UI.
