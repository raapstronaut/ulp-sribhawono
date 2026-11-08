<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API mobile hanya untuk akun tim lapangan aktif yang terikat tim aktif dengan role sama.
 * Akun ADMIN tidak memakai API mobile.
 */
class EnsureFieldAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->canUseFieldApi()) {
            return response()->json([
                'message' => 'Akun tidak berhak memakai aplikasi lapangan atau sudah dinonaktifkan.',
                'errors' => ['auth' => ['Akun tidak berhak memakai aplikasi lapangan atau sudah dinonaktifkan.']],
            ], 403);
        }

        return $next($request);
    }
}
