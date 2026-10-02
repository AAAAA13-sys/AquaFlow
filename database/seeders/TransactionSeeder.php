<?php

namespace Database\Seeders;

use App\Models\ContainerCustodyLog;
use App\Models\Customer;
use App\Models\ProductionQueueItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A small starting sales ledger so the dashboard and sales log are not empty
 * before the history generator runs.
 *
 * The 90-day history used by the ARIMA model comes from:
 *     php artisan aquaflow:generate-history
 */
class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $cashier = User::query()->where('username', 'cashier')->firstOrFail();

        $sales = [
            [
                'receipt_number' => 'OR-1011', 'customer' => 'Santos Family', 'order_type' => 'Delivery',
                'volume' => '3S+2R', 'subtotal' => 175.00, 'payment' => 'Account', 'tendered' => 0.00,
                'days_ago' => 2, 'time' => '08:12:00',
                'items' => [['slim', 'Slim 5-Gal Refill', 3, 35.00], ['round', 'Round 5-Gal Refill', 2, 35.00]],
                'custody' => [3, 1, 2, 2],
            ],
            [
                'receipt_number' => 'OR-1012', 'customer' => 'Walk-in Guest', 'order_type' => 'Walk-in',
                'volume' => '2S', 'subtotal' => 70.00, 'payment' => 'Cash', 'tendered' => 100.00,
                'days_ago' => 1, 'time' => '09:05:00',
                'items' => [['slim', 'Slim 5-Gal Refill', 2, 35.00]],
                'custody' => [0, 0, 0, 0],
            ],
            [
                'receipt_number' => 'OR-1013', 'customer' => 'Reyes Store', 'order_type' => 'Delivery',
                'volume' => '5S+5R', 'subtotal' => 350.00, 'payment' => 'Account', 'tendered' => 0.00,
                'days_ago' => 1, 'time' => '10:40:00',
                'items' => [['slim', 'Slim 5-Gal Refill', 5, 35.00], ['round', 'Round 5-Gal Refill', 5, 35.00]],
                'custody' => [5, 3, 5, 5],
            ],
            [
                'receipt_number' => 'OR-1014', 'customer' => 'Aqua Office', 'order_type' => 'Delivery',
                'volume' => '6S', 'subtotal' => 210.00, 'payment' => 'Account', 'tendered' => 0.00,
                'days_ago' => 0, 'time' => '11:15:00',
                'items' => [['slim', 'Slim 5-Gal Refill', 6, 35.00]],
                'custody' => [6, 0, 0, 0],
            ],
            [
                'receipt_number' => 'OR-1015', 'customer' => 'Walk-in Guest', 'order_type' => 'Walk-in',
                'volume' => '2S', 'subtotal' => 70.00, 'payment' => 'Cash', 'tendered' => 100.00,
                'days_ago' => 0, 'time' => '13:02:00',
                'items' => [['slim', 'Slim 5-Gal Refill', 2, 35.00]],
                'custody' => [0, 0, 0, 0],
            ],
            [
                'receipt_number' => 'OR-1016', 'customer' => 'Walk-in Guest', 'order_type' => 'Walk-in',
                'volume' => '0 gal', 'subtotal' => 500.00, 'payment' => 'Cash', 'tendered' => 500.00,
                'days_ago' => 0, 'time' => '14:20:00',
                'items' => [['newS', 'New Slim 5-Gal Jug', 2, 250.00]],
                'custody' => [0, 0, 0, 0],
            ],
            [
                'receipt_number' => 'OR-1017', 'customer' => 'Garcia Laundry Shop', 'order_type' => 'Delivery',
                'volume' => '8S+3R', 'subtotal' => 385.00, 'payment' => 'Account', 'tendered' => 0.00,
                'days_ago' => 0, 'time' => '15:35:00',
                'items' => [['slim', 'Slim 5-Gal Refill', 8, 35.00], ['round', 'Round 5-Gal Refill', 3, 35.00]],
                'custody' => [8, 0, 3, 0],
            ],
            [
                'receipt_number' => 'OR-1018', 'customer' => 'Mendoza Canteen', 'order_type' => 'Walk-in',
                'volume' => '4R', 'subtotal' => 140.00, 'payment' => 'Cash', 'tendered' => 150.00,
                'days_ago' => 0, 'time' => '16:05:00',
                'items' => [['round', 'Round 5-Gal Refill', 4, 35.00]],
                'custody' => [0, 0, 4, 0],
            ],
            [
                'receipt_number' => 'OR-1019', 'customer' => 'Torres Apartment (6 units)', 'order_type' => 'Debt Payment',
                'volume' => '0 gal', 'subtotal' => 200.00, 'payment' => 'Cash', 'tendered' => 200.00,
                'days_ago' => 0, 'time' => '16:40:00',
                'items' => [],
                'custody' => [0, 0, 0, 0],
            ],
        ];

        // Orders sold today move along the production line; spread them across
        // the stages so the queue view shows work at every step.
        $queueStage = 0;

        foreach ($sales as $sale) {
            $customer = Customer::query()->where('name', $sale['customer'])->firstOrFail();

            // A debt settlement collects a receivable; it is not a new taxable
            // sale, so it carries no VAT split.
            $isSettlement = $sale['order_type'] === 'Debt Payment';
            $vatable = $isSettlement ? 0.0 : round($sale['subtotal'] / (1 + CheckoutService::VAT_RATE), 2);
            $vat = $isSettlement ? 0.0 : round($sale['subtotal'] - $vatable, 2);

            $transaction = Transaction::query()->updateOrCreate(
                ['receipt_number' => $sale['receipt_number']],
                [
                    'customer_id' => $customer->id,
                    'cashier_id' => $cashier->id,
                    'order_type' => $sale['order_type'],
                    'gallons_volume' => $sale['volume'],
                    // Prices are VAT inclusive: the shelf total is the gross
                    // amount, and the vatable sales plus the tax are derived.
                    'subtotal_amount' => $vatable,
                    'vat_amount' => $vat,
                    'auto_discount' => 0,
                    'manual_discount' => 0,
                    'total_amount' => $sale['subtotal'],
                    'payment_method' => $sale['payment'],
                    'cash_tendered' => $sale['tendered'],
                    'cash_change' => max(0, $sale['tendered'] - $sale['subtotal']),
                    'transaction_date' => now()->subDays($sale['days_ago'])->toDateString(),
                    'transaction_time' => $sale['time'],
                ]
            );

            foreach ($sale['items'] as [$productId, $name, $quantity, $price]) {
                TransactionItem::query()->updateOrCreate(
                    ['transaction_id' => $transaction->id, 'product_id' => $productId],
                    [
                        'item_name' => $name,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'line_total' => round($quantity * $price, 2),
                    ]
                );
            }

            [$slimOut, $slimIn, $roundOut, $roundIn] = $sale['custody'];

            // Historical custody rows only: the station lends no jugs any more,
            // and a debt settlement moves no containers at all.
            if ($sale['items'] !== []) {
                ContainerCustodyLog::query()->updateOrCreate(
                    ['transaction_id' => $transaction->id],
                    [
                        'customer_id' => $customer->id,
                        'slim_out' => $slimOut,
                        'slim_in' => $slimIn,
                        'round_out' => $roundOut,
                        'round_in' => $roundIn,
                        'deficit_slim' => max(0, $slimOut - $slimIn),
                        'deficit_round' => max(0, $roundOut - $roundIn),
                        'damaged_reported' => false,
                    ]
                );
            }

            // Anything sold today that still needs filling appears in the queue.
            // The stages are spread across the line so the production view shows
            // orders at every step.
            if ($sale['days_ago'] === 0 && $sale['volume'] !== '0 gal') {
                DB::table('production_queue')->updateOrInsert(
                    ['receipt_number' => $sale['receipt_number']],
                    [
                        'customer_name' => $customer->name,
                        'stage' => $queueStage % ProductionQueueItem::FINAL_STAGE,
                        'elapsed_minutes' => 3 + ($queueStage * 4),
                        'items_description' => $sale['volume'],
                        'order_type' => $sale['order_type'],
                        'is_completed' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $queueStage++;
            }
        }
    }
}
