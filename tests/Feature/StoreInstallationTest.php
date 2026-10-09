<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use App\Services\StoreInstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreInstallationTest extends TestCase
{
    use RefreshDatabase;

    private function details(): array
    {
        return ['name' => 'Store Owner', 'username' => 'owner', 'password' => 'Store123', 'pin' => '2468'];
    }

    public function test_clean_install_has_no_demo_data_and_cannot_reset_an_existing_store(): void
    {
        app(StoreInstallationService::class)->install($this->details());
        $owner = User::sole();
        $this->assertTrue($owner->isAdmin());
        $this->assertTrue(Hash::check('Store123', $owner->password));
        $this->assertTrue(Hash::check('2468', $owner->pin));
        foreach (['transactions', 'customers', 'employees', 'suppliers', 'stock_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertGreaterThan(0, InventoryItem::count());
        $this->assertEquals(0, InventoryItem::sum('stock_on_hand'));
        $this->assertDatabaseCount('products', 7);
        $item = InventoryItem::first();
        $item->update(['stock_on_hand' => 12]);
        $this->assertFalse(app(StoreInstallationService::class)->install($this->details()));
        $this->assertSame(12, $item->fresh()->stock_on_hand);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_credentials_do_not_create_partial_catalog(): void
    {
        try {
            app(StoreInstallationService::class)->install(array_replace($this->details(), ['password' => '1234']));
            $this->fail('Weak password accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
    }
}
