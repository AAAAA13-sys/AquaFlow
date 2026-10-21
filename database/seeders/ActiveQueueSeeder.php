<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductionQueueItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds sample orders across the 3 core live fulfillment states:
 *   1. To Refill (Preparing in Refill Queue for Refiller)
 *   2. Queued for Delivery (Ready for Rider dispatch / Out for Delivery)
 *   3. Cashier End (Walk-in order refilled & ready for counter pickup)
 */
class ActiveQueueSeeder extends Seeder
{
    public function run(): void
    {
        $cashier = User::where('role', 'cashier')->first() ?? User::where('role', 'super_admin')->firstOrFail();
        $rider = User::where('role', 'rider')->first();
        $walkInCustomer = Customer::where('name', 'Walk-in Guest')->first() ?? Customer::firstOrFail();
        $regCustomers = Customer::where('name', '<>', 'Walk-in Guest')->get();
        $cust1 = $regCustomers->first() ?? $walkInCustomer;
        $cust2 = $regCustomers->skip(1)->first() ?? $cust1;

        $slimProduct = Product::find('slim');
        $roundProduct = Product::find('round');

        $slimPrice = (float) ($slimProduct?->price ?? 35.00);
        $roundPrice = (float) ($roundProduct?->price ?? 35.00);

        DB::transaction(function () use ($cashier, $rider, $walkInCustomer, $cust1, $cust2, $slimPrice, $roundPrice): void {
            // -------------------------------------------------------------
            // 1. TO REFILL (Queued in Refill Queue - Preparing state)
            // -------------------------------------------------------------
            $receipt1 = 'OR-'.Transaction::nextReceiptSequence();
            $qty1 = 4;
            $total1 = $roundPrice * $qty1;
            $subtotal1 = round($total1 / 1.12, 2);
            $vat1 = round($total1 - $subtotal1, 2);

            $trans1 = Transaction::create([
                'receipt_number' => $receipt1,
                'customer_id' => $cust1->id,
                'cashier_id' => $cashier->id,
                'order_type' => 'Delivery',
                'gallons_volume' => $qty1.' gal',
                'subtotal_amount' => $subtotal1,
                'vat_amount' => $vat1,
                'auto_discount' => 0,
                'manual_discount' => 0,
                'total_amount' => $total1,
                'payment_method' => 'Account',
                'cash_tendered' => 0,
                'cash_change' => 0,
                'transaction_date' => today(),
                'transaction_time' => now()->subMinutes(15)->format('H:i:s'),
            ]);

            TransactionItem::create([
                'transaction_id' => $trans1->id,
                'product_id' => 'round',
                'item_name' => 'Round 5-Gal Refill',
                'quantity' => $qty1,
                'unit_price' => $roundPrice,
                'line_total' => $total1,
            ]);

            $queue1 = ProductionQueueItem::create([
                'transaction_id' => $trans1->id,
                'receipt_number' => $receipt1,
                'customer_name' => $cust1->name,
                'stage' => 1, // Unload/Wash
                'elapsed_minutes' => 5,
                'items_description' => $qty1.'x Round 5-Gal Refill',
                'order_type' => 'Delivery',
                'is_completed' => false,
            ]);
            $queue1->forceFill([
                'fulfillment_status' => 'preparing',
                'version' => 0,
                'rider_id' => null,
                'handed_over_at' => null,
                'delivery_code' => '58291047',
            ])->save();

            // -------------------------------------------------------------
            // 2. QUEUED FOR DELIVERY (Refilled, Ready for Rider Dispatch)
            // -------------------------------------------------------------
            $receipt2 = 'OR-'.Transaction::nextReceiptSequence();
            $qty2 = 3;
            $total2 = $slimPrice * $qty2;
            $subtotal2 = round($total2 / 1.12, 2);
            $vat2 = round($total2 - $subtotal2, 2);

            $trans2 = Transaction::create([
                'receipt_number' => $receipt2,
                'customer_id' => $cust2->id,
                'cashier_id' => $cashier->id,
                'order_type' => 'Delivery',
                'gallons_volume' => $qty2.' gal',
                'subtotal_amount' => $subtotal2,
                'vat_amount' => $vat2,
                'auto_discount' => 0,
                'manual_discount' => 0,
                'total_amount' => $total2,
                'payment_method' => 'Account',
                'cash_tendered' => 0,
                'cash_change' => 0,
                'transaction_date' => today(),
                'transaction_time' => now()->subMinutes(30)->format('H:i:s'),
            ]);

            TransactionItem::create([
                'transaction_id' => $trans2->id,
                'product_id' => 'slim',
                'item_name' => 'Slim 5-Gal Refill',
                'quantity' => $qty2,
                'unit_price' => $slimPrice,
                'line_total' => $total2,
            ]);

            $queue2 = ProductionQueueItem::create([
                'transaction_id' => $trans2->id,
                'receipt_number' => $receipt2,
                'customer_name' => $cust2->name,
                'stage' => ProductionQueueItem::FINAL_STAGE,
                'elapsed_minutes' => 20,
                'items_description' => $qty2.'x Slim 5-Gal Refill',
                'order_type' => 'Delivery',
                'is_completed' => false,
            ]);
            $queue2->forceFill([
                'fulfillment_status' => 'ready',
                'version' => 0,
                'rider_id' => $rider?->id,
                'handed_over_by' => $cashier->id,
                'handed_over_at' => now()->subMinutes(10),
                'delivery_code' => '71940283',
            ])->save();

            // -------------------------------------------------------------
            // 3. CASHIER END (Walk-in Refilled & Ready for Counter Handover)
            // -------------------------------------------------------------
            $receipt3 = 'OR-'.Transaction::nextReceiptSequence();
            $qty3 = 2;
            $total3 = $slimPrice * $qty3;
            $subtotal3 = round($total3 / 1.12, 2);
            $vat3 = round($total3 - $subtotal3, 2);
            $tendered3 = 100.00;
            $change3 = $tendered3 - $total3;

            $trans3 = Transaction::create([
                'receipt_number' => $receipt3,
                'customer_id' => $walkInCustomer->id,
                'cashier_id' => $cashier->id,
                'order_type' => 'Walk-in',
                'gallons_volume' => $qty3.' gal',
                'subtotal_amount' => $subtotal3,
                'vat_amount' => $vat3,
                'auto_discount' => 0,
                'manual_discount' => 0,
                'total_amount' => $total3,
                'payment_method' => 'Cash',
                'cash_tendered' => $tendered3,
                'cash_change' => $change3,
                'transaction_date' => today(),
                'transaction_time' => now()->subMinutes(8)->format('H:i:s'),
            ]);

            TransactionItem::create([
                'transaction_id' => $trans3->id,
                'product_id' => 'slim',
                'item_name' => 'Slim 5-Gal Refill',
                'quantity' => $qty3,
                'unit_price' => $slimPrice,
                'line_total' => $total3,
            ]);

            $queue3 = ProductionQueueItem::create([
                'transaction_id' => $trans3->id,
                'receipt_number' => $receipt3,
                'customer_name' => $walkInCustomer->name,
                'stage' => ProductionQueueItem::FINAL_STAGE,
                'elapsed_minutes' => 8,
                'items_description' => $qty3.'x Slim 5-Gal Refill',
                'order_type' => 'Walk-in',
                'is_completed' => false,
            ]);
            $queue3->forceFill([
                'fulfillment_status' => 'ready',
                'version' => 0,
                'rider_id' => null,
                'handed_over_by' => null,
                'handed_over_at' => null,
                'delivery_code' => null,
            ])->save();
        });
    }
}
