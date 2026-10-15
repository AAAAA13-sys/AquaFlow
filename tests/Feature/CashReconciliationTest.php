<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_reconciliation_page_and_endpoints_are_removed(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'StrongPass123', 'role' => 'admin']);
        $this->actingAs($owner)->get('/admin/cash')->assertNotFound();
        $this->getJson('/api/cash-shift')->assertNotFound();
        $this->getJson('/api/cash-shift/overview')->assertNotFound();
        $this->postJson('/api/cash-shift/open', ['opening_float' => 100])->assertNotFound();
        $this->postJson('/api/cash-shift/close', ['counted_cash' => 100])->assertNotFound();
    }
}
