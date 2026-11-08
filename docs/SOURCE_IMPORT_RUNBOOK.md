# PANDUAN IMPORT DATA SUMBER DAN KOORDINAT PETA
## ULP Sribhawono — untuk pengembang dan Admin teknis

**Status:** mengikuti kode per 2 November 2026 (`app/Console/Commands/ImportSourceSheets.php`, `app/Support/OperationalArea.php`).

# 1. Sumber dan tabel tujuan

Semua tab dibaca dari spreadsheet `MASTER_DATA` dengan scope read-only. Tidak ada command yang menulis ke Google Sheets. Tidak ada scheduler atau skrip Railway yang menjalankan `sources:import`.

| Tab | Tabel | Sifat data | Command | Bila diulang |
|---|---|---|---|---|
| `KEYPOINT_RAW` | `keypoints` | Sinkron | `keypoint:sync` | Aman (upsert); ada `--dry-run` |
| `APKUK_RAW` | `apkuks` | Sinkron + rekonsiliasi | `apkuk:sync` | Aman (pengaman snapshot); ada `--dry-run` |
| `GANGGUAN_PUSAT_IMPORT` | `gangguans` | Sinkron | `gangguan:sync` | Aman (upsert); ada `--dry-run` |
| `ASSET_RAW` | `asset_source_rows` | **Staging** | `sources:import --only=ASSET_RAW` | Wajib `--replace` |
| `VEGETASI_RAW` | `vegetasi_source_rows` | **Staging** | `sources:import --only=VEGETASI_RAW` | Wajib `--replace` |
| `SECTION_PIC_RAW` | `section_pic_source_rows` | **Staging**, sumber kepemilikan Section | `sources:import --only=SECTION_PIC_RAW` | Wajib `--replace`; lihat `SECTION_REFERENCE_CONTRACT.md` |

Tab `MASTER_POSKO`, `ASSET_REVIEW_RAW`, dan `CEK_APKUK` tidak memiliki importer.

# 2. Struktur ASSET_RAW (10 kolom)

Header diperiksa persis, termasuk urutan dan huruf besar-kecil.

| Kolom | Header | Kolom tabel |
|---|---|---|
| A | `ID_ASET` | `id_aset_raw` |
| B | `NAMA_ASET` | `nama_aset_raw` |
| C | `JENIS_ASET` | `jenis_aset_raw` |
| D | `PENYULANG` | `penyulang_raw` |
| E | `LATITUDE` | `latitude_raw` |
| F | `LONGITUDE` | `longitude_raw` |
| G | `STATUS` | `status_raw` |
| H | `Foto` | `foto_raw` |
| I | `Section` | `section_raw` |
| J | `Posko` | `posko_raw` |

Per 2 November 2026 tab ini berisi 17.059 baris, dan kolom `Foto`, `Section`, serta `Posko` kosong di semua baris. Format 7 kolom lama ditolak importer.

# 3. Aset dan vegetasi masih staging

- `asset_source_rows` dan `vegetasi_source_rows` adalah snapshot mentah, bukan master aset atau vegetasi yang sudah disahkan.
- `section_raw` dan `posko_raw` pada ASSET_RAW serta `section_raw` pada VEGETASI_RAW **bukan referensi kepemilikan**. Kepemilikan Section → Posko hanya dari `SECTION_PIC_RAW` melalui `SectionPoskoMap`.
- Target WO ROW merujuk `ID POHON`, bukan ID baris staging, karena `--replace` membuat ID baris baru.
- Semua nilai disimpan sebagai teks. Importer hanya memangkas spasi di tepi nilai dan BOM, menyimpan sel kosong sebagai NULL, dan mengabaikan isi sel di luar kolom header. Pada pemeriksaan 2 November 2026, ASSET_RAW tidak memiliki sel berspasi di tepi, sedangkan VEGETASI_RAW memiliki 8.053 sel seperti itu. Koordinat kedua tab sama persis dengan nilai sel di sumber.

# 4. Pengaman importer

| Keadaan | Perilaku |
|---|---|
| Staging kosong | Import boleh tanpa `--replace` |
| Staging ASSET_RAW/VEGETASI_RAW berisi, tanpa `--replace` | Ditolak sebelum membaca Google; data lama tidak berubah; exit 1 |
| Staging SECTION_PIC_RAW berisi, tanpa `--replace` | Ditolak oleh pengaman Section; data lama tidak berubah; exit 1 |
| Header atau jumlah kolom tidak cocok | Ditolak, juga bila memakai `--replace`; data lama tidak berubah |
| Google gagal dibaca atau tab kosong | Ditolak; data lama tidak berubah |
| Tab hanya berisi header | Tidak ada perubahan, juga bila memakai `--replace`; exit 0 dengan peringatan |
| `--replace` dengan sumber valid | Hapus lalu isi dalam satu transaksi; bila gagal, data lama tetap |
| Tanpa `--only` | Ketiga tab diproses satu per satu; tab yang ditolak tidak menghalangi tab lain, dan command berakhir exit 1 |

**Belum ada pengaman jumlah baris minimum untuk `--replace` pada ASSET_RAW dan VEGETASI_RAW.** Bila tab sedang terpotong (mis. `IMPORTRANGE` belum selesai) tetapi headernya benar, staging akan diganti dengan data yang lebih sedikit. Karena itu langkah 3 di bawah wajib.

# 5. Cara import ulang yang aman

1. **Pastikan target.** Di lokal: host `127.0.0.1`, database `ulp_sribhawono_dev`, dan berkas `bootstrap/cache/config.php` tidak ada. Jangan memakai `--env` atau `.env.neon` untuk pekerjaan lokal.
2. **Backup**, simpan di luar repository, lalu periksa isinya:
   ```
   pg_dump --host=127.0.0.1 --username=<user> --dbname=ulp_sribhawono_dev --format=custom --file=D:\BACKUP\ULP_SRIBAWONO\ulp_sribhawono_dev_<waktu>.dump
   pg_restore --list D:\BACKUP\ULP_SRIBAWONO\ulp_sribhawono_dev_<waktu>.dump
   ```
3. **Bandingkan jumlah baris** tab sumber dengan isi staging. Bila sumber turun drastis, berhenti dan tanyakan pemilik data. `sheets:profile-sources --sheet=ASSET_RAW` dapat dipakai, tetapi command ini mencetak Spreadsheet ID lengkap, jadi jangan bagikan keluarannya.
4. **Import satu tab**, selalu dengan `--only`:
   ```
   php artisan sources:import --only=ASSET_RAW --replace
   ```
   Jangan memakai `--replace` tanpa `--only`, karena ketiga tab akan diganti sekaligus.
5. **Periksa hasil:** `php artisan sources:audit-staging`, lalu bandingkan jumlah barisnya dengan langkah 3.
6. **Untuk SECTION_PIC_RAW**, ikuti `SECTION_REFERENCE_CONTRACT.md`, lalu jalankan `topology:sync-core-masters` dan `pasila:section-references`.

Untuk `keypoint:sync`, `apkuk:sync`, dan `gangguan:sync`, jalankan `--dry-run` lebih dulu.

Jangan menjalankan `migrate:fresh`, `db:wipe`, atau `php artisan test --env=...` pada database yang berisi. `php artisan test` tanpa `--env` memakai SQLite in-memory (dipaksa `phpunit.xml`).

# 6. Aturan koordinat Peta Jaringan

Berlaku untuk Keypoint dan APKUK di `/admin/maps/data`.

| Keadaan koordinat | Kode | Perlakuan |
|---|---|---|
| Di dalam wilayah operasional | — | Marker; ikut menentukan batas tampilan peta |
| Latitude atau longitude bernilai 0 | `KOORDINAT_NOL` | Tidak dipetakan; tampil di tab **Koordinat Invalid** |
| Di luar wilayah operasional | `DI_LUAR_WILAYAH` | Tidak dipetakan; tampil di tab **Koordinat Invalid** |
| Kosong | — | Tidak dipetakan; untuk APKUK dihitung "APKUK tanpa GPS" |

- **Wilayah operasional** (`config/maps.php`): lat −5,95 s/d −4,70, lon 105,25 s/d 106,10. Angka ini diturunkan dari sebaran 17.059 aset ASSET_RAW ditambah margin sekitar ±22 km. Ini bukan batas administrasi resmi; ubah di file itu bila jaringan bertambah.
- Hanya titik layak yang dikirim sebagai marker, sehingga **Lihat Semua Titik** dan lapisan APKUK tidak dapat terbuka di luar wilayah.
- Nilai koordinat di database dan SPS BOS **tidak diubah**. Respons memuatnya apa adanya di `coordinate_issues`, dengan jumlah di `meta.keypoint_invalid_coordinates` dan `meta.apkuk_invalid_coordinates`.
- Untuk APKUK aktif berlaku: `apkuk_active_total` = `apkuk_mapped` + `apkuk_invalid_coordinates` + `apkuk_without_coordinates`.
- **Memperbaiki titik invalid:** perbaiki di SPS BOS, lalu jalankan `apkuk:sync`. Titik otomatis kembali menjadi marker. Jangan mengubahnya langsung di database.
- **Batasan:** kesalahan yang masih jatuh di dalam kotak wilayah tidak terdeteksi. Aturan ini hanya dipakai peta, belum dipakai validasi kandidat WO.

## Titik invalid per 2 November 2026

Koordinat ASSET_RAW di bawah adalah bahan pengecekan untuk pemilik data, bukan nilai yang sudah disahkan.

| ID APKUK | Tiang | Baris `APKUK_RAW` | Koordinat di APKUK | Koordinat tiang di ASSET_RAW |
|---|---|---|---|---|
| A91 | `F.jaipong.000391` | 1676 | −5,3540; 0 | −5,35396; 105,81011 |
| A104 | `F.jaipong.000351` | 1687 | −5,3508; 105,1200 | −5,35083; 105,80597 |
| A110 | `F.KECAK.001690` | 1692 | −5,0921; 105,0932 | −5,09227; 105,59322 |
| A115 | `F.payung.000378` | 1696 | −5,3109; 105,1200 | −5,31086; 105,55086 |
