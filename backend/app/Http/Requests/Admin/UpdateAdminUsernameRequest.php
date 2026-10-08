<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateAdminUsernameRequest extends FormRequest
{
    protected $errorBag = 'updateUsername';

    public function authorize(): bool
    {
        return $this->user()?->canAccessAdmin() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($this->user()?->getKey()),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => Str::upper(trim((string) $this->input('username'))),
        ]);
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'username.unique' => 'Username sudah digunakan oleh akun lain.',
        ];
    }
}
