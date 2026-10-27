<?php

namespace App\Services\Work;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Menyimpan foto bukti ke disk privat. Setiap file mendapat path unik baru sehingga
 * foto lama tidak pernah tertimpa. Disk diatur lewat MEDIA_DISK (default `local`).
 */
class MediaStore
{
    public const MAX_KILOBYTES = 10240;

    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'heic'];

    public function disk(): string
    {
        return (string) config('filesystems.media_disk', 'local');
    }

    /**
     * @return array{disk: string, storage_path: string, sha256: string, mime_type: ?string, size_bytes: int}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $disk = $this->disk();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $path = trim($directory, '/').'/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        return [
            'disk' => $disk,
            'storage_path' => $path,
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
        ];
    }

    /**
     * Menghapus file yang sudah tersimpan bila transaksi database gagal (agar tidak yatim).
     *
     * @param  array<int, array{disk: string, storage_path: string}>  $stored
     */
    public function discard(array $stored): void
    {
        foreach ($stored as $file) {
            Storage::disk($file['disk'])->delete($file['storage_path']);
        }
    }
}
