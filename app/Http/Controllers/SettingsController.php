<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

/**
 * Station configuration (owner writes, everyone reads).
 */
class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['settings' => SystemSetting::allValues()]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        foreach ([SystemSetting::KEY_STATION_NAME, SystemSetting::KEY_RESTOCK_LEAD_DAYS, SystemSetting::KEY_SUS_TARGET] as $key) {
            if (array_key_exists($key, $data)) {
                SystemSetting::put($key, (string) $data[$key]);
            }
        }

        return response()->json(['settings' => SystemSetting::allValues()]);
    }
}
