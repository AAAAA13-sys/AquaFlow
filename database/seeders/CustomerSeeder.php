<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * Demo customers with representative custody balances and receivables.
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            ['name' => 'San Miguel Commercial Canteen', 'address' => 'Kapitolyo, Pasig', 'contact' => '0917-000-1001', 'issued_slim' => 45, 'returned_slim' => 30, 'issued_round' => 10, 'returned_round' => 10, 'debt_balance' => 24800, 'total_transactions' => 62, 'days_ago' => 7],
            ['name' => 'Barangay Kapitolyo Health Center', 'address' => 'Block 4 Lot 12', 'contact' => '0917-000-1002', 'issued_slim' => 30, 'returned_slim' => 18, 'issued_round' => 20, 'returned_round' => 12, 'debt_balance' => 1450, 'total_transactions' => 34, 'days_ago' => 7],
            ['name' => 'Santos Family', 'address' => 'Blk 3 Lot 7', 'contact' => '0917-111-2233', 'issued_slim' => 20, 'returned_slim' => 17, 'issued_round' => 10, 'returned_round' => 10, 'debt_balance' => 150, 'total_transactions' => 18, 'days_ago' => 8],
            ['name' => 'Reyes Store', 'address' => 'Market Rd', 'contact' => '0918-555-0101', 'issued_slim' => 40, 'returned_slim' => 35, 'issued_round' => 30, 'returned_round' => 28, 'debt_balance' => 500, 'total_transactions' => 41, 'days_ago' => 8],
            ['name' => 'Dela Cruz Residence', 'address' => 'Phase 2 B12', 'contact' => '0920-333-4455', 'issued_slim' => 8, 'returned_slim' => 8, 'issued_round' => 4, 'returned_round' => 3, 'debt_balance' => 0, 'total_transactions' => 9, 'days_ago' => 9],
            ['name' => 'Aqua Office', 'address' => 'Industrial Park', 'contact' => '0919-777-8899', 'issued_slim' => 60, 'returned_slim' => 54, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 750, 'total_transactions' => 27, 'days_ago' => 7],
            ['name' => 'Mendoza Canteen', 'address' => 'School Ave', 'contact' => '0930-123-4567', 'issued_slim' => 25, 'returned_slim' => 25, 'issued_round' => 15, 'returned_round' => 12, 'debt_balance' => 0, 'total_transactions' => 22, 'days_ago' => 10],
            ['name' => 'Torres Apartment (6 units)', 'address' => 'Block 9 Lot 3', 'contact' => '0931-222-3344', 'issued_slim' => 18, 'returned_slim' => 14, 'issued_round' => 6, 'returned_round' => 6, 'debt_balance' => 320, 'total_transactions' => 15, 'days_ago' => 9],
            ['name' => 'Walk-in Guest', 'address' => '-', 'contact' => '-', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => null],
            ['name' => 'Garcia Laundry Shop', 'address' => 'Riverside St', 'contact' => '0932-444-5566', 'issued_slim' => 22, 'returned_slim' => 20, 'issued_round' => 12, 'returned_round' => 9, 'debt_balance' => 210, 'total_transactions' => 19, 'days_ago' => 8],
        ];

        foreach ($customers as $customer) {
            $daysAgo = $customer['days_ago'];
            unset($customer['days_ago']);

            Customer::query()->updateOrCreate(
                ['name' => $customer['name']],
                $customer + ['last_visit' => $daysAgo === null ? null : now()->subDays($daysAgo)]
            );
        }
    }
}
