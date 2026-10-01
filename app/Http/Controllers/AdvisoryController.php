<?php

namespace App\Http\Controllers;

use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;

class AdvisoryController extends Controller
{
    public function __construct(
        private readonly InventoryEngine $engine,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $advisories = $this->engine->advisories();

        $draftPurchaseOrder = $advisories->map(fn (array $advisory): array => [
            'item' => $advisory['item'],
            'unit' => $advisory['unit'],
            'order_quantity' => $advisory['order_quantity'],
            'supplier' => $advisory['supplier'],
            'lead_time' => $advisory['lead_time'],
            'severity' => $advisory['severity'],
        ])->values();

        return response()->json([
            'ok' => true,
            'generated_at' => now()->toIso8601String(),
            'count' => $advisories->count(),
            'advisories' => $advisories,
            'draft_po' => $draftPurchaseOrder,
        ]);
    }
}
