# DATA MODEL SPECIFICATION
## Sistem Pengelolaan Pekerjaan Jaringan Distribusi — ULP Sribhawono

**Version:** v3.0 — 8 Oktober 2026  
**RDBMS:** PostgreSQL canonical server; SQLite Android untuk cache/outbox, bukan replika  
**Status:** IMPLEMENTED vs PLANNED dibedakan; ini rancangan logis, belum migration final

# 1. Prinsip dan status nyata

1. **Jangan reset atau recreate DB lama.** Migration applied historis tidak ditulis ulang; gunakan migration baru, uji dan backup sebelum deploy.
2. **Raw staging bukan canonical asset.** Raw dijaga untuk provenance/import, canonical diverifikasi lewat mapping.
3. **Penyulang–Posko many-to-many, Section ownership explicit.** Jangan atribusi aset otomatis hanya dari pivot.
4. **History immutable by default:** kerja/foto/event lama tidak ditimpa; koreksi ADMIN menulis old/new + alasan.
5. **Link lintas domain stabil:** aset, PASILA, P0, WO, gangguan, temuan, media, petugas saling merujuk dengan foreign key.
6. **Jangan menebak data hilang:** pusat punya coverage belum jelas; simpan null + verification/provenance status.
7. **Role spesifik:** ADMIN Web; 4 role lapangan Android. Account ≠ Petugas. Device-limit masih TBD.

# 2. Tabel yang ada di repository (IMPLEMENTED)

| Kelompok | Nama | Status |
|---|---|---|
| Staging | apkuk_source_rows | Migration & importer |
| Staging | asset_source_rows | Migration & importer |
| Staging | vegetasi_source_rows | Migration & importer |
| Staging | gangguan_source_rows | Migration & importer |
| Staging | section_pic_source_rows | Migration & importer |
| Staging | keypoint_source_rows | Migration & importer |
| Master | poskos | Migration + sync |
| Master | penyulangs | Migration + sync |
| Pivot | posko_penyulang | Migration + sync |
| Auth | users, password_reset_tokens, sessions | Default Laravel skeleton, belum RBAC final |

Checkpoint historis: 6 Posko, 16 Penyulang, 26 relasi. Snapshot raw angka dapat berubah setelah reimport; 73 tests / 274 assertions adalah laporan sebelumnya, bukan hasil re-run pada revisi ini.

# 3. Master & reference (PLANNED)

| Entity usulan | Kolom/relasi esensial | Keterangan |
|---|---|---|
| sections | id, penyulang_id, posko_id, kode/nama, start/end keypoint refs, status, geometry optional | Definisi segmen/batas dari data resmi; validasi dulu |
| assets | id, asset_type, external_id, name, penyulang_id, section_id, posko_id, lat/lon, status, verified_at, source | Identitas canonical; spesifikasi berbeda per jenis bisa JSON/tabel detail |
| asset_identifiers | asset_id, source_system, raw_identifier, confidence | Matching alias legacy/duplikat |
| vegetations | id, asset/section/penyulang refs, lat/lon, type, last_action_at, risk fields | Terkait WO ROW; lokasi bisa belum lengkap |
| apkuk_installations | id, asset_id, installed_quantity, installed_at, source_wo_id, state | Jangan menyamakan WO count dengan unit fisik |
| petugas | id, code, name, role/team_id, active, effective dates | Pelaksana manusia; dropdown sesuai tim |
| teams | id, type, posko_id nullable, active | Akun team scoped |
| users | id, role, team_id nullable, username/login, credential, active | Admin Web terpisah, tim mobile scoped |
| shift_assignments | id, team_id, shift_code, effective_from/to | Personel tim tetap, penugasan shift berubah |
| shift_instances | id, posko_id, work_date, shift_code, starts_at, ends_at | Unique per posko + work date + shift |
| reference_codes | id, category, code, label, role_scope, active, effective_from/to | Temuan, tindakan, jenis pekerjaan, alasan reschedule, dll |
| app_configs | config_key, value, effective_at, updated_by | Parameter shift/ranking, bukan magic numbers |

Belum diputuskan: tipe teknis serial aset per kategori, master referensi kode final, dan model topology Section paling akurat dari pusat.

# 4. Operasional (PLANNED)

| Entity | Kolom/relasi inti | Aturan |
|---|---|---|
| work_orders | id, source, team_type, work_type, target_asset_id/vegetation_id, section_id, penyulang_id, posko_id, shift_instance_id nullable, due_at, status, parent_wo_id nullable | POSKO APKUK; HARSUS PEKERJAAN_KHUSUS; ROW RABAS/PANGKAS/TEBANG |
| work_order_results | work_order_id, action_code, physical_apkuk_quantity nullable, outcome, occurred_at, received_at | Jumlah APKUK fisik bukan jumlah WO |
| pasila_sessions | id, posko_id, section_id, penyulang_id, shift_instance_id, source SYSTEM/ADMIN, recommendation_rank, status | Auto dibuat awal shift; penambahan manual terkontrol |
| pasila_findings | id, pasila_id, asset_id nullable, lat/lon, code, status, occurred_at | **Satu temuan satu GPS/foto/petugas** |
| p0 | id, pasila_id, asset_id, source SYSTEM/ADMIN, approval_status, status, reject/replacement link | Executor POSKO; Section/Posko diwarisi dari PASILA |
| p0_petugas | p0_id, petugas_id | Petugas aktual; bukan akun login |
| inspections | id, team_id, section_id, visit_at, has_finding, no_finding_record, status | No finding tersimpan jujur |
| inspection_findings | id, inspection_id, asset_id nullable, lat/lon, finding_code, validation_status, suggested_target_team | ACC Admin sebelum canonical |
| vegetation_risk_evaluations | id, vegetation_id, computed_at, score, due_reason, algorithm_version | Formula/cycle TBD |
| work_routes | id, route_type, team_id, work_date, section_id nullable, status | Cluster satu area/rute |
| work_route_stops | id, route_id, work_order_id, sequence, lat/lon snapshot | Banyak WO ROW/hari; titik APKUK |
| work_reschedules | id, origin_work_type/id, requested_by, from/to date, reason_code, note, status, created_at | **Dropdown reason + manual note wajib** |
| work_escalations | id, source_type/id, target_team_type, reason_code, requested_by, admin_decision, reviewed_by, child_wo_id | ACC → source SELESAI_DITERUSKAN dan child WO |
| approvals | id, entity_type/id, kind, decision, reviewer_id, reviewed_at, note nullable | System P0 proposal, finding inspeksi, escalation |
| work_participants | entity_type/id, petugas_id, assigned_at | Relasi tim/petugas aktual sesuai kegiatan |
| notifications | id, recipient_user/team_id, type, payload, delivery/status | Hanya relevan jika akun login; device policy TBD |

Tabel polimorfik di atas **desain logis**, bukan nama final yang memaksa implementasi: final migration boleh memilih junction eksplisit demi FK dan integritas.

# 5. Gangguan, aset, foto dan audit (PLANNED)

| Entity | Kolom esensial | Tujuan |
|---|---|---|
| disturbance_events | id, occurred_at, lat/lon_kejadian, penyulang_id, section_id nullable, cause/status, source | Marker Maps **lokasi kejadian gangguan** |
| disturbance_affected_assets | event_id, asset_id, impact_type | Satu gangguan bisa mengenai banyak aset |
| media_attachments | id, entity_type/id, asset_id nullable, photo_type, storage_path, sha256, captured_at, lat/lon, gps_accuracy, uploaded_at | BEFORE/AFTER/FINDING/SURVEY; tidak overwrite |
| asset_history_events | id, asset_id, event_type, source_type/id, occurred_at, recorded_at, status | Timeline perbaikan, pemasangan, inspeksi, gangguan |
| audit_logs | id, actor_user_id, entity_type/id, action, before_json, after_json, reason, created_at | Admin correction dengan old/new/reason |
| map_geometries | penyulang_id/section_id, geometry, provenance, version | Opsional jika GIS tersedia |
| data_quality_issues | source, source_record_id, field, issue_type, resolution, actor | Inventaris data yang belum lengkap |

**Foto utama aset bukan path foto yang ditimpa.** Query foto valid terbaru berdasarkan captured_at (dengan tie-breaker deterministic); galeri dan asset history tetap menyimpan seluruh bukti lama.

# 6. Relasi kritis

- Penyulang 1:N Section; Posko N:M Penyulang; Section 1:N Aset; kepemilikan Posko per Section/aset diverifikasi.
- Shift instance Posko 1:N WO APKUK, PASILA; PASILA 1:N findings dan 1:N P0 (target minimum per shift >=1, jumlah maksimum belum diputuskan).
- P0 wajib mempunyai PASILA valid; asset P0 harus dalam Section PASILA; executor POSKO. P0 SYSTEM approval wajib; reject menciptakan **obligation** replacement manual Admin (bukan diam-diam dianggap selesai).
- WO tetap sebagai parent source ketika eskalasi; approved escalation membuat child WO, status source SELESAI_DITERUSKAN, audit tersimpan.
- WO HARSUS manual **wajib asset jaringan**. WO ROW terkait vegetasi dan cluster rute harian.
- Field finding terpisah dari session agar satu PASILA bisa punya banyak lokasi/foto.
- Inspeksi no-finding tidak boleh dihitung temuan valid.
- Disturbance location dan affected asset berbeda, jangan meletakkan marker di semua aset terdampak sebagai satu event secara default.

# 7. Status dan invariants penting

**WO status proposal:** BARU / DITUGASKAN / DIKERJAKAN / RESCHEDULED / SELESAI / SELESAI_DITERUSKAN / DIBATALKAN. State machine final dan hak mutasi perlu tes.
**P0 proposal:** MENUNGGU_ACC / DITOLAK / DISETUJUI lalu executable states; Admin manual aktif sesuai validasi. Reject SYSTEM mensyaratkan pengganti manual.
**Sync local:** QUEUED / SENDING / ACKNOWLEDGED / RETRYABLE_ERROR / ACTION_REQUIRED dengan request_id/idempotency_key.
**Uniqueness:** shift_instance unik posko+work_date+shift; generator PASILA/WO dan ROW harus idempotent terhadap job retry. Aturan carryover mengurangi kuota WO berikutnya **TBD**. Aturan persis deduplikasi satu vegetasi/multi siklus juga **TBD**, walaupun banyak titik dan WO per hari diperbolehkan.

# 8. Index & query

- assets(penyulang_id,asset_type,section_id), external IDs unique under source/namespace; spatial index bila PostGIS digunakan.
- disturbance_events(penyulang_id,occurred_at) dan affected_assets(asset_id).
- work_orders(team_type,work_type,status,due_at), (posko_id,shift_instance_id).
- pasila_sessions(shift_instance_id), pasila_findings(pasila_id).
- p0(pasila_id,approval_status,status).
- media_attachments(asset_id,captured_at) dan asset_history_events(asset_id,occurred_at).
- approvals(kind,decision,created_at), work_escalations(admin_decision).

# 9. Strategi migration dan import

1. Snapshot/backup PostgreSQL dev nyata.
2. Cek migrations yang sudah applied dan jumlah source rows; jangan percaya snapshot historis sebagai data live.
3. Buat **migration baru secara additive**, seed reference/tim/shift, test FK, migration rollback pada DB khusus tes.
4. Import canonical via mapping idempotent dari staging + provenance, pertahankan missing/null bila belum dapat dari pusat.
5. Tambahkan transaction domain setelah invariant disepakati; jangan mengubah migration staging lama yang sudah dijalankan.
6. Data baru dari Web/Mobile → API → canonical PostgreSQL. Spreadsheet bukan auto-sync terus-menerus.
