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
            if ($locked->is_completed) {
                return;
            }
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
