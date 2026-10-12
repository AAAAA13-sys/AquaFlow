<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminSetupRequest;
use App\Http\Resources\UserResource;
use App\Services\AdminSetupService;
use App\Services\OwnerEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminSetupController extends Controller
{
    public function login(Request $request, AdminSetupService $setup)
    {
        $data = $request->validate(['secret' => ['required', 'string', 'max:64']]);
        $user = $setup->bootstrap($data);
        abort_unless($user, 401, 'Invalid setup code, or setup has already finished.');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(['setup_user' => $user->id, 'setup_expires' => now()->timestamp + 900]);

        return response()->json(['setup' => true]);
    }

    public function prepare(AdminSetupRequest $request, AdminSetupService $setup)
    {
        $id = $setup->prepare($request->session()->get('setup_user'), $request->validated(), $request->session()->getId());
        $request->session()->put('setup_challenge', $id);
        $request->session()->put('setup_station_name', $request->validated('station_name'));

        return response()->json(['message' => 'Check your email for the verification code.']);
    }

    public function verify(Request $request, AdminSetupService $setup, OwnerEmailService $email)
    {
        abort_unless($request->session()->has('setup_user') && $request->session()->get('setup_expires', 0) > now()->timestamp, 403);
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $user = $setup->complete($request->session()->get('setup_user'), (string) $request->session()->get('setup_challenge'), $request->session()->getId(), $data['code'], $request->session()->get('setup_station_name'));
        abort_unless($user, 422, 'Invalid or expired verification code.');
        $codes = $email->enroll($user);
        $request->session()->forget(['setup_user', 'setup_expires', 'setup_challenge', 'setup_station_name']);
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => new UserResource($user), 'recovery_codes' => $codes]);
    }
}
