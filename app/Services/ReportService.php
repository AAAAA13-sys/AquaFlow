<?php

namespace App\Services;

use App\Data\CashFlow;
use App\Data\FinancialPeriod;
use App\Data\OperatingMargin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Period report: sales, collections, cost of supplies, purchases, expenses
 * and the two profit figures (operating margin and cash flow).
 *
 * Read-only. Every amount is summed in SQL DECIMAL and returned from the
 * database as integer cents (ROUND(SUM(x) * 100)), so no float arithmetic
 * touches money before the final 2-decimal string.
 *
 * Definitions mirror the screens that already show these figures:
 *  - Revenue follows Sales History (admin/sales.js): transactions dated in the
 *    period, excluding voided rows (voided_at) and Debt Payment rows.
 *  - Collections follow CashReconciliationService::cashMovement(): every
 *    non-voided Cash transaction counts as money received, including Debt
 *    Payment rows; Account sales are not cash until they are collected.
 */
class ReportService
{
    public function __construct(private readonly ExpenseService $expenses) {}

    /**
     * @return array<string,mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        $period = new FinancialPeriod($from, $to, (string) config('app.timezone'));

        $sales = $this->sales($from, $to);
        $collections = $this->collections($from, $to);
        $cost = $this->costOfSupplies($from, $to);
        $purchases = $this->purchasesReceived($from, $to);
        $expenses = $this->expenses->totalsBetween($from, $to);
        $expenseCents = $this->toCents($expenses['total']);

        $operatingMargin = new OperatingMargin(
            netSalesCents: $sales['net_cents'],
            costOfSuppliesCents: $cost['total_cents'],
            expenseCents: $expenseCents,
        );

        $cashFlow = new CashFlow(
            collectionsTotalCents: $collections['total_cents'],
            purchasesReceivedCents: $purchases,
            expenseCents: $expenseCents,
        );

        return [
            'period' => $period->toArray(),
            'sales' => $sales['public'],
            'collections' => [
                'sales_cash' => $this->fmt($collections['sales_cash']),
                'debt_payments' => $this->fmt($collections['debt_payments']),
                'remittances' => $this->fmt($collections['remittances']),
                'total' => $this->fmt($collections['total_cents']),
            ],
            'cost_of_supplies' => [
                'total' => $this->fmt($cost['total_cents']),
                'items_without_cost' => $cost['items_without_cost'],
                'units_without_cost' => $cost['units_without_cost'],
                'uncosted_items' => $cost['uncosted_items'],
                'sales_without_consumption' => $this->salesWithoutConsumption($from, $to),
            ],
            'expenses' => $expenses,
            'purchases_received' => ['total' => $this->fmt($purchases)],
            'operating_margin' => $operatingMargin->toArray(),
            'cash_flow' => $cashFlow->toArray(),
        ];
    }

    // ---- Sales ------------------------------------------------------------

    /** Revenue rows, as Sales History counts them. */
    private function saleRows(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('transactions as t')
            ->whereNull('t.voided_at')
            ->where('t.order_type', '!=', 'Debt Payment')
            ->whereBetween('t.transaction_date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * @return array{public: array<string,mixed>, net_cents: int}
     */
    private function sales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = $this->saleRows($from, $to)->selectRaw(
            'COUNT(*) AS cnt, ROUND(COALESCE(SUM(t.total_amount), 0) * 100) AS gross,'
            .' ROUND(COALESCE(SUM(t.vat_amount), 0) * 100) AS vat,'
            .' ROUND(COALESCE(SUM(t.auto_discount + t.manual_discount), 0) * 100) AS discounts'
        )->first();

        $gross = (int) $totals->gross;
        $vat = (int) $totals->vat;
        // Prices are VAT-inclusive, so net is the gross with the tax taken out.
        $net = $gross - $vat;

        $group = fn (string $column) => $this->saleRows($from, $to)
            ->selectRaw("t.{$column} AS k, COUNT(*) AS cnt, ROUND(SUM(t.total_amount) * 100) AS gross, ROUND(SUM(t.vat_amount) * 100) AS vat")
            ->groupBy("t.{$column}")->orderByDesc('gross')->orderBy('k')->get();

        $byOrderType = $group('order_type')->map(fn ($r) => [
            'order_type' => $r->k, 'count' => (int) $r->cnt, 'gross' => $this->fmt((int) $r->gross),
            'net' => $this->fmt((int) $r->gross - (int) $r->vat),
        ])->values()->all();

        $byPayment = $group('payment_method')->map(fn ($r) => [
            'payment_method' => $r->k, 'count' => (int) $r->cnt, 'gross' => $this->fmt((int) $r->gross),
            'net' => $this->fmt((int) $r->gross - (int) $r->vat),
        ])->values()->all();

        $byCashier = $this->saleRows($from, $to)
            ->leftJoin('users as u', 'u.id', '=', 't.cashier_id')
            ->selectRaw('t.cashier_id, MAX(u.name) AS name, COUNT(*) AS cnt, ROUND(SUM(t.total_amount) * 100) AS gross, ROUND(SUM(t.vat_amount) * 100) AS vat')
            ->groupBy('t.cashier_id')->orderByDesc('gross')->orderBy('t.cashier_id')->get()
            ->map(fn ($r) => [
                'cashier_id' => (int) $r->cashier_id, 'name' => $r->name ?? 'Unknown', 'count' => (int) $r->cnt,
                'gross' => $this->fmt((int) $r->gross), 'net' => $this->fmt((int) $r->gross - (int) $r->vat),
            ])->values()->all();

        // Line totals are the VAT-inclusive receipt lines, so they add up to gross.
        $byProduct = $this->saleRows($from, $to)
            ->join('transaction_items as ti', 'ti.transaction_id', '=', 't.id')
            ->leftJoin('products as p', 'p.id', '=', 'ti.product_id')
            ->selectRaw('ti.product_id, COALESCE(MAX(p.name), MAX(ti.item_name)) AS name, SUM(ti.quantity) AS qty, ROUND(SUM(ti.line_total) * 100) AS revenue')
            ->groupBy('ti.product_id')->orderByDesc('revenue')->orderBy('ti.product_id')->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id, 'name' => $r->name, 'qty' => (int) $r->qty, 'revenue' => $this->fmt((int) $r->revenue),
            ])->values()->all();

        return [
            'net_cents' => $net,
            'public' => [
                'gross' => $this->fmt($gross),
                'vat' => $this->fmt($vat),
                'net' => $this->fmt($net),
                'discounts' => $this->fmt((int) $totals->discounts),
                'count' => (int) $totals->cnt,
                'by_order_type' => $byOrderType,
                'by_payment_method' => $byPayment,
                'by_cashier' => $byCashier,
                'by_product' => $byProduct,
            ],
        ];
    }

    // ---- Collections ------------------------------------------------------

    /**
     * Money received in the period. Same base as the cash drawer: every
     * non-voided Cash transaction dated in the period. A rider remittance is
     * itself written as a Debt Payment row (CustomerLedgerService::settle), so
     * the split uses delivery_remittances to count each peso exactly once.
     *
     * @return array{sales_cash:int, debt_payments:int, remittances:int, total_cents:int}
     */
    private function collections(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::table('transactions as t')
            ->leftJoin('delivery_remittances as r', 'r.transaction_id', '=', 't.id')
            ->whereNull('t.voided_at')
            ->where('t.payment_method', 'Cash')
            ->whereBetween('t.transaction_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(
                "ROUND(COALESCE(SUM(CASE WHEN t.order_type <> 'Debt Payment' THEN t.total_amount ELSE 0 END), 0) * 100) AS sales_cash,"
                ." ROUND(COALESCE(SUM(CASE WHEN t.order_type = 'Debt Payment' AND r.id IS NULL THEN t.total_amount ELSE 0 END), 0) * 100) AS debt_payments,"
                ." ROUND(COALESCE(SUM(CASE WHEN t.order_type = 'Debt Payment' AND r.id IS NOT NULL THEN t.total_amount ELSE 0 END), 0) * 100) AS remittances"
            )->first();

        $sales = (int) $row->sales_cash;
        $debt = (int) $row->debt_payments;
        $remit = (int) $row->remittances;

        return ['sales_cash' => $sales, 'debt_payments' => $debt, 'remittances' => $remit, 'total_cents' => $sales + $debt + $remit];
    }

    // ---- Cost of supplies consumed ---------------------------------------

    /**
     * Σ |qty| × unit cost over the period's `sale` stock movements. The unit
     * cost is the latest restock (PO or manual) with a cost recorded on or
     * before the movement. A voided sale's movements are left out: the void
     * writes compensating `adjustment` rows for the same transaction on the
     * same business day (VoidService::reverseSale), so the pair nets to zero.
     *
     * @return array{total_cents:int, items_without_cost:int, units_without_cost:int, uncosted_items:list<array<string,mixed>>}
     */
    private function costOfSupplies(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = $this->timestamps($from, $to);

        $costed = DB::table('stock_movements as sm')
            ->leftJoin('transactions as t', 't.id', '=', 'sm.transaction_id')
            ->where('sm.type', 'sale')
            ->whereBetween('sm.created_at', [$start, $end])
            ->whereNull('t.voided_at')
            ->selectRaw('sm.inventory_item_id, -sm.qty AS units, ('
                .'SELECT r.unit_cost FROM stock_movements r'
                .' WHERE r.inventory_item_id = sm.inventory_item_id AND r.type = \'restock\' AND r.unit_cost IS NOT NULL'
                .' AND (r.created_at < sm.created_at OR (r.created_at = sm.created_at AND r.id < sm.id))'
                .' ORDER BY r.created_at DESC, r.id DESC LIMIT 1) AS unit_cost');

        $rows = DB::query()->fromSub($costed, 'c')
            ->leftJoin('inventory as i', 'i.id', '=', 'c.inventory_item_id')
            ->selectRaw('c.inventory_item_id, MAX(i.item_name) AS name,'
                .' ROUND(COALESCE(SUM(CASE WHEN c.unit_cost IS NOT NULL THEN c.units * c.unit_cost END), 0) * 100) AS cost_cents,'
                .' COALESCE(SUM(CASE WHEN c.unit_cost IS NULL THEN c.units ELSE 0 END), 0) AS uncosted_units')
            ->groupBy('c.inventory_item_id')->orderBy('c.inventory_item_id')->get();

        // Round once over the exact sum rather than per item.
        $total = (int) DB::query()->fromSub($costed, 'c')
            ->selectRaw('ROUND(COALESCE(SUM(CASE WHEN c.unit_cost IS NOT NULL THEN c.units * c.unit_cost END), 0) * 100) AS cents')
            ->value('cents');

        $uncosted = $rows->filter(fn ($r) => (int) $r->uncosted_units > 0)
            ->map(fn ($r) => ['inventory_item_id' => (int) $r->inventory_item_id, 'name' => $r->name, 'units' => (int) $r->uncosted_units])
            ->values()->all();

        return [
            'total_cents' => $total,
            'items_without_cost' => count($uncosted),
            'units_without_cost' => array_sum(array_column($uncosted, 'units')),
            'uncosted_items' => $uncosted,
        ];
    }

    /**
     * Revenue sales that wrote no `sale` stock movement, so their supplies
     * were never costed. Checkout refuses a product without a consumption
     * recipe (CartAvailabilityService::requirements), so a real POS sale always
     * writes at least one movement; rows without one come from imported or
     * generated history. A sale none of whose products has a recipe consumes
     * nothing by definition and is not counted (judged on current recipes).
     */
    private function salesWithoutConsumption(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->saleRows($from, $to)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_movements as sm')
                ->whereColumn('sm.transaction_id', 't.id')->where('sm.type', 'sale'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('transaction_items as ti')
                ->join('product_consumables as pc', 'pc.product_id', '=', 'ti.product_id')
                ->whereColumn('ti.transaction_id', 't.id'))
            ->count();
    }

    // ---- Purchases --------------------------------------------------------

    /** Cost of purchase-order stock received in the period, from its restock movements. */
    private function purchasesReceived(CarbonImmutable $from, CarbonImmutable $to): int
    {
        [$start, $end] = $this->timestamps($from, $to);

        return (int) DB::table('stock_movements')
            ->where('type', 'restock')
            ->whereNotNull('purchase_order_id')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('ROUND(COALESCE(SUM(qty * COALESCE(unit_cost, 0)), 0) * 100) AS cents')
            ->value('cents');
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array{0:string,1:string} inclusive station-time timestamp bounds */
    private function timestamps(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = config('app.timezone');

        return [
            $from->setTimezone($tz)->startOfDay()->toDateTimeString(),
            $to->setTimezone($tz)->endOfDay()->toDateTimeString(),
        ];
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '0');
        $cents = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function fmt(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
