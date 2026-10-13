<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Till cash reconciliation.
 *
 * Everything here is additive: it reads the sales record that already exists
 * and writes one row per cashier-day. It never alters a transaction.
 */
class CashReconciliationService
{
    /**
     * Cash taken during a shift: opening float plus cash sales.
     *
     * Account (credit) sales are excluded - no money moved at the counter, so
     * including them would overstate the drawer. Debt payments are excluded
     * too because they are collected against an existing balance and are
     * reported separately on the sales page.
     */
    public function cashMovement(CashShift $shift): array
    {
        $sales = Transaction::query()
            ->where('cashier_id', $shift->user_id)
            ->whereDate('transaction_date', $shift->business_date)
            ->where('payment_method', 'Cash')
            ->where('order_type', '!=', 'Debt Payment')
            ->get(['total_amount']);

        $total = (float) $sales->sum('total_amount');

        return [
            'total_sales' => round($total, 2),
            'cash_sales_count' => $sales->count(),
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
        $businessDate = $date ?: today()->toDateString();

        $existing = CashShift::query()
            ->where('user_id', $user->id)
            ->whereDate('business_date', $businessDate)
            ->first();

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
    }

    /** Recent closed sessions, newest first, for the history list. */
    public function recentHistory(int $limit = 14): Collection
    {
        return CashShift::query()
            ->with('user')
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->limit($limit)
            ->get();
    }
}