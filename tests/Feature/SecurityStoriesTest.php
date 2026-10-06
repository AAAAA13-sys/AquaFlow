<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityStoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_u_s06_deactivated_users_lose_existing_sessions(): void
    {
        $user = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($user)->getJson('/api/bootstrap')->assertOk();
        $user->update(['is_active' => false]);
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_api_rate_limit_is_per_user_and_returns_retry_after(): void
    {
        config(['rate_limits.api' => 2]);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson('/api/settings')->assertOk();
        $this->getJson('/api/settings')->assertOk();
        $this->getJson('/api/settings')->assertStatus(429)->assertHeader('Retry-After');
        $this->actingAs(User::where('username', 'admin')->firstOrFail())->getJson('/api/settings')->assertOk();
    }

    public function test_u_s04_cashiers_cannot_modify_inventory(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->patchJson('/api/inventory/'.InventoryItem::value('id'), ['direction' => 1])->assertForbidden();
    }
}
