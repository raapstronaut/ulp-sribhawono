# Laporan Implementasi Batch B1
## Kualitas Data, Integritas Identitas, dan Ketahanan Backend — ULP Sribhawono

- **Tanggal:** 28–29 Oktober 2026
- **Branch:** `feat/admin-web-foundation-v1` (PR #10, tetap OPEN)
- **Basis:** commit dokumentasi audit `71f8e57` (di atas `e10a85d`)
- **Status kode B1:** **belum di-commit**, menunggu review pengguna. Tidak ada push, merge, deploy, migration production, perubahan Railway, maupun perubahan Google Sheets.
- **Acuan:** `docs/OPERATIONAL_SPEC_GAP_AUDIT.md` (temuan K-3, K-7, D-4, D-5, D-7, D-8, D-9; risiko P-7, P-10)

---

## 1. Ringkasan implementasi

| Sub-batch | Hasil | Status |
|---|---|---|
| B1.1 Identitas pohon ROW | Target WO ROW memakai `POHON:{ID POHON}` (VEGETASI_RAW kolom AB), bukan ID baris staging. Ada guard data ganda, guard form basi, dan migration data reversible untuk target lama | **COMPLETED** (bagian aman); canonical aset tetap menunggu SPS aset baru |
| B1.2 Detail APKUK | Halaman `/admin/apkuk/{id}` berisi 12 kolom A–L apa adanya, nilai turunan dipisah, status sumber, rekonsiliasi, WO terkait, dan laporan lain di tiang yang sama. Daftar APKUK menaut ke detail | **COMPLETED** |
| B1.3 Normalisasi penyulang | Alias terpusat `PenyulangName` (TANGGAMUS → TANGGAMUS 2) dipakai untuk pencocokan, filter, dan agregasi. Nilai mentah tidak diubah. Ada juga perbaikan bug titik di akhir pada Master Data | **COMPLETED** |
| B1.4 Koreksi Section | Tabel `apkuk_section_overrides`, form Admin di Detail APKUK, validasi terhadap referensi yang ada, audit trail, cabut koreksi, dan Section efektif dipakai kandidat WO | **COMPLETED** (validasi referensi sementara; master Section canonical belum ada) |
| B1.5 Retry API | Helper `IdempotentSubmission` untuk laporan, reschedule, limpahan, kendala, dan inspeksi. Balapan request → replay, isi berbeda → 422, `start` idempoten, plus `meta.idempotent_replay` | **COMPLETED** (konkurensi nyata diuji di PostgreSQL untuk laporan; endpoint lain lewat simulasi deterministik) |
| B1.6 Jam shift | Komentar "SEMENTARA" diganti jam final. Tes regresi 00.00/08.00/16.00/23.59, UTC, Shift B tengah malam, dan Dashboard/PASILA pada pergantian hari | **COMPLETED** |

Tidak ada perubahan aturan bisnis baru. Yang tidak dikerjakan sesuai batasan B1: `shift_instances`, generator WO, ML, PASILA/P0, generator ROW, Flutter, dan perubahan production.

---

## 2. Perubahan per sub-batch

### B1.1 — Identitas pohon untuk pekerjaan ROW

**Verifikasi sumber (read-only, 28 Okt):**
- Header `VEGETASI_RAW` live sesuai importer posisional: G = `ID TIANG`, M = `KONDISI`, R = `JARAK POHON`, S = `JARAK POHON SAAT INI`, AB = `ID POHON` (28 kolom).
- `ID POHON` di sumber: 16.983 baris, 16.983 unik, 0 kosong, 0 ganda.
- 4.465 `ID TIANG` memuat lebih dari satu pohon (maksimum 28). Identitas pohon karena itu tidak boleh diturunkan dari tiang.

**Masalah lama:**
- Kunci target `VEGETASI:{vegetasi_source_rows.id}` berubah setiap `sources:import --replace` (`ImportSourceSheets` menghapus lalu menyisipkan ulang).
- Akibatnya pohon yang sama bisa lolos guard WO aktif, dan `source_ref` lama bisa menunjuk pohon lain.

**Perubahan:**

| Komponen | Isi |
|---|---|
| `LocationTargetResolver::vegetation()` | Kunci `POHON:{ID POHON}` (prefix namespace terpisah dari format lama); `source_ref` = ID POHON; snapshot menyimpan `identity`, `vegetasi_source_row_id`, `vegetasi_source_row_number`, nilai `id_pohon` mentah |
| ID POHON kosong | Ditolak: target tidak dapat dibuat stabil (tidak ada ID fiktif) |
| ID POHON ganda di staging | Diterima bila semua baris menyatakan pohon yang sama (penyulang canonical + ID tiang sama, mis. import tanpa `--replace`); ditolak dengan pesan klarifikasi bila berbeda |
| Form WO ROW | Mengirim `target_ref` (ID POHON yang dilihat Admin). Bila baris staging berganti isi setelah import ulang, permintaan ditolak (form basi) |
| Guard WO aktif | Tetap `ROW|{jenis}|{target_key}` — satu WO aktif per pohon per jenis pekerjaan; dua pohon di satu tiang terpisah; WO selesai/batal melepas kunci (siklus baru) |
| Migration data `2026_10_28_200001_key_vegetation_work_order_targets_by_id_pohon` | Target lama `VEGETASI:%` di-rekey dari `snapshot.id_pohon`; kunci lama disimpan di `snapshot.legacy_target_key/legacy_source_ref`; WO aktif ganda pada pohon sama ditandai `identity_conflict` tanpa ditimpa/dibatalkan; target tanpa ID POHON dibiarkan; `down()` mengembalikan persis |
| Tampilan | Detail WO: "Referensi sumber: ID POHON …"; format lama ditandai "(format lama)" |

### B1.2 — Detail lengkap laporan APKUK

| Komponen | Isi |
|---|---|
| Route | `GET /admin/apkuk/{apkuk}` (`admin.apkuk.show`, ID numerik 1–18 digit) di grup `auth` + `admin` |
| Data sumber | 12 kolom APKUK_RAW A–L dengan label header asli (ID, Tanggal, Petugas, Penyulang, Section, No. Tiang/Gardu, Naik Dari, Terpasang bh, Kurang bh, Status, Koordinat, Foto); nilai kosong → "Kosong di sumber"; nol tetap nol; DUMMY tampil apa adanya; foto http(s) → tautan |
| Nilai turunan (dipisah) | Penyulang master + catatan normalisasi, koordinat terbaca, Section efektif + asalnya, kelayakan WO (alasan/peringatan) |
| Status sumber | Aktif / Menunggu rekonsiliasi / Histori (diarsipkan), sesuai aturan PR #11 (tidak diubah) |
| Relasi | Riwayat antrean rekonsiliasi, WO APKUK terkait (eager-load), laporan lain pada tiang + penyulang yang sama (maks. 20, satu query + satu query status) |
| Daftar APKUK | No. tiang menaut ke detail; Section hasil koreksi Admin ditandai |

Catatan: kolom B (Tanggal), H, dan I tersimpan sebagai tipe tanggal/angka hasil sinkronisasi. Sel yang tidak dapat diurai sudah ditolak sinkronisasi sejak PR #11, jadi nilai tersimpan mencerminkan sumber. Nilai mentah sel tanggal (serial Sheets) tidak disimpan di database.

### B1.3 — Normalisasi identitas penyulang

| Tempat | Sebelum | Sesudah |
|---|---|---|
| `App\Support\PenyulangName` (baru) | Alias tersebar (`SyncCoreTopologyMasters`, `AuditTopology`) | Satu definisi `ALIASES = ['TANGGAMUS' => 'TANGGAMUS 2']`, normalisasi kapital/spasi/titik akhir, ekspresi SQL portabel PostgreSQL/SQLite |
| Kandidat WO APKUK, target HARSUS/ROW/inspeksi | Normalisasi tanpa alias | `PenyulangName` (alias berlaku) |
| Special Case (dimensi penyulang) | Group by teks mentah → TANGGAMUS dan TANGGAMUS 2 terpisah | Group by nama canonical; detail memuat semua varian, nilai mentah tetap tampil |
| Analitik (gangguan per penyulang), Dashboard (top penyulang) | Teks mentah | Nama canonical |
| Master Data → detail penyulang | `upper(trim(penyulang))`: **bug** — `CAKALELE.`/`KECAK.` tidak terhitung | Normalisasi lengkap + alias |
| Data peta | `penyulang` mentah; dropdown punya entri `CAKALELE.` dan `TANGGAMUS` terpisah | `penyulang` canonical + `penyulang_sumber` mentah; popup menampilkan nilai sumber bila berbeda |
| Importer Gangguan | Filter kolom ULP sumber `SRIBAWONO` | **Tidak diubah** (filter mengikuti ejaan sumber) |
| Tabel sumber | — | Tidak ada nilai yang ditulis ulang; tidak ada entitas penyulang alias |

`TANGGAMUS 3` atau nama lain yang belum terbukti identik tidak dipetakan.

### B1.4 — Koreksi Section internal oleh Admin

| Komponen | Isi |
|---|---|
| Tabel `apkuk_section_overrides` (migration `2026_10_28_200002`) | Satu baris per laporan (unique `apkuk_id`, FK `restrictOnDelete`): `section_name`, `source_section_at_change`, `reference_basis`, `status` AKTIF/DICABUT, `reason`, pembuat/pengubah, waktu |
| Aturan | Hanya untuk Section sumber kosong/`-`/DUMMY (keputusan 28 Okt). Section sumber yang terisi tidak dapat ditimpa; perbaikan di SPS BOS |
| Validasi | Hanya untuk laporan dengan penyulang yang cocok master. Ada dua sumber referensi: (1) pemutus `SECTION_PIC_RAW` yang dipetakan ke penyulang laporan lewat fill-down kolom PENYULANG, aturan yang sama dengan `SyncCoreTopologyMasters`; (2) Section laporan APKUK lain pada penyulang yang sama, asalkan `SECTION_PIC_RAW` tidak memetakannya ke penyulang lain. Placeholder ditolak. Alasan ≥ 10 karakter. Tidak ada master Section palsu; dasar referensi dicatat (diperketat pada review akhir, §11) |
| Audit | `audit_logs` entitas `apkuk_section_override`: CREATED / UPDATED / REVOKED (aksi baru "Dicabut") dengan nilai lama, baru, pelaku, waktu, alasan |
| Section efektif | Koreksi aktif selama sumber kosong/DUMMY. Bila sumber kemudian terisi, nilai sumber berlaku dan selisihnya ditandai di Detail (koreksi tidak dihapus otomatis) |
| Dampak | Kandidat WO memakai Section efektif (`sectionOrigin` dicatat di log WO baru). WO yang sudah ada **tidak** berubah (snapshot) |
| Sinkronisasi | `apkuk:sync` tidak menyentuh tabel ini; diuji dengan harness sinkronisasi asli |
| Akses | Route `POST /admin/apkuk/{apkuk}/section` di guard Admin; akun lapangan 403, tamu → login |

### B1.5 — Ketahanan API mobile terhadap retry

**Audit sebelum B1:**

| Endpoint | Masalah |
|---|---|
| laporan, reschedule, limpahan, kendala, inspeksi | Cek kunci hanya sebelum insert. Dua request bersamaan → unique violation → **HTTP 500** |
| laporan | Bila request pertama sudah mengubah status WO, retry berikutnya bisa mendapat **409**, bukan hasil semula |
| semua | Kunci sama dengan isi berbeda → record lama dikembalikan diam-diam |
| inspeksi | Replay tidak memeriksa tim pemilik kunjungan |
| `start` | Retry setelah timeout → 409 |

**Perubahan:**

| Komponen | Isi |
|---|---|
| `App\Services\Work\IdempotentSubmission` (baru) | Kunci yang sudah ada → replay bila pemilik sama (WO, atau tim untuk inspeksi) dan isi penentu sama. Pemilik berbeda → 422 "client_request_id sudah dipakai untuk WO lain/tim lain". Isi berbeda → 422 menyebut kolom yang berbeda. Unique violation / konflik status (409) / validasi akibat balapan → replay bila record berkunci sama ada; bila tidak ada, galat diteruskan |
| Isi pembanding (fingerprint) | Seluruh isi yang disimpan (diperluas pada review akhir, §11). **Laporan:** jenis, jumlah terpasang, catatan, koordinat, akurasi GPS, waktu kejadian (bila dikirim), petugas, dan SHA-256 tiap foto BEFORE/AFTER. **Reschedule:** alasan, catatan, tanggal/shift tujuan. **Limpahan:** tim/jenis tujuan, alasan, catatan. **Kendala:** jenis dan uraian. **Inspeksi:** penyulang, Section, ringkasan, waktu kunjungan, koordinat, petugas, dan tiap temuan (kode, uraian, aset, SHA-256 foto). Perbedaan bentuk penulisan untuk isi yang sama tidak dianggap berbeda: angka sebagai teks, urutan petugas/foto, offset zona waktu untuk instan yang sama |
| Media | Foto request yang kalah dihapus dari disk (tidak menjadi media yatim/ganda); media pemenang tetap |
| `start` | Idempoten bila WO sudah DIKERJAKAN (tanpa log ganda); status lain tetap 409 |
| Respons | Tambahan `meta.idempotent_replay` (true/false). Kode status dan bentuk `data` tidak berubah (replay tetap 201) |
| Otorisasi | Lingkup WO (`FieldWorkScope`) diperiksa sebelum replay; WO tim lain → 404 tanpa data |

Pemilihan 422 untuk kunci dipakai ulang dengan isi berbeda mengikuti draf IETF *Idempotency-Key* dan kontrak tes lama. 409 tetap untuk konflik workflow.

### B1.6 — Konsistensi jam shift

- `ShiftService`: komentar diganti menjadi jam FINAL (A 08–16, B 16–00, C 00–08 WIB). Logika tidak berubah karena nilainya sudah benar.
- Tanggal kerja Shift C (TBD-1) tidak diputuskan. Perilaku kalender saat ini dikunci oleh tes dan didokumentasikan.
- Dokumen aktif sudah diselaraskan di commit `71f8e57`, tanpa sisa "jam shift belum diputuskan".

---

## 3. File yang diubah

**Baru:**

| File | Sub-batch |
|---|---|
| `backend/app/Support/PenyulangName.php` | B1.3 |
| `backend/app/Services/Apkuk/ApkukSectionService.php` | B1.4 |
| `backend/app/Models/ApkukSectionOverride.php` | B1.4 |
| `backend/app/Services/Work/IdempotentSubmission.php` | B1.5 |
| `backend/database/migrations/2026_10_28_200001_key_vegetation_work_order_targets_by_id_pohon.php` | B1.1 |
| `backend/database/migrations/2026_10_28_200002_create_apkuk_section_overrides_table.php` | B1.4 |
| `backend/resources/views/admin/apkuk/show.blade.php` | B1.2, B1.4 |
| `backend/tests/Feature/Operations/RowTreeIdentityTest.php` | B1.1 (9 tes) |
| `backend/tests/Feature/Operations/ApkukDetailTest.php` | B1.2 (5 tes) |
| `backend/tests/Feature/Operations/PenyulangAliasTest.php` | B1.3 (6 tes) |
| `backend/tests/Feature/Operations/ApkukSectionOverrideTest.php` | B1.4 (10 tes) |
| `backend/tests/Feature/Operations/MobileRetryResilienceTest.php` | B1.5 (11 tes) |
| `backend/tests/Feature/ShiftBoundaryTest.php` | B1.6 (10 tes) |
| `docs/BATCH_B1_IMPLEMENTATION_REPORT.md` | laporan ini |

**Diubah (29 file):**
- **Controller:**
  - `ApkukController` — show, updateSection, penanda koreksi;
  - `TeamWorkOrderController` — kunci ID POHON, `target_ref`;
  - `MapDataController`, `MasterDataController`, `SpecialCaseController`, `AnalyticsController` — alias;
  - API `WorkOrderController` dan `InspectionController` — `meta.idempotent_replay`.
- **Service:**
  - `ApkukCandidateService` / `ApkukCandidate` — alias, Section efektif;
  - `ApkukWorkOrderService` — log asal Section;
  - `DashboardService` — alias;
  - `LocationTargetResolver`, `TeamWorkOrderCreator` — ID POHON;
  - `WorkOrderExecutionService`, `InspectionService` — idempotensi;
  - `ShiftService` — komentar.
- **Model:** `Apkuk` (relasi koreksi), `AuditLog` (aksi REVOKED, label entitas), `WorkOrderLocationTarget` (label ID POHON).
- **Command:** `SyncCoreTopologyMasters` dan `AuditTopology` (alias terpusat).
- **View:**
  - `apkuk/index`, `team-work-orders/create`, `work-orders/show`, `master-data/penyulang`;
  - `resources/js/maps.js`;
  - `routes/web.php` (2 route).
- **Tes:** `tests/Feature/Sync/ApkukResilientSyncTest.php` (+1 tes koreksi bertahan setelah sync).
- **Dokumen:** `docs/PROJECT_STATUS.md`, `docs/DATA_MODEL.md`, `docs/API_CONTRACT.md`, `docs/OPERATIONAL_SPEC_GAP_AUDIT.md` (status singkat).

Tidak disentuh: `maps-upgrade/`, `backend/.codex-php.ini`, migration lama, data production, dan Google Sheets.

---

## 4. Migration baru

| Migration | Jenis | Dampak | Rollback |
|---|---|---|---|
| `2026_10_28_200001_key_vegetation_work_order_targets_by_id_pohon` | Koreksi data reversible pada `work_order_location_targets` (VEGETASI), tanpa perubahan skema | Kunci target lama → `POHON:{ID POHON}`; kunci lama disimpan di snapshot; bentrok aktif ditandai | `down()` mengembalikan kunci dan snapshot persis (diuji SQLite dan PostgreSQL) |
| `2026_10_28_200002_create_apkuk_section_overrides_table` | Additive (tabel baru) | Tidak mengubah tabel lama | `dropIfExists` |

**Urutan production nanti:** kedua migration ini bergantung pada tabel Batch A (`2026_10_28_000001`). Di Neon production keduanya baru boleh dijalankan setelah migration Batch A, dalam rencana deploy terpisah yang sudah disetujui. Saat ini production belum memiliki tabel WO target lokasi, jadi migration data B1.1 akan berjalan pada tabel kosong.

---

## 5. Kompatibilitas PR #10 dan PR #11

| Aspek | Hasil |
|---|---|
| Aturan PR #11 (pending/`source_missing_at`/arsip/muncul kembali) | Tidak diubah. Detail APKUK hanya membaca `ApkukSourceState`/antrean; pending tetap tidak layak WO (`ApkukPendingIntegrationTest`, `ApkukResilientSyncTest` lulus) |
| `apkuk:sync` | Tidak diubah; koreksi Section bertahan setelah sinkronisasi asli (tes baru di `ApkukResilientSyncTest`) |
| Kontrak API mobile | Kode status dan `data` tetap; tambahan `meta.idempotent_replay`. Perilaku baru:<br>• kunci sama dengan isi berbeda (termasuk catatan, foto, atau metadata) → 422;<br>• `start` pada WO DIKERJAKAN → 200;<br>• `occurred_at` tanpa offset dibaca sebagai WIB, dan semua nilai disimpan sebagai UTC (§11) |
| Form Admin WO ROW | Tetap `target_id`; tambahan `target_ref` opsional |
| Lima role v3 | Tidak berubah; tidak ada route/role baru di luar Admin Web |
| Tes lama | 259 tes lama tetap lulus tanpa mengubah asersinya |

---

## 6. Bukti pengujian

| Uji | Lingkungan | Hasil |
|---|---|---|
| Suite Laravel penuh | SQLite in-memory (`php artisan test`, tanpa `--env`) | **311 lulus, 2.699 assertion**, 0 gagal (baseline 259 / 2.283; sebelum review akhir 305 / 2.641) |
| Tes khusus B1 | SQLite | 52 tes baru (51 di 6 kelas baru + 1 di `ApkukResilientSyncTest`) |
| Migration + fungsi B1 | PostgreSQL 18 lokal, schema sementara `b1test_*` (dihapus) | **30/30 OK** setelah review akhir (sebelumnya 27/27), rinciannya di bawah tabel ini |
| Konkurensi nyata `client_request_id` | PostgreSQL lokal, dua proses PHP | Proses lain menahan transaksi berkunci sama. INSERT request kedua **menunggu 4,0 detik** di unique index, lalu mengembalikan laporan pemenang (replay), bukan 500. Hasil: 1 laporan, 0 media yatim, status WO tidak diubah |
| Browser | Edge headless, server lokal, schema audit UJI lokal | **41/41 OK**, rinciannya di bawah tabel ini |
| Pint | Semua file PHP B1 | Lulus. Dua file lama (`AuditTopology`, `ApkukCandidateService`) sudah gagal Pint di HEAD dengan jenis pelanggaran yang sama; tidak diformat ulang agar diff tetap kecil |
| `php -l` | 14 file inti | Tanpa galat sintaks |
| `npm run build` | Vite | Berhasil |
| `git diff --check` | — | Bersih |

Rincian uji PostgreSQL (30 pemeriksaan):
- migrate 24/24, lalu rollback dan re-migrate dua migration B1;
- rekey target lama, penanda bentrok, rollback yang persis;
- referensi Section dibatasi penyulang laporan, dan pemutus penyulang lain ditolak;
- koreksi Section dengan riwayat `CREATED`→`REVOKED`→`UPDATED`;
- konkurensi nyata dengan isi identik menjadi replay, sedangkan kunci sama dengan foto berbeda ditolak 422;
- halaman Detail APKUK, Special Case beserta detailnya, Analitik, Dashboard, data peta, Master Data, form ROW, dan daftar APKUK: semuanya HTTP 200.

Rincian uji browser (41 pemeriksaan):
- daftar → Detail APKUK dengan 12 kolom, DUMMY, dan "Kosong di sumber";
- koreksi Section: ditolak (alasan pendek), simpan, cabut, simpan ulang, dengan 3 entri riwayat;
- penanda koreksi di daftar dan Audit Log;
- detail WO ROW "ID POHON …", form ROW mengirim `target_ref`, 3 lencana WO aktif;
- Dashboard, Special Case, Analitik, Master Data, APKUK, Rekonsiliasi, dan Peta tanpa galat;
- data peta canonical dan detail seluler tanpa scroll horizontal;
- 0 error console.

Skrip dan log uji ada di scratchpad sesi (`b1/pg_b1_test.php`, `b1/pg_b1_race_child.php`, `b1/browser_b1.js`, beserta lognya). Data uji browser ditulis hanya ke schema lokal `batcha_audit_cfb5ba`: satu laporan `UJI-B1-DUMMY` dan satu pemutus `REC UJI-B1 (TEST)`.

---

## 7. Status per sub-batch

| Sub-batch | Status | Catatan |
|---|---|---|
| B1.1 | COMPLETED | Identitas ID POHON. Canonical aset/vegetasi permanen menunggu spreadsheet aset SPS BOS (§9) |
| B1.2 | COMPLETED | — |
| B1.3 | COMPLETED | Hanya alias terkonfirmasi |
| B1.4 | COMPLETED | Validasi memakai referensi yang ada (SECTION_PIC_RAW + Section APKUK sepenyulang), bukan master Section canonical |
| B1.5 | COMPLETED | Konkurensi nyata diuji di PostgreSQL untuk laporan; reschedule/limpahan/kendala/inspeksi memakai helper yang sama dan diuji dengan simulasi deterministik |
| B1.6 | COMPLETED | Tanggal kerja Shift C tetap TBD |

---

## 8. Risiko yang masih ada

| Risiko | Dampak | Mitigasi berikutnya |
|---|---|---|
| Kode B1 belum di-commit/deploy | Production belum menikmati perbaikan | Review → commit → deploy terjadwal bersama Batch A |
| 676 laporan aktif/pending di production ber-Section kosong/DUMMY; koreksi satu per satu | Beban Admin | Koreksi massal ber-audit hanya bila disetujui; utamakan perbaikan di SPS BOS |
| Referensi Section belum canonical | Nilai koreksi bisa perlu ditinjau ulang saat master Section tersedia | Tinjau ulang koreksi saat master/aset baru masuk; dasar referensi sudah tercatat |
| Fingerprint mensyaratkan file foto dikirim ulang byte-identik | Klien yang mengompres ulang foto pada tiap percobaan akan mendapat 422 (data di server tetap ada; tidak ada data hilang) | Flutter wajib menyimpan file final di outbox dan mengirim file yang sama (OFFLINE_SYNC_SPEC §4); uji di perangkat nyata |
| Peta masih memuat semua data (K-5) | Tidak skala bila layer aset 17 rb ditambahkan | Di luar B1; rencana Maps v3 |
| Pelanggaran Pint lama di 2 file | Kosmetik | Format terpisah bila disetujui |
| Import staging vegetasi tetap manual | Data ROW bisa tertinggal dari sumber | Jadwal impor/sinkron vegetasi (keputusan terpisah) |

---

## 9. Ketergantungan pada spreadsheet aset SPS BOS baru

| Area | Yang sudah aman sekarang | Yang menunggu sumber aset baru |
|---|---|---|
| ROW | Identitas pohon `ID POHON` (dari VEGETASI_RAW, bukan ASSET_REVIEW_RAW) | Relasi pohon → tiang canonical (`ID TIANG` vegetasi `17TG…` ↔ ID aset), Section pohon (kolom SECTION vegetasi 0% terisi) |
| Section | Koreksi internal ber-audit dengan referensi SECTION_PIC_RAW | Master Section canonical dan batas Section per aset/tiang |
| Aset | Tidak membangun master aset dari ASSET_REVIEW_RAW | Identitas aset canonical, pemetaan Posko per aset, histori per aset, layer aset di peta |
| ML | — | Fitur spasial/topologi dan pencocokan gangguan → aset yang lebih akurat |

---

## 10. Rekomendasi sebelum Batch B2

1. **Review dan commit B1** setelah pengguna meninjau diff; lalu push ke PR #10 (tetap tidak merge).
2. **Putuskan TBD-1, tanggal kerja Shift C.** Ini prasyarat `shift_instances` di B2. Pilihannya tanggal kalender, seperti perilaku saat ini, atau hari operasional yang dimulai pukul 08.00.
3. **Putuskan TBD-5 dan TBD-6:** sumber kandidat bila kebutuhan APKUK_RAW kurang dari target, dan pengesahan pemetaan Section → Posko. Keduanya prasyarat generator 6 WO.
4. **Siapkan rencana deploy Batch A + B1:**
   - backup;
   - migration berurutan `000001`, `000002`, lalu `200001`, `200002`;
   - `MEDIA_DISK` persisten;
   - kode referensi resmi;
   - tim, petugas, dan akun.

   Seluruhnya dengan persetujuan terpisah.
5. **Mulai menyusun dataset ber-versi untuk ML**, read-only, sesuai `OPERATIONAL_SPEC_GAP_AUDIT.md` §10.5 tahap ML-1. Pekerjaan ini bisa berjalan paralel dengan B2.

---

## 11. Review akhir sebelum commit (29 Okt 2026)

Dua titik yang diminta pengguna diperiksa ulang. Keduanya **memerlukan perbaikan**, dan pemeriksaan itu menemukan satu masalah metadata terkait. Semua perbaikan dibuat minimal, disertai tes regresi.

### 11.1 Referensi koreksi Section

**Temuan:**
- `references()` menawarkan semua pemutus `SECTION_PIC_RAW` dari penyulang mana pun. Admin dapat mengisi Section penyulang lain pada laporan REOG.
- Sumber kedua (Section laporan APKUK lain di penyulang yang sama) juga meneruskan nilai sumber yang bertentangan dengan `SECTION_PIC_RAW`.

**Bukti data live (Neon production, read-only):**
- Fill-down kolom PENYULANG pada `SECTION_PIC_RAW` memetakan seluruh 68 pemutus. Tidak ada yang tak terselesaikan dan tidak ada pemutus di lebih dari satu penyulang, sehingga pemetaan ini aman dipakai.
- 69 laporan APKUK menulis Section yang oleh `SECTION_PIC_RAW` dipetakan ke penyulang lain. Contohnya ZAPIN dengan LBSM NEGARA SAKA (PIC: CAKALELE) dan CAKALELE dengan LBSM MANGGARAWAN (PIC: RONGGENG).
- 2.119 laporan konsisten dan 1.640 memakai Section yang tidak dikenal `SECTION_PIC_RAW`.

**Perbaikan (`ApkukSectionService::references()`):**
- Referensi hanya bila penyulang laporan cocok master; tanpa itu tidak ada referensi dan koreksi ditolak.
- Pemutus `SECTION_PIC_RAW` hanya yang hasil fill-down-nya ke penyulang canonical laporan. Urutan baris sumber dan aturannya sama dengan `SyncCoreTopologyMasters`. Baris sebelum penyulang pertama tidak dipetakan.
- Section dari laporan APKUK lain hanya bila `SECTION_PIC_RAW` tidak memetakannya ke penyulang lain.
- Sistem tidak menebak Section dari penyulang. Admin tetap memilih nilai beserta alasan, dan nilai sumber, koreksi, serta audit trail tidak berubah.

**Tes:**
- Pemutus penyulang lain tidak ditawarkan dan ditolak.
- Baris tanpa penyulang tidak dipetakan.
- Nilai sumber yang bertentangan tidak ditawarkan.
- Laporan berpenyulang DUMMY tidak punya referensi.
- Diuji juga di PostgreSQL.

Fixture tes sinkronisasi dilengkapi kolom PENYULANG pada baris `SECTION_PIC_RAW` agar sesuai struktur sumber; asersinya tidak diubah.

### 11.2 Idempotensi API mobile

**Temuan:** fingerprint semula hanya memuat kolom penentu. Pengiriman dengan kunci sama tetapi catatan, foto, atau metadata berbeda dianggap replay dan mendapat 201 `idempotent_replay: true`. Menurut OFFLINE_SYNC_SPEC, HP kemudian menandai item ACKNOWLEDGED dan membersihkan payload serta fotonya. Isi kiriman kedua yang belum pernah tersimpan di server akan hilang permanen.

**Perbaikan:**
- Fingerprint mencakup seluruh isi yang disimpan (lihat B1.5).
- Retry sah dari outbox selalu mengirim payload dan file yang dibekukan, jadi tetap menjadi replay. Ini dibuktikan tes "bentuk penulisan berbeda, isi sama".
- Isi berbeda dengan kunci sama ditolak 422. Data di server dan file di HP tetap aman; HP menampilkan "perlu tindakan".
- Tidak ada migration tambahan; pembanding memakai kolom dan relasi yang sudah tersimpan, termasuk `sha256` media.

**Tes:**
- Catatan, foto, petugas, waktu, atau koordinat yang berubah ditolak tanpa data hilang.
- Retry dengan format berbeda (`"3"`, urutan petugas/foto, offset zona waktu) tetap replay.
- Balapan dengan isi identik tetap replay.
- Diuji juga di PostgreSQL dengan konkurensi nyata.

### 11.3 Temuan terkait: zona waktu `occurred_at` laporan WO

**Temuan:** API mobile meneruskan `occurred_at` apa adanya, dan service mengurainya dalam zona aplikasi (UTC). Eloquent menyimpan jam dinding tanpa offset. Akibatnya waktu lokal tanpa offset atau dengan `+07:00` tersimpan bergeser 7 jam. Web Admin tidak terdampak karena sudah mengonversi ke UTC sebelum memanggil service.

**Perbaikan:**
- `occurred_at` tanpa offset dibaca sebagai WIB; offset dan `Z` dihormati.
- Nilai disimpan sebagai UTC, sama seperti `visited_at` inspeksi.

**Tes:** `2026-10-28 08:30` (WIB), `…T08:30:00+07:00`, dan `…T01:30:00Z` semuanya tersimpan sebagai `2026-10-28 01:30:00` UTC.

### 11.4 Kesiapan

| Pemeriksaan | Hasil |
|---|---|
| Suite Laravel penuh | 311 lulus / 2.699 assertion, 0 gagal |
| PostgreSQL terisolasi | 30/30 OK, schema uji dihapus |
| Pint (file B1) | Lulus |
| `git diff --check` | Bersih |

Kode B1 siap di-commit setelah disetujui pengguna. Push ke PR #10 juga menunggu persetujuan, tanpa merge dan tanpa deploy.
