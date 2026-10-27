<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InspectionFindingMedia;
use App\Models\WorkOrderMedia;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menampilkan foto bukti dari disk privat hanya untuk Admin (route di balik middleware admin).
 */
class MediaController extends Controller
{
    public function workOrder(WorkOrderMedia $media): Response
    {
        return $this->stream($media->disk, $media->storage_path, $media->mime_type);
    }

    public function inspection(InspectionFindingMedia $media): Response
    {
        return $this->stream($media->disk, $media->storage_path, $media->mime_type);
    }

    private function stream(string $disk, string $path, ?string $mime): Response
    {
        abort_unless(Storage::disk($disk)->exists($path), 404, 'File foto tidak ditemukan di penyimpanan.');

        return Storage::disk($disk)->response($path, basename($path), [
            'Content-Type' => $mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
