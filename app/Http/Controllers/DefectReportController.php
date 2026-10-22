<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolveDefectReportRequest;
use App\Http\Requests\StoreDefectReportRequest;
use App\Http\Resources\DefectItemResource;
use App\Http\Resources\DefectReportResource;
use App\Models\DefectReport;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Defect & damaged-stock reporting.
 *
 * Staff file a report; a manager approves it (writing the units off stock and
 * appending a `damage` movement) or rejects it. The report row is never
 * deleted, so the audit trail survives the accounts involved.
 */
class DefectReportController extends Controller
{
    public function items(): JsonResponse
    {
        return response()->json([
            'inventory' => DefectItemResource::collection(
                InventoryItem::query()->current()->orderBy('item_name')
                    ->get(['id', 'item_name', 'stock_on_hand', 'unit'])
            ),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = DefectReport::query()
            ->with(['inventoryItem', 'reporter', 'resolver'])
            ->newestFirst();

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return response()->json([
            'reports' => DefectReportResource::collection($query->paginate(20)->withQueryString()),
            'statuses' => [DefectReport::STATUS_PENDING, DefectReport::STATUS_APPROVED, DefectReport::STATUS_REJECTED],
        ]);
    }

    public function store(StoreDefectReportRequest $request): JsonResponse
    {
        $report = DefectReport::create([
            'reported_by' => $request->user()->id,
            'inventory_item_id' => $request->validated()['inventory_item_id'],
            'quantity' => $request->validated()['quantity'],
            'reason' => $request->validated()['reason'],
            'status' => DefectReport::STATUS_PENDING,
        ]);

        return response()->json([
            'report' => new DefectReportResource($report->load(['inventoryItem', 'reporter'])),
        ], 201);
    }

    public function approve(ResolveDefectReportRequest $request, DefectReport $defectReport): JsonResponse
    {
        if (! $defectReport->isPending()) {
            throw ValidationException::withMessages(['status' => 'This report has already been resolved.']);
        }

        $report = DB::transaction(function () use ($request, $defectReport): DefectReport {
            $locked = InventoryItem::whereKey($defectReport->inventory_item_id)->lockForUpdate()->firstOrFail();

            if ($locked->retired_at !== null) {
                throw ValidationException::withMessages(['inventory_item_id' => 'That stock item has been retired.']);
            }

            // Re-validate against the row now that it is locked: stock may have
            // moved since the report was filed.
            if ($defectReport->quantity > $locked->stock_on_hand) {
                throw ValidationException::withMessages(['quantity' => 'Quantity exceeds available stock']);
            }

            $locked->stock_on_hand = $locked->stock_on_hand - $defectReport->quantity;
            $locked->save();

            StockMovement::create([
                'inventory_item_id' => $locked->id,
                'type' => 'damage',
                'qty' => -$defectReport->quantity,
                'user_id' => $request->user()->id,
                'reason' => 'damage',
                'notes' => 'Defect report #'.$defectReport->id.' approved',
            ]);

            $defectReport->status = DefectReport::STATUS_APPROVED;
            $defectReport->resolved_by = $request->user()->id;
            $defectReport->resolved_at = now();
            $defectReport->notes = $request->validated()['notes'] ?? null;
            $defectReport->save();

            return $defectReport;
        });

        return response()->json([
            'report' => new DefectReportResource($report->load(['inventoryItem', 'reporter', 'resolver'])),
            'stock_on_hand' => (int) $report->inventoryItem->stock_on_hand,
        ]);
    }

    public function reject(ResolveDefectReportRequest $request, DefectReport $defectReport): JsonResponse
    {
        if (! $defectReport->isPending()) {
            throw ValidationException::withMessages(['status' => 'This report has already been resolved.']);
        }

        $defectReport->status = DefectReport::STATUS_REJECTED;
        $defectReport->resolved_by = $request->user()->id;
        $defectReport->resolved_at = now();
        $defectReport->notes = $request->validated()['notes'] ?? null;
        $defectReport->save();

        return response()->json([
            'report' => new DefectReportResource($defectReport->load(['inventoryItem', 'reporter', 'resolver'])),
        ]);
    }
}
