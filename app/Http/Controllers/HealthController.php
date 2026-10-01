<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness probe used by the UI and by deployment health checks.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $databaseOk = true;

        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            $databaseOk = false;
        }

        return response()->json([
            'ok' => $databaseOk,
            'db' => $databaseOk,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'time' => now()->toIso8601String(),
        ], $databaseOk ? 200 : 503);
    }
}
