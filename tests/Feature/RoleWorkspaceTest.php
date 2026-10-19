<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsRole(string $role): void
    {
        $this->actingAs(User::create(['name' => 'Test User', 'username' => 'workspace_'.$role,
            'email' => $role.'@example.com', 'password' => 'Private123!', 'role' => $role, 'is_active' => true]));
    }

    public function test_owner_has_business_controls_and_cashier_return_link(): void
    {
        $this->loginAsRole('super_admin');
        $this->get('/admin/dashboard')->assertOk()->assertSee('Owner overview')->assertSee('Business controls');
        $this->get('/cashier/stocks')->assertOk()->assertSee('Owner Portal');
    }

    public function test_manager_is_not_shown_owner_setup_links(): void
    {
        $this->loginAsRole('admin');
        $this->get('/admin/dashboard')->assertOk()->assertDontSee('href="http://localhost/admin/settings"', false)
            ->assertDontSee('id="stepSettings"', false)->assertDontSee('Business controls');
        $this->get('/cashier/stocks')->assertOk()->assertSee('Manager Portal');
    }

    public function test_cashier_stock_page_is_read_only_and_has_no_planning_columns(): void
    {
        $this->loginAsRole('cashier');
        $this->get('/cashier/stocks')->assertOk()->assertSee('Stock Availability')
            ->assertDontSee('Safety Stock')->assertDontSee('Reorder Point')->assertDontSee('Owner Portal');
        $this->postJson('/api/inventory', [])->assertForbidden();
        $this->putJson('/api/settings', [])->assertForbidden();
    }

    public function test_manager_cannot_create_catalog_items(): void
    {
        $this->loginAsRole('admin');
        $this->postJson('/api/products', [])->assertForbidden();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_cashier_history_is_scoped_in_both_list_and_bootstrap(): void
    {
        $this->seed();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $owner = User::where('username', 'admin')->firstOrFail();
        $customer = \App\Models\Customer::firstOrCreate(['name' => 'History customer'], ['customer_type' => 'regular']);
        foreach ([$cashier, $owner] as $actor) {
            \App\Models\Transaction::create([
                'receipt_number' => 'ROLE-'.$actor->id, 'customer_id' => $customer->id,
                'cashier_id' => $actor->id, 'order_type' => 'Walk-in', 'payment_method' => 'Cash',
                'total_amount' => 50, 'transaction_date' => today(), 'transaction_time' => '10:00:00',
            ]);
        }
        $this->actingAs($cashier);
        foreach (['/api/transactions?paginated=1', '/api/bootstrap'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'transactions')
                ->assertDontSee('ROLE-'.$owner->id);
        }
        $this->getJson('/api/bootstrap')->assertJsonMissingPath('meta.sales_today_total');
        $this->actingAs($owner)->getJson('/api/transactions')->assertOk()->assertJsonCount(2, 'transactions');
    }

    public function test_catalog_writes_are_owner_only_and_manager_can_read_prices(): void
    {
        $this->seed();
        $product = \App\Models\Product::firstOrFail();
        $price = $product->price;
        $this->loginAsRole('admin');
        $this->get('/admin/inventory')->assertOk()->assertDontSee('id="tillFormPanel"', false);
        $this->get('/admin/orders')->assertOk()->assertSee('fulfillmentRows');
        $this->getJson('/api/products')->assertOk();
        $this->patchJson('/api/products/'.$product->id, ['price' => 123])->assertForbidden();
        $this->deleteJson('/api/products/'.$product->id)->assertForbidden();
        $this->putJson('/api/products/'.$product->id.'/recipe', [])->assertForbidden();
        $this->assertEquals($price, $product->fresh()->price);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->patchJson('/api/products/'.$product->id, ['price' => 123])->assertOk();
        $this->assertEquals(123, $product->fresh()->price);
        $this->get('/admin/inventory')->assertOk()->assertSee('id="tillFormPanel"', false);
    }

    public function test_cashier_cannot_complete_preparation_or_rider_work(): void
    {
        $this->loginAsRole('cashier');
        foreach (['Walk-in', 'Delivery'] as $type) {
            $item = \App\Models\ProductionQueueItem::create([
                'receipt_number' => $type, 'customer_name' => 'Test',
                'items_description' => '1 refill', 'order_type' => $type,
                'stage' => 0, 'is_completed' => false,
            ]);
            $item->forceFill(['fulfillment_status' => $type === 'Delivery' ? 'ready' : 'preparing'])->save();
            $this->postJson('/api/queue/'.$item->id.'/deliver')->assertStatus(409);
            $this->assertFalse($item->fresh()->is_completed);
            $this->assertNull($item->fresh()->delivered_at);
        }
    }
}
