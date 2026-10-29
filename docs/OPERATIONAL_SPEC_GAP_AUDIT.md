# Audit Kesenjangan Spesifikasi Operasional
## ULP Sribhawono — Spesifikasi v3 + keputusan 28 Oktober 2026 vs implementasi aktual

- **Tanggal audit:** 28 Oktober 2026
- **Branch:** `feat/admin-web-foundation-v1` @ `e10a85d` (PR #10, tetap OPEN — tidak di-merge, tidak di-deploy)
- **Jenis:** audit teknis + penyelarasan spesifikasi. Tidak ada perubahan kode aplikasi, migration, data production, Railway, maupun Google Sheets.
- **Status bukti:** kode dibaca langsung; tes dijalankan ulang pada sesi ini; data production dibaca **read-only** (lihat §2.3).
- **Dokumen pendamping:** `docs/WEB_ADMIN_DATA_FIELD_AUDIT.md` (provenance per field, baseline audit ini).
- **Pembaruan 29 Okt 2026 — Batch B2 (commit lokal, belum di-push):**
  - Keputusan pengguna menyelesaikan TBD-1 (hari operasional mulai 08:00), TBD-2 (limpahan sah masuk kuota), TBD-5 (kekurangan dicatat, tanpa data fiktif), dan TBD-6 (Posko hanya dari SECTION_PIC_RAW terverifikasi).
  - Dibangun: `shift_instances`, generator 6 WO APKUK idempoten, dan halaman Kuota Shift APKUK dengan rincian alasan laporan. Strateginya baseline rule-based. GENERATE fail-closed: hanya `local`/`testing` dengan database lokal, dan semua lingkungan operasional ditolak sampai model ML dan prosedur readiness siap.
  - Kuota: WO manual Admin ikut menempati kuota enam dan ditolak bila shift penuh. Generator dan WO manual memegang kunci kuota shift yang sama; hanya limpahan sah yang boleh melebihi enam. Catatan §9 tentang `ApkukSyncLock::shared()` pada WO manual menggambarkan keadaan sebelum B2.
  - Rincian: `docs/BATCH_B2_IMPLEMENTATION_REPORT.md`.
- **Pembaruan 29 Okt 2026 — Batch B1 (commit `2308c4f`):**
  - Celah yang ditutup: K-3 (target ROW kini `ID POHON`), K-7 (komentar shift), P-7 (alias TANGGAMUS pada agregat), P-10 (retry paralel API).
  - Keputusan yang diimplementasikan: D-4 (Detail APKUK 12 kolom) dan D-8 (koreksi Section ber-audit).
  - Rincian: `docs/BATCH_B1_IMPLEMENTATION_REPORT.md`. Bagian lain audit ini tetap berlaku.

---

## 1. Ringkasan eksekutif

**Kesimpulan utama:** fondasi Web Admin, Work Order generik, review/approval, sinkronisasi SPS BOS, dan API mobile v1 sudah nyata dan teruji. Namun **inti operasi per shift belum ada**:

- tidak ada scheduler shift;
- tidak ada generator 6 WO APKUK;
- tidak ada domain PASILA/P0;
- tidak ada generator ROW;
- tidak ada model ML;
- tidak ada aplikasi Flutter yang fungsional.

Sistem **belum siap dipakai operasional** sesuai v3.

| Area | Status | Inti temuan |
|---|---|---|
| Lima role & RBAC | **IMPLEMENTED** (server) | Web hanya ADMIN; API mobile hanya akun tim lapangan aktif; scope POSKO per Posko ditegakkan di server |
| Jam shift A/B/C | **IMPLEMENTED** (nilai) / **PARTIAL** (fungsi) | Konstanta 08–16, 16–00, 00–08 WIB sudah sesuai keputusan final. Belum ada `shift_instances`, job awal shift, maupun pemakaian jadwal tim |
| Generator 6 WO APKUK/Posko/shift | **MISSING** | Hanya pembuatan WO manual Admin. `SOURCE_SYSTEM` tidak pernah dipakai |
| Machine learning | **MISSING** | Tidak ada model, dataset training, pipeline, ataupun tabel skor. Urutan kandidat saat ini = aturan tetap (`kurang_bh` terbesar, laporan tertua) |
| PASILA / P0 | **MISSING** (domain) | Halaman hanya monitor konteks shift + WO APKUK; P0 berupa empty state |
| WO lifecycle, laporan, foto BEFORE/AFTER, reschedule, eskalasi | **IMPLEMENTED** (Web + API) | Ada dua aturan yang lebih ketat daripada v3 (lihat §7, K-1/K-2) |
| INSPEKSI | **PARTIAL** | Kunjungan, temuan, validasi, dan WO tindak lanjut sudah ada; ranking/rotasi area dan rekomendasi tim belum ada |
| ROW | **PARTIAL** | WO manual sudah ada; generator otomatis, rute, dan identitas `ID POHON` belum |
| HARSUS | **IMPLEMENTED** (manual + child eskalasi) | Target aset dari Keypoint (129); aset canonical belum ada |
| Sinkronisasi SPS BOS (APKUK resilient) | **IMPLEMENTED** | Production: 16 run berturut `SUCCESS_WITH_PENDING_RECONCILIATION`, 78 laporan pending |
| Maps Web | **PARTIAL** | Leaflet sudah ada; pemilihan penyulang wajib, bbox/lazy, layer aset, drawer histori/foto, dan quick-create belum |
| Flutter Android | **MISSING** (fungsi) | Hanya layar login (tanpa API) + placeholder beranda; tanpa dependency HTTP/SQLite/kamera/GPS |
| Offline sync | **PARTIAL** (server) / **MISSING** (klien) | Server sudah punya `client_request_id` unik + 409/422; klien outbox belum ada |
| Production readiness Batch A | **BLOCKED** (persetujuan deploy) | Tabel Batch A belum ada di Neon production; `reference_codes`, tim, petugas = 0; penyimpanan foto persisten belum ditetapkan |

**Temuan data yang mengubah rencana generator dan ML** (bukti live read-only, §9–§10):

1. **Kandidat APKUK yang memenuhi syarat dasar hanya 376 laporan**, dan 95% di antaranya berada di dua section: LBSM GUNUNG MAS (BEDANA, 188) dan REC BRAJA DEWA (REOG, 171).
2. Pemetaan section → POSKO PIC dari `SECTION_PIC_RAW` menempatkan kandidat sebagai berikut: PUGUNG 188, WAY JEPARA 171, PASIR SAKTI 2, SRIBHAWONO/JABUNG/TRIDATU **0**, belum terpetakan 15. Target 6 WO × 3 shift × 6 Posko = **108 WO/hari** tidak dapat dipenuhi oleh empat Posko dari sumber kebutuhan saat ini.
3. Histori APKUK baru dimulai **11 Maret 2026**. 3.646 dari 3.747 tiang aktif hanya punya satu laporan. Tabel WO production masih **0 baris**, sehingga belum ada label hasil pekerjaan untuk melatih model.
4. Data yang menjanjikan untuk ML:
   - 438 dari 492 gangguan punya `no_tiang`;
   - 367 di antaranya cocok dengan master aset (`ASSET_RAW`, 16.052 tiang unik berkoordinat);
   - gangguan berpenyebab BINATANG berjumlah 269, terutama KUKANG (219).

   Data ini memungkinkan label "risiko gangguan binatang per tiang/section", dengan keterbatasan yang dijelaskan di §10.
5. `ID POHON` unik dan terisi 100% untuk 16.983 titik vegetasi, sehingga layak menjadi identitas target ROW sesuai keputusan pengguna. Namun implementasi masih memakai ID baris staging.

**Prioritas kerja berikutnya:**

1. Kunci keputusan TBD §16 yang memblokir generator.
2. Siapkan fondasi shift (`shift_instances` + job idempoten).
3. Bangun generator rule-based sebagai baseline + fallback.
4. Bangun pipeline ML dengan gerbang readiness terukur.
5. Bangun domain PASILA/P0.
6. Bangun Flutter + outbox.

Detail ada di §14.

---

## 2. Referensi, versi dokumen, dan metode

### 2.1 Dokumen yang dibaca seluruhnya

| Dokumen | Versi | Peran dalam audit |
|---|---|---|
| `docs/PROJECT_STATUS.md` | v3.0, 8 Okt 2026; pembaruan 28 Okt (Batch A) | Aturan bisnis final, status implementasi lama (dibandingkan dengan kode) |
| `docs/PRD.md` | v3.0, 8 Okt 2026 | Alur POSKO/HARSUS/INSPEKSI/ROW, acceptance minimum |
| `docs/TECHNICAL_SPEC.md` | v3.0 | Arsitektur, shift generation, invariants |
| `docs/DATA_MODEL.md` | v3.0 (+ catatan 20 Okt) | Tabel IMPLEMENTED vs PLANNED |
| `docs/API_CONTRACT.md` | v3.0 | Endpoint usulan, aturan 409/422/idempotency |
| `docs/DESIGN_BRIEF.md` | v3.0 | Inventaris Web Admin, Maps, layar mobile |
| `docs/OFFLINE_SYNC_SPEC.md` | v3.0 | Outbox, status sinkronisasi, pengujian wajib |
| `docs/IMPLEMENTATION_PLAN.md` | v3.0 | Fase, DoD, dependency |
| `backend/AGENTS.md` | — | Larangan pengembangan |
| `docs/WEB_ADMIN_DATA_FIELD_AUDIT.md` | 28 Okt 2026 | Baseline provenance per field + bukti live |
| `docs/APKUK_RESILIENT_SYNC.md`, `docs/BATCH_A_RELEASE.md` | 28 Okt 2026 | Kebijakan source-state APKUK, rencana rilis Batch A |

`docs/FINAL_AUDIT_REPORT.md` adalah arsip historis. Dokumen itu tidak dipakai sebagai bukti dan tidak diubah.

### 2.2 Keputusan pengguna 28 Oktober 2026 (mengungguli v3 bila bertentangan)

| # | Keputusan | Bagian v3 yang diperbarui | Status di kode |
|---|---|---|---|
| D-1 | Jam shift **FINAL**: A 08.00–16.00, B 16.00–00.00, C 00.00–08.00 WIB, berurutan 24 jam | TECHNICAL_SPEC §5 ("mengikuti konfigurasi lama sampai disahkan ulang"), PROJECT_STATUS §7, PRD §10 | Nilai sesuai (`ShiftService::SHIFTS`); komentar kode masih menyebut "SEMENTARA" |
| D-2 | **ML untuk pemilihan/prioritas WO otomatis wajib siap dan berfungsi sebelum operasional** | PRD §9 ("model ML bukan prasyarat"), TECHNICAL_SPEC §1 ("tidak wajib … ML") | Belum ada ML (§10) |
| D-3 | Data APKUK yang tidak ada pada snapshot SPS BOS valid otomatis keluar dari laporan aktif. Histori Neon tetap disimpan. Laporan yang muncul kembali dipulihkan. Pembacaan sumber gagal tidak boleh dianggap penghapusan | DATA_MODEL §9.6 ("Spreadsheet bukan auto-sync terus-menerus") | Sesuai (PR #11, `ApkukSourceState`, guard snapshot) |
| D-4 | Semua data asli APKUK harus tersedia di tampilan Detail APKUK | — | Belum: halaman APKUK menampilkan 8 dari 12 kolom; tidak ada halaman detail |
| D-5 | Data DUMMY dari SPS BOS dipertahankan, tidak diubah atau dikecualikan berdasarkan asumsi | — | Sebagian (§11.3) |
| D-6 | Normalisasi diperbolehkan untuk typo, ejaan, kapitalisasi, dan alias terbukti; fakta lapangan tidak diubah | — | Sebagian (§11.2) |
| D-7 | Nama Posko resmi **SRIBHAWONO**; penyulang resmi **TANGGAMUS 2**; PSK001–PSK006 hanya kode spreadsheet dan tidak ditampilkan; Posko operasional memakai nama | WEB_ADMIN audit §8 butir 17–18 | Master sudah memakai SRIBHAWONO/TANGGAMUS 2; alias belum diterapkan pada `gangguans` |
| D-8 | Section kosong/DUMMY boleh dilengkapi Admin melalui data internal dengan audit trail, tanpa menimpa nilai sumber | WEB_ADMIN audit §8 butir 19 | Belum ada fitur |
| D-9 | Pekerjaan ROW mengacu **ID POHON** | DATA_MODEL §3 `vegetations`, WEB_ADMIN audit §8 butir 23 | Belum: target ROW memakai ID baris staging |
| D-10 | `ASSET_REVIEW_RAW` bukan acuan aset permanen; pemilik SPS BOS menyiapkan spreadsheet aset penyulang lengkap | TECHNICAL_SPEC §3 | Tidak ada kode yang membaca `ASSET_REVIEW_RAW` (sesuai) |

### 2.3 Metode dan batasan

| Bukti | Cara | Batasan |
|---|---|---|
| Kode | Pembacaan langsung route, controller, service, model, migration, view, JS, dan Flutter `lib/` | — |
| Tes Laravel | `php artisan test` (SQLite in-memory, tanpa `--env`) pada HEAD `e10a85d` | Tidak menguji PostgreSQL; uji PostgreSQL terisolasi terakhir: 28 Okt (22/22 migration, rollback, re-migrate) |
| Tes Flutter | `flutter analyze --no-pub` + `flutter test --no-pub` (Flutter 3.47.5 / Dart 3.13.4) | Tidak ada perangkat/emulator; tidak ada integrasi backend |
| Data production | Skrip PHP membaca `.env.neon`. Sesi `PGOPTIONS=-c default_transaction_read_only=on` dengan role runtime; skrip berhenti bila host/role/timeline/read-only tidak cocok | Hanya agregat; tidak ada nama petugas/pengguna; tidak ada penulisan |
| Google Sheets SPS BOS | Hasil baca read-only 28 Okt (WEB_ADMIN audit §3.7) | Tidak dibaca ulang pada sesi ini; angka Neon menunjukkan data terus bertambah (APKUK 4.515 → 4.569 baris pada hari yang sama) |
| Railway | Tidak diakses. Status cron diambil dari tabel `apkuk_sync_runs` dan log yang dikonfirmasi pengguna | Konfigurasi cron Railway tidak ada di repository |
| Flutter di perangkat | Tidak tersedia | Klaim Flutter hanya berdasarkan kode |

---

## 3. Matriks lima role

| Aspek | ADMIN | POSKO | HARSUS | INSPEKSI | ROW |
|---|---|---|---|---|---|
| **Platform v3** | Web Laravel saja | Flutter Android | Flutter Android | Flutter Android | Flutter Android |
| **Penegakan platform** | `/admin/*` → middleware `auth` + `admin` (`routes/web.php`, `EnsureActiveAdmin`); sesi Admin ditolak di API (`MobileApiSecurityTest::test_admin_session_cannot_be_used_on_mobile_api`) — **IMPLEMENTED** | Token Sanctum + middleware `field` (`EnsureFieldAccount`); akun tim ditolak di Web (403) — **IMPLEMENTED** | sama | sama | sama |
| **Akun = tim** | Akun Admin terpisah | `users.field_team_id` → `field_teams` (role POSKO + `posko_id`) — **IMPLEMENTED**; batas "satu akun per tim" tidak ditegakkan — **PARTIAL** | sama | sama | sama |
| **Petugas aktual dipilih dari roster** | — | `personnel_ids` wajib petugas aktif tim pelaksana (`WorkOrderExecutionService::personnel`). **Menyimpang:** `personnel_names` manual di luar roster diterima (`:387`) | sama | sama (`InspectionService`) | sama |
| **Pekerjaan utama v3** | Monitor, review ACC/Tolak, master, manual WO/PASILA/P0/HARSUS/ROW, Maps, koreksi | 6 WO APKUK/shift, PASILA tiap shift + P0 dalam PASILA, reschedule | WO PEKERJAAN_KHUSUS (Admin atau child eskalasi ACC) | Temuan nyata ≥1/hari, ranking+rotasi, validasi Admin | WO RABAS/PANGKAS/TEBANG otomatis (langsung aktif) + manual, cluster rute |
| **Yang sudah berjalan** | Semua modul Web Batch A kecuali PASILA/P0 manual | WO APKUK (feed, mulai, laporan, foto, reschedule, eskalasi, kendala) via API | WO khusus via API; Admin buat manual dengan target Keypoint wajib | Kunjungan + temuan + foto via API/Web; validasi Admin; WO tindak lanjut | WO manual via Web; laporan via API |
| **Yang belum** | Buat PASILA/P0 manual, quick-create dari peta, koreksi histori aset | Generator 6 WO, PASILA/P0, jadwal shift tim memengaruhi feed | — (aset canonical) | Ranking/rotasi area, rekomendasi tim otomatis | Generator otomatis, paket/rute, identitas ID POHON |
| **Larangan v3 terjaga?** | — | P0 tidak bisa dibuat siapa pun (domain belum ada) — tidak dilanggar | Tidak ada endpoint P0 HARSUS; scope HARSUS tidak melihat P0 (`WorkModulesTest::test_harsus_page_lists_special_work_and_escalation_origin_without_p0`) | INSPEKSI tidak menerima WO (`FieldWorkScope`) | — |

Tidak ditemukan role tambahan ataupun penggabungan P0 dengan HARSUS di kode, route, atau view.

---

## 4. Perbandingan aturan spesifikasi vs implementasi

Legenda status: **IMPLEMENTED** = berjalan dan dibuktikan tes; **PARTIAL** = ada sebagian / belum teruji; **MISSING** = belum ada; **BLOCKED** = menunggu data/keputusan; **KONFLIK** = implementasi berbeda dari spesifikasi.

| # | Aturan (sumber) | Implementasi | Status |
|---|---|---|---|
| R-1 | Shift A 08–16, B 16–00, C 00–08 WIB (D-1) | `app/Services/ShiftService.php:23` (`SHIFTS`), `window()` `:61`, tes `tests/Unit/ShiftServiceTest.php` (batas 00.00, 07.59, 08.00, 15.59, 16.00, 23.59; konversi zona waktu) | IMPLEMENTED |
| R-2 | Identitas shift-instance unik Posko + tanggal kerja + kode (TECHNICAL_SPEC §5, DATA_MODEL §7) | Tidak ada tabel `shift_instances`; WO menyimpan `work_date` + `shift_code` | MISSING |
| R-3 | PASILA dibuat otomatis awal tiap shift per Posko (PRD §2.1) | Tidak ada domain/scheduler. `PasilaController` hanya agregat WO (`:22-26` docblock) | MISSING |
| R-4 | Target 6 WO PEMASANGAN_APKUK per Posko per shift, otomatis, dengan rute (PRD §2.2) | Konstanta tampilan saja (`DashboardService.php:28`, `PasilaController.php:27`); WO hanya `createManual` (`ApkukWorkOrderService.php:37`) | MISSING |
| R-5 | 6 WO = 6 pekerjaan; jumlah fisik APKUK dicatat terpisah (PRD §2.2) | `installed_quantity` wajib 1–1000 pada `PEMASANGAN_SELESAI` (`WorkOrderExecutionService::submitReport`); target qty di `work_order_apkuk_targets`; Dashboard menjumlah fisik terpisah (`DashboardService::apkuk`) | IMPLEMENTED |
| R-6 | Reschedule: alasan dropdown + catatan wajib + jadwal tujuan dipilih petugas (PRD §6) | `requestReschedule` (`:200`): `ReferenceCode::isValid`, catatan ≥5 karakter, tanggal/shift tujuan divalidasi | IMPLEMENTED |
| R-7 | Approval reschedule per jenis **TBD — jangan kunci ACC** (PRD §6, API §8) | Semua reschedule masuk `MENUNGGU_ACC` + status WO `RESCHEDULE_DIAJUKAN` (`:240`); hanya Admin yang memindah jadwal (`WorkOrderReviewService::approveReschedule` `:88`) | **KONFLIK** (K-1) |
| R-8 | WO terkendala yang sudah direschedule tidak memblokir PASILA + P0 (PRD §2.4) | Tidak dapat diuji: domain PASILA/P0 belum ada | BLOCKED (oleh R-3) |
| R-9 | P0 milik POSKO, wajib terkait PASILA, Section/Posko sama; SYSTEM butuh ACC; reject → Admin wajib membuat pengganti (PRD §2) | `P0Service` mengembalikan empty state (`app/Services/P0Service.php:10`); `P0Controller::show` selalu 404 | MISSING (BLOCKED sebagian: form P0 detail TBD) |
| R-10 | HARSUS menerima PEKERJAAN_KHUSUS dari Admin (aset jaringan wajib) atau child eskalasi ACC; bukan P0 (PRD §3) | `TeamWorkOrderCreator::createManual` `:45-47` (keypoint wajib); `approveEscalation` `:152`; tes `TeamWorkOrderFlowTest` | IMPLEMENTED |
| R-11 | Cuaca/akses sementara bukan alasan otomatis eskalasi HARSUS (PRD §3) | Tidak ada eskalasi otomatis; eskalasi selalu diajukan tim + ACC Admin | IMPLEMENTED (secara desain) |
| R-12 | Eskalasi: ACC Admin, sumber `SELESAI_DITERUSKAN`, child WO bertaut, tolak tanpa child (PRD §6) | `WorkOrderReviewService::approveEscalation` (`lockForUpdate`, transaksi), `rejectEscalation`; tes `test_escalation_approval_creates_child_work_order_atomically_and_rejection_creates_none` | IMPLEMENTED |
| R-13 | INSPEKSI: ranking + rotasi area (PRD §4) | Tidak ada; `InspectionController.php:25` menandai TBD | MISSING (formula TBD) |
| R-14 | INSPEKSI: Tidak Ada Temuan dicatat jujur (PRD §4) | `has_finding=false` + ringkasan wajib; tes `test_admin_records_no_finding_visit_honestly_and_it_is_not_counted_as_finding` | IMPLEMENTED |
| R-15 | Temuan per titik: foto/GPS/waktu/petugas sendiri (PRD §4–5) | Foto wajib per temuan di API (`requirePhotos: true`), opsional bila dicatat Admin. Koordinat temuan boleh kosong lalu diisi koordinat Keypoint (`InspectionService.php:271`). Waktu temuan = waktu kunjungan | PARTIAL (K-4) |
| R-16 | Rekomendasi tim penerima temuan oleh sistem; Admin ACC (PRD §4) | `suggested_team_type` diisi petugas/Admin; tidak ada rekomendasi otomatis | PARTIAL (algoritma TBD) |
| R-17 | ROW auto WO langsung aktif tanpa ACC, banyak per hari, cluster rute (PRD §5) | Tidak ada generator; `RowController.php:15` menandai belum dibangun | MISSING |
| R-18 | ROW mengacu ID POHON (D-9) | `LocationTargetResolver::vegetation` `:73` memakai `'VEGETASI:'.$row->id` (ID baris staging); `id_pohon` hanya di `source_details` | **KONFLIK** (K-3) |
| R-19 | ROW: cegah tugas ganda tanpa kendali (PRD §5) | Unique `work_order_location_targets.active_target_key` (`TeamWorkOrderCreator.php:24,109,141`); tes duplikasi HARSUS | IMPLEMENTED untuk ID staging; tidak tahan reimport (K-3) |
| R-20 | Penyelesaian pekerjaan wajib foto BEFORE + AFTER (PRD §7) | `submitReport`: `PEMASANGAN_SELESAI`/`PEKERJAAN_SELESAI` ditolak tanpa keduanya; tes `test_completion_requires_before_after_photos_personnel_and_quantity` | IMPLEMENTED |
| R-21 | Form mobile: konteks otomatis, GPS otomatis, dropdown dari DB (PRD §7) | API `bootstrap` mengirim tim, petugas, kode referensi, shift, penyulang; GPS wajib di laporan API (`latitude/longitude required`). Form Flutter belum ada | PARTIAL (server) / MISSING (klien) |
| R-22 | Offline: submit tersimpan lokal, kirim otomatis, retry tanpa duplikasi (OFFLINE_SYNC §1–4) | Server: `client_request_id` wajib + unique (`2026_10_20_000001`, `2026_10_28_000001`), replay mengembalikan record sama, 409 workflow (`bootstrap/app.php`), 422 validasi. Klien: tidak ada | PARTIAL / MISSING |
| R-23 | Maps Leaflet: pilih 1 dari 16 penyulang wajib sebelum memuat layer; bbox/lazy (PRD §8) | `MapDataController::index` (`:13`) mengembalikan semua Keypoint/APKUK/Gangguan sekaligus; default "Semua Penyulang" (`resources/js/maps.js:131`) | **KONFLIK** (K-5) |
| R-24 | Maps: search global, ganti penyulang otomatis, drawer Informasi/Histori/Foto, quick-create (PRD §8) | Pencarian ada (client-side); drawer histori/foto aset, layer aset, quick-create tidak ada | PARTIAL |
| R-25 | Gangguan ditampilkan pada lokasi kejadian (PRD §8) | Sumber tidak punya koordinat; Gangguan ditampilkan sebagai daftar (`MapDataController.php:66-67`), bukan marker semu | BLOCKED (data) |
| R-26 | Histori tidak dihapus; koreksi Admin dengan old/new/actor/waktu/alasan (PRD §9) | `AuditLogger` dipakai di master data, akun, review, inspeksi, rekonsiliasi APKUK; `work_order_status_logs` append-only; edit WO wajib alasan (`test_admin_schedule_edit_requires_reason_and_records_before_after`) | IMPLEMENTED (WO/master); MISSING (histori aset) |
| R-27 | Special Case berbasis frekuensi gangguan, bukan >3 trip tetap; formula TBD (PRD §9) | Frekuensi mentah per penyulang/peralatan/no tiang + periode 30–365 hari, tanpa ambang (`SpecialCaseController.php:20-25`) | IMPLEMENTED (sesuai batas TBD) |
| R-28 | APKUK pending tidak boleh menjadi target WO (D-3, PR #11) | `ApkukCandidateService::ISSUE_MENUNGGU_REKONSILIASI`; `ApkukSyncLock::shared()` di `ApkukWorkOrderService::create`; tes `ApkukPendingIntegrationTest` | IMPLEMENTED |
| R-29 | Cegah WO APKUK aktif ganda per tiang | `work_order_apkuk_targets.active_target_key` unique + `ISSUE_WO_AKTIF`; tes `test_duplicate_active_work_order_for_same_pole_is_rejected` | IMPLEMENTED |
| R-30 | Token/sesi perangkat: batas 2 device + logout 4 jam **TBD, jangan hardcode** (TECHNICAL_SPEC §4) | Tidak di-hardcode; `config/sanctum.php:55` `expiration => null` (token tidak kedaluwarsa) | IMPLEMENTED (sesuai TBD); risiko §13 |
| R-31 | ML wajib sebelum operasional (D-2) | Tidak ada | MISSING |

---

## 5. Indeks bukti lokasi kode

| Komponen | File / kelas / method |
|---|---|
| Shift | `backend/app/Services/ShiftService.php` (`SHIFTS` :23, `current`, `window` :61, `describe` :96); `backend/app/Services/Work/WorkSchedule.php` (`forShift`, `forDates`) |
| Scheduler | `backend/routes/console.php` — hanya `gangguan:sync`, `apkuk:sync`, `keypoint:sync`; `backend/railway-sync.sh` menjalankan ketiga sync tersebut, bukan `schedule:run` |
| Status WO | `backend/app/Enums/WorkOrderStatus.php` (8 status, `allowedTransitions`) |
| WO APKUK | `backend/app/Services/ApkukCandidateService.php` (`evaluate`, `filter` :196, urutan :205, `PLACEHOLDERS` :66); `backend/app/Services/ApkukWorkOrderService.php` (`createManual` :37) |
| WO HARSUS/ROW | `backend/app/Services/Work/TeamWorkOrderCreator.php`, `LocationTargetResolver.php` (`keypoint` :24, `vegetation` :56/:73) |
| Pelaksanaan | `backend/app/Services/Work/WorkOrderExecutionService.php` (`start`, `submitReport`, `requestReschedule` :200, `requestEscalation` :266, `reportIssue`, `personnel` :387) |
| Review | `backend/app/Services/Work/WorkOrderReviewService.php` (`approveReport`, `rejectReport`, `approveReschedule` :88, `approveEscalation` :152, `resolveIssue`) |
| Batal WO | `backend/app/Models/WorkOrder.php::supportsAdminCancellation` (:207) — WO APKUK, atau WO manual tanpa parent/child; `WorkOrderLifecycleService::cancel` |
| Inspeksi | `backend/app/Services/Inspection/InspectionService.php` (`record`, `approveFinding`, `rejectFinding`, `prepareFinding` :244) |
| P0 / PASILA | `backend/app/Services/P0Service.php`, `Http/Controllers/Admin/P0Controller.php`, `Http/Controllers/Admin/PasilaController.php` |
| Dashboard | `backend/app/Services/DashboardService.php` (`poskoStatuses` :162, `apkuk` :231, `inspection`) |
| Maps | `backend/app/Http/Controllers/Admin/MapDataController.php`, `backend/resources/js/maps.js`, route `admin.maps.keypoints` (closure di `routes/web.php`) |
| API mobile | `backend/routes/api.php`; `Http/Controllers/Api/V1/Mobile/{Auth,Bootstrap,WorkOrder,Inspection}Controller.php`; `Services/Work/FieldWorkScope.php`; `Http/Middleware/EnsureFieldAccount.php` |
| Media | `backend/app/Services/Work/MediaStore.php` (disk `filesystems.media_disk`, SHA-256, path UUID baru, maks 10 MB, jpg/jpeg/png/webp/heic) |
| Audit | `backend/app/Services/Audit/AuditLogger.php`, `Models/AuditLog.php`, `Models/WorkOrderStatusLog.php` |
| APKUK source-state | `backend/app/Services/Apkuk/*` (`ApkukSynchronizer`, `ApkukSyncPolicy`, `ApkukSourceState`, `ApkukReconciliationQueue`, `ApkukSyncLock`), `config/apkuk.php` |
| Master | `backend/app/Console/Commands/SyncCoreTopologyMasters.php` (`OFFICIAL_POSKOS`, alias TANGGAMUS → TANGGAMUS 2 :375-381), `Services/Admin/MasterDataService.php` |
| Gangguan | `backend/app/Console/Commands/SyncGangguanPusat.php` (filter ULP sumber `'SRIBAWONO'` :71, penyulang disimpan mentah) |
| Flutter | `mobile/lib/main.dart`, `screens/login_screen.dart` (`_submit` hanya validasi lalu ke beranda), `screens/beranda_screen.dart` (placeholder), `pubspec.yaml` (hanya `cupertino_icons`) |

---

## 6. Status per modul

| # | Modul | Status | Bukti utama | Tes ada | Tes kurang | Ketergantungan data | Risiko production | Prioritas |
|---|---|---|---|---|---|---|---|---|
| 1 | Dashboard | PARTIAL | `DashboardService` (KPI WO nyata, APKUK aktif/pending/histori, gangguan, inspeksi) | `DashboardTest`, `ApkukPendingIntegrationTest` | Dashboard tepat pukul 00.00–00.30 (Shift C); KPI P0/PASILA | WO/inspeksi dari lapangan | KPI P0/PASILA kosong; progres 6 WO hanya menghitung WO manual | P2 |
| 2 | P0 | MISSING | `P0Service` empty state | Akses/empty state | Seluruh rule P0 (TECHNICAL_SPEC §9) | Form P0 detail (TBD), PASILA | Kewajiban ≥1 P0/shift tidak dapat dijalankan | P1 (setelah PASILA) |
| 3 | WO APKUK | IMPLEMENTED (manual) | `ApkukWorkOrderService`, `WorkOrderExecutionService` | `ApkukWorkOrderTest` (14), `ApkukEndToEndFlowTest` (6) | Retry paralel `client_request_id` yang sama | APKUK_RAW | Beban manual Admin 108 WO/hari tanpa generator | P0 |
| 4 | Generator WO otomatis | MISSING | `SOURCE_SYSTEM` tidak pernah dibuat | — | Idempotensi rerun, kuota 6, kekurangan kandidat, pending, duplikasi, pergantian tengah malam | Pemetaan section → Posko, koordinat titik awal rute | Target v3 tidak terpenuhi | **P0** |
| 5 | Machine Learning | MISSING | Tidak ada kode/model/dataset | — | Backtest, kalibrasi, fallback | Label dan histori (§10) | Prasyarat go-live (D-2) belum terpenuhi | **P0** |
| 6 | APKUK | PARTIAL | `ApkukController` (tab aktif/menunggu/histori) | `ApkukPendingIntegrationTest` | Halaman detail 12 kolom | APKUK_RAW | D-4 belum terpenuhi | P1 |
| 7 | Rekonsiliasi APKUK | IMPLEMENTED | `ApkukReconciliationQueue`, `ApkukReconciliationController`, `apkuk:reconcile` | `ApkukReconciliationTest` (17), `ApkukResilientSyncTest` (22) | — | Konfirmasi pemilik SPS BOS | 78 pending menunggu keputusan pemilik data | P2 |
| 8 | PASILA | MISSING (domain) | `PasilaController` monitor | `WorkModulesTest` (PASILA tidak tersedia) | Pembuatan otomatis, temuan per titik, tidak terblokir WO reschedule | Section rekomendasi (bobot TBD) | Kewajiban per shift tidak dapat dijalankan | P1 |
| 9 | HARSUS | IMPLEMENTED | `TeamWorkOrderCreator`, `HarsusController` | `TeamWorkOrderFlowTest` | — | Keypoint (129); aset canonical | Target aset terbatas pada FCO/LBSM/RECLOSER | P2 |
| 10 | INSPEKSI | PARTIAL | `InspectionService`, API `inspections` | `InspectionFlowTest` (4) | Waktu per temuan, rekomendasi area | Formula ranking (TBD) | Area tidak diarahkan sistem | P2 |
| 11 | ROW | PARTIAL | WO manual, `RowController` | `TeamWorkOrderFlowTest::test_row_work_order_on_vegetation_point_completes_through_verification` | Identitas ID POHON tahan reimport, generator, idempotensi | VEGETASI_RAW (staging manual) | ID staging berubah saat `--replace` (K-3) | P1 |
| 12 | Review & Approval | IMPLEMENTED | `ReviewController`, `WorkOrderReviewService` | `ControlModulesTest`, `ApkukEndToEndFlowTest` | ACC usulan P0 SYSTEM (domain belum ada) | — | Reschedule wajib ACC (K-1) | P2 |
| 13 | Special Case | IMPLEMENTED (sesuai TBD) | `SpecialCaseController` | `ControlModulesTest` | Alias TANGGAMUS pada agregat | Gangguan | 33 gangguan TANGGAMUS terpisah dari TANGGAMUS 2 | P2 |
| 14 | Maps Monitoring | PARTIAL | `MapDataController`, `maps.js` | `MapsTest` (3) | Pemilihan penyulang wajib, bbox | ASSET_RAW untuk layer aset; koordinat gangguan | Payload penuh tidak akan skala bila 17 rb aset ditambahkan | P2 |
| 15 | Master Data | IMPLEMENTED | `MasterDataController`, `MasterDataManageController`, `MasterDataService` (audit) | `DataModulesTest`, `AdministrationTest` | Section internal (D-8) | `MASTER_POSKO` tidak dibaca (sesuai D-7) | `reference_codes` production = 0 (alur reschedule/eskalasi/temuan gagal validasi) | P0 (seed resmi) |
| 16 | Analytics | IMPLEMENTED | `WorkOrderAnalyticsService`, ekspor CSV | `DataModulesTest` | Agregat per tanggal kerja Shift C | — | — | P3 |
| 17 | User Management / RBAC | IMPLEMENTED | `UserAccountService` (pengaman Admin terakhir, cabut token saat reset/nonaktif) | `AdministrationTest`, `AdminAuthorizationTest`, `MobileApiSecurityTest` | Satu akun per tim | — | Token tidak kedaluwarsa | P2 |
| 18 | Audit Logs | IMPLEMENTED | `AuditLogController` (umum + status WO) | `ControlModulesTest`, `ApkukPendingIntegrationTest` | — | — | — | P3 |
| 19 | Shift Scheduler | PARTIAL (nilai) / MISSING (job) | `ShiftService`; tidak ada job | `ShiftServiceTest` (5) | Job idempoten, rerun, jendela pergantian tengah malam | Trigger cron Railway | Tidak ada pembuatan otomatis apa pun per shift | **P0** |
| 20 | Mobile API | PARTIAL | 13 route `api/v1/mobile/*` | `MobileApiSecurityTest` (5), E2E APKUK, inspeksi | Retry paralel, PASILA/P0/ROW-route/area endpoint | — | Endpoint PASILA/P0/rute/area belum ada | P1 |
| 21 | Flutter Android | MISSING (fungsi) | Login tanpa API, beranda placeholder | 4 widget test (lulus) | Seluruh fungsi | API | Tim lapangan tidak dapat memakai sistem | **P0** |
| 22 | Offline Sync | PARTIAL (server) / MISSING (klien) | `client_request_id` unique | Idempotensi inspeksi/APKUK | Seluruh skenario OFFLINE_SYNC §9 | — | Duplikasi/kehilangan data bila klien dibangun tanpa outbox | P1 |
| 23 | Asset History & media | PARTIAL | `MediaStore` (hash, path baru, disk privat); tidak ada `asset_history_events` | Tes foto laporan/temuan | Timeline aset, foto terbaru per aset | Aset canonical (spreadsheet aset baru) | Filesystem Railway tidak persisten bila `MEDIA_DISK` belum S3 | P1 |

---

## 7. Daftar konflik dan gap prioritas

### 7.1 Konflik implementasi vs spesifikasi

| ID | Konflik | Bukti | Dampak | Rekomendasi (butuh persetujuan) |
|---|---|---|---|---|
| K-1 | Reschedule **selalu** butuh ACC Admin, padahal v3 menyatakan kebijakan approval reschedule TBD dan melarang mengunci ACC tanpa keputusan | `WorkOrderExecutionService.php:240`, `WorkOrderStatus::RescheduleDiajukan` | WO sisa tidak berpindah jadwal sebelum Admin bertindak | Putuskan matriks approval reschedule per jenis WO (TBD-3). Hingga diputuskan, catat bahwa ACC adalah aturan sementara |
| K-2 | Penyelesaian HARSUS/ROW (`PEKERJAAN_SELESAI`) wajib verifikasi Admin, ditandai "aturan sementara, belum disahkan" | `WorkOrderReport.php:23`, `WorkOrderExecutionService.php:31` | Antrean review bertambah | Putuskan (TBD-4) |
| K-3 | Target ROW memakai ID baris staging, bukan ID POHON (D-9) | `LocationTargetResolver.php:73` | Reimport `--replace` mengubah ID → guard duplikasi dan traceability rusak | Migration additive: kunci `VEGETASI_POHON:{ID POHON}` + backfill target aktif; tes reimport |
| K-4 | Koordinat temuan inspeksi boleh diambil dari Keypoint bila GPS kosong; waktu temuan = waktu kunjungan | `InspectionService.php:271` | Temuan tanpa GPS perangkat tampak memiliki GPS | Tandai asal koordinat (perangkat vs aset) atau wajibkan GPS di API (TBD-18) |
| K-5 | Maps memuat seluruh data tanpa wajib memilih penyulang | `MapDataController::index`, `maps.js:131` | Aman pada skala sekarang (±3,8 rb titik); tidak sesuai v3 dan tidak akan skala dengan 17 rb aset | Endpoint per penyulang + layer + bbox sebelum layer aset ditambahkan |
| K-6 | Nama petugas manual di luar roster diterima | `WorkOrderExecutionService.php:387` | Petugas aktual tidak selalu tertaut master | Pertahankan sebagai cadangan sementara atau larang (TBD-17) |
| K-7 | Komentar `ShiftService` masih menyebut jam "SEMENTARA" | `ShiftService.php:12` | Menyesatkan pengembang | Perbarui komentar pada batch implementasi berikutnya |
| K-8 | `no_tiang` bernilai `DUMMY` membuat laporan tidak layak WO (`ISSUE_NO_TIANG_TIDAK_VALID`) | `ApkukCandidateService.php:66` | Data tetap disimpan dan ditampilkan (sesuai D-5); hanya dikecualikan sebagai target fisik | Konfirmasi bahwa tiang `DUMMY` memang tidak boleh menjadi target WO (TBD-22) |

### 7.2 Gap prioritas (berurutan menurut urgensi dan dependensi)

| Urutan | Gap | Bergantung pada | Memblokir |
|---|---|---|---|
| G-1 | Keputusan TBD-1, TBD-5, TBD-6 (tanggal kerja Shift C, sumber kandidat saat kekurangan, pemetaan section → Posko) | Pengguna/atasan | G-3, G-4 |
| G-2 | Deploy Batch A ke production (migration, `MEDIA_DISK` persisten, kode referensi resmi, tim/petugas/akun) | Persetujuan deploy terpisah | Semua penggunaan lapangan |
| G-3 | Fondasi shift: `shift_instances` + perintah `shift:open` idempoten + trigger terjadwal | G-1 (TBD-1); perubahan cron Railway (persetujuan) | G-4, G-6 |
| G-4 | Generator 6 WO APKUK rule-based (baseline + fallback) | G-1, G-3 | G-5 |
| G-5 | Pipeline ML + gerbang readiness (§10) | G-4 (integrasi dan pembanding), TBD-7 (target/label) | Go-live (D-2) |
| G-6 | Domain PASILA + temuan per titik, lalu P0 (SYSTEM/ADMIN, ACC, pengganti wajib) | G-3; form P0 (TBD-10) | Kewajiban per shift POSKO |
| G-7 | Flutter: auth, bootstrap, feed, form, kamera, GPS, SQLite outbox, WorkManager | API (ada), G-6 untuk PASILA/P0 | Operasi lapangan |
| G-8 | Identitas ROW = ID POHON (K-3), lalu generator ROW + rute | Formula vegetasi (TBD-12) untuk generator | ROW otomatis |
| G-9 | APKUK detail (12 kolom, D-4), Section internal ber-audit (D-8), alias TANGGAMUS pada Gangguan (D-7) | — | Kualitas data Admin |
| G-10 | Maps v3 (penyulang wajib, bbox, layer aset, drawer histori/foto, quick-create) | Spreadsheet aset lengkap (D-10) | Monitoring aset |
| G-11 | INSPEKSI ranking/rotasi, Special Case formula | TBD-13, TBD-14 | — |

---

## 8. Kondisi shift scheduler

| Aspek | Kondisi aktual | Penilaian |
|---|---|---|
| Nilai jam | `SHIFTS` A 8–16, B 16–24, C 0–8; zona `Asia/Jakarta` | Sesuai D-1 |
| Lokasi konfigurasi | Konstanta di kode, bukan `app_configs` (TECHNICAL_SPEC §5) | Dapat diterima karena jam sudah final; perubahan jam tetap butuh deploy |
| Penentuan shift berjalan | `ShiftService::current()`; batas jam diuji termasuk 00.00 dan 23.59 | IMPLEMENTED |
| Tanggal kerja Shift C | `window(D,'C')` = 00.00–08.00 **tanggal D** (kalender). `describe('C')` berlabel `3/3`, sehingga urutan kronologis tanggal D adalah C → A → B | **Butuh keputusan (TBD-1).** Implementasi konsisten di WO, Dashboard, dan PASILA, tetapi label urutan menyiratkan C sebagai shift ketiga hari operasional |
| Pergantian tengah malam | B tanggal D berakhir 00.00 D+1 (`ShiftServiceTest::test_window_returns_shift_bounds_on_calendar_date_in_jakarta`); WO lewat `due_at` → terlambat (`test_active_work_order_past_its_shift_is_overdue`) | IMPLEMENTED untuk jendela waktu. Belum diuji: Dashboard/PASILA pada 00.00–00.30, reschedule ke Shift C hari berikutnya |
| Shift-instance | Tidak ada tabel; identitas = `work_date` + `shift_code` + `posko_id` di WO | MISSING |
| Job awal shift | Tidak ada. `routes/console.php` hanya tiga sync; Railway hanya menjalankan `railway-sync.sh` | MISSING. Butuh trigger 08.00/16.00/00.00 WIB, misalnya `Schedule::command(...)->dailyAt()->timezone('Asia/Jakarta')` atau cron Railway terpisah (perubahan Railway butuh persetujuan) |
| Jadwal tim POSKO | `field_team_shift_assignments` + CRUD Master Data (tidak tumpang tindih) | PARTIAL: tidak dipakai feed API, Dashboard, maupun WO |
| Pemakaian di modul | Dashboard (`poskoStatuses` memakai tanggal WIB + shift berjalan), WO (`WorkSchedule::forShift` menolak shift yang sudah lewat), PASILA (filter), reschedule (shift tujuan wajib untuk WO ber-shift), bootstrap API (`current_shift`) | Konsisten |
| Analytics | Agregat per tanggal pembuatan/penyelesaian, bukan per shift-instance | Perlu disesuaikan setelah TBD-1 |

---

## 9. Evaluasi generator WO

### 9.1 Status sebenarnya

**Generator 6 WO per Posko per shift tidak ada.** Yang ada:

- **Penilaian kelayakan kandidat** (`ApkukCandidateService::evaluate`), dengan aturan berikut:
  - hanya laporan terbaru per tiang yang dinilai;
  - laporan ganda pada tanggal yang sama dianggap ambigu;
  - `kurang_bh > 0` dengan status `BELUM EKSEKUSI`/`KURANG`;
  - penyulang harus cocok dengan master;
  - no. tiang tidak boleh kosong/placeholder;
  - laporan pending rekonsiliasi dan tiang yang sudah punya WO aktif ditolak.
- **Urutan tetap** (`filter` :205): layak lebih dulu → `kurang_bh` terbesar → laporan tertua → no. tiang. Ini **aturan heuristik**, bukan ranking berbobot ataupun ML.
- **Pembuatan WO manual Admin**. Posko dipilih Admin, tidak diturunkan dari penyulang. Proses dilindungi `ApkukSyncLock::shared()` dan unique `active_target_key`.

### 9.2 Kapasitas kandidat vs target (Neon production, read-only, 28 Okt 2026)

Kandidat kasar dihitung dari laporan yang tidak diarsip, tidak pending, `kurang_bh > 0`, dan berstatus `BELUM EKSEKUSI`/`KURANG`. Angka ini sebelum aturan laporan terbaru/ganda; saat ini belum ada WO aktif di production.

| Ukuran | Nilai |
|---|---:|
| Target sistem | 6 Posko × 6 WO × 3 shift = **108 WO/hari** |
| Kandidat kasar | **376** laporan (BELUM EKSEKUSI 315 = 1.821 bh; KURANG 61 = 140 bh) |
| Status bertentangan (SELESAI tetapi `kurang_bh > 0`) | 45 laporan, 68 bh (ditolak sebagai `STATUS_BERTENTANGAN`) |
| Kandidat per penyulang | BEDANA 188, REOG 171, KUNINGAN 2 13, JAIPONG 2, TANGGAMUS 2 2 |
| Kandidat per section | LBSM GUNUNG MAS 188, REC BRAJA DEWA 171, REC SUKRI BALAK 13, 4 section lain masing-masing 1 |
| Section kandidat cocok `SECTION_PIC_RAW` (kolom pemutus) | 361 dari 376 |
| Kandidat per POSKO PIC (`SECTION_PIC_RAW`) | PUGUNG 188, WAY JEPARA 171, PASIR SAKTI 2, **SRIBHAWONO 0, JABUNG 0, TRIDATU 0**, tidak terpetakan 15 |

**Implikasi:**

1. Dengan sumber kebutuhan saat ini, PUGUNG dan WAY JEPARA kehabisan kandidat dalam sekitar 9–10 hari pada laju 18 WO/hari, kecuali ada laporan baru. Empat Posko lain tidak mendapat WO APKUK otomatis sama sekali.
2. Penyulang BEDANA dan REOG masing-masing terkait dua Posko di pivot `posko_penyulang`. DATA_MODEL v3 melarang atribusi Posko hanya dari pivot. Pemetaan via `SECTION_PIC_RAW` adalah kandidat paling kuat, tetapi **belum disahkan** sebagai kepemilikan section.
3. Generator wajib melaporkan kekurangan secara jujur, misalnya "kandidat tersedia 2 dari target 6", dan tidak boleh mengisi kuota dengan target yang tidak sah.

### 9.3 Desain generator yang direkomendasikan (belum diimplementasikan)

| Komponen | Rekomendasi | Dasar |
|---|---|---|
| Pemicu | Perintah `shift:open` dijalankan di awal A/B/C (WIB), juga bisa dijalankan ulang dengan aman | TECHNICAL_SPEC §5 |
| Idempotensi | `shift_instances` unik (posko, work_date, shift_code). WO SYSTEM menyimpan `shift_instance_id` + `slot` (1–6) unik; rerun hanya mengisi slot kosong | DATA_MODEL §7 |
| Kunci konkurensi | Advisory lock generator + `ApkukSyncLock::shared()` agar tidak berjalan saat `apkuk:sync` menulis | PR #11 |
| Seleksi | `ApkukCandidateService::evaluate()` → hanya `isEligible()` → hanya kandidat dengan pemetaan Posko yang sah (TBD-6) → urutkan dengan skor (rule-based sekarang, ML setelah lulus gerbang) | §10 |
| Larangan | Pending rekonsiliasi, WO aktif, laporan lama/ganda, penyulang tidak dikenal (sudah ada di `evaluate`) | R-28, R-29 |
| Kekurangan kandidat | Simpan `generated_count`/`shortfall` per shift-instance; tampilkan di Dashboard/PASILA | Tanpa asumsi pengisian |
| Carry-over | **Tidak mengurangi kuota** sampai diputuskan (TBD-2); WO reschedule tetap tercatat terpisah | PRD §2 |
| Rute | Urutan *nearest-neighbour* dari titik awal Posko memakai koordinat target APKUK (98,1% berkoordinat), dibedakan dari rute jalan sebenarnya | TECHNICAL_SPEC §5; titik awal Posko belum ada di master (TBD-9) |
| Audit | `work_order_status_logs` `CREATED` dengan `source=SYSTEM`, versi skor/model, alasan pemilihan | PRD §9 |
| Tes wajib | Rerun tanpa duplikasi; dua proses paralel; kekurangan kandidat; pending muncul saat generator berjalan; 23.59/00.00; Posko tanpa pemetaan | TECHNICAL_SPEC §9 |

---

## 10. Evaluasi kesiapan machine learning

### 10.1 Jawaban audit (12 pertanyaan)

| # | Pertanyaan | Jawaban berdasarkan bukti |
|---|---|---|
| 1 | Ada implementasi model ML? | **Tidak.** Tidak ada file Python/notebook/model (`*.py`, `*.ipynb`, `*.pkl`, `*.onnx`, `*.joblib`), dependency ML, tabel skor/fitur, atau kode prediksi di repository |
| 2 | Ada dataset training? | **Tidak ada dataset terkurasi.** Bahan mentah tersedia di Neon: `apkuks` 4.569 baris (11 Mar–28 Okt 2026), `gangguans` 492 (1 Jan–28 Okt 2026), `asset_source_rows` 17.059, `vegetasi_source_rows` 16.983, `keypoints` 129. **Label hasil pekerjaan = 0** (`work_orders`, `work_order_reports` kosong) |
| 3 | Target/label yang masuk akal | Lihat §10.2. Kandidat terkuat: **risiko gangguan BINATANG per tiang/section dalam horizon H** (APKUK adalah pelindung tiang dari hewan yang naik; 269/492 gangguan berpenyebab BINATANG, 219 di antaranya KUKANG) |
| 4 | Fitur input tersedia | §10.3 |
| 5 | Training/validasi/evaluasi/inferensi | Belum ada. Rekomendasi §10.5 |
| 6 | Pencegahan leakage dan bias | §10.4 |
| 7 | Integrasi ke scheduler/generator | Belum ada generator. Rekomendasi: skor batch disimpan di tabel skor ber-versi, dibaca generator; fallback ke urutan rule-based (§9.3) |
| 8 | Menentukan enam target valid per Posko per shift | Kelayakan tetap ditentukan aturan (`evaluate`); ML hanya mengurutkan kandidat **yang sudah sah**. Kuota diisi dari atas sampai 6 atau sampai kandidat habis (shortfall dicatat) |
| 9 | Larangan pending dan duplikasi | Tetap aturan keras di luar model (`ISSUE_MENUNGGU_REKONSILIASI`, `ISSUE_WO_AKTIF`, unique `active_target_key`). Model tidak boleh bisa mengesampingkannya |
| 10 | Rute | Optimasi rute terpisah dari model skor: kandidat terpilih diurutkan berdasar jarak. Bila ingin mempertimbangkan klaster, pemilihan dapat memakai skor × kedekatan sebagai fungsi objektif terpisah yang diuji |
| 11 | Kalibrasi dan pemantauan saat SPS BOS berubah | Kalibrasi probabilitas (isotonic/Platt) pada jendela validasi; pemantauan drift volume/kosakata per sync (sudah ada `apkuk_sync_runs`) + performa realisasi gangguan bulanan; retrain terjadwal + saat header/kosakata berubah |
| 12 | Data yang masih kurang | §15 |

### 10.2 Opsi target prediksi

| Opsi | Label | Ketersediaan | Risiko | Penilaian |
|---|---|---|---|---|
| A. Risiko gangguan BINATANG per tiang/section, horizon 30/60/90 hari | Ada gangguan BINATANG di tiang/section dalam horizon setelah titik potong | 438/492 gangguan punya `no_tiang`; 367 cocok master aset; 269 BINATANG | Positif sedikit (ratusan dari 16 rb tiang); label tertunda (Okt 2026: 4 BINATANG dari 53, kemungkinan `penyebab` belum lengkap); penamaan tiang tidak seragam (contoh prefiks penyulang di luar master pada `no_tiang`) | **Direkomendasikan sebagai target utama**, kemungkinan pada grain section-minggu agar positif cukup |
| B. Kebutuhan APKUK pada tiang yang belum dilaporkan | `kurang_bh > 0` pada laporan pertama | 3.747 tiang dilaporkan dari 16.052 tiang master | Bias seleksi (hanya tiang yang disurvei); label berasal dari survei yang sama | Sekunder — berguna bila kandidat diperluas ke tiang tanpa laporan (TBD-5) |
| C. Prioritas berdasarkan hasil WO (selesai/tepat waktu/ACC) | Hasil WO | 0 WO di production | Belum ada data | Tidak mungkin sebelum operasional; dapat ditambah kemudian |

**Keterkaitan APKUK–gangguan yang terukur** (BINATANG dengan `no_tiang`, dicocokkan dengan laporan APKUK pada penyulang yang sama):

| Kondisi | Jumlah gangguan |
|---|---:|
| Tiang tanpa laporan APKUK | 183 |
| Gangguan sebelum laporan APKUK pertama | 51 |
| Gangguan pada/sesudah laporan APKUK pertama | 35 |

Data ini cukup untuk mengukur baseline. Data ini **tidak cukup** untuk menyimpulkan efek pemasangan APKUK secara kausal, karena tanggal pemasangan fisik belum tercatat sebelum WO berjalan.

### 10.3 Fitur yang tersedia

| Sumber | Fitur | Keterisian/catatan |
|---|---|---|
| APKUK_RAW (`apkuks`) | `kurang_bh`, `terpasang_bh`, status, `naik_dari` (JTR/TIANG/KABEL SR/SKUR/KABEL OPTIK), umur laporan, koordinat, section | Koordinat 98,1%; naik_dari 70,1%; section DUMMY/kosong 676 laporan |
| Gangguan (`gangguans`) | Hitungan per tiang/section/penyulang dalam jendela waktu, penyebab, jenis hewan, sifat (SESAAT 454/PERMANEN 38), lama padam, ENS | `no_tiang` 89%; `penyebab` 90,2%; `jenis_hewan` 54,9%; tanpa koordinat |
| Aset (`asset_source_rows`) | Jenis (TIANG_TM 16.052, GARDU 1.007), koordinat (100%), ID aset unik | Kolom Section/Posko/Foto 0% di sumber |
| Vegetasi (`vegetasi_source_rows`) | Kondisi, pertumbuhan (kode 1–4), jarak saat ini, jarak aman, tanggal periksa, tanggal perkiraan bahaya | 100% terisi; tanggal tidak zero-padded ("2026-6-19"); `jarak_aman` bernilai 100 pada sebagian besar baris (perlu konfirmasi makna) |
| Keypoint | Jenis (FCO 86, LBSM 24, RECLOSER 19), koordinat | 100% berkoordinat |
| Section | `SECTION_PIC_RAW` pemutus → POSKO PIC | 69 baris; belum canonical |
| Spasial turunan | Kepadatan tiang, jarak ke keypoint, kedekatan dengan vegetasi | Dapat dihitung dari koordinat aset |

### 10.4 Leakage dan bias yang harus dicegah

1. **Titik potong waktu ketat:** semua fitur hanya memakai data dengan tanggal sebelum titik potong. Laporan APKUK, status, dan `penyebab` gangguan yang diperbarui sesudahnya tidak boleh masuk.
2. **Label tertunda:** `penyebab` gangguan bulan berjalan belum lengkap. Kecualikan jendela label yang belum matang (misalnya 30 hari terakhir).
3. **Snapshot SPS BOS berubah:** simpan ekstrak dataset ber-versi (tanggal snapshot + hash). Status laporan bisa diubah pemilik data, sehingga hanya nilai historis yang diketahui pada waktu itu yang dipakai. `apkuks` tidak menyimpan riwayat perubahan per kolom; ini keterbatasan data.
4. **Bias seleksi survei:** tiang yang punya laporan APKUK bukan sampel acak. Gunakan fitur indikator "pernah disurvei" dan evaluasi terpisah untuk tiang tanpa laporan.
5. **Bias lokasi:** gangguan hanya terlokasi lewat `no_tiang`. Tiang/section dengan pencatatan buruk tampak "aman". Ukur kualitas pencocokan per penyulang.
6. **Alias dan typo:** terapkan normalisasi terbukti (D-6/D-7, misalnya TANGGAMUS → TANGGAMUS 2) sebelum agregasi, tanpa menimpa nilai mentah.
7. **Umpan balik:** setelah generator berjalan, tiang yang dipilih model mendapat perlakuan sehingga gangguannya turun. Catat versi model per WO dan pertahankan porsi evaluasi.

### 10.5 Rencana ML yang realistis dan dapat diuji

| Tahap | Isi | Kriteria keluar |
|---|---|---|
| ML-0 Keputusan | Target optimasi (TBD-7), grain (tiang vs section-minggu), horizon, metrik lulus, tempat eksekusi (TBD-8) | Disetujui tertulis |
| ML-1 Dataset | Ekstrak read-only ber-versi dari Neon (tanpa data pribadi), normalisasi tiang/penyulang, label sesuai horizon, laporan kualitas data | Dataset dapat direproduksi dari tanggal snapshot |
| ML-2 Baseline | B0 = urutan sekarang (`kurang_bh`, tertua); B1 = frekuensi gangguan BINATANG 90 hari per section; B2 = acak dalam kandidat sah | Metrik baseline tercatat |
| ML-3 Model | Regresi logistik/gradient boosting kecil, *rolling-origin backtest* (misalnya latih Jan–Jun, validasi Jul–Agu, uji Sep–Okt) | Mengungguli B0 dan B1 pada jendela uji dengan margin yang disepakati |
| ML-4 Kalibrasi & penjelasan | Kalibrasi probabilitas; faktor utama per kandidat untuk Admin | Brier/reliability dalam batas; penjelasan tampil |
| ML-5 Integrasi | Tabel skor (target, skor, versi model, `computed_at`, fitur ringkas), dibaca generator; fallback otomatis ke B0 bila skor kedaluwarsa/hilang; override Admin ber-audit | Tes generator dengan skor, tanpa skor, dan skor basi |
| ML-6 Pemantauan | Drift input per sync, realisasi bulanan, alarm skor basi, prosedur retrain | Dashboard pemantauan + runbook |

**Metrik yang diusulkan:** precision@k dan recall@k (k = kuota harian per Posko dan total 108/hari), PR-AUC (kelas sangat tidak seimbang), Brier score, cakupan gangguan BINATANG 30 hari berikutnya oleh tiang/section terpilih, dan perbandingan dengan B0/B1 pada jendela waktu yang sama.

**Klasifikasi saat ini:**

| Lapisan | Status |
|---|---|
| Rule-based / heuristik | ADA (kelayakan + urutan tetap) |
| Ranking berbobot | TIDAK ADA |
| Model ML terlatih | TIDAK ADA |
| Model tervalidasi | TIDAK ADA |
| Model terintegrasi pada generator | TIDAK ADA (generator pun belum ada) |

**Syarat readiness (gerbang go-live D-2):** ML-0 s.d. ML-6 selesai, model lulus metrik yang disepakati pada data uji di luar periode latih, generator memakai skor dengan fallback teruji, dan pemantauan aktif. Selama gerbang belum lulus, sistem **tidak boleh** diklaim siap operasional.

---

## 11. Integrasi data SPS BOS

### 11.1 Status sinkronisasi

| Sumber | Mekanisme | Status | Catatan |
|---|---|---|---|
| APKUK_RAW | `apkuk:sync` cron 15 menit, resilient (PR #11) | IMPLEMENTED | Production: aktif 3.721, pending 78, histori 716 (pagi 28 Okt). Baris terus bertambah (4.569 baris total sore 28 Okt) |
| GANGGUAN_PUSAT_IMPORT | `gangguan:sync` cron, 28 posisi kolom tervalidasi | IMPLEMENTED | Penyulang disimpan mentah (TANGGAMUS belum dialias); ID yang hilang dari sumber tidak ditandai |
| KEYPOINT_RAW | `keypoint:sync` cron | IMPLEMENTED | 129 baris, `NOTE` 0% |
| ASSET_RAW, VEGETASI_RAW, SECTION_PIC_RAW | `sources:import` manual ke staging | PARTIAL | Bukan sinkron berkala; `--replace` mengubah ID baris |
| MASTER_POSKO, ASSET_REVIEW_RAW | Tidak dibaca | Sesuai D-7/D-10 | — |

### 11.2 Normalisasi: status terhadap keputusan D-6/D-7

| Normalisasi | Kondisi | Status |
|---|---|---|
| Posko SRIBHAWONO | Master memakai `SRIBHAWONO` (`SyncCoreTopologyMasters::OFFICIAL_POSKOS`) | Sesuai. **Catatan:** `SyncGangguanPusat.php:71` memfilter kolom ULP sumber yang ditulis `SRIBAWONO` — filter ini harus tetap mengikuti ejaan sumber |
| TANGGAMUS → TANGGAMUS 2 | Alias diterapkan saat sinkron master; **tidak** pada `gangguans.penyulang` (33 baris), Special Case, Analytics, dan Maps | PARTIAL — terapkan saat baca/agregasi tanpa mengubah nilai mentah |
| Penyulang bertitik (`CAKALELE.`, `KECAK.`) | Dinormalisasi kandidat APKUK (`normalizePenyulang`) | Sesuai |
| PSK001–PSK006 | Tidak dibaca dan tidak ditampilkan | Sesuai D-7 |
| Fakta lapangan (koordinat, foto, status, jumlah, kondisi, pengukuran, histori) | Disimpan apa adanya; WO menyimpan snapshot target | Sesuai |

### 11.3 Perlakuan DUMMY (D-5)

| Tempat | Perlakuan | Penilaian |
|---|---|---|
| Sinkron APKUK | Baris DUMMY disimpan apa adanya (tidak ada filter) | Sesuai |
| Halaman APKUK | Ditampilkan apa adanya | Sesuai |
| Kandidat WO | Section DUMMY → peringatan saja, `section_name` WO = null, nilai mentah tetap di `section_source`. No. tiang DUMMY → tidak layak WO | Sesuai untuk data; pengecualian target perlu konfirmasi (K-8) |
| Penyulang DUMMY (1 laporan) | Tidak cocok master → tidak layak WO | Konsekuensi pencocokan master, bukan filter DUMMY |
| Section internal (D-8) | Belum ada mekanisme Admin melengkapi Section | MISSING |

### 11.4 Dampak spreadsheet aset penyulang lengkap (D-10)

| Area | Dampak | Persiapan tanpa mengarang data |
|---|---|---|
| Identitas aset canonical | Kunci kemungkinan `ID_ASET` (format `17TG…`) + nama tiang (`F.PENYULANG.000123`); 3.135 dari 3.747 tiang APKUK aktif cocok aset pada penyulang yang sama, 3.711 pada penyulang mana pun | Tabel `assets` + `asset_identifiers` (alias) dengan provenance dan confidence; jangan hapus staging lama |
| Section mapping | Bila spreadsheet baru memuat Section/Posko per aset, pemetaan kandidat → Posko menjadi resmi (TBD-6) | Siapkan importer idempoten dengan laporan selisih |
| Master Posko/Penyulang | Validasi terhadap 6 Posko/16 penyulang resmi + alias | Tolak nilai baru tanpa persetujuan |
| Vegetasi & ID POHON | `ID_TIANG` vegetasi (`17TG…`) dapat menaut pohon ke tiang canonical | Simpan ID POHON sebagai kunci target ROW |
| ML | Fitur spasial/topologi lebih kaya; label gangguan → aset lebih akurat | Dataset ber-versi per snapshot aset |
| WO | Snapshot target lama tetap; WO baru menaut aset canonical | Jangan menulis ulang snapshot WO |
| Maps | Layer aset 17 rb titik memerlukan API per penyulang + bbox (K-5) | Bangun sebelum layer aset diaktifkan |
| Histori per aset | `asset_history_events` + foto terbaru per aset | Additive |

---

## 12. Kesiapan Flutter dan API

### 12.1 API mobile v1 (server)

| Kebutuhan | Kondisi | Status |
|---|---|---|
| Login akun tim, logout, `me` | `AuthController` (token Sanctum ability `field`, throttle 6/menit) | IMPLEMENTED |
| Bootstrap (tim, petugas aktif, kode referensi per role, shift, penyulang) | `BootstrapController` | IMPLEMENTED |
| Work feed scoped | `FieldWorkScope`: POSKO per Posko timnya; HARSUS/ROW per jenis tim; INSPEKSI tanpa WO | IMPLEMENTED |
| Mulai, laporan + foto, reschedule, eskalasi, kendala | `Api/V1/Mobile/WorkOrderController` | IMPLEMENTED |
| Kunjungan + temuan inspeksi | `Api/V1/Mobile/InspectionController` | IMPLEMENTED |
| Idempotensi | `client_request_id` wajib + unique; replay → record sama; ID dipakai untuk WO lain → 422 | IMPLEMENTED. Risiko kecil: dua retry **paralel** dengan kunci sama dapat menghasilkan 500 (unique violation) sebelum retry berikutnya mengembalikan record yang sama — tidak menggandakan data |
| `occurred_at` vs `received_at` | Disimpan terpisah pada laporan | IMPLEMENTED |
| `expected_version` / ETag | Tidak ada; konflik ditangani lewat guard status (409) | PARTIAL |
| Upload foto tahan putus (manifest/checksum/resume) | Multipart biasa ≤10 MB per foto; SHA-256 dihitung server | PARTIAL |
| Endpoint PASILA, P0, area inspeksi, rute ROW, kode referensi terpisah | Tidak ada | MISSING (domain belum ada) |
| Token kedaluwarsa / batas perangkat | Tidak ada (TBD) | Sesuai TBD; risiko §13 |

### 12.2 Flutter Android

| Kebutuhan v3 | Kondisi di `mobile/` | Status |
|---|---|---|
| Login akun tim ke API | Form login memvalidasi isian lalu langsung membuka beranda tanpa memanggil server (`login_screen.dart::_submit`) | MISSING |
| Beranda tugas per role | Placeholder "Layar Beranda menyusul" | MISSING |
| Pilih petugas aktual, dropdown dari DB | — | MISSING |
| Kamera + GPS | Tidak ada dependency | MISSING |
| SQLite/outbox, WorkManager, status QUEUED…ACTION_REQUIRED | Tidak ada dependency | MISSING |
| Google Maps eksternal | — | MISSING |
| Kualitas kode | `flutter analyze`: 0 masalah; `flutter test`: 4/4 lulus (UI login) | Hanya membuktikan layar login |

Flutter **belum dapat diklaim tersedia** untuk satu role pun.

---

## 13. Risiko production

| # | Risiko | Kemungkinan | Dampak | Mitigasi |
|---|---|---|---|---|
| P-1 | Go-live tanpa generator dan ML melanggar D-2 dan target 6 WO | Tinggi bila dipaksakan | Tinggi | Gerbang go-live §10.5 |
| P-2 | Batch A belum di production: tabel tidak ada, `reference_codes`/tim/petugas = 0 | Pasti | Tinggi — alur lapangan gagal validasi | Rencana `docs/BATCH_A_RELEASE.md` + seed kode resmi |
| P-3 | Foto tersimpan di filesystem Railway yang tidak persisten | Tinggi bila `MEDIA_DISK` tetap lokal | Tinggi — bukti hilang | Disk S3-compatible sebelum upload diaktifkan |
| P-4 | Empat Posko tanpa kandidat APKUK | Pasti dengan data sekarang | Sedang | TBD-5/TBD-6; tampilkan shortfall |
| P-5 | Reimport vegetasi `--replace` merusak target ROW | Sedang | Sedang | K-3 sebelum ROW dipakai |
| P-6 | Token Sanctum tidak kedaluwarsa | Sedang | Sedang (perangkat hilang) | Kebijakan sesi (TBD-15); pencabutan token sudah ada saat reset/nonaktif |
| P-7 | Agregat Gangguan tidak menggabungkan TANGGAMUS | Pasti | Rendah–sedang (Special Case/ML) | Alias saat baca |
| P-8 | Label gangguan tertunda/tidak lengkap | Tinggi | Sedang (ML) | §10.4 |
| P-9 | Peta melambat ketika layer aset ditambahkan | Tinggi | Sedang | K-5 |
| P-10 | Retry paralel API → 500 | Rendah | Rendah | Tangani unique violation sebagai replay |
| P-11 | Perubahan struktur SPS BOS | Sedang | Tinggi | Guard header/snapshot PR #11 sudah ada; tambahkan pemantauan drift untuk ML |
| P-12 | Scheduler produksi butuh perubahan Railway | Pasti | Sedang | Persetujuan terpisah; jangan jalankan scheduler produksi manual |

---

## 14. Rencana implementasi bertahap (berdasarkan dependensi)

| Batch | Isi | Prasyarat | Bukti selesai (DoD) |
|---|---|---|---|
| **B0 — Keputusan** | TBD-1, 2, 3, 5, 6, 7, 8, 9 (§16) | Pengguna/atasan | Keputusan tercatat di PROJECT_STATUS |
| **B1 — Penyelarasan kecil (tanpa aturan baru)** | Komentar shift (K-7); alias TANGGAMUS saat baca; halaman Detail APKUK 12 kolom (D-4); identitas ROW `ID POHON` (K-3, migration additive + backfill); Section internal ber-audit (D-8); penanganan retry paralel (P-10) | — | Tes per butir; PostgreSQL test terisolasi |
| **B2 — Fondasi shift** | `shift_instances`; perintah `shift:open` idempoten; trigger terjadwal (lokal/test dulu); pemakaian jadwal tim POSKO | B0 (TBD-1) | Tes rerun, paralel, 23.59/00.00/08.00/16.00 |
| **B3 — Generator rule-based** | 6 WO SYSTEM per Posko per shift dari kandidat sah + pemetaan Posko; shortfall; urutan rute; audit | B2, TBD-5/6/9 | Tes §9.3; uji data salinan |
| **B4 — ML** | ML-1…ML-6 (§10.5); integrasi skor ke B3 dengan fallback | B3, TBD-7/8 | Laporan backtest vs baseline; gerbang lulus |
| **B5 — PASILA & P0** | Sesi PASILA otomatis di `shift:open`; temuan per titik; P0 SYSTEM/ADMIN, ACC, pengganti wajib; endpoint API | B2; TBD-10/11 | Tes TECHNICAL_SPEC §9 (P0 scoped PASILA, reject → pengganti, reschedule tidak mengunci PASILA) |
| **B6 — Flutter** | Auth, bootstrap, feed per role, form, kamera/GPS, SQLite outbox, WorkManager, Google Maps eksternal | API v1 (ada); B5 untuk PASILA/P0 | Uji perangkat nyata OFFLINE_SYNC §9 |
| **B7 — ROW otomatis, INSPEKSI ranking, Maps v3, histori aset** | Generator ROW (formula), rute; ranking area; peta per penyulang + bbox + layer aset + drawer; `asset_history_events` | Spreadsheet aset (D-10), TBD-12/13 | Tes idempotensi ROW, search global |
| **B8 — Release** | Deploy Batch A dan batch berikutnya ke production dengan backup; MEDIA_DISK; Railway scheduler | Persetujuan deploy | Checklist `BATCH_A_RELEASE.md` |

Catatan: B8 (deploy Batch A) dapat berjalan paralel lebih awal setelah disetujui, karena tidak bergantung pada B2–B7.

---

## 15. Kebutuhan data tambahan yang benar-benar penting

| # | Data | Untuk | Pemilik |
|---|---|---|---|
| DT-1 | Pemetaan resmi Section → Posko (atau pengesahan `SECTION_PIC_RAW` POSKO PIC), termasuk REC SUKRI BALAK dan section tanpa PIC | Generator WO per Posko | Atasan/pemilik data |
| DT-2 | Spreadsheet aset penyulang lengkap (ID aset, nama tiang, jenis, koordinat, Section, Posko) | Aset canonical, Maps, ML, histori | Pemilik SPS BOS (sedang disiapkan) |
| DT-3 | Koordinat titik berangkat tiap Posko | Urutan rute | ULP |
| DT-4 | Kode referensi resmi (alasan reschedule, alasan limpahan, jenis temuan) | Semua form lapangan | ULP |
| DT-5 | Roster petugas per tim dan akun tim | Petugas aktual | ULP |
| DT-6 | Kelengkapan `penyebab`/`jenis_hewan` gangguan dan pencocokan `no_tiang` lintas penyulang | Label ML | PLN pusat / pemilik data |
| DT-7 | Tanggal pemasangan fisik APKUK historis (bila ada) | Efek pemasangan; evaluasi ML | Pemilik SPS BOS |
| DT-8 | Makna kolom vegetasi `jarak_aman` (nilai 100) dan kode `pertumbuhan` 1–4 | Formula ROW, fitur ML | Pemilik SPS BOS |
| DT-9 | Form P0 detail | Domain P0 | Atasan |

---

## 16. Keputusan yang masih TBD

Keputusan final 28 Okt (D-1 s.d. D-10) tidak diulang di sini.

| ID | Keputusan | Mengapa perlu | Memblokir |
|---|---|---|---|
| TBD-1 | Tanggal kerja Shift C: tanggal kalender (implementasi sekarang: 00.00–08.00 tanggal D) atau hari operasional yang dimulai 08.00 (C = malam setelah B) | Identitas shift-instance dan laporan per hari | B2 |
| TBD-2 | Apakah WO carry-over mengurangi kuota shift berikutnya (v3: TBD) | Kuota generator | B3 |
| TBD-3 | Matriks approval reschedule per jenis WO (implementasi sekarang selalu ACC, K-1) | Alur lapangan | — |
| TBD-4 | Verifikasi Admin atas penyelesaian HARSUS/ROW (sementara, K-2) | Beban review | — |
| TBD-5 | Sumber kandidat bila kebutuhan APKUK_RAW kurang dari target (tiang tanpa laporan? biarkan shortfall?) | Empat Posko tanpa kandidat | B3, ML opsi B |
| TBD-6 | Kepemilikan Section → Posko untuk penugasan WO | Penyulang multi-Posko | B3 |
| TBD-7 | Tujuan optimasi ML, grain, horizon, dan metrik/ambang lulus | Gerbang D-2 | B4 |
| TBD-8 | Tempat menjalankan training/inferensi ML (layanan batch terpisah di Railway atau lainnya) | Infrastruktur | B4 |
| TBD-9 | Titik awal rute per Posko | Urutan rute | B3 |
| TBD-10 | Form P0 detail (v3: TBD) | Domain P0 | B5 |
| TBD-11 | Bobot rekomendasi Section PASILA (v3: TBD) | PASILA otomatis | B5 |
| TBD-12 | Formula risiko vegetasi + kebijakan duplikasi ROW (v3: TBD) | Generator ROW | B7 |
| TBD-13 | Ranking/rotasi area INSPEKSI dan klasifikasi rekomendasi tim (v3: TBD) | INSPEKSI | B7 |
| TBD-14 | Metode, periode, dan ambang Special Case (v3: TBD) | Special Case | — |
| TBD-15 | Batas perangkat dan logout otomatis (v3: TBD) | Keamanan token | — |
| TBD-16 | Kode dropdown resmi (v3: TBD) | Semua form | Go-live |
| TBD-17 | Nama petugas manual di luar roster: izinkan sebagai cadangan atau larang (K-6) | Data petugas | — |
| TBD-18 | Koordinat temuan tanpa GPS perangkat (K-4) | Validitas bukti | — |
| TBD-19 | Lokasi marker gangguan: sumber tanpa koordinat; boleh memakai koordinat tiang (`no_tiang` → aset) sebagai pendekatan? | Maps | B7 |
| TBD-20 | 78 laporan APKUK pending (arsip atau tunggu) | Kebersihan data | — |
| TBD-21 | Header Gangguan yang menyesatkan dan nilai `#REF!` | Kontrak sumber | — |
| TBD-22 | Tiang `DUMMY`: konfirmasi tidak boleh menjadi target WO (K-8) | D-5 | — |
| TBD-23 | Aturan pembatalan WO SYSTEM/limpahan | Lifecycle | B3 |

---

## Lampiran A. Bukti pengujian sesi ini (28 Okt 2026)

| Uji | Hasil |
|---|---|
| `php artisan test` (HEAD `e10a85d`, SQLite in-memory) | **259 passed, 2.283 assertions**, 0 gagal |
| `flutter analyze --no-pub` | No issues found |
| `flutter test --no-pub` | 4/4 passed (layar login) |
| Audit data Neon production | Read-only (role runtime, `default_transaction_read_only=on`, guard host/role/timeline); hanya agregat |
| Uji PostgreSQL migration terisolasi | Terakhir 28 Okt (sesi integrasi): 22/22 migration, rollback 4, re-migrate; schema uji dihapus |

## Lampiran B. Penyelarasan dokumentasi (28 Okt 2026)

Perubahan minimum pada dokumen aktif:

| Dokumen | Perubahan |
|---|---|
| `docs/PROJECT_STATUS.md` | Tambahan bagian keputusan 28 Okt (D-1 s.d. D-10) + rujukan ke audit ini; baris Shift dan kebutuhan keputusan jam shift diperbarui |
| `docs/PRD.md` | §9: ML wajib sebelum operasional (rule-based tetap baseline/fallback); §10: jam shift final |
| `docs/TECHNICAL_SPEC.md` | §1: ML tidak lagi "tidak wajib"; §5: jam shift final |
| `docs/DATA_MODEL.md` | §7: identitas ROW = ID POHON; source-state APKUK |
| `docs/API_CONTRACT.md` | §8: "shift config final" dihapus dari TBD |
| `docs/IMPLEMENTATION_PLAN.md` | §10: gerbang ML sebelum go-live |
| `docs/WEB_ADMIN_DATA_FIELD_AUDIT.md` | §8: penanda butir yang diputuskan 28 Okt |

`docs/FINAL_AUDIT_REPORT.md`, `docs/DESIGN_BRIEF.md`, dan `docs/OFFLINE_SYNC_SPEC.md` tidak diubah.
