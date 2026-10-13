<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseCashShiftRequest;
use App\Http\Requests\OpenCashShiftRequest;
use App\Models\CashShift;
use App\Services\CashReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * End-of-day cash reconciliation.
 *
 * Additive endpoint set: open a drawer with a float, close it against counted
 * cash, and read the history. Any authenticated staff member can reconcile
 * their own drawer.
 */
class CashShiftController extends Controller
{
    public function __construct(
        private readonly CashReconciliationService $cash,
    ) {}

    /** The caller's own session for today, or null if the drawer is closed. */
    public function current(Request $request): JsonResponse
    {
        $shift = CashShift::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('business_date', today())
            ->first();

        // While the drawer is open the operator is counting cash against a live
        // figure, so refresh what *should* be there as sales come in rather than
        // showing the figure recorded when the drawer was opened.
        if ($shift?->isOpen()) {
            $movement = $this->cash->cashMovement($shift);
            $shift->fill($movement)->save();
            $shift->refresh();
        }

        return response()->json([
            'shift' => $shift ? $this->present($shift) : null,
            'history' => $this->cash->recentHistory()->map(fn (CashShift $s): array => $this->present($s))->values(),
        ]);
    }

    public function open(OpenCashShiftRequest $request): JsonResponse
    {
        $result = $this->cash->open(
            $request->user(),
            (float) $request->validated()['opening_float'],
        );

        if ($result['already_open']) {
            return response()->json([
                'message' => 'A drawer session is already open for today.',
                'shift' => $this->present($result['shift']),
            ], 409);
        }

        return response()->json([
            'message' => 'Drawer opened.',
            'shift' => $this->present($result['shift']),
        ]);
    }

    public function close(CloseCashShiftRequest $request): JsonResponse
    {
        $shift = CashShift::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('business_date', today())
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            throw ValidationException::withMessages([
                'counted_cash' => 'No open drawer session for today. Open the drawer first.',
            ]);
        }

        $result = $this->cash->close(
            $shift,
            (float) $request->validated()['counted_cash'],
            $request->validated()['note'] ?? null,
        );

        return response()->json([
            'message' => 'Drawer closed.',
            'shift' => $this->present($result['shift']),
        ]);
    }

    /** Owner view across every cashier for the day. */
    public function overview(Request $request): JsonResponse
    {
        $shifts = CashShift::query()
            ->with('user')
            ->whereDate('business_date', today())
            ->orderBy('user_id')
            ->get();

        return response()->json([
            'shifts' => $shifts->map(fn (CashShift $s): array => $this->present($s))->values(),
            'history' => $this->cash->recentHistory()->map(fn (CashShift $s): array => $this->present($s))->values(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(CashShift $shift): array
    {
        return [
            'id' => $shift->id,
            'user_id' => $shift->user_id,
            'cashier' => $shift->user?->name ?? 'Unknown',
            'date' => $shift->business_date->format('Y-m-d'),
            'opening_float' => (float) $shift->opening_float,
            'expected_cash' => (float) $shift->expected_cash,
            'counted_cash' => $shift->counted_cash === null ? null : (float) $shift->counted_cash,
            'variance' => $shift->variance === null ? null : (float) $shift->variance,
            'total_sales' => (float) $shift->total_sales,
            'cash_sales_count' => (int) $shift->cash_sales_count,
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'is_open' => $shift->isOpen(),
            'status' => match (true) {
                $shift->isOpen() => 'open',
                $shift->isShort() => 'short',
                $shift->isOver() => 'over',
                default => 'balanced',
            },
            'note' => $shift->note,
        ];
    }
}