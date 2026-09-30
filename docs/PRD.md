# PRODUCT REQUIREMENTS DOCUMENT

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Final / Locked Baseline (Refreshed per 30 September 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Scope:** ULP Sribhawono (Single-Tenant)
**Platform:** Android App (Flutter) + Web Admin & Backend API (Laravel + PostgreSQL)
**Pengguna utama:** ADMIN, POSKO, HARSUS, ROW
**Fokus:** Pengelolaan pekerjaan preventive jaringan distribusi secara end-to-end

---

# 1. Problem Statement

Kegiatan preventive jaringan distribusi melibatkan banyak titik aset, Posko, Section, histori gangguan, inspeksi, vegetasi, dokumentasi, dan tindak lanjut. Sistem dibutuhkan agar alur kondisi/temuan → prioritas → penugasan → eksekusi → dokumentasi → tindak lanjut → histori → evaluasi berada dalam satu alur yang dapat dipantau.

Kondisi lapangan juga dapat memiliki jaringan internet yang tidak stabil. Karena itu, aplikasi mobile harus tetap memungkinkan petugas menyimpan input dan foto sebagai draft lokal ketika offline, lalu mengirimkannya saat koneksi tersedia.

---

# 2. Target User + Persona

## 2.1 ADMIN

ADMIN menggunakan Web Admin untuk:
- memonitor seluruh pekerjaan;
- membuat P0 dan WO manual;
- mengelola seluruh master data;
- memvalidasi titik aset/vegetasi baru;
- menyetujui atau menolak reschedule P0 khusus;
- melakukan koreksi data yang sudah masuk server;
- melihat audit trail dan histori.

## 2.2 POSKO

POSKO menggunakan Android App untuk:
- menerima P0 dan WO;
- menjalankan PASILA;
- memilih petugas aktual dari master Petugas;
- menyimpan input sebagai draft ketika offline;
- mengirim satu draft atau seluruh draft saat online;
- membuka Maps dan navigasi ketika online.

## 2.3 HARSUS

HARSUS adalah Tim inspeksi Harsus. Kepanjangan resmi HARSUS belum dikunci dan tidak boleh diinvensi di dokumen.

HARSUS dapat mencatat temuan, kebutuhan padam, rekomendasi, koordinat, dan dokumentasi. Bila menemukan titik yang belum terdaftar, HARSUS boleh menyimpan temuan dengan koordinat/foto lalu Admin memvalidasi atau membuat master aset.

## 2.4 ROW

ROW menangani pekerjaan vegetasi RABAS, PANGKAS, dan TEBANG. Kepanjangan resmi ROW belum dikunci dan tidak boleh ditulis sebagai kepanjangan tertentu sampai dikonfirmasi stakeholder.

ROW dapat mengusulkan titik vegetasi baru dengan koordinat, foto, dan catatan untuk divalidasi Admin.

---

# 3. Goals dan Non-Goals

## 3.1 Goals MVP

### G-01 — Pengelolaan end-to-end
Sistem mengelola pekerjaan dari penugasan sampai penyelesaian, dokumentasi, histori, dan evaluasi.

### G-02 — Prioritas kerja POSKO
Urutan prioritas operasional:

**P0 → WO → PASILA**

P0 selalu didahulukan.

### G-03 — Work Order preventive
Target operasional adalah **6 WO per shift per Posko**. Pada MVP, WO hanya untuk **PEMASANGAN_APKUK**. Jika P0 menyita waktu shift, P0 tetap didahulukan dan WO yang belum dikerjakan dijadwalkan ulang.

### G-04 — PASILA setiap shift
PASILA wajib tersedia setiap shift. Sistem memberi rekomendasi area/aset berdasarkan prioritas gangguan/trip. POSKO boleh mengganti rekomendasi dengan alasan wajib.

### G-05 — Tindak lanjut temuan
Temuan yang tidak dapat dieksekusi langsung dapat menjadi P0.

### G-06 — Inspeksi HARSUS
HARSUS dapat mencatat temuan dan menghasilkan tindak lanjut P0.

### G-07 — Pengelolaan ROW
ROW mengelola pekerjaan vegetasi. Vegetasi yang ditangani POSKO melalui PASILA tetap masuk histori ROW.

### G-08 — Special Case
Rule awal Special Case adalah **lebih dari 3 trip pada satu penyulang dalam satu bulan kalender**. Special Case dapat memengaruhi prioritas WO dan dapat menghasilkan P0 investigasi.

### G-09 — Maps operasional
Maps menampilkan titik, informasi aset, pekerjaan, dan navigasi. Peta lengkap bersifat online-primary.

### G-10 — Mobile field operation
POSKO, HARSUS, dan ROW menggunakan Flutter Android App.

### G-11 — Web operation
Web Admin khusus role ADMIN untuk MVP.

### G-12 — Offline draft
Input lapangan dan foto dapat disimpan sebagai draft lokal ketika offline. Draft tidak otomatis menjadi data resmi server. User menekan **Kirim** atau **Kirim Semua** ketika online.

### G-13 — Histori & audit
Perubahan status, reschedule, koreksi Admin, dan pekerjaan selesai tercatat dalam histori/audit.

## 3.2 Non-Goals MVP

- Ranking/skor final performa Posko — **v2**.
- Native iOS.
- Multi-ULP.
- Payroll/insentif.
- Inventory/gudang penuh.
- Full offline Maps basemap.
- Full bidirectional database synchronization.
- PowerSync.
- Realtime WebSocket sebagai syarat MVP.
- AI/ML kompleks sebagai blocker untuk membangun alur inti. Integrasi ML dapat ditambahkan setelah alur dasar stabil.

---

# 4. User Stories

## ADMIN
- Melihat seluruh P0, WO, PASILA, HARSUS, ROW, Special Case, serta status submission yang sudah masuk server.
- Membuat P0/WO manual.
- Mengelola Posko, Penyulang, Section, Asset, APKUK, Vegetasi, Petugas, dan User.
- Memvalidasi usulan aset/vegetasi baru.
- Menyetujui atau menolak custom reschedule P0.
- Mengoreksi data server dan meninggalkan audit log.

## POSKO
- Melihat pekerjaan dengan urutan P0 → WO → PASILA.
- Menjalankan pekerjaan dan memilih petugas aktual.
- Menyimpan draft saat offline dan mengeditnya sebelum dikirim.
- Mengirim satu atau banyak draft saat online.
- Melanjutkan P0 ke shift berikutnya atau mengajukan custom reschedule.

## HARSUS
- Membuat inspeksi dan foto temuan.
- Menyimpan draft offline.
- Mengirim temuan yang belum memiliki master aset melalui koordinat untuk divalidasi Admin.

## ROW
- Mencatat pekerjaan RABAS/PANGKAS/TEBANG.
- Menyimpan draft offline.
- Mengusulkan titik vegetasi baru untuk validasi Admin.

---

# 5. Daftar Fitur MVP

- Authentication dan role: ADMIN, POSKO, HARSUS, ROW.
- Master Posko, Penyulang, Section, Asset, APKUK, Vegetasi, Petugas, User.
- P0 manual/HARSUS/PASILA/Special Case.
- WO pemasangan APKUK, target 6/shift/Posko.
- PASILA per shift, rekomendasi target + override dengan alasan.
- HARSUS inspection.
- ROW work + proposal vegetasi.
- Special Case >3 trip/penyulang/bulan kalender.
- Maps online-primary.
- Dokumentasi foto.
- Draft offline + local queue + Kirim/Kirim Semua.
- Histori dan audit log.
- Admin dashboard polling ringan, default sekitar 15 detik + tombol Refresh.

---

# 6. Functional Requirements MVP

## 6.1 Authentication dan User Access

**FR-AUTH-01** Sistem menyediakan login online melalui Laravel backend.  
**FR-AUTH-02** Role aplikasi hanya: ADMIN, POSKO, HARSUS, ROW.  
**FR-AUTH-03** Satu akun dapat mewakili satu tim/unit; jumlah akun mengikuti jumlah tim yang tersedia.  
**FR-AUTH-04** Satu akun POSKO terhubung ke satu Posko.  
**FR-AUTH-05** Akun dapat digunakan di lebih dari satu device.  
**FR-AUTH-06** Petugas aktual dipilih dari master Petugas.  
**FR-AUTH-07** Login baru membutuhkan internet. Session yang sudah tersimpan dapat digunakan untuk membuka mode draft offline selama masih valid menurut aplikasi lokal; validasi server kembali dilakukan saat perangkat online.

## 6.2 Posko, Penyulang, Section, dan Shift

### Klarifikasi Topologi & Kepemilikan Wilayah:
1. **Relasi Many-to-Many Posko ↔ Penyulang:** Satu Penyulang (feeder) dapat melintasi dan ditangani oleh lebih dari satu Posko. Demikian pula satu Posko dapat menangani segmen dari beberapa Penyulang.
2. **Asset Ownership Bukan Berdasarkan Feeder Saja:** Hubungan Posko-Penyulang tidak menentukan secara otomatis ownership operasional suatu aset fisik. Jangan pernah menyimpulkan Posko penanggung jawab aset hanya dari nama feeder.
3. **Penetapan Ownership Melalui Section:** Ownership operasional aset fisik nantinya ditetapkan secara definitif melalui **Section tervalidasi**. Satu Section memiliki satu Posko penanggung jawab aktif pada satu waktu, dan aset diasosiasikan ke Posko melalui Section tersebut.
4. **Section Final Pending:** Master Section final belum dibuat pada implementasi aktif saat ini karena masih menunggu kelengkapan sumber data pemutus/proteksi eksternal yang terfilter (file FCO dan Switching ULP Sribhawono).

Jam operasional baseline:
- Shift A: **08:00–16:00**
- Shift B: **16:00–00:00**
- Shift C: **00:00–08:00**

Konfigurasi tetap disimpan di sistem agar dapat diubah Admin bila kebijakan operasional resmi berubah.

## 6.3 P0

**FR-P0-01** P0 adalah prioritas tertinggi.  
**FR-P0-02** Sumber P0: ADMIN, HARSUS, PASILA, SPECIAL_CASE.  
**FR-P0-03** P0 yang sudah dapat ditugaskan ke POSKO wajib menunjuk asset, Section, dan Posko yang sudah tervalidasi. Temuan lokasi yang belum terdaftar disimpan lebih dahulu pada sumbernya (mis. HARSUS/PASILA) sebagai temuan menunggu validasi Admin.  
**FR-P0-04** Admin memetakan/membuat asset + Section/Posko untuk temuan unknown point; setelah validasi selesai barulah P0 dibuat dengan status `BARU` dan muncul ke POSKO.  
**FR-P0-05** Status minimum: BARU, DITERIMA, DIKERJAKAN, RESCHEDULED, SELESAI, CANCELLED.  
**FR-P0-06** Bila P0 belum selesai, POSKO memilih salah satu:
1. **LANJUT_SHIFT_BERIKUTNYA** — otomatis diarahkan ke shift berikutnya, alasan wajib, tidak perlu approval Admin.
2. **CUSTOM_RESCHEDULE** — pilih tanggal + shift, alasan wajib, menunggu APPROVED/REJECTED oleh Admin.

P0 tetap menggunakan record/ID yang sama. Tidak membuat child P0 hanya karena reschedule.

**FR-P0-07** P0 menyimpan **snapshot kebutuhan eksekusi** (`kebutuhan_padam`, `kebutuhan_material`) agar POSKO menerima pekerjaan yang sudah siap dieksekusi. Jika P0 berasal dari HARSUS/PASILA, nilai dicopy dari sumber; jika dibuat manual, ADMIN mengisinya. Untuk P0 dari Special Case, kebutuhan eksekusi harus tersedia/ditinjau sebelum P0 ditugaskan.  
**FR-P0-08** Histori status, shift, jadwal, alasan, requester, approver, `occurred_at` (waktu aktivitas/input) dan `recorded_at` (waktu diterima server) dicatat.  
**FR-P0-09** Saat `CUSTOM_RESCHEDULE` masih menunggu keputusan Admin, status P0 tetap `DIKERJAKAN`. Jika disetujui/NEXT_SHIFT, status menjadi `RESCHEDULED`; saat pekerjaan dilanjutkan pada target shift, status kembali `DIKERJAKAN`. Jika request ditolak, status tetap `DIKERJAKAN`.

## 6.4 Work Order

**FR-WO-01** WO MVP hanya `PEMASANGAN_APKUK`.  
**FR-WO-02** Target 6 WO/shift/Posko.  
**FR-WO-03** P0 tetap didahulukan; WO yang tidak sempat dikerjakan dapat `RESCHEDULED` ke tanggal/shift berikutnya dengan alasan. WO carry-over **mengisi kuota terlebih dahulu**; total target pada shift target tetap 6 WO/Posko, bukan 6 baru ditambah backlog.  
**FR-WO-04** Satu WO = satu aset.  
**FR-WO-05** Aset dengan APKUK aktif tidak mendapat WO pemasangan APKUK identik.  
**FR-WO-06** WO menyimpan `priority_score` dan `alasan_prioritas` agar POSKO dapat melihat “kenapa titik ini dipilih”.  
**FR-WO-07** Admin dapat membuat WO manual dengan jenis yang sama pada MVP.  
**FR-WO-08** Kandidat awal difilter dari aset eligible yang belum memiliki APKUK aktif dan tidak memiliki WO APKUK aktif. Ranking dapat menggunakan model ML ketika siap; alur utama tidak boleh bergantung pada model yang belum tersedia.  
**FR-WO-09** Saat WO pemasangan APKUK berhasil `SELESAI`, server dalam proses yang sama membuat/memperbarui record APKUK menjadi aktif pada aset tersebut dengan tanggal pasang berdasarkan waktu pekerjaan, sehingga aset tidak terpilih lagi sebagai kandidat WO APKUK identik.

## 6.5 PASILA

**FR-PAS-01** PASILA wajib setiap shift.  
**FR-PAS-02** Sistem memberikan rekomendasi area/aset berdasarkan prioritas gangguan/trip.  
**FR-PAS-03** POSKO boleh override rekomendasi dengan alasan wajib.  
**FR-PAS-04** Satu sesi dapat memiliki banyak action.  
**FR-PAS-05** Action yang tidak dapat dieksekusi wajib mencatat alasan, `kebutuhan_padam`, dan `kebutuhan_material`, lalu dapat menghasilkan P0.  
**FR-PAS-06** Vegetasi yang dieksekusi langsung dicatat sebagai ROW insidental.

## 6.6 HARSUS

HARSUS mencatat temuan, kondisi aset, dokumentasi, rekomendasi, `TANPA_PADAM/PERLU_PADAM`, kebutuhan material (gunakan `TIDAK_ADA` bila tidak membutuhkan material), koordinat, dan flag perlu P0. Asset boleh belum terdaftar; temuan tetap dapat disimpan menggunakan koordinat lalu masuk `PENDING_ASSET_VALIDATION`. Admin memetakan/membuat asset sebelum P0 executable dibuat.

## 6.7 ROW

Jenis tindakan: RABAS, PANGKAS, TEBANG. ROW cukup menyimpan **foto + catatan**, tidak memerlukan field teks khusus kondisi_sebelum/kondisi_sesudah. ROW dapat mengusulkan vegetasi baru dengan koordinat + foto + catatan untuk validasi Admin.

## 6.8 Special Case

Rule awal: **>3 trip/penyulang/bulan kalender**.

Jika Special Case mengenai aset yang sudah memiliki P0 aktif:
- masalah sama → jangan buat P0 baru; tambahkan info/alasan/evidence ke P0 aktif;
- masalah berbeda → boleh membuat P0 baru.

Special Case juga menjadi salah satu faktor prioritas WO/APKUK.

## 6.9 Maps

Maps tersedia di mobile dan Admin Web secara online-primary. Cache offline tidak wajib menyimpan basemap. Navigasi eksternal membutuhkan koneksi/ketersediaan aplikasi peta.

## 6.10 Dokumentasi

Semua pekerjaan yang akan disubmit wajib memiliki minimal satu foto. Jenis foto final:
- BEFORE
- AFTER
- TEMUAN
- SURVEY

Tidak semua pekerjaan wajib BEFORE+AFTER; aturan minimal per jenis pekerjaan dapat ditentukan di aplikasi. Foto dapat diambil offline dan disimpan bersama draft.

## 6.11 Histori dan Koreksi

Draft lokal bebas diedit sebelum submit. Setelah data masuk server/pekerjaan selesai, field user lapangan tidak diedit langsung. Koreksi dilakukan oleh ADMIN melalui Web Admin dan seluruh perubahan dicatat pada audit log.

## 6.12 Dashboard Admin

Web khusus ADMIN. Dashboard dapat menggunakan polling ringan sekitar 15 detik dan tombol Refresh; realtime WebSocket tidak menjadi requirement MVP.

## 6.13 Offline Draft & Submission

**FR-OFF-01** Input field dan foto dapat disimpan offline.  
**FR-OFF-02** Draft tersimpan persisten setelah app ditutup/HP restart.  
**FR-OFF-03** Draft dapat diubah sebelum submit.  
**FR-OFF-04** User dapat Kirim satu draft atau Kirim Semua saat online.  
**FR-OFF-05** Status lokal minimum: DRAFT, READY_TO_SEND, SENDING, SUBMITTED, FAILED.  
**FR-OFF-06** Jika bulk submit berisi beberapa record dan satu gagal, record lain tetap boleh berhasil.  
**FR-OFF-07** Record FAILED tetap di lokal dan dapat retry tanpa duplikasi.  
**FR-OFF-08** Waktu aktivitas/input berasal dari waktu pencatatan di device (`occurred_at`/`input_at`); server menyimpan waktu penerimaan terpisah (`recorded_at`/`submitted_at`). Untuk status P0/WO, kedua waktu disimpan pada `status_history`; untuk entity lapangan baru keduanya juga tersimpan pada record transaksi terkait.  
**FR-OFF-09** P0/WO baru yang dibuat saat device offline baru muncul setelah device online dan melakukan refresh/bootstrap.  
**FR-OFF-10** Cache offline hanya data ringan yang diperlukan form, seperti asset, section, posko, petugas, dan koordinat. Histori lengkap tetap online-primary.  
**FR-OFF-11** Dua device yang mencoba mengubah pekerjaan server yang sama tidak boleh saling overwrite otomatis; server mengembalikan conflict untuk pemeriksaan manual.

---

# 7. Sketsa Data Model & Status Implementasi

### A. Tabel Nyata yang Sudah Terimplementasi (per 30 September 2026):
- **Staging / Raw Source Tables:** `apkuk_source_rows`, `asset_source_rows`, `vegetasi_source_rows`, `gangguan_source_rows`, `section_pic_source_rows`, `keypoint_source_rows`.
- **Core Master Tables:** `poskos` (6 Posko resmi), `penyulangs` (16 canonical feeder), dan pivot Many-to-Many `posko_penyulang` (26 relasi terverifikasi).
- **Framework Tables:** `users`, `cache`, `jobs`.

### B. Planned / Domain Schema (Target Implementasi Lanjutan):
- Master Terencana: `sections` (BLOCKED), `assets` (BLOCKED/PARTIAL), `apkuks`, `vegetasis`, `petugas`.
- Transaksi Terencana: `p0`, `p0_petugas`, `p0_reschedule_history`, `p0_context_events`, `work_orders`, `wo_petugas`, `wo_reschedule_history`, `pasila_sessions`, `pasila_actions`, `harsus_inspections`, `row_works`, `vegetasi_proposals`, `special_cases`, `documentations`.
- History, Audit & Config: `status_histories`, `audit_logs`, `app_configs`.

Draft/outbox mobile **bukan** tabel transaksi server; data tersebut berada pada SQLite perangkat sampai submit berhasil. Detail skema lengkap terdapat di [DATA_MODEL.md](./DATA_MODEL.md).

---

# 8. Edge Cases dan Failure States

- App ditutup saat offline → draft tetap ada.
- Bulk submit sebagian gagal → hanya item gagal tetap `FAILED`.
- Foto wajib belum tersedia → submit ditolak di client/server.
- Upload foto gagal → item belum dianggap complete; retry tersedia.
- P0 dibuat pada titik tanpa master aset → masuk alur validasi Admin.
- Dua device submit perubahan pada pekerjaan sama → server mengembalikan conflict, tidak auto-overwrite.
- P0 belum selesai → lanjut shift berikutnya atau custom reschedule.
- WO belum selesai karena P0 → WO dapat reschedule.
- Special Case masalah sama dengan P0 aktif → merge evidence/info, bukan duplikasi P0.
- Special Case masalah berbeda → P0 baru diperbolehkan.

---

# 9. Success Metrics / Definition of Done MVP

- Keempat role berfungsi sesuai hak akses.
- P0 berjalan dari assignment sampai selesai/reschedule dengan histori lengkap.
- Sistem dapat menghasilkan target 6 WO/shift/Posko dan menjelaskan alasan prioritas.
- PASILA tersedia setiap shift dan mendukung rekomendasi + override.
- HARSUS dan ROW dapat membuat draft offline termasuk foto/koordinat.
- Draft tidak hilang setelah app ditutup dan dapat submit satu per satu/bulk.
- Retry tidak membuat duplikasi.
- Admin dapat mengelola seluruh master, validasi titik, koreksi, dan audit.
- Semua pekerjaan submit memiliki dokumentasi minimal sesuai rule.

---

# 10. Open Questions yang Masih Sah

1. Formula dan model ML final untuk memberi priority score WO.
2. Formula jadwal ROW berikutnya.
3. Metode ingest data gangguan produksi (CSV/API/adapter lain sesuai akses nyata).
4. Retention policy transaksi/foto dan backup production.
5. Deployment provider production dan detail sizing infrastruktur.
6. Matriks foto minimal per jenis pekerjaan bila stakeholder ingin aturan lebih spesifik dari “minimal satu foto”.
7. Taxonomy `issue_type` dan aturan menentukan “masalah sama vs berbeda” untuk Special Case sebelum Phase Special Case production.

Keputusan yang **tidak lagi Open Question**: target WO tetap 6, WO MVP APKUK saja, PASILA target selection, bulan kalender Special Case, jam shift, model akun tim, HARSUS unknown point, conflict manual, full offline basemap tidak wajib, correction dilakukan Admin, dan offline menggunakan draft/manual submit.

---

# Ringkasan Produk

Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi ULP Sribhawono adalah platform Flutter Android + Laravel Web/API + PostgreSQL yang mengelola P0, WO, PASILA, HARSUS, ROW, Special Case, Maps, dokumentasi, histori, dan monitoring Admin. Aplikasi lapangan mendukung penyimpanan draft serta foto saat offline dan pengiriman manual ketika internet tersedia, tanpa full bidirectional database sync.
