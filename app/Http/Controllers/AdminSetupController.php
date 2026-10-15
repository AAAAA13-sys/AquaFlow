<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminSetupRequest;
use App\Http\Resources\UserResource;
use App\Services\AdminSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminSetupController extends Controller
{
    public function __construct(private readonly AdminSetupService $setup) {}

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

    /**
     * The final setup step. There is no code to return: the owner is created
     * from the username and password they supply and signed straight in.
     */
    public function complete(AdminSetupRequest $request, AdminSetupService $setup)
    {
        abort_unless($request->session()->has('setup_user') && $request->session()->get('setup_expires', 0) > now()->timestamp, 403);
        $data = $request->validated();
        $user = $setup->complete($request->session()->get('setup_user'), $data, $data['station_name'] ?? null);
        abort_unless($user, 422, 'Setup could not be completed.');
        $request->session()->forget(['setup_user', 'setup_expires', 'setup_station_name']);
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => new UserResource($user)]);
    }
}
