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
        // Contact numbers follow the real +63 format rather than repeated filler
        // digits, so reminders and receipts read like a real contact list.
        $customers = [
            ['name' => 'San Miguel Commercial Canteen', 'address' => 'Kapitolyo, Pasig', 'contact' => '+639171234501', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 7],
            ['name' => 'Barangay Kapitolyo Health Center', 'address' => 'Block 4 Lot 12', 'contact' => '+639171234502', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 7],
            ['name' => 'Santos Family', 'address' => 'Blk 3 Lot 7', 'contact' => '+639171112233', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 8],
            ['name' => 'Reyes Store', 'address' => 'Market Rd', 'contact' => '+639185550101', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 8],
            ['name' => 'Dela Cruz Residence', 'address' => 'Phase 2 B12', 'contact' => '+639203334455', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 9],
            ['name' => 'Aqua Office', 'address' => 'Industrial Park', 'contact' => '+639197778899', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 7],
            ['name' => 'Mendoza Canteen', 'address' => 'School Ave', 'contact' => '+639301234567', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 10],
            ['name' => 'Torres Apartment (6 units)', 'address' => 'Block 9 Lot 3', 'contact' => '+639312223344', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 9],
            ['name' => 'Walk-in Guest', 'address' => '-', 'contact' => '-', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => null],
            ['name' => 'Garcia Laundry Shop', 'address' => 'Riverside St', 'contact' => '+639324445566', 'issued_slim' => 0, 'returned_slim' => 0, 'issued_round' => 0, 'returned_round' => 0, 'debt_balance' => 0, 'total_transactions' => 0, 'days_ago' => 8],
        ];

        foreach ($customers as $customer) {
            $daysAgo = $customer['days_ago'];
            unset($customer['days_ago']);

            $isWalkIn = $customer['name'] === 'Walk-in Guest';
            $match = $isWalkIn
                ? ['system_key' => 'walk-in']
                : ['name' => $customer['name']];

            if ($isWalkIn && ! Customer::query()->where('system_key', 'walk-in')->exists()) {
                $match = ['name' => 'Walk-in Guest'];
            }

            Customer::query()->updateOrCreate(
                $match,
                $customer + ['system_key' => $isWalkIn ? 'walk-in' : null, 'last_visit' => $daysAgo === null ? null : now()->subDays($daysAgo)]
            );
        }
    }
}
