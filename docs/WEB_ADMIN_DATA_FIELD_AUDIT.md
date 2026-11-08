# Web Admin Data & Field Audit

- **Status audit:** code-verified, source-contract-verified, **live-source-verified (read-only)**
- **Tanggal audit:** 28 Oktober 2026 (verifikasi live 15.40–16.10 WIB)
- **Ruang lingkup:** Web Admin ULP Sribawono (branch `feat/admin-web-foundation-v1` setelah integrasi PR #11), kontrak API mobile, kontrak sinkronisasi Google Sheets, struktur dan isi Neon production (read-only), skema lokal/migrasi, dan artefak pengujian pada repository.

> Cara membaca: bagian 3.7–3.8 berisi **bukti live** dari spreadsheet `MASTER_DATA` dan Neon production. Bagian 5.0 merangkum asal data per menu. Bagian 8 berisi keputusan yang perlu Anda konfirmasi.

## 1. Ringkasan eksekutif

Audit ini menemukan tiga tingkat kematangan domain yang berbeda:

1. **Sudah operasional dan memiliki penyimpanan/kontrak field**: Work Order, APKUK, Inspeksi, ROW, HARSUS, Review/Approval, Special Case sebagian, Master Data sebagian, Analytics, User Management, Audit Logs, dan APKUK Reconciliation.
2. **Sudah memiliki tampilan agregat tetapi belum memiliki domain sendiri**: PASILA. Halaman PASILA saat ini menyusun konteks shift, posko, Work Order APKUK, reschedule, tim, dan personel; belum ada tabel sesi PASILA, temuan PASILA, atau P0.
3. **Masih shell/tampilan rencana**: P0. Tidak ada tabel, model, API, atau proses simpan P0. Detail P0 selalu menghasilkan 404 dan tombol/form P0 dinonaktifkan.

Temuan lintas modul yang paling penting:

- Sumber asli adalah satu spreadsheet Google berjudul **`MASTER_DATA`** (dibaca live, read-only) dengan 9 tab. Kode membaca **`APKUK_RAW`**, **`GANGGUAN_PUSAT_IMPORT`**, **`KEYPOINT_RAW`**, serta staging **`ASSET_RAW`**, **`VEGETASI_RAW`**, dan **`SECTION_PIC_RAW`**. Tab **`MASTER_POSKO`** (ID resmi PSK001–PSK006) dan **`ASSET_REVIEW_RAW`** (121 aset bermasalah) **belum dibaca kode sama sekali**; `CEK_APKUK` hanya sel hitungan.
- Data live konsisten dengan kontrak kode: header APKUK/Keypoint persis sesuai; seluruh 28 posisi kolom Gangguan yang dibaca kode berisi data yang sesuai maknanya (dua header sumber menyesatkan: `STATUS` berisi sifat SESAAT/PERMANEN dan `SECTION` berisi RECLOSER/GI/LBSM). Status APKUK live hanya `SELESAI`, `BELUM EKSEKUSI`, `KURANG`, atau kosong (26 baris).
- Ketidaksesuaian data lintas sumber yang terbukti: penyulang **`TANGGAMUS`** pada 33 Gangguan tidak cocok dengan master (`TANGGAMUS 2`); posko **`SRIBAWONO`** di `MASTER_POSKO` vs **`SRIBHAWONO`** di `SECTION_PIC_RAW`/database; 458 laporan APKUK ber-section `DUMMY`; 1 laporan APKUK ber-penyulang `DUMMY`; 1 nilai `#REF!` di kolom kategori proteksi Gangguan.
- APKUK dan Keypoint memiliki kontrak header yang ketat. Sinkronisasi Gangguan hanya memvalidasi sebagian header sentinel walaupun membaca banyak kolom berdasarkan posisi; ini merupakan risiko perubahan susunan kolom sumber.
- Belum tersedia master canonical untuk **section** dan **asset**. `section_name` masih teks bebas/snapshot sumber; HARSUS memakai Keypoint sebagai proxy target; ROW memakai baris staging vegetasi.
- Flutter yang tersimpan di repository masih berupa layar login dan Beranda placeholder. Field “mobile” di dokumen ini berarti **kontrak API yang disiapkan untuk aplikasi**, bukan form Flutter yang sudah terimplementasi.
- Peta belum memenuhi desain v3: belum mewajibkan satu penyulang, belum ada asset/gangguan marker yang lengkap, affected-assets, sidebar riwayat/foto/quick-create, bbox/paging, atau clustering.
- Beberapa aturan operasi sudah dikodekan tetapi masih memerlukan pengesahan bisnis, antara lain jam shift, formula PASILA/P0, canonical section/asset, status APKUK sumber, approval HARSUS/ROW, aturan reschedule, special-case threshold, dan kebijakan akun-per-tim.

Audit data ini **tidak mengubah sumber data, Neon production, maupun aturan bisnis**. Perubahan kode pada batch yang sama hanya integrasi PR #11 ke Web Admin (lihat bagian 12); seluruh temuan data di dokumen ini dicatat sebagai rekomendasi untuk diputuskan bersama, bukan diterapkan.

## 2. Metode, batasan, dan cara membaca audit

### 2.1 Bukti yang diperiksa

- Migrasi dan model Laravel untuk bentuk tabel, nullability, key, dan relasi.
- Route, controller, service, request validation, Blade, dan JavaScript untuk field yang benar-benar ditampilkan/diisi.
- Command dan service sinkronisasi untuk kontrak field sumber.
- Test Laravel untuk memeriksa kontrak perilaku; nilai factory/fake **tidak diperlakukan sebagai data asli**.
- Project Flutter yang ada di repository untuk memeriksa apakah form mobile benar-benar telah dibuat.
- Dokumen proyek v3 untuk membandingkan implementasi saat ini dengan kebutuhan bisnis.

### 2.2 Batasan verifikasi

- **Google Sheets `MASTER_DATA` dibaca live** dengan scope `spreadsheets.readonly`: daftar tab, header, keterisian per kolom, dan kosakata kolom kategori. Nilai pribadi (nama petugas/user, URL foto) tidak disalin, hanya dihitung.
- **Neon production dibaca live** dengan role runtime dalam sesi `default_transaction_read_only=on` (server menolak penulisan): daftar tabel, jumlah baris, foreign key, keterisian kolom kunci, dan konsistensi lintas tabel. Tidak ada data production yang dimutasi.
- **Belum terverifikasi live:** log Railway (dibaca dari laporan pengguna dan tabel `apkuk_sync_runs`), isi tabel modul Batch A di production (tabelnya belum dimigrasi ke production), dan aplikasi Flutter (repository hanya berisi login/placeholder).
- Data contoh di lingkungan audit lokal (schema `batcha_audit_*`) berlabel TEST/AUDIT dan **bukan** data operasional.
- File konfigurasi lingkungan hanya diinventarisasi keberadaannya; nilai rahasia tidak disalin ke dokumen ini.
- Label “required” mengikuti validator/service yang berjalan. Atribut HTML saja tidak dianggap otoritatif bila berbeda dari server.

### 2.3 Legenda provenance

| Kode | Arti | Otoritas field |
|---|---|---|
| `SRC-APKUK` | Baris asli tab `APKUK_RAW`; `sumber_input=APPSHEET_LAMA` | Sumber AppSheet lama; policy reconciliation meminta konfirmasi owner SPS BOS; Web Admin read-only |
| `SRC-GANGGUAN` | Baris asli tab `GANGGUAN_PUSAT_IMPORT`; `sumber_data=PLN_PUSAT` | Sumber PLN Pusat; steward/owner spreadsheet tidak dapat dibuktikan dari kode |
| `SPS-KEYPOINT` | Baris asli tab `KEYPOINT_RAW` | SPS BOS; Web Admin read-only |
| `SRC-STAGING` | Tab raw `ASSET_RAW`, `VEGETASI_RAW`, atau `SECTION_PIC_RAW` | Sumber spreadsheet terkonfigurasi; owner tidak dibuktikan kode dan belum otomatis berarti master canonical |
| `MASTER-INT` | Master internal aplikasi | Dibuat oleh sync internal atau Admin sesuai modul |
| `ADMIN` | Input/keputusan Web Admin | Admin Web |
| `SYSTEM` | Dibentuk otomatis dari workflow | Laravel/service/database |
| `MOBILE-API` | Field kontrak API untuk tim lapangan | Klien mobile masa depan; belum membuktikan form Flutter ada |
| `TEST` | Nilai factory/fake/test | Hanya bukti perilaku, bukan sumber bisnis |
| `UNAVAILABLE` | Belum ada tabel/form/API operasional | Tidak boleh diisi dengan asumsi |
| `TBD` | Memerlukan keputusan bisnis/owner data | Tidak boleh dikunci sebagai fakta tanpa persetujuan |

### 2.4 Aturan interpretasi

- “Ada di tabel” berarti ada kolom migration/model; belum tentu tampil pada UI.
- “Ada di form” berarti pengguna dapat mengirim field melalui UI/API; service dapat menambahkan syarat kondisional.
- “Asli” hanya dipakai bila ada kontrak reader/sync yang menunjuk langsung ke sumber.
- Snapshot sumber pada Work Order tetap merupakan salinan internal untuk menjaga histori, bukan baris asli yang dapat diedit.
- Data yang tidak tersedia tidak direkomendasikan untuk “diisi seadanya”; rekomendasi berfokus pada penetapan owner, sumber, dan aturan validasi.

## 3. Kontrak sumber asli dan staging

Connector Google Sheets di repository meminta scope read-only. Command yang diperiksa membaca satu Spreadsheet ID terkonfigurasi dan tidak menyediakan write-back ke tab sumber.

### 3.1 APKUK — `APKUK_RAW!A1:L`

Reader mewajibkan tepat 12 header berikut dan memetakan semuanya ke `apkuks`.

| Header asli | Kolom internal | Tipe/olah | Ketersediaan dan owner |
|---|---|---|---|
| `ID` | `source_id` | teks identitas laporan | `SRC-APKUK`, wajib pada baris valid |
| `Tanggal` | `tanggal` | tanggal wajib dapat diparsing | `SRC-APKUK` |
| `Petugas` | `petugas` | teks | `SRC-APKUK`, dapat kosong sesuai sumber |
| `Penyulang` | `penyulang` | teks dinormalisasi saat pencocokan | `SRC-APKUK` |
| `Section` | `section` | teks | `SRC-APKUK`; bukan relasi canonical section |
| `No. Tiang/Gardu` | `no_tiang` | teks wajib; bagian identitas | `SRC-APKUK` |
| `Naik Dari` | `naik_dari` | teks | `SRC-APKUK` |
| `Terpasang bh` | `terpasang_bh` | integer hasil parsing | `SRC-APKUK` |
| `Kurang bh` | `kurang_bh` | integer hasil parsing | `SRC-APKUK` |
| `Status` | `status` | teks sumber | `SRC-APKUK`; live: `SELESAI` 2.636, `BELUM EKSEKUSI` 934, `KURANG` 125, kosong 26 |
| `Koordinat` | `koordinat` + `latitude`/`longitude` | raw text dan hasil parsing | `SRC-APKUK` |
| `Foto` | `foto` | referensi/teks sumber | `SRC-APKUK`; bukan media upload internal |

Field sistem tambahan: `sumber_input=APPSHEET_LAMA`, `source_row_number`, `last_synced_at`, `legacy_key`, timestamps, dan `source_missing_at`. `legacy_key` dibentuk dari sumber, ID ter-normalisasi, dan tiang. `source_missing_at` hanya boleh dipakai setelah konfirmasi owner sumber melalui proses reconciliation; bukan status yang berasal dari spreadsheet.

Aturan sinkronisasi yang terverifikasi:

- Memvalidasi minimum jumlah baris, tanggal, integer, koordinat, header, serta duplikasi `ID + tiang`.
- Status kandidat yang dikenali kode saat ini: butuh pekerjaan `BELUM EKSEKUSI`/`KURANG`; selesai `SELESAI`. Pemeriksaan live 28 Okt menemukan **hanya** ketiga nilai ini ditambah 26 sel kosong, jadi kosakata kode sudah lengkap untuk data saat ini; pengesahan owner tetap diperlukan agar nilai baru tidak muncul diam-diam.
- State aplikasi: **active** = tidak hilang dari sumber dan tidak punya reconciliation terbuka; **pending** = tidak hilang dari sumber tetapi punya reconciliation terbuka; **history** = `source_missing_at` terisi.
- Model APKUK bersifat read-only; Web Admin tidak menyimpan atau menghapus field asli.

### 3.2 Gangguan — `GANGGUAN_PUSAT_IMPORT!A1:CY`

Command hanya mengambil baris dengan `ULP Koreksi = SRIBAWONO`. Mapping berikut dibaca berdasarkan posisi kolom.

| Posisi | Header yang diverifikasi | Kolom internal | Provenance |
|---:|---|---|---|
| A / 0 | `Penyulang` | `penyulang` | `SRC-GANGGUAN` |
| B / 1 | tidak divalidasi | `peralatan` | `SRC-GANGGUAN`, positional |
| C / 2 | `ULP Koreksi` | `ulp` | `SRC-GANGGUAN` |
| D / 3 | `Tanggal` | `tanggal_kejadian` | `SRC-GANGGUAN` |
| E / 4 | tidak divalidasi | `kode_gangguan` | `SRC-GANGGUAN`, positional |
| F / 5 | tidak divalidasi | `uraian_penyebab` | `SRC-GANGGUAN`, positional |
| G / 6 | tidak divalidasi | `penyebab` | `SRC-GANGGUAN`, positional |
| H / 7 | tidak divalidasi | `lepas` | `SRC-GANGGUAN`, positional |
| I / 8 | tidak divalidasi | `beban_amp` | `SRC-GANGGUAN`, positional |
| J / 9 | tidak divalidasi | `rele_kerja` | `SRC-GANGGUAN`, positional |
| K / 10 | tidak divalidasi | `arus_gangguan_amp` | `SRC-GANGGUAN`, positional |
| L / 11 | tidak divalidasi | `masuk` | `SRC-GANGGUAN`, positional |
| M / 12 | tidak divalidasi | `beban_setelah_amp` | `SRC-GANGGUAN`, positional |
| O / 14 | tidak divalidasi | `lama_padam_menit` | `SRC-GANGGUAN`, positional |
| P / 15 | tidak divalidasi | `ens_kwh` | `SRC-GANGGUAN`, positional |
| Y / 24 | tidak divalidasi | `material_rusak` | `SRC-GANGGUAN`, positional |
| Z / 25 | `No: Transaksi APKT` | `id_gangguan` | `SRC-GANGGUAN` |
| AC / 28 | tidak divalidasi | `sifat_gangguan` | `SRC-GANGGUAN`, positional |
| AF / 31 | tidak divalidasi | `kategori_proteksi_pusat` | `SRC-GANGGUAN`, positional |
| AW / 48 | tidak divalidasi | `tindakan_corrective` | `SRC-GANGGUAN`, positional |
| AX / 49 | tidak divalidasi | `tindakan_preventive` | `SRC-GANGGUAN`, positional |
| AZ / 51 | `NoTi` | `no_tiang` | `SRC-GANGGUAN` |
| CB / 79 | `FOTO CORRECTIVE` | `foto_corrective_url` | `SRC-GANGGUAN` |
| CC / 80 | `FOTO GANGGUAN` | `foto_gangguan_url` | `SRC-GANGGUAN` |
| CI / 86 | tidak divalidasi | `keterangan_temuan` | `SRC-GANGGUAN`, positional |
| CN / 91 | `HEWAN` | `jenis_hewan` | `SRC-GANGGUAN` |
| CS / 96 | `ENS APKT` | `ens_apkt_kwh` | `SRC-GANGGUAN` |
| CU / 98 | `KEYPOINT TERGANGGU` | `keypoint_pusat` | `SRC-GANGGUAN` |

Field sistem: `sumber_data=PLN_PUSAT`, `last_synced_at`, timestamps. Tabel aktif tidak memiliki latitude/longitude dan tidak memiliki foreign key ke Keypoint/asset. Akibatnya, gangguan dapat ditampilkan sebagai daftar/agregat tetapi belum dapat menjadi marker atau membuktikan asset terdampak.

**Verifikasi live 28 Okt:** ke-28 posisi di atas dicocokkan dengan header asli tab `GANGGUAN_PUSAT_IMPORT` (494 baris, semua `ULP Koreksi = SRIBAWONO`). Semua berisi data yang sesuai makna kolom internal, dengan catatan:

| Posisi | Header asli | Isi live | Kolom internal | Catatan |
|---|---|---|---|---|
| AC / 28 | `STATUS` | `SESAAT` 456, `PERMANEN` 38 | `sifat_gangguan` | Makna benar, nama header menyesatkan |
| AF / 31 | `SECTION` | `RECLOSER` 389, `GI` 95, `LBSM` 9, `#REF! ()` 1 | `kategori_proteksi_pusat` | Makna benar; header menyesatkan; 1 nilai rusak formula |
| Z / 25 | `No: Transaksi APKT` | terisi 99,6% | `id_gangguan` | 2 baris tanpa nomor dilewati sync → database 492 vs sumber 494 |
| Y / 24 | `MATERIAL YANG RUSAK` | terisi 4,7% | `material_rusak` | Field jarang diisi di sumber |
| CN / 91 | `HEWAN` | terisi 54,7% | `jenis_hewan` | Penyebab hewan 269 dari 494 |

Sumber Gangguan **tidak memiliki kolom koordinat** (dicek seluruh 90 header), sehingga marker Gangguan memang belum dapat dibuat dari sumber ini; `NoTi` (tiang, terisi 88,7%) adalah kandidat penghubung ke aset.

**Risiko utama:** reader mengonsumsi banyak kolom positional yang header-nya tidak divalidasi. Penyisipan/pergeseran kolom pada spreadsheet dapat menggeser arti data tanpa terdeteksi. Rekomendasi: tetapkan header canonical untuk setiap kolom yang dipakai dan validasi seluruhnya sebelum mengubah mapping.

Banyak field Gangguan sudah tersimpan—misalnya URL foto, tindakan preventive, material rusak, ENS, beban, relay, arus, dan teks keypoint—tetapi Map dan detail Special Case hanya menampilkan subset. Field tersimpan tidak otomatis tersedia bagi operator atau reviewer.

### 3.3 Keypoint — `KEYPOINT_RAW!A1:E`

| Header asli | Kolom internal | Aturan | Provenance |
|---|---|---|---|
| `PENYULANG` | `penyulang` | identitas, wajib | `SPS-KEYPOINT` |
| `JENIS` | `jenis` | identitas, wajib | `SPS-KEYPOINT` |
| `NAMA` | `nama` | identitas, wajib | `SPS-KEYPOINT` |
| `KOORDINAT` | `koordinat_raw` + `latitude`/`longitude` | koordinat wajib valid | `SPS-KEYPOINT` |
| `NOTE` | `note` | opsional | `SPS-KEYPOINT` |

Field sistem: `legacy_key` (hash penyulang+jenis+nama), `sumber_input=SPS_BOS`, `source_row_number`, `last_synced_at`, timestamps. Model bersifat read-only. Sinkronisasi memvalidasi semua header, identitas, koordinat, dan minimum jumlah baris.

### 3.4 Raw/staging lainnya

| Tab | Header exact yang dibaca | Penggunaan saat ini | Status sumber |
|---|---|---|---|
| `ASSET_RAW` | `ID_ASET`, `NAMA_ASET`, `JENIS_ASET`, `PENYULANG`, `LATITUDE`, `LONGITUDE`, `STATUS`, `Foto`, `Section`, `Posko` | Disimpan ke `asset_source_rows`; belum menjadi master asset canonical. `Section`/`Posko` mentah bukan referensi kepemilikan | `SRC-STAGING` |
| `VEGETASI_RAW` | `NO`, `NAMA POHON`, `UP3`, `ULP`, `PENYULANG`, `ID JTM`, `ID TIANG`, `NAMA TIANG`, `JENIS POHON`, `PERTUMBUHAN POHON`, `JARAK AMAN`, `JARAK BAHAYA`, `KONDISI`, `SECTION`, `EAM`, `LATITUDE`, `LONGITUDE`, `JARAK POHON`, `JARAK POHON SAAT INI`, `IZIN PADAM`, `IZIN TEBANG BAWAH`, `TANGGAL UPDATE`, `TANGGAL PERIKSA`, `TANGGAL PERKIRAAN BAHAYA`, `USER PEMBUAT`, `USER UPDATE`, `KETERANGAN`, `ID POHON` | Disimpan sebagai teks raw; menjadi kandidat target ROW | `SRC-STAGING`, belum canonical/tervalidasi penuh |
| `SECTION_PIC_RAW` | `PENYULANG`, `PEMUTUS`, `POSKO PIC` | Menyimpan row non-kosong, null cell, dan nomor baris; row sepenuhnya kosong dilewati; sinkronisasi master melakukan derived fill-down | `SRC-STAGING` |

Ada tabel staging lama untuk Keypoint, tetapi command Keypoint saat ini menulis langsung ke `keypoints`. Jangan memakai staging lama itu sebagai bukti sumber aktif tanpa pemeriksaan tambahan.

Command profiling mengenal nama `GANGGUAN_RAW`, tetapi pipeline Gangguan operasional menulis `gangguans` dari `GANGGUAN_PUSAT_IMPORT`; tabel staging Gangguan lama sudah diganti. Selain itu, deskripsi `sources:import` masih menyebut `KEYPOINT_RAW`, sementara daftar source yang benar-benar diimport hanya Asset, Vegetasi, dan Section/PIC. Dua label command tersebut adalah metadata/copy yang stale, bukan bukti writer aktif.

### 3.5 `MASTER_DATA`, Neon, dan artefak lokal

- `MASTER_DATA` adalah **judul spreadsheet** yang dikonfigurasi (bukan nama tab). Semua tab di bagian 3.1–3.4 berada di spreadsheet ini; inventaris lengkapnya di bagian 3.7.
- Neon production diperiksa live secara read-only (bagian 3.8). Skema per kolom tetap mengikuti migration dan model repository.
- Tidak ada snapshot lokal spreadsheet; seluruh angka live di dokumen ini berasal dari pembacaan langsung pada 28 Okt 2026 dan dapat berubah karena SPS BOS adalah sumber live.

### 3.6 Semantik refresh dan cadence

| Pipeline | Perilaku refresh terverifikasi | Jadwal yang dikonfigurasi di repository |
|---|---|---|
| APKUK | Snapshot tervalidasi; insert/update/return; identitas hilang masuk reconciliation dan tidak langsung diarsipkan | menit 5/20/35/50; juga ada di `railway-sync.sh` |
| Gangguan | Upsert berdasarkan `id_gangguan`; ID yang absen dari snapshot terbaru **tidak** dihapus/diarsipkan | setiap 15 menit; juga ada di `railway-sync.sh` |
| Keypoint | Upsert berdasarkan identity hash; sinkronisasi dibatalkan bila identitas lama hilang | menit 10/25/40/55; juga ada di `railway-sync.sh` |
| Raw staging | Default append; `--replace` menghapus lalu insert ulang | tidak ditemukan dalam scheduler atau `railway-sync.sh` |
| Core topology master | Menurunkan master/pivot dari staging Section/PIC | tidak ditemukan dalam scheduler atau `railway-sync.sh` |

Jadwal di atas adalah konfigurasi kode, bukan bukti scheduler production sedang berjalan.

Nuansa kualitas Gangguan: row dengan ID/tanggal tidak valid dilewati; numeric tidak valid disimpan `NULL`; raw value yang gagal dan nomor baris sumber tidak disimpan. Akibatnya database tidak dapat membedakan angka kosong dari parse failure dan dapat mempertahankan ID lama yang sudah tidak ada pada snapshot sumber. Ini harus terlihat pada monitoring/data-quality report.

Nuansa staging: tanpa `--replace`, import berulang dapat menambah duplikasi. Dengan `--replace`, surrogate `id` berubah. Staging mempertahankan null cell dan `source_row_number` untuk row non-kosong, tetapi melewati row data yang seluruhnya kosong; karena itu bukan byte-for-byte snapshot spreadsheet.

### 3.7 Hasil verifikasi live spreadsheet `MASTER_DATA` (read-only, 28 Okt 2026)

| Tab | Baris data | Header | Dibaca kode? | Catatan bukti |
|---|---:|---:|---|---|
| `APKUK_RAW` | 3.721 | 12 | Ya — `apkuk:sync` (cron) | Header persis sesuai kontrak |
| `GANGGUAN_PUSAT_IMPORT` | 494 | 90 (grid 109 kolom) | Ya — `gangguan:sync` (cron), 28 posisi | Lihat tabel verifikasi posisi di 3.2 |
| `KEYPOINT_RAW` | 129 | 5 | Ya — `keypoint:sync` (cron) | `NOTE` 0% terisi |
| `ASSET_RAW` | 17.059 | 10 | Ya — `sources:import` (manual, 10 kolom) | Header `Foto`, `Section`, `Posko` dibaca sebagai nilai mentah; saat ini 0% terisi |
| `VEGETASI_RAW` | 16.983 | 28 | Ya — `sources:import` (manual) | `SECTION`, `EAM`, `IZIN TEBANG BAWAH` 0% terisi; `IZIN PADAM` seluruhnya `TIDAK`; `ID POHON` 100% terisi |
| `SECTION_PIC_RAW` | 69 | 3 | Ya — `sources:import` + `topology:sync` | `PENYULANG` 23% (pola fill-down), `PEMUTUS` 100%, `POSKO PIC` 97% |
| `MASTER_POSKO` | 6 | 3 (`POSKO_ID`, `POSKO`, `STATUS`) | **Tidak** | ID resmi PSK001–PSK006, semua `AKTIF` |
| `ASSET_REVIEW_RAW` | 121 | 8 | **Tidak** | 108 nama aset ganda koordinat + lintas penyulang; 12 ganda koordinat; 1 sudah ada di peta lama |
| `CEK_APKUK` | 0 | 1 | Tidak | Hanya sel hitungan (`3721`) |

**Keterisian kolom APKUK_RAW (3.721 baris):** ID, Tanggal, Penyulang, No. Tiang/Gardu, Terpasang bh 100%; Status 99,3%; Koordinat 98,7%; Kurang bh 98,6%; Petugas 95,6%; Section 95,6% (tetapi `DUMMY` 458 baris); Foto 67,5%; Naik Dari 66,6%.

**Kosakata yang diverifikasi:**

| Sumber | Kolom | Nilai live |
|---|---|---|
| APKUK_RAW | Penyulang | 16 penyulang master + `CAKALELE.` 47 dan `KECAK.` 4 (titik di akhir, dinormalisasi kode) + `DUMMY` 1 |
| APKUK_RAW | Naik Dari | `JTR` 1.429, kosong 1.243, `TIANG` 519, `KABEL SR` 258, `SKUR` 245, `KABEL OPTIK` 27 |
| APKUK_RAW | Section | 55 nilai berbeda; teratas `DUMMY` 458, `REC BRAJA DEWA` 359, kosong 165 |
| KEYPOINT_RAW | JENIS | `FCO` 86, `LBSM` 24, `RECLOSER` 19 |
| ASSET_RAW | JENIS_ASET / STATUS | `TIANG_TM` 16.052, `GARDU` 1.007 / semua `VALID` |
| VEGETASI_RAW | KONDISI | `BAIK` 9.491, `CUKUP` 5.444, `KURANG` 1.668, `BURUK` 380 |
| GANGGUAN | Penyulang | 16 nilai; `TANGGAMUS` 33 (bukan `TANGGAMUS 2`) |
| GANGGUAN | Penyebab | `BINATANG` 269, `PETIR` 61, kosong 50, `ROW-POHON` 23, `MATERIAL` 22, lainnya |

### 3.8 Hasil verifikasi live Neon production (read-only, 28 Okt 2026)

| Kelompok | Tabel (jumlah baris) |
|---|---|
| Sumber tersinkron | `apkuks` 4.515, `gangguans` 492, `keypoints` 129 |
| Staging | `asset_source_rows` 17.059, `vegetasi_source_rows` 16.983, `section_pic_source_rows` 69, `keypoint_source_rows` 129 (lama) |
| Master | `poskos` 6, `penyulangs` 16, `posko_penyulang` 26, `field_teams` 0, `field_personnel` 0, `field_team_shift_assignments` 0, `reference_codes` 0 |
| APKUK resilient sync | `apkuk_sync_runs` 16 (semua `SUCCESS_WITH_PENDING_RECONCILIATION`, terakhir 15.45 WIB), `apkuk_reconciliation_items` 78 (`MISSING`/`OPEN`), `apkuk_reconciliations` 1 |
| Work Order (fondasi) | `work_orders`, target, laporan, media, reschedule, status log: semuanya 0 |
| Akun | `users` 1 (ADMIN aktif) |
| Batch A | Tabel `audit_logs`, `inspections`, `work_order_location_targets`, `work_escalations`, `work_order_issues`, `personal_access_tokens` **belum ada** di production (migration Batch A belum dijalankan) |

Total 35 foreign key. Status APKUK: **aktif 3.721, pending 78, histori 716**.

**Konsistensi lintas sumber (bukti live):**

| Temuan | Bukti | Dampak |
|---|---|---|
| Penyulang Gangguan `TANGGAMUS` tidak ada di master | 33 baris `gangguans` | Agregat Gangguan per penyulang tidak tersambung ke `TANGGAMUS 2` di APKUK/Keypoint/Asset |
| Nama posko berbeda antar tab | `MASTER_POSKO`: `SRIBAWONO`; `SECTION_PIC_RAW` dan `poskos`: `SRIBHAWONO` | Risiko master posko ganda bila `MASTER_POSKO` kelak dibaca |
| ID posko resmi tidak tersimpan | `poskos.kode_posko` = nama posko; PSK001–PSK006 hanya ada di sumber | Kode posko di Web Admin bukan kode resmi |
| Section APKUK tidak andal | 676 laporan aktif/pending ber-section kosong/`-`/`DUMMY` | Section tidak bisa dipakai sebagai kunci routing/filter |
| Laporan APKUK `DUMMY` | 1 penyulang `DUMMY` di laporan aktif | Data uji di sumber produksi |
| Kolom sumber selalu kosong | Keypoint `NOTE`, Asset `Foto`/`Section`/`Posko`, Vegetasi `SECTION`/`EAM`/`IZIN TEBANG BAWAH` | Field tersebut belum bisa menjadi sumber field Web Admin |

## 4. Master internal dan relasi lintas domain

### 4.1 Posko dan penyulang

Sinkronisasi master menggunakan whitelist internal berikut:

- Posko: SRIBHAWONO, PUGUNG, JABUNG, PASIR SAKTI, WAY JEPARA, TRIDATU.
- Penyulang: BAUNG 2, BEDANA, CAKALELE, GAMBYONG, JAIPONG, JATILAN, KECAK, KUNINGAN 2, NANAS, PAYUNG, REOG, RONGGENG, SAMAN, TANGGAMUS 2, TOR TOR, ZAPIN.
- Alias yang dikodekan: `TANGGAMUS` menjadi `TANGGAMUS 2`.

`SECTION_PIC_RAW` dipakai untuk membangun pivot banyak-ke-banyak posko-penyulang, setelah validasi terhadap whitelist. Field `gardu_induk` tersedia di `penyulangs`, tetapi sinkronisasi saat ini tidak mengisinya; provenance field tersebut belum ditetapkan.

### 4.2 Tim, personel, dan akun

| Entitas | Field utama | Owner | Relasi penting |
|---|---|---|---|
| `field_teams` | code, name, role, posko, active | `ADMIN`/`MASTER-INT` | Tim POSKO wajib terkait posko; HARSUS/INSPEKSI/ROW tidak harus |
| `field_personnel` | team, personnel_code, name, active | `ADMIN` | Banyak personel per tim; berbeda dari akun login |
| `users` | name, username, email, role, team, active, password | `ADMIN` | Akun field terkait satu tim; belum ada batas satu akun per tim |
| `field_team_shift_assignments` | team, shift, effective_from/until | `ADMIN` | Penjadwalan tim POSKO |
| `reference_codes` | category, code, label, role_scope, sort_order, active | `ADMIN` | Kode alasan/temuan/operasional |

Pemisahan penting: **akun tim bukan personel**. Laporan menyimpan submitting user/team serta snapshot daftar personel yang benar-benar bekerja.

### 4.3 Relasi pekerjaan dan bukti

```text
Work Order
├─ target APKUK snapshot (opsional; khusus APKUK)
├─ status logs (append-only)
├─ reschedule requests
├─ reports
│  ├─ personnel snapshots
│  └─ media before/after/evidence
├─ escalations → child Work Order
└─ issues

Inspection Visit
├─ personnel snapshots
├─ findings
│  └─ media
└─ finding approval → optional HARSUS/ROW Work Order
```

`section_name` pada Work Order, Inspection, dan snapshot target adalah teks, bukan foreign key. Ini menjaga nilai historis tetapi tidak menyelesaikan kebutuhan master section canonical.

Route Web Admin yang diaudit berada di kelompok autentikasi/Admin. API lapangan menggunakan token dan pemeriksaan role/team per action. Pengamanan route tidak mengubah provenance: Admin tetap tidak menjadi owner field SPS BOS hanya karena dapat melihatnya.

## 5. Audit per modul

### 5.0 Matriks ringkas asal data per menu

Kode asal: **SPS** = spreadsheet `MASTER_DATA` (APKUK_RAW/KEYPOINT_RAW dari SPS BOS, GANGGUAN_PUSAT_IMPORT dari PLN pusat, staging aset/vegetasi/section); **MASTER** = master internal; **ADMIN** = dibuat/diputuskan di Web Admin; **FLUTTER** = kontrak API yang kelak diisi aplikasi lapangan (aplikasi belum tersedia); **SISTEM** = dihitung otomatis; **UJI** = hanya data contoh di lingkungan audit lokal.

| Menu | Data asli (SPS) | Master internal | Dibuat Web Admin | Diisi Flutter (API) | Otomatis (SISTEM) | Belum ada sumber / perlu keputusan |
|---|---|---|---|---|---|---|
| Dashboard | APKUK aktif/pending/histori, Gangguan 7/30 hari | jumlah posko/penyulang | — | (laporan WO/inspeksi masuk ke KPI) | seluruh KPI, status sync terakhir | KPI P0/PASILA; target 6 WO/posko/shift dan jam shift dikodekan |
| P0 | — | — | — | — | — | **Seluruh domain** (definisi, tabel, form) |
| Work Order | snapshot target APKUK; target HARSUS dari Keypoint; target ROW dari staging Vegetasi | posko, penyulang, tim, petugas, kode referensi | buat/ubah/batal, laporan atas nama tim, keputusan review | laporan, foto BEFORE/AFTER, GPS, reschedule, limpahan, kendala | kode WO, due, status log, sisa jumlah | section canonical; aset canonical; aturan due/approval per jenis |
| APKUK | seluruh 12 kolom APKUK_RAW (read-only) | penyulang (pencocokan) | WO dari kandidat | jumlah terpasang lewat laporan WO | state aktif/pending/histori, kelayakan kandidat | section `DUMMY`; 26 status kosong; tampilkan petugas/naik dari/foto? |
| Rekonsiliasi APKUK | selisih APKUK_RAW vs database | — | arsip (wajib konfirmasi pemilik data), konfirmasi identitas, simpan sebagai baru | — | antrean, muncul kembali otomatis, riwayat sync | format bukti konfirmasi pemilik data; 78 pending saat ini |
| PASILA | — | posko, tim, jadwal shift | — (tampilan agregat) | — | ringkasan WO APKUK per posko/shift | **Domain sesi/temuan PASILA dan formula target** |
| Inspeksi | Keypoint (referensi opsional) | penyulang, tim inspeksi, petugas, kode temuan | kunjungan, temuan, validasi, WO tindak lanjut | kunjungan, temuan, foto, GPS | status temuan, WO tindak lanjut | section teks bebas; bukti minimum Web vs mobile |
| ROW | staging VEGETASI_RAW (16.983 titik; `ID POHON` 100%) | tim ROW | WO ROW manual | laporan pekerjaan | duplikasi target aktif dicegah | generator/formula risiko; kunci stabil titik (kandidat `ID POHON`) |
| HARSUS | KEYPOINT_RAW (129) sebagai target | tim HARSUS | WO HARSUS manual | laporan pekerjaan | duplikasi target aktif dicegah | aset canonical (ASSET_RAW 17.059 + 121 review belum dipakai) |
| Peta Monitoring | koordinat Keypoint (100%) dan APKUK (98%) | penyulang | — | — | status APKUK aktif/pending terpisah | Gangguan tanpa koordinat di sumber; ASSET_RAW belum ditampilkan |
| Review & Approval | — | — | ACC/Tolak laporan, reschedule, limpahan, temuan | pengajuan yang direview | antrean dan status | matriks approval per role/jenis |
| Special Case | Gangguan | — | tindak lanjut kendala | kendala WO | WO terlambat | ambang/severity special case |
| Master Data | Keypoint; posko & penyulang diturunkan dari SECTION_PIC_RAW | posko, penyulang, tim, petugas, kode, jadwal shift | CRUD tim/petugas/kode/jadwal | — | statistik sumber | `MASTER_POSKO` (ID PSK) belum dibaca; nama `SRIBAWONO`/`SRIBHAWONO`; alias `TANGGAMUS` |
| Analytics | status APKUK aktif (pending/histori dikecualikan) | penyulang, posko | filter, ekspor CSV | (data WO dari lapangan) | agregat periode | definisi "selesai" dan cakupan periode KPI |
| Users & Access | — | tim (untuk akun lapangan) | akun Admin/lapangan, aktif/nonaktif, reset sandi | login/token mobile | pencabutan token saat reset | kebijakan akun per tim/perangkat |
| Audit Logs | — | — | — (hanya baca) | — | jejak WO dan jejak umum, termasuk keputusan APKUK (`Diarsipkan`) | — |
| Pengaturan Akun | — | — | ubah username/kata sandi sendiri | — | — | — |

Di production saat ini hanya data **SPS**, master posko/penyulang, dan satu akun Admin yang berisi. Seluruh data operasional Batch A (tim, petugas, WO, inspeksi, audit log) **belum ada di production** karena migration Batch A belum dijalankan. Data yang tampak di lingkungan audit lokal adalah **UJI**.


## 5.1 Dashboard

### Field/indikator yang tersedia

| Area | Field yang tampil/dihitung | Asal | Sifat |
|---|---|---|---|
| Filter | period `today`/7/30 hari | query Admin | opsional; default aplikasi |
| Konteks | waktu update, rentang tanggal, shift saat ini, jumlah posko/penyulang aktif | `SYSTEM` + master | otomatis |
| KPI WO | total all-time, active all-time, belum, dikerjakan, menunggu, selesai dalam periode, dibuat dalam periode, overdue | Work Order | otomatis; perhatikan campuran all-time dan periodik |
| Status | agregat per status | Work Order | otomatis |
| Tim | active, waiting, overdue, completed period per tim | Work Order/team | otomatis |
| Posko/shift | target APKUK 6, total, completed, active, overdue, jumlah tim | Work Order/target | otomatis; target 6 dikodekan |
| Penyulang | active Work Order per penyulang | Work Order | otomatis |
| Tren | dibuat dan selesai 14 hari | Work Order | otomatis |
| APKUK | active/pending/history, last sync, sync status, active WO, jumlah installed period | APKUK + WO | otomatis |
| Gangguan | jumlah 7/30 hari, top 5 penyulang, last sync | Gangguan | otomatis |
| Inspeksi | kunjungan/temuan hari ini | Inspection | otomatis |
| Review queue | report, reschedule, escalation, finding, issue tertunda | workflow internal | otomatis |
| Aktivitas | status log WO terbaru | status log | otomatis |

### Ketersediaan sumber dan gap

- Dashboard tidak menciptakan field bisnis baru; seluruhnya agregasi internal/sumber sync.
- Target enam APKUK per posko/shift berasal dari aturan aplikasi v3, bukan field spreadsheet.
- Jam shift saat ini dikodekan A 08:00–16:00, B 16:00–00:00, C 00:00–08:00 WIB. Ini harus disahkan owner bisnis atau dipindahkan ke konfigurasi/master.
- KPI “total/active” bercakupan all-time sedangkan beberapa status bercakupan periode. Label UI sebaiknya menampilkan scope agar tidak dibaca sebagai satu denominator yang sama.
- Pada baris posko/shift, `overdue` menghitung seluruh WO overdue di posko (bukan hanya APKUK pada shift berjalan), dan `teams` menghitung seluruh tim POSKO termasuk yang nonaktif. Label/query perlu diselaraskan agar konteks tidak menyesatkan.
- Definisi selesai belum seragam: status group memasukkan `SELESAI` dan `SELESAI_DITERUSKAN`, tetapi completed per tim dan completed pada baris posko hanya menghitung exact `SELESAI`. Analytics juga memiliki agregasinya sendiri. Sahkan satu definisi atau beri label per metrik.
- P0 dan sesi PASILA tidak memiliki KPI asli karena domainnya belum tersedia; jangan menginterpretasikan nol sebagai nihil kejadian lapangan.

## 5.2 P0

### Status implementasi

P0 adalah `UNAVAILABLE` sebagai domain operasional. Tidak ada tabel/model/migration/API/form simpan. Controller detail selalu 404. Data service hanya menghasilkan nol/koleksi kosong dan tombol input dinonaktifkan.

### Field yang hanya merupakan rancangan tampilan

| Tampilan shell | Field/label | Status provenance |
|---|---|---|
| Daftar | label `PO`/`PO AKTIF`, sumber, asset/lokasi, penyulang, konteks posko, status, pembaruan | `UNAVAILABLE`; label `PO` tampak sebagai typo UI untuk P0, bukan field terverifikasi |
| Detail | ID, source, description, asset/location, feeder/section, posko, coordinates | `UNAVAILABLE`; proposal UI |
| Detail konteks | power outage, material/K3, alasan reschedule/target | `UNAVAILABLE`; proposal UI |
| Detail workflow | status, pelaksana, timestamp, personel, foto, history | `UNAVAILABLE`; proposal UI |

### Keputusan dan rekomendasi

- Tetapkan definisi P0, owner data, event pemicu, primary key, status, SLA, kebutuhan padam, material/K3, bukti, dan relasi ke PASILA/WO sebelum membuat schema.
- Putuskan apakah P0 merupakan Work Order type, incident terpisah, atau finding PASILA. Jangan membuat ketiganya sebagai salinan tanpa source-of-truth.
- Nilai kosong saat ini harus dilabeli “belum tersedia”, bukan nol operasional.

## 5.3 Work Order

### Field inti yang tersimpan

| Kelompok | Field | Asal/pengisi | Wajib/opsional |
|---|---|---|---|
| Identitas | `code`, `team_type`, `work_type`, `source`, `status` | `SYSTEM`; pilihan workflow memengaruhi type | code/status otomatis; type/source sesuai creator |
| Penugasan | `posko_id`, `field_team_id`, `penyulang_id`, `section_name` | Admin/generator/escalation | kondisional; section teks opsional |
| Jadwal | `work_date`, `shift_code`, `scheduled_start_at`, `due_at` | Admin/system | form memakai nama input `due_date`; tergantung flow, lihat mismatch di bawah |
| Hierarki | `parent_work_order_id` | `SYSTEM` saat escalation/follow-up | opsional |
| Catatan | `notes` | Admin/API | opsional |
| Audit status | creator, started/completed/cancelled time, cancel actor/reason | `SYSTEM` + alasan Admin/API | otomatis/kondisional |

Target APKUK menyimpan snapshot: APKUK ID, target key aktif, no tiang, penyulang/section/naik dari sumber, tanggal/status/jumlah sumber, koordinat, target quantity, installed, dan remaining. Snapshot menjaga histori walaupun row sumber kemudian berubah/hilang.

### Daftar, create, dan edit Web Admin

| Layar | Field tampil/form | Provenance dan validasi |
|---|---|---|
| Daftar APKUK | kode WO, posko, tiang/section, penyulang, target, jadwal, status, update | Gabungan WO + snapshot APKUK |
| Filter | q, status, posko, shift, work date | Query Admin, opsional |
| Create APKUK | kandidat APKUK, posko, work date, shift, notes | Kandidat/read-only `SRC-APKUK`; posko/date/shift dari Admin; date tidak boleh sebelum hari ini |
| Kandidat | tiang, penyulang/section, kurang, status, tanggal, eligibility | `SRC-APKUK` + kalkulasi `SYSTEM` |
| Edit assignment | work date, shift, due date, team, section, notes, reason | `ADMIN`; reason wajib |
| Cancel | reason | `ADMIN`, wajib |

**Mismatch:** view edit menandai shift dan due date sebagai required, tetapi validator server mengizinkan keduanya null. Tentukan satu kontrak dan samakan UI, request validation, serta business rule.

### Laporan hasil

| Field | Web Admin | Mobile API | Owner/aturan |
|---|---|---|---|
| report type | wajib | wajib | daftar tipe internal |
| occurred at | wajib | opsional; service dapat memakai waktu penerimaan | Admin/tim lapangan + `SYSTEM` fallback |
| installed/remaining | `installed_quantity` kondisional; remaining otomatis | `installed_quantity` kondisional; remaining otomatis | wajib sesuai penyelesaian APKUK; service menghitung `remaining_quantity` |
| notes | opsional | opsional | submitter |
| latitude/longitude | opsional | wajib | Admin atau device; GPS mobile wajib |
| GPS accuracy | opsional | opsional | device/Admin |
| personnel | minimal satu melalui ID master atau nama manual | minimal satu | service, bukan sekadar HTML |
| photos | before+after untuk penyelesaian; minimal evidence untuk already-complete | aturan sama di service | media internal; bukan foto sumber APKUK |
| client request ID | tidak digunakan | wajib | idempotency mobile |

Nama personel manual merupakan fallback. Karena bukan foreign key master, penggunaannya perlu dipantau dan direkonsiliasi agar master personel tidak terus tertinggal.

### Reschedule, escalation, issue, dan histori

| Subflow | Field utama | Owner |
|---|---|---|
| Reschedule | reason code, note, tanggal/shift asal, tanggal/shift usulan, status, reviewer/time/note, client request | tim/mobile + reviewer Admin + `SYSTEM` |
| Escalation | target team role, target work type, reason, note, status/reviewer; child WO | submitter/reviewer + `SYSTEM` |
| Issue | issue type, description, reporter, resolution action/note | tim/Admin |
| Status log | action, from/to status, actor user/team, reason, payload, occurred time | `SYSTEM`, append-only |

Untuk mobile, reschedule mewajibkan `client_request_id`, reason code, note, dan proposed work date; proposed shift diwajibkan service bila WO asal memakai shift. Escalation mewajibkan client request ID, target team/work type, reason code, dan note. Issue mewajibkan client request ID, type, dan description. Jalur Admin untuk escalation/issue tidak mengirim client request ID.

### Relasi, gap, dan keputusan

- Section belum canonical dan dapat berbeda ejaan antara sumber, Admin, Inspection, dan Work Order.
- HARSUS manual memakai Keypoint sebagai proxy target; ROW memakai baris vegetasi staging. Belum ada target asset generik yang canonical.
- Kebijakan bahwa completion HARSUS/ROW memerlukan approval, reschedule memerlukan approval, dan rejection mengembalikan WO ke status tertentu sudah dikodekan tetapi masih harus disahkan bisnis.
- Suggested team dari finding dapat bernilai POSKO, sedangkan follow-up finding hanya membuat HARSUS/ROW; kontrak ini harus diselaraskan.
- Foto, GPS, personel, dan immutable history sudah memiliki struktur, tetapi form Admin lebih longgar untuk GPS daripada API mobile. Pastikan ini memang pengecualian operasional yang disetujui.
- Admin mengonversi `occurred_at` dari WIB secara eksplisit, sedangkan API mobile menerima nilai opsional dan service mem-parse tanpa timezone WIB eksplisit. Wajibkan ISO 8601 ber-offset pada mobile atau samakan konversi agar waktu lokal tidak bergeser diam-diam.

## 5.4 APKUK

### Field yang tersedia pada domain dan layar

| Area | Field | Asal | Catatan |
|---|---|---|---|
| Tabel utama | source ID, tanggal, petugas, penyulang, section, no tiang, naik dari, terpasang, kurang, status, koordinat, foto | `SRC-APKUK` | read-only; seluruh kontrak sumber tersimpan |
| Sinkronisasi | sumber input, nomor baris, last synced, legacy key | `SYSTEM` | otomatis |
| State | source missing, reconciliation terbuka | `SYSTEM` + keputusan Admin | membentuk active/pending/history |
| Daftar Web | tiang, ID laporan, tanggal, penyulang/section, terpasang/kurang, status, eligibility, reconciliation/archive | sumber + kalkulasi internal | petugas, naik dari, koordinat raw, dan foto belum tampil di tabel utama |
| Progress | WO, target, posko, quantity, status | WO + target snapshot | internal, bukan perubahan ke row sumber |
| Filter | q, penyulang, status/state | query Admin | opsional |
| Ringkasan | active, pending, history, last sync, eligible, need bh, active WO, installed, completed WO | sumber + WO | otomatis |

### Eligibility kandidat

Service memilih record terbaru untuk kombinasi penyulang ter-normalisasi + tiang, kemudian memeriksa status, koordinat/section, duplikasi latest, Work Order aktif, dan reconciliation. State sumber dan eligibility WO adalah dua konsep berbeda: record dapat masih berasal dari spreadsheet tetapi tidak eligible karena pending/duplikat/sedang dikerjakan.

### Gap dan rekomendasi

- Konfirmasi vocabulary status sumber dengan owner data. Status di kode saat ini bukan pengganti audit nilai live.
- Tentukan apakah `petugas`, `naik_dari`, `foto`, dan koordinat raw perlu terlihat pada detail Admin. Field tersebut sudah tersedia dari sumber, jadi menampilkannya tidak memerlukan penciptaan data baru.
- Pertahankan APKUK read-only. Koreksi field asli harus dilakukan owner sumber, sedangkan Admin hanya mengatur workflow internal dan reconciliation.
- Bedakan visual active, pending, dan history secara konsisten di Dashboard, Master Data, APKUK, peta, dan export.

## 5.5 PASILA

### Field yang benar-benar tersedia

PASILA belum memiliki tabel sesi atau finding sendiri. Halaman saat ini adalah agregat atas data berikut.

| Area | Field tampil | Asal |
|---|---|---|
| Filter/konteks | work date, shift, posko | Admin + `SYSTEM` |
| Posko | code/name/status shift, penyulang terkait | master internal |
| APKUK | realisasi/target WO APKUK per posko/shift | Work Order; target aplikasi 6 |
| Reschedule | jumlah/status permintaan | Work Order reschedules |
| Tim | roster tim, jumlah personel | team/personnel master |
| Detail komponen | WO code/status/target, feeder links, reschedule reason/from/to/status | domain terkait |
| PASILA/P0 | label tidak tersedia | `UNAVAILABLE` |

### Field yang belum tersedia

- ID dan waktu sesi PASILA.
- petugas pelaksana/penanggung jawab sesi sebagai snapshot.
- checklist/aktivitas PASILA.
- finding PASILA, severity, lokasi/asset, koordinat, foto, tindakan, dan status.
- hubungan eksplisit PASILA finding → P0/WO.
- generator/formula target selain target APKUK yang sudah ada.

### Keputusan dan rekomendasi

- Tetapkan apakah PASILA adalah sesi per posko+shift, checklist, dashboard operasi, atau parent event. Schema harus mengikuti definisi itu.
- Tetapkan formula target dan hubungan satu-ke-banyak ke finding/P0. Angka agregat WO tidak boleh diberi label sebagai hasil PASILA tanpa event sumber.
- Realisasi APKUK PASILA saat ini menghitung exact status `SELESAI`; `SELESAI_DITERUSKAN` tidak masuk. Putuskan apakah ini memang definisi realisasi yang benar.
- Roster detail PASILA hanya memfilter `posko_id`; tidak membatasi role POSKO atau `is_active`. Tim non-POSKO yang kebetulan terkait posko dan tim nonaktif dapat ikut tampil, berbeda dari semantics Dashboard. Selaraskan definisi roster.
- Canonical section diperlukan bila PASILA akan membagi pekerjaan per section.

## 5.6 Inspeksi

### Field kunjungan

| Field | Web Admin | Mobile API | Provenance/aturan |
|---|---|---|---|
| team | wajib dipilih | tidak dikirim sebagai field; diinjeksi dari akun terautentikasi | master/internal |
| penyulang | wajib | wajib | master penyulang |
| section | opsional, teks | opsional | belum canonical |
| visited at | wajib | wajib | Admin/device |
| has finding/result | otomatis dari ada/tidaknya baris temuan | otomatis dengan aturan yang sama | `SYSTEM`; bukan field pilihan langsung |
| summary | opsional kecuali no-finding memerlukannya | kondisional | submitter |
| visit latitude/longitude/accuracy | tidak tersedia pada form Admin | lat/lon wajib; accuracy opsional | Admin menghasilkan null; device menghasilkan GPS |
| personnel | ID master atau nama manual; minimal satu | minimal satu | snapshot personel |
| client request ID | tidak ada | wajib | idempotency mobile |

### Field temuan

| Field | Web Admin | Mobile API | Aturan |
|---|---|---|---|
| finding code | wajib | wajib | reference code aktif |
| description | wajib | wajib | minimal panjang di service |
| keypoint ID | opsional; diisi sebagai ID teks | opsional | `SPS-KEYPOINT` reference |
| asset label | opsional | opsional | teks, belum canonical asset |
| latitude/longitude | kondisional | kondisional | setiap finding harus punya keypoint atau koordinat |
| suggested team | opsional | opsional | role reference; mismatch follow-up perlu keputusan |
| occurred at | otomatis sama dengan waktu kunjungan | diturunkan/kontrak | tidak ada input waktu per finding di Web |
| photos | tidak diwajibkan untuk submit Web | minimal satu per finding | media internal |
| verification | pending/approved/rejected + reviewer/note/time | keputusan Admin | workflow internal |

Daftar menampilkan code, visit time, team, penyulang/section, result, source/action; filter q, team, feeder, result, from/to. Ringkasan menghitung visit, finding, no-finding, pending, dan approved.

### Approval finding dan follow-up

- Approve menerima review note opsional dan dapat membuat follow-up HARSUS/ROW dengan team, work date, dan due date.
- Reject memerlukan alasan.
- `suggested_team` dapat mencakup POSKO, tetapi pembuat follow-up hanya mendukung HARSUS/ROW. Ini bukan sekadar masalah label; keputusan routing dibutuhkan.

### Gap dan rekomendasi

- Requirement v3 meminta foto/GPS/waktu per finding. Web Admin saat ini tidak mewajibkan foto, tidak menangkap GPS kunjungan, dan memakai satu waktu kunjungan untuk semua finding. Tentukan apakah Admin memang jalur back-office exception; bila ya, audit alasan pengecualian.
- Ganti input ID Keypoint manual dengan selector/search yang menjaga referential integrity dan menampilkan feeder/nama/koordinat.
- Bila Keypoint dipilih, service mengganti `asset_label` dengan label Keypoint. Namun koordinat payload yang juga diberikan dapat mengalahkan koordinat Keypoint tanpa pemeriksaan jarak/kecocokan. Tetapkan apakah koordinat device adalah evidence terpisah atau lokasi canonical, lalu validasi deviasinya.
- Asset label masih teks. Jangan menyebutnya asset master sampai `ASSET_RAW` dipromosikan melalui proses canonical yang disetujui.
- Algoritma ranking/rotation/rekomendasi inspeksi belum tersedia dan harus ditetapkan sebelum diotomasi.

## 5.7 ROW

### Data dan field tersedia

| Area | Field | Asal |
|---|---|---|
| Daftar kandidat | ID pohon, nama pohon/tiang, kondisi + jarak saat ini, tanggal perkiraan bahaya, status WO aktif | subset `VEGETASI_RAW` melalui `vegetasi_source_rows` |
| Ringkasan target terpilih | label target, penyulang, koordinat | subset staging + master penyulang |
| Create WO | work type, feeder, target row, work date, due date | `ADMIN`; required |
| Create WO opsional | team, posko, section, notes | `ADMIN` |
| Daftar | WO, type, posko, feeder/section, schedule, status, update/action | Work Order |
| Filter/ringkasan | q, type, status, feeder, date; active, overdue, completed, by type/source | Work Order |
| Hasil | report, GPS, personnel, photos, notes | Admin/`MOBILE-API` + system; quantity non-APKUK tidak dipakai/disimpan sebagai hasil ROW |

### Provenance dan gap

- Baris vegetasi masih staging raw dan banyak kolomnya disimpan sebagai teks. Pemakaian sebagai kandidat tidak otomatis menjadikannya master ROW canonical.
- Field sumber seperti izin padam/tebang, keterangan, dan sejumlah tanggal memang tersimpan di staging, tetapi tidak dipilih ke query kandidat, tidak tampil pada form, dan tidak masuk snapshot target saat ini. “Ada di sumber” tidak berarti “tersedia bagi operator ROW”.
- Belum ada generator otomatis, formula risiko, deduplication policy, status penyelesaian vegetasi canonical, atau routing berdasarkan posko/section.
- `ID POHON`, `ID TIANG`, dan koordinat tersedia pada sumber contract, tetapi kualitas/keunikan live belum diverifikasi.
- Identity target saat ini adalah `VEGETASI:{vegetasi_source_rows.id}`, yaitu surrogate ID staging, bukan `ID POHON`. Import default dapat menambah duplikat; `--replace` menghapus/reinsert sehingga ID dapat berubah. Akibatnya pohon yang sama dapat memperoleh `target_key` baru, melewati guard WO aktif, dan `source_ref` lama tidak lagi menunjuk row staging current. Tetapkan identity bisnis yang stabil sebelum mengandalkan deduplication/traceability ROW.
- Tetapkan formula bahaya, tanggal jatuh tempo, izin padam/tebang, owner perubahan data, serta cara menulis balik hasil—jika memang diperlukan. Saat ini tidak ada write-back ke sumber.

## 5.8 HARSUS

### Data dan field tersedia

| Area | Field | Asal |
|---|---|---|
| Kandidat | keypoint ID, jenis, nama, penyulang, koordinat, note, status WO | `SPS-KEYPOINT` + WO |
| Create WO required | work type HARSUS, feeder, target Keypoint, work date, due date | `ADMIN` |
| Create WO optional | team, posko, section, notes | `ADMIN` |
| Sumber lain | escalation/follow-up dari finding | workflow internal |
| Daftar | WO, type, posko, feeder/section, schedule, status, source, update/action | Work Order |

### Gap dan rekomendasi

- Keypoint adalah proxy target, bukan master asset lengkap. Jenis/nama/koordinat dapat membantu routing, tetapi tidak menyediakan hierarchy/peralatan/section canonical.
- Tetapkan apakah HARSUS selalu wajib mengacu Keypoint, dapat mengacu asset canonical, atau dapat berbasis lokasi bebas.
- Due date, team assignment, approval completion, dan hubungan ke inspection finding sudah ada secara workflow tetapi aturan bisnisnya perlu pengesahan.

## 5.9 Peta Monitoring

### Payload dan layer saat ini

| Layer/data | Field endpoint | Ketersediaan |
|---|---|---|
| Keypoint | ID, feeder, type, name, latitude, longitude, note | `SPS-KEYPOINT`; marker tersedia |
| APKUK | ID, pending flag, source ID, date, feeder, pole, status, kurang/terpasang, latitude, longitude | `SRC-APKUK`; active+pending marker tersedia, history dikecualikan |
| Gangguan | ID, date, feeder, equipment, pole, nature, cause | `SRC-GANGGUAN`; daftar tersedia, tidak ada koordinat marker |
| Meta | counts/state | internal | otomatis |

UI memiliki search, filter feeder/type/layer untuk Keypoint dan APKUK, popup marker, serta warna khusus pending reconciliation. Terdapat dua endpoint terkait peta dengan filter/payload Keypoint yang tidak sepenuhnya identik; ini meningkatkan risiko drift kontrak.

### Gap terhadap kebutuhan v3

- Peta membuka semua feeder; belum mewajibkan pemilihan satu dari 16 penyulang.
- Global search belum menjalankan alur pencarian lintas feeder lalu switch konteks feeder.
- Belum ada layer asset canonical, WO/P0/PASILA/ROW/HARSUS/Inspection yang lengkap.
- Gangguan tidak mempunyai koordinat dan relasi affected-assets, sehingga belum dapat diplot/ditelusuri dampaknya secara andal.
- Belum ada sidebar asset berisi detail, foto, history, dan quick-create Work Order.
- Belum ada server-side bbox, paging, atau clustering.
- APKUK history tidak memiliki opsi layer terpisah; active dan pending perlu legenda yang konsisten.

Rekomendasi: jangan menebak koordinat Gangguan dari teks tiang tanpa master asset/tiang dan aturan matching yang diaudit. Satukan kontrak endpoint peta sebelum menambah layer.

## 5.10 Review & Approval

### Queue dan field keputusan

| Kategori | Field yang direview | Field keputusan Admin |
|---|---|---|
| Reports | WO/type, team/posko, target, occurred time, submitting team, personnel, notes, photos | approve + note opsional; reject + alasan wajib |
| Reschedules | WO/team/requester, reason/note, jadwal/shift asal dan usulan | approve + note opsional; reject + alasan wajib |
| Escalations | source WO/team/status, target role/type, reason/note, requester/time | approve: child team opsional, work/due date wajib, note; reject: alasan wajib |
| Findings | inspection/finding context | action diarahkan ke detail Inspection; approve/reject/follow-up |

Filter tersedia untuk category, team, dan posko. Team/posko membatasi queue yang berbasis Work Order; queue finding saat ini tidak dimuat sama sekali ketika salah satu filter itu aktif, bukan difilter berdasarkan inspection/team/feeder. Perilaku ini perlu dibuat eksplisit atau diperbaiki. Semua queue berasal dari workflow internal; tidak ada field asli spreadsheet yang diedit oleh approval.

### Keputusan yang masih perlu disahkan

- Saat ini completion HARSUS dan ROW menunggu approval.
- Reschedule menggunakan approval queue, meskipun kebijakan per role/jenis pekerjaan belum ditetapkan final.
- Report yang ditolak mengembalikan Work Order ke status penugasan tertentu.
- Approve finding dapat membuat HARSUS/ROW, sedangkan suggested role dapat menyebut POSKO.

Empat aturan tersebut harus didokumentasikan sebagai kebijakan bisnis, bukan hanya dianggap benar karena sudah ada di service.

## 5.11 Special Case

### Fitur tersedia

| Area | Field | Provenance |
|---|---|---|
| Issue WO | WO, type, team, posko, status, issue type/description, reporter, time | workflow internal |
| Resolution | resolution action dan note | `ADMIN`, wajib |
| Overdue | WO dan konteks jadwal/status | Work Order/system |
| Frequency filter | period 30/90/180/365, dimension feeder/equipment/pole, q | Admin query |
| Frequency table | group, count, total outage dalam menit, last event | agregasi `SRC-GANGGUAN` |
| Event detail | date, ID, feeder, equipment, pole, cause/description, nature, outage, corrective | subset `SRC-GANGGUAN` |

### Batas fungsi

- Menyimpan resolution action/note tidak otomatis melakukan reschedule, escalation, cancellation, atau data correction; action aktual tetap ada pada workflow terkait.
- Belum ada threshold frekuensi, severity/classification, rule window, owner alert, atau tindakan otomatis.
- Gangguan tidak memiliki koordinat/affected-assets, sehingga analisis spatial belum tersedia.
- URL foto, preventive action, material, ENS/load/relay/arus, teks keypoint, dan field Gangguan lain yang sudah tersimpan tidak tampil pada detail Special Case saat ini.

Rekomendasi: tetapkan threshold per dimensi, denominator, periode, dan tindakan yang diizinkan sebelum memberi label “berulang/kritis”.

## 5.12 Master Data

### Tab dan field yang tersedia

| Tab | Field yang tampil | Owner dan mutability |
|---|---|---|
| Sources | label, kind, row count, last sync, note | ringkasan `SYSTEM`; sumber tetap read-only |
| Posko | code, name, related feeder count, team count, active | whitelist/sync `MASTER-INT`; read-only di UI ini |
| Penyulang | code, name, gardu induk, posko count, active | whitelist/sync `MASTER-INT`; read-only; gardu induk belum bersumber |
| Teams | code, name, role, posko, active/total personnel, status | CRUD `ADMIN` |
| Personnel | name, personnel code, team, status | CRUD `ADMIN` |
| Reference codes | category, code, label, role scope, status | CRUD `ADMIN`; sort order tersimpan tetapi tidak ditonjolkan di tabel |
| Schedule | team, shift, effective from/until | CRUD `ADMIN` untuk tim POSKO |
| Keypoint | ID, name, type, source feeder, coordinates, last sync | `SPS-KEYPOINT`, read-only; note ada di tabel tetapi tidak ditampilkan di daftar |

### Field form Admin

| Entitas | Required | Optional/kondisional |
|---|---|---|
| Team | code, name, role | posko wajib bila role POSKO; active; reason perubahan opsional |
| Personnel | team, name | personnel code, active |
| Reference code | category, code, label | role scope, sort order, active |
| Shift assignment | POSKO team, shift, effective from | effective until |
| Delete master | reason minimal 5 karakter | wajib; audit otomatis |
| Edit/ubah status master | field form terkait | reason perubahan saat ini opsional; audit otomatis bila ada perubahan |

### Provenance dan gap

- Posko/penyulang internal dibangun dari whitelist serta relasi `SECTION_PIC_RAW`. Ini berbeda dari data yang diinput Admin.
- `gardu_induk` ada di skema tetapi tidak diisi oleh sinkronisasi yang diperiksa. Tetapkan sumber sebelum membuatnya editable.
- Tidak ada master section canonical. Kolom `PEMUTUS` pada staging tidak otomatis menjadi entitas section tanpa definisi/key/hierarchy yang disepakati.
- Tidak ada master asset canonical walaupun `ASSET_RAW` tersedia.
- Shift assignment mencatat tim dan masa berlaku, sedangkan jam shift tetap hard-coded di service.
- Vocabulary reference code bergantung pada data Admin/seeder; audit tidak menganggap nilai test sebagai daftar resmi.
- Ringkasan sumber APKUK saat ini sudah menampilkan active, pending reconciliation, dan history melalui resolver state yang sama; detail Penyulang juga menyatakan pending/history dikecualikan dari angka aktif.

## 5.13 Analytics

### Filter, metrik, dan export

| Area | Field/metrik | Scope/provenance |
|---|---|---|
| Filter | period, `team_type`, posko, feeder | query Admin; bukan satu `field_team` tertentu |
| WO KPI | total, active, completed, cancelled, on-time, late, overdue, on-time rate, average completion hours | Work Order dalam periode work date |
| WO breakdown | period, work type, posko, feeder, status, matrix team/status | Work Order/internal |
| Gangguan | bulanan, top feeder, top cause | `SRC-GANGGUAN` dalam periode tanggal |
| APKUK | jumlah active per status dan total kurang | state APKUK saat ini, bukan histori periode |
| Keypoint | total per jenis dan mapped count | snapshot Keypoint saat ini |
| CSV | code, team, work type, source, status, posko, feeder, section, target, work date, shift, due/start/finish, on-time/active late, target/installed APKUK, creator/created | Work Order + target snapshot/internal |

### Interpretasi dan gap

- WO dan Gangguan menggunakan rentang tanggal; APKUK/Keypoint adalah snapshot current-state. UI/export harus menyatakan perbedaan denominator ini.
- APKUK analytics memakai state active, sehingga pending reconciliation dan history tidak masuk. Label perlu eksplisit.
- Panel “belum tersedia” masih menyebut domain Inspeksi belum tersedia, padahal Inspeksi kini operasional. Konten ini stale dan harus dikoreksi.
- Belum ada metrik sesi PASILA/P0, kualitas inspeksi, risiko vegetasi/ROW, affected-assets Gangguan, atau SLA approval yang memiliki domain final.
- Jangan menghitung “on-time” tanpa menyepakati treatment untuk due date null, reschedule, reopened/rejected report, dan cancelled WO.

## 5.14 User Management

### Field dan aturan

| Area | Field | Validasi/owner |
|---|---|---|
| Daftar | username, name/email, role, team, status, created time | data akun internal |
| Filter | q, role, status | Admin query |
| Create/edit | name, username, email, role | `ADMIN`, required dan unique sesuai validator |
| Team | field team | wajib untuk field role; relasi internal |
| Password | password + confirmation | required saat create; minimum 8 dengan huruf dan angka |
| Active | is_active | Admin; perubahan status memerlukan reason |
| Reset | new password + confirmation + reason | Admin, required |
| Detail | Web/API access, token count, timestamps, audit/activity | internal/system |

Guard service yang tersedia: akun lapangan harus terkait tim aktif dengan role yang sama; Admin tidak dapat menonaktifkan atau menurunkan role dirinya sendiri; minimal satu Admin aktif harus tersisa; perubahan role/team, deaktivasi, dan reset password mencabut akses/token sesuai kondisi.

### Mobile auth dan gap

API login memerlukan `username`, `password`, dan `device_name`, lalu menghasilkan token serta konteks account/team. Flutter yang ada hanya menampilkan username/password dan langsung berpindah ke Beranda placeholder bila input lokal dianggap valid; belum memanggil API dan tidak mengirim `device_name`.

Keputusan yang dibutuhkan:

- Email saat ini wajib/unik. Konfirmasi apakah akun tim memiliki mailbox individual yang benar-benar unik.
- Tidak ada constraint satu akun per `field_team`. Konfirmasi apakah satu tim boleh memiliki beberapa akun aktif.
- Tetapkan batas device/token, expiry/revocation, pergantian personel pengguna akun tim, dan audit ownership.
- Jangan menyebut autentikasi Flutter siap operasional sebelum flow token, error, secure storage, logout, dan offline identity diterapkan.

## 5.15 Audit Logs

### Dua jenis jejak audit

| Store | Field | Cakupan |
|---|---|---|
| Work Order status logs | occurred time, action, WO, from/to status, actor user/team, reason, payload | state transition dan aktivitas WO; append-only melalui guard aplikasi/model |
| General audit logs | actor user/team, entity type/id/label, action, before/after, reason, IP, occurred time | CRUD/keputusan administratif; append-only melalui guard aplikasi/model |

Filter WO log: code, action, team, date. Filter general log: entity, action, date, q. Detail menyediakan payload JSON. Logger membuang key top-level yang persis bernama `password`, `remember_token`, atau `token`; sanitasi tidak rekursif. Password reset saat ini tidak mencatat nilai password.

### Interpretasi dan rekomendasi

- Dua store memiliki tujuan berbeda dan harus dijelaskan kepada reviewer; general audit tidak menggantikan timeline status WO.
- Immutability dijaga model/service aplikasi, bukan constraint database yang mencegah SQL langsung. Hak akses database dan prosedur operasi tetap bagian dari kontrol audit.
- Pastikan semua mutation baru mencatat actor, old/new, reason, dan correlation/client request bila relevan.
- Jangan menaruh secret di nested payload audit; tambahkan redaction rekursif bila payload masa depan dapat memuat struktur bertingkat.
- Tetapkan retention, export/access policy, IP trust bila berada di balik proxy, dan prosedur koreksi audit—tanpa mengubah record append-only.
- Nilai payload test adalah `TEST`, bukan contoh data production.

## 5.16 APKUK Reconciliation

### Tabel dan state

| Entitas | Field | Owner/provenance |
|---|---|---|
| Sync run | started/finished, status/failure stage/reason, snapshot accepted, source count/digests, counts JSON (new/updated/returned/pending), guard/details, database user | `SYSTEM` |
| Reconciliation item | kind `MISSING`/`IDENTITY_CHANGE`, status/open key, source record, candidate IDs, first/last run, detection count, resolution/details | `SYSTEM` dari perbandingan source |
| Resolution | decision, resolved by/at, reason, owner confirmation | `ADMIN` dengan evidence owner |
| Apply record append-only | plan ID/fingerprint, component fingerprints, action, approver metadata/user, reason, database user, counts/time, APKUK IDs/keys, impact | `SYSTEM` + Admin/CLI; jejak apply, bukan row sumber |

### Layar dan form

| Area | Field/action |
|---|---|
| Summary | missing, identity change, resolved, last run/status/count |
| Open item | old/source ID, tiang, tanggal, feeder, status/kurang, source row, active WO references |
| Missing decision | archive/historical decision, reason wajib, owner confirmation wajib, acknowledge active WO bila ada |
| Bulk missing | item IDs, reason, owner confirmation, acknowledge active WO |
| Identity decision | pilih old report/candidate bila relevan, reason, keputusan same identity atau insert new; Web tidak menyediakan `owner_confirmation`, sementara controller/service membiarkannya opsional |
| Runs | start/finish, status/failure, snapshot accepted, source count, dan angka new/updated/returned/pending dari `counts` JSON |
| Resolved | kind/report/decision/resolver/owner confirmation/reason/time |

### Aturan penting

- Item open berarti **pending reconciliation**, bukan record sudah hilang/diarsipkan.
- `source_missing_at` hanya diisi setelah keputusan archive yang memenuhi guard, termasuk konfirmasi owner dan acknowledge bila ada Work Order aktif.
- Tidak ada hard delete APKUK. Record yang kembali dari sumber dapat dikembalikan sesuai sync state.
- Work Order menyimpan snapshot agar target historis tetap dapat dibaca.
- Pada apply record, `approved_by` adalah metadata string operator CLI, sedangkan `approved_by_user_id` adalah foreign key Admin terautentikasi bila alur menyediakannya. Keduanya tidak boleh dianggap setara tanpa konteks kanal apply.

### Keputusan/rekomendasi

- Tetapkan format evidence `owner_confirmation`: siapa yang berwenang, kanal, timestamp, referensi, dan minimum bukti. Free text saja sulit diaudit.
- Tetapkan policy identity-change dengan contoh yang disahkan owner: koreksi ID, pindah tiang, duplikasi, atau record baru.
- Putuskan apakah bukti owner juga wajib untuk identity correction/new insert; saat ini kewajiban Web hanya jelas pada archive `MISSING`.
- Pastikan bulk action tidak menyamarkan record yang memiliki WO aktif dan selalu menghasilkan audit log per item.
- Dashboard/analytics/peta harus memakai state resolver yang sama, bukan kondisi query buatan masing-masing.
- Sibling item yang terselesaikan sebagai efek keputusan identity kini menyimpan `resolved_by_user_id` dan `resolution_details`; riwayat membedakan actor Admin, run sinkronisasi, dan penyelesaian terkait.

## 6. Kontrak Mobile API versus Flutter yang tersedia

### 6.1 Kontrak API yang sudah ada

| Area | Field/payload utama |
|---|---|
| Login/auth | username, password, device_name; response account/team/token |
| Bootstrap | team, posko, personnel, reference codes, issue types, shifts/current shift, feeders |
| Work feed/detail | WO identity/assignment/schedule/status; target summary (label, coordinates, Maps URL, APKUK target qty); `report_types`; report history subset (type, verification, installed qty, occurred time, personnel); reschedule/escalation/issue subset |
| Submit report | client request ID, type, occurred at, GPS, quantities, notes, personnel, photos |
| Reschedule | client request ID, reason code/note, proposed date/shift |
| Escalation | target team/type, reason/note sesuai action |
| Issue | type, description |
| Inspection | client request ID, feeder/section, visit time/GPS, result/summary, personnel, findings, finding GPS/keypoint/photos |

### 6.2 Yang benar-benar ada di Flutter

- Input username dan password pada layar login.
- Validasi lokal sederhana dan navigasi ke Beranda placeholder.
- Belum ada panggilan login API, `device_name`, token lifecycle, bootstrap, work feed, report, media upload, inspection, outbox/offline sync, atau conflict handling.

Karena itu, semua field di 6.1 harus diberi label **`MOBILE-API`/future Flutter**, bukan “sudah ada pada aplikasi Flutter”.

Kontrak detail mobile tidak mengirim field `allowed_actions`; klien harus menginferensikan action dari status/`report_types` dan tetap tunduk pada guard server. Detail juga tidak mengirim full target snapshot atau media/foto report, serta menghilangkan beberapa field report tersimpan seperti notes, GPS, remaining quantity, dan received time. Tentukan kebutuhan tampilan/offline detail sebelum Flutter bergantung pada payload ini.

## 7. Matriks gap prioritas

| Prioritas | Gap | Dampak | Rekomendasi sebelum implementasi lanjut |
|---|---|---|---|
| P0 | Domain P0 tidak ada | UI dapat disalahartikan sebagai data nol | Sahkan definisi, source-of-truth, schema, status, SLA, dan relasi PASILA/WO |
| P0 | PASILA belum memiliki sesi/finding | Target/realisasi bukan bukti aktivitas PASILA | Sahkan event model dan formula |
| P0 | Canonical section tidak ada | filter, routing, snapshot, dan relasi tidak konsisten | Definisikan key, hierarchy, owner, mapping sumber |
| P0 | Canonical asset tidak ada | HARSUS/Peta/Gangguan memakai proxy/teks | Promosikan source melalui pipeline terkontrol; jangan langsung menganggap `ASSET_RAW` canonical |
| P0 | Gangguan positional mapping lemah | Pergeseran kolom dapat merusak arti data | Validasi semua header yang dikonsumsi dan tambahkan failure test |
| P0 | Target ROW memakai surrogate ID staging | Reimport dapat mengganti identity, melewati duplicate guard, dan memutus traceability | Gunakan stable vegetation business key/canonical ID sebelum operasi ROW dipercaya |
| P1 | Peta tidak memenuhi desain v3 | monitoring dan affected-assets belum dapat dipercaya | Satukan endpoint; feeder context, layer model, bbox/paging/cluster, sidebar |
| P1 | Evidence Inspeksi Web lebih longgar dari mobile | foto/GPS/waktu dapat kosong | Sahkan exception Admin atau samakan guard |
| P1 | Flutter belum terhubung API | kontrak lapangan belum usable | Implementasi auth/bootstrap/workflow/outbox bertahap |
| P1 | Kebijakan approval/reschedule belum final | perubahan status dapat berbeda dari SOP | Sahkan state machine dan matrix approval |
| P1 | Owner confirmation reconciliation free text | bukti keputusan sulit diaudit | Definisikan format evidence dan kewenangan |
| P2 | Scope KPI bercampur | salah interpretasi Dashboard/Analytics | Label all-time/current/period secara eksplisit |
| P2 | Konten Analytics stale | pengguna mengira Inspection belum ada | Perbarui panel unavailable |
| P2 | Gardu induk/shift hours tidak bersumber | master tampak resmi tanpa provenance | Tetapkan owner/sumber/configuration |
| P1 | Penyulang `TANGGAMUS` (Gangguan) vs `TANGGAMUS 2` (master) | 33 Gangguan tidak tersambung ke penyulang master | Putuskan alias resmi atau koreksi di sumber; jangan menebak di kode |
| P1 | `MASTER_POSKO` (ID PSK, `SRIBAWONO`) tidak dibaca; master posko dari `SECTION_PIC_RAW` (`SRIBHAWONO`) | kode posko internal bukan kode resmi; risiko master ganda | Tetapkan satu sumber master posko dan ejaan resmi |
| P1 | Section APKUK `DUMMY`/kosong (676 laporan aktif/pending) | filter/routing per section tidak andal | Koreksi di sumber atau tetapkan section dari master topologi |
| P2 | `ASSET_REVIEW_RAW` (121 aset) belum dipakai | kualitas aset canonical belum terkendali | Tetapkan proses review sebelum aset menjadi target |

## 8. Daftar keputusan bisnis yang diperlukan

Tidak ada nilai default baru yang direkomendasikan untuk butir berikut; semuanya harus diputuskan owner bisnis/data.

> **Pembaruan 28 Okt 2026:** butir berikut sudah diputuskan pengguna (lihat `PROJECT_STATUS.md` §3a dan `OPERATIONAL_SPEC_GAP_AUDIT.md` §2.2):
> - 12 — jam shift final; tanggal kerja Shift C masih terbuka;
> - 17 — `TANGGAMUS` = `TANGGAMUS 2`;
> - 18 — Posko resmi SRIBHAWONO; PSK001–006 tidak ditampilkan;
> - 19 — Section dilengkapi Admin lewat data internal ber-audit;
> - 22 — DUMMY dipertahankan;
> - 23 — ROW memakai `ID POHON`;
> - 24 — semua data asli tampil di Detail APKUK.
>
> Butir lain tetap terbuka.

1. **P0:** definisi, trigger, severity, status, SLA, bukti, kebutuhan padam/material/K3, dan hubungan dengan PASILA/WO.
2. **PASILA:** definisi sesi, grain posko/shift, checklist/finding, target formula, closing rule, dan relasi P0.
3. **Section:** primary key, hierarchy feeder–section–asset, alias, owner, dan sumber canonical.
4. **Asset:** tipe asset, ID canonical, mapping `ASSET_RAW`/Keypoint/tiang, lifecycle, dan relasi gangguan.
5. **APKUK:** vocabulary status resmi, identitas record, aturan latest/duplicate, dan field sumber yang harus tampil.
6. **Gangguan:** header canonical lengkap, identity/deduplication, koordinat/matching asset, dan affected-assets.
7. **ROW:** formula risiko, threshold, due date, izin, routing, dan write-back/closure source.
8. **HARSUS:** jenis target yang sah, due date, assignment, bukti, dan approval completion.
9. **Inspeksi:** exception bukti Web Admin, waktu per finding, suggested POSKO routing, ranking/rotation.
10. **Work Order:** nullability shift/due date, state transition, cancellation/reopen, rejection, dan mandatory evidence per type.
11. **Approval:** matrix role/type untuk report, reschedule, escalation, dan finding.
12. **Shift:** jam resmi, zona waktu, lintas tengah malam, hari efektif, dan sumber konfigurasi.
13. **Special Case:** threshold, period, severity, owner alert, dan action matrix.
14. **Akun:** email wajib/unik, jumlah akun per tim, device/token policy, dan pergantian pengguna.
15. **Reconciliation:** otoritas owner, format confirmation, identity-change policy, dan handling WO aktif.
16. **Pending APKUK saat ini:** keputusan atas 78 laporan yang hilang dari SPS BOS (8 di antaranya ID dan tiangnya tidak ada lagi di sumber) — arsipkan atau tunggu muncul kembali.
17. **Alias penyulang:** apakah `TANGGAMUS` pada data Gangguan sama dengan penyulang `TANGGAMUS 2`?
18. **Master posko:** sumber resmi (`MASTER_POSKO` atau `SECTION_PIC_RAW`), ejaan resmi `SRIBAWONO`/`SRIBHAWONO`, dan apakah kode PSK001–PSK006 menjadi kode posko di Web Admin.
19. **Section APKUK `DUMMY`:** dikoreksi di SPS BOS, diturunkan dari topologi, atau dibiarkan tanpa section.
20. **Kolom sumber yang selalu kosong** (Keypoint `NOTE`; Asset `Foto`/`Section`/`Posko`; Vegetasi `SECTION`/`EAM`/`IZIN TEBANG BAWAH`): akan diisi pemilik data atau dikeluarkan dari kontrak.
21. **Header Gangguan:** ganti nama header `STATUS`→sifat dan `SECTION`→kategori proteksi di sumber, atau kode memvalidasi header saat ini apa adanya; perbaikan nilai `#REF!`.
22. **Data uji di sumber produksi:** laporan APKUK ber-penyulang `DUMMY` dan section `DUMMY`.
23. **Kandidat kunci stabil ROW:** apakah `ID POHON` (terisi 100%) disahkan sebagai identitas titik vegetasi.
24. **Tampilan detail APKUK:** apakah petugas, naik dari, foto, dan koordinat mentah perlu ditampilkan (datanya tersedia di sumber).

## 9. Rekomendasi urutan tindak lanjut

1. Sahkan keputusan data model P0, PASILA, section, dan asset sebelum migration baru.
2. Bekukan kontrak sumber melalui data dictionary yang ditandatangani owner, khususnya Gangguan dan status APKUK.
3. Selaraskan validator server, Blade, API contract, dan SOP untuk Work Order/Inspection/Approval.
4. Perbaiki label current/all-time/period serta stale content sebelum menambah KPI.
5. Satukan kontrak peta dan desain query spatial setelah canonical asset/section tersedia.
6. Implementasikan Flutter sebagai consumer kontrak API, disertai idempotency, outbox, media retry, dan secure token lifecycle.
7. Tambahkan acceptance test berbasis kontrak yang disahkan; tetap gunakan fixture sintetis dan jangan menyalin production data.

## 10. Jejak bukti repository

Kelompok file utama yang menjadi dasar audit:

- Baseline kebutuhan: `docs/PROJECT_STATUS.md`, `docs/PRD.md`, `docs/DATA_MODEL.md`, `docs/BATCH_A_RELEASE.md`, `docs/APKUK_RESILIENT_SYNC.md`.
- Routing: `backend/routes/web.php`, `backend/routes/api.php`, `backend/routes/console.php`.
- Struktur data: seluruh migration di `backend/database/migrations`, model di `backend/app/Models`.
- Kontrak sumber/sync: command dan service terkait APKUK, Gangguan, Keypoint, raw sheet importer, dan core master sync di `backend/app`.
- Workflow Web/API: controller, request/service, view Blade, dan JavaScript di `backend/app`, `backend/resources/views`, dan `backend/resources/js`.
- Verifikasi perilaku: test Laravel di `backend/tests`; factory/fixture diperlakukan sebagai `TEST`.
- Status Flutter: source project mobile pada repository; hanya login/placeholder yang terverifikasi.

## 11. Kesimpulan

Web Admin sudah memiliki fondasi operasional yang cukup lengkap untuk Work Order, APKUK, Inspection, workflow approval, dan master internal. Namun, **kelengkapan field UI tidak sama dengan kelengkapan sumber data**. P0 dan PASILA belum memiliki domain final; section dan asset belum canonical; Gangguan belum spatial/relational; ROW/HARSUS masih memakai staging/proxy; dan Flutter belum menjadi consumer API.

Langkah aman berikutnya adalah mengesahkan keputusan bisnis serta data dictionary sumber, kemudian menyelaraskan schema, validator, UI, API, dan analytics terhadap keputusan tersebut. Sampai itu dilakukan, field `UNAVAILABLE`/`TBD` pada audit ini tidak boleh digantikan dengan asumsi atau nilai test.

## Appendix A — Indeks field tabel operasional

Indeks ini memakai nama kolom database sebagaimana didefinisikan migration. `id`, `created_at`, dan `updated_at` tetap disebut bila relevan untuk identity/audit; tabel framework seperti cache, jobs, sessions, dan personal access tokens tidak termasuk karena bukan field bisnis Web Admin.

### A.1 Sumber tersinkronisasi

| Tabel | Field |
|---|---|
| `apkuks` | `id`, `source_id`, `legacy_key`, `tanggal`, `petugas`, `penyulang`, `section`, `no_tiang`, `naik_dari`, `terpasang_bh`, `kurang_bh`, `status`, `koordinat`, `latitude`, `longitude`, `foto`, `sumber_input`, `source_row_number`, `last_synced_at`, `source_missing_at`, timestamps |
| `keypoints` | `id`, `legacy_key`, `penyulang`, `jenis`, `nama`, `koordinat_raw`, `latitude`, `longitude`, `note`, `sumber_input`, `source_row_number`, `last_synced_at`, timestamps |
| `gangguans` | `id_gangguan`, `tanggal_kejadian`, `penyulang`, `ulp`, `peralatan`, `kategori_proteksi_pusat`, `kode_gangguan`, `sifat_gangguan`, `penyebab`, `jenis_hewan`, `uraian_penyebab`, `no_tiang`, `keypoint_pusat`, `lepas`, `masuk`, `lama_padam_menit`, `ens_kwh`, `ens_apkt_kwh`, `beban_amp`, `beban_setelah_amp`, `rele_kerja`, `arus_gangguan_amp`, `material_rusak`, `tindakan_corrective`, `tindakan_preventive`, `keterangan_temuan`, `foto_gangguan_url`, `foto_corrective_url`, `sumber_data`, `last_synced_at`, timestamps |
| `asset_source_rows` | raw mirror untuk sepuluh header `ASSET_RAW` (termasuk `foto_raw`, `section_raw`, `posko_raw`), `source_row_number`, `imported_at`, timestamps |
| `vegetasi_source_rows` | raw mirror untuk 28 header `VEGETASI_RAW`, `source_row_number`, `imported_at`, timestamps |
| `section_pic_source_rows` | `penyulang_raw`, `pemutus_raw`, `posko_pic_raw`, `source_row_number`, `imported_at`, timestamps |
| `keypoint_source_rows` | `penyulang_raw`, `jenis_raw`, `nama_raw`, `koordinat_raw`, `note_raw`, `source_row_number`, `imported_at`, timestamps; staging lama, bukan writer Keypoint aktif |

### A.2 Master dan identity internal

| Tabel | Field |
|---|---|
| `users` | `id`, `name`, `email`, `email_verified_at`, `password`, `remember_token`, `username`, `role`, `is_active`, `field_team_id`, timestamps |
| `poskos` | `id`, `kode_posko`, `nama_posko`, `is_active`, timestamps |
| `penyulangs` | `id`, `kode_penyulang`, `nama_penyulang`, `gardu_induk`, `is_active`, timestamps |
| `posko_penyulang` | `posko_id`, `penyulang_id`, timestamps |
| `field_teams` | `id`, `code`, `name`, `role`, `posko_id`, `is_active`, timestamps |
| `field_personnel` | `id`, `field_team_id`, `personnel_code`, `name`, `is_active`, timestamps |
| `field_team_shift_assignments` | `id`, `field_team_id`, `shift_code`, `effective_from`, `effective_until`, timestamps |
| `reference_codes` | `id`, `category`, `code`, `label`, `role_scope`, `is_active`, `sort_order`, timestamps |

### A.3 Work Order dan execution evidence

| Tabel | Field |
|---|---|
| `work_orders` | `id`, `code`, `team_type`, `work_type`, `source`, `status`, `posko_id`, `field_team_id`, `penyulang_id`, `section_name`, `work_date`, `shift_code`, `scheduled_start_at`, `due_at`, `parent_work_order_id`, `notes`, `created_by_user_id`, `started_at`, `completed_at`, `cancelled_at`, `cancelled_by_user_id`, `cancel_reason`, timestamps |
| `work_order_apkuk_targets` | `id`, `work_order_id`, `apkuk_id`, `target_key`, `active_target_key`, `no_tiang`, `penyulang_source`, `section_source`, `naik_dari`, `source_report_date`, `source_status`, `source_terpasang_bh`, `source_kurang_bh`, `latitude`, `longitude`, `target_quantity`, `installed_quantity`, `remaining_quantity`, timestamps |
| `work_order_location_targets` | `id`, `work_order_id`, `target_type`, `source_ref`, `target_key`, `active_target_key`, `label`, `asset_type`, `penyulang_source`, `section_source`, `latitude`, `longitude`, `snapshot`, timestamps |
| `work_order_status_logs` | `id`, `work_order_id`, `action`, `from_status`, `to_status`, `actor_user_id`, `actor_field_team_id`, `reason`, `payload`, `created_at` |
| `work_order_reschedules` | `id`, `work_order_id`, `requested_by_field_team_id`, `requested_by_user_id`, `reason_code`, `note`, `from_work_date`, `from_shift_code`, `proposed_work_date`, `proposed_shift_code`, `status`, `reviewed_by_user_id`, `reviewed_at`, `review_note`, `client_request_id`, timestamps |
| `work_order_reports` | `id`, `work_order_id`, `report_type`, `verification_status`, `installed_quantity`, `remaining_quantity`, `notes`, `latitude`, `longitude`, `gps_accuracy_m`, `occurred_at`, `received_at`, `submitted_by_field_team_id`, `submitted_by_user_id`, `verified_by_user_id`, `verified_at`, `verification_note`, `client_request_id`, timestamps |
| `work_order_report_personnel` | `id`, `work_order_report_id`, `field_personnel_id`, `personnel_name`, timestamps |
| `work_order_media` | `id`, `work_order_id`, `work_order_report_id`, `photo_type`, `capture_source`, `disk`, `storage_path`, `sha256`, `mime_type`, `size_bytes`, `captured_at`, `latitude`, `longitude`, `gps_accuracy_m`, `uploaded_at`, timestamps |
| `work_escalations` | `id`, `source_work_order_id`, `target_team_type`, `target_work_type`, `reason_code`, `note`, `requested_by_field_team_id`, `requested_by_user_id`, `status`, `reviewed_by_user_id`, `reviewed_at`, `review_note`, `child_work_order_id`, `client_request_id`, timestamps |
| `work_order_issues` | `id`, `work_order_id`, `issue_type`, `description`, `status`, `reported_by_field_team_id`, `reported_by_user_id`, `resolution_action`, `resolution_note`, `resolved_by_user_id`, `resolved_at`, `client_request_id`, timestamps |

### A.4 Inspeksi

| Tabel | Field |
|---|---|
| `inspections` | `id`, `code`, `field_team_id`, `penyulang_id`, `section_name`, `visited_at`, `has_finding`, `summary`, `latitude`, `longitude`, `gps_accuracy_m`, `source`, `submitted_by_field_team_id`, `submitted_by_user_id`, `client_request_id`, timestamps |
| `inspection_personnel` | `id`, `inspection_id`, `field_personnel_id`, `personnel_name`, timestamps |
| `inspection_findings` | `id`, `inspection_id`, `finding_code`, `description`, `keypoint_id`, `asset_label`, `latitude`, `longitude`, `occurred_at`, `suggested_team_type`, `validation_status`, `validated_by_user_id`, `validated_at`, `validation_note`, `follow_up_work_order_id`, timestamps |
| `inspection_finding_media` | `id`, `inspection_finding_id`, `capture_source`, `disk`, `storage_path`, `sha256`, `mime_type`, `size_bytes`, `captured_at`, `latitude`, `longitude`, `uploaded_at`, timestamps |

### A.5 Audit dan reconciliation

| Tabel | Field |
|---|---|
| `audit_logs` | `id`, `actor_user_id`, `actor_field_team_id`, `entity_type`, `entity_id`, `entity_label`, `action`, `before`, `after`, `reason`, `ip_address`, `created_at` |
| `apkuk_sync_runs` | `id`, `status`, `failure_stage`, `failure_reason`, `snapshot_accepted`, `source_report_count`, `source_digest`, `identity_digest`, `counts`, `guard`, `details`, `database_user`, `started_at`, `finished_at`, timestamps |
| `apkuk_reconciliation_items` | `id`, `kind`, `status`, `open_key`, `apkuk_id`, `legacy_key`, `source_id`, `no_tiang`, `source_record`, `candidate_apkuk_ids`, `first_seen_run_id`, `last_seen_run_id`, `first_detected_at`, `last_detected_at`, `detection_count`, `resolution`, `resolved_at`, `resolved_by_user_id`, `resolution_reason`, `owner_confirmation`, `resolution_details`, `resolved_run_id`, `reconciliation_id`, timestamps |
| `apkuk_reconciliations` | `id`, `plan_id`, `fingerprint`, `fingerprint_parts`, `action`, `approved_by`, `approved_by_user_id`, `reason`, `database_user`, `marked_count`, `source_report_count`, `marked_at`, `apkuk_ids`, `legacy_keys`, `impact`, `created_at` |

## 12. Perubahan integrasi pada batch ini (PR #10 + PR #11)

| Area | Perubahan | Alasan |
|---|---|---|
| Git | `origin/main` (PR #11, `c9ca7dc`) di-merge ke `feat/admin-web-foundation-v1` tanpa konflik | Web Admin memakai sinkronisasi APKUK terbaru |
| Status APKUK | Dashboard, halaman APKUK (tab **Menunggu rekonsiliasi**), Analytics, dan Master Data memakai `ApkukSourceState` (aktif/pending/histori) | Pending tidak dihitung aktif |
| Navigasi | Menu **Rekonsiliasi APKUK** pada grup Kontrol | Antrean dapat diakses dari sidebar |
| Dashboard | Kartu terpisah "APKUK menunggu rekonsiliasi" beserta status sync terakhir | Membedakan aktif, pending, histori |
| Audit | Setiap keputusan antrean APKUK dan `apkuk:reconcile --apply` dicatat di Audit Log umum (tindakan "Diarsipkan"/"Ditindaklanjuti"), termasuk konfirmasi pemilik data | Jejak keputusan terlihat di menu Audit Log |
| Layout | Skrip cegah-kirim-ganda menyalin `name`/`value` tombol yang diklik sebelum tombol di-disable | Bug yang ditemukan uji browser: pilihan `decision` tidak terkirim sehingga arsip/identitas gagal |
| Kode | Nama kelas lengkap dari PR #11 diganti `use` biasa | Tindak lanjut yang sengaja ditunda di PR #11 |

## 13. Hasil pengujian batch ini

| Uji | Hasil |
|---|---|
| `php artisan test` (SQLite) | 259 tes lulus, 2.283 assertion (sebelum perbaikan layout; diulang sebelum commit) |
| Migration PostgreSQL 18 lokal (schema sementara) | 22/22 migration Ran; rollback 4 migration terbaru hanya menghapus objek baru; migrate ulang berhasil; schema dihapus |
| Browser (Edge headless) — integrasi APKUK | 42/42 cek, 0 error console: dashboard, tab menunggu, rekonsiliasi, arsip ditolak tanpa konfirmasi pemilik data dan tanpa centang WO aktif, arsip berhasil, WO tetap utuh, Audit Log, histori, kandidat WO, peta, tampilan seluler |
| Browser — crawl read-only seluruh menu | 154 halaman + 22 detail WO, 0 kegagalan, 0 error console |
| `npm run build` | berhasil |
