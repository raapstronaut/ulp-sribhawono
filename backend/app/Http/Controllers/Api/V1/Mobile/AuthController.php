<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login akun tim lapangan dengan token Sanctum. Akun ADMIN tidak dapat login di sini.
 * Kebijakan batas perangkat dan logout otomatis masih TBD sehingga token tidak dibatasi.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('username', $data['username'])->first();
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['username' => 'Username atau kata sandi salah.']);
        }
        if (! $user->canUseFieldApi()) {
            throw ValidationException::withMessages(['username' => 'Akun ini tidak aktif atau bukan akun tim lapangan.']);
        }

        $token = $user->createToken(mb_substr($data['device_name'], 0, 100), ['field'])->plainTextToken;

        return response()->json([
            'data' => ['token' => $token, 'token_type' => 'Bearer', 'account' => $this->account($user)],
            'meta' => ['server_time' => now()->toIso8601String()],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['data' => ['logged_out' => true], 'meta' => ['server_time' => now()->toIso8601String()]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account($request->user()), 'meta' => ['server_time' => now()->toIso8601String()]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function account(User $user): array
    {
        $team = $user->fieldTeam;

        return [
            'id' => $user->getKey(),
            'username' => $user->username,
            'name' => $user->name,
            'role' => $user->role,
            'field_personnel_id' => $user->field_personnel_id,
            'team' => $team ? [
                'id' => $team->getKey(),
                'code' => $team->code,
                'name' => $team->name,
                'role' => $team->role,
                'posko_id' => $team->posko_id,
            ] : null,
        ];
    }
}
