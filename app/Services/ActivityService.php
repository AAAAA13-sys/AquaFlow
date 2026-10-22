<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\DefectReport;
use App\Models\DeliveryRemittance;
use App\Models\ProductionQueueItem;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

/**
 * "What did I do today?" for any station account.
 *
 * Every operational action leaves evidence in the business records: a state
 * change on the order (`stage_history`), a sale (`transactions`), an accepted
 * rider remittance (`delivery_remittances`), a drawer open or close
 * (`cash_shifts`) or a damaged-stock report (`defect_reports`). This service
 * reads those records for the signed-in account and returns one chronological
 * list, so the cashier, the rider and the refiller can all see their own day.
 *
 * Only the signed-in account's own records are read: nothing here accepts an
 * actor id from the request.
 */
class ActivityService
{
    /**
     * @return array{entries: list<array<string,mixed>>, counts: array<string,mixed>}
     */
    public function today(User $user): array
    {
        $entries = array_merge(
            $this->orderActions($user),
            $this->damageReports($user),
            $user->canHandleCash() ? $this->cashierActions($user) : [],
        );

        usort($entries, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return ['entries' => $entries, 'counts' => $this->counts($entries)];
    }

    /**
     * State changes the account made on orders: the refiller marking an order
     * ready, the rider starting or finishing a delivery, the cashier handing an
     * order over or confirming a pickup.
     */
    private function orderActions(User $user): array
    {
        $jobs = ProductionQueueItem::with(['transaction.items', 'transaction.customer', 'deliveryProof'])
            ->where('is_completed', false)
            ->orWhereDate('delivered_at', today())
            ->orderBy('created_at')->orderBy('id')->get();

        $entries = [];
        foreach ($jobs as $job) {
            foreach (($job->stage_history ?? []) as $entry) {
                if ((int) ($entry['user_id'] ?? 0) !== $user->id) {
                    continue;
                }

                $at = $this->stationTime($entry['at'] ?? null);
                if (! $at || ! $at->isSameDay(now())) {
                    continue;
                }

                $entries[] = $this->orderEntry($job, $user, $entry, $at);
            }
        }

        return $entries;
    }

    /**
     * A recorded timestamp in station time.
     *
     * The history is written in UTC (2026-01-22T14:50Z), so reading it as-is
     * would call a 10:50 PM shift "today" at 7 AM the next morning and drop
     * the first hours of the morning. The station's day boundary is the
     * application timezone, the same one the cash shift and the sales ledger
     * use.
     */
    private function stationTime(?string $timestamp): ?Carbon
    {
        if (! $timestamp) {
            return null;
        }

        try {
            return Carbon::parse($timestamp)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function orderEntry(ProductionQueueItem $job, User $user, array $entry, Carbon $at): array
    {
        $lines = $job->transaction?->items->map(fn ($line) => $line->quantity.' × '.$line->item_name)->all() ?? [];
        $proof = $job->deliveryProof;
        $money = $user->canHandleCash() || $user->role === User::ROLE_RIDER;
        $action = $entry['action'] ?? null;
        $status = $entry['status'] ?? $job->fulfillment_status;

        return [
            'kind' => 'order',
            'at' => $at->toISOString(),
            'title' => $job->receipt_number,
            'subtitle' => $job->customer_name ?: 'Walk-in customer',
            'badge' => $this->orderBadge($action, $status, $user),
            'tone' => $this->orderTone($status),
            'lines' => $lines ? implode(', ', $lines) : ($job->items_description ?: 'Water refill'),
            'amount' => $money && $proof ? round((float) $proof->reported_cash, 2) : null,
            'amount_label' => $money && $proof ? 'Cash collected' : null,
            'note' => $entry['reason'] ?? null,
            'address' => $user->role === User::ROLE_RIDER
                ? ($job->transaction?->delivery_address ?: $job->transaction?->customer?->address)
                : null,
        ];
    }

    private function orderBadge(?string $action, string $status, User $user): string
    {
        return match ($action) {
            'ready' => 'Marked ready',
            'dispatch' => 'Started delivery',
            'deliver' => 'Delivered',
            'issue' => 'Reported an issue',
            'handover' => 'Handed over to rider',
            'reset_code' => 'Reissued the buyer code',
            'assign' => 'Assigned the rider',
            default => $status === 'delivered' ? 'Confirmed pickup' : 'Updated the order',
        };
    }

    private function orderTone(string $status): string
    {
        return match ($status) {
            'preparing' => 'preparing',
            'ready' => 'ready',
            'out_for_delivery' => 'out_for_delivery',
            'delivered' => 'delivered',
            default => 'neutral',
        };
    }

    /**
     * Damaged-stock reports this account filed. The report row is evidence and
     * is never deleted, so it stays reviewable from the activity list.
     */
    private function damageReports(User $user): array
    {
        return DefectReport::query()
            ->with('inventoryItem')
            ->where('reported_by', $user->id)
            // Laravel writes timestamps in the application timezone, so the
            // day boundary is the station's own day.
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(fn (DefectReport $report): array => [
                'kind' => 'damage',
                'at' => $report->created_at->toISOString(),
                'title' => $report->inventoryItem?->item_name ?? 'Stock item',
                'subtitle' => $report->quantity.' '.($report->inventoryItem?->unit ?? 'pcs').' reported damaged',
                'badge' => 'Reported damaged stock',
                'tone' => 'damage',
                'lines' => $report->reason,
                'amount' => null,
                'amount_label' => null,
                'note' => $this->reportStatus($report),
                'address' => null,
            ])->all();
    }

    private function reportStatus(DefectReport $report): ?string
    {
        return match ($report->status) {
            DefectReport::STATUS_APPROVED => 'Approved: written off stock',
            DefectReport::STATUS_REJECTED => 'Rejected by a manager',
            default => 'Waiting for a manager',
        };
    }

    /**
     * Till work: the sales and payments this cashier recorded, the rider cash
     * they accepted, and their own drawer open/close.
     */
    private function cashierActions(User $user): array
    {
        $entries = [];

        $sales = Transaction::query()
            ->with(['customer', 'items'])
            ->where('cashier_id', $user->id)
            ->whereDate('transaction_date', today())
            ->orderBy('transaction_time')->orderBy('id')
            ->get();

        foreach ($sales as $sale) {
            $at = Carbon::parse($sale->transaction_date->format('Y-m-d').' '.($sale->transaction_time ?: '00:00:00'));
            if (! $at->isToday()) {
                continue;
            }

            $lines = $sale->items->map(fn ($line) => $line->quantity.' × '.$line->item_name)->all();
            $entries[] = [
                'kind' => $sale->order_type === 'Debt Payment' ? 'payment' : 'sale',
                'at' => $at->toISOString(),
                'title' => $sale->receipt_number,
                'subtitle' => $sale->customer?->name ?: 'Walk-in customer',
                'badge' => $sale->order_type === 'Debt Payment' ? 'Payment collected' : 'Sale recorded',
                'tone' => $sale->order_type === 'Debt Payment' ? 'payment' : 'sale',
                'lines' => $lines ? implode(', ', $lines) : ($sale->order_type === 'Debt Payment' ? 'Account payment' : 'Water refill'),
                'amount' => round((float) $sale->total_amount, 2),
                'amount_label' => $sale->order_type === 'Debt Payment' ? 'Collected' : 'Total',
                'note' => $sale->payment_method === 'Cash' ? null : 'Paid by '.$sale->payment_method,
                'address' => $sale->delivery_address,
            ];
        }

        $remittances = DeliveryRemittance::query()
            ->where('cashier_id', $user->id)
            ->whereDate('created_at', today())
            ->orderBy('created_at')->orderBy('id')
            ->get();

        foreach ($remittances as $remittance) {
            $entries[] = [
                'kind' => 'remittance',
                'at' => Carbon::parse($remittance->created_at)->toISOString(),
                'title' => 'Rider cash',
                'subtitle' => 'Accepted a delivery collection',
                'badge' => 'Accepted rider cash',
                'tone' => 'payment',
                'lines' => $remittance->note ?: 'Remittance accepted at the till',
                'amount' => round((float) $remittance->amount, 2),
                'amount_label' => 'Accepted',
                'note' => null,
                'address' => null,
            ];
        }

        $shifts = CashShift::query()
            ->where('user_id', $user->id)
            ->whereDate('business_date', today())
            ->orderBy('id')
            ->get();

        foreach ($shifts as $shift) {
            if ($shift->opened_at) {
                $entries[] = [
                    'kind' => 'drawer',
                    'at' => Carbon::parse($shift->opened_at)->toISOString(),
                    'title' => 'Cash drawer',
                    'subtitle' => 'Opened the drawer for the shift',
                    'badge' => 'Drawer opened',
                    'tone' => 'drawer',
                    'lines' => 'Starting float: '.number_format((float) $shift->opening_float, 2),
                    'amount' => null,
                    'amount_label' => null,
                    'note' => $shift->opening_note,
                    'address' => null,
                ];
            }

            if ($shift->closed_at) {
                $entries[] = [
                    'kind' => 'drawer',
                    'at' => Carbon::parse($shift->closed_at)->toISOString(),
                    'title' => 'Cash drawer',
                    'subtitle' => 'Closed the drawer for the shift',
                    'badge' => 'Drawer closed',
                    'tone' => 'drawer',
                    'lines' => 'Counted: '.number_format((float) $shift->counted_cash, 2)
                        .' · expected: '.number_format((float) $shift->expected_cash, 2),
                    'amount' => $shift->variance === null ? null : round((float) $shift->variance, 2),
                    'amount_label' => $shift->variance === null ? null : 'Variance',
                    'note' => $shift->note,
                    'address' => null,
                ];
            }
        }

        return $entries;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function counts(array $entries): array
    {
        $counts = ['actions' => count($entries), 'sales' => 0, 'deliveries' => 0, 'damage' => 0, 'cash' => 0.0];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'sale') {
                $counts['sales']++;
            }
            if ($entry['kind'] === 'order' && $entry['tone'] === 'delivered') {
                $counts['deliveries']++;
            }
            if ($entry['kind'] === 'damage') {
                $counts['damage']++;
            }
            if (in_array($entry['kind'], ['sale', 'payment', 'remittance'], true)) {
                $counts['cash'] += (float) ($entry['amount'] ?? 0);
            }
        }

        return $counts;
    }
}
