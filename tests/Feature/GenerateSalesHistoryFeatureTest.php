<?php

namespace Tests\Feature;

use App\Models\ProductionQueueItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateSalesHistoryFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Demo deliveries placed on today's queue must be linked to their sale.
     * The cashier handover checks the counted quantity against the linked
     * receipt's items; an unlinked row counts as zero, so it could never be
     * handed over and the rider could never dispatch it.
     */
    public function test_generated_queue_orders_are_linked_to_their_receipts(): void
    {
        $this->seed();
        $this->artisan('aquaflow:generate-history', ['--days' => 30, '--fresh' => true])->assertSuccessful();

        $queued = ProductionQueueItem::with('transaction.items')->get();
        $this->assertNotEmpty($queued, 'The generator should queue some of today\'s deliveries.');

        foreach ($queued as $job) {
            $this->assertNotNull($job->transaction, $job->receipt_number.' has no linked sale.');
            $this->assertSame($job->receipt_number, $job->transaction->receipt_number);
            $this->assertGreaterThan(0, (int) $job->transaction->items->sum('quantity'), $job->receipt_number.' has nothing to hand over.');
        }
    }
}
