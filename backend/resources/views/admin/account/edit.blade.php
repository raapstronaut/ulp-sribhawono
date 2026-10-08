@extends('layouts.admin')

@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Pengaturan Akun Admin</h1>
        <p class="mt-1 text-sm text-slate-500">Perbarui username dan password akun yang sedang digunakan.</p>
    </div>

    @if(session('status') === 'username-updated')
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            Username berhasil diperbarui.
        </div>
    @elseif(session('status') === 'password-updated')
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            Password berhasil diperbarui.
        </div>
    @endif

    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-5">
            <h2 class="text-lg font-bold text-slate-900">Ganti Username</h2>
            <p class="mt-1 text-sm text-slate-500">Username dipakai saat login dan harus unik.</p>
        </div>

        <form method="POST" action="{{ route('admin.account.username.update') }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div>
                <label for="username" class="mb-2 block text-sm font-semibold text-slate-700">Username baru</label>
                <input
                    id="username"
                    name="username"
                    type="text"
                    value="{{ old('username', auth()->user()->username) }}"
                    required
                    autocomplete="username"
                    maxlength="50"
                    class="w-full max-w-md rounded-lg border px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 {{ $errors->updateUsername->has('username') ? 'border-red-400' : 'border-slate-300' }}"
                >
                @error('username', 'updateUsername')
                    <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-xs text-slate-400">Huruf akan disimpan sebagai huruf kapital. Karakter yang diizinkan: huruf, angka, titik, garis bawah, dan tanda hubung.</p>
            </div>

            <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-200">
                Simpan Username
            </button>
        </form>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-5">
            <h2 class="text-lg font-bold text-slate-900">Ganti Password</h2>
            <p class="mt-1 text-sm text-slate-500">Masukkan password lama untuk memastikan perubahan dilakukan oleh pemilik akun.</p>
        </div>

        <form method="POST" action="{{ route('admin.account.password.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="current_password" class="mb-2 block text-sm font-semibold text-slate-700">Password lama</label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="w-full max-w-md rounded-lg border px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 {{ $errors->updatePassword->has('current_password') ? 'border-red-400' : 'border-slate-300' }}">
                @error('current_password', 'updatePassword')
                    <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">Password baru</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" class="w-full max-w-md rounded-lg border px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 {{ $errors->updatePassword->has('password') ? 'border-red-400' : 'border-slate-300' }}">
                @error('password', 'updatePassword')
                    <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-xs text-slate-400">Minimal 12 karakter serta mengandung huruf besar, huruf kecil, angka, dan simbol.</p>
            </div>

            <div>
                <label for="password_confirmation" class="mb-2 block text-sm font-semibold text-slate-700">Ulangi password baru</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="w-full max-w-md rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-200">
                Simpan Password Baru
            </button>
        </form>
    </section>
</div>
@endsection
