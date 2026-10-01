<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::query()->where('username', 'cashier')->firstOrFail();
        $this->owner = User::query()->where('username', 'admin')->firstOrFail();
    }

    private function js(string $relative): string
    {
        return (string) file_get_contents(base_path('public/' . $relative));
    }

    public function test_guests_are_sent_to_the_right_login_screen(): void
    {
        $this->get('/cashier')->assertRedirect('/');
        $this->get('/cashier/customer-custody')->assertRedirect('/');
        $this->get('/cashier/queue')->assertRedirect('/');
        $this->get('/cashier/history')->assertRedirect('/');
        $this->get('/admin')->assertRedirect('/owner/login');
        $this->get('/admin/dashboard')->assertRedirect('/owner/login');
    }

    // ---------- The terminal is a three-stage POS ----------

    public function test_stage_one_is_customer_and_address_only(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertSee('Customer', false)
            ->assertSee('+ New Customer', false)
            ->assertSee('Settle Debt', false)
            ->assertSee('id="custSearch"', false)
            ->assertSee('id="custCard"', false)
            ->assertSee('id="custList"', false)
            // The spec removed phone capture from registration.
            ->assertDontSee('Contact Number', false)
            ->assertDontSee('regPhone', false);
    }

    public function test_order_type_moves_to_stage_two(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertSee('Order Type', false)
            ->assertSee("setType('Walk-in')", false)
            ->assertSee("setType('Delivery')", false);

        // Stage 1 must not offer an order type: it belongs to stage 2.
        $stageOne = explode('data-step="2"', $response->getContent())[0];
        $this->assertStringNotContainsString('setType(', $stageOne);
    }

    public function test_stage_two_lists_products_and_the_items_bought(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        // Tapping a tile must list the item on the same panel.
        $response->assertOk()
            ->assertSee('Select Items', false)
            ->assertSee('id="productGrid"', false)
            ->assertSee('Bought Items', false)
            ->assertSee('id="boughtItems"', false)
            ->assertSee('id="boughtTotal"', false)
            ->assertSee('Running Total', false);
    }

    public function test_the_cashier_never_enters_filled_out_or_returned_containers(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertDontSee('Empty Containers Returned', false)
            ->assertDontSee('Filled OUT', false)
            ->assertDontSee('Damaged / cracked bottle', false)
            ->assertDontSee('returns-table', false)
            ->assertDontSee('returns-row', false)
            ->assertDontSee('Container Shape', false);
    }

    public function test_new_jugs_are_sold_as_retail_stock_not_a_deposit(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            // Selling a jug is ordinary merchandise, not a custody deposit.
            ->assertDontSee('Deposit', false);

        $cart = $this->js('js/pos/cart.js');

        $this->assertStringContainsString('STOCKS: ', $cart);
        $this->assertStringContainsString('Walk-in only', $cart);
        $this->assertStringContainsString('For Sale - brand new', $cart);
    }

    public function test_stage_three_has_inclusive_vat_and_no_discounts(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertSee('Order Summary', false)
            ->assertSee('Vatable Sales', false)
            ->assertSee('12% VAT (inclusive)', false)
            ->assertSee('id="tTot"', false)
            ->assertSee('id="tVatable"', false)
            ->assertSee('id="tVat"', false)
            // Discounts and the old subtotal line are gone.
            ->assertDontSee('Auto Return Discount', false)
            ->assertDontSee('Discount', false)
            ->assertDontSee('Subtotal', false)
            ->assertDontSee('jugBanner', false);
    }

    public function test_stage_three_offers_cash_gcash_and_account(): void
    {
        $this->actingAs($this->cashier)->get('/cashier')
            ->assertOk()
            ->assertSee("setPay('Cash')", false)
            ->assertSee("setPay('GCash')", false)
            ->assertSee("setPay('Account')", false)
            ->assertSee('GCASH / QR', false)
            ->assertSee('id="gcashBox"', false)
            ->assertSee('Exact', false);
    }

    public function test_the_receipt_only_ever_prints_the_receipt(): void
    {
        $this->actingAs($this->cashier)->get('/cashier')
            ->assertOk()
            ->assertSee('printReceipt()', false)
            ->assertSee('id="rBody"', false);

        $css = (string) file_get_contents(base_path('public/css/styles.css'));
        $this->assertStringContainsString('printing-receipt', $css);
    }

    public function test_all_three_stages_ship_in_one_document(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk();

        // All stages ship in one document (no page reloads mid-sale); the
        // terminal displays one at a time.
        foreach (['data-step="1"', 'data-step="2"', 'data-step="3"'] as $stage) {
            $response->assertSee($stage, false);
        }
    }

    // ---------- The cashier sidebar ----------

    public function test_the_cashier_sidebar_links_the_three_pages(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertSee('POS Terminal', false)
            ->assertSee('Orders in Progress', false)
            ->assertSee('Sales &amp; Transactions', false)
            ->assertSee('/cashier/queue', false)
            ->assertSee('/cashier/history', false);
    }

    public function test_the_queue_page_lists_the_production_line(): void
    {
        $this->actingAs($this->cashier)->get('/cashier/queue')
            ->assertOk()
            ->assertSee('Orders in Progress &amp; Queue', false)
            ->assertSee('Today&rsquo;s Queue', false)
            ->assertSee('id="queue"', false)
            ->assertSee('id="stageGuide"', false)
            // The "Production Line" explainer banner was removed.
            ->assertDontSee('Production Line', false)
            ->assertDontSee('Advance each order', false);
    }

    public function test_the_history_page_lists_transactions_with_the_vat_split(): void
    {
        $this->actingAs($this->cashier)->get('/cashier/history')
            ->assertOk()
            ->assertSee('Sales &amp; Transaction History', false)
            ->assertSee('Vatable', false)
            ->assertSee('VAT', false)
            ->assertSee('id="hBody"', false)
            ->assertSee('id="hDate"', false)
            ->assertSee('Debt Payment', false);
    }

    public function test_each_sidebar_page_marks_itself_active(): void
    {
        $pages = [
            '/cashier' => 'POS Terminal',
            '/cashier/queue' => 'Orders in Progress &amp; Queue',
            '/cashier/history' => 'Sales &amp; Transaction History',
        ];

        foreach ($pages as $url => $marker) {
            $this->actingAs($this->cashier)->get($url)
                ->assertOk()
                ->assertSee($marker, false);
        }
    }

    public function test_the_cashier_has_no_route_to_the_owner_portal(): void
    {
        // The cashier sidebar used to link straight into the owner portal.
        foreach (['/cashier', '/cashier/queue', '/cashier/history'] as $url) {
            $this->actingAs($this->cashier)->get($url)
                ->assertOk()
                ->assertDontSee('Owner Portal', false)
                ->assertDontSee('/admin', false);
        }
    }

    public function test_the_cashier_subpages_have_no_back_to_pos_button(): void
    {
        foreach (['/cashier/queue', '/cashier/history'] as $url) {
            $this->actingAs($this->cashier)->get($url)
                ->assertOk()
                ->assertDontSee('Back to POS Terminal', false);
        }
    }

    public function test_each_stage_validates_its_content_before_advancing(): void
    {
        // The message slot lives with the stage buttons, so it is always in view
        // at the moment the cashier tries to move on.
        $this->actingAs($this->cashier)->get('/cashier')
            ->assertOk()
            ->assertSee('id="posStageError"', false)
            ->assertSee('role="alert"', false)
            ->assertSee('onclick="posNext()"', false);

        $checkout = (string) file_get_contents(base_path('public/js/pos/checkout.js'));

        // Stage 1 needs a customer; stage 2 needs at least one item.
        $this->assertStringContainsString('Select a customer before continuing.', $checkout);
        $this->assertStringContainsString('Add at least one item before continuing.', $checkout);
        $this->assertStringContainsString('function validateStage', $checkout);
        $this->assertStringContainsString('function stageProblem', $checkout);

        // Next and every forward tab jump go through the gate.
        $this->assertStringContainsString('if (!validateStage(posCur)) return;', $checkout);
        $this->assertMatchesRegularExpression('/if \(!force && target > posCur\)/', $checkout);

        // Completing a sale reports through the same inline message.
        $this->assertStringNotContainsString("alert('Select a customer first.')", $checkout);
        $this->assertStringContainsString('showStageError(', $checkout);
    }

    public function test_a_fully_blocked_stage_keeps_the_cashier_put(): void
    {
        // Server side, the same two rules are enforced no matter what the
        // client does: no customer, and an empty cart.
        $this->actingAs($this->cashier)
            ->postJson('/api/transactions', [
                'customer_id' => 999999,
                'order_type' => 'Walk-in',
                'payment_method' => 'Cash',
                'cash_tendered' => 500,
                'items' => [['product_id' => 'slim', 'quantity' => 1]],
            ])
            ->assertStatus(422);

        $this->actingAs($this->cashier)
            ->postJson('/api/transactions', [
                'customer_id' => 9,
                'order_type' => 'Walk-in',
                'payment_method' => 'Cash',
                'cash_tendered' => 500,
                'items' => [],
            ])
            ->assertStatus(422);
    }

    public function test_the_stage_bar_stays_inside_the_terminal_column(): void
    {
        // Back/Next used to be a full-viewport bar; it is now bounded so it
        // reads as part of the panel rather than page chrome.
        $css = (string) file_get_contents(base_path('public/css/styles.css'));

        $this->assertMatchesRegularExpression(
            '/\.mobile-action-bar\s*\{[^}]*max-width:\s*620px/s',
            $css
        );
    }

    // ---------- Navigation guards ----------

    public function test_legacy_wizard_urls_redirect_to_the_terminal(): void
    {
        foreach (['customer-custody', 'products-intake', 'payment-print'] as $step) {
            $this->actingAs($this->cashier)
                ->get('/cashier/' . $step)
                ->assertRedirect('/cashier');
        }
    }

    public function test_a_cashier_is_redirected_away_from_the_owner_portal(): void
    {
        $this->actingAs($this->cashier)
            ->get('/admin/dashboard')
            ->assertRedirect('/cashier');
    }

    public function test_the_owner_can_open_every_dashboard_tab(): void
    {
        $this->actingAs($this->owner)->get('/admin')->assertRedirect('/admin/dashboard');

        $tabs = [
            'dashboard' => 'Station Dashboard &amp; Analytics',
            'sales' => 'Sales &amp; POS Logs',
            'arima' => 'ARIMA Analytics',
            'inventory' => 'Consumables &amp; ROP',
            'customers' => 'Customer Liabilities',
            'suppliers' => 'Suppliers',
            'users' => 'Users &amp; Access',
            'settings' => 'Settings',
        ];

        foreach ($tabs as $tab => $marker) {
            $this->actingAs($this->owner)
                ->get('/admin/' . $tab)
                ->assertOk()
                ->assertSee($marker, false);
        }
    }

    public function test_the_owner_can_also_open_the_terminal(): void
    {
        $this->actingAs($this->owner)->get('/cashier')->assertOk();
    }

    public function test_unknown_tabs_and_steps_are_not_found(): void
    {
        $this->actingAs($this->owner)->get('/admin/whatever')->assertNotFound();
        $this->actingAs($this->cashier)->get('/cashier/whatever')->assertNotFound();
    }

    public function test_pages_render_the_shared_shell(): void
    {
        $response = $this->actingAs($this->owner)->get('/admin/dashboard');

        // The sidebar and CSRF token come from the layout, not the page.
        $response->assertSee('sidebar-nav', false);
        $response->assertSee('csrf-token', false);
        $response->assertSee('Owner Portal');
    }

    public function test_the_health_endpoint_reports_the_database(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('db', true);
    }
}
