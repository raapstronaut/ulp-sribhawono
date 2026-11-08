<?php

namespace App\Services\Apkuk\Generation;

/**
 * Pengaman aktivasi generator WO APKUK, fail-closed. Pratinjau selalu boleh (tidak menulis WO).
 * Membuat WO sungguhan hanya bila diaktifkan eksplisit DAN berjalan di lingkungan pengembangan/
 * pengujian yang terdaftar dengan database lokal. Semua lingkungan lain (production, neon,
 * workflows, staging, atau nama apa pun) dianggap operasional dan ditolak: penggunaan operasional
 * mensyaratkan model ML terlatih + tervalidasi dan prosedur readiness yang disetujui (keputusan
 * pengguna), yang belum ada. Penanda isMachineLearning() strategi bukan bukti kesiapan model.
 */
final class ApkukGeneratorGuard
{
    /** Satu-satunya nilai APP_ENV yang boleh menjalankan generator (dengan aktivasi uji). */
    public const DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    private const LOCAL_DATABASE_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    public function blockedReason(): ?string
    {
        if (! config('apkuk.generator.enabled')) {
            return 'Generator WO otomatis nonaktif (APKUK_GENERATOR_ENABLED=false). Gunakan pratinjau.';
        }

        $environment = (string) app()->environment();
        if (! in_array($environment, self::DEVELOPMENT_ENVIRONMENTS, true)) {
            return "Generate WO ditolak di lingkungan \"{$environment}\": hanya lingkungan pengembangan/pengujian (".implode(', ', self::DEVELOPMENT_ENVIRONMENTS).') yang boleh menjalankan generator. Penggunaan operasional mensyaratkan model ML terlatih dan tervalidasi serta prosedur readiness yang disetujui; keduanya belum tersedia.';
        }

        // DB_URL (bila diisi) menimpa DB_HOST, jadi host diambil dari URL lebih dulu.
        $connection = (string) config('database.default');
        $settings = (array) config("database.connections.{$connection}", []);
        $host = filled($settings['url'] ?? null)
            ? (string) parse_url((string) $settings['url'], PHP_URL_HOST)
            : (string) ($settings['host'] ?? '');
        if (($settings['driver'] ?? null) !== 'sqlite' && ! in_array($host, self::LOCAL_DATABASE_HOSTS, true)) {
            return "Generate WO ditolak: lingkungan \"{$environment}\" terhubung ke database non-lokal. Generator uji hanya boleh menulis ke database lokal/terisolasi.";
        }

        return null;
    }
}
