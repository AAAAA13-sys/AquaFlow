<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class SuperAdminMigrationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_upgrade_preserves_existing_owner_identity_credentials_and_activity(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'Private123!', 'role' => 'admin', 'is_active' => false]);
        $hash = $owner->password;
        $migration = require database_path('migrations/2026_10_16_000004_add_super_admin_role.php');
        $migration->up();
        $this->assertSame('super_admin', $owner->fresh()->role);
        $this->assertSame($hash, $owner->fresh()->password);
        $this->assertFalse($owner->fresh()->is_active);
        $this->assertDatabaseCount('users', 1);
        $this->expectException(\RuntimeException::class);
        try {
            $migration->down();
        } finally {
            User::whereKey($owner->id)->delete();
        }
    }
}
