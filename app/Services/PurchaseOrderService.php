<?php

namespace App\Services;

use App\Http\Resources\PurchaseOrderResource;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Purchase order lifecycle: draft -> ordered -> partially_received -> received,
 * with cancellation from any state before received.
 *
 * Lock order is always: purchase order, then inventory rows in ascending id
 * (inside StockMovementService::record), then the supplier. State is
 * revalidated after the locks are held.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly AuditLogService $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $user, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($user, $data): PurchaseOrder {
            $po = PurchaseOrder::create([
                // Unique placeholder; replaced by the id-based number just below.
                'po_number' => 'TMP'.Str::random(16),
                'supplier_id' => $data['supplier_id'],
                'status' => PurchaseOrder::DRAFT,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            $po->po_number = sprintf('PO-%04d', $po->id);
            $po->total_cost = $this->replaceLines($po, $data['lines']);
            $po->save();

            $this->audit->record($user, 'po_create', $po, null, $this->snapshot($po));

            return $this->fresh($po);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $user, PurchaseOrder $po, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($user, $po, $data): PurchaseOrder {
            $po = $this->lock($po);
            $this->requireStatus($po, [PurchaseOrder::DRAFT], 'Only a draft purchase order can be edited.');
            $before = $this->snapshot($po);

            $po->fill([
                'supplier_id' => $data['supplier_id'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $po->total_cost = $this->replaceLines($po, $data['lines']);
            $po->save();

            $this->audit->record($user, 'po_update', $po, $before, $this->snapshot($po));

            return $this->fresh($po);
        });
    }

    public function markOrdered(User $user, PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($user, $po): PurchaseOrder {
            $po = $this->lock($po);
            $this->requireStatus($po, [PurchaseOrder::DRAFT], 'Only a draft purchase order can be marked as ordered.');
            $before = $this->snapshot($po);

            $po->forceFill(['status' => PurchaseOrder::ORDERED, 'ordered_at' => now()])->save();

            $this->audit->record($user, 'po_order', $po, $before, $this->snapshot($po));

            return $this->fresh($po);
        });
    }

    /**
     * @param  array{submission_key:string,lines:array<int,array{line_id:int,qty:int}>}  $data
     * @return array<string,mixed> the stored (and replayed) response body
     */
    public function receive(User $user, PurchaseOrder $po, array $data): array
    {
        $requested = collect($data['lines'])
            ->map(fn (array $l): array => ['line_id' => (int) $l['line_id'], 'qty' => (int) $l['qty']])
            ->sortBy('line_id')->values()->all();
        $hash = hash('sha256', json_encode([$po->id, $requested], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $po, $data, $requested, $hash): array {
            $po = $this->lock($po);

            $existing = DB::table('purchase_order_receipts')
                ->where('user_id', $user->id)->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['submission_key' => 'This submission key was already used for a different receipt.']);
                }

                return json_decode($existing->result, true, 512, JSON_THROW_ON_ERROR);
            }

            $this->requireStatus($po, [PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL],
                'Only an ordered purchase order can receive stock.');

            // The PO lock serialises receiving, so these rows cannot move under us.
            $lines = PurchaseOrderLine::where('purchase_order_id', $po->id)->orderBy('inventory_item_id')->get()->keyBy('id');
            $quantities = [];
            foreach ($requested as $row) {
                $line = $lines->get($row['line_id']);
                if (! $line) {
                    throw ValidationException::withMessages(['lines' => 'A line does not belong to this purchase order.']);
                }
                if ($row['qty'] > $line->qty_ordered - $line->qty_received) {
                    throw ValidationException::withMessages(['lines' => 'Cannot receive more than the remaining quantity.']);
                }
                $quantities[$line->id] = $row['qty'];
            }
            if (array_sum($quantities) < 1) {
                throw ValidationException::withMessages(['lines' => 'Enter a received quantity for at least one line.']);
            }

            $before = $this->snapshot($po);
            $received = [];
            // Ascending inventory id keeps row-lock order consistent with other writers.
            foreach ($lines as $line) {
                $qty = $quantities[$line->id] ?? 0;
                if ($qty < 1) {
                    continue;
                }
                $this->stock->record(InventoryItem::findOrFail($line->inventory_item_id), [
                    'type' => 'restock',
                    'qty' => $qty,
                    'supplier_id' => $po->supplier_id,
                    'unit_cost' => $line->unit_cost,
                    'purchase_order_id' => $po->id,
                    'notes' => 'Received on '.$po->po_number,
                ], $user);
                $line->qty_received += $qty;
                $line->save();
                $received[] = ['line_id' => $line->id, 'qty' => $qty];
            }
            usort($received, fn (array $a, array $b): int => $a['line_id'] <=> $b['line_id']);

            $complete = $lines->every(fn (PurchaseOrderLine $l): bool => $l->qty_received >= $l->qty_ordered);
            $po->status = $complete ? PurchaseOrder::RECEIVED : PurchaseOrder::PARTIAL;
            $po->received_at = $complete ? now() : null;
            // Unit costs are fixed on the order, so the total is the ordered value.
            $po->total_cost = $this->total($lines);
            $po->save();
            Supplier::whereKey($po->supplier_id)->update(['last_delivery' => today()->toDateString()]);

            $this->audit->record($user, 'po_receive', $po, $before,
                $this->snapshot($po) + ['received' => $received]);

            $result = [
                'purchase_order' => (new PurchaseOrderResource($this->fresh($po)))->resolve(),
                'received' => $received,
            ];
            $result = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

            DB::table('purchase_order_receipts')->insert([
                'purchase_order_id' => $po->id, 'user_id' => $user->id,
                'submission_key' => $data['submission_key'], 'request_hash' => $hash,
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $result;
        });
    }

    public function cancel(User $user, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($user, $po, $reason): PurchaseOrder {
            $po = $this->lock($po);
            $this->requireStatus($po, [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL],
                'This purchase order can no longer be cancelled.');

            if ($po->status === PurchaseOrder::DRAFT) {
                abort_unless($user->isSuperAdmin() || (int) $po->created_by === $user->id, 403,
                    'Only the creator or the owner can cancel a draft.');
            } else {
                abort_unless($user->isSuperAdmin(), 403, 'Only the owner can cancel an ordered purchase order.');
            }
            $before = $this->snapshot($po);

            $po->forceFill([
                'status' => PurchaseOrder::CANCELLED, 'cancelled_at' => now(),
                'cancelled_by' => $user->id, 'cancel_reason' => $reason,
            ])->save();

            $this->audit->record($user, 'po_cancel', $po, $before, $this->snapshot($po) + ['cancel_reason' => $reason]);

            return $this->fresh($po);
        });
    }

    /**
     * Active items at or below their reorder point, with a suggested quantity.
     *
     * @return array<int,array<string,mixed>>
     */
    public function suggestions(): array
    {
        $items = InventoryItem::current()->with('supplier')
            ->where('reorder_point', '>', 0)->whereColumn('stock_on_hand', '<=', 'reorder_point')
            ->orderBy('item_name')->get();

        $costs = [];
        if ($items->isNotEmpty()) {
            StockMovement::where('type', 'restock')->whereNotNull('unit_cost')
                ->whereIn('inventory_item_id', $items->pluck('id'))
                ->orderByDesc('id')->get(['inventory_item_id', 'unit_cost'])
                ->each(function (StockMovement $m) use (&$costs): void {
                    $costs[$m->inventory_item_id] ??= (float) $m->unit_cost;
                });
        }

        return $items->map(fn (InventoryItem $i): array => [
            'inventory_item_id' => $i->id,
            'item_name' => $i->item_name,
            'unit' => $i->unit,
            'stock_on_hand' => $i->stock_on_hand,
            'reorder_point' => $i->reorder_point,
            'target_stock' => $i->target_stock,
            'suggested_qty' => max($i->target_stock - $i->stock_on_hand, 1),
            'supplier_id' => $i->supplier_id,
            'supplier_name' => $i->supplier?->name,
            'last_unit_cost' => $costs[$i->id] ?? null,
        ])->values()->all();
    }

    private function lock(PurchaseOrder $po): PurchaseOrder
    {
        return PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<int,string> $allowed */
    private function requireStatus(PurchaseOrder $po, array $allowed, string $message): void
    {
        if (! in_array($po->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * @param  array<int,array{inventory_item_id:int,qty:int,unit_cost:float|int|string}>  $lines
     * @return float the order total
     */
    private function replaceLines(PurchaseOrder $po, array $lines): float
    {
        $po->lines()->delete();
        $total = 0.0;
        foreach ($lines as $line) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po->id,
                'inventory_item_id' => $line['inventory_item_id'],
                'qty_ordered' => $line['qty'],
                'unit_cost' => $line['unit_cost'],
            ]);
            $total += $line['qty'] * (float) $line['unit_cost'];
        }

        return round($total, 2);
    }

    /** @param \Illuminate\Support\Collection<int,PurchaseOrderLine> $lines */
    private function total($lines): float
    {
        return round($lines->sum(fn (PurchaseOrderLine $l): float => $l->qty_ordered * (float) $l->unit_cost), 2);
    }

    /** @return array<string,mixed> */
    private function snapshot(PurchaseOrder $po): array
    {
        return [
            'po_number' => $po->po_number,
            'status' => $po->status,
            'supplier_id' => $po->supplier_id,
            'expected_date' => $po->expected_date?->format('Y-m-d'),
            'notes' => $po->notes,
            'total_cost' => (float) $po->total_cost,
        ];
    }

    private function fresh(PurchaseOrder $po): PurchaseOrder
    {
        return $po->refresh()->load(['supplier', 'creator', 'lines.item']);
    }
}
