# PRODUCT REQUIREMENTS DOCUMENT

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Draft untuk validasi stakeholder  
**Scope:** ULP Sribhawono  
**Platform:** Android App + Web Application  
**Pengguna utama:** Admin, Posko, Harsus, ROW  
**Fokus:** Pengelolaan pekerjaan preventive jaringan distribusi secara end-to-end

---

# 1. Problem Statement

## Masalah

Kegiatan preventive jaringan distribusi melibatkan beberapa tim, wilayah kerja, jenis pekerjaan, temuan lapangan, histori gangguan, dan tindak lanjut yang saling berkaitan.

Sistem yang dibutuhkan tidak cukup hanya menampilkan kondisi jaringan dalam bentuk dashboard. Dibutuhkan satu sistem yang dapat menghubungkan proses:

**identifikasi kondisi → penentuan prioritas → penugasan → pelaksanaan → dokumentasi → tindak lanjut → histori → evaluasi.**

Tanpa alur yang terintegrasi, terdapat risiko:

- pekerjaan preventive tidak memiliki urutan prioritas yang jelas;
- temuan inspeksi tidak langsung mempunyai tindak lanjut;
- pekerjaan yang tidak dapat diselesaikan di lapangan hanya menjadi catatan;
- histori tindakan pada suatu aset sulit digunakan untuk menentukan pekerjaan berikutnya;
- pekerjaan vegetasi dapat dilakukan berulang karena tidak diketahui sudah pernah ditangani;
- gangguan berulang tidak langsung diterjemahkan menjadi pekerjaan preventive;
- Admin sulit melihat status keseluruhan pekerjaan seluruh Posko secara terpusat.

## Siapa yang terdampak?

### Admin

Admin membutuhkan visibilitas terhadap pekerjaan seluruh Posko, Harsus, dan ROW serta kemampuan untuk memberikan pekerjaan manual ketika diperlukan.

### Posko

Posko membutuhkan daftar pekerjaan yang jelas, terurut berdasarkan prioritas, memiliki titik lokasi, informasi pekerjaan, dan mekanisme pelaporan hasil langsung dari lapangan.

### Harsus

Harsus membutuhkan mekanisme untuk mencatat hasil inspeksi dan memastikan temuan yang membutuhkan tindakan diteruskan menjadi pekerjaan.

### ROW

ROW membutuhkan informasi pekerjaan vegetasi serta histori titik yang telah ditangani agar pekerjaan tidak dilakukan berulang tanpa kebutuhan.

### ULP Sribhawono

ULP membutuhkan mekanisme preventive yang tidak hanya bereaksi setelah gangguan terjadi, tetapi dapat menggunakan kondisi dan histori jaringan untuk menentukan tindakan berikutnya.

---

# 2. Target User + Persona

Sistem memiliki tepat **4 role utama**:

1. Admin
2. Posko
3. Harsus
4. ROW

## Persona 1 — Petugas Posko

**Konteks kerja:**  
Melakukan pekerjaan jaringan di lapangan berdasarkan wilayah tanggung jawab Posko.

**Kebutuhan utama:**

- mengetahui pekerjaan yang harus dilakukan pada shift berjalan;
- mengetahui pekerjaan mana yang harus didahulukan;
- melihat titik dan detail pekerjaan;
- membuka navigasi ke lokasi;
- melihat informasi/histori titik;
- memilih nama petugas yang benar-benar turun melalui dropdown;
- menginput hasil pekerjaan;
- mengambil dokumentasi;
- tetap dapat melakukan input ketika tidak tersedia koneksi;
- mengirim/sinkronisasi hasil ketika koneksi kembali tersedia.

**Prioritas pekerjaan:**

**P0 → Work Order → PASILA**

Satu akun Posko mewakili **satu Posko**, bukan satu petugas individu.

Identitas petugas aktual dipilih ketika pekerjaan dilaksanakan.

---

## Persona 2 — Admin

**Konteks kerja:**  
Mengawasi seluruh aktivitas preventive ULP Sribhawono.

**Kebutuhan utama:**

- melihat aktivitas seluruh Posko;
- melihat P0, WO, PASILA, Harsus, dan ROW;
- melihat pekerjaan aktif, selesai, tertunda, atau membutuhkan tindak lanjut;
- menerbitkan WO manual;
- membuat P0 manual;
- melihat Special Case;
- melihat Maps dan histori aset;
- melakukan koreksi/administrasi apabila diperlukan;
- melihat performa operasional Posko.

---

# 3. Goals dan Non-Goals

## Goals MVP

### G-01 — Pengelolaan pekerjaan end-to-end

Sistem mampu mengelola pekerjaan dari tahap penugasan sampai penyelesaian dan histori.

### G-02 — Prioritas kerja Posko

Posko mendapatkan pekerjaan berdasarkan urutan:

**P0 → Work Order → PASILA**

P0 selalu memiliki prioritas tertinggi.

### G-03 — Work Order preventive

Sistem dapat menghasilkan Work Order preventive.

Target awal:

**6 WO per shift per Posko.**

Satu WO mewakili satu titik aset.

Jenis utama WO pada MVP:

**pemasangan APKUK.**

Admin juga dapat memberikan WO secara manual.

### G-04 — PASILA setiap shift

Setiap Posko menjalankan PASILA pada setiap shift.

PASILA merupakan:

**patroli + eksekusi langsung.**

Satu sesi PASILA dapat menghasilkan banyak action.

### G-05 — Tindak lanjut temuan

Temuan yang tidak dapat diselesaikan langsung harus dapat diteruskan menjadi P0.

### G-06 — Inspeksi Harsus

Harsus dapat mencatat temuan inspeksi dan menghasilkan tindak lanjut berupa P0.

### G-07 — Pengelolaan ROW

ROW dapat mengelola pekerjaan rabas, pangkas, dan tebang.

Vegetasi yang ditangani Posko saat PASILA juga harus masuk histori ROW.

### G-08 — Special Case

Gangguan berulang dapat dikenali sistem dan diterjemahkan menjadi P0 untuk tindakan preventive.

### G-09 — Maps operasional

Sistem memiliki Maps untuk:

- melihat titik;
- melihat informasi aset;
- melihat histori;
- melihat pekerjaan;
- membuka navigasi.

### G-10 — Mobile field operation

Petugas lapangan dapat menggunakan Android App.

### G-11 — Web operation

Admin dan pengguna yang membutuhkan akses desktop dapat menggunakan Web Application.

### G-12 — Offline input

Input pekerjaan lapangan harus tetap dapat dilakukan saat perangkat tidak memiliki koneksi.

Data kemudian disinkronkan ketika koneksi kembali tersedia.

### G-13 — Histori

Hasil pekerjaan menjadi histori dan dapat digunakan sebagai bahan evaluasi pekerjaan berikutnya.

---

## Non-Goals MVP

Hal berikut **tidak menjadi bagian MVP**:

- ranking/skor final performa Posko;
- sistem payroll atau insentif;
- aplikasi native iOS;
- customer complaint management;
- sistem inventory/gudang penuh;
- multi-ULP;
- integrasi enterprise ke seluruh sistem PLN;
- AI/ML kompleks sebagai syarat MVP;
- penggantian sistem gangguan pusat.

Ranking Posko masuk **v2**.

---

# 4. User Stories

## Admin

### US-ADM-01
Sebagai **Admin**, saya ingin melihat seluruh pekerjaan P0, WO, PASILA, Harsus, dan ROW supaya saya dapat memonitor operasional seluruh Posko.

### US-ADM-02
Sebagai **Admin**, saya ingin membuat P0 manual supaya pekerjaan khusus dapat langsung diberikan kepada Posko yang bertanggung jawab.

### US-ADM-03
Sebagai **Admin**, saya ingin memberikan WO manual supaya pekerjaan preventive tertentu dapat diberikan meskipun tidak berasal dari rekomendasi otomatis sistem.

### US-ADM-04
Sebagai **Admin**, saya ingin melihat Special Case supaya gangguan berulang dapat segera ditindaklanjuti.

### US-ADM-05
Sebagai **Admin**, saya ingin melihat histori suatu aset supaya saya dapat mengetahui gangguan dan tindakan yang pernah dilakukan sebelumnya.

### US-ADM-06
Sebagai **Admin**, saya ingin melihat pekerjaan berdasarkan Posko dan section supaya monitoring wilayah dapat dilakukan dengan jelas.

---

## Posko

### US-PSK-01
Sebagai **Posko**, saya ingin melihat pekerjaan shift saya berdasarkan urutan P0 → WO → PASILA supaya saya mengetahui pekerjaan yang harus didahulukan.

### US-PSK-02
Sebagai **Posko**, saya ingin melihat lokasi pekerjaan di Maps supaya saya dapat menuju titik yang benar.

### US-PSK-03
Sebagai **Posko**, saya ingin memilih petugas pelaksana dari dropdown supaya pekerjaan tercatat atas nama orang yang benar-benar turun ke lapangan.

### US-PSK-04
Sebagai **Posko**, saya ingin mengisi hasil dan dokumentasi pekerjaan supaya pekerjaan dapat dipertanggungjawabkan.

### US-PSK-05
Sebagai **Posko**, saya ingin tetap menginput pekerjaan saat offline supaya kegiatan lapangan tidak berhenti karena kehilangan sinyal.

### US-PSK-06
Sebagai **Posko**, saya ingin data offline otomatis tersinkron ketika online supaya saya tidak perlu input ulang.

### US-PSK-07
Sebagai **Posko**, saya ingin mencatat banyak action dalam satu PASILA supaya seluruh temuan selama patroli dapat tercatat.

### US-PSK-08
Sebagai **Posko**, saya ingin mengubah temuan PASILA yang tidak dapat diselesaikan menjadi P0 supaya temuan tersebut tetap ditindaklanjuti.

### US-PSK-09
Sebagai **Posko**, saya ingin menangani vegetasi yang memungkinkan saat PASILA supaya risiko yang dapat langsung diatasi tidak perlu menunggu Tim ROW.

---

## Harsus

### US-HAR-01
Sebagai **Harsus**, saya ingin memilih titik aset dan menginput hasil inspeksi supaya kondisi jaringan dapat tercatat.

### US-HAR-02
Sebagai **Harsus**, saya ingin memberikan rekomendasi perlu padam atau tanpa padam supaya tindak lanjut pekerjaan mempunyai informasi awal.

### US-HAR-03
Sebagai **Harsus**, saya ingin temuan yang membutuhkan tindakan diteruskan menjadi P0 supaya Posko menerima pekerjaan tersebut.

### US-HAR-04
Sebagai **Harsus**, saya ingin tetap dapat melakukan input ketika offline supaya inspeksi tetap dapat dicatat di lapangan.

---

## ROW

### US-ROW-01
Sebagai **ROW**, saya ingin melihat pekerjaan vegetasi berdasarkan wilayah/section supaya pekerjaan rabas, pangkas, dan tebang dapat dilaksanakan secara terencana.

### US-ROW-02
Sebagai **ROW**, saya ingin mencatat hasil pekerjaan vegetasi supaya kondisi terbaru dapat diketahui sistem.

### US-ROW-03
Sebagai **ROW**, saya ingin melihat pekerjaan vegetasi yang telah dilakukan Posko saat PASILA supaya titik tersebut tidak ditangani ulang tanpa kebutuhan.

### US-ROW-04
Sebagai **ROW**, saya ingin bekerja secara offline dan melakukan sinkronisasi setelah online supaya input lapangan tidak bergantung penuh pada koneksi.

---

# 5. Daftar Fitur

## MVP — WAJIB

### Authentication & Role
- Login.
- Role Admin.
- Role Posko.
- Role Harsus.
- Role ROW.
- Satu akun Posko = satu Posko.

### Master dan Mapping Operasional
- Posko.
- Section.
- Aset jaringan.
- Mapping Section → Posko.
- APKUK.
- Vegetasi.
- Daftar petugas untuk dropdown.

### P0
- P0 dari Admin.
- P0 dari Harsus.
- P0 dari PASILA.
- P0 dari Special Case.
- Assignment otomatis berdasarkan wilayah.
- Progress pekerjaan.
- Dokumentasi.
- Histori.

### Work Order
- Rekomendasi WO otomatis.
- Target 6 WO/shift/Posko.
- Pemasangan APKUK.
- WO manual Admin.
- Progress.
- Dokumentasi.
- Reschedule/cancel dengan histori.

### PASILA
- PASILA setiap shift.
- Penentuan area/titik prioritas.
- Multi-action.
- Eksekusi langsung.
- Dokumentasi.
- Escalation menjadi P0.
- Vegetasi insidental.

### Harsus
- Input inspeksi.
- Pilih aset.
- Temuan.
- Dokumentasi.
- Perlu padam/tanpa padam.
- Rekomendasi.
- Generate P0.

### ROW
- Daftar pekerjaan vegetasi.
- Rabas.
- Pangkas.
- Tebang.
- Histori.
- Pekerjaan insidental Posko.
- Perencanaan pekerjaan berikutnya.

### Special Case
- Deteksi trip berulang.
- Identifikasi titik terkait.
- Generate P0.
- Monitoring Special Case.

### Maps
- Titik aset.
- Gardu.
- Tiang.
- Keypoint.
- Vegetasi.
- Informasi gangguan.
- Filter wilayah.
- Detail titik.
- Histori.
- Tombol navigasi.

### Dokumentasi
- Foto sebelum.
- Foto sesudah.
- Waktu.
- Petugas.
- Status pekerjaan.
- Catatan.
- Lokasi bila tersedia.

### Histori
- Histori per aset.
- Gangguan.
- P0.
- WO.
- PASILA.
- Harsus.
- ROW.
- APKUK.

### Admin Dashboard
- Monitoring seluruh Posko.
- P0.
- WO.
- PASILA.
- Harsus.
- ROW.
- Special Case.
- Status pekerjaan.

### Offline & Sync
- Input offline.
- Penyimpanan lokal.
- Queue sinkronisasi.
- Retry sync.
- Status data belum/sudah tersinkron.

---

## v2

### Ranking Performa Posko
- skor performa;
- ranking;
- bobot gangguan;
- bobot action;
- evaluasi per periode.

### Prioritization Engine Lanjutan
- risk score lebih kompleks;
- histori lebih panjang;
- kemungkinan pemanfaatan machine learning apabila data mencukupi.

### Advanced Analytics
- trend gangguan;
- efektivitas pekerjaan;
- efektivitas APKUK;
- pola gangguan per section/penyulang;
- analisis ROW.

### Reporting
- PDF.
- Excel.
- laporan periodik.

---

## Nanti

- integrasi enterprise PLN yang lebih luas;
- inventory/material management;
- native iOS;
- multi-ULP;
- payroll/insentif;
- predictive maintenance tingkat lanjut.

---

# 6. Functional Requirements MVP

## 6.1 Authentication dan User Access

**FR-AUTH-01**  
Sistem harus menyediakan login.

**FR-AUTH-02**  
Setiap akun mempunyai satu role utama:

- ADMIN
- POSKO
- HARSUS
- ROW

**FR-AUTH-03**  
Satu akun Posko harus terhubung ke tepat satu `POSKO_ID`.

**FR-AUTH-04**  
Section yang dapat dilihat Posko ditentukan melalui section yang menjadi tanggung jawab Posko tersebut.

**FR-AUTH-05**  
Nama petugas aktual tidak harus sama dengan akun Posko.

**FR-AUTH-06**  
Saat melakukan pekerjaan, Posko memilih nama petugas dari daftar dropdown.

**FR-AUTH-07**  
Pengelolaan daftar nama petugas dilakukan oleh Admin.

---

# 6.2 Posko dan Section

**FR-REG-01**  
Sistem harus memiliki daftar Posko.

**FR-REG-02**  
Sistem harus memiliki daftar Section.

**FR-REG-03**  
Satu Posko dapat bertanggung jawab atas banyak Section.

**FR-REG-04**  
Satu Section memiliki satu Posko penanggung jawab aktif pada satu waktu.

**FR-REG-05**  
Section yang dimiliki satu Posko dapat berasal dari penyulang yang berbeda.

---

# 6.3 P0

**FR-P0-01**  
P0 adalah pekerjaan dengan prioritas tertinggi.

**FR-P0-02**  
P0 dapat berasal dari:

- Admin;
- Harsus;
- PASILA;
- Special Case.

**FR-P0-03**  
P0 harus mempunyai titik aset atau lokasi pekerjaan yang jelas.

**FR-P0-04**  
Sistem menentukan Posko penerima berdasarkan section titik tersebut.

**FR-P0-05**  
Status minimum:

- BARU
- DITERIMA
- DIKERJAKAN
- SELESAI
- RESCHEDULED / TIDAK SELESAI
- CANCELLED oleh pihak berwenang.

**FR-P0-06**  
P0 yang tidak selesai wajib mempunyai alasan.

**FR-P0-07**  
P0 lanjutan harus membawa referensi terhadap pekerjaan/temuan asal.

**FR-P0-08**  
Riwayat perubahan status harus disimpan.

---

# 6.4 Work Order

**FR-WO-01**  
Sistem harus mampu menghasilkan WO preventive.

**FR-WO-02**  
Target awal adalah:

**6 WO per shift per Posko.**

**FR-WO-03**  
Satu WO = satu titik aset.

**FR-WO-04**  
Jenis WO utama MVP adalah pemasangan APKUK.

**FR-WO-05**  
Aset dengan APKUK aktif tidak boleh mendapatkan WO pemasangan APKUK yang identik.

**FR-WO-06**  
Aset yang telah memiliki APKUK tetapi masih bermasalah dapat masuk proses evaluasi/tindak lanjut, bukan otomatis pemasangan ulang.

**FR-WO-07**  
Admin dapat membuat WO manual.

**FR-WO-08**  
Sistem menyimpan alasan/rekomendasi mengapa aset dipilih sebagai WO.

**FR-WO-09**  
Status minimum WO:

- BARU
- DITERIMA
- DIKERJAKAN
- SELESAI
- RESCHEDULED
- CANCELLED.

---

# 6.5 PASILA

**FR-PAS-01**  
PASILA wajib tersedia pada setiap shift.

**FR-PAS-02**  
PASILA merupakan patroli sekaligus eksekusi langsung.

**FR-PAS-03**  
PASILA memiliki Posko, section, shift, dan area/titik prioritas.

**FR-PAS-04**  
Satu PASILA dapat mempunyai banyak action.

**FR-PAS-05**  
Setiap action dapat berupa:

- selesai langsung;
- tidak dapat diselesaikan.

**FR-PAS-06**  
Action yang selesai langsung harus masuk histori.

**FR-PAS-07**  
Action yang tidak dapat diselesaikan harus dapat menghasilkan P0.

**FR-PAS-08**  
Alasan tidak selesai wajib dicatat.

**FR-PAS-09**  
Jika action berupa vegetasi dan dapat ditangani langsung, action tersebut dicatat sebagai ROW insidental.

---

# 6.6 Harsus

**FR-HAR-01**  
Harsus dapat membuat inspeksi.

**FR-HAR-02**  
Inspeksi dikaitkan dengan titik aset/section.

**FR-HAR-03**  
Harsus mencatat:

- temuan;
- kondisi;
- dokumentasi;
- rekomendasi;
- perlu padam/tanpa padam.

**FR-HAR-04**  
Temuan yang membutuhkan tindak lanjut dapat membentuk P0.

**FR-HAR-05**  
P0 ditugaskan kepada Posko berdasarkan section.

---

# 6.7 ROW

**FR-ROW-01**  
ROW dapat melihat daftar pekerjaan vegetasi.

**FR-ROW-02**  
Jenis tindakan:

- RABAS
- PANGKAS
- TEBANG.

**FR-ROW-03**  
Pekerjaan dikaitkan dengan section/lokasi vegetasi.

**FR-ROW-04**  
ROW mencatat kondisi sebelum dan sesudah.

**FR-ROW-05**  
Pekerjaan vegetasi yang dilakukan Posko melalui PASILA harus muncul dalam histori ROW.

**FR-ROW-06**  
Riwayat penanganan vegetasi digunakan untuk menentukan kebutuhan pekerjaan berikutnya.

**FR-ROW-07**  
Interval penanganan vegetasi harus dapat dikonfigurasi dan tidak boleh dipaksakan sama untuk seluruh jenis/kondisi vegetasi.

---

# 6.8 Special Case

**FR-SC-01**  
Sistem mendeteksi gangguan berulang.

**FR-SC-02**  
Rule awal Special Case:

**penyulang mengalami lebih dari 3 trip dalam satu bulan.**

**FR-SC-03**  
Sistem mengidentifikasi titik-titik yang terlibat dalam kejadian tersebut.

**FR-SC-04**  
Gangguan berulang pada aset yang sama tidak menghasilkan P0 identik berkali-kali.

**FR-SC-05**  
Aset yang berulang pada titik sama harus mendapatkan peningkatan prioritas evaluasi.

**FR-SC-06**  
Beberapa aset berbeda yang terlibat dapat menghasilkan beberapa target tindak lanjut.

**FR-SC-07**  
Special Case menghasilkan P0.

---

# 6.9 Maps

**FR-MAP-01**  
Maps tersedia pada web dan mobile.

**FR-MAP-02**  
Maps dapat menampilkan:

- Tiang;
- Gardu;
- Keypoint;
- Vegetasi;
- titik gangguan;
- titik pekerjaan.

**FR-MAP-03**  
Maps memiliki filter:

- penyulang;
- section;
- Posko;
- tipe aset;
- status pekerjaan.

**FR-MAP-04**  
Klik/tap titik menampilkan informasi aset.

**FR-MAP-05**  
Detail dapat menampilkan:

- identitas;
- kondisi;
- APKUK;
- histori gangguan;
- histori pekerjaan;
- foto;
- status pekerjaan aktif.

**FR-MAP-06**  
Tersedia tombol navigasi menuju koordinat titik menggunakan aplikasi peta.

---

# 6.10 Dokumentasi

**FR-DOC-01**  
Pekerjaan selesai harus memiliki identitas petugas.

**FR-DOC-02**  
Pekerjaan menyimpan waktu pelaksanaan.

**FR-DOC-03**  
Pekerjaan menyimpan status.

**FR-DOC-04**  
Foto before/after diwajibkan untuk jenis pekerjaan yang memang membutuhkan pembuktian kondisi sebelum dan sesudah.

**FR-DOC-05**  
Dokumentasi dapat dibuat ketika offline.

---

# 6.11 Histori

**FR-HIS-01**  
Sistem mempunyai histori per aset.

**FR-HIS-02**  
Histori mencakup aktivitas yang relevan:

- gangguan;
- APKUK;
- P0;
- WO;
- PASILA;
- inspeksi Harsus;
- ROW.

**FR-HIS-03**  
Histori diurutkan berdasarkan waktu.

**FR-HIS-04**  
Histori dapat digunakan sebagai konteks sebelum melakukan pekerjaan berikutnya.

---

# 6.12 Dashboard Admin

**FR-ADM-01**  
Admin dapat melihat seluruh Posko.

**FR-ADM-02**  
Admin dapat melihat jumlah/status:

- P0;
- WO;
- PASILA;
- inspeksi Harsus;
- ROW;
- Special Case.

**FR-ADM-03**  
Dashboard dapat difilter berdasarkan:

- Posko;
- section;
- penyulang;
- tanggal/periode;
- status.

**FR-ADM-04**  
Admin dapat membuka detail pekerjaan dan histori terkait.

---

# 6.13 Offline & Sync

**FR-OFF-01**  
Android App harus memungkinkan input ketika tidak tersedia internet.

**FR-OFF-02**  
Data offline disimpan secara persisten pada perangkat.

**FR-OFF-03**  
Data tidak boleh hilang apabila aplikasi ditutup sebelum sync.

**FR-OFF-04**  
Ketika perangkat kembali online, data masuk antrean sinkronisasi.

**FR-OFF-05**  
Setiap record mempunyai status sync:

- LOCAL
- SYNCING
- SYNCED
- FAILED.

**FR-OFF-06**  
Sync gagal dapat dicoba kembali tanpa membuat duplikasi data.

**FR-OFF-07**  
User dapat mengetahui apakah pekerjaan sudah tersinkron atau belum.

---

# 7. Sketsa Data Model

Ini adalah **logical data model awal**, bukan schema database final.

## UserAccount

- user_id
- username
- password_hash
- role
- posko_id nullable
- status

Role:

- ADMIN
- POSKO
- HARSUS
- ROW

---

## Petugas

- petugas_id
- nama_petugas
- posko_id / tim
- status

Digunakan untuk dropdown pelaksana pekerjaan.

Data nama petugas belum tersedia dan akan dilengkapi kemudian.

---

## Posko

- posko_id
- nama_posko
- status

---

## Section

- section_id
- nama_section
- penyulang
- posko_id
- aset_awal
- aset_akhir
- panjang_section
- status

Relasi:

**Posko 1 → N Section**

---

## Asset

- asset_id
- nama_asset
- jenis_asset
- section_id
- latitude
- longitude
- konstruksi
- status
- kondisi_terakhir
- foto_ref

Posko dan penyulang dapat ditentukan melalui section.

---

## Gangguan

- gangguan_id
- asset_id
- section_id
- posko_id
- penyulang
- tanggal_kejadian
- penyebab
- sifat_gangguan
- trip
- informasi gangguan lainnya

Sumber detail mengikuti sumber gangguan pusat.

---

## APKUK

- apkuk_id
- asset_id
- jenis_apkuk
- jumlah
- tanggal_pasang
- posisi
- status
- kondisi
- foto_ref

---

## Vegetasi

- vegetasi_id
- asset_terdekat_id
- section_id
- latitude
- longitude
- jenis_vegetasi
- kondisi
- kategori_pertumbuhan
- tingkat_risiko
- tanggal_row_terakhir
- kondisi_terakhir
- foto_ref

---

## WorkOrder

- wo_id
- asset_id
- section_id
- posko_id
- tanggal
- shift
- sumber
- jenis_wo
- priority_score
- alasan_prioritas
- status
- petugas_id
- waktu_mulai
- waktu_selesai
- alasan_reschedule

Sumber:

- AUTO
- ADMIN

---

## P0

- p0_id
- asset_id
- section_id
- posko_id
- source_type
- source_id
- jenis_pekerjaan
- prioritas
- kebutuhan_padam
- kebutuhan_material
- status
- petugas_id
- parent_p0_id
- alasan_tidak_selesai
- waktu_dibuat
- waktu_mulai
- waktu_selesai

Source:

- ADMIN
- HARSUS
- PASILA
- SPECIAL_CASE

---

## PasilaSession

- pasila_id
- tanggal
- shift
- posko_id
- section_id
- penyulang_prioritas
- anchor_asset_id
- waktu_mulai
- waktu_selesai
- status

---

## PasilaAction

- action_id
- pasila_id
- asset_id
- jenis_temuan
- tindakan
- selesai_langsung
- kebutuhan_padam
- kebutuhan_material
- keterangan
- create_p0
- p0_id
- is_row_incidental

Relasi:

**PASILA 1 → N Action**

---

## HarsusInspection

- inspeksi_id
- asset_id
- section_id
- tanggal
- temuan
- kondisi
- tingkat_risiko
- kebutuhan_padam
- rekomendasi
- foto_ref
- p0_id nullable

---

## RowWork

- row_id
- vegetasi_id
- section_id
- tanggal
- jenis_tindakan
- sumber
- posko_id nullable
- petugas_id
- status
- tanggal_row_berikutnya

Sumber:

- ROW_TERJADWAL
- PASILA_INSIDENTAL

---

## SpecialCase

- special_case_id
- penyulang
- periode
- jumlah_trip
- status
- created_at

---

## Documentation

- documentation_id
- source_type
- source_id
- asset_id
- photo_type
- file_ref
- captured_at
- latitude
- longitude
- petugas_id

---

## WorkHistory

Histori dapat dibentuk dari seluruh transaksi pekerjaan dan tidak harus menjadi satu tabel fisik terpisah.

---

# 8. Edge Cases dan Failure States

## EC-01 — Input offline, aplikasi ditutup

Input harus tetap tersimpan lokal dan tersedia setelah aplikasi dibuka kembali.

---

## EC-02 — Sync terputus di tengah jalan

Data yang sudah terkirim tidak boleh terkirim ulang sebagai record baru.

Sync harus bersifat idempotent.

---

## EC-03 — Foto gagal tersinkron

Data pekerjaan tidak boleh hilang.

Status dokumentasi harus ditandai gagal sync dan dapat dicoba kembali.

---

## EC-04 — P0 dibuat tetapi section tidak memiliki Posko aktif

P0 tidak boleh hilang.

Pekerjaan masuk queue Admin untuk assignment manual.

---

## EC-05 — Aset tidak ditemukan saat Harsus inspeksi

**OPEN QUESTION:** apakah Harsus boleh membuat titik sementara/new finding point atau harus meminta Admin menambahkan aset terlebih dahulu?

---

## EC-06 — Temuan PASILA ternyata sudah memiliki P0 aktif

Sistem memberikan warning untuk mencegah pekerjaan duplikat.

---

## EC-07 — WO APKUK dibuat untuk aset yang ternyata sudah memiliki APKUK aktif

Sistem harus membatalkan rekomendasi atau menandainya tidak valid sebelum eksekusi.

---

## EC-08 — APKUK terpasang tetapi aset tetap mengalami gangguan

Sistem tidak membuat WO pemasangan APKUK identik.

Aset diarahkan ke evaluasi/tindak lanjut.

---

## EC-09 — Posko melakukan ROW insidental

Pekerjaan harus tercatat dalam histori vegetasi agar Tim ROW mengetahui titik tersebut sudah ditangani.

---

## EC-10 — PASILA menemukan banyak masalah

Satu sesi tetap dapat menyimpan banyak action tanpa membatasi jumlah berdasarkan jumlah target awal.

---

## EC-11 — PASILA tidak dapat menyelesaikan temuan

Temuan harus menghasilkan P0 dengan informasi dari PASILA sebagai referensi.

---

## EC-12 — Perangkat kehabisan storage

Sistem memberikan warning sebelum dokumentasi offline gagal disimpan.

---

## EC-13 — GPS tidak tersedia

Pekerjaan tetap dapat dilakukan berdasarkan aset/titik yang telah dipilih.

GPS aktual tidak boleh menjadi satu-satunya cara menentukan lokasi.

---

## EC-14 — Data offline diedit dari lebih dari satu device

Strategi conflict resolution belum ditentukan.

**OPEN QUESTION.**

---

## EC-15 — WO belum selesai ketika shift berakhir

Pekerjaan tidak boleh hilang.

Status menjadi reschedule/unfinished dengan alasan.

Aturan terhadap target 6 WO berikutnya masih perlu ditentukan.

---

# 9. Success Metrics

## Definition of Done MVP

Project dianggap siap digunakan apabila:

### Alur Role

- Admin dapat menjalankan seluruh fungsi utama.
- Posko dapat menerima dan menyelesaikan P0, WO, dan PASILA.
- Harsus dapat melakukan inspeksi dan menghasilkan P0.
- ROW dapat menerima dan mencatat pekerjaan vegetasi.

### P0

- P0 dapat dibuat dari seluruh sumber yang ditentukan.
- Assignment Posko berjalan berdasarkan section.
- P0 dapat diselesaikan sampai dokumentasi dan histori.

### WO

- Sistem dapat menghasilkan 6 WO/shift/Posko sesuai business rule yang sudah ditentukan.
- Admin dapat membuat WO manual.
- Aset ber-APKUK aktif tidak mendapatkan duplikasi WO pemasangan.

### PASILA

- PASILA dapat dijalankan setiap shift.
- Satu PASILA mendukung banyak action.
- Action tidak selesai dapat berubah menjadi P0.

### Offline

- Seluruh input utama field dapat disimpan tanpa internet.
- Data offline tidak hilang setelah aplikasi ditutup.
- Data dapat tersinkron ketika kembali online.
- Pengujian sync tidak menghasilkan record duplikat.

### Maps

- Titik pekerjaan dapat ditemukan.
- Detail aset dapat dibuka.
- Navigasi ke titik dapat dilakukan.

### Dokumentasi

- Identitas pelaksana tercatat.
- Waktu tercatat.
- Status tercatat.
- Dokumentasi yang diwajibkan tersedia.

### Histori

- Hasil pekerjaan dapat dilihat kembali pada titik terkait.
- Pekerjaan baru dapat menggunakan histori sebelumnya sebagai konteks.

### Admin Monitoring

- Admin dapat melihat aktivitas seluruh Posko dalam satu sistem.

---

## Business Outcome Metrics

Target angka untuk dampak operasional **belum ditetapkan**.

Metrik yang nantinya dapat digunakan:

- penurunan repeat fault;
- jumlah pekerjaan preventive selesai;
- jumlah P0 selesai;
- rasio temuan PASILA yang ditindaklanjuti;
- rasio temuan Harsus yang ditindaklanjuti;
- jumlah Special Case yang berhasil diselesaikan;
- waktu penyelesaian pekerjaan;
- efektivitas APKUK;
- efektivitas ROW.

Target numeriknya harus ditetapkan bersama stakeholder dan tidak diasumsikan dalam PRD.

---

# 10. Open Questions

## OQ-01 — Formula prioritas WO

Faktor apa saja yang menentukan enam aset terpilih?

Kandidat faktor:

- histori gangguan;
- frekuensi trip;
- status APKUK;
- kondisi aset;
- Special Case;
- histori pekerjaan.

Bobot belum ditentukan.

---

## OQ-02 — 6 WO ketika terdapat P0 banyak

Apakah target **6 WO/shift/Posko tetap wajib** apabila pada shift tersebut terdapat banyak P0 prioritas?

Atau 6 WO merupakan target normal yang dapat berkurang akibat P0?

---

## OQ-03 — Manual WO Admin

Apakah WO manual Admin pada MVP hanya boleh berupa pemasangan APKUK atau dapat berupa pekerjaan preventive lain?

---

## OQ-04 — PASILA target selection

Ketika tidak ada kondisi khusus/P0 yang menjadi acuan, apakah area PASILA selalu ditentukan berdasarkan penyulang dengan jumlah trip tertinggi?

Periode perhitungan trip perlu dikunci.

---

## OQ-05 — Special Case

Rule awal:

**>3 trip/penyulang/bulan.**

Perlu dikonfirmasi apakah menggunakan:

- bulan kalender;
- rolling 30 hari;
- periode lain.

---

## OQ-06 — ROW scheduling

Interval ROW dapat berbeda berdasarkan kondisi/vegetasi.

Formula penentuan tanggal pekerjaan ROW berikutnya belum dikunci.

---

## OQ-07 — Petugas

Daftar nama petugas belum tersedia.

Perlu ditentukan:

- siapa mengelola master petugas;
- apakah petugas hanya terikat ke satu Posko/tim;
- apakah satu pekerjaan bisa memiliki lebih dari satu petugas.

---

## OQ-08 — Offline Maps

Input pekerjaan wajib offline.

Namun kebutuhan **peta dasar penuh secara offline** belum ditentukan.

Pilihan:

- hanya detail pekerjaan/data titik yang dicache;
- peta wilayah juga tersedia offline.

---

## OQ-09 — Conflict Resolution

Jika data yang sama diedit dari dua perangkat ketika offline, aturan versi data yang dianggap benar belum ditentukan.

---

## OQ-10 — Harsus menemukan titik yang belum terdaftar

Belum ditentukan apakah:

- Harsus boleh membuat titik sementara;
- Admin harus membuat aset terlebih dahulu;
- temuan dapat dibuat dengan koordinat tanpa `ID_ASET` lalu divalidasi kemudian.

---

## OQ-11 — Batas dokumentasi

Perlu ditentukan pekerjaan mana yang:

- wajib foto before + after;
- cukup foto hasil;
- wajib koordinat aktual.

---

## OQ-12 — Shift

Sistem menggunakan **3 shift per hari**.

Jam operasional dan aturan pergantian/cutoff shift perlu disimpan sebagai parameter agar dapat diubah tanpa mengubah aplikasi.

---

## OQ-13 — Correction workflow

Perlu difinalisasi apakah pekerjaan berstatus SELESAI:

- tidak dapat diedit oleh Posko sama sekali;
- hanya dapat dikoreksi melalui request ke Admin.

Konsep awal mengarah ke opsi kedua.

---

## OQ-14 — Sumber data gangguan

Data histori gangguan berasal dari sumber gangguan pusat.

Metode integrasi ke backend baru perlu ditentukan sesuai akses teknis yang tersedia.

---

# Ringkasan Produk

**Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi ULP Sribhawono adalah platform Android dan Web yang mengelola proses preventive secara end-to-end melalui P0, Work Order, PASILA, inspeksi Harsus, pekerjaan ROW, Special Case, Maps, dokumentasi, histori, monitoring Admin, serta kemampuan kerja offline dan sinkronisasi ketika koneksi tersedia.**

Produk dianggap berhasil ketika keempat role dapat menggunakan seluruh alur utama tersebut secara nyata dari penugasan sampai penyelesaian dan histori tanpa kehilangan data, termasuk pada kondisi lapangan tanpa koneksi internet.
