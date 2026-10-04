# PRODUCT REQUIREMENTS DOCUMENT

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Business Flow v2 Baseline (Updated per 4 Oktober 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Scope:** ULP Sribhawono (Single-Tenant)
**Platform:** Android App (Flutter) + Web Admin & Backend API (Laravel + PostgreSQL)
**Pengguna utama:** ADMIN, POSKO, HARSUS, INSPEKSI, ROW
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
- mereview dan menyetujui/menolak P0 proposals dari INSPEKSI;
- mereview dan menyetujui/menolak P0 escalation proposals dari PASILA;
- menerima notifikasi/rekomendasi Special Case dan memutuskan apakah membuat P0;
- mengedit rekomendasi Special Case sebelum mengkonversi menjadi P0;
- menyetujui atau menolak reschedule P0;
- melakukan koreksi data yang sudah masuk server;
- melihat audit trail dan histori.

## 2.2 POSKO

POSKO menggunakan Android App untuk:
- menerima dan menjalankan Work Order (WO);
- menjalankan PASILA;
- POSKO **TIDAK** mengeksekusi P0 dan **TIDAK** menerima P0 dalam work feed-nya;
- masih dapat melakukan tindakan vegetasi insidental melalui PASILA ketika dapat dieksekusi langsung;
- vegetasi insidental melalui PASILA tetap masuk histori ROW/vegetasi;
- memilih petugas aktual dari master Petugas;
- menyimpan input sebagai draft ketika offline;
- mengirim satu draft atau seluruh draft saat online;
- membuka Maps dan navigasi ketika online.

## 2.3 HARSUS

HARSUS adalah **Pemeliharaan Khusus**. HARSUS mengeksekusi **seluruh pekerjaan P0 yang sudah executable**.

HARSUS:
- tidak terikat satu Posko (dapat melihat P0 seluruh ULP);
- **TIDAK** berbasis shift (P0 tidak menggunakan Shift A/B/C untuk eksekusi HARSUS);
- **TIDAK** melakukan inspeksi aset (inspeksi dilakukan oleh INSPEKSI);
- memilih Petugas aktual dari Master Petugas **saat menyelesaikan/submit P0**, bukan saat menerima atau memulai;
- dapat mengajukan reschedule P0 dengan target tanggal + alasan.

## 2.4 INSPEKSI

INSPEKSI adalah role resmi baru. INSPEKSI menggunakan Android App untuk:
- melakukan inspeksi kondisi aset/jaringan di seluruh ULP (tidak terikat satu Posko);
- memilih aset existing untuk inspeksi;
- mencatat temuan pada titik yang belum terdaftar menggunakan koordinat + foto + temuan untuk validasi Admin;
- menandai temuan sebagai "Perlu P0" (INSPEKSI **TIDAK** langsung membuat P0 executable; Admin harus memvalidasi/menyetujui terlebih dahulu);
- mengusulkan titik vegetasi baru dengan koordinat + foto + catatan untuk validasi Admin;
- menyimpan draft offline;
- mengirim draft saat online.

## 2.5 ROW

ROW menangani pekerjaan vegetasi RABAS, PANGKAS, dan TEBANG. Kepanjangan resmi ROW belum dikunci dan tidak boleh ditulis sebagai kepanjangan tertentu sampai dikonfirmasi stakeholder.

ROW:
- mengeksekusi pekerjaan vegetasi saja;
- **TIDAK** lagi mengusulkan titik vegetasi baru (proposal vegetasi baru berasal dari INSPEKSI);
- aturan vegetasi insidental oleh POSKO melalui PASILA tetap berlaku.

---

# 3. Goals dan Non-Goals

## 3.1 Goals MVP

### G-01 — Pengelolaan end-to-end
Sistem mengelola pekerjaan dari penugasan sampai penyelesaian, dokumentasi, histori, dan evaluasi.

### G-02 — Prioritas kerja POSKO
Urutan prioritas operasional POSKO:

**WO → PASILA**

POSKO tidak lagi mengeksekusi P0. Seluruh P0 ditangani oleh HARSUS.

### G-03 — Work Order preventive
Target operasional adalah **6 WO per shift per Posko**. Pada MVP, WO hanya untuk **PEMASANGAN_APKUK**. Carry-over rules tetap berlaku.

### G-04 — PASILA setiap shift
PASILA wajib tersedia setiap shift. Sistem memberi rekomendasi area/aset berdasarkan prioritas gangguan/trip. POSKO boleh mengganti rekomendasi dengan alasan wajib.

### G-05 — Tindak lanjut temuan
Temuan yang tidak dapat dieksekusi langsung dapat menjadi proposal P0 yang memerlukan persetujuan Admin.

### G-06 — Inspeksi INSPEKSI
INSPEKSI (role baru) melakukan inspeksi kondisi aset/jaringan, mencatat temuan, dan dapat mengusulkan P0 serta vegetasi baru. HARSUS tidak lagi melakukan inspeksi.

### G-07 — Pengelolaan ROW
ROW mengelola pekerjaan vegetasi (eksekusi saja). Vegetasi yang ditangani POSKO melalui PASILA tetap masuk histori ROW. Proposal vegetasi baru berasal dari INSPEKSI.

### G-08 — Special Case
Rule awal Special Case adalah **lebih dari 3 trip pada satu penyulang dalam satu bulan kalender**. Special Case **TIDAK** otomatis membuat P0. Special Case menghasilkan notifikasi/rekomendasi untuk Admin, yang kemudian memutuskan apakah membuat P0.

### G-09 — Maps operasional
Maps menampilkan titik, informasi aset, pekerjaan, dan navigasi. Peta lengkap bersifat online-primary.

### G-10 — Mobile field operation
POSKO, HARSUS, INSPEKSI, dan ROW menggunakan Flutter Android App.

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
- Melihat seluruh P0, WO, PASILA, INSPEKSI, ROW, Special Case, serta status submission yang sudah masuk server.
- Membuat P0/WO manual.
- Mengelola Posko, Penyulang, Section, Asset, APKUK, Vegetasi, Petugas, dan User.
- Memvalidasi usulan aset/vegetasi baru dari INSPEKSI.
- Mereview dan menyetujui/menolak P0 proposals dari temuan INSPEKSI.
- Mereview dan menyetujui/menolak P0 escalation proposals dari PASILA.
- Menerima notifikasi Special Case, mengedit rekomendasi jika perlu, dan memutuskan apakah membuat P0.
- Menyetujui atau menolak reschedule P0.
- Mengoreksi data server dan meninggalkan audit log.

## POSKO
- Melihat pekerjaan WO dan PASILA.
- Menjalankan WO dan PASILA, memilih petugas aktual.
- POSKO **TIDAK** mengeksekusi P0.
- Menyimpan draft saat offline dan mengeditnya sebelum dikirim.
- Mengirim satu atau banyak draft saat online.
- Jika masalah pada PASILA tidak dapat dieksekusi langsung, dapat mengusulkan escalation ke P0 yang memerlukan persetujuan Admin.
- Masih dapat melakukan tindakan vegetasi insidental melalui PASILA.

## HARSUS
- Menerima dan mengeksekusi seluruh P0 yang sudah executable.
- Menyelesaikan P0 dengan memilih Petugas aktual saat completion/submission.
- Mengajukan reschedule P0 dengan target tanggal + alasan.
- Menyimpan draft offline.
- **TIDAK** melakukan inspeksi aset.

## INSPEKSI
- Melakukan inspeksi kondisi aset/jaringan di seluruh ULP.
- Mencatat temuan pada aset existing maupun titik baru (unknown point).
- Menandai temuan sebagai "Perlu P0" untuk direview Admin.
- Mengusulkan titik vegetasi baru untuk validasi Admin.
- Menyimpan draft offline.

## ROW
- Mencatat pekerjaan RABAS/PANGKAS/TEBANG.
- Menyimpan draft offline.
- **TIDAK** lagi mengusulkan titik vegetasi baru.

---

# 5. Daftar Fitur MVP

- Authentication dan role: ADMIN, POSKO, HARSUS, INSPEKSI, ROW.
- Master Posko, Penyulang, Section, Asset, APKUK, Vegetasi, Petugas, User.
- P0 manual/INSPEKSI/PASILA/Special Case — seluruh P0 dieksekusi oleh HARSUS.
- WO pemasangan APKUK, target 6/shift/Posko — dieksekusi oleh POSKO.
- PASILA per shift, rekomendasi target + override dengan alasan — dieksekusi oleh POSKO.
- INSPEKSI inspection + unknown point + vegetation proposal.
- ROW work (eksekusi vegetasi saja).
- Special Case >3 trip/penyulang/bulan kalender — rekomendasi untuk Admin, bukan auto-P0.
- Maps online-primary.
- Dokumentasi foto.
- Draft offline + local queue + Kirim/Kirim Semua.
- Histori dan audit log.
- Admin dashboard polling ringan, default sekitar 15 detik + tombol Refresh.

---

# 6. Functional Requirements MVP

## 6.1 Authentication dan User Access

**FR-AUTH-01** Sistem menyediakan login online melalui Laravel backend.  
**FR-AUTH-02** Role aplikasi: ADMIN, POSKO, HARSUS, INSPEKSI, ROW.
**FR-AUTH-03** Satu akun dapat mewakili satu tim/unit; jumlah akun mengikuti jumlah tim yang tersedia.  
**FR-AUTH-04** Satu akun POSKO terhubung ke satu Posko.  
**FR-AUTH-05** HARSUS dan INSPEKSI tidak terikat satu Posko (dapat beroperasi di seluruh ULP).
**FR-AUTH-06** Akun dapat digunakan di lebih dari satu device.
**FR-AUTH-07** Petugas aktual dipilih dari master Petugas.
**FR-AUTH-08** Login baru membutuhkan internet. Session yang sudah tersimpan dapat digunakan untuk membuka mode draft offline selama masih valid menurut aplikasi lokal; validasi server kembali dilakukan saat perangkat online.

## 6.2 Posko, Penyulang, Section, dan Shift

### Klarifikasi Topologi & Kepemilikan Wilayah:
1. **Relasi Many-to-Many Posko ↔ Penyulang:** Satu Penyulang (feeder) dapat melintasi dan ditangani oleh lebih dari satu Posko. Demikian pula satu Posko dapat menangani segmen dari beberapa Penyulang.
2. **Asset Ownership Bukan Berdasarkan Feeder Saja:** Hubungan Posko-Penyulang tidak menentukan secara otomatis ownership operasional suatu aset fisik. Jangan pernah menyimpulkan Posko penanggung jawab aset hanya dari nama feeder.
3. **Penetapan Ownership Melalui Section:** Ownership operasional aset fisik nantinya ditetapkan secara definitif melalui **Section tervalidasi**. Satu Section memiliki satu Posko penanggung jawab aktif pada satu waktu, dan aset diasosiasikan ke Posko melalui Section tersebut.
4. **Section Final Pending:** Master Section final belum dibuat pada implementasi aktif saat ini karena masih menunggu kelengkapan sumber data pemutus/proteksi eksternal yang terfilter (file FCO dan Switching ULP Sribhawono).

Jam operasional baseline (untuk POSKO):
- Shift A: **08:00–16:00**
- Shift B: **16:00–00:00**
- Shift C: **00:00–08:00**

HARSUS **TIDAK** berbasis shift. P0 tidak menggunakan Shift A/B/C untuk eksekusi HARSUS.

Konfigurasi tetap disimpan di sistem agar dapat diubah Admin bila kebijakan operasional resmi berubah.

## 6.3 P0

**FR-P0-01** P0 adalah prioritas tertinggi.  
**FR-P0-02** Sumber P0: ADMIN (manual), INSPEKSI (finding + Admin approval), PASILA (escalation + Admin approval), SPECIAL_CASE (rekomendasi + Admin decision).
**FR-P0-03** Seluruh P0 yang sudah executable **dieksekusi oleh HARSUS**, bukan POSKO. POSKO tidak menerima P0 dalam work feed-nya.
**FR-P0-04** P0 yang sudah dapat dieksekusi wajib menunjuk asset, Section, dan Posko yang sudah tervalidasi. `posko_id` pada P0 merepresentasikan **lokasi/wilayah operasional aset**, bukan berarti POSKO yang mengeksekusi. Executor = HARSUS.
**FR-P0-05** Temuan lokasi yang belum terdaftar disimpan lebih dahulu pada sumbernya (INSPEKSI/PASILA) sebagai temuan menunggu validasi Admin. Admin memetakan/membuat asset + Section/Posko untuk temuan unknown point; setelah validasi selesai barulah P0 dibuat.
**FR-P0-06** Status minimum: BARU, DITERIMA, DIKERJAKAN, RESCHEDULED, SELESAI, CANCELLED.
**FR-P0-07** Bila P0 belum selesai, HARSUS mengajukan **reschedule** dengan target tanggal + alasan wajib. Admin approve/reject. Jika approved, P0 menjadi RESCHEDULED dan tetap menggunakan ID yang sama. Jika rejected, P0 tetap pada status kerja yang berlaku. Tidak ada mekanisme "Lanjut Shift Berikutnya" untuk HARSUS karena HARSUS tidak berbasis shift.
**FR-P0-08** P0 menyimpan **snapshot kebutuhan eksekusi** (`kebutuhan_padam`, `kebutuhan_material`) agar HARSUS menerima pekerjaan yang sudah siap dieksekusi. Jika P0 berasal dari INSPEKSI/PASILA, nilai dicopy dari sumber; jika dibuat manual, ADMIN mengisinya. Untuk P0 dari Special Case, kebutuhan eksekusi harus tersedia/ditinjau sebelum P0 dibuat.
**FR-P0-09** Histori status, jadwal, alasan, requester, approver, `occurred_at` (waktu aktivitas/input) dan `recorded_at` (waktu diterima server) dicatat.
**FR-P0-10** Petugas aktual dipilih oleh HARSUS saat completion/submission P0. Petugas **TIDAK** diperlukan saat menerima, accept, atau memulai P0. Completion ditolak jika data completion wajib (termasuk Petugas) tidak lengkap.

## 6.4 Work Order

**FR-WO-01** WO MVP hanya `PEMASANGAN_APKUK`.  
**FR-WO-02** Target 6 WO/shift/Posko.  
**FR-WO-03** WO yang tidak sempat dikerjakan dapat `RESCHEDULED` ke tanggal/shift berikutnya dengan alasan. WO carry-over **mengisi kuota terlebih dahulu**; total target pada shift target tetap 6 WO/Posko, bukan 6 baru ditambah backlog.
**FR-WO-04** Satu WO = satu aset.  
**FR-WO-05** Aset dengan APKUK aktif tidak mendapat WO pemasangan APKUK identik.  
**FR-WO-06** WO menyimpan `priority_score` dan `alasan_prioritas` agar POSKO dapat melihat "kenapa titik ini dipilih".
**FR-WO-07** Admin dapat membuat WO manual dengan jenis yang sama pada MVP.  
**FR-WO-08** Kandidat awal difilter dari aset eligible yang belum memiliki APKUK aktif dan tidak memiliki WO APKUK aktif. Ranking dapat menggunakan model ML ketika siap; alur utama tidak boleh bergantung pada model yang belum tersedia.  
**FR-WO-09** Saat WO pemasangan APKUK berhasil `SELESAI`, server dalam proses yang sama membuat/memperbarui record APKUK menjadi aktif pada aset tersebut dengan tanggal pasang berdasarkan waktu pekerjaan, sehingga aset tidak terpilih lagi sebagai kandidat WO APKUK identik.

## 6.5 PASILA

**FR-PAS-01** PASILA wajib setiap shift.  
**FR-PAS-02** Sistem memberikan rekomendasi area/aset berdasarkan prioritas gangguan/trip.  
**FR-PAS-03** POSKO boleh override rekomendasi dengan alasan wajib.  
**FR-PAS-04** Satu sesi dapat memiliki banyak action.  
**FR-PAS-05** Action yang tidak dapat dieksekusi wajib mencatat alasan, `kebutuhan_padam`, dan `kebutuhan_material`, lalu **dapat mengusulkan escalation ke P0**. Escalation PASILA adalah **proposal/request yang memerlukan persetujuan Admin**. Escalation **TIDAK** langsung membuat P0 executable.
**FR-PAS-06** Vegetasi yang dieksekusi langsung dicatat sebagai ROW insidental.

## 6.6 INSPEKSI

INSPEKSI adalah role baru yang menggantikan fungsi inspeksi yang sebelumnya didokumentasikan pada HARSUS.

INSPEKSI mencatat temuan, kondisi aset, dokumentasi, rekomendasi, `TANPA_PADAM/PERLU_PADAM`, kebutuhan material (gunakan `TIDAK_ADA` bila tidak membutuhkan material), koordinat, dan flag perlu P0.

**Aset existing:** INSPEKSI memilih aset → catat kondisi/temuan → kebutuhan padam → kebutuhan material → rekomendasi → Perlu P0 yes/no → foto/dokumentasi → submit.

**Unknown point:** INSPEKSI → koordinat → foto → temuan → optional Section jika diketahui → submit sebagai pending validasi → Admin memetakan/membuat asset + Section/Posko → jika Perlu P0 = yes, P0 baru dibuat setelah validasi → HARSUS mengeksekusi.

**Vegetasi baru:** INSPEKSI → koordinat → foto → catatan → submit proposal vegetasi → Admin validasi → validated Master Vegetasi / ROW work → ROW mengeksekusi.

## 6.7 ROW

Jenis tindakan: RABAS, PANGKAS, TEBANG. ROW cukup menyimpan **foto + catatan**, tidak memerlukan field teks khusus kondisi_sebelum/kondisi_sesudah.

ROW **TIDAK** lagi mengusulkan vegetasi baru. Proposal vegetasi baru berasal dari INSPEKSI.

## 6.8 Special Case

Rule awal: **>3 trip/penyulang/bulan kalender**.

Special Case **TIDAK** otomatis membuat P0.

Flow:
1. Sistem mendeteksi >3 trip pada satu penyulang dalam satu bulan kalender.
2. Sistem membuat notifikasi/indikasi/rekomendasi untuk Admin.
3. Admin membuka Special Case.
4. Admin dapat mereview dan mengedit informasi P0 yang direkomendasikan.
5. Admin memutuskan apakah membuat/menyetujui P0.
6. Jika dibuat, HARSUS mengeksekusi P0 tersebut.

Special Case adalah **SYSTEM RECOMMENDATION / CONTEXT** yang dapat dikonversi menjadi P0 oleh Admin. Source/context tetap dapat merekam SPECIAL_CASE untuk traceability, tetapi pembuatan P0 adalah keputusan eksplisit Admin.

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
- Transaksi Terencana: `p0`, `p0_petugas`, `p0_reschedule_history`, `p0_context_events`, `work_orders`, `wo_petugas`, `wo_reschedule_history`, `pasila_sessions`, `pasila_actions`, `inspections`, `row_works`, `vegetasi_proposals`, `special_cases`, `documentations`.
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
- P0 belum selesai → HARSUS mengajukan reschedule dengan target tanggal + alasan, Admin approve/reject.
- WO belum selesai → WO dapat reschedule.
- Special Case masalah sama dengan P0 aktif → merge evidence/info, bukan duplikasi P0.
- Special Case masalah berbeda → Admin dapat membuat P0 baru.

---

# 9. Success Metrics / Definition of Done MVP

- Kelima role berfungsi sesuai hak akses.
- P0 berjalan dari assignment sampai selesai/reschedule dengan histori lengkap, dieksekusi oleh HARSUS.
- Sistem dapat menghasilkan target 6 WO/shift/Posko dan menjelaskan alasan prioritas.
- PASILA tersedia setiap shift dan mendukung rekomendasi + override.
- INSPEKSI dapat membuat inspeksi dan proposal vegetasi, dengan draft offline termasuk foto/koordinat.
- HARSUS dapat menerima dan menyelesaikan P0 dengan draft offline.
- ROW dapat mengeksekusi pekerjaan vegetasi dengan draft offline.
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
6. Matriks foto minimal per jenis pekerjaan bila stakeholder ingin aturan lebih spesifik dari "minimal satu foto".
7. Taxonomy `issue_type` dan aturan menentukan "masalah sama vs berbeda" untuk Special Case sebelum Phase Special Case production.

Keputusan yang **tidak lagi Open Question**: target WO tetap 6, WO MVP APKUK saja, PASILA target selection, bulan kalender Special Case, jam shift, model akun tim, unknown point inspection, conflict manual, full offline basemap tidak wajib, correction dilakukan Admin, offline menggunakan draft/manual submit, HARSUS = Pemeliharaan Khusus, HARSUS eksekutor P0, INSPEKSI role inspeksi, ROW hanya eksekusi vegetasi.

---

# Ringkasan Produk

Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi ULP Sribhawono adalah platform Flutter Android + Laravel Web/API + PostgreSQL yang mengelola P0 (eksekutor: HARSUS), WO (eksekutor: POSKO), PASILA (eksekutor: POSKO), Inspeksi (eksekutor: INSPEKSI), ROW (eksekutor: ROW), Special Case (rekomendasi sistem → keputusan Admin), Maps, dokumentasi, histori, dan monitoring Admin. Aplikasi lapangan mendukung penyimpanan draft serta foto saat offline dan pengiriman manual ketika internet tersedia, tanpa full bidirectional database sync.
