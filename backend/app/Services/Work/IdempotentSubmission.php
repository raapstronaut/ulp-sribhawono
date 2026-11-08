<?php

namespace App\Services\Work;

use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pengiriman ulang (retry) dari aplikasi lapangan dengan client_request_id yang sama.
 *
 * - Record yang sudah ada dikembalikan apa adanya (tanpa transaksi atau media baru) bila milik
 *   pekerjaan/tim yang sama dan isi permintaannya sama. Bila berbeda: 422 pada client_request_id,
 *   karena kunci idempotensi dipakai ulang untuk permintaan lain.
 * - "Isi sama" mencakup seluruh isi yang disimpan: field penentu, catatan, koordinat, waktu
 *   kejadian (bila dikirim), petugas, dan SHA-256 tiap foto. Outbox HP mengirim ulang payload dan
 *   file yang dibekukan saat submit (OFFLINE_SYNC_SPEC §4), sehingga retry sah selalu sama;
 *   perbedaan isi berarti kunci dipakai untuk kiriman lain. Menolaknya (422) mencegah HP menandai
 *   kiriman lain sebagai "terkirim" lalu membersihkan foto/payload yang belum pernah diterima.
 *   Perbedaan bentuk penulisan yang tidak mengubah isi (angka "3"/3, urutan petugas/foto, zona
 *   waktu untuk instan yang sama) tidak dianggap berbeda.
 * - Dua request bersamaan dengan kunci sama: yang kalah menabrak unique index client_request_id,
 *   guard status WO (409), atau validasi yang bergantung pada data pemenang (mis. limpahan yang
 *   sudah menunggu ACC). Bila record dengan kunci itu ternyata ada, kunci sudah pernah berhasil
 *   dipakai sehingga hasilnya dikembalikan sebagai replay; bila tidak ada, galat diteruskan.
 *
 * Replay dikenali pemanggil dari $model->wasRecentlyCreated === false.
 */
final class IdempotentSubmission
{
    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  array{0: string, 1: string|null, 2: string}  $owner  [kolom pemilik, nilai, label untuk pesan]
     * @param  array<string, mixed>  $fingerprint  kolom => nilai yang wajib sama, atau nama => Closure(T): bool untuk relasi
     * @param  callable(): T  $create
     * @return T
     */
    public static function run(string $model, ?string $key, array $owner, array $fingerprint, callable $create): Model
    {
        if (blank($key)) {
            return $create();
        }

        if ($existing = $model::query()->where('client_request_id', $key)->first()) {
            return self::replay($existing, $owner, $fingerprint);
        }

        try {
            return $create();
        } catch (UniqueConstraintViolationException|WorkflowConflictException|ValidationException $e) {
            $existing = $model::query()->where('client_request_id', $key)->first();
            if ($existing === null) {
                throw $e;
            }

            return self::replay($existing, $owner, $fingerprint);
        }
    }

    /**
     * Nilai teks seperti yang disimpan service (trim; kosong → null).
     */
    public static function text(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }

    /**
     * Angka desimal dibulatkan ke skala kolom, agar "-5.2" dan "-5.2000000" dianggap sama.
     */
    public static function decimal(mixed $value, int $scale): ?float
    {
        return is_numeric($value) ? round((float) $value, $scale) : null;
    }

    /**
     * Petugas tersimpan (ID roster + nama manual) sama dengan input, tanpa memperhatikan urutan.
     *
     * @param  Collection<int, Model>  $rows  baris *_personnel (field_personnel_id, personnel_name)
     * @param  array<int, mixed>  $ids
     * @param  array<int, mixed>  $names
     */
    public static function samePersonnel(Collection $rows, array $ids, array $names): bool
    {
        $inputIds = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $inputNames = array_values(array_filter(array_map(fn ($name) => mb_substr(trim((string) $name), 0, 255), $names), fn ($name) => $name !== ''));

        return self::sameList($rows->whereNotNull('field_personnel_id')->pluck('field_personnel_id')->map(fn ($id) => (string) $id)->all(), $inputIds)
            && self::sameList($rows->whereNull('field_personnel_id')->pluck('personnel_name')->all(), $inputNames);
    }

    /**
     * SHA-256 file unggahan, dihitung sama seperti MediaStore.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string>
     */
    public static function hashes(array $files): array
    {
        return array_values(array_map(fn (UploadedFile $file) => hash_file('sha256', $file->getRealPath()), array_filter($files, fn ($file) => $file instanceof UploadedFile)));
    }

    /**
     * @param  array<int, mixed>  $a
     * @param  array<int, mixed>  $b
     */
    public static function sameList(array $a, array $b): bool
    {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * @param  array{0: string, 1: string|null, 2: string}  $owner
     * @param  array<string, mixed>  $fingerprint
     */
    private static function replay(Model $existing, array $owner, array $fingerprint): Model
    {
        [$column, $value, $label] = $owner;
        if (! self::same($existing->getAttribute($column), $value)) {
            throw ValidationException::withMessages(['client_request_id' => "client_request_id sudah dipakai untuk {$label}."]);
        }

        $different = [];
        foreach ($fingerprint as $attribute => $expected) {
            $same = $expected instanceof Closure ? (bool) $expected($existing) : self::same($existing->getAttribute($attribute), $expected);
            if (! $same) {
                $different[] = $attribute;
            }
        }
        if ($different !== []) {
            throw ValidationException::withMessages([
                'client_request_id' => 'client_request_id sudah dipakai untuk permintaan dengan isi berbeda ('.implode(', ', $different).'). Gunakan ID baru untuk pengiriman baru.',
            ]);
        }

        return $existing;
    }

    private static function same(mixed $existing, mixed $expected): bool
    {
        if ($existing instanceof CarbonInterface) {
            return $expected instanceof CarbonInterface
                ? $existing->getTimestamp() === $expected->getTimestamp()
                : $existing->toDateString() === trim((string) $expected);
        }
        if (is_numeric($existing) && is_numeric($expected)) {
            return abs((float) $existing - (float) $expected) < 1e-9;
        }

        return self::scalar($existing) === self::scalar($expected);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_bool($value) => $value ? '1' : '0',
            default => trim((string) $value),
        };
    }
}
