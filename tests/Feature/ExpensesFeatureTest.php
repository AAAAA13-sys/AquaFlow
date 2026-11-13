<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpensesFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('username', 'admin')->firstOrFail();
        $this->manager = User::where('username', 'manager')->firstOrFail();
        $this->cashier = User::where('username', 'cashier')->firstOrFail();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    private function payload(array $override = []): array
    {
        return array_replace([
            'submission_key' => (string) Str::uuid(),
            'spent_on' => $this->today()->toDateString(),
            'category' => 'utilities',
            'amount' => 1250.5,
            'payee' => 'Meralco',
            'reference' => 'INV-100',
            'notes' => 'October bill',
        ], $override);
    }

    private function record(array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->postJson('/api/expenses', $this->payload($override));
    }

    public function test_manager_records_expense_and_it_is_audited(): void
    {
        $response = $this->record();
        $response->assertCreated()
            ->assertJsonPath('expense.category', 'utilities')
            ->assertJsonPath('expense.category_label', 'Utilities')
            ->assertJsonPath('expense.amount', 1250.5)
            ->assertJsonPath('expense.recorded_by.id', $this->manager->id)
            ->assertJsonPath('expense.voided_at', null);

        $id = $response->json('expense.id');
        $log = AuditLog::where('entity_type', 'expense')->where('entity_id', (string) $id)->firstOrFail();
        $this->assertSame('expense_create', $log->action);
        $this->assertSame($this->manager->id, (int) $log->actor_id);
    }

    public function test_retry_with_same_key_does_not_duplicate(): void
    {
        $payload = $this->payload();
        $first = $this->actingAs($this->manager)->postJson('/api/expenses', $payload);
        $first->assertCreated();
        $second = $this->actingAs($this->manager)->postJson('/api/expenses', $payload);
        $second->assertCreated();

        $this->assertSame($first->json('expense.id'), $second->json('expense.id'));
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, AuditLog::where('entity_type', 'expense')->count());

        $this->actingAs($this->manager)->postJson('/api/expenses', array_replace($payload, ['amount' => 99]))
            ->assertStatus(422)->assertJsonValidationErrors('submission_key');
        $this->assertSame(1, Expense::count());
    }

    public function test_future_date_and_non_positive_amount_rejected(): void
    {
        $this->record(['spent_on' => $this->today()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('spent_on');
        $this->record(['spent_on' => $this->today()->subDays(367)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('spent_on');
        $this->record(['spent_on' => $this->today()->subDays(366)->toDateString()])->assertCreated();
        $this->record(['amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->record(['amount' => -5])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->record(['amount' => 1.234])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->record(['amount' => 10000000000])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->record(['category' => 'bogus'])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->record(['submission_key' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('submission_key');
        $this->assertSame(1, Expense::count());
    }

    public function test_cashier_cannot_access_expenses(): void
    {
        $this->actingAs($this->cashier)->getJson('/api/expenses')->assertForbidden();
        $this->record([], $this->cashier)->assertForbidden();
        $id = $this->record()->json('expense.id');
        $this->actingAs($this->cashier)->postJson("/api/expenses/{$id}/void", ['reason' => 'Mistake'])->assertForbidden();
    }

    public function test_only_owner_can_void_with_reason(): void
    {
        $id = $this->record()->json('expense.id');

        $this->actingAs($this->manager)->postJson("/api/expenses/{$id}/void", ['reason' => 'Mistake entry'])->assertForbidden();
        $this->assertNull(Expense::find($id)->voided_at);

        $this->actingAs($this->owner)->postJson("/api/expenses/{$id}/void", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($this->owner)->postJson("/api/expenses/{$id}/void", ['reason' => 'ab'])->assertStatus(422);

        $this->actingAs($this->owner)->postJson("/api/expenses/{$id}/void", ['reason' => 'Duplicate entry'])
            ->assertOk()
            ->assertJsonPath('expense.void_reason', 'Duplicate entry')
            ->assertJsonPath('expense.voided_by.id', $this->owner->id);

        $this->assertNotNull(Expense::find($id)->voided_at);
        $this->assertSame(['expense_create', 'expense_void'],
            AuditLog::where('entity_type', 'expense')->where('entity_id', (string) $id)->orderBy('id')->pluck('action')->all());
    }

    public function test_voiding_twice_is_rejected(): void
    {
        $id = $this->record()->json('expense.id');
        $this->actingAs($this->owner)->postJson("/api/expenses/{$id}/void", ['reason' => 'Duplicate entry'])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/expenses/{$id}/void", ['reason' => 'Again please'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(1, AuditLog::where('action', 'expense_void')->count());
    }

    public function test_totals_exclude_voided_and_respect_range_and_category(): void
    {
        $today = $this->today();
        $this->record(['category' => 'utilities', 'amount' => 100.10, 'spent_on' => $today->toDateString()]);
        $this->record(['category' => 'utilities', 'amount' => 50, 'spent_on' => $today->subDays(3)->toDateString()]);
        $this->record(['category' => 'rent', 'amount' => 700, 'spent_on' => $today->subDays(2)->toDateString()]);
        $v = $this->record(['category' => 'rent', 'amount' => 999, 'spent_on' => $today->toDateString()])->json('expense.id');
        $this->record(['category' => 'transport', 'amount' => 20, 'spent_on' => $today->subDays(10)->toDateString()]);
        $this->actingAs($this->owner)->postJson("/api/expenses/{$v}/void", ['reason' => 'Wrong amount'])->assertOk();

        $from = $today->subDays(5)->toDateString();
        $to = $today->toDateString();
        $res = $this->actingAs($this->manager)->getJson("/api/expenses?from={$from}&to={$to}");
        $res->assertOk();
        $this->assertCount(4, $res->json('expenses')); // the voided row stays visible
        $this->assertEquals(850.10, $res->json('totals.total'));
        $this->assertEquals(150.10, $res->json('totals.by_category.utilities'));
        $this->assertEquals(700, $res->json('totals.by_category.rent'));
        $this->assertEquals(0, $res->json('totals.by_category.transport'));
        $this->assertCount(8, $res->json('totals.by_category'));

        $cat = $this->actingAs($this->manager)->getJson("/api/expenses?category=utilities&from={$from}&to={$to}");
        $this->assertCount(2, $cat->json('expenses'));
        $this->assertEquals(150.10, $cat->json('totals.total'));

        // Bounds are inclusive: a single-day range returns that day only.
        $day = $today->subDays(3)->toDateString();
        $one = $this->actingAs($this->manager)->getJson("/api/expenses?from={$day}&to={$day}");
        $this->assertCount(1, $one->json('expenses'));

        $yesterday = $today->subDay()->toDateString();
        $this->actingAs($this->manager)->getJson("/api/expenses?from={$to}&to={$yesterday}")->assertStatus(422);

        $totals = app(ExpenseService::class)->totalsBetween($today->subDays(5), $today);
        $this->assertSame('850.10', $totals['total']);
        $this->assertSame('150.10', $totals['by_category']['utilities']);
        $this->assertSame('0.00', $totals['by_category']['permits']);
        $this->assertSame(['utilities', 'rent', 'salaries', 'supplies', 'maintenance', 'transport', 'permits', 'other'],
            array_keys($totals['by_category']));
    }

    public function test_expenses_page_renders_for_management_only(): void
    {
        $this->actingAs($this->owner)->get('/admin/expenses')->assertOk()->assertSee('Expenses')->assertSee('js/admin/expenses.js', false);
        $this->actingAs($this->manager)->get('/admin/expenses')->assertOk();
        $this->assertContains($this->actingAs($this->cashier)->get('/admin/expenses')->getStatusCode(), [302, 403]);
    }
}
