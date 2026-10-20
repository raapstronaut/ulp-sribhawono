<?php

namespace App\Http\Requests\Admin;

use App\Services\ShiftService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApkukWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdmin() ?? false;
    }

    /**
     * Aturan kelayakan lokasi dan duplikasi divalidasi di ApkukWorkOrderService.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $shifts = app(ShiftService::class);

        return [
            'apkuk_id' => ['required', 'integer', Rule::exists('apkuks', 'id')],
            'posko_id' => ['required', 'uuid', Rule::exists('poskos', 'id')->where('is_active', true)],
            'work_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$shifts->today()],
            'shift_code' => ['required', Rule::in($shifts->codes())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'apkuk_id.required' => 'Pilih lokasi tiang dari daftar kandidat APKUK.',
            'apkuk_id.exists' => 'Data APKUK tidak ditemukan.',
            'posko_id.required' => 'Pilih POSKO penanggung jawab.',
            'posko_id.uuid' => 'POSKO tidak valid.',
            'posko_id.exists' => 'POSKO tidak ditemukan atau tidak aktif.',
            'work_date.required' => 'Tanggal kerja wajib diisi.',
            'work_date.date_format' => 'Format tanggal kerja tidak valid.',
            'work_date.after_or_equal' => 'Tanggal kerja tidak boleh sebelum hari ini.',
            'shift_code.required' => 'Pilih shift.',
            'shift_code.in' => 'Shift tidak dikenal.',
            'notes.max' => 'Catatan maksimal 1000 karakter.',
        ];
    }
}
