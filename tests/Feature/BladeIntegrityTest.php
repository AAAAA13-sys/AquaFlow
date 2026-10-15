<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BladeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_pages_render_with_unique_ids_and_existing_local_assets(): void
    {
        $this->seed();
        $owner = User::where('role', 'admin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $pages = array_map(fn ($page) => ['/admin/'.$page, $owner], ['dashboard', 'sales', 'arima', 'inventory', 'customers', 'suppliers', 'users', 'settings']);
        $pages = [...$pages, ['/cashier', $cashier], ['/cashier/queue', $cashier], ['/cashier/history', $cashier]];
        foreach ($pages as [$url, $user]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('css/theme.css?v=', $html, $url.' must use the shared palette');
            $document = new \DOMDocument;
            @$document->loadHTML($html);
            $ids = [];
            foreach ((new \DOMXPath($document))->query('//*[@id]') as $element) {
                $ids[] = $element->getAttribute('id');
            }
            $this->assertSame([], array_filter(array_count_values($ids), fn ($count) => $count > 1), $url.' has duplicate IDs');
            preg_match_all('/(?:src|href)="([^" ]+\.(?:js|css)(?:\?[^" ]*)?)"/', $html, $assets);
            foreach ($assets[1] as $asset) {
                $path = parse_url(html_entity_decode($asset), PHP_URL_PATH);
                $this->assertFileExists(public_path(ltrim($path, '/')), $url.' references missing asset '.$path);
            }
        }
    }

    public function test_admin_login_keeps_removed_controls_absent_and_versions_its_script(): void
    {
        $this->get('/owner/login')->assertOk()->assertDontSee('Use a recovery code')->assertDontSee('Start again with different details')->assertSee('admin-login.js?v=', false);
    }

    public function test_pos_has_a_native_customer_dialog_stock_retry_and_read_only_steps(): void
    {
        $this->seed();
        $html = $this->actingAs(User::where('role', 'cashier')->firstOrFail())->get('/cashier')->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//dialog[@id="registerModal"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="stockPreview"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="posSteps"]//*[@onclick]')->length);
    }
}
