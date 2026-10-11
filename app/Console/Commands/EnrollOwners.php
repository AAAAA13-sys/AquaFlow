<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class EnrollOwners extends Command
{
    protected $signature = 'aquaflow:enroll-owner-email';

    protected $description = 'Prompt for missing owner email destinations during a store upgrade';

    public function handle(): int
    {
        foreach (User::where('role', 'admin')->whereNull('email')->get() as $owner) {
            $email = $this->ask('Private verification email for '.$owner->username);
            if ($this->call('aquaflow:owner-email', ['username' => $owner->username, 'email' => $email]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
