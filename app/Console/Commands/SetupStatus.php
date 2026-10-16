<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Reports whether first-run owner setup has finished.
 *
 * The installer uses this before enabling public remote access. The setup code is
 * printed on the store host, but a tunnel opened before setup completes would
 * expose the login page to whoever finds the link first - so the tunnel waits.
 *
 * Exit status: 0 = complete, 1 = still pending.
 */
class SetupStatus extends Command
{
    protected $signature = 'aquaflow:setup-status';

    protected $description = 'Exit 0 when owner setup is complete, 1 while first-run setup is pending';

    public function handle(): int
    {
        $pending = User::query()->where('is_bootstrap', true)->exists()
            && ! User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('is_bootstrap', false)->exists();

        $this->line($pending ? 'pending' : 'complete');

        return $pending ? self::FAILURE : self::SUCCESS;
    }
}
