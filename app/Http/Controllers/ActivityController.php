<?php

namespace App\Http\Controllers;

use App\Services\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in account's own day: what it did, in order.
 *
 * Every role reads its own records only — the cashier sees its sales, drawer
 * and accepted rider cash, the rider and refiller see their order work and
 * damage reports. There is no parameter to read somebody else's day.
 */
class ActivityController extends Controller
{
    public function index(Request $request, ActivityService $service): JsonResponse
    {
        $activity = $service->today($request->user());

        return response()->json([
            'entries' => $activity['entries'],
            'counts' => $activity['counts'],
            'updated_at' => now()->toISOString(),
        ]);
    }
}
