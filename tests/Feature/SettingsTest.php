<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_partial_updates_preserve_other_values_and_descriptions(): void
    {
        $before = SystemSetting::allValues();
        $description = SystemSetting::query()->findOrFail('restock_lead_days')->description;

        $this->actingAs(User::query()->where('role', 'admin')->firstOrFail())
            ->putJson('/api/settings', ['restock_lead_days' => 5, 'ignored' => 'value'])
            ->assertOk()->assertExactJson(['settings' => array_replace($before, ['restock_lead_days' => '5'])]);

        $this->assertSame($description, SystemSetting::query()->findOrFail('restock_lead_days')->description);
        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'ignored']);
    }

    public function test_owner_can_update_all_settings_or_send_an_empty_update(): void
    {
        $settings = ['station_name' => 'Test Station', 'restock_lead_days' => '1', 'sus_target' => 'Grade A'];
        $this->actingAs(User::query()->where('role', 'admin')->firstOrFail())
            ->putJson('/api/settings', $settings)->assertOk()->assertExactJson(['settings' => $settings]);
        $this->putJson('/api/settings', [])->assertOk()->assertExactJson(['settings' => $settings]);
    }

    public function test_settings_writes_require_owner_and_keep_validation(): void
    {
        $this->putJson('/api/settings', ['station_name' => 'Forbidden'])->assertUnauthorized();
        $this->actingAs(User::query()->where('role', 'cashier')->firstOrFail())
            ->putJson('/api/settings', ['station_name' => 'Forbidden'])->assertForbidden();
        $this->actingAs(User::query()->where('role', 'admin')->firstOrFail())
            ->putJson('/api/settings', ['restock_lead_days' => 0])->assertUnprocessable()
            ->assertJsonValidationErrors('restock_lead_days');
    }
}
