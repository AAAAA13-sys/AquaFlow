<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ProductionQueueItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAndDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_owner_can_manage_employees_without_creating_login_accounts(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $users = User::count();
        $employees = Employee::count();
        $id = $this->postJson('/api/employees', ['name' => 'Ana Santos', 'job_title' => 'Refill operator', 'contact_number' => '09123456789', 'is_active' => true])->assertCreated()->json('employee.id');
        $this->patchJson('/api/employees/'.$id, ['name' => 'Ana Santos', 'job_title' => 'Delivery worker', 'is_active' => false])->assertOk()->assertJsonPath('employee.is_active', false);
        $this->getJson('/api/employees')->assertOk()->assertJsonCount($employees + 1, 'employees');
        $this->assertSame($users, User::count());
        $this->deleteJson('/api/employees/'.$id)->assertOk();
        $this->assertSoftDeleted('employees', ['id' => $id]);
        $this->getJson('/api/employees')->assertJsonCount($employees, 'employees');
    }

    public function test_employee_validation_and_owner_permissions(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson('/api/employees')->assertForbidden();
        $this->postJson('/api/employees', ['name' => 'Ana', 'job_title' => 'Worker'])->assertForbidden();
        $this->get('/admin/employees')->assertRedirect('/cashier');
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->postJson('/api/employees', ['name' => '', 'job_title' => ''])->assertUnprocessable()->assertJsonValidationErrors(['name', 'job_title']);
        $this->get('/admin/employees')->assertOk()->assertSee('Add employee')->assertSee('employeeBody', false);
    }

    public function test_cashier_can_deliver_directly_and_repeat_without_duplicate_audit(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($cashier);
        $item = ProductionQueueItem::create(['receipt_number' => 'TEST-DELIVERY', 'customer_name' => 'Ana', 'items_description' => '2 refills', 'order_type' => 'Delivery', 'stage' => 0, 'is_completed' => false]);
        $this->postJson('/api/queue/'.$item->id.'/deliver')->assertOk()->assertJsonPath('item.status', 'delivered');
        $this->postJson('/api/queue/'.$item->id.'/advance')->assertForbidden();
        $item->refresh();
        $this->assertTrue($item->is_completed);
        $this->assertNotNull($item->delivered_at);
        $this->assertSame($cashier->id, $item->stage_history[0]['user_id']);
        $time = $item->delivered_at->toISOString();
        $this->postJson('/api/queue/'.$item->id.'/deliver')->assertOk();
        $item->refresh();
        $this->assertCount(1, $item->stage_history);
        $this->assertSame($time, $item->delivered_at->toISOString());
        $this->getJson('/api/queue?status=active&search=TEST-DELIVERY')->assertJsonCount(0, 'queue');
        $this->getJson('/api/queue?status=delivered&search=TEST-DELIVERY')->assertOk()->assertJsonCount(1, 'queue');
        $this->getJson('/api/queue?status=invalid')->assertUnprocessable();
    }
}
