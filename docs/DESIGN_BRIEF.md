# DESIGN BRIEF

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Final / Locked Baseline (Refreshed per 30 September 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Platform:** Flutter Android App + Laravel Web Admin
**Primary field users:** POSKO, HARSUS, ROW
**Primary desktop user:** ADMIN
**Design priority:** Field operation first, monitoring second
**Connectivity assumption:** Online-primary untuk membaca data terbaru; input lapangan dapat disimpan sebagai draft offline dan dikirim manual saat online.

> [!NOTE]
> **STATUS GRAND DESIGN (T05 - AVAILABLE):**
> Dokumen ini menetapkan prinsip desain, screen inventory, dan target user flow. Perancangan visual detail / Grand Design menyeluruh untuk Web Admin dan Flutter Mobile belum selesai dan menjadi task aktif berikutnya (**T05 Grand Design Web + Mobile**). Screen inventory dan flow di bawah adalah target desain resmi yang harus diacu saat pembuatan mockup/desain final.

---

# 1. Design Principles

## Principle 1 — Prioritas Harus Terbaca dalam < 3 Detik
Urutan P0 → WO → PASILA harus langsung terlihat pada Home POSKO.

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

Gunakan tampilan operasional yang bersih, kontras, dan tidak dekoratif berlebihan. Status, prioritas, lokasi, shift, dan tindakan utama harus lebih dominan daripada elemen visual sekunder.

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
- P0 List / Detail
- WO List / Detail
- PASILA Session / Action
- Maps
- Riwayat online

## HARSUS
- Home
- New Inspection
- Draft Inspection
- Maps
- Riwayat online

## ROW
- Home
- ROW Work Detail
- New Vegetation Proposal
- Draft Work
- Maps
- Riwayat online

## Admin Web — Laravel
- Login
- Dashboard
- P0 Monitor + Reschedule Approval
- WO Monitor + Manual WO
- PASILA Monitor
- HARSUS Monitor + Asset Validation
- ROW Monitor + Vegetation Validation
- Special Case
- Maps
- Asset Detail / History
- Master Posko
- Master Penyulang
- Master Section
- Master Asset
- Master APKUK
- Master Vegetasi
- Master Petugas
- User Management
- Audit Log
- Settings

**Ranking Posko tidak masuk MVP.**

---

# 5. User Flow

## 5.1 POSKO — Mulai Shift
1. Login/restore session.
2. Jika online, refresh data terbaru.
3. Tampilkan Shift berjalan.
4. Tampilkan urutan P0 → WO → PASILA.
5. Tampilkan badge jumlah draft belum dikirim.

Shift final:
- A: 08:00–16:00
- B: 16:00–00:00
- C: 00:00–08:00

## 5.2 P0

### Eksekusi normal
BARU → DITERIMA → DIKERJAKAN → SELESAI.

### Belum selesai
Saat P0 belum selesai, tampilkan dua CTA:

**Lanjut Shift Berikutnya**
- alasan wajib;
- target shift otomatis berikutnya;
- langsung berlaku;
- P0 tetap menggunakan ID yang sama.

**Reschedule**
- pilih tanggal;
- pilih shift;
- alasan wajib;
- selama request menunggu Admin, P0 tetap berstatus DIKERJAKAN;
- Admin approve/reject;
- approve → RESCHEDULED, reject → tetap DIKERJAKAN;
- P0 tetap menggunakan ID yang sama.

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

P0 tetap mengalahkan WO. WO yang tidak selesai karena prioritas P0 dapat dijadwalkan ulang ke tanggal/shift target dengan alasan. Carry-over WO memakai slot kuota shift tujuan terlebih dahulu sehingga total target tetap 6 WO/Posko pada shift tersebut.

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
- opsi eskalasi menjadi P0.

## 5.5 HARSUS

Form inspeksi mendukung:
- pilih asset bila tersedia;
- bila asset belum ada → pilih/isi section bila diketahui, ambil koordinat, foto, temuan, lalu submit sebagai titik `PENDING_ASSET_VALIDATION`;
- kebutuhan padam: TANPA_PADAM / PERLU_PADAM;
- kebutuhan material (isi `TIDAK_ADA` bila tidak ada);
- perlu P0: ya/tidak.

Unknown point **belum dikirim sebagai P0 ke POSKO**. Admin harus memetakan/membuat asset + Section/Posko terlebih dahulu; setelah valid barulah P0 dibuat dengan snapshot kebutuhan eksekusi.

## 5.6 ROW

ROW Work mencatat tindakan RABAS/PANGKAS/TEBANG, foto, catatan, petugas, waktu, dan lokasi.

Untuk titik vegetasi baru:
- ambil koordinat;
- foto;
- catatan;
- simpan draft/submit;
- status menunggu validasi Admin.

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
2. P0 aktif.
3. WO shift berjalan.
4. PASILA.
5. Draft belum dikirim.

## 6.2 Work List
Filter: jenis pekerjaan, shift, status, tanggal. Item draft lokal diberi label yang berbeda dari record server.

## 6.3 P0 Detail
Area utama:
- status/prioritas;
- asset/lokasi;
- sumber P0;
- kebutuhan padam + kebutuhan material sebagai snapshot eksekusi;
- konteks HARSUS/PASILA bila ada;
- petugas;
- dokumentasi;
- timeline;
- CTA mulai/selesai;
- CTA Lanjut Shift Berikutnya / Reschedule ketika belum selesai.

## 6.4 WO Detail
Tampilkan alasan prioritas secara eksplisit, bukan hanya angka score. Jika WO merupakan carry-over, tampilkan asal tanggal/shift, target baru, dan alasan reschedule. Saat completion, form dapat menerima kode APKUK bila tersedia; setelah submit berhasil, UI menampilkan bahwa APKUK pada aset telah tercatat aktif.

## 6.5 PASILA Session
Tampilkan rekomendasi target dan alasan rekomendasi bila tersedia. Bila user override, alasan wajib terlihat di summary.

## 6.6 PASILA Action Form
Field minimum: asset/lokasi, action type, uraian, status eksekusi, kebutuhan padam/material bila tidak bisa dieksekusi, foto, petugas.

## 6.7 HARSUS New Inspection
Mendukung asset existing atau lokasi belum terdaftar dengan koordinat.

## 6.8 ROW Job Detail
Tampilkan target vegetasi, tindakan, foto, catatan, petugas, status.

## 6.9 Maps Mobile
Online-primary. Marker/koordinat yang sudah dicache boleh tampil tanpa koneksi bila tersedia, tetapi basemap penuh tidak dijamin offline.

## 6.10 Asset Detail
Histori lengkap online-primary.

## 6.11 Draft / Submission Center
Ganti konsep “Sync Center” lama menjadi **Draft / Submission Center**.

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
KPI operasional, critical queue, status reschedule, Special Case, progress WO, PASILA, HARSUS, ROW, dan recent activity.

## 6.13 Admin Work Monitor
Tabel online dengan filter + refresh manual/otomatis. Admin juga memiliki antrean validasi lokasi untuk unknown point dari HARSUS/PASILA sebelum P0 executable dibuat.

## 6.14 Master Data Screens
Admin dapat CRUD master Posko, Penyulang, Section, Asset, APKUK, Vegetasi, Petugas, dan User sesuai audit policy.

---

# 7. Component Library

Pertahankan App Shell, Button, Job Card, Status Badge, Priority Banner, Asset Selector, Petugas Selector, Timeline, Map Marker, Data Table, KPI Tile, Form Field, Dialog, Toast.

Tambahkan/ubah:
- `ConnectivityBanner`
- `LocalDraftBadge`
- `SubmissionStatusBadge`
- `SendAllButton`
- `RescheduleChoiceSheet`
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

Desain MVP menggunakan pola **online-primary + offline draft**. UI tidak boleh memakai istilah “tersinkron otomatis” untuk menjelaskan draft. Istilah yang digunakan ke user adalah **Draft**, **Siap Dikirim**, **Mengirim**, **Terkirim**, dan **Gagal**.
