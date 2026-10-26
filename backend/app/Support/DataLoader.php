<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Menjalankan query halaman Admin per bagian tanpa menjatuhkan seluruh halaman.
 *
 * Kegagalan database (tabel belum dimigrasi di suatu environment, koneksi putus)
 * dilaporkan ke log dan ditandai per bagian, sehingga view menampilkan error state
 * yang jujur alih-alih angka 0 yang menyesatkan.
 */
final class DataLoader
{
    /** @var array<string, true> */
    private array $failures = [];

    public function attempt(string $section, callable $query, mixed $fallback = null): mixed
    {
        try {
            return $query();
        } catch (QueryException $exception) {
            report($exception);
            $this->failures[$section] = true;

            return $fallback;
        }
    }

    public function failed(string ...$sections): bool
    {
        foreach ($sections as $section) {
            if (isset($this->failures[$section])) {
                return true;
            }
        }

        return false;
    }

    public function anyFailed(): bool
    {
        return $this->failures !== [];
    }

    /**
     * Nomor halaman di luar jangkauan (tautan lama, URL diubah manual) dialihkan ke
     * halaman terakhir, agar daftar yang sebenarnya berisi tidak tampil sebagai kosong.
     * Panggil setelah withQueryString() agar filter ikut terbawa.
     */
    public static function withinRange(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        if ($paginator->total() > 0 && $paginator->currentPage() > $paginator->lastPage()) {
            throw new HttpResponseException(redirect()->to($paginator->url($paginator->lastPage())));
        }

        return $paginator;
    }

    public static function emptyPage(int $perPage, string $pageName = 'page'): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $perPage, 1, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }
}
