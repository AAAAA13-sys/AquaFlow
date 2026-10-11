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
            ['name' => 'San Miguel Commercial Canteen', 'address' => 'Kapitolyo, Pasig', 'contact' => '0917-000-1001', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 7],
            ['name' => 'Barangay Kapitolyo Health Center', 'address' => 'Block 4 Lot 12', 'contact' => '0917-000-1002', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 7],
            ['name' => 'Santos Family', 'address' => 'Blk 3 Lot 7', 'contact' => '0917-111-2233', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 8],
            ['name' => 'Reyes Store', 'address' => 'Market Rd', 'contact' => '0918-555-0101', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 8],
            ['name' => 'Dela Cruz Residence', 'address' => 'Phase 2 B12', 'contact' => '0920-333-4455', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 9],
            ['name' => 'Aqua Office', 'address' => 'Industrial Park', 'contact' => '0919-777-8899', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 7],
            ['name' => 'Mendoza Canteen', 'address' => 'School Ave', 'contact' => '0930-123-4567', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 10],
            ['name' => 'Torres Apartment (6 units)', 'address' => 'Block 9 Lot 3', 'contact' => '0931-222-3344', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 9],
            ['name' => 'Walk-in Guest', 'address' => '-', 'contact' => '-', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => null],
            ['name' => 'Garcia Laundry Shop', 'address' => 'Riverside St', 'contact' => '0932-444-5566', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 8],
        ];

        foreach ($customers as $customer) {
            $daysAgo = $customer['days_ago'];
            unset($customer['days_ago']);

            Customer::query()->updateOrCreate(
                ['name' => $customer['name']],
                $customer + ['system_key' => $customer['name'] === 'Walk-in Guest' ? 'walk-in' : null, 'last_visit' => $daysAgo === null ? null : now()->subDays($daysAgo)]
            );
        }
    }
}
