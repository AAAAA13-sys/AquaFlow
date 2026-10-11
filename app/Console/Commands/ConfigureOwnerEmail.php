<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ConfigureOwnerEmail extends Command
{
    protected $signature = 'aquaflow:owner-email {username} {email}';

    protected $description = 'Enroll an existing owner in email verification from the trusted store host';

    public function handle(): int
    {
        $data = ['email' => $this->argument('email')];
        Validator::make($data, ['email' => ['required', 'email:rfc', 'max:255']])->validate();
        $owner = User::where('username', $this->argument('username'))->where('role', 'admin')->firstOrFail();
        if ($owner->email === $data['email']) {
            $this->info('This owner already has that destination. Recovery codes were preserved.');

            return self::SUCCESS;
        }
        if ($owner->email_verified_at) {
            $this->error('A verified destination already exists. Use the documented recovery process before changing it.');

            return self::FAILURE;
        }
        $owner->update(['email' => $data['email']]);
        $this->info('Owner email configured. Sign in at /owner/login with your email and verify the emailed code, then save the displayed recovery codes.');

        return self::SUCCESS;
    }
}
