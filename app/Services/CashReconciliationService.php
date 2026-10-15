<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Till cash reconciliation.
 *
 * Everything here is additive: it reads the sales record that already exists
 * and writes one row per cashier-day. It never alters a transaction.
 */
class CashReconciliationService
{
    /** Physical cash includes collections; sales revenue and count exclude them. */
    public function cashMovement(CashShift $shift): array
    {
        $sales = Transaction::query()
            ->where('cashier_id', $shift->user_id)
            ->whereDate('transaction_date', $shift->business_date)
            ->where('payment_method', 'Cash')
            ->get(['total_amount', 'order_type']);

        $total = (float) $sales->sum('total_amount');
        $revenue = $sales->where('order_type', '!=', 'Debt Payment');

        return [
            'total_sales' => round((float) $revenue->sum('total_amount'), 2),
            'cash_sales_count' => $revenue->count(),
            'expected_cash' => round($shift->opening_float + $total, 2),
        ];
    }

    /**
     * Open the drawer for a cashier. Idempotent per day: calling it again
     * returns the session already open rather than recording a second float.
     *
     * @return array{shift: CashShift, already_open: bool}
     */
    public function open(User $user, float $openingFloat, ?string $date = null): array
    {
        return DB::transaction(function () use ($user, $openingFloat, $date): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $businessDate = $date ?: today()->toDateString();

            $existing = CashShift::query()
                ->where('user_id', $user->id)
                ->where(fn ($query) => $query->whereDate('business_date', $businessDate)->orWhereNull('closed_at'))
                ->orderBy('business_date')->lockForUpdate()->first();

            if ($existing) {
                return ['shift' => $existing, 'already_open' => true];
            }

            $shift = CashShift::create([
                'user_id' => $user->id,
                'business_date' => $businessDate,
                'opening_float' => round($openingFloat, 2),
                'opened_at' => now(),
            ]);

            $shift->fill($this->cashMovement($shift))->save();

            return ['shift' => $shift->fresh(), 'already_open' => false];
        });
    }

    /**
     * Count the drawer and close the session.
     *
     * The expected figure is recomputed at close time rather than trusted from
     * the stored value, so cash recorded after the shift opened is included.
     *
     * @return array{shift: CashShift, expected_cash: float, variance: float}
     */
    public function close(CashShift $shift, float $countedCash, ?string $note = null): array
    {
        return DB::transaction(function () use ($shift, $countedCash, $note): array {
            User::whereKey($shift->user_id)->lockForUpdate()->firstOrFail();
            $shift = CashShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if (! $shift->isOpen()) {
                throw ValidationException::withMessages(['counted_cash' => 'This drawer has already been closed.']);
            }
            $movement = $this->cashMovement($shift);

            $expected = $movement['expected_cash'];
            $variance = round($countedCash - $expected, 2);

            $shift->fill([
                'expected_cash' => $expected,
                'counted_cash' => round($countedCash, 2),
                'variance' => $variance,
                'total_sales' => $movement['total_sales'],
                'cash_sales_count' => $movement['cash_sales_count'],
                'closed_at' => now(),
                'note' => $note,
            ])->save();

            return ['shift' => $shift->fresh(), 'expected_cash' => $expected, 'variance' => $variance];
        });
    }

    /** Recent closed sessions, newest first, for the history list. */
    public function recentHistory(int $limit = 14, ?int $userId = null): Collection
    {
        return CashShift::query()
            ->with('user')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->limit($limit)
            ->get();
    }
}
