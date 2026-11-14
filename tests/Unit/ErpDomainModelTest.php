<?php

namespace Tests\Unit;

use App\Data\CashFlow;
use App\Data\CustomerStatementLedger;
use App\Data\FinancialPeriod;
use App\Data\OperatingMargin;
use App\Data\StatementLine;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ErpDomainModelTest extends TestCase
{
    public function test_purchase_order_predicates_and_state_transitions(): void
    {
        $po = (new PurchaseOrder)->forceFill(['status' => PurchaseOrder::DRAFT, 'created_by' => 5]);

        $this->assertTrue($po->isDraft());
        $this->assertFalse($po->isOrdered());
        $this->assertFalse($po->isPartiallyReceived());
        $this->assertFalse($po->isReceived());
        $this->assertFalse($po->isCancelled());
        $this->assertTrue($po->canBeEdited());
        $this->assertFalse($po->canReceiveStock());
        $this->assertTrue($po->canBeCancelled());

        // Cancel permission checks:
        $creator = (new User)->forceFill(['id' => 5, 'role' => User::ROLE_ADMIN]);
        $otherUser = (new User)->forceFill(['id' => 9, 'role' => User::ROLE_ADMIN]);
        $owner = (new User)->forceFill(['id' => 1, 'role' => User::ROLE_SUPER_ADMIN]);

        $this->assertTrue($po->canBeCancelledBy($creator));
        $this->assertFalse($po->canBeCancelledBy($otherUser));
        $this->assertTrue($po->canBeCancelledBy($owner));

        // Mark ordered:
        $po->markAsOrdered();
        $this->assertSame(PurchaseOrder::ORDERED, $po->status);
        $this->assertFalse($po->canBeEdited());
        $this->assertTrue($po->canReceiveStock());
        $this->assertTrue($po->canBeCancelled());

        // For ordered PO, only owner can cancel:
        $this->assertFalse($po->canBeCancelledBy($creator));
        $this->assertTrue($po->canBeCancelledBy($owner));

        // Attempting to mark ordered again throws ValidationException:
        $this->expectException(ValidationException::class);
        $po->markAsOrdered();
    }

    public function test_purchase_order_line_calculations_and_receipt(): void
    {
        $line = new PurchaseOrderLine([
            'qty_ordered' => 20,
            'qty_received' => 5,
            'unit_cost' => '12.5000',
        ]);

        $this->assertSame(15, $line->remainingQuantity());
        $this->assertFalse($line->isFullyReceived());
        $this->assertEquals(250.0, $line->lineTotal());

        $line->receiveQuantity(10);
        $this->assertSame(15, $line->qty_received);
        $this->assertSame(5, $line->remainingQuantity());
        $this->assertFalse($line->isFullyReceived());

        $line->receiveQuantity(5);
        $this->assertSame(20, $line->qty_received);
        $this->assertSame(0, $line->remainingQuantity());
        $this->assertTrue($line->isFullyReceived());

        // Receiving more than remaining throws ValidationException:
        $this->expectException(ValidationException::class);
        $line->receiveQuantity(1);
    }

    public function test_purchase_order_recalculate_from_lines(): void
    {
        $po = (new PurchaseOrder)->forceFill(['status' => PurchaseOrder::ORDERED]);
        $lineA = new PurchaseOrderLine(['qty_ordered' => 10, 'qty_received' => 10, 'unit_cost' => 5.0]);
        $lineB = new PurchaseOrderLine(['qty_ordered' => 20, 'qty_received' => 10, 'unit_cost' => 3.0]);

        $po->recalculateFromLines([$lineA, $lineB]);
        $this->assertSame(PurchaseOrder::PARTIAL, $po->status);
        $this->assertEquals(110.0, (float) $po->total_cost);
        $this->assertNull($po->received_at);

        $lineB->qty_received = 20;
        $po->recalculateFromLines([$lineA, $lineB]);
        $this->assertSame(PurchaseOrder::RECEIVED, $po->status);
        $this->assertNotNull($po->received_at);
    }

    public function test_expense_domain_methods_and_snapshot(): void
    {
        $expense = new Expense([
            'spent_on' => '2026-11-10',
            'category' => 'utilities',
            'amount' => '150.75',
            'payee' => 'Meralco',
            'reference' => 'INV-01',
            'notes' => 'Electric bill',
        ]);

        $this->assertTrue($expense->isActive());
        $this->assertFalse($expense->isVoided());
        $this->assertSame('Utilities', $expense->categoryLabel());
        $this->assertSame(15075, $expense->amountInCents());

        $owner = (new User)->forceFill(['id' => 1, 'role' => User::ROLE_SUPER_ADMIN]);
        $manager = (new User)->forceFill(['id' => 2, 'role' => User::ROLE_ADMIN]);

        $this->assertTrue($expense->canBeVoidedBy($owner));
        $this->assertFalse($expense->canBeVoidedBy($manager));

        $snapshot = $expense->toAuditSnapshot();
        $this->assertSame('utilities', $snapshot['category']);
        $this->assertEquals(150.75, $snapshot['amount']);
        $this->assertFalse($snapshot['voided']);

        $expense->void($owner, 'Duplicate entry');
        $this->assertTrue($expense->isVoided());
        $this->assertSame('Duplicate entry', $expense->void_reason);
        $this->assertSame(1, $expense->voided_by);
        $this->assertFalse($expense->canBeVoidedBy($owner));
    }

    public function test_customer_statement_ledger_accumulation(): void
    {
        $customer = (new Customer)->forceFill(['id' => 10, 'name' => 'Cruz', 'address' => 'Pasig', 'contact' => '09171234567']);
        $from = CarbonImmutable::parse('2026-11-01');
        $to = CarbonImmutable::parse('2026-11-30');

        $ledger = new CustomerStatementLedger(
            customer: $customer,
            from: $from,
            to: $to,
            openingBalanceCents: 5000, // 50.00 opening balance
            currentBalanceCents: 8500, // 85.00 live debt
        );

        $this->assertEquals(50.0, $ledger->openingBalance());

        // Add a sale of 60.00 (6000 cents) on account
        $ledger->recordEntry('2026-11-05', '10:00:00', 'REC-001', 'Delivery on account', 6000, false);
        $this->assertEquals(110.0, $ledger->closingBalance());
        $this->assertEquals(60.0, $ledger->totalCharges());
        $this->assertEquals(0.0, $ledger->totalPayments());

        // Add a debt payment of 25.00 (2500 cents)
        $ledger->recordEntry('2026-11-12', '14:30:00', 'REC-002', 'Payment received', 2500, true);
        $this->assertEquals(85.0, $ledger->closingBalance());
        $this->assertEquals(60.0, $ledger->totalCharges());
        $this->assertEquals(25.0, $ledger->totalPayments());

        $payload = $ledger->toArray();
        $this->assertSame(10, $payload['customer']['id']);
        $this->assertEquals(50.0, $payload['opening_balance']);
        $this->assertEquals(85.0, $payload['closing_balance']);
        $this->assertEquals(85.0, $payload['current_balance']);
        $this->assertCount(2, $payload['lines']);
        $this->assertEquals(60.0, $payload['lines'][0]['charge']);
        $this->assertEquals(0.0, $payload['lines'][0]['payment']);
        $this->assertEquals(110.0, $payload['lines'][0]['balance_after']);
        $this->assertEquals(0.0, $payload['lines'][1]['charge']);
        $this->assertEquals(25.0, $payload['lines'][1]['payment']);
        $this->assertEquals(85.0, $payload['lines'][1]['balance_after']);
    }

    public function test_operating_margin_and_cash_flow_value_objects(): void
    {
        $margin = new OperatingMargin(
            netSalesCents: 100000,      // 1,000.00
            costOfSuppliesCents: 25000, // 250.00
            expenseCents: 15000         // 150.00
        );

        $this->assertSame(60000, $margin->valueInCents());
        $this->assertSame('600.00', $margin->formattedValue());
        $this->assertStringContainsString('Net sales', $margin->formula());
        $this->assertSame('600.00', $margin->toArray()['value']);

        // Negative margin
        $negativeMargin = new OperatingMargin(10000, 20000, 5000);
        $this->assertSame(-15000, $negativeMargin->valueInCents());
        $this->assertSame('-150.00', $negativeMargin->formattedValue());

        $cashFlow = new CashFlow(
            collectionsTotalCents: 90000,   // 900.00
            purchasesReceivedCents: 30000,  // 300.00
            expenseCents: 15000             // 150.00
        );

        $this->assertSame(45000, $cashFlow->valueInCents());
        $this->assertSame('450.00', $cashFlow->formattedValue());
        $this->assertStringContainsString('Cash collected', $cashFlow->formula());

        $period = new FinancialPeriod(
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-11-07'),
            'Asia/Manila'
        );
        $this->assertSame(7, $period->days());
        $this->assertSame('Asia/Manila', $period->timezone);
    }

    public function test_purchase_order_data_and_receipt_dto(): void
    {
        $payload = [
            'supplier_id' => 3,
            'expected_date' => '2026-12-15',
            'notes' => '  Urgent caps  ',
            'lines' => [
                ['inventory_item_id' => 12, 'qty' => 50, 'unit_cost' => 5.25],
            ],
        ];

        $dto = \App\Data\PurchaseOrderData::fromArray($payload);
        $this->assertSame(3, $dto->supplierId);
        $this->assertSame('2026-12-15', $dto->expectedDate);
        $this->assertSame('Urgent caps', $dto->notes);
        $this->assertCount(1, $dto->lines);

        $receiptPayload = [
            'submission_key' => '4b611e03-7cb7-45e7-a9c6-112233445566',
            'lines' => [
                ['line_id' => 20, 'qty' => 25],
                ['line_id' => 10, 'qty' => 10],
            ],
        ];
        $receiptDto = \App\Data\ReceivePurchaseOrderData::fromArray($receiptPayload);
        $this->assertSame('4b611e03-7cb7-45e7-a9c6-112233445566', $receiptDto->submissionKey);
        // Lines are sorted deterministically by line_id:
        $this->assertSame(10, $receiptDto->lines[0]['line_id']);
        $this->assertSame(20, $receiptDto->lines[1]['line_id']);

        $hashA = $receiptDto->computeRequestHash(100);
        $hashB = $receiptDto->computeRequestHash(100);
        $this->assertSame($hashA, $hashB);
        $this->assertNotSame($hashA, $receiptDto->computeRequestHash(101));
    }

    public function test_expense_data_dto_and_hashing(): void
    {
        $payload = [
            'spent_on' => '2026-11-14',
            'category' => 'utilities',
            'amount' => 500.5,
            'payee' => '  Meralco  ',
            'reference' => '  REF-123  ',
            'notes' => '  Monthly bill  ',
            'submission_key' => '8a721b04-1234-4567-89ab-cdef01234567',
        ];

        $dto = \App\Data\ExpenseData::fromArray($payload);
        $this->assertSame('2026-11-14', $dto->spentOn);
        $this->assertSame('utilities', $dto->category);
        $this->assertSame(500.5, $dto->amount);
        $this->assertSame('Meralco', $dto->payee);
        $this->assertSame('REF-123', $dto->reference);
        $this->assertSame('Monthly bill', $dto->notes);
        $this->assertSame('8a721b04-1234-4567-89ab-cdef01234567', $dto->submissionKey);

        $attrs = $dto->toModelAttributes(recordedBy: 1, requestHash: 'dummy-hash');
        $this->assertSame(1, $attrs['recorded_by']);
        $this->assertSame('dummy-hash', $attrs['request_hash']);

        $hash = $dto->computeRequestHash();
        $this->assertSame(64, strlen($hash));
    }

    public function test_transaction_domain_methods_and_statement_description(): void
    {
        $sale = new \App\Models\Transaction([
            'order_type' => 'Delivery',
            'payment_method' => 'Account',
            'total_amount' => 150.0,
        ]);
        $this->assertFalse($sale->isVoided());
        $this->assertTrue($sale->isActive());
        $this->assertFalse($sale->isDebtPayment());
        $this->assertTrue($sale->isAccountSale());
        $this->assertFalse($sale->isCash());
        $this->assertFalse($sale->isEligiblePhysicalCash());

        $cashSale = new \App\Models\Transaction([
            'order_type' => 'Walk-in',
            'payment_method' => 'Cash',
            'total_amount' => 100.0,
        ]);
        $this->assertTrue($cashSale->isCash());
        $this->assertTrue($cashSale->isEligiblePhysicalCash());

        $payment = new \App\Models\Transaction([
            'order_type' => 'Debt Payment',
            'payment_method' => 'Cash',
            'total_amount' => 50.0,
        ]);
        $this->assertTrue($payment->isDebtPayment());
        $this->assertFalse($payment->isAccountSale());
        $this->assertSame('Payment received', $payment->describeForStatement());
    }

    public function test_inventory_item_suggested_reorder_quantity(): void
    {
        $item = new \App\Models\InventoryItem([
            'stock_on_hand' => 10,
            'reorder_point' => 15,
            'target_stock' => 50,
        ]);
        $this->assertTrue($item->needsReorder());
        $this->assertSame(40, $item->suggestedReorderQuantity());

        // Clamped to at least 1 even if stock exceeds target:
        $fullItem = new \App\Models\InventoryItem([
            'stock_on_hand' => 60,
            'reorder_point' => 15,
            'target_stock' => 50,
        ]);
        $this->assertFalse($fullItem->needsReorder());
        $this->assertSame(1, $fullItem->suggestedReorderQuantity());
    }
}
