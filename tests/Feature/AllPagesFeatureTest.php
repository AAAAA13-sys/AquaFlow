<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AllPagesFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $cashier;
    private User $refiller;
    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('username', 'admin')->firstOrFail();
        $this->manager = User::where('username', 'manager')->firstOrFail();
        $this->cashier = User::where('username', 'cashier')->firstOrFail();

        $refillerEmployee = Employee::create([
            'name' => 'Test Refiller',
            'job_title' => 'Refill operator',
            'is_active' => true,
        ]);
        $this->refiller = User::create([
            'username' => 'test_refiller',
            'name' => 'Test Refiller',
            'password' => Hash::make('password123'),
            'role' => User::ROLE_REFILLER,
            'employee_id' => $refillerEmployee->id,
            'is_active' => true,
        ]);

        $riderEmployee = Employee::create([
            'name' => 'Test Rider',
            'job_title' => 'Delivery worker',
            'is_active' => true,
        ]);
        $this->rider = User::create([
            'username' => 'test_rider',
            'name' => 'Test Rider',
            'password' => Hash::make('password123'),
            'role' => User::ROLE_RIDER,
            'employee_id' => $riderEmployee->id,
            'is_active' => true,
        ]);
    }

    private const ADMIN_TABS = [
        'dashboard',
        'orders',
        'sales',
        'arima',
        'inventory',
        'customers',
        'suppliers',
        'users',
        'cash',
        'defects',
        'approvals',
        'audit-logs',
        'staff-workspaces',
        'purchasing',
        'expenses',
        'reports',
    ];

    private const CASHIER_PAGES = [
        '/cashier',
        '/cashier/queue',
        '/cashier/activity',
        '/cashier/history',
        '/cashier/cash',
        '/cashier/stocks',
    ];

    private function assertCleanHtmlResponse($response): void
    {
        $content = $response->getContent();
        $this->assertStringStartsWith('<!DOCTYPE html>', trim($content));
        $this->assertStringContainsString('lang="en"', $content);
        $this->assertStringContainsString('Skip to content', $content);
        $this->assertStringNotContainsString('@extends', $content);
        $this->assertStringNotContainsString('@section', $content);
        $this->assertStringNotContainsString('@include', $content);
        $this->assertStringNotContainsString('@yield', $content);
        $this->assertStringNotContainsString('@component', $content);
    }

    public function test_all_admin_tabs_render_cleanly_for_owner(): void
    {
        foreach (self::ADMIN_TABS as $tab) {
            $response = $this->actingAs($this->owner)->get('/admin/' . $tab);
            $response->assertOk();
            $this->assertCleanHtmlResponse($response);
        }

        // Settings is an owner-only screen
        $settingsResponse = $this->actingAs($this->owner)->get('/admin/settings');
        $settingsResponse->assertOk();
        $this->assertCleanHtmlResponse($settingsResponse);
    }

    public function test_all_admin_tabs_render_for_manager_and_settings_is_restricted(): void
    {
        foreach (self::ADMIN_TABS as $tab) {
            $response = $this->actingAs($this->manager)->get('/admin/' . $tab);
            $response->assertOk();
            $this->assertCleanHtmlResponse($response);
        }

        // Settings must be inaccessible to manager
        $response = $this->actingAs($this->manager)->get('/admin/settings');
        $this->assertTrue(in_array($response->getStatusCode(), [302, 403], true));
    }

    public function test_admin_tabs_are_restricted_from_staff_roles(): void
    {
        foreach ([$this->cashier, $this->refiller, $this->rider] as $staff) {
            $response = $this->actingAs($staff)->get('/admin/dashboard');
            $this->assertTrue(in_array($response->getStatusCode(), [302, 403], true));
        }
    }

    public function test_all_cashier_pages_render_cleanly_for_cashier(): void
    {
        foreach (self::CASHIER_PAGES as $page) {
            $response = $this->actingAs($this->cashier)->get($page);
            $response->assertOk();
            $this->assertCleanHtmlResponse($response);
        }
    }

    public function test_employee_workspaces_render_cleanly_with_role_specific_controls(): void
    {
        // Refiller home & history
        $refillerHome = $this->actingAs($this->refiller)->get('/employee');
        $refillerHome->assertOk();
        $this->assertCleanHtmlResponse($refillerHome);
        $refillerHome->assertSee('To prepare');
        $refillerHome->assertSee('Report Defect');

        $refillerHistory = $this->actingAs($this->refiller)->get('/employee/history');
        $refillerHistory->assertOk();
        $this->assertCleanHtmlResponse($refillerHistory);

        // Rider home & history
        $riderHome = $this->actingAs($this->rider)->get('/employee');
        $riderHome->assertOk();
        $this->assertCleanHtmlResponse($riderHome);
        $riderHome->assertSee('Ready to deliver');
        $riderHome->assertDontSee('Report Defect');

        $riderHistory = $this->actingAs($this->rider)->get('/employee/history');
        $riderHistory->assertOk();
        $this->assertCleanHtmlResponse($riderHistory);
    }

    public function test_unauthenticated_and_guest_navigation(): void
    {
        $guestRoot = $this->get('/');
        $guestRoot->assertOk();
        $this->assertStringStartsWith('<!DOCTYPE html>', trim($guestRoot->getContent()));
        $guestRoot->assertSee('AquaFlow');

        $this->get('/owner/login')->assertRedirect('/');
        $this->get('/admin/dashboard')->assertRedirect('/');
        $this->get('/cashier')->assertRedirect('/');
        $this->get('/employee')->assertRedirect('/');
    }
}
