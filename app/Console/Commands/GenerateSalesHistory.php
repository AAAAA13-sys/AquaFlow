<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use App\Models\Transaction;
use App\Services\CheckoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generate a realistic sales history so every dashboard has something to show.
 *
 * The pattern (weekday baseline with a weekend uplift) is what the ARIMA engine
 * is expected to learn; the rest of the variety exists so the owner portal can
 * be reviewed against believable data:
 *
 *   - all four till products (two refills, two brand-new jugs)
 *   - Walk-in and Delivery orders
 *   - Cash, GCash and Charge-to-Account
 *   - periodic debt settlements, logged as `Debt Payment` rows
 *   - inclusive 12% VAT split on every sale
 *
 *     php artisan aquaflow:generate-history
 *     php artisan aquaflow:generate-history --days=120 --fresh
 */
class GenerateSalesHistory extends Command
{
    protected $signature = 'aquaflow:generate-history
        {--days=90 : Days of history to generate}
        {--fresh : Delete the existing generated history first}';

    protected $description = 'Generate a realistic sales history for dashboards and forecasting';

    /** Inclusive VAT, matching CheckoutService. */
    private const VAT_RATE = 0.12;

    private int $receipt = 0;

    public function handle(): int
    {
        $days = max(30, min(365, (int) $this->option('days')));

        $cashierId = (int) User::query()->where('role', User::ROLE_CASHIER)->orderBy('id')->value('id');
        $walkInId = (int) Customer::query()->where('name', 'Walk-in Guest')->value('id');

        /** @var list<int> $registered */
        $registered = Customer::query()
            ->where('name', '<>', 'Walk-in Guest')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($cashierId === 0 || $walkInId === 0 || $registered === []) {
            $this->error('Seed data missing: run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $removed = $this->purgeGeneratedHistory();

            $this->line("  Removed {$removed} previously generated transactions.");
        }

        $this->receipt = Transaction::nextReceiptSequence();
        $firstReceipt = $this->receipt;

        mt_srand(20260928); // deterministic demo data

        $sales = 0;
        $gallons = 0;
        $jugs = 0;
        $settlements = 0;

        DB::transaction(function () use ($days, $cashierId, $walkInId, $registered, &$sales, &$gallons, &$jugs, &$settlements): void {
            for ($offset = $days - 1; $offset >= 0; $offset--) {
                $date = now()->subDays($offset);
                $isWeekend = in_array((int) $date->dayOfWeekIso, [6, 7], true);

                $remaining = $isWeekend ? mt_rand(360, 470) : mt_rand(170, 260);

                while ($remaining > 0) {
                    $saleGallons = min($remaining, mt_rand(5, 45));
                    $remaining -= $saleGallons;

                    $slim = mt_rand(0, $saleGallons);
                    $round = $saleGallons - $slim;

                    if ($slim === 0 && $round === 0) {
                        continue;
                    }

                    $isWalkIn = mt_rand(1, 100) <= 18;
                    $customerId = $isWalkIn ? $walkInId : $registered[array_rand($registered)];
                    $orderType = (! $isWalkIn && mt_rand(1, 100) <= 45) ? 'Delivery' : 'Walk-in';
                    $payment = $this->pickPayment($orderType);

                    $total = round($saleGallons * 35.0, 2);

                    $this->insertSale(
                        cashierId: $cashierId,
                        customerId: $customerId,
                        orderType: $orderType,
                        payment: $payment,
                        total: $total,
                        volume: CheckoutService::volumeLabel($slim, $round),
                        date: $date->toDateString(),
                        time: sprintf('%02d:%02d:00', mt_rand(7, 18), mt_rand(0, 59)),
                        items: $this->refillItems($slim, $round),
                        walkIn: $isWalkIn,
                    );

                    $sales++;
                    $gallons += $saleGallons;
                }

                // Brand-new jugs are walk-in merchandise: a smaller, separate sale.
                if (mt_rand(1, 100) <= 28) {
                    $qtySlim = mt_rand(0, 2);
                    $qtyRound = mt_rand(0, 2);

                    if ($qtySlim + $qtyRound > 0) {
                        $items = [];
                        if ($qtySlim > 0) {
                            $items[] = ['product_id' => 'newS', 'item_name' => 'New Slim 5-Gal Jug', 'quantity' => $qtySlim, 'unit_price' => 250.00];
                        }
                        if ($qtyRound > 0) {
                            $items[] = ['product_id' => 'newR', 'item_name' => 'New Round 5-Gal Jug', 'quantity' => $qtyRound, 'unit_price' => 250.00];
                        }

                        $this->insertSale(
                            cashierId: $cashierId,
                            customerId: $walkInId,
                            orderType: 'Walk-in',
                            payment: 'Cash',
                            total: round(($qtySlim + $qtyRound) * 250.0, 2),
                            volume: '0 gal',
                            date: $date->toDateString(),
                            time: sprintf('%02d:%02d:00', mt_rand(7, 18), mt_rand(0, 59)),
                            items: $items,
                            walkIn: true,
                        );

                        $sales++;
                        $jugs += $qtySlim + $qtyRound;
                    }
                }

                // Periodic debt settlement against an outstanding balance.
                if (mt_rand(1, 100) <= 34 && $this->settleDebt($cashierId, $date->toDateString())) {
                    $settlements++;
                }
            }
        });

        $this->info(sprintf(
            'Generated %d sales (%d gallons, %d jugs, %d settlements) over %d days as %s..%s',
            $sales,
            $gallons,
            $jugs,
            $settlements,
            $days,
            'OR-' . $firstReceipt,
            'OR-' . ($this->receipt - 1),
        ));

        return self::SUCCESS;
    }

    /**
     * MSME rule: the order type decides payment. Walk-ins pay cash at the
     * counter; deliveries are charged to the customer's account.
     */
    private function pickPayment(string $orderType): string
    {
        return $orderType === 'Delivery' ? 'Account' : 'Cash';
    }

    /**
     * @param  list<array{product_id: string, item_name: string, quantity: int, unit_price: float}>  $items
     */
    private function insertSale(
        int $cashierId,
        int $customerId,
        string $orderType,
        string $payment,
        float $total,
        string $volume,
        string $date,
        string $time,
        array $items,
        bool $walkIn,
    ): void {
        $total = round($total, 2);

        if ($payment === 'Cash') {
            $tendered = (float) (ceil($total / 50) * 50);
            $change = round($tendered - $total, 2);
        } else {
            $tendered = 0.0;
            $change = 0.0;
        }

        // Prices are VAT inclusive: pull the tax out of the gross total.
        $vatable = round($total / (1 + self::VAT_RATE), 2);
        $vat = round($total - $vatable, 2);

        $transactionId = DB::table('transactions')->insertGetId([
            'receipt_number' => 'OR-' . $this->receipt,
            'customer_id' => $customerId,
            'cashier_id' => $cashierId,
            'order_type' => $orderType,
            'gallons_volume' => $volume,
            'subtotal_amount' => $vatable,
            'vat_amount' => $vat,
            'auto_discount' => 0,
            'manual_discount' => 0,
            'total_amount' => $total,
            'payment_method' => $payment,
            'cash_tendered' => $tendered,
            'cash_change' => $change,
            'transaction_date' => $date,
            'transaction_time' => $time,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'transaction_id' => $transactionId,
                'product_id' => $item['product_id'],
                'item_name' => $item['item_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => round($item['quantity'] * $item['unit_price'], 2),
            ];
        }

        DB::table('transaction_items')->insert($rows);

        if (! $walkIn) {
            DB::table('customers')->where('id', $customerId)->update(['last_visit' => $date]);
            DB::table('customers')->where('id', $customerId)->increment('total_transactions');
        }

        // Charging to account grows the receivable.
        if ($payment === 'Account') {
            DB::table('customers')
                ->where('id', $customerId)
                ->update(['debt_balance' => DB::raw('debt_balance + ' . $total)]);
        }

        $this->receipt++;
    }

    /**
     * Record a partial payment for a customer who owes something.
     */
    private function settleDebt(int $cashierId, string $date): bool
    {
        $debtor = DB::table('customers')
            ->where('debt_balance', '>', 0)
            ->where('name', '<>', 'Walk-in Guest')
            ->inRandomOrder()
            ->first(['id', 'debt_balance']);

        if ($debtor === null) {
            return false;
        }

        $balance = (float) $debtor->debt_balance;

        // Customers clear most of the tab when they pay, rounded to a real note.
        $share = mt_rand(45, 100) / 100;
        $paid = round(min($balance, max(50.0, $balance * $share)) / 50) * 50;

        if ($paid <= 0) {
            return false;
        }

        DB::table('customers')
            ->where('id', $debtor->id)
            ->update(['debt_balance' => DB::raw('GREATEST(0, debt_balance - ' . $paid . ')')]);

        DB::table('transactions')->insert([
            'receipt_number' => 'OR-' . $this->receipt,
            'customer_id' => $debtor->id,
            'cashier_id' => $cashierId,
            'order_type' => 'Debt Payment',
            'gallons_volume' => '0 gal',
            'subtotal_amount' => 0,
            'vat_amount' => 0,
            'auto_discount' => 0,
            'manual_discount' => 0,
            'total_amount' => $paid,
            'payment_method' => 'Cash',
            'cash_tendered' => $paid,
            'cash_change' => 0,
            'transaction_date' => $date,
            'transaction_time' => sprintf('%02d:%02d:00', mt_rand(7, 18), mt_rand(0, 59)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->receipt++;

        return true;
    }

    /**
     * @return list<array{product_id: string, item_name: string, quantity: int, unit_price: float}>
     */
    private function refillItems(int $slim, int $round): array
    {
        $items = [];

        if ($slim > 0) {
            $items[] = ['product_id' => 'slim', 'item_name' => 'Slim 5-Gal Refill', 'quantity' => $slim, 'unit_price' => 35.00];
        }
        if ($round > 0) {
            $items[] = ['product_id' => 'round', 'item_name' => 'Round 5-Gal Refill', 'quantity' => $round, 'unit_price' => 35.00];
        }

        return $items;
    }

    /**
     * Remove the rows this command produces, leaving anything hand-entered.
     */
    private function purgeGeneratedHistory(): int
    {
        $ids = DB::table('transactions')
            ->whereIn('order_type', ['Walk-in', 'Delivery', 'Debt Payment'])
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        DB::table('transaction_items')->whereIn('transaction_id', $ids)->delete();
        DB::table('container_custody_logs')->whereIn('transaction_id', $ids)->delete();

        return DB::table('transactions')->whereIn('id', $ids)->delete();
    }
}
