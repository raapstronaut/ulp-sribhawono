# DESIGN BRIEF

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Business Flow v2 Baseline (Updated per 4 Oktober 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Platform:** Flutter Android App + Laravel Web Admin
**Primary field users:** POSKO, HARSUS, INSPEKSI, ROW
**Primary desktop user:** ADMIN
**Design priority:** Field operation first, monitoring second
**Connectivity assumption:** Online-primary untuk membaca data terbaru; input lapangan dapat disimpan sebagai draft offline dan dikirim manual saat online.

> [!NOTE]
> **STATUS GRAND DESIGN (T05 - AVAILABLE — Requires v2 Revision):**
> Dokumen ini menetapkan prinsip desain, screen inventory, dan target user flow. Perancangan visual detail / Grand Design menyeluruh untuk Web Admin dan Flutter Mobile belum selesai dan menjadi task aktif berikutnya (**T05 Grand Design Web + Mobile**). Screen inventory dan flow di bawah adalah target desain resmi yang harus diacu saat pembuatan mockup/desain final. Grand Design Web yang dibuat sebelumnya adalah draft/referensi dan **TIDAK BOLEH** ditandai final sampai direvisi untuk Business Flow v2. Mobile Grand Design juga belum final.

---

# 1. Design Principles

## Principle 1 — Prioritas Harus Terbaca dalam < 3 Detik
Urutan WO → PASILA harus langsung terlihat pada Home POSKO. P0 tidak muncul di POSKO; P0 muncul di Home HARSUS.

## Principle 2 — Field Action Lebih Penting daripada Dashboard Cantik
Form harus cepat, tombol utama besar, penggunaan satu tangan memungkinkan, dan kesalahan input harus mudah dipahami.

## Principle 3 — Offline adalah Mode Draft, bukan Error
Ketika jaringan hilang, user tetap dapat mengisi form, mengambil foto, dan menyimpan draft. Aplikasi harus membedakan dengan jelas antara:
- **Draft lokal** — belum menjadi data server;
- **Ready to Send** — siap dikirim;
- **Sending** — sedang dikirim;
- **Submitted** — sudah diterima server;
- **Failed** — gagal dan dapat dicoba ulang.

Aplikasi tidak menjanjikan bahwa pekerjaan baru dari server akan muncul ketika device offline.

---

# 2. Visual Direction

Gunakan tampilan operasional yang bersih, kontras, dan tidak dekoratif berlebihan. Status, prioritas, lokasi, dan tindakan utama harus lebih dominan daripada elemen visual sekunder.

Status harus memiliki label teks; warna tidak boleh menjadi satu-satunya indikator.

---

# 3. Design Tokens

Pertahankan token warna, typography, spacing, radius, dan elevation dari baseline sebelumnya. Implementasi final mengikuti design system proyek; perubahan arsitektur tidak mengubah identitas visual.

---

# 4. Screen Inventory

## Shared Mobile
- Login
- Connectivity Banner
- Draft / Submission Center
- Profile / Session

## POSKO
- Shift Home
- WO List / Detail
- PASILA Session / Action
- Maps
- Draft / Submission
- Riwayat online
- **REMOVED:** P0 List / Detail (POSKO tidak lagi mengeksekusi P0)

## HARSUS
- Home
- P0 List
- P0 Detail
- P0 Completion
- Reschedule Request
- Maps
- Draft / Submission (if relevant)
- Riwayat online
- **REMOVED:** Inspection screens (inspeksi dilakukan oleh INSPEKSI)

## INSPEKSI — NEW
- Home
- New Inspection
- Inspection Detail / Draft
- Unknown Point / Asset Finding
- New Vegetation Proposal
- Draft / Submission
- Maps
- Riwayat online

## ROW
- Home
- ROW Work List / Detail
- RABAS/PANGKAS/TEBANG Execution
- Draft Work
- Maps
- Riwayat online
- **REMOVED:** New Vegetation Proposal (proposal vegetasi baru dilakukan oleh INSPEKSI)

## Admin Web — Laravel
- Login
- Dashboard
- P0 Monitor + Reschedule Approval (P0 dieksekusi HARSUS)
- WO Monitor + Manual WO
- PASILA Monitor
- INSPEKSI Monitor + Asset/Location Validation + Vegetation Proposal Validation
- ROW Monitor (monitoring eksekusi saja)
- Special Case — notification/recommendation → Admin review/edit → create P0
- Maps
- Asset Detail / History
- Master Posko
- Master Penyulang
- Master Section
- Master Asset
- Master APKUK
- Master Vegetasi
- Master Petugas
- User & Hak Akses (5 roles)
- Audit Log
- Settings

**Ranking Posko tidak masuk MVP.**

---

# 5. User Flow

## 5.1 POSKO — Mulai Shift
1. Login/restore session.
2. Jika online, refresh data terbaru.
3. Tampilkan Shift berjalan.
4. Tampilkan urutan WO → PASILA.
5. Tampilkan badge jumlah draft belum dikirim.

Shift final:
- A: 08:00–16:00
- B: 16:00–00:00
- C: 00:00–08:00

POSKO **TIDAK** menerima atau mengeksekusi P0.

## 5.2 P0 — HARSUS

### Eksekusi normal
BARU → DITERIMA → DIKERJAKAN → SELESAI.

HARSUS **TIDAK** berbasis shift. P0 tidak menggunakan Shift A/B/C.

### Petugas
Petugas aktual dipilih oleh HARSUS saat completion/submission P0, **BUKAN** saat menerima atau memulai P0.

### Belum selesai
Saat P0 belum selesai, tampilkan CTA:

**Reschedule**
- pilih target tanggal;
- alasan wajib;
- selama request menunggu Admin, P0 tetap berstatus DIKERJAKAN;
- Admin approve/reject;
- approve → RESCHEDULED, reject → tetap DIKERJAKAN;
- P0 tetap menggunakan ID yang sama.

**REMOVED:** "Lanjut Shift Berikutnya" (HARSUS tidak berbasis shift).

## 5.3 Work Order

WO MVP hanya pemasangan APKUK. Detail harus menampilkan:
- asset;
- section;
- shift;
- priority score bila tersedia;
- **Kenapa titik ini dipilih?** (`alasan_prioritas`);
- status;
- petugas;
- dokumentasi.

WO yang tidak selesai dapat dijadwalkan ulang ke tanggal/shift target dengan alasan. Carry-over WO memakai slot kuota shift tujuan terlebih dahulu sehingga total target tetap 6 WO/Posko pada shift tersebut.

## 5.4 PASILA

Sebelum mulai, sistem menampilkan **Rekomendasi Target PASILA** berdasarkan prioritas gangguan/trip.

User dapat:
- **Gunakan Rekomendasi**; atau
- **Pilih Lokasi Lain** → alasan override wajib.

Action PASILA yang tidak dapat dieksekusi menampilkan field:
- alasan;
- kebutuhan padam;
- kebutuhan material;
- dokumentasi;
- opsi eskalasi menjadi P0 (**proposal yang memerlukan persetujuan Admin**).

Escalation PASILA **TIDAK** langsung membuat P0 executable. Admin harus mereview dan menyetujui terlebih dahulu. P0 yang disetujui ditugaskan ke HARSUS.

## 5.5 INSPEKSI — NEW

Form inspeksi mendukung:
- pilih asset bila tersedia;
- bila asset belum ada → pilih/isi section bila diketahui, ambil koordinat, foto, temuan, lalu submit sebagai titik `PENDING_ASSET_VALIDATION`;
- kebutuhan padam: TANPA_PADAM / PERLU_PADAM;
- kebutuhan material (isi `TIDAK_ADA` bila tidak ada);
- rekomendasi;
- perlu P0: ya/tidak.

Jika Perlu P0 = yes:
- Admin validation/review;
- P0 executable dibuat hanya setelah approval;
- HARSUS mengeksekusi.

Unknown point **belum dikirim sebagai P0 ke HARSUS**. Admin harus memetakan/membuat asset + Section/Posko terlebih dahulu; setelah valid barulah P0 dibuat dengan snapshot kebutuhan eksekusi.

### Vegetasi Baru (INSPEKSI)
- ambil koordinat;
- foto;
- catatan;
- simpan draft/submit;
- status menunggu validasi Admin;
- setelah validasi → Master Vegetasi / ROW work.

## 5.6 ROW

ROW Work mencatat tindakan RABAS/PANGKAS/TEBANG, foto, catatan, petugas, waktu, dan lokasi.

**REMOVED:** Proposal vegetasi baru dari ROW. Proposal vegetasi baru dilakukan oleh INSPEKSI.

Tidak ada field teks wajib `kondisi_sebelum` / `kondisi_sesudah`.

## 5.7 Admin Monitoring

Dashboard menggunakan online-primary polling ringan, default sekitar **15 detik**, ditambah tombol **Refresh Sekarang**. Realtime WebSocket tidak menjadi requirement MVP.

## 5.8 Offline Draft → Submit

Flow final:

`Isi Form → Simpan Draft → Edit bila perlu → Ready to Send → online → Kirim/Kirim Semua → Submitted atau Failed`

Bulk submit tidak all-or-nothing. Item berhasil tetap berhasil; hanya item gagal yang tetap berada di Submission Center.

---

# 6. Layout per Screen

## 6.1 POSKO Shift Home
Urutan visual:
1. Shift header + koneksi.
2. WO shift berjalan.
3. PASILA.
4. Draft belum dikirim.

**REMOVED:** P0 aktif dari POSKO home (POSKO tidak mengeksekusi P0).

## 6.2 Work List
Filter: jenis pekerjaan, shift, status, tanggal. Item draft lokal diberi label yang berbeda dari record server.

## 6.3 P0 Detail (HARSUS)
Area utama:
- status/prioritas;
- asset/lokasi;
- sumber P0;
- kebutuhan padam + kebutuhan material sebagai snapshot eksekusi;
- konteks INSPEKSI/PASILA bila ada;
- petugas (dipilih saat completion);
- dokumentasi;
- timeline;
- CTA mulai/selesai;
- CTA Reschedule ketika belum selesai (target tanggal + alasan).

## 6.4 WO Detail
Tampilkan alasan prioritas secara eksplisit, bukan hanya angka score. Jika WO merupakan carry-over, tampilkan asal tanggal/shift, target baru, dan alasan reschedule. Saat completion, form dapat menerima kode APKUK bila tersedia; setelah submit berhasil, UI menampilkan bahwa APKUK pada aset telah tercatat aktif.

## 6.5 PASILA Session
Tampilkan rekomendasi target dan alasan rekomendasi bila tersedia. Bila user override, alasan wajib terlihat di summary.

## 6.6 PASILA Action Form
Field minimum: asset/lokasi, action type, uraian, status eksekusi, kebutuhan padam/material bila tidak bisa dieksekusi, foto, petugas.

## 6.7 INSPEKSI New Inspection
Mendukung asset existing atau lokasi belum terdaftar dengan koordinat. Mendukung proposal Perlu P0 yang akan direview Admin.

## 6.8 ROW Job Detail
Tampilkan target vegetasi, tindakan, foto, catatan, petugas, status.

## 6.9 Maps Mobile
Online-primary. Marker/koordinat yang sudah dicache boleh tampil tanpa koneksi bila tersedia, tetapi basemap penuh tidak dijamin offline.

## 6.10 Asset Detail
Histori lengkap online-primary.

## 6.11 Draft / Submission Center
Ganti konsep "Sync Center" lama menjadi **Draft / Submission Center**.

Tab/kelompok:
- Draft
- Ready to Send
- Sending
- Failed
- Recently Submitted

CTA:
- Edit
- Kirim
- Kirim Ulang
- Kirim Semua

## 6.12 Admin Dashboard
KPI operasional, critical queue, status reschedule, Special Case notification/recommendation, progress WO, PASILA, INSPEKSI, ROW, dan recent activity.

## 6.13 Admin Work Monitor
Tabel online dengan filter + refresh manual/otomatis. Admin juga memiliki antrean validasi lokasi untuk unknown point dari INSPEKSI/PASILA sebelum P0 executable dibuat. Validasi proposal vegetasi dari INSPEKSI.

## 6.14 Master Data Screens
Admin dapat CRUD master Posko, Penyulang, Section, Asset, APKUK, Vegetasi, Petugas, dan User (5 roles) sesuai audit policy.

---

# 7. Component Library

Pertahankan App Shell, Button, Job Card, Status Badge, Priority Banner, Asset Selector, Petugas Selector, Timeline, Map Marker, Data Table, KPI Tile, Form Field, Dialog, Toast.

Tambahkan/ubah:
- `ConnectivityBanner`
- `LocalDraftBadge`
- `SubmissionStatusBadge`
- `SendAllButton`
- `RescheduleRequestSheet` (target tanggal + alasan, tanpa shift untuk HARSUS)
- `AdminApprovalBadge`

## Evidence Capture
Jenis foto final:
- BEFORE
- AFTER
- TEMUAN
- SURVEY

Semua pekerjaan harus memiliki minimal satu foto sebelum dapat submit. Draft tetap boleh disimpan tanpa memenuhi seluruh validasi submit.

---

# 8. Screen States

Setiap screen perlu membedakan:
- Loading server;
- Online;
- Offline Draft Mode;
- Validation Error;
- Submission Failed;
- Conflict;
- Submitted.

### Offline Draft Mode
Banner: **Offline — perubahan disimpan sebagai draft di perangkat dan belum dikirim ke server.**

### Conflict
Jika server berubah sejak data terakhir dibuka, jangan overwrite. Tampilkan bahwa data perlu diperiksa sebelum submit ulang.

### Disabled Account While Offline
Device dapat tetap menyimpan draft lokal sampai koneksi kembali. Saat online, server memvalidasi akun. Bila akun nonaktif, submit ditolak dan aplikasi meminta login/otorisasi ulang; draft lokal tidak dihapus otomatis.

---

# 9. Responsive Behaviour

Mobile diprioritaskan untuk operasi lapangan. Web Admin responsif untuk desktop/laptop dan tablet, tetapi bukan target mobile utama.

---

# 10. Accessibility

Pertahankan prinsip kontras, focus, keyboard navigation di web, touch target yang cukup, label form eksplisit, error messaging yang dapat ditindaklanjuti, dan accessibility semantics Flutter.

---

# Final Design Direction

Desain MVP menggunakan pola **online-primary + offline draft**. UI tidak boleh memakai istilah "tersinkron otomatis" untuk menjelaskan draft. Istilah yang digunakan ke user adalah **Draft**, **Siap Dikirim**, **Mengirim**, **Terkirim**, dan **Gagal**.
