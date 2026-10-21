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

    /** The caller's outstanding session, otherwise today's closed session. */
    public function current(Request $request): JsonResponse
    {
        $shift = CashShift::query()
            ->where('user_id', $request->user()->id)
            ->where(fn ($query) => $query->whereDate('business_date', today())->orWhereNull('closed_at'))
            ->orderBy('business_date')->first();

        // While the drawer is open the operator is counting cash against a live
        // figure, so refresh what *should* be there as sales come in rather than
        // showing the figure recorded when the drawer was opened.
        if ($shift?->isOpen()) {
            $movement = $this->cash->cashMovement($shift);
            $shift->fill($movement);
        }

        return response()->json([
            'shift' => $shift ? $this->present($shift) : null,
            'history' => $this->cash->recentHistory(userId: $request->user()->id)->map(fn (CashShift $s): array => $this->present($s))->values(),
        ]);
    }

    public function open(OpenCashShiftRequest $request): JsonResponse
    {
        $result = $this->cash->open(
            $request->user(),
            (float) $request->validated()['opening_float'],
            openingNote: $request->validated()['opening_note'] ?? null,
        );

        if ($result['already_open']) {
            return response()->json([
                'message' => 'A drawer session already exists. Close any outstanding drawer before opening another.',
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
            ->whereKey($request->validated()['shift_id'])->first();

        if (! $shift) {
            throw ValidationException::withMessages([
                'counted_cash' => 'No open drawer session. Open the drawer first.',
            ]);
        }

        $result = $this->cash->close(
            $shift,
            (float) $request->validated()['counted_cash'],
            (float) $request->validated()['expected_cash'],
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
            ->where(fn ($query) => $query->whereDate('business_date', today())->orWhereNull('closed_at'))
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
        if ($shift->isOpen()) { $shift->fill($this->cash->cashMovement($shift)); }
        return [
            'pending_collections' => $shift->isOpen() ? $this->cash->pendingCollections($shift->user_id) : 0,
            'collections' => round($shift->expected_cash - $shift->opening_float - $shift->total_sales, 2),
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
            'opening_note' => $shift->opening_note,
            'timing' => $this->presentTiming($shift),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function presentTiming(CashShift $shift): array
    {
        $policy = CashShift::shiftPolicy();
        $tz = $shift->policyTz();

        $openActualStr = $shift->opened_at ? $shift->opened_at->copy()->setTimezone($tz)->format('g:i A') : null;
        $closeActualStr = $shift->closed_at ? $shift->closed_at->copy()->setTimezone($tz)->format('g:i A') : null;

        $openStatus = $shift->openingPunctuality();
        $closeStatus = $shift->closingPunctuality();
        $openLateMins = $shift->openingLateMinutes();
        $closeLateMins = $shift->closingLateMinutes();

        $openLabel = match ($openStatus) {
            'unopened' => 'Unopened',
            'early' => "Early Prep ({$openActualStr} • Before schedule)",
            'on_time' => "Opened on-time ({$openActualStr} • Within grace period)",
            'late' => "Late Opening ({$openActualStr} • +{$openLateMins}m past grace)",
            default => 'Unknown',
        };

        $closeLabel = match ($closeStatus) {
            'unopened' => 'Unopened',
            'open' => 'Currently Open',
            'early' => "Early Close ({$closeActualStr} • Before schedule)",
            'on_time' => "Closed on-time ({$closeActualStr} • Within grace period)",
            'delayed' => "Delayed Close ({$closeActualStr} • +{$closeLateMins}m past grace)",
            default => 'Unknown',
        };

        return [
            'schedule' => substr($policy['start'], 0, 5).' - '.substr($policy['end'], 0, 5),
            'grace_minutes' => $policy['grace_minutes'],
            'timezone' => $tz,
            'opening_status' => $openStatus,
            'opening_label' => $openLabel,
            'opening_late_minutes' => $openLateMins,
            'closing_status' => $closeStatus,
            'closing_label' => $closeLabel,
            'closing_late_minutes' => $closeLateMins,
        ];
    }
}
