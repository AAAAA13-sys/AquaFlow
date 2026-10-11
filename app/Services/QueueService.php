<?php

namespace App\Services;

use App\Models\ProductionQueueItem;
use Illuminate\Support\Facades\DB;

class QueueService
{
    public function deliver(ProductionQueueItem $item, int $userId): ProductionQueueItem
    {
        DB::transaction(function () use ($item, $userId): void {
            $locked = ProductionQueueItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->order_type === 'Walk-in', 403, 'Only the assigned rider can deliver this order.');
            abort_unless($locked->fulfillment_status === 'ready' || $locked->is_completed || ! $locked->transaction_id, 422, 'The refiller must mark this order ready first.');
            if ($locked->is_completed) {
                return;
            }
            $locked->fulfillment_status = 'delivered';
            $locked->version++;
            $locked->is_completed = true;
            $locked->delivered_at = now();
            $history = $locked->stage_history ?? [];
            $history[] = ['status' => 'delivered', 'at' => now()->toIso8601String(), 'user_id' => $userId];
            $locked->stage_history = $history;
            $locked->save();
        });

        return $item->refresh();
    }
}
