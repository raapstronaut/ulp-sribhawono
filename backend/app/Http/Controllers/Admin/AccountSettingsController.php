<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminPasswordRequest;
use App\Http\Requests\Admin\UpdateAdminUsernameRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AccountSettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.account.edit');
    }

    public function updateUsername(UpdateAdminUsernameRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'username' => $request->validated('username'),
        ])->save();

        $request->session()->regenerate();

        return back()->with('status', 'username-updated');
    }

    public function updatePassword(UpdateAdminPasswordRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'password' => Hash::make($request->validated('password')),
        ])->save();

        $request->session()->regenerate();

        return back()->with('status', 'password-updated');
    }
}
