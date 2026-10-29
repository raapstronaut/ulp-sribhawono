<?php

/*
|--------------------------------------------------------------------------
| Pengaman sinkronisasi APKUK_RAW (apkuk:sync)
|--------------------------------------------------------------------------
|
| Snapshot sumber dibandingkan dengan snapshot valid terakhir (apkuk_sync_runs) dan
| isi apkuks. Bila salah satu batas terlampaui, snapshot dianggap mencurigakan
| (terpotong, salah kolom, perubahan massal) dan TIDAK ADA penulisan APKUK sama sekali.
| Batas dapat dilonggarkan untuk satu eksekusi manual lewat opsi --max-missing,
| --max-new, dan --max-updated (tercatat di apkuk_sync_runs.guard).
|
*/

return [
    'sync' => [
        // Penurunan jumlah laporan dibanding snapshot valid terakhir (5%).
        'max_drop_ratio' => (float) env('APKUK_SYNC_MAX_DROP_RATIO', 0.05),

        // Identitas aktif yang baru hilang dalam satu eksekusi: min(jumlah, rasio x aktif).
        'max_new_missing' => (int) env('APKUK_SYNC_MAX_NEW_MISSING', 150),
        'max_new_missing_ratio' => (float) env('APKUK_SYNC_MAX_NEW_MISSING_RATIO', 0.05),

        // Laporan baru + muncul kembali dalam satu eksekusi: max(jumlah, rasio x snapshot terakhir).
        'max_new_reports' => (int) env('APKUK_SYNC_MAX_NEW_REPORTS', 500),
        'max_growth_ratio' => (float) env('APKUK_SYNC_MAX_GROWTH_RATIO', 0.25),

        // Laporan yang isinya berubah dalam satu eksekusi: max(jumlah, rasio x laporan cocok).
        'max_updated' => (int) env('APKUK_SYNC_MAX_UPDATED', 100),
        'max_updated_ratio' => (float) env('APKUK_SYNC_MAX_UPDATED_RATIO', 0.10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generator WO APKUK per Posko per shift (Batch B2)
    |--------------------------------------------------------------------------
    |
    | Pratinjau (tanpa menulis WO) selalu tersedia untuk Admin. Membuat WO sungguhan hanya bila
    | `enabled` = true (default false) DAN APP_ENV termasuk lingkungan pengembangan/pengujian
    | yang terdaftar (local, testing) dengan database lokal. Semua lingkungan lain, termasuk
    | production, neon, dan workflows, ditolak (fail-closed) sampai model ML terlatih dan
    | tervalidasi beserta prosedur readiness tersedia (keputusan pengguna). Lihat ApkukGeneratorGuard.
    |
    */
    'generator' => [
        'enabled' => (bool) env('APKUK_GENERATOR_ENABLED', false),
    ],
];
