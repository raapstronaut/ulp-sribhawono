<?php

namespace App\Services\Apkuk;

use RuntimeException;

/**
 * Sumber APKUK_RAW tidak dapat dipakai (konfigurasi, gagal baca, kosong, header salah).
 * Pesan ditampilkan apa adanya oleh command.
 */
class ApkukSourceException extends RuntimeException {}
