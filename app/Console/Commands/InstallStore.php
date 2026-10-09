<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StoreInstallationService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class InstallStore extends Command
{
    protected $signature = 'aquaflow:install-store';

    protected $description = 'Initialize a clean store with its first owner account (no demo data)';

    public function handle(StoreInstallationService $installer): int
    {
        if (User::query()->exists()) {
            $this->info('An account already exists. Store data and credentials were preserved.');

            return self::SUCCESS;
        }
        $details = [
            'name' => $this->ask('Owner name'),
            'username' => $this->ask('Owner username'),
            'password' => $this->secret('Password (8+ characters, uppercase, lowercase and number)'),
            'pin' => $this->secret('Owner PIN (4-12 digits)'),
        ];
        if ($details['password'] !== $this->secret('Repeat password')) {
            $this->error('Passwords do not match. Run Setup again.');

            return self::FAILURE;
        }
        try {
            $installer->install($details);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $this->info('Store ready. Review prices and settings, then record actual opening stock before selling.');

        return self::SUCCESS;
    }
}
