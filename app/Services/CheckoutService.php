<?php

namespace App\Services;

use App\Data\CheckoutData;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductionQueueItem;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS checkout.
 *
 * Money: prices come from the catalog and 12% VAT is INCLUSIVE - the shelf price
 * is the gross amount, so the vatable sales and the tax portion are derived from
 * it. There are no discounts.
 *
 * Payment (MSME rule): order type decides it. A WALK-IN pays CASH at the
 * counter, a DELIVERY is CHARGED TO THE CUSTOMER'S ACCOUNT. The cashier never
 * chooses, and any client-supplied payment method is ignored.
 *
 * Containers: under the Zero Station-Owned Jugs rule the station lends nothing,
 * so there is no custody debt. A refill is the customer's own jug coming back
 * filled; a brand-new jug is ordinary merchandise that depletes jug stock and is
 * restricted to walk-in sales.
 *
 * Everything a sale implies happens in one database transaction.
 */
class CheckoutService
{
    /** Philippine VAT, inclusive of the displayed price. */
    public const VAT_RATE = 0.12;

    public const PAYMENT_CASH = 'Cash';

    public const PAYMENT_ACCOUNT = 'Account';

    public function __construct(
        private readonly InventoryEngine $inventory,
    ) {}

    /** Order type determines payment: walk-in is cash, delivery is on account. */
    public static function paymentFor(string $orderType): string
    {
        return $orderType === 'Delivery' ? self::PAYMENT_ACCOUNT : self::PAYMENT_CASH;
    }

    /**
     * @return array{transaction: Transaction, customer: Customer, totals: array<string,mixed>}
     *
     * @throws ValidationException
     */
    public function execute(CheckoutData $data, User $cashier): array
    {
        return DB::transaction(function () use ($data, $cashier): array {
            $catalog = $this->loadCatalog($data->items);
            $pricing = $this->price($data, $catalog);
            $customer = Customer::query()->lockForUpdate()->find($data->customerId);

            if ($customer === null) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Customer not found.',
                ]);
            }

            $this->assertCustomerRules($customer, $data, $catalog);

            $transaction = $this->createTransaction($data, $cashier, $pricing, $customer);
            $this->createItems($transaction, $pricing['lines']);
            $this->updateCustomerLedger($customer, $pricing);
            $this->deductStock($catalog, $transaction, $cashier);
            $this->queueForProduction($transaction, $customer, $data, $pricing);

            return [
                'transaction' => $transaction,
                'customer' => $customer->refresh(),
                'totals' => $pricing,
            ];
        });
    }

    // Catalog & Price Engine

    /**
     * @param  list<array{product_id: string, quantity: int}>  $items
     * @return Collection<string, Product>
     */
    private function loadCatalog(array $items): Collection
    {
        $requested = [];
        foreach ($items as $item) {
            $requested[$item['product_id']] = ($requested[$item['product_id']] ?? 0) + $item['quantity'];
        }

        $products = Product::query()
            ->soldAtPos()
            ->whereIn('id', array_keys($requested))
            ->orderBy('id')->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach (array_keys($requested) as $id) {
            if (! $products->has($id)) {
                throw ValidationException::withMessages([
                    'items' => "This item is not sold at the POS: {$id}.",
                ]);
            }
        }

        return $products->map(function (Product $product) use ($requested): Product {
            $product->setAttribute('requested_quantity', $requested[$product->id]);

            return $product;
        });
    }

    /**
     * Gross prices are the shelf prices; VAT is extracted from them.
     *
     * @param  Collection<string, Product>  $catalog
     * @return array<string,mixed>
     */
    private function price(CheckoutData $data, Collection $catalog): array
    {
        $gross = 0.0;
        $gallons = 0;
        $slimGallons = 0;
        $roundGallons = 0;
        $lines = [];

        foreach ($catalog as $product) {
            $quantity = (int) $product->getAttribute('requested_quantity');
            $lineTotal = round($product->price * $quantity, 2);

            $lines[] = [
                'product_id' => $product->id,
                'item_name' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $product->price,
                'line_total' => $lineTotal,
            ];

            $gross = round($gross + $lineTotal, 2);

            if ($product->isRefill()) {
                $gallons += $quantity;
                $slimGallons += $product->container_kind === 'S' ? $quantity : 0;
                $roundGallons += $product->container_kind === 'R' ? $quantity : 0;
            }
        }

        // Inclusive VAT: pull the tax out of the gross amount.
        $vatable = round($gross / (1 + self::VAT_RATE), 2);
        $vat = round($gross - $vatable, 2);

        $payment = self::paymentFor($data->orderType);
        $isCash = $payment === self::PAYMENT_CASH;

        if ($isCash && $data->cashTendered + 0.0001 < $gross) {
            throw ValidationException::withMessages([
                'cash_tendered' => 'Tendered amount is less than the total due.',
            ]);
        }

        $change = $isCash ? round(max(0.0, $data->cashTendered - $gross), 2) : 0.0;

        return [
            'lines' => $lines,
            'payment_method' => $payment,
            'vatable' => $vatable,
            'vat' => $vat,
            'total' => $gross,
            'cash_tendered' => $isCash ? $data->cashTendered : 0.0,
            'cash_change' => $change,
            'gallons' => $gallons,
            'slim_volume' => $slimGallons,
            'round_volume' => $roundGallons,
            'volume' => self::volumeLabel($slimGallons, $roundGallons),
            'units' => array_sum(array_map(
                static fn (Product $product): int => (int) $product->getAttribute('requested_quantity'),
                $catalog->all()
            )),
        ];
    }

    public static function volumeLabel(int $slim, int $round): string
    {
        $parts = [];
        if ($slim > 0) {
            $parts[] = $slim.'S';
        }
        if ($round > 0) {
            $parts[] = $round.'R';
        }

        return $parts === [] ? '0 gal' : implode('+', $parts);
    }

    // Validation Rules

    /**
     * @param  Collection<string, Product>  $catalog
     */
    private function assertCustomerRules(Customer $customer, CheckoutData $data, Collection $catalog): void
    {
        $isWalkIn = $customer->name === 'Walk-in Guest';

        // A delivery is charged to the account, so it needs a real ledger to
        // charge against. (A walk-in always pays cash, so it can never accrue.)
        if ($data->orderType === 'Delivery' && $isWalkIn) {
            throw ValidationException::withMessages([
                'order_type' => 'Delivery requires a registered customer.',
            ]);
        }

        // A brand-new jug is taken away over the counter, so it cannot be
        // attached to a delivery order.
        if ($data->orderType === 'Delivery') {
            $blocked = $catalog
                ->filter(fn (Product $product): bool => $product->walk_in_only)
                ->pluck('name')
                ->all();

            if ($blocked !== []) {
                throw ValidationException::withMessages([
                    'items' => 'Walk-in only: '.implode(', ', $blocked).'. New containers cannot be delivered.',
                ]);
            }
        }
    }

    // Transaction & Items Persistence

    /**
     * @param  array<string,mixed>  $pricing
     */
    private function createTransaction(CheckoutData $data, User $cashier, array $pricing, Customer $customer): Transaction
    {
        return Transaction::query()->create([
            'receipt_number' => 'OR-'.Transaction::nextReceiptSequence(),
            'customer_id' => $data->customerId,
            'cashier_id' => $cashier->id,
            'order_type' => $data->orderType,
            'gallons_volume' => $pricing['volume'],
            // Vatable sales + the extracted tax together equal the gross total.
            'subtotal_amount' => $pricing['vatable'],
            'vat_amount' => $pricing['vat'],
            'auto_discount' => 0,
            'manual_discount' => 0,
            'total_amount' => $pricing['total'],
            'payment_method' => $pricing['payment_method'],
            'cash_tendered' => $pricing['cash_tendered'],
            'cash_change' => $pricing['cash_change'],
            'balance_after' => $customer->debt_balance + ($data->orderType === 'Delivery' ? $pricing['total'] : 0),
            'payment_status' => $data->orderType === 'Delivery' ? 'unpaid' : 'paid',
            'delivery_address' => $data->orderType === 'Delivery' ? $customer->address : null,
            'transaction_date' => now()->toDateString(),
            'transaction_time' => now()->toTimeString(),
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     */
    private function createItems(Transaction $transaction, array $lines): void
    {
        foreach ($lines as $line) {
            TransactionItem::query()->create(['transaction_id' => $transaction->id] + $line);
        }
    }

    // Customer Ledger

    /**
     * @param  array<string,mixed>  $pricing
     */
    private function updateCustomerLedger(Customer $customer, array $pricing): void
    {
        // No container counters are touched: the station lends nothing, so a
        // refill creates no liability and a jug purchase creates no deposit.
        $customer->total_transactions += 1;
        $customer->last_visit = now()->toDateString();

        if ($pricing['payment_method'] === self::PAYMENT_ACCOUNT) {
            $customer->debt_balance += $pricing['total'];
        }

        $customer->save();
    }

    // Inventory

    /**
     * Deplete the stock each sold product consumes.
     *
     * Linked items handle themselves (a caps pack consumes 50 pieces, a new jug
     * consumes one jug), and every refilled gallon additionally consumes one cap
     * and one seal.
     *
     * @param  Collection<string, Product>  $catalog
     */
    private function deductStock(Collection $catalog, Transaction $transaction, User $cashier): void
    {
        $requirements = [];
        $recipes = DB::table('product_consumables')->whereIn('product_id', $catalog->keys())->get();
        foreach ($recipes as $recipe) {
            $qty = (int) $catalog[$recipe->product_id]->getAttribute('requested_quantity') * $recipe->qty_per_sale;
            $requirements[$recipe->inventory_item_id] = ($requirements[$recipe->inventory_item_id] ?? 0) + $qty;
        }
        foreach ($catalog as $product) {
            if (! $recipes->contains('product_id', $product->id)) {
                throw ValidationException::withMessages(['items' => 'No consumption recipe configured for '.$product->name.'.']);
            }
        }
        $stock = InventoryItem::whereIn('id', array_keys($requirements))->orderBy('id')->lockForUpdate()->get();
        foreach ($stock as $item) {
            $qty = $requirements[$item->id];
            if ($item->stock_on_hand < $qty) {
                $short = $qty - $item->stock_on_hand;
                throw ValidationException::withMessages(['items' => "Insufficient stock: {$item->item_name} is short by {$short} {$item->unit}."]);
            }
        }
        foreach ($stock as $item) {
            $qty = $requirements[$item->id];
            $item->stock_on_hand -= $qty;
            $item->save();
            StockMovement::create(['inventory_item_id' => $item->id, 'type' => 'sale',
                'qty' => -$qty, 'user_id' => $cashier->id, 'transaction_id' => $transaction->id,
                'notes' => 'Sale '.$transaction->receipt_number]);
            $this->inventory->recalculateItem($item);
        }
    }

    // Production Queue Dispatch

    /**
     * @param  array<string,mixed>  $pricing
     */
    private function queueForProduction(Transaction $transaction, Customer $customer, CheckoutData $data, array $pricing): void
    {
        if ($pricing['gallons'] <= 0) {
            return;
        }

        ProductionQueueItem::query()->create([
            'receipt_number' => $transaction->receipt_number,
            'customer_name' => $customer->name,
            'stage' => 0,
            'elapsed_minutes' => 0,
            'items_description' => $pricing['volume'],
            'order_type' => $data->orderType,
            'is_completed' => false,
        ]);
    }
}
