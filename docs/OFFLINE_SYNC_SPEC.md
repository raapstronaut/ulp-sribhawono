# OFFLINE DRAFT & SUBMISSION SPECIFICATION

## Sistem Pengelolaan Pekerjaan Preventive Jaringan Distribusi — ULP Sribhawono

**Status:** Business Flow v2 Baseline (Updated per 4 Oktober 2026)
**Project Status & Execution State:** [PROJECT_STATUS.md](./PROJECT_STATUS.md)
**Dokumen Acuan:** PRD.md · DESIGN_BRIEF.md · TECHNICAL_SPEC.md · DATA_MODEL.md
**Platform Target:** Flutter Android + SQLite local draft/cache + Laravel API + PostgreSQL
**Catatan:** Dokumen ini menggantikan desain full offline synchronization/PowerSync sebelumnya.

> [!NOTE]
> **STATUS IMPLEMENTASI (T19 - PENDING):**
> Dokumen ini merupakan **spesifikasi perilaku mobile offline masa depan**. Implementasi database lokal SQLite (`sqflite`), manajemen antrean draft, dan outbox pengiriman pada project Flutter Android saat ini **BELUM DIMULAI** (berstatus `PENDING` di Task Pool). Seluruh prinsip arsitektur (draft mode, server authoritative, manual submit, optimistic locking, dan idempotency) telah terkunci dan menjadi panduan saat implementasi Task T19 dikerjakan.

---

# 1. Offline Principles

1. Offline digunakan terutama untuk **menyimpan input lapangan sebagai draft**.
2. Draft belum menjadi data resmi server sampai user menekan **Kirim** atau **Kirim Semua** saat online.
3. Draft harus tetap ada walau app ditutup/HP restart.
4. Foto dapat diambil offline dan disimpan lokal.
5. Data server baru tidak diharapkan muncul ketika offline.
6. Histori lengkap dan Maps penuh online-primary.
7. Retry tidak boleh membuat duplicate record.
8. Waktu input/aktivitas dan waktu submit server disimpan terpisah.

---

# 2. Offline Capability Matrix

| Operation | Offline | Notes |
|---|---:|---|
| Membuka draft lokal | ✅ | selalu |
| Mengedit draft | ✅ | sebelum submit |
| Isi hasil WO yang sudah tersedia lokal (POSKO) | ✅ | disimpan draft |
| Buat PASILA action draft (POSKO) | ✅ | menggunakan cache ringan |
| P0 completion/reschedule draft (HARSUS) | ✅ | cached P0 work |
| Buat inspection baru (INSPEKSI) | ✅ | termasuk unknown point |
| Buat vegetation proposal (INSPEKSI) | ✅ | draft proposal |
| Buat ROW work (ROW) | ✅ | termasuk foto/koordinat |
| Ambil foto | ✅ | local file |
| Pilih asset/section/petugas | ✅ terbatas | hanya cache yang sudah diunduh |
| Kirim data | ❌ | perlu internet |
| Terima P0/WO baru | ❌ | muncul setelah online refresh |
| Login baru | ❌ | perlu server |
| Histori lengkap | ❌ | online-primary |
| Full Google basemap | ❌ dijamin | tidak menjadi requirement |

---

# 3. Local SQLite Data Scope

## 3.1 Cache tables
- `cache_posko`
- `cache_section`
- `cache_asset`
- `cache_petugas`
- optional `cache_apkuk_summary`

Cache menyimpan hanya data yang dibutuhkan form dan identifikasi lokasi. Tidak menjadi source of truth server.

## 3.2 Draft tables
- `local_draft`
- `local_draft_photo`
- `submission_outbox`

Contoh `local_draft`:
- `local_id UUID`
- `entity_type`
- `operation CREATE/UPDATE`
- `server_entity_id NULLABLE`
- `expected_server_version NULLABLE`
- `payload_json`
- `input_at`
- `status`
- `last_error_code/message`
- local timestamps

---

# 4. Submission State Model

```text
DRAFT
  │ user menyelesaikan isian minimum
  ▼
READY_TO_SEND
  │ online + klik Kirim
  ▼
SENDING
  ├── success ──► SUBMITTED
  ├── network/validation ──► FAILED
  └── version conflict ──► FAILED(CONFLICT)
```

`SUBMITTED` adalah state lokal bahwa server sudah mengonfirmasi. Record server memiliki status domain sendiri (BARU/DIKERJAKAN/SELESAI, dll).

---

# 5. Record Creation Offline Flow

Contoh INSPEKSI:
1. User buka New Inspection.
2. Pilih asset dari cache atau pilih unknown point.
3. Isi temuan.
4. Ambil coordinate.
5. Ambil foto.
6. Simpan Draft.
7. User dapat kembali/edit.
8. Tandai Ready to Send.
9. Saat online klik Kirim.
10. Laravel validasi dan create server record.
11. Local status → SUBMITTED.

---

# 6. Existing Server Record Update Offline Flow

Contoh P0 sudah pernah di-fetch oleh HARSUS saat online:
1. Simpan `server_entity_id` + `server_version` ke local context.
2. Saat offline, HARSUS isi hasil/perubahan sebagai draft.
3. Submit membawa `expected_version`.
4. Laravel menerima hanya jika version masih cocok.
5. Jika server sudah berubah, return `409 CONFLICT`.

---

# 7. Conflict Resolution Strategy

Tidak menggunakan last-write-wins otomatis untuk pekerjaan kritis.

Kasus dua HP satu akun:
- HP A submit P0 version 3 → accepted → server version 4.
- HP B masih membawa expected version 3 → submit → 409 CONFLICT.
- Draft HP B tetap ada.
- UI meminta refresh/periksa data terbaru.

Tujuan: mencegah overwrite dan duplicate pendataan.

---

# 8. Domain State Transition During Submission

Draft lokal boleh bebas diedit sebelum submit, tetapi Laravel tetap memeriksa transisi domain legal.

Contoh:
- P0 BARU tidak boleh langsung SELESAI bila flow mewajibkan ACCEPT/START terlebih dahulu.
- P0 reschedule request divalidasi server (target tanggal + alasan, Admin approval).
- Completed record tidak bisa diedit user lapangan setelah server menerima completion.

---

# 9. Photo Offline Architecture

1. Camera menghasilkan file lokal.
2. File dipindah ke app-private directory.
3. SQLite menyimpan local path + photo type + captured_at + coordinate.
4. Foto terkait draft tidak dihapus sebelum server confirm submission.
5. Semua pekerjaan wajib minimal satu foto saat submit.
6. Draft boleh disimpan sebelum semua foto lengkap.

Jenis foto:
- BEFORE
- AFTER
- TEMUAN
- SURVEY

---

# 10. Submission Ordering / Dependency

Jika draft memiliki parent-child:

```text
Parent transaction
  └── documentation/photos
```

atau PASILA:

```text
Session
  └── Action
       └── Photos
       └── optional P0 escalation proposal
```

Setiap item queue dikirim ke **endpoint domain canonical** sebagai satu logical multipart submission (payload + foto). Parent/entity dibuat atau diperbarui terlebih dahulu, lalu metadata dokumentasi ditautkan oleh Laravel sebelum success dikembalikan. Tidak ada endpoint bulk server generik. Tombol `Kirim Semua` hanya mengulang proses per item; dependency internal sebuah item ditangani server.

---

# 11. Idempotency & Duplicate Prevention

Setiap intended submission memiliki `client_request_id` UUID.

Retry dengan key sama:
- tidak create ulang;
- server mengembalikan hasil sebelumnya atau safe replay response.

Key baru hanya digunakan jika user memang membuat intended transaction baru.

---

# 12. Deletion Strategy

Draft lokal dapat dihapus user sebelum submit dengan confirmation.

Setelah SUBMITTED:
- local working copy dapat dipurge setelah retention lokal tertentu;
- server transaction tidak di-hard-delete oleh field user.

---

# 13. Connectivity Detection & UI State

Connectivity hanya indikator awal; request tetap harus menangani kegagalan nyata.

UI states:
- Online
- Offline Draft Mode
- Sending
- Failed
- Conflict

Offline banner:
**Offline — data yang Anda isi akan disimpan di perangkat dan belum dikirim ke server.**

---

# 14. Authentication & Session Handling While Offline

- Login baru harus online.
- Session/token yang sudah tersimpan memungkinkan user membuka local draft/cache.
- Saat online, token divalidasi lagi.
- Jika akun dinonaktifkan saat device offline, user mungkin masih dapat menulis draft lokal; submit ditolak setelah server mengetahui account disabled.
- Draft tidak dihapus otomatis karena account disabled.

---

# 15. App Restart & Crash Recovery

SQLite transaction wajib memastikan draft tidak korup. Setelah restart:
- pending DRAFT/READY/FAILED tetap muncul;
- item `SENDING` yang tidak mendapat konfirmasi dikembalikan ke READY/FAILED yang aman untuk retry menggunakan idempotency key yang sama.

---

# 16. Submission UI / UX

Screen **Draft / Submission Center** menampilkan:
- jumlah Draft
- Ready to Send
- Failed
- Recently Submitted

Action:
- Edit
- Kirim
- Kirim Semua
- Retry
- Hapus Draft (hanya belum submit)

`Kirim Semua` memproses item per item. Partial success wajib terlihat: item sukses `SUBMITTED`, item gagal `FAILED` dan dapat retry tanpa mengirim ulang item sukses.

---

# 17. Role-Based Local Scope

## POSKO
Cache wilayah Posko sendiri + pekerjaan WO/PASILA yang sudah diterima/fetched.
- WO draft/submission.
- PASILA draft/submission.
- **NOT** P0 (POSKO does not execute P0).

## HARSUS
- Cached/available P0 work across whole ULP.
- P0 completion/reschedule submission.
- Petugas selected at completion.
- **NOT** inspection (inspection is done by INSPEKSI).

## INSPEKSI
- Inspection draft/submission.
- Unknown point finding draft.
- Vegetation proposal draft.
- Photos.
- Send when online.

## ROW
Cache reference minimum untuk pekerjaan vegetasi.
- ROW work draft (RABAS/PANGKAS/TEBANG).
- **NOT** new vegetation proposal (proposals are from INSPEKSI).

ADMIN tidak memiliki offline Web requirement.

---

# 18. Bootstrap / Refresh Flow

Saat online:
1. Validate session.
2. Fetch shift/config.
3. Fetch latest work feed (role-appropriate).
4. Refresh reference cache ringan.
5. Jangan overwrite local draft.
6. Tampilkan conflict jika context server draft sudah berubah.

---

# 19. Large Dataset Strategy

Jangan cache seluruh histori gangguan/foto ke HP. Cache hanya active reference yang diperlukan form dan pekerjaan relevant terhadap role/Posko.

---

# 20. Error Handling Matrix

| Error | Local result | User action |
|---|---|---|
| No internet | tetap DRAFT/READY | tunggu internet |
| Timeout | FAILED/READY retry | retry same idempotency key |
| Validation 422 | FAILED | edit draft |
| Conflict 409 | FAILED(CONFLICT) | refresh/periksa |
| Account disabled | FAILED | hubungi Admin/login ulang |
| Photo missing | tidak boleh send | tambah foto |
| Storage penuh | warning/error local | kosongkan storage |
| Server 5xx | FAILED | retry nanti |

---

# 21. Observability & Auditability

Client log minimum:
- submission attempt id
- entity type/local id
- response code
- timestamps
- error category

Jangan log password/token/foto binary.

Server audit mengikuti Technical/API spec.

---

# 22. Verification & Acceptance Test Scenarios

1. Buat 5 draft offline, kill app, buka kembali → 5 draft tetap ada.
2. Bulk send 5 item, 1 invalid → 4 submitted, 1 failed.
3. Retry failed setelah edit → satu record saja di server.
4. Dua device update P0 sama → yang kedua conflict, tidak overwrite.
5. Foto offline tetap terkait ke draft setelah restart.
6. Submit tanpa foto wajib → ditolak.
7. Input jam 14:10, submit jam 18:45 → server menyimpan `occurred_at/input_at=14:10` dan `recorded_at/submitted_at=18:45`; status P0/WO menyimpan pasangan waktu tersebut di `status_history`.
8. Admin create P0 saat device offline → P0 baru baru terlihat setelah refresh online (oleh HARSUS, bukan POSKO).
9. Account disabled saat offline → draft lokal tetap ada, submit ditolak ketika reconnect.
10. Unknown INSPEKSI point offline → coordinate+photo tersimpan dan dapat submit untuk Admin validation; P0 executable baru dibuat setelah asset + Section/Posko tervalidasi, Admin approves, dan kebutuhan eksekusi dicopy sebagai snapshot. P0 ditugaskan ke HARSUS.

---

# 23. Open Questions

- Retention local draft setelah SUBMITTED.
- Batas ukuran foto/compression exact.
- Jumlah cache asset maksimum per role bila dataset sangat besar.

Tidak ada Open Question lagi tentang PowerSync/full sync: **PowerSync tidak digunakan** pada baseline baru.
