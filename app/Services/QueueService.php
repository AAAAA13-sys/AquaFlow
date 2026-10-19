<?php

namespace App\Services;

use App\Models\ProductionQueueItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QueueService
{
    public function deliver(ProductionQueueItem $item, int $userId): ProductionQueueItem
    {
        abort_if(
            auth()->check() && ! in_array(auth()->user()->role, [User::ROLE_CASHIER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true),
            403,
            'Only cashiers and administrators can confirm order delivery.'
        );

        DB::transaction(function () use ($item, $userId): void {
            $locked = ProductionQueueItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->order_type !== 'Walk-in', 409, 'Delivery must be confirmed by the assigned rider.');
            if ($locked->is_completed) {
                return;
            }
            abort_unless($locked->fulfillment_status === 'ready', 409, 'The refiller must mark this order ready before handover.');
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
