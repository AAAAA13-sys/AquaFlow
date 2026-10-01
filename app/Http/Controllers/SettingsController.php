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

        if (array_key_exists('station_name', $data)) {
            SystemSetting::put(SystemSetting::KEY_STATION_NAME, (string) $data['station_name']);
        }
        if (array_key_exists('restock_lead_days', $data)) {
            SystemSetting::put(SystemSetting::KEY_RESTOCK_LEAD_DAYS, (string) $data['restock_lead_days']);
        }
        if (array_key_exists('sus_target', $data)) {
            SystemSetting::put(SystemSetting::KEY_SUS_TARGET, (string) $data['sus_target']);
        }

        return response()->json(['settings' => SystemSetting::allValues()]);
    }
}
