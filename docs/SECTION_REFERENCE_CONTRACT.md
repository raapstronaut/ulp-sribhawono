# Kontrak Data Referensi Section → Posko

- **Berlaku sejak:** Batch B3.1 (30 Oktober 2026)
- **Pembaca:** pemilik data SPS BOS/atasan yang menyiapkan data resmi, dan Admin/pengembang yang memasukkannya.
- **Tujuan:** data resmi Section–Penyulang–Posko cukup diisi sesuai format ini, tanpa perubahan kode atau logika bisnis.
- **Status data saat ini:** isi `SECTION_PIC_RAW` yang ada sekarang adalah data kerja dari spreadsheet `MASTER_DATA`, **belum disahkan** sebagai referensi resmi (DT-1). Sistem memakainya apa adanya, dan baris yang tidak lengkap atau ambigu ditahan.

## 1. Prinsip

1. **Satu sumber kepemilikan.** Kepemilikan Section → Posko hanya dibaca dari tab `SECTION_PIC_RAW` (staging `section_pic_source_rows`) melalui `App\Services\Apkuk\SectionPoskoMap`. Generator WO APKUK (B2) dan sesi PASILA (B3) memakai pemetaan yang sama. Tidak ada daftar Section yang ditulis di kode.
2. **Kepemilikan tetap.** Setiap Section dipegang tepat satu Posko. Kepemilikan tidak berpindah antar-Posko per shift. Yang berganti setiap shift hanya rekomendasi Section yang dipatroli PASILA.
3. **Satu Posko, banyak Section.** Satu Posko boleh memegang banyak Section di banyak Penyulang. Satu Penyulang boleh berisi Section milik beberapa Posko.
4. **Tidak menebak.** Posko tidak pernah diturunkan dari Penyulang. Baris yang tidak lengkap atau ambigu **ditahan** beserta alasannya dan tidak dipakai untuk penugasan.

## 2. Format kolom

Lokasi: tab **`SECTION_PIC_RAW`** pada spreadsheet `MASTER_DATA` (ID di `GOOGLE_SHEETS_SPREADSHEET_ID`). Untuk validasi sebelum diisi ke spreadsheet, isi yang sama dapat disimpan sebagai berkas CSV.

| Kolom | Header (persis) | Isi | Wajib |
|---|---|---|---|
| A | `PENYULANG` | Nama Penyulang | Pada baris pertama tiap kelompok; baris berikutnya boleh kosong (mewarisi nilai di atasnya) |
| B | `PEMUTUS` | Nama Section (pemutus/LBS/recloser) | Ya |
| C | `POSKO PIC` | Nama Posko penanggung jawab | Ya |

**Aturan header** (sama dengan importer `sources:import`):
- Tepat tiga kolom dengan nama persis seperti tabel, huruf kapital, urutan A–C.
- Kolom tambahan di sebelah kanan hanya boleh kosong. Kolom tambahan berisi membuat import gagal.
- Baris kosong sebelum header diabaikan.

**Aturan nilai:**

| Kolom | Aturan |
|---|---|
| `PENYULANG` | Salah satu dari 16 Penyulang master aktif. Kapital, spasi tepi, dan titik di akhir tidak berpengaruh. Alias terkonfirmasi: `TANGGAMUS` → `TANGGAMUS 2`. Bila kosong, baris memakai Penyulang terakhir di atasnya (*fill-down*). Baris sebelum Penyulang pertama diabaikan. |
| `PEMUTUS` | Nama Section unik di ULP. Section yang sama tidak boleh dicatat pada dua Penyulang. Kapital dan spasi ganda tidak berpengaruh. Nilai kosong, `-`, dan `DUMMY` diabaikan. |
| `POSKO PIC` | Tepat satu Posko master aktif (nama atau kode). Alias terkonfirmasi: `SRIBAWONO` → `SRIBHAWONO`. Section yang sama boleh muncul di beberapa baris asalkan POSKO PIC-nya sama. |

Contoh:

```csv
PENYULANG,PEMUTUS,POSKO PIC
REOG,LBSM GUNUNG MAS,PUGUNG
,REC BRAJA DEWA,WAY JEPARA
BEDANA,LBSM BEDANA,PUGUNG
```

Pada contoh ini, PUGUNG memegang dua Section di REOG dan BEDANA, sedangkan WAY JEPARA memegang satu Section di REOG.

## 3. Hasil validasi

Setiap pasangan Section + Penyulang diperiksa. Hanya pasangan **terverifikasi** yang dapat ditugaskan (generator APKUK maupun PASILA). Pasangan lain ditahan:

| Kode | Arti | Cara memperbaiki |
|---|---|---|
| `PENYULANG_TIDAK_DIKENAL` | Penyulang tidak cocok master aktif | Perbaiki ejaan Penyulang |
| `SECTION_MULTI_PENYULANG` | Section tercatat di lebih dari satu Penyulang | Satu Section hanya di satu Penyulang |
| `POSKO_PIC_KOSONG` | POSKO PIC kosong | Isi POSKO PIC |
| `POSKO_TIDAK_DIKENAL` | POSKO PIC tidak cocok master Posko aktif | Perbaiki nama Posko |
| `POSKO_AMBIGU` | Section memiliki lebih dari satu POSKO PIC | Tetapkan satu Posko |
| `SECTION_KOSONG`, `SECTION_DUMMY`, `SECTION_TIDAK_DIKENAL`, `SECTION_PENYULANG_LAIN` | Khusus pencocokan Section laporan APKUK (B2) | Lihat laporan B2 §5 |

Posko aktif tanpa satu pun Section terverifikasi tetap mendapat sesi PASILA setiap shift, dengan status **menunggu penentuan Section**.

## 4. Prosedur memasukkan data resmi

1. **Validasi tanpa menulis apa pun.** Simpan calon data sebagai CSV (pemisah `,` atau `;`), lalu jalankan:
   ```
   php artisan pasila:section-references --file=/path/calon-section.csv
   ```
   Perintah ini mencetak Section terverifikasi per Posko, referensi yang ditahan beserta alasannya, dan Posko yang belum memiliki Section, lalu baris ringkasan `SECTION_REFERENCES ...`. Tidak ada data yang diimport.
2. **Perbaiki** baris yang ditahan sampai daftar ditahan kosong atau memang disengaja.
3. **Isi tab `SECTION_PIC_RAW`** di spreadsheet dengan isi yang sudah lolos validasi. Ini dikerjakan pemilik data; pengembang tidak mengubah Google Sheets.
4. **Import hanya tab Section** (perlu persetujuan bila targetnya production):
   ```
   php artisan sources:import --only=SECTION_PIC_RAW --replace
   php artisan topology:sync-core-masters
   ```
   `--only` membatasi import ke tab ini, sehingga `--replace` tidak menghapus staging `ASSET_RAW`/`VEGETASI_RAW`. Tanpa `--only` ketiga tab diproses: tanpa `--replace` tab yang sudah berisi ditolak, sedangkan dengan `--replace` ketiganya diganti (lihat `SOURCE_IMPORT_RUNBOOK.md`). Command kedua hanya memperbarui relasi tampilan Posko ↔ Penyulang.
5. **Konfirmasi** pemetaan aktif:
   ```
   php artisan pasila:section-references
   ```

### Pengaman importer untuk `SECTION_PIC_RAW`

Importer membaca Google Sheets dengan akses read-only dan tidak pernah menulis ke spreadsheet. Referensi lama **tetap utuh** dalam kondisi berikut:

| Kondisi | Perilaku |
|---|---|
| Header salah (urutan, huruf, atau kolom tambahan berisi), tab kosong, atau gagal membaca Google | Import ditolak sebelum menyentuh database |
| Tab hanya berisi header | Tidak ada yang diimport; staging lama tidak dihapus |
| Staging sudah berisi tetapi dijalankan tanpa `--replace` | Ditolak. Tab ini adalah snapshot utuh; tanpa `--replace` baris baru bercampur dengan baris lama dan pewarisan kolom PENYULANG menjadi salah |
| Isi baru tidak menghasilkan satu pun Section terverifikasi (mis. kolom tertukar) | Ditolak; referensi lama dipertahankan |
| Gagal di tengah penyisipan | Transaksi dibatalkan; staging lama utuh |

Bila import diterima, importer mencetak jumlah Section terverifikasi sebelum dan sesudah, Section yang tidak lagi terverifikasi, serta Section yang berganti Posko (`Berganti Posko: <Section> (<Penyulang>), dari <Posko lama> ke <Posko baru>`) untuk ditinjau. Import ulang data yang sama dengan `--replace` tidak menggandakan baris.

### Bila format data resmi berbeda

Format pada §2 adalah format yang dibaca sistem saat ini. Bila data resmi kelak datang dalam bentuk lain (mis. kolom ID Section, kode Posko, satu baris per Section tanpa pewarisan PENYULANG, atau tab/berkas lain), yang perlu disesuaikan hanya **importer atau pemetaan kolomnya**. Selama hasilnya tetap berupa daftar (Penyulang, Section, Posko), `SectionPoskoMap`, sesi PASILA, penetapan Section oleh Admin, dan generator WO APKUK membaca hasil yang sama, sehingga logika PASILA/P0 tidak perlu ditulis ulang.

## 5. Dampak setelah data diganti

- **Langsung berlaku** untuk sesi PASILA baru, penetapan Section oleh Admin, dan generator WO APKUK. Tidak ada cache yang perlu dibersihkan.
- **Sesi PASILA yang sudah ada tidak diubah.** Section-nya adalah catatan shift tersebut. Halaman PASILA menandai sesi yang Section-nya tidak lagi terverifikasi atau kini milik Posko lain.
- **Riwayat tidak ditulis ulang.** Shift lampau tidak dibuka mundur dan Section-nya tidak diubah.
