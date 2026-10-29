# Laporan Implementasi Batch B2
## Shift Scheduler, Pemetaan Section–Posko, Generator WO APKUK, dan Monitoring Kuota — ULP Sribhawono

- **Tanggal:** 29 Oktober 2026
- **Branch:** `feat/admin-web-foundation-v1` (PR #10, tetap OPEN), basis `2308c4f` (B1)
- **Status kode B2:** **di-commit lokal** di branch ini (belum di-push ke PR #10). Tidak ada push, merge, deploy, migration production, perubahan Railway, maupun perubahan Google Sheets.
- **Final review B2 (29 Okt 2026):** selesai. Pengaman aktivasi kini fail-closed, pratinjau konsisten dengan alokasi beku, dan rincian alasan laporan tercatat. Lihat §17.
- **Perbaikan konkurensi kuota (29 Okt 2026):** WO manual Admin dan pemindahan jadwal APKUK oleh Admin kini memeriksa kuota enam di bawah kunci kuota shift yang sama dengan generator. Total tidak lagi bisa menjadi 7 kecuali karena limpahan sah. Lihat §18.
- **Status operasional:** fondasi siap diuji lokal. GENERATE hanya dapat berjalan di APP_ENV `local`/`testing` dengan database lokal; semua lingkungan lain (production, `neon`, `workflows`, dan lainnya) selalu ditolak. Generator **tidak boleh dinyatakan siap operasional** sebelum model ML terlatih dan tervalidasi serta prosedur readiness tersedia.

---

## 1. Tujuan B2

Membangun fondasi penjadwalan shift dan generator enam WO PEMASANGAN_APKUK per Posko per shift dari data SPS BOS yang sah. Fondasi ini mengikuti aturan carry-over final dan pemetaan Section–Posko yang terverifikasi, transparan terhadap kekurangan data, idempoten, dan siap menerima model ML nyata pada tahap berikutnya.

## 2. Keputusan final pengguna (29 Okt 2026)

| Kode | Keputusan |
|---|---|
| A | Shift A 08.00–16.00, B 16.00–00.00, C 00.00–08.00 WIB, tanpa jeda (Asia/Jakarta) |
| B | Hari operasional dimulai 08.00 WIB dan berakhir 08.00 WIB keesokan hari; Shift C milik hari operasional sebelumnya; interval half-open |
| C | Kuota total enam WO PEMASANGAN_APKUK per Posko per shift (pekerjaan, bukan unit fisik) |
| D | WO limpahan sah (reschedule disetujui) dihitung dalam kuota shift penerima; generator hanya mengisi slot kosong; WO limpahan tetap record yang sama |
| E | Limpahan > 6: semua dipertahankan, 0 WO baru, kelebihan dicatat terpisah |
| F | Kandidat kurang: buat sebanyak kandidat sah, kekurangan dicatat; tanpa data fiktif |
| G | Posko dari SECTION_PIC_RAW → pemutus → POSKO PIC yang terverifikasi; gagal → ditahan untuk Admin |
| H | WO manual Admin yang sudah terjadwal ikut menempati kuota enam. Hanya limpahan sah yang boleh membuat total melebihi enam; WO manual biasa tidak boleh diam-diam menjadikan total tujuh. Alokasi beku tetap berlaku, tanpa top-up |

Keputusan ini menyelesaikan TBD-1 (tanggal kerja Shift C), TBD-2 (carry-over dan kuota), TBD-5 (kandidat kurang dari target), dan TBD-6 (Section → Posko) pada `OPERATIONAL_SPEC_GAP_AUDIT.md`.

## 3. Definisi hari operasional dan shift

Implementasinya ada di `App\Services\ShiftService` dan tetap menjadi satu-satunya definisi.

| Tanggal operasional D | Mulai (WIB) | Selesai (WIB, eksklusif) |
|---|---|---|
| Shift A | D 08:00 | D 16:00 |
| Shift B | D 16:00 | D+1 00:00 |
| Shift C | D+1 00:00 | D+1 08:00 |

- `operationalDate(now)`: sebelum 08:00 WIB berarti tanggal kemarin.
- `window(D, kode)`: interval [start, end) dalam WIB, disimpan sebagai UTC.
- `instanceAt(now)`: tanggal operasional, kode shift, dan jendela waktunya.
- `today()` tetap tanggal kalender, dipakai untuk rentang kalender seperti Special Case, Analitik, dan WO ROW/HARSUS.
- `work_date` pada WO ber-shift kini berarti **tanggal operasional**. Pemakai yang bergantung pada "hari ini" untuk pekerjaan ber-shift sudah dialihkan:
  - Dashboard;
  - PASILA;
  - validasi dan form WO APKUK;
  - daftar WO shift berjalan;
  - bootstrap API, yang kini menyertakan `current_shift.operational_date`.

**Data lama:** sebelum B2, WO Shift C menyimpan `work_date` kalender. Production memiliki **0 WO dan 0 reschedule** (dicek read-only 29 Okt), jadi tidak ada data yang perlu dikonversi. Di schema uji lokal, WO Shift C lama kini terbaca sebagai hari operasional. Jadwal sebenarnya tetap tercatat di `scheduled_start_at`/`due_at`, dan tidak ada data yang diubah.

## 4. Struktur shift instance

| Tabel | Isi | Keunikan |
|---|---|---|
| `shift_instances` | operational_date, shift_code, starts_at/ends_at (UTC), timezone | unique (operational_date, shift_code) |

`ShiftInstance::ensure()` aman dipanggil berulang maupun bersamaan, karena unique violation ditangani dengan membaca ulang record yang sudah ada. Shift instance bersifat lintas Posko. Identitas per Posko disimpan di `apkuk_shift_allocations` (unique shift_instance_id + posko_id). WO tetap dijadwalkan lewat `work_date` + `shift_code`; reschedule yang disetujui memindahkan keduanya pada WO yang sama.

## 5. Pemetaan Section–Posko

`App\Services\Apkuk\SectionPoskoMap` menjadi satu sumber untuk koreksi Section B1 (`ApkukSectionService::references()`) maupun generator.

**Cara kerja:**
- `SECTION_PIC_RAW` diurutkan menurut baris sumber. Kolom PENYULANG diturunkan ke baris bawahnya (fill-down), sama dengan `SyncCoreTopologyMasters`, dan memakai nama canonical `PenyulangName`.
- Section efektif kandidat dipakai, yaitu nilai sumber atau koreksi Admin B1.
- Posko diambil hanya bila pemutus tercatat pada penyulang yang sama dan memiliki **tepat satu** POSKO PIC yang cocok dengan master Posko aktif. Ejaan SRIBAWONO dibaca sebagai SRIBHAWONO, sesuai keputusan 28 Okt.
- Posko **tidak pernah** ditebak dari penyulang.

**Alasan kandidat ditahan:**

| Kode | Arti |
|---|---|
| `SECTION_KOSONG` | Section kosong/`-` dan belum dikoreksi |
| `SECTION_DUMMY` | Section DUMMY dan belum dikoreksi (nilai sumber tetap DUMMY) |
| `SECTION_TIDAK_DIKENAL` | Section tidak ada di SECTION_PIC_RAW, termasuk koreksi B1 yang hanya berdasar Section laporan lain |
| `SECTION_PENYULANG_LAIN` | Section dipetakan ke penyulang lain (bertentangan) |
| `SECTION_MULTI_PENYULANG` | Section tercatat di lebih dari satu penyulang (ambigu) |
| `POSKO_PIC_KOSONG` / `POSKO_TIDAK_DIKENAL` / `POSKO_AMBIGU` | POSKO PIC kosong, tidak cocok master, atau lebih dari satu |
| `PENYULANG_TIDAK_DIKENAL` | Penyulang tidak cocok master |

Koreksi Section B1 kembali melewati pemetaan ini. Koreksi saja tidak cukup bila Posko-nya tidak terbukti.

**Cakupan data production (read-only, 29 Okt):**
- **Kandidat sah:** 376 (kasar: belum diarsip, tidak pending, kebutuhan > 0, status BELUM EKSEKUSI/KURANG, penyulang cocok master).
- **Terpetakan:** 361, yaitu PUGUNG 188, WAY JEPARA 171, PASIR SAKTI 2.
- **Ditahan:** 15, semuanya `SECTION_TIDAK_DIKENAL`.
- **Tanpa kandidat:** SRIBHAWONO, JABUNG, dan TRIDATU, sehingga kekurangannya 6 per shift.

## 6. Aturan carry-over

`App\Services\Apkuk\Generation\ApkukShiftQuota::scheduled()` mengelompokkan WO APKUK yang terjadwal pada shift tersebut, per Posko.

| Kategori | Definisi |
|---|---|
| Limpahan sah | WO dengan reschedule **DISETUJUI** yang tujuannya sama dengan jadwal WO saat ini |
| WO lain terjadwal | WO yang terjadwal di shift ini tanpa limpahan: manual Admin, hasil generate sebelumnya, atau jadwal yang diubah Admin |
| Tidak dihitung | WO DIBATALKAN; WO yang belum selesai di shift lain; WO dengan reschedule yang masih menunggu ACC (tetap di shift asalnya) |

Setiap WO dihitung sekali per ID, berapa pun riwayat reschedule-nya. Hanya jadwal yang berlaku yang menentukan. WO limpahan tidak dibuat ulang, dan ID, histori, serta relasinya tetap.

**Aturan WO manual (dikonfirmasi pengguna 29 Okt 2026, keputusan H):** WO APKUK manual Admin yang terjadwal di Posko dan shift yang sama ikut menempati kuota total enam.

| Pertanyaan | Perilaku |
|---|---|
| WO mana yang menempati slot? | Semua WO `PEMASANGAN_APKUK` dengan `posko_id` Posko itu, `work_date` = tanggal operasional, dan `shift_code` = shift itu, berstatus apa pun **kecuali DIBATALKAN**: limpahan sah, manual Admin (`ADMIN_MANUAL`), maupun hasil generator (`SYSTEM`) |
| WO manual dibuat **sebelum** generate | Mengurangi slot baru. Contoh: 1 manual + 8 kandidat → 5 WO baru, total 6 |
| WO manual dibuat **sesudah** generate | Boleh selama total < 6. Contoh: generate 2 (kekurangan 4) → Admin dapat menambah 4 WO manual; WO manual kelima ditolak. Angka alokasi tetap angka saat generate, dan WO nyata terkini tampil terpisah (§12) |
| WO manual saat total sudah ≥ 6 | **Ditolak** dengan pesan kuota penuh (jumlah limpahan dan WO lain disebutkan). Tidak ada WO yang dibatalkan atau dipindah, dan tidak ada override |
| Admin memindah jadwal WO APKUK ke shift lain (`WorkOrderEditor`) | Diperlakukan seperti WO manual baru di shift tujuan: ditolak bila shift tujuan penuh. Koreksi tanpa pindah jadwal tidak diperiksa |
| Reschedule disetujui (limpahan sah) | Tidak diperiksa kuota dan tetap boleh membuat total > 6; kelebihan dicatat dan ditampilkan |
| WO manual pada tiang yang sudah punya WO aktif | Ditolak oleh unique `active_target_key`, sama seperti sebelum B2 |

Hanya `ApkukWorkOrderService` (manual dan generator) dan `WorkOrderEditor` yang dapat menempatkan WO POSKO `PEMASANGAN_APKUK` ke sebuah shift. `TeamWorkOrderCreator` (WO HARSUS/ROW manual dan child limpahan) menolak tim POSKO. Workflow HARSUS, ROW, P0, dan PASILA tidak berubah.

## 7. Rumus kuota dan kekurangan

```
terisi      = limpahan + WO lain terjadwal
slot_baru   = max(0, 6 − terisi)
baru        = min(slot_baru, kandidat_sah_Posko)
total       = terisi + baru
kekurangan  = max(0, 6 − total)
kelebihan   = max(0, total − 6)
```

Bila tidak ada WO lain terjadwal, rumus ini identik dengan rumus final. Kelebihan dicatat sebagai metrik terpisah dan tidak pernah sebagai kekurangan negatif. Kelebihan juga tidak memicu pembatalan atau pemindahan WO.

| Skenario | Limpahan | Kandidat | Baru | Total | Kekurangan | Kelebihan |
|---|---:|---:|---:|---:|---:|---:|
| A | 0 | 8 | 6 | 6 | 0 | 0 |
| B | 2 | 10 | 4 | 6 | 0 | 0 |
| C | 2 | 2 | 2 | 4 | 2 | 0 |
| D | 6 | 10 | 0 | 6 | 0 | 0 |
| E | 8 | 10 | 0 | 8 | 0 | 2 |
| F | 0 | 0 | 0 | 0 | 6 | 0 |
| G | WO belum selesai tanpa reschedule sah | | tidak dihitung limpahan | | | |
| H | Reschedule berulang | | dihitung sekali pada jadwal yang berlaku | | | |

Semua skenario diuji (`ApkukShiftGeneratorTest`).

## 8. Generator WO

`App\Services\Apkuk\Generation\ApkukShiftGenerator::run(tanggal_operasional, shift, mode, pemicu)`

**Alur eksekusi:**
1. `ShiftInstance::ensure`.
2. Log eksekusi dibuat di `apkuk_generation_runs`.
3. Untuk GENERATE: periksa pengaman aktivasi dan pastikan shift belum berakhir.
4. Penilaian kandidat lewat `ApkukCandidateService`: snapshot aktif PR #11, tanpa pending dan histori, tanpa WO aktif, tanpa laporan lama atau ganda.
5. Pemetaan Posko lewat `SectionPoskoMap`; yang tidak terpetakan ditahan.
6. Kuota dihitung per Posko.
7. Kandidat diurutkan oleh `CandidateRanking`.
8. WO dibuat lewat `ApkukWorkOrderService::createSystem`, yang memakai jalur penyimpanan yang sama dengan WO manual (target, snapshot, log lifecycle).

**Pengaman:**
- **Idempotensi:** ada tiga lapis.
  - Satu transaksi per eksekusi generate, dengan advisory lock generator (`LOCK_KEY`), kunci kuota shift (`ApkukShiftQuota::lock`, juga dipegang WO manual Admin; §18), dan `ApkukSyncLock::shared`. Kandidat dan slot dinilai ulang di dalam kunci.
  - Baris `apkuk_shift_allocations` (unik per shift + Posko) diklaim sebelum WO dibuat. Posko yang sudah digenerate dilewati (`SUDAH_DIGENERATE`).
  - Unique `active_target_key` per tiang; savepoint per WO, jadi target yang ternyata sudah punya WO aktif dilewati tanpa membatalkan WO lain.
- **Eksekusi terlambat atau ulang:** shift yang sama tetap teridentifikasi sama. Shift yang sudah berakhir ditolak. Eksekusi ulang tidak menambah WO.
- **Satu kandidat hanya untuk satu Posko:** pemetaan menghasilkan satu Posko per kandidat, dan unique target mencegah tiang yang sama dipakai dua kali.
- **Provenance:** setiap WO SYSTEM menyimpan:
  - `generation_run_id`;
  - `created_by_user_id` = null (sistem);
  - log CREATED berisi strategi, versi, `is_machine_learning`, peringkat, skor, alasan, dan dasar pemetaan Posko.
- **Hasil yang tercatat:** per Posko tercatat limpahan, WO lain, baru, total, kekurangan, kelebihan, kandidat yang dibuat atau dilewati, dan kandidat yang ditahan beserta alasannya. Setiap eksekusi juga menyimpan rincian kategori seluruh laporan APKUK (`breakdown`, §12).

**Alokasi beku per shift + Posko (diputuskan desain, diperjelas di final review):**
- GENERATE pertama untuk sebuah shift mengklaim baris alokasi untuk **setiap** Posko aktif, termasuk Posko tanpa kandidat (kekurangan 6).
- Kekurangan pada shift itu **tidak diisi ulang otomatis**, walaupun kemudian muncul kandidat baru (sync APKUK, koreksi Section, atau penyelesaian antrean rekonsiliasi). Kandidat itu tetap sah untuk shift berikutnya.
- Pratinjau membaca alokasi yang sama. Posko yang sudah dialokasikan tampil "Sudah digenerate sebelumnya", dengan 0 WO akan dibuat, angka beku dari alokasi, dan jumlah kandidat sah saat ini. Pratinjau tidak lagi menjanjikan WO yang akan ditolak GENERATE.
- Admin tetap dapat membuat WO manual untuk shift tersebut sampai total 6 (lihat §6).

Contoh teruji: PUGUNG kuota 6 dengan 2 kandidat → 2 WO, kekurangan 4 → 3 kandidat baru muncul → pratinjau menampilkan 0 akan dibuat (kandidat sah saat ini 3, kekurangan tetap 4) → GENERATE ulang membuat 0 WO.

**Opsi pengisian ulang kekurangan (belum diimplementasikan, butuh keputusan pengguna):**

| Opsi | Cara kerja | Risiko konkurensi dan operasional |
|---|---|---|
| 1. Tetap beku (saat ini) | Sekali per shift + Posko | Paling sederhana dan mudah diaudit. Kandidat yang terlambat menunggu shift berikutnya |
| 2. Top-up eksplisit oleh Admin | Aksi "Isi kekurangan" per shift + Posko, hanya selama shift belum berakhir. Satu transaksi dengan advisory lock generator, `ApkukSyncLock::shared`, dan `SELECT … FOR UPDATE` pada baris alokasi. Slot dihitung ulang dari WO nyata: `max(0, 6 − terisi)`. Alokasi menyimpan jumlah kumulatif dan eksekusi top-up terakhir | Wajib memegang kunci kuota shift yang sama (§18) agar tidak bertabrakan dengan WO manual; tanpa itu total bisa 7 |
| 3. Top-up otomatis | Eksekusi berikutnya (scheduler atau ulang) mengisi slot kosong selama shift berjalan | Posko menerima WO baru di tengah shift tanpa pemberitahuan. Idempotensi berubah dari "sekali per shift" menjadi "maksimum 6 total", dengan risiko konkurensi yang sama seperti opsi 2 |

Pada semua opsi tetap berlaku: maksimum 6 WO normal per Posko per shift dari generator, limpahan sah dipertahankan, kelebihan tidak dibatalkan, tidak ada WO ganda (unique `active_target_key`), dan tidak ada WO fiktif.

**Risiko WO manual bersamaan dengan GENERATE:** diperbaiki (§18). Kunci kuota shift dipegang generator dan WO manual, sehingga keduanya tidak lagi menghasilkan total 7.

**Command CLI:**

```
php artisan apkuk:generate-shift --date=Y-m-d --shift=A|B|C
php artisan apkuk:generate-shift --current
php artisan apkuk:generate-shift --current --generate
```

Tanpa `--generate`, command menjalankan **pratinjau**. Dengan `--generate`, command hanya berjalan bila pengaman mengizinkan.

## 9. Strategi baseline seleksi kandidat

Kontrak `CandidateRanking` memiliki `name()`, `version()`, `isMachineLearning()`, dan `rank()`. `RankedCandidate` berisi peringkat, skor, dan alasan.

Implementasi saat ini adalah `BaselineCandidateRanking` versi `b2.1`, sebuah aturan **rule-based, bukan machine learning**:
- urutan: `kurang_bh` terbesar, lalu laporan tertua, lalu no. tiang dan ID laporan sebagai pemecah seri;
- deterministik, tanpa unsur acak, dan sama dengan urutan daftar kandidat WO manual;
- skornya null, karena aturan tidak menghasilkan probabilitas;
- dipakai sebagai pembanding model ML kelak.

## 10. Pengaman aktivasi (fail-closed)

Versi awal B2 hanya menambah syarat untuk `APP_ENV=production`. Padahal Neon production dijalankan dengan `APP_ENV=neon` (`.env.neon`), dan branch Neon workflows dengan `APP_ENV=workflows`. Dengan flag aktif, kedua lingkungan itu akan lolos dan baseline rule-based bisa membuat WO. Final review mengganti pengaman menjadi **allowlist**:

| Lapisan | Perilaku |
|---|---|
| Pratinjau | Selalu tersedia untuk Admin; hanya menulis log eksekusi, tidak pernah WO atau alokasi |
| `APKUK_GENERATOR_ENABLED` | Default **false** di semua lingkungan; GENERATE ditolak (status REFUSED) |
| Lingkungan | GENERATE hanya diizinkan bila APP_ENV termasuk `ApkukGeneratorGuard::DEVELOPMENT_ENVIRONMENTS` = `local`, `testing`. Nilai lain, termasuk `production`, `neon`, `workflows`, `staging`, dan nilai kosong, **selalu ditolak** dengan pesan bahwa model ML terlatih dan tervalidasi serta prosedur readiness diperlukan dan belum tersedia |
| Database | Di `local`/`testing`, koneksi non-SQLite wajib ke host lokal (`127.0.0.1`, `localhost`, `::1`). Host dari `DB_URL` diperiksa lebih dulu karena menimpa `DB_HOST`. Jadi `.env` lokal yang diarahkan ke Neon tetap ditolak |
| Penanda ML | `isMachineLearning()` hanya label provenance dan **bukan** izin. Strategi yang mengaku ML tetap ditolak di luar `local`/`testing` (diuji) |
| `APKUK_GENERATOR_PRODUCTION_ENABLED` | **Dihapus.** Tidak ada flag yang bisa membuka GENERATE di lingkungan operasional. Pembukaannya kelak memerlukan perubahan kode yang ditinjau, setelah model ML dan prosedur readiness disetujui |
| Scheduler | `routes/console.php` hanya mendaftarkan `apkuk:generate-shift --current --generate` (00:05/08:05/16:05 WIB) bila flag aktif; di luar `local`/`testing` setiap eksekusinya tetap REFUSED. Railway saat ini hanya menjalankan `railway-sync.sh`, bukan `schedule:run` |
| Web Admin | Tombol Generate nonaktif beserta alasannya bila pengaman memblokir; setiap eksekusi mencatat lingkungan aplikasi |

## 11. Kesiapan integrasi ML

**Yang sudah disiapkan:**
- Kontrak strategi dengan versi dan penanda ML.
- Pencatatan strategi, versi, peringkat, skor, dan alasan pada setiap eksekusi dan WO.
- Pengaman fail-closed: lingkungan operasional ditolak sampai model ML dan prosedur readiness tersedia.
- Pratinjau sebagai alat pembanding: model kelak bisa dibandingkan dengan baseline pada shift dan kandidat yang sama.

**Fitur yang benar-benar tersedia (lihat `OPERATIONAL_SPEC_GAP_AUDIT.md` §10.3):**
- Dari APKUK: `kurang_bh`, `terpasang_bh`, status, naik dari, umur laporan, koordinat, dan Section efektif.
- Frekuensi gangguan per tiang atau Section; `no_tiang` gangguan terisi 89% dan 367 cocok dengan master aset.
- Data aset beserta koordinatnya, dan data vegetasi.

**Yang belum ada:**
- **Label dan data latih:** belum ada label hasil pekerjaan (WO production 0) maupun dataset ber-versi; tidak ada label yang dikarang. Calon label terkuat adalah risiko gangguan BINATANG per tiang atau Section dalam horizon tertentu (ML-0, menunggu keputusan tujuan dan metrik).
- **Penyimpanan versi model:** `model_version` disimpan lewat `strategy_version`. Bila diperlukan, registri model bisa ditambahkan sebagai tabel terpisah.
- **Pencegahan kebocoran data:** fitur hanya boleh dari data sebelum titik potong, label tertunda dikecualikan, dan pendekatannya rolling-origin backtest (§10.4 audit).

## 12. Monitoring Admin

Halaman **Kuota Shift APKUK** (`/admin/kuota-shift`) ada di menu Pekerjaan. Menu Admin kini berjumlah 18.

- **Filter:** tanggal operasional dan shift; jam shift ditampilkan dalam WIB beserta penanda "berjalan".
- **WO nyata tersimpan per Posko:** target 6, limpahan, manual/lain, dari generator, total, slot kosong, kelebihan, dan status alokasi.
  - Bila sudah digenerate, kolom alokasi menampilkan eksekusi, waktu, **angka beku saat generate** (limpahan + lain + dibuat = total), dan kekurangan kandidat (tidak diisi ulang otomatis).
  - Bila WO nyata berubah setelah generate (WO manual, limpahan masuk, atau pembatalan), muncul "Berubah setelah generate: WO nyata kini N (±selisih)".
  - Kelebihan diberi keterangan sumbernya: "dari limpahan sah, dipertahankan" bila kelebihan ≤ jumlah limpahan. Bila tidak, muncul "melebihi limpahan: periksa WO lain"; ini hanya dapat terjadi pada data yang dibuat sebelum perbaikan §18.
- **Pratinjau setelah generate:** Posko beku juga menyebut "WO nyata saat pratinjau" (`current_total`), di samping angka beku alokasi.
- **Hasil pratinjau terakhir:** berlabel **SIMULASI**, per Posko, dengan kandidat terpilih (peringkat, tiang, kebutuhan) yang menaut ke Detail APKUK. Kolom "Akan dibuat" bernilai 0 untuk Posko yang alokasinya sudah beku, disertai keterangan eksekusi asal, kekurangan yang tidak diisi ulang, dan kandidat sah saat ini.
- **Rincian laporan APKUK** (`CandidateBreakdown`, disimpan di `apkuk_generation_runs.breakdown`):
  - **sah dan terpetakan**, per Posko;
  - **sah tetapi Section–Posko tidak terbukti** (ditahan), per alasan `SectionPoskoMap`;
  - **tidak sah**, per alasan utama `ApkukCandidateService`, dengan urutan prioritas: menunggu rekonsiliasi, sudah ada WO aktif, ada laporan lebih baru, laporan ganda ambigu, tidak ada kebutuhan, status bertentangan, status tidak dikenal, kurang kosong, kurang negatif, penyulang tidak dikenal, no. tiang tidak valid. Setiap laporan dihitung sekali; `issue_counts` menyimpan hitungan per alasan apa adanya;
  - **histori/diarsipkan** (`source_missing_at`), tidak ikut dinilai.

  Total laporan aktif = sah + tidak sah. Hanya kandidat sah yang Posko-nya tidak terbukti yang diberi label alasan Section; laporan pending, ber-WO aktif, atau bermasalah data tidak lagi tercampur dengan "Section tidak dikenal". SPS BOS tidak diubah. Command CLI mencetak ringkasan yang sama.
- **Kandidat ditahan:** tiang, penyulang, Section sumber/efektif, dan alasan; maksimal 100 baris ditampilkan.
- **Riwayat eksekusi:** mode, status, strategi (Model ML atau Rule-based), lingkungan, pelaksana, dan waktu.
- **Peringatan permanen:** sistem belum operasional, baseline bukan ML, dan pratinjau tidak membuat WO.
- **Akses:** khusus Admin aktif (guard yang sudah ada).

## 13. Migration dan file yang berubah

**Migration baru (additive):**

| Migration | Isi |
|---|---|
| `2026_10_29_000001_create_shift_instances_table` | `shift_instances` |
| `2026_10_29_000002_create_apkuk_generation_tables` | `apkuk_generation_runs` (termasuk JSON `summary`, `held`, `breakdown`), `apkuk_shift_allocations`, kolom nullable `work_orders.generation_run_id` |

Keduanya bergantung pada migration Batch A dan B1 yang belum dijalankan di production. Kolom `breakdown` ditambahkan langsung ke migration B2 karena migration itu belum pernah dijalankan di Neon. Schema audit lokal `batcha_audit_cfb5ba` sudah di-rollback 2 langkah lalu di-migrate ulang (khusus lokal). `down()` tetap menghapus semua objek B2 (diuji di PostgreSQL).

**File baru:**
- `app/Services/Apkuk/SectionPoskoMap.php`
- `app/Services/Apkuk/Generation/{ApkukShiftQuota, ApkukShiftGenerator, ApkukGeneratorGuard, CandidateRanking, BaselineCandidateRanking, RankedCandidate, CandidateBreakdown}.php`
- `app/Models/{ShiftInstance, ApkukGenerationRun, ApkukShiftAllocation}.php`
- `app/Http/Controllers/Admin/ShiftQuotaController.php`
- `app/Console/Commands/GenerateApkukShiftWorkOrders.php`
- `resources/views/admin/shift-quota/index.blade.php`
- tes `ApkukShiftGeneratorTest`, `SectionPoskoMapTest`
- dokumen ini

**File diubah:**
- `ShiftService`: hari operasional.
- `ApkukWorkOrderService`: `createSystem` dan jalur penyimpanan bersama; WO manual mengambil kunci kuota dan ditolak bila shift penuh (§18).
- `WorkOrderEditor`: pemindahan jadwal WO APKUK ke shift lain memeriksa kuota shift tujuan (§18); WO tim lain tidak berubah.
- `ApkukSectionService`: memakai `SectionPoskoMap`.
- `DashboardService`, `PasilaController`, `WorkOrderController`, `StoreApkukWorkOrderRequest`, `BootstrapController`: tanggal operasional.
- `WorkOrder`: relasi `generationRun`.
- `AppServiceProvider`: binding strategi.
- `AdminNavigation`: menu baru.
- `config/apkuk.php`: flag generator (`enabled` saja).
- `routes/web.php`, `routes/console.php`: scheduler terkunci flag. Pint juga merapikan indentasi blok `keypoint:sync` yang sudah ada; isinya tidak berubah.
- `dashboard.blade.php`: judul hari operasional.

**Tes yang diubah karena keputusan bisnis baru, bukan agar lulus:**

| Tes | Alasan |
|---|---|
| `ShiftServiceTest` (window Shift C) | Mengunci aturan lama "Shift C tanggal D = D 00–08". Sekarang C(D) = D+1 00–08, ditambah tes `operationalDate` |
| `ShiftBoundaryTest` (dibuat di B1) | Asersi tanggal kalender Shift C diganti tanggal operasional. Batas 07:59/08:00/15:59/16:00/23:59/00:00 tetap diuji, ditambah identitas `shift_instances` |
| `ApkukWorkOrderTest::test_schedule_must_not_be_in_the_past` | "Shift C tanggal 20 sudah lewat pada pukul 09:00" tidak berlaku lagi, karena C hari operasional 20 adalah malam 21 Okt. Maksud tes (shift lampau ditolak) diuji dengan Shift A pada pukul 17:00 |
| `AdminModuleAccessTest` | Jumlah menu 17 menjadi 18 (Kuota Shift APKUK) |

## 14. Hasil tes aktual (setelah perbaikan konkurensi kuota)

| Uji | Lingkungan | Hasil |
|---|---|---|
| Suite Laravel penuh | SQLite in-memory (`php artisan test`, tanpa `--env`) | **352 lulus, 2.991 assertion**, 0 gagal (final review: 346 / 2.938; baseline B1: 311 / 2.699) |
| Tes B2 dan yang terdampak | SQLite: `ApkukShiftGeneratorTest` (33), `SectionPoskoMapTest` (6), `ShiftServiceTest`, `ShiftBoundaryTest`, `ApkukWorkOrderTest`, `AdminModuleAccessTest`, `ApkukEndToEndFlowTest` | 89/89 lulus (945 assertion) |
| PostgreSQL terisolasi | Postgres 18 lokal, schema `b2test_*` (dihapus) | **33/33 OK**, rinciannya di bawah tabel ini |
| Cakupan pemetaan production | Neon production, **read-only** (sebelum final review) | 376 kandidat: 361 terpetakan, 15 ditahan |
| Browser (final review) | Edge headless, server lokal, schema audit lokal | **22/22 OK**, rinciannya di bawah tabel ini |
| Browser (setelah perbaikan kuota) | Edge headless, `php -S` lokal, schema sementara `b2browser_*` (dihapus) | **34/34 OK**, rinciannya di §18 |
| Pint `--test` | 38 file PHP B2 (diubah + baru, termasuk `WorkOrderEditor`) | Lulus |
| Pint `--test` | Seluruh proyek | Gagal pada 17 file lama yang **tidak disentuh B2** (identik dengan HEAD `2308c4f`), misalnya `AuditTopology`, `CheckApkukGoogle`, `ApkukCandidateService`. Tidak diperbaiki agar diff B2 tetap fokus. Laporan sebelumnya yang hanya menyebut dua file kurang lengkap |
| `git diff --check` | Seluruh diff | Bersih |
| `npm run build` | Vite | Berhasil |

Rincian uji PostgreSQL (33 pemeriksaan; 13 terakhir untuk perbaikan kuota dan pemindahan jadwal, dirinci di §18):
- migrate 26/26, unique constraint, rollback dan re-migrate B2 (termasuk kolom `breakdown`);
- pratinjau tanpa WO, limpahan 2 + baru 4, DUMMY ditahan;
- generate, kekurangan WAY JEPARA, eksekusi ulang idempoten, limpahan tetap utuh;
- **final review:** pratinjau setelah generate menampilkan alokasi beku tanpa WO baru (WAY JEPARA kekurangan tetap 3); rincian tersimpan sebagai JSON (sah 7 = terpetakan 6 + ditahan 1; WO aktif 7); `APP_ENV=neon` menolak GENERATE tanpa WO maupun alokasi;
- **konkurensi nyata:** dua proses generator bersamaan menunggu advisory lock (3,3 dan 3,5 detik). Tepat satu yang mengisi kuota, total 6 WO (bukan 12), satu `shift_instance`, dan tanpa target aktif ganda.

Rincian uji browser (22 pemeriksaan):
- menu dan halaman Kuota Shift, peringatan belum operasional;
- tombol Generate nonaktif;
- pratinjau dari Web Admin berlabel SIMULASI, dengan kandidat ditahan beserta alasannya;
- **final review:** peringatan fail-closed (hanya `local`/`testing`); panel Rincian laporan APKUK dengan tiga kategori. Data schema audit: tidak sah 29 = menunggu rekonsiliasi 4, WO aktif 8, laporan lebih baru 1, tidak ada kebutuhan 7, status bertentangan 9; ditahan 25 = Section tidak ada di SECTION_PIC_RAW;
- kandidat hasil koreksi B1 terpetakan ke PUGUNG;
- tampilan seluler tanpa scroll horizontal;
- Dashboard menyebut hari operasional;
- 0 error console.

## 15. Status per bagian

| Bagian | Status | Catatan |
|---|---|---|
| B2.1 Identitas shift dan hari operasional | COMPLETED | — |
| B2.2 Validasi Section–Posko | COMPLETED | Bergantung pada kelengkapan SECTION_PIC_RAW |
| B2.3 Kuota dan carry-over | COMPLETED | WO manual menempati slot (dikonfirmasi); WO manual dan pindah jadwal Admin ditolak bila penuh, di bawah kunci kuota (§18) |
| B2.4 Generator WO | COMPLETED (lokal/uji) | Alokasi beku per shift + Posko; top-up menunggu keputusan (§8). Rute kunjungan belum termasuk |
| B2.5 Pengaman aktivasi | COMPLETED | Fail-closed: hanya `local`/`testing` dengan database lokal; lingkungan lain selalu ditolak (§10) |
| B2.6 Monitoring Admin | COMPLETED | Termasuk rincian alasan laporan (§12) |
| B2.7 Persiapan ML | PARTIAL | Kontrak dan pencatatan siap; dataset, label, dan model belum ada (bukan scope B2) |
| Operasional sistem | **BLOCKED** | ML wajib (keputusan pengguna), PASILA/P0, Flutter, dan deploy Batch A/B1/B2 |

## 16. Risiko produksi dan dependency berikutnya

| Risiko / dependency | Dampak | Langkah |
|---|---|---|
| ML belum ada | Generator tidak boleh dipakai operasional | Tahap ML-0…ML-6 (audit §10.5) |
| Cakupan Section–Posko | SRIBHAWONO, JABUNG, TRIDATU tanpa kandidat; 15 kandidat ditahan | Lengkapi SECTION_PIC_RAW atau spreadsheet aset; koreksi Section B1 |
| PASILA/P0 belum ada | Kewajiban POSKO per shift belum berjalan; generator APKUK tidak menggantikannya | Batch berikutnya (form P0 masih TBD) |
| Flutter belum fungsional | WO tidak dapat dikerjakan dari lapangan | Batch Flutter |
| Deploy | Production belum punya tabel Batch A, B1, B2 | Rencana deploy terpisah dengan backup; urutan migration Batch A → B1 → B2 |
| Rute kunjungan | Urutan kerja belum dioptimasi | Butuh titik awal per Posko (data belum ada) |
| Kekurangan tidak diisi ulang | Kandidat yang muncul setelah GENERATE menunggu shift berikutnya (Admin dapat mengisi manual sampai 6) | Keputusan opsi top-up (§8) |
| Tidak ada override kuota | Admin tidak dapat menambah WO APKUK manual ketujuh pada shift penuh, walau ada kebutuhan mendesak | Keputusan pengguna bila override diperlukan (§18) |
| Data uji lokal | WO Shift C lama di schema uji terbaca sebagai hari operasional | Tidak berdampak ke production (0 WO) |

## 17. Final review B2 (29 Okt 2026)

**Temuan yang diperbaiki:**

| # | Temuan | Perbaikan | Bukti |
|---|---|---|---|
| 1 | Pengaman hanya mengunci `APP_ENV=production`, padahal Neon berjalan sebagai `neon`/`workflows`, sehingga baseline bisa membuat WO bila flag aktif. Penanda `isMachineLearning()` juga dianggap cukup | Allowlist `local`/`testing` + database lokal (memeriksa `DB_URL` dan `DB_HOST`); flag `APKUK_GENERATOR_PRODUCTION_ENABLED` dihapus; label ML bukan izin | Tes `production`, `neon`, `workflows`, `staging`, kosong (dengan strategi yang mengaku ML) → REFUSED, 0 WO, 0 alokasi; `local`/`testing` lolos; host Neon via `DB_HOST` maupun `DB_URL` ditolak; PG `APP_ENV=neon` ditolak |
| 2 | Pratinjau mengabaikan alokasi, sehingga setelah GENERATE dengan kekurangan ia menjanjikan WO baru yang lalu dilewati GENERATE | Pratinjau membaca alokasi; Posko beku tampil 0 akan dibuat beserta keterangan; konsekuensi dan opsi top-up didokumentasikan (§8) | Tes skenario PUGUNG 2 → kekurangan 4 → 3 kandidat baru → pratinjau 0 → GENERATE 0; PG WAY JEPARA |
| 3 | Monitoring hanya menampilkan kandidat sah yang ditahan; laporan tidak sah tidak terlihat alasannya | `CandidateBreakdown` + kolom `breakdown` + panel Rincian laporan APKUK + ringkasan CLI | Tes kategori (terpetakan per Posko, ditahan per alasan, pending, WO aktif, laporan lebih baru, tidak ada kebutuhan, penyulang tidak dikenal, histori); browser 22/22 |
| 4 | Interpretasi WO manual belum cukup rinci | §6 diperjelas; aturannya tidak diubah | — |

**Butuh keputusan pengguna:**
1. ~~WO APKUK manual Admin di dalam atau di luar kuota enam (§6).~~ Diputuskan 29 Okt: di dalam kuota (keputusan H).
2. Opsi pengisian ulang kekurangan: tetap beku (dipakai untuk B2), top-up eksplisit Admin, atau top-up otomatis (§8).
3. Prosedur readiness ML (kriteria validasi, persetujuan, dan siapa yang membuka pengaman) sebelum GENERATE diizinkan di lingkungan operasional.
4. Override kuota untuk WO manual Admin di atas enam (§18). Belum dibuat.

## 18. Perbaikan konkurensi kuota WO manual (29 Okt 2026)

**Akar masalah:**

| # | Temuan | Akibat |
|---|---|---|
| 1 | `ApkukWorkOrderService::createManual` sama sekali tidak memeriksa kuota | WO manual **setelah** generator selesai (atau setelah enam WO manual) diam-diam menjadikan total 7, 8, dan seterusnya |
| 2 | Generator memegang `LOCK_KEY` + `ApkukSyncLock::shared`, sedangkan WO manual hanya `ApkukSyncLock::shared`. Kunci shared tidak saling menunggu, dan generator membaca slot sekali di awal | WO manual yang di-commit saat generator berjalan tidak terhitung: generator membuat 6 dan total menjadi 7 (dibuktikan oleh kontrol negatif PG) |
| 3 | `WorkOrderEditor` dapat memindah WO APKUK ke shift lain tanpa memeriksa kuota | Kuota bisa dilewati dengan membuat WO di shift kosong lalu memindahnya ke shift penuh |

**Perbaikan:**
- `ApkukShiftQuota::lock(tanggal_operasional, shift)`: advisory lock transaksi PostgreSQL dalam bentuk dua-int, `pg_advisory_xact_lock(720261029, hashtext('tanggal|shift'))`.
  - Ruang kuncinya terpisah dari kunci bigint generator dan `ApkukSyncLock`, dan berlaku per shift untuk semua Posko.
  - Memakai `lock_timeout` 10 detik. Bila waktu habis, pengguna menerima pesan validasi "sedang diproses, coba lagi", bukan error 500.
  - SQLite (tes) tidak memakai kunci ini.
- `ApkukShiftQuota::assertSlotAvailable(Posko, tanggal, shift)`:
  - menghitung limpahan sah + WO lain dari database **setelah** kunci didapat;
  - menolak bila hasilnya ≥ 6, dengan pesan yang menyebut Posko, shift, hari operasional, jumlah limpahan, dan WO lain. Contoh: "Kuota WO APKUK Posko WAY JEPARA Shift A hari operasional 30/10/2026 sudah penuh (kuota 6, terisi 8: limpahan sah 2, WO lain 6)."
- Pemakai kunci dan pemeriksaan:

  | Jalur | Kunci | Pemeriksaan slot |
  |---|---|---|
  | Generator GENERATE | `LOCK_KEY` → kuota → `ApkukSyncLock::shared` | Dihitung generator dari WO nyata di bawah kunci |
  | WO manual Admin | kuota → `ApkukSyncLock::shared` | `assertSlotAvailable` sebelum menyimpan |
  | Pindah jadwal WO APKUK oleh Admin | baris WO (`FOR UPDATE`) → kuota | `assertSlotAvailable` pada shift tujuan |
  | Reschedule disetujui (limpahan sah) | tanpa kunci kuota | Tidak diperiksa; limpahan boleh melebihi 6 |
  | Pratinjau | tanpa kunci | Hanya simulasi |

- **Urutan kunci dan deadlock:**
  - Urutan tetap: kunci generator → kunci kuota → `ApkukSyncLock`.
  - `apkuk:sync`/rekonsiliasi hanya memegang `ApkukSyncLock` eksklusif dan tidak pernah meminta kunci kuota, jadi tidak ada siklus.
  - Pemegang kunci kuota (generator, WO manual) tidak pernah menunggu kunci baris WO yang sudah ada. Karena itu editor boleh mengambil kunci kuota setelah mengunci baris WO-nya.
- **Limpahan bersamaan dengan generator:** reschedule yang disetujui tepat saat GENERATE berjalan hasilnya sama dengan reschedule yang disetujui sesudahnya: total bisa 7 dan kelebihannya tercatat sebagai limpahan sah. Ini sesuai aturan, sehingga jalur reschedule sengaja tidak diubah.
- **Monitoring:** angka beku alokasi dan WO nyata terkini ditampilkan terpisah, termasuk selisihnya dan sumber kelebihan (§12).
- **Tanpa override:** tidak ada mekanisme override kuota baru. Bila Admin perlu WO ketujuh di luar limpahan, itu keputusan terpisah (keputusan 4 di §17). Desain yang disarankan: alasan wajib + audit + tetap di bawah kunci kuota.

**Bukti uji:**

| Skenario | SQLite (`ApkukShiftGeneratorTest`) | PostgreSQL lokal terisolasi |
|---|---|---|
| WO manual sebelum generator | 2 manual + 8 kandidat → 4 baru, total 6 | 1 manual → generator 5 baru, total 6 |
| WO manual setelah kuota penuh | Ditolak dengan pesan lengkap; Posko lain/shift lain tetap boleh | Ditolak, tetap 6 |
| Kekurangan diisi manual | Generate 2 → 4 manual boleh, yang kelima ditolak; alokasi tetap 2/kekurangan 4; halaman menampilkan "WO nyata kini 6 (+4)" | — |
| Manual dan generator bersamaan | — (SQLite serial) | Manual lambat lebih dulu: generator menunggu 4,5 detik lalu membuat 5 → total 6. Generator lambat lebih dulu: manual menunggu 4,3 detik lalu ditolak → total 6 |
| `apkuk:sync` memegang kunci eksklusif | — | Generator (2,5 dtk) dan manual (2,1 dtk) antre tanpa deadlock → total 6 |
| Kontrol negatif (jalur lama tanpa kunci kuota) | — | Generator tidak menunggu (0,4 dtk) dan total menjadi **7**, membuktikan akar masalah dan bahwa uji di atas membedakan |
| Delapan limpahan sah | Utuh; generator 0 baru; manual ditolak; reschedule kesembilan yang disetujui tetap masuk (total 9, kelebihan 3, "dari limpahan sah") | 8 utuh, kelebihan 2 tercatat, manual ditolak |
| Dua generator paralel | — | Idempoten, total 6 (§14) |
| Shift C | 30 Okt 02:00 WIB: generate C hari 29 → 6 WO mulai 30 Okt 00:00 WIB; manual C hari 29 ditolak, C hari 30 boleh | C hari operasional D: 6 WO mulai D+1 00:00 WIB; manual C yang sama ditolak |
| Pindah jadwal oleh Admin (`WorkOrderEditor` asli) | Ke shift penuh ditolak; koreksi catatan dan pindah ke shift yang kosong tetap boleh | Editor lambat lebih dulu: generator menunggu 4,5 detik lalu membuat 5 → total 6, WO pindah dengan 1 log UPDATED. Generator lambat lebih dulu: editor menunggu 4,1 detik lalu ditolak → total 6; WO tetap di shift asal tanpa log UPDATED (transaksi dibatalkan utuh) |
| Semua jalur bersamaan | — | `apkuk:sync` eksklusif + generator + WO manual + editor pada shift yang sama: generator 5, manual dibuat, editor ditolak → total 6, tanpa deadlock, tanpa target aktif ganda |
| B1 dan alur manual lama | Suite penuh 352/352 | — |

**Uji browser setelah perbaikan (Edge headless, 34/34):** server `php -S` lokal di atas schema PostgreSQL sementara yang diisi data uji (bukan schema audit, bukan Neon), lalu dihapus. Yang diperiksa:
- **Tabel WO nyata:**
  - PUGUNG menampilkan angka beku "limpahan 0 + lain 0 + dibuat 2 = 2", kekurangan 4 (tidak diisi ulang otomatis), dan "WO nyata kini 4 (+2)".
  - WAY JEPARA menampilkan beku 6, WO nyata kini 8 (+2), dan kelebihan 2 "dari limpahan sah, dipertahankan".
  - SRIBHAWONO menampilkan beku 0 dan kekurangan 6.
- **Pratinjau:** status "Sudah digenerate sebelumnya" dengan "WO nyata saat pratinjau".
- **Rincian kandidat:** terpetakan, ditahan (DUMMY), dan tidak sah (rekonsiliasi, WO aktif, tidak ada kebutuhan); histori 1.
- **Peringatan kuota pada form Buat WO APKUK:** HTTP 302 kembali ke form dengan pesan kuota penuh, dan WO tidak dibuat.
- **Peringatan kuota pada form Ubah jadwal:** pesan yang sama; jadwal WO tidak berubah.
- **Pemeriksaan database setelah uji:** WAY JEPARA tetap 8 WO dan WO pindahan tetap di jadwal asal.
- **Seluler 390 px:** Kuota Shift dan form ubah tanpa scroll horizontal; tabel dapat digeser di dalam panelnya.
- **Console:** 0 error.

Uji browser menemukan satu masalah teks: pesan semula berbunyi "sudah penuh: 8 dari 6", yang membingungkan saat kelebihan. Pesan diubah menjadi "sudah penuh (kuota 6, terisi 8: …)". Tes SQLite, PG, dan browser diulang setelah perubahan ini.
